<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

final class ExternalSiteImporter
{
    private const IMPORT_EXECUTION_SECONDS = 900;
    private const HTML_FETCH_TIMEOUT_SECONDS = 10;
    private const ASSET_FETCH_TIMEOUT_SECONDS = 8;
    private const LARGE_ASSET_FETCH_TIMEOUT_SECONDS = 180;
    private const MAX_HTML_BYTES = 8_388_608;
    private const MAX_REWRITABLE_ASSET_BYTES = 12_582_912;
    private const MAX_STREAMED_ASSET_BYTES = 1_073_741_824;
    private const ASSET_PROGRESS_BYTES = 2_097_152;
    private const ASSET_PROGRESS_SECONDS = 2.0;

    private const TEMPLATE_FILES = [
        'page' => 'page.html',
        'blog-post' => 'blog-post.html',
        'blog' => 'blog-index.html',
    ];

    private const TEMPLATE_ROLES = [
        'page' => [
            ['id' => 'main-menu', 'label' => 'Main menu list', 'required' => true],
            ['id' => 'page-content', 'label' => 'Editable page content', 'required' => true],
        ],
        'blog-post' => [
            ['id' => 'main-menu', 'label' => 'Main menu list', 'required' => true],
            ['id' => 'blog-post-content', 'label' => 'Editable post body', 'required' => true],
        ],
        'blog' => [
            ['id' => 'main-menu', 'label' => 'Main menu list', 'required' => true],
            ['id' => 'blog-index-content', 'label' => 'Editable archive intro', 'required' => true],
            ['id' => 'blog-items', 'label' => 'Generated post list', 'required' => true],
        ],
    ];

    private string $rootPath;
    private string $editPath;
    private $progressCallback = null;

    public function __construct(string $rootPath, string $editPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->editPath = rtrim($editPath, '/');
    }

    public function templateRoles(string $kind): array
    {
        return self::TEMPLATE_ROLES[$kind] ?? throw new RuntimeException('Unknown template kind: ' . $kind);
    }

    public function cacheTemplateSource(string $url, string $kind): array
    {
        $this->templateFilename($kind);
        $fetched = $this->fetchHtml($url);
        $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $dir = $this->importsPath();

        Filesystem::ensureDirectory($dir);
        Filesystem::atomicWrite($dir . '/' . $id . '.html', $fetched['html']);

        $meta = [
            'id' => $id,
            'kind' => $kind,
            'url' => $fetched['url'],
            'title' => $this->titleFromHtml($fetched['html']) ?: $fetched['url'],
            'created_at' => gmdate('c'),
        ];

        Filesystem::atomicWrite($dir . '/' . $id . '.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $meta;
    }

    public function getCachedTemplateSource(string $id): array
    {
        $this->assertImportId($id);
        $dir = $this->importsPath();
        $metaPath = $dir . '/' . $id . '.json';
        $htmlPath = $dir . '/' . $id . '.html';

        if (!is_file($metaPath) || !is_file($htmlPath)) {
            throw new RuntimeException('Imported template source not found.');
        }

        $meta = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($meta)) {
            throw new RuntimeException('Imported template metadata could not be read.');
        }

        $meta['html'] = (string) file_get_contents($htmlPath);
        return $meta;
    }

