<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiToken;
use App\Entity\Link;
use App\Entity\User;

/**
 * Package 4 through the REST API: the write-only password and the device-target array, the new
 * read fields, and the 422s - without ever returning the hash.
 */
final class LinkPasswordDeviceApiTest extends AppTestCase
{
    public function testApiSetsPasswordAndDeviceTargetsAndNeverReturnsTheHash(): void
    {
        $user = $this->createUser('api@example.com');
        $this->createDomain('go.example.com', $user);
        $token = $this->token($user);

        $this->api('POST', '/api/v1/links', $token, [
            'target_url' => 'https://www.example.com/geheim',
            'code' => 'geheim',
            'password' => 'geheim123',
            'device_targets' => [
                ['device' => 'ios', 'url' => 'https://www.example.com/ios'],
                ['device' => 'android', 'url' => 'https://www.example.com/android'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);

        $raw = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('$2y$', $raw);
        self::assertStringNotContainsString('password_hash', $raw);

        $data = $this->json()['data'];
        self::assertTrue($data['password_protected']);
        self::assertArrayNotHasKey('password_hash', $data);
        // The API returns device_targets as an object; key order is not significant.
        $targets = $data['device_targets'];
        ksort($targets);
        self::assertSame(
            ['android' => 'https://www.example.com/android', 'ios' => 'https://www.example.com/ios'],
            $targets,
        );
        $id = $data['id'];

        // The hash really is stored (it only never leaves the API).
        $stored = $this->em()->getRepository(Link::class)->findOneBy(['code' => 'geheim']);
        self::assertNotNull($stored);
        self::assertTrue(password_verify('geheim123', (string) $stored->getPasswordHash()));

        // Reading back keeps the shape.
        $this->api('GET', '/api/v1/links/'.$id, $token);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['data']['password_protected']);

        // An empty string removes the protection.
        $this->api('PATCH', '/api/v1/links/'.$id, $token, ['password' => '']);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['data']['password_protected']);
        self::assertFalse($this->fresh($stored)->hasPassword());
    }

    public function testNullPasswordLeavesTheProtectionUnchanged(): void
    {
        $user = $this->createUser('api@example.com');
        $domain = $this->createDomain('go.example.com', $user);
        $token = $this->token($user);
        $link = $this->createLink($user, $domain, 'geheim', 'https://www.example.com/geheim');
        $link->setPasswordHash(password_hash('geheim123', \PASSWORD_DEFAULT));
        $this->em()->flush();

        $this->api('PATCH', '/api/v1/links/'.$link->getId(), $token, ['password' => null, 'title' => 'bleibt']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['data']['password_protected']);
        self::assertTrue($this->fresh($link)->hasPassword());

        // Absent (not just null) means unchanged too.
        $this->api('PATCH', '/api/v1/links/'.$link->getId(), $token, ['title' => 'bleibt auch']);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['data']['password_protected']);
    }

    public function testApiValidatesPasswordLength(): void
    {
        $user = $this->createUser('api@example.com');
        $this->createDomain('go.example.com', $user);
        $token = $this->token($user);

        $this->api('POST', '/api/v1/links', $token, ['target_url' => 'https://www.example.com/a', 'password' => 'abc']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('password', $this->json()['violations'][0]['field']);

        $this->api('POST', '/api/v1/links', $token, ['target_url' => 'https://www.example.com/b', 'password' => str_repeat('a', 73)]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('password', $this->json()['violations'][0]['field']);
    }

    public function testApiValidatesAndReplacesDeviceTargets(): void
    {
        $user = $this->createUser('api@example.com');
        $this->createDomain('go.example.com', $user);
        $token = $this->token($user);

        $this->api('POST', '/api/v1/links', $token, [
            'target_url' => 'https://www.example.com/g',
            'code' => 'g',
            'device_targets' => [['device' => 'windows', 'url' => 'https://www.example.com/win']],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('device_targets', $this->json()['violations'][0]['field']);

        $this->api('POST', '/api/v1/links', $token, [
            'target_url' => 'https://www.example.com/g2',
            'code' => 'g2',
            'device_targets' => [['device' => 'ios', 'url' => 'ftp://example.com/ios']],
        ]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('device_targets', $this->json()['violations'][0]['field']);

        // The submitted array replaces the stored set entirely.
        $this->api('POST', '/api/v1/links', $token, [
            'target_url' => 'https://www.example.com/g3',
            'code' => 'g3',
            'device_targets' => [
                ['device' => 'ios', 'url' => 'https://www.example.com/ios'],
                ['device' => 'android', 'url' => 'https://www.example.com/android'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $id = $this->json()['data']['id'];

        $this->api('PATCH', '/api/v1/links/'.$id, $token, ['device_targets' => [['device' => 'desktop', 'url' => 'https://www.example.com/desktop']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['desktop' => 'https://www.example.com/desktop'], $this->json()['data']['device_targets']);
    }

    private function token(User $user): string
    {
        [$token, $plain] = ApiToken::issue($user, 'test');
        $this->em()->persist($token);
        $this->em()->flush();

        return $plain;
    }

    /** @param array<string, mixed>|string|null $body */
    private function api(string $method, string $uri, string $token, array|string|null $body = null): void
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], content: \is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
