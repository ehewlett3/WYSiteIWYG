<?php
declare(strict_types=1);

namespace WYSiteIWYG;

/**
 * A minimal HTML source scanner used to edit files in place without
 * re-serializing them. libxml's HTML4 serializer rewrites doctypes, encodes
 * braces in URLs, and reshapes HTML5 markup; splicing only the edited element's
 * byte range keeps the rest of the file byte-identical.
 */
final class HtmlSource
{
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title', 'xmp'];

    /**
     * Start tags in document order.
     *
     * @return array<int, array{type:string, name:string, start:int, end:int, selfClosing:bool}>
     */
    public static function tokens(string $html): array
    {
        $tokens = [];
        $length = strlen($html);
        $pos = 0;

        while ($pos < $length) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                break;
            }

            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $close = strpos($html, '-->', $lt + 4);
                $pos = $close === false ? $length : $close + 3;
                continue;
            }

            $next = $html[$lt + 1] ?? '';
            if ($next === '!' || $next === '?') {
                $close = strpos($html, '>', $lt + 2);
                $pos = $close === false ? $length : $close + 1;
                continue;
            }

            $isEnd = $next === '/';
            $nameStart = $lt + ($isEnd ? 2 : 1);
            if (preg_match('/\G[A-Za-z][A-Za-z0-9:-]*/', $html, $match, 0, $nameStart) !== 1) {
                $pos = $lt + 1;
                continue;
            }

            $name = strtolower($match[0]);
            $tagEnd = self::tagEnd($html, $nameStart + strlen($match[0]));
            if ($tagEnd === null) {
                break;
            }

            $selfClosing = !$isEnd && $html[$tagEnd - 2] === '/';
            $tokens[] = ['type' => $isEnd ? 'end' : 'start', 'name' => $name, 'start' => $lt, 'end' => $tagEnd, 'selfClosing' => $selfClosing];
            $pos = $tagEnd;

            if (!$isEnd && in_array($name, self::RAW_TEXT_ELEMENTS, true)) {
                if (preg_match('#</' . $name . '\b#i', $html, $closeMatch, PREG_OFFSET_CAPTURE, $pos) === 1) {
                    $pos = $closeMatch[0][1];
                } else {
                    $pos = $length;
                }
            }
        }

        return $tokens;
    }

    /** Number of start tags named $tag in the source. */
    public static function countStartTags(string $html, string $tag): int
    {
        $tag = strtolower($tag);
        $count = 0;
        foreach (self::tokens($html) as $token) {
            if ($token['type'] === 'start' && $token['name'] === $tag) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Byte range [start, end) of the inner HTML of the $ordinal-th (0-based) <$tag>
     * element, or null when it can't be located unambiguously (void element,
     * implicit end tag, or unterminated markup).
     *
     * @return array{0:int, 1:int}|null
     */
    public static function innerRange(string $html, string $tag, int $ordinal): ?array
    {
        $tag = strtolower($tag);
        if (in_array($tag, self::VOID_ELEMENTS, true)) {
            return null;
        }

        $tokens = self::tokens($html);
        $seen = -1;
        $startIndex = null;
        foreach ($tokens as $index => $token) {
            if ($token['type'] === 'start' && $token['name'] === $tag && ++$seen === $ordinal) {
                $startIndex = $index;
                break;
            }
        }

        if ($startIndex === null || $tokens[$startIndex]['selfClosing']) {
            return null;
        }

        $depth = 0;
        for ($i = $startIndex + 1, $n = count($tokens); $i < $n; $i++) {
            $token = $tokens[$i];
            if ($token['name'] !== $tag) {
                continue;
            }

            if ($token['type'] === 'start' && !$token['selfClosing']) {
                $depth++;
                continue;
            }

            if ($token['type'] === 'end') {
                if ($depth === 0) {
                    return [$tokens[$startIndex]['end'], $token['start']];
                }
                $depth--;
            }
        }

        return null;
    }

    /** Offset just past the '>' that closes a tag, honoring quoted attribute values. */
    private static function tagEnd(string $html, int $pos): ?int
    {
        $length = strlen($html);
        $quote = null;
        $previous = '';
        for (; $pos < $length; $pos++) {
            $char = $html[$pos];
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                    $previous = $char;
                }
                continue;
            }

            // A quote only opens a value right after "=" (spaces allowed).
            if (($char === '"' || $char === "'") && $previous === '=') {
                $quote = $char;
                continue;
            }

            if ($char === '>') {
                return $pos + 1;
            }

            if (!ctype_space($char)) {
                $previous = $char;
            }
        }

        return null;
    }
}
