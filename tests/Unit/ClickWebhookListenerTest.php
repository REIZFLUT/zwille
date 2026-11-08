<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\User;
use App\Event\Tracking\ClickRecordedEvent;
use App\Tests\Support\CapturingLogger;
use PHPUnit\Framework\TestCase;
use Plugin\ClickWebhook\ClickWebhookListener;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ClickWebhookListenerTest extends TestCase
{
    private const string URL = 'https://crm.example.com/hooks/clicks';

    public function testPostsASignedJsonPayload(): void
    {
        $capturedMethod = null;
        $capturedUrl = null;
        $capturedBody = null;
        $capturedHeaders = null;

        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedMethod, &$capturedUrl, &$capturedBody, &$capturedHeaders): MockResponse {
            $capturedMethod = $method;
            $capturedUrl = $url;
            $capturedBody = $options['body'] ?? null;
            $capturedHeaders = $options['headers'] ?? null;

            return new MockResponse('', ['http_code' => 202]);
        });
        $logger = new CapturingLogger();
        $listener = new ClickWebhookListener($client, $logger, [
            'url' => self::URL,
            'secret' => 's3cr3t',
            'headers' => ['X-Api-Key' => 'abc'],
        ]);

        $listener($this->event());

        self::assertSame('POST', $capturedMethod);
        self::assertSame(self::URL, $capturedUrl);

        $body = (string) $capturedBody;
        $payload = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertSame('click.recorded', $payload['event']);
        self::assertSame('spring', $payload['short_code']);
        self::assertSame('https://go.example.com/spring', $payload['short_url']);
        self::assertSame('https://www.example.com/spring', $payload['target_url']);
        self::assertSame('2026-11-06T09:30:00+01:00', $payload['occurred_at']);
        self::assertSame('DE', $payload['country_code']);
        self::assertSame('mobile', $payload['device_type']);
        self::assertTrue($payload['unique_visitor']);

        // Symfony normalises the headers into a flat "Name: value" list before the mock sees
        // them; assert on that, so the test checks what would really leave the process.
        $headers = $capturedHeaders;
        self::assertIsArray($headers);
        self::assertContains('Content-Type: application/json', $headers);
        self::assertContains('X-Api-Key: abc', $headers);
        $signature = 'sha256='.hash_hmac('sha256', $body, 's3cr3t');
        self::assertContains('X-Zwille-Signature: '.$signature, $headers);
        self::assertContains('X-Emsl-Signature: '.$signature, $headers, 'deprecated header still sent');

        self::assertSame([], $logger->getRecords(), 'a 2xx answer is not logged');
    }

    public function testWithoutAUrlNoRequestIsMade(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 200]));
        $listener = new ClickWebhookListener($client, new CapturingLogger(), []);

        $listener($this->event());

        self::assertSame(0, $client->getRequestsCount());
    }

    public function testWithoutASecretNoSignatureHeaderIsSent(): void
    {
        $capturedHeaders = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedHeaders): MockResponse {
            $capturedHeaders = $options['headers'] ?? null;

            return new MockResponse('', ['http_code' => 200]);
        });
        $listener = new ClickWebhookListener($client, new CapturingLogger(), ['url' => self::URL]);

        $listener($this->event());

        self::assertIsArray($capturedHeaders);
        self::assertContains('Content-Type: application/json', $capturedHeaders);
        $signature = array_filter($capturedHeaders, static fn (mixed $header): bool => (bool) preg_match('/^X-(Zwille|Emsl)-Signature:/', (string) $header));
        self::assertSame([], array_values($signature));
    }

    public function testAFailingRequestIsLoggedAndNotThrown(): void
    {
        $client = new MockHttpClient(static function (): never {
            throw new \RuntimeException('connection refused');
        });
        $logger = new CapturingLogger();
        $listener = new ClickWebhookListener($client, $logger, ['url' => self::URL]);

        $listener($this->event());

        self::assertTrue($logger->hasRecord('warning', 'Click webhook delivery failed'));
    }

    public function testAnErrorStatusCodeIsLogged(): void
    {
        $client = new MockHttpClient(new MockResponse('', ['http_code' => 503]));
        $logger = new CapturingLogger();
        $listener = new ClickWebhookListener($client, $logger, ['url' => self::URL]);

        $listener($this->event());

        self::assertTrue($logger->hasRecord('warning', 'Click webhook answered'));
    }

    private function event(): ClickRecordedEvent
    {
        return new ClickRecordedEvent(
            $this->link(),
            new \DateTimeImmutable('2026-11-06T09:30:00+01:00'),
            'DE',
            'mobile',
            true,
        );
    }

    private function link(): Link
    {
        $link = new Link(new User());
        $link->setDomain(new Domain('go.example.com'));
        $link->setCode('spring');
        $link->setTargetUrl('https://www.example.com/spring');

        return $link;
    }
}
