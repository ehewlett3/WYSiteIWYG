<?php
declare(strict_types=1);

use WYSiteIWYG\ExternalSiteImporter;
use WYSiteIWYG\SiteStructureAnalyzer;
use WYSiteIWYG\ThemeImporter;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

function theme_import_page(string $title, string $body): string
{
    return '<!doctype html><html><head><title>' . $title . ' | Acme</title>'
        . '<link rel="stylesheet" href="assets/imported/acme.example/wp-content/themes/acme/style.css">'
        . '<link rel="icon" href="assets/imported/acme.example/favicon.png"><link rel="canonical" href="https://acme.example/x/"></head><body>'
        . '<header class="site-header"><img class="logo" src="assets/imported/acme.example/wp-content/uploads/logo.png" alt="Acme">'
        . '<nav class="main-nav"><ul class="menu"><li><a href="about/">About</a></li><li><a href="visit/">Visit</a></li><li><a href="news/">News</a></li></ul></nav></header>'
        . '<main class="site-main"><h1 class="entry-title">' . $title . '</h1><div class="entry-content">' . $body . '<img src="assets/imported/acme.example/wp-content/uploads/content.jpg"></div></main>'
        . '<footer class="site-footer"><p>Acme Ltd</p></footer></body></html>';
}

test('THM-D: a crawled site becomes a self-contained theme that renders without the source', function (): void {
    $site = make_site();
    $root = $site['root'];
    $put = static function (string $path, string $contents) use ($root): void {
        @mkdir(dirname($root . '/' . $path), 0775, true);
        file_put_contents($root . '/' . $path, $contents);
    };
    $put('assets/imported/acme.example/wp-content/themes/acme/style.css', ':root{--acme-accent:#c0392b;--acme-font:Georgia, serif}@import "parts/extra.css";@font-face{font-family:A;src:url(fonts/a.woff2)}body{background:url(../../uploads/bg.png)}');
    $put('assets/imported/acme.example/wp-content/themes/acme/parts/extra.css', '.x{color:red}');
    $put('assets/imported/acme.example/wp-content/themes/acme/fonts/a.woff2', 'FONT');
    $put('assets/imported/acme.example/wp-content/uploads/bg.png', 'PNG-BG');
    $put('assets/imported/acme.example/wp-content/uploads/logo.png', 'PNG-LOGO');
    $put('assets/imported/acme.example/wp-content/uploads/content.jpg', 'JPG');
    $put('assets/imported/acme.example/favicon.png', 'ICO');
    $pages = [];
    foreach (['about' => 'We make things in Leeds since 1921, with a small team of craftspeople.', 'visit' => 'The workshop is open on weekdays from nine; book a tour by phone.', 'news' => 'This spring we opened a second workshop near the station.'] as $slug => $text) {
        $pages[$slug . '/index.html'] = theme_import_page(ucfirst($slug), '<p>' . $text . '</p>');
        $put($slug . '/index.html', $pages[$slug . '/index.html']);
    }

    $cluster = (new SiteStructureAnalyzer())->analyze($pages)['clusters'][0];
    $regions = ['main-menu' => $cluster['roles']['main-menu']['selector'], 'page-content' => $cluster['roles']['page-content']['selector']];
    assert_same('ul.menu', $regions['main-menu']);
    assert_same('div.entry-content', $regions['page-content']);

    $importer = new ExternalSiteImporter($root, $site['edit']);
    $themeImporter = new ThemeImporter($root, $importer, $site['themes']);
    $id = $themeImporter->importFromPages('Acme', ['page' => [
        'html' => $pages[$cluster['representative']], 'base' => '', 'local' => true, 'regions' => $regions, 'title' => $cluster['roles']['title']['selector'],
    ]], ['rights_confirmed' => true, 'source_url' => 'https://acme.example/']);

    $template = (string) file_get_contents($site['edit'] . '/themes/' . $id . '/page.html');
    assert_contains('{{THEME_ASSET_BASE}}acme.example/wp-content/themes/acme/style.css', $template);
    assert_contains('{{THEME_ASSET_BASE}}acme.example/wp-content/uploads/logo.png', $template);
    assert_contains('<h1 class="entry-title">{{TITLE}}</h1>', $template);
    assert_not_contains('assets/imported', $template, 'no references into the crawled copy');
    assert_not_contains('acme.example/x/', $template, 'canonical link to the old site dropped');
    assert_not_contains('content.jpg', $template, 'content images stay with the page, not the theme');

    $assets = $site['edit'] . '/themes/' . $id . '/assets/acme.example/wp-content/';
    assert_same('PNG-BG', file_get_contents($assets . 'uploads/bg.png'));
    assert_same('FONT', file_get_contents($assets . 'themes/acme/fonts/a.woff2'));
    assert_true(is_file($assets . 'themes/acme/parts/extra.css'));
    assert_contains('url(../../uploads/bg.png)', (string) file_get_contents($assets . 'themes/acme/style.css'));

    $meta = $site['themes']->meta($id);
    assert_same('div.entry-content', $meta['content_scope']);
    assert_same('ul.menu', $meta['regions']['page']['main-menu']);
    assert_true($meta['renders_title']);
    assert_same(['acme-accent', 'acme-font'], array_column($meta['variables'], 'name'));

    $site['themes']->assertThemeIsValid($id);
    $site['generator']->applyTheme($id);
    $page = $site['generator']->createPage('Contact', 'contact');
    $html = (string) file_get_contents($root . '/' . $page);
    assert_contains('assets/theme/' . $id . '/acme.example/wp-content/themes/acme/style.css', $html);
    assert_contains('<h1 class="entry-title">Contact</h1>', $html);
    assert_true(is_file($root . '/assets/theme/' . $id . '/acme.example/wp-content/uploads/logo.png'), 'theme assets are published');
});

