<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('IMP-5: importing keeps in-content navs and section headings, drops site chrome', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    file_put_contents($site['root'] . '/about.html', '<!doctype html><html><head><title>About us</title></head><body>'
        . '<header class="site-header"><a href="/">Logo</a><nav><a href="/">Home</a><a href="/a/">A</a><a href="/b/">B</a><a href="/c/">C</a></nav></header>'
        . '<main><h2>Our story</h2><p>Founded in 1999.</p><nav class="toc"><a href="#a">Jump</a><a href="#b">Two</a></nav>'
        . '<section><header><h3>Mission</h3></header><p>We help.</p></section></main><footer>(c) 2024</footer></body></html>');
    $site['generator']->importExistingPage('about.html');
    $html = (string) file_get_contents($site['root'] . '/about.html');
    preg_match('#<!-- WYSITE:BEGIN name="page-content".*?-->(.*?)<!-- WYSITE:END#s', $html, $block);
    assert_contains('Mission', $block[1]);
    assert_contains('class="toc"', $block[1]);
    assert_contains('Founded in 1999', $block[1]);
    assert_not_contains('Logo', $block[1]);
    assert_not_contains('(c) 2024', $block[1]);
});

test('IMP-5: a leading <h1> matching the title is not imported twice', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    file_put_contents($site['root'] . '/team.html', '<!doctype html><html><head><title>Our Team | WYSiteIWYG</title></head><body><main><h1>Our Team</h1><p>People.</p></main></body></html>');
    $site['generator']->importExistingPage('team.html');
    $html = (string) file_get_contents($site['root'] . '/team.html');
    assert_same(1, substr_count(strtolower($html), '<h1'), 'one h1 (the template prints {{TITLE}})');
    assert_contains('People.', $html);
});

test('IMP-6: empty content slots are hidden, and shown again once filled', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    file_put_contents($site['root'] . '/single.html', '<!doctype html><html><head><title>Single</title></head><body><main><p>Only block.</p></main></body></html>');
    $site['generator']->importExistingPage('single.html');
    $html = (string) file_get_contents($site['root'] . '/single.html');
    assert_same(2, substr_count($html, 'data-wysite-optional hidden'), 'slots 2 and 3 hidden');
    assert_same(1, substr_count($html, 'data-wysite-optional>'), 'slot 1 visible');

    $site['repository']->updateBlock('single.html', 'page-content-2', '<p>Second.</p>');
    $site['generator']->refreshOptionalSlots('single.html');
    $html = (string) file_get_contents($site['root'] . '/single.html');
    assert_same(1, substr_count($html, 'data-wysite-optional hidden'));
});
