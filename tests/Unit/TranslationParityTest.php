<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every catalogue exists twice (German and English) and four packages in a row appended keys to
 * them, so the two files drifting apart is the most likely silent mistake of that work: a missing
 * English key only shows up as a German word in the English UI.
 */
final class TranslationParityTest extends TestCase
{
    public function testEveryLocalePairCarriesTheSameKeys(): void
    {
        $directory = \dirname(__DIR__, 2).'/translations';
        $pairs = 0;

        foreach (glob($directory.'/*.de.yaml') ?: [] as $german) {
            $english = $directory.'/'.basename($german, '.de.yaml').'.en.yaml';
            self::assertFileExists($english, 'the English counterpart of '.basename($german).' is missing');

            $germanKeys = self::flatten(Yaml::parseFile($german));
            $englishKeys = self::flatten(Yaml::parseFile($english));

            $missingEnglish = array_diff($germanKeys, $englishKeys);
            $missingGerman = array_diff($englishKeys, $germanKeys);

            self::assertSame([], array_values($missingEnglish), basename($german).': English is missing these keys');
            self::assertSame([], array_values($missingGerman), basename($english).': German is missing these keys');
            ++$pairs;
        }

        self::assertGreaterThan(0, $pairs, 'no German catalogue was found to compare');
    }

    /**
     * @param array<array-key, mixed> $tree
     *
     * @return list<string> dotted key paths, sorted so a diff reads like a list of names
     */
    private static function flatten(array $tree, string $prefix = ''): array
    {
        $keys = [];
        foreach ($tree as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix.'.'.$key;
            if (\is_array($value)) {
                $keys = [...$keys, ...self::flatten($value, $path)];

                continue;
            }
            $keys[] = $path;
        }
        sort($keys);

        return $keys;
    }
}
