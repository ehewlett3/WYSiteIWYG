<?php
declare(strict_types=1);

use WYSiteIWYG\HtmlSource;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\fixture;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

function splice_path(array $segments): array
{
    return array_map(static fn(array $s): array => ['tag' => $s[0], 'index' => $s[1]], $segments);
}

test('DATA-3: template-scope selector edit leaves every other byte unchanged', function (): void {
    $site = make_site();
    $original = fixture('selector-template.html');
    file_put_contents($site['edit'] . '/templates/page.html', $original);

    $site['repository']->updateSelectedElementInActiveTemplate('page', splice_path([['html', 1], ['body', 1], ['main', 1], ['section', 2]]), '<p>New second</p>');

    $expected = str_replace('<section class="second"><p>Second</p></section>', '<section class="second"><p>New second</p></section>', $original);
    assert_same($expected, file_get_contents($site['edit'] . '/templates/page.html'));
});

test('DATA-3: nested same-name elements and raw-text scripts are skipped correctly', function (): void {
    $site = make_site();
    $original = fixture('selector-template.html');
    file_put_contents($site['root'] . '/p.html', $original);

    $site['repository']->updateSelectedElement('p.html', splice_path([['html', 1], ['body', 1], ['main', 1], ['section', 1]]), '<p>Replaced</p>');
    $expected = str_replace('<section class="intro"><p>Old intro</p><section class="nested"><p>Nested</p></section></section>', '<section class="intro"><p>Replaced</p></section>', $original);
    assert_same($expected, file_get_contents($site['root'] . '/p.html'));

    $site['repository']->updateSelectedElement('p.html', splice_path([['html', 1], ['body', 1], ['header', 1]]), '<p>Head</p>');
    assert_same(1, substr_count((string) file_get_contents($site['root'] . '/p.html'), '<header class=\'site-header\' data-x="a > b"><p>Head</p></header>'));
});

test('DATA-3: fallback DOM save keeps doctype and tokens', function (): void {
    $site = make_site();
    // No <tbody> in the source: libxml invents none in HTML mode, but an implicit
    // </li> makes the splice ambiguous and forces the DOM fallback.
    $original = "<!doctype html>\n<html><head><link rel=\"stylesheet\" href=\"{{THEME_CSS_HREF}}\"></head><body><ul><li>One<li>Two</ul></body></html>\n";
    file_put_contents($site['root'] . '/f.html', $original);
    $site['repository']->updateSelectedElement('f.html', splice_path([['html', 1], ['body', 1], ['ul', 1], ['li', 1]]), 'Uno');
    $saved = (string) file_get_contents($site['root'] . '/f.html');
    assert_same(0, strpos($saved, "<!doctype html>\n"));
    assert_same(1, substr_count($saved, 'href="{{THEME_CSS_HREF}}"'));
    assert_same(1, substr_count($saved, '<li>Uno</li>'));
});

test('HtmlSource: counts ignore comments and raw text', function (): void {
    $html = '<!-- <p> --><p>a</p><script>"<p>"</script><style>p{}</style><p>b</p>';
    assert_same(2, HtmlSource::countStartTags($html, 'p'));
    assert_same([strlen('<!-- <p> --><p>'), strlen('<!-- <p> --><p>a')], HtmlSource::innerRange($html, 'p', 0));
});
