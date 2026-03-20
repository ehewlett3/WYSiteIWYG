<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

final class ThemeManager
{
    private const TEMPLATE_FILES = [
        'page' => 'page.html',
        'blog-post' => 'blog-post.html',
        'blog' => 'blog-index.html',
    ];

    private const SUPPORTED_TOKENS = [
        '{{THEME_CSS_HREF}}',
        '{{TITLE}}',
        '{{EXCERPT}}',
        '{{DATE}}',
        '{{BODY_CLASS}}',
        '{{MAIN_MENU}}',
        '{{PAGE_CONTENT_BLOCK}}',
        '{{PAGE_CONTENT_BLOCKS}}',
        '{{PAGE_CONTENT}}',
        '{{PAGE_CONTENT_2}}',
        '{{PAGE_CONTENT_3}}',
        '{{BLOG_POST_CONTENT}}',
        '{{BLOG_INDEX_CONTENT}}',
        '{{BLOG_ITEMS}}',
        '{{HASHTAGS}}',
        '{{TAG_LINKS}}',
        '{{TAG}}',
        '{{TAG_LABEL}}',
        '{{WYSITE_PUBLIC_BRIDGE}}',
    ];

    private const TEMPLATE_REQUIREMENTS = [
        'page' => [
            'meta_kind' => 'page',
            'tokens' => ['{{TITLE}}', '{{EXCERPT}}', '{{THEME_CSS_HREF}}', '{{WYSITE_PUBLIC_BRIDGE}}'],
            'blocks' => [
                ['name' => 'main-menu', 'token' => '{{MAIN_MENU}}'],
            ],
        ],
        'blog-post' => [
            'meta_kind' => 'blog-post',
            'tokens' => ['{{TITLE}}', '{{EXCERPT}}', '{{DATE}}', '{{HASHTAGS}}', '{{THEME_CSS_HREF}}', '{{WYSITE_PUBLIC_BRIDGE}}', '{{TAG_LINKS}}'],
            'blocks' => [
                ['name' => 'main-menu', 'token' => '{{MAIN_MENU}}'],
                ['name' => 'blog-post-content', 'token' => '{{BLOG_POST_CONTENT}}'],
            ],
        ],
        'blog' => [
            'meta_kind' => 'blog',
            'tokens' => ['{{TITLE}}', '{{EXCERPT}}', '{{TAG}}', '{{THEME_CSS_HREF}}', '{{WYSITE_PUBLIC_BRIDGE}}', '{{BLOG_ITEMS}}'],
            'blocks' => [
                ['name' => 'main-menu', 'token' => '{{MAIN_MENU}}'],
                ['name' => 'blog-index-content', 'token' => '{{BLOG_INDEX_CONTENT}}'],
            ],
        ],
    ];

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

    public function assertThemeIsValid(string $id): void
    {
        $theme = $this->getTheme($id);
        $errors = [];

        if (!is_file($this->themePath($id) . '/site.css')) {
            $errors[] = 'site.css is missing.';
        }

        foreach (self::TEMPLATE_REQUIREMENTS as $kind => $requirements) {
            $filename = self::TEMPLATE_FILES[$kind];
            $path = $this->themePath($id) . '/' . $filename;
            if (!is_file($path)) {
                $errors[] = $filename . ' is missing.';
                continue;
            }

            $template = (string) file_get_contents($path);
            foreach ($this->validateTemplate($filename, $template, $requirements) as $error) {
                $errors[] = $error;
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                'Theme "' . ($theme['name'] ?? $id) . "\" is invalid.\n" .
                implode("\n", array_map(static fn(string $error): string => '- ' . $error, $errors))
            );
        }
    }

