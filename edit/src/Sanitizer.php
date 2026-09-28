<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * Server-side HTML sanitizer. Public pages share an origin with /edit/, so any
 * script that reaches a page can act as a signed-in admin who views it. Every
 * path that writes editor- or import-supplied HTML into the site runs it through
 * here; the browser-side ProseMirror schema is a convenience, not a boundary.
 *
 * It removes only what can execute or hijack the page: <script>, on* handlers,
 * javascript:/vbscript:/non-image data: URLs, srcdoc, <base>, http-equiv <meta>,
 * PHP processing instructions, and (in fragments) injected WYSITE markers.
 * Styling and structure are deliberately preserved — <style>, stylesheet links,
 * classes, inline styles, data-* attributes, iframes, audio/video, forms — so
 * editing a block in place never changes how existing content looks.
 *
 * html() handles fragments; document() handles whole template documents, where
 * WYSITE block markers must survive. $profile is kept for callers that want to
 * label the source ('content', 'import'); both apply the same rules.
 */
final class Sanitizer
{
    /** Elements removed together with everything inside them. */
    private const DROP_ELEMENTS = [
        'script', 'base', 'applet', 'frame', 'frameset', 'portal',
    ];

    private const URL_ATTRIBUTES = [
        'href', 'src', 'action', 'poster', 'xlink:href', 'background', 'cite', 'longdesc', 'data', 'lowsrc', 'dynsrc', 'ping',
    ];

    private const DEFAULT_EMBED_HOSTS = [
        'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'player.vimeo.com', 'www.google.com', 'maps.google.com', 'calendar.google.com', 'docs.google.com',
        'open.spotify.com', 'w.soundcloud.com', 'embed.podcasts.apple.com', 'www.openstreetmap.org',
        'player.simplecast.com', 'bandcamp.com', 'www.loom.com', 'fast.wistia.net',
    ];

    /** @var string[]|null */
    private static ?array $embedHosts = null;

    /** Nodes/attributes removed by the current call (0 = input was already safe). */
    private static int $removed = 0;

    /**
     * Replace the list of known embed hosts (from Site settings). Iframes from
     * other hosts are kept — a cross-origin frame can't reach this origin — but
     * the migration report flags them for review.
     */
    public static function setEmbedHosts(array $hosts): void
    {
        $clean = [];
        foreach ($hosts as $host) {
            $host = strtolower(trim((string) $host));
            if ($host !== '' && preg_match('/^[a-z0-9.-]+$/', $host) === 1) {
                $clean[] = $host;
            }
        }
        self::$embedHosts = array_values(array_unique($clean));
    }

    /** @return string[] */
    public static function embedHosts(): array
    {
        return self::$embedHosts ?? self::DEFAULT_EMBED_HOSTS;
    }

    /** @return string[] */
    public static function defaultEmbedHosts(): array
    {
        return self::DEFAULT_EMBED_HOSTS;
    }

    /** Sanitize an HTML fragment (block content, a selected element's inner HTML). */
    public static function html(string $html, string $profile = 'content'): string
    {
        if (trim($html) === '') {
            return '';
        }

        self::$removed = 0;

        $dom = self::load('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return '';
        }

        // libxml hoists a fragment's leading <style>/<link>/<meta> into <head>;
        // move them back, in order, so the fragment round-trips intact.
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head instanceof \DOMElement) {
            $first = $body->firstChild;
            $isWrapperCharset = true;
            foreach (iterator_to_array($head->childNodes) as $child) {
                if ($isWrapperCharset && $child instanceof \DOMElement && strtolower($child->tagName) === 'meta' && $child->getAttribute('charset') !== '') {
                    $isWrapperCharset = false;
                    continue;
                }
                $body->insertBefore($child, $first);
            }
        }

        self::clean($body, $profile, false);

