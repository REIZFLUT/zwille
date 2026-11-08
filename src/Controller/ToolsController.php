<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The bookmarklet page: drag-and-drop links that call back into THIS installation.
 * No query is sent anywhere else and no third-party script is embedded (strict CSP).
 */
final class ToolsController extends AbstractController
{
    #[Route('/tools/bookmarklet', name: 'app_tools_bookmarklet', methods: ['GET'])]
    public function bookmarklet(): Response
    {
        // Absolute URLs on the admin host that serves this request, e.g. https://admin.example.com/links/new.
        $newUrl = $this->generateUrl('app_link_new', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $quickUrl = $this->generateUrl('app_link_quick', [], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->render('tools/bookmarklet.html.twig', [
            'bookmarklets' => [
                'standard' => $this->buildBookmarklet($newUrl),
                'instant' => $this->buildBookmarklet($quickUrl),
                'custom' => $this->buildBookmarklet($quickUrl, "+(c?'&code='+encodeURIComponent(c):'')", 'Wunschkürzel (leer = zufällig):'),
                'goto' => $this->buildBookmarklet($quickUrl, "+'&goto=1'"),
            ],
        ]);
    }

    /**
     * Builds a javascript:-URL. The window opens with the current page URL and title taken
     * from the browser (encodeURIComponent -> valid percent-encoded query values). Static
     * parts are assembled in PHP; the prompt text is percent-encoded with rawurlencode() so
     * the whole href stays pure ASCII, and decodeURIComponent() turns it back into text.
     */
    private function buildBookmarklet(string $base, string $suffix = '', ?string $prompt = null): string
    {
        $js = 'var u=encodeURIComponent(location.href),t=encodeURIComponent(document.title);';
        if (null !== $prompt) {
            // rawurlencode() here and decodeURIComponent() in the browser: the snippet keeps
            // working no matter how the target page encodes umlauts.
            $js .= "var c=prompt(decodeURIComponent('".rawurlencode($prompt)."'));if(null===c)return;";
        }
        $js .= \sprintf(
            "window.open('%s?url='+u+'&title='+t%s,'zwille','width=720,height=640');",
            $base,
            $suffix,
        );

        return 'javascript:(function(){'.$js.'})()';
    }
}
