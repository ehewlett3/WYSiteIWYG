<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

/**
 * THM-A: CSS selectors for template regions (menu, content, ...) that keep
 * working on every page of a site, unlike positional DOM paths taken from one
 * page. Generated selectors prefer stable ids, landmark roles and stable class
 * combinations, and fall back to a short path anchored on the nearest stable
 * ancestor.
 *
 * Supported subset (generated and user-entered): tag, #id, .class, [attr],
 * [attr="v"], :nth-of-type(n), descendant (space) and child (>) combinators,
 * and "," alternatives (first that matches wins in match()).
 */
final class RegionSelector
{
    private const VOLATILE_CLASS = '/^(active|current.*|selected|open|opened|show|shown|hidden|visible|collapsed|expanded|is-.*|js-.*|focus.*|hover.*|loaded|lazyloaded|lazyload|wp-image-\d+|post-\d+|postid-\d+|page-id-\d+|page-item-\d+|menu-item-\d+|item-\d+|attachment-.*|size-.*|elementor-element-[0-9a-f]+|elementor-\d+|css-[a-z0-9]{4,}|sc-[a-z]{4,}|jsx-\d+|ng-.*|has-post-thumbnail|format-.*|category-.*|tag-.*|type-.*|status-.*|author-.*|hentry|single-.*|logged-in|admin-bar)$/i';

    /** @return string[] classes of $element that identify structure rather than state */
    public static function stableClasses(\DOMElement $element): array
    {
        $classes = [];
        foreach (preg_split('/\s+/', trim($element->getAttribute('class'))) ?: [] as $class) {
            if ($class === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $class) !== 1) {
                continue;
            }
            if (preg_match(self::VOLATILE_CLASS, $class) === 1 || preg_match('/\d{3,}|[0-9a-f]{6,}/i', $class) === 1) {
                continue;
            }
            $classes[] = $class;
        }

