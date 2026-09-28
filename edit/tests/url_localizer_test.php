<?php
declare(strict_types=1);

use WYSiteIWYG\UrlLocalizer;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('UrlLocalizer: relative prefix follows clean-URL depth', function (): void {
    assert_same('./', UrlLocalizer::relativePrefix('index.html'));
    assert_same('../', UrlLocalizer::relativePrefix('about.html'));
    assert_same('../', UrlLocalizer::relativePrefix('blog/index.html'));
    assert_same('../../', UrlLocalizer::relativePrefix('about/team.html'));
    assert_same('../../', UrlLocalizer::relativePrefix('about/team/index.html'));
});

test('UrlLocalizer: absolute internal URLs become base-relative', function (): void {
    assert_same('assets/site.css', UrlLocalizer::toBaseRelative('/assets/site.css'));
    assert_same('./', UrlLocalizer::toBaseRelative('/'));
    assert_same('https://ex.com/a', UrlLocalizer::toBaseRelative('https://ex.com/a'));
    assert_same('//cdn.ex.com/a', UrlLocalizer::toBaseRelative('//cdn.ex.com/a'));
    assert_same('blog/', UrlLocalizer::toBaseRelative('/sub/blog/', '/sub/'));
});

test('UrlLocalizer: localizeHtml injects a single relative base', function (): void {
    $html = '<html><head><base href="/old/"><link href="/assets/site.css"></head><body><img srcset="/a.png 1x, /b.png 2x"></body></html>';
    $out = UrlLocalizer::localizeHtml($html, 'about/team.html');
    assert_contains('<base href="../../">', $out);
    assert_not_contains('/old/', $out);
    assert_contains('href="assets/site.css"', $out);
    assert_contains('srcset="a.png 1x, b.png 2x"', $out);
});

test('UrlLocalizer: stylesheet url() refs resolve from the stylesheet directory', function (): void {
    $css = 'body{background:url("/assets/img/bg.png")} .x{background:url(https://ex.com/y.png)}';
    $out = UrlLocalizer::localizeCssUrls($css, 'assets');
    assert_contains('url("img/bg.png")', $out);
    assert_contains('url(https://ex.com/y.png)', $out);
});

test('renderKind: scalar metadata tokens are HTML-escaped', function (): void {
    $site = make_site();
    $path = $site['generator']->createPage('<script>alert(1)</script> "quoted"', 'xss-title');
    $html = (string) file_get_contents($site['root'] . '/' . $path);
    assert_not_contains('<script>alert(1)</script>', $html);
    assert_contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    assert_contains('&quot;quoted&quot;', $html);
});
