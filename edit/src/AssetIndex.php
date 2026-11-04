<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * IMP-2: the persistent, content-addressed index of mirrored files, shared by
 * every import run. It maps canonical URL keys (UrlCanonicalizer) to the local
 * file and its sha256, so an asset already mirrored is never downloaded or
 * stored again, identical bytes behind different URLs share one file, and two
 * different files never overwrite each other.
 *
 * Stored in edit/storage/import-assets.local.json (see the format below); saved
 * in batches and under a lock.
 *
 * {"version":1,"entries":{"<key>":{"path":"assets/imported/…","sha256":"…","bytes":1,
 *   "content_type":"…","etag":"…","last_modified":"…","final_url":"…","aliases":["…"],
 *   "first_seen":"…"}},"by_hash":{"<sha256>":"<key>"}}
 */
final class AssetIndex
{
    private const SAVE_EVERY = 50;

    private string $file;
    private string $rootPath;
    /** @var array{version:int, entries:array<string,array>, by_hash:array<string,string>}|null */
    private ?array $data = null;
    private int $pending = 0;
    /** @var array<string, string>|null local path => sha256 */
    private ?array $pathHashes = null;

    public function __construct(string $file, string $rootPath)
    {
        $this->file = $file;
        $this->rootPath = rtrim($rootPath, '/');
    }

    /** The entry for $key if its file still exists. */
    public function lookup(string $key): ?array
    {
        $entry = $this->load()['entries'][$key] ?? null;
        return is_array($entry) && $this->exists((string) ($entry['path'] ?? '')) ? $entry : null;
    }

    /** The entry whose file has these bytes, if any still exists. */
    public function lookupHash(string $sha256): ?array
    {
        $key = $this->load()['by_hash'][$sha256] ?? null;
        $entry = is_string($key) ? $this->lookup($key) : null;
        return $entry !== null ? $entry + ['key' => $key] : null;
    }

    /** sha256 recorded for a local path (null when the index doesn't know it). */
    public function hashForPath(string $relativePath): ?string
    {
        if ($this->pathHashes === null) {
            $this->pathHashes = [];
            foreach ($this->load()['entries'] as $entry) {
                if (($entry['path'] ?? '') !== '' && ($entry['sha256'] ?? '') !== '') {
                    $this->pathHashes[(string) $entry['path']] = (string) $entry['sha256'];
                }
            }
        }

        return $this->pathHashes[$relativePath] ?? null;
    }

    public function record(string $key, array $entry): void
    {
        $data = $this->load();
        $existing = $data['entries'][$key] ?? [];
        $entry['aliases'] = array_values(array_unique(array_merge((array) ($existing['aliases'] ?? []), (array) ($entry['aliases'] ?? []))));
        $entry['first_seen'] = $existing['first_seen'] ?? gmdate('c');
        $this->data['entries'][$key] = $entry;
        if ($this->pathHashes !== null && !empty($entry['path']) && !empty($entry['sha256'])) {
            $this->pathHashes[(string) $entry['path']] = (string) $entry['sha256'];
        }
        if (!empty($entry['sha256'])) {
            $this->data['by_hash'][(string) $entry['sha256']] ??= $key;
        }
        $this->touch();
    }

    /** Remember that $rawUrl (or another key) resolves to the same file as $key. */
    public function addAlias(string $key, string $rawUrl): void
    {
        $this->load();
        if (!isset($this->data['entries'][$key]) || $rawUrl === '' || in_array($rawUrl, (array) $this->data['entries'][$key]['aliases'], true)) {
            return;
        }
        $this->data['entries'][$key]['aliases'][] = $rawUrl;
        $this->touch();
    }

    /** Point an alias key at an existing entry (another URL for the same file). */
    public function link(string $aliasKey, string $targetKey): void
    {
        $target = $this->lookup($targetKey);
        if ($target === null || $aliasKey === $targetKey) {
            return;
        }
        $this->load();
        $this->data['entries'][$aliasKey] = $target + ['alias_of' => $targetKey];
        $this->data['entries'][$aliasKey]['alias_of'] = $targetKey;
        $this->touch();
    }

    /** Replace every reference to $fromPath with $toPath (dedupe tool). */
    public function repoint(string $fromPath, string $toPath): void
    {
        $this->load();
        foreach ($this->data['entries'] as $key => $entry) {
            if (($entry['path'] ?? '') === $fromPath) {
                $this->data['entries'][$key]['path'] = $toPath;
            }
        }
        $this->pathHashes = null;
        $this->touch();
    }

    /** @return array<string, array> */
    public function entries(): array
    {
        return $this->load()['entries'];
    }

    public function save(): void
    {
        if ($this->data === null || $this->pending === 0) {
            return;
        }

        $data = $this->data;
        Filesystem::withLock($this->file, function () use ($data): void {
            // Merge with what other requests saved meanwhile (ours wins per key).
            $onDisk = $this->read();
            $merged = [
                'version' => 1,
                'entries' => array_merge($onDisk['entries'], $data['entries']),
                'by_hash' => array_merge($onDisk['by_hash'], $data['by_hash']),
            ];
            Filesystem::atomicWrite($this->file, (string) json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 0600);
            $this->data = $merged;
        });
        $this->pending = 0;
    }

    private function touch(): void
    {
        if (++$this->pending >= self::SAVE_EVERY) {
            $this->save();
        }
    }

    private function exists(string $relativePath): bool
    {
        return $relativePath !== '' && !str_contains($relativePath, '..') && is_file($this->rootPath . '/' . $relativePath);
    }

    /** @return array{version:int, entries:array<string,array>, by_hash:array<string,string>} */
    private function load(): array
    {
        return $this->data ??= $this->read();
    }

    /** @return array{version:int, entries:array<string,array>, by_hash:array<string,string>} */
    private function read(): array
    {
        $decoded = is_file($this->file) ? json_decode((string) file_get_contents($this->file), true) : null;
        return [
            'version' => 1,
            'entries' => is_array($decoded['entries'] ?? null) ? $decoded['entries'] : [],
            'by_hash' => is_array($decoded['by_hash'] ?? null) ? $decoded['by_hash'] : [],
        ];
    }
}
