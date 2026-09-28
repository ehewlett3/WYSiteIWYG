<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * Import a WordPress export file (WXR, Tools → Export → All content) into
 * managed pages and posts (WP-2). Unlike crawling rendered HTML, the export
 * carries exact dates, tags, authors, excerpts, draft status, and permalinks.
 *
 * - post_type post → blog post, page → page, attachment → mirrored asset;
 * - publish → published; draft/pending/private/future → private draft;
 * - each item keeps its permalink path (link), falling back to its slug for
 *   query-string permalinks (?p=123);
 * - classic-editor content gets wpautop-style paragraphs; Gutenberg block
 *   comments are removed and the block HTML kept.
 */
final class WxrImporter
{
    private const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    private SiteGenerator $generator;
    private BlockRepository $repository;

    public function __construct(SiteGenerator $generator, BlockRepository $repository)
    {
        $this->generator = $generator;
        $this->repository = $repository;
    }

    /**
     * @return array{created:string[], drafts:string[], skipped:string[], failed:string[], attachments:string[], source_hosts:string[]}
     */
    public function import(string $file, bool $overwrite = false): array
    {
        if (!class_exists(\XMLReader::class)) {
            throw new RuntimeException('The XMLReader extension is required to import a WordPress export.');
        }

        $reader = new \XMLReader();
        if (!@$reader->open($file, 'UTF-8', LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('That file could not be read as a WordPress export (WXR).');
        }

        $result = ['created' => [], 'drafts' => [], 'skipped' => [], 'failed' => [], 'attachments' => [], 'source_hosts' => [], 'query_redirects' => []];
        $sawItem = false;

        while (@$reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->name !== 'item') {
                continue;
            }

            $node = $reader->expand();
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $sawItem = true;
            $item = null;

            try {
                $item = $this->readItem($node);
                if ($item === null) {
                    continue;
                }

                $host = strtolower((string) parse_url($item['link'], PHP_URL_HOST));
                if ($host !== '' && !in_array($host, $result['source_hosts'], true)) {
                    $result['source_hosts'][] = $host;
                }

                if ($item['type'] === 'attachment') {
                    if ($item['attachment_url'] !== '') {
                        $result['attachments'][] = $item['attachment_url'];
                    }
                    continue;
                }

                $path = $this->localPath($item);
                $created = $this->generator->createFromImport(
                    $path,
                    $item['type'] === 'post' ? 'blog-post' : 'page',
                    [
                        'title' => $item['title'],
                        'date' => $item['date'],
                        'tags' => $item['tags'],
                        'excerpt' => $item['excerpt'],
                        'author' => $item['author'],
                        'content' => $item['content'],
                    ],
                    $item['draft'],
                    $overwrite
                );

                $query = (string) parse_url($item['link'], PHP_URL_QUERY);
                if ($query !== '' && $created !== null && !$item['draft']) {
                    $result['query_redirects'][] = ['path' => (string) (parse_url($item['link'], PHP_URL_PATH) ?: '/'), 'query' => $query, 'target' => $created];
                }

                if ($created === null) {
                    $result['skipped'][] = $path;
                } elseif ($item['draft']) {
                    $result['drafts'][] = $created;
                } else {
                    $result['created'][] = $created;
                }
            } catch (\Throwable $error) {
                $result['failed'][] = ($item['title'] ?? 'item') . ' — ' . $error->getMessage();
            }
        }
        $reader->close();

        if (!$sawItem) {
            throw new RuntimeException('No items were found. Export "All content" from WordPress (Tools → Export) and upload that .xml file.');
        }

        $this->generator->rebuildTagPages();
        return $result;
    }

    /**
     * @return array{type:string, title:string, link:string, slug:string, date:string, draft:bool, tags:string[], excerpt:string, author:string, content:string, attachment_url:string}|null
     */
    private function readItem(\DOMElement $item): ?array
    {
        $children = [];
        foreach ($item->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $children[] = $child;
            }
        }

        // WXR versions differ in the wp: namespace URI (1.0-1.2); match by local name.
        $value = static function (string $localName, ?string $namespace = null, ?string $prefix = null) use ($children): string {
            foreach ($children as $child) {
                if ($child->localName !== $localName) {
                    continue;
                }
                if ($namespace !== null && $child->namespaceURI !== $namespace) {
                    continue;
                }
                if ($prefix !== null && $child->prefix !== $prefix) {
                    continue;
                }
                return trim((string) $child->textContent);
            }
            return '';
        };

        $type = $value('post_type', null, 'wp');
        if (!in_array($type, ['post', 'page', 'attachment'], true)) {
            return null; // menus, revisions, custom post types, ...
        }

