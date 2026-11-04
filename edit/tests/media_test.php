<?php
declare(strict_types=1);

use WYSiteIWYG\MediaLibrary;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('UX-4: the media library counts uses (pages and CSS) and only trashes unused files', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    @mkdir($site['root'] . '/assets/uploads/2026/11', 0775, true);
    @mkdir($site['root'] . '/assets/imported/x/css', 0775, true);
    file_put_contents($site['root'] . '/assets/uploads/2026/11/used.png', 'P');
    file_put_contents($site['root'] . '/assets/uploads/2026/11/unused.png', 'P');
    file_put_contents($site['root'] . '/assets/imported/x/bg.jpg', 'J');
    file_put_contents($site['root'] . '/assets/imported/x/css/style.css', 'body{background:url(../bg.jpg)}');
    $page = $site['generator']->createPage('Media', 'media');
    $site['repository']->updateBlock($page, 'page-content', '<img src="assets/uploads/2026/11/used.png" alt="">');

    $library = new MediaLibrary($site['root'], $site['edit']);
    $byPath = array_column($library->list()['items'], null, 'path');
    assert_same(1, $byPath['assets/uploads/2026/11/used.png']['uses']);
    assert_same(0, $byPath['assets/uploads/2026/11/unused.png']['uses']);
    assert_true($byPath['assets/imported/x/bg.jpg']['uses'] > 0, 'CSS url() counts as a use');
    assert_same(['assets/uploads/2026/11/unused.png'], array_column($library->list('image', true)['items'], 'path'));

    $moved = (new MediaLibrary($site['root'], $site['edit']))->trashUnused(['assets/uploads/2026/11/used.png', 'assets/uploads/2026/11/unused.png']);
    assert_same(1, $moved);
    assert_true(is_file($site['root'] . '/assets/uploads/2026/11/used.png'));
});

test('UX-6: revision diff shows added and removed lines with context', function (): void {
    $rows = MediaLibrary::diff('<p>One</p><p>Two</p><p>Three</p>', '<p>One</p><p>2</p><p>Three</p>');
    $kinds = array_column($rows, 0);
    assert_true(in_array('add', $kinds, true) && in_array('del', $kinds, true));
    assert_same([], MediaLibrary::diff('<p>Same</p>', '<p>Same</p>'));
});
