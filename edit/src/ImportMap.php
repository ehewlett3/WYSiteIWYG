<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * THM-C: the reviewed result of analysing imported pages (SiteStructureAnalyzer)
 * plus the actions that use it: correcting a region selector, creating a theme
 * from the site's layouts, and converting every page into a managed page using
 * the reviewed content region. Stored in edit/storage/import-map.local.json.
 */
final class ImportMap
{
    private string $rootPath;
    private string $file;
    private BlockRepository $repository;
    private SiteGenerator $generator;

    public function __construct(string $rootPath, string $editPath, BlockRepository $repository, SiteGenerator $generator)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->file = rtrim($editPath, '/') . '/storage/import-map.local.json';
        $this->repository = $repository;
        $this->generator = $generator;
    }

    /** Analyse the site's unmanaged HTML pages (redirect stubs excluded). */
    public function analyze(int $limit = 400): array
    {
        $pages = [];
        foreach ($this->repository->listImportCandidates() as $candidate) {
            $html = (string) @file_get_contents($this->rootPath . '/' . $candidate['path']);
            if ($html === '' || preg_match('/<meta[^>]+http-equiv=["\']?refresh/i', $html) === 1) {
                continue;
            }
            $pages[(string) $candidate['path']] = $html;
            if (count($pages) >= $limit) {
                break;
            }
        }
        if ($pages === []) {
            throw new RuntimeException('There are no imported pages to analyse. Import a site first.');
        }

        $analyzer = new SiteStructureAnalyzer(function (string $path, string $html): ?string {
            if (strtolower($path) === 'index.html') {
                return 'home';
            }
            if (preg_match('#(^|/)(category|tag|author|page/\d+)(/|$)#', strtolower($path)) === 1) {
                return 'blog';
            }
            return $this->generator->detectPost($html) !== null ? 'blog-post' : 'page';
        });
        $map = $analyzer->analyze($pages);
        $map['created_at'] = gmdate('c');
        $this->save($map);

        return $map;
    }

    public function load(): ?array
    {
        $map = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
        return is_array($map) && isset($map['clusters']) ? $map : null;
    }

    public function save(array $map): void
    {
        Filesystem::atomicWrite($this->file, (string) json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0600);
    }

    /** Replace a role's selector and re-check it on every page of its group. */
    public function updateRole(string $clusterId, string $role, string $selector, string $note = ''): array
    {
        $map = $this->load() ?? throw new RuntimeException('Analyse the imported pages first.');
        $selector = trim($selector);
        RegionSelector::assertValid($selector);
        if (preg_match('/^[a-z-]+$/', $role) !== 1) {
            throw new RuntimeException('Unknown region.');
        }

        foreach ($map['clusters'] as &$cluster) {
            if ($cluster['id'] !== $clusterId) {
                continue;
            }
            $ones = 0;
            $attention = array_values(array_filter((array) $cluster['attention'], static fn(array $item): bool => $item['role'] !== $role));
            foreach ($cluster['pages'] as $path) {
                $count = count(RegionSelector::match($selector, $this->parse((string) $path)));
                if ($count === 1) {
                    $ones++;
                } else {
                    $attention[] = ['page' => $path, 'role' => $role, 'matches' => $count];
                }
            }
            $total = max(1, count($cluster['pages']));
            $cluster['roles'][$role] = array_merge((array) ($cluster['roles'][$role] ?? []), [
                'selector' => $selector,
                'confidence' => round($ones / $total, 2),
                'matches' => ['1' => $ones, '0' => 0, 'many' => $total - $ones],
                'reviewed' => true,
            ] + ($note !== '' ? ['note' => $note] : []));
            $cluster['attention'] = $attention;
            $this->save($map);
            return $cluster;
        }
        unset($cluster);

        throw new RuntimeException('Unknown layout group.');
    }

    public function cluster(string $clusterId): array
    {
        foreach (($this->load() ?? ['clusters' => []])['clusters'] as $cluster) {
            if ($cluster['id'] === $clusterId) {
                return $cluster;
            }
        }

        throw new RuntimeException('Unknown layout group.');
    }

    /**
     * One source page per template kind (the largest group of each kind), for
     * ThemeImporter.
     *
     * @return array<string, array{html:string, base:string, local:bool, regions:array<string,string>, title?:string}>
     */
    public function themeSources(): array
    {
        $map = $this->load() ?? throw new RuntimeException('Analyse the imported pages first.');
        $sources = [];
        foreach ($map['clusters'] as $cluster) {
            $kind = (string) $cluster['kind'];
            if (isset($sources[$kind])) {
                continue;
            }
            $regions = [];
            foreach ((array) $cluster['roles'] as $role => $info) {
                if ($role !== 'title' && !empty($info['selector'])) {
                    $regions[$role] = (string) $info['selector'];
                }
            }
            $required = ['blog-post' => ['main-menu', 'blog-post-content'], 'blog' => ['main-menu', 'blog-index-content', 'blog-items']][$kind] ?? ['main-menu', 'page-content'];
            if (array_diff($required, array_keys($regions)) !== []) {
                continue; // incomplete group: the theme falls back to its page template
            }
            $sources[$kind] = [
                'html' => (string) file_get_contents($this->rootPath . '/' . $cluster['representative']),
                'base' => '',
                'local' => true,
                'regions' => $regions,
                'title' => (string) ($cluster['roles']['title']['selector'] ?? ''),
            ];
        }

        return $sources;
    }

    /**
     * Convert every page of the reviewed groups into managed pages using their
     * content region. Archive groups are skipped (tag pages replace them).
     *
     * @return array{converted:int, skipped:string[], failed:string[]}
     */
    public function convertPages(bool $cleanMarkup = false): array
    {
        $map = $this->load() ?? throw new RuntimeException('Analyse the imported pages first.');
        $result = ['converted' => 0, 'skipped' => [], 'failed' => []];
        foreach ($map['clusters'] as $cluster) {
            $kind = (string) $cluster['kind'];
            $contentRole = $kind === 'blog-post' ? 'blog-post-content' : 'page-content';
            $selector = (string) ($cluster['roles'][$contentRole]['selector'] ?? '');
            foreach ($cluster['pages'] as $path) {
                if ($kind === 'blog') {
                    $result['skipped'][] = $path;
                    continue;
                }
                $override = (string) ($cluster['overrides'][$path] ?? '');
                $effective = $override !== '' ? $override : $selector;
                try {
                    $this->generator->importExistingPage((string) $path, [
                        'kind' => $kind === 'blog-post' ? 'blog-post' : 'page',
                        'container_xpath' => $effective !== '' && RegionSelector::match($effective, $this->parse((string) $path)) !== [] ? RegionSelector::toXPath($effective) : '',
                        'clean_markup' => $cleanMarkup,
                    ], false);
                    $result['converted']++;
                } catch (RuntimeException $error) {
                    $result['failed'][] = $path . ' - ' . $error->getMessage();
                }
            }
        }
        $this->generator->rebuildTagPages();

        return $result;
    }

    /** The menu of the largest group, as the site's shared menu HTML. */
    public function menuHtml(): ?string
    {
        $map = $this->load();
        $cluster = $map['clusters'][0] ?? null;
        $selector = (string) ($cluster['roles']['main-menu']['selector'] ?? '');
        if ($selector === '') {
            return null;
        }
        $element = RegionSelector::match($selector, $this->parse((string) $cluster['representative']))[0] ?? null;
        if (!$element instanceof \DOMElement) {
            return null;
        }
        foreach (iterator_to_array((new \DOMXPath($element->ownerDocument))->query('.//*[@class]', $element) ?: []) as $node) {
            $classes = array_filter(preg_split('/\s+/', (string) $node->getAttribute('class')) ?: [], static fn(string $c): bool => preg_match('/^(current|active|selected)/i', $c) !== 1);
            $classes === [] ? $node->removeAttribute('class') : $node->setAttribute('class', implode(' ', $classes));
        }
        $element->removeAttribute('id');

        return Sanitizer::html(HtmlDocument::serialize($element));
    }

    private function parse(string $path): \DOMDocument
    {
        return HtmlDocument::parse((string) @file_get_contents($this->rootPath . '/' . $path));
    }
}
