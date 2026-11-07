<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Audit\AuditLogger;
use App\Form\SettingsType;
use App\Settings\Settings;
use App\Tracking\GeoIpLookup;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ADMIN_ACCESS')]
final class SettingsController extends AbstractController
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    #[Route('/admin/settings', name: 'app_admin_settings', methods: ['GET', 'POST'])]
    public function __invoke(Request $request, Settings $settings, GeoIpLookup $geoIp): Response
    {
        $form = $this->createForm(SettingsType::class, [
            'title' => $settings->getString(Settings::TITLE),
            'accent' => $settings->getString(Settings::ACCENT),
            'codeLength' => $settings->getInt(Settings::CODE_LENGTH),
            'defaultRedirect' => $settings->getInt(Settings::DEFAULT_REDIRECT),
            'fetchTitle' => $settings->getBool(Settings::LINK_FETCH_TITLE),
            'tracking' => $settings->getBool(Settings::TRACKING),
            'trackCountry' => $settings->getBool(Settings::TRACK_COUNTRY),
            'require2faAdmins' => $settings->getBool(Settings::REQUIRE_2FA_ADMINS),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{title: string, accent: string, logo: ?UploadedFile, removeLogo: bool, codeLength: int, defaultRedirect: int, fetchTitle: bool, tracking: bool, trackCountry: bool, require2faAdmins: bool} $data */
            $data = $form->getData();
            $values = [
                Settings::TITLE => $data['title'],
                Settings::ACCENT => preg_match('/^#[0-9a-f]{6}$/i', $data['accent']) ? strtolower($data['accent']) : Settings::DEFAULTS[Settings::ACCENT],
                Settings::CODE_LENGTH => $data['codeLength'],
                Settings::DEFAULT_REDIRECT => $data['defaultRedirect'],
                Settings::LINK_FETCH_TITLE => $data['fetchTitle'],
                Settings::TRACKING => $data['tracking'],
                Settings::TRACK_COUNTRY => $data['trackCountry'],
                Settings::REQUIRE_2FA_ADMINS => $data['require2faAdmins'],
            ];
            if ($data['removeLogo']) {
                $values[Settings::LOGO] = null;
            } elseif (null !== $data['logo']) {
                $values[Settings::LOGO] = \sprintf('data:%s;base64,%s', $data['logo']->getMimeType(), base64_encode((string) file_get_contents($data['logo']->getPathname())));
            }
            // Snapshot the current values first: save() overwrites them, and a change is
            // recorded as old -> new (secret-like settings only as "changed", never the value).
            $before = [];
            foreach (array_keys(Settings::DEFAULTS) as $key) {
                $before[$key] = $settings->get($key);
            }
            $settings->save($values);
            $changes = self::changes($values, $before);
            if ([] !== $changes) {
                $this->audit->record(AuditLogger::SETTINGS_CHANGED, ['changes' => $changes], targetType: 'setting');
            }
            $this->addFlash('success', 'flash.settings_saved');

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
            'geoip_available' => $geoIp->isAvailable(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * The "what changed" payload: each changed key with old -> new, except keys that carry a
     * secret (password, token, ... and the logo blob) - those only record that they changed,
     * never the value itself.
     *
     * @param array<string, mixed>   $values values about to be saved
     * @param array<string, ?string> $before snapshot taken before the save
     *
     * @return array<string, array<string, mixed>>
     */
    private static function changes(array $values, array $before): array
    {
        $changes = [];
        foreach ($values as $key => $value) {
            $normalized = \is_bool($value) ? ($value ? '1' : '0') : (null === $value ? null : (string) $value);
            $previous = $before[$key] ?? null;
            if ($previous === $normalized) {
                continue;
            }
            $changes[$key] = AuditLogger::isSensitiveKey($key)
                ? ['changed' => true]
                : ['from' => $previous, 'to' => $normalized];
        }

        return $changes;
    }
}
