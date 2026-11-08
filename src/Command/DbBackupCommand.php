<?php

declare(strict_types=1);

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Writes a consistent copy of the database while the app keeps running.
 *
 * SQLite is copied in-process with VACUUM INTO. MySQL/MariaDB is dumped with the client
 * tools (--single-transaction), so a running app is not blocked and the dump is consistent
 * for InnoDB tables. Credentials are handed to the tool through MYSQL_PWD, never on the
 * command line, so they stay out of the process list.
 *
 * Every file is created under a 0077 umask, so the copy (password hashes, TOTP secrets, API
 * token hashes) is never readable by other accounts - not even for the duration of the dump,
 * which a chmod at the end could not prevent.
 */
#[AsCommand(name: 'app:db:backup', description: 'Writes a consistent database backup: VACUUM INTO for SQLite, mariadb-dump/mysqldump for MySQL/MariaDB')]
final readonly class DbBackupCommand
{
    /** Client tools usable for MySQL/MariaDB; the MariaDB one is preferred when both exist. */
    public const array DUMP_TOOLS = ['mariadb-dump', 'mysqldump'];

    public function __construct(
        private Connection $connection,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[Argument('Target file (default: var/backup/zwille-<timestamp>.sqlite for SQLite, var/backup/zwille-<timestamp>.sql.gz for MySQL/MariaDB)')] ?string $target = null): int
    {
        $platform = $this->connection->getDatabasePlatform();

        if ($platform instanceof SQLitePlatform) {
            return $this->backupSqlite($io, $target);
        }

        if ($platform instanceof AbstractMySQLPlatform) {
            return $this->backupMysql($io, $target);
        }

        $io->error('Only SQLite and MySQL/MariaDB databases can be backed up with this command; use the tools of your database for other platforms.');

        return Command::FAILURE;
    }

    private function backupSqlite(SymfonyStyle $io, ?string $target): int
    {
        $target ??= \sprintf('%s/var/backup/zwille-%s.sqlite', $this->projectDir, date('Ymd-His'));
        if (file_exists($target)) {
            $io->error("$target already exists.");

            return Command::FAILURE;
        }

        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
            $io->error("Cannot create $dir.");

            return Command::FAILURE;
        }

        $source = $this->connection->getParams()['path'] ?? null;
        if (!\is_string($source) || !is_file($source)) {
            $io->error('The SQLite database file could not be determined.');

            return Command::FAILURE;
        }
        // Own connection: VACUUM is not allowed inside a transaction of the app connection.
        self::privately(static fn () => new \PDO('sqlite:'.$source)->prepare('VACUUM INTO ?')->execute([$target]));

        // The copy contains password hashes, TOTP secrets and API tokens: owner only.
        clearstatcache(true, $target);
        if (!chmod($target, 0o600) || 0o600 !== (fileperms($target) & 0o777)) {
            $io->error(\sprintf('Cannot restrict the permissions of %s to 0600.', $target));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Backup written to %s (%s KB).', $target, number_format((int) filesize($target) / 1024)));

        return Command::SUCCESS;
    }

    private function backupMysql(SymfonyStyle $io, ?string $target): int
    {
        $tool = $this->findDumpTool();
        if (null === $tool) {
            $io->error(\sprintf(
                'Neither "%s" was found in PATH: install the MariaDB or MySQL client tools (e.g. the "mariadb-client" or "mysql-client" package) and run this command again.',
                implode('" nor "', self::DUMP_TOOLS),
            ));

            return Command::FAILURE;
        }

        $target ??= \sprintf('%s/var/backup/zwille-%s.sql.gz', $this->projectDir, date('Ymd-His'));
        if (file_exists($target)) {
            $io->error("$target already exists.");

            return Command::FAILURE;
        }

        $dir = \dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0o770, true) && !is_dir($dir)) {
            $io->error("Cannot create $dir.");

            return Command::FAILURE;
        }

        // Dump to a plain .sql file first and compress afterwards: the client tools write
        // gzip themselves only through version-specific flags, a stream keeps it simple.
        $gzip = str_ends_with(strtolower($target), '.gz');
        $dumpTarget = $gzip ? substr($target, 0, -3) : $target;
        if ('' === $dumpTarget) {
            $dumpTarget = $target.'.sql';
        }
        // The plain dump is an intermediate file that is deleted afterwards: never let it
        // overwrite (and then remove) a file that happens to have that name.
        if ($gzip && file_exists($dumpTarget)) {
            $io->error("$dumpTarget already exists (used as the intermediate file for $target).");

            return Command::FAILURE;
        }

        $params = $this->connection->getParams();
        $database = $params['dbname'] ?? null;
        if (!\is_string($database) || '' === $database) {
            $io->error('The database name could not be determined from DATABASE_URL.');

            return Command::FAILURE;
        }

        $process = new Process(array_merge([$tool], $this->dumpArguments($params, $database, $dumpTarget, $this->needsColumnStatisticsOff($tool))));
        // The password goes through the environment, not the argument list, so it does not
        // show up in the process list (ps) or the shell history.
        $process->setEnv(['MYSQL_PWD' => (string) ($params['password'] ?? '')]);
        $process->setTimeout(null);
        // The child inherits the umask, so the tool creates the dump owner-only from the start.
        self::privately(static fn () => $process->run());

        if (!$process->isSuccessful()) {
            if (is_file($dumpTarget)) {
                unlink($dumpTarget);
            }
            $io->error(\sprintf(
                'The database dump failed: %s',
                trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'unknown error',
            ));

            return Command::FAILURE;
        }

        if ($gzip) {
            if (!self::privately(fn (): bool => $this->gzip($dumpTarget, $target))) {
                unlink($dumpTarget);
                $io->error(\sprintf('Cannot compress the dump to %s.', $target));

                return Command::FAILURE;
            }
            unlink($dumpTarget);
        }

        // The dump contains password hashes, TOTP secrets and API tokens: owner only.
        clearstatcache(true, $target);
        if (!chmod($target, 0o600) || 0o600 !== (fileperms($target) & 0o777)) {
            $io->error(\sprintf('Cannot restrict the permissions of %s to 0600.', $target));

            return Command::FAILURE;
        }

        $io->success(\sprintf('Backup written to %s (%s KB).', $target, number_format((int) filesize($target) / 1024)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private function dumpArguments(array $params, string $database, string $dumpTarget, bool $columnStatisticsOff): array
    {
        $arguments = [
            '--single-transaction',
            '--quick',
            // MySQL 8 otherwise needs the PROCESS privilege for the tablespace section, which
            // an application account normally lacks; the app does not use tablespaces.
            '--no-tablespaces',
            '--default-character-set='.(\is_string($params['charset'] ?? null) ? $params['charset'] : 'utf8mb4'),
            '--result-file='.$dumpTarget,
        ];

        if ($columnStatisticsOff) {
            $arguments[] = '--column-statistics=0';
        }

        $socket = $params['unix_socket'] ?? null;
        if (\is_string($socket) && '' !== $socket) {
            // A Unix socket replaces host and port.
            $arguments[] = '--socket='.$socket;
        } else {
            $arguments[] = '--host='.(\is_string($params['host'] ?? null) ? $params['host'] : '127.0.0.1');
            if (isset($params['port']) && '' !== (string) $params['port']) {
                $arguments[] = '--port='.(string) $params['port'];
            }
        }

        $user = $params['user'] ?? null;
        if (\is_string($user) && '' !== $user) {
            $arguments[] = '--user='.$user;
        }

        $arguments[] = $database;

        return $arguments;
    }

    /**
     * MySQL's own mysqldump (8.0+) queries information_schema.COLUMN_STATISTICS, which MariaDB
     * does not have, and aborts. On MariaDB servers that client needs --column-statistics=0;
     * mariadb-dump (and the "mysqldump" alias MariaDB ships) does not know the flag at all.
     */
    private function needsColumnStatisticsOff(string $tool): bool
    {
        if (!$this->connection->getDatabasePlatform() instanceof MariaDBPlatform) {
            return false;
        }

        $version = new Process([$tool, '--version']);
        $version->run();

        return $version->isSuccessful() && !str_contains($version->getOutput(), 'MariaDB');
    }

    /**
     * Runs $operation with a 0077 umask, so every file it (or a child process) creates is
     * owner-only from the first byte.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    private static function privately(callable $operation): mixed
    {
        $previous = umask(0o077);
        try {
            return $operation();
        } finally {
            umask($previous);
        }
    }

    private function findDumpTool(): ?string
    {
        $finder = new ExecutableFinder();
        foreach (self::DUMP_TOOLS as $tool) {
            $path = $finder->find($tool);
            if (null !== $path) {
                return $path;
            }
        }

        return null;
    }

    private function gzip(string $source, string $target): bool
    {
        $in = fopen($source, 'rb');
        if (false === $in) {
            return false;
        }

        $out = gzopen($target, 'wb9');
        if (false === $out) {
            fclose($in);

            return false;
        }

        $ok = true;
        while (!feof($in)) {
            $chunk = fread($in, 1 << 20);
            if (false === $chunk) {
                $ok = false;
                break;
            }
            if (false === gzwrite($out, $chunk)) {
                $ok = false;
                break;
            }
        }

        fclose($in);
        if (!gzclose($out)) {
            $ok = false;
        }

        return $ok;
    }
}
