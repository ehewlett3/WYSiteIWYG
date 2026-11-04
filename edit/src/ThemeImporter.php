<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * THM-D: turn pages of another site (crawled into this one, or fetched from a
 * URL) into a self-contained theme. Each template is built from region
 * selectors (RegionSelector), every stylesheet, font, image and icon the
 * template's chrome uses is copied into edit/themes/<id>/assets/ and referenced
 * through {{THEME_ASSET_BASE}}, so the theme never loads anything from the
 * source site and can be exported and installed elsewhere.
 *
 * theme.json records the regions per kind, the content scope (the element that
 * wraps page content) and whether the template prints the page title.
 */
final class ThemeImporter
{
    private const MAX_ASSETS = 400;
    private const MAX_BYTES = 52_428_800;
    private const CONTENT_ROLES = ['page-content', 'blog-post-content', 'blog-index-content', 'blog-items'];

    private string $rootPath;
    private ExternalSiteImporter $importer;
    private ThemeManager $themes;
    /** @var array<string, string|null> source key => theme asset path */
    private array $assets = [];
    private int $bytes = 0;
    /** @var string[] CSS text seen, for theme variables */
    private array $css = [];

    public function __construct(string $rootPath, ExternalSiteImporter $importer, ThemeManager $themes)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->importer = $importer;
        $this->themes = $themes;
    }

    /**
     * Create a new theme from one source page per kind.
     *
     * @param array<string, array{html:string, base:string, local:bool, regions:array<string,string>, title?:string}> $sources
     * @param array{rights_confirmed?:bool, source_url?:string} $options
     */
    public function importFromPages(string $name, array $sources, array $options = []): string
    {
        if (empty($options['rights_confirmed'])) {
            throw new RuntimeException('Confirm that you have the right to reuse this site\'s design and assets.');
        }
        if (!isset($sources['page']) && !isset($sources['home'])) {
            throw new RuntimeException('A theme needs at least a page template: include a page layout.');
        }

        $id = $this->themes->createTheme($name);
        $sources += ['page' => $sources['home'] ?? null];
        foreach ($sources as $kind => $source) {
            if (is_array($source)) {
                $this->addTemplate($id, (string) $kind, $source);
            }
        }

        $this->themes->updateMeta($id, [
            'description' => 'Imported from ' . ($options['source_url'] ?? 'another site') . '.',
            'source' => ['url' => (string) ($options['source_url'] ?? ''), 'imported_at' => gmdate('c'), 'rights_confirmed' => true],
            'variables' => $this->variablesFromCss(),
        ]);

        return $id;
    }

    /**
     * Build (or replace) the $kind template of an existing theme from a source
     * page and its region selectors. Returns the template path relative to the
     * site root.
     *
     * @param array{html:string, base:string, local:bool, regions:array<string,string>, title?:string} $source
     */
    public function addTemplate(string $themeId, string $kind, array $source): string
    {
        $dom = HtmlDocument::parse(strip_active_content((string) $source['html']));
        $elements = [];
        foreach ((array) $source['regions'] as $role => $selector) {
            if (!is_string($selector) || $selector === '') {
                continue;
            }
            $matches = RegionSelector::match($selector, $dom);
            if ($matches === []) {
                throw new RuntimeException('The ' . $role . ' region (' . $selector . ') was not found on the ' . $kind . ' page.');
            }
            $elements[(string) $role] = $matches[0];
        }

        $contentElements = array_values(array_intersect_key($elements, array_flip(self::CONTENT_ROLES)));
        $rendersTitle = $this->templateTitle($dom, (string) ($source['title'] ?? ''), $contentElements);
        $this->localizeAssets($dom, (string) $source['base'], (bool) $source['local'], $themeId, $contentElements);

        $html = $this->importer->buildTemplateFromDom($dom, $kind, $elements);
        $filename = ['home' => 'home.html', 'page' => 'page.html', 'blog-post' => 'blog-post.html', 'blog' => 'blog-index.html'][$kind]
            ?? throw new RuntimeException('Unknown template kind: ' . $kind);
        $target = $this->themes->themeDirectory($themeId) . '/' . $filename;
        Filesystem::writeSitePage($target, $html);

        $meta = $this->themes->meta($themeId);
        $regions = is_array($meta['regions'] ?? null) ? $meta['regions'] : [];
        $regions[$kind] = array_filter((array) $source['regions'], 'is_string');
        $changes = ['regions' => $regions, 'renders_title' => $rendersTitle || !empty($meta['renders_title'])];
        $scope = (string) ($source['regions']['page-content'] ?? $source['regions']['blog-post-content'] ?? '');
        if ($scope !== '' && empty($meta['content_scope'])) {
            $changes['content_scope'] = $scope;
        }
        $this->themes->updateMeta($themeId, $changes);

        return 'edit/themes/' . $themeId . '/' . $filename;
    }

    /**
     * Save a template from the region designer: the designer's DOM paths are
     * resolved in the same HTML5 tree the browser built and stored as selectors.
     *
     * @param array{html:string, url:string, source?:string} $cached cached designer source
     * @param array<string, array> $selections role => DOM path
     */
    public function addTemplateFromSelections(string $themeId, string $kind, array $cached, array $selections): string
    {
        $dom = HtmlDocument::parse(strip_active_content((string) $cached['html']));
        $regions = [];
        foreach ($selections as $role => $path) {
            if (!is_string($role) || !is_array($path)) {
                continue;
            }
            $element = HtmlDocument::elementAt($dom, $path);
            if ($element === null) {
                throw new RuntimeException('The selected ' . $role . ' region no longer matches the page. Select it again.');
            }
            $regions[$role] = RegionSelector::generate($element);
        }

        $local = ($cached['source'] ?? '') === 'local';
        return $this->addTemplate($themeId, $kind, [
            'html' => (string) $cached['html'],
            'base' => $local ? '' : (string) $cached['url'],
            'local' => $local,
            'regions' => $regions,
        ]);
    }

    /**
     * The page's own title heading outside the content (e.g. .entry-title in an
     * entry header) becomes {{TITLE}}, so every page shows its own title.
     *
     * @param \DOMElement[] $contentElements
     */
    private function templateTitle(\DOMDocument $dom, string $titleSelector, array $contentElements): bool
    {
        $candidates = $titleSelector !== '' ? RegionSelector::match($titleSelector, $dom) : iterator_to_array($dom->getElementsByTagName('h1'));
        foreach ($candidates as $heading) {
            if (!$heading instanceof \DOMElement || $this->inside($heading, $contentElements)) {
                continue;
            }
            while ($heading->firstChild !== null) {
                $heading->removeChild($heading->firstChild);
            }
            $heading->appendChild($dom->createTextNode('{{TITLE}}'));
            return true;
        }

        return false;
    }

    /** @param \DOMElement[] $contentElements */
    private function localizeAssets(\DOMDocument $dom, string $base, bool $local, string $themeId, array $contentElements): void
    {
        $xpath = new \DOMXPath($dom);
        $targets = [
            ['//link[@href]', 'href'],
            ['//img[@src]', 'src'],
            ['//source[@src]', 'src'],
            ['//video[@poster]', 'poster'],
            ['//input[@type="image"][@src]', 'src'],
        ];
        foreach ($targets as [$query, $attribute]) {
            foreach (iterator_to_array($xpath->query($query) ?: []) as $element) {
                if (!$element instanceof \DOMElement || $this->inside($element, $contentElements)) {
                    continue;
                }
                if (strtolower($element->tagName) === 'link' && preg_match('/\b(stylesheet|icon|apple-touch-icon|mask-icon|manifest)\b/i', $element->getAttribute('rel')) !== 1) {
                    // canonical, alternate, preconnect, ... point at the old site
                    $element->parentNode?->removeChild($element);
                    continue;
                }
                $local_path = $this->asset($element->getAttribute($attribute), $base, $local, $themeId, 0);
                if ($local_path !== null) {
                    $element->setAttribute($attribute, '{{THEME_ASSET_BASE}}' . $local_path);
                } else {
                    $element->removeAttribute($attribute);
                }
            }
        }

        foreach (iterator_to_array($xpath->query('//img[@srcset] | //source[@srcset]') ?: []) as $element) {
            if (!$element instanceof \DOMElement || $this->inside($element, $contentElements)) {
                continue;
            }
            $candidates = [];
            foreach (explode(',', $element->getAttribute('srcset')) as $candidate) {
                $parts = preg_split('/\s+/', trim($candidate), 2) ?: [];
                $path = ($parts[0] ?? '') !== '' ? $this->asset($parts[0], $base, $local, $themeId, 0) : null;
                if ($path !== null) {
                    $candidates[] = '{{THEME_ASSET_BASE}}' . $path . (isset($parts[1]) ? ' ' . $parts[1] : '');
                }
            }
            $candidates === [] ? $element->removeAttribute('srcset') : $element->setAttribute('srcset', implode(', ', $candidates));
        }

        foreach (iterator_to_array($xpath->query('//*[@style]') ?: []) as $element) {
            if ($element instanceof \DOMElement && !$this->inside($element, $contentElements)) {
                $element->setAttribute('style', $this->rewriteCssUrls($element->getAttribute('style'), $base, $local, $themeId, '', 0, true));
            }
        }
        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $style) {
            if ($style instanceof \DOMElement && !$this->inside($style, $contentElements)) {
                $css = $this->rewriteCssUrls($style->textContent, $base, $local, $themeId, '', 0, true);
                $this->css[] = $css;
                $style->textContent = $css;
            }
        }
    }

    /**
     * Copy one referenced file into the theme. Returns its path under the
     * theme's assets/ folder, or null when it can't be used.
     */
    private function asset(string $reference, string $base, bool $local, string $themeId, int $depth): ?string
    {
        $reference = trim(html_entity_decode($reference, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($reference === '' || $reference[0] === '#' || preg_match('#^(data|mailto|tel|javascript|blob):#i', $reference) === 1) {
            return null;
        }
        if (str_contains($reference, '{{')) {
            return null;
        }

        $source = $this->resolve($reference, $base, $local);
        if ($source === null) {
            return null;
        }
        if (array_key_exists($source['key'], $this->assets)) {
            return $this->assets[$source['key']];
        }
        $this->assets[$source['key']] = null;
        if (count($this->assets) > self::MAX_ASSETS || $this->bytes > self::MAX_BYTES) {
            return null;
        }

        try {
            if ($source['file'] !== null) {
                $body = (string) file_get_contents($source['file']);
                $type = '';
            } else {
                $fetched = $this->importer->fetchResource($source['url']);
                $body = (string) $fetched['body'];
                $type = strtolower(trim(explode(';', (string) $fetched['content_type'])[0]));
            }
        } catch (RuntimeException) {
            return null;
        }
        $this->bytes += strlen($body);

        $path = $source['path'];
        if (pathinfo($path, PATHINFO_EXTENSION) === '') {
            $path .= '.' . (['text/css' => 'css', 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/svg+xml' => 'svg', 'image/webp' => 'webp', 'font/woff2' => 'woff2', 'font/woff' => 'woff', 'image/x-icon' => 'ico', 'image/gif' => 'gif'][$type] ?? 'bin');
        }
        $path = AssetPolicy::safeRelativePath($path);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'css') {
            $cssBase = $source['file'] !== null ? $source['relative'] : $source['url'];
            $body = $this->rewriteCssUrls($body, $cssBase, $source['file'] !== null, $themeId, dirname($path), $depth + 1, false);
            $this->css[] = $body;
        } elseif ($extension === 'svg') {
            $body = AssetPolicy::sanitizeSvg($body) ?? '';
            if ($body === '') {
                return null;
            }
        }

        $target = $this->themes->themeDirectory($themeId) . '/assets/' . $path;
        Filesystem::atomicWrite($target, $body);
        $this->assets[$source['key']] = $path;

        return $path;
    }

    /**
     * Resolve a reference to a local file (crawled copy) or a remote URL, and the
     * path it gets under the theme's assets/ folder.
     *
     * @return array{key:string, file:?string, url:string, relative:string, path:string}|null
     */
    private function resolve(string $reference, string $base, bool $local): ?array
    {
        if ($local && preg_match('#^(https?:)?//#i', $reference) !== 1) {
            // Local pages are base-relative to the site root; CSS references are
            // relative to the stylesheet ($base is then its root-relative path).
            $relative = $this->collapse((str_starts_with($reference, '/') ? '' : ($base !== '' && str_contains($base, '/') ? dirname($base) . '/' : '')) . ltrim((string) preg_replace('/[?#].*$/', '', $reference), '/'));
            if ($relative === '' || str_starts_with($relative, 'edit/') || !is_file($this->rootPath . '/' . $relative)) {
                return null;
            }
            $path = str_starts_with($relative, 'assets/imported/') ? substr($relative, strlen('assets/imported/')) : 'site/' . $relative;
            return ['key' => 'file:' . $relative, 'file' => $this->rootPath . '/' . $relative, 'url' => '', 'relative' => $relative, 'path' => $path];
        }

        $url = preg_match('#^//#', $reference) === 1 ? 'https:' . $reference : $reference;
        if (preg_match('#^https?://#i', $url) !== 1) {
            if (preg_match('#^https?://#i', $base) !== 1) {
                return null;
            }
            $url = $this->absolutize($url, $base);
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $path = strtolower((string) $parts['host']) . '/' . ltrim($this->collapse(rawurldecode((string) ($parts['path'] ?? '/'))), '/');
        $path = (string) preg_replace('#[^A-Za-z0-9._/-]+#', '-', $path);
        if (str_ends_with($path, '/')) {
            $path .= 'index';
        }
        if (!empty($parts['query']) && !str_contains(strtolower((string) $parts['host']), 'fonts.googleapis.com') && preg_match('/^(ver|v)=/', (string) $parts['query']) !== 1) {
            $path = (string) preg_replace('/(\.[A-Za-z0-9]+)?$/', '-' . substr(sha1((string) $parts['query']), 0, 8) . '$1', $path, 1);
        }
        if (str_contains(strtolower((string) $parts['host']), 'fonts.googleapis.com')) {
            $path .= '-' . substr(sha1((string) ($parts['query'] ?? '')), 0, 8) . '.css';
        }

        return ['key' => 'url:' . preg_replace('/#.*$/', '', $url), 'file' => null, 'url' => (string) preg_replace('/#.*$/', '', $url), 'relative' => '', 'path' => $path];
    }

    /**
     * Copy every url()/@import target of a stylesheet and point it at the copy.
     * $fromDir is the stylesheet's own folder under assets/ ('' for inline CSS,
     * which gets {{THEME_ASSET_BASE}} URLs instead of relative ones).
     */
    private function rewriteCssUrls(string $css, string $base, bool $local, string $themeId, string $fromDir, int $depth, bool $inline): string
    {
        if ($depth > 3) {
            return $css;
        }
        $replace = function (string $reference) use ($base, $local, $themeId, $fromDir, $depth, $inline): ?string {
            $path = $this->asset($reference, $base, $local, $themeId, $depth);
            if ($path === null) {
                return null;
            }
            return $inline ? '{{THEME_ASSET_BASE}}' . $path : $this->relativePath($fromDir, $path);
        };

        $css = (string) preg_replace_callback('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', static function (array $m) use ($replace): string {
            $next = $replace($m[2]);
            return $next === null ? $m[0] : 'url(' . $m[1] . $next . $m[1] . ')';
        }, $css);

        return (string) preg_replace_callback('/@import\s+(["\'])([^"\']+)\1/i', static function (array $m) use ($replace): string {
            $next = $replace($m[2]);
            return $next === null ? $m[0] : '@import ' . $m[1] . $next . $m[1];
        }, $css);
    }

    /** CSS custom properties on :root that look like colors or fonts become theme variables (THM-G). */
    private function variablesFromCss(): array
    {
        $variables = [];
        foreach ($this->css as $css) {
            if (preg_match_all('/:root\s*\{([^}]*)\}/i', $css, $blocks) === 0) {
                continue;
            }
            foreach ($blocks[1] as $block) {
                preg_match_all('/--([A-Za-z0-9_-]{1,60})\s*:\s*([^;]+);?/', $block, $declarations, PREG_SET_ORDER);
                foreach ($declarations as [, $name, $value]) {
                    $value = trim($value);
                    $type = preg_match('/^(#[0-9a-f]{3,8}|rgba?\([^)]*\)|hsla?\([^)]*\))$/i', $value) === 1 ? 'color'
                        : (preg_match('/(serif|sans-serif|monospace|cursive|system-ui)\s*$/i', $value) === 1 ? 'font' : null);
                    if ($type === null || isset($variables[$name]) || count($variables) >= 16) {
                        continue;
                    }
                    $variables[$name] = ['name' => strtolower($name), 'label' => ucfirst(str_replace(['--', '-', '_'], ['', ' ', ' '], $name)), 'type' => $type, 'default' => $value];
                }
            }
        }

        return array_values($variables);
    }

    /** @param \DOMElement[] $containers */
    private function inside(\DOMElement $element, array $containers): bool
    {
        foreach ($containers as $container) {
            if ($container->isSameNode($element) || $container->contains($element)) {
                return true;
            }
        }

        return false;
    }

    private function collapse(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    private function absolutize(string $reference, string $base): string
    {
        $parts = parse_url($base);
        $root = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($reference, '/')) {
            return $root . '/' . $this->collapse($reference);
        }
        $dir = preg_replace('#/[^/]*$#', '/', (string) ($parts['path'] ?? '/')) ?? '/';
        return $root . '/' . $this->collapse($dir . $reference);
    }

    private function relativePath(string $fromDir, string $toPath): string
    {
        $from = array_values(array_filter(explode('/', $fromDir), static fn(string $s): bool => $s !== '' && $s !== '.'));
        $to = explode('/', $toPath);
        $common = 0;
        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            $common++;
        }

        return str_repeat('../', count($from) - $common) . implode('/', array_slice($to, $common));
    }
}
