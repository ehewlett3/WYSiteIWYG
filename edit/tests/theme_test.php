<?php
declare(strict_types=1);

use WYSiteIWYG\ThemeManager;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('THEME-4: legacy theme.php manifests are parsed as data, never executed', function (): void {
    $old = (string) shell_exec('cd ' . escapeshellarg(dirname(__DIR__, 2)) . ' && git show HEAD:edit/themes/vivid-nocturne/theme.php 2>/dev/null');
    if ($old !== '') {
        $parsed = ThemeManager::parsePhpArrayManifest($old);
        assert_same('Vivid Nocturne', $parsed['name']);
        assert_same('#7c3aed', $parsed['dashboard']['accent']);
        assert_same('"Syne", "Segoe UI", system-ui, sans-serif', $parsed['dashboard']['font-serif']);
    }
    assert_same(['a' => "it's", 'b' => [1, 2.5, true, null], 'c' => 'x\\y'], ThemeManager::parsePhpArrayManifest("<?php\nreturn ['a' => 'it\\'s', 'b' => [1, 2.5, true, null], 'c' => 'x\\\\y'];"));
    foreach ([
        "<?php system('id'); return [];",
        "<?php return ['a' => system('id')];",
        "<?php return ['a' => \$x];",
        "<?php return ['a' => 'b' . 'c'];",
        "<?php return ['a' => PHP_VERSION];",
        "<?php return array('a' => `id`);",
    ] as $evil) {
        assert_same([], ThemeManager::parsePhpArrayManifest($evil), $evil);
    }
});

test('THEME-4: themes load from theme.json and new themes are written as JSON', function (): void {
    $site = make_site();
    $ids = array_column($site['themes']->listThemes(), 'id');
    sort($ids);
    assert_same(['stjohn-inspired', 'verdant-sanctuary', 'vivid-nocturne', 'wysite-cathedral'], $ids);
    $id = $site['themes']->createTheme('My Custom');
    assert_true(is_file($site['edit'] . '/themes/' . $id . '/theme.json'));
    assert_same('My Custom', $site['themes']->getTheme($id)['name']);
});

test('THEME-1: customized variables are published into site.css; invalid values are dropped', function (): void {
    $site = make_site();
    $site['generator']->settings()->update(['theme_variables' => ['verdant-sanctuary' => ['site-accent' => '#ff0000', 'site-ink' => 'red;} body{display:none', 'site-bg' => '#edf3ed']]]);
    $site['generator']->applyTheme('verdant-sanctuary');
    $css = (string) file_get_contents($site['root'] . '/assets/site.css');
    assert_contains('--site-accent: #ff0000;', $css);
    assert_not_contains('display:none', $css);
    assert_not_contains('--site-bg:', substr($css, (int) strrpos($css, 'theme customizations')), 'defaults are not re-declared');
});

test('THEME-3: a theme without blog templates renders posts in its own chrome', function (): void {
    $site = make_site();
    $dir = $site['edit'] . '/themes/stjohn-inspired';
    unlink($dir . '/blog-post.html');
    unlink($dir . '/blog-index.html');
    $site['themes']->assertThemeIsValid('stjohn-inspired');
    $site['generator']->applyTheme('stjohn-inspired');
    $post = $site['generator']->createBlogPost('Hello', 'hello', 'x', '#blog');
    $html = (string) file_get_contents($site['root'] . '/' . $post);
    assert_contains('parish-masthead', $html);
    assert_not_contains('site-brand__icon', $html, 'no Cathedral markup');
    assert_contains('WYSITE:BEGIN name="blog-post-content"', $html);
    assert_same(0, substr_count($html, 'name="page-content"'));
    assert_contains('parish-masthead', (string) file_get_contents($site['root'] . '/blog/index.html'));
});

test('THEME-5: theme asset folders are published and referenced from /assets/theme/', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('stjohn-inspired');
    assert_true(is_file($site['root'] . '/assets/theme/stjohn-inspired/icxc.png'));
    $css = (string) file_get_contents($site['root'] . '/assets/site.css');
    assert_contains('url("theme/stjohn-inspired/icxc.png")', $css);
    assert_not_contains('edit/themes', $css);
});
