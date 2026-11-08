<?php

declare(strict_types=1);

namespace App\Doctrine;

/**
 * Builds the Doctrine connection URL ("DATABASE_URL") from Laravel-style DB_* variables.
 *
 * Precedence, higher rule wins:
 *   1. a real environment variable beats any dot-env file,
 *   2. .env.local beats .env,
 *   3. an explicit DATABASE_URL beats every DB_* variable.
 *
 * A DATABASE_URL that is set anywhere is returned untouched and the DB_* variables are
 * ignored - the two schemes are never mixed. Only when DATABASE_URL is missing is the URL
 * assembled here. config/runtime.php calls compose() once at boot for web and CLI alike and
 * writes the result back into the environment; the Symfony runtime then only has to read
 * DATABASE_URL.
 *
 * Supported DB_CONNECTION values: sqlite (default), mysql and mariadb - the last two are
 * aliases for the same pdo_mysql driver. An unknown value aborts with an exception rather
 * than silently falling back.
 */
final readonly class DatabaseUrlComposer
{
    /** Allowed DB_CONNECTION values; "mysql" and "mariadb" share the same driver. */
    public const array DRIVERS = ['sqlite', 'mysql', 'mariadb'];

    /** SQLite file used when neither DATABASE_URL nor DB_DATABASE is set. */
    public const string DEFAULT_SQLITE_PATH = 'var/data/zwille.sqlite';

    /**
     * Default SQLite file from before the rename to Zwille (formerly EatMyShort.Link). An
     * install that relied on the default keeps using it as long as the new file does not
     * exist, so an update never silently switches to an empty database.
     */
    public const string LEGACY_SQLITE_PATH = 'var/data/eatmyshort.sqlite';

    private const string DEFAULT_HOST = '127.0.0.1';
    private const string DEFAULT_PORT = '3306';
    private const string DEFAULT_CHARSET = 'utf8mb4';
    private const string DEFAULT_USERNAME = 'root';

    public function __construct(private string $projectDir)
    {
    }

    /**
     * Composes the URL from the current process environment ($_SERVER/$_ENV as prepared by
     * config/runtime.php, including values read from the loaded dot-env file).
     */
    public function compose(): string
    {
        return $this->build(static function (string $name): ?string {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
            if (null !== $value) {
                return \is_string($value) ? $value : null;
            }
            $value = getenv($name);

            return false === $value ? null : $value;
        });
    }

    /**
     * Same as compose(), but against an explicit map - the pure, testable core.
     *
     * @param array<string, string|null> $environment
     */
    public function composeFrom(array $environment): string
    {
        return $this->build(static function (string $name) use ($environment): ?string {
            $value = $environment[$name] ?? null;

            return \is_string($value) ? $value : null;
        });
    }

    /**
     * @param callable(string): ?string $get
     */
    private function build(callable $get): string
    {
        $explicit = self::clean($get('DATABASE_URL'));
        if (null !== $explicit) {
            return $explicit;
        }

        $driver = strtolower(self::clean($get('DB_CONNECTION')) ?? 'sqlite');

        return match ($driver) {
            'sqlite' => $this->sqliteUrl($get),
            'mysql', 'mariadb' => $this->mysqlUrl($get),
            default => throw new \InvalidArgumentException(\sprintf('Invalid value "%s" for "DB_CONNECTION". Allowed values: %s.', $driver, implode(', ', self::DRIVERS))),
        };
    }

    /**
     * @param callable(string): ?string $get
     */
    private function sqliteUrl(callable $get): string
    {
        $path = self::clean($get('DB_DATABASE')) ?? $this->defaultSqlitePath();

        // A relative DB_DATABASE is resolved against the project directory; an absolute
        // path is used as-is. Either way the DSN carries an absolute path.
        if (!str_starts_with($path, '/')) {
            $path = rtrim($this->projectDir, '/').'/'.$path;
        }

        return 'sqlite:///'.$path;
    }

    /**
     * @param callable(string): ?string $get
     */
    private function mysqlUrl(callable $get): string
    {
        $username = self::clean($get('DB_USERNAME')) ?? self::DEFAULT_USERNAME;
        // The password is not trimmed: leading and trailing spaces can be significant and
        // must survive into the URL-encoded DSN.
        $password = $get('DB_PASSWORD') ?? '';

        $query = ['charset' => self::clean($get('DB_CHARSET')) ?? self::DEFAULT_CHARSET];

        $socket = self::clean($get('DB_SOCKET'));
        if (null !== $socket) {
            // A Unix socket replaces host and port; "localhost" is only a placeholder that
            // keeps the URL well-formed, the driver connects through unix_socket.
            $hostPort = 'localhost';
            $query['unix_socket'] = $socket;
        } else {
            $hostPort = (self::clean($get('DB_HOST')) ?? self::DEFAULT_HOST)
                .':'.(self::clean($get('DB_PORT')) ?? self::DEFAULT_PORT);
        }

        return \sprintf(
            'mysql://%s:%s@%s/%s?%s',
            rawurlencode($username),
            rawurlencode($password),
            $hostPort,
            self::clean($get('DB_DATABASE')) ?? '',
            http_build_query($query, '', '&', \PHP_QUERY_RFC3986),
        );
    }

    private function defaultSqlitePath(): string
    {
        $base = rtrim($this->projectDir, '/').'/';
        if (!is_file($base.self::DEFAULT_SQLITE_PATH) && is_file($base.self::LEGACY_SQLITE_PATH)) {
            return self::LEGACY_SQLITE_PATH;
        }

        return self::DEFAULT_SQLITE_PATH;
    }

    private static function clean(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
