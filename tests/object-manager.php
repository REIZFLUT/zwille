<?php

declare(strict_types=1);

use App\Kernel;

require __DIR__.'/../vendor/autoload.php';

// PHPStan (phpstan-doctrine) bootet hier einen ObjectManager. Seit dem Entfernen der
// .env-Dateien liefert dieses Skript die dafuer noetigen Werte selbst - nur fuer die
// statische Analyse, nicht fuer den echten Betrieb.
foreach ([
    'APP_ENV' => 'dev',
    'APP_SECRET' => 'phpstan-analysis-secret-0123456789abcdef',
    'APP_ADMIN_HOSTS' => 'localhost',
    'DEFAULT_URI' => 'http://localhost',
    'DATABASE_URL' => 'sqlite:///%kernel.project_dir%/var/data/zwille.sqlite',
    'MAILER_DSN' => 'null://null',
] as $name => $value) {
    $_SERVER[$name] ??= $_ENV[$name] ?? $value;
    $_ENV[$name] = $_SERVER[$name];
}

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) ($_SERVER['APP_DEBUG'] ?? false));
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
