<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Doctrine\DatabaseUrlComposer;
use Doctrine\DBAL\Tools\DsnParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DatabaseUrlComposerTest extends TestCase
{
    private const string PROJECT_DIR = '/srv/zwille';

    public function testDefaultsToTheProjectSqliteFile(): void
    {
        self::assertSame(
            'sqlite:////srv/zwille/var/data/zwille.sqlite',
            $this->compose([]),
        );
    }

    public function testDefaultKeepsUsingTheFileFromBeforeTheRename(): void
    {
        $dir = sys_get_temp_dir().'/zwille-dbcomposer-'.bin2hex(random_bytes(4));
        mkdir($dir.'/var/data', 0o777, true);
        try {
            touch($dir.'/var/data/eatmyshort.sqlite');
            $composer = new DatabaseUrlComposer($dir);
            self::assertSame('sqlite:///'.$dir.'/var/data/eatmyshort.sqlite', $composer->composeFrom([]));

            // Once the new default exists it wins.
            touch($dir.'/var/data/zwille.sqlite');
            self::assertSame('sqlite:///'.$dir.'/var/data/zwille.sqlite', $composer->composeFrom([]));

            // An explicit DB_DATABASE is never redirected.
            unlink($dir.'/var/data/zwille.sqlite');
            self::assertSame('sqlite:///'.$dir.'/var/data/zwille.sqlite', $composer->composeFrom(['DB_DATABASE' => 'var/data/zwille.sqlite']));
        } finally {
            array_map('unlink', glob($dir.'/var/data/*') ?: []);
            rmdir($dir.'/var/data');
            rmdir($dir.'/var');
            rmdir($dir);
        }
    }

    public function testSqliteRelativePathIsResolvedAgainstTheProjectDirectory(): void
    {
        self::assertSame(
            'sqlite:////srv/zwille/data/other.sqlite',
            $this->compose(['DB_DATABASE' => 'data/other.sqlite']),
        );
    }

    public function testSqliteAbsolutePathIsKept(): void
    {
        self::assertSame(
            'sqlite:////srv/sqlite/absolute.sqlite',
            $this->compose(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/srv/sqlite/absolute.sqlite']),
        );
    }

    public function testMysqlUsesLaravelStyleDefaults(): void
    {
        self::assertSame(
            'mysql://root:@127.0.0.1:3306/?charset=utf8mb4',
            $this->compose(['DB_CONNECTION' => 'mysql']),
        );
    }

    public function testMysqlCarriesHostPortDatabaseAndCharset(): void
    {
        self::assertSame(
            'mysql://root:@db.internal:3307/zwille?charset=latin1',
            $this->compose([
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'zwille',
                'DB_HOST' => 'db.internal',
                'DB_PORT' => '3307',
                'DB_CHARSET' => 'latin1',
            ]),
        );
    }

    public function testUsernameAndPasswordAreUrlEncoded(): void
    {
        self::assertSame(
            'mysql://us%20er%40x:p%40ss%3Aw%2Frd%23@127.0.0.1:3306/zwille?charset=utf8mb4',
            $this->compose([
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'zwille',
                'DB_USERNAME' => 'us er@x',
                'DB_PASSWORD' => 'p@ss:w/rd#',
            ]),
        );
    }

    public function testEmptyPasswordStaysEmpty(): void
    {
        self::assertSame(
            'mysql://app:@127.0.0.1:3306/zwille?charset=utf8mb4',
            $this->compose([
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'zwille',
                'DB_USERNAME' => 'app',
                'DB_PASSWORD' => '',
            ]),
        );
    }

    public function testMariaDbIsAnAliasForTheSameDriver(): void
    {
        $environment = [
            'DB_DATABASE' => 'zwille',
            'DB_HOST' => 'db.internal',
            'DB_USERNAME' => 'app',
            'DB_PASSWORD' => 'secret',
        ];

        self::assertSame(
            $this->compose($environment + ['DB_CONNECTION' => 'mysql']),
            $this->compose($environment + ['DB_CONNECTION' => 'mariadb']),
        );
    }

    public function testUnixSocketReplacesHostAndPort(): void
    {
        self::assertSame(
            'mysql://root:@localhost/zwille?charset=utf8mb4&unix_socket=%2Fvar%2Frun%2Fmysqld%2Fmysqld.sock',
            $this->compose([
                'DB_CONNECTION' => 'mariadb',
                'DB_DATABASE' => 'zwille',
                'DB_HOST' => 'ignored.example.com',
                'DB_PORT' => '9999',
                'DB_SOCKET' => '/var/run/mysqld/mysqld.sock',
            ]),
        );
    }

    /**
     * @param array<string, string> $environment
     */
    #[DataProvider('blankValuesFallBackToDefaultsProvider')]
    public function testBlankValuesFallBackToDefaults(array $environment, string $expected): void
    {
        self::assertSame($expected, $this->compose($environment));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function blankValuesFallBackToDefaultsProvider(): iterable
    {
        yield 'blank connection' => [['DB_CONNECTION' => ''], 'sqlite:////srv/zwille/var/data/zwille.sqlite'];
        yield 'blank sqlite path' => [['DB_DATABASE' => ''], 'sqlite:////srv/zwille/var/data/zwille.sqlite'];
        yield 'blank host' => [['DB_CONNECTION' => 'mysql', 'DB_HOST' => ''], 'mysql://root:@127.0.0.1:3306/?charset=utf8mb4'];
        yield 'blank charset' => [['DB_CONNECTION' => 'mysql', 'DB_CHARSET' => ''], 'mysql://root:@127.0.0.1:3306/?charset=utf8mb4'];
    }

    public function testUnknownConnectionAbortsWithAClearMessage(): void
    {
        $composer = $this->composer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DB_CONNECTION/');
        $this->expectExceptionMessageMatches('/sqlite, mysql, mariadb/');

        $composer->composeFrom(['DB_CONNECTION' => 'oracle']);
    }

    public function testExplicitDatabaseUrlWinsOverDbVariables(): void
    {
        self::assertSame(
            'postgres://user:pw@db:5432/app',
            $this->compose([
                'DATABASE_URL' => 'postgres://user:pw@db:5432/app',
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'ignored',
                'DB_HOST' => 'ignored',
            ]),
        );
    }

    public function testExplicitDatabaseUrlWinsEvenWithAnUnknownConnection(): void
    {
        // A set DATABASE_URL must never be mixed with the DB_* scheme: the invalid
        // DB_CONNECTION is not even looked at.
        self::assertSame(
            'sqlite:///var/data/explicit.sqlite',
            $this->compose([
                'DATABASE_URL' => 'sqlite:///var/data/explicit.sqlite',
                'DB_CONNECTION' => 'oracle',
            ]),
        );
    }

    /**
     * @param array<string, string> $environment
     * @param array<string, mixed>  $expected
     */
    #[DataProvider('dbalParsedUrlProvider')]
    public function testComposedUrlParsesWithTheDbalDriverParser(array $environment, array $expected): void
    {
        $parser = new DsnParser(['mysql' => 'pdo_mysql', 'mariadb' => 'pdo_mysql', 'sqlite' => 'pdo_sqlite']);
        $params = $parser->parse($this->compose($environment));

        foreach ($expected as $key => $value) {
            self::assertArrayHasKey($key, $params);
            self::assertSame($value, $params[$key], $key);
        }
    }

    /**
     * @return iterable<string, array{array<string, string>, array<string, mixed>}>
     */
    public static function dbalParsedUrlProvider(): iterable
    {
        yield 'sqlite default' => [
            [],
            [
                'driver' => 'pdo_sqlite',
                'path' => '/srv/zwille/var/data/zwille.sqlite',
            ],
        ];
        yield 'sqlite relative' => [
            ['DB_DATABASE' => 'data/x.sqlite'],
            ['driver' => 'pdo_sqlite', 'path' => '/srv/zwille/data/x.sqlite'],
        ];
        yield 'mysql with encoded credentials' => [
            [
                'DB_CONNECTION' => 'mysql',
                'DB_DATABASE' => 'zwille',
                'DB_USERNAME' => 'us er@x',
                'DB_PASSWORD' => 'p@ss:w/rd#',
            ],
            [
                'driver' => 'pdo_mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'user' => 'us er@x',
                'password' => 'p@ss:w/rd#',
                'dbname' => 'zwille',
                'charset' => 'utf8mb4',
            ],
        ];
        yield 'mysql over a unix socket' => [
            ['DB_CONNECTION' => 'mariadb', 'DB_DATABASE' => 'zwille', 'DB_SOCKET' => '/var/run/mysqld/mysqld.sock'],
            [
                'driver' => 'pdo_mysql',
                'host' => 'localhost',
                'dbname' => 'zwille',
                'unix_socket' => '/var/run/mysqld/mysqld.sock',
            ],
        ];
    }

    /**
     * @param array<string, string> $environment
     */
    private function compose(array $environment): string
    {
        return $this->composer()->composeFrom($environment);
    }

    private function composer(): DatabaseUrlComposer
    {
        return new DatabaseUrlComposer(self::PROJECT_DIR);
    }
}
