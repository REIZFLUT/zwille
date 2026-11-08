<?php

declare(strict_types=1);

namespace App\Twig;

use App\Branding\AccentPalette;
use App\Domain\HostRegistry;
use App\Entity\Link;
use App\Entity\User;
use App\EventListener\SecurityHeadersListener;
use App\Link\LinkAvailability;
use App\Security\AdminAccess;
use App\Settings\Settings;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Exception\MissingResourceException;
use Symfony\Component\Intl\Languages;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final readonly class AppExtension
{
    public function __construct(
        private RequestStack $requestStack,
        private Settings $settings,
        private HostRegistry $hosts,
        private AdminAccess $adminAccess,
        private Security $security,
        private LinkAvailability $availability,
        private LocaleSwitcher $localeSwitcher,
        #[Autowire('%env(default:app.default_timezone:APP_TIMEZONE)%')]
        private string $timezone,
    ) {
    }

    /**
     * Date patterns per UI language. English uses day-month-year with a month name and a
     * 24-hour clock (en_GB) - unambiguous for an international audience.
     */
    private const array DATE_PATTERNS = [
        'de' => ['locale' => 'de_DE', 'date' => 'dd.MM.y', 'datetime' => 'dd.MM.y HH:mm', 'seconds' => 'dd.MM.y HH:mm:ss', 'day' => 'dd.MM.'],
        'en' => ['locale' => 'en_GB', 'date' => 'd MMM y', 'datetime' => 'd MMM y, HH:mm', 'seconds' => 'd MMM y, HH:mm:ss', 'day' => 'd MMM'],
    ];

    /** Integer with the thousands separator of the UI language (3.516 / 3,516). */
    #[AsTwigFilter('local_number')]
    public function localNumber(int|float $number): string
    {
        $formatted = new \NumberFormatter($this->patterns()['locale'], \NumberFormatter::DECIMAL)->format($number);

        return false === $formatted ? (string) $number : $formatted;
    }

    /** Date in the UI language, in the app time zone (like Twig's |date). */
    #[AsTwigFilter('local_date')]
    public function localDate(\DateTimeInterface $date): string
    {
        return $this->formatDate($date, $this->patterns()['date']);
    }

    /** Date and time in the UI language, in the app time zone. */
    #[AsTwigFilter('local_datetime')]
    public function localDateTime(\DateTimeInterface $date, bool $seconds = false): string
    {
        return $this->formatDate($date, $this->patterns()[$seconds ? 'seconds' : 'datetime']);
    }

    /** @return array{locale: string, date: string, datetime: string, seconds: string, day: string} */
    private function patterns(): array
    {
        return self::DATE_PATTERNS[substr($this->localeSwitcher->getLocale(), 0, 2)] ?? self::DATE_PATTERNS['en'];
    }

    private function formatDate(\DateTimeInterface $date, string $pattern, ?\DateTimeZone $zone = null): string
    {
        $formatter = new \IntlDateFormatter($this->patterns()['locale'], \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, $zone ?? new \DateTimeZone($this->timezone), null, $pattern);
        $formatted = $formatter->format($date);

        return false === $formatted ? $date->format('Y-m-d H:i') : $formatted;
    }

    /** Admin rights that are actually usable from the current address. */
    #[AsTwigFunction('is_admin')]
    public function isAdmin(): bool
    {
        $user = $this->security->getUser();

        return $user instanceof User && $this->adminAccess->isAdmin($user);
    }

    #[AsTwigFunction('csp_nonce')]
    public function cspNonce(): string
    {
        $request = $this->requestStack->getMainRequest();

        return null === $request ? '' : SecurityHeadersListener::nonce($request);
    }

    #[AsTwigFunction('setting')]
    public function setting(string $key): ?string
    {
        return $this->settings->get($key);
    }

    #[AsTwigFunction('app_title')]
    public function appTitle(): string
    {
        return $this->settings->getString(Settings::TITLE);
    }

    /**
     * The link's current status ("live", "inactive", "scheduled", "exhausted" or "expired"),
     * decided by the one service that also governs the redirect - the list chip and the
     * status filter therefore never disagree with it.
     */
    #[AsTwigFunction('link_status')]
    public function linkStatus(Link $link): string
    {
        return $this->availability->status($link);
    }

    /** For e-mails, which can't use the CSS tokens: accent background and the text colour for it. */
    #[AsTwigFunction('accent_palette')]
    public function accentPalette(): AccentPalette
    {
        return $this->settings->getAccentPalette();
    }

    #[AsTwigFunction('has_custom_logo')]
    public function hasCustomLogo(): bool
    {
        return $this->settings->hasLogo();
    }

    #[AsTwigFunction('primary_admin_host')]
    public function primaryAdminHost(): string
    {
        return $this->hosts->getPrimaryAdminHost();
    }

    #[AsTwigFilter('country_name')]
    public function countryName(?string $code, ?string $locale = null): string
    {
        if (null === $code || '' === $code) {
            return '–';
        }
        try {
            return Countries::getName(strtoupper($code), $locale);
        } catch (MissingResourceException) {
            return strtoupper($code);
        }
    }

    #[AsTwigFilter('language_name')]
    public function languageName(?string $code, ?string $locale = null): string
    {
        if (null === $code || '' === $code) {
            return '–';
        }
        try {
            return Languages::getName(str_replace('-', '_', $code), $locale);
        } catch (MissingResourceException) {
            return $code;
        }
    }

    /**
     * Bar chart as plain SVG markup (no JS, no inline styles; colours via CSS classes).
     *
     * @param list<array{day: \DateTimeImmutable, clicks: int, visitors: int}> $series
     */
    #[AsTwigFunction('bar_chart', isSafe: ['html'])]
    public function barChart(array $series, string $label = ''): string
    {
        $width = 720;
        $height = 180;
        $padTop = 12;
        $padBottom = 22;
        $count = max(1, \count($series));
        $max = max(1, ...array_map(static fn (array $p): int => $p['clicks'], $series ?: [['clicks' => 0]]));
        $slot = $width / $count;
        $barWidth = max(1.0, $slot * 0.72);
        $chartHeight = $height - $padTop - $padBottom;

        $bars = '';
        foreach ($series as $i => $point) {
            $h = $point['clicks'] > 0 ? max(2.0, $point['clicks'] / $max * $chartHeight) : 0.0;
            $x = $i * $slot + ($slot - $barWidth) / 2;
            $y = $padTop + $chartHeight - $h;
            $title = htmlspecialchars(\sprintf('%s: %s / %s', $this->formatDate($point['day'], $this->patterns()['date'], $point['day']->getTimezone()), $this->localNumber($point['clicks']), $this->localNumber($point['visitors'])), \ENT_QUOTES);
            $bars .= \sprintf(
                '<g class="chart-bar"><rect class="chart-hit" x="%.2f" y="%d" width="%.2f" height="%d"/><rect class="chart-fill" x="%.2f" y="%.2f" width="%.2f" height="%.2f" rx="1.5"/><title>%s</title></g>',
                $i * $slot, $padTop, $slot, $chartHeight, $x, $y, $barWidth, $h, $title,
            );
        }

        $labels = '';
        $step = max(1, (int) ceil($count / 8));
        foreach ($series as $i => $point) {
            if (0 === $i % $step) {
                $anchor = 0 === $i ? 'start' : ($i === $count - 1 ? 'end' : 'middle');
                $x = 0 === $i ? $i * $slot : ($i === $count - 1 ? ($i + 1) * $slot : $i * $slot + $slot / 2);
                $labels .= \sprintf('<text class="chart-label" x="%.2f" y="%d" text-anchor="%s">%s</text>', $x, $height - 6, $anchor, htmlspecialchars($this->formatDate($point['day'], $this->patterns()['day'], $point['day']->getTimezone()), \ENT_QUOTES));
            }
        }

        return \sprintf(
            '<svg class="chart" viewBox="0 0 %d %d" role="img" aria-label="%s"><line class="chart-axis" x1="0" y1="%d" x2="%d" y2="%d"/><text class="chart-label" x="2" y="%d">%s</text>%s%s</svg>',
            $width, $height, htmlspecialchars($label, \ENT_QUOTES), $padTop + $chartHeight, $width, $padTop + $chartHeight, $padTop + 2, $this->localNumber($max), $bars, $labels,
        );
    }
}
