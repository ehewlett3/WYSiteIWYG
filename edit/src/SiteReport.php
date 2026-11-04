<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * Site-wide scans that don't change content unless asked to:
 * - brokenLinks(): internal links/assets that resolve to missing files (NAV-2);
 * - migrationReport(): what a WordPress-to-static migration silently broke —
 *   forms, search and comment forms, embeds, links and scripts still pointing at
 *   the source site, missing assets (WP-4);
 * - quick fixes that edit only the affected element's bytes (HtmlSource), so the
 *   rest of each page is left exactly as it was.
 */
final class SiteReport
{
    private string $rootPath;
    private BlockRepository $repository;

    public function __construct(string $rootPath, BlockRepository $repository)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->repository = $repository;
    }

    /** @return string[] root-relative .html files to scan (managed + unmanaged, no stubs). */
    public function pagePaths(): array
    {
        $paths = array_column($this->repository->listPages(), 'path');
        foreach ($this->repository->listImportCandidates() as $candidate) {
            $paths[] = $candidate['path'];
        }
        sort($paths);
        return array_values(array_unique($paths));
    }

    /**
     * @return array<int, array{page:string, ref:string, target:string}>
     */
    public function brokenLinks(?array $pagePaths = null): array
    {
        $broken = [];
        foreach ($pagePaths ?? $this->pagePaths() as $path) {
            $html = (string) @file_get_contents($this->rootPath . '/' . $path);
            foreach ($this->internalRefs($html) as $ref) {
                $target = $this->resolve($path, $html, $ref);
                if ($target !== null && !$this->exists($target)) {
                    $broken[] = ['page' => $path, 'ref' => $ref, 'target' => $target];
                }
            }
        }

        return $broken;
    }

    /**
     * UX-9: common accessibility problems per page: images without alt text,
     * links and buttons without a name, skipped heading levels, a missing page
     * language, and form fields without a label.
     *
     * @return array<string, array<int, array{type:string, detail:string}>>
     */
    public function accessibilityReport(?array $pagePaths = null): array
    {
        $report = [];
        foreach ($pagePaths ?? array_column($this->repository->listPages(), 'path') as $path) {
            $html = (string) @file_get_contents($this->rootPath . '/' . $path);
            if ($html === '' || stripos($html, 'http-equiv="refresh"') !== false) {
                continue;
            }
            $dom = HtmlDocument::parse($html);
            $xpath = new \DOMXPath($dom);
            $findings = [];
            $root = $dom->documentElement;
            if ($root instanceof \DOMElement && trim($root->getAttribute('lang')) === '') {
                $findings[] = ['type' => 'No page language', 'detail' => 'Add lang to <html> (Settings → Language does this for generated pages).'];
            }
            foreach ($xpath->query('//body//img[not(@alt)]') ?: [] as $img) {
                $findings[] = ['type' => 'Image without alt text', 'detail' => basename((string) parse_url($img->getAttribute('src'), PHP_URL_PATH))];
            }
            foreach ($xpath->query('//body//a[@href] | //body//button') ?: [] as $link) {
                $name = trim($link->textContent . ' ' . $link->getAttribute('aria-label') . ' ' . $link->getAttribute('title'));
                foreach ($xpath->query('.//img[@alt]', $link) ?: [] as $img) {
                    $name .= $img->getAttribute('alt');
                }
                if (trim($name) === '') {
                    $findings[] = ['type' => ($link->nodeName === 'button' ? 'Button' : 'Link') . ' without a name', 'detail' => (string) $link->getAttribute('href')];
                }
            }
            $previous = 0;
            foreach ($xpath->query('//body//h1 | //body//h2 | //body//h3 | //body//h4 | //body//h5 | //body//h6') ?: [] as $heading) {
                $level = (int) substr($heading->nodeName, 1);
                if ($previous > 0 && $level > $previous + 1) {
                    $findings[] = ['type' => 'Skipped heading level', 'detail' => 'h' . $previous . ' → h' . $level . ': "' . mb_substr(trim($heading->textContent), 0, 60) . '"'];
                }
                $previous = $level;
            }
            foreach ($xpath->query('//body//input[not(@type="hidden") and not(@type="submit") and not(@type="button") and not(@type="image")] | //body//select | //body//textarea') ?: [] as $field) {
                $id = $field->getAttribute('id');
                $labelled = $field->getAttribute('aria-label') !== '' || $field->getAttribute('aria-labelledby') !== ''
                    || ($xpath->query('ancestor::label', $field)?->length ?? 0) > 0
                    || ($id !== '' && ($xpath->query('//label[@for="' . str_replace('"', '', $id) . '"]')?->length ?? 0) > 0);
                if (!$labelled) {
                    $findings[] = ['type' => 'Form field without a label', 'detail' => (string) ($field->getAttribute('name') ?: $field->nodeName)];
                }
            }
            if ($findings !== []) {
                $report[$path] = $findings;
            }
        }
        ksort($report);

        return $report;
    }

    /**
     * Per-file findings for a migrated site. $sourceHosts are the old site's
     * hostnames (e.g. ["example.org", "www.example.org"]).
     *
     * @return array<string, array<int, array{type:string, detail:string, fix?:string}>>
     */
    public function migrationReport(array $sourceHosts, array $knownEmbedHosts): array
    {
        $sourceHosts = array_map('strtolower', $sourceHosts);
        $report = [];

        foreach ($this->pagePaths() as $path) {
            $html = (string) @file_get_contents($this->rootPath . '/' . $path);
            $findings = [];

            foreach ($this->forms($html) as $form) {
                $action = $form['action'];
                if ($form['kind'] === 'comment') {
                    $findings[] = ['type' => 'Comment form', 'detail' => 'Comments need a server; this form posts to ' . ($action !== '' ? $action : 'nowhere') . '.', 'fix' => 'remove-comment-forms'];
                } elseif ($form['kind'] === 'search') {
                    $findings[] = ['type' => 'Search form', 'detail' => 'WordPress search (?s=) does not work on a static site.', 'fix' => 'replace-search-forms'];
                } else {
                    $findings[] = ['type' => 'Form', 'detail' => 'Posts to ' . ($action !== '' ? $action : 'this page') . ' — check it still has somewhere to go.', 'fix' => 'retarget-forms'];
                }
            }

            if (preg_match_all('/<iframe\b[^>]*(?<=\s)src\s*=\s*["\']([^"\']+)["\']/i', $html, $frames)) {
                foreach ($frames[1] as $src) {
                    $host = strtolower((string) parse_url(html_entity_decode($src), PHP_URL_HOST));
                    if ($host !== '' && !in_array($host, $knownEmbedHosts, true)) {
                        $findings[] = ['type' => 'Embed', 'detail' => 'iframe from ' . $host . ' (not on the known embed list).'];
                    }
                }
            }

            if (preg_match_all('/<script\b[^>]*(?<=\s)src\s*=\s*["\']([^"\']+)["\']/i', $html, $scripts)) {
                foreach ($scripts[1] as $src) {
                    $host = strtolower((string) parse_url(html_entity_decode($src), PHP_URL_HOST));
                    if ($host !== '' && in_array($host, $sourceHosts, true)) {
                        $findings[] = ['type' => 'Script', 'detail' => 'Loads ' . $src . ' from the old site.'];
                    }
                }
            }

            if ($sourceHosts !== [] && preg_match_all('/(?<=\s)(?:href|src)\s*=\s*["\'](https?:\/\/([^\/"\']+)[^"\']*)["\']/i', $html, $links, PREG_SET_ORDER)) {
                $count = 0;
                foreach ($links as $link) {
                    if (in_array(strtolower($link[2]), $sourceHosts, true)) {
                        $count++;
                    }
                }
                if ($count > 0) {
                    $findings[] = ['type' => 'Old-site links', 'detail' => $count . ' link(s) still point at ' . implode(' / ', array_slice($sourceHosts, 0, 2)) . '.'];
                }
            }

            foreach ($this->brokenLinks([$path]) as $missing) {
                $findings[] = ['type' => 'Missing file', 'detail' => $missing['ref'] . ' → ' . $missing['target']];
            }

            if ($findings !== []) {
                $report[$path] = $findings;
            }
        }

        return $report;
    }

    /**
     * Apply a quick fix to every scanned page. Returns the number of pages changed.
     * - remove-comment-forms: delete WordPress comment forms (and #respond wrappers' forms);
     * - replace-search-forms: swap search forms for a GET form to $searchUrl restricted to $siteHost;
     * - retarget-forms: point other forms' action at $formEndpoint.
     */
    public function applyFix(string $fix, string $searchUrl = '', string $siteHost = '', string $formEndpoint = ''): int
    {
        if ($fix === 'replace-search-forms' && $searchUrl === '') {
            throw new RuntimeException('Set an external search URL in Site settings first.');
        }
        if ($fix === 'retarget-forms' && $formEndpoint === '') {
            throw new RuntimeException('Set a form endpoint in Site settings first.');
        }

        $changed = 0;
        foreach ($this->pagePaths() as $path) {
            $abs = $this->rootPath . '/' . $path;
            $html = (string) file_get_contents($abs);
            $updated = $html;

            // Work from the last form backwards so earlier byte offsets stay valid.
            foreach (array_reverse($this->forms($html)) as $form) {
                if ($fix === 'remove-comment-forms' && $form['kind'] === 'comment') {
                    $updated = substr_replace($updated, '', $form['start'], $form['end'] - $form['start']);
                } elseif ($fix === 'replace-search-forms' && $form['kind'] === 'search') {
                    $replacement = '<form class="search-form" role="search" method="get" action="' . h($searchUrl) . '">'
                        . '<input type="search" name="q" aria-label="Search" placeholder="Search">'
                        . ($siteHost !== '' ? '<input type="hidden" name="sites" value="' . h($siteHost) . '">' : '')
                        . '<button type="submit">Search</button></form>';
                    $updated = substr_replace($updated, $replacement, $form['start'], $form['end'] - $form['start']);
                } elseif ($fix === 'retarget-forms' && $form['kind'] === 'form') {
                    $startTag = substr($updated, $form['start'], $form['tagEnd'] - $form['start']);
                    $newTag = preg_match('/(?<=\s)action\s*=\s*(["\'])[^"\']*\1/i', $startTag) === 1
                        ? (preg_replace('/(?<=\s)action\s*=\s*(["\'])[^"\']*\1/i', 'action="' . h($formEndpoint) . '"', $startTag, 1) ?? $startTag)
                        : preg_replace('/^<form\b/i', '<form action="' . h($formEndpoint) . '"', $startTag, 1);
                    $updated = substr_replace($updated, (string) $newTag, $form['start'], $form['tagEnd'] - $form['start']);
                }
            }

            if ($updated !== $html) {
                Filesystem::writeSitePage($abs, $updated);
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * Forms in $html with their byte ranges and a classification.
     *
     * @return array<int, array{kind:string, action:string, start:int, tagEnd:int, end:int}>
     */
    private function forms(string $html): array
    {
        $forms = [];
        $tokens = HtmlSource::tokens($html);
        $depth = 0;
        $open = null;
        foreach ($tokens as $token) {
            if ($token['name'] !== 'form') {
                continue;
            }
            if ($token['type'] === 'start') {
                if ($depth === 0) {
                    $open = $token;
                }
                $depth++;
                continue;
            }
            $depth = max(0, $depth - 1);
            if ($depth === 0 && $open !== null) {
                $outer = substr($html, $open['start'], $token['end'] - $open['start']);
                $startTag = substr($html, $open['start'], $open['end'] - $open['start']);
                $action = preg_match('/(?<=\s)action\s*=\s*["\']([^"\']*)["\']/i', $startTag, $m) === 1 ? html_entity_decode($m[1]) : '';
                $kind = 'form';
                if (preg_match('/wp-comments-post|id\s*=\s*["\']commentform["\']|class\s*=\s*["\'][^"\']*comment-form/i', $outer) === 1) {
                    $kind = 'comment';
                } elseif (preg_match('/role\s*=\s*["\']search["\']|class\s*=\s*["\'][^"\']*search-form|name\s*=\s*["\']s["\']/i', $outer) === 1) {
                    $kind = 'search';
                }
                $forms[] = ['kind' => $kind, 'action' => $action, 'start' => $open['start'], 'tagEnd' => $open['end'], 'end' => $token['end']];
                $open = null;
            }
        }

        return $forms;
    }

    /** @return string[] */
    private function internalRefs(string $html): array
    {
        $refs = [];
        if (preg_match_all('/<(?:a|link|img|script|source|video|audio|iframe)\b[^>]*?(?<=\s)(href|src)\s*=\s*["\']([^"\']*)["\']/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $refs[] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
            }
        }
        if (preg_match_all('/(?<=\s)srcset\s*=\s*["\']([^"\']*)["\']/i', $html, $sets)) {
            foreach ($sets[1] as $set) {
                foreach (explode(',', html_entity_decode($set, ENT_QUOTES, 'UTF-8')) as $candidate) {
                    $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
                    if ($url !== '') {
                        $refs[] = $url;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($refs, static function (string $ref): bool {
            $ref = trim($ref);
            return $ref !== '' && $ref[0] !== '#' && !str_contains($ref, '{{')
                && preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $ref) !== 1;
        })));
    }

    /** Site-root-relative target of $ref on page $path, or null when outside the site. */
    private function resolve(string $path, string $html, string $ref): ?string
    {
        $pageUrl = trim($this->repository->publicUrlForPath($path), '/');
        $pageDir = $pageUrl === '' ? '' : $pageUrl . '/';
        if (preg_match('/<base\b[^>]*(?<=\s)href\s*=\s*["\']([^"\']*)["\']/i', $html, $base) === 1 && preg_match('#^([a-z]+:|//|/)#i', $base[1]) !== 1) {
            $pageDir = $this->normalize($pageDir . $base[1]);
        }

        $ref = preg_replace('/[?#].*$/', '', $ref) ?? $ref;
        if ($ref === '') {
            return null;
        }
        $target = $ref[0] === '/' ? ltrim($ref, '/') : $this->normalize($pageDir . $ref);
        if ($target === null || str_starts_with($target, 'edit/')) {
            return null;
        }

        return rawurldecode($target);
    }

    /** Collapse ./ and ../ segments; null when the path climbs above the root. */
    private function normalize(string $path): ?string
    {
        $out = [];
        $trailing = str_ends_with($path, '/') || str_ends_with($path, '/.') || $path === '.' || $path === '';
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($out === []) {
                    return null;
                }
                array_pop($out);
                continue;
            }
            $out[] = $segment;
        }

        $joined = implode('/', $out);
        return $joined === '' ? '' : $joined . ($trailing ? '/' : '');
    }

    private function exists(string $target): bool
    {
        $abs = $this->rootPath . '/' . $target;
        if ($target === '' || str_ends_with($target, '/')) {
            $dir = rtrim($abs, '/');
            return is_file($dir . '/index.html') || is_file($dir . '/index.htm') || ($target !== '' && is_file($dir . '.html'));
        }

        return is_file($abs) || is_file($abs . '.html') || is_file($abs . '/index.html');
    }
}
