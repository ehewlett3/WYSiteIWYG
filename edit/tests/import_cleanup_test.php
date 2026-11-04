<?php
declare(strict_types=1);

use WYSiteIWYG\AssetIndex;
use WYSiteIWYG\ImportDedupe;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\scratch_dir;
use function WYSiteIWYG\Tests\test;

test('IMP-4: duplicates from older imports merge, references follow, undo restores', function (): void {
    $root = scratch_dir();
    mkdir($root . '/edit/storage', 0775, true);
    $put = static function (string $path, string $contents) use ($root): void {
        @mkdir(dirname($root . '/' . $path), 0775, true);
        file_put_contents($root . '/' . $path, $contents);
    };
    $put('assets/imported/example.com/wp-content/uploads/a.jpg', 'AAAA');
    $put('assets/imported/www.example.com/wp-content/uploads/a.jpg', 'AAAA');
    $put('assets/imported/example.com/wp-content/uploads/a-ver-6.4-12345678.jpg', 'AAAA');
    $put('assets/imported/example.com/wp-content/uploads/other.jpg', 'BBBB');
    $put('assets/imported/example.com/wp-content/themes/t/style.css', 'a{background:url(../../uploads/a-ver-6.4-12345678.jpg)} b{background:url("/assets/imported/www.example.com/wp-content/uploads/a.jpg")}');
    $page = '<html><body><img src="assets/imported/www.example.com/wp-content/uploads/a.jpg"><img src="/assets/imported/example.com/wp-content/uploads/a-ver-6.4-12345678.jpg"><img src="assets/imported/example.com/wp-content/uploads/other.jpg"></body></html>';
    $put('post/index.html', $page);
    $put('post/index-replytocom-42-dc6d7f46.html', $page);
    $put('other-app/index.html', '<img src="assets/imported/www.example.com/wp-content/uploads/a.jpg">');
    $manifest = ['post/index.html' => 'x', 'post/index-replytocom-42-dc6d7f46.html' => 'x'];

    $dedupe = new ImportDedupe($root, $root . '/edit', new AssetIndex($root . '/edit/storage/import-assets.local.json', $root), static fn(): array => array_keys($manifest));
    $scan = $dedupe->scan();
    assert_same(1, count($scan['groups']));
    assert_same('assets/imported/example.com/wp-content/uploads/a.jpg', $scan['groups'][0]['keeper']);
    assert_same(1, count($scan['pages']));

    $result = $dedupe->apply($scan);
    assert_same(2, $result['files_moved']);
    assert_false(is_file($root . '/assets/imported/www.example.com/wp-content/uploads/a.jpg'));
    $post = (string) file_get_contents($root . '/post/index.html');
    assert_same(2, substr_count($post, 'assets/imported/example.com/wp-content/uploads/a.jpg'), 'both references point at the keeper');
    assert_not_contains('www.example.com', $post);
    $css = (string) file_get_contents($root . '/assets/imported/example.com/wp-content/themes/t/style.css');
    assert_contains('url(../../uploads/a.jpg)', $css);
    assert_contains('/assets/imported/example.com/wp-content/uploads/a.jpg', $css);
    assert_contains('refresh', (string) file_get_contents($root . '/post/index-replytocom-42-dc6d7f46.html'));
    assert_contains('www.example.com', (string) file_get_contents($root . '/other-app/index.html'), 'other apps are left alone');

    $restored = $dedupe->undo((string) $dedupe->lastRun());
    assert_true($restored >= 4);
    assert_true(is_file($root . '/assets/imported/www.example.com/wp-content/uploads/a.jpg'));
    assert_same($page, file_get_contents($root . '/post/index.html'));
    assert_same($page, file_get_contents($root . '/post/index-replytocom-42-dc6d7f46.html'));
    assert_same(null, $dedupe->lastRun());
});
