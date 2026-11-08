<?php

declare(strict_types=1);

namespace App\Tests\Plugin;

/**
 * Scratch directory helpers for the plugin tests: build a throwaway plugins/ tree, write
 * files into it and remove it again.
 */
trait TempDirectoryTrait
{
    private string $tempDir = '';

    protected function createTempDir(): string
    {
        $this->tempDir = sys_get_temp_dir().'/zwille-plugins-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o777, true);

        return $this->tempDir;
    }

    protected function writeFile(string $relativePath, string $contents): string
    {
        $path = $this->tempDir.'/'.$relativePath;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);

        return $path;
    }

    protected function removeTempDir(): void
    {
        if ('' === $this->tempDir || !is_dir($this->tempDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->tempDir);
        $this->tempDir = '';
    }
}
