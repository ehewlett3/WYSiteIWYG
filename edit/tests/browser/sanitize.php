<?php
declare(strict_types=1);

// CLI helper for the browser suite: prints Sanitizer::html() output for every
// line of a vector file as a JSON array (same order as the file).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$editPath = dirname(__DIR__, 2);
foreach (glob($editPath . '/src/*.php') ?: [] as $file) {
    require_once $file;
}

$lines = array_values(array_filter(explode("\n", (string) file_get_contents($argv[1] ?? '')), static fn(string $line): bool => $line !== ''));
echo json_encode(array_map(static fn(string $line): string => WYSiteIWYG\Sanitizer::html($line), $lines), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
