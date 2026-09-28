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
