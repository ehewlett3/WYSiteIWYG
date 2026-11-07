<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * THM-B: find where template markers belong on every page of an imported site,
 * without AI. Pages are compared with each other: subtrees that are identical on
 * most pages are site chrome (header, menu, footer, sidebar); the deepest element
 * present on nearly every page that holds most of the text that changes from
 * page to page is the content region. Pages are grouped by layout and kind, and
 * every role gets a robust selector (RegionSelector), a confidence, and a check
 * on every page of its group.
 *
 * analyze() is pure: it reads only the HTML it is given.
 */
final class SiteStructureAnalyzer
{
    private const SKIP = ['script', 'style', 'noscript', 'template', 'link', 'meta'];
    private const MAX_SAMPLE = 40;

    /** @var callable(string $html): string|null page kind guess ('blog-post', 'page', ...) */
    private $classify;

    /** @param (callable(string $path, string $html): ?string)|null $classify */
    public function __construct(?callable $classify = null)
    {
        $this->classify = $classify;
    }

    /**
     * @param array<string, string> $pages root-relative path => HTML
     * @return array{version:int, pages_total:int, clusters: array<int, array>}
     */
    public function analyze(array $pages, array $options = []): array
    {
        ksort($pages);
        $parsed = [];
        foreach ($pages as $path => $html) {
            $dom = HtmlDocument::parse((string) $html);
            $body = $dom->getElementsByTagName('body')->item(0);
            if (!$body instanceof \DOMElement) {
                continue;
            }
            $nodes = [];
            $this->index($body, '', 0, $nodes);
            $parsed[(string) $path] = [
                'dom' => $dom,
                'body' => $body,
                'nodes' => $nodes,
                'title' => $this->pageTitle($dom),
                'kind' => $this->kindFor((string) $path, (string) $html),
            ];
        }

        $clusters = [];
        foreach ($this->cluster($parsed, (float) ($options['similarity'] ?? 0.8)) as $index => $paths) {
            $clusters[] = $this->analyzeCluster('c' . ($index + 1), $paths, $parsed);
        }

        return ['version' => 1, 'pages_total' => count($parsed), 'clusters' => $clusters];
    }

    /**
     * Record every element under $element: path signature => metrics.
     *
     * @param array<string, array> $nodes
     */
    private function index(\DOMElement $element, string $parentSig, int $depth, array &$nodes): array
    {
        $counts = [];
        $childHashes = [];
        $text = 0;
        $links = 0;
        $internal = 0;
        $direct = '';
        foreach ($element->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $direct .= ' ' . $child->textContent;
                continue;
            }
            if (!$child instanceof \DOMElement) {
                continue;
            }
            $tag = strtolower($child->localName ?? $child->tagName);
            if (in_array($tag, self::SKIP, true)) {
                continue;
            }
            $sig = $this->signature($child);
            $counts[$sig] = ($counts[$sig] ?? 0) + 1;
            $pathSig = $parentSig . '/' . $sig . '[' . $counts[$sig] . ']';
            if ($tag === 'svg') {
                $nodes[$pathSig] = ['el' => $child, 'depth' => $depth + 1, 'hash' => sha1($sig), 'text' => 0, 'links' => 0, 'internal' => 0, 'tag' => $tag];
                $childHashes[] = sha1($sig);
                continue;
            }
            $metrics = $this->index($child, $pathSig, $depth + 1, $nodes);
            $childHashes[] = $metrics['hash'];
            $text += $metrics['text'];
            $links += $metrics['links'];
            $internal += $metrics['internal'];
        }

        $direct = trim((string) preg_replace('/\s+/u', ' ', $direct));
        $text += strlen($direct);
        $tag = strtolower($element->localName ?? $element->tagName);
        if ($tag === 'a' && $element->hasAttribute('href')) {
            $links++;
            // Imported pages are localized, so links to the site are relative.
            $href = trim($element->getAttribute('href'));
            if ($href !== '' && !str_starts_with($href, '#') && preg_match('#^([a-z][a-z0-9+.-]*:|//)#i', $href) !== 1) {
                $internal++;
            }
        }

