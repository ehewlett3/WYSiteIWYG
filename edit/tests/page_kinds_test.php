<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('DATA-4: a 3-block page converted to a post keeps all blocks through theme apply', function (): void {
    $site = make_site();
    $path = $site['generator']->createPage('Three blocks', 'three');
    foreach (['page-content' => 'Alpha text', 'page-content-2' => 'Beta text', 'page-content-3' => 'Gamma text'] as $block => $text) {
        $site['repository']->updateBlock($path, $block, '<p>' . $text . '</p>');
    }
    $site['repository']->updateMetadata($path, ['kind' => 'blog-post', 'hashtags' => '#blog', 'date' => '2026-01-02']);
    $site['generator']->applyTheme('wysite-cathedral');

    $html = (string) file_get_contents($site['root'] . '/' . $path);
    assert_contains('kind="blog-post"', $html);
    foreach (['Alpha text', 'Beta text', 'Gamma text'] as $text) {
        assert_contains($text, $html);
    }
});

test('DATA-5: the homepage is kind home and stays home across saves and rebuilds', function (): void {
    $site = make_site();
    $site['generator']->createPage('Home', 'index');
    assert_contains('kind="home"', (string) file_get_contents($site['root'] . '/index.html'));
    assert_same('home', $site['repository']->getPage('index.html')['kind']);
    assert_same('home', $site['repository']->getPage('')['kind']);

    $site['repository']->updateMetadata('index.html', ['title' => 'Renamed']);
    $site['generator']->applyTheme('wysite-cathedral');
    assert_same('home', $site['repository']->getPage('index.html')['kind']);

    // Hashtags alone no longer turn an explicit page into a post.
    $path = $site['generator']->createPage('Plain', 'plain');
    $site['repository']->updateMetadata($path, ['hashtags' => '#news']);
    assert_same('page', $site['repository']->getPage($path)['kind']);
});

test('DATA-6: a theme home.html is used after apply and removed when switching away', function (): void {
    $site = make_site();
    $site['generator']->createPage('Home', 'index');
    $about = $site['generator']->createPage('About', 'about');

    $themeDir = $site['edit'] . '/themes/verdant-sanctuary';
    $home = str_replace(['kind="page"', '<body class="{{BODY_CLASS}}">'], ['kind="home"', '<body class="{{BODY_CLASS}}"><p class="home-marker">HOME TEMPLATE</p>'], (string) file_get_contents($themeDir . '/page.html'));
    file_put_contents($themeDir . '/home.html', $home);
    $site['themes']->assertThemeIsValid('verdant-sanctuary');

    $site['generator']->applyTheme('verdant-sanctuary');
    assert_true(is_file($site['edit'] . '/templates/home.html'));
    assert_contains('HOME TEMPLATE', (string) file_get_contents($site['root'] . '/index.html'));
    assert_not_contains('HOME TEMPLATE', (string) file_get_contents($site['root'] . '/' . $about));

    $site['generator']->applyTheme('wysite-cathedral');
    assert_false(is_file($site['edit'] . '/templates/home.html'));
    $index = (string) file_get_contents($site['root'] . '/index.html');
    assert_not_contains('HOME TEMPLATE', $index);
    assert_contains('kind="home"', $index);
});

test('DATA-8/BLOG-7: tag collisions are rejected before anything is written', function (): void {
    $site = make_site();
    $site['generator']->createPage('Launch', 'launch');
    assert_throws(fn() => $site['generator']->assertTagsWritable('#launch'), 'launch');
    $site['generator']->assertTagsWritable('#other');
    assert_throws(fn() => $site['generator']->createBlogPost('Post', 'post', 'x', '#launch'), 'launch');
    assert_false(is_file($site['root'] . '/post.html') || is_file($site['root'] . '/post/index.html'), 'nothing written on conflict');
});
