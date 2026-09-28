<?php
declare(strict_types=1);

use WYSiteIWYG\AiAssistant;
use WYSiteIWYG\AuthManager;
use WYSiteIWYG\BlockRepository;
use WYSiteIWYG\ExternalSiteImporter;
use WYSiteIWYG\SiteGenerator;
use WYSiteIWYG\ThemeManager;

require_once __DIR__ . '/src/Support.php';
require_once __DIR__ . '/src/SitePath.php';
require_once __DIR__ . '/src/Sanitizer.php';
require_once __DIR__ . '/src/Revisions.php';
require_once __DIR__ . '/src/HtmlSource.php';
require_once __DIR__ . '/src/SiteSettings.php';
require_once __DIR__ . '/src/SystemCheck.php';
require_once __DIR__ . '/src/SiteReport.php';
require_once __DIR__ . '/src/WxrImporter.php';
require_once __DIR__ . '/src/UrlLocalizer.php';
require_once __DIR__ . '/src/BlockRepository.php';
require_once __DIR__ . '/src/ExternalSiteImporter.php';
require_once __DIR__ . '/src/SiteGenerator.php';
require_once __DIR__ . '/src/ThemeManager.php';
require_once __DIR__ . '/src/AiAssistant.php';

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

// Sessions are started lazily: the only request public pages make here is
// session-status, and it must not hand every visitor a session cookie. Anything
// else (login, install, dashboard) needs one for CSRF tokens and flashes.
$needsSession = isset($_COOKIE[$sessionName]) || (($_GET['action'] ?? '') !== 'session-status');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($sessionName);
    ini_set('session.use_strict_mode', '1');
    // Match the app's 8-hour idle timeout (PHP's default GC ends sessions after 24 min).
    ini_set('session.gc_maxlifetime', '28800');
    ini_set('session.cookie_samesite', 'Lax');
    session_set_cookie_params(0, $cookiePath, '', $https, true);
    if ($needsSession) {
        session_start();
    }
}

$rootPath = dirname(__DIR__);
$editPath = __DIR__;
WYSiteIWYG\Filesystem::setDisplayRoot($rootPath);
$revisions = new WYSiteIWYG\Revisions($rootPath, __DIR__ . '/storage/revisions');
WYSiteIWYG\Filesystem::setRevisions($revisions);
$backups = new WYSiteIWYG\Backups($rootPath, __DIR__ . '/storage/backups');

$auth = new AuthManager(__DIR__ . '/storage/users.local.php');
$auth->setHintCookie($cookiePath, $https);
$repository = new BlockRepository($rootPath, $editPath);
$themes = new ThemeManager(__DIR__ . '/storage/config.php', __DIR__ . '/themes', __DIR__ . '/storage/state.local.php');
$settings = new WYSiteIWYG\SiteSettings(__DIR__ . '/storage/site.local.php');

// scheme://host of this request: the fallback base for absolute URLs (canonical,
// feeds) when Site settings has no canonical URL. The Host header is validated
// so a malformed value can't end up in generated pages.
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$siteOrigin = preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $requestHost) === 1 ? ($https ? 'https://' : 'http://') . strtolower($requestHost) : '';

$generator = new SiteGenerator($rootPath, $repository, $themes, $siteBaseUrl, $settings, $siteOrigin);
$externalImporter = new ExternalSiteImporter($rootPath, $editPath);
$externalImporter->setByteBudget((int) $settings->get('import_byte_budget_mb') * 1_048_576);
$externalImporter->setKeepScripts((bool) $settings->get('keep_imported_scripts'));
if ($settings->embedHosts() !== []) {
    WYSiteIWYG\Sanitizer::setEmbedHosts($settings->embedHosts());
}
$ai = new AiAssistant(__DIR__ . '/storage/ai.local.php');

return [
    'auth' => $auth,
    'repository' => $repository,
    'generator' => $generator,
    'externalImporter' => $externalImporter,
    'themes' => $themes,
    'ai' => $ai,
    'revisions' => $revisions,
    'backups' => $backups,
    'installToken' => new WYSiteIWYG\InstallToken(__DIR__ . '/storage/install-token.local.php'),
    'rootPath' => $rootPath,
    'editPath' => $editPath,
    'appUrl' => $appUrl,
    'siteBaseUrl' => $siteBaseUrl,
    'settings' => $settings,
    'report' => new WYSiteIWYG\SiteReport($rootPath, $repository),
    'systemCheck' => new WYSiteIWYG\SystemCheck($rootPath, $editPath),
    'siteOrigin' => $siteOrigin,
    'siteTitle' => (string) $settings->get('site_name'),
];
