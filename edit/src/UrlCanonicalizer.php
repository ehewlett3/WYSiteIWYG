<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * IMP-1: one identity per resource. The importer keys pages, assets, local
 * paths and its de-duplication on these canonical forms, so the same file
 * referenced as www/apex, http/https, with cache-busting or tracking query
 * parameters, or through an image CDN proxy is stored exactly once.
 *
 * Keys are identities, not fetch URLs: they always use https, the site's apex
 * host for every equivalent source host, normalized percent-encoding, and a
 * sorted query without parameters that never change the content.
 */
final class UrlCanonicalizer
{
    /** Tracking parameters, dropped everywhere. */
    private const ALWAYS_DROP = [
        'fbclid', 'gclid', 'dclid', 'msclkid', 'mc_cid', 'mc_eid', '_ga', '_gl', 'yclid', 'igshid', 'ref_src',
        '_hsenc', '_hsmi', 'mkt_tok', 'wbraid', 'gbraid',
    ];

    /** Parameters that make a WordPress page look new without changing it. */
    private const PAGE_DROP = [
        'replytocom', 'share', 'nb', 'like_comment', 'shared', 'preview_id', 'preview_nonce', '_wpnonce', 'amp',
    ];

    /** Cache busters: the asset is the same file whatever the value. */
    private const ASSET_DROP = [
        'ver', 'v', 'version', 'rev', 'cb', 'cachebuster', 'cache', '_', 't', 'ts', 'timestamp', 'tmstv', 'nocache',
        'itok', 'hash', 'build',
    ];

    /** Resize/format parameters of image CDN proxies (dropped when preferring originals). */
    private const PROXY_PARAMS = ['resize', 'w', 'h', 'fit', 'ssl', 'strip', 'quality', 'zoom', 'crop', 'lb'];

    /** @var array<string, true> */
    private array $sourceHosts = [];
    /** @var string[] */
    private array $extraIgnored;
    private bool $preferOriginals;

    /**
     * @param string[] $sourceHosts hosts of the site being imported (www and apex are added automatically)
     * @param string[] $extraIgnored extra query parameter names to drop (Settings)
     */
    public function __construct(array $sourceHosts = [], array $extraIgnored = [], bool $preferOriginals = true)
    {
        foreach ($sourceHosts as $host) {
            $host = strtolower(trim((string) $host));
            if ($host === '') {
                continue;
            }
            $apex = preg_replace('/^www\./', '', $host) ?? $host;
            $this->sourceHosts[$apex] = true;
            $this->sourceHosts['www.' . $apex] = true;
        }
        $this->extraIgnored = array_values(array_filter(array_map(static fn($name): string => strtolower(trim((string) $name)), $extraIgnored)));
        $this->preferOriginals = $preferOriginals;
    }

    public function isSourceHost(string $host): bool
    {
        return isset($this->sourceHosts[strtolower($host)]);
    }

    /** Canonical key for an HTML page URL. */
    public function page(string $url): string
    {
        return $this->normalize($url, true);
    }

    /** Canonical key for any other resource (image, CSS, PDF, feed, ...). */
    public function asset(string $url): string
    {
        return $this->normalize($url, false);
    }

    /**
     * The URL to download for an asset: the origin for image CDN proxies when
     * originals are preferred, otherwise the URL as referenced.
     */
    public function fetchUrl(string $url): string
    {
        $unproxied = $this->unproxy($url);
        return $unproxied !== null && $this->preferOriginals ? $unproxied : $url;
    }

    /** Folder name under assets/imported/ for a host: one folder per site. */
    public function hostFolder(string $host): string
    {
        $host = strtolower(trim($host));
        if ($this->isSourceHost($host)) {
            $host = preg_replace('/^www\./', '', $host) ?? $host;
        }
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $host), '.-');
        return $clean !== '' ? $clean : 'external';
    }

    private function normalize(string $url, bool $isPage): string
    {
        $url = trim($url);
        $proxied = $this->unproxy($url);
        $fromProxy = $proxied !== null;
        if ($fromProxy) {
            $url = $proxied;
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $url;
        }

        $host = strtolower((string) $parts['host']);
        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $host = $ascii;
            }
        }
        if ($this->isSourceHost($host)) {
            $host = preg_replace('/^www\./', '', $host) ?? $host;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $portSuffix = $port !== null && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) ? ':' . $port : '';

        $path = $this->normalizePath((string) ($parts['path'] ?? '/'));
        if ($isPage) {
            $path = preg_replace('#/index\.(?:html?|php)$#i', '/', $path) ?? $path;
            if ($path !== '/' && pathinfo($path, PATHINFO_EXTENSION) === '' && !str_ends_with($path, '/')) {
                $path .= '/';
            }
        }

        $query = $this->normalizeQuery((string) ($parts['query'] ?? ''), $isPage, $fromProxy && $this->preferOriginals);

        return 'https://' . $host . $portSuffix . $path . ($query !== '' ? '?' . $query : '');
    }

    /** https://<origin host>/<path> for an image CDN proxy URL, else null. */
    private function unproxy(string $url): ?string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || preg_match('/^i[0-3]\.wp\.com$/i', (string) ($parts['host'] ?? '')) !== 1) {
            return null;
        }

        $path = ltrim((string) ($parts['path'] ?? ''), '/');
        $slash = strpos($path, '/');
        if ($slash === false) {
            return null;
        }
        $originHost = substr($path, 0, $slash);
        if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $originHost) !== 1) {
            return null;
        }

        $query = (string) ($parts['query'] ?? '');
        $query = $this->preferOriginals ? $this->dropParams($query, self::PROXY_PARAMS) : $query;

        return 'https://' . strtolower($originHost) . substr($path, $slash) . ($query !== '' ? '?' . $query : '');
    }

    private function normalizePath(string $path): string
    {
        // Decode unreserved characters, uppercase every other escape.
        $path = (string) preg_replace_callback('/%([0-9A-Fa-f]{2})/', static function (array $m): string {
            $char = chr((int) hexdec($m[1]));
            return preg_match('/[A-Za-z0-9\-._~]/', $char) === 1 ? $char : '%' . strtoupper($m[1]);
        }, $path);

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        if (str_ends_with($path, '/') && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized;
    }

    private function normalizeQuery(string $query, bool $isPage, bool $dropProxyParams): string
    {
        $drop = array_merge(self::ALWAYS_DROP, $this->extraIgnored, $isPage ? self::PAGE_DROP : self::ASSET_DROP);
        if ($dropProxyParams) {
            $drop = array_merge($drop, self::PROXY_PARAMS);
        }

        return $this->dropParams($query, $drop, true);
    }

    /** @param string[] $names */
    private function dropParams(string $query, array $names, bool $sort = false): string
    {
        if ($query === '') {
            return '';
        }

        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            $name = strtolower(rawurldecode((string) explode('=', $pair, 2)[0]));
            if (in_array($name, $names, true) || str_starts_with($name, 'utm_')) {
                continue;
            }
            $pairs[] = $pair;
        }

        if ($sort) {
            sort($pairs, SORT_STRING);
        }

        return implode('&', $pairs);
    }
}
