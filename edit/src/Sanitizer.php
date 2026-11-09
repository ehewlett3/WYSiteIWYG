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
 *
 * Parser differentials (mXSS): on PHP < 8.4 the parser is libxml's HTML4 parser,
 * which reads some markup differently from browsers (form feeds between
 * attributes, raw-text elements, foreign content, comments). So output is always
 * re-serialized from the cleaned tree, the contexts where the parsers disagree are
 * removed, and a second pass must find nothing left to remove. On PHP 8.4+ the
 * lexbor HTML5 parser builds the same tree as browsers. All backends serialize
 * the cleaned tree: fragment parsing can ignore malformed tags and document
 * wrappers without reporting any removed nodes.
 */
final class Sanitizer
{
    /** Elements removed together with everything inside them. */
    private const DROP_ELEMENTS = [
        'script', 'base', 'applet', 'frame', 'frameset', 'portal',
        // Browsers parse the content of these as raw text (or, for noscript,
        // differently depending on scripting), libxml as markup: a classic
        // parser-differential (mXSS) vector, and none of them carry content a
        // static page needs.
        'noscript', 'noembed', 'noframes', 'xmp', 'plaintext', 'template',
        // Only meaningful inside <math>/<svg>; outside them they exist to confuse
        // namespace switching on re-parse.
        'mglyph', 'malignmark', 'annotation-xml', 'foreignobject',
    ];

    private const URL_ATTRIBUTES = [
        'href', 'src', 'action', 'poster', 'xlink:href', 'background', 'cite', 'longdesc', 'data', 'lowsrc', 'dynsrc', 'ping',
    ];

