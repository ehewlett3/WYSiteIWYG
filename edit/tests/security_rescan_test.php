<?php
declare(strict_types=1);

use WYSiteIWYG\SecurityRescan;
use WYSiteIWYG\SiteSettings;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('SEC-1d: re-scan reports and cleans only blocks with active content', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    $dirty = $site['generator']->createPage('Dirty', 'dirty');
    $clean = $site['generator']->createPage('Clean', 'clean');
    // Written straight to disk, as an old sanitizer would have let it through.
    $site['repository']->updateBlock($dirty, 'page-content', "<p>Hi</p><img\x0csrc=x\x0conerror=alert(1)>");
    $site['repository']->updateBlock($clean, 'page-content', "<p>Fine <b>text</b></p>");
    $cleanBefore = (string) file_get_contents($site['root'] . '/' . $clean);
    file_put_contents($site['root'] . '/imported.html', '<html><body><p>x</p><svg><style><img src=x onerror=alert(1)></style></svg></body></html>');

    $settings = new SiteSettings($site['edit'] . '/storage/site.local.php');
    $rescan = new SecurityRescan($site['root'], $site['edit'], $site['repository'], $settings);

    $report = $rescan->run(['imported.html'], false);
    assert_same(1, count($report['blocks']));
    assert_same($dirty, $report['blocks'][0]['path']);
    assert_same(1, count($report['files']));
    assert_contains('onerror', file_get_contents($site['root'] . '/' . $dirty), 'dry run changes nothing');

    $rescan->run(['imported.html'], true);
    assert_not_contains('onerror', (string) file_get_contents($site['root'] . '/' . $dirty));
    assert_contains('<p>Hi</p>', (string) file_get_contents($site['root'] . '/' . $dirty));
    assert_not_contains('onerror', (string) file_get_contents($site['root'] . '/imported.html'));
    assert_same($cleanBefore, (string) file_get_contents($site['root'] . '/' . $clean), 'clean pages are left byte-for-byte');
    assert_same(0, SecurityRescan::count($rescan->run(['imported.html'], false)));
});
