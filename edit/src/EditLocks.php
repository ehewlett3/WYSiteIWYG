<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * UX-10: soft edit locks. While someone has an editor open on a page, the
 * preview tells others "Alex is editing this page". Locks expire on their own
 * (the editor renews them every minute); saving still refuses to overwrite a
 * concurrent change (baseHash), so a lock is advice, not a hard block.
 */
final class EditLocks
{
    private const TTL = 120;

    private string $dir;

    public function __construct(string $editPath)
    {
        $this->dir = rtrim($editPath, '/') . '/storage/locks';
    }

    /** The other user editing $path right now, if any. */
    public function holder(string $path, string $user): ?string
    {
        $lock = $this->read($path);
        return $lock !== null && $lock['user'] !== $user ? $lock['user'] : null;
    }

    /** Take or renew the lock; returns the other holder when someone else has it. */
    public function acquire(string $path, string $user): ?string
    {
        $other = $this->holder($path, $user);
        if ($other !== null) {
            return $other;
        }
        Filesystem::atomicWrite($this->file($path), (string) json_encode(['user' => $user, 'until' => time() + self::TTL, 'path' => $path]), 0600);

        return null;
    }

    public function release(string $path, string $user): void
    {
        $lock = $this->read($path);
        if ($lock !== null && $lock['user'] === $user) {
            @unlink($this->file($path));
        }
    }

    /** @return array{user:string, until:int}|null */
    private function read(string $path): ?array
    {
        $file = $this->file($path);
        $lock = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($lock) || (int) ($lock['until'] ?? 0) < time()) {
            return null;
        }

        return ['user' => (string) $lock['user'], 'until' => (int) $lock['until']];
    }

    private function file(string $path): string
    {
        return $this->dir . '/' . sha1($path) . '.json';
    }
}
