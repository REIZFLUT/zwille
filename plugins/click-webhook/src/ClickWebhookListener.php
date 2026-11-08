<?php

declare(strict_types=1);

namespace Plugin\ClickWebhook;

use App\Event\Tracking\ClickRecordedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Pushes every recorded click to a configured M2M endpoint as a signed JSON webhook - the
 * "app to app over an authenticated API" pattern.
 *
 * ClickRecordedEvent fires inside kernel.terminate, after the visitor already received their
 * redirect (under PHP-FPM after fastcgi_finish_request). A slow endpoint therefore only
 * occupies a worker and never costs a visitor a millisecond - but it also never reaches the
 * response, so a delivery failure must be logged, not thrown: the click is already committed
 * and nothing here may undo it. Without a configured url the listener does nothing at all.
 */
#[AsEventListener]
final readonly class ClickWebhookListener
{
    /** @param array<string, mixed> $settings */
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger,
        private array $settings,
    ) {
    }

    public function __invoke(ClickRecordedEvent $event): void
    {
        $url = $this->settings['url'] ?? null;
        if (!\is_string($url) || '' === trim($url)) {
            return;
        }

        try {
            // Encode the body ourselves so the signature is computed over exactly the bytes
            // that leave the process (Symfony's "json" option would apply its own flags).
            $body = json_encode($this->payload($event), \JSON_THROW_ON_ERROR);

            $headers = $this->headers();
            $headers['Content-Type'] = 'application/json';
            $secret = $this->settings['secret'] ?? null;
            if (\is_string($secret) && '' !== $secret) {
                $signature = 'sha256='.hash_hmac('sha256', $body, $secret);
                $headers['X-Zwille-Signature'] = $signature;
                // Deprecated name from before the rename to Zwille, still sent so existing
                // receivers keep verifying. Remove in a later major version.
                $headers['X-Emsl-Signature'] = $signature;
            }

            $response = $this->http->request('POST', $url, [
                'headers' => $headers,
                'body' => $body,
                'timeout' => $this->timeout(),
            ]);
            $status = $response->getStatusCode();
            if ($status >= 400) {
                $this->logger->warning('Click webhook answered with HTTP {status}.', ['status' => $status, 'url' => $url]);
            }
        } catch (\Throwable $exception) {
            // Never rethrow: a broken webhook must not affect the recorded click or the run.
            $this->logger->warning('Click webhook delivery failed: {message}', [
                'message' => $exception->getMessage(),
                'url' => $url,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ClickRecordedEvent $event): array
    {
        $link = $event->getLink();

        return [
            'event' => 'click.recorded',
            'link_id' => $link->getId(),
            'short_code' => $link->getCode(),
            'short_url' => $link->getShortUrl(),
            'target_url' => $link->getTargetUrl(),
            'occurred_at' => $event->getOccurredAt()->format(\DateTimeInterface::RFC3339),
            'country_code' => $event->getCountryCode(),
            'device_type' => $event->getDeviceType(),
            'unique_visitor' => $event->isUniqueVisitor(),
        ];
    }

    private function timeout(): float
    {
        $timeout = $this->settings['timeout'] ?? 2.0;

        return is_numeric($timeout) ? (float) $timeout : 2.0;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $configured = $this->settings['headers'] ?? null;
        if (!\is_array($configured)) {
            return [];
        }

        $headers = [];
        foreach ($configured as $name => $value) {
            if (\is_string($name) && (\is_string($value) || is_numeric($value))) {
                $headers[$name] = (string) $value;
            }
        }

        return $headers;
    }
}
