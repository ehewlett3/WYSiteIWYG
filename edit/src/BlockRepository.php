<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class BlockRepository
{
    private const ACTIVE_TEMPLATE_FILES = [
        'home' => 'home.html',
        'page' => 'page.html',
        'blog-post' => 'blog-post.html',
        'blog' => 'blog-index.html',
    ];

    private string $rootPath;
    private string $editPath;
    private SitePath $sitePath;

    public function __construct(string $rootPath, string $editPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->editPath = rtrim($editPath, '/');
        $this->sitePath = new SitePath($this->rootPath, $this->editPath);
    }

    public function sitePath(): SitePath
    {
        return $this->sitePath;
    }

    /** @var array{generation:int, pages:array}|null */
    private ?array $pagesCache = null;

    /**
     * Every managed page. Memoized until the next file write in this request
     * (Filesystem::generation), since rebuilds call it many times (BLOG-9).
     */
    public function listPages(): array
    {
        if ($this->pagesCache !== null && $this->pagesCache['generation'] === Filesystem::generation()) {
            return $this->pagesCache['pages'];
        }

        $pages = [];
        foreach ($this->siteFiles() as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fullPath = str_replace('\\', '/', $file->getPathname());
            if ($this->shouldSkip($fullPath)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
            if (!in_array($extension, ['html', 'htm'], true)) {
                continue;
            }

            $html = (string) file_get_contents($fullPath);
            $blocks = $this->parseBlocks($html);
            if ($blocks === []) {
                continue;
            }

            $relativePath = $this->relativeFromRoot($fullPath);
            $metadata = $this->extractMetadata($html);
            $blockTypes = array_values(array_unique(array_map(static fn(array $block): string => $block['type'], $blocks)));
            $kind = $this->inferKind($relativePath, $blockTypes, $metadata);

            $pages[] = [
                'path' => $relativePath,
                'url' => $this->relativeToUrl($relativePath),
                'title' => $metadata['title'] ?? basename($relativePath),
                'kind' => $kind,
                'description' => $metadata['excerpt'] ?? '',
                'excerpt' => $metadata['excerpt'] ?? '',
                'date' => $metadata['date'] ?? '',
                'hashtags' => $metadata['hashtags'] ?? '',
                'tags' => $metadata['tags'] ?? [],
                'tag' => $metadata['tag'] ?? '',
                'generated' => $metadata['generated'] ?? '',
                'exclude_template' => ($metadata['exclude_template'] ?? '') === '1',
                'demo' => ($metadata['demo'] ?? '') === '1',
                'paged' => (string) ($metadata['paged'] ?? ''),
                'status' => (string) ($metadata['status'] ?? ''),
                'author' => (string) ($metadata['author'] ?? ''),
                'image' => (string) ($metadata['image'] ?? ''),
                'blocks' => array_map(
                    static fn(array $block): array => [
                        'name' => $block['name'],
                        'type' => $block['type'],
                        'label' => $block['label'],
                    ],
                    $blocks
                ),
            ];
        }

        usort(
            $pages,
            static fn(array $left, array $right): int => strcmp($left['path'], $right['path'])
        );

        $this->pagesCache = ['generation' => Filesystem::generation(), 'pages' => $pages];
        return $pages;
    }

    /**
     * Unmanaged HTML files. With $includeRedirects, WYSiteIWYG's own redirect
     * stubs are listed too (flagged 'redirect' => true).
     */
    public function listImportCandidates(bool $includeRedirects = false): array
    {
        $pages = [];
        foreach ($this->siteFiles() as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fullPath = str_replace('\\', '/', $file->getPathname());
            if ($this->shouldSkip($fullPath)) {
                continue;
            }

            $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
            if (!in_array($extension, ['html', 'htm'], true)) {
                continue;
            }

            $html = (string) file_get_contents($fullPath);
            // Managed pages and WYSiteIWYG's own redirect stubs aren't candidates.
            $isRedirect = str_contains($html, 'WYSITE:REDIRECT');
            if ($this->parseBlocks($html) !== [] || ($isRedirect && !$includeRedirects)) {
                continue;
            }

            $relativePath = $this->relativeFromRoot($fullPath);
            if ($isRedirect) {
                $pages[] = ['path' => $relativePath, 'url' => $this->relativeToUrl($relativePath), 'title' => 'Redirect', 'excerpt' => '', 'redirect' => true];
                continue;
            }
            $metadata = $this->extractMetadata($html);

            $pages[] = [
                'path' => $relativePath,
                'url' => $this->relativeToUrl($relativePath),
                'title' => $metadata['title'] ?? basename($relativePath),
                'excerpt' => $metadata['excerpt'] ?? $this->snippetFromHtml($html),
            ];
        }

        usort(
            $pages,
            static fn(array $left, array $right): int => strcmp($left['path'], $right['path'])
        );

        return $pages;
    }

    /** Draft pages (not public), newest first. */
    public function listDrafts(): array
    {
        $base = $this->sitePath->draftsPath();
        if (!is_dir($base)) {
            return [];
        }

        $drafts = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || preg_match('/\.html?$/i', $file->getFilename()) !== 1) {
                continue;
            }
            $html = (string) file_get_contents($file->getPathname());
            $meta = $this->extractMetadata($html);
            $relative = $this->relativeFromRoot(str_replace('\\', '/', $file->getPathname()));
            $drafts[] = [
                'path' => $relative,
                'public_path' => SitePath::publicPath($relative),
                'title' => $meta['title'] ?? basename($relative),
                'kind' => (string) ($meta['kind'] ?? 'page'),
                'publish_at' => (string) ($meta['publish_at'] ?? ''),
                'modified' => (int) $file->getMTime(),
            ];
        }

        usort($drafts, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);
        return $drafts;
    }

    public function listBlogPosts(?string $tag = null): array
    {
        $posts = [];
        $tag = $tag !== null ? $this->normalizeTag($tag) : null;

        foreach ($this->listPages() as $page) {
            if (($page['tags'] ?? []) === [] || $page['status'] === 'draft' || $page['kind'] !== 'blog-post' && $page['kind'] !== 'page') {
                continue;
            }

            if ($tag !== null && !in_array($tag, $page['tags'], true)) {
                continue;
            }

            $posts[] = [
                'path' => $page['path'],
                'url' => $page['url'],
                'title' => $page['title'],
                'excerpt' => $page['excerpt'] !== '' ? $page['excerpt'] : $this->snippetForPage($page['path']),
                'date' => $page['date'] ?? '',
                'hashtags' => $page['hashtags'] ?? '',
                'tags' => $page['tags'] ?? [],
                'author' => $page['author'],
                'image' => $page['image'],
            ];
        }

        usort(
            $posts,
            static function (array $left, array $right): int {
                $dateComparison = strcmp($right['date'], $left['date']);
                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                return strcmp($left['title'], $right['title']);
            }
        );

        return $posts;
    }

    public function getPage(string $relativePath): array
    {
        $fullPath = $this->resolveExistingPath($relativePath);
        $html = (string) file_get_contents($fullPath);
        $blocks = $this->parseBlocks($html);
        $meta = $this->extractMetadata($html);
        $blockTypes = array_values(array_unique(array_map(static fn(array $block): string => $block['type'], $blocks)));

        return [
            'path' => $this->relativeFromRoot($fullPath),
            'html' => $html,
            'blocks' => $blocks,
            'meta' => $meta,
            'kind' => $this->inferKind($this->relativeFromRoot($fullPath), $blockTypes, $meta),
        ];
    }

    public function getImportCandidate(string $relativePath): array
    {
        $fullPath = $this->resolveExistingPath($relativePath);
        if ($this->shouldSkip($fullPath)) {
            throw new RuntimeException('That file cannot be imported through WYSiteIWYG.');
        }

        $html = (string) file_get_contents($fullPath);
        if ($this->parseBlocks($html) !== []) {
            throw new RuntimeException('That file is already managed by WYSiteIWYG.');
        }

        $meta = $this->extractMetadata($html);

        return [
            'path' => $this->relativeFromRoot($fullPath),
            'html' => $html,
            'meta' => $meta,
            'title' => $meta['title'] ?? basename($relativePath),
            'excerpt' => $meta['excerpt'] ?? $this->snippetFromHtml($html),
        ];
    }

    /** Names of blocks an admin marked "raw HTML, admin-only" (META admin_blocks). */
    public function adminOnlyBlocks(array $meta): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($meta['admin_blocks'] ?? '')))));
    }

    public function getBlock(string $relativePath, string $blockName): array
    {
        $page = $this->getPage($relativePath);
        $adminOnly = $this->adminOnlyBlocks($page['meta']);
        foreach ($page['blocks'] as $block) {
            if ($block['name'] === $blockName) {
                return [
                    'name' => $block['name'],
                    'type' => $block['type'],
                    'label' => $block['label'],
                    'content' => $block['content'],
                    'admin_only' => in_array($block['name'], $adminOnly, true),
                ];
            }
        }

        throw new RuntimeException('Unknown editable block.');
    }

    public function updateBlock(string $relativePath, string $blockName, string $newContent): void
    {
        $fullPath = $this->resolveExistingPath($relativePath);
        $html = (string) file_get_contents($fullPath);
        $blocks = $this->parseBlocks($html);

        foreach ($blocks as $block) {
            if ($block['name'] !== $blockName) {
                continue;
            }

            $replacement = $block['start_comment'] . "\n" . trim($newContent) . "\n" . $block['end_comment'];
            $updated = substr_replace($html, $replacement, $block['offset'], $block['length']);
            Filesystem::writeSitePage($fullPath, $updated);
            return;
        }

        throw new RuntimeException('Editable block not found in file.');
    }

    public function updateBlockInRelativeFile(string $relativePath, string $blockName, string $newContent): void
    {
        $fullPath = $this->resolveManagedRelativeFilePath($relativePath);
        if (!is_file($fullPath)) {
            throw new RuntimeException('Template file not found: ' . $relativePath);
        }

        $html = (string) file_get_contents($fullPath);
        $blocks = $this->parseBlocks($html);
        foreach ($blocks as $block) {
            if ($block['name'] !== $blockName) {
                continue;
            }

            $replacement = $block['start_comment'] . "\n" . trim($newContent) . "\n" . $block['end_comment'];
            $updated = substr_replace($html, $replacement, $block['offset'], $block['length']);
            Filesystem::writeSitePage($fullPath, $updated);
            return;
        }

        throw new RuntimeException('Editable block not found in ' . $relativePath);
    }

    private function resolveManagedRelativeFilePath(string $relativePath): string
    {
        $clean = trim(str_replace('\\', '/', $relativePath), '/');

        if (str_starts_with($clean, 'edit/templates/')) {
            $templatePath = substr($clean, strlen('edit/templates/'));
            if (!in_array($templatePath, self::ACTIVE_TEMPLATE_FILES, true)) {
                throw new RuntimeException('Unsafe template path requested.');
            }

            return $this->editPath . '/templates/' . $templatePath;
        }

        return $this->resolvePath($clean);
    }

    public function activeTemplateRelativePath(string $kind): string
    {
        return 'edit/templates/' . $this->activeTemplateFilename($kind);
    }

    /**
     * The active template kind a page kind actually renders with: 'home' uses
     * edit/templates/home.html when the applied theme published one, else 'page'.
     */
    public function effectiveTemplateKind(string $kind): string
    {
        if ($kind === 'home' && !is_file($this->editPath . '/templates/home.html')) {
            return 'page';
        }

        return $kind;
    }

    public function loadActiveTemplate(string $kind): string
    {
        $filename = $this->activeTemplateFilename($kind);
        $path = $this->editPath . '/templates/' . $filename;

        if (!is_file($path)) {
            throw new RuntimeException('Active template file not found: edit/templates/' . $filename);
        }

        return (string) file_get_contents($path);
    }

    public function updateSelectedElement(string $relativePath, array $domPath, string $newInnerHtml): void
    {
        $this->updateElementInHtmlFile(
            $this->resolveExistingPath($relativePath),
            $domPath,
            $newInnerHtml,
            $relativePath
        );
    }

    public function updateSelectedElementInActiveTemplate(string $kind, array $domPath, string $newInnerHtml): string
    {
        $filename = $this->activeTemplateFilename($this->effectiveTemplateKind($kind));
        $relativePath = 'edit/templates/' . $filename;
        $fullPath = $this->editPath . '/templates/' . $filename;

        if (!is_file($fullPath)) {
            throw new RuntimeException('Active template file not found: ' . $relativePath);
        }

        $this->updateElementInHtmlFile($fullPath, $domPath, $newInnerHtml, $relativePath);

        return $relativePath;
    }

    public function updateMetadata(string $relativePath, array $attributes): void
    {
        $fullPath = $this->resolveExistingPath($relativePath);
        $html = (string) file_get_contents($fullPath);
        $current = $this->extractMetadata($html);
        $metadata = array_merge($current, $attributes);

        if (isset($metadata['hashtags'])) {
            $metadata['hashtags'] = $this->normalizeHashtagString((string) $metadata['hashtags']);
            $metadata['tags'] = $this->parseHashtags((string) $metadata['hashtags']);
        }

        $comment = $this->buildMetadataComment($metadata);
        if (preg_match('/<!--\s*WYSITE:META(?P<attrs>.*?)-->/si', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $match[0][1];
            $length = strlen($match[0][0]);
            $html = substr_replace($html, $comment, $offset, $length);
        } elseif (preg_match('/<head[^>]*>/i', $html, $match, PREG_OFFSET_CAPTURE) === 1) {
            $offset = $match[0][1] + strlen($match[0][0]);
            $html = substr_replace($html, "\n  " . $comment, $offset, 0);
        } else {
            $html = $comment . "\n" . $html;
        }

        Filesystem::writeSitePage($fullPath, $html);
    }

    public function relativeFromRequestPath(string $requestPath): ?string
    {
        $path = rawurldecode((string) parse_url($requestPath, PHP_URL_PATH));
        $path = '/' . ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '/' || $path === '') {
            return 'index.html';
        }

        if (str_starts_with($path, '/edit/')) {
            return null;
        }

        $clean = trim($path, '/');
        $candidates = [];

        if (preg_match('/\.html?$/i', $clean) === 1) {
            $candidates[] = $clean;
        } else {
            $candidates[] = $clean . '.html';
            $candidates[] = $clean . '/index.html';
        }

        foreach ($candidates as $candidate) {
            try {
                if (is_file($this->resolvePath($candidate))) {
                    return $candidate;
                }
            } catch (RuntimeException) {
                continue;
            }
        }

        return null;
    }

    public function renderPreviewHtml(string $relativePath, string $appUrl, string $siteBaseUrl, string $csrfToken, string $username, string $nonce = '', array $extraContext = []): string
    {
        $page = $this->getPage($relativePath);
        return $this->renderPreviewHtmlFromSource(
            $relativePath,
            $page['html'],
            $appUrl,
            $siteBaseUrl,
            $csrfToken,
            $username,
            array_merge([
                'pageKind' => $page['kind'],
                'activeTemplatePath' => $this->activeTemplateRelativePath($this->effectiveTemplateKind($page['kind'])),
                'selectionSaveEnabled' => true,
                'fileHash' => sha1($page['html']),
            ], $extraContext),
            $nonce
        );
    }

    public function renderPreviewHtmlFromSource(
        string $relativePath,
        string $html,
        string $appUrl,
        string $siteBaseUrl,
        string $csrfToken,
        string $username,
        array $extraContext = [],
        string $nonce = ''
    ): string {
        $blocks = $this->parseBlocks($html);

        foreach (array_reverse($blocks) as $block) {
            $button = '<button class="wysite-edit-button" type="button" data-wysite-block="' . h($block['name']) . '" data-wysite-type="' . h($block['type']) . '">' . h($block['label']) . '</button>';
            $slotClass = $block['type'] === 'menu' ? 'wysite-edit-slot wysite-edit-slot--menu' : 'wysite-edit-slot';
            $replacement = $block['start_comment'] . "\n<div class=\"wysite-edit-region\" data-wysite-block=\"" . h($block['name']) . "\" data-wysite-type=\"" . h($block['type']) . "\">\n" . $button . "\n<div class=\"" . $slotClass . "\" data-wysite-edit-slot=\"" . h($block['name']) . "\">\n" . trim($block['content']) . "\n</div>\n</div>\n" . $block['end_comment'];
            $html = substr_replace($html, $replacement, $block['offset'], $block['length']);
        }

        $context = [
            'appUrl' => $appUrl,
            'siteBaseUrl' => $siteBaseUrl,
            'pagePath' => $relativePath,
            'csrfToken' => $csrfToken,
            'username' => $username,
            'blocks' => array_map(
                fn(array $block): array => [
                    'name' => $block['name'],
                    'type' => $block['type'],
                    'label' => $block['label'],
                    'adminOnly' => in_array($block['name'], $this->adminOnlyBlocks($this->extractMetadata($html)), true),
                ],
                $blocks
            ),
        ];
        $context = array_merge($context, $extraContext);

        // Drop the page's own relative <base href> (baked for static serving) and
        // place the preview's absolute base at the very top of <head>, before the
        // page's own (now base-relative) stylesheet/asset links so they resolve.
        $html = preg_replace('#\s*<base\b[^>]*>#i', '', $html) ?? $html;
        $baseTag = '<base href="' . h($siteBaseUrl) . '">';
        if (preg_match('#<head\b[^>]*>#i', $html) === 1) {
            $html = preg_replace('#(<head\b[^>]*>)#i', '$1' . $baseTag, $html, 1) ?? $html;
        } else {
            $html = $baseTag . $html;
        }

        $nonceAttr = $nonce !== '' ? ' nonce="' . h($nonce) . '"' : '';
        $headInjection = '<link rel="stylesheet" href="' . h($appUrl) . '/assets/editor.css">' .
            '<script' . $nonceAttr . '>window.WYSITE_PREVIEW_CONTEXT = ' . json_encode($context, JSON_UNESCAPED_SLASHES) . ';</script>' .
            '<script type="module"' . $nonceAttr . ' src="' . h($appUrl) . '/assets/editor-shell.js"></script>';

        $metaBits = ['<strong>WYSiteIWYG</strong>', '<span>' . h($relativePath) . '</span>', '<span>Signed in as ' . h($username) . '</span>'];
        if (!empty($extraContext['previewThemeName'])) {
            $metaBits[] = '<span>Previewing theme: ' . h((string) $extraContext['previewThemeName']) . '</span>';
        }

        $bar = '<div class="wysite-admin-bar">' .
            '<div class="wysite-admin-bar__meta">' . implode('', $metaBits) . '</div>' .
            '<div class="wysite-admin-bar__actions">' .
            '<a class="wysite-admin-link" href="' . h($appUrl) . '/index.php">Dashboard</a>' .
            '<a class="wysite-admin-link" href="' . h($siteBaseUrl . ltrim($relativePath, '/')) . '" target="_blank" rel="noreferrer">Open live page</a>' .
            '<form method="post" action="' . h($appUrl) . '/index.php?action=logout"><input type="hidden" name="csrf_token" value="' . h($csrfToken) . '"><button class="wysite-admin-link wysite-admin-link--button" type="submit">Log out</button></form>' .
            '</div>' .
            '</div>';

        if (stripos($html, '</head>') !== false) {
            $html = preg_replace('/<\/head>/i', $headInjection . '</head>', $html, 1) ?? ($headInjection . $html);
        } else {
            $html = $headInjection . $html;
        }

        if (stripos($html, '<body') !== false) {
            $html = preg_replace('/(<body\b[^>]*>)/i', '$1' . $bar, $html, 1) ?? ($bar . $html);
        } else {
            $html = $bar . $html;
        }

        return $html;
    }

    /** Absolute path for a public page that may not exist yet (see SitePath). */
    public function resolvePath(string $relativePath): string
    {
        return $this->sitePath->resolvePage($relativePath, false);
    }

    private function resolveExistingPath(string $relativePath): string
    {
        return $this->sitePath->resolvePage($relativePath, true);
    }

    private function shouldSkip(string $fullPath): bool
    {
        if (str_starts_with($fullPath, $this->editPath . '/')) {
            return true;
        }

        $relative = $this->relativeFromRoot($fullPath);
        foreach ($this->excludedPaths as $excluded) {
            if ($relative === $excluded || str_starts_with($relative, $excluded . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @var string[] root-relative folders left out of page scans (Site settings). */
    private array $excludedPaths = [];

    public function setExcludedPaths(array $paths): void
    {
        $this->excludedPaths = array_values(array_filter(array_map(
            static fn($path): string => trim(str_replace('\\', '/', (string) $path), '/'),
            $paths
        ), static fn(string $path): bool => $path !== ''));
        $this->pagesCache = null;
    }

    /**
     * .html/.htm files that can be site pages: skips dot-directories (.git, ...),
     * the editor, assets/, and folders excluded in Site settings (DROP-5).
     *
     * @return iterable<\SplFileInfo>
     */
    private function siteFiles(): iterable
    {
        $root = $this->rootPath;
        $excluded = $this->excludedPaths;
        $filter = new \RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file) use ($root, $excluded): bool {
                $name = $file->getFilename();
                if (str_starts_with($name, '.')) {
                    return false;
                }

                $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');
                if ($file->isDir()) {
                    if (in_array($relative, ['edit', 'assets'], true)) {
                        return false;
                    }
                    foreach ($excluded as $path) {
                        if ($relative === $path || str_starts_with($relative, $path . '/')) {
                            return false;
                        }
                    }
                    return true;
                }

                return preg_match('/\.html?$/i', $name) === 1;
            }
        );

        return new RecursiveIteratorIterator($filter);
    }

    private function activeTemplateFilename(string $kind): string
    {
        $filename = self::ACTIVE_TEMPLATE_FILES[$kind] ?? null;
        if ($filename === null) {
            throw new RuntimeException('Unknown template kind: ' . $kind);
        }

        return $filename;
    }

    private function updateElementInHtmlFile(string $fullPath, array $domPath, string $newInnerHtml, string $label): void
    {
        if (!class_exists(\DOMDocument::class)) {
            throw new RuntimeException('The DOM extension is required for selector-based editing.');
        }

        $html = (string) file_get_contents($fullPath);
        $dom = new \DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($this->htmlForDom($html));
        libxml_clear_errors();

        if (!$loaded) {
            throw new RuntimeException('Unable to parse ' . $label . ' for selector-based editing.');
        }

        $element = $this->elementFromDomPath($dom, $domPath);
        // Never let a PHP open tag reach a saved file, whichever path writes it.
        $newInnerHtml = str_replace('<?', '&lt;?', $newInnerHtml);

        // Preferred: splice only the element's inner bytes so everything else in
        // the file (doctype, template tokens, HTML5 markup, formatting) is untouched.
        $range = $this->sourceRangeForElement($dom, $element, $html);
        if ($range !== null) {
            $updated = substr_replace($html, $newInnerHtml, $range[0], $range[1] - $range[0]);
            Filesystem::writeSitePage($fullPath, $updated);
            return;
        }

        // Fallback when the source can't be mapped unambiguously (e.g. libxml
        // inferred an element the file doesn't spell out): re-serialize through the
        // DOM, but keep the original doctype line and template tokens.
        $this->replaceInnerHtml($dom, $element, $newInnerHtml);

        $updated = restore_placeholder_tokens($this->stripDomEncodingHack($dom->saveHTML() ?: ''));
        if (trim($updated) === '') {
            throw new RuntimeException('Selector-based editing produced an empty document for ' . $label . '.');
        }

        $updated = preg_replace('/^\s*<!DOCTYPE[^>]*>\s*/i', '', $updated) ?? $updated;
        if (preg_match('/^\s*(<!doctype[^>]*>)(\s*)/i', $html, $doctype) === 1) {
            $updated = $doctype[1] . $doctype[2] . $updated;
        }

        Filesystem::writeSitePage($fullPath, $updated);
    }

    /**
     * Byte range of $element's inner HTML in the original $html, located by its
     * position among same-named elements. Null when the source and the parsed DOM
     * disagree on how many such elements exist.
     *
     * @return array{0:int, 1:int}|null
     */
    private function sourceRangeForElement(\DOMDocument $dom, \DOMElement $element, string $html): ?array
    {
        $tag = strtolower($element->tagName);
        $ordinal = null;
        $count = 0;
        foreach ($dom->getElementsByTagName($tag) as $index => $candidate) {
            if ($candidate === $element) {
                $ordinal = $index;
            }
            $count++;
        }

        if ($ordinal === null || HtmlSource::countStartTags($html, $tag) !== $count) {
            return null;
        }

        return HtmlSource::innerRange($html, $tag, $ordinal);
    }

    private function elementFromDomPath(\DOMDocument $dom, array $domPath): \DOMElement
    {
        $segments = array_values($domPath);
        if ($segments === []) {
            throw new RuntimeException('The selected element path was empty.');
        }

        $current = $dom->documentElement;
        if (!$current instanceof \DOMElement) {
            throw new RuntimeException('The selected element could not be resolved.');
        }

        $first = $this->normalizeDomPathSegment($segments[0]);
        if ($first['tag'] === strtolower($current->tagName) && $first['index'] === 1) {
            array_shift($segments);
        }

        foreach ($segments as $segment) {
            $part = $this->normalizeDomPathSegment($segment);
            $current = $this->nthElementChildByTag($current, $part['tag'], $part['index']);
            if (!$current instanceof \DOMElement) {
                throw new RuntimeException('The selected element no longer matches this file.');
            }
        }

        return $current;
    }

    private function normalizeDomPathSegment(mixed $segment): array
    {
        if (!is_array($segment)) {
            throw new RuntimeException('The selected element path was not valid.');
        }

        $tag = strtolower(trim((string) ($segment['tag'] ?? '')));
        $index = (int) ($segment['index'] ?? 0);

        if ($tag === '' || preg_match('/^[a-z][a-z0-9:-]*$/', $tag) !== 1 || $index < 1) {
            throw new RuntimeException('The selected element path contained an invalid segment.');
        }

        return ['tag' => $tag, 'index' => $index];
    }

    private function nthElementChildByTag(\DOMElement $parent, string $tag, int $index): ?\DOMElement
    {
        $seen = 0;

        foreach ($parent->childNodes as $child) {
            if (!($child instanceof \DOMElement) || strtolower($child->tagName) !== $tag) {
                continue;
            }

            $seen++;
            if ($seen === $index) {
                return $child;
            }
        }

        return null;
    }

    private function replaceInnerHtml(\DOMDocument $owner, \DOMElement $element, string $html): void
    {
        while ($element->firstChild) {
            $element->removeChild($element->firstChild);
        }

        $fragment = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $loaded = $fragment->loadHTML($this->htmlForDom('<!doctype html><html><body>' . $html . '</body></html>'));
        libxml_clear_errors();

        if (!$loaded) {
            throw new RuntimeException('The selected element content could not be parsed.');
        }

        $body = $fragment->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return;
        }

        // libxml keeps PHP open/close tags as processing instructions on re-serialization,
        // which would plant executable code in the saved file. Never import them.
        foreach (iterator_to_array((new \DOMXPath($fragment))->query('//processing-instruction()') ?: []) as $pi) {
            $pi->parentNode?->removeChild($pi);
        }

        $children = [];
        foreach ($body->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            $element->appendChild($owner->importNode($child, true));
        }
    }

    private function htmlForDom(string $html): string
    {
        return '<?xml encoding="UTF-8">' . $html;
    }

    private function stripDomEncodingHack(string $html): string
    {
        return preg_replace('/<\?xml\s+encoding=["\']UTF-8["\']\??>\s*/i', '', $html) ?? $html;
    }

    private function inferKind(string $relativePath, array $blockTypes, array $metadata = []): string
    {
        $explicit = (string) ($metadata['kind'] ?? '');

        // The site's root index.html is the homepage. Home renders with a theme's
        // home template when there is one and falls back to the page template, so
        // this is output-identical for sites without one.
        if ($relativePath === 'index.html' && ($explicit === '' || $explicit === 'page') && ($metadata['tags'] ?? []) === []) {
            return 'home';
        }

        if ($explicit !== '') {
            return $explicit;
        }

        // Legacy pages without an explicit kind: hashtags meant "post".
        if (($metadata['tags'] ?? []) !== []) {
            return 'blog-post';
        }

        if (in_array('blog-post', $blockTypes, true)) {
            return 'blog-post';
        }

        if (in_array('blog', $blockTypes, true)) {
            return 'blog';
        }

        if ($relativePath === 'blog/index.html') {
            return 'blog';
        }

        return 'page';
    }

    private function relativeFromRoot(string $fullPath): string
    {
        return $this->sitePath->relativeFor($fullPath);
    }

    /** Site-relative clean URL for a page path ("about/team.html" -> "/about/team/"). */
    public function publicUrlForPath(string $relativePath): string
    {
        return $this->relativeToUrl($relativePath);
    }

    private function relativeToUrl(string $relativePath): string
    {
        $clean = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($clean === '' || $clean === 'index.html') {
            return '/';
        }

        if (str_ends_with($clean, '/index.html')) {
            return '/' . trim(substr($clean, 0, -strlen('/index.html')), '/') . '/';
        }

        if (preg_match('/\.html?$/i', $clean) === 1) {
            return '/' . trim((string) preg_replace('/\.html?$/i', '', $clean), '/') . '/';
        }

        return '/' . $clean;
    }

    private function parseAttributes(string $attributeString): array
    {
        $attributes = [];
        if (preg_match_all('/([A-Za-z0-9_-]+)="([^"]*)"/', $attributeString, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES, 'UTF-8');
            }
        }

        return $attributes;
    }

    private function extractMetadata(string $html): array
    {
        $metadata = [];

        if (preg_match('/<!--\s*WYSITE:META(?P<attrs>.*?)-->/si', $html, $match)) {
            $metadata = $this->parseAttributes($match['attrs']);
        }

        if (empty($metadata['title']) && preg_match('/<title>(.*?)<\/title>/si', $html, $match)) {
            $metadata['title'] = trim(strip_tags($match[1]));
        }

        if (!empty($metadata['hashtags'])) {
            $metadata['hashtags'] = $this->normalizeHashtagString((string) $metadata['hashtags']);
        }

        $metadata['tags'] = $this->parseHashtags((string) ($metadata['hashtags'] ?? ''));

        return $metadata;
    }

    private function buildMetadataComment(array $metadata): string
    {
        unset($metadata['tags']);

        $ordered = [];
        foreach (['title', 'kind', 'date', 'excerpt', 'hashtags', 'tag', 'generated', 'exclude_template'] as $key) {
            if (array_key_exists($key, $metadata)) {
                $ordered[$key] = (string) $metadata[$key];
            }
        }

        foreach ($metadata as $key => $value) {
            if (array_key_exists($key, $ordered)) {
                continue;
            }

            $ordered[$key] = (string) $value;
        }

        $pairs = [];
        foreach ($ordered as $key => $value) {
            if ($value === '') {
                continue;
            }

            $pairs[] = $key . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"';
        }

        return '<!-- WYSITE:META ' . implode(' ', $pairs) . ' -->';
    }

    private function parseHashtags(string $value): array
    {
        $tags = preg_split('/[\s,]+/', trim($value)) ?: [];
        $normalized = [];

        foreach ($tags as $tag) {
            $clean = $this->normalizeTag($tag);
            if ($clean === '' || in_array($clean, $normalized, true)) {
                continue;
            }

            $normalized[] = $clean;
        }

        return $normalized;
    }

    private function normalizeHashtagString(string $value): string
    {
        $tags = $this->parseHashtags($value);
        if ($tags === []) {
            return '';
        }

        return implode(' ', array_map(static fn(string $tag): string => '#' . $tag, $tags));
    }

    private function normalizeTag(string $value): string
    {
        $value = ltrim(trim(strtolower($value)), '#');
        $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?? $value;
        $value = preg_replace('/-+/', '-', $value) ?? $value;
        return trim($value, '-');
    }

    private function snippetForPage(string $relativePath): string
    {
        try {
            $page = $this->getPage($relativePath);
        } catch (RuntimeException) {
            return '';
        }

        foreach ($page['blocks'] as $block) {
            if (
                $block['name'] !== 'blog-post-content'
                && $block['name'] !== 'page-content'
                && preg_match('/^page-content-\d+$/', $block['name']) !== 1
            ) {
                continue;
            }

            $text = trim(preg_replace('/\s+/', ' ', strip_tags((string) $block['content'])) ?? '');
            if ($text !== '') {
                return substr($text, 0, 180) . (strlen($text) > 180 ? '...' : '');
            }
        }

        return '';
    }

    private function snippetFromHtml(string $html): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
        if ($text === '') {
            return '';
        }

        return substr($text, 0, 180) . (strlen($text) > 180 ? '...' : '');
    }

    private function parseBlocks(string $html): array
    {
        $pattern = '/(?P<start><!--\s*WYSITE:BEGIN(?P<attrs>.*?)-->)(?P<content>.*?)(?P<end><!--\s*WYSITE:END\s+name="(?P<endname>[^"]+)"\s*-->)/si';
        $matches = [];
        preg_match_all($pattern, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $blocks = [];
        foreach ($matches as $match) {
            $startComment = $match['start'][0];
            $endComment = $match['end'][0];
            $outer = $match[0][0];
            $attrs = $this->parseAttributes($match['attrs'][0]);
            $name = $attrs['name'] ?? $match['endname'][0];

            if ($name !== $match['endname'][0]) {
                throw new RuntimeException('Mismatched WYSiteIWYG block markers for ' . $name);
            }

            $blocks[] = [
                'name' => $name,
                'type' => $attrs['type'] ?? 'page',
                'label' => $attrs['label'] ?? ucwords(str_replace(['-', '_'], ' ', $name)),
                'content' => trim($match['content'][0]),
                'start_comment' => $startComment,
                'end_comment' => $endComment,
                'offset' => $match[0][1],
                'length' => strlen($outer),
            ];
        }

        return $blocks;
    }
}
