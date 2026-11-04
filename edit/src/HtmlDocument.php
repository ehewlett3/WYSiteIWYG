<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * PLT-2: parse HTML into the same tree a browser builds, so DOM paths, region
 * selectors and structure analysis computed on the server match what the
 * editor and designer compute in the browser. Uses the vendored HTML5 parser
 * (masterminds/html5) and returns the familiar \DOMDocument API; falls back to
 * libxml when the vendor folder is missing (SystemCheck warns about that).
 */
final class HtmlDocument
{
    public static function backend(): string
    {
        return class_exists(\Masterminds\HTML5::class) ? 'html5' : 'libxml';
    }

    /** Start tags that close an open <p> (HTML5 tree construction, "in body"). */
    private const CLOSES_P = [
        'address', 'article', 'aside', 'blockquote', 'center', 'details', 'dialog', 'dir', 'div', 'dl', 'fieldset',
        'figcaption', 'figure', 'footer', 'header', 'hgroup', 'main', 'menu', 'nav', 'ol', 'p', 'search', 'section',
        'summary', 'ul', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'pre', 'listing', 'form', 'table', 'hr', 'xmp',
    ];

    public static function parse(string $html): \DOMDocument
    {
        // PHP 8.4+: let the spec-compliant parser build the browser's tree, then
        // hand its (well-formed) serialization to the legacy-DOM parser.
        if (class_exists(\Dom\HTMLDocument::class) && self::backend() === 'html5') {
            try {
                $spec = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
                $html = $spec->saveHtml();
            } catch (\Throwable) {
                // fall through with the original markup
            }
        }

        if (self::backend() === 'html5') {
            $parser = new \Masterminds\HTML5(['disable_html_ns' => true]);
            $dom = $parser->loadHTML($html);
            if ($dom instanceof \DOMDocument && $dom->documentElement !== null) {
                self::applyImpliedStructure($dom);
                return $dom;
            }
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                $dom->removeChild($child);
            }
        }

        return $dom;
    }

    /**
     * Repair the two tree-construction rules the pure-PHP parser skips, so its
     * tree matches browsers for common markup: rows directly inside <table> get
     * an implied <tbody>, and a <p> holding a block element is closed before it.
     */
    private static function applyImpliedStructure(\DOMDocument $dom): void
    {
        foreach (iterator_to_array($dom->getElementsByTagName('table')) as $table) {
            $run = null;
            foreach (iterator_to_array($table->childNodes) as $child) {
                $isRow = $child instanceof \DOMElement && strtolower($child->localName) === 'tr';
                if ($isRow) {
                    if ($run === null) {
                        $run = $dom->createElement('tbody');
                        $table->insertBefore($run, $child);
                    }
                    $run->appendChild($child);
                } elseif ($child instanceof \DOMElement || ($child instanceof \DOMText && trim($child->textContent) !== '')) {
                    $run = null;
                }
            }
        }

        foreach (array_reverse(iterator_to_array($dom->getElementsByTagName('p'))) as $paragraph) {
            if (!$paragraph instanceof \DOMElement || !$paragraph->parentNode instanceof \DOMNode) {
                continue;
            }
            $split = null;
            foreach ($paragraph->childNodes as $child) {
                if ($child instanceof \DOMElement && in_array(strtolower($child->localName), self::CLOSES_P, true)) {
                    $split = $child;
                    break;
                }
            }
            if ($split === null) {
                continue;
            }
            $after = $paragraph->nextSibling;
            $move = [];
            for ($node = $split; $node !== null; $node = $node->nextSibling) {
                $move[] = $node;
            }
            foreach ($move as $node) {
                $paragraph->parentNode->insertBefore($node, $after);
            }
        }
    }

    /** Serialize a document or node the way it was parsed. */
    public static function serialize(\DOMNode $node): string
    {
        if (self::backend() === 'html5') {
            $parser = new \Masterminds\HTML5(['disable_html_ns' => true]);
            return restore_placeholder_tokens((string) $parser->saveHTML($node));
        }

        $document = $node instanceof \DOMDocument ? $node : $node->ownerDocument;
        $out = (string) $document?->saveHTML($node instanceof \DOMDocument ? null : $node);
        return restore_placeholder_tokens((string) preg_replace('/<\?xml\s+encoding=["\']UTF-8["\']\??>\s*/i', '', $out));
    }

    /** Inner HTML of an element. */
    public static function innerHtml(\DOMElement $element): string
    {
        $html = '';
        foreach ($element->childNodes as $child) {
            $html .= self::serialize($child);
        }

        return $html;
    }

    /**
     * DOM path in the editor's form: [{tag, index}] from <html> down, where
     * index counts same-tag element siblings (1-based), like buildDomPath().
     *
     * @return array<int, array{tag:string, index:int}>
     */
    public static function domPath(\DOMElement $element): array
    {
        $path = [];
        $current = $element;
        while ($current instanceof \DOMElement) {
            $tag = strtolower($current->localName ?? $current->tagName);
            $index = 1;
            for ($sibling = $current->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
                if ($sibling instanceof \DOMElement && strtolower($sibling->localName ?? $sibling->tagName) === $tag) {
                    $index++;
                }
            }
            array_unshift($path, ['tag' => $tag, 'index' => $index]);
            if ($tag === 'html') {
                break;
            }
            $current = $current->parentNode instanceof \DOMElement ? $current->parentNode : null;
        }

        return $path;
    }

    /** Resolve a domPath() result back to an element, or null. */
    public static function elementAt(\DOMDocument $dom, array $path): ?\DOMElement
    {
        $current = $dom->documentElement;
        if (!$current instanceof \DOMElement) {
            return null;
        }
        $segments = array_values($path);
        if (($segments[0]['tag'] ?? '') === strtolower($current->localName ?? $current->tagName)) {
            array_shift($segments);
        }

        foreach ($segments as $segment) {
            $tag = strtolower((string) ($segment['tag'] ?? ''));
            $index = (int) ($segment['index'] ?? 0);
            if ($tag === '' || $index < 1) {
                return null;
            }
            $seen = 0;
            $next = null;
            foreach ($current->childNodes as $child) {
                if ($child instanceof \DOMElement && strtolower($child->localName ?? $child->tagName) === $tag && ++$seen === $index) {
                    $next = $child;
                    break;
                }
            }
            if (!$next instanceof \DOMElement) {
                return null;
            }
            $current = $next;
        }

        return $current;
    }
}
