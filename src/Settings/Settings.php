<?php

declare(strict_types=1);

namespace App\Settings;

use App\Branding\AccentPalette;
use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Runtime settings editable in the admin area. Values are cached in cache.app and
 * invalidated on every write; unknown keys fall back to the defaults below.
 */
final class Settings implements ResetInterface
{
    public const string TITLE = 'app.title';
    public const string ACCENT = 'branding.accent';
    public const string LOGO = 'branding.logo';
    public const string REQUIRE_2FA_ADMINS = 'security.require_2fa_admins';
    public const string CODE_LENGTH = 'links.code_length';
    public const string DEFAULT_REDIRECT = 'links.default_redirect_type';
    public const string LINK_FETCH_TITLE = 'links.fetch_title';
    public const string TRACKING = 'tracking.enabled';
    public const string TRACK_COUNTRY = 'tracking.country';
    /** Kill switch: while off, every agent token is refused. */
    public const string AGENT_ACCESS = 'api.agent_access';

    public const array DEFAULTS = [
        self::TITLE => 'EatMyShort.Link',
        // Simpsons yellow – a nod to the name, like the program itself.
        self::ACCENT => '#fed90f',
        self::LOGO => null,
        self::REQUIRE_2FA_ADMINS => '0',
        self::CODE_LENGTH => '6',
        self::DEFAULT_REDIRECT => '302',
        self::LINK_FETCH_TITLE => '1',
        self::TRACKING => '1',
        self::TRACK_COUNTRY => '1',
        self::AGENT_ACCESS => '1',
    ];

    private const string CACHE_KEY = 'app_settings_v2';
    private const string LOGO_CACHE_KEY = 'app_settings_logo';
    /** Presence flag inside the bulk cache; the (large) data URI has its own key. */
    private const string LOGO_PRESENT_KEY = '_branding.logo_present';

    /** @var array<string, ?string>|null */
    private ?array $values = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {
    }

    public function get(string $key): ?string
    {
        if (self::LOGO === $key) {
            // Loaded on demand only: the data URI can be hundreds of kilobytes and must not
            // travel with every settings lookup on the redirect hot path.
            return $this->getLogo();
        }

        $this->values ??= $this->cache->get(self::CACHE_KEY, fn (): array => $this->load());

        return \array_key_exists($key, $this->values) ? $this->values[$key] : (self::DEFAULTS[$key] ?? null);
    }

    public function getString(string $key): string
    {
        return (string) $this->get($key);
    }

    public function getInt(string $key): int
    {
        return (int) $this->get($key);
    }

    public function getBool(string $key): bool
    {
        return '1' === $this->get($key);
    }

    /** Accent colour plus the readable colours derived from it; falls back to the default accent. */
    public function getAccentPalette(): AccentPalette
    {
        try {
            return AccentPalette::fromHex($this->getString(self::ACCENT));
        } catch (\InvalidArgumentException) {
            return AccentPalette::fromHex((string) self::DEFAULTS[self::ACCENT]);
        }
    }

    /** Whether a custom logo is configured (cheap: reads the presence flag from the bulk cache). */
    public function hasLogo(): bool
    {
        $this->values ??= $this->cache->get(self::CACHE_KEY, fn (): array => $this->load());

        return '1' === ($this->values[self::LOGO_PRESENT_KEY] ?? null);
    }

    /** The logo data URI. Only call this where the image is actually rendered. */
    public function getLogo(): ?string
    {
        $logo = $this->cache->get(self::LOGO_CACHE_KEY, fn (): ?string => $this->em->getRepository(Setting::class)->find(self::LOGO)?->getValue());

        return \is_string($logo) ? $logo : null;
    }

    /** @param array<string, string|int|bool|null> $values */
    public function save(array $values): void
    {
        $repo = $this->em->getRepository(Setting::class);
        foreach ($values as $key => $value) {
            if (!\array_key_exists($key, self::DEFAULTS)) {
                throw new \InvalidArgumentException(\sprintf('Unknown setting "%s".', $key));
            }
            $value = \is_bool($value) ? ($value ? '1' : '0') : (null === $value ? null : (string) $value);
            $setting = $repo->find($key);
            if (null === $setting) {
                $this->em->persist(new Setting($key, $value));
            } else {
                $setting->setValue($value);
            }
        }
        $this->em->flush();
        $this->cache->delete(self::CACHE_KEY);
        $this->cache->delete(self::LOGO_CACHE_KEY);
        $this->values = null;
    }

    public function reset(): void
    {
        $this->values = null;
    }

    /** @return array<string, ?string> */
    private function load(): array
    {
        $values = [];
        $hasLogo = false;
        foreach ($this->em->getRepository(Setting::class)->findAll() as $setting) {
            if (self::LOGO === $setting->getKey()) {
                // Keep the bulk cache small: only remember that a logo exists.
                $hasLogo = null !== $setting->getValue() && '' !== $setting->getValue();
                continue;
            }
            $values[$setting->getKey()] = $setting->getValue();
        }
        $values[self::LOGO_PRESENT_KEY] = $hasLogo ? '1' : null;

        return $values;
    }
}
