<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

function blog_site(int $posts, int $perPage = 10): array
{
    $site = make_site('/sub/');
    $site['generator']->settings()->update(['canonical_base_url' => 'https://example.org/sub/', 'posts_per_page' => $perPage, 'site_name' => 'Parish & Friends']);
    for ($i = 1; $i <= $posts; $i++) {
        $path = $site['generator']->createBlogPost('Post ' . $i, 'post-' . $i, 'Excerpt ' . $i, $i % 2 === 0 ? '#blog #news' : '#blog');
        $site['repository']->updateMetadata($path, ['date' => sprintf('2026-01-%02d', $i)]);
        $site['repository']->updateBlock($path, 'blog-post-content', '<p>Body ' . $i . ' <img src="assets/uploads/p' . $i . '.jpg"> <a href="about/">About</a></p>');
    }
    $site['generator']->rebuildTagPages();
    return $site;
}

test('BLOG-1: RSS feeds are well-formed, absolute, newest first, with full content', function (): void {
    $site = blog_site(3);
    $xml = (string) file_get_contents($site['root'] . '/blog/feed.xml');
    $feed = simplexml_load_string($xml);
    assert_true($feed !== false, 'feed parses as XML');
    assert_same('2.0', (string) $feed['version']);
    assert_same('Parish & Friends', (string) $feed->channel->title);
    assert_same('https://example.org/sub/blog/', (string) $feed->channel->link);
    assert_same(3, count($feed->channel->item));
    assert_same('Post 3', (string) $feed->channel->item[0]->title);
    assert_same('https://example.org/sub/post-3/', (string) $feed->channel->item[0]->link);
    assert_true(strtotime((string) $feed->channel->item[0]->pubDate) !== false, 'pubDate is RFC 2822');
    $content = (string) $feed->channel->item[0]->children('http://purl.org/rss/1.0/modules/content/')->encoded;
    assert_contains('src="https://example.org/sub/assets/uploads/p3.jpg"', $content);
    assert_contains('href="https://example.org/sub/about/"', $content);

    $news = simplexml_load_string((string) file_get_contents($site['root'] . '/news/feed.xml'));
    assert_same(1, count($news->channel->item));
});

test('BLOG-4: blog index paginates and removes stale pages', function (): void {
    $site = blog_site(5, 2);
    assert_true(is_file($site['root'] . '/blog/page/2/index.html'));
    assert_true(is_file($site['root'] . '/blog/page/3/index.html'));
    $first = (string) file_get_contents($site['root'] . '/blog/index.html');
    assert_contains('Page 1 of 3', $first);
    assert_contains('rel="next" href="blog/page/2/"', $first);
    assert_not_contains('Post 1<', $first);
    $third = (string) file_get_contents($site['root'] . '/blog/page/3/index.html');
    assert_contains('Post 1<', $third);
    assert_contains('<base href="../../../">', $third);

    $site['generator']->settings()->update(['posts_per_page' => 10]);
    $site['generator']->rebuildTagPages();
    assert_false(is_file($site['root'] . '/blog/page/2/index.html'));
    assert_same(['blog/index.html', 'news/index.html'], array_values(array_filter(
        array_column($site['repository']->listPages(), 'path'),
        static fn(string $path): bool => !str_starts_with($path, 'post-')
    )));
});

test('BLOG-6: sitemap and default robots.txt', function (): void {
    $site = blog_site(2);
    $sitemap = simplexml_load_string((string) file_get_contents($site['root'] . '/sitemap.xml'));
    $all = [];
    foreach ($sitemap->url as $url) {
        $all[] = (string) $url->loc;
    }
    assert_true(in_array('https://example.org/sub/post-1/', $all, true));
    assert_true(in_array('https://example.org/sub/blog/', $all, true));
    assert_contains('Sitemap: https://example.org/sub/sitemap.xml', (string) file_get_contents($site['root'] . '/robots.txt'));

    file_put_contents($site['root'] . '/robots.txt', "User-agent: *\nDisallow:\n");
    $site['generator']->rebuildTagPages();
    assert_same("User-agent: *\nDisallow:\n", file_get_contents($site['root'] . '/robots.txt'), 'a site-owned robots.txt is left alone');
});

