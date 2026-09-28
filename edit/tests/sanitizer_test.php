<?php
declare(strict_types=1);

use WYSiteIWYG\Sanitizer;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\test;

test('SEC-4: active content is removed', function (): void {
    $cases = [
        '<img src=x onerror="alert(1)">' => ['onerror', 'alert'],
        '<a href="javascript:alert(1)">x</a>' => ['javascript:'],
        '<a href=" jav&#x09;ascript:alert(1)">x</a>' => ['ascript:alert'],
        '<script>alert(1)</script><p>ok</p>' => ['<script', 'alert'],
        '<iframe srcdoc="<script>alert(1)</script>"></iframe>' => ['srcdoc', 'script'],
        '<svg><script>alert(1)</script><circle r="1"/></svg>' => ['<script', 'alert'],
        '<p>a</p><!-- WYSITE:END name="x" --><p>b</p>' => ['WYSITE:'],
        '<p>a</p><?php system("id"); ?>' => ['<?php', 'system('],
        '<p title="<?php echo 1 ?>">x</p>' => ['<?php'],
        '<form action="javascript:alert(1)"><button formaction="javascript:x">b</button></form>' => ['javascript:', 'formaction'],
        '<object data="javascript:alert(1)"></object><embed src="javascript:alert(1)">' => ['javascript:'],
        '<a href="data:text/html;base64,PHNjcmlwdD4=">x</a>' => ['data:text/html'],
        '<img src="data:image/svg+xml;base64,PHN2Zz4=">' => ['data:image/svg'],
        '<svg><a><animate attributeName="href" values="javascript:alert(1)"/><text>x</text></a></svg>' => ['animate', 'javascript:'],
        '<p style="background:url(javascript:alert(1))">x</p>' => ['javascript:'],
        '<base href="https://evil.example/"><p>x</p>' => ['<base'],
        '<meta http-equiv="refresh" content="0;url=https://evil.example/">' => ['refresh'],
        '<iframe src="javascript:alert(1)"></iframe>' => ['javascript:'],
        '<link rel="import" href="x.html"><p>x</p>' => ['import'],
    ];

    foreach ($cases as $input => $forbidden) {
        $out = Sanitizer::html($input);
        foreach ($forbidden as $needle) {
            assert_not_contains($needle, $out, 'Input: ' . $input);
        }
    }
});

test('SEC-4: safe content survives', function (): void {
    $html = '<p><a href="https://example.com/a?b=1">link</a> <a href="mailto:a@b.c">mail</a> <a href="#top">top</a></p>'
        . '<img src="assets/uploads/x.png" alt="x" srcset="assets/a.png 1x, assets/b.png 2x">'
        . '<img src="data:image/png;base64,iVBORw0KGgo=">'
        . '<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>'
        . '<figure><img src="a.jpg"><figcaption>Cap</figcaption></figure>'
        . '<audio controls src="assets/a.mp3"></audio><dl><dt>t</dt><dd>d</dd></dl>';
    $out = Sanitizer::html($html);
    assert_contains('href="https://example.com/a?b=1"', $out);
    assert_contains('href="mailto:a@b.c"', $out);
    assert_contains('href="#top"', $out);
    assert_contains('src="assets/uploads/x.png"', $out);
    assert_contains('srcset="assets/a.png 1x, assets/b.png 2x"', $out);
    assert_contains('data:image/png;base64', $out);
    assert_contains('<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe>', $out);
    assert_contains('<figcaption>Cap</figcaption>', $out);
    assert_contains('<audio controls', $out);
    assert_contains('<dd>d</dd>', $out);
});

test('SEC-4: styling and structure round-trip unchanged', function (): void {
    $html = '<link rel="stylesheet" id="wp-block-css" href="assets/imported/x/block.css" media="all">'
        . '<style>.hero{color:red}</style>'
        . '<div class="wp-block-group has-background" style="background:#fff;padding:2em" data-align="wide">'
        . '<meta itemprop="datePublished" content="2024-01-01">'
        . '<iframe src="https://maps.example.org/embed?q=1" width="600" height="450" style="border:0"></iframe>'
        . '<object data="assets/docs/menu.pdf" type="application/pdf"></object>'
        . '<form action="https://forms.example.com/submit" method="post"><input name="email" type="email"><button>Go</button></form>'
        . '<!--[if lt IE 9]><p>old</p><![endif]-->'
        . '<p>Hi <span class="x" data-note="1">there</span></p></div>';
    assert_same($html, Sanitizer::html($html));
});

test('SEC-4: document mode keeps head assets, markers and tokens', function (): void {
    $doc = '<!doctype html><html><head><!-- WYSITE:META kind="page" --><meta charset="utf-8"><meta http-equiv="refresh" content="0"><link rel="stylesheet" href="{{THEME_CSS_HREF}}"><script src="x.js"></script></head>'
        . '<body class="{{BODY_CLASS}}" onload="x()"><!-- WYSITE:BEGIN name="main-menu" type="menu" -->{{MAIN_MENU}}<!-- WYSITE:END name="main-menu" --></body></html>';
    $out = Sanitizer::document($doc);
    assert_same(0, strpos($out, '<!doctype html>'));
    assert_contains('href="{{THEME_CSS_HREF}}"', $out);
    assert_contains('WYSITE:BEGIN name="main-menu"', $out);
    assert_contains('WYSITE:META', $out);
    assert_contains('<meta charset="utf-8">', $out);
    assert_not_contains('refresh', $out);
    assert_not_contains('<script', $out);
    assert_not_contains('onload', $out);
});

test('SEC-4: safe input is returned byte-for-byte (no libxml reformatting)', function (): void {
    $html = "<figure class=\"wp-block-embed\">\n  <iframe allowfullscreen src=\"https://www.youtube.com/embed/x\"></iframe>\n</figure>\n<svg viewBox=\"0 0 10 10\"><circle r=\"1\"/></svg>\n<p>caf&eacute; &nbsp; <br/></p>";
    assert_same($html, Sanitizer::html($html));
    $doc = "<!DOCTYPE html>\n<html lang=\"en\"><head><title>{{TITLE}}</title></head><body class=\"{{BODY_CLASS}}\">{{MAIN_MENU}}</body></html>\n";
    assert_same($doc, Sanitizer::document($doc));
});
