<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('DATA-7: an edit can be restored byte-for-byte', function (): void {
    $site = make_site();
    $path = $site['generator']->createPage('About', 'about');
    $before = file_get_contents($site['root'] . '/' . $path);

    $site['repository']->updateBlock($path, 'page-content', '<p>Changed</p>');
    $history = $site['revisions']->list($path);
    assert_same(1, count($history));

    $site['revisions']->restore($path, $history[0]['id']);
    assert_same($before, file_get_contents($site['root'] . '/' . $path));
    assert_same(2, count($site['revisions']->list($path)), 'the replaced version is kept too');
    assert_throws(fn() => $site['revisions']->read('../x.html', $history[0]['id']), 'Invalid');
});

test('DATA-7: unchanged writes do not add history, and history is pruned', function (): void {
    $site = make_site();
    $revisions = new \WYSiteIWYG\Revisions($site['root'], $site['edit'] . '/storage/revisions', 3);
    \WYSiteIWYG\Filesystem::setRevisions($revisions);
    $file = $site['root'] . '/p.html';
    file_put_contents($file, 'v0');
    \WYSiteIWYG\Filesystem::writeSitePage($file, 'v0');
    assert_same(0, count($revisions->list('p.html')));
    for ($i = 1; $i <= 5; $i++) {
        \WYSiteIWYG\Filesystem::writeSitePage($file, 'v' . $i);
    }
    assert_same(3, count($revisions->list('p.html')));
});

test('DATA-7: restoring a pre-theme-apply backup restores every page', function (): void {
    $site = make_site();
    $files = [
        $site['generator']->createPage('Home', 'index'),
        $site['generator']->createPage('About', 'about/team'),
        $site['generator']->createBlogPost('Hello', 'hello', 'First post', '#blog'),
        'blog/index.html',
    ];
    $before = [];
    foreach ($files as $file) {
        $before[$file] = file_get_contents($site['root'] . '/' . $file);
    }

    $name = $site['backups']->create('apply-theme');
    $site['generator']->applyTheme('wysite-cathedral');
    assert_true($before['index.html'] !== file_get_contents($site['root'] . '/index.html'));

    $site['backups']->restore($name);
    foreach ($files as $file) {
        assert_same($before[$file], file_get_contents($site['root'] . '/' . $file), $file);
    }
});

WYSiteIWYG\Tests\test('SEC-7b: backups include custom themes but not shipped ones', function (): void {
    $site = WYSiteIWYG\Tests\make_site();
    mkdir($site['edit'] . '/themes/my-import', 0775, true);
    file_put_contents($site['edit'] . '/themes/my-import/page.html', '<html><body>custom</body></html>');
    file_put_contents($site['edit'] . '/themes/my-import/site.css', 'body{color:red}');
    $name = $site['backups']->create('test');
    file_put_contents($site['edit'] . '/themes/my-import/site.css', 'broken');
    $count = $site['backups']->restore($name);
    WYSiteIWYG\Tests\assert_true($count >= 2);
    WYSiteIWYG\Tests\assert_same('body{color:red}', file_get_contents($site['edit'] . '/themes/my-import/site.css'));
    $zip = new ZipArchive();
    $zip->open($site['edit'] . '/storage/backups/' . $name . '.zip');
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    WYSiteIWYG\Tests\assert_true(in_array('edit/themes/my-import/page.html', $names, true));
    WYSiteIWYG\Tests\assert_true([] === array_filter($names, static fn(string $n): bool => str_starts_with($n, 'edit/themes/verdant-sanctuary/')));
});
