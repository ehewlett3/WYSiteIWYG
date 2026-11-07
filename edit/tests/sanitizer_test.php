<?php
declare(strict_types=1);

use WYSiteIWYG\Sanitizer;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
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
        . '<p>Hi <span class="x" data-note="1">there</span></p></div>';
    assert_same($html, Sanitizer::html($html));
    // Comments never survive in fragments (conditional comments included).
    assert_same($html, Sanitizer::html(str_replace('<p>Hi', '<!--[if lt IE 9]><p>old</p><![endif]--><p>Hi', $html)));
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

test('SEC-1: clean input keeps its meaning on both sanitizer backends', function (): void {
    $html = "<figure class=\"wp-block-embed\">\n  <iframe allowfullscreen src=\"https://www.youtube.com/embed/x\"></iframe>\n</figure>\n<svg viewBox=\"0 0 10 10\"><circle r=\"1\"/></svg>\n<p>caf&eacute; &nbsp; <br/></p>";
    $doc = "<!DOCTYPE html>\n<html lang=\"en\"><head><title>{{TITLE}}</title></head><body class=\"{{BODY_CLASS}}\">{{MAIN_MENU}}</body></html>\n";
    $out = Sanitizer::html($html);
    assert_contains('<iframe allowfullscreen src="https://www.youtube.com/embed/x"></iframe>', $out);
    assert_contains('<circle r="1"', $out);
    assert_contains('café', $out);
    $outDoc = Sanitizer::document($doc);
    assert_contains('<title>{{TITLE}}</title>', $outDoc);
    assert_contains('class="{{BODY_CLASS}}"', $outDoc);
    assert_contains('{{MAIN_MENU}}', $outDoc);
});

test('SEC-1: parser-differential (mXSS) vectors leave nothing executable after a second parse', function (): void {
    $vectors = array_filter(explode("\n", (string) file_get_contents(__DIR__ . '/fixtures/mxss-vectors.txt')), static fn(string $line): bool => $line !== '');
    assert_true(count($vectors) >= 40, 'vector corpus present');
    foreach ($vectors as $vector) {
        $out = Sanitizer::html($vector);
        // What a browser would see: re-parse the output and inspect every element.
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $out . '</body>');
        libxml_clear_errors();
        foreach ((new DOMXPath($dom))->query('//*') as $element) {
            assert_true(strtolower($element->nodeName) !== 'script', 'script survived: ' . $vector);
            foreach ($element->attributes as $attribute) {
                assert_true(!str_starts_with(strtolower($attribute->nodeName), 'on'), 'handler survived: ' . $vector . ' => ' . $out);
            }
        }
        assert_not_contains('javascript:', strtolower(html_entity_decode($out)), $vector);
        foreach (['<noscript', '<noembed', '<noframes', '<xmp', '<plaintext', '<template', '<!--', '<foreignobject', '<annotation-xml', '<mglyph'] as $needle) {
            assert_not_contains($needle, strtolower($out), $vector);
        }
        // Re-sanitizing the output is a no-op (the output is stable).
        assert_same($out, Sanitizer::html($out), 'unstable: ' . $vector);
    }
});

test('SEC-2b: frames pointing back at the site may only show pages or PDFs', function (): void {
    Sanitizer::setSiteHosts(['example.com']);
    try {
        assert_contains('<iframe src="about/"></iframe>', Sanitizer::html('<iframe src="about/"></iframe>'));
        assert_contains('<iframe src="/blog/post.html"></iframe>', Sanitizer::html('<iframe src="/blog/post.html"></iframe>'));
        assert_same('', Sanitizer::html('<iframe src="assets/imported/x/evil.svg"></iframe>'));
        assert_same('', Sanitizer::html('<iframe src="https://example.com/feed/index.xml"></iframe>'));
        assert_same('', Sanitizer::html('<embed src="assets/x.svg">'));
        assert_same('', Sanitizer::html('<object data="//example.com/a.xml"></object>'));
        assert_contains('menu.pdf', Sanitizer::html('<object data="assets/docs/menu.pdf" type="application/pdf"></object>'));
        assert_contains('evil.example', Sanitizer::html('<iframe src="https://evil.example/x.svg"></iframe>'));
    } finally {
        Sanitizer::setSiteHosts([]);
    }
});

test('SEC-1: documents keep only WYSITE marker comments', function (): void {
    $doc = '<!doctype html><html><head><!-- WYSITE:META title="x" --></head><body><!-- note --><!-- WYSITE:BEGIN name="a" type="page" --><p>x</p><!-- WYSITE:END name="a" --><!-- WYSITE:BEGIN <img src=x onerror=alert(1)> --></body></html>';
    $out = Sanitizer::document($doc);
    assert_contains('<!-- WYSITE:META title="x" -->', $out);
    assert_contains('<!-- WYSITE:BEGIN name="a" type="page" -->', $out);
    assert_not_contains('note', $out);
    assert_not_contains('onerror', $out);
});

test('SEC-6: CSS sanitizer resists nesting and escapes', function (): void {
    foreach (['</sty</stylele>', '\\3c /style>', 'a{background:url(java\\script:alert(1))}', '@im\\port url(https://evil.example/x.css);', 'x{behavior:url(a.htc)}', '<!--', '</scr<scriptipt'] as $css) {
        $out = strtolower(Sanitizer::css($css));
        foreach (['</style', '<script', 'javascript:', '@import', 'behavior:', '<!--'] as $needle) {
            assert_not_contains($needle, $out, $css);
        }
    }
    $ok = Sanitizer::css('.card{background:url(data:image/png;base64,AAA);font-family:x} @import url(https://fonts.googleapis.com/css2?family=A);', ['assets/']);
    assert_contains('data:image/png', $ok);
    assert_contains('@import url(https://fonts.googleapis.com/', $ok);
    assert_contains('url(assets/uploads/a.png)', Sanitizer::css('a{background:url(assets/uploads/a.png)}', ['assets/']));
    assert_not_contains('evil', Sanitizer::css('a{background:url(https://evil.example/x.png)}', ['assets/']));
});

test('Sanitizer serializes ignored fragment tokens and cleans document root attributes', function (): void {
    foreach (['<img src=x onerror=alert(1)//', '<body onload=alert(1)>', '<html onmouseover=alert(1)>'] as $input) {
        assert_not_contains('onerror', Sanitizer::html($input));
        assert_not_contains('onload', Sanitizer::html($input));
        assert_not_contains('onmouseover', Sanitizer::html($input));
    }
    $out = Sanitizer::document('<!doctype html><html onmouseover="alert(1)"><head><title>Test</title></head><body><p>Kept</p></body></html>');
    assert_not_contains('onmouseover', $out);
    assert_contains('<p>Kept</p>', $out);
});
