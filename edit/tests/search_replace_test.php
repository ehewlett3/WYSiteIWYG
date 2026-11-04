<?php
declare(strict_types=1);

use WYSiteIWYG\SearchReplace;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('UX-5: search & replace changes text and links in blocks, not markup or raw blocks', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    $a = $site['generator']->createPage('A', 'a');
    $b = $site['generator']->createPage('B', 'b');
    $site['repository']->updateBlock($a, 'page-content', '<p class="old-site">Visit old-site.example today</p><a href="https://old-site.example/x/">link</a><img src="https://old-site.example/i.png" alt="old-site.example logo">');
    $site['repository']->updateBlock($b, 'page-content', '<p>Nothing here</p>');
    $site['repository']->updateBlock($b, 'page-content-2', '<p>old-site.example</p>');
    $site['repository']->updateMetadata($b, ['admin_blocks' => 'page-content-2']);

    $sr = new SearchReplace($site['repository'], $site['generator']);
    $preview = $sr->run(['find' => 'old-site.example', 'replace' => 'new.example', 'text' => true, 'links' => true], false);
    assert_same(1, count($preview));
    assert_same(4, $preview[0]['count']);
    assert_contains('old-site.example', (string) file_get_contents($site['root'] . '/' . $a), 'preview changes nothing');

    $sr->run(['find' => 'old-site.example', 'replace' => 'new.example', 'text' => true, 'links' => true], true);
    $html = (string) file_get_contents($site['root'] . '/' . $a);
    assert_contains('class="old-site"', $html, 'attributes outside the chosen ones are untouched');
    assert_contains('Visit new.example today', $html);
    assert_contains('href="https://new.example/x/"', $html);
    assert_contains('alt="new.example logo"', $html);
    assert_contains('old-site.example', (string) file_get_contents($site['root'] . '/' . $b), 'raw-HTML blocks are skipped');

    $links = $sr->run(['find' => 'https://(new)\.example', 'replace' => 'https://www.$1.example', 'regex' => true, 'links' => true], true);
    assert_same(2, $links[0]['count']);
    assert_contains('href="https://www.new.example/x/"', (string) file_get_contents($site['root'] . '/' . $a));
});
