<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Link;
use App\Entity\LinkDeviceTarget;
use App\Entity\LinkLocalisation;
use App\Entity\User;
use App\Link\LinkTargetResolver;
use App\Tracking\UserAgentInspector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * The device-resolution matrix of the single resolution point: device beats language beats the
 * default target, and an unknown device (bots, empty user agent) counts as desktop. A link
 * without device targets must resolve exactly as the previous language-then-default logic did.
 */
final class LinkTargetResolverTest extends TestCase
{
    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const string IPAD = 'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
    private const string ANDROID = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36';
    private const string DESKTOP = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';
    private const string BOT = 'Googlebot/2.1 (+http://www.google.com/bot.html)';

    private function resolver(): LinkTargetResolver
    {
        return new LinkTargetResolver(new UserAgentInspector(new ArrayAdapter()));
    }

    private function request(?string $userAgent, ?string $language = null): Request
    {
        $request = new Request();
        if (null !== $userAgent) {
            $request->headers->set('User-Agent', $userAgent);
        }
        if (null !== $language) {
            $request->headers->set('Accept-Language', $language);
        }

        return $request;
    }

    private function link(): Link
    {
        return new Link(new User())->setCode('geheim')->setTargetUrl('https://www.example.com/default');
    }

    public function testDeviceBeatsLanguageBeatsDefault(): void
    {
        $link = $this->link()
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('ios')->setTargetUrl('https://www.example.com/ios'))
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('android')->setTargetUrl('https://www.example.com/android'))
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('desktop')->setTargetUrl('https://www.example.com/desktop'))
            ->addLocalisation(new LinkLocalisation()->setLanguage('en')->setTargetUrl('https://www.example.com/en'));

        $resolver = $this->resolver();

        self::assertSame('https://www.example.com/ios', $resolver->resolve($link, $this->request(self::IPHONE, 'en')));
        self::assertSame('https://www.example.com/ios', $resolver->resolve($link, $this->request(self::IPAD, 'fr')));
        self::assertSame('https://www.example.com/android', $resolver->resolve($link, $this->request(self::ANDROID, 'en')));
        // A desktop visitor with an English header still gets the desktop target: the device wins.
        self::assertSame('https://www.example.com/desktop', $resolver->resolve($link, $this->request(self::DESKTOP, 'en')));
    }

    public function testUnknownDeviceAndBotFallBackToLanguageThenDefault(): void
    {
        // Only ios and android are configured, so a desktop visitor falls through to the language.
        $link = $this->link()
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('ios')->setTargetUrl('https://www.example.com/ios'))
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('android')->setTargetUrl('https://www.example.com/android'))
            ->addLocalisation(new LinkLocalisation()->setLanguage('en')->setTargetUrl('https://www.example.com/en'));

        $resolver = $this->resolver();

        self::assertSame('https://www.example.com/en', $resolver->resolve($link, $this->request(self::DESKTOP, 'en')));
        self::assertSame('https://www.example.com/default', $resolver->resolve($link, $this->request(self::DESKTOP, 'fr')));
        // A bot counts as desktop: no desktop target -> language -> default.
        self::assertSame('https://www.example.com/default', $resolver->resolve($link, $this->request(self::BOT, 'fr')));
        self::assertSame('https://www.example.com/default', $resolver->resolve($link, $this->request(null)));
    }

    public function testBotGetsTheDesktopTargetWhenOneIsSet(): void
    {
        $link = $this->link()
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('ios')->setTargetUrl('https://www.example.com/ios'))
            ->addDeviceTarget(new LinkDeviceTarget()->setDevice('desktop')->setTargetUrl('https://www.example.com/desktop'));

        self::assertSame('https://www.example.com/desktop', $this->resolver()->resolve($link, $this->request(self::BOT)));
    }

    public function testLinkWithoutDeviceTargetsKeepsTheOldBehaviour(): void
    {
        $link = $this->link()
            ->addLocalisation(new LinkLocalisation()->setLanguage('en')->setTargetUrl('https://www.example.com/en'));
        $resolver = $this->resolver();

        self::assertSame('https://www.example.com/en', $resolver->resolve($link, $this->request(self::IPHONE, 'en-GB,en;q=0.8')));
        self::assertSame('https://www.example.com/default', $resolver->resolve($link, $this->request(self::IPHONE, 'fr')));

        $plain = $this->link();
        self::assertSame('https://www.example.com/default', $resolver->resolve($plain, $this->request(self::ANDROID, 'en')));
    }

    public function testDeviceKeyOfARequest(): void
    {
        $resolver = $this->resolver();

        self::assertSame('ios', $resolver->device($this->request(self::IPHONE)));
        self::assertSame('android', $resolver->device($this->request(self::ANDROID)));
        self::assertSame('desktop', $resolver->device($this->request(self::DESKTOP)));
        self::assertSame('desktop', $resolver->device($this->request(self::BOT)));
        self::assertSame('desktop', $resolver->device($this->request(null)));
    }
}