    /** Elements allowed inside <svg>; anything else (style, script, foreignObject, HTML) is dropped. */
    private const SVG_ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'rect', 'text', 'tspan', 'textpath',
        'defs', 'use', 'symbol', 'lineargradient', 'radialgradient', 'stop', 'clippath', 'mask', 'pattern', 'title',
        'desc', 'marker', 'image', 'filter', 'a', 'view', 'metadata', 'switch',
    ];

    /** Elements allowed inside <math> (annotation-xml, mglyph and malignmark are mXSS staples). */
    private const MATH_ELEMENTS = [
        'math', 'mi', 'mo', 'mn', 'ms', 'mtext', 'mrow', 'msup', 'msub', 'msubsup', 'mfrac', 'msqrt', 'mroot',
        'mtable', 'mtr', 'mtd', 'mspace', 'mstyle', 'mover', 'munder', 'munderover', 'mpadded', 'mphantom',
        'menclose', 'semantics', 'annotation', 'merror', 'mmultiscripts', 'mprescripts', 'none',
    ];

    /** Comments kept in whole documents: WYSiteIWYG markers with no markup characters. */
    private const SAFE_DOCUMENT_COMMENT = '/^\s*WYSITE:(BEGIN|END|META|PAGINATION|COMPONENT)\b[^<>]*$/';

    private const DEFAULT_EMBED_HOSTS = [
        'youtube.com', 'www.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com',
        'player.vimeo.com', 'www.google.com', 'maps.google.com', 'calendar.google.com', 'docs.google.com',
        'open.spotify.com', 'w.soundcloud.com', 'embed.podcasts.apple.com', 'www.openstreetmap.org',
        'player.simplecast.com', 'bandcamp.com', 'www.loom.com', 'fast.wistia.net',
    ];

    /** @var string[]|null */
    private static ?array $embedHosts = null;

    /** @var string[] Hostnames of this site: absolute URLs to them count as same-origin. */
    private static array $siteHosts = [];

    /** Nodes/attributes removed by the current pass. */
    private static int $removed = 0;

    /** @var string[] What the current pass removed ("<script>", "onerror", "comment", ...). */
    private static array $passFindings = [];

    /** @var string[] What the last html()/document() call removed (first pass). */
    private static array $lastFindings = [];

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

    /**
     * What the most recent html()/document() call removed, e.g. ["<script>",
     * "onerror attribute", "comment"]. Empty when the input was already safe.
     *
     * @return string[]
     */
    public static function lastFindings(): array
    {
        return self::$lastFindings;
    }

    /** True when the last call removed something other than comments. */
    public static function lastRemovedActiveContent(): bool
    {
        return array_diff(self::$lastFindings, ['comment']) !== [];
    }

    /** This site's own hostnames (request host, canonical URL host). */
    public static function setSiteHosts(array $hosts): void
    {
        self::$siteHosts = array_values(array_unique(array_filter(array_map(
            static fn($host): string => strtolower(trim((string) $host)),
            $hosts
        ))));
    }

    /**
     * Which parser backs the sanitizer: 'lexbor' (PHP 8.4+ Dom\HTMLDocument, the
     * same HTML5 tree browsers build) or 'libxml' (HTML4 parser; output is always
     * re-serialized and the contexts where it disagrees with browsers are removed).
     */
    public static function backend(): string
    {
        return class_exists(\Dom\HTMLDocument::class) ? 'lexbor' : 'libxml';
    }

    /** Sanitize an HTML fragment (block content, a selected element's inner HTML). */
    public static function html(string $html, string $profile = 'content'): string
    {
        self::$lastFindings = [];
        if (trim($html) === '') {
            return '';
        }

        return self::stabilize($html, static fn(string $input): array => self::fragmentPass($input, $profile), true);
    }

    /**
     * Sanitize a whole template document. Scripts, event handlers, and unsafe URLs
     * go; stylesheet links, non-refresh <meta>, <style>, and WYSITE markers stay.
     */
    public static function document(string $html): string
    {
        self::$lastFindings = [];
        if (trim($html) === '') {
            return '';
        }

        return self::stabilize($html, static fn(string $input): array => self::documentPass($input), false);
    }

    /**
     * Run passes until one removes nothing. The first pass re-serializes, so a
     * second pass over its output sees exactly what a browser will parse; if that
     * still finds something to remove, the markup is unstable and is not trusted.
     *
     * @param callable(string): array{0:string,1:int} $pass
     */
    private static function stabilize(string $html, callable $pass, bool $isFragment): string
    {
        [$out, $removed] = $pass($html);
        self::$lastFindings = self::$passFindings;
        // libxml can drop what it can't parse (e.g. attributes separated by form
        // feeds) without the cleaner seeing it; report that as a finding too.
        if (array_diff(self::$lastFindings, ['comment']) === [] && self::looksActive($html) && !self::looksActive($out)) {
            self::$lastFindings[] = 'markup browsers read differently';
        }

        for ($i = 0; $i < 3; $i++) {
            [$next, $removedAgain] = $pass($out);
            if ($removedAgain === 0) {
                return $out;
            }
            $out = $next;
        }

        error_log('WYSiteIWYG: sanitizer output did not stabilize; markup was escaped.');
        if ($isFragment) {
            return h(strip_tags($html));
        }

        throw new \RuntimeException('This markup could not be made safe. Edit it in HTML mode and remove unusual constructs.');
    }

    private static function looksActive(string $html): bool
    {
        return preg_match('/<[a-z][^>]*?[\x00-\x20\/"\']on[a-z]+\s*=|<\s*script\b|javascript\s*:|vbscript\s*:/i', $html) === 1;
    }

    /** @return array{0:string,1:int} */
    private static function fragmentPass(string $html, string $profile): array
    {
        self::$removed = 0;
        self::$passFindings = [];

        if (self::backend() === 'lexbor') {
            $doc = \Dom\HTMLDocument::createFromString('<!doctype html><html><head><meta charset="utf-8"></head><body></body></html>', LIBXML_NOERROR);
            $body = $doc->body;
            $body->innerHTML = $html;
            self::clean($body, $profile, false, null);
            $out = '';
            foreach ($body->childNodes as $child) {
                $out .= $doc->saveHtml($child);
            }
            return [self::finish($out), self::$removed];
        }

        $dom = self::load('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            return ['', self::$removed];
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

        self::clean($body, $profile, false, null);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return [self::finish($out), self::$removed];
    }

    /** @return array{0:string,1:int} */
    private static function documentPass(string $html): array
    {
        self::$removed = 0;
        self::$passFindings = [];
        $doctype = preg_match('/^\s*(<!doctype[^>]*>)/i', $html, $match) === 1 ? $match[1] : '';

        if (self::backend() === 'lexbor') {
            $doc = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR);
            $root = $doc->documentElement;
            if ($root === null) {
                return ['', self::$removed];
            }
            self::cleanAttributes($root, self::tagOf($root));
            self::clean($root, 'document', true, null);
            $out = $doc->saveHtml($root);
            return [($doctype !== '' ? $doctype . "\n" : '') . self::finish((string) $out), self::$removed];
        }

        $dom = self::load($html);
        $root = $dom->documentElement;
        if (!$root instanceof \DOMElement) {
            return ['', self::$removed];
        }

        self::cleanAttributes($root, self::tagOf($root));
        self::clean($root, 'document', true, null);
        $out = $dom->saveHTML($root);
        return [($doctype !== '' ? $doctype . "\n" : '') . self::finish((string) $out), self::$removed];
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
                    self::$passFindings[] = 'processing instruction';
                }
                $dom->removeChild($child);
            }
        }

        return $dom;
    }

    /** Lowercase local name of an element node from either DOM API. */
    private static function tagOf(object $element): string
    {
        return strtolower((string) ($element->localName ?? $element->nodeName));
    }

    /**
     * Walk a subtree and remove everything that can execute. Written against the
     * node-type surface both \DOMNode (libxml) and \Dom\Node (PHP 8.4) share.
     *
     * @param string|null $foreign 'svg' or 'math' when inside foreign content
     */
    private static function clean(object $node, string $profile, bool $isDocument, ?string $foreign): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            $type = $child->nodeType;

            if ($type === XML_PI_NODE || $type === XML_CDATA_SECTION_NODE) {
                self::$removed++;
                self::$passFindings[] = $type === XML_PI_NODE ? 'processing instruction' : 'CDATA';
                $node->removeChild($child);
                continue;
            }

            if ($type === XML_COMMENT_NODE) {
                // Fragments never keep comments (marker injection, `--!>`/nesting
                // differentials); documents keep only WYSiteIWYG markers.
                $text = (string) $child->nodeValue;
                if (!$isDocument || preg_match(self::SAFE_DOCUMENT_COMMENT, $text) !== 1) {
                    self::$removed++;
                    self::$passFindings[] = 'comment';
                    $node->removeChild($child);
                }
                continue;
            }

            if ($type !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = self::tagOf($child);
            $childForeign = $foreign;
            if ($foreign === null && ($tag === 'svg' || $tag === 'math')) {
                $childForeign = $tag;
            }

            if (!self::keepElement($child, $tag, $foreign)) {
                self::$removed++;
                self::$passFindings[] = '<' . $tag . '>';
                $node->removeChild($child);
                continue;
            }

            self::cleanAttributes($child, $tag);

            if ($tag === 'iframe' || $tag === 'textarea' || $tag === 'title') {
                // Browsers treat the content of these as text; libxml may have built
                // elements from it. Keep only the text, which the serializer escapes.
                if ($tag === 'iframe') {
                    if ($child->firstChild !== null) {
                        self::$removed++;
                        self::$passFindings[] = 'iframe content';
                    }
                    while ($child->firstChild !== null) {
                        $child->removeChild($child->firstChild);
                    }
                    continue;
                }
                $hasElements = false;
                foreach ($child->childNodes as $grandchild) {
                    if ($grandchild->nodeType !== XML_TEXT_NODE) {
                        $hasElements = true;
                        break;
                    }
                }
                if ($hasElements) {
                    self::$removed++;
                    self::$passFindings[] = 'markup inside <' . $tag . '>';
                    $child->textContent = (string) $child->textContent;
                }
                continue;
            }

            if ($tag === 'style') {
                // Raw text: anything tag-like inside could end the element early in
                // one parser but not the other.
                if (preg_match('/<[\/!?a-z]/i', (string) $child->textContent) === 1) {
                    self::$removed++;
                    self::$passFindings[] = '<style> containing markup';
                    $node->removeChild($child);
                }
                continue;
            }

            self::clean($child, $profile, $isDocument, $childForeign);
        }
    }

    private static function keepElement(object $element, string $tag, ?string $foreign): bool
    {
        if ($foreign === 'svg') {
            return in_array($tag, self::SVG_ELEMENTS, true) && self::keepAnimation($element, $tag);
        }
        if ($foreign === 'math') {
            return in_array($tag, self::MATH_ELEMENTS, true);
        }

        if (in_array($tag, self::DROP_ELEMENTS, true)) {
            return false;
        }

        // <meta http-equiv> can redirect (refresh) or set cookies; microdata
        // <meta itemprop> and charset/name metas are inert.
        if ($tag === 'meta' && $element->hasAttribute('http-equiv')) {
            return false;
        }

        // Stylesheets and resource hints stay; import/modulepreload can load script.
        if ($tag === 'link' && preg_match('/\b(import|modulepreload|serviceworker)\b/i', (string) $element->getAttribute('rel')) === 1) {
            return false;
        }

        if (in_array($tag, ['iframe', 'object', 'embed'], true) && !self::isAllowedFrameTarget($element, $tag)) {
            return false;
        }

        return self::keepAnimation($element, $tag);
    }

    /** SVG animation can rewrite an href into a javascript: URL after load. */
    private static function keepAnimation(object $element, string $tag): bool
    {
        if (!in_array($tag, ['set', 'animate'], true)) {
            return true;
        }

        $target = strtolower((string) ($element->getAttribute('attributeName') ?: $element->getAttribute('attributename')));
        return !in_array($target, ['href', 'xlink:href'], true);
    }

    /**
     * Frames and plugins pointing back at this site may only show pages (or a PDF):
     * a same-origin SVG or XML document would run its script in this origin.
     */
    private static function isAllowedFrameTarget(object $element, string $tag): bool
    {
        $url = trim((string) $element->getAttribute($tag === 'object' ? 'data' : 'src'));
        if ($url === '' || !self::isSameSiteUrl($url)) {
            return true;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if ($tag === 'iframe') {
            $extension = pathinfo($path, PATHINFO_EXTENSION);
            return $path === '' || str_ends_with($path, '/') || in_array($extension, ['', 'html', 'htm'], true);
        }

        return str_ends_with($path, '.pdf') || strtolower(trim((string) $element->getAttribute('type'))) === 'application/pdf';
    }

    private static function isSameSiteUrl(string $url): bool
    {
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match('#^\s*//#', $decoded) === 1) {
            $decoded = 'https:' . trim($decoded);
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', trim($decoded)) !== 1) {
            return true; // relative
        }

        $host = strtolower((string) parse_url(trim($decoded), PHP_URL_HOST));
        return $host !== '' && in_array($host, self::$siteHosts, true);
    }

    private static function cleanAttributes(object $element, string $tag): void
    {
        $remove = [];
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower((string) $attribute->nodeName);
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

            if ($name === 'style' && preg_match('/expression\s*\(|javascript\s*:|vbscript\s*:|behavior\s*:|-moz-binding|@import/i', self::decodeCssEscapes($value)) === 1) {
                $remove[] = $attribute->nodeName;
            }
        }

        foreach ($remove as $name) {
            self::$removed++;
            self::$passFindings[] = strtolower((string) $name) . ' attribute';
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

    /**
     * Sanitize a stylesheet that will be served or inlined. Removes anything that
     * could end a <style> element or run script, after resolving CSS escapes, and
     * repeats until nothing changes (so nested payloads can't reassemble).
     * url() values must start with one of $allowedUrlPrefixes (data: raster images
     * are always allowed); @import is allowed only for Google Fonts.
     *
     * @param string[] $allowedUrlPrefixes
     */
    public static function css(string $css, array $allowedUrlPrefixes = []): string
    {
        $allowedUrlPrefixes = array_merge(['https://fonts.gstatic.com/', 'https://fonts.googleapis.com/'], $allowedUrlPrefixes);
        $css = str_replace("\0", '', $css);
        for ($i = 0; $i < 10; $i++) {
            $before = $css;
            $css = (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/s', static function (array $m): string {
                if (($m[1] ?? '') !== '') {
                    $code = hexdec($m[1]);
                    return self::utf8Chr((int) $code);
                }
                return $m[2];
            }, $css);
            $css = (string) preg_replace('#</?\s*(style|script)|<!--|-->|<\?|javascript\s*:|vbscript\s*:|expression\s*\(|behavior\s*:|-moz-binding#i', '', $css);
            $css = (string) preg_replace_callback('/@import\s+(?:url\([^)]*\)|"[^"]*"|\'[^\']*\'|[^;"\'\s]+)[^;]*;?/i', static function (array $match): string {
                return preg_match('#^@import\s+(url\(\s*)?["\']?https://fonts\.googleapis\.com/#i', $match[0]) === 1 ? $match[0] : '';
            }, $css);
            if ($css === $before) {
                break;
            }
        }

        return (string) preg_replace_callback('/url\(\s*(["\']?)(.*?)\1\s*\)/i', static function (array $match) use ($allowedUrlPrefixes): string {
            $url = trim($match[2]);
            if (preg_match('#^data:image/(png|jpe?g|gif|webp|avif)[;,]#i', $url) === 1 || str_starts_with($url, '#')) {
                return $match[0];
            }
            foreach ($allowedUrlPrefixes as $prefix) {
                if ($prefix !== '' && str_starts_with($url, $prefix) && !str_contains($url, '..')) {
                    return $match[0];
                }
            }
            return 'none';
        }, $css);
    }

    private static function decodeCssEscapes(string $value): string
    {
        return (string) preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})\s?|\\\\(.)/s', static function (array $m): string {
            if (($m[1] ?? '') !== '') {
                $code = hexdec($m[1]);
                return self::utf8Chr((int) $code);
            }
            return $m[2];
        }, html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private static function utf8Chr(int $code): string
    {
        if ($code <= 0 || $code >= 0x110000 || ($code >= 0xD800 && $code <= 0xDFFF)) {
            return '';
        }
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }
        return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
    }

    private static function finish(string $html): string
    {
        $html = preg_replace('/<\?xml\s+encoding=["\']UTF-8["\']\??>\s*/i', '', $html) ?? $html;
        $html = restore_placeholder_tokens($html);
        // Belt and braces: no PHP open tag can survive serialization.
        return str_replace(['<?', '?>'], ['&lt;?', '?&gt;'], trim($html));
    }
}
