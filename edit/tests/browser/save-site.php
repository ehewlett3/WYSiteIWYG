<?php
declare(strict_types=1);

// A private, disposable application for the HTTP save regression check.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$source = dirname(__DIR__, 2);
require_once $source . '/src/vendor/autoload.php';
foreach (glob($source . '/src/*.php') ?: [] as $file) {
    require_once $file;
}
require_once $source . '/tests/lib.php';
$site = WYSiteIWYG\Tests\make_site();
foreach (['bootstrap.php', 'index.php'] as $file) {
    copy($source . '/' . $file, $site['edit'] . '/' . $file);
}
foreach (['src', 'assets', 'views'] as $directory) {
    symlink($source . '/' . $directory, $site['edit'] . '/' . $directory);
}
mkdir($site['root'] . '/sessions');
(new WYSiteIWYG\AuthManager($site['edit'] . '/storage/users.local.php'))->bootstrapAdmin('testadmin', 'disposable-test-password');
$site['generator']->applyTheme('verdant-sanctuary');
$site['generator']->createPage('Paste regression', 'paste');
echo $site['root'];
