<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * SEC-7c: who changed what. One JSON line per successful state-changing
 * request in edit/storage/audit.local.jsonl (0600), rotated at 5 MB.
 */
final class AuditLog
{
    private const MAX_BYTES = 5_242_880;

    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
    }

    public function record(string $user, string $action, array $detail = []): void
    {
        $detail = array_filter(array_map(
            static fn($value): string => function_exists('mb_substr') ? mb_substr((string) $value, 0, 200) : substr((string) $value, 0, 200),
            array_filter($detail, 'is_scalar')
        ), static fn(string $value): bool => $value !== '');
        $line = json_encode([
            't' => gmdate('c'),
            'user' => $user,
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'action' => $action,
            'detail' => $detail,
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";

        Filesystem::ensureDirectory(dirname($this->file));
        if (is_file($this->file) && (int) filesize($this->file) > self::MAX_BYTES) {
            @rename($this->file, $this->file . '.1');
        }
        $new = !is_file($this->file);
        @file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
        if ($new) {
            @chmod($this->file, 0600);
        }
    }

    /**
     * Newest first.
     *
     * @return array<int, array{t:string, user:string, ip:string, action:string, detail:array}>
     */
    public function recent(int $limit = 300, string $user = '', string $action = '', string $path = ''): array
    {
        if (!is_file($this->file)) {
            return [];
        }
        $lines = array_reverse(file($this->file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $out = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if (($user !== '' && ($entry['user'] ?? '') !== $user)
                || ($action !== '' && ($entry['action'] ?? '') !== $action)
                || ($path !== '' && !str_contains((string) ($entry['detail']['path'] ?? ''), $path))) {
                continue;
            }
            $out[] = $entry;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
