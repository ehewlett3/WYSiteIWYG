<?php
declare(strict_types=1);

// CLI helper: render a page with the public CSP whose block holds a payload
// written straight to disk (as if a sanitizer bug let it through) and an
// admin raw-HTML block with a legitimate inline script.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$editPath = dirname(__DIR__, 2);
require_once $editPath . '/src/vendor/autoload.php';
foreach (glob($editPath . '/src/*.php') ?: [] as $file) {
    require_once $file;
}
require_once $editPath . '/tests/lib.php';

$site = WYSiteIWYG\Tests\make_site();
$site['generator']->applyTheme('verdant-sanctuary');
$path = $site['generator']->createPage('CSP', 'csp');
$site['repository']->updateBlock($path, 'page-content', "<p>Hello</p><img src=x onerror=\"alert('xss')\">");
$site['repository']->updateBlock($path, 'page-content-2', '<script>window.adminScriptRan = 1;</script><p>Widget</p>');
$site['repository']->updateMetadata($path, ['admin_blocks' => 'page-content-2']);
$site['generator']->rebuildPage($path);
echo file_get_contents($site['root'] . '/' . $path);
WYSiteIWYG\Tests\remove_tree($site['root']);
