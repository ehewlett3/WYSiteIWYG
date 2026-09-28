<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

final class ThemeManager
{
    private const TEMPLATE_FILES = [
        'home' => 'home.html',
        'page' => 'page.html',
        'blog-post' => 'blog-post.html',
        'blog' => 'blog-index.html',
    ];

    /** Optional kinds and the kind each falls back to within the same theme. */
    private const TEMPLATE_FALLBACKS = [
        'home' => 'page',
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
        '{{SITE_NAME}}',
        '{{SITE_TAGLINE}}',
        '{{SITE_LANG}}',
        '{{SITE_LOGO}}',
        '{{FOOTER}}',
        '{{HEAD_META}}',
        '{{DATE_HUMAN}}',
        '{{AUTHOR}}',
        '{{PAGINATION}}',
    ];

    private const TEMPLATE_REQUIREMENTS = [
        'home' => [
            'meta_kind' => 'home',
            'tokens' => ['{{TITLE}}', '{{EXCERPT}}', '{{THEME_CSS_HREF}}', '{{WYSITE_PUBLIC_BRIDGE}}'],
            'blocks' => [
                ['name' => 'main-menu', 'token' => '{{MAIN_MENU}}'],
            ],
        ],
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

    /** @var array|null Parsed theme list, cached for the request. */
    private ?array $themeListCache = null;

    public function listThemes(): array
    {
        if ($this->themeListCache !== null) {
            return $this->themeListCache;
        }

        $themes = [];
        $dirs = array_unique(array_map(
            static fn(string $file): string => dirname($file),
            array_merge(glob($this->themesPath . '/*/theme.json') ?: [], glob($this->themesPath . '/*/theme.php') ?: [])
        ));
        foreach ($dirs as $themePath) {
            $id = basename(str_replace('\\', '/', $themePath));
            if (preg_match('/^[a-z0-9-]+$/', $id) !== 1) {
                continue;
            }
            $meta = $this->themeMeta($id);
            if ($meta === []) {
                continue;
            }

            $themes[] = [
                'id' => $id,
                'name' => $meta['name'] ?? $id,
                'description' => $meta['description'] ?? '',
                'inspiration' => $meta['inspiration'] ?? '',
                'preview_blurb' => $meta['preview_blurb'] ?? '',
                'has_dashboard' => isset($meta['dashboard']) && is_array($meta['dashboard']),
                'variables' => $this->normalizeVariables($meta['variables'] ?? []),
            ];
        }

        usort(
            $themes,
            static fn(array $left, array $right): int => strcmp($left['name'], $right['name'])
        );

        return $this->themeListCache = $themes;
    }

    /**
     * Customizable CSS variables a theme declares (THEME-1):
     * [{name, label, type: color|font|size|text, default}].
     */
    public function variables(string $themeId): array
    {
        return $this->normalizeVariables($this->themeMeta($themeId)['variables'] ?? []);
    }

    private function normalizeVariables(mixed $variables): array
    {
        $out = [];
        foreach (is_array($variables) ? $variables : [] as $variable) {
            if (!is_array($variable) || preg_match('/^[a-z0-9-]+$/', (string) ($variable['name'] ?? '')) !== 1) {
                continue;
            }
            $type = in_array($variable['type'] ?? '', ['color', 'font', 'size', 'text'], true) ? $variable['type'] : 'text';
            $out[] = [
                'name' => (string) $variable['name'],
                'label' => (string) ($variable['label'] ?? $variable['name']),
                'type' => $type,
                'default' => (string) ($variable['default'] ?? ''),
            ];
        }

        return $out;
    }

    /** Validate one customized value for a variable type; returns '' when invalid. */
    public static function cleanVariableValue(string $type, string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/[{};<>\\\\]|\/\*|url\s*\(|expression|@import/i', $value) === 1) {
            return '';
        }

        return match ($type) {
            'color' => preg_match('/^(#[0-9a-f]{3,8}|(rgb|hsl)a?\([0-9.,%\s\/deg]+\)|[a-z]+)$/i', $value) === 1 ? $value : '',
            'size' => preg_match('/^-?\d+(\.\d+)?(px|rem|em|%|vw|vh|ch)?$/', $value) === 1 ? $value : '',
            'font' => preg_match('/^[A-Za-z0-9 ,"\'\-]+$/', $value) === 1 ? $value : '',
            default => preg_match('/^[A-Za-z0-9 #%.,()"\'\-\/]+$/', $value) === 1 ? $value : '',
        };
    }

    /** CSS that applies the site's customized values for $themeId, or ''. */
    public function variableCss(string $themeId, array $values): string
    {
        $declarations = [];
        foreach ($this->variables($themeId) as $variable) {
            $value = self::cleanVariableValue($variable['type'], (string) ($values[$variable['name']] ?? ''));
            if ($value !== '' && $value !== $variable['default']) {
                $declarations[] = '  --' . $variable['name'] . ': ' . $value . ';';
            }
        }

        return $declarations === [] ? '' : "\n/* Site settings: theme customizations */\n:root {\n" . implode("\n", $declarations) . "\n}\n";
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

    /**
     * A theme's manifest. theme.json is preferred; a legacy theme.php is parsed as
     * data (never executed), so a downloaded theme can't run code (THEME-4).
     */
    private function themeMeta(string $id): array
    {
        $json = $this->themePath($id) . '/theme.json';
        if (is_file($json)) {
            $meta = json_decode((string) file_get_contents($json), true);
            return is_array($meta) ? $meta : [];
        }

        $php = $this->themePath($id) . '/theme.php';
        return is_file($php) ? self::parsePhpArrayManifest((string) file_get_contents($php)) : [];
    }

    /**
     * Read `<?php return [ ... ];` containing only string/number/bool/null keys
     * and values and nested arrays. Anything else (function calls, variables,
     * constants, concatenation...) makes the manifest invalid.
     */
    public static function parsePhpArrayManifest(string $source): array
    {
        if (!function_exists('token_get_all')) {
            return [];
        }

        $tokens = array_values(array_filter(
            token_get_all($source),
            static fn($token): bool => !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_CLOSE_TAG], true)
        ));
        $pos = 0;
        $next = static function () use (&$tokens, &$pos) {
            return $tokens[$pos++] ?? null;
        };
        $peek = static function () use (&$tokens, &$pos) {
            return $tokens[$pos] ?? null;
        };

        $first = $next();
        if (!is_array($first) || $first[0] !== T_RETURN) {
            return [];
        }

        $parseValue = null;
        $parseValue = static function () use (&$parseValue, $next, $peek): mixed {
            $token = $next();
            if ($token === '[' || (is_array($token) && $token[0] === T_ARRAY && $next() === '(')) {
                $close = $token === '[' ? ']' : ')';
                $array = [];
                while (true) {
                    if ($peek() === $close) {
                        $next();
                        return $array;
                    }
                    $value = $parseValue();
                    if ($peek() === null) {
                        throw new RuntimeException('Unterminated array.');
                    }
                    if (is_array($peek()) && $peek()[0] === T_DOUBLE_ARROW) {
                        $next();
                        if (!is_string($value) && !is_int($value)) {
                            throw new RuntimeException('Invalid key.');
                        }
                        $array[$value] = $parseValue();
                    } else {
                        $array[] = $value;
                    }
                    if ($peek() === ',') {
                        $next();
                    } elseif ($peek() !== $close) {
                        throw new RuntimeException('Unexpected token.');
                    }
                }
            }
            if (is_array($token)) {
                switch ($token[0]) {
                    case T_CONSTANT_ENCAPSED_STRING:
                        $raw = $token[1];
                        return $raw[0] === "'"
                            ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($raw, 1, -1))
                            : stripcslashes(substr($raw, 1, -1));
                    case T_LNUMBER:
                        return (int) $token[1];
                    case T_DNUMBER:
                        return (float) $token[1];
                    case T_STRING:
                        $word = strtolower($token[1]);
                        if (in_array($word, ['true', 'false', 'null'], true)) {
                            return $word === 'null' ? null : $word === 'true';
                        }
                }
            }
            throw new RuntimeException('Theme manifests may only contain plain values.');
        };

