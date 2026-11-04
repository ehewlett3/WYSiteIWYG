<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * EDT-C: the CSS that keeps a copied section's original look in its new place.
 * Each copied section gets an id like wy-c-k3x9q2; its rules live in
 * /assets/wysite-components.css between "wy:<id> begin/end" comments. Only
 * rules whose selectors are that section's own classes are accepted, so this
 * CSS can never restyle anything else on the site.
 */
final class ComponentStyles
{
    public const FILE = 'assets/wysite-components.css';
    private const MAX_BYTES = 65_536;

    private string $rootPath;
    private string $siteBaseUrl;

    public function __construct(string $rootPath, string $siteBaseUrl = '/')
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->siteBaseUrl = '/' . trim($siteBaseUrl, '/');
        $this->siteBaseUrl = $this->siteBaseUrl === '/' ? '/' : $this->siteBaseUrl . '/';
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^wy-c-[a-z0-9]{6}$/', $id) === 1;
    }

    /** Validate and clean one section's CSS; throws when it targets anything else. */
    public function validate(string $id, string $css): string
    {
        if (!self::isValidId($id)) {
            throw new RuntimeException('Invalid section style id.');
        }
        if (strlen($css) > self::MAX_BYTES) {
            throw new RuntimeException('The copied section\'s styles are too large (over 64 KB).');
        }

        $css = Sanitizer::css($css, [$this->siteBaseUrl . 'assets/', 'assets/']);
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $this->assertOwnSelectors($id, $css);

        // Absolute site paths become relative to /assets/ (where the file lives).
        return trim(UrlLocalizer::localizeCssUrls($css, 'assets', $this->siteBaseUrl));
    }

    /** Store (or replace) a section's rules. */
    public function save(string $id, string $css): void
    {
        $css = $this->validate($id, $css);
        $file = $this->rootPath . '/' . self::FILE;
        Filesystem::withLock($file, function () use ($file, $id, $css): void {
            $current = is_file($file) ? (string) file_get_contents($file) : "/* Styles that keep copied sections looking like the original (WYSiteIWYG). */\n";
            $current = $this->without($current, $id);
            Filesystem::atomicWrite($file, rtrim($current) . "\n/* wy:" . $id . " begin */\n" . $css . "\n/* wy:" . $id . " end */\n");
        });
        AssetPolicy::ensureAssetsHtaccess($this->rootPath);
    }

    /** Make sure a page links the section stylesheet (spliced into <head>). */
    public function ensureLinked(string $pageFile): void
    {
        $html = (string) @file_get_contents($pageFile);
        if ($html === '' || str_contains($html, 'wysite-components.css') || stripos($html, '</head>') === false) {
            return;
        }
        $link = '<link rel="stylesheet" href="' . self::FILE . '">';
        $html = (string) preg_replace('#</head>#i', '  ' . $link . "\n</head>", $html, 1);
        Filesystem::atomicWrite($pageFile, $html);
    }

    /**
     * Remove groups no page uses any more.
     *
     * @param string[] $pageFiles absolute paths of every page
     * @return string[] removed ids
     */
    public function garbageCollect(array $pageFiles, bool $apply): array
    {
        $file = $this->rootPath . '/' . self::FILE;
        if (!is_file($file)) {
            return [];
        }
        $css = (string) file_get_contents($file);
        preg_match_all('#/\* wy:(wy-c-[a-z0-9]{6}) begin \*/#', $css, $matches);
        $used = [];
        foreach ($pageFiles as $page) {
            preg_match_all('/\bwy-c-[a-z0-9]{6}\b/', (string) @file_get_contents($page), $found);
            foreach ($found[0] as $id) {
                $used[$id] = true;
            }
        }

        $unused = array_values(array_filter($matches[1], static fn(string $id): bool => !isset($used[$id])));
        if ($apply && $unused !== []) {
            foreach ($unused as $id) {
                $css = $this->without($css, $id);
            }
            Filesystem::atomicWrite($file, $css);
        }

        return $unused;
    }

    /** Drop every group (copied sections then follow the current theme). */
    public function reset(): int
    {
        $file = $this->rootPath . '/' . self::FILE;
        if (!is_file($file)) {
            return 0;
        }
        $count = preg_match_all('#/\* wy:wy-c-[a-z0-9]{6} begin \*/#', (string) file_get_contents($file));
        Filesystem::atomicWrite($file, "/* Styles that keep copied sections looking like the original (WYSiteIWYG). */\n");
        return (int) $count;
    }

    private function without(string $css, string $id): string
    {
        return (string) preg_replace('#\n?/\* wy:' . preg_quote($id, '#') . ' begin \*/.*?/\* wy:' . preg_quote($id, '#') . ' end \*/\n?#s', "\n", $css);
    }

    /** Every style rule must select only this section's classes; at-rules may only be @media/@supports. */
    private function assertOwnSelectors(string $id, string $css): void
    {
        $own = '\.' . preg_quote($id, '/') . '(?:-\d{1,4})?';
        $depth = 0;
        $prelude = '';
        $length = strlen($css);
        $inBlock = [];
        for ($i = 0; $i < $length; $i++) {
            $char = $css[$i];
            if ($char === '{') {
                $text = trim($prelude);
                $prelude = '';
                if (str_starts_with($text, '@')) {
                    if (preg_match('/^@(media|supports|container|layer)\b/i', $text) !== 1) {
                        throw new RuntimeException('Copied section styles may only use @media and @supports.');
                    }
                    $inBlock[] = 'at';
                    continue;
                }
                foreach (explode(',', $text) as $selector) {
                    if (preg_match('/^' . $own . '(?:' . $own . ')*$/', trim($selector)) !== 1) {
                        throw new RuntimeException('Copied section styles may only target the copied section ("' . trim($selector) . '" is not allowed).');
                    }
                }
                $inBlock[] = 'rule';
                $depth++;
                // skip the declarations
                $close = strpos($css, '}', $i);
                if ($close === false) {
                    throw new RuntimeException('The copied section\'s styles are incomplete.');
                }
                $i = $close;
                array_pop($inBlock);
                $depth--;
                continue;
            }
            if ($char === '}') {
                if ($inBlock === []) {
                    throw new RuntimeException('The copied section\'s styles are unbalanced.');
                }
                array_pop($inBlock);
                $prelude = '';
                continue;
            }
            if ($char === ';' && $inBlock === [] && trim($prelude) !== '') {
                throw new RuntimeException('Copied section styles may not contain top-level statements.');
            }
            $prelude .= $char;
        }
        if (trim($prelude) !== '' || $inBlock !== []) {
            throw new RuntimeException('The copied section\'s styles are incomplete.');
        }
    }
}
