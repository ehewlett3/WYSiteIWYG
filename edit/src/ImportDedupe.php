<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * IMP-4: clean up duplicates left by imports made before canonical URLs and the
 * asset index existed. Identical files under assets/imported/ collapse onto one
 * keeper, references in imported pages, managed pages and mirrored CSS/JS are
 * re-pointed, and page copies created by tracking/reply/share parameters become
 * redirects. Nothing is deleted: removed files and the originals of rewritten
 * files go to edit/storage/trash/<id>/ so the whole run can be undone.
 */
final class ImportDedupe
{
    /** Query parameters whose page copies are duplicates of the plain page. */
    private const DUPLICATE_PAGE_PARAMS = ['replytocom', 'share', 'nb', 'utm_[a-z]+', 'fbclid', 'gclid', 'amp', 'like_comment', 'shared'];

    private string $rootPath;
    private string $editPath;
    private AssetIndex $index;
    /** @var callable(): string[] */
    private $importedFiles;

    /** @param callable(): string[] $importedFiles root-relative files the crawler created */
    public function __construct(string $rootPath, string $editPath, AssetIndex $index, callable $importedFiles)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->editPath = rtrim($editPath, '/');
        $this->index = $index;
        $this->importedFiles = $importedFiles;
    }

    /**
     * @return array{groups: array<int, array{keeper:string, duplicates:string[], bytes:int}>, pages: array<int, array{path:string, target:string}>, reclaimable:int}
     */
    public function scan(): array
    {
        $byHash = [];
        foreach ($this->mirroredFiles() as $relative) {
            $byHash[(string) hash_file('sha256', $this->rootPath . '/' . $relative)][] = $relative;
        }

        $groups = [];
        $reclaimable = 0;
        foreach ($byHash as $paths) {
            if (count($paths) < 2) {
                continue;
            }
            usort($paths, fn(string $a, string $b): int => $this->keeperScore($a) <=> $this->keeperScore($b));
            $keeper = array_shift($paths);
            $bytes = (int) filesize($this->rootPath . '/' . $keeper);
            $groups[] = ['keeper' => $keeper, 'duplicates' => $paths, 'bytes' => $bytes * count($paths)];
            $reclaimable += $bytes * count($paths);
        }
        usort($groups, static fn(array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        $pages = [];
        $pattern = '/-(?:' . implode('|', self::DUPLICATE_PAGE_PARAMS) . ')(?:-[A-Za-z0-9._-]*)?-[0-9a-f]{8}\.html$/';
        foreach (($this->importedFiles)() as $relative) {
            if (preg_match($pattern, $relative) !== 1 || !is_file($this->rootPath . '/' . $relative)) {
                continue;
            }
            $target = (string) preg_replace($pattern, '.html', $relative);
            if ($target !== $relative && is_file($this->rootPath . '/' . $target)) {
                $pages[] = ['path' => $relative, 'target' => $target];
            }
        }

        return ['groups' => $groups, 'pages' => $pages, 'reclaimable' => $reclaimable];
    }

    /**
     * @return array{trash:string, files_moved:int, files_rewritten:int, pages_redirected:int}
     */
    public function apply(array $scan): array
    {
        $map = [];
        foreach ($scan['groups'] as $group) {
            foreach ($group['duplicates'] as $duplicate) {
                $map[$duplicate] = $group['keeper'];
            }
        }

        $trashId = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
        $trash = $this->trashPath() . '/' . $trashId;
        $manifest = ['moved' => [], 'originals' => []];
        Filesystem::ensureDirectory($trash);

        $rewritten = 0;
        if ($map !== []) {
            foreach ($this->referencingFiles() as $relative) {
                $full = $this->rootPath . '/' . $relative;
                $contents = (string) file_get_contents($full);
                $next = str_ends_with(strtolower($relative), '.css')
                    ? $this->rewriteCss($contents, dirname($relative), $map)
                    : $this->rewriteMarkup($contents, $map);
                if ($next === $contents) {
                    continue;
                }
                $this->stash($trash . '/originals/' . $relative, $contents);
                $manifest['originals'][] = $relative;
                Filesystem::atomicWrite($full, $next);
                $rewritten++;
            }
        }

        foreach ($map as $duplicate => $keeper) {
            $this->moveToTrash($duplicate, $trash);
            $manifest['moved'][] = $duplicate;
            $this->index->repoint($duplicate, $keeper);
        }
        $this->index->save();

        $pages = 0;
        foreach ($scan['pages'] as $page) {
            $full = $this->rootPath . '/' . $page['path'];
            if (!is_file($full)) {
                continue;
            }
            if (!in_array($page['path'], $manifest['originals'], true)) {
                $this->stash($trash . '/originals/' . $page['path'], (string) file_get_contents($full));
                $manifest['originals'][] = $page['path'];
            }
            Filesystem::atomicWrite($full, $this->redirectStub($page['target']));
            $pages++;
        }

        Filesystem::atomicWrite($trash . '/manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600);

        return ['trash' => $trashId, 'files_moved' => count($manifest['moved']), 'files_rewritten' => $rewritten, 'pages_redirected' => $pages];
    }

    /** The newest dedupe run that can still be undone. */
    public function lastRun(): ?string
    {
        $runs = glob($this->trashPath() . '/*/manifest.json') ?: [];
        rsort($runs);
        return $runs !== [] ? basename(dirname($runs[0])) : null;
    }

    /** Put back everything a dedupe run moved or rewrote. Returns files restored. */
    public function undo(string $trashId): int
    {
        if (preg_match('/^\d{8}T\d{6}Z-[0-9a-f]{6}$/', $trashId) !== 1) {
            throw new RuntimeException('Unknown clean-up run.');
        }
        $trash = $this->trashPath() . '/' . $trashId;
        $manifest = json_decode((string) @file_get_contents($trash . '/manifest.json'), true);
        if (!is_array($manifest)) {
            throw new RuntimeException('That clean-up run can no longer be undone.');
        }

        $restored = 0;
        foreach ((array) ($manifest['moved'] ?? []) as $relative) {
            $from = $trash . '/files/' . $relative;
            if ($this->isSafeRelative((string) $relative) && is_file($from)) {
                Filesystem::ensureDirectory(dirname($this->rootPath . '/' . $relative));
                @rename($from, $this->rootPath . '/' . $relative);
                $restored++;
            }
        }
        foreach ((array) ($manifest['originals'] ?? []) as $relative) {
            $from = $trash . '/originals/' . $relative;
            if ($this->isSafeRelative((string) $relative) && is_file($from)) {
                Filesystem::atomicWrite($this->rootPath . '/' . $relative, (string) file_get_contents($from));
                $restored++;
            }
        }

        $this->removeTree($trash);
        return $restored;
    }

    private function keeperScore(string $path): array
    {
        $name = basename($path);
        return [
            preg_match('/-[A-Za-z0-9._-]*-[0-9a-f]{8}\.[A-Za-z0-9]+$/', $name) === 1 ? 1 : 0, // query-slug copies last
            preg_match('#^assets/imported/www\.#', $path) === 1 ? 1 : 0,                      // apex folder first
            strlen($path),
            (int) @filemtime($this->rootPath . '/' . $path),
            $path,
        ];
    }

    /** @param array<string, string> $map duplicate path => keeper path (root-relative) */
    private function rewriteMarkup(string $contents, array $map): string
    {
        foreach ($map as $duplicate => $keeper) {
            if (!str_contains($contents, $duplicate)) {
                continue;
            }
            $contents = (string) preg_replace(
                '~(?<=["\'(\s,=/])' . preg_quote($duplicate, '~') . '(?=["\')\s,#?]|$)~',
                str_replace(['\\', '$'], ['\\\\', '\\$'], $keeper),
                $contents
            );
        }

        return $contents;
    }

    /** CSS url() values are relative to the stylesheet; resolve, then re-relativize. */
    private function rewriteCss(string $css, string $cssDir, array $map): string
    {
        return (string) preg_replace_callback('/url\(\s*(["\']?)([^"\')]+)\1\s*\)/i', function (array $m) use ($cssDir, $map): string {
            $url = trim($m[2]);
            if ($url === '' || preg_match('#^(?:[a-z]+:|//|\#)#i', $url) === 1) {
                return $m[0];
            }
            [$path, $suffix] = array_pad(preg_split('/(?=[?#])/', $url, 2) ?: [$url], 2, '');
            $resolved = str_starts_with($path, '/') ? ltrim($path, '/') : $this->resolveRelative($cssDir, $path);
            if (!isset($map[$resolved])) {
                return $m[0];
            }
            $replacement = str_starts_with($path, '/') ? '/' . $map[$resolved] : $this->relativePath($cssDir, $map[$resolved]);
            return 'url(' . $m[1] . $replacement . $suffix . $m[1] . ')';
        }, $css);
    }

    private function resolveRelative(string $dir, string $path): string
    {
        $parts = $dir === '.' || $dir === '' ? [] : explode('/', $dir);
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return implode('/', $parts);
    }

    private function relativePath(string $fromDir, string $toPath): string
    {
        $from = array_values(array_filter(explode('/', $fromDir), static fn(string $s): bool => $s !== '' && $s !== '.'));
        $to = explode('/', $toPath);
        $common = 0;
        while ($common < count($from) && $common < count($to) - 1 && $from[$common] === $to[$common]) {
            $common++;
        }

        return str_repeat('../', count($from) - $common) . implode('/', array_slice($to, $common));
    }

    /** @return string[] */
    private function mirroredFiles(): array
    {
        $base = $this->rootPath . '/assets/imported';
        if (!is_dir($base)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath)), '/');
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Files that may reference mirrored assets: crawler pages, managed pages and
     * mirrored CSS/JS. Other applications' files are never touched.
     *
     * @return string[]
     */
    private function referencingFiles(): array
    {
        $files = array_filter(($this->importedFiles)(), static fn(string $path): bool => preg_match('/\.(html?|css|js)$/i', $path) === 1);
        foreach ($this->mirroredFiles() as $path) {
            if (preg_match('/\.(css|js)$/i', $path) === 1) {
                $files[] = $path;
            }
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $file): bool {
                $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath)), '/');
                return !str_starts_with($file->getFilename(), '.') && $rel !== 'edit' && $rel !== 'assets';
            }
        ));
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.html?$/i', $file->getFilename()) === 1) {
                $head = (string) @file_get_contents($file->getPathname(), false, null, 0, 4096);
                if (str_contains($head, 'WYSITE:')) {
                    $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath)), '/');
                }
            }
        }

        return array_values(array_filter(array_unique($files), fn(string $path): bool => $this->isSafeRelative($path) && is_file($this->rootPath . '/' . $path)));
    }

    private function moveToTrash(string $relative, string $trash): void
    {
        $from = $this->rootPath . '/' . $relative;
        $to = $trash . '/files/' . $relative;
        Filesystem::ensureDirectory(dirname($to));
        if (!@rename($from, $to)) {
            throw new RuntimeException('Could not move ' . $relative . ' to the clean-up trash.');
        }
    }

    private function stash(string $path, string $contents): void
    {
        Filesystem::ensureDirectory(dirname($path));
        file_put_contents($path, $contents);
    }

    private function redirectStub(string $targetPath): string
    {
        $url = $targetPath === 'index.html' ? '/' : '/' . (str_ends_with($targetPath, '/index.html') ? substr($targetPath, 0, -strlen('index.html')) : $targetPath);
        return '<!doctype html><html><head><meta charset="utf-8"><link rel="canonical" href="' . h($url) . '">'
            . '<meta http-equiv="refresh" content="0; url=' . h($url) . '"><title>Redirecting</title></head>'
            . '<body><p><a href="' . h($url) . '">This page moved</a></p></body></html>';
    }

    private function isSafeRelative(string $path): bool
    {
        return $path !== '' && !str_contains($path, '..') && !str_starts_with($path, '/') && !str_starts_with($path, 'edit/');
    }

    private function trashPath(): string
    {
        return $this->editPath . '/storage/trash';
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
