<?php
declare(strict_types=1);

use WYSiteIWYG\HtmlDocument;
use WYSiteIWYG\RegionSelector;
use WYSiteIWYG\SiteStructureAnalyzer;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\test;

/** A WordPress-like page: stable chrome, varying title/body, volatile menu classes. */
function structure_wp_page(int $id, string $title, string $body, int $current, bool $broken = false): string
{
    $items = '';
    foreach (['Home' => './', 'About' => 'about/', 'Blog' => 'blog/', 'Visit' => 'visit/', 'Contact' => 'contact/'] as $i => $label) {
        $items .= '<li class="menu-item menu-item-' . (100 + crc32($i) % 50) . ($current === crc32($i) % 5 ? ' current-menu-item' : '') . '"><a href="' . $label . '">' . $i . '</a></li>';
    }
    $article = $broken
        ? '<div class="post-body"><p>' . $body . '</p></div>'
        : '<article id="post-' . $id . '" class="post-' . $id . ' page type-page"><header class="entry-header"><h1 class="entry-title">' . $title . '</h1></header><div class="entry-content">' . $body . '</div></article>';
    return '<!doctype html><html><head><title>' . $title . ' | Example Parish</title></head><body class="page-id-' . $id . '">'
        . '<header id="masthead" class="site-header"><div class="site-branding"><a href="./">Example Parish</a></div>'
        . '<nav id="site-navigation" class="main-navigation"><ul id="primary-menu" class="menu">' . $items . '</ul></nav></header>'
        . '<div id="content" class="site-content"><main id="primary" class="site-main">' . $article . '</main>'
        . '<aside id="secondary" class="widget-area"><section class="widget"><h2 class="widget-title">Recent</h2><ul><li><a href="news/">News</a></li></ul></section></aside></div>'
        . '<footer id="colophon" class="site-footer"><p>© Example Parish · 12 Church Lane</p></footer></body></html>';
}

test('THM-A: generated selectors prefer stable ids and classes and skip volatile ones', function (): void {
    $dom = HtmlDocument::parse('<html><body><div id="post-123" class="post-123 entry active"><p class="lead is-open">x</p></div><nav id="site-nav"><ul class="menu current-menu"><li>a</li></ul></nav></body></html>');
    $div = $dom->getElementsByTagName('div')->item(0);
    $ul = $dom->getElementsByTagName('ul')->item(0);
    assert_same('div.entry', RegionSelector::generate($div));
    assert_same('ul.menu', RegionSelector::generate($ul));
    assert_same('#site-nav', RegionSelector::generate($dom->getElementsByTagName('nav')->item(0)));
    assert_same([], array_intersect(RegionSelector::stableClasses($div), ['post-123', 'active']));
    assert_same(1, count(RegionSelector::match('main, div.entry > p.lead', $dom)));
    assert_same('//ul[contains(concat(" ", normalize-space(@class), " "), " menu ")]/li[2]', RegionSelector::toXPath('ul.menu > li:nth-of-type(2)'));
    assert_throws(static fn() => RegionSelector::toXPath('div:hover'), 'Unsupported selector');
    assert_throws(static fn() => RegionSelector::toXPath(':nth-of-type(2)'), 'needs a tag');
});

test('THM-B: the analyzer finds menu, content and title across a site and flags odd pages', function (): void {
    $pages = [];
    $texts = ['Our history goes back to 1887 when the first stones were laid by the founding families of the valley.', 'Visit us on Sundays at ten. Parking is available behind the hall, and the side entrance has step-free access.', 'Contact the office by phone or email; we usually answer within two working days, except in August.', 'The choir rehearses on Thursdays. New voices are welcome, and no audition is needed for the parish choir.', 'Our food bank opens on Tuesdays and Fridays from nine until noon in the parish hall by the car park.'];
    foreach (['about', 'visit', 'contact', 'choir', 'food-bank'] as $i => $slug) {
        $pages[$slug . '/index.html'] = structure_wp_page(10 + $i, ucfirst(str_replace('-', ' ', $slug)), '<p>' . $texts[$i] . '</p><p>' . strrev($texts[$i]) . '</p>', $i);
    }
    $pages['odd/index.html'] = structure_wp_page(99, 'Odd', 'A page whose template lost its entry-content wrapper, with enough text to matter for the analysis.', 0, true);

    $analysis = (new SiteStructureAnalyzer())->analyze($pages);
    assert_same(6, $analysis['pages_total']);
    $cluster = $analysis['clusters'][0];
    assert_same('page', $cluster['kind']);
    assert_same(6, count($cluster['pages']));

    assert_same('#primary-menu', $cluster['roles']['main-menu']['selector']);
    assert_true($cluster['roles']['main-menu']['confidence'] >= 0.9);
    $content = $cluster['roles']['page-content']['selector'];
    assert_contains('entry-content', $content);
    assert_same(5, $cluster['roles']['page-content']['matches']['1']);
    assert_false($cluster['roles']['title']['renders_in_content'], 'the h1 sits outside .entry-content');

    $flagged = array_column($cluster['attention'], 'page');
    assert_same(['odd/index.html'], array_values(array_unique($flagged)));
});

test('THM-B: a single page falls back to landmarks with low confidence', function (): void {
    $analysis = (new SiteStructureAnalyzer())->analyze(['index.html' => structure_wp_page(1, 'Home', '<p>Welcome to the parish website.</p>', 0)]);
    $roles = $analysis['clusters'][0]['roles'];
    assert_same('#primary-menu', $roles['main-menu']['selector']);
    assert_contains('entry-content', $roles['page-content']['selector']);
    assert_true($roles['page-content']['confidence'] < 0.75);
});
