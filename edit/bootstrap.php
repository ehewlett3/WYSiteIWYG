<?php
declare(strict_types=1);

use WYSiteIWYG\AiAssistant;
use WYSiteIWYG\AuthManager;
use WYSiteIWYG\BlockRepository;
use WYSiteIWYG\ExternalSiteImporter;
use WYSiteIWYG\SiteGenerator;
use WYSiteIWYG\ThemeManager;

require_once __DIR__ . '/src/vendor/autoload.php';
require_once __DIR__ . '/src/Support.php';
require_once __DIR__ . '/src/HtmlDocument.php';
require_once __DIR__ . '/src/RegionSelector.php';
require_once __DIR__ . '/src/SiteStructureAnalyzer.php';
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
require_once __DIR__ . '/src/UrlCanonicalizer.php';
require_once __DIR__ . '/src/AssetIndex.php';
require_once __DIR__ . '/src/ExternalSiteImporter.php';
require_once __DIR__ . '/src/SiteGenerator.php';
require_once __DIR__ . '/src/ThemeManager.php';
require_once __DIR__ . '/src/ThemeImporter.php';
require_once __DIR__ . '/src/ContentCleaner.php';
require_once __DIR__ . '/src/ImportMap.php';
require_once __DIR__ . '/src/ComponentStyles.php';
require_once __DIR__ . '/src/ComponentLibrary.php';
require_once __DIR__ . '/src/AuditLog.php';
require_once __DIR__ . '/src/AiAssistant.php';
require_once __DIR__ . '/src/SecurityRescan.php';
require_once __DIR__ . '/src/ImportDedupe.php';

WYSiteIWYG\apply_security_headers();

$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$appUrl = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/edit/index.php')), '/');
$appUrl = $appUrl === '' ? '/' : $appUrl;

$siteBaseUrl = rtrim(str_replace('\\', '/', dirname($appUrl)), '/');
$siteBaseUrl = $siteBaseUrl === '' ? '/' : $siteBaseUrl . '/';

// The editor hint cookie is read by public pages, so it lives at the site base;
// the session cookie is only needed by /edit/ itself (SEC-7a).
$cookiePath = $siteBaseUrl;
$sessionCookiePath = rtrim($appUrl, '/') . '/';
$sessionName = 'wysite_session_v2';
$expireCookie = static function (string $name, string $path, bool $secure): void {
    setcookie($name, '', time() - 42000, $path, '', $secure, true);
};

if (session_status() !== PHP_SESSION_ACTIVE) {
    $legacyCookies = [
        ['name' => 'wysite_session', 'paths' => [$cookiePath, $sessionCookiePath, '/edit/', '/'], 'skip_current_path' => false],
        // Earlier versions scoped the session cookie to the site base.
        ['name' => $sessionName, 'paths' => [$cookiePath, '/', '/edit/'], 'skip_current_path' => true],
    ];

    foreach ($legacyCookies as $legacyCookie) {
        if (!isset($_COOKIE[$legacyCookie['name']])) {
            continue;
        }

        foreach (array_values(array_unique($legacyCookie['paths'])) as $legacyPath) {
            if (!is_string($legacyPath) || $legacyPath === '') {
                continue;
            }

            if (!empty($legacyCookie['skip_current_path']) && $legacyPath === $sessionCookiePath) {
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
    session_set_cookie_params(0, $sessionCookiePath, '', $https, true);
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
// SEC-3c: optional separate editor origin (e.g. https://edit.example.com).
$editorOrigin = rtrim((string) getenv('WYSITE_EDITOR_ORIGIN'), '/');
$editorOrigin = preg_match('#^https?://[a-z0-9.-]+(:\d+)?$#i', $editorOrigin) === 1 ? strtolower($editorOrigin) : '';
$auth->setHintCookie($editorOrigin !== '' ? '/' : $cookiePath, $https, (string) getenv('WYSITE_COOKIE_DOMAIN'));
$repository = new BlockRepository($rootPath, $editPath);
$themes = new ThemeManager(__DIR__ . '/storage/config.php', __DIR__ . '/themes', __DIR__ . '/storage/state.local.php');
$settings = new WYSiteIWYG\SiteSettings(__DIR__ . '/storage/site.local.php');

// scheme://host of this request: the fallback base for absolute URLs (canonical,
// feeds) when Site settings has no canonical URL. The Host header is validated
// so a malformed value can't end up in generated pages.
$requestHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$siteOrigin = preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $requestHost) === 1 ? ($https ? 'https://' : 'http://') . strtolower($requestHost) : '';

$generator = new SiteGenerator($rootPath, $repository, $themes, $siteBaseUrl, $settings, $siteOrigin);
$generator->setEditorOrigin($editorOrigin, $appUrl);
$externalImporter = new ExternalSiteImporter($rootPath, $editPath);
$externalImporter->setByteBudget((int) $settings->get('import_byte_budget_mb') * 1_048_576);
$externalImporter->setKeepScripts((bool) $settings->get('keep_imported_scripts'));
$externalImporter->setStageImports((bool) $settings->get('stage_imports'));
$externalImporter->setImportOptions($settings->importIgnoredParams(), (bool) $settings->get('import_prefer_originals'));
if ($settings->embedHosts() !== []) {
    WYSiteIWYG\Sanitizer::setEmbedHosts($settings->embedHosts());
}
// Absolute URLs to these hosts count as same-origin for the frame rules.
WYSiteIWYG\Sanitizer::setSiteHosts(array_filter([
    (string) parse_url($siteOrigin, PHP_URL_HOST),
    (string) parse_url((string) $settings->get('canonical_base_url'), PHP_URL_HOST),
]));
$ai = new AiAssistant(__DIR__ . '/storage/ai.local.php');
$componentStyles = new WYSiteIWYG\ComponentStyles($rootPath, $siteBaseUrl);

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
    'rescan' => new WYSiteIWYG\SecurityRescan($rootPath, $editPath, $repository, $settings),
    'componentStyles' => $componentStyles,
    'audit' => new WYSiteIWYG\AuditLog(__DIR__ . '/storage/audit.local.jsonl'),
    'components' => new WYSiteIWYG\ComponentLibrary($editPath, $themes, $componentStyles),
    'themeImporter' => new WYSiteIWYG\ThemeImporter($rootPath, $externalImporter, $themes),
    'dedupe' => new WYSiteIWYG\ImportDedupe($rootPath, $editPath, $externalImporter->assetIndex(), static fn(): array => $externalImporter->importedFiles()),
    'siteOrigin' => $siteOrigin,
    'editorOrigin' => $editorOrigin,
    'siteTitle' => (string) $settings->get('site_name'),
];