    public function renderTemplateDesignerHtml(array $source, string $appUrl, string $csrfToken, string $username): string
    {
        $kind = (string) ($source['kind'] ?? 'page');
        $context = [
            'appUrl' => $appUrl,
            'csrfToken' => $csrfToken,
            'importId' => (string) $source['id'],
            'kind' => $kind,
            'sourceUrl' => (string) $source['url'],
            'roles' => $this->templateRoles($kind),
        ];

        $headInjection = '<base href="' . h((string) $source['url']) . '">' .
            '<link rel="stylesheet" href="' . h($appUrl) . '/assets/editor.css">' .
            '<script>window.WYSITE_TEMPLATE_IMPORT_CONTEXT = ' . json_encode($context, JSON_UNESCAPED_SLASHES) . ';</script>' .
            '<script type="module" src="' . h($appUrl) . '/assets/template-import-builder.js"></script>';

        $bar = '<div class="wysite-template-import-bar">' .
            '<div class="wysite-admin-bar__meta">' .
            '<strong>Template Import</strong>' .
            '<span>' . h((string) $source['title']) . '</span>' .
            '<span>' . h($kind) . '</span>' .
            '<span>Signed in as ' . h($username) . '</span>' .
            '</div>' .
            '<div class="wysite-admin-bar__actions">' .
            '<select id="wysite-template-role" class="wysite-template-role-select"></select>' .
            '<button id="wysite-template-save" class="wysite-admin-link wysite-admin-link--button" type="button">Save template</button>' .
            '<a class="wysite-admin-link" href="' . h($appUrl) . '/index.php">Dashboard</a>' .
            '</div>' .
            '</div>' .
            '<div id="wysite-template-selection-list" class="wysite-template-selection-list"></div>';

        $html = (string) $source['html'];
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

    public function promoteCachedTemplate(string $id, string $kind, array $selections): string
    {
        $source = $this->getCachedTemplateSource($id);
        $this->assertRequiredSelections($kind, $selections);

        $dom = $this->loadHtmlDocument((string) $source['html'], 'imported template source');
        $selected = [];
        foreach ($selections as $role => $path) {
            if (!is_string($role) || !$this->isKnownRole($kind, $role) || !is_array($path)) {
                continue;
            }

            $selected[$role] = $this->elementFromDomPath($dom, $path);
        }

        $this->prepareTemplateDocument($dom, $kind);
        foreach ($selected as $role => $element) {
            $this->applyTemplateRole($dom, $element, $role);
        }

        $html = $this->restorePlaceholderTokens($this->stripDomEncodingHack($dom->saveHTML() ?: ''));
        $this->assertTemplateHasRequiredTokens($kind, $html);

        $filename = $this->templateFilename($kind);
        $path = $this->editPath . '/templates/' . $filename;
        Filesystem::ensureDirectory(dirname($path));
        Filesystem::atomicWrite($path, $html);

        return 'edit/templates/' . $filename;
    }

    public function importSite(string $startUrl, int $maxPages = 50, bool $overwrite = false, ?callable $progressCallback = null): array
    {
        $previousProgressCallback = $this->progressCallback;
        $this->progressCallback = $progressCallback;
        $this->refreshExecutionBudget();

        try {
            $startUrl = $this->normalizeHttpUrl($startUrl);
            $maxPages = max(1, min(500, $maxPages));
            $startHost = strtolower((string) parse_url($startUrl, PHP_URL_HOST));
            $sourceHosts = $this->equivalentHosts($startHost);
            $queue = [$startUrl];
            $seen = [];
            $pagesVisited = 0;
            $saved = [];
            $skipped = [];
            $failed = [];
            $duplicates = [];
            $limitSkipped = [];
            $resourcesSaved = [];
            $nonEssentialSkipped = [];
            $visitedLocalPaths = [];
            $assetMap = [];
            $assetsSaved = [];
            $assetsFailed = [];

            $progress = function (string $type, array $extra = []) use (&$queue, &$seen, &$pagesVisited, &$saved, &$skipped, &$failed, &$duplicates, &$limitSkipped, &$resourcesSaved, &$nonEssentialSkipped, &$assetsSaved, &$assetsFailed, $maxPages): void {
                $this->emitProgress(array_merge([
                    'type' => $type,
                    'visited' => $pagesVisited,
                    'visited_urls' => count($seen),
                    'queued' => count($queue),
                    'max_pages' => $maxPages,
                    'saved' => count($saved),
                    'resources_saved' => count($resourcesSaved),
                    'skipped_existing' => count($skipped),
                    'skipped_duplicates' => count($duplicates),
                    'skipped_limit' => count($limitSkipped),
                    'skipped_nonessential' => count($nonEssentialSkipped),
                    'failed' => count($failed),
                    'assets_saved' => count($assetsSaved),
                    'wp_content_assets_saved' => $this->countWordPressContentAssetPaths($assetsSaved),
                    'assets_failed' => count($assetsFailed),
                ], $extra));
            };

            $progress('start', ['url' => $startUrl]);

            while ($queue !== []) {
                $this->refreshExecutionBudget();

                $url = array_shift($queue);
                if (!is_string($url) || isset($seen[$url])) {
                    continue;
                }

                if ($pagesVisited >= $maxPages && !$this->isQueuedResourceCandidate($url)) {
                    $seen[$url] = true;
                    $limitSkipped[] = $url;
                    $progress('page_skipped_limit', ['url' => $url, 'message' => 'Skipped because the HTML page limit was reached.']);
                    continue;
                }

                $seen[$url] = true;
                $progress('page_start', ['url' => $url]);

                try {
                    if ($this->isNonEssentialWordPressUrl($url)) {
                        $message = 'Skipped WordPress-specific endpoint.';
                        $this->recordSkippedUrl($nonEssentialSkipped, $url, $message);
                        $progress('page_skipped_nonessential', ['url' => $url, 'message' => $message]);
                        continue;
                    }

                    $fetched = $this->fetchImportableDocument($url);
                    $relativePath = $this->localDocumentPathForUrl($url, (string) $fetched['content_type']);
                    if (isset($visitedLocalPaths[$relativePath])) {
                        $duplicates[] = $relativePath;
                        $progress('page_duplicate', ['url' => $url, 'path' => $relativePath]);
                        continue;
                    }
                    $visitedLocalPaths[$relativePath] = true;

                    $target = $this->rootPath . '/' . $relativePath;
                    $isHtml = $this->isHtmlDocumentUrl($url, (string) $fetched['content_type']);
                    $body = (string) $fetched['body'];
                    if ($isHtml) {
                        $pagesVisited++;
                    }

                    if (!$overwrite && is_file($target)) {
                        $skipped[] = $relativePath;
                        $progress('page_skipped_existing', ['url' => $url, 'path' => $relativePath]);
                        unset($fetched, $body);
                        continue;
                    }

                    if ($isHtml) {
                        $rewritten = $this->rewriteImportedHtmlReferences(
                            $body,
                            $fetched['url'],
                            $sourceHosts,
                            $assetMap,
                            $assetsSaved,
                            $assetsFailed,
                            $nonEssentialSkipped
                        );
                        Filesystem::atomicWrite($target, $rewritten['html']);
                        $saved[] = $relativePath;
                        $progress('page_saved', ['url' => $url, 'path' => $relativePath, 'found_links' => count($rewritten['links'])]);

                        foreach ($rewritten['links'] as $link) {
                            if (!isset($seen[$link]) && !in_array($link, $queue, true)) {
                                $queue[] = $link;
                            }
                        }
                    } else {
                        $rewrittenResource = $this->rewriteStaticResourceReferences(
                            $body,
                            $fetched['url'],
                            (string) $fetched['content_type'],
                            $sourceHosts,
                            $assetMap,
                            $assetsSaved,
                            $assetsFailed,
                            $nonEssentialSkipped
                        );
                        Filesystem::atomicWrite($target, $rewrittenResource['body']);
                        $resourcesSaved[] = $relativePath;
                        $progress('resource_saved', [
                            'url' => $url,
                            'path' => $relativePath,
                            'content_type' => (string) $fetched['content_type'],
                            'bytes' => strlen($rewrittenResource['body']),
                            'found_links' => count($rewrittenResource['links']),
                        ]);

                        foreach ($rewrittenResource['links'] as $link) {
                            if (!isset($seen[$link]) && !in_array($link, $queue, true)) {
                                $queue[] = $link;
                            }
                        }
                    }

                    unset($fetched, $rewritten, $rewrittenResource, $body);
                    if (function_exists('gc_collect_cycles')) {
                        gc_collect_cycles();
                    }
                } catch (RuntimeException $error) {
                    $message = $error->getMessage();
                    if (str_starts_with($message, 'Skipping non-essential response')) {
                        $this->recordSkippedUrl($nonEssentialSkipped, $url, $message);
                        $progress('page_skipped_nonessential', ['url' => $url, 'message' => $message]);
                    } elseif ($this->shouldMirrorDocumentFetchFailure($url, $message)) {
                        $assetPath = $this->mirrorAsset($url, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
                        if ($assetPath !== null) {
                            $redirectPath = $this->writeQueuedAssetRedirect($url, $assetPath, $overwrite);
                            if ($redirectPath !== null) {
                                $resourcesSaved[] = $redirectPath;
                            }
                            $progress('queued_asset_saved', ['url' => $url, 'path' => $assetPath]);
                        }
                    } else {
                        $failed[] = $url . ' - ' . $message;
                        $progress('page_failed', ['url' => $url, 'message' => $message]);
                    }
                }
            }

            $permissionReport = $this->normalizeImportedAssetPermissions();
            if (($permissionReport['files'] + $permissionReport['directories']) > 0) {
                $progress('asset_permissions_repaired', $permissionReport);
            }

            $localizeReport = $this->relocalizeImportedOutput();
            if (($localizeReport['pages'] + $localizeReport['stylesheets']) > 0) {
                $progress('localized', $localizeReport);
            }

            $result = [
                'saved' => $saved,
                'localized' => $localizeReport,
                'resources_saved' => $resourcesSaved,
                'skipped' => $skipped,
                'duplicates' => $duplicates,
                'limit_skipped' => $limitSkipped,
                'nonessential_skipped' => array_values($nonEssentialSkipped),
                'non_html_skipped' => array_values($nonEssentialSkipped),
                'failed' => $failed,
                'visited' => $pagesVisited,
                'visited_urls' => count($seen),
                'assets_saved' => array_keys($assetsSaved),
                'wp_content_assets_saved' => $this->countWordPressContentAssetPaths($assetsSaved),
                'asset_permissions_fixed' => $permissionReport,
                'assets_failed' => array_values($assetsFailed),
            ];
            $progress('complete', ['result' => $result]);

            return $result;
        } finally {
            $this->progressCallback = $previousProgressCallback;
        }
    }

    /**
     * Make already-imported output location-agnostic. Raw (unmanaged) imported HTML
     * pages get a per-page relative <base> and base-relative URLs; mirrored
     * stylesheet url() refs are rewritten to resolve relative to each CSS file (the
     * document <base> does not affect CSS url()). Managed pages (with WYSITE markers)
     * are left to SiteGenerator. Idempotent — safe to re-run as a backfill.
     *
     * @return array{pages:int,stylesheets:int}
     */
    public function relocalizeImportedOutput(): array
    {
        $pages = 0;
        $stylesheets = 0;

        if (!is_dir($this->rootPath)) {
            return ['pages' => 0, 'stylesheets' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $full = str_replace('\\', '/', $file->getPathname());
            $rel = ltrim(substr($full, strlen($this->rootPath)), '/');
            if ($rel === '' || str_starts_with($rel, 'edit/')) {
                continue;
            }

            $ext = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));

            if (in_array($ext, ['html', 'htm'], true)) {
                $html = (string) file_get_contents($full);
                if (str_contains($html, 'WYSITE:BEGIN')) {
                    continue; // managed page — SiteGenerator owns its localization
                }
                $localized = UrlLocalizer::localizeHtml($html, $rel);
                if ($localized !== $html) {
                    Filesystem::atomicWrite($full, $localized);
                    $pages++;
                }
                continue;
            }

            if ($ext === 'css' && str_starts_with($rel, 'assets/imported/')) {
                $css = (string) file_get_contents($full);
                $localized = UrlLocalizer::localizeCssUrls($css, dirname($rel));
                if ($localized !== $css) {
                    Filesystem::atomicWrite($full, $localized);
                    $stylesheets++;
                }
            }
        }

        return ['pages' => $pages, 'stylesheets' => $stylesheets];
    }

    public function importAssetUrls(array $urls): array
    {
        $this->refreshExecutionBudget();

        $assetMap = [];
        $assetsSaved = [];
        $assetsFailed = [];
        $urlToLocalPath = [];

        foreach ($this->normalizeAssetUrlList($urls) as $url) {
            try {
                $host = strtolower((string) parse_url($url, PHP_URL_HOST));
                $localPath = $this->mirrorAsset($url, $this->equivalentHosts($host), $assetMap, $assetsSaved, $assetsFailed);
                if ($localPath !== null) {
                    $urlToLocalPath[$this->withoutFragment($url)] = $localPath;
                }
            } catch (RuntimeException $error) {
                $assetsFailed[$url] = $url . ' - ' . $error->getMessage();
            }
        }

        return [
            'assets_saved' => array_keys($assetsSaved),
            'assets_failed' => array_values($assetsFailed),
            'asset_permissions_fixed' => $this->normalizeImportedAssetPermissions(),
            'updated_files' => $this->rewriteImportedAssetReferences($urlToLocalPath),
        ];
    }

    private function countWordPressContentAssetPaths(array $assetsSaved): int
    {
        $count = 0;
        foreach (array_keys($assetsSaved) as $path) {
            if (is_string($path) && str_contains($path, '/wp-content/')) {
                $count++;
            }
        }

        return $count;
    }

    private function makePublicFile(string $path): void
    {
        @chmod($path, 0644);
    }

    private function normalizeImportedAssetPermissions(): array
    {
        $base = $this->rootPath . '/assets/imported';
        $fixedFiles = 0;
        $fixedDirectories = 0;

        if (!is_dir($base)) {
            return ['files' => 0, 'directories' => 0];
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $mode = @fileperms($path);
            if (!is_int($mode)) {
                continue;
            }

            if ($item->isDir()) {
                if (($mode & 0755) !== 0755 && @chmod($path, 0755)) {
                    $fixedDirectories++;
                }
                continue;
            }

            if ($item->isFile() && ($mode & 0644) !== 0644 && @chmod($path, 0644)) {
                $fixedFiles++;
            }
        }

        return ['files' => $fixedFiles, 'directories' => $fixedDirectories];
    }

    private function applyTemplateRole(DOMDocument $dom, DOMElement $element, string $role): void
    {
        match ($role) {
            'main-menu' => $this->replaceOuterHtml($dom, $element, $this->markerHtml('main-menu', 'menu', 'Main Menu', '{{MAIN_MENU}}')),
            'page-content' => $this->replaceInnerHtml($dom, $element, $this->markerHtml('page-content', 'page', 'Page Content', '{{PAGE_CONTENT_BLOCK}}')),
            'blog-post-content' => $this->replaceInnerHtml($dom, $element, $this->markerHtml('blog-post-content', 'blog-post', 'Blog Post Content', '{{BLOG_POST_CONTENT}}') . "\n{{TAG_LINKS}}"),
            'blog-index-content' => $this->replaceInnerHtml($dom, $element, $this->markerHtml('blog-index-content', 'blog', 'Blog Index Intro', '{{BLOG_INDEX_CONTENT}}')),
            'blog-items' => $this->replaceInnerHtml($dom, $element, '{{BLOG_ITEMS}}'),
            default => null,
        };
    }

    private function markerHtml(string $name, string $type, string $label, string $token): string
    {
        return '<!-- WYSITE:BEGIN name="' . h($name) . '" type="' . h($type) . '" label="' . h($label) . "\" -->\n" .
            $token . "\n" .
            '<!-- WYSITE:END name="' . h($name) . '" -->';
    }

    private function prepareTemplateDocument(DOMDocument $dom, string $kind): void
    {
        $html = $dom->documentElement;
        if (!$html instanceof DOMElement) {
            throw new RuntimeException('Imported template source has no document element.');
        }

        $head = $dom->getElementsByTagName('head')->item(0);
        if (!$head instanceof DOMElement) {
            $head = $dom->createElement('head');
            $html->insertBefore($head, $html->firstChild);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body instanceof DOMElement) {
            $existingClass = trim($body->getAttribute('class'));
            $body->setAttribute('class', trim('{{BODY_CLASS}} ' . $existingClass));
        }

        $this->removeExistingWysiteMeta($head);
        $head->insertBefore($dom->createComment($this->metaCommentText($kind)), $head->firstChild);
        $this->upsertTitle($dom, $head);
        $this->upsertDescription($dom, $head);

        if (!str_contains($dom->saveHTML() ?: '', '{{THEME_CSS_HREF}}')) {
            $link = $dom->createElement('link');
            $link->setAttribute('rel', 'stylesheet');
            $link->setAttribute('href', '{{THEME_CSS_HREF}}');
            $head->appendChild($link);
        }

        if ($body instanceof DOMElement && !str_contains($dom->saveHTML($body) ?: '', '{{WYSITE_PUBLIC_BRIDGE}}')) {
            $body->appendChild($dom->createTextNode("\n{{WYSITE_PUBLIC_BRIDGE}}\n"));
        }
    }

    private function removeExistingWysiteMeta(DOMElement $head): void
    {
        $remove = [];
        foreach ($head->childNodes as $child) {
            if ($child->nodeType === XML_COMMENT_NODE && str_contains((string) $child->nodeValue, 'WYSITE:META')) {
                $remove[] = $child;
            }
        }

        foreach ($remove as $child) {
            $head->removeChild($child);
        }
    }

    private function metaCommentText(string $kind): string
    {
        return match ($kind) {
            'blog-post' => ' WYSITE:META title="{{TITLE}}" kind="blog-post" date="{{DATE}}" excerpt="{{EXCERPT}}" hashtags="{{HASHTAGS}}" ',
            'blog' => ' WYSITE:META title="{{TITLE}}" kind="blog" excerpt="{{EXCERPT}}" tag="{{TAG}}" ',
            default => ' WYSITE:META title="{{TITLE}}" kind="page" excerpt="{{EXCERPT}}" ',
        };
    }

    private function upsertTitle(DOMDocument $dom, DOMElement $head): void
    {
        $title = $head->getElementsByTagName('title')->item(0);
        if (!$title instanceof DOMElement) {
            $title = $dom->createElement('title');
            $head->appendChild($title);
        }

        while ($title->firstChild) {
            $title->removeChild($title->firstChild);
        }
        $title->appendChild($dom->createTextNode('{{TITLE}}'));
    }

    private function upsertDescription(DOMDocument $dom, DOMElement $head): void
    {
        $description = null;
        foreach ($head->getElementsByTagName('meta') as $meta) {
            if ($meta instanceof DOMElement && strtolower($meta->getAttribute('name')) === 'description') {
                $description = $meta;
                break;
            }
        }

        if (!$description instanceof DOMElement) {
            $description = $dom->createElement('meta');
            $description->setAttribute('name', 'description');
            $head->appendChild($description);
        }

        $description->setAttribute('content', '{{EXCERPT}}');
    }

    private function assertTemplateHasRequiredTokens(string $kind, string $html): void
    {
        $required = match ($kind) {
            'blog-post' => ['{{TITLE}}', '{{EXCERPT}}', '{{DATE}}', '{{HASHTAGS}}', '{{THEME_CSS_HREF}}', '{{WYSITE_PUBLIC_BRIDGE}}', '{{MAIN_MENU}}', '{{BLOG_POST_CONTENT}}', '{{TAG_LINKS}}'],
            'blog' => ['{{TITLE}}', '{{EXCERPT}}', '{{TAG}}', '{{THEME_CSS_HREF}}', '{{WYSITE_PUBLIC_BRIDGE}}', '{{MAIN_MENU}}', '{{BLOG_INDEX_CONTENT}}', '{{BLOG_ITEMS}}'],
            default => ['{{TITLE}}', '{{EXCERPT}}', '{{THEME_CSS_HREF}}', '{{WYSITE_PUBLIC_BRIDGE}}', '{{MAIN_MENU}}', '{{PAGE_CONTENT_BLOCK}}'],
        };

        foreach ($required as $token) {
            if (!str_contains($html, $token)) {
                throw new RuntimeException('The generated template is missing ' . $token . '. Select the required sections again.');
            }
        }
    }

    private function assertRequiredSelections(string $kind, array $selections): void
    {
        foreach ($this->templateRoles($kind) as $role) {
            if (!empty($role['required']) && empty($selections[$role['id']])) {
                throw new RuntimeException('Select a section for "' . $role['label'] . '" before saving the template.');
            }
        }
    }

    private function isKnownRole(string $kind, string $role): bool
    {
        foreach ($this->templateRoles($kind) as $definition) {
            if ($definition['id'] === $role) {
                return true;
            }
        }

        return false;
    }

    private function fetchHtml(string $url): array
    {
        $this->refreshExecutionBudget();

        $url = $this->normalizeHttpUrl($url);
        $response = $this->fetchUrlBody(
            $url,
            self::HTML_FETCH_TIMEOUT_SECONDS,
            self::MAX_HTML_BYTES,
            "Accept: text/html,application/xhtml+xml\r\n"
        );

        $contentType = strtolower(trim(explode(';', $response['content_type'])[0] ?? ''));
        if ($contentType !== '' && !in_array($contentType, ['text/html', 'application/xhtml+xml'], true)) {
            throw new RuntimeException('Skipping non-HTML response from ' . $url . ' (' . $contentType . ').');
        }

        $html = $response['body'];
        if ($html === '') {
            throw new RuntimeException('Unable to fetch ' . $url . '.');
        }

        return [
            'url' => $url,
            'html' => $html,
        ];
    }

    private function fetchImportableDocument(string $url): array
    {
        $this->refreshExecutionBudget();

        $url = $this->normalizeHttpUrl($url);
        $response = $this->fetchUrlBody(
            $url,
            self::HTML_FETCH_TIMEOUT_SECONDS,
            self::MAX_HTML_BYTES,
            "Accept: text/html,application/xhtml+xml,application/rss+xml,application/atom+xml,application/xml,text/xml,application/json,text/plain;q=0.9,*/*;q=0.1\r\n"
        );

        $body = (string) $response['body'];
        if ($body === '') {
            throw new RuntimeException('Unable to fetch ' . $url . '.');
        }

        $contentType = (string) $response['content_type'];
        if (!$this->isHtmlDocumentUrl($url, $contentType) && !$this->isImportableStaticDocument($url, $contentType)) {
            throw new RuntimeException('Non-document response should be mirrored as an asset from ' . $url . ' (' . ($this->normalizedContentType($contentType) ?: 'unknown content type') . ').');
        }

        return [
            'url' => $url,
            'body' => $body,
            'content_type' => $contentType,
        ];
    }

    private function normalizeHttpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('Enter a URL to import.');
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            throw new RuntimeException('Only http and https URLs can be imported.');
        }

