<?php

declare(strict_types=1);

namespace App\Controller\Admin;

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
            'agentAccess' => $settings->getBool(Settings::AGENT_ACCESS),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{title: string, accent: string, logo: ?UploadedFile, removeLogo: bool, codeLength: int, defaultRedirect: int, fetchTitle: bool, tracking: bool, trackCountry: bool, require2faAdmins: bool, agentAccess: bool} $data */
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
                Settings::AGENT_ACCESS => $data['agentAccess'],
            ];
            if ($data['removeLogo']) {
                $values[Settings::LOGO] = null;
            } elseif (null !== $data['logo']) {
                $values[Settings::LOGO] = \sprintf('data:%s;base64,%s', $data['logo']->getMimeType(), base64_encode((string) file_get_contents($data['logo']->getPathname())));
            }
            $settings->save($values);
            $this->addFlash('success', 'flash.settings_saved');

            return $this->redirectToRoute('app_admin_settings');
        }

        return $this->render('admin/settings.html.twig', [
            'form' => $form,
            'geoip_available' => $geoIp->isAvailable(),
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
