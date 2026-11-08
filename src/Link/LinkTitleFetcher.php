<?php

declare(strict_types=1);

namespace App\Link;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Fetches the page <title> (or og:title) of a target URL, the way YOURLS does when a
 * link is shortened.
 *
 * The fetcher never throws: DNS problems, SSRF rejections, timeouts, HTTP errors, wrong
 * content types and broken HTML all result in null so that link creation continues
 * undisturbed.
 *
 * SSRF protection comes from Symfony's NoPrivateNetworkHttpClient: it resolves the host
 * itself, rejects every address in IpUtils::PRIVATE_SUBNETS (loopback, RFC 1918, CGNAT,
 * link local, NAT64, 6to4, ...), pins the checked address for the connection so DNS
 * rebinding cannot swap in another one, and repeats the check for every redirect hop
 * before that hop is requested.
 */
final class LinkTitleFetcher
{
    private const int MAX_BYTES = 262144; // 256 KB
    private const int MAX_TITLE_LENGTH = 255;
    private const int MAX_REDIRECTS = 2;

    private readonly HttpClientInterface $client;

    public function __construct(
        HttpClientInterface $client,
        private readonly LoggerInterface $logger,
    ) {
        $this->client = new NoPrivateNetworkHttpClient($client);
    }

    public function fetch(string $url): ?string
    {
        $scheme = mb_strtolower((string) parse_url($url, \PHP_URL_SCHEME));
        if (!\in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        try {
            // Worst case (two redirects, each hop at the duration limit) stays below ten
            // seconds; creating a link waits for this.
            $response = $this->client->request('GET', $url, [
                'timeout' => 2,
                'max_duration' => 3,
                'max_redirects' => self::MAX_REDIRECTS,
                'headers' => [
                    'User-Agent' => 'Zwille Titelabruf',
                    'Accept' => 'text/html',
                ],
            ]);

            return $this->titleFromResponse($response);
        } catch (\Throwable $e) {
            $this->logger->debug('Titelabruf für "{url}" fehlgeschlagen: {message}', ['url' => $url, 'message' => $e->getMessage()]);

            return null;
        }
    }

    /** Reads and validates the final response (redirects were followed by the client). */
    private function titleFromResponse(ResponseInterface $response): ?string
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $response->cancel();

            return null;
        }

        $contentType = mb_strtolower($response->getHeaders(false)['content-type'][0] ?? '');
        if (!str_contains($contentType, 'text/html') && !str_contains($contentType, 'application/xhtml+xml')) {
            $response->cancel();

            return null;
        }

        return $this->extractTitle($this->readLimited($response));
    }

    /** Reads at most MAX_BYTES of the body and aborts the transfer once the limit is hit. */
    private function readLimited(ResponseInterface $response): string
    {
        $content = '';
        foreach ($this->client->stream($response) as $chunk) {
            if ($chunk->isTimeout()) {
                continue;
            }
            $content .= $chunk->getContent();
            if (\strlen($content) >= self::MAX_BYTES) {
                $response->cancel();
                $content = substr($content, 0, self::MAX_BYTES);
                break;
            }
        }

        return $content;
    }

    private function extractTitle(string $html): ?string
    {
        if ('' === trim($html)) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            // The XML prologue pins the parser to UTF-8; otherwise libxml assumes Latin-1
            // and mangles every multi-byte character in the title.
            $loaded = $document->loadHTML('<?xml encoding="UTF-8">'.$html, \LIBXML_NOERROR | \LIBXML_NOWARNING | \LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return null;
        }

        $title = $this->metaContent($document, 'og:title') ?? $this->nodeText($document, 'title');
        if (null === $title) {
            return null;
        }

        $title = html_entity_decode($title, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
        $title = trim((string) preg_replace('/\s+/u', ' ', $title));
        if ('' === $title) {
            return null;
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    private function metaContent(\DOMDocument $document, string $property): ?string
    {
        foreach ($document->getElementsByTagName('meta') as $meta) {
            $name = mb_strtolower($meta->getAttribute('property'));
            if ('' === $name) {
                $name = mb_strtolower($meta->getAttribute('name'));
            }
            if ($name === $property && '' !== trim($meta->getAttribute('content'))) {
                return $meta->getAttribute('content');
            }
        }

        return null;
    }

    private function nodeText(\DOMDocument $document, string $tag): ?string
    {
        $node = $document->getElementsByTagName($tag)->item(0);
        if (!$node instanceof \DOMNode) {
            return null;
        }
        $text = trim($node->textContent);

        return '' === $text ? null : $text;
    }
}
