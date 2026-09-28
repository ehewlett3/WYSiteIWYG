<?php
declare(strict_types=1);

/**
 * Run the WYSiteIWYG test suite:  php edit/tests/run.php [name-filter]
 *
 * Loads the app classes directly (not bootstrap.php, which starts a session and
 * sends headers) and executes every edit/tests/*_test.php file.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$editPath = dirname(__DIR__);
foreach (['Support', 'UrlLocalizer', 'BlockRepository', 'ExternalSiteImporter', 'SiteGenerator', 'ThemeManager', 'AiAssistant'] as $class) {
    require_once $editPath . '/src/' . $class . '.php';
}
foreach (glob($editPath . '/src/*.php') ?: [] as $file) {
    require_once $file;
}

require_once __DIR__ . '/lib.php';

$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);
foreach ($files as $file) {
    require $file;
}

exit(WYSiteIWYG\Tests\run_all($argv[1] ?? null));
