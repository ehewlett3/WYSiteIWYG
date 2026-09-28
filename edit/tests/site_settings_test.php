<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('DROP-4: site name reaches headers and titles and survives a theme apply', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    $home = $site['generator']->createPage('Home', 'index');
    $post = $site['generator']->createBlogPost('Hello', 'hello', 'Excerpt', '#blog');

    $site['generator']->settings()->update(['site_name' => 'St. Mary <Parish>', 'tagline' => 'Est. 1901', 'language' => 'en-GB']);
    $site['generator']->rebuildAllPages();

    foreach ([$home, $post, 'blog/index.html'] as $path) {
        $html = (string) file_get_contents($site['root'] . '/' . $path);
        assert_contains('<html lang="en-GB">', $html, $path);
        assert_contains('| St. Mary &lt;Parish&gt;</title>', $html, $path);
        assert_contains('<span class="site-brand__title">St. Mary &lt;Parish&gt;</span>', $html, $path);
        assert_contains('Est. 1901', $html, $path);
        assert_not_contains('Verdant Sanctuary</span>', $html, $path);
    }

    $site['generator']->applyTheme('vivid-nocturne');
    assert_contains('<span class="site-brand__title">St. Mary &lt;Parish&gt;</span>', (string) file_get_contents($site['root'] . '/' . $home));
});

test('DROP-4/BLOG-6: head metadata, footer and META carry-over', function (): void {
    $site = make_site();
    $site['generator']->settings()->update(['canonical_base_url' => 'https://example.org/sub', 'footer_html' => '<p>Footer <script>x()</script>here</p>']);
    $path = $site['generator']->createPage('About us', 'about');
    $site['repository']->updateMetadata($path, ['excerpt' => 'All "about" us', 'demo' => '1', 'author' => 'Ann']);
    $site['generator']->applyTheme('wysite-cathedral');

    $html = (string) file_get_contents($site['root'] . '/' . $path);
    assert_contains('<meta name="description" content="All &quot;about&quot; us">', $html);
    assert_contains('<link rel="canonical" href="https://example.org/sub/about/">', $html);
    assert_contains('<meta property="og:title" content="About us">', $html);
    assert_contains('type="application/rss+xml"', $html);
    assert_contains('<p>Footer here</p>', $html);
    assert_contains('demo="1"', $html, 'theme apply keeps extra META fields');
    assert_contains('author="Ann"', $html);
    assert_same(1, substr_count($html, 'rel="canonical"'));
});

test('DROP-4: settings validation', function (): void {
    $site = make_site();
    $settings = $site['generator']->settings();
    assert_throws(fn() => $settings->update(['site_name' => '  ']), 'cannot be empty');
    assert_throws(fn() => $settings->update(['language' => 'english!']), 'language code');
    assert_throws(fn() => $settings->update(['canonical_base_url' => 'javascript:alert(1)']), 'http(s)');
    assert_throws(fn() => $settings->update(['logo' => '../edit/storage/users.local.php']), 'Invalid logo');
    $settings->update(['tagline' => 'ok']);
    assert_same('0600', substr(sprintf('%o', fileperms($site['edit'] . '/storage/site.local.php')), -4));
});

test('DROP-1: new pages are folder-style and slugs only reserve edit/ and assets/', function (): void {
    $site = make_site();
    assert_same('about/index.html', $site['generator']->createPage('About', 'about'));
    assert_same('editorial/index.html', $site['generator']->createPage('Editorial', 'editorial'));
    assert_throws(fn() => $site['generator']->createPage('X', 'edit/x'), 'edit/');
    assert_throws(fn() => $site['generator']->createPage('Again', 'about'), 'already exists');
    $site['generator']->settings()->update(['url_style' => 'flat']);
    assert_same('flat.html', $site['generator']->createPage('Flat', 'flat'));
    $site['generator']->settings()->update(['post_permalink' => '{yyyy}/{mm}/{slug}']);
    assert_same(gmdate('Y') . '/' . gmdate('m') . '/dated.html', $site['generator']->createBlogPost('Dated', 'dated', 'x', '#blog'));
});

test('DROP-1: converting flat pages to folders keeps content and leaves stubs', function (): void {
    $site = make_site('/sub/');
    $site['generator']->settings()->update(['url_style' => 'flat']);
    $path = $site['generator']->createPage('About', 'about/team');
    $site['repository']->updateBlock($path, 'page-content', '<p>Team text</p>');
    assert_same('about/team.html', $path);
    assert_contains('<base href="../../">', (string) file_get_contents($site['root'] . '/about/team.html'));

    $result = $site['generator']->convertToFolderUrls();
    assert_same(['about/team.html'], $result['moved']);
    $moved = (string) file_get_contents($site['root'] . '/about/team/index.html');
    assert_contains('Team text', $moved);
    assert_contains('<base href="../../">', $moved, 'same public URL depth, same base');

    $stub = (string) file_get_contents($site['root'] . '/about/team.html');
    assert_contains('WYSITE:REDIRECT', $stub);
    assert_contains('url=/sub/about/team/', $stub);
    assert_same([], $site['repository']->listImportCandidates(), 'stubs are not import candidates');
    assert_same(['about/team/index.html'], array_column($site['repository']->listPages(), 'path'));
});

test('DROP-5: purge touches only managed pages, stubs and imported files; scans skip excluded folders', function (): void {
    $site = make_site();
    $site['generator']->createPage('Home', 'index');
    mkdir($site['root'] . '/shop', 0775, true);
    mkdir($site['root'] . '/.git', 0775, true);
    file_put_contents($site['root'] . '/shop/cart.html', '<html><body>shop</body></html>');
    file_put_contents($site['root'] . '/.git/x.html', '<html></html>');
    file_put_contents($site['root'] . '/crawled.html', '<html><body>crawled</body></html>');
    file_put_contents($site['root'] . '/hand.html', '<html><body>hand made</body></html>');

    $candidates = array_column($site['repository']->listImportCandidates(), 'path');
    assert_same(['crawled.html', 'hand.html', 'shop/cart.html'], $candidates);

    $site['generator']->settings()->update(['exclude_paths' => "shop\n"]);
    $site = \WYSiteIWYG\Tests\wire_site($site['root']);
    assert_same(['crawled.html', 'hand.html'], array_column($site['repository']->listImportCandidates(), 'path'));

    $purge = $site['generator']->purgeCandidates(['crawled.html', 'assets/x.css']);
    assert_same(['crawled.html', 'index.html'], $purge);
    $site['generator']->purgeAllPages(['crawled.html']);
    assert_same(true, is_file($site['root'] . '/hand.html'));
    assert_same(true, is_file($site['root'] . '/shop/cart.html'));
    assert_same(false, is_file($site['root'] . '/index.html'));
});
