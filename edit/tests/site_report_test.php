<?php
declare(strict_types=1);

use WYSiteIWYG\SiteReport;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\fixture;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

function report_site(): array
{
    $site = make_site();
    $site['generator']->createPage('About', 'about');
    mkdir($site['root'] . '/hello', 0775, true);
    file_put_contents($site['root'] . '/hello/index.html', fixture('wp-post.html'));
    return $site + ['report' => new SiteReport($site['root'], $site['repository'])];
}

test('NAV-2: broken internal links and missing assets are reported', function (): void {
    $site = report_site();
    $broken = $site['report']->brokenLinks(['hello/index.html']);
    $targets = array_column($broken, 'target');
    sort($targets);
    assert_same(['assets/imported/old.example.org/x.jpg', 'gone/'], $targets);
    assert_same('hello/index.html', $broken[0]['page']);
});

test('WP-4: migration report finds forms, embeds, old-site links and scripts', function (): void {
    $site = report_site();
    $report = $site['report']->migrationReport(['old.example.org'], ['www.youtube.com']);
    $types = array_column($report['hello/index.html'], 'type');
    foreach (['Comment form', 'Search form', 'Form', 'Embed', 'Script', 'Old-site links', 'Missing file'] as $type) {
        assert_true(in_array($type, $types, true), 'missing finding: ' . $type);
    }
    $embeds = array_values(array_filter($report['hello/index.html'], static fn(array $f): bool => $f['type'] === 'Embed'));
    assert_same(1, count($embeds), 'known embed hosts are not flagged');
});

test('WP-4: quick fixes edit only the affected forms', function (): void {
    $site = report_site();
    $before = (string) file_get_contents($site['root'] . '/hello/index.html');

    assert_same(1, $site['report']->applyFix('remove-comment-forms'));
    $after = (string) file_get_contents($site['root'] . '/hello/index.html');
    assert_not_contains('commentform', $after);
    assert_same(str_replace('<form action="https://old.example.org/wp-comments-post.php" method="post" id="commentform" class="comment-form"><textarea name="comment"></textarea></form>', '', $before), $after);

    $site['report']->applyFix('replace-search-forms', 'https://duckduckgo.com/', 'example.org');
    $site['report']->applyFix('retarget-forms', '', '', 'https://forms.example.com/f/1');
    $after = (string) file_get_contents($site['root'] . '/hello/index.html');
    assert_contains('action="https://duckduckgo.com/"', $after);
    assert_contains('<form action="https://forms.example.com/f/1" method="post" class="wpcf7-form">', $after);
    assert_contains('<article><p>Text with <a href="about/">About</a>', $after, 'surrounding markup untouched');
});

test('WP-1: crawled WordPress posts import as blog posts with date, tags and body', function (): void {
    $site = make_site();
    $html = '<!doctype html><html><head><title>Hello World – Old Blog</title>'
        . '<meta property="og:title" content="Hello World"><meta property="og:description" content="First post excerpt">'
        . '<meta property="article:published_time" content="2019-05-04T10:00:00+00:00"></head>'
        . '<body class="post-template-default single single-post"><header><nav>menu</nav></header>'
        . '<article><h1 class="entry-title">Hello World</h1><div class="entry-content"><p>The <strong>body</strong>.</p><figure class="wp-block-image"><img src="assets/x.jpg" alt=""></figure></div>'
        . '<footer>Posted in <a href="/category/news/" rel="category tag">News</a>, <a href="/tag/parish-life/" rel="tag">Parish Life</a>, <a href="/category/uncategorized/" rel="category tag">Uncategorized</a></footer></article>'
        . '<aside class="widget">sidebar</aside></body></html>';
    mkdir($site['root'] . '/2019/05/hello-world', 0775, true);
    file_put_contents($site['root'] . '/2019/05/hello-world/index.html', $html);
    file_put_contents($site['root'] . '/about.html', '<html><body class="page"><main><p>About text</p></main></body></html>');

    assert_same('blog-post', $site['generator']->importExistingPage('2019/05/hello-world/index.html'));
    assert_same('page', $site['generator']->importExistingPage('about.html'));

    $page = $site['repository']->getPage('2019/05/hello-world/index.html');
    assert_same('blog-post', $page['kind']);
    assert_same('2019-05-04', $page['meta']['date']);
    assert_same('#blog #news #parish-life', $page['meta']['hashtags']);
    assert_same('Hello World', $page['meta']['title']);
    $body = $site['repository']->getBlock('2019/05/hello-world/index.html', 'blog-post-content')['content'];
    assert_contains('<p>The <strong>body</strong>.</p>', $body);
    assert_contains('wp-block-image', $body);
    assert_not_contains('sidebar', $body);

    $blog = (string) file_get_contents($site['root'] . '/blog/index.html');
    assert_contains('Hello World', $blog);
    assert_contains('May 4, 2019', $blog);
    assert_true(is_file($site['root'] . '/parish-life/index.html'));
});
