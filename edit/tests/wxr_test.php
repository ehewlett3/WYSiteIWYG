<?php
declare(strict_types=1);

use WYSiteIWYG\WxrImporter;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('WP-2: a WordPress export imports posts, pages, drafts and permalinks', function (): void {
    $site = make_site();
    $importer = new WxrImporter($site['generator'], $site['repository']);
    $result = $importer->import(__DIR__ . '/fixtures/export.wxr.xml');

    assert_same(['2024/04/easter-spring/index.html', 'classic-post/index.html', 'about-us/index.html'], $result['created']);
    assert_same(['draft:unfinished/index.html'], $result['drafts']);
    assert_same(['https://old.example.org/wp-content/uploads/2024/04/lilies.jpg'], $result['attachments']);
    assert_same(['old.example.org'], $result['source_hosts']);

    $post = $site['repository']->getPage('2024/04/easter-spring/index.html');
    assert_same('blog-post', $post['kind']);
    assert_same('Easter & Spring', $post['meta']['title']);
    assert_same('2024-04-05', $post['meta']['date']);
    assert_same('#blog #news #feasts', $post['meta']['hashtags']);
    assert_same('Fr. John', $post['meta']['author']);
    $body = $site['repository']->getBlock('2024/04/easter-spring/index.html', 'blog-post-content')['content'];
    assert_contains('<p>Christ is risen!</p>', $body);
    assert_contains('<figure class="wp-block-image">', $body);
    assert_not_contains('wp:paragraph', $body);

    $classic = $site['repository']->getBlock('classic-post/index.html', 'blog-post-content')['content'];
    assert_contains("<p>First paragraph<br>\nstill first.</p>", $classic);
    assert_contains('<p>Second paragraph.</p>', $classic);
    assert_contains('<li>One</li>', $classic);

    $about = $site['repository']->getPage('about-us/index.html');
    assert_same('page', $about['kind']);
    assert_not_contains('alert(1)', $about['html']);

    assert_false(is_file($site['root'] . '/unfinished/index.html'), 'drafts stay private');
    $blog = (string) file_get_contents($site['root'] . '/blog/index.html');
    assert_contains('Easter &amp; Spring', $blog);
    assert_not_contains('Unfinished', $blog);
    assert_true(is_file($site['root'] . '/feasts/index.html'));

    $again = $importer->import(__DIR__ . '/fixtures/export.wxr.xml');
    assert_same(4, count($again['skipped']), 'existing items are skipped unless overwriting');
});

test('WP-2: non-WXR files are rejected clearly', function (): void {
    $site = make_site();
    $file = $site['root'] . '/x.xml';
    file_put_contents($file, '<?xml version="1.0"?><rss><channel></channel></rss>');
    assert_throws(fn() => (new WxrImporter($site['generator'], $site['repository']))->import($file), 'No items');
});

test('WP-7: crawl jobs persist their state and finish with a summary', function (): void {
    $site = make_site();
    $importer = new \WYSiteIWYG\ExternalSiteImporter($site['root'], $site['edit']);
    $id = $importer->startImportJob('https://nothing-here.invalid/', 5);
    assert_same(1, count($importer->unfinishedImportJobs()));
    $job = json_decode((string) file_get_contents($site['edit'] . '/storage/import-jobs/' . $id . '.json'), true);
    assert_same(['https://nothing-here.invalid/'], $job['queue']);
    assert_same('0600', substr(sprintf('%o', fileperms($site['edit'] . '/storage/import-jobs/' . $id . '.json')), -4));

    $step = $importer->runImportJob($id, 25);
    assert_same(true, $step['done']);
    assert_same(1, count($step['result']['failed']));
    assert_same(0, count($importer->unfinishedImportJobs()));
    $again = $importer->runImportJob($id, 25);
    assert_same(true, $again['done'], 'a finished job stays finished');
    assert_throws(fn() => $importer->runImportJob('../x', 1), 'Invalid');
});

test('WP-3/WP-6: migrated feeds, archive redirects and query-string redirect map', function (): void {
    $site = make_site();
    file_put_contents($site['edit'] . '/storage/import-sources.local.json', '["old.example.org"]');
    mkdir($site['root'] . '/category/news', 0775, true);
    file_put_contents($site['root'] . '/category/news/index.html', '<html><body>Frozen archive</body></html>');
    file_put_contents($site['root'] . '/.htaccess', "Options -Indexes\n");

    $importer = new WxrImporter($site['generator'], $site['repository']);
    $result = $importer->import(__DIR__ . '/fixtures/export.wxr.xml');
    assert_same([['path' => '/', 'query' => 'p=42', 'target' => 'classic-post/index.html']], $result['query_redirects']);

    // WP-3: the WordPress feed URLs answer with the live feed.
    assert_contains('Easter &amp; Spring', (string) file_get_contents($site['root'] . '/feed/index.xml'));
    assert_contains('<rss', (string) file_get_contents($site['root'] . '/category/news/feed/index.xml'));

    $counts = $site['generator']->writeMigrationRedirects($result['query_redirects']);
    assert_same(['archives' => 1, 'queries' => 1], $counts);
    $stub = (string) file_get_contents($site['root'] . '/category/news/index.html');
    assert_contains('WYSITE:REDIRECT to="news/index.html"', $stub);

    $htaccess = (string) file_get_contents($site['root'] . '/.htaccess');
    assert_contains("Options -Indexes\n", $htaccess);
    assert_contains("RewriteCond %{QUERY_STRING} ^p\\=42$\nRewriteRule ^$ /classic-post/? [R=301,L]", $htaccess);
    $site['generator']->writeMigrationRedirects($result['query_redirects']);
    assert_same(1, substr_count((string) file_get_contents($site['root'] . '/.htaccess'), '# BEGIN WYSiteIWYG redirects'), 'the block is replaced, not duplicated');
    assert_contains('/?p=42 /classic-post/', (string) file_get_contents($site['edit'] . '/storage/redirects.txt'));
});
