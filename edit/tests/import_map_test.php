<?php
declare(strict_types=1);

use WYSiteIWYG\ContentCleaner;
use WYSiteIWYG\ExternalSiteImporter;
use WYSiteIWYG\ImportMap;
use WYSiteIWYG\ThemeImporter;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('THM-C: analyse, review, create theme, adopt menu and convert every page', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    $root = $site['root'];
    @mkdir($root . '/assets/imported/acme.example', 0775, true);
    file_put_contents($root . '/assets/imported/acme.example/style.css', 'body{color:#222}');
    $texts = ['about' => 'Founded in Leeds in 1921 by two cabinet makers who wanted to build furniture that lasts.', 'visit' => 'The showroom is open Monday to Saturday from nine until five; ring the bell at the side door.', 'team' => 'Twelve people work in the workshop, from apprentices to a master joiner with forty years at the bench.'];
    foreach ($texts as $slug => $text) {
        @mkdir($root . '/' . $slug, 0775, true);
        file_put_contents($root . '/' . $slug . '/index.html', '<!doctype html><html><head><title>' . ucfirst($slug) . ' | Acme</title><link rel="stylesheet" href="assets/imported/acme.example/style.css"></head><body>'
            . '<header><nav class="primary"><ul class="nav-list"><li class="current"><a href="about/">About</a></li><li><a href="visit/">Visit</a></li><li><a href="team/">Team</a></li></ul></nav></header>'
            . '<div class="layout"><div class="main-col"><h1>' . ucfirst($slug) . '</h1><div class="elementor-widget-container"><div class="elementor-text-editor"><p>' . $text . '</p></div></div></div></div>'
            . '<footer><p>Acme</p></footer></body></html>');
    }

    $map = new ImportMap($root, $site['edit'], $site['repository'], $site['generator'], new ExternalSiteImporter($root, $site['edit']));
    $analysis = $map->analyze();
    assert_same(3, $analysis['pages_total']);
    $cluster = $analysis['clusters'][0];
    assert_same('ul.nav-list', $cluster['roles']['main-menu']['selector']);

    // Reviewing: point the content region at the column (fix by hand).
    $updated = $map->updateRole($cluster['id'], 'page-content', 'div.main-col');
    assert_same(3, $updated['roles']['page-content']['matches']['1']);

    $themeId = (new ThemeImporter($root, new ExternalSiteImporter($root, $site['edit']), $site['themes']))
        ->importFromPages('Acme', $map->themeSources(), ['rights_confirmed' => true]);
    $site['generator']->applyTheme($themeId);
    $site['generator']->syncMenu($site['generator']->normalizeMenuHtml((string) $map->menuHtml()));
    $result = $map->convertPages(true);
    assert_same(3, $result['converted']);

    $about = (string) file_get_contents($root . '/about/index.html');
    assert_contains('WYSITE:BEGIN name="page-content"', $about);
    assert_contains('Founded in Leeds', $about);
    assert_not_contains('elementor-widget-container', $about, 'builder wrappers cleaned');
    assert_contains('<li><a href="about/">About</a></li>', str_replace(' aria-current="page"', '', $about), 'menu adopted without the current class');
    assert_contains('assets/theme/' . $themeId . '/', $about);
    assert_same(1, substr_count(strtolower($about), '<h1'), 'title printed once');
});

test('THM-E: content cleaner unwraps builder markup and keeps meaning', function (): void {
    $out = ContentCleaner::clean('<div class="elementor-section"><div class="elementor-column"><div class="elementor-widget-container"><p style="width:600px;color:red">Hi <b>there</b></p><img src="a.jpg" class="aligncenter" style="width: 300px"></div></div><div class="vc_row"></div></div><div id="keep" class="wrapper"><p>Kept wrapper</p></div><p> </p>');
    assert_same('<p style="color:red">Hi <b>there</b></p><img src="a.jpg" class="aligncenter"><div id="keep" class="wrapper"><p>Kept wrapper</p></div>', $out);
});
