<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * The single resolver for public page paths. Every page read or write that takes
 * a caller-supplied relative path goes through resolvePage(), which only accepts
 * .html/.htm files inside the site root and outside the /edit/ app, with no
 * dot-segments, dotfiles, or symlink escapes.
 */
final class SitePath
{
    /** Prefix for draft pages, stored privately under edit/storage/drafts/. */
    public const DRAFT_PREFIX = 'draft:';

    private string $rootPath;
    private string $editPath;

    public function __construct(string $rootPath, string $editPath)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $this->editPath = rtrim(str_replace('\\', '/', $editPath), '/');
    }

    public function draftsPath(): string
    {
        return $this->editPath . '/storage/drafts';
    }

    public static function isDraft(string $relativePath): bool
    {
        return str_starts_with(ltrim($relativePath), self::DRAFT_PREFIX);
    }

    /** "draft:about/index.html" -> "about/index.html" (the path it publishes to). */
    public static function publicPath(string $relativePath): string
    {
        return self::isDraft($relativePath) ? substr(ltrim($relativePath), strlen(self::DRAFT_PREFIX)) : $relativePath;
    }

    /**
     * Validate a root-relative page path and return its clean form (no leading
     * slash). An empty path means the homepage.
     */
    public function normalize(string $relativePath): string
    {
        if (str_contains($relativePath, "\0")) {
            throw new RuntimeException('Unsafe path requested.');
        }

        if (self::isDraft($relativePath)) {
            return self::DRAFT_PREFIX . $this->normalize(self::publicPath($relativePath));
        }

        $clean = trim(str_replace('\\', '/', trim($relativePath)), '/');
        if ($clean === '') {
            return 'index.html';
        }

        $segments = explode('/', $clean);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new RuntimeException('Unsafe path requested.');
            }
        }

        if (strtolower($segments[0]) === 'edit') {
            throw new RuntimeException('Unsafe path requested.');
        }

        $extension = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
        if (!in_array($extension, ['html', 'htm'], true)) {
            throw new RuntimeException('Only .html pages can be opened here.');
        }

        return $clean;
    }

    /**
     * Resolve a page path to an absolute filesystem path. With $mustExist the file
     * has to exist; otherwise the nearest existing ancestor directory is checked,
     * which is what page creation needs.
     */
    public function resolvePage(string $relativePath, bool $mustExist = true): string
    {
        $clean = $this->normalize($relativePath);
        if (self::isDraft($clean)) {
            return $this->resolveDraft(self::publicPath($clean), $mustExist);
        }
        $fullPath = $this->rootPath . '/' . $clean;

        if (is_file($fullPath)) {
            $this->assertContained((string) realpath($fullPath));
            return $fullPath;
        }

        if ($mustExist) {
            throw new RuntimeException('Page not found: ' . $clean);
        }

        if (file_exists($fullPath) || is_link($fullPath)) {
            throw new RuntimeException('Unsafe path requested.');
        }

        $ancestor = dirname($fullPath);
        while (!is_dir($ancestor) && $ancestor !== dirname($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $this->assertContained((string) realpath($ancestor), true);

        return $fullPath;
    }

    private function resolveDraft(string $clean, bool $mustExist): string
    {
        $base = $this->draftsPath();
        $fullPath = $base . '/' . $clean;
        if (!is_file($fullPath)) {
            if ($mustExist) {
                throw new RuntimeException('Draft not found: ' . $clean);
            }
            return $fullPath;
        }

        $real = str_replace('\\', '/', (string) realpath($fullPath));
        $realBase = str_replace('\\', '/', (string) realpath($base));
        if ($realBase === '' || !str_starts_with($real, $realBase . '/')) {
            throw new RuntimeException('Unsafe path requested.');
        }

        return $fullPath;
    }

    /** Relative page path ("draft:"-prefixed for drafts) for an absolute file path. */
    public function relativeFor(string $fullPath): string
    {
        $fullPath = str_replace('\\', '/', $fullPath);
        $drafts = $this->draftsPath() . '/';
        if (str_starts_with($fullPath, $drafts)) {
            return self::DRAFT_PREFIX . substr($fullPath, strlen($drafts));
        }

        return ltrim(substr($fullPath, strlen($this->rootPath)), '/');
    }

    /** True when $fullPath (absolute) is a safe public page location. */
    public function isPublicPagePath(string $fullPath): bool
    {
        $fullPath = str_replace('\\', '/', $fullPath);
        if (!str_starts_with($fullPath, $this->rootPath . '/')) {
            return false;
        }

        try {
            $this->resolvePage(substr($fullPath, strlen($this->rootPath) + 1), false);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    private function assertContained(string $real, bool $isDirectory = false): void
    {
        $root = realpath($this->rootPath);
        $edit = realpath($this->editPath);
        if ($real === '' || $root === false) {
            throw new RuntimeException('Unsafe path requested.');
        }

        $real = str_replace('\\', '/', $real);
        $root = str_replace('\\', '/', $root);
        $inRoot = str_starts_with($real, $root . '/') || ($isDirectory && $real === $root);
        if (!$inRoot) {
            throw new RuntimeException('Unsafe path requested.');
        }

        if ($edit !== false) {
            $edit = str_replace('\\', '/', $edit);
            if ($real === $edit || str_starts_with($real, $edit . '/')) {
                throw new RuntimeException('Unsafe path requested.');
            }
        }
    }
}