test('BLOG-2: drafts are private until published, and can be unpublished', function (): void {
    $site = make_site();
    $draft = $site['generator']->createBlogPost('Secret', 'secret', '', '#blog', null, true);
    assert_same('draft:secret/index.html', $draft);
    assert_false(is_file($site['root'] . '/secret/index.html'), 'not at its public URL');
    assert_true(is_file($site['edit'] . '/storage/drafts/secret/index.html'));
    assert_same([], array_column($site['repository']->listPages(), 'path'));
    assert_same('draft:secret/index.html', $site['repository']->listDrafts()[0]['path']);

    // Drafts can be edited like pages.
    $site['repository']->updateBlock($draft, 'blog-post-content', '<p>Draft body</p>');
    assert_contains('Draft body', $site['repository']->getPage($draft)['html']);
    $site['generator']->rebuildTagPages();
    assert_not_contains('Secret', (string) file_get_contents($site['root'] . '/blog/index.html'));

    $public = $site['generator']->publishDraft($draft);
    assert_same('secret/index.html', $public);
    $html = (string) file_get_contents($site['root'] . '/secret/index.html');
    assert_contains('Draft body', $html);
    assert_not_contains('status="draft"', $html);
    assert_contains('<base href="../">', $html);
    assert_contains('Secret', (string) file_get_contents($site['root'] . '/blog/index.html'));
    assert_false(is_dir($site['edit'] . '/storage/drafts/secret'));

    $back = $site['generator']->unpublishPage($public);
    assert_same('draft:secret/index.html', $back);
    assert_false(is_file($site['root'] . '/secret/index.html'));
    assert_not_contains('Secret', (string) file_get_contents($site['root'] . '/blog/index.html'));
});

test('BLOG-2: scheduled drafts publish when due', function (): void {
    $site = make_site();
    $draft = $site['generator']->createPage('Later', 'later', true);
    $site['repository']->updateMetadata($draft, ['publish_at' => '2026-10-01']);
    assert_same([], $site['generator']->publishDueDrafts('2026-09-30'));
    assert_same(['later/index.html'], $site['generator']->publishDueDrafts('2026-10-01'));
});

test('BLOG-3: delete and move pages; moves leave a redirect and update the menu', function (): void {
    $site = make_site();
    $site['generator']->createPage('Home', 'index');
    $about = $site['generator']->createPage('About', 'about');
    $site['generator']->syncMenu('<ul><li><a href="./">Home</a></li><li><a href="about/">About</a></li></ul>');

    $moved = $site['generator']->movePage($about, 'about-us');
    assert_same('about-us/index.html', $moved);
    assert_contains('WYSITE:REDIRECT', (string) file_get_contents($site['root'] . '/about/index.html'));
    assert_contains('href="about-us/"', (string) file_get_contents($site['root'] . '/index.html'));
    assert_not_contains('href="about/"', (string) file_get_contents($site['root'] . '/index.html'));

    $warning = $site['generator']->deletePage($moved);
    assert_contains('menu still links', (string) $warning);
    assert_false(is_file($site['root'] . '/about-us/index.html'));
    assert_true(count($site['revisions']->list('about-us/index.html')) >= 1, 'deleted page is kept in history');
});

test('NAV-1: add to menu, and the current page link is marked per page only', function (): void {
    $site = make_site();
    $site['generator']->createPage('Home', 'index');
    $about = $site['generator']->createPage('About', 'about');
    $site['generator']->syncMenu('<ul><li><a href="./">Home</a></li></ul>');
    $site['generator']->addMenuLink($about, 'About us');

    $home = (string) file_get_contents($site['root'] . '/index.html');
    $aboutHtml = (string) file_get_contents($site['root'] . '/' . $about);
    assert_contains('<a href="about/">About us</a>', $home);
    assert_contains('<a href="./" class="is-current" aria-current="page">Home</a>', $home);
    assert_contains('<a href="about/" class="is-current" aria-current="page">About us</a>', $aboutHtml);
    assert_contains('<a href="./">Home</a>', $aboutHtml);
    assert_same(1, substr_count($aboutHtml, 'aria-current'));

    // The stored menu (what the next page gets) is page-neutral.
    $site['generator']->applyTheme('wysite-cathedral');
    $home = (string) file_get_contents($site['root'] . '/index.html');
    assert_same(1, substr_count($home, 'aria-current'));
    assert_contains('<a href="about/">About us</a>', $home);
});
