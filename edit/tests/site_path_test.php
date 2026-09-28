<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('SEC-1: unsafe page paths are rejected', function (): void {
    $site = make_site();
    file_put_contents($site['edit'] . '/x.html', 'secret');
    file_put_contents($site['edit'] . '/storage/ai.local.php', '<?php return ["api_key" => "sk-secret"];');
    file_put_contents($site['root'] . '/.htaccess', 'Options -Indexes');
    file_put_contents($site['root'] . '/x.php', '<?php echo 1;');
    $sitePath = $site['repository']->sitePath();

    $unsafe = [
        './edit/x.html',
        'a/../edit/x.html',
        'EDIT/x.html',
        '.htaccess',
        'x.php',
        './edit/storage/ai.local.php',
        rawurldecode('%2e/edit/x.html'),
        rawurldecode('%2e%2e/etc/passwd.html'),
        "index.html\0.php",
        'a//b.html',
    ];
    foreach ($unsafe as $path) {
        assert_throws(fn() => $sitePath->resolvePage($path, false), '', RuntimeException::class);
        assert_throws(fn() => $site['repository']->getPage($path), '', RuntimeException::class);
    }
});

test('SEC-1: symlink escapes are rejected', function (): void {
    $site = make_site();
    $outside = \WYSiteIWYG\Tests\scratch_dir();
    file_put_contents($outside . '/leak.html', 'outside');
    symlink($outside, $site['root'] . '/linked');
    symlink($site['edit'] . '/templates', $site['root'] . '/tpl');

    $sitePath = $site['repository']->sitePath();
    assert_throws(fn() => $sitePath->resolvePage('linked/leak.html'), 'Unsafe');
    assert_throws(fn() => $sitePath->resolvePage('tpl/page.html'), 'Unsafe');
    assert_throws(fn() => $sitePath->resolvePage('linked/new.html', false), 'Unsafe');
});

test('SEC-1: normal page paths still resolve', function (): void {
    $site = make_site();
    mkdir($site['root'] . '/about');
    mkdir($site['root'] . '/blog');
    foreach (['index.html', 'about/team.html', 'blog/index.html'] as $path) {
        file_put_contents($site['root'] . '/' . $path, '<html></html>');
        assert_same($site['root'] . '/' . $path, $site['repository']->sitePath()->resolvePage($path));
    }
    assert_same($site['root'] . '/index.html', $site['repository']->resolvePath(''));
    assert_same($site['root'] . '/new/deep/page.html', $site['repository']->resolvePath('new/deep/page.html'));
    assert_same('about/team.html', $site['repository']->relativeFromRequestPath('/about/team/'));
    assert_same(null, $site['repository']->relativeFromRequestPath('/.htaccess'));
});

test('SEC-1: selector saves cannot plant PHP processing instructions', function (): void {
    $site = make_site();
    file_put_contents($site['root'] . '/page.html', '<!doctype html><html><body><main><p>Hi</p></main></body></html>');
    $site['repository']->updateSelectedElement('page.html', [['tag' => 'html', 'index' => 1], ['tag' => 'body', 'index' => 1], ['tag' => 'main', 'index' => 1]], '<p>ok</p><?php system("id"); ?>');
    $saved = (string) file_get_contents($site['root'] . '/page.html');
    assert_contains('<p>ok</p>', $saved);
    // Direct repository calls neutralize the open tag to inert text; the HTTP
    // endpoints also run the Sanitizer, which drops it entirely.
    assert_not_contains('<?php', $saved);
    assert_not_contains('<?', $saved);
});
