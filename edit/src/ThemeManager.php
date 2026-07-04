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

    private string $configPath;
    private string $statePath;
    private string $themesPath;
    private string $defaultTheme = 'wysite-cathedral';

    /**
     * @param string $configPath Shipped defaults (tracked in git); read-only here.
     * @param string $statePath  Per-install runtime selections (git-ignored); the
     *                           active theme, dashboard theme, and build target are
     *                           written here so a commit never carries a live choice.
     */
    public function __construct(string $configPath, string $themesPath, string $statePath)
    {
        $this->configPath = $configPath;
        $this->statePath = $statePath;
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
                'has_dashboard' => isset($meta['dashboard']) && is_array($meta['dashboard']),
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
        $themeId = $this->value('theme', $this->defaultTheme);

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
        $this->setStateKey('theme', $id);
    }

    /**
     * The theme whose palette styles the editor dashboard, or '' for the built-in
     * default. This is independent of the active *site* theme (currentThemeId()).
     */
    public function dashboardThemeId(): string
    {
        $id = $this->value('dashboard_theme', '');
        if ($id === '') {
            return '';
        }

        try {
            $this->getTheme($id);
            return $id;
        } catch (RuntimeException) {
            return '';
        }
    }

    public function setDashboardTheme(string $id): void
    {
        if ($id === '' || $id === 'default') {
            $this->setStateKey('dashboard_theme', '');
            return;
        }

        $this->getTheme($id);
        $this->setStateKey('dashboard_theme', $id);
    }

    public function dashboardPalette(string $id): array
    {
        $palette = $this->themeMeta($id)['dashboard'] ?? null;
        return is_array($palette) ? $palette : [];
    }

    /**
     * CSS for the selected dashboard theme as a :root{} override of editor.css
     * custom properties, or '' when the built-in default is in effect.
     */
    public function dashboardCssVariables(): string
    {
        $id = $this->dashboardThemeId();
        if ($id === '') {
            return '';
        }

        $declarations = [];
        foreach ($this->dashboardPalette($id) as $key => $value) {
            $name = preg_replace('/[^a-z0-9-]/i', '', (string) $key);
            $clean = trim(str_replace(['{', '}', '<', '>'], '', (string) $value));
            if ($name === '' || $clean === '') {
                continue;
            }
            $declarations[] = '--wysite-' . $name . ': ' . $clean . ';';
        }

        return $declarations === [] ? '' : ':root{' . implode('', $declarations) . '}';
    }

    private function themeMeta(string $id): array
    {
        $file = $this->themePath($id) . '/theme.php';
        if (!is_file($file)) {
            return [];
        }

        $meta = require $file;
        return is_array($meta) ? $meta : [];
    }

    /**
     * Create a new, empty theme to build from imported pages: a directory with
     * theme.php and a minimal site.css. Templates are added later by promoting
     * imported pages into it. Returns the new theme id (slug).
     */
    public function createTheme(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new RuntimeException('Provide a theme name.');
        }

        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim((string) preg_replace('/-+/', '-', $slug), '-');
        if ($slug === '') {
            throw new RuntimeException('Provide a theme name with letters or numbers.');
        }

        $dir = $this->themePath($slug);
        if (is_dir($dir)) {
            throw new RuntimeException('A theme with the id "' . $slug . '" already exists.');
        }

        Filesystem::ensureDirectory($dir);
        $meta = [
            'name' => $name,
            'description' => 'Custom theme built from imported pages.',
            'inspiration' => '',
            'preview_blurb' => '',
        ];
        Filesystem::atomicWrite($dir . '/theme.php', "<?php\nreturn " . var_export($meta, true) . ";\n");
        Filesystem::atomicWrite(
            $dir . '/site.css',
            "/* " . $name . " — minimal theme stylesheet. Imported pages link their own CSS. */\n"
        );

        return $slug;
    }

    /** The theme currently selected as the template build target, or '' if none. */
    public function builderThemeId(): string
    {
        $id = $this->value('builder_theme', '');
        if ($id === '') {
            return '';
        }

        try {
            $this->getTheme($id);
            return $id;
        } catch (RuntimeException) {
            return '';
        }
    }

    public function setBuilderTheme(string $id): void
    {
        if ($id === '') {
            $this->setStateKey('builder_theme', '');
            return;
        }

        $this->getTheme($id);
        $this->setStateKey('builder_theme', $id);
    }

    public function defaultThemeId(): string
    {
        return $this->defaultTheme;
    }

    /**
     * Delete a theme package from disk. The active theme and the built-in default
     * fallback are protected (deleting them would break rendering). Any runtime
     * references (build target, dashboard appearance) pointing at it are cleared.
     */
    public function deleteTheme(string $id): void
    {
        if (preg_match('/^[a-z0-9-]+$/', $id) !== 1) {
            throw new RuntimeException('Invalid theme id.');
        }

        $dir = $this->themePath($id);
        if (!is_dir($dir)) {
            throw new RuntimeException('Unknown theme: ' . $id);
        }
        if ($id === $this->defaultTheme) {
            throw new RuntimeException('The built-in default theme cannot be deleted.');
        }
        if ($id === $this->currentThemeId()) {
            throw new RuntimeException('You cannot delete the active theme. Apply a different theme first.');
        }

        $this->deleteDirectory($dir);

        if ($this->value('builder_theme', '') === $id) {
            $this->setStateKey('builder_theme', '');
        }
        if ($this->value('dashboard_theme', '') === $id) {
            $this->setStateKey('dashboard_theme', '');
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        if (!@rmdir($dir)) {
            throw new RuntimeException('Could not fully remove the theme directory. Check file permissions.');
        }
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
                // A theme must at least define a page template. Blog index/post
                // templates are optional — sites without a blog don't need them, and
                // those kinds fall back to the default theme if ever rendered.
                if ($kind === 'page') {
                    $errors[] = $filename . ' is missing (a theme must at least define a page template).';
                }
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
            // A theme may omit blog/blog-post templates. Fall back to the built-in
            // default theme so those kinds still render if the site has them.
            $fallback = $this->themePath($this->defaultTheme) . '/' . $filename;
            if ($themeId !== $this->defaultTheme && is_file($fallback)) {
                return (string) file_get_contents($fallback);
            }

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

        // Note: we deliberately do NOT compare raw {{ vs }} counts — imported pages
        // legitimately contain stray braces (inline JS/JSON/CSS). Unknown {{TOKEN}}
        // placeholders are still caught by unknownTemplateTokens() below.

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

    /** Shipped defaults (config.php). Read-only; the app never writes this file. */
    private function loadConfig(): array
    {
        if (!is_file($this->configPath)) {
            return ['theme' => $this->defaultTheme];
        }

        $data = require $this->configPath;
        return is_array($data) ? $data : ['theme' => $this->defaultTheme];
    }

    /** Per-install runtime selections (state.local.php). */
    private function loadState(): array
    {
        if (!is_file($this->statePath)) {
            return [];
        }

        $data = require $this->statePath;
        return is_array($data) ? $data : [];
    }

    /**
     * Resolve a setting: a runtime selection wins, then the shipped default, then
     * the given fallback. This also means an existing config.php that still holds a
     * runtime value keeps working until the setting is next changed.
     */
    private function value(string $key, string $default): string
    {
        $state = $this->loadState();
        if (array_key_exists($key, $state) && (string) $state[$key] !== '') {
            return (string) $state[$key];
        }

        $config = $this->loadConfig();
        if (array_key_exists($key, $config) && (string) $config[$key] !== '') {
            return (string) $config[$key];
        }

        return $default;
    }

    /** Set (or, with an empty value, clear) a runtime selection in state.local.php. */
    private function setStateKey(string $key, string $value): void
    {
        $state = $this->loadState();
        if ($value === '') {
            unset($state[$key]);
        } else {
            $state[$key] = $value;
        }

        $payload = "<?php\nreturn " . var_export($state, true) . ";\n";
        Filesystem::atomicWrite($this->statePath, $payload);
    }
}
