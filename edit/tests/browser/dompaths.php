<?php
declare(strict_types=1);

// CLI helper: DOM paths (tag:index/...) of every element in pre-order, as the
// server computes them with HtmlDocument.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$editPath = dirname(__DIR__, 2);
require_once $editPath . '/src/vendor/autoload.php';
foreach (glob($editPath . '/src/*.php') ?: [] as $file) {
    require_once $file;
}
$dom = WYSiteIWYG\HtmlDocument::parse((string) file_get_contents($argv[1] ?? ''));
$out = [];
$walk = static function (DOMNode $node) use (&$walk, &$out): void {
    foreach ($node->childNodes as $child) {
        if ($child instanceof DOMElement) {
            $out[] = implode('/', array_map(static fn(array $s): string => $s['tag'] . ':' . $s['index'], WYSiteIWYG\HtmlDocument::domPath($child)));
            $walk($child);
        }
    }
};
$walk($dom);
echo json_encode($out);
