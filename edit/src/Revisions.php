<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * Per-file revision history. Before a site page or active template is
 * overwritten, its previous bytes are copied to
 * edit/storage/revisions/<root-relative path>/<UTC timestamp>-<rand>.html
 * (storage/ is denied over HTTP and git-ignored). The newest $keep revisions
 * per file are retained.
 */
final class Revisions
{
    private string $rootPath;
    private string $storePath;
    private int $keep;

    public function __construct(string $rootPath, string $storePath, int $keep = 20)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/');
        $this->keep = max(1, $keep);
    }

    /** Root-relative path for an absolute path, or null when outside the root. */
    public function relative(string $absPath): ?string
    {
        $absPath = str_replace('\\', '/', $absPath);
        if (!str_starts_with($absPath, $this->rootPath . '/')) {
            return null;
        }

        return substr($absPath, strlen($this->rootPath) + 1);
    }

    /** True for files whose history we keep: .html pages and templates, not storage. */
    public function tracks(string $absPath): bool
    {
        $relative = $this->relative($absPath);
        if ($relative === null || str_starts_with($relative, 'edit/storage/')) {
            return false;
        }

        return preg_match('/\.html?$/i', $relative) === 1;
    }

    /** Copy the current contents of $absPath into history (no-op if it doesn't exist). */
    public function snapshot(string $absPath, ?string $incoming = null): ?string
    {
        if (!$this->tracks($absPath) || !is_file($absPath)) {
            return null;
        }

        $current = (string) file_get_contents($absPath);
        if ($incoming !== null && $incoming === $current) {
            return null;
        }

        $dir = $this->dirFor((string) $this->relative($absPath));
        $id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(3));
        Filesystem::atomicWrite($dir . '/' . $id . '.html', $current, 0600);
        $this->prune($dir);

        return $id;
    }

    /**
     * @return array<int, array{id:string, time:int, bytes:int}> newest first
     */
    public function list(string $relativePath): array
    {
        $dir = $this->dirFor($relativePath);
        $items = [];
        foreach (glob($dir . '/*.html') ?: [] as $file) {
            $id = basename($file, '.html');
            $items[] = ['id' => $id, 'time' => $this->timeFromId($id), 'bytes' => (int) filesize($file)];
        }

        usort($items, static fn(array $a, array $b): int => strcmp($b['id'], $a['id']));
        return $items;
    }

    public function read(string $relativePath, string $id): string
    {
        $file = $this->fileFor($relativePath, $id);
        if (!is_file($file)) {
            throw new RuntimeException('That revision no longer exists.');
        }

        return (string) file_get_contents($file);
    }

    /** Restore a revision (the current version is itself snapshotted first). */
    public function restore(string $relativePath, string $id): void
    {
        $contents = $this->read($relativePath, $id);
        Filesystem::writeSitePage($this->rootPath . '/' . $this->cleanRelative($relativePath), $contents);
    }

    private function dirFor(string $relativePath): string
    {
        return $this->storePath . '/' . $this->cleanRelative($relativePath);
    }

    private function fileFor(string $relativePath, string $id): string
    {
        if (preg_match('/^\d{8}T\d{6}Z-[0-9a-f]{6}$/', $id) !== 1) {
            throw new RuntimeException('Invalid revision id.');
        }

        return $this->dirFor($relativePath) . '/' . $id . '.html';
    }

    private function cleanRelative(string $relativePath): string
    {
        $clean = trim(str_replace('\\', '/', $relativePath), '/');
        foreach (explode('/', $clean) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new RuntimeException('Invalid revision path.');
            }
        }

        return $clean;
    }

    private function prune(string $dir): void
    {
        $files = glob($dir . '/*.html') ?: [];
        rsort($files);
        foreach (array_slice($files, $this->keep) as $old) {
            @unlink($old);
        }
    }

    private function timeFromId(string $id): int
    {
        $time = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', substr($id, 0, 16), new \DateTimeZone('UTC'));
        return $time instanceof \DateTimeImmutable ? $time->getTimestamp() : 0;
    }
}

/**
 * Whole-site snapshots taken before bulk operations (theme apply, import all,
 * purge, delete demo, template rebuild): every site .html file plus the active
 * templates, as edit/storage/backups/<timestamp>-<action>.zip (or a directory
 * copy when ZipArchive is unavailable).
 */
final class Backups
{
    private string $rootPath;
    private string $storePath;
    private int $keep;

