<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

final class ThemeManager
{
    private string $storePath;
    private string $themesPath;
    private string $defaultTheme = 'wysite-cathedral';

    public function __construct(string $storePath, string $themesPath)
    {
        $this->storePath = $storePath;
        $this->themesPath = rtrim($themesPath, '/');
    }

    public function listThemes(): array
    {
        $themes = [];
        foreach (glob($this->themesPath . '/*/theme.php') ?: [] as $definition) {
            $themePath = str_replace('\\', '/', dirname($definition));
            $id = basename($themePath);
            $meta = require $definition;
            if (!is_array($meta)) {
                continue;
            }

            $themes[] = [
                'id' => $id,
                'name' => $meta['name'] ?? $id,
                'description' => $meta['description'] ?? '',
                'inspiration' => $meta['inspiration'] ?? '',
                'preview_blurb' => $meta['preview_blurb'] ?? '',
            ];
        }

        usort(
            $themes,
            static fn(array $left, array $right): int => strcmp($left['name'], $right['name'])
        );

        return $themes;
    }

    public function getTheme(string $id): array
    {
        foreach ($this->listThemes() as $theme) {
            if ($theme['id'] === $id) {
                return $theme;
            }
        }

        throw new RuntimeException('Unknown theme: ' . $id);
    }

    public function currentThemeId(): string
    {
        $data = $this->load();
        $themeId = $data['theme'] ?? $this->defaultTheme;

        try {
            $this->getTheme($themeId);
            return $themeId;
        } catch (RuntimeException) {
            return $this->defaultTheme;
        }
    }

    public function currentTheme(): array
    {
        return $this->getTheme($this->currentThemeId());
    }

    public function setCurrentTheme(string $id): void
    {
        $this->getTheme($id);
        $data = $this->load();
        $data['theme'] = $id;
        $this->save($data);
    }

    public function loadTemplate(string $themeId, string $kind): string
    {
        $map = [
            'page' => 'page.html',
            'blog-post' => 'blog-post.html',
            'blog' => 'blog-index.html',
        ];

        $filename = $map[$kind] ?? null;
        if ($filename === null) {
            throw new RuntimeException('Unknown theme template kind: ' . $kind);
        }

        $path = $this->themePath($themeId) . '/' . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Missing theme template: ' . $filename);
        }

        return (string) file_get_contents($path);
    }

    public function loadStylesheet(string $themeId): string
    {
        $path = $this->themePath($themeId) . '/site.css';
        if (!is_file($path)) {
            throw new RuntimeException('Missing theme stylesheet for ' . $themeId);
        }

        return (string) file_get_contents($path);
    }

    public function previewStylesheetHref(string $themeId, string $appUrl): string
    {
        $this->getTheme($themeId);
        return rtrim($appUrl, '/') . '/themes/' . rawurlencode($themeId) . '/site.css';
    }

    private function themePath(string $themeId): string
    {
        return $this->themesPath . '/' . $themeId;
    }

    private function load(): array
    {
        if (!is_file($this->storePath)) {
            return ['theme' => $this->defaultTheme];
        }

        $data = require $this->storePath;
        return is_array($data) ? $data : ['theme' => $this->defaultTheme];
    }

    private function save(array $data): void
    {
        $payload = "<?php\nreturn " . var_export($data, true) . ";\n";
        Filesystem::atomicWrite($this->storePath, $payload);
    }
}
