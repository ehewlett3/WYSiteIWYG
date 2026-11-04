<?php
declare(strict_types=1);

use WYSiteIWYG\ComponentLibrary;
use WYSiteIWYG\ComponentStyles;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('EDT-C: section CSS may only style the copied section', function (): void {
    $site = make_site('/sub/');
    $styles = new ComponentStyles($site['root'], '/sub/');
    $ok = $styles->validate('wy-c-abc123', ".wy-c-abc123-0 { padding: 24px; background: url(\"/sub/assets/imported/x/bg.png\") }\n@media (min-width: 700px) { .wy-c-abc123-0 { padding: 40px; } }\n.wy-c-abc123-1.wy-c-abc123-2 { color: red }");
    assert_contains('@media (min-width: 700px)', $ok);
    assert_contains('url("imported/x/bg.png")', $ok, 'site URLs become relative to /assets/');
    foreach ([
        'body { color: red }',
        '.wy-c-abc123-0, .other { color: red }',
        '.wy-c-zzz999-0 { color: red }',
        '@font-face { font-family: x; src: url(x.woff) }',
        '.wy-c-abc123-0 { color: red } } body { display: none',
        '@media screen { body { display: none } }',
    ] as $css) {
        assert_throws(static fn() => $styles->validate('wy-c-abc123', $css), '', RuntimeException::class);
    }
    assert_not_contains('evil', $styles->validate('wy-c-abc123', '.wy-c-abc123-0 { background: url(https://evil.example/x.png) }'));
    assert_throws(static fn() => $styles->validate('../x', '.a{}'), 'Invalid');
});

test('EDT-C: saving links the stylesheet into the page and unused groups are collected', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    $page = $site['generator']->createPage('Cards', 'cards');
    $file = $site['root'] . '/' . $page;
    $styles = new ComponentStyles($site['root']);
    $styles->save('wy-c-abc123', '.wy-c-abc123-0 { padding: 4px }');
    $styles->save('wy-c-def456', '.wy-c-def456-0 { padding: 8px }');
    $styles->ensureLinked($file);
    assert_contains('<link rel="stylesheet" href="assets/wysite-components.css">', (string) file_get_contents($file));
    $styles->ensureLinked($file);
    assert_same(1, substr_count((string) file_get_contents($file), 'wysite-components.css'));

    $site['repository']->updateBlock($page, 'page-content', '<div class="card wy-c-abc123 wy-c-abc123-0">x</div>');
    assert_same(['wy-c-def456'], $styles->garbageCollect([$file], true));
    $css = (string) file_get_contents($site['root'] . '/assets/wysite-components.css');
    assert_contains('wy-c-abc123', $css);
    assert_not_contains('wy-c-def456', $css);

    // A rebuilt page keeps the link through {{HEAD_META}}.
    $site['generator']->rebuildPage($page);
    assert_contains('wysite-components.css', (string) file_get_contents($file));
});

test('EDT-D: saved sections are listed, sanitized, and deletable by their owner or an admin', function (): void {
    $site = make_site();
    $library = new ComponentLibrary($site['edit'], $site['themes'], new ComponentStyles($site['root']));
    $saved = $library->save('Feature card', '<div class="card"><h3>T</h3><img src=x onerror=alert(1)></div>', [['computed' => ['display' => 'block'], 'rules' => []]], 'alice', 'about/index.html');
    assert_not_contains('onerror', $saved['html']);
    $all = $library->all();
    assert_same('Feature card', $all['saved'][0]['name']);
    assert_same('block', $all['saved'][0]['styles'][0]['computed']['display']);
    assert_throws(static fn() => $library->delete($saved['id'], 'bob', false), 'Only an administrator');
    $library->delete($saved['id'], 'bob', true);
    assert_same([], $library->all()['saved']);
});

test('EDT-D: theme components ship with the theme', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    @mkdir($site['edit'] . '/themes/verdant-sanctuary/components', 0775, true);
    file_put_contents($site['edit'] . '/themes/verdant-sanctuary/components/cta.html', '<!-- WYSITE:COMPONENT name="Call to action" --><div class="content-block"><p>Join us</p></div>');
    $theme = (new ComponentLibrary($site['edit'], $site['themes'], new ComponentStyles($site['root'])))->all()['theme'];
    assert_true(in_array('Call to action', array_column($theme, 'name'), true));
});
