<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * THM-D: themes as portable zip files (export from one site, install on
 * another), and UX-12: the whole public site as a zip for hosting elsewhere.
 * Imported theme zips are untrusted: only known theme files and allowlisted
 * asset types are extracted, paths can't escape the theme folder, templates
 * and components are sanitized, and the theme must validate or nothing is kept.
 */
final class ThemePackage
{
    private const MAX_BYTES = 52_428_800;
    private const TEMPLATE_FILES = ['page.html', 'home.html', 'blog-post.html', 'blog-index.html', 'preview.html'];

    private string $rootPath;
    private ThemeManager $themes;

    public function __construct(string $rootPath, ThemeManager $themes)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->themes = $themes;
    }

    /** Zip a theme folder; returns the temporary zip path (caller deletes it). */
    public function export(string $themeId): string
    {
        $this->requireZip();
        $dir = $this->themes->themeDirectory($themeId);
        $this->themes->getTheme($themeId);
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'wysite-theme-');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the theme archive.');
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink() && !str_starts_with($file->getFilename(), '.')) {
                $zip->addFile($file->getPathname(), $themeId . '/' . ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($dir)), '/'));
            }
        }
        $zip->close();

        return $zipPath;
    }

    /** Install a theme from an uploaded zip; returns the new theme id. */
    public function import(string $zipFile): string
    {
        $this->requireZip();
        $zip = new \ZipArchive();
        if ($zip->open($zipFile) !== true) {
            throw new RuntimeException('That file is not a zip archive.');
        }

        try {
            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_BYTES) {
                    throw new RuntimeException('The theme is larger than 50 MB.');
                }
                $entries[$name] = $i;
            }

            // Accept a single top-level folder ("my-theme/page.html") or files at the root.
            $prefix = '';
            $tops = array_unique(array_map(static fn(string $n): string => explode('/', $n)[0], array_keys($entries)));
            if (count($tops) === 1 && !isset($entries[$tops[0]])) {
                $prefix = $tops[0] . '/';
            }

            $files = [];
            foreach ($entries as $name => $index) {
                $relative = substr($name, strlen($prefix));
                if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/') || preg_match('#(^|/)\.#', $relative) === 1) {
                    throw new RuntimeException('The theme contains an unsafe path: ' . $name);
                }
                $allowed = in_array($relative, array_merge(self::TEMPLATE_FILES, ['theme.json', 'site.css']), true)
                    || preg_match('#^components/[a-z0-9_-]+\.html$#i', $relative) === 1
                    || (str_starts_with($relative, 'assets/') && AssetPolicy::isAllowedExtension((string) pathinfo($relative, PATHINFO_EXTENSION)));
                if (!$allowed) {
                    continue; // unknown files are ignored, never written
                }
                $files[$relative] = (string) $zip->getFromIndex($index);
            }
        } finally {
            $zip->close();
        }

        $meta = json_decode((string) ($files['theme.json'] ?? ''), true);
        if (!is_array($meta) || trim((string) ($meta['name'] ?? '')) === '') {
            throw new RuntimeException('The theme has no theme.json with a name.');
        }
        if (!isset($files['page.html'], $files['site.css'])) {
            throw new RuntimeException('A theme needs page.html and site.css.');
        }

        $id = $this->themes->assertNewThemeName((string) $meta['name']);
        $dir = $this->themes->themeDirectory($id);
        try {
            foreach ($files as $relative => $contents) {
                if (in_array($relative, self::TEMPLATE_FILES, true)) {
                    $contents = Sanitizer::document($contents);
                } elseif (str_starts_with($relative, 'components/')) {
                    $contents = (string) preg_replace('/^(<!--\s*WYSITE:COMPONENT[^>]*-->)?(.*)$/s', '$1', $contents) . Sanitizer::html((string) preg_replace('/^<!--\s*WYSITE:COMPONENT[^>]*-->/', '', $contents));
                } elseif ($relative === 'site.css') {
                    $contents = Sanitizer::css($contents, ['assets/', './assets/', 'data:image/svg+xml']);
                } elseif ($relative === 'theme.json') {
                    $contents = (string) json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                } elseif (strtolower((string) pathinfo($relative, PATHINFO_EXTENSION)) === 'svg') {
                    $contents = AssetPolicy::sanitizeSvg($contents) ?? '';
                }
                // Template, CSS and manifest names come from a fixed list; only
                // assets get the publish-safe file name treatment.
                $target = str_starts_with($relative, 'assets/') ? AssetPolicy::safeRelativePath($relative) : $relative;
                Filesystem::atomicWrite($dir . '/' . $target, $contents);
            }
            $this->themes->updateMeta($id, []);
            $this->themes->assertThemeIsValid($id);
        } catch (\Throwable $error) {
            $this->themes->deleteTheme($id);
            throw new RuntimeException('The theme could not be installed: ' . $error->getMessage());
        }

        return $id;
    }

    /** UX-12: the public site (everything but edit/ and hidden files) as a zip. */
    public function exportSite(): string
    {
        $this->requireZip();
        $zipPath = (string) tempnam(sys_get_temp_dir(), 'wysite-site-');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the site archive.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $file): bool {
                $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath)), '/');
                $name = $file->getFilename();
                // Match the server's private paths, and never follow a symlink
                // that could package files outside the public site.
                return !$file->isLink()
                    && !str_starts_with($name, '.')
                    && !in_array(strtolower($relative), ['edit', 'doc'], true)
                    && preg_match('/^(?:HANDOFF(?:_[A-Z0-9_-]+)?|OPUS_PROMPT)\.md$/i', $name) !== 1
                    && preg_match('/\.local\.|\.(?:pem|key|p12|pfx|jks|keystore)$/i', $name) !== 1;
            }
        ));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath)), '/'));
            }
        }
        if ($zip->numFiles === 0) {
            $zip->addFromString('README.txt', "This site has no published pages yet.\n");
        }
        $zip->close();

        return $zipPath;
    }

    private function requireZip(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is needed for this.');
        }
    }
}
