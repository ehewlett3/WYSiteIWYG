<?php
declare(strict_types=1);

use WYSiteIWYG\AssetPolicy;
use WYSiteIWYG\ExternalSiteImporter;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\call_private;
use function WYSiteIWYG\Tests\scratch_dir;
use function WYSiteIWYG\Tests\test;

function sec2_is_executable_path(string $path): bool
{
    return preg_match('/\.(php\d?|phtml|pht|phar|phps|shtml|cgi|pl|py|asp|aspx|jsp|htaccess|ini)$/i', $path) === 1
        || preg_match('#(^|/)\.#', $path) === 1
        || preg_match('/\.(php\d?|phtml|phar)\./i', $path) === 1;
}

test('SEC-2: mirrored asset URLs never produce an executable path', function (): void {
    $root = scratch_dir();
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    $urls = [
        'https://ex.com/wp-content/uploads/shell.php',
        'https://ex.com/download/a.phtml',
        'https://ex.com/wp-content/x.phar',
        'https://ex.com/wp-content/x.PHP5',
        'https://ex.com/wp-content/shell.php.png',
        'https://ex.com/wp-content/.htaccess',
        'https://ex.com/wp-content/.user.ini',
        'https://ex.com/wp-content/run.cgi?x=1',
    ];
    foreach ($urls as $url) {
        $path = call_private($importer, 'localAssetPathForUrl', $url, '');
        assert_false(sec2_is_executable_path($path), $url . ' mapped to ' . $path);
        assert_true(str_starts_with($path, 'assets/imported/ex.com/'), $path);
    }
    assert_same('assets/imported/ex.com/wp-content/uploads/shell-php.txt', call_private($importer, 'localAssetPathForUrl', $urls[0], ''));
    assert_same('assets/imported/ex.com/wp-content/shell-php.png', call_private($importer, 'localAssetPathForUrl', $urls[4], ''));
    assert_same('assets/imported/ex.com/wp-content/uploads/photo.jpg', call_private($importer, 'localAssetPathForUrl', 'https://ex.com/wp-content/uploads/photo.jpg', ''));
});

test('SEC-2: static documents keep feed types but neutralize executables', function (): void {
    $root = scratch_dir();
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    assert_same('feed/index.xml', call_private($importer, 'localStaticDocumentPathForUrl', 'https://ex.com/feed/', 'application/rss+xml'));
    $path = call_private($importer, 'localStaticDocumentPathForUrl', 'https://ex.com/x.phtml', 'text/plain');
    assert_false(sec2_is_executable_path($path), $path);
});

test('SEC-2: assets/.htaccess is created once and denies PHP', function (): void {
    $root = scratch_dir();
    AssetPolicy::ensureAssetsHtaccess($root);
    $contents = (string) file_get_contents($root . '/assets/.htaccess');
    assert_contains('Require all denied', $contents);
    assert_contains('php_flag engine off', $contents);
    file_put_contents($root . '/assets/.htaccess', 'custom');
    AssetPolicy::ensureAssetsHtaccess($root);
    assert_same('custom', file_get_contents($root . '/assets/.htaccess'));
});

test('Import drops WordPress-only head links but keeps feeds and canonical', function (): void {
    $root = scratch_dir();
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    $html = '<!DOCTYPE html><html><head>'
        . '<link rel="https://api.w.org/" href="https://ex.com/wp-json/">'
        . '<link rel="alternate" title="JSON" type="application/json" href="https://ex.com/wp-json/wp/v2/pages/6">'
        . '<link rel="alternate" title="oEmbed (JSON)" type="application/json+oembed" href="https://ex.com/wp-json/oembed/1.0/embed?url=x">'
        . '<link rel="EditURI" type="application/rsd+xml" href="https://ex.com/xmlrpc.php?rsd">'
        . '<link rel="pingback" href="https://ex.com/xmlrpc.php">'
        . '<link rel="shortlink" href="https://ex.com/?p=6">'
        . '<link rel="alternate" type="application/rss+xml" href="https://ex.com/feed/">'
        . '<link rel="canonical" href="https://ex.com/about/">'
        . '<link rel="shortlink" href="https://elsewhere.example/?p=6">'
        . '</head><body><p>Hi</p></body></html>';
    $assetMap = $assetsSaved = $assetsFailed = $nonEssential = [];
    $method = new \ReflectionMethod($importer, 'rewriteImportedHtmlReferences');
    $result = $method->invokeArgs($importer, [$html, 'https://ex.com/about/', ['ex.com'], &$assetMap, &$assetsSaved, &$assetsFailed, &$nonEssential]);
    $out = $result['html'];
    foreach (['wp-json', 'xmlrpc', 'oembed', 'ex.com/?p=6'] as $gone) {
        assert_false(str_contains($out, $gone), $gone . ' should be removed');
    }
    assert_contains('application/rss+xml', $out);
    assert_contains('rel="canonical"', $out);
    assert_contains('elsewhere.example/?p=6', $out, 'links to other hosts are left alone');
});