        return $url;
    }

    private function rewriteImportedHtmlReferences(
        string $html,
        string $pageUrl,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$nonEssentialSkipped
    ): array {
        $dom = $this->loadHtmlDocument($html, $pageUrl);
        $baseUrl = $this->documentBaseUrl($dom, $pageUrl);
        $pageLinks = [];

        foreach (['a' => 'href', 'area' => 'href', 'form' => 'action'] as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                if ($element instanceof DOMElement) {
                    $this->rewriteUrlAttribute($element, $attribute, 'page', $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $pageLinks, $nonEssentialSkipped);
                }
            }
        }

        foreach ([
            ['script', 'src'],
            ['img', 'src'],
            ['source', 'src'],
            ['video', 'src'],
            ['audio', 'src'],
            ['track', 'src'],
            ['embed', 'src'],
            ['input', 'src'],
            ['video', 'poster'],
            ['object', 'data'],
        ] as [$tag, $attribute]) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                if ($element instanceof DOMElement) {
                    $this->rewriteUrlAttribute($element, $attribute, 'asset', $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $pageLinks, $nonEssentialSkipped);
                }
            }
        }

        foreach ($dom->getElementsByTagName('iframe') as $element) {
            if ($element instanceof DOMElement) {
                $this->rewriteUrlAttribute($element, 'src', 'page', $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $pageLinks, $nonEssentialSkipped);
            }
        }

        foreach ($dom->getElementsByTagName('link') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttribute('href')) {
                continue;
            }

            $absoluteHref = $this->absolutizeUrl($element->getAttribute('href'), $baseUrl, false);
            $absoluteHost = is_string($absoluteHref) ? strtolower((string) parse_url($absoluteHref, PHP_URL_HOST)) : '';
            $rel = strtolower($element->getAttribute('rel'));
            if (preg_match('/\b(canonical|alternate|archives|index)\b/', $rel) === 1) {
                $mode = 'page';
            } elseif (preg_match('/\b(stylesheet|icon|apple-touch-icon|manifest|preload|modulepreload)\b/', $rel) === 1) {
                $mode = 'asset';
            } elseif (is_string($absoluteHref) && $this->hostMatches($sourceHosts, $absoluteHost) && $this->isNonEssentialWordPressUrl($absoluteHref)) {
                $mode = 'page';
            } else {
                continue;
            }

            $this->rewriteUrlAttribute($element, 'href', $mode, $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $pageLinks, $nonEssentialSkipped);
        }

        foreach (['img', 'source'] as $tag) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                if ($element instanceof DOMElement && $element->hasAttribute('srcset')) {
                    $element->setAttribute(
                        'srcset',
                        $this->rewriteSrcset($element->getAttribute('srcset'), $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed)
                    );
                }
            }
        }

        foreach ($dom->getElementsByTagName('*') as $element) {
            if ($element instanceof DOMElement && $element->hasAttribute('style')) {
                $element->setAttribute(
                    'style',
                    $this->rewriteCssReferences($element->getAttribute('style'), $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed)
                );
            }
        }

        foreach ($dom->getElementsByTagName('style') as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }

            $css = $element->textContent;
            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($dom->createTextNode($this->rewriteCssReferences($css, $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed)));
        }

        $this->rewriteGenericElementUrlAttributes($dom, $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $nonEssentialSkipped, $pageLinks);
        $this->rewriteInlineScriptUrlReferences($dom, $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $nonEssentialSkipped, $pageLinks);
        $this->removeBaseElements($dom);
        $outputHtml = $this->stripDomEncodingHack($dom->saveHTML() ?: $html);
        $links = array_keys($pageLinks);
        unset($dom);

        return [
            'html' => $outputHtml,
            'links' => $links,
        ];
    }

    private function rewriteUrlAttribute(
        DOMElement $element,
        string $attribute,
        string $mode,
        string $baseUrl,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$pageLinks,
        array &$nonEssentialSkipped
    ): void {
        if (!$element->hasAttribute($attribute)) {
            return;
        }

        $rawUrl = $element->getAttribute($attribute);
        $effectiveMode = $mode;
        if ($mode === 'page' && ($element->hasAttribute('download') || $this->isLikelyDownloadUrl($rawUrl))) {
            $effectiveMode = 'asset';
        }

        $rewritten = $this->rewriteReferencedUrl(
            $rawUrl,
            $baseUrl,
            $effectiveMode,
            $sourceHosts,
            $assetMap,
            $assetsSaved,
            $assetsFailed,
            $pageLinks,
            $nonEssentialSkipped
        );

        if ($rewritten !== null) {
            $element->setAttribute($attribute, $rewritten);
        }
    }

    private function rewriteReferencedUrl(
        string $rawUrl,
        string $baseUrl,
        string $mode,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$pageLinks,
        array &$nonEssentialSkipped
    ): ?string {
        $absoluteUrl = $this->absolutizeUrl($rawUrl, $baseUrl, false);
        if ($absoluteUrl === null) {
            return null;
        }

        $parts = parse_url($absoluteUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $path = (string) ($parts['path'] ?? '/');

        if ($mode === 'page' && $this->isLikelyDownloadUrl($absoluteUrl)) {
            $assetPath = $this->mirrorAsset($absoluteUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
            return $assetPath !== null ? '/' . $assetPath . $this->fragmentSuffix($absoluteUrl) : null;
        }

        if ($mode === 'page') {
            if (!$this->hostMatches($sourceHosts, $host)) {
                return null;
            }

            if ($this->isNonEssentialWordPressUrl($absoluteUrl)) {
                $this->recordSkippedUrl($nonEssentialSkipped, $absoluteUrl, 'Skipped WordPress-specific endpoint.');
                return null;
            }

            if ($this->isWordPressContentUrl($absoluteUrl)) {
                $assetPath = $this->mirrorAsset($absoluteUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
                return $assetPath !== null ? '/' . $assetPath . $this->fragmentSuffix($absoluteUrl) : null;
            }

            if ($this->isImportableStaticDocument($absoluteUrl, '')) {
                $pageLinks[$this->withoutFragment($absoluteUrl)] = true;
                return $this->localUrlForDocumentUrl($absoluteUrl);
            }

            if ($this->looksLikeHtmlPage($path)) {
                $pageLinks[$this->withoutFragment($absoluteUrl)] = true;
                return $this->localUrlForPageUrl($absoluteUrl);
            }

            $assetPath = $this->mirrorAsset($absoluteUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
            return $assetPath !== null ? '/' . $assetPath . $this->fragmentSuffix($absoluteUrl) : null;
        }

        $assetPath = $this->mirrorAsset($absoluteUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
        return $assetPath !== null ? '/' . $assetPath . $this->fragmentSuffix($absoluteUrl) : null;
    }

    private function rewriteSrcset(
        string $srcset,
        string $baseUrl,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed
    ): string {
        $items = array_map('trim', explode(',', $srcset));
        $rewritten = [];
        $pageLinks = [];
        $nonEssentialSkipped = [];

        foreach ($items as $item) {
            if ($item === '' || str_starts_with(strtolower($item), 'data:')) {
                $rewritten[] = $item;
                continue;
            }

            $parts = preg_split('/\s+/', $item, 2);
            $url = $parts[0] ?? '';
            $descriptor = $parts[1] ?? '';
            $next = $this->rewriteReferencedUrl($url, $baseUrl, 'asset', $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $pageLinks, $nonEssentialSkipped);
            $rewritten[] = trim(($next ?? $url) . ($descriptor !== '' ? ' ' . $descriptor : ''));
        }

        return implode(', ', $rewritten);
    }

    private function rewriteCssReferences(
        string $css,
        string $baseUrl,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed
    ): string {
        $pageLinks = [];
        $nonEssentialSkipped = [];
        $css = preg_replace_callback(
            '/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
            function (array $match) use ($baseUrl, $sourceHosts, &$assetMap, &$assetsSaved, &$assetsFailed, &$pageLinks, &$nonEssentialSkipped): string {
                $quote = $match[1] !== '' ? $match[1] : '"';
                $url = trim((string) $match[2]);
                $rewritten = $this->rewriteReferencedUrl($url, $baseUrl, 'asset', $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $pageLinks, $nonEssentialSkipped);
                return 'url(' . $quote . ($rewritten ?? $url) . $quote . ')';
            },
            $css
        ) ?? $css;

        return preg_replace_callback(
            '/@import\s+([\'"])([^\'"]+)\1/i',
            function (array $match) use ($baseUrl, $sourceHosts, &$assetMap, &$assetsSaved, &$assetsFailed, &$pageLinks, &$nonEssentialSkipped): string {
                $rewritten = $this->rewriteReferencedUrl($match[2], $baseUrl, 'asset', $sourceHosts, $assetMap, $assetsSaved, $assetsFailed, $pageLinks, $nonEssentialSkipped);
                return '@import ' . $match[1] . ($rewritten ?? $match[2]) . $match[1];
            },
            $css
        ) ?? $css;
    }

    private function rewriteGenericElementUrlAttributes(
        DOMDocument $dom,
        string $baseUrl,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$nonEssentialSkipped,
        array &$pageLinks
    ): void {
        foreach ($dom->getElementsByTagName('*') as $element) {
            if (!$element instanceof DOMElement || !$element->hasAttributes()) {
                continue;
            }

            $attributes = [];
            foreach ($element->attributes ?? [] as $attribute) {
                $attributes[] = [$attribute->nodeName, (string) $attribute->nodeValue];
            }

            foreach ($attributes as [$name, $value]) {
                $lowerName = strtolower($name);
                if (in_array($lowerName, ['href', 'src', 'action', 'poster', 'data', 'style'], true)) {
                    continue;
                }

                if ($lowerName === 'srcset' || str_ends_with($lowerName, 'srcset')) {
                    $next = $this->rewriteSrcset($value, $baseUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
                } elseif ($this->attributeMayContainUrl($lowerName, $value)) {
                    $next = $this->rewriteEmbeddedUrlReferences(
                        $value,
                        $baseUrl,
                        $lowerName,
                        $sourceHosts,
                        $assetMap,
                        $assetsSaved,
                        $assetsFailed,
                        $nonEssentialSkipped,
                        $pageLinks
                    );
                } else {
                    continue;
                }

                if ($next !== $value) {
                    $element->setAttribute($name, $next);
                }
            }
        }
    }

    private function rewriteInlineScriptUrlReferences(
        DOMDocument $dom,
        string $baseUrl,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$nonEssentialSkipped,
        array &$pageLinks
    ): void {
        foreach ($dom->getElementsByTagName('script') as $element) {
            if (!$element instanceof DOMElement || $element->hasAttribute('src')) {
                continue;
            }

            $contents = $element->textContent;
            $next = $this->rewriteEmbeddedUrlReferences(
                $contents,
                $baseUrl,
                'script',
                $sourceHosts,
                $assetMap,
                $assetsSaved,
                $assetsFailed,
                $nonEssentialSkipped,
                $pageLinks
            );

            if ($next === $contents) {
                continue;
            }

            while ($element->firstChild) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($dom->createTextNode($next));
        }
    }

    private function attributeMayContainUrl(string $name, string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (str_contains($value, '://') || str_contains($value, '&lt;') || str_contains($value, '<')) {
            return true;
        }

        if (!preg_match('/(?:^|[-_:])(?:src|href|url|uri|link|file|image|img|audio|video|media|poster|cover|thumb|thumbnail|background|bg|feed|rss|embed|download)(?:$|[-_:])/', $name)) {
            return false;
        }

        return preg_match('~^(?:/|\.{0,2}/|https?://|//)[^\s<>"\']+$~i', trim($value)) === 1;
    }

    private function rewriteEmbeddedUrlReferences(
        string $value,
        string $baseUrl,
        string $hint,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$nonEssentialSkipped,
        array &$pageLinks
    ): string {
        $rewritten = preg_replace_callback(
            '~https?:\\\\+/\\\\+/[^\\s<>"\']+~i',
            function (array $match) use ($baseUrl, $hint, $sourceHosts, &$assetMap, &$assetsSaved, &$assetsFailed, &$nonEssentialSkipped, &$pageLinks): string {
                $rawUrl = preg_replace('~\\\\+/~', '/', (string) $match[0]) ?? (string) $match[0];
                $suffix = '';
                while ($rawUrl !== '' && preg_match('/[\\\\.,;:!?]+$/', $rawUrl) === 1) {
                    $suffix = substr($rawUrl, -1) . $suffix;
                    $rawUrl = substr($rawUrl, 0, -1);
                }

                $next = $this->rewriteStaticResourceUrl(
                    $rawUrl,
                    $baseUrl,
                    $hint,
                    $sourceHosts,
                    $assetMap,
                    $assetsSaved,
                    $assetsFailed,
                    $nonEssentialSkipped,
                    $pageLinks
                );

                return $next !== null ? str_replace('/', '\\/', $next . $suffix) : $match[0];
            },
            $value
        ) ?? $value;

        $rewritten = preg_replace_callback(
            '~(?<![A-Za-z0-9/+.-])(?:https?:)?//[^\s<>"\'\]\)\\\\]+~i',
            function (array $match) use ($baseUrl, $hint, $sourceHosts, &$assetMap, &$assetsSaved, &$assetsFailed, &$nonEssentialSkipped, &$pageLinks): string {
                $rawUrl = html_entity_decode((string) $match[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $suffix = '';
                while ($rawUrl !== '' && preg_match('/[.,;:!?]+$/', $rawUrl) === 1) {
                    $suffix = substr($rawUrl, -1) . $suffix;
                    $rawUrl = substr($rawUrl, 0, -1);
                }

                $next = $this->rewriteStaticResourceUrl(
                    $rawUrl,
                    $baseUrl,
                    $hint,
                    $sourceHosts,
                    $assetMap,
                    $assetsSaved,
                    $assetsFailed,
                    $nonEssentialSkipped,
                    $pageLinks
                );

                return ($next ?? $match[0]) . $suffix;
            },
            $rewritten
        ) ?? $rewritten;

        if ($rewritten !== $value || !preg_match('~^(?:/|\.{0,2}/)[^\s<>"\']+$~', trim($value))) {
            return $rewritten;
        }

        $next = $this->rewriteStaticResourceUrl(
            trim($value),
            $baseUrl,
            $hint,
            $sourceHosts,
            $assetMap,
            $assetsSaved,
            $assetsFailed,
            $nonEssentialSkipped,
            $pageLinks
        );

        return $next ?? $value;
    }

    private function rewriteStaticResourceReferences(
        string $body,
        string $resourceUrl,
        string $contentType,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$nonEssentialSkipped
    ): array {
        $pageLinks = [];
        $rewritten = $body;

        $rewritten = preg_replace_callback(
            '/\b(href|src|url|poster|data|action)=([\'"])([^\'"]+)\2/i',
            function (array $match) use ($resourceUrl, $sourceHosts, &$assetMap, &$assetsSaved, &$assetsFailed, &$nonEssentialSkipped, &$pageLinks): string {
                $next = $this->rewriteStaticResourceUrl(
                    (string) $match[3],
                    $resourceUrl,
                    strtolower((string) $match[1]),
                    $sourceHosts,
                    $assetMap,
                    $assetsSaved,
                    $assetsFailed,
                    $nonEssentialSkipped,
                    $pageLinks
                );

                return $match[1] . '=' . $match[2] . ($next ?? $match[3]) . $match[2];
            },
            $rewritten
        ) ?? $rewritten;

        if ($this->isXmlLikeStaticDocument($resourceUrl, $contentType)) {
            $rewritten = preg_replace_callback(
                '~(<link\b[^>]*>\s*)(https?://[^<\s]+)(\s*</link>)~i',
                function (array $match) use ($resourceUrl, $sourceHosts, &$assetMap, &$assetsSaved, &$assetsFailed, &$nonEssentialSkipped, &$pageLinks): string {
                    $next = $this->rewriteStaticResourceUrl(
                        (string) $match[2],
                        $resourceUrl,
                        'link-text',
                        $sourceHosts,
                        $assetMap,
                        $assetsSaved,
                        $assetsFailed,
                        $nonEssentialSkipped,
                        $pageLinks
                    );

                    return $match[1] . ($next ?? $match[2]) . $match[3];
                },
                $rewritten
            ) ?? $rewritten;
        } elseif ($this->isJsonLikeStaticDocument($resourceUrl, $contentType)) {
            $rewritten = preg_replace_callback(
                '/("(?:url|href|src|poster|data|contentUrl|embedUrl)"\s*:\s*")([^"]+)(")/i',
                function (array $match) use ($resourceUrl, $sourceHosts, &$assetMap, &$assetsSaved, &$assetsFailed, &$nonEssentialSkipped, &$pageLinks): string {
                    $next = $this->rewriteStaticResourceUrl(
                        (string) $match[2],
                        $resourceUrl,
                        'json-url',
                        $sourceHosts,
                        $assetMap,
                        $assetsSaved,
                        $assetsFailed,
                        $nonEssentialSkipped,
                        $pageLinks
                    );

                    return $match[1] . ($next ?? $match[2]) . $match[3];
                },
                $rewritten
            ) ?? $rewritten;
        }

        $rewritten = $this->rewriteEmbeddedUrlReferences(
            $rewritten,
            $resourceUrl,
            'resource',
            $sourceHosts,
            $assetMap,
            $assetsSaved,
            $assetsFailed,
            $nonEssentialSkipped,
            $pageLinks
        );

        return [
            'body' => $rewritten,
            'links' => array_keys($pageLinks),
        ];
    }

    private function rewriteStaticResourceUrl(
        string $rawUrl,
        string $baseUrl,
        string $hint,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed,
        array &$nonEssentialSkipped,
        array &$pageLinks
    ): ?string {
        $absoluteUrl = $this->absolutizeUrl($rawUrl, $baseUrl, false);
        if ($absoluteUrl === null) {
            return null;
        }

        $parts = parse_url($absoluteUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        if ($this->isNonEssentialWordPressUrl($absoluteUrl)) {
            $this->recordSkippedUrl($nonEssentialSkipped, $absoluteUrl, 'Skipped WordPress-specific endpoint.');
            return null;
        }

        $host = strtolower((string) $parts['host']);
        $sameSourceHost = $this->hostMatches($sourceHosts, $host);
        $path = (string) ($parts['path'] ?? '/');

        if ($sameSourceHost) {
            if ($this->isWordPressContentUrl($absoluteUrl)) {
                $assetPath = $this->mirrorAsset($absoluteUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
                return $assetPath !== null ? '/' . $assetPath . $this->fragmentSuffix($absoluteUrl) : null;
            }

            if ($this->isImportableStaticDocument($absoluteUrl, '')) {
                $pageLinks[$this->withoutFragment($absoluteUrl)] = true;
                return $this->localUrlForDocumentUrl($absoluteUrl);
            }

            if ($this->isLikelyDownloadUrl($absoluteUrl) || $this->looksLikeAssetUrl($absoluteUrl) || !$this->looksLikeHtmlPage($path)) {
                $assetPath = $this->mirrorAsset($absoluteUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
                return $assetPath !== null ? '/' . $assetPath . $this->fragmentSuffix($absoluteUrl) : null;
            }

            $pageLinks[$this->withoutFragment($absoluteUrl)] = true;
            return $this->localUrlForPageUrl($absoluteUrl);
        }

        if ($this->looksLikeAssetUrl($absoluteUrl) || in_array($hint, ['src', 'url', 'poster', 'data', 'json-url'], true)) {
            $assetPath = $this->mirrorAsset($absoluteUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
            return $assetPath !== null ? '/' . $assetPath . $this->fragmentSuffix($absoluteUrl) : null;
        }

        return null;
    }

    private function mirrorAsset(
        string $url,
        array $sourceHosts,
        array &$assetMap,
        array &$assetsSaved,
        array &$assetsFailed
    ): ?string {
        $canonicalUrl = $this->withoutFragment($url);
        if (array_key_exists($canonicalUrl, $assetMap)) {
            return $assetMap[$canonicalUrl] !== '' ? $assetMap[$canonicalUrl] : null;
        }

        $assetMap[$canonicalUrl] = '';

        try {
            $this->emitProgress([
                'type' => 'asset_start',
                'url' => $canonicalUrl,
                'assets_saved' => count($assetsSaved),
                'assets_failed' => count($assetsFailed),
            ]);

            $asset = $this->downloadAssetToTemp($canonicalUrl);
            $relativePath = $this->localAssetPathForUrl($canonicalUrl, $asset['content_type']);
            $assetMap[$canonicalUrl] = $relativePath;
            $target = $this->rootPath . '/' . $relativePath;

            if ($this->assetLooksLikeCss($canonicalUrl, $asset['content_type'])) {
                $contents = (string) file_get_contents($asset['path']);
                $contents = $this->rewriteCssReferences($contents, $canonicalUrl, $sourceHosts, $assetMap, $assetsSaved, $assetsFailed);
                @unlink($asset['path']);
                Filesystem::atomicWrite($target, $contents);
                $this->makePublicFile($target);
            } else {
                Filesystem::ensureDirectory(dirname($target));
                if (is_file($target)) {
                    @unlink($target);
                }
                if (!@rename($asset['path'], $target)) {
                    @unlink($asset['path']);
                    throw new RuntimeException('Unable to move mirrored asset into place.');
                }
                $this->makePublicFile($target);
            }

            $assetsSaved[$relativePath] = $canonicalUrl;
            $this->emitProgress([
                'type' => 'asset_saved',
                'url' => $canonicalUrl,
                'path' => $relativePath,
                'bytes' => (int) ($asset['bytes'] ?? 0),
                'assets_saved' => count($assetsSaved),
                'assets_failed' => count($assetsFailed),
            ]);

            return $relativePath;
        } catch (RuntimeException $error) {
            $assetMap[$canonicalUrl] = '';
            $assetsFailed[$canonicalUrl] = $canonicalUrl . ' - ' . $error->getMessage();
            $this->emitProgress([
                'type' => 'asset_failed',
                'url' => $canonicalUrl,
                'message' => $error->getMessage(),
                'assets_saved' => count($assetsSaved),
                'assets_failed' => count($assetsFailed),
            ]);
            return null;
        }
    }

    private function documentBaseUrl(DOMDocument $dom, string $pageUrl): string
    {
        foreach ($dom->getElementsByTagName('base') as $base) {
            if (!$base instanceof DOMElement || !$base->hasAttribute('href')) {
                continue;
            }

            $baseUrl = $this->absolutizeUrl($base->getAttribute('href'), $pageUrl, false);
            if ($baseUrl !== null) {
                return $baseUrl;
            }
        }

        return $pageUrl;
    }

    private function removeBaseElements(DOMDocument $dom): void
    {
        $remove = [];
        foreach ($dom->getElementsByTagName('base') as $base) {
            if ($base instanceof DOMElement) {
                $remove[] = $base;
            }
        }

        foreach ($remove as $base) {
            if ($base->parentNode instanceof DOMNode) {
                $base->parentNode->removeChild($base);
            }
        }
    }

    private function fetchUrlBody(string $url, int $timeoutSeconds, int $maxBytes, string $acceptHeader): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'max_redirects' => 5,
                'user_agent' => 'WYSiteIWYG Migration Importer',
                'header' => $acceptHeader,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to fetch resource.');
        }

        $metadata = stream_get_meta_data($handle);
        $headers = $metadata['wrapper_data'] ?? [];
        $declaredLength = $this->responseContentLength(is_array($headers) ? $headers : []);
        if ($declaredLength !== null && $declaredLength > $maxBytes) {
            fclose($handle);
            throw new RuntimeException('Resource is larger than the import limit of ' . $this->bytesLabel($maxBytes) . '.');
        }

        $body = '';
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                fclose($handle);
                throw new RuntimeException('Unable to read resource.');
            }

            $body .= $chunk;
            if (strlen($body) > $maxBytes) {
                fclose($handle);
                throw new RuntimeException('Resource is larger than the import limit of ' . $this->bytesLabel($maxBytes) . '.');
            }
        }
        fclose($handle);

        return [
            'body' => $body,
            'content_type' => $this->responseContentType(is_array($headers) ? $headers : []),
        ];
    }

    private function downloadAssetToTemp(string $url): array
    {
        $this->refreshExecutionBudget();

        $url = $this->normalizeHttpUrl($url);
        if (function_exists('curl_init')) {
            return $this->downloadAssetToTempWithCurl($url);
        }

        return $this->downloadAssetToTempWithStream($url);
    }

    private function downloadAssetToTempWithCurl(string $url): array
    {
        $tmpDir = $this->importsPath() . '/tmp';
        Filesystem::ensureDirectory($tmpDir);
        $tmpPath = tempnam($tmpDir, 'asset-');
        if (!is_string($tmpPath)) {
            throw new RuntimeException('Unable to create a temporary asset file.');
        }

        $out = @fopen($tmpPath, 'wb');
        if (!is_resource($out)) {
            @unlink($tmpPath);
            throw new RuntimeException('Unable to open a temporary asset file.');
        }

        $contentType = '';
        $maxBytes = $this->assetLooksLikeCss($url, '') ? self::MAX_REWRITABLE_ASSET_BYTES : self::MAX_STREAMED_ASSET_BYTES;
        $abortReason = null;

        $ch = curl_init($url);
        if ($ch === false) {
            fclose($out);
            @unlink($tmpPath);
            throw new RuntimeException('Unable to initialize cURL for asset download.');
        }

        curl_setopt_array($ch, [
            CURLOPT_FILE => $out,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_BUFFERSIZE => 1024 * 1024,
            CURLOPT_USERAGENT => 'WYSiteIWYG Migration Importer',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_NOPROGRESS => false,
        ]);

        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, string $header) use (&$contentType, &$maxBytes, &$abortReason, $url): int {
            $line = trim($header);
            if ($line === '') {
                return strlen($header);
            }

            if (preg_match('/^HTTP\//i', $line) === 1) {
                $contentType = '';
                $maxBytes = $this->assetLooksLikeCss($url, '') ? self::MAX_REWRITABLE_ASSET_BYTES : self::MAX_STREAMED_ASSET_BYTES;
                return strlen($header);
            }

            if (stripos($line, 'content-type:') === 0) {
                $contentType = trim(substr($line, strlen('content-type:')));
                if ($this->assetLooksLikeCss($url, $contentType)) {
                    $maxBytes = self::MAX_REWRITABLE_ASSET_BYTES;
                }
                return strlen($header);
            }

            if (stripos($line, 'content-length:') === 0) {
                $value = trim(substr($line, strlen('content-length:')));
                if (ctype_digit($value) && (int) $value > $maxBytes) {
                    $abortReason = 'Resource is larger than the import limit of ' . $this->bytesLabel($maxBytes) . '.';
                    return -1;
                }
            }

            return strlen($header);
        });

        $lastProgressBytes = 0;
        $lastProgressAt = 0.0;
        $progressCallback = function (...$args) use (&$maxBytes, &$abortReason, &$lastProgressBytes, &$lastProgressAt, $url): int {
            if (count($args) >= 5) {
                $downloadTotal = (float) $args[1];
                $downloaded = (float) $args[2];
            } elseif (count($args) >= 4) {
                $downloadTotal = (float) $args[0];
                $downloaded = (float) $args[1];
            } else {
                return 0;
            }

            if (($downloadTotal > 0 && $downloadTotal > $maxBytes) || $downloaded > $maxBytes) {
                $abortReason = 'Resource is larger than the import limit of ' . $this->bytesLabel($maxBytes) . '.';
                return 1;
            }

            $this->emitThrottledAssetProgress($url, (int) $downloaded, (int) $downloadTotal, $lastProgressBytes, $lastProgressAt);

            return 0;
        };

        if (defined('CURLOPT_XFERINFOFUNCTION')) {
            curl_setopt($ch, constant('CURLOPT_XFERINFOFUNCTION'), $progressCallback);
        } elseif (defined('CURLOPT_PROGRESSFUNCTION')) {
            curl_setopt($ch, constant('CURLOPT_PROGRESSFUNCTION'), $progressCallback);
        }

        $result = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalContentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $ch = null;
        fclose($out);

        if ($finalContentType !== '') {
            $contentType = $finalContentType;
        }

        if ($result === false || $abortReason !== null) {
            @unlink($tmpPath);
            throw new RuntimeException($abortReason ?? ('cURL error: ' . ($error !== '' ? $error : 'Unable to fetch asset.')));
        }

        if ($status >= 400) {
            @unlink($tmpPath);
            throw new RuntimeException('Asset request returned HTTP ' . $status . '.');
        }

        $bytes = filesize($tmpPath);
        $bytes = is_int($bytes) ? $bytes : 0;
        $maxBytes = $this->assetLooksLikeCss($url, $contentType)
            ? self::MAX_REWRITABLE_ASSET_BYTES
            : self::MAX_STREAMED_ASSET_BYTES;
        if ($bytes > $maxBytes) {
            @unlink($tmpPath);
            throw new RuntimeException('Resource is larger than the import limit of ' . $this->bytesLabel($maxBytes) . '.');
        }

        return [
            'path' => $tmpPath,
            'content_type' => $contentType,
            'bytes' => $bytes,
        ];
    }

    private function downloadAssetToTempWithStream(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::LARGE_ASSET_FETCH_TIMEOUT_SECONDS,
                'max_redirects' => 5,
                'user_agent' => 'WYSiteIWYG Migration Importer',
                'header' => "Accept: */*\r\n",
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if (!is_resource($handle)) {
            throw new RuntimeException('Unable to fetch asset.');
        }

        $metadata = stream_get_meta_data($handle);
        $headers = $metadata['wrapper_data'] ?? [];
        $headers = is_array($headers) ? $headers : [];
        $contentType = $this->responseContentType($headers);
        $maxBytes = $this->assetLooksLikeCss($url, $contentType)
            ? self::MAX_REWRITABLE_ASSET_BYTES
            : self::MAX_STREAMED_ASSET_BYTES;

        $declaredLength = $this->responseContentLength($headers);
        if ($declaredLength !== null && $declaredLength > $maxBytes) {
            fclose($handle);
            throw new RuntimeException('Resource is larger than the import limit of ' . $this->bytesLabel($maxBytes) . '.');
        }

        $tmpDir = $this->importsPath() . '/tmp';
        Filesystem::ensureDirectory($tmpDir);
        $tmpPath = tempnam($tmpDir, 'asset-');
        if (!is_string($tmpPath)) {
            fclose($handle);
            throw new RuntimeException('Unable to create a temporary asset file.');
        }

        $out = @fopen($tmpPath, 'wb');
        if (!is_resource($out)) {
            fclose($handle);
            @unlink($tmpPath);
            throw new RuntimeException('Unable to open a temporary asset file.');
        }

        $bytes = 0;
        $lastProgressBytes = 0;
        $lastProgressAt = 0.0;
        while (!feof($handle)) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                fclose($handle);
                fclose($out);
                @unlink($tmpPath);
                throw new RuntimeException('Unable to read asset.');
            }

            $bytes += strlen($chunk);
            if ($bytes > $maxBytes) {
                fclose($handle);
                fclose($out);
                @unlink($tmpPath);
                throw new RuntimeException('Resource is larger than the import limit of ' . $this->bytesLabel($maxBytes) . '.');
            }

            if ($chunk !== '' && fwrite($out, $chunk) === false) {
                fclose($handle);
                fclose($out);
                @unlink($tmpPath);
                throw new RuntimeException('Unable to write temporary asset file.');
            }

            $this->emitThrottledAssetProgress($url, $bytes, $declaredLength ?? 0, $lastProgressBytes, $lastProgressAt);
        }

        fclose($handle);
        fclose($out);

        return [
            'path' => $tmpPath,
            'content_type' => $contentType,
            'bytes' => $bytes,
        ];
    }

    private function responseContentType(array $headers): string
    {
        foreach ($headers as $header) {
            if (stripos((string) $header, 'content-type:') === 0) {
                return trim((string) substr((string) $header, strlen('content-type:')));
            }
        }

        return '';
    }

    private function emitThrottledAssetProgress(
        string $url,
        int $downloaded,
        int $total,
        int &$lastProgressBytes,
        float &$lastProgressAt
    ): void {
        if ($downloaded <= 0) {
            return;
        }

        $now = microtime(true);
        $complete = $total > 0 && $downloaded >= $total;
        if (!$complete && ($downloaded - $lastProgressBytes) < self::ASSET_PROGRESS_BYTES && ($now - $lastProgressAt) < self::ASSET_PROGRESS_SECONDS) {
            return;
        }

        $lastProgressBytes = $downloaded;
        $lastProgressAt = $now;
        $this->emitProgress([
            'type' => 'asset_progress',
            'url' => $url,
            'bytes' => $downloaded,
            'total_bytes' => $total,
        ]);
    }

    private function responseContentLength(array $headers): ?int
    {
        foreach ($headers as $header) {
            if (stripos((string) $header, 'content-length:') === 0) {
                $value = trim((string) substr((string) $header, strlen('content-length:')));
                return ctype_digit($value) ? (int) $value : null;
            }
        }

        return null;
    }

    private function bytesLabel(int $bytes): string
    {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }

    private function normalizeAssetUrlList(array $urls): array
    {
        $normalized = [];
        foreach ($urls as $value) {
            foreach (preg_split('/[\r\n]+/', (string) $value) ?: [] as $line) {
                $url = trim($line);
                if ($url === '') {
                    continue;
                }

                $normalized[$this->withoutFragment($this->normalizeHttpUrl($url))] = true;
            }
        }

        return array_keys($normalized);
    }

    private function rewriteImportedAssetReferences(array $urlToLocalPath): int
    {
        if ($urlToLocalPath === []) {
            return 0;
        }

        $updated = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $fullPath = str_replace('\\', '/', $file->getPathname());
            if (str_starts_with($fullPath, $this->editPath . '/')) {
                continue;
            }

            $extension = strtolower((string) pathinfo($fullPath, PATHINFO_EXTENSION));
            if (!in_array($extension, ['html', 'htm', 'css', 'js'], true)) {
                continue;
            }

            $contents = (string) file_get_contents($fullPath);
            $next = $contents;

            foreach ($urlToLocalPath as $url => $localPath) {
                $replacement = '/' . ltrim((string) $localPath, '/');
                $next = str_replace($this->referenceVariants((string) $url), $replacement, $next);
            }

            if ($next !== $contents) {
                Filesystem::atomicWrite($fullPath, $next);
                $updated++;
            }
        }

        return $updated;
    }

    private function referenceVariants(string $url): array
    {
        $variants = [
            $url,
            htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        ];

        $parts = parse_url($url);
        if (is_array($parts) && !empty($parts['scheme']) && !empty($parts['host'])) {
            $path = (string) ($parts['path'] ?? '/');
            $query = isset($parts['query']) ? '?' . (string) $parts['query'] : '';
            $protocolRelative = '//' . (string) $parts['host'] . $path . $query;
            $variants[] = $protocolRelative;
            $variants[] = htmlspecialchars($protocolRelative, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        return array_values(array_unique($variants));
    }

    private function isLikelyDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        $path = strtolower((string) ($parts['path'] ?? ''));
        $query = strtolower((string) ($parts['query'] ?? ''));
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['mp3', 'm4a', 'mp4', 'mov', 'wav', 'ogg', 'webm', 'zip', 'gz', 'tar', 'pdf', 'epub'], true)) {
            return true;
        }

        if (preg_match('~/(?:download|podcast-download)(?:/|$)~', $path) === 1) {
            return true;
        }

        return preg_match('/(?:^|&)(?:download|attachment)=|(?:^|&)ref=(?:download|new_window)(?:&|$)/', $query) === 1;
    }

    private function looksLikeAssetUrl(string $url): bool
    {
        if ($this->isLikelyDownloadUrl($url)) {
            return true;
        }

        if ($this->isWordPressContentUrl($url)) {
            return true;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, [
            'avif',
            'css',
            'doc',
            'docx',
            'eot',
            'epub',
            'gif',
            'ico',
            'jpeg',
            'jpg',
            'js',
            'm4a',
            'm4v',
            'mov',
            'mp3',
            'mp4',
            'oga',
            'ogg',
            'ogv',
            'otf',
            'pdf',
            'png',
            'svg',
            'tar',
            'ttf',
            'wav',
            'webm',
            'webp',
            'woff',
            'woff2',
            'zip',
        ], true);
    }

    private function shouldMirrorDocumentFetchFailure(string $url, string $message): bool
    {
        if ($this->isNonEssentialWordPressUrl($url)) {
            return false;
        }

        if (str_starts_with($message, 'Non-document response should be mirrored as an asset')) {
            return true;
        }

        if (!str_starts_with($message, 'Resource is larger than the import limit')) {
            return false;
        }

        return !$this->isImportableStaticDocument($url, '') || $this->looksLikeAssetUrl($url);
    }

    private function isQueuedResourceCandidate(string $url): bool
    {
        return $this->isWordPressContentUrl($url) || $this->isImportableStaticDocument($url, '') || $this->looksLikeAssetUrl($url);
    }

    private function absolutizeUrl(string $href, string $baseUrl, bool $stripFragment = true): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || preg_match('/^(mailto|tel|javascript|data|blob):/i', $href)) {
            return null;
        }

        if (str_starts_with($href, '#')) {
            return null;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        if (str_starts_with($href, '//')) {
            $url = (string) $base['scheme'] . ':' . $href;
            return $stripFragment ? $this->withoutFragment($url) : $url;
        }

        if (preg_match('/^https?:\/\//i', $href)) {
            return $stripFragment ? $this->withoutFragment($href) : $href;
        }

        $root = (string) $base['scheme'] . '://' . (string) $base['host'];
        if (!empty($base['port'])) {
            $root .= ':' . (string) $base['port'];
        }

        if (str_starts_with($href, '/')) {
            $url = $root . $href;
            return $stripFragment ? $this->withoutFragment($url) : $url;
        }

        $basePath = (string) ($base['path'] ?? '/');
        $dir = preg_replace('/\/[^\/]*$/', '/', $basePath) ?? '/';
        $url = $root . $this->normalizeUrlPath($dir . $href);
        return $stripFragment ? $this->withoutFragment($url) : $url;
    }

    private function withoutFragment(string $url): string
    {
        return preg_replace('/#.*$/', '', $url) ?? $url;
    }

    private function normalizeUrlPath(string $path): string
    {
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }

        return '/' . implode('/', $parts);
    }

    private function looksLikeHtmlPage(string $path): bool
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return $extension === '' || in_array($extension, ['html', 'htm', 'php', 'asp', 'aspx'], true);
    }

    private function isHtmlDocumentUrl(string $url, string $contentType): bool
    {
        $type = $this->normalizedContentType($contentType);
        if ($type !== '') {
            return in_array($type, ['text/html', 'application/xhtml+xml'], true);
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        return !$this->isLikelyStaticDocumentUrl($url) && $this->looksLikeHtmlPage($path);
    }

    private function isImportableStaticDocument(string $url, string $contentType): bool
    {
        if ($this->isNonEssentialWordPressUrl($url)) {
            return false;
        }

        $type = $this->normalizedContentType($contentType);
        if ($type !== '') {
            if (in_array($type, [
                'application/rss+xml',
                'application/atom+xml',
                'application/rdf+xml',
                'application/xml',
                'text/xml',
                'application/json',
                'text/json',
                'text/plain',
            ], true)) {
                return true;
            }

            if (str_ends_with($type, '+xml') || str_ends_with($type, '+json')) {
                return true;
            }
        }

        return $this->isLikelyStaticDocumentUrl($url);
    }

    private function isXmlLikeStaticDocument(string $url, string $contentType): bool
    {
        $type = $this->normalizedContentType($contentType);
        if ($type !== '') {
            return in_array($type, [
                'application/rss+xml',
                'application/atom+xml',
                'application/rdf+xml',
                'application/xml',
                'text/xml',
            ], true) || str_ends_with($type, '+xml');
        }

        $extension = $this->extensionForStaticDocumentUrl($url);
        return $extension === 'xml' || $this->isLikelyFeedUrl($url);
    }

    private function isJsonLikeStaticDocument(string $url, string $contentType): bool
    {
        $type = $this->normalizedContentType($contentType);
        if ($type !== '') {
            return in_array($type, ['application/json', 'text/json'], true) || str_ends_with($type, '+json');
        }

        return $this->extensionForStaticDocumentUrl($url) === 'json';
    }

    private function isLikelyStaticDocumentUrl(string $url): bool
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['xml', 'rss', 'rdf', 'atom', 'json', 'txt'], true)) {
            return true;
        }

        return $this->isLikelyFeedUrl($url);
    }

    private function isLikelyFeedUrl(string $url): bool
    {
        $path = strtolower(trim((string) (parse_url($url, PHP_URL_PATH) ?: '/'), '/'));
        $segments = $path === '' ? [] : explode('/', $path);
        if (in_array('feed', $segments, true)) {
            return true;
        }

        $query = strtolower((string) (parse_url($url, PHP_URL_QUERY) ?: ''));
        return preg_match('/(?:^|&)(?:feed|format)=(?:rss|rss2|atom|rdf|podcast)(?:&|$)/', $query) === 1;
    }

    private function isNonEssentialWordPressUrl(string $url): bool
    {
        $path = strtolower($this->normalizeUrlPath((string) (parse_url($url, PHP_URL_PATH) ?: '/')));
        $query = strtolower((string) (parse_url($url, PHP_URL_QUERY) ?: ''));

        if ($this->isWordPressContentPath($path)) {
            return false;
        }

        if ($path === '/wp-json' || str_starts_with($path, '/wp-json/')) {
            return true;
        }

        if ($path === '/wp-admin' || str_starts_with($path, '/wp-admin/')) {
            return true;
        }

        if (in_array($path, [
            '/xmlrpc.php',
            '/wp-login.php',
            '/wp-cron.php',
            '/wp-comments-post.php',
            '/wp-trackback.php',
            '/wp-includes/wlwmanifest.xml',
        ], true)) {
            return true;
        }

        return str_contains($query, 'rest_route=');
    }

    private function isWordPressContentUrl(string $url): bool
    {
        return $this->isWordPressContentPath(strtolower($this->normalizeUrlPath((string) (parse_url($url, PHP_URL_PATH) ?: '/'))));
    }

    private function isWordPressContentPath(string $path): bool
    {
        return $path === '/wp-content' || str_starts_with($path, '/wp-content/');
    }

    private function normalizedContentType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType)[0] ?? ''));
    }

    private function localPathForUrl(string $url): string
    {
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $path = $this->normalizeUrlPath($path);
        $path = trim($path, '/');
        $querySlug = $this->querySlugForUrl($url);

        if ($path === '') {
            return $querySlug !== '' ? 'index-' . $querySlug . '.html' : 'index.html';
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $segment) ?? $segment;
            $clean = trim($clean, '.-');
            if ($clean === '' || $clean === '..') {
                continue;
            }
            $segments[] = $clean;
        }

        if ($segments === [] || in_array($segments[0], ['edit'], true)) {
            throw new RuntimeException('Refusing to import unsafe path: ' . $path);
        }

        $last = end($segments);
        if (!is_string($last)) {
            $segments[] = 'index.html';
        } elseif (preg_match('/\.html?$/i', $last) === 1) {
            // Already a local HTML filename.
        } elseif (preg_match('/\.(php|asp|aspx)$/i', $last) === 1) {
            $segments[count($segments) - 1] = preg_replace('/\.(php|asp|aspx)$/i', '.html', $last) ?? ($last . '.html');
        } else {
            $segments[] = 'index.html';
        }

        if ($querySlug !== '') {
            $lastIndex = count($segments) - 1;
            $segments[$lastIndex] = $this->appendSlugBeforeExtension($segments[$lastIndex], $querySlug);
        }

        return implode('/', $segments);
    }

    private function localDocumentPathForUrl(string $url, string $contentType = ''): string
    {
        if ($this->isHtmlDocumentUrl($url, $contentType)) {
            return $this->localPathForUrl($url);
        }

        return $this->localStaticDocumentPathForUrl($url, $contentType);
    }

    private function localStaticDocumentPathForUrl(string $url, string $contentType): string
    {
        $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $isDirectory = str_ends_with($path, '/');
        $path = trim($this->normalizeUrlPath($path), '/');
        $querySlug = $this->querySlugForUrl($url);
        $extension = $this->extensionForContentType($contentType) ?: $this->extensionForStaticDocumentUrl($url) ?: 'txt';
        $segments = [];

        foreach ($path !== '' ? explode('/', $path) : [] as $segment) {
            $clean = $this->sanitizePathSegment($segment);
            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        if ($segments === [] || in_array($segments[0], ['edit'], true)) {
            if ($segments !== []) {
                throw new RuntimeException('Refusing to import unsafe path: ' . $path);
            }
            $segments[] = 'index.' . $extension;
        } elseif ($isDirectory) {
            $segments[] = 'index.' . $extension;
        } else {
            $lastIndex = count($segments) - 1;
            if (pathinfo($segments[$lastIndex], PATHINFO_EXTENSION) === '') {
                $segments[$lastIndex] .= '.' . $extension;
            }
        }

        if ($querySlug !== '') {
            $lastIndex = count($segments) - 1;
            $segments[$lastIndex] = $this->appendSlugBeforeExtension($segments[$lastIndex], $querySlug);
        }

        return implode('/', $segments);
    }

    private function localUrlForPageUrl(string $url): string
    {
        $path = $this->localPathForUrl($url);
        if ($path === 'index.html') {
            $localUrl = '/';
        } elseif (str_ends_with($path, '/index.html')) {
            $localUrl = '/' . substr($path, 0, -strlen('index.html'));
        } else {
            $localUrl = '/' . $path;
        }

        return $localUrl . $this->fragmentSuffix($url);
    }

    private function localUrlForDocumentUrl(string $url): string
    {
        if ($this->isHtmlDocumentUrl($url, '')) {
            return $this->localUrlForPageUrl($url);
        }

        return '/' . $this->localStaticDocumentPathForUrl($url, '') . $this->fragmentSuffix($url);
    }

    private function writeQueuedAssetRedirect(string $sourceUrl, string $assetPath, bool $overwrite): ?string
    {
        try {
            $relativePath = $this->localPathForUrl($sourceUrl);
        } catch (RuntimeException) {
            return null;
        }

        if ($relativePath === $assetPath) {
            return null;
        }

        $target = $this->rootPath . '/' . $relativePath;
        if (!$overwrite && is_file($target)) {
            return null;
        }

        $assetUrl = '/' . ltrim($assetPath, '/');
        $html = '<!doctype html><html><head><meta charset="utf-8">' .
            '<meta http-equiv="refresh" content="0; url=' . h($assetUrl) . '">' .
            '<title>Redirecting</title></head><body>' .
            '<p><a href="' . h($assetUrl) . '">Open mirrored resource</a></p>' .
            '</body></html>';
        Filesystem::atomicWrite($target, $html);

        return $relativePath;
    }

    private function localAssetPathForUrl(string $url, string $contentType = ''): string
    {
        $host = $this->sanitizePathSegment(strtolower((string) parse_url($url, PHP_URL_HOST)));
        if ($host === '') {
            $host = 'external';
        }

        $rawPath = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: '/'));
        $isDirectory = str_ends_with($rawPath, '/');
        $path = trim($this->normalizeUrlPath($rawPath), '/');
        $segments = [];

        foreach ($path !== '' ? explode('/', $path) : [] as $segment) {
            $clean = $this->sanitizePathSegment($segment);
            if ($clean !== '') {
                $segments[] = $clean;
            }
        }

        if ($segments === [] || $isDirectory) {
            $segments[] = 'index';
        }

        $lastIndex = count($segments) - 1;
        $extension = strtolower((string) pathinfo($segments[$lastIndex], PATHINFO_EXTENSION));
        if ($extension === '') {
            $extension = $this->extensionForContentType($contentType) ?: 'asset';
            $segments[$lastIndex] .= '.' . $extension;
        }

        $querySlug = $this->querySlugForUrl($url);
        if ($querySlug !== '') {
            $segments[$lastIndex] = $this->appendSlugBeforeExtension($segments[$lastIndex], $querySlug);
        }

        return 'assets/imported/' . $host . '/' . implode('/', $segments);
    }

    private function equivalentHosts(string $host): array
    {
        $host = strtolower(trim($host));
        $hosts = [$host => true];

        if (str_starts_with($host, 'www.')) {
            $hosts[substr($host, 4)] = true;
        } elseif ($host !== '') {
            $hosts['www.' . $host] = true;
        }

        return array_keys($hosts);
    }

    private function hostMatches(array $sourceHosts, string $host): bool
    {
        return in_array(strtolower($host), $sourceHosts, true);
    }

    private function fragmentSuffix(string $url): string
    {
        $fragment = parse_url($url, PHP_URL_FRAGMENT);
        return is_string($fragment) && $fragment !== '' ? '#' . rawurlencode(rawurldecode($fragment)) : '';
    }

    private function querySlugForUrl(string $url): string
    {
        $query = parse_url($url, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return '';
        }

        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', rawurldecode($query)) ?? '';
        $clean = trim($clean, '.-');
        $clean = $clean !== '' ? substr($clean, 0, 48) : 'query';

        return $clean . '-' . substr(sha1($query), 0, 8);
    }

    private function appendSlugBeforeExtension(string $filename, string $slug): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        if ($extension === '') {
            return $filename . '-' . $slug;
        }

        return substr($filename, 0, -strlen($extension) - 1) . '-' . $slug . '.' . $extension;
    }

    private function sanitizePathSegment(string $segment): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '-', $segment) ?? $segment;
        $clean = trim($clean, '.-');
        return $clean === '..' ? '' : $clean;
    }

    private function extensionForContentType(string $contentType): string
    {
        $type = strtolower(trim(explode(';', $contentType)[0] ?? ''));

        return [
            'text/css' => 'css',
            'text/javascript' => 'js',
            'application/javascript' => 'js',
            'application/x-javascript' => 'js',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            'image/x-icon' => 'ico',
            'font/woff' => 'woff',
            'font/woff2' => 'woff2',
            'application/font-woff' => 'woff',
            'application/font-woff2' => 'woff2',
            'application/pdf' => 'pdf',
            'audio/aac' => 'aac',
            'audio/mp4' => 'm4a',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/x-m4a' => 'm4a',
            'audio/x-wav' => 'wav',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'application/rss+xml' => 'xml',
            'application/atom+xml' => 'xml',
            'application/rdf+xml' => 'xml',
            'application/xml' => 'xml',
            'text/xml' => 'xml',
            'application/json' => 'json',
            'text/json' => 'json',
            'text/plain' => 'txt',
        ][$type] ?? '';
    }

    private function extensionForStaticDocumentUrl(string $url): string
    {
        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['xml', 'rss', 'rdf', 'atom', 'json', 'txt'], true)) {
            return $extension === 'rss' || $extension === 'rdf' || $extension === 'atom' ? 'xml' : $extension;
        }

        return $this->isLikelyFeedUrl($url) ? 'xml' : '';
    }

    private function assetLooksLikeCss(string $url, string $contentType): bool
    {
        $type = strtolower(trim(explode(';', $contentType)[0] ?? ''));
        return $type === 'text/css' || preg_match('/\.css(?:$|\?)/i', $url) === 1;
    }

    private function loadHtmlDocument(string $html, string $label): DOMDocument
    {
        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('The DOM extension is required for external imports.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML($this->htmlForDom($html));
        libxml_clear_errors();

        if (!$loaded) {
            throw new RuntimeException('Unable to parse ' . $label . '.');
        }

        return $dom;
    }

    private function elementFromDomPath(DOMDocument $dom, array $domPath): DOMElement
    {
        $segments = array_values($domPath);
        if ($segments === []) {
            throw new RuntimeException('The selected element path was empty.');
        }

        $current = $dom->documentElement;
        if (!$current instanceof DOMElement) {
            throw new RuntimeException('The selected element could not be resolved.');
        }

        $first = $this->normalizeDomPathSegment($segments[0]);
        if ($first['tag'] === strtolower($current->tagName) && $first['index'] === 1) {
            array_shift($segments);
        }

        foreach ($segments as $segment) {
            $part = $this->normalizeDomPathSegment($segment);
            $current = $this->nthElementChildByTag($current, $part['tag'], $part['index']);
            if (!$current instanceof DOMElement) {
                throw new RuntimeException('The selected element no longer matches the imported source.');
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

    private function nthElementChildByTag(DOMElement $parent, string $tag, int $index): ?DOMElement
    {
        $seen = 0;

        foreach ($parent->childNodes as $child) {
            if (!($child instanceof DOMElement) || strtolower($child->tagName) !== $tag) {
                continue;
            }

            $seen++;
            if ($seen === $index) {
                return $child;
            }
        }

        return null;
    }

    private function replaceOuterHtml(DOMDocument $owner, DOMElement $element, string $html): void
    {
        $parent = $element->parentNode;
        if (!$parent instanceof DOMNode) {
            throw new RuntimeException('Unable to replace selected element.');
        }

        foreach ($this->fragmentNodes($owner, $html) as $node) {
            $parent->insertBefore($node, $element);
        }
        $parent->removeChild($element);
    }

    private function replaceInnerHtml(DOMDocument $owner, DOMElement $element, string $html): void
    {
        while ($element->firstChild) {
            $element->removeChild($element->firstChild);
        }

        foreach ($this->fragmentNodes($owner, $html) as $node) {
            $element->appendChild($node);
        }
    }

    private function fragmentNodes(DOMDocument $owner, string $html): array
    {
        $fragment = $this->loadHtmlDocument('<!doctype html><html><body>' . $html . '</body></html>', 'template fragment');
        $body = $fragment->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) {
            return [];
        }

        $nodes = [];
        foreach ($body->childNodes as $child) {
            $nodes[] = $owner->importNode($child, true);
        }

        return $nodes;
    }

    private function titleFromHtml(string $html): string
    {
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $match) === 1) {
            return trim(html_entity_decode(strip_tags($match[1]), ENT_QUOTES, 'UTF-8'));
        }

        return '';
    }

    private function templateFilename(string $kind): string
    {
        return self::TEMPLATE_FILES[$kind] ?? throw new RuntimeException('Unknown template kind: ' . $kind);
    }

    private function importsPath(): string
    {
        return $this->editPath . '/storage/external-imports';
    }

    private function assertImportId(string $id): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $id) !== 1) {
            throw new RuntimeException('Invalid import id.');
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

    private function restorePlaceholderTokens(string $html): string
    {
        return preg_replace('/%7B%7B([A-Z0-9_]+)%7D%7D/i', '{{$1}}', $html) ?? $html;
    }

    private function recordSkippedUrl(array &$skipped, string $url, string $message): void
    {
        $skipped[$this->withoutFragment($url)] = $this->withoutFragment($url) . ' - ' . $message;
    }

    private function emitProgress(array $event): void
    {
        if (is_callable($this->progressCallback)) {
            ($this->progressCallback)($event);
        }
    }

    private function refreshExecutionBudget(): void
    {
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(self::IMPORT_EXECUTION_SECONDS);
        }

        @ini_set('max_execution_time', (string) self::IMPORT_EXECUTION_SECONDS);
    }
}
