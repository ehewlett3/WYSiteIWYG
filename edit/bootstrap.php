<?php
declare(strict_types=1);

use WYSiteIWYG\AuthManager;
use WYSiteIWYG\BlockRepository;
use WYSiteIWYG\SiteGenerator;
use WYSiteIWYG\ThemeManager;

require_once __DIR__ . '/src/Support.php';
require_once __DIR__ . '/src/BlockRepository.php';
require_once __DIR__ . '/src/SiteGenerator.php';
require_once __DIR__ . '/src/ThemeManager.php';

WYSiteIWYG\apply_security_headers();

$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$appUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/edit/index.php')), '/');
$appUrl = $appUrl === '' ? '/' : $appUrl;

$siteBaseUrl = rtrim(str_replace('\\', '/', dirname($appUrl)), '/');
$siteBaseUrl = $siteBaseUrl === '' ? '/' : $siteBaseUrl . '/';

$cookiePath = $siteBaseUrl;

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('wysite_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$rootPath = dirname(__DIR__);
$editPath = __DIR__;

$auth = new AuthManager(__DIR__ . '/storage/users.local.php');
$repository = new BlockRepository($rootPath, $editPath);
$themes = new ThemeManager(__DIR__ . '/storage/config.php', __DIR__ . '/themes');
$generator = new SiteGenerator($rootPath, $repository, $themes);

return [
    'auth' => $auth,
    'repository' => $repository,
    'generator' => $generator,
    'themes' => $themes,
    'rootPath' => $rootPath,
    'editPath' => $editPath,
    'appUrl' => $appUrl,
    'siteBaseUrl' => $siteBaseUrl,
    'siteTitle' => 'WYSiteIWYG',
];
