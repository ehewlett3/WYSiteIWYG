<?php
declare(strict_types=1);

use WYSiteIWYG\SiteReport;
use WYSiteIWYG\ThemePackage;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('THM-D/UX-12: themes round-trip as zip files; hostile zips are refused or cleaned', function (): void {
    $site = make_site();
    $packages = new ThemePackage($site['root'], $site['themes']);
    $zipFile = $packages->export('verdant-sanctuary');
    $zip = new ZipArchive();
    $zip->open($zipFile);
    assert_true($zip->locateName('verdant-sanctuary/page.html') !== false);
    // rename the exported theme so it installs as a new one
    $zip->addFromString('verdant-sanctuary/theme.json', json_encode(['name' => 'Verdant Copy']));
    $zip->addFromString('verdant-sanctuary/page.html', str_replace('<body', '<body onload="alert(1)"', (string) $zip->getFromName('verdant-sanctuary/page.html')));
    $zip->addFromString('verdant-sanctuary/evil.php', '<?php system($_GET[1]);');
    $zip->addFromString('verdant-sanctuary/assets/x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script><rect/></svg>');
    $zip->close();

    $id = $packages->import($zipFile);
    assert_same('verdant-copy', $id);
    $dir = $site['edit'] . '/themes/' . $id;
    assert_true(is_file($dir . '/page.html'));
    assert_not_contains('onload', (string) file_get_contents($dir . '/page.html'));
    assert_true(!is_file($dir . '/evil.php'), 'unknown files are never written');
    assert_not_contains('<script', (string) file_get_contents($dir . '/assets/x.svg'));

    $bad = tempnam(sys_get_temp_dir(), 'z');
    $zip = new ZipArchive();
    $zip->open($bad, ZipArchive::OVERWRITE);
    $zip->addFromString('../../escape.html', 'x');
    $zip->addFromString('theme.json', json_encode(['name' => 'Escape']));
    $zip->close();
    assert_throws(static fn() => $packages->import($bad), 'unsafe path');
    @unlink($bad);
    @unlink($zipFile);

    $site['generator']->applyTheme('verdant-sanctuary');
    $site['generator']->createPage('Hello', 'hello');
    $siteZip = $packages->exportSite();
    $zip = new ZipArchive();
    $zip->open($siteZip);
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }
    $zip->close();
    @unlink($siteZip);
    assert_same([], array_values(array_filter($names, static fn(string $n): bool => str_starts_with($n, 'edit/'))));
    assert_true(in_array('hello/index.html', $names, true));
});

test('UX-9: the accessibility report finds missing alt text, unnamed links, skipped headings, unlabeled fields', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    $page = $site['generator']->createPage('A11y', 'a11y');
    $site['repository']->updateBlock($page, 'page-content', '<h2>Section</h2><h4>Too deep</h4><img src="a.png"><a href="x/"></a><a href="y/"><img src="b.png" alt="Named"></a><input name="email"><label>Name <input name="name"></label>');
    $report = (new SiteReport($site['root'], $site['repository']))->accessibilityReport([$page]);
    $types = array_column($report[$page], 'type');
    foreach (['Image without alt text', 'Link without a name', 'Skipped heading level', 'Form field without a label'] as $expected) {
        assert_contains($expected, implode('|', $types));
    }
    assert_same(1, count(array_filter($types, static fn(string $t): bool => $t === 'Form field without a label')), 'a field inside its label is fine');
});
