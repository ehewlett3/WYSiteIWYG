<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * Environment preflight (DROP-2): PHP version, extensions, and write access the
 * app needs, plus optional HTTP probes (is edit/storage/ publicly readable, do
 * clean URLs work). Each row is ['status' => pass|warn|fail, 'label', 'detail'].
 */
final class SystemCheck
{
    private string $rootPath;
    private string $editPath;

    public function __construct(string $rootPath, string $editPath)
    {
        $this->rootPath = rtrim($rootPath, '/');
        $this->editPath = rtrim($editPath, '/');
    }

    /** @return array<int, array{status:string, label:string, detail:string}> */
    public function run(): array
    {
        $rows = [];

        $rows[] = version_compare(PHP_VERSION, '8.1.0', '>=')
            ? $this->row('pass', 'PHP version', PHP_VERSION)
            : $this->row('fail', 'PHP version', PHP_VERSION . ' — PHP 8.1 or newer is required.');

        $extensions = [
            'dom' => ['fail', 'required for page editing, imports, and the sanitizer'],
            'libxml' => ['fail', 'required by the DOM extension'],
            'fileinfo' => ['warn', 'image uploads fall back to getimagesize()'],
            'curl' => ['warn', 'imports fall back to PHP streams (slower for large media)'],
            'openssl' => ['warn', 'needed to fetch https:// sites and AI providers'],
            'zip' => ['warn', 'backups are stored as folders instead of .zip files'],
            'mbstring' => ['warn', 'long site names are trimmed byte-wise'],
        ];
        foreach ($extensions as $extension => [$severity, $why]) {
            $rows[] = extension_loaded($extension)
                ? $this->row('pass', 'ext-' . $extension, 'loaded')
                : $this->row($severity, 'ext-' . $extension, 'missing — ' . $why . '.');
        }

        $writable = [
            '' => 'site root (new pages)',
            'assets' => 'uploads and imported assets',
            'edit/storage' => 'accounts, settings, backups',
            'edit/templates' => 'active templates',
            'edit/themes' => 'theme builder',
        ];
        foreach ($writable as $relative => $purpose) {
            $path = $this->rootPath . ($relative === '' ? '' : '/' . $relative);
            $label = 'Writable: ' . ($relative === '' ? '/' : $relative . '/');
            if (!file_exists($path)) {
                $parent = dirname($path);
                $rows[] = is_writable($parent)
                    ? $this->row('pass', $label, 'will be created (' . $purpose . ')')
                    : $this->row('fail', $label, 'missing and cannot be created — ' . $purpose . '.');
                continue;
            }
            $rows[] = $this->isWritableDirectory($path)
                ? $this->row('pass', $label, $purpose)
                : $this->row('fail', $label, 'the web server cannot write here — ' . $purpose . '. Fix the folder owner or permissions.');
        }

        $sessionPath = (string) session_save_path();
        if ($sessionPath !== '' && is_dir($sessionPath) && !is_writable($sessionPath)) {
            $rows[] = $this->row('warn', 'PHP sessions', 'session.save_path (' . $sessionPath . ') is not writable; sign-in may fail.');
        }

        return $rows;
    }

    /**
     * HTTP probes against this deployment. $siteUrl is the absolute URL of the
     * site root (e.g. https://example.org/sub/). Failures to connect are warnings,
     * never failures: some hosts block loopback requests.
     *
     * @return array<int, array{status:string, label:string, detail:string}>
     */
    public function probe(string $siteUrl, ?string $flatPagePath = null): array
    {
        $rows = [];
        $siteUrl = rtrim($siteUrl, '/') . '/';

        $status = $this->httpStatus($siteUrl . 'edit/storage/.htaccess');
        if ($status === null) {
            $rows[] = $this->row('warn', 'Private storage', 'Could not reach this site over HTTP to check (loopback blocked?).');
        } elseif ($status === 200) {
            $rows[] = $this->row('fail', 'Private storage', 'edit/storage/ is publicly readable. This server ignores .htaccess (nginx, Caddy, php -S…): deny /edit/storage/, /edit/tests/ and /.git/ in its config (see README).');
        } else {
            $rows[] = $this->row('pass', 'Private storage', 'edit/storage/ is not served (HTTP ' . $status . ').');
        }

        if ($flatPagePath !== null) {
            $clean = preg_replace('/\.html?$/i', '', $flatPagePath) . '/';
            $status = $this->httpStatus($siteUrl . $clean);
            if ($status === 200) {
                $rows[] = $this->row('pass', 'Clean URLs', '/' . $clean . ' serves ' . $flatPagePath . '.');
            } elseif ($status !== null) {
                $rows[] = $this->row('fail', 'Clean URLs', '/' . $clean . ' returned HTTP ' . $status . ': flat .html pages need the root .htaccess rewrite rules. Use Manager → "Convert to folder URLs" to work on any server.');
            }
        }

        return $rows;
    }

    /** True if every row passed or only warned. */
    public static function hasFailures(array $rows): bool
    {
        foreach ($rows as $row) {
            if ($row['status'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    private function isWritableDirectory(string $path): bool
    {
        if (!is_dir($path) || !is_writable($path)) {
            return false;
        }

        // is_writable() can be wrong under ACLs/open_basedir; try a real file.
        $probe = $path . '/.wysite-write-test-' . bin2hex(random_bytes(4));
        if (@file_put_contents($probe, 'x') === false) {
            return false;
        }
        @unlink($probe);
        return true;
    }

    private function httpStatus(string $url): ?int
    {
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 4, 'ignore_errors' => true, 'follow_location' => 0, 'user_agent' => 'WYSiteIWYG system check'],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $handle = @fopen($url, 'rb', false, $context);
        if (!is_resource($handle)) {
            return null;
        }
        $meta = stream_get_meta_data($handle);
        fclose($handle);

        $status = null;
        foreach ((array) ($meta['wrapper_data'] ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }

    private function row(string $status, string $label, string $detail): array
    {
        return ['status' => $status, 'label' => $label, 'detail' => $detail];
    }
}
