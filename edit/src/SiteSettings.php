<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * Per-install site identity and behaviour, stored in edit/storage/site.local.php
 * (git-ignored). Values here feed template tokens ({{SITE_NAME}}, {{FOOTER}},
 * {{HEAD_META}}, ...) so they survive theme changes.
 */
final class SiteSettings
{
    public const DEFAULTS = [
        'site_name' => 'WYSiteIWYG',
        'tagline' => 'Static pages, edited in place',
        'language' => 'en',
        'canonical_base_url' => '',
        'logo' => '',
        'favicon' => '',
        'footer_html' => '<p>Published as static HTML and edited in place.</p>',
        'live_banner' => true,
        'url_style' => 'folder',
        'post_permalink' => '{slug}',
        'posts_per_page' => 10,
        'exclude_paths' => '',
        'embed_hosts' => '',
        'import_byte_budget_mb' => 2048,
        'keep_imported_scripts' => false,
        'search_url' => '',
        'form_endpoint' => '',
        'theme_variables' => [],
        'checklist_dismissed' => false,
    ];

    public const PERMALINK_PATTERNS = ['{slug}', 'blog/{slug}', '{yyyy}/{mm}/{slug}'];

    private string $path;
    private ?array $cache = null;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $stored = [];
        if (is_file($this->path)) {
            $data = require $this->path;
            $stored = is_array($data) ? $data : [];
        }

        return $this->cache = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? (self::DEFAULTS[$key] ?? null);
    }

    public function isCustomized(string $key): bool
    {
        if (!is_file($this->path)) {
            return false;
        }
        $data = require $this->path;
        return is_array($data) && array_key_exists($key, $data);
    }

    /** Validate and persist a subset of settings. Returns the full new settings. */
    public function update(array $input): array
    {
        $clean = [];
        foreach ($input as $key => $value) {
            if (!array_key_exists($key, self::DEFAULTS)) {
                continue;
            }
            $clean[$key] = $this->validate($key, $value);
        }

        Filesystem::withLock($this->path, function () use ($clean): void {
            $current = [];
            if (is_file($this->path)) {
                $data = require $this->path;
                $current = is_array($data) ? $data : [];
            }
            $next = array_merge($current, $clean);
            Filesystem::atomicWrite($this->path, "<?php\nreturn " . var_export($next, true) . ";\n");
        });

        $this->cache = null;
        return $this->all();
    }

    /** Site-root-relative paths (or path prefixes) to leave out of scans and purges. */
    public function excludedPaths(): array
    {
        $paths = [];
        foreach (preg_split('/[\r\n,]+/', (string) $this->get('exclude_paths')) ?: [] as $line) {
            $line = trim(str_replace('\\', '/', $line), " /");
            if ($line !== '' && !str_contains($line, '..')) {
                $paths[] = $line;
            }
        }

        return $paths;
    }

    /** @return string[] */
    public function embedHosts(): array
    {
        $hosts = preg_split('/[\s,]+/', strtolower((string) $this->get('embed_hosts'))) ?: [];
        return array_values(array_filter($hosts, static fn(string $host): bool => preg_match('/^[a-z0-9.-]+$/', $host) === 1));
    }

    private function validate(string $key, mixed $value): mixed
    {
        switch ($key) {
            case 'site_name':
            case 'tagline':
                $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? '');
                if ($key === 'site_name' && $value === '') {
                    throw new RuntimeException('The site name cannot be empty.');
                }
                return function_exists('mb_substr') ? mb_substr($value, 0, 120) : substr($value, 0, 120);

            case 'language':
                $value = trim((string) $value);
                if (preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $value) !== 1) {
                    throw new RuntimeException('Use a language code such as "en" or "en-GB".');
                }
                return $value;

            case 'canonical_base_url':
            case 'search_url':
            case 'form_endpoint':
                $value = trim((string) $value);
                if ($value === '') {
                    return '';
                }
                $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));
                if (!in_array($scheme, ['http', 'https'], true) || (string) parse_url($value, PHP_URL_HOST) === '') {
                    throw new RuntimeException('Enter a full http(s) URL for ' . str_replace('_', ' ', $key) . '.');
                }
                return $key === 'canonical_base_url' ? rtrim($value, '/') . '/' : $value;

            case 'logo':
            case 'favicon':
                $value = ltrim(trim((string) $value), '/');
                if ($value !== '' && (preg_match('#^assets/[A-Za-z0-9._/-]+$#', $value) !== 1 || str_contains($value, '..'))) {
                    throw new RuntimeException('Invalid ' . $key . ' path.');
                }
                return $value;

            case 'footer_html':
                return Sanitizer::html((string) $value);

            case 'live_banner':
            case 'keep_imported_scripts':
            case 'checklist_dismissed':
                return (bool) $value;

            case 'url_style':
                return in_array($value, ['folder', 'flat'], true) ? $value : 'folder';

            case 'post_permalink':
                return in_array($value, self::PERMALINK_PATTERNS, true) ? $value : '{slug}';

            case 'posts_per_page':
                return max(1, min(100, (int) $value));

            case 'import_byte_budget_mb':
                return max(50, min(1_048_576, (int) $value));

            case 'exclude_paths':
            case 'embed_hosts':
                return trim((string) $value);

            case 'theme_variables':
                if (!is_array($value)) {
                    return [];
                }
                $out = [];
                foreach ($value as $theme => $vars) {
                    if (!is_string($theme) || preg_match('/^[a-z0-9-]+$/', $theme) !== 1 || !is_array($vars)) {
                        continue;
                    }
                    foreach ($vars as $name => $varValue) {
                        if (is_string($name) && preg_match('/^[a-z0-9-]+$/', $name) === 1) {
                            $out[$theme][$name] = trim(str_replace(['{', '}', '<', '>', ';'], '', (string) $varValue));
                        }
                    }
                }
                return $out;
        }

        return $value;
    }
}