        // Nothing unsafe found: hand back the input byte-for-byte rather than
        // libxml's re-serialization, so a save never reformats untouched markup.
        if (self::$removed === 0 && !str_contains($html, '<?')) {
            return $html;
        }

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return self::finish($out);
    }

    /**
     * Sanitize a whole template document. Scripts, event handlers, and unsafe URLs
     * go; stylesheet links, non-refresh <meta>, <style>, and WYSITE markers stay.
     */
    public static function document(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        self::$removed = 0;
        $doctype = preg_match('/^\s*(<!doctype[^>]*>)/i', $html, $match) === 1 ? $match[1] : '';
        $dom = self::load($html);
        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            return '';
        }

        self::clean($root, 'document', true);
        if (self::$removed === 0 && !str_contains($html, '<?')) {
            return $html;
        }

        $out = $dom->saveHTML($root);
        return ($doctype !== '' ? $doctype . "\n" : '') . self::finish((string) $out);
    }

    private static function load(string $html): \DOMDocument
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (iterator_to_array($dom->childNodes) as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                // Our own <?xml encoding> prefix isn't a finding; anything else is.
                if (strtolower($child->target) !== 'xml') {
                    self::$removed++;
                }
                $dom->removeChild($child);
            }
        }

        return $dom;
    }

    private static function clean(\DOMNode $node, string $profile, bool $isDocument): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMProcessingInstruction) {
                self::$removed++;
                $node->removeChild($child);
                continue;
            }

            if ($child instanceof \DOMComment) {
                // Documents (templates) legitimately carry block markers; fragments
                // must never inject one, or they could break the page's block parsing.
                $text = (string) $child->nodeValue;
                if ((!$isDocument && str_contains($text, 'WYSITE:')) || str_contains($text, '<?')) {
                    self::$removed++;
                    $node->removeChild($child);
                }
                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            if (!self::keepElement($child, $profile, $isDocument)) {
                self::$removed++;
                $node->removeChild($child);
                continue;
            }

            self::cleanAttributes($child);
            self::clean($child, $profile, $isDocument);
        }
    }

    private static function keepElement(\DOMElement $element, string $profile, bool $isDocument): bool
    {
        $tag = strtolower($element->tagName);

        if (in_array($tag, self::DROP_ELEMENTS, true)) {
            return false;
        }

        // <meta http-equiv> can redirect (refresh) or set cookies; microdata
        // <meta itemprop> and charset/name metas are inert.
        if ($tag === 'meta' && $element->hasAttribute('http-equiv')) {
            return false;
        }

        // Stylesheets and resource hints stay; import/modulepreload can load script.
        if ($tag === 'link' && preg_match('/\b(import|modulepreload|serviceworker)\b/i', $element->getAttribute('rel')) === 1) {
            return false;
        }

        // SVG animation can rewrite an href into a javascript: URL after load.
        if (in_array($tag, ['set', 'animate'], true)) {
            $target = strtolower($element->getAttribute('attributeName') ?: $element->getAttribute('attributename'));
            if (in_array($target, ['href', 'xlink:href'], true)) {
                return false;
            }
        }

        return true;
    }

    private static function cleanAttributes(\DOMElement $element): void
    {
        $remove = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = (string) $attribute->nodeValue;

            if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'formaction'], true)) {
                $remove[] = $attribute->nodeName;
                continue;
            }

            if ($name === 'srcset' || $name === 'imagesrcset') {
                if (!self::isSafeSrcset($value)) {
                    $remove[] = $attribute->nodeName;
                }
                continue;
            }

            if (in_array($name, self::URL_ATTRIBUTES, true) && !self::isSafeUrl($value)) {
                $remove[] = $attribute->nodeName;
                continue;
            }

            if ($name === 'style' && preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import/i', $value) === 1) {
                $remove[] = $attribute->nodeName;
            }
        }

        foreach ($remove as $name) {
            self::$removed++;
            $element->removeAttribute($name);
        }
    }

    /**
     * Allow http(s), mailto, tel, relative URLs, fragments, template tokens, and
     * inline raster images. Everything else (javascript:, vbscript:, data:text/html,
     * data:image/svg+xml, file:, ...) is rejected.
     */
    public static function isSafeUrl(string $url): bool
    {
        // Browsers ignore control characters and whitespace inside a scheme.
        $normalized = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($normalized === '') {
            return true;
        }

        if (preg_match('/^([a-z][a-z0-9+.-]*):/', $normalized, $match) !== 1) {
            return true;
        }

        $scheme = $match[1];
        if (in_array($scheme, ['http', 'https', 'mailto', 'tel'], true)) {
            return true;
        }

        return $scheme === 'data' && preg_match('#^data:image/(png|jpe?g|gif|webp|avif)[;,]#', $normalized) === 1;
    }

    private static function isSafeSrcset(string $value): bool
    {
        foreach (explode(',', $value) as $candidate) {
            $url = preg_split('/\s+/', trim($candidate))[0] ?? '';
            // data: URLs contain commas, so a split candidate may start mid-URL;
            // only reject when a candidate actually names an unsafe scheme.
            if ($url !== '' && !self::isSafeUrl($url)) {
                return false;
            }
        }

        return true;
    }

    public static function isAllowedEmbed(string $src): bool
    {
        $src = trim($src);
        if (preg_match('#^(https:)?//#i', $src) !== 1) {
            return false;
        }

        $host = strtolower((string) parse_url(str_starts_with($src, '//') ? 'https:' . $src : $src, PHP_URL_HOST));
        return $host !== '' && in_array($host, self::embedHosts(), true);
    }

    private static function finish(string $html): string
    {
        $html = preg_replace('/<\?xml\s+encoding=["\']UTF-8["\']\??>\s*/i', '', $html) ?? $html;
        $html = restore_placeholder_tokens($html);
        // Belt and braces: no PHP open tag can survive serialization.
        return str_replace(['<?', '?>'], ['&lt;?', '?&gt;'], trim($html));
    }
}