    public function __construct(string $rootPath, string $storePath, int $keep = 10)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $this->storePath = rtrim(str_replace('\\', '/', $storePath), '/');
        $this->keep = max(1, $keep);
    }

    /** Snapshot the site; returns the backup name. */
    public function create(string $action): string
    {
        $action = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($action)), '-') ?: 'backup';
        $name = gmdate('Ymd\THis\Z') . '-' . $action;
        Filesystem::ensureDirectory($this->storePath);

        $files = $this->collect();

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            $target = $this->storePath . '/' . $name . '.zip';
            if ($zip->open($target, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Unable to create the backup archive.');
            }
            foreach ($files as $relative) {
                $zip->addFile($this->rootPath . '/' . $relative, $relative);
            }
            if ($files === []) {
                $zip->addFromString('.empty', '');
            }
            $zip->close();
            @chmod($target, 0600);
        } else {
            $dir = $this->storePath . '/' . $name;
            Filesystem::ensureDirectory($dir);
            foreach ($files as $relative) {
                Filesystem::ensureDirectory(dirname($dir . '/' . $relative));
                copy($this->rootPath . '/' . $relative, $dir . '/' . $relative);
            }
        }

        $this->prune();
        return $name;
    }

    /** @return array<int, array{name:string, time:int, bytes:int, files:int}> newest first */
    public function list(): array
    {
        $items = [];
        foreach (glob($this->storePath . '/*') ?: [] as $path) {
            $base = basename($path);
            if (!preg_match('/^(\d{8}T\d{6}Z)-[a-z0-9-]+(\.zip)?$/', $base, $match)) {
                continue;
            }
            $name = preg_replace('/\.zip$/', '', $base) ?? $base;
            $time = \DateTimeImmutable::createFromFormat('Ymd\THis\Z', $match[1], new \DateTimeZone('UTC'));
            $items[] = [
                'name' => $name,
                'time' => $time instanceof \DateTimeImmutable ? $time->getTimestamp() : 0,
                'bytes' => is_file($path) ? (int) filesize($path) : 0,
                'files' => count($this->entries($name)),
            ];
        }

        usort($items, static fn(array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $items;
    }

    /** Absolute path of a zip backup (for download). */
    public function archivePath(string $name): string
    {
        $path = $this->storePath . '/' . $this->assertName($name) . '.zip';
        if (!is_file($path)) {
            throw new RuntimeException('That backup is not available as an archive.');
        }

        return $path;
    }

    /**
     * Restore every file in a backup (current pages are snapshotted into revision
     * history first). Files created after the backup are left alone.
     */
    public function restore(string $name): int
    {
        $name = $this->assertName($name);
        $restored = 0;

        foreach ($this->entries($name) as $relative => $reader) {
            if (!$this->isRestorable($relative)) {
                continue;
            }
            Filesystem::writeSitePage($this->rootPath . '/' . $relative, $reader());
            $restored++;
        }

        return $restored;
    }

    /** @return array<string, callable(): string> */
    private function entries(string $name): array
    {
        $entries = [];
        $zipPath = $this->storePath . '/' . $name . '.zip';
        if (is_file($zipPath) && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entry = (string) $zip->getNameIndex($i);
                    if ($entry === '.empty') {
                        continue;
                    }
                    $entries[$entry] = static function () use ($zipPath, $entry): string {
                        $zip = new \ZipArchive();
                        $zip->open($zipPath);
                        $data = (string) $zip->getFromName($entry);
                        $zip->close();
                        return $data;
                    };
                }
                $zip->close();
            }
            return $entries;
        }

        $dir = $this->storePath . '/' . $name;
        if (!is_dir($dir)) {
            return [];
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($dir) + 1);
            $entries[$relative] = static fn(): string => (string) file_get_contents($file->getPathname());
        }

        return $entries;
    }

    /** @return string[] root-relative paths */
    private function collect(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $file): bool {
                    $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath) + 1);
                    if (str_starts_with($file->getFilename(), '.')) {
                        return false;
                    }
                    if (!$file->isDir()) {
                        return true;
                    }
                    if ($relative === 'assets') {
                        return false;
                    }
                    if ($relative === 'edit' || $relative === 'edit/templates') {
                        return true;
                    }
                    return !str_starts_with($relative, 'edit/');
                }
            )
        );

        foreach ($iterator as $file) {
            $relative = substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath) + 1);
            if ($this->isRestorable($relative)) {
                $files[] = $relative;
            }
        }

        sort($files);
        return $files;
    }

    private function isRestorable(string $relative): bool
    {
        if (preg_match('/\.html?$/i', $relative) !== 1 || str_contains($relative, '..')) {
            return false;
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || str_starts_with($segment, '.')) {
                return false;
            }
        }

        if (str_starts_with($relative, 'edit/')) {
            return preg_match('#^edit/templates/[a-z0-9-]+\.html$#', $relative) === 1;
        }

        return !str_starts_with($relative, 'assets/');
    }

    private function assertName(string $name): string
    {
        if (preg_match('/^\d{8}T\d{6}Z-[a-z0-9-]+$/', $name) !== 1) {
            throw new RuntimeException('Invalid backup name.');
        }

        return $name;
    }

    private function prune(): void
    {
        $all = array_column($this->list(), 'name');
        foreach (array_slice($all, $this->keep) as $old) {
            @unlink($this->storePath . '/' . $old . '.zip');
            $dir = $this->storePath . '/' . $old;
            if (is_dir($dir)) {
                $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($items as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($dir);
            }
        }
    }
}
