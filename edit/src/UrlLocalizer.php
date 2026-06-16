<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * Makes static output location-agnostic: site-internal absolute URLs are turned
 * into base-relative ones and pages get a per-page relative <base href>, so the
 * same files work at the domain root or in any subdirectory, over http or https.
 *
 * Used by SiteGenerator (generated pages + published stylesheet) and
 * ExternalSiteImporter (imported pages + mirrored stylesheets).
 */
final class UrlLocalizer
{
    /**
     * Localize a full HTML page. Internal absolute URLs in href/src/action/poster,
     * srcset, and inline/style url(...) become base-relative; a relative <base href>
     * (depth-derived from the page's canonical served path) is injected at the top
     * of <head>, replacing any existing <base>.
     */
    public static function localizeHtml(string $html, string $relativePath, string $siteBaseUrl = '/'): string
    {
        $prefix = self::relativePrefix($relativePath);

        // Drop any existing <base> so resolution is governed by ours (idempotent).
        $html = preg_replace('#\s*<base\b[^>]*>#i', '', $html) ?? $html;

        $html = preg_replace_callback(
            '#\b(href|src|action|poster)\s*=\s*(["\'])([^"\']*)\2#i',
            static fn(array $m): string => $m[1] . '=' . $m[2] . self::toBaseRelative($m[3], $siteBaseUrl) . $m[2],
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '#\bsrcset\s*=\s*(["\'])([^"\']*)\1#i',
            static function (array $m) use ($siteBaseUrl): string {
                $candidates = array_map(
                    static function (string $candidate) use ($siteBaseUrl): string {
                        $candidate = trim($candidate);
                        if ($candidate === '') {
                            return $candidate;
                        }
                        $parts = preg_split('/\s+/', $candidate, 2) ?: [$candidate];
                        $parts[0] = self::toBaseRelative($parts[0], $siteBaseUrl);
                        return implode(' ', $parts);
                    },
                    explode(',', $m[2])
                );
                return 'srcset=' . $m[1] . implode(', ', $candidates) . $m[1];
            },
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
            static fn(array $m): string => 'url(' . $m[1] . self::toBaseRelative($m[2], $siteBaseUrl) . $m[1] . ')',
            $html
        ) ?? $html;

        $baseTag = '<base href="' . h($prefix) . '">';
        if (preg_match('#<head\b[^>]*>#i', $html) === 1) {
            $html = preg_replace('#(<head\b[^>]*>)#i', '$1' . $baseTag, $html, 1) ?? $html;
        }

        return $html;
    }

    /**
     * Rewrite url(...) references in a stylesheet so they resolve relative to the
     * stylesheet's own location (CSS url() ignores the document <base>). This makes
     * mirrored/published CSS work regardless of where the site is deployed.
     *
     * @param string $cssDirFromRoot directory containing the CSS file, relative to
     *                               the site root (e.g. "assets" or
     *                               "assets/imported/host/wp-content/themes/x").
     */
    public static function localizeCssUrls(string $css, string $cssDirFromRoot, string $siteBaseUrl = '/'): string
    {
        return preg_replace_callback(
            '#url\(\s*(["\']?)([^"\')]+)\1\s*\)#i',
            static function (array $m) use ($cssDirFromRoot, $siteBaseUrl): string {
                $rootRelative = self::rootRelative($m[2], $siteBaseUrl);
                if ($rootRelative === null) {
                    return $m[0];
                }
                $rel = self::relativePathBetween($cssDirFromRoot, $rootRelative);
                return 'url(' . $m[1] . $rel . $m[1] . ')';
            },
            $css
        ) ?? $css;
    }

    /**
     * Convert a single site-internal absolute URL to a base-relative one, leaving
     * external/protocol-relative/fragment/data and already-relative URLs untouched.
     */
    public static function toBaseRelative(string $url, string $siteBaseUrl = '/'): string
    {
        $url = trim($url);
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return $url;
        }

        $siteBaseUrl = self::normalizeBase($siteBaseUrl);
        if ($siteBaseUrl !== '/' && str_starts_with($url . '/', $siteBaseUrl)) {
            $rest = substr($url, strlen($siteBaseUrl) - 1);
        } else {
            $rest = $url;
        }
        $rest = ltrim($rest, '/');

        return $rest === '' ? './' : $rest;
    }

    /**
     * The relative prefix that points at the site root from a page's canonical
     * (clean-URL, trailing-slash) served location.
     */
    public static function relativePrefix(string $relativePath): string
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');

        if ($path === '' || $path === 'index.html') {
            $depth = 0;
        } else {
            if (str_ends_with($path, '/index.html')) {
                $served = substr($path, 0, -strlen('/index.html'));
            } else {
                $served = preg_replace('/\.html?$/i', '', $path) ?? $path;
            }
            $served = trim($served, '/');
            $depth = $served === '' ? 0 : substr_count($served, '/') + 1;
        }

        return $depth === 0 ? './' : str_repeat('../', $depth);
    }

    /** Root-relative form (no leading slash) of an internal absolute URL, or null. */
    private static function rootRelative(string $url, string $siteBaseUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            return null;
        }

        $rest = self::toBaseRelative($url, $siteBaseUrl);
        $rest = ltrim($rest, '/');
        if ($rest === '' || $rest === './') {
            return null;
        }

        return $rest;
    }

    /** Relative path from a directory to a target file path (both root-relative). */
    private static function relativePathBetween(string $fromDir, string $toPath): string
    {
        $from = array_values(array_filter(explode('/', trim($fromDir, '/')), static fn(string $s): bool => $s !== ''));
        $to = array_values(array_filter(explode('/', $toPath), static fn(string $s): bool => $s !== ''));

        $common = 0;
        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            $common++;
        }

        $up = count($from) - $common;
        $down = array_slice($to, $common);
        $rel = str_repeat('../', $up) . implode('/', $down);

        return $rel === '' ? './' : $rel;
    }

    private static function normalizeBase(string $siteBaseUrl): string
    {
        $siteBaseUrl = '/' . trim($siteBaseUrl, '/');
        return $siteBaseUrl === '/' ? '/' : $siteBaseUrl . '/';
    }
}
