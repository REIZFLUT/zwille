<?php

/*
 * Laufzeit-Konfiguration ohne .env-Datei im Repository.
 *
 * Diese Datei wird von public/index.php und bin/console VOR dem Symfony-Runtime
 * (vendor/autoload_runtime.php) eingebunden. Sie entscheidet, welche dot-env-Datei
 * geladen wird, baut die Datenbank-DSN aus den DB_*-Variablen und bricht ohne jegliche
 * Konfiguration klar ab, statt den Symfony-Runtime still auf den Dev-Modus
 * zurueckfallen zu lassen.
 *
 * Geladen werden:
 *  - im Testlauf (APP_ENV=test) ausschliesslich .env.test,
 *  - sonst .env, ersatzweise die git-ignorierte .env.local.
 * .env.example wird NIE automatisch geladen - sie ist nur eine Kopiervorlage.
 *
 * Vorrang-Regeln (siehe docs/INSTALL.md): echte Umgebungsvariablen > .env.local > .env;
 * eine gesetzte DATABASE_URL gewinnt immer gegen die DB_*-Variablen.
 */

declare(strict_types=1);

$projectDir = dirname(__DIR__);

// PHP-CLI-Server und FPM-Pools ohne "E" in variables_order stellen Prozess-
// Umgebungsvariablen nur ueber getenv() bereit, nicht in $_SERVER/$_ENV. Dotenv und
// die env-Prozessoren von Symfony lesen aber $_SERVER/$_ENV - ohne diesen Schritt
// wuerde eine vorhandene .env.local die echten (z. B. im FPM-Pool gesetzten) Werte
// ueberschreiben. Nur HTTP_/REDIRECT_ (vom SAPI aus Request-Headern gefuellt) bleiben
// aussen vor; vorhandene Eintraege werden nie ueberschrieben.
foreach (getenv() as $name => $value) {
    if ('' === $name || str_starts_with($name, 'HTTP_') || str_starts_with($name, 'REDIRECT_')) {
        continue;
    }
    $_SERVER[$name] ??= $value;
    $_ENV[$name] ??= $value;
}

// APP_ENV vor dem Runtime-Lauf aus der echten Umgebung lesen.
$env = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null;

// Zu verwendende dot-env-Datei bestimmen (null = keine vorhanden).
$dotenvFile = null;

if ('test' === $env) {
    if (is_file($projectDir.'/.env.test')) {
        $dotenvFile = '.env.test';
    }
} else {
    foreach (['.env', '.env.local'] as $candidate) {
        if (is_file($projectDir.'/'.$candidate)) {
            $dotenvFile = $candidate;
            break;
        }
    }
}

// Fail-fast: ohne dot-env-Datei UND ohne gesetztes APP_ENV gibt es keine
// Konfiguration. Ohne .env-Datei muss APP_ENV (und die uebrige Konfiguration) als
// echte Umgebungsvariable gesetzt oder .env.example nach .env kopiert werden.
if (null === $dotenvFile && null === $env) {
    $message = 'Zwille: Es liegt keine .env-Datei im Projekt und APP_ENV ist nicht gesetzt. '
        .'Entweder .env.example nach .env kopieren oder APP_ENV (und die uebrige Konfiguration) '
        .'als echte Umgebungsvariable setzen - siehe docs/INSTALL.md.';

    if ('cli' === \PHP_SAPI) {
        fwrite(\STDERR, $message."\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Serverkonfiguration fehlt.';
    exit;
}

// Die dot-env-Datei hier selbst laden, damit die DSN-Komposition unten die DB_*-Werte
// sieht. Der Symfony-Runtime wird danach mitgeteilt, sie nicht ein zweites Mal zu laden.
// Der Composer-Autoloader steht an dieser Stelle noch nicht zur Verfuegung
// (autoload_runtime.php bindet ihn erst danach ein - und ein fruehes require von
// autoload.php wuerde jene generierte Datei dazu bringen, sich ohne App-Start zu
// beenden). Deshalb nur fuer die hier genutzte Dotenv-Komponente einen eng begrenzten
// Autoloader registrieren.
if (null !== $dotenvFile) {
    $vendorDir = $projectDir.'/vendor';

    spl_autoload_register(static function (string $class) use ($vendorDir): void {
        $prefix = 'Symfony\\Component\\Dotenv\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $file = $vendorDir.'/symfony/dotenv/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    });

    (new Symfony\Component\Dotenv\Dotenv())->bootEnv($projectDir.'/'.$dotenvFile, 'dev', ['test']);
}

// Datenbank-DSN bestimmen: eine gesetzte DATABASE_URL gewinnt, sonst wird sie aus den
// DB_*-Variablen gebaut (Default SQLite). Bei unbekanntem DB_CONNECTION bricht der Start
// klar ab, statt still auf SQLite zurueckzufallen.
require_once $projectDir.'/src/Doctrine/DatabaseUrlComposer.php';

// Vor dem Zusammensetzen merken, ob schon eine DATABASE_URL vorliegt (echte
// Umgebungsvariable oder dot-env-Datei) - dann bleibt sie unveraendert.
$explicitDatabaseUrl = $_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? null;
$explicitDatabaseUrl = \is_string($explicitDatabaseUrl) ? trim($explicitDatabaseUrl) : null;

try {
    $databaseUrl = (new App\Doctrine\DatabaseUrlComposer($projectDir))->compose();
} catch (InvalidArgumentException $exception) {
    $message = 'Zwille: '.$exception->getMessage().' Siehe docs/INSTALL.md.';

    if ('cli' === \PHP_SAPI) {
        fwrite(\STDERR, $message."\n");
        exit(1);
    }

    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Datenbank-Konfiguration fehlerhaft.';
    exit;
}

// doctrine.yaml liest die URL ueber '%env(resolve:DATABASE_URL)%', und "resolve" deutet
// "%name%" als Container-Parameter. Eine selbst gebaute DSN kann aber selbst
// Prozent-Escapes enthalten (URL-kodierte Zugangsdaten, Socket-Pfade) - die Verdopplung
// hier schuetzt sie; resolve() macht daraus wieder ein einfaches Prozent. Eine selbst
// geschriebene DATABASE_URL bleibt unangetastet (dafuer gelten die ueblichen
// Symfony-Escape-Regeln, genau wie bisher).
if (null === $explicitDatabaseUrl || '' === $explicitDatabaseUrl) {
    $databaseUrl = str_replace('%', '%%', $databaseUrl);
}

$_SERVER['DATABASE_URL'] = $_ENV['DATABASE_URL'] = $databaseUrl;

// Dem Symfony-Runtime die Projekt-Datei nennen und die dot-env-Behandlung abnehmen: sie
// ist oben bereits erledigt, ein zweiter Durchlauf wuerde nur Arbeit und Fehlerquellen
// duplizieren.
$_SERVER['APP_RUNTIME_OPTIONS'] = [
    'project_dir' => $projectDir,
    'disable_dotenv' => true,
];
