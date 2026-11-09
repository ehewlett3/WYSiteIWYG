<?php
declare(strict_types=1);

use WYSiteIWYG\AssetPolicy;
use WYSiteIWYG\ExternalSiteImporter;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\call_private;
use function WYSiteIWYG\Tests\scratch_dir;
use function WYSiteIWYG\Tests\test;

test('SEC-2a: mirrored SVG loses scripts, handlers, foreignObject and stylesheets PIs', function (): void {
    $svg = '<?xml version="1.0"?><?xml-stylesheet type="text/xsl" href="x.xsl"?><svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" onload="alert(1)">'
        . '<script>alert(1)</script><foreignObject><div xmlns="http://www.w3.org/1999/xhtml"><img src="x" onerror="alert(1)"/></div></foreignObject>'
        . '<a xlink:href="javascript:alert(1)"><circle r="4" fill="red"/></a><set attributeName="href" to="javascript:alert(1)"/><rect width="2" height="2"/></svg>';
    $out = (string) AssetPolicy::sanitizeSvg($svg);
    foreach (['<script', 'onload', 'foreignObject', 'onerror', 'javascript:', 'xml-stylesheet', '<set'] as $needle) {
        assert_not_contains($needle, $out);
    }
    assert_contains('<circle r="4" fill="red"/>', $out);
    assert_contains('<rect width="2" height="2"/>', $out);
    assert_same(null, AssetPolicy::sanitizeSvg('<svg><unclosed>'));
    assert_same(null, AssetPolicy::sanitizeSvg('<!DOCTYPE svg [<!ENTITY a "x">]><svg>&a;</svg>'));
});

test('SEC-2a: feeds stay feeds; other XML documents become text', function (): void {
    $rss = '<?xml version="1.0"?><?xml-stylesheet href="pretty.xsl" type="text/xsl"?><rss version="2.0"><channel><title>T</title>'
        . '<item><title>Post</title><description xmlns:h="http://www.w3.org/1999/xhtml"><h:script>alert(1)</h:script>Body</description></item></channel></rss>';
    $out = (string) AssetPolicy::sanitizeXmlDocument($rss);
    assert_contains('<title>Post</title>', $out);
    assert_not_contains('h:script', $out);
    assert_not_contains('alert(1)', $out);
    assert_not_contains('xml-stylesheet', $out);
    assert_same(null, AssetPolicy::sanitizeXmlDocument('<html xmlns="http://www.w3.org/1999/xhtml"><script>alert(1)</script></html>'));
    assert_true(AssetPolicy::sanitizeXmlDocument('<config><a>1</a></config>', false) !== null);
});

test('SEC-5: backfill and relocalize never rewrite files the importer did not create', function (): void {
    $root = scratch_dir();
    mkdir($root . '/edit/storage', 0775, true);
    mkdir($root . '/other-app', 0775, true);
    $foreignHtml = '<html><head></head><body><img src="https://source.example/wp-content/a.png"><a href="/x/">x</a></body></html>';
    $foreignJs = 'var img = "https://source.example/wp-content/a.png";';
    file_put_contents($root . '/other-app/page.html', $foreignHtml);
    file_put_contents($root . '/other-app/app.js', $foreignJs);
    file_put_contents($root . '/imported.html', $foreignHtml);
    file_put_contents($root . '/edit/storage/import-manifest.local.json', json_encode(['imported.html' => 'x']));

    $importer = new ExternalSiteImporter($root, $root . '/edit');
    $updated = call_private($importer, 'rewriteImportedAssetReferences', ['https://source.example/wp-content/a.png' => 'assets/imported/source.example/wp-content/a.png']);
    assert_same(1, $updated);
    assert_contains('/assets/imported/source.example/wp-content/a.png', (string) file_get_contents($root . '/imported.html'));
    assert_same($foreignHtml, file_get_contents($root . '/other-app/page.html'));
    assert_same($foreignJs, file_get_contents($root . '/other-app/app.js'));

    $importer->relocalizeImportedOutput();
    assert_contains('<base href="../">', (string) file_get_contents($root . '/imported.html'));
    assert_same($foreignHtml, file_get_contents($root . '/other-app/page.html'));
});
