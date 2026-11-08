<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

$projectDir = dirname(__DIR__);

$sqliteDatabase = $projectDir.'/var/data/test.sqlite';

// No .env file is part of the repository (see .env.example), so the test configuration is
// defined here and every run behaves the same. putenv() matters: the console kernel below
// and any child process inherit the real process environment, not $_SERVER.
//
// DATABASE_URL is the one exception: a caller may point the suite at another driver
// (MariaDB/MySQL) by exporting DATABASE_URL. Any SQLite value - including the one a developer
// may have exported for the dev environment - is ignored and mapped to the dedicated test file
// below, so the SQLite path never depends on the ambient environment.
$databaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? (getenv('DATABASE_URL') ?: null);
$isSqlite = !\is_string($databaseUrl) || '' === $databaseUrl
    || str_starts_with($databaseUrl, 'sqlite:')
    || str_starts_with($databaseUrl, 'sqlite3:');
if ($isSqlite) {
    $databaseUrl = 'sqlite:///'.$sqliteDatabase;
}

$testEnv = [
    'KERNEL_CLASS' => 'App\\Kernel',
    'APP_ENV' => 'test',
    'APP_SECRET' => '$ecretf0rt3st',
    'DEFAULT_URI' => 'http://localhost',
    'DATABASE_URL' => $databaseUrl,
    'APP_ADMIN_HOSTS' => 'admin.zwille.test',
    'ADMIN_IP_ALLOWLIST' => '',
    'MAILER_DSN' => 'null://null',
    'MAILER_FROM' => 'Zwille <noreply@localhost>',
];
foreach ($testEnv as $name => $value) {
    putenv($name.'='.$value);
    $_SERVER[$name] = $_ENV[$name] = $value;
}

require $projectDir.'/vendor/autoload.php';

// The example plugins under plugins/ are not part of the test whitelist (APP_PLUGIN_DIR
// points at tests/Fixtures/plugins), so the kernel does not register their namespaces while
// the suite runs. The tests still exercise those classes directly - and register the UTM
// listener as a test service - so teach the autoloader where to find them here. Classes are
// loaded on demand only; nothing is loaded through the whitelist.
foreach ([
    'Plugin\\UtmTagging\\' => $projectDir.'/plugins/utm-tagging/src',
    'Plugin\\ClickWebhook\\' => $projectDir.'/plugins/click-webhook/src',
] as $prefix => $path) {
    spl_autoload_register(static function (string $class) use ($prefix, $path): void {
        if ($prefix === substr($class, 0, \strlen($prefix))) {
            $file = $path.'/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

// Fresh test database built from the real migrations (DAMA rolls back every test).
if ('test' === $_SERVER['APP_ENV']) {
    // In-process on purpose: a subprocess could resolve another environment or project dir.
    $application = new Application(new Kernel('test', true));
    $application->setAutoExit(false);

    if ($isSqlite) {
        // SQLite: throw the file away so the first and every later run start from nothing.
        // Nothing else has to be cleaned: an empty database file is created by the migration.
        foreach ([$sqliteDatabase, $sqliteDatabase.'-wal', $sqliteDatabase.'-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $commands = [['command' => 'doctrine:migrations:migrate']];
    } else {
        // MariaDB/MySQL: the database itself is created by the caller, so only its contents are
        // reset. --full-database also removes tables the mapping does not know about (leftovers
        // of an older run), and the real migrations then rebuild the schema - this makes a
        // repeated run behave exactly like the first one, with no test data surviving.
        // Nothing SQLite-specific (file deletion) happens on this path.
        $commands = [
            ['command' => 'doctrine:schema:drop', '--full-database' => true, '--force' => true],
            ['command' => 'doctrine:migrations:migrate'],
        ];
    }

    foreach ($commands as $command) {
        $exitCode = $application->run(new ArrayInput($command + [
            '--no-interaction' => true,
            '--quiet' => true,
        ]));
        if (0 !== $exitCode) {
            exit($exitCode);
        }
    }
}
