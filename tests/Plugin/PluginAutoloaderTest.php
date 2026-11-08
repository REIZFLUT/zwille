<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

use App\Plugin\PluginAutoloader;
use App\Plugin\PluginMetadata;
use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\TestCase;

final class PluginAutoloaderTest extends TestCase
{
    private const string PLUGIN = 'autoload-probe';

    private string $directory;

    protected function setUp(): void
    {
        // Fixed path on purpose: the Composer class loader is a process-wide singleton, so
        // reusing one directory keeps the "same prefix, same path" idempotency check stable.
        $this->directory = sys_get_temp_dir().'/zwille-plugin-autoloader/'.self::PLUGIN;
        if (!is_dir($this->directory.'/src')) {
            mkdir($this->directory.'/src', 0o777, true);
        }
        file_put_contents($this->directory.'/src/Greeter.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Plugin\AutoloadProbe;

            final class Greeter
            {
            }
            PHP);
    }

    public function testRegistersPsr4PrefixDerivedFromThePluginName(): void
    {
        (new PluginAutoloader())->register($this->plugin());

        self::assertTrue(class_exists('Plugin\\AutoloadProbe\\Greeter'));
        self::assertContains($this->directory.'/src', $this->classLoader()->getPrefixesPsr4()['Plugin\\AutoloadProbe\\'] ?? []);
    }

    public function testRegistrationIsIdempotent(): void
    {
        $autoloader = new PluginAutoloader();
        $autoloader->register($this->plugin());
        $autoloader->register($this->plugin());

        self::assertSame([$this->directory.'/src'], $this->classLoader()->getPrefixesPsr4()['Plugin\\AutoloadProbe\\']);
    }

    private function plugin(): PluginMetadata
    {
        return new PluginMetadata(self::PLUGIN, '1.0.0', 'Autoload fixture.', null, [], $this->directory);
    }

    private function classLoader(): ClassLoader
    {
        $loader = array_values(ClassLoader::getRegisteredLoaders())[0] ?? null;
        if ($loader instanceof ClassLoader) {
            return $loader;
        }

        foreach (spl_autoload_functions() as $function) {
            if (\is_array($function) && $function[0] instanceof ClassLoader) {
                return $function[0];
            }
        }

        self::fail('No Composer class loader is registered.');
    }
}