        $status = $value('status', null, 'wp');
        if (in_array($status, ['trash', 'auto-draft', 'inherit'], true) && $type !== 'attachment') {
            return null;
        }

        $tags = [];
        foreach ($children as $child) {
            if ($child->localName === 'category' && in_array($child->getAttribute('domain'), ['category', 'post_tag'], true)) {
                $name = $child->getAttribute('nicename') ?: trim((string) $child->textContent);
                $tag = strtolower(trim((string) preg_replace('/[^a-z0-9-]+/i', '-', $name), '-'));
                if ($tag !== '' && $tag !== 'uncategorized' && !in_array($tag, $tags, true)) {
                    $tags[] = $tag;
                }
            }
        }

        $dateGmt = $value('post_date_gmt', null, 'wp');
        $date = $dateGmt !== '' && !str_starts_with($dateGmt, '0000') ? $dateGmt : $value('post_date', null, 'wp');
        $timestamp = $date !== '' ? strtotime($date) : false;

        return [
            'type' => $type,
            'title' => html_entity_decode($value('title'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'link' => $value('link'),
            'slug' => $value('post_name', null, 'wp'),
            'date' => $timestamp !== false ? gmdate('Y-m-d', $timestamp) : '',
            'draft' => $status !== 'publish',
            'tags' => $tags,
            'excerpt' => trim(strip_tags($value('encoded', null, 'excerpt'))),
            'author' => $value('creator', self::DC_NS),
            'content' => self::prepareContent($value('encoded', self::CONTENT_NS)),
            'attachment_url' => $value('attachment_url', null, 'wp'),
        ];
    }

    /** Local file for an item: its permalink path, or its slug for ?p= links. */
    private function localPath(array $item): string
    {
        $path = trim(rawurldecode((string) parse_url($item['link'], PHP_URL_PATH)), '/');
        $query = (string) parse_url($item['link'], PHP_URL_QUERY);

        if ($path === '' || $query !== '') {
            $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($item['slug'] ?: $item['title'])), '-');
            if ($slug === '') {
                throw new RuntimeException('The item has no usable permalink or slug.');
            }
            $path = $slug;
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            $segment = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $segment), '.-');
            if ($segment !== '') {
                $segments[] = $segment;
            }
        }
        if ($segments === [] || in_array(strtolower($segments[0]), ['edit', 'assets'], true)) {
            throw new RuntimeException('Refusing to import to an unsafe path: /' . $path);
        }

        $last = (string) end($segments);
        if (preg_match('/\.html?$/i', $last) === 1) {
            return implode('/', $segments);
        }

        return implode('/', $segments) . '/index.html';
    }

    /**
     * Gutenberg content: drop the block comments, keep the HTML. Classic
     * content: add paragraphs and line breaks the way WordPress's wpautop does.
     */
    public static function prepareContent(string $content): string
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        if (str_contains($content, '<!-- wp:')) {
            $content = preg_replace('#<!--\s*/?wp:[^>]*-->#', '', $content) ?? $content;
            return trim((string) preg_replace("/\n{3,}/", "\n\n", $content));
        }

        return self::autop($content);
    }

    /** A compact wpautop(): blank lines → paragraphs, single newlines → <br>. */
    public static function autop(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $block = 'address|article|aside|blockquote|details|div|dl|fieldset|figcaption|figure|footer|form|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|tbody|td|tfoot|th|thead|tr|ul|iframe|video|audio|script|style';

        // Keep <pre> blocks untouched.
        $pres = [];
        $text = preg_replace_callback('#<pre\b.*?</pre>#is', static function (array $m) use (&$pres): string {
            $pres[] = $m[0];
            return "\n\n<wysite-pre-" . (count($pres) - 1) . ">\n\n";
        }, $text) ?? $text;

        // Put block-level tags on their own "paragraph" chunks.
        $text = preg_replace('#(<(?:' . $block . ')\b[^>]*>)#i', "\n\n$1", $text) ?? $text;
        $text = preg_replace('#(</(?:' . $block . ')>)#i', "$1\n\n", $text) ?? $text;

        $chunks = preg_split("/\n\s*\n/", $text) ?: [];
        $out = [];
        foreach ($chunks as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (preg_match('#^<(?:/?(?:' . $block . ')\b|wysite-pre-\d+>)#i', $chunk) === 1) {
                $out[] = $chunk;
                continue;
            }
            $out[] = '<p>' . preg_replace("/\n/", "<br>\n", $chunk) . '</p>';
        }

        $html = implode("\n", $out);
        return preg_replace_callback('#<wysite-pre-(\d+)>#', static fn(array $m): string => $pres[(int) $m[1]] ?? '', $html) ?? $html;
    }
}
