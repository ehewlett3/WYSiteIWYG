<?php
declare(strict_types=1);

use WYSiteIWYG\Csrf;
use WYSiteIWYG\Filesystem;
use WYSiteIWYG\Flash;
use function WYSiteIWYG\h;
use function WYSiteIWYG\is_post;
use function WYSiteIWYG\json_response;
use function WYSiteIWYG\redirect;
use function WYSiteIWYG\request_data;

$app = require __DIR__ . '/bootstrap.php';

$auth = $app['auth'];
$repository = $app['repository'];
$generator = $app['generator'];
$externalImporter = $app['externalImporter'];
$themes = $app['themes'];
$ai = $app['ai'];
$appUrl = $app['appUrl'];
$rootPath = $app['rootPath'];
$siteBaseUrl = $app['siteBaseUrl'];
$siteTitle = $app['siteTitle'];
$installToken = $app['installToken'];
$revisions = $app['revisions'];
$settings = $app['settings'];
$systemCheck = $app['systemCheck'];
$siteReport = $app['report'];
$backups = $app['backups'];

// CSS variable overrides for the chosen dashboard theme (empty = built-in look).
$GLOBALS['WYSITE_DASHBOARD_CSS'] = $themes->dashboardCssVariables();

$action = $_GET['action'] ?? 'dashboard';

if (!$auth->isInstalled() && $action !== 'install') {
    redirect($appUrl . '/index.php?action=install');
}

function render_dashboard_nav(string $appUrl, ?string $active, array $user): string
{
    $items = [
        ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => $appUrl . '/index.php', 'admin' => false],
        ['id' => 'themes', 'label' => 'Theme', 'href' => $appUrl . '/index.php?action=themes', 'admin' => false],
        ['id' => 'manager', 'label' => 'Manager', 'href' => $appUrl . '/index.php?action=manager', 'admin' => true],
        ['id' => 'ai', 'label' => 'AI', 'href' => $appUrl . '/index.php?action=ai', 'admin' => true],
        ['id' => 'users', 'label' => 'Users', 'href' => $appUrl . '/index.php?action=users', 'admin' => true],
        ['id' => 'settings', 'label' => 'Settings', 'href' => $appUrl . '/index.php?action=settings', 'admin' => true],
        ['id' => 'docs', 'label' => 'Docs', 'href' => $appUrl . '/index.php?action=docs', 'admin' => false],
    ];

    // Tabs only render on the dashboard views (when $active is set); sub-pages still
    // show the username dropdown so Log out stays reachable.
    $links = '';
    if ($active !== null) {
        foreach ($items as $item) {
            if ($item['admin'] && empty($user['is_admin'])) {
                continue;
            }
            $class = 'wysite-nav__link' . ($item['id'] === $active ? ' is-active' : '');
            $links .= '<a class="' . $class . '" href="' . h($item['href']) . '">' . h($item['label']) . '</a>';
        }
    }

    // Rightmost item: username with a native <details> dropdown holding Log out
    // (no inline JS, so it works under the strict dashboard CSP).
    $userMenu = '<details class="wysite-nav__user">'
        . '<summary class="wysite-nav__user-name">' . h($user['username']) . '</summary>'
        . '<div class="wysite-nav__user-menu">'
        . '<a class="wysite-nav__logout" href="' . h($appUrl) . '/index.php?action=account">Change my password</a>'
        . '<form method="post" action="' . h($appUrl) . '/index.php?action=logout">'
        . '<input type="hidden" name="csrf_token" value="' . h(Csrf::token()) . '">'
        . '<button type="submit" class="wysite-nav__logout">Log out</button>'
        . '</form>'
        . '</div>'
        . '</details>';

    return '<nav class="wysite-nav">' . $links . $userMenu . '</nav>';
}

function layout(string $title, string $body, string $appUrl, string $siteTitle, ?array $user = null, ?string $navActive = null): void
{
    // The dashboard renders only the editor's own (escaped) markup, so it can run
    // under a strict CSP: no inline JS at all — confirmations and the import UI live
    // in dashboard.js. Inline styles remain allowed for the dashboard-theme block.
    \WYSiteIWYG\send_csp('dashboard');
    $flashes = Flash::consume();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> | <?= h($siteTitle) ?></title>
  <link rel="stylesheet" href="<?= h($appUrl) ?>/assets/editor.css">
  <?php if (!empty($GLOBALS['WYSITE_DASHBOARD_CSS'])): ?><style><?= $GLOBALS['WYSITE_DASHBOARD_CSS'] ?></style><?php endif; ?>
  <script src="<?= h($appUrl) ?>/assets/dashboard.js" defer></script>
</head>
<body class="wysite-app-shell">
  <div class="wysite-shell">
    <header class="wysite-shell__header">
      <div class="wysite-brand">
        <p class="wysite-kicker">Filesystem-first static site editing</p>
        <h1><?= h($siteTitle) ?></h1>
      </div>
      <?php if ($user): ?>
        <?= render_dashboard_nav($appUrl, $navActive, $user) ?>
      <?php endif; ?>
    </header>

    <?php foreach ($flashes as $flash): ?>
      <div class="wysite-flash wysite-flash--<?= h($flash['type']) ?>"><?= render_flash_message((string) $flash['message']) ?></div>
    <?php endforeach; ?>

    <?= $body ?>
  </div>
</body>
</html>
    <?php
}

function render_flash_message(string $message): string
{
    $lines = array_values(
        array_filter(
            array_map(
                static fn(string $line): string => trim($line),
                preg_split('/\R/', $message) ?: []
            ),
            static fn(string $line): bool => $line !== ''
        )
    );

    if (count($lines) <= 1) {
        return h($message);
    }

    $title = array_shift($lines);
    $items = array_map(
        static fn(string $line): string => preg_replace('/^\-\s*/', '', $line) ?? $line,
        $lines
    );

    $html = '<p class="wysite-flash__title">' . h($title) . '</p>';
    $html .= '<ul class="wysite-flash__list">';
    foreach ($items as $item) {
        $html .= '<li>' . h($item) . '</li>';
    }
    $html .= '</ul>';

    return $html;
}

/** Why an import that stripped scripts may look or behave differently from the original. */
function scripts_removed_notice(int $count): string
{
    return '- Scripts removed: ' . $count . '. Menus, tabs, toggles, sliders and animations that the original theme built with JavaScript will not work. '
        . 'If you trust the source site, turn on "Keep the original site\'s scripts" in Settings and re-import with overwrite.';
}

function require_login($auth, string $appUrl): array
{
    $user = $auth->currentUser();
    if ($user === null) {
        Flash::push('error', 'Please sign in to edit the site.');
        redirect($appUrl . '/index.php?action=login');
    }

    return $user;
}

function require_admin(array $user): void
{
    if (empty($user['is_admin'])) {
        throw new RuntimeException('Administrator access is required for that action.');
    }
}

/**
 * Sensitive account changes re-check the signed-in user's own password, so a
 * hijacked session (e.g. script running in an admin's browser) can't mint admins
 * or lock people out on its own.
 */
function require_reauth($auth, array $user, array $data): void
{
    if (!$auth->verifyPassword((string) $user['username'], (string) ($data['current_password'] ?? ''))) {
        throw new RuntimeException('Please re-enter your own password to confirm that change.');
    }
}

/** Return to a dashboard view after an action (instead of always the Dashboard). */
/**
 * Optimistic concurrency for in-page saves: the preview carries the file's sha1
 * and saves send it back. If someone else saved in between, refuse rather than
 * silently overwrite their work.
 */
function assert_page_unchanged($repository, string $path, array $data): void
{
    $baseHash = (string) ($data['baseHash'] ?? '');
    if ($baseHash === '') {
        return;
    }

    if (!hash_equals(sha1($repository->getPage($path)['html']), $baseHash)) {
        json_response(['ok' => false, 'conflict' => true, 'message' => 'This page changed since you opened it (another tab or editor saved it). Reload to get the latest version, then reapply your edit.'], 409);
    }
}

function redirect_to_view(string $appUrl, string $view = 'dashboard'): never
{
    redirect($appUrl . '/index.php' . ($view === 'dashboard' ? '' : '?action=' . rawurlencode($view)));
}

/**
 * Validate an uploaded image and store it under assets/uploads/<subdir>/ with a
 * random name. Works without ext-fileinfo (falls back to getimagesize()).
 * Returns the root-relative path.
 */
function store_uploaded_image(array $upload, string $rootPath, string $subdir, bool $allowIcon = false): string
{
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No image was uploaded successfully.');
    }

    $tmpPath = (string) ($upload['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
        throw new RuntimeException('The uploaded image could not be processed.');
    }

    $size = (int) ($upload['size'] ?? 0);
    if ($size <= 0 || $size > 10 * 1024 * 1024) {
        throw new RuntimeException('Images must be smaller than 10 MB.');
    }

    $mime = '';
    if (class_exists(finfo::class)) {
        $mime = (string) (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath);
    } elseif (function_exists('getimagesize')) {
        $info = @getimagesize($tmpPath);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/avif' => 'avif',
    ];
    if ($allowIcon) {
        $extensions += ['image/vnd.microsoft.icon' => 'ico', 'image/x-icon' => 'ico'];
    }

    $extension = $extensions[$mime] ?? null;
    if ($extension === null) {
        throw new RuntimeException('Only ' . ($allowIcon ? 'ICO, ' : '') . 'JPG, PNG, GIF, WebP, and AVIF images are supported.');
    }

    $relativePath = 'assets/uploads/' . trim($subdir, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
    $targetPath = $rootPath . '/' . $relativePath;
    Filesystem::ensureDirectory(dirname($targetPath));
    \WYSiteIWYG\AssetPolicy::ensureAssetsHtaccess($rootPath);

    $contents = file_get_contents($tmpPath);
    if ($contents === false) {
        throw new RuntimeException('The uploaded image could not be read.');
    }
    Filesystem::atomicWrite($targetPath, $contents);

    return $relativePath;
}

/** Render SystemCheck rows as a table. */
function render_system_rows(array $rows): string
{
    $labels = ['pass' => 'OK', 'warn' => 'Warning', 'fail' => 'Problem'];
    $html = '<div class="wysite-table-wrap"><table class="wysite-table wysite-system"><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr class="is-' . h($row['status']) . '"><td><span class="wysite-status wysite-status--' . h($row['status']) . '">'
            . h($labels[$row['status']] ?? $row['status']) . '</span></td><td><strong>' . h($row['label']) . '</strong></td><td>' . h($row['detail']) . '</td></tr>';
    }
    return $html . '</tbody></table></div>';
}

/** Truncate to $length characters, multibyte-aware when mbstring is available. */
function mb_substr_safe(string $value, int $length): string
{
    return function_exists('mb_substr') ? mb_substr($value, 0, $length) : substr($value, 0, $length);
}