test('THM-1: a template built from a URL copies its assets instead of hotlinking', function (): void {
    $site = make_site();
    $importer = new ExternalSiteImporter($site['root'], $site['edit']);
    $importer->setTransport(static function (string $url): array {
        $files = [
            'https://source.example/about/' => ['text/html', '<!doctype html><html><head><title>About</title><link rel="stylesheet" href="/wp-content/themes/acme/style.css"></head><body><header><img src="https://source.example/wp-content/uploads/logo.png"><nav><ul class="menu"><li><a href="/">Home</a></li></ul></nav></header><main id="main"><p>content</p></main></body></html>'],
            'https://source.example/wp-content/themes/acme/style.css' => ['text/css', 'body{background:url(img/bg.png)}'],
            'https://source.example/wp-content/themes/acme/img/bg.png' => ['image/png', 'BG'],
            'https://source.example/wp-content/uploads/logo.png' => ['image/png', 'LOGO'],
        ];
        return isset($files[$url]) ? ['status' => 200, 'headers' => ['Content-Type' => $files[$url][0]], 'body' => $files[$url][1]] : ['status' => 404, 'headers' => [], 'body' => ''];
    });
    $source = $importer->cacheTemplateSource('https://source.example/about/', 'page');
    $themeId = $site['themes']->createTheme('From URL');
    $path = (new ThemeImporter($site['root'], $importer, $site['themes']))->addTemplateFromSelections($themeId, 'page', $importer->getCachedTemplateSource($source['id']), [
        'main-menu' => [['tag' => 'html', 'index' => 1], ['tag' => 'body', 'index' => 1], ['tag' => 'header', 'index' => 1], ['tag' => 'nav', 'index' => 1], ['tag' => 'ul', 'index' => 1]],
        'page-content' => [['tag' => 'html', 'index' => 1], ['tag' => 'body', 'index' => 1], ['tag' => 'main', 'index' => 1]],
    ]);
    $template = (string) file_get_contents($site['root'] . '/' . $path);
    assert_not_contains('source.example/wp-content', str_replace('{{THEME_ASSET_BASE}}source.example/wp-content', '', $template), 'no hotlinks to the source site');
    assert_not_contains('href="/wp-content', $template);
    assert_contains('{{THEME_ASSET_BASE}}source.example/wp-content/themes/acme/style.css', $template);
    assert_same('BG', file_get_contents($site['edit'] . '/themes/' . $themeId . '/assets/source.example/wp-content/themes/acme/img/bg.png'));
    assert_same('#main', $site['themes']->meta($themeId)['regions']['page']['page-content']);
});
