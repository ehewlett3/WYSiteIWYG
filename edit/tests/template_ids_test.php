<?php
declare(strict_types=1);

use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('THM-H: the preview tags template elements, and edits by id land on the right element', function (): void {
    $site = make_site();
    $site['generator']->applyTheme('verdant-sanctuary');
    $site['generator']->settings()->update(['footer_html' => '<p class="from-settings">Footer text</p>']);
    $path = $site['generator']->createPage('About', 'about');
    $site['repository']->updateBlock($path, 'page-content', '<div class="inside-block"><p>Content</p></div>');

    $html = (string) $site['generator']->renderPageWithTemplateIds($path);
    preg_match('/<div class="site-footer__inner" data-wysite-tid="(\d+)"/', str_replace(' data-wysite-tid', ' data-wysite-tid', $html), $m)
        ?: preg_match('/<div data-wysite-tid="(\d+)" class="site-footer__inner"/', $html, $m);
    assert_same(true, isset($m[1]), 'footer wrapper carries a template id');
    assert_contains('<p class="from-settings">Footer text</p>', $html, 'token output has no id');
    assert_not_contains('inside-block" data-wysite-tid', $html);
    assert_not_contains('<div data-wysite-tid="', (string) preg_replace('/.*WYSITE:BEGIN name="page-content"(.*?)WYSITE:END.*/s', '$1', $html), 'block content has no ids');

    $template = $site['root'] . '/edit/templates/page.html';
    $hash = sha1((string) file_get_contents($template));
    $site['repository']->updateActiveTemplateElementByTid('page', (int) $m[1], "\n        {{FOOTER}}<p>Extra line</p>\n      ", $hash);
    $updated = (string) file_get_contents($template);
    assert_contains('{{FOOTER}}<p>Extra line</p>', $updated);
    assert_not_contains('data-wysite-tid', $updated);
    assert_throws(static fn() => $site['repository']->updateActiveTemplateElementByTid('page', (int) $m[1], '<p>x</p>', $hash), 'changed since');
});