/** Human label for a page kind (UX-12). */
function kind_label(string $kind): string
{
    return [
        'home' => 'Home',
        'page' => 'Page',
        'blog-post' => 'Blog post',
        'blog' => 'Blog index',
    ][$kind] ?? ucfirst(str_replace('-', ' ', $kind));
}

/** Public URL of a page path under the site base (for display and links). */
function public_page_url(string $siteBaseUrl, string $relativePath): string
{
    $clean = ltrim($relativePath, '/');
    if ($clean === 'index.html') {
        return $siteBaseUrl;
    }
    if (str_ends_with($clean, '/index.html')) {
        return $siteBaseUrl . substr($clean, 0, -strlen('index.html'));
    }

    return $siteBaseUrl . (preg_replace('/\.html?$/i', '', $clean) ?? $clean) . '/';
}

/** Summarize a deployDemoSite() result for a flash message. */
function demo_deploy_message(array $result): string
{
    $message = 'Deployed the demo site: ' . $result['created'] . ' page(s) created, ' . $result['refreshed'] . ' refreshed.';
    if ($result['skipped'] !== []) {
        $message .= "\n- Skipped (already exists, left untouched): " . implode(', ', $result['skipped']);
    }
    foreach ($result['warnings'] as $warning) {
        $message .= "\n- " . $warning;
    }

    return $message;
}

/**
 * A cheap, no-AI default template kind for an unmanaged HTML file, from its path.
 * Used as the baseline for the "Use as template" dropdown; AI classification (when
 * configured) refines it.
 */
function guess_template_kind(string $path): string
{
    $lower = strtolower(ltrim(str_replace('\\', '/', $path), '/'));
    if ($lower === 'index.html' || $lower === 'index.htm') {
        return 'home';
    }
    if (preg_match('#(^|/)blog/index\.html?$#', $lower) === 1) {
        return 'blog';
    }
    if (preg_match('#(^|/)blog/#', $lower) === 1) {
        return 'blog-post';
    }
    return 'page';
}

/**
 * Screenshots uploaded to guide AI theme design, as base64 images the model can
 * read. At most three; each must be a real PNG, JPEG, GIF, or WebP image of up to
 * 3.5 MB (the providers' per-image limits are about 5 MB after encoding).
 *
 * @return array<int,array{media_type:string,data:string}>
 */
function ai_theme_screenshots(mixed $files): array
{
    if (!is_array($files) || !is_array($files['tmp_name'] ?? null)) {
        return [];
    }

    $images = [];
    foreach ($files['tmp_name'] as $index => $tmp) {
        $error = (int) ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $label = (string) ($files['name'][$index] ?? 'screenshot');
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) $tmp)) {
            throw new RuntimeException('The screenshot "' . $label . '" did not upload.');
        }
        if ((int) filesize((string) $tmp) > 3_500_000) {
            throw new RuntimeException('The screenshot "' . $label . '" is larger than 3.5 MB. Use a smaller or more compressed image.');
        }
        $info = @getimagesize((string) $tmp);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            throw new RuntimeException('The screenshot "' . $label . '" is not a PNG, JPEG, GIF, or WebP image.');
        }
        if (count($images) >= 3) {
            throw new RuntimeException('Upload at most three screenshots.');
        }
        $images[] = ['media_type' => $mime, 'data' => base64_encode((string) file_get_contents((string) $tmp))];
    }

    return $images;
}

/**
 * If AI assistance is configured, ask the model to pre-select template regions for a
 * cached source and attach them for the designer to show. Best-effort: any failure
 * (misconfig, network, bad key) is surfaced as a flash but never blocks the manual
 * designer, which remains the fallback and the source of truth.
 */
function maybe_attach_ai_suggestions($ai, $externalImporter, string $sourceId, string $kind): void
{
    if (!$ai->isConfigured('regions')) {
        return;
    }

    try {
        $source = $externalImporter->getCachedTemplateSource($sourceId);
        $suggestions = $ai->suggestRoles((string) $source['html'], $kind, $externalImporter->templateRoles($kind));
        if ($suggestions !== []) {
            $externalImporter->attachTemplateSuggestions($sourceId, $suggestions);
            Flash::push('success', 'AI pre-selected ' . count($suggestions) . ' region(s). Review and adjust them before saving the template.');
        } else {
            Flash::push('error', 'The AI assistant could not confidently match any regions; select them manually.');
        }
    } catch (Throwable $error) {
        Flash::push('error', 'AI suggestion skipped: ' . $error->getMessage() . ' You can still select regions manually.');
    }
}

