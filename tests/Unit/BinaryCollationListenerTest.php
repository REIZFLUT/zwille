<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Doctrine\BinaryCollationListener;
use App\Entity\Link;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

/**
 * Proves that the binary collation for exact-match columns only ever reaches the MySQL/MariaDB
 * DDL and leaves the SQLite DDL byte for byte unchanged.
 *
 * The metadata is built with a throwaway EntityManager per platform instead of the application's
 * EntityManager: the application connection is SQLite (where the listener must stay silent), so
 * it could never show the MySQL outcome - and mutating the shared, DAMA-cached metadata of the
 * running kernel would leak into every other test.
 */
final class BinaryCollationListenerTest extends TestCase
{
    private const string MYSQL_DDL_COLLATION = 'COLLATE `utf8mb4_bin`';

    public function testMysqlDdlPinsTheCodeColumnToBinaryCollation(): void
    {
        $ddl = $this->createSchemaSql('pdo_mysql', [
            'serverVersion' => '10.11.14-MariaDB-0ubuntu0.24.04.1',
        ]);

        self::assertStringContainsString(
            'code VARCHAR(64) NOT NULL '.self::MYSQL_DDL_COLLATION,
            $ddl,
            'link.code must be compared byte by byte on MySQL/MariaDB',
        );
        // No other column may carry an explicit collation.
        self::assertSame(1, substr_count($ddl, 'COLLATE'));
    }

    public function testSqliteDdlIsUnchangedByTheListener(): void
    {
        $ddl = $this->createSchemaSql('pdo_sqlite', ['memory' => true]);

        self::assertStringNotContainsString('COLLATE', $ddl);
        self::assertStringContainsString('code VARCHAR(64) NOT NULL', $ddl);

        // Same SQLite DDL with and without the listener: the event listener must not change
        // SQLite at all (otherwise the existing migrations and doctrine:schema:validate break).
        self::assertSame(
            $this->createSchemaSql('pdo_sqlite', ['memory' => true], withListener: false),
            $ddl,
        );
    }

    public function testListenerLeavesMetadataAloneOnNonMysqlPlatforms(): void
    {
        $em = $this->entityManager('pdo_sqlite', ['memory' => true]);

        self::assertNull($em->getClassMetadata(Link::class)->fieldMappings['code']->options);
    }

    /** @param array<string, mixed> $extraParams */
    private function createSchemaSql(string $driver, array $extraParams, bool $withListener = true): string
    {
        $em = $this->entityManager($driver, $extraParams, $withListener);

        $tool = new SchemaTool($em);
        $sql = $tool->getCreateSchemaSql([$em->getClassMetadata(Link::class)]);

        return implode("\n", $sql);
    }

    /**
     * @param array<string, mixed> $extraParams
     */
    private function entityManager(string $driver, array $extraParams, bool $withListener = true): EntityManager
    {
        $config = new Configuration();
        $config->setMetadataDriverImpl(new AttributeDriver([dirname(__DIR__, 2).'/src/Entity']));
        $config->setProxyDir(sys_get_temp_dir().'/zwille-test-proxies');
        $config->setProxyNamespace('ZwilleTestProxies');
        $config->enableNativeLazyObjects(true);

        // The MySQL credentials are never used: the platform is resolved from the version
        // string in $extraParams, so no connection is opened by this test.
        $params = ['driver' => $driver] + $extraParams + [
            'host' => '127.0.0.1',
            'user' => 'unused',
            'password' => 'unused',
            'dbname' => 'unused',
        ];
        if ('pdo_sqlite' === $driver) {
            $params = ['driver' => $driver] + $extraParams;
        }

        $connection = DriverManager::getConnection($params);

        $em = new EntityManager($connection, $config);
        if ($withListener) {
            $em->getEventManager()->addEventListener(
                Events::loadClassMetadata,
                new BinaryCollationListener($connection),
            );
        }

        return $em;
    }
}
