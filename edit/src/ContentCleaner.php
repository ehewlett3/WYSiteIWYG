<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * THM-E: optional clean-up of imported content so any theme can style it:
 * wrapper elements that only carry page-builder classes are unwrapped, empty
 * elements dropped, and fixed pixel widths/heights removed from block elements.
 * Semantic markup (headings, paragraphs, lists, figures, tables, images) and
 * every attribute on it are kept.
 */
final class ContentCleaner
{
    private const BUILDER_CLASS = '/^(elementor|et_pb_|et-|vc_|wpb_|fl-|fusion-|avia-|tdb-|td_|x-|brz-|wp-block-group__inner-container$|wp-block-group$|row$|col-|column$|columns$|container$|inner$|wrapper$|wrap$)/i';
    private const BLOCK_TAGS = ['div', 'section', 'article', 'figure', 'p', 'table', 'ul', 'ol', 'blockquote', 'img', 'iframe', 'video'];

    public static function clean(string $html): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $dom = HtmlDocument::parse('<!doctype html><html><body>' . $html . '</body></html>');
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return $html;
        }

        for ($pass = 0; $pass < 5; $pass++) {
            $changed = false;
            foreach (array_reverse(iterator_to_array($body->getElementsByTagName('*'))) as $element) {
                if (!$element instanceof \DOMElement || !$element->parentNode instanceof \DOMNode) {
                    continue;
                }
                $tag = strtolower($element->localName);
                if (in_array($tag, ['div', 'span', 'section'], true) && self::isBuilderWrapper($element)) {
                    while ($element->firstChild !== null) {
                        $element->parentNode->insertBefore($element->firstChild, $element);
                    }
                    $element->parentNode->removeChild($element);
                    $changed = true;
                    continue;
                }
                if (in_array($tag, ['div', 'span', 'section', 'p'], true) && self::isEmpty($element)) {
                    $element->parentNode->removeChild($element);
                    $changed = true;
                    continue;
                }
                if ($element->hasAttribute('style') && in_array($tag, self::BLOCK_TAGS, true)) {
                    $style = (string) preg_replace('/(?:^|;)\s*(?:min-|max-)?(?:width|height)\s*:\s*[\d.]+px\s*(?=;|$)/i', '', $element->getAttribute('style'));
                    $style = trim($style, " ;");
                    $style === '' ? $element->removeAttribute('style') : $element->setAttribute('style', $style);
                }
            }
            if (!$changed) {
                break;
            }
        }

        return trim(HtmlDocument::innerHtml($body));
    }

    private static function isBuilderWrapper(\DOMElement $element): bool
    {
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if ($name === 'class' || str_starts_with($name, 'data-')) {
                continue;
            }
            return false; // id, style, role, aria-*: keep the element
        }
        $classes = preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [];
        foreach ($classes as $class) {
            if ($class !== '' && preg_match(self::BUILDER_CLASS, $class) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function isEmpty(\DOMElement $element): bool
    {
        if (trim(str_replace("\xC2\xA0", '', $element->textContent)) !== '') {
            return false;
        }
        $xpath = new \DOMXPath($element->ownerDocument);
        return ($xpath->query('.//img | .//picture | .//video | .//audio | .//iframe | .//svg | .//object | .//embed | .//input | .//hr | .//br', $element)?->length ?? 0) === 0;
    }
}