        return array_values(array_unique($classes));
    }

    public static function isStableId(string $id): bool
    {
        return $id !== ''
            && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $id) === 1
            && preg_match('/\d{2,}|[0-9a-f]{6,}|^(post|page|elementor|wp-block|attachment|menu-item|comment)-/i', $id) !== 1;
    }

    /**
     * A selector for $element that matches exactly it in its own document and
     * exactly one element in at least 90% of $otherDocs.
     *
     * @param \DOMDocument[] $otherDocs
     */
    public static function generate(\DOMElement $element, array $otherDocs = []): string
    {
        $doc = $element->ownerDocument;
        if (!$doc instanceof \DOMDocument) {
            throw new RuntimeException('The element is not in a document.');
        }

        foreach (self::ownCandidates($element) as $candidate) {
            if (self::isUnique($candidate, $doc, $element) && self::worksOnOthers($candidate, $otherDocs)) {
                return $candidate;
            }
        }

        // Anchor on the nearest ancestor that has a unique selector of its own.
        $steps = [];
        $current = $element;
        while ($current->parentNode instanceof \DOMElement) {
            $steps[] = self::nthStep($current);
            $parent = $current->parentNode;
            $tag = strtolower($parent->localName ?? $parent->tagName);
            if ($tag === 'html') {
                break;
            }
            foreach ($tag === 'body' ? ['body'] : self::ownCandidates($parent) as $anchor) {
                $candidate = $anchor . ' > ' . implode(' > ', array_reverse($steps));
                if (self::isUnique($candidate, $doc, $element) && self::worksOnOthers($candidate, $otherDocs)) {
                    return $candidate;
                }
                if ($tag === 'body') {
                    return $candidate;
                }
            }
            $current = $parent;
        }

        return 'body > ' . implode(' > ', array_reverse($steps));
    }

    /** @return \DOMElement[] elements matching $selector (first matching alternative) */
    public static function match(string $selector, \DOMDocument $doc): array
    {
        foreach (self::alternatives($selector) as $alternative) {
            $nodes = (new \DOMXPath($doc))->query(self::toXPath($alternative));
            $elements = [];
            foreach ($nodes ?: [] as $node) {
                if ($node instanceof \DOMElement) {
                    $elements[] = $node;
                }
            }
            if ($elements !== []) {
                return $elements;
            }
        }

        return [];
    }

    /** Validate a selector without matching it (throws on unsupported syntax). */
    public static function assertValid(string $selector): void
    {
        foreach (self::alternatives($selector) as $alternative) {
            self::toXPath($alternative);
        }
    }

    public static function toXPath(string $selector): string
    {
        $alternatives = self::alternatives($selector);
        if (count($alternatives) > 1) {
            return implode(' | ', array_map([self::class, 'toXPath'], $alternatives));
        }

        $selector = trim($alternatives[0] ?? '');
        if ($selector === '') {
            throw new RuntimeException('Enter a selector.');
        }

        $xpath = '';
        $axis = '//';
        $pos = 0;
        $length = strlen($selector);
        while ($pos < $length) {
            if (preg_match('/\G\s*>\s*/', $selector, $m, 0, $pos) === 1) {
                $axis = '/';
                $pos += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G\s+/', $selector, $m, 0, $pos) === 1) {
                $axis = '//';
                $pos += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G([a-zA-Z][a-zA-Z0-9-]*|\*)?((?:#[A-Za-z_][A-Za-z0-9_-]*|\.[A-Za-z_][A-Za-z0-9_-]*|\[[a-zA-Z_:][a-zA-Z0-9_:.-]*(?:="[^"]*")?\])*)(?::nth-of-type\((\d+)\))?/', $selector, $m, 0, $pos) !== 1 || $m[0] === '') {
                throw new RuntimeException('Unsupported selector syntax near "' . substr($selector, $pos, 20) . '". Use tags, #id, .class, [attr], :nth-of-type(n), spaces and >.');
            }
            $tag = ($m[1] ?? '') !== '' ? strtolower($m[1]) : '*';
            $nth = $m[3] ?? '';
            if ($nth !== '' && $tag === '*') {
                throw new RuntimeException(':nth-of-type() needs a tag name.');
            }
            $step = $tag . ($nth !== '' ? '[' . (int) $nth . ']' : '');
            preg_match_all('/#([A-Za-z_][A-Za-z0-9_-]*)|\.([A-Za-z_][A-Za-z0-9_-]*)|\[([a-zA-Z_:][a-zA-Z0-9_:.-]*)(?:="([^"]*)")?\]/', (string) ($m[2] ?? ''), $parts, PREG_SET_ORDER);
            foreach ($parts as $part) {
                if (($part[1] ?? '') !== '') {
                    $step .= '[@id="' . $part[1] . '"]';
                } elseif (($part[2] ?? '') !== '') {
                    $step .= '[contains(concat(" ", normalize-space(@class), " "), " ' . $part[2] . ' ")]';
                } elseif (array_key_exists(4, $part)) {
                    if (str_contains($part[4], '"')) {
                        throw new RuntimeException('Attribute values may not contain double quotes.');
                    }
                    $step .= '[@' . strtolower($part[3]) . '="' . $part[4] . '"]';
                } else {
                    $step .= '[@' . strtolower($part[3]) . ']';
                }
            }
            $xpath .= $axis . $step;
            $axis = '//';
            $pos += strlen($m[0]);
        }

        return $xpath;
    }

    /** @return string[] */
    private static function alternatives(string $selector): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $selector)), static fn(string $part): bool => $part !== ''));
    }

    /** @return string[] candidate selectors that describe $element by itself */
    private static function ownCandidates(\DOMElement $element): array
    {
        $tag = strtolower($element->localName ?? $element->tagName);
        $candidates = [];
        $id = $element->getAttribute('id');
        if (self::isStableId($id)) {
            $candidates[] = '#' . $id;
        }
        $role = strtolower(trim($element->getAttribute('role')));
        if (preg_match('/^[a-z]+$/', $role) === 1) {
            $candidates[] = $tag . '[role="' . $role . '"]';
        }
        if (in_array($tag, ['main', 'header', 'footer', 'nav', 'aside', 'article'], true)) {
            $candidates[] = $tag;
        }
        $classes = self::stableClasses($element);
        foreach ($classes as $class) {
            $candidates[] = $tag . '.' . $class;
        }
        $count = count($classes);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $candidates[] = $tag . '.' . $classes[$i] . '.' . $classes[$j];
            }
        }

        return $candidates;
    }

    private static function nthStep(\DOMElement $element): string
    {
        $tag = strtolower($element->localName ?? $element->tagName);
        $index = 1;
        for ($sibling = $element->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
            if ($sibling instanceof \DOMElement && strtolower($sibling->localName ?? $sibling->tagName) === $tag) {
                $index++;
            }
        }
        $classes = self::stableClasses($element);

        return $tag . ($classes !== [] ? '.' . $classes[0] : '') . ':nth-of-type(' . $index . ')';
    }

    private static function isUnique(string $selector, \DOMDocument $doc, \DOMElement $element): bool
    {
        try {
            $matches = self::match($selector, $doc);
        } catch (RuntimeException) {
            return false;
        }

        return count($matches) === 1 && $matches[0]->isSameNode($element);
    }

    /** @param \DOMDocument[] $docs */
    private static function worksOnOthers(string $selector, array $docs): bool
    {
        if ($docs === []) {
            return true;
        }

        $ok = 0;
        foreach ($docs as $doc) {
            if (count(self::match($selector, $doc)) === 1) {
                $ok++;
            }
        }

        return $ok / count($docs) >= 0.9;
    }
}