try {
    $hashSummary = $auth->passwordHashSummary();

    if ($action === 'session-status') {
        $user = $auth->currentUser();
        $requestPath = (string) ($_GET['path'] ?? '/');
        $relativePath = $repository->relativeFromRequestPath($requestPath);

        json_response([
            'loggedIn' => $user !== null,
            'username' => $user['username'] ?? null,
            'editUrl' => $user !== null && $relativePath !== null
                ? $appUrl . '/index.php?action=preview&path=' . rawurlencode($relativePath)
                : null,
            'dashboardUrl' => $user !== null ? $appUrl . '/index.php' : null,
        ]);
    }

    if ($action === 'install') {
        if ($auth->isInstalled()) {
            redirect($appUrl . '/index.php');
        }

        $tokenError = '';
        try {
            $installToken->ensure();
        } catch (Throwable $error) {
            $tokenError = $error->getMessage();
        }
        $siteHasPages = (glob($rootPath . '/*.html') ?: []) !== [] || (glob($rootPath . '/*.htm') ?: []) !== [];
        $systemRows = $systemCheck->run();

        if (is_post()) {
            if (\WYSiteIWYG\SystemCheck::hasFailures($systemRows)) {
                throw new RuntimeException('Fix the problems listed under "Server check" before installing.');
            }

            $data = request_data();
            if (!Csrf::validate($data['csrf_token'] ?? null)) {
                throw new RuntimeException('Your session expired. Refresh and try again.');
            }

            if (!$installToken->verify((string) ($data['setup_token'] ?? ''))) {
                throw new RuntimeException('The setup token was not correct. Copy it from ' . \WYSiteIWYG\InstallToken::RELATIVE_PATH . ' on the server.');
            }

            $auth->bootstrapAdmin(trim((string) ($data['username'] ?? '')), (string) ($data['password'] ?? ''));
            $installToken->clear();
            $auth->attempt(trim((string) ($data['username'] ?? '')), (string) ($data['password'] ?? ''));
            Flash::push('success', 'WYSiteIWYG is ready. Your admin account has been created.');

            if (!empty($data['install_demo'])) {
                $deployed = $generator->deployDemoSite(require __DIR__ . '/docs/content.php');
                Flash::push('success', demo_deploy_message($deployed) . ' Delete it anytime from the Manager.');
            }

            redirect($appUrl . '/index.php');
        }

        ob_start();
        ?>
        <main class="wysite-auth-card">
          <div>
            <p class="wysite-kicker">First-run setup</p>
            <h2>Create the first administrator</h2>
            <p>The credentials are stored as one-way <?= h($hashSummary['algorithm']) ?> password hashes in <code>edit/storage/users.local.php</code>, so the editor can stay self-contained inside the <code>/edit/</code> folder without encouraging commits of live password data.</p>
          </div>
          <details class="wysite-system-summary"<?= \WYSiteIWYG\SystemCheck::hasFailures($systemRows) ? ' open' : '' ?>>
            <summary>Server check: <?= \WYSiteIWYG\SystemCheck::hasFailures($systemRows) ? 'problems found' : 'ready' ?></summary>
            <?= render_system_rows($systemRows) ?>
          </details>
          <form method="post" class="wysite-form">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <label>
              <span>Setup token</span>
              <input type="text" name="setup_token" required autocomplete="off" spellcheck="false">
            </label>
            <?php if ($tokenError !== ''): ?>
              <p class="wysite-flash wysite-flash--error">The setup token could not be created (<?= h($tokenError) ?>). Make <code>edit/storage/</code> writable by the web server, or set the <code>WYSITE_INSTALL_TOKEN</code> environment variable, then reload.</p>
            <?php endif; ?>
            <p class="wysite-muted">
              <?php if ($installToken->usesEnvironment()): ?>
                Enter the value of the <code>WYSITE_INSTALL_TOKEN</code> environment variable.
              <?php else: ?>
                To prove you control this server, open <code><?= h(\WYSiteIWYG\InstallToken::RELATIVE_PATH) ?></code> (via SSH, SFTP, or your host's file manager) and copy the token it contains. The file is deleted once setup finishes.
              <?php endif; ?>
            </p>
            <label>
              <span>Username</span>
              <input type="text" name="username" required minlength="3" maxlength="32">
            </label>
            <label>
              <span>Password</span>
              <input type="password" name="password" required minlength="10">
            </label>
            <label class="wysite-checkbox">
              <input type="checkbox" name="install_demo" value="1"<?= $siteHasPages ? '' : ' checked' ?>>
              <span>Also install the demo site — the built-in documentation pages, deployed to the site root as editable content. Leave unchecked if you are dropping <code>/edit/</code> into an existing site.</span>
            </label>
            <?php if ($siteHasPages): ?>
              <p class="wysite-flash wysite-flash--error">This folder already contains HTML pages, so the demo is off by default. Installing it alongside an existing site adds documentation pages next to yours; existing pages are never overwritten.</p>
            <?php endif; ?>
            <button class="wysite-button" type="submit">Create administrator</button>
          </form>
        </main>
        <?php
        layout('Install', (string) ob_get_clean(), $appUrl, $siteTitle);
        return;
    }

    if ($action === 'login') {
        if ($auth->currentUser() !== null) {
            redirect($appUrl . '/index.php');
        }

        if (is_post()) {
            $data = request_data();
            if (!Csrf::validate($data['csrf_token'] ?? null)) {
                throw new RuntimeException('Your session expired. Refresh and try again.');
            }

            $ok = $auth->attempt(trim((string) ($data['username'] ?? '')), (string) ($data['password'] ?? ''));
            if (!$ok) {
                throw new RuntimeException('The username or password was not recognized.');
            }

            Flash::push('success', 'Signed in successfully.');
            redirect($appUrl . '/index.php');
        }

        ob_start();
        ?>
        <main class="wysite-auth-card">
          <div>
            <p class="wysite-kicker">Sign in</p>
            <h2>Edit the live static site</h2>
            <p>WYSiteIWYG loads the real HTML files from the site root, overlays edit controls, and saves changes directly back to disk. Passwords are checked against one-way <?= h($hashSummary['algorithm']) ?> hashes.</p>
          </div>
          <form method="post" class="wysite-form">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <label>
              <span>Username</span>
              <input type="text" name="username" required>
            </label>
            <label>
              <span>Password</span>
              <input type="password" name="password" required>
            </label>
            <button class="wysite-button" type="submit">Sign in</button>
          </form>
        </main>
        <?php
        layout('Login', (string) ob_get_clean(), $appUrl, $siteTitle);
        return;
    }

    if ($action === 'logout') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The logout request was rejected.');
        }

        $auth->logout();
        session_start();
        Flash::push('success', 'You have been signed out.');
        redirect($appUrl . '/index.php?action=login');
    }

    $user = require_login($auth, $appUrl);

    if ($action === 'upload-image') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        $upload = $_FILES['image'] ?? null;
        if (!is_array($upload)) {
            json_response(['ok' => false, 'message' => 'No image was uploaded successfully.'], 400);
        }

        try {
            $relativePath = store_uploaded_image($upload, $rootPath, gmdate('Y/m'));
        } catch (RuntimeException $error) {
            json_response(['ok' => false, 'message' => $error->getMessage()], 400);
        }

        $publicUrl = $siteBaseUrl . ltrim($relativePath, '/');
        json_response([
            'ok' => true,
            'url' => $publicUrl,
            'path' => $relativePath,
            'message' => 'Image uploaded.',
        ]);
    }

    if ($action === 'preview') {
        $path = (string) ($_GET['path'] ?? 'index.html');
        $previewTheme = trim((string) ($_GET['theme'] ?? ''));

        // The preview renders the site's own (possibly imported) HTML in the app
        // origin; a strict nonce/strict-dynamic CSP stops any script that arrives
        // with that HTML from running with the session/CSRF token in reach.
        \WYSiteIWYG\send_csp('preview');
        $nonce = \WYSiteIWYG\csp_nonce();

        if ($previewTheme !== '') {
            $page = $repository->getPage($path);
            $themes->assertThemeIsValid($previewTheme);
            $theme = $themes->getTheme($previewTheme);
            $html = $generator->renderPageForTheme($path, $previewTheme, $themes->previewStylesheetHref($previewTheme, $appUrl));
            echo $repository->renderPreviewHtmlFromSource(
                $path,
                $html,
                $appUrl,
                $siteBaseUrl,
                Csrf::token(),
                $user['username'],
                [
                    'previewThemeId' => $previewTheme,
                    'previewThemeName' => $theme['name'],
                    'pageKind' => $page['kind'],
                    'activeTemplatePath' => $repository->activeTemplateRelativePath($page['kind']),
                    'selectionSaveEnabled' => false,
                    'isAdmin' => !empty($user['is_admin']),
                ],
                $nonce
            );
        } else {
            echo $repository->renderPreviewHtml($path, $appUrl, $siteBaseUrl, Csrf::token(), $user['username'], $nonce, [
                'isAdmin' => !empty($user['is_admin']),
            ]);
        }
        return;
    }

    if ($action === 'pages-index') {
        // Published pages for the editor's link picker, as base-relative URLs.
        $index = [];
        foreach ($repository->listPages() as $indexPage) {
            if (($indexPage['paged'] ?? '') !== '') {
                continue;
            }
            $url = ltrim((string) $indexPage['url'], '/');
            $index[] = ['title' => (string) $indexPage['title'], 'url' => $url === '' ? './' : $url, 'kind' => $indexPage['kind']];
        }
        usort($index, static fn(array $a, array $b): int => strcasecmp($a['title'], $b['title']));
        json_response(['ok' => true, 'pages' => $index]);
    }

    if ($action === 'block') {
        $path = (string) ($_GET['path'] ?? '');
        $name = (string) ($_GET['name'] ?? '');
        json_response(['ok' => true, 'block' => $repository->getBlock($path, $name)]);
    }

    if ($action === 'save-block') {
        $data = request_data();
        if (!Csrf::validate($data['csrfToken'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        $path = (string) ($data['path'] ?? '');
        $name = (string) ($data['name'] ?? '');
        $block = $repository->getBlock($path, $name);
        if (!empty($block['admin_only'])) {
            // Raw-HTML block (SEC-4 step 4): only admins may change it, and their
            // markup is kept as written apart from PHP tags and marker comments.
            if (empty($user['is_admin'])) {
                json_response(['ok' => false, 'message' => 'This block holds raw HTML and is admin-only.'], 403);
            }
            $html = str_replace(['<?', '?>'], ['&lt;?', '?&gt;'], (string) ($data['html'] ?? ''));
            $html = preg_replace('/<!--\s*WYSITE:.*?-->/s', '', $html) ?? $html;
        } else {
            // Never trust the browser-side schema: strip scripts, handlers, unsafe
            // URLs and marker comments before anything reaches a public page.
            $html = \WYSiteIWYG\Sanitizer::html((string) ($data['html'] ?? ''));
        }
        assert_page_unchanged($repository, $path, $data);

        if (($block['type'] ?? '') === 'menu' || $name === 'main-menu') {
            $generator->syncMenu($html);
            json_response(['ok' => true, 'message' => 'The shared menu was updated across pages and templates.', 'fileHash' => sha1($repository->getPage($path)['html'])]);
        }

        $html = $generator->normalizeEditableBlockHtml((string) ($block['type'] ?? ''), $html);
        $repository->updateBlock($path, $name, $html);
        json_response(['ok' => true, 'message' => 'The page was saved.', 'fileHash' => sha1($repository->getPage($path)['html'])]);
    }

    if ($action === 'save-selection') {
        $data = request_data();
        if (!Csrf::validate($data['csrfToken'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        $path = (string) ($data['path'] ?? '');
        $scope = (string) ($data['scope'] ?? 'page');
        $html = \WYSiteIWYG\Sanitizer::html((string) ($data['html'] ?? ''), $scope === 'template' ? 'import' : 'content');
        $domPath = $data['domPath'] ?? null;
        $page = $repository->getPage($path);
        $kind = (string) ($page['kind'] ?? 'page');

        if (!is_array($domPath)) {
            json_response(['ok' => false, 'message' => 'The selected section path was invalid.'], 400);
        }

        if ($scope === 'template') {
            // Templates are site-wide chrome; like theme apply, only admins change them.
            if (empty($user['is_admin'])) {
                json_response(['ok' => false, 'message' => 'Template sections are admin-only. Ask an administrator to change the site-wide header, footer, or layout.'], 400);
            }

            $backups->create('template-edit');
            $templatePath = $repository->updateSelectedElementInActiveTemplate($kind, $domPath, $html);
            $updatedPages = $generator->rebuildPagesUsingTemplate($kind);
            json_response([
                'ok' => true,
                'message' => 'The active template was saved and ' . $updatedPages . ' page(s) using it were rebuilt.',
                'templatePath' => $templatePath,
                'updatedPages' => $updatedPages,
            ]);
        }

        assert_page_unchanged($repository, $path, $data);
        $repository->updateSelectedElement($path, $domPath, $html);
        json_response(['ok' => true, 'message' => 'The selected section was saved.', 'fileHash' => sha1($repository->getPage($path)['html'])]);
    }

    if ($action === 'create-page') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The page creation request was rejected.');
        }

        $title = trim((string) ($_POST['title'] ?? ''));
        $isDraft = !empty($_POST['draft']);
        $path = $generator->createPage($title, (string) ($_POST['slug'] ?? ''), $isDraft);
        if (!empty($_POST['add_to_menu'])) {
            $generator->addMenuLink($path, $title);
        }
        Flash::push('success', ($isDraft ? 'Created a draft of ' : 'Created ') . \WYSiteIWYG\SitePath::publicPath($path) . ($isDraft ? ' — it stays private until you publish it from Page details.' : '.'));
        redirect($appUrl . '/index.php?action=preview&path=' . rawurlencode($path));
    }

    if ($action === 'create-post') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The blog creation request was rejected.');
        }

        $isDraft = !empty($_POST['draft']);
        $path = $generator->createBlogPost(
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['slug'] ?? ''),
            (string) ($_POST['excerpt'] ?? ''),
            (string) ($_POST['hashtags'] ?? '#blog'),
            null,
            $isDraft
        );
        if (!empty($_POST['add_to_menu'])) {
            $generator->addMenuLink($path, trim((string) ($_POST['title'] ?? '')));
        }
        Flash::push('success', $isDraft
            ? 'Created a draft post. It stays off the blog and feeds until you publish it from Page details.'
            : 'Created ' . $path . ' and refreshed the hashtag landing pages.');
        redirect($appUrl . '/index.php?action=preview&path=' . rawurlencode($path));
    }

    if ($action === 'dismiss-checklist') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The request was rejected.');
        }
        $settings->update(['checklist_dismissed' => true]);
        redirect_to_view($appUrl, 'dashboard');
    }

    if ($action === 'set-block-access') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The request was rejected.');
        }
        $path = (string) ($_POST['path'] ?? '');
        $page = $repository->getPage($path);
        $names = array_column($page['blocks'], 'name');
        $chosen = array_values(array_intersect($names, (array) ($_POST['admin_blocks'] ?? [])));
        $repository->updateMetadata($page['path'], ['admin_blocks' => implode(',', $chosen)]);
        Flash::push('success', $chosen === [] ? 'All blocks are editable by editors again.' : 'Raw-HTML (admin-only) blocks: ' . implode(', ', $chosen) . '.');
        redirect($appUrl . '/index.php?action=metadata&path=' . rawurlencode($page['path']));
    }

    if (in_array($action, ['publish-page', 'unpublish-page', 'move-page', 'delete-page'], true)) {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The page request was rejected.');
        }

        $path = (string) ($_POST['path'] ?? '');
        if ($action === 'publish-page') {
            $public = $generator->publishDraft($path);
            Flash::push('success', 'Published ' . $public . '.');
            redirect($appUrl . '/index.php?action=metadata&path=' . rawurlencode($public));
        }
        if ($action === 'unpublish-page') {
            $draft = $generator->unpublishPage($path);
            Flash::push('success', 'Unpublished. The page is now a private draft.');
            redirect($appUrl . '/index.php?action=metadata&path=' . rawurlencode($draft));
        }
        if ($action === 'move-page') {
            $moved = $generator->movePage($path, (string) ($_POST['slug'] ?? ''));
            Flash::push('success', 'Moved to ' . \WYSiteIWYG\SitePath::publicPath($moved) . (\WYSiteIWYG\SitePath::isDraft($moved) ? '.' : '. The old address redirects to the new one and menu links were updated.'));
            redirect($appUrl . '/index.php?action=metadata&path=' . rawurlencode($moved));
        }

        $warning = $generator->deletePage($path);
        Flash::push($warning === null ? 'success' : 'error', 'Deleted ' . \WYSiteIWYG\SitePath::publicPath($path) . '. Its last version is kept in the page history.' . ($warning !== null ? "\n- " . $warning : ''));
        redirect_to_view($appUrl, 'dashboard');
    }

    if ($action === 'metadata') {
        $path = (string) ($_GET['path'] ?? '');
        $page = $repository->getPage($path);
        $meta = $page['meta'];

        ob_start();
        ?>
        <main class="wysite-dashboard">
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">Page details</p>
                <h2><?= h($meta['title'] ?? $page['path']) ?></h2>
              </div>
              <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=preview&path=<?= rawurlencode($page['path']) ?>">Back to page</a>
            </div>
            <?php $isTagIndex = ($meta['generated'] ?? '') === 'tag-index'; ?>
            <?php $publicUrl = public_page_url($siteBaseUrl, \WYSiteIWYG\SitePath::publicPath($page['path'])); ?>
            <p class="wysite-muted">Public URL<?= \WYSiteIWYG\SitePath::isDraft($page['path']) ? ' (once published)' : '' ?>: <a href="<?= h($publicUrl) ?>" target="_blank" rel="noreferrer"><code><?= h($publicUrl) ?></code></a> · File: <code><?= h($page['path']) ?></code></p>

            <form method="post" action="<?= h($appUrl) ?>/index.php?action=save-metadata" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <input type="hidden" name="path" value="<?= h($page['path']) ?>">
              <label>
                <span>Title</span>
                <input type="text" name="title" required value="<?= h((string) ($meta['title'] ?? '')) ?>">
              </label>
              <?php if ($isTagIndex): ?>
                <p class="wysite-muted">Type: <?= h(kind_label('blog')) ?> (generated from hashtags)</p>
              <?php else: ?>
              <label>
                <span>Type</span>
                <select name="kind" class="wysite-theme-select">
                  <?php $typeOptions = $page['path'] === 'index.html' ? ['home', 'page', 'blog-post'] : ['page', 'blog-post']; ?>
                  <?php foreach ($typeOptions as $option): ?>
                    <option value="<?= h($option) ?>"<?= $page['kind'] === $option ? ' selected' : '' ?>><?= h(kind_label($option)) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <p class="wysite-muted">Blog posts show a date and appear on <code>/blog/</code> and on a landing page for each hashtag (e.g. <code>#launch</code> → <code>/launch/</code>). Pages ignore hashtags. Changing the type re-renders this page with the matching template; its content is kept.</p>
              <?php endif; ?>
              <label>
                <span>Excerpt / snippet</span>
                <textarea name="excerpt" rows="3"><?= h((string) ($meta['excerpt'] ?? '')) ?></textarea>
              </label>
              <label>
                <span>Date</span>
                <input type="date" name="date" value="<?= h((string) ($meta['date'] ?? '')) ?>">
              </label>
              <label>
                <span>Hashtags</span>
                <input type="text" name="hashtags" placeholder="#blog #launch" value="<?= h((string) ($meta['hashtags'] ?? '')) ?>">
              </label>
              <label>
                <span>Author <em>(optional; shown on blog cards)</em></span>
                <input type="text" name="author" maxlength="120" value="<?= h((string) ($meta['author'] ?? '')) ?>">
              </label>
              <label>
                <span>Featured image <em>(optional; an assets/… path or https:// URL, used on blog cards and link previews)</em></span>
                <input type="text" name="image" placeholder="assets/uploads/2026/09/photo.jpg" value="<?= h((string) ($meta['image'] ?? '')) ?>">
              </label>
              <label class="wysite-checkbox">
                <input type="checkbox" name="exclude_template" value="1" <?= (($meta['exclude_template'] ?? '') === '1') ? 'checked' : '' ?>>
                <span>Exclude this page from template rebuilds and theme apply operations</span>
              </label>
              <div class="wysite-hero-actions">
                <button class="wysite-button" type="submit">Save details</button>
                <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php">Back to dashboard</a>
              </div>
            </form>
          </section>

          <?php $isDraftPage = \WYSiteIWYG\SitePath::isDraft($page['path']); ?>
          <?php if (!$isTagIndex): ?>
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">Status &amp; address</p>
                <h3><?= $isDraftPage ? 'Draft — not public yet' : 'Published' ?></h3>
              </div>
            </div>
            <div class="wysite-grid">
              <article>
                <?php if ($isDraftPage): ?>
                  <p class="wysite-muted">Only signed-in editors can see this page. Publishing makes it live at <code><?= h(public_page_url($siteBaseUrl, \WYSiteIWYG\SitePath::publicPath($page['path']))) ?></code> and adds it to the blog and feeds if it is a post.</p>
                  <form method="post" action="<?= h($appUrl) ?>/index.php?action=publish-page">
                    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="path" value="<?= h($page['path']) ?>">
                    <button class="wysite-button" type="submit">Publish now</button>
                  </form>
                  <form method="post" action="<?= h($appUrl) ?>/index.php?action=save-metadata" class="wysite-form">
                    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="path" value="<?= h($page['path']) ?>">
                    <input type="hidden" name="schedule_only" value="1">
                    <label><span>Or publish automatically on <em>(checked whenever someone opens the dashboard or saves)</em></span><input type="date" name="publish_at" value="<?= h((string) ($meta['publish_at'] ?? '')) ?>"></label>
                    <button class="wysite-button wysite-button--ghost" type="submit">Save schedule</button>
                  </form>
                <?php elseif ($page['path'] !== 'index.html'): ?>
                  <p class="wysite-muted">Unpublishing removes the page from the site (and the blog and feeds) and keeps it as a private draft.</p>
                  <form method="post" action="<?= h($appUrl) ?>/index.php?action=unpublish-page" data-wysite-confirm="Take this page offline and turn it back into a draft?">
                    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="path" value="<?= h($page['path']) ?>">
                    <button class="wysite-button wysite-button--ghost" type="submit">Unpublish</button>
                  </form>
                <?php endif; ?>
              </article>
              <?php if ($page['path'] !== 'index.html'): ?>
              <article>
                <form method="post" action="<?= h($appUrl) ?>/index.php?action=move-page" class="wysite-form">
                  <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                  <input type="hidden" name="path" value="<?= h($page['path']) ?>">
                  <label><span>Move to a new address</span><input type="text" name="slug" required data-wysite-slug-preview="<?= h($siteBaseUrl) ?>" placeholder="<?= h(trim(preg_replace('#(/index)?\.html?$#', '', \WYSiteIWYG\SitePath::publicPath($page['path'])) ?? '', '/')) ?>"></label>
                  <button class="wysite-button wysite-button--ghost" type="submit">Move</button>
                </form>
                <form method="post" action="<?= h($appUrl) ?>/index.php?action=delete-page" data-wysite-confirm="Delete this page? Its last version is kept in the page history.">
                  <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                  <input type="hidden" name="path" value="<?= h($page['path']) ?>">
                  <button class="wysite-button wysite-button--ghost" type="submit">Delete page</button>
                </form>
              </article>
              <?php endif; ?>
            </div>
          </section>
          <?php endif; ?>

          <?php if ($user['is_admin'] && $page['blocks'] !== []): ?>
          <?php $adminBlocks = $repository->adminOnlyBlocks($meta); ?>
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">Raw HTML blocks</p>
                <h3>Admin-only sections</h3>
              </div>
              <p class="wysite-muted">A raw-HTML block keeps exactly what an administrator writes (for example a widget's script). Editors can see it but cannot change it.</p>
            </div>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=set-block-access" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <input type="hidden" name="path" value="<?= h($page['path']) ?>">
              <?php foreach ($page['blocks'] as $pageBlock): ?>
                <?php if ($pageBlock['type'] === 'menu') { continue; } ?>
                <label class="wysite-checkbox">
                  <input type="checkbox" name="admin_blocks[]" value="<?= h($pageBlock['name']) ?>"<?= in_array($pageBlock['name'], $adminBlocks, true) ? ' checked' : '' ?>>
                  <span><?= h($pageBlock['label']) ?> <code><?= h($pageBlock['name']) ?></code></span>
                </label>
              <?php endforeach; ?>
              <button class="wysite-button wysite-button--ghost" type="submit">Save block access</button>
            </form>
          </section>
          <?php endif; ?>

          <?php $history = $revisions->list($page['path']); ?>
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">History</p>
                <h3>Earlier versions</h3>
              </div>
              <p class="wysite-muted">A copy is kept each time this page is saved or rebuilt (the newest 20).</p>
            </div>
            <?php if ($history === []): ?>
              <p class="wysite-muted">No earlier versions yet.</p>
            <?php else: ?>
              <div class="wysite-table-wrap">
                <table class="wysite-table">
                  <thead><tr><th>Saved (UTC)</th><th>Size</th><th></th></tr></thead>
                  <tbody>
                    <?php foreach ($history as $revision): ?>
                      <tr>
                        <td><?= h(gmdate('Y-m-d H:i:s', $revision['time'])) ?></td>
                        <td><?= h(number_format($revision['bytes'] / 1024, 1)) ?> KB</td>
                        <td class="wysite-table__actions">
                          <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=revision-view&path=<?= rawurlencode($page['path']) ?>&id=<?= rawurlencode($revision['id']) ?>" target="_blank" rel="noreferrer">View</a>
                          <form method="post" action="<?= h($appUrl) ?>/index.php?action=restore-revision" data-wysite-confirm="Restore the version saved <?= h(gmdate('Y-m-d H:i', $revision['time'])) ?> UTC? The current version is kept in the history.">
                            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                            <input type="hidden" name="path" value="<?= h($page['path']) ?>">
                            <input type="hidden" name="id" value="<?= h($revision['id']) ?>">
                            <button class="wysite-button wysite-button--ghost" type="submit">Restore</button>
                          </form>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </main>
        <?php
        layout('Page Details', (string) ob_get_clean(), $appUrl, $siteTitle, $user);
        return;
    }

    if ($action === 'save-metadata') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The page-details update request was rejected.');
        }

        $path = (string) ($_POST['path'] ?? '');
        $page = $repository->getPage($path);
        $path = $page['path'];
        $existingMeta = $page['meta'];

        if (!empty($_POST['schedule_only'])) {
            $publishAt = trim((string) ($_POST['publish_at'] ?? ''));
            if ($publishAt !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $publishAt) !== 1) {
                throw new RuntimeException('Choose a valid publish date.');
            }
            if (!\WYSiteIWYG\SitePath::isDraft($path)) {
                throw new RuntimeException('Only drafts can be scheduled.');
            }
            $repository->updateMetadata($path, ['publish_at' => $publishAt]);
            Flash::push('success', $publishAt !== '' ? 'This draft will be published on ' . $publishAt . '.' : 'Removed the publishing schedule.');
            redirect($appUrl . '/index.php?action=metadata&path=' . rawurlencode($path));
        }
        $hashtags = trim((string) ($_POST['hashtags'] ?? ''));
        $date = trim((string) ($_POST['date'] ?? ''));

        if (($existingMeta['generated'] ?? '') === 'tag-index') {
            $kind = 'blog';
            $hashtags = '';
        } else {
            // The type only changes when the user picks a different one; hashtags
            // no longer imply "post", and a homepage is never silently demoted.
            $allowed = $path === 'index.html' ? ['home', 'page', 'blog-post'] : ['page', 'blog-post'];
            $requested = (string) ($_POST['kind'] ?? $page['kind']);
            $kind = in_array($requested, $allowed, true) ? $requested : $page['kind'];
            if ($kind === 'blog-post') {
                $hashtags = $hashtags !== '' ? $hashtags : '#blog';
            } else {
                $hashtags = '';
            }
        }

        // Validate before writing anything, so a tag/page collision can't leave the
        // page half-updated.
        $generator->assertTagsWritable($hashtags);

        $image = trim((string) ($_POST['image'] ?? ''));
        if ($image !== '' && preg_match('#^(https?://[^\s"<>]+|/?assets/[A-Za-z0-9._/%-]+)$#i', $image) !== 1) {
            throw new RuntimeException('The featured image must be an assets/… path or an http(s) URL.');
        }
        $author = trim(preg_replace('/\s+/', ' ', (string) ($_POST['author'] ?? '')) ?? '');

        $repository->updateMetadata(
            $path,
            [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
                'date' => $kind === 'blog-post'
                    ? ($date !== '' ? $date : (string) ($existingMeta['date'] ?? gmdate('Y-m-d')))
                    : '',
                'hashtags' => $hashtags,
                'kind' => $kind,
                'tag' => (string) ($existingMeta['tag'] ?? ''),
                'generated' => (string) ($existingMeta['generated'] ?? ''),
                'exclude_template' => !empty($_POST['exclude_template']) ? '1' : '',
                'author' => mb_substr_safe($author, 120),
                'image' => preg_match('#^https?://#i', $image) === 1 ? $image : ltrim($image, '/'),
            ]
        );

        if ($kind !== $page['kind']) {
            $generator->rebuildPage($path);
        }

        $generator->rebuildTagPages();
        Flash::push('success', 'Updated the page details' . ($kind !== $page['kind'] ? ' and changed its type to ' . kind_label($kind) : '') . '.');
        redirect($appUrl . '/index.php?action=preview&path=' . rawurlencode($path));
    }

    if ($action === 'revision-view') {
        $path = $repository->sitePath()->normalize((string) ($_GET['path'] ?? ''));
        $html = $revisions->read($path, (string) ($_GET['id'] ?? ''));
        // Show the old version as a static page: the preview CSP blocks every
        // script (there is no nonce here), so nothing in it runs in this origin.
        \WYSiteIWYG\send_csp('preview');
        $html = preg_replace('#\s*<base\b[^>]*>#i', '', $html) ?? $html;
        $baseTag = '<base href="' . h($siteBaseUrl) . '">';
        echo preg_match('#<head\b[^>]*>#i', $html) === 1
            ? (preg_replace('#(<head\b[^>]*>)#i', '$1' . $baseTag, $html, 1) ?? $html)
            : $baseTag . $html;
        return;
    }

    if ($action === 'restore-revision') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The restore request was rejected.');
        }

        $path = $repository->sitePath()->normalize((string) ($_POST['path'] ?? ''));
        $revisions->restore($path, (string) ($_POST['id'] ?? ''));
        $generator->rebuildTagPages();
        Flash::push('success', 'Restored an earlier version of ' . $path . '. The version it replaced is kept in the history.');
        redirect($appUrl . '/index.php?action=metadata&path=' . rawurlencode($path));
    }

    if ($action === 'backup-download') {
        require_admin($user);
        $file = $backups->archivePath((string) ($_GET['name'] ?? ''));
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="wysite-' . basename($file) . '"');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        return;
    }

    if ($action === 'restore-backup') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The restore request was rejected.');
        }

        $backups->create('before-restore');
        $count = $backups->restore((string) ($_POST['name'] ?? ''));
        Flash::push('success', 'Restored ' . $count . ' file(s) from the backup. A backup of the state just before the restore was saved too.');
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'save-settings') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The settings request was rejected.');
        }

        $input = [
            'site_name' => (string) ($_POST['site_name'] ?? ''),
            'tagline' => (string) ($_POST['tagline'] ?? ''),
            'language' => (string) ($_POST['language'] ?? 'en'),
            'canonical_base_url' => (string) ($_POST['canonical_base_url'] ?? ''),
            'footer_html' => (string) ($_POST['footer_html'] ?? ''),
            'live_banner' => !empty($_POST['live_banner']),
            'url_style' => (string) ($_POST['url_style'] ?? 'folder'),
            'post_permalink' => (string) ($_POST['post_permalink'] ?? '{slug}'),
            'posts_per_page' => (int) ($_POST['posts_per_page'] ?? 10),
            'exclude_paths' => (string) ($_POST['exclude_paths'] ?? ''),
            'embed_hosts' => (string) ($_POST['embed_hosts'] ?? ''),
            'import_byte_budget_mb' => (int) ($_POST['import_byte_budget_mb'] ?? 2048),
            'keep_imported_scripts' => !empty($_POST['keep_imported_scripts']),
            'search_url' => (string) ($_POST['search_url'] ?? ''),
            'form_endpoint' => (string) ($_POST['form_endpoint'] ?? ''),
        ];

        foreach (['logo' => false, 'favicon' => true] as $field => $allowIcon) {
            $upload = $_FILES[$field . '_file'] ?? null;
            if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $input[$field] = store_uploaded_image($upload, $rootPath, 'site', $allowIcon);
            } elseif (!empty($_POST['remove_' . $field])) {
                $input[$field] = '';
            }
        }

        $settings->update($input);
        $rebuilt = $generator->rebuildAllPages();
        Flash::push('success', 'Saved site settings and rebuilt ' . $rebuilt . ' page(s).');
        redirect_to_view($appUrl, 'settings');
    }

    if ($action === 'save-theme-variables') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The theme customization request was rejected.');
        }

        $themeId = $themes->currentThemeId();
        $values = [];
        foreach ($themes->variables($themeId) as $variable) {
            $raw = (string) ($_POST['var'][$variable['name']] ?? '');
            if (!empty($_POST['reset'])) {
                continue;
            }
            $clean = \WYSiteIWYG\ThemeManager::cleanVariableValue($variable['type'], $raw);
            if ($raw !== '' && $clean === '') {
                throw new RuntimeException('"' . $raw . '" is not a valid value for ' . $variable['label'] . '.');
            }
            $values[$variable['name']] = $clean;
        }

        $all = (array) $settings->get('theme_variables');
        $all[$themeId] = $values;
        $settings->update(['theme_variables' => $all]);
        $generator->publishStylesheet($themeId);
        Flash::push('success', !empty($_POST['reset']) ? 'Restored the theme\'s default look.' : 'Saved your theme customizations.');
        redirect_to_view($appUrl, 'themes');
    }

    if ($action === 'apply-theme') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The theme change request was rejected.');
        }

        $themeId = trim((string) ($_POST['theme'] ?? ''));
        $backups->create('apply-theme');
        $generator->applyTheme($themeId);
        Flash::push('success', 'Applied the ' . $themes->getTheme($themeId)['name'] . ' theme across the site. A backup of the previous look is in Manager → Backups.');
        redirect_to_view($appUrl, 'themes');
    }

    if ($action === 'delete-theme') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The delete-theme request was rejected.');
        }

        $themeId = trim((string) ($_POST['theme'] ?? ''));
        $themeName = $themes->getTheme($themeId)['name'] ?? $themeId;
        $themes->deleteTheme($themeId);
        Flash::push('success', 'Deleted the "' . $themeName . '" theme.');
        redirect_to_view($appUrl, 'themes');
    }

    if ($action === 'set-dashboard-theme') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The dashboard appearance request was rejected.');
        }

        $themeId = trim((string) ($_POST['dashboard_theme'] ?? ''));
        $themes->setDashboardTheme($themeId);
        Flash::push(
            'success',
            $themeId === '' || $themeId === 'default'
                ? 'Dashboard appearance reset to the built-in default.'
                : 'Dashboard now uses the ' . $themes->getTheme($themeId)['name'] . ' appearance.'
        );
        redirect_to_view($appUrl, 'themes');
    }

    if ($action === 'save-ai-settings') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The AI settings request was rejected.');
        }

        $ai->save([
            'provider' => (string) ($_POST['provider'] ?? ''),
            'model' => (string) ($_POST['model'] ?? ''),
            'task_models' => is_array($_POST['task_models'] ?? null) ? $_POST['task_models'] : [],
            'base_url' => (string) ($_POST['base_url'] ?? ''),
            'api_key' => (string) ($_POST['api_key'] ?? ''),
            'clear_key' => !empty($_POST['clear_key']),
            'enabled' => !empty($_POST['enabled']),
        ]);
        Flash::push('success', 'Saved AI assistant settings.');
        redirect_to_view($appUrl, 'ai');
    }

    if ($action === 'ai-kinds') {
        require_admin($user);
        $aiCandidates = array_map(
            static fn(array $c): array => [
                'path' => $c['path'],
                'title' => $c['title'],
                'outline' => $c['excerpt'],
                'mtime' => (int) (@filemtime($rootPath . '/' . $c['path']) ?: 0),
            ],
            $repository->listImportCandidates()
        );
        json_response(['ok' => true, 'kinds' => $ai->cachedKinds($aiCandidates, ['home', 'page', 'blog', 'blog-post'])]);
    }

    if ($action === 'ai-list-models') {
        require_admin($user);
        $data = request_data();
        if (!Csrf::validate($data['csrfToken'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        try {
            $models = $ai->listModels([
                'provider' => (string) ($data['provider'] ?? ''),
                'base_url' => (string) ($data['base_url'] ?? ''),
                'api_key' => (string) ($data['api_key'] ?? ''),
            ]);
            json_response(['ok' => true, 'models' => $models]);
        } catch (Throwable $error) {
            json_response(['ok' => false, 'message' => $error->getMessage()], 400);
        }
    }

    if ($action === 'ai-test') {
        require_admin($user);
        $data = request_data();
        if (!Csrf::validate($data['csrfToken'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        try {
            $count = $ai->testConnection([
                'provider' => (string) ($data['provider'] ?? ''),
                'base_url' => (string) ($data['base_url'] ?? ''),
                'api_key' => (string) ($data['api_key'] ?? ''),
            ]);
            json_response(['ok' => true, 'message' => 'Connection OK — ' . $count . ' model(s) available.']);
        } catch (Throwable $error) {
            json_response(['ok' => false, 'message' => $error->getMessage()], 400);
        }
    }

    if ($action === 'create-theme') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The create-theme request was rejected.');
        }

        $newThemeId = $themes->createTheme((string) ($_POST['name'] ?? ''));
        $themes->setBuilderTheme($newThemeId);
        Flash::push('success', 'Created theme "' . $themes->getTheme($newThemeId)['name'] . '" and selected it as the build target. Add templates by choosing "Use as template" on imported pages.');
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'ai-generate-theme') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The theme design request was rejected.');
        }
        if (!$ai->isConfigured('theme')) {
            Flash::push('error', 'Set up AI assistance (an API key and a theme design model) on the AI page first.');
            redirect_to_view($appUrl, 'manager');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $themes->assertNewThemeName($name);
        $brief = trim((string) ($_POST['brief'] ?? ''));
        $sampleUrls = array_slice(array_values(array_filter(array_map('trim', preg_split('/\s+/', (string) ($_POST['sample_urls'] ?? '')) ?: []))), 0, 4);
        $images = ai_theme_screenshots($_FILES['screenshots'] ?? null);
        if ($brief === '' && $sampleUrls === [] && $images === []) {
            throw new RuntimeException('Describe the look you want, or give a sample site or screenshot to follow.');
        }

        // Designing a theme with a capable model can take a few minutes.
        @set_time_limit(900);
        @ignore_user_abort(true);

        $samples = [];
        $skippedSamples = [];
        foreach ($sampleUrls as $sampleUrl) {
            try {
                $samples[] = $externalImporter->fetchDesignSample($sampleUrl);
            } catch (Throwable $error) {
                $skippedSamples[] = $sampleUrl . ' (' . $error->getMessage() . ')';
            }
        }
        if ($sampleUrls !== [] && $samples === [] && $brief === '' && $images === []) {
            throw new RuntimeException('None of the sample sites could be fetched: ' . implode('; ', $skippedSamples));
        }

        $design = $ai->generateTheme(
            [
                'name' => $name,
                'brief' => $brief,
                'with_home' => !empty($_POST['with_home']),
                'site_name' => (string) $settings->get('site_name'),
                'site_tagline' => (string) $settings->get('tagline'),
            ],
            $samples,
            $images,
            static fn(string $kind, string $html): array => $themes->templateErrors($kind, \WYSiteIWYG\ThemeManager::sanitizeGeneratedTemplate($html))
        );
        $newThemeId = $themes->createGeneratedTheme($name, $design['meta'], $design['css'], $design['templates']);
        $themes->setBuilderTheme($newThemeId);

        Flash::push('success', 'Designed the theme "' . $themes->getTheme($newThemeId)['name'] . '". Preview it on the Theme page, and apply it when you are happy with it. It is also the build target, so you can replace its templates with ones from your own pages.');
        if ($skippedSamples !== []) {
            Flash::push('error', 'Some sample sites could not be fetched and were not used: ' . implode('; ', $skippedSamples));
        }
        if (str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')) {
            json_response(['ok' => true, 'redirect' => $appUrl . '/index.php?action=themes']);
        }
        redirect_to_view($appUrl, 'themes');
    }

    if ($action === 'set-builder-theme') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The build-target request was rejected.');
        }

        $themeId = trim((string) ($_POST['theme'] ?? ''));
        $themes->setBuilderTheme($themeId);
        Flash::push(
            'success',
            $themeId === ''
                ? 'Cleared the template build target.'
                : 'Now building templates into the "' . $themes->getTheme($themeId)['name'] . '" theme.'
        );
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'template-from-page') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The template request was rejected.');
        }

        if ($themes->builderThemeId() === '') {
            Flash::push('error', 'Create or select a build-target theme in the Template Manager before adding templates.');
            redirect_to_view($appUrl, 'manager');
        }

        $source = $externalImporter->cacheLocalTemplateSource(
            (string) ($_POST['path'] ?? ''),
            (string) ($_POST['kind'] ?? 'page'),
            $siteBaseUrl
        );

        maybe_attach_ai_suggestions($ai, $externalImporter, (string) $source['id'], (string) $source['kind']);

        redirect($appUrl . '/index.php?action=external-template-preview&id=' . rawurlencode((string) $source['id']));
    }

    if ($action === 'clear-template') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The clear-template request was rejected.');
        }

        $builderTheme = $themes->builderThemeId();
        if ($builderTheme === '') {
            Flash::push('error', 'No build-target theme selected.');
            redirect_to_view($appUrl, 'manager');
        }

        $kind = (string) ($_POST['kind'] ?? '');
        $filename = basename($repository->activeTemplateRelativePath($kind)); // validates kind
        $relative = 'edit/themes/' . $builderTheme . '/' . $filename;
        $full = $rootPath . '/' . $relative;
        if (is_file($full) && @unlink($full)) {
            Flash::push('success', 'Cleared the ' . $kind . ' template from ' . $relative . '.');
        } else {
            Flash::push('error', 'No ' . $kind . ' template to clear in the selected theme.');
        }
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'external-template-fetch') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The external template import request was rejected.');
        }

        if ($themes->builderThemeId() === '') {
            Flash::push('error', 'Create or select a build-target theme in the Template manager before fetching a template source.');
            redirect_to_view($appUrl, 'manager');
        }

        $source = $externalImporter->cacheTemplateSource(
            (string) ($_POST['url'] ?? ''),
            (string) ($_POST['kind'] ?? 'page')
        );

        maybe_attach_ai_suggestions($ai, $externalImporter, (string) $source['id'], (string) $source['kind']);

        redirect($appUrl . '/index.php?action=external-template-preview&id=' . rawurlencode((string) $source['id']));
    }

    if ($action === 'external-template-preview') {
        require_admin($user);
        \WYSiteIWYG\send_csp('preview');
        $source = $externalImporter->getCachedTemplateSource((string) ($_GET['id'] ?? ''));
        echo $externalImporter->renderTemplateDesignerHtml($source, $appUrl, Csrf::token(), $user['username'], \WYSiteIWYG\csp_nonce());
        return;
    }

    if ($action === 'external-template-save') {
        require_admin($user);
        $data = request_data();
        if (!Csrf::validate($data['csrfToken'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        $builderTheme = $themes->builderThemeId();
        if ($builderTheme === '') {
            json_response(['ok' => false, 'message' => 'Select or create a build-target theme before saving templates.'], 400);
        }

        $kind = (string) ($data['kind'] ?? 'page');
        $templatePath = $externalImporter->promoteCachedTemplate(
            (string) ($data['importId'] ?? ''),
            $kind,
            is_array($data['selections'] ?? null) ? $data['selections'] : [],
            $builderTheme
        );

        json_response([
            'ok' => true,
            'message' => 'Added the ' . $kind . ' template to the "' . $themes->getTheme($builderTheme)['name'] . '" theme (' . $templatePath . '). Apply the theme when it is complete to make it live.',
            'templatePath' => $templatePath,
        ]);
    }

    if ($action === 'external-site-import') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The external site import request was rejected.');
        }

        if (!empty($_POST['progress_stream'])) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            while (ob_get_level() > 0) {
                @ob_end_flush();
            }

            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
            header('X-Accel-Buffering: no');

            $sendProgress = static function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
                @ob_flush();
                flush();
            };

            try {
                // One short batch per request; the browser posts the job id back
                // until the crawl is complete (WP-7).
                $jobId = trim((string) ($_POST['job_id'] ?? ''));
                if ($jobId === '') {
                    $jobId = $externalImporter->startImportJob(
                        (string) ($_POST['url'] ?? ''),
                        (int) ($_POST['max_pages'] ?? 50),
                        !empty($_POST['overwrite']),
                        !empty($_POST['keep_scripts'])
                    );
                }
                $step = $externalImporter->runImportJob($jobId, 25, $sendProgress);
                if ($step['done']) {
                    $generator->writeMigrationRedirects($externalImporter->queryRedirects());
                    $sendProgress(['type' => 'done', 'result' => $step['result']]);
                }
            } catch (Throwable $error) {
                $sendProgress(['type' => 'fatal', 'message' => $error->getMessage()]);
            }
            return;
        }

        $results = $externalImporter->importSite(
            (string) ($_POST['url'] ?? ''),
            (int) ($_POST['max_pages'] ?? 50),
            !empty($_POST['overwrite']),
            null,
            !empty($_POST['keep_scripts'])
        );
        $generator->writeMigrationRedirects($externalImporter->queryRedirects());

        $message = 'External site import finished.' .
            "\n- Saved HTML pages: " . count($results['saved']) .
            "\n- Non-HTML resources saved: " . count($results['resources_saved'] ?? []) .
            "\n- Skipped existing: " . count($results['skipped']) .
            "\n- Skipped duplicate URLs: " . count($results['duplicates'] ?? []) .
            "\n- Skipped after page limit: " . count($results['limit_skipped'] ?? []) .
            "\n- Non-essential WordPress endpoints skipped: " . count($results['nonessential_skipped'] ?? []) .
            "\n- Assets mirrored: " . count($results['assets_saved'] ?? []) .
            "\n- /wp-content assets mirrored: " . (int) ($results['wp_content_assets_saved'] ?? 0) .
            "\n- Imported asset permissions repaired: " . (int) (($results['asset_permissions_fixed']['files'] ?? 0) + ($results['asset_permissions_fixed']['directories'] ?? 0)) .
            "\n- Assets skipped or failed: " . count($results['assets_failed'] ?? []) .
            "\n- Failed: " . count($results['failed']);
        if (($results['assets_failed'] ?? []) !== []) {
            $message .= "\n- " . implode("\n- ", array_slice($results['assets_failed'], 0, 5));
        }
        if (($results['nonessential_skipped'] ?? []) !== []) {
            $message .= "\n- Non-essential: " . implode("\n- Non-essential: ", array_slice($results['nonessential_skipped'], 0, 5));
        }
        if ($results['failed'] !== []) {
            $message .= "\n- " . implode("\n- ", array_slice($results['failed'], 0, 5));
        }
        if ((int) ($results['scripts_removed'] ?? 0) > 0) {
            $message .= "\n" . scripts_removed_notice((int) $results['scripts_removed']);
        }

        Flash::push($results['failed'] === [] && ($results['assets_failed'] ?? []) === [] ? 'success' : 'error', $message);
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'discard-import-job') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The request was rejected.');
        }
        $externalImporter->discardImportJob((string) ($_POST['job'] ?? ''));
        Flash::push('success', 'Discarded the unfinished import. Files it already saved are kept.');
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'wxr-import') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The WordPress import request was rejected.');
        }

        $upload = $_FILES['wxr'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $upload['tmp_name'])) {
            throw new RuntimeException('Choose the .xml file from WordPress → Tools → Export.');
        }

        $backups->create('wxr-import');
        $wxr = new \WYSiteIWYG\WxrImporter($generator, $repository);
        $imported = $wxr->import((string) $upload['tmp_name'], !empty($_POST['overwrite']));
        $externalImporter->recordImportSource($imported['source_hosts']);
        $externalImporter->recordQueryRedirects($imported['query_redirects'] ?? []);
        $generator->writeMigrationRedirects($externalImporter->queryRedirects());

        $message = 'WordPress import finished.'
            . "\n- Published pages and posts: " . count($imported['created'])
            . "\n- Drafts (private until published): " . count($imported['drafts'])
            . "\n- Skipped (already exist): " . count($imported['skipped']);
        if (!empty($_POST['mirror_media']) && $imported['attachments'] !== []) {
            $assets = $externalImporter->importAssetUrls($imported['attachments']);
            $message .= "\n- Media mirrored: " . count($assets['assets_saved']) . ' (failed: ' . count($assets['assets_failed']) . ')';
        } elseif ($imported['attachments'] !== []) {
            $message .= "\n- Media not mirrored: " . count($imported['attachments']) . ' file(s) still load from the old site';
        }
        foreach (array_slice($imported['failed'], 0, 5) as $failure) {
            $message .= "\n- Failed: " . $failure;
        }

        Flash::push($imported['failed'] === [] ? 'success' : 'error', $message);
        redirect($appUrl . '/index.php?action=report');
    }

    if ($action === 'external-assets-import') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The external asset import request was rejected.');
        }

        $assetUrls = preg_split('/\r\n|\r|\n/', (string) ($_POST['asset_urls'] ?? '')) ?: [];
        $results = $externalImporter->importAssetUrls($assetUrls);

        $message = 'External asset import finished.' .
            "\n- Assets mirrored: " . count($results['assets_saved']) .
            "\n- Assets skipped or failed: " . count($results['assets_failed']) .
            "\n- Imported asset permissions repaired: " . (int) (($results['asset_permissions_fixed']['files'] ?? 0) + ($results['asset_permissions_fixed']['directories'] ?? 0)) .
            "\n- Files updated: " . (int) $results['updated_files'];
        if ($results['assets_failed'] !== []) {
            $message .= "\n- " . implode("\n- ", array_slice($results['assets_failed'], 0, 5));
        }

        Flash::push($results['assets_failed'] === [] ? 'success' : 'error', $message);
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'import') {
        require_admin($user);
        $path = (string) ($_GET['path'] ?? '');
        $candidate = $repository->getImportCandidate($path);

        ob_start();
        ?>
        <main class="wysite-dashboard">
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">Import Existing HTML</p>
                <h2><?= h($candidate['title']) ?></h2>
              </div>
              <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php">Back to dashboard</a>
            </div>
            <p class="wysite-muted">WYSiteIWYG will rewrite <code><?= h($candidate['path']) ?></code> with the currently selected theme and place the imported content into editable Page Content blocks.</p>

            <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-page" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <input type="hidden" name="path" value="<?= h($candidate['path']) ?>">
              <label>
                <span>Title</span>
                <input type="text" name="title" value="<?= h((string) ($candidate['title'] ?? '')) ?>" required>
              </label>
              <label>
                <span>Excerpt</span>
                <textarea name="excerpt" rows="3"><?= h((string) ($candidate['excerpt'] ?? '')) ?></textarea>
              </label>
              <label>
                <span>Import as</span>
                <select name="kind" class="wysite-theme-select">
                  <option value="auto">Detect automatically (WordPress posts become blog posts)</option>
                  <option value="page">Page</option>
                  <option value="blog-post">Blog post</option>
                </select>
              </label>
              <label>
                <span>Import Container XPath</span>
                <input type="text" name="container_xpath" placeholder="Leave blank to auto-detect &lt;main&gt; or &lt;body&gt;">
              </label>
              <label>
                <span>Advanced Block Placement XPaths</span>
                <textarea name="block_xpaths" rows="7" class="wysite-code-field" placeholder="./section[1]&#10;./section[3]"></textarea>
              </label>
              <p class="wysite-muted">Leave the placement field blank to import the chosen container as one editable block. For advanced placement, enter one XPath per line. Each XPath must match a direct child of the selected container; matched elements become their own Page Content blocks, and content before, between, and after them is kept in order as surrounding blocks.</p>
              <div class="wysite-hero-actions">
                <button class="wysite-button" type="submit">Import Page</button>
                <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php">Cancel</a>
              </div>
            </form>
          </section>
        </main>
        <?php
        layout('Import HTML', (string) ob_get_clean(), $appUrl, $siteTitle, $user);
        return;
    }

    if ($action === 'import-page') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The import request was rejected.');
        }

        $path = trim((string) ($_POST['path'] ?? ''));
        $containerXPath = trim((string) ($_POST['container_xpath'] ?? ''));
        $blockXpaths = preg_split('/\r\n|\r|\n/', (string) ($_POST['block_xpaths'] ?? '')) ?: [];

        $generator->importExistingPage(
            $path,
            [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
                'container_xpath' => $containerXPath,
                'block_xpaths' => $blockXpaths,
                'kind' => (string) ($_POST['kind'] ?? 'auto'),
            ]
        );

        Flash::push('success', 'Imported ' . $path . ' into the current theme and added editable page-content blocks.');
        redirect($appUrl . '/index.php?action=preview&path=' . rawurlencode($path));
    }

    if ($action === 'import-all') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The bulk import request was rejected.');
        }

        $candidates = $repository->listImportCandidates();
        if ($candidates === []) {
            Flash::push('success', 'There were no unmanaged HTML files to import.');
            redirect_to_view($appUrl, 'manager');
        }

        $backups->create('import-all');
        // WP-1: when AI is configured, its classification breaks ties for pages the
        // post detector didn't recognise.
        $aiKinds = [];
        if ($ai->isConfigured('classify')) {
            try {
                $aiKinds = $ai->cachedKinds(array_map(static fn(array $c): array => [
                    'path' => $c['path'],
                    'title' => $c['title'],
                    'outline' => $c['excerpt'],
                    'mtime' => (int) (@filemtime($rootPath . '/' . $c['path']) ?: 0),
                ], $candidates), ['home', 'page', 'blog', 'blog-post']);
            } catch (Throwable) {
                $aiKinds = [];
            }
        }

        $postCount = 0;
        foreach ($candidates as $candidate) {
            $kind = 'auto';
            if (($aiKinds[$candidate['path']] ?? '') === 'blog-post' && $generator->detectPost((string) @file_get_contents($rootPath . '/' . $candidate['path'])) === null) {
                $kind = 'blog-post';
            }
            if ($generator->importExistingPage($candidate['path'], ['kind' => $kind], false) === 'blog-post') {
                $postCount++;
            }
        }
        $generator->rebuildTagPages();
        $redirects = $generator->writeMigrationRedirects($externalImporter->queryRedirects());

        Flash::push('success', ($redirects['archives'] + $redirects['queries'] > 0 ? 'Redirected ' . $redirects['archives'] . ' old category/tag archive(s) and ' . $redirects['queries'] . ' query-string URL(s) to their new pages. ' : '') . 'Imported ' . count($candidates) . ' existing HTML file(s) into the current theme (' . $postCount . ' detected as blog posts, with their dates and tags).');
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'create-user') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The user creation request was rejected.');
        }

        $makeAdmin = !empty($_POST['is_admin']);
        if ($makeAdmin) {
            require_reauth($auth, $user, $_POST);
        }

        $auth->createUser(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''), $makeAdmin);
        Flash::push('success', 'Created user ' . trim((string) ($_POST['username'] ?? '')) . '.');
        redirect_to_view($appUrl, 'users');
    }

    if ($action === 'update-password') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The password update request was rejected.');
        }

        require_reauth($auth, $user, $_POST);
        $auth->updatePassword(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''));
        Flash::push('success', 'Updated the password for ' . trim((string) ($_POST['username'] ?? '')) . '.');
        redirect_to_view($appUrl, 'users');
    }

    if ($action === 'set-role') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The role change request was rejected.');
        }

        require_reauth($auth, $user, $_POST);
        $target = trim((string) ($_POST['username'] ?? ''));
        $makeAdmin = ($_POST['role'] ?? '') === 'admin';
        $auth->setAdmin($target, $makeAdmin);
        Flash::push('success', $target . ' is now ' . ($makeAdmin ? 'an administrator.' : 'an editor.'));
        redirect_to_view($appUrl, $target === $user['username'] && !$makeAdmin ? 'dashboard' : 'users');
    }

    if ($action === 'delete-user') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The delete-user request was rejected.');
        }

        require_reauth($auth, $user, $_POST);
        $auth->deleteUser(trim((string) ($_POST['username'] ?? '')));
        Flash::push('success', 'The account was removed.');
        redirect_to_view($appUrl, 'users');
    }

    if ($action === 'change-own-password') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The password change request was rejected.');
        }

        $new = (string) ($_POST['password'] ?? '');
        if ($new !== (string) ($_POST['password_confirm'] ?? '')) {
            throw new RuntimeException('The new passwords did not match.');
        }

        $auth->changeOwnPassword($user['username'], (string) ($_POST['current_password'] ?? ''), $new);
        Flash::push('success', 'Your password was changed.');
        redirect_to_view($appUrl, 'account');
    }

    if ($action === 'docs') {
        $demoContent = require __DIR__ . '/docs/content.php';

        // Map a page/post slug to its on-page anchor id.
        $docAnchor = static fn(string $slug): string =>
            'doc-' . ($slug === '' || $slug === 'index' ? 'index' : trim(str_replace('/', '-', strtolower($slug)), '-'));

        // The documentation renders every page/post on one screen, so rewrite the
        // content's site links (e.g. /features/, /blog/) to in-page anchors. This
        // keeps the docs navigable whether or not the demo site is deployed.
        $docLinks = static function (string $html) use ($docAnchor): string {
            return preg_replace_callback(
                '#href="/([^"]*)"#i',
                static fn(array $m): string => 'href="#' . $docAnchor(trim($m[1], '/')) . '"',
                $html
            ) ?? $html;
        };

        ob_start();
        ?>
        <main class="wysite-dashboard wysite-docs">
          <section class="wysite-panel wysite-panel--hero">
            <div>
              <p class="wysite-kicker">Documentation</p>
              <h2>How WYSiteIWYG works</h2>
              <p>This is the built-in documentation. The same pages can be deployed to the site root as an editable demo site from the dashboard.</p>
            </div>
            <div class="wysite-hero-actions">
              <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php">Back to dashboard</a>
            </div>
          </section>
          <?php foreach ($demoContent['pages'] as $docPage): ?>
          <article class="wysite-panel" id="<?= h($docAnchor($docPage['slug'])) ?>">
            <h3><?= h($docPage['title']) ?></h3>
            <p class="wysite-muted"><?= h($docPage['excerpt']) ?></p>
            <?php foreach ($docPage['blocks'] as $docBlock): ?>
            <div class="wysite-doc-block"><?= $docLinks($docBlock) ?></div>
            <?php endforeach; ?>
          </article>
          <?php endforeach; ?>
          <article class="wysite-panel" id="doc-blog">
            <h3>Journal</h3>
            <p class="wysite-muted">Blog-style posts. When the demo site is deployed these become the journal at <code>/blog/</code>.</p>
          </article>
          <?php foreach ($demoContent['posts'] as $docPost): ?>
          <article class="wysite-panel" id="<?= h($docAnchor($docPost['slug'])) ?>">
            <h3><?= h($docPost['title']) ?> <span class="wysite-muted">· Journal</span></h3>
            <p class="wysite-muted"><?= h($docPost['excerpt']) ?></p>
            <div class="wysite-doc-block"><?= $docLinks($docPost['content']) ?></div>
          </article>
          <?php endforeach; ?>
        </main>
        <?php
        layout('Documentation', (string) ob_get_clean(), $appUrl, $siteTitle, $user, 'docs');
        return;
    }

    if ($action === 'deploy-demo') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The deploy-demo request was rejected.');
        }

        $deployed = $generator->deployDemoSite(require __DIR__ . '/docs/content.php');
        Flash::push($deployed['warnings'] === [] ? 'success' : 'error', demo_deploy_message($deployed));
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'convert-folder-urls') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The conversion request was rejected.');
        }

        $backups->create('folder-urls');
        $converted = $generator->convertToFolderUrls();
        $settings->update(['url_style' => 'folder']);
        $message = 'Converted ' . count($converted['moved']) . ' page(s) to folder URLs. Public addresses are unchanged; the old .html files now redirect.';
        if ($converted['skipped'] !== []) {
            $message .= "\n- Skipped (a folder page already exists): " . implode(', ', $converted['skipped']);
        }
        Flash::push('success', $message);
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'apply-report-fix') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The fix request was rejected.');
        }

        $fix = (string) ($_POST['fix'] ?? '');
        if (!in_array($fix, ['remove-comment-forms', 'replace-search-forms', 'retarget-forms'], true)) {
            throw new RuntimeException('Unknown fix.');
        }
        $siteHost = (string) parse_url((string) ($settings->get('canonical_base_url') ?: $app['siteOrigin']), PHP_URL_HOST);
        $changed = $siteReport->applyFix($fix, (string) $settings->get('search_url'), $siteHost, (string) $settings->get('form_endpoint'));
        Flash::push('success', 'Updated ' . $changed . ' page(s). Earlier versions are in each page\'s history.');
        redirect($appUrl . '/index.php?action=report');
    }

    if ($action === 'redirects-download') {
        require_admin($user);
        $file = __DIR__ . '/storage/redirects.txt';
        if (!is_file($file)) {
            throw new RuntimeException('No redirect map has been generated yet.');
        }
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="redirects.txt"');
        readfile($file);
        return;
    }

    if ($action === 'report') {
        require_admin($user);
        $knownEmbeds = $settings->embedHosts() ?: \WYSiteIWYG\Sanitizer::defaultEmbedHosts();
        $migration = $siteReport->migrationReport($externalImporter->importedSourceHosts(), $knownEmbeds);
        $fixCounts = [];
        foreach ($migration as $findings) {
            foreach ($findings as $finding) {
                if (!empty($finding['fix'])) {
                    $fixCounts[$finding['fix']] = ($fixCounts[$finding['fix']] ?? 0) + 1;
                }
            }
        }
        $fixLabels = [
            'remove-comment-forms' => ['Remove comment forms', 'Comment forms can\'t work on a static site.'],
            'replace-search-forms' => ['Replace search forms', 'Uses the external search URL from Site settings (' . ($settings->get('search_url') ?: 'not set') . ').'],
            'retarget-forms' => ['Point forms at the form endpoint', 'Uses the form endpoint from Site settings (' . ($settings->get('form_endpoint') ?: 'not set') . ').'],
        ];
        ob_start();
        ?>
        <main class="wysite-dashboard">
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">Site report</p>
                <h2><?= count($migration) ?> page(s) need a look</h2>
              </div>
              <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=manager">Back to Manager</a>
            </div>
            <p class="wysite-muted">Broken internal links and missing assets on every page, plus what a WordPress migration typically breaks (forms, search, comments, embeds, links and scripts still on the old site<?= $externalImporter->importedSourceHosts() !== [] ? ': ' . h(implode(', ', $externalImporter->importedSourceHosts())) : '' ?>).</p>
            <?php if (is_file(__DIR__ . '/storage/redirects.txt')): ?>
              <p class="wysite-muted">Old query-string URLs (<code>?p=123</code>) redirect to their new pages through a block in the root <code>.htaccess</code>. On nginx or other servers, <a href="<?= h($appUrl) ?>/index.php?action=redirects-download">download the redirect list</a> and add it to the server config.</p>
            <?php endif; ?>
            <?php if ($fixCounts !== []): ?>
              <div class="wysite-hero-actions">
                <?php foreach ($fixCounts as $fix => $count): ?>
                  <form method="post" action="<?= h($appUrl) ?>/index.php?action=apply-report-fix" data-wysite-confirm="<?= h($fixLabels[$fix][0]) ?> on every page (<?= (int) $count ?> found)? <?= h($fixLabels[$fix][1]) ?>">
                    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="fix" value="<?= h($fix) ?>">
                    <button class="wysite-button wysite-button--ghost" type="submit"><?= h($fixLabels[$fix][0]) ?> (<?= (int) $count ?>)</button>
                  </form>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ($migration === []): ?>
              <p>Nothing found — every internal link resolves and no migration issues were detected.</p>
            <?php else: ?>
              <div class="wysite-table-wrap">
                <table class="wysite-table">
                  <thead><tr><th>Page</th><th>Issue</th><th>Detail</th></tr></thead>
                  <tbody>
                    <?php foreach ($migration as $reportPath => $findings): ?>
                      <?php foreach ($findings as $index => $finding): ?>
                        <tr>
                          <td><?php if ($index === 0): ?><a href="<?= h($appUrl) ?>/index.php?action=preview&path=<?= rawurlencode($reportPath) ?>"><code><?= h($reportPath) ?></code></a><?php endif; ?></td>
                          <td><?= h($finding['type']) ?></td>
                          <td><?= h($finding['detail']) ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </main>
        <?php
        layout('Site report', (string) ob_get_clean(), $appUrl, $siteTitle, $user);
        return;
    }

    if ($action === 'purge-preview') {
        require_admin($user);
        $purgeFiles = $generator->purgeCandidates($externalImporter->importedFiles());
        ob_start();
        ?>
        <main class="wysite-dashboard">
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">Delete all pages</p>
                <h2><?= count($purgeFiles) ?> file(s) will be deleted</h2>
              </div>
              <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=manager">Cancel</a>
            </div>
            <p class="wysite-muted">Only pages managed by WYSiteIWYG, its redirect stubs, and HTML created by the site importer are removed. Other HTML under the site root, assets, and the editor are left alone. A backup is taken first (Manager → Backups).</p>
            <?php if ($purgeFiles === []): ?>
              <p>There are no pages to delete.</p>
            <?php else: ?>
              <ul class="wysite-file-list">
                <?php foreach ($purgeFiles as $purgeFile): ?><li><code><?= h($purgeFile) ?></code></li><?php endforeach; ?>
              </ul>
              <form method="post" action="<?= h($appUrl) ?>/index.php?action=purge-site" data-wysite-confirm="Delete these <?= count($purgeFiles) ?> file(s)? You can restore them from the backup taken just before.">
                <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                <button class="wysite-button" type="submit">Delete <?= count($purgeFiles) ?> file(s)</button>
              </form>
            <?php endif; ?>
          </section>
        </main>
        <?php
        layout('Delete all pages', (string) ob_get_clean(), $appUrl, $siteTitle, $user);
        return;
    }

    if ($action === 'purge-site') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The clear-site request was rejected.');
        }

        $backups->create('purge');
        $removed = $generator->purgeAllPages($externalImporter->importedFiles());
        Flash::push(
            'success',
            $removed > 0
                ? 'Removed ' . $removed . ' page(s). Assets were left in place; restore from Manager → Backups if needed.'
                : 'There were no pages to remove.'
        );
        redirect_to_view($appUrl, 'manager');
    }

    if ($action === 'delete-demo') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The delete-demo request was rejected.');
        }

        $backups->create('delete-demo');
        $removed = $generator->deleteDemoContent();
        Flash::push(
            'success',
            $removed > 0
                ? 'Removed ' . $removed . ' demo page(s).'
                : 'There was no demo content to remove.'
        );
        redirect_to_view($appUrl, 'manager');
    }

    // ---- Multi-page dashboard router ----
    $view = in_array($action, ['themes', 'manager', 'ai', 'users', 'account', 'settings'], true) ? $action : 'dashboard';
    if (in_array($view, ['manager', 'ai', 'users', 'settings'], true) && !$user['is_admin']) {
        redirect_to_view($appUrl, 'dashboard');
    }

    $availableThemes = $generator->availableThemes();
    $currentTheme = $generator->currentTheme();

    ob_start();
    include __DIR__ . '/views/' . $view . '.php';
    $viewTitles = ['dashboard' => 'Dashboard', 'themes' => 'Theme', 'manager' => 'Manager', 'ai' => 'AI', 'users' => 'Users', 'account' => 'Account', 'settings' => 'Settings'];
    layout($viewTitles[$view], (string) ob_get_clean(), $appUrl, $siteTitle, $user, $view);
} catch (Throwable $error) {
    $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
    if ($accept !== '' && str_contains($accept, 'application/x-ndjson')) {
        header('Content-Type: application/x-ndjson; charset=utf-8');
        echo json_encode(['type' => 'fatal', 'message' => $error->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        return;
    }

    if ($accept !== '' && str_contains($accept, 'application/json')) {
        json_response(['ok' => false, 'message' => $error->getMessage()], 400);
    }

    $currentUser = $auth->currentUser();
    if ($currentUser !== null && $action !== 'login') {
        ob_start();
        ?>
        <main class="wysite-dashboard">
          <section class="wysite-panel">
            <div class="wysite-panel__heading">
              <div>
                <p class="wysite-kicker">Editor Error</p>
                <h2>WYSiteIWYG hit a problem before it could finish loading.</h2>
              </div>
            </div>
            <p><?= h($error->getMessage()) ?></p>
            <div class="wysite-hero-actions">
              <a class="wysite-button" href="<?= h($appUrl) ?>/index.php">Return to dashboard</a>
              <form method="post" action="<?= h($appUrl) ?>/index.php?action=logout">
                <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                <button type="submit" class="wysite-button wysite-button--ghost">Log out</button>
              </form>
            </div>
          </section>
        </main>
        <?php
        layout('Error', (string) ob_get_clean(), $appUrl, $siteTitle, $currentUser);
        return;
    }

    // Signed-out errors (bad login, bad setup token) are shown after the redirect.
    Flash::push('error', $error->getMessage());
    redirect($appUrl . '/index.php?action=login');
}