    public function loadTemplate(string $themeId, string $kind): string
    {
        $filename = self::TEMPLATE_FILES[$kind] ?? null;
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

    private function validateTemplate(string $filename, string $template, array $requirements): array
    {
        $errors = [];

        if (substr_count($template, '{{') !== substr_count($template, '}}')) {
            $errors[] = $filename . ' has unmatched {{...}} placeholder delimiters.';
        }

        if (!$this->hasMetaKind($template, (string) $requirements['meta_kind'])) {
            $errors[] = $filename . ' must include a WYSITE:META comment with kind="' . $requirements['meta_kind'] . '".';
        }

        foreach ($requirements['tokens'] as $token) {
            if (!str_contains($template, $token)) {
                $errors[] = $filename . ' is missing required token ' . $token . '.';
            }
        }

        if (($requirements['meta_kind'] ?? '') === 'page' && !$this->hasPageContentToken($template)) {
            $errors[] = $filename . ' must include {{PAGE_CONTENT_BLOCK}} or {{PAGE_CONTENT_BLOCKS}}.';
        }

        foreach ($requirements['blocks'] as $block) {
            if (!$this->hasEditableBlock($template, (string) $block['name'], (string) $block['token'])) {
                $errors[] = $filename . ' must wrap ' . $block['token'] . ' in a matching ' . $block['name'] . ' WYSITE block.';
            }
        }

        foreach ($this->unknownTemplateTokens($template) as $token) {
            $errors[] = $filename . ' contains unsupported placeholder ' . $token . '.';
        }

        foreach ($this->markerPairErrors($template) as $error) {
            $errors[] = $filename . ' ' . $error;
        }

        return $errors;
    }

    private function hasMetaKind(string $template, string $kind): bool
    {
        return preg_match('/<!--\s*WYSITE:META\b(?=[^>]*\bkind="' . preg_quote($kind, '/') . '")[^>]*-->/si', $template) === 1;
    }

    private function hasEditableBlock(string $template, string $blockName, string $token): bool
    {
        $pattern = '/<!--\s*WYSITE:BEGIN\b(?=[^>]*\bname="' . preg_quote($blockName, '/') . '")[^>]*-->' .
            '.*?' . preg_quote($token, '/') .
            '.*?<!--\s*WYSITE:END\s+name="' . preg_quote($blockName, '/') . '"\s*-->/si';

        return preg_match($pattern, $template) === 1;
    }

    private function hasPageContentToken(string $template): bool
    {
        return str_contains($template, '{{PAGE_CONTENT_BLOCK}}')
            || str_contains($template, '{{PAGE_CONTENT_BLOCKS}}');
    }

    private function markerPairErrors(string $template): array
    {
        preg_match_all('/<!--\s*WYSITE:BEGIN(?P<attrs>.*?)-->/si', $template, $beginMatches, PREG_SET_ORDER);
        preg_match_all('/<!--\s*WYSITE:END\s+name="(?P<name>[^"]+)"\s*-->/si', $template, $endMatches, PREG_SET_ORDER);

        $beginNames = [];
        foreach ($beginMatches as $match) {
            $attrs = $this->parseAttributes($match['attrs'] ?? '');
            $name = trim((string) ($attrs['name'] ?? ''));
            if ($name === '') {
                return ['contains a WYSITE:BEGIN marker without a name attribute.'];
            }

            $beginNames[] = $name;
        }

        $endNames = array_map(
            static fn(array $match): string => trim((string) ($match['name'] ?? '')),
            $endMatches
        );

        if (count($beginNames) !== count($endNames)) {
            return ['has mismatched counts of WYSITE:BEGIN and WYSITE:END markers.'];
        }

        $errors = [];
        foreach ($beginNames as $index => $beginName) {
            if ($beginName !== $endNames[$index]) {
                $errors[] = 'has mismatched WYSITE block markers for "' . $beginName . '" and "' . $endNames[$index] . '".';
            }
        }

        return $errors;
    }

    private function unknownTemplateTokens(string $template): array
    {
        preg_match_all('/{{\s*[^}]+\s*}}/', $template, $matches);
        $tokens = array_values(array_unique(array_map('trim', $matches[0] ?? [])));

        return array_values(
            array_filter(
                $tokens,
                static fn(string $token): bool => !in_array($token, self::SUPPORTED_TOKENS, true)
            )
        );
    }

    private function parseAttributes(string $input): array
    {
        $attributes = [];
        preg_match_all('/([a-zA-Z0-9_-]+)\s*=\s*"([^"]*)"/', $input, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attributes[$match[1]] = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5);
        }

        return $attributes;
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