test('Import jobs remember whether to keep scripts, defaulting to the site setting', function (): void {
    $root = scratch_dir();
    mkdir($root . '/edit/storage', 0775, true);
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    assert_false(call_private($importer, 'loadJob', $importer->startImportJob('https://ex.com/'))['keep_scripts']);
    assert_true(call_private($importer, 'loadJob', $importer->startImportJob('https://ex.com/', 5, false, true))['keep_scripts']);
    $importer->setKeepScripts(true);
    assert_true(call_private($importer, 'loadJob', $importer->startImportJob('https://ex.com/'))['keep_scripts']);
    assert_false(call_private($importer, 'loadJob', $importer->startImportJob('https://ex.com/', 5, false, false))['keep_scripts']);
});

test('Import localizes img srcset once and never re-fetches the local copies', function (): void {
    $root = scratch_dir();
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    $local = 'assets/imported/ex.invalid/wp-content/uploads/a.jpg';
    $assetMap = ['https://ex.invalid/wp-content/uploads/a.jpg' => $local];
    $assetsSaved = $assetsFailed = $nonEssential = [];
    $html = '<!DOCTYPE html><html><head></head><body><img src="https://ex.invalid/wp-content/uploads/a.jpg" srcset="https://ex.invalid/wp-content/uploads/a.jpg 700w"></body></html>';
    $method = new \ReflectionMethod($importer, 'rewriteImportedHtmlReferences');
    $result = $method->invokeArgs($importer, [$html, 'https://ex.invalid/', ['ex.invalid'], &$assetMap, &$assetsSaved, &$assetsFailed, &$nonEssential]);
    assert_same(['https://ex.invalid/wp-content/uploads/a.jpg'], array_keys($assetMap));
    assert_same([], $assetsFailed);
    assert_contains('srcset="/' . $local . ' 700w"', $result['html']);
});

test('Import skips unfilled theme placeholder URLs', function (): void {
    $root = scratch_dir();
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    assert_true(call_private($importer, 'isNonEssentialWordPressUrl', 'https://ex.com/about/page/[thrive_page_number'));
    assert_true(call_private($importer, 'isNonEssentialWordPressUrl', 'https://ex.com/page/%5Bthrive_page_number%5D/'));
    assert_false(call_private($importer, 'isNonEssentialWordPressUrl', 'https://ex.com/about/page/2/'));
});

test('An interrupted import retries the URL in progress once, then records it as failed', function (): void {
    $root = scratch_dir();
    mkdir($root . '/edit/storage', 0775, true);
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    $slow = 'https://ex.invalid/slow/';

    $id = $importer->startImportJob('https://ex.invalid/', 5);
    $state = call_private($importer, 'loadJob', $id);
    $state['in_progress'] = $slow;
    $state['seen'][$slow] = true;
    call_private($importer, 'saveJob', $id, $state);
    $started = [];
    $importer->runImportJob($id, 0, function (array $event) use (&$started): void {
        if ($event['type'] === 'page_start') {
            $started[] = $event['url'];
        }
    });
    assert_same($slow, $started[0] ?? null, 'first interruption: the URL is retried first');

    $id = $importer->startImportJob('https://ex.invalid/', 5);
    $state = call_private($importer, 'loadJob', $id);
    $state['in_progress'] = $slow;
    $state['interrupted'] = [$slow => 1];
    call_private($importer, 'saveJob', $id, $state);
    $result = $importer->runImportJob($id, 0)['result'];
    $slowFailures = array_filter($result['failed'], static fn(string $f): bool => str_starts_with($f, $slow . ' - The server stopped twice'));
    assert_same(1, count($slowFailures), 'second interruption: recorded as failed, not retried');
});
