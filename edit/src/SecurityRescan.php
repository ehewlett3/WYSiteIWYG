<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * SEC-1d: re-run the sanitizer over content saved or imported before a
 * sanitizer fix. Only blocks/files where the sanitizer removes active content
 * (scripts, handlers, unsafe URLs, parser-differential constructs) are reported
 * or rewritten; formatting-only differences are ignored, so a dry run lists real
 * problems and an apply run leaves clean content byte-for-byte alone.
 */
final class SecurityRescan
{
    private string $rootPath;
    private string $editPath;
    private BlockRepository $repository;
    private SiteSettings $settings;

    public function __construct(string $rootPath, string $editPath, BlockRepository $repository, SiteSettings $settings)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->editPath = rtrim($editPath, '/');
        $this->repository = $repository;
        $this->settings = $settings;
    }

    /**
     * @param string[] $importedFiles root-relative unmanaged files created by the crawler
     * @return array{blocks: array<int, array{path:string, block:string, removed:string[]}>, files: array<int, array{path:string, removed:string[]}>, skipped: string[], templates: array<int, array{path:string, removed:string[]}>, footer: string[]}
     */
    public function run(array $importedFiles, bool $apply): array
    {
        $report = ['blocks' => [], 'files' => [], 'skipped' => [], 'templates' => [], 'footer' => []];

        $paths = array_column($this->repository->listPages(), 'path');
        foreach ($this->repository->listDrafts() as $draft) {
            $paths[] = (string) $draft['path'];
        }

        foreach (array_unique($paths) as $path) {
            try {
                $page = $this->repository->getPage($path);
            } catch (RuntimeException) {
                continue;
            }
            $adminOnly = $this->repository->adminOnlyBlocks($page['meta']);
            foreach ($page['blocks'] as $block) {
                if (in_array($block['name'], $adminOnly, true)) {
                    continue; // raw-HTML blocks are deliberately unsanitized
                }
                $clean = Sanitizer::html((string) $block['content']);
                if (!Sanitizer::lastRemovedActiveContent()) {
                    continue;
                }
                $report['blocks'][] = ['path' => $path, 'block' => (string) $block['name'], 'removed' => array_values(array_unique(Sanitizer::lastFindings()))];
                if ($apply) {
                    $this->repository->updateBlock($path, (string) $block['name'], $clean);
                }
            }
        }

        $keepScripts = (bool) $this->settings->get('keep_imported_scripts');
        foreach ($importedFiles as $relative) {
            if (preg_match('/\.html?$/i', $relative) !== 1 || !is_file($this->rootPath . '/' . $relative)) {
                continue;
            }
            $html = (string) file_get_contents($this->rootPath . '/' . $relative);
            if (str_contains($html, 'WYSITE:BEGIN')) {
                continue; // became a managed page; covered above
            }
            if ($keepScripts) {
                $report['skipped'][] = $relative;
                continue;
            }
            try {
                $clean = Sanitizer::document($html);
            } catch (RuntimeException) {
                $clean = '';
            }
            if (!Sanitizer::lastRemovedActiveContent() && $clean !== '') {
                continue;
            }
            $report['files'][] = ['path' => $relative, 'removed' => array_values(array_unique(Sanitizer::lastFindings())) ?: ['unparseable markup']];
            if ($apply && $clean !== '') {
                Filesystem::writeSitePage($this->rootPath . '/' . $relative, $clean);
            }
        }

        foreach (glob($this->editPath . '/templates/*.html') ?: [] as $template) {
            $html = (string) file_get_contents($template);
            $clean = Sanitizer::document($html);
            if (!Sanitizer::lastRemovedActiveContent()) {
                continue;
            }
            $report['templates'][] = ['path' => 'edit/templates/' . basename($template), 'removed' => array_values(array_unique(Sanitizer::lastFindings()))];
            if ($apply) {
                Filesystem::writeSitePage($template, $clean);
            }
        }

        $footer = (string) $this->settings->get('footer_html');
        $cleanFooter = Sanitizer::html($footer);
        if (Sanitizer::lastRemovedActiveContent()) {
            $report['footer'] = array_values(array_unique(Sanitizer::lastFindings()));
            if ($apply) {
                $this->settings->update(['footer_html' => $cleanFooter]);
            }
        }

        return $report;
    }

    public static function count(array $report): int
    {
        return count($report['blocks']) + count($report['files']) + count($report['templates']) + ($report['footer'] !== [] ? 1 : 0);
    }
}
