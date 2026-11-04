<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * UX-5: find and replace across every page's editable blocks (not raw-HTML
 * blocks): visible text, link and image addresses, or both. Only text nodes and
 * the chosen attributes change, never tag names or markup structure, and every
 * changed page keeps its previous version in its history.
 */
final class SearchReplace
{
    private const URL_ATTRIBUTES = ['href', 'src', 'srcset', 'poster', 'action', 'data-src', 'data-srcset'];
    private const TEXT_ATTRIBUTES = ['alt', 'title', 'aria-label'];

    private BlockRepository $repository;
    private SiteGenerator $generator;

    public function __construct(BlockRepository $repository, SiteGenerator $generator)
    {
        $this->repository = $repository;
        $this->generator = $generator;
    }

    /**
     * @param array{find:string, replace:string, regex?:bool, case?:bool, text?:bool, links?:bool} $query
     * @return array<int, array{path:string, block:string, count:int, samples:string[]}>
     */
    public function run(array $query, bool $apply): array
    {
        $pattern = $this->pattern($query);
        $replace = (string) $query['replace'];
        $doText = !empty($query['text']);
        $doLinks = !empty($query['links']);
        if (!$doText && !$doLinks) {
            throw new RuntimeException('Choose text, links, or both.');
        }

        $paths = array_column($this->repository->listPages(), 'path');
        foreach ($this->repository->listDrafts() as $draft) {
            $paths[] = (string) $draft['path'];
        }

        $results = [];
        $menu = null;
        foreach (array_unique($paths) as $path) {
            $page = $this->repository->getPage($path);
            $adminOnly = $this->repository->adminOnlyBlocks($page['meta']);
            foreach ($page['blocks'] as $block) {
                if (in_array($block['name'], $adminOnly, true)) {
                    continue;
                }
                [$html, $count, $samples] = $this->replaceIn((string) $block['content'], $pattern, $replace, $doText, $doLinks);
                if ($count === 0) {
                    continue;
                }
                if ($block['type'] === 'menu') {
                    // The menu is shared: report and change it once.
                    if ($menu === null) {
                        $menu = $html;
                        $results[] = ['path' => 'Site menu', 'block' => $block['name'], 'count' => $count, 'samples' => $samples];
                    }
                    continue;
                }
                $results[] = ['path' => $path, 'block' => (string) $block['name'], 'count' => $count, 'samples' => $samples];
                if ($apply) {
                    $this->repository->updateBlock($path, (string) $block['name'], Sanitizer::html($html));
                }
            }
        }

        if ($apply && $menu !== null) {
            $this->generator->syncMenu(Sanitizer::html($menu));
        }

        return $results;
    }

    private function pattern(array $query): string
    {
        $find = (string) ($query['find'] ?? '');
        if ($find === '') {
            throw new RuntimeException('Enter something to find.');
        }
        $flags = 'u' . (empty($query['case']) ? 'i' : '');
        $pattern = !empty($query['regex']) ? '~' . str_replace('~', '\~', $find) . '~' . $flags : '~' . preg_quote($find, '~') . '~' . $flags;
        if (@preg_match($pattern, '') === false) {
            throw new RuntimeException('That regular expression is not valid.');
        }

        return $pattern;
    }

    /** @return array{0:string, 1:int, 2:string[]} */
    private function replaceIn(string $html, string $pattern, string $replace, bool $doText, bool $doLinks): array
    {
        if (preg_match($pattern, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== 1 && preg_match($pattern, $html) !== 1) {
            return [$html, 0, []];
        }

        $dom = HtmlDocument::parse('<!doctype html><html><body>' . $html . '</body></html>');
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return [$html, 0, []];
        }

        $count = 0;
        $samples = [];
        $apply = static function (string $value) use ($pattern, $replace, &$count, &$samples): string {
            $next = (string) preg_replace_callback($pattern, static function (array $m) use ($replace, &$count, &$samples, $value): string {
                $count++;
                if (count($samples) < 3) {
                    $samples[] = $m[0] . ' → ' . preg_replace('/\$(\d)/', '', $replace);
                }
                $out = $replace;
                foreach ($m as $i => $group) {
                    $out = str_replace('$' . $i, (string) $group, $out);
                }
                return $out;
            }, $value);
            return $next;
        };

        $xpath = new \DOMXPath($dom);
        if ($doText) {
            foreach (iterator_to_array($xpath->query('//body//text()') ?: []) as $text) {
                $parentTag = strtolower((string) $text->parentNode?->nodeName);
                if (in_array($parentTag, ['script', 'style'], true)) {
                    continue;
                }
                $next = $apply((string) $text->nodeValue);
                if ($next !== $text->nodeValue) {
                    $text->nodeValue = $next;
                }
            }
        }
        $attributes = array_merge($doLinks ? self::URL_ATTRIBUTES : [], $doText ? self::TEXT_ATTRIBUTES : []);
        foreach (iterator_to_array($xpath->query('//body//*') ?: []) as $element) {
            foreach ($attributes as $name) {
                if ($element instanceof \DOMElement && $element->hasAttribute($name)) {
                    $next = $apply($element->getAttribute($name));
                    if ($next !== $element->getAttribute($name)) {
                        $element->setAttribute($name, $next);
                    }
                }
            }
        }

        return [$count > 0 ? trim(HtmlDocument::innerHtml($body)) : $html, $count, $samples];
    }
}