        try {
            $value = $parseValue();
            return is_array($value) && in_array($peek(), [';', null], true) ? $value : [];
        } catch (RuntimeException) {
            return [];
        }
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
        Filesystem::atomicWrite($dir . '/theme.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        $this->themeListCache = null;
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
        $this->themeListCache = null;

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

    /** Whether the theme ships its own template file for $kind. */
    public function hasTemplate(string $themeId, string $kind): bool
    {
        $filename = self::TEMPLATE_FILES[$kind] ?? null;
        return $filename !== null && is_file($this->themePath($themeId) . '/' . $filename);
    }

    public function loadTemplate(string $themeId, string $kind): string
    {
        $filename = self::TEMPLATE_FILES[$kind] ?? null;
        if ($filename === null) {
            throw new RuntimeException('Unknown theme template kind: ' . $kind);
        }

        $path = $this->themePath($themeId) . '/' . $filename;
        if (!is_file($path) && isset(self::TEMPLATE_FALLBACKS[$kind])) {
            return $this->loadTemplate($themeId, self::TEMPLATE_FALLBACKS[$kind]);
        }

        if (!is_file($path)) {
            // A theme may omit blog templates. Build them from the theme's own page
            // template (THEME-3) so posts wear this theme's chrome, not another's.
            if (in_array($kind, ['blog-post', 'blog'], true) && is_file($this->themePath($themeId) . '/page.html')) {
                return self::synthesizeBlogTemplate((string) file_get_contents($this->themePath($themeId) . '/page.html'), $kind);
            }

            throw new RuntimeException('Missing theme template: ' . $filename);
        }

        return (string) file_get_contents($path);
    }

    /**
     * Derive a blog-post or blog-index template from a page template: same
     * header, menu, and footer, with the first content slot holding the post body
     * (or the archive intro and post list) and the other slots removed.
     */
    public static function synthesizeBlogTemplate(string $pageTemplate, string $kind): string
    {
        $meta = $kind === 'blog-post'
            ? ' WYSITE:META title="{{TITLE}}" kind="blog-post" date="{{DATE}}" excerpt="{{EXCERPT}}" hashtags="{{HASHTAGS}}" '
            : ' WYSITE:META title="{{TITLE}}" kind="blog" excerpt="{{EXCERPT}}" tag="{{TAG}}" generated="tag-index" ';
        $template = preg_replace('/<!--\s*WYSITE:META\b.*?-->/s', '<!--' . $meta . '-->', $pageTemplate, 1) ?? $pageTemplate;

        $body = $kind === 'blog-post'
            ? '<p class="post-meta"><time datetime="{{DATE}}">{{DATE_HUMAN}}</time></p>' . "\n"
                . '<!-- WYSITE:BEGIN name="blog-post-content" type="blog-post" label="Blog Post Content" -->' . "\n{{BLOG_POST_CONTENT}}\n"
                . '<!-- WYSITE:END name="blog-post-content" -->' . "\n{{TAG_LINKS}}"
            : '<!-- WYSITE:BEGIN name="blog-index-content" type="blog" label="Blog Index Intro" -->' . "\n{{BLOG_INDEX_CONTENT}}\n"
                . '<!-- WYSITE:END name="blog-index-content" -->' . "\n" . '<div class="blog-roll">{{BLOG_ITEMS}}</div>';

        // A slot may already be wrapped in page-content markers (promoted templates).
        $slot = '(?:<!--\s*WYSITE:BEGIN\s+name="page-content"[^>]*-->\s*)?\{\{PAGE_CONTENT_BLOCKS?\}\}(?:\s*<!--\s*WYSITE:END\s+name="page-content"\s*-->)?';
        $replaced = false;
        $template = preg_replace_callback('/' . $slot . '/', static function () use (&$replaced, $body): string {
            if ($replaced) {
                return '';
            }
            $replaced = true;
            return $body;
        }, $template) ?? $template;

        return $template;
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

        if (in_array($requirements['meta_kind'] ?? '', ['page', 'home'], true) && !$this->hasPageContentToken($template)) {
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
        Filesystem::withLock($this->statePath, function () use ($key, $value): void {
            $state = $this->loadState();
            if ($value === '') {
                unset($state[$key]);
            } else {
                $state[$key] = $value;
            }

            $payload = "<?php\nreturn " . var_export($state, true) . ";\n";
            Filesystem::atomicWrite($this->statePath, $payload);
        });
    }
}
