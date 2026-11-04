<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * UX-4: browse uploaded and imported files, see where each is used, and move
 * unused ones out of the site (into edit/storage/trash/, so nothing is lost).
 * UX-6 lives here too: a line diff between two versions of a page.
 */
final class MediaLibrary
{
    private const FOLDERS = ['assets/uploads', 'assets/imported'];
    private const IMAGE = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico'];
    private const DOCUMENT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv', 'epub', 'txt'];
    private const MEDIA = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'mp4', 'm4v', 'mov', 'webm', 'ogv'];

    private string $rootPath;
    private string $editPath;
    /** @var string[]|null */
    private ?array $fileCache = null;

    public function __construct(string $rootPath, string $editPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->editPath = rtrim($editPath, '/');
    }

    public static function typeOf(string $path): string
    {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::IMAGE, true) ? 'image'
            : (in_array($extension, self::DOCUMENT, true) ? 'document'
                : (in_array($extension, self::MEDIA, true) ? 'media'
                    : (in_array($extension, ['css', 'js', 'woff', 'woff2', 'ttf', 'otf', 'eot', 'map'], true) ? 'code' : 'other')));
    }

    /**
     * @return array{items: array<int, array{path:string, type:string, bytes:int, modified:int, uses:int}>, total:int}
     */
    public function list(string $type = '', bool $unusedOnly = false, string $search = '', int $limit = 300): array
    {
        $uses = $this->usageIndex();
        $items = [];
        foreach ($this->files() as $path) {
            $itemType = self::typeOf($path);
            if (($type !== '' && $itemType !== $type) || ($search !== '' && stripos($path, $search) === false)) {
                continue;
            }
            $count = $uses[$path] ?? 0;
            if ($unusedOnly && ($count > 0 || $itemType === 'code')) {
                continue;
            }
            $full = $this->rootPath . '/' . $path;
            $items[] = ['path' => $path, 'type' => $itemType, 'bytes' => (int) filesize($full), 'modified' => (int) filemtime($full), 'uses' => $count];
        }
        usort($items, static fn(array $a, array $b): int => $b['modified'] <=> $a['modified']);

        return ['items' => array_slice($items, 0, $limit), 'total' => count($items)];
    }

    /**
     * Move files no page or stylesheet uses into edit/storage/trash/media-<time>/.
     *
     * @param string[] $paths
     * @return int files moved
     */
    public function trashUnused(array $paths): int
    {
        $uses = $this->usageIndex();
        $trash = $this->editPath . '/storage/trash/media-' . gmdate('Ymd\THis\Z');
        $moved = 0;
        foreach ($paths as $path) {
            $path = ltrim((string) $path, '/');
            if (!in_array($path, $this->files(), true) || ($uses[$path] ?? 0) > 0) {
                continue;
            }
            Filesystem::ensureDirectory(dirname($trash . '/' . $path));
            if (@rename($this->rootPath . '/' . $path, $trash . '/' . $path)) {
                $moved++;
            }
        }

        return $moved;
    }

    /** @return string[] root-relative files in the media folders */
    private function files(): array
    {
        if ($this->fileCache !== null) {
            return $this->fileCache;
        }
        $files = [];
        foreach (self::FOLDERS as $folder) {
            $base = $this->rootPath . '/' . $folder;
            if (!is_dir($base)) {
                continue;
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !str_starts_with($file->getFilename(), '.')) {
                    $files[] = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath)), '/');
                }
            }
        }

        return $this->fileCache = $files;
    }

    /**
     * How many pages and stylesheets mention each file (by site path in HTML,
     * by file name in CSS, whose url() values are relative).
     *
     * @return array<string, int>
     */
    private function usageIndex(): array
    {
        $files = $this->files();
        $byName = [];
        foreach ($files as $path) {
            $byName[basename($path)][] = $path;
        }
        $uses = [];
        $scan = function (string $contents, bool $isCss) use ($files, $byName, &$uses): void {
            if ($isCss) {
                preg_match_all('/url\(\s*["\']?([^"\')]+)/i', $contents, $m);
                foreach ($m[1] as $url) {
                    foreach ($byName[basename((string) parse_url($url, PHP_URL_PATH))] ?? [] as $path) {
                        $uses[$path] = ($uses[$path] ?? 0) + 1;
                    }
                }
                return;
            }
            preg_match_all('#assets/(?:uploads|imported)/[^"\'\s)<>?\#,]+#', $contents, $m);
            foreach (array_unique($m[0]) as $path) {
                $path = rawurldecode($path);
                $uses[$path] = ($uses[$path] ?? 0) + 1;
            }
        };

        $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($this->rootPath, \FilesystemIterator::SKIP_DOTS),
            function (\SplFileInfo $file): bool {
                $rel = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($this->rootPath)), '/');
                // Pages, imported files and stylesheets; never the editor's own state.
                return !str_starts_with($file->getFilename(), '.') && !str_starts_with($rel, 'edit/storage') && !str_starts_with($rel, 'edit/tests') && !str_starts_with($rel, 'edit/assets');
            }
        ));
        foreach ($iterator as $file) {
            $name = strtolower($file->getFilename());
            if (!$file->isFile() || $file->getSize() > 8_388_608) {
                continue;
            }
            if (str_ends_with($name, '.html') || str_ends_with($name, '.htm') || str_ends_with($name, '.xml') || str_ends_with($name, '.json')) {
                $scan((string) file_get_contents($file->getPathname()), false);
            } elseif (str_ends_with($name, '.css')) {
                $scan((string) file_get_contents($file->getPathname()), true);
            }
        }

        return $uses;
    }

    /**
     * UX-6: line diff of two HTML documents (tags start lines, so changes stay
     * readable). Each row is ['same'|'add'|'del', line]; unchanged runs are
     * shortened to a few lines of context.
     *
     * @return array<int, array{0:string, 1:string}>
     */
    public static function diff(string $old, string $new, int $context = 3): array
    {
        $split = static fn(string $html): array => array_slice(explode("\n", (string) preg_replace('/>\s*</', ">\n<", trim($html))), 0, 4000);
        $a = $split($old);
        $b = $split($new);
        $n = count($a);
        $m = count($b);
        if ($n * $m > 4_000_000) {
            throw new RuntimeException('These versions are too large to compare.');
        }
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j] ? $lcs[$i + 1][$j + 1] + 1 : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $rows = [];
        $i = $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j]) {
                $rows[] = ['same', $a[$i]];
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $rows[] = ['add', $b[$j++]];
            } else {
                $rows[] = ['del', $a[$i++]];
            }
        }

        // Keep context around changes only.
        $keep = [];
        foreach ($rows as $index => $row) {
            if ($row[0] !== 'same') {
                for ($k = max(0, $index - $context); $k <= min(count($rows) - 1, $index + $context); $k++) {
                    $keep[$k] = true;
                }
            }
        }
        $out = [];
        $skipped = 0;
        foreach ($rows as $index => $row) {
            if (isset($keep[$index])) {
                if ($skipped > 0) {
                    $out[] = ['skip', $skipped . ' unchanged line(s)'];
                    $skipped = 0;
                }
                $out[] = $row;
            } else {
                $skipped++;
            }
        }
        if ($skipped > 0 && $out !== []) {
            $out[] = ['skip', $skipped . ' unchanged line(s)'];
        }

        return $out;
    }
}
