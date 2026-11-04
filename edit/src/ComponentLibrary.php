<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * EDT-D: reusable sections. "Saved sections" are stored per site in
 * edit/storage/components/<id>.json with the CSS that keeps their look;
 * "theme components" ship with a theme in edit/themes/<id>/components/*.html,
 * each starting with <!-- WYSITE:COMPONENT name="..." --> and styled by the
 * theme itself.
 */
final class ComponentLibrary
{
    private string $dir;
    private ThemeManager $themes;
    private ComponentStyles $styles;

    public function __construct(string $editPath, ThemeManager $themes, ComponentStyles $styles)
    {
        $this->dir = rtrim($editPath, '/') . '/storage/components';
        $this->themes = $themes;
        $this->styles = $styles;
    }

    /** @return array{theme: array<int, array>, saved: array<int, array>} */
    public function all(): array
    {
        $theme = [];
        try {
            $folder = $this->themes->themeDirectory($this->themes->currentThemeId()) . '/components';
            foreach (glob($folder . '/*.html') ?: [] as $file) {
                $html = (string) file_get_contents($file);
                $name = preg_match('/<!--\s*WYSITE:COMPONENT\s+name="([^"]+)"/', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES) : basename($file, '.html');
                $body = trim((string) preg_replace('/<!--\s*WYSITE:COMPONENT[^>]*-->/', '', $html));
                $theme[] = ['id' => 'theme:' . basename($file, '.html'), 'name' => $name, 'html' => Sanitizer::html($body), 'css' => ''];
            }
        } catch (RuntimeException) {
            // no current theme components
        }

        $saved = [];
        foreach (glob($this->dir . '/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data) && isset($data['id'], $data['html'])) {
                $saved[] = $data;
            }
        }
        usort($saved, static fn(array $a, array $b): int => strcasecmp((string) $a['name'], (string) $b['name']));

        return ['theme' => $theme, 'saved' => $saved];
    }

    /**
     * @param array $styles the section's captured look (computed styles and
     *                      matched rules per element), replayed on insert
     */
    public function save(string $name, string $html, array $styles, string $user, string $sourcePage): array
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '' || strlen($name) > 80) {
            throw new RuntimeException('Give the section a name (up to 80 characters).');
        }
        $html = Sanitizer::html($html);
        if (trim($html) === '') {
            throw new RuntimeException('The section is empty.');
        }
        $stylesJson = (string) json_encode($styles, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($stylesJson) > 524_288) {
            $styles = []; // too large to replay; the section adopts its new place's look
        }

        $id = 'cmp-' . bin2hex(random_bytes(5));
        $data = [
            'id' => $id, 'name' => $name, 'html' => $html, 'styles' => $styles,
            'source_page' => $sourcePage, 'theme_id' => $this->themes->currentThemeId(),
            'created_by' => $user, 'created_at' => gmdate('c'),
        ];
        Filesystem::atomicWrite($this->dir . '/' . $id . '.json', (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), 0600);

        return $data;
    }

    public function delete(string $id, string $user, bool $isAdmin): void
    {
        if (preg_match('/^cmp-[a-f0-9]{10}$/', $id) !== 1) {
            throw new RuntimeException('Unknown saved section.');
        }
        $file = $this->dir . '/' . $id . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            throw new RuntimeException('Unknown saved section.');
        }
        if (!$isAdmin && ($data['created_by'] ?? '') !== $user) {
            throw new RuntimeException('Only an administrator or the person who saved it can delete this section.');
        }
        @unlink($file);
    }
}
