<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('DATA-1: demo deploy leaves existing pages byte-identical and delete-demo keeps them', function (): void {
    $site = make_site();
    $content = require dirname(__DIR__) . '/docs/content.php';

    $site['generator']->createPage('My real home', 'index');
    file_put_contents($site['root'] . '/features.html', '<!doctype html><html><head><title>Mine</title></head><body>unmanaged</body></html>');
    $home = file_get_contents($site['root'] . '/index.html');
    $features = file_get_contents($site['root'] . '/features.html');

    $result = $site['generator']->deployDemoSite($content);
    assert_same($home, file_get_contents($site['root'] . '/index.html'));
    assert_same($features, file_get_contents($site['root'] . '/features.html'));
    assert_true(in_array('index.html', $result['skipped'], true));
    assert_true(in_array('features.html', $result['skipped'], true));
    assert_true($result['created'] > 0);

    $site['generator']->deleteDemoContent();
    assert_same($home, file_get_contents($site['root'] . '/index.html'));
    assert_same($features, file_get_contents($site['root'] . '/features.html'));
});

test('DATA-1: redeploying refreshes only demo-flagged pages', function (): void {
    $site = make_site();
    $content = require dirname(__DIR__) . '/docs/content.php';
    $first = $site['generator']->deployDemoSite($content);
    assert_same([], $first['skipped']);
    $second = $site['generator']->deployDemoSite($content);
    assert_same(0, $second['created']);
    assert_same($first['created'], $second['refreshed']);
    assert_contains('demo="1"', (string) file_get_contents($site['root'] . '/index.html'));
});