        $hash = sha1($this->signature($element) . '|' . $direct . '|' . implode(',', $childHashes));
        $metrics = ['hash' => $hash, 'text' => $text, 'links' => $links, 'internal' => $internal];
        if ($parentSig !== '') {
            $nodes[$parentSig] = $metrics + ['el' => $element, 'depth' => $depth, 'tag' => $tag];
        }

        return $metrics;
    }

    private function signature(\DOMElement $element): string
    {
        $tag = strtolower($element->localName ?? $element->tagName);
        $sig = $tag;
        $id = $element->getAttribute('id');
        if (RegionSelector::isStableId($id)) {
            $sig .= '#' . $id;
        }
        $classes = RegionSelector::stableClasses($element);
        sort($classes);
        if ($classes !== []) {
            $sig .= '.' . implode('.', array_slice($classes, 0, 4));
        }
        $role = strtolower(trim($element->getAttribute('role')));
        if ($role !== '') {
            $sig .= '[' . $role . ']';
        }

        return $sig;
    }

    /**
     * Group pages that share a layout (Jaccard similarity of their top-level
     * structure) and a kind.
     *
     * @return array<int, string[]>
     */
    private function cluster(array $parsed, float $threshold): array
    {
        $clusters = [];
        foreach ($parsed as $path => $page) {
            $fingerprint = [];
            foreach ($page['nodes'] as $pathSig => $node) {
                // Layout = the top three levels; deeper differences are content.
                if ($node['depth'] <= 3) {
                    $fingerprint[$pathSig] = true;
                }
            }
            $placed = false;
            foreach ($clusters as &$cluster) {
                if ($cluster['kind'] !== $page['kind']) {
                    continue;
                }
                $intersection = count(array_intersect_key($fingerprint, $cluster['fingerprint']));
                $union = count($fingerprint + $cluster['fingerprint']);
                if ($union > 0 && $intersection / $union >= $threshold) {
                    $cluster['paths'][] = $path;
                    $placed = true;
                    break;
                }
            }
            unset($cluster);
            if (!$placed) {
                $clusters[] = ['kind' => $page['kind'], 'fingerprint' => $fingerprint, 'paths' => [$path]];
            }
        }

        usort($clusters, static fn(array $a, array $b): int => count($b['paths']) <=> count($a['paths']));
        return array_map(static fn(array $cluster): array => $cluster['paths'], $clusters);
    }

    private function analyzeCluster(string $id, array $paths, array $parsed): array
    {
        $sample = array_slice($paths, 0, self::MAX_SAMPLE);
        $n = count($sample);
        $kind = $parsed[$sample[0]]['kind'];

        // presence and stability of every structural position
        $stats = [];
        foreach ($sample as $path) {
            foreach ($parsed[$path]['nodes'] as $pathSig => $node) {
                $stats[$pathSig]['count'] = ($stats[$pathSig]['count'] ?? 0) + 1;
                $stats[$pathSig]['hashes'][$node['hash']] = ($stats[$pathSig]['hashes'][$node['hash']] ?? 0) + 1;
            }
        }
        foreach ($stats as $pathSig => &$stat) {
            $stat['presence'] = $stat['count'] / $n;
            $stat['stability'] = max($stat['hashes']) / $stat['count'];
        }
        unset($stat);

        $isChrome = static fn(string $pathSig): bool => $n > 1 && ($stats[$pathSig]['presence'] ?? 0) >= 0.8 && ($stats[$pathSig]['stability'] ?? 0) >= 0.8;

        $representative = $this->representative($sample, $parsed, $stats);
        $repPage = $parsed[$representative];
        $otherDocs = array_map(static fn(string $path): \DOMDocument => $parsed[$path]['dom'], array_slice(array_values(array_diff($sample, [$representative])), 0, 10));

        $roles = [];
        $menu = $n > 1 ? $this->findMenu($repPage, $isChrome) : $this->findMenuSingle($repPage);
        if ($menu !== null) {
            $roles['main-menu'] = $this->role($menu['el'], $menu['confidence'], $otherDocs, $paths, $parsed);
        }

        $content = $n > 1 ? $this->findContent($sample, $parsed, $stats, $isChrome, $representative) : $this->findContentSingle($repPage);
        if ($content !== null) {
            $contentRole = match ($kind) {
                'blog-post' => 'blog-post-content',
                'blog' => 'blog-index-content',
                default => 'page-content',
            };
            if ($kind === 'blog') {
                $items = $this->findArchiveItems($content['el']);
                if ($items !== null) {
                    $roles['blog-items'] = $this->role($items, $content['confidence'], $otherDocs, $paths, $parsed);
                    $intro = $this->findArchiveIntro($content['el'], $items);
                    if ($intro !== null) {
                        $roles['blog-index-content'] = $this->role($intro, $content['confidence'] * 0.8, $otherDocs, $paths, $parsed);
                    }
                }
            } else {
                $roles[$contentRole] = $this->role($content['el'], $content['confidence'], $otherDocs, $paths, $parsed);
            }

            $title = $this->findTitle($repPage, $content['el']);
            if ($title !== null) {
                $roles['title'] = ['selector' => $title['selector'], 'renders_in_content' => $title['inside'], 'confidence' => 1.0];
            }
        }

        $attention = [];
        foreach ($roles as $role => $info) {
            if (!isset($info['per_page'])) {
                continue;
            }
            foreach ($info['per_page'] as $path => $matches) {
                if ($matches !== 1) {
                    $attention[] = ['page' => $path, 'role' => $role, 'matches' => $matches];
                }
            }
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'pages' => $paths,
            'representative' => $representative,
            'roles' => array_map(static function (array $info): array {
                unset($info['per_page']);
                return $info;
            }, $roles),
            'attention' => $attention,
        ];
    }

    /** The page sharing the most stable structure with the rest of its group. */
    private function representative(array $sample, array $parsed, array $stats): string
    {
        $best = $sample[0];
        $bestScore = -1.0;
        foreach ($sample as $path) {
            $score = 0.0;
            foreach ($parsed[$path]['nodes'] as $pathSig => $node) {
                if ($node['depth'] <= 4) {
                    $score += $stats[$pathSig]['presence'] ?? 0;
                }
            }
            if ($score > $bestScore) {
                $best = $path;
                $bestScore = $score;
            }
        }

        return $best;
    }

    private function role(\DOMElement $element, float $confidence, array $otherDocs, array $paths, array $parsed): array
    {
        $selector = RegionSelector::generate($element, $otherDocs);
        $perPage = [];
        $ones = 0;
        foreach ($paths as $path) {
            $count = count(RegionSelector::match($selector, $parsed[$path]['dom']));
            $perPage[$path] = $count;
            $ones += $count === 1 ? 1 : 0;
        }
        $matchRate = $paths === [] ? 0.0 : $ones / count($paths);

        return [
            'selector' => $selector,
            'confidence' => round(min($confidence, $matchRate), 2),
            'matches' => ['1' => $ones, '0' => count(array_filter($perPage, static fn(int $c): bool => $c === 0)), 'many' => count(array_filter($perPage, static fn(int $c): bool => $c > 1))],
            'per_page' => $perPage,
        ];
    }

    /** @return array{el:\DOMElement, confidence:float}|null */
    private function findMenu(array $page, callable $isChrome): ?array
    {
        $best = null;
        foreach ($page['nodes'] as $pathSig => $node) {
            if (!in_array($node['tag'], ['ul', 'ol'], true) || !$isChrome($pathSig) || $node['internal'] < 2) {
                continue;
            }
            $inNav = $this->hasAncestor($node['el'], ['nav', 'header']) || $this->hasRoleAncestor($node['el'], 'navigation');
            $score = $node['internal'] * ($inNav ? 2 : 1) - $node['depth'] * 0.1;
            if ($best === null || $score > $best['score']) {
                $best = ['el' => $node['el'], 'score' => $score, 'inNav' => $inNav];
            }
        }

        return $best === null ? null : ['el' => $best['el'], 'confidence' => $best['inNav'] ? 0.95 : 0.75];
    }

    /** @return array{el:\DOMElement, confidence:float}|null */
    private function findMenuSingle(array $page): ?array
    {
        $xpath = new \DOMXPath($page['dom']);
        foreach (['//header//nav//ul', '//nav//ul', '//*[@role="navigation"]//ul', '//header//ul'] as $query) {
            foreach ($xpath->query($query) ?: [] as $ul) {
                if ($ul instanceof \DOMElement && ($xpath->query('.//a[@href]', $ul)?->length ?? 0) >= 2) {
                    return ['el' => $ul, 'confidence' => 0.55];
                }
            }
        }

        return null;
    }

    /** @return array{el:\DOMElement, confidence:float}|null */
    private function findContent(array $sample, array $parsed, array $stats, callable $isChrome, string $representative): ?array
    {
        // Text per page that is not inside chrome.
        $varying = [];
        foreach ($sample as $path) {
            $varying[$path] = $this->varyingText($parsed[$path]['nodes'], $isChrome);
        }

        $candidates = [];
        foreach ($stats as $pathSig => $stat) {
            if ($stat['presence'] < 0.75 || $isChrome($pathSig)) {
                continue;
            }
            // A content region is a container, never a lone paragraph or heading.
            $tag = (string) preg_replace('/[#.\[].*$/', '', (string) substr($pathSig, (int) strrpos($pathSig, '/') + 1));
            if (!in_array($tag, ['div', 'main', 'article', 'section', 'td'], true)) {
                continue;
            }
            // Judged on the pages that have this element, so one odd page
            // doesn't push the choice up to a wrapper.
            $good = 0;
            $present = 0;
            foreach ($sample as $path) {
                if (!isset($parsed[$path]['nodes'][$pathSig])) {
                    continue;
                }
                $present++;
                $total = $varying[$path][''] ?? 0;
                $here = $varying[$path][$pathSig] ?? 0;
                if ($total > 0 && $here / $total >= 0.7) {
                    $good++;
                }
            }
            $share = $present > 0 ? $good / $present : 0.0;
            if ($share >= 0.8) {
                $candidates[$pathSig] = $share;
            }
        }
        if ($candidates === []) {
            return null;
        }

        $nodes = $parsed[$representative]['nodes'];
        $best = null;
        foreach ($candidates as $pathSig => $share) {
            if (!isset($nodes[$pathSig])) {
                continue;
            }
            $depth = $nodes[$pathSig]['depth'];
            if ($best === null || $depth > $best['depth']) {
                $best = ['el' => $nodes[$pathSig]['el'], 'depth' => $depth, 'share' => $share, 'pathSig' => $pathSig];
            }
        }
        if ($best === null) {
            return null;
        }

        return ['el' => $best['el'], 'confidence' => round(min($best['share'], $stats[$best['pathSig']]['presence']), 2)];
    }

    /** @return array<string, int> pathSig => varying text length ('' = whole page) */
    private function varyingText(array $nodes, callable $isChrome): array
    {
        // Direct text per node, then roll up, skipping chrome subtrees.
        $direct = [];
        foreach ($nodes as $pathSig => $node) {
            $own = 0;
            foreach ($node['el']->childNodes as $child) {
                if ($child instanceof \DOMText) {
                    $own += strlen(trim((string) preg_replace('/\s+/u', ' ', $child->textContent)));
                }
            }
            $direct[$pathSig] = $own;
        }

        $totals = ['' => 0];
        foreach ($direct as $pathSig => $own) {
            $chrome = false;
            $prefixes = [];
            $cursor = $pathSig;
            while ($cursor !== '') {
                if ($isChrome($cursor)) {
                    $chrome = true;
                    break;
                }
                $prefixes[] = $cursor;
                $cursor = (string) substr($cursor, 0, (int) strrpos($cursor, '/'));
            }
            if ($chrome || $own === 0) {
                continue;
            }
            $totals[''] += $own;
            foreach ($prefixes as $prefix) {
                $totals[$prefix] = ($totals[$prefix] ?? 0) + $own;
            }
        }

        return $totals;
    }

    /** One page: landmarks first, then the densest text container. */
    private function findContentSingle(array $page): ?array
    {
        $xpath = new \DOMXPath($page['dom']);
        foreach (['//main//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]', '//article//*[contains(concat(" ", normalize-space(@class), " "), " entry-content ")]', '//main', '//*[@role="main"]', '//article', '//*[@id="content"]'] as $query) {
            $node = $xpath->query($query)?->item(0);
            if ($node instanceof \DOMElement) {
                return ['el' => $node, 'confidence' => 0.6];
            }
        }

        $best = null;
        foreach ($page['nodes'] as $node) {
            if (in_array($node['tag'], ['nav', 'header', 'footer', 'aside'], true) || $node['text'] === 0) {
                continue;
            }
            $density = $node['text'] - 20 * $node['links'];
            if ($best === null || $density > $best['density'] || ($density === $best['density'] && $node['depth'] > $best['depth'])) {
                $best = ['el' => $node['el'], 'density' => $density, 'depth' => $node['depth']];
            }
        }

        return $best === null ? null : ['el' => $best['el'], 'confidence' => 0.4];
    }

    private function findArchiveItems(\DOMElement $content): ?\DOMElement
    {
        $best = null;
        $bestCount = 0;
        $xpath = new \DOMXPath($content->ownerDocument);
        foreach ($xpath->query('.//*', $content) ?: [] as $parent) {
            if (!$parent instanceof \DOMElement) {
                continue;
            }
            $bySig = [];
            foreach ($parent->childNodes as $child) {
                if ($child instanceof \DOMElement && ($xpath->query('.//a[@href]', $child)?->length ?? 0) > 0) {
                    $sig = $this->signature($child);
                    $bySig[$sig] = ($bySig[$sig] ?? 0) + 1;
                }
            }
            $count = $bySig === [] ? 0 : max($bySig);
            if ($count >= 3 && $count > $bestCount) {
                $best = $parent;
                $bestCount = $count;
            }
        }

        return $best;
    }

    private function findArchiveIntro(\DOMElement $content, \DOMElement $items): ?\DOMElement
    {
        $xpath = new \DOMXPath($content->ownerDocument);
        foreach ($xpath->query('.//header | .//*[contains(concat(" ", normalize-space(@class), " "), " page-header ")] | .//*[contains(concat(" ", normalize-space(@class), " "), " archive-header ")]', $content) ?: [] as $node) {
            if ($node instanceof \DOMElement && !HtmlDocument::contains($node, $items) && !HtmlDocument::contains($items, $node)) {
                return $node;
            }
        }

        return null;
    }

    /** @return array{selector:string, inside:bool}|null */
    private function findTitle(array $page, \DOMElement $content): ?array
    {
        $title = $this->normalize($page['title']);
        if ($title === '') {
            return null;
        }
        foreach ($page['dom']->getElementsByTagName('h1') as $h1) {
            $text = $this->normalize($h1->textContent);
            if ($text !== '' && ($text === $title || str_starts_with($title, $text))) {
                return ['selector' => RegionSelector::generate($h1), 'inside' => HtmlDocument::contains($content, $h1)];
            }
        }

        return null;
    }

    private function pageTitle(\DOMDocument $dom): string
    {
        $title = $dom->getElementsByTagName('title')->item(0);
        $text = $title instanceof \DOMElement ? $title->textContent : '';
        return trim((string) preg_replace('/\s*[|\-–—·»:]\s*[^|\-–—·»:]+$/u', '', $text));
    }

    private function normalize(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }

    private function kindFor(string $path, string $html): string
    {
        if ($this->classify !== null) {
            $kind = ($this->classify)($path, $html);
            if (is_string($kind) && in_array($kind, ['home', 'page', 'blog', 'blog-post'], true)) {
                return $kind;
            }
        }

        $lower = strtolower($path);
        if ($lower === 'index.html') {
            return 'home';
        }
        if (preg_match('#(^|/)(category|tag|author|page/\d+)(/|$)#', $lower) === 1 || preg_match('#^blog/index\.html$#', $lower) === 1) {
            return 'blog';
        }

        return 'page';
    }

    private function hasAncestor(\DOMElement $element, array $tags): bool
    {
        for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->localName ?? $node->tagName), $tags, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasRoleAncestor(\DOMElement $element, string $role): bool
    {
        for ($node = $element->parentNode; $node instanceof \DOMElement; $node = $node->parentNode) {
            if (strtolower($node->getAttribute('role')) === $role) {
                return true;
            }
        }

        return false;
    }
}
