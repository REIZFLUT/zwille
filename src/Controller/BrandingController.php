<?php

declare(strict_types=1);

namespace App\Controller;

use App\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-defined branding, served as separate resources so the CSP can stay free
 * of inline styles.
 */
final class BrandingController extends AbstractController
{
    #[Route('/branding/theme.css', name: 'app_branding_css', methods: ['GET'])]
    public function css(Request $request, Settings $settings): Response
    {
        $palette = $settings->getAccentPalette();
        $css = \sprintf(
            // Dark: an explicit choice (data-theme, set by js/theme-init.js) or the system setting
            // unless the user chose light - the same two selectors app.css uses for its tokens.
            ":root{--accent:%s;--accent-ink:%s;--accent-text:%s;}\n"
            .":root[data-theme=\"dark\"]{--accent-text:%s;}\n"
            ."@media (prefers-color-scheme: dark){:root:not([data-theme=\"light\"]){--accent-text:%s;}}\n",
            $palette->accent,
            $palette->ink,
            $palette->textOnLight,
            $palette->textOnDark,
            $palette->textOnDark,
        );
        $response = new Response($css, Response::HTTP_OK, ['Content-Type' => 'text/css; charset=utf-8']);
        $response->setEtag(md5($css));
        $response->setPublic();
        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }

    /** The logo mark in the accent colour, with the "mouth" in the matching contrast colour. */
    #[Route('/branding/favicon.svg', name: 'app_branding_favicon', methods: ['GET'])]
    public function favicon(Request $request, Settings $settings): Response
    {
        $palette = $settings->getAccentPalette();
        $response = $this->render('branding/favicon.svg.twig', ['accent' => $palette->accent]);
        $response->headers->set('Content-Type', 'image/svg+xml');
        $response->setEtag(md5((string) $response->getContent()));
        $response->setPublic();
        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }

    #[Route('/branding/logo', name: 'app_branding_logo', methods: ['GET'])]
    public function logo(Request $request, Settings $settings): Response
    {
        $dataUri = $settings->getLogo();
        if (null === $dataUri || 1 !== preg_match('#^data:(image/(?:svg\+xml|png|webp));base64,(.+)$#', $dataUri, $m)) {
            throw $this->createNotFoundException();
        }

        $response = new Response((string) base64_decode($m[2], true), Response::HTTP_OK, [
            'Content-Type' => $m[1],
            // Opened directly, an uploaded SVG must not be able to run scripts on this origin.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; sandbox",
        ]);
        $response->setEtag(md5($dataUri));
        $response->setPublic();
        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
