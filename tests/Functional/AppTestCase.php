<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Domain;
use App\Entity\Link;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AppTestCase extends WebTestCase
{
    protected const string ADMIN_HOST = 'admin.zwille.test';
    protected const string PASSWORD = 'correct horse battery staple';

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient([], ['HTTP_HOST' => self::ADMIN_HOST, 'REMOTE_ADDR' => '203.0.113.10', 'HTTP_ACCEPT_LANGUAGE' => 'de-DE,de;q=0.9']);
        // One container (and EntityManager) for the whole test, so fixtures stay managed across requests.
        $this->client->disableReboot();
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function createUser(string $email, bool $admin = false, bool $withPassword = true): User
    {
        $user = new User()->setEmail($email)->setDisplayName(ucfirst(strstr($email, '@', true) ?: $email))->setAdmin($admin);
        if ($withPassword) {
            $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        }
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    protected function createDomain(string $hostname, ?User ...$users): Domain
    {
        $domain = new Domain($hostname);
        $this->em()->persist($domain);
        foreach ($users as $user) {
            $user?->addDomain($domain);
        }
        $this->em()->flush();

        return $domain;
    }

    protected function createLink(User $owner, Domain $domain, string $code, string $target = 'https://www.example.com/'): Link
    {
        $link = new Link($owner)->setDomain($domain)->setCode($code)->setTargetUrl($target);
        $this->em()->persist($link);
        $this->em()->flush();

        return $link;
    }

    /**
     * Requests a page on a short-link host.
     *
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    protected function shortRequest(string $host, string $path, array $headers = [], string $method = 'GET', array $server = []): void
    {
        $server = ['HTTP_HOST' => $host] + $server;
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $this->client->request($method, $path, server: $server);
    }

    /**
     * Same entity loaded freshly: the kernel resets the EntityManager between requests,
     * so fixtures created before a request are detached afterwards.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected function fresh(object $entity): object
    {
        $id = $this->em()->getUnitOfWork()->getSingleIdentifierValue($entity);
        $this->em()->clear();
        $fresh = $this->em()->find($entity::class, $id);
        self::assertNotNull($fresh);

        return $fresh;
    }
}
