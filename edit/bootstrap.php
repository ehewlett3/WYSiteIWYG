<?php
declare(strict_types=1);

use WYSiteIWYG\AuthManager;
use WYSiteIWYG\BlockRepository;
use WYSiteIWYG\ExternalSiteImporter;
use WYSiteIWYG\SiteGenerator;
use WYSiteIWYG\ThemeManager;

require_once __DIR__ . '/src/Support.php';
require_once __DIR__ . '/src/UrlLocalizer.php';
require_once __DIR__ . '/src/BlockRepository.php';
require_once __DIR__ . '/src/ExternalSiteImporter.php';
require_once __DIR__ . '/src/SiteGenerator.php';
require_once __DIR__ . '/src/ThemeManager.php';

WYSiteIWYG\apply_security_headers();

$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$appUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/edit/index.php')), '/');
$appUrl = $appUrl === '' ? '/' : $appUrl;

$siteBaseUrl = rtrim(str_replace('\\', '/', dirname($appUrl)), '/');
$siteBaseUrl = $siteBaseUrl === '' ? '/' : $siteBaseUrl . '/';

$cookiePath = $siteBaseUrl;
$sessionName = 'wysite_session_v2';
$expireCookie = static function (string $name, string $path, bool $secure): void {
    setcookie($name, '', time() - 42000, $path, '', $secure, true);
};

if (session_status() !== PHP_SESSION_ACTIVE) {
    $legacyCookies = [
        ['name' => 'wysite_session', 'paths' => [$cookiePath, rtrim($appUrl, '/') . '/', '/edit/', '/'], 'skip_current_path' => false],
        ['name' => $sessionName, 'paths' => [rtrim($appUrl, '/') . '/', '/edit/'], 'skip_current_path' => true],
    ];

    foreach ($legacyCookies as $legacyCookie) {
        if (!isset($_COOKIE[$legacyCookie['name']])) {
            continue;
        }

        foreach (array_values(array_unique($legacyCookie['paths'])) as $legacyPath) {
            if (!is_string($legacyPath) || $legacyPath === '') {
                continue;
            }

            if (!empty($legacyCookie['skip_current_path']) && $legacyPath === $cookiePath) {
                continue;
            }

            $expireCookie($legacyCookie['name'], $legacyPath, $https);
        }
    }
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($sessionName);
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params(0, $cookiePath, '', $https, true);
    session_start();
}

$rootPath = dirname(__DIR__);
$editPath = __DIR__;

$auth = new AuthManager(__DIR__ . '/storage/users.local.php');
$repository = new BlockRepository($rootPath, $editPath);
$themes = new ThemeManager(__DIR__ . '/storage/config.php', __DIR__ . '/themes');
$generator = new SiteGenerator($rootPath, $repository, $themes, $siteBaseUrl);
$externalImporter = new ExternalSiteImporter($rootPath, $editPath);

return [
    'auth' => $auth,
    'repository' => $repository,
    'generator' => $generator,
    'externalImporter' => $externalImporter,
    'themes' => $themes,
    'rootPath' => $rootPath,
    'editPath' => $editPath,
    'appUrl' => $appUrl,
    'siteBaseUrl' => $siteBaseUrl,
    'siteTitle' => 'WYSiteIWYG',
];
