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
$appUrl = $app['appUrl'];
$rootPath = $app['rootPath'];
$siteBaseUrl = $app['siteBaseUrl'];
$siteTitle = $app['siteTitle'];

$action = $_GET['action'] ?? 'dashboard';

if (!$auth->isInstalled() && $action !== 'install') {
    redirect($appUrl . '/index.php?action=install');
}

function layout(string $title, string $body, string $appUrl, string $siteTitle, ?array $user = null): void
{
    $flashes = Flash::consume();
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> | <?= h($siteTitle) ?></title>
  <link rel="stylesheet" href="<?= h($appUrl) ?>/assets/editor.css">
</head>
<body class="wysite-app-shell">
  <div class="wysite-shell">
    <header class="wysite-shell__header">
      <div>
        <p class="wysite-kicker">Filesystem-first static site editing</p>
        <h1><?= h($siteTitle) ?></h1>
      </div>
      <?php if ($user): ?>
        <div class="wysite-user-chip">
          <span>Signed in as <?= h($user['username']) ?></span>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=logout">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <button type="submit" class="wysite-button wysite-button--ghost">Log out</button>
          </form>
        </div>
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

        if (is_post()) {
            $data = request_data();
            if (!Csrf::validate($data['csrf_token'] ?? null)) {
                throw new RuntimeException('Your session expired. Refresh and try again.');
            }

            $auth->bootstrapAdmin(trim((string) ($data['username'] ?? '')), (string) ($data['password'] ?? ''));
            $auth->attempt(trim((string) ($data['username'] ?? '')), (string) ($data['password'] ?? ''));
            Flash::push('success', 'WYSiteIWYG is ready. Your admin account has been created.');
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
          <form method="post" class="wysite-form">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <label>
              <span>Username</span>
              <input type="text" name="username" required minlength="3" maxlength="32">
            </label>
            <label>
              <span>Password</span>
              <input type="password" name="password" required minlength="10">
            </label>
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
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(['ok' => false, 'message' => 'No image was uploaded successfully.'], 400);
        }

        $tmpPath = (string) ($upload['tmp_name'] ?? '');
        if ($tmpPath === '' || !is_file($tmpPath)) {
            json_response(['ok' => false, 'message' => 'The uploaded image could not be processed.'], 400);
        }

        $size = (int) ($upload['size'] ?? 0);
        if ($size <= 0 || $size > 10 * 1024 * 1024) {
            json_response(['ok' => false, 'message' => 'Images must be smaller than 10 MB.'], 400);
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string) $finfo->file($tmpPath);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif',
        ];
        $extension = $extensions[$mime] ?? null;
        if ($extension === null) {
            json_response(['ok' => false, 'message' => 'Only JPG, PNG, GIF, WebP, and AVIF images are supported.'], 400);
        }

        $relativeDir = 'assets/uploads/' . gmdate('Y/m');
        $relativePath = $relativeDir . '/' . bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $rootPath . '/' . $relativePath;

        Filesystem::ensureDirectory(dirname($targetPath));
        $contents = file_get_contents($tmpPath);
        if ($contents === false) {
            json_response(['ok' => false, 'message' => 'The uploaded image could not be read.'], 400);
        }

        Filesystem::atomicWrite($targetPath, $contents);

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
                ]
            );
        } else {
            echo $repository->renderPreviewHtml($path, $appUrl, $siteBaseUrl, Csrf::token(), $user['username']);
        }
        return;
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
        $html = (string) ($data['html'] ?? '');
        $block = $repository->getBlock($path, $name);

        if (($block['type'] ?? '') === 'menu' || $name === 'main-menu') {
            $generator->syncMenu($html);
            json_response(['ok' => true, 'message' => 'The shared menu was updated across pages and templates.']);
        }

        $html = $generator->normalizeEditableBlockHtml((string) ($block['type'] ?? ''), $html);
        $repository->updateBlock($path, $name, $html);
        json_response(['ok' => true, 'message' => 'The page was saved.']);
    }

    if ($action === 'save-selection') {
        $data = request_data();
        if (!Csrf::validate($data['csrfToken'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        $path = (string) ($data['path'] ?? '');
        $html = (string) ($data['html'] ?? '');
        $domPath = $data['domPath'] ?? null;
        $scope = (string) ($data['scope'] ?? 'page');
        $page = $repository->getPage($path);
        $kind = (string) ($page['kind'] ?? 'page');

        if (!is_array($domPath)) {
            json_response(['ok' => false, 'message' => 'The selected section path was invalid.'], 400);
        }

        if ($scope === 'template') {
            $templatePath = $repository->updateSelectedElementInActiveTemplate($kind, $domPath, $html);
            $updatedPages = $generator->rebuildPagesUsingTemplate($kind);
            json_response([
                'ok' => true,
                'message' => 'The active template was saved and ' . $updatedPages . ' page(s) using it were rebuilt.',
                'templatePath' => $templatePath,
                'updatedPages' => $updatedPages,
            ]);
        }

        $repository->updateSelectedElement($path, $domPath, $html);
        json_response(['ok' => true, 'message' => 'The selected section was saved.']);
    }

    if ($action === 'create-page') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The page creation request was rejected.');
        }

        $path = $generator->createPage((string) ($_POST['title'] ?? ''), (string) ($_POST['slug'] ?? ''));
        Flash::push('success', 'Created ' . $path . '.');
        redirect($appUrl . '/index.php?action=preview&path=' . rawurlencode($path));
    }

    if ($action === 'create-post') {
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The blog creation request was rejected.');
        }

        $path = $generator->createBlogPost(
            (string) ($_POST['title'] ?? ''),
            (string) ($_POST['slug'] ?? ''),
            (string) ($_POST['excerpt'] ?? ''),
            (string) ($_POST['hashtags'] ?? '#blog')
        );
        Flash::push('success', 'Created ' . $path . ' and refreshed the hashtag landing pages.');
        redirect($appUrl . '/index.php?action=preview&path=' . rawurlencode($path));
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
            <p class="wysite-muted">Add hashtags here to have a page appear automatically on matching landing pages like <code>/blog/</code>, <code>/launch/</code>, or <code>/workflow/</code>.</p>

            <form method="post" action="<?= h($appUrl) ?>/index.php?action=save-metadata" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <input type="hidden" name="path" value="<?= h($page['path']) ?>">
              <label>
                <span>Title</span>
                <input type="text" name="title" required value="<?= h((string) ($meta['title'] ?? '')) ?>">
              </label>
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
        $existingMeta = $page['meta'];
        $hashtags = trim((string) ($_POST['hashtags'] ?? ''));
        $date = trim((string) ($_POST['date'] ?? ''));

        if (($existingMeta['generated'] ?? '') === 'tag-index') {
            $kind = 'blog';
            $hashtags = '';
        } else {
            $kind = $hashtags !== '' ? 'blog-post' : 'page';
        }

        $repository->updateMetadata(
            $path,
            [
                'title' => trim((string) ($_POST['title'] ?? '')),
                'excerpt' => trim((string) ($_POST['excerpt'] ?? '')),
                'date' => $kind === 'blog-post'
                    ? ($date !== '' ? $date : (string) ($existingMeta['date'] ?? gmdate('Y-m-d')))
                    : '',
                'hashtags' => $kind === 'blog-post' ? $hashtags : '',
                'kind' => $kind,
                'tag' => (string) ($existingMeta['tag'] ?? ''),
                'generated' => (string) ($existingMeta['generated'] ?? ''),
                'exclude_template' => !empty($_POST['exclude_template']) ? '1' : '',
            ]
        );

        $generator->rebuildTagPages();
        Flash::push('success', 'Updated the page details and refreshed the hashtag landing pages.');
        redirect($appUrl . '/index.php?action=preview&path=' . rawurlencode($path));
    }

    if ($action === 'apply-theme') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The theme change request was rejected.');
        }

        $themeId = trim((string) ($_POST['theme'] ?? ''));
        $generator->applyTheme($themeId);
        Flash::push('success', 'Applied the ' . $themes->getTheme($themeId)['name'] . ' theme across the site.');
        redirect($appUrl . '/index.php');
    }

    if ($action === 'external-template-fetch') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The external template import request was rejected.');
        }

        $source = $externalImporter->cacheTemplateSource(
            (string) ($_POST['url'] ?? ''),
            (string) ($_POST['kind'] ?? 'page')
        );

        redirect($appUrl . '/index.php?action=external-template-preview&id=' . rawurlencode((string) $source['id']));
    }

    if ($action === 'external-template-preview') {
        require_admin($user);
        $source = $externalImporter->getCachedTemplateSource((string) ($_GET['id'] ?? ''));
        echo $externalImporter->renderTemplateDesignerHtml($source, $appUrl, Csrf::token(), $user['username']);
        return;
    }

    if ($action === 'external-template-save') {
        require_admin($user);
        $data = request_data();
        if (!Csrf::validate($data['csrfToken'] ?? null)) {
            json_response(['ok' => false, 'message' => 'Invalid CSRF token.'], 419);
        }

        $kind = (string) ($data['kind'] ?? 'page');
        $templatePath = $externalImporter->promoteCachedTemplate(
            (string) ($data['importId'] ?? ''),
            $kind,
            is_array($data['selections'] ?? null) ? $data['selections'] : []
        );
        $updatedPages = $generator->rebuildPagesUsingTemplate($kind);

        json_response([
            'ok' => true,
            'message' => 'Created ' . $templatePath . ' and rebuilt ' . $updatedPages . ' page(s) that use it.',
            'templatePath' => $templatePath,
            'updatedPages' => $updatedPages,
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
                $results = $externalImporter->importSite(
                    (string) ($_POST['url'] ?? ''),
                    (int) ($_POST['max_pages'] ?? 50),
                    !empty($_POST['overwrite']),
                    $sendProgress
                );
                $sendProgress(['type' => 'done', 'result' => $results]);
            } catch (Throwable $error) {
                $sendProgress(['type' => 'fatal', 'message' => $error->getMessage()]);
            }
            return;
        }

        $results = $externalImporter->importSite(
            (string) ($_POST['url'] ?? ''),
            (int) ($_POST['max_pages'] ?? 50),
            !empty($_POST['overwrite'])
        );

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

        Flash::push($results['failed'] === [] && ($results['assets_failed'] ?? []) === [] ? 'success' : 'error', $message);
        redirect($appUrl . '/index.php');
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
        redirect($appUrl . '/index.php');
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
            redirect($appUrl . '/index.php');
        }

        foreach ($candidates as $candidate) {
            $generator->importExistingPage($candidate['path']);
        }

        Flash::push('success', 'Imported ' . count($candidates) . ' existing HTML file(s) into the current theme.');
        redirect($appUrl . '/index.php');
    }

    if ($action === 'create-user') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The user creation request was rejected.');
        }

        $auth->createUser(
            trim((string) ($_POST['username'] ?? '')),
            (string) ($_POST['password'] ?? ''),
            !empty($_POST['is_admin'])
        );

        Flash::push('success', 'Created user ' . trim((string) ($_POST['username'] ?? '')) . '.');
        redirect($appUrl . '/index.php');
    }

    if ($action === 'update-password') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The password update request was rejected.');
        }

        $auth->updatePassword(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''));
        Flash::push('success', 'Updated the password for ' . trim((string) ($_POST['username'] ?? '')) . '.');
        redirect($appUrl . '/index.php');
    }

    if ($action === 'delete-user') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The delete-user request was rejected.');
        }

        $auth->deleteUser(trim((string) ($_POST['username'] ?? '')));
        Flash::push('success', 'The account was removed.');
        redirect($appUrl . '/index.php');
    }

    if ($action === 'delete-demo') {
        require_admin($user);
        if (!is_post() || !Csrf::validate($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The delete-demo request was rejected.');
        }

        $removed = $generator->deleteDemoContent();
        Flash::push(
            'success',
            $removed > 0
                ? 'Removed ' . $removed . ' demo page(s).'
                : 'There was no demo content to remove.'
        );
        redirect($appUrl . '/index.php');
    }

    $pages = $repository->listPages();
    $hasDemoContent = $user['is_admin'] && $generator->hasDemoContent();
    $importCandidates = $user['is_admin'] ? $repository->listImportCandidates() : [];
    $users = $user['is_admin'] ? $auth->allUsers() : [];
    $availableThemes = $generator->availableThemes();
    $currentTheme = $generator->currentTheme();

    ob_start();
    ?>
    <main class="wysite-dashboard">
      <section class="wysite-panel wysite-panel--hero">
        <div>
          <p class="wysite-kicker">Transparent editing</p>
          <h2>Open any marked page, click the in-page badge, and edit the real HTML file.</h2>
          <p>Editable sections are delimited with HTML comments, so the output stays static and the editing system stays simple.</p>
        </div>
        <div class="wysite-hero-actions">
          <a class="wysite-button" href="<?= h($appUrl) ?>/index.php?action=preview&path=index.html">Open homepage preview</a>
          <a class="wysite-button wysite-button--ghost" href="<?= h($siteBaseUrl) ?>" target="_blank" rel="noreferrer">Open live site</a>
          <?php if ($hasDemoContent): ?>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=delete-demo" onsubmit="return window.confirm('Delete all demo pages and posts? This removes every page flagged as demo content and cannot be undone.');">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <button class="wysite-button wysite-button--ghost" type="submit">Delete demo content</button>
          </form>
          <?php endif; ?>
        </div>
      </section>

      <section class="wysite-grid">
        <article class="wysite-panel">
          <h3>Create a page</h3>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-page" class="wysite-form">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <label>
              <span>Page title</span>
              <input type="text" name="title" required>
            </label>
            <label>
              <span>Slug</span>
              <input type="text" name="slug" placeholder="about/team">
            </label>
            <button class="wysite-button" type="submit">Create page</button>
          </form>
        </article>

        <article class="wysite-panel">
          <h3>Create a blog post</h3>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-post" class="wysite-form">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <label>
              <span>Post title</span>
              <input type="text" name="title" required>
            </label>
            <label>
              <span>Slug</span>
              <input type="text" name="slug" placeholder="launch-notes">
            </label>
            <label>
              <span>Excerpt</span>
              <textarea name="excerpt" rows="3" required></textarea>
            </label>
            <label>
              <span>Hashtags</span>
              <input type="text" name="hashtags" value="#blog" placeholder="#blog #workflow">
            </label>
            <button class="wysite-button" type="submit">Create post</button>
          </form>
        </article>
      </section>

      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Theme switching</p>
            <h3>Preview a theme, then rewrite the site to match it</h3>
          </div>
          <p class="wysite-muted">Current theme: <?= h($currentTheme['name']) ?></p>
        </div>
        <div class="wysite-theme-grid">
          <?php foreach ($availableThemes as $theme): ?>
            <article class="wysite-theme-card <?= $theme['id'] === $currentTheme['id'] ? 'is-current' : '' ?>">
              <?php if ($theme['id'] === $currentTheme['id']): ?>
                <span class="wysite-theme-chip">Current</span>
              <?php endif; ?>
              <div>
                <h3><?= h($theme['name']) ?></h3>
                <p><?= h($theme['description']) ?></p>
                <?php if ($theme['inspiration'] !== ''): ?>
                  <p class="wysite-muted"><?= h($theme['inspiration']) ?></p>
                <?php endif; ?>
                <?php if ($theme['preview_blurb'] !== ''): ?>
                  <p class="wysite-muted"><?= h($theme['preview_blurb']) ?></p>
                <?php endif; ?>
              </div>
              <div class="wysite-theme-actions">
                <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=preview&path=index.html&theme=<?= rawurlencode($theme['id']) ?>">Preview Home</a>
                <?php if ($user['is_admin']): ?>
                  <form method="post" action="<?= h($appUrl) ?>/index.php?action=apply-theme">
                    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="theme" value="<?= h($theme['id']) ?>">
                    <button class="wysite-button" type="submit">Apply Theme</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Editable files</p>
            <h3>Pages discovered from the filesystem</h3>
          </div>
          <p class="wysite-muted">These are the static HTML files containing WYSiteIWYG marker comments.</p>
        </div>
        <div class="wysite-table-wrap">
          <table class="wysite-table">
            <thead>
              <tr>
                <th>Page</th>
                <th>Type</th>
                <th>Template</th>
                <th>Hashtags</th>
                <th>Blocks</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pages as $page): ?>
                <tr>
                  <td>
                    <strong><?= h($page['title']) ?></strong>
                    <div class="wysite-muted"><?= h($page['path']) ?></div>
                  </td>
                  <td><?= h($page['kind']) ?></td>
                  <td><?= !empty($page['exclude_template']) ? 'Excluded' : 'Included' ?></td>
                  <td><?= h($page['hashtags'] !== '' ? $page['hashtags'] : '—') ?></td>
                  <td><?= h(implode(', ', array_map(static fn(array $block): string => $block['label'], $page['blocks']))) ?></td>
                  <td class="wysite-table__actions">
                    <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=preview&path=<?= rawurlencode($page['path']) ?>">Edit</a>
                    <?php if (($page['generated'] ?? '') !== 'tag-index'): ?>
                      <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=metadata&path=<?= rawurlencode($page['path']) ?>">Details</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <?php if ($user['is_admin']): ?>
        <section class="wysite-panel">
          <div class="wysite-panel__heading">
            <div>
              <p class="wysite-kicker">External migration tools</p>
              <h3>Import templates or crawl a static copy of another site</h3>
            </div>
          </div>
          <div class="wysite-grid">
            <article>
              <h3>Build a template from a URL</h3>
              <p class="wysite-muted">Fetch one external page, select the menu and content regions in the browser, and promote those selections into an active template file in <code>/edit/templates/</code>.</p>
              <form method="post" action="<?= h($appUrl) ?>/index.php?action=external-template-fetch" class="wysite-form">
                <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                <label>
                  <span>Source URL</span>
                  <input type="url" name="url" required placeholder="https://example.com/about/">
                </label>
                <label>
                  <span>Template type</span>
                  <select name="kind" class="wysite-theme-select">
                    <option value="page">Page template</option>
                    <option value="blog-post">Blog post template</option>
                    <option value="blog">Blog / archive template</option>
                  </select>
                </label>
                <button class="wysite-button" type="submit">Fetch and Select Sections</button>
              </form>
            </article>

            <article>
              <h3>Import an external site</h3>
              <p class="wysite-muted">Crawl source-site HTML pages from a starting URL, save feeds and other static resources locally, rewrite source-domain page links, and mirror referenced assets and feed media into <code>/assets/imported/</code>. This is intended as a first migration pass for WordPress-style sites before importing pages into WYSite blocks.</p>
              <form method="post" action="<?= h($appUrl) ?>/index.php?action=external-site-import" class="wysite-form" data-wysite-external-import-form="1" onsubmit="return window.confirm('Import HTML pages, feeds, and referenced assets from this external site? Existing local files will only be replaced if overwrite is checked.');">
                <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                <label>
                  <span>Starting URL</span>
                  <input type="url" name="url" required placeholder="https://example.com/">
                </label>
                <label>
                  <span>Maximum pages</span>
                  <input type="number" name="max_pages" min="1" max="500" value="50">
                </label>
                <label class="wysite-checkbox">
                  <input type="checkbox" name="overwrite" value="1">
                  <span>Overwrite existing local HTML files</span>
                </label>
                <button class="wysite-button" type="submit">Import Site</button>
              </form>
              <div class="wysite-import-progress" data-wysite-external-import-progress hidden>
                <div class="wysite-import-progress__bar"><span data-wysite-import-progress-bar></span></div>
                <p class="wysite-muted" data-wysite-import-progress-status>Preparing import...</p>
                <pre class="wysite-import-progress__log" data-wysite-import-progress-log></pre>
              </div>
            </article>

            <article>
              <h3>Backfill specific assets</h3>
              <p class="wysite-muted">Use this as a repair pass for individual media URLs that were blocked, discovered later, or listed in an import report. Assets are streamed into <code>/assets/imported/</code>, then matching references in local HTML/CSS/JS files are rewritten.</p>
              <form method="post" action="<?= h($appUrl) ?>/index.php?action=external-assets-import" class="wysite-form">
                <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                <label>
                  <span>Asset URLs</span>
                  <textarea name="asset_urls" rows="7" placeholder="https://example.com/wp-content/uploads/audio.m4a"></textarea>
                </label>
                <button class="wysite-button" type="submit">Backfill Assets</button>
              </form>
            </article>
          </div>
        </section>
        <script>
        (() => {
          const form = document.querySelector('[data-wysite-external-import-form]');
          const panel = document.querySelector('[data-wysite-external-import-progress]');
          if (!form || !panel || !window.fetch || !window.TextDecoder) {
            return;
          }

          const status = panel.querySelector('[data-wysite-import-progress-status]');
          const log = panel.querySelector('[data-wysite-import-progress-log]');
          const bar = panel.querySelector('[data-wysite-import-progress-bar]');
          const button = form.querySelector('button[type="submit"]');
          const defaultButtonText = button ? button.textContent : '';
          const largeAssetBytes = 5 * 1024 * 1024;

          form.addEventListener('submit', async (event) => {
            if (event.defaultPrevented) {
              return;
            }

            event.preventDefault();
            const data = new FormData(form);
            data.append('progress_stream', '1');
            panel.hidden = false;
            log.textContent = '';
            setProgress(0);
            setStatus('Starting import...');
            if (button) {
              button.disabled = true;
              button.textContent = 'Importing...';
            }

            try {
              const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                body: data,
                headers: { Accept: 'application/x-ndjson' }
              });
              if (!response.ok || !response.body) {
                throw new Error('Import request failed.');
              }

              const reader = response.body.getReader();
              const decoder = new TextDecoder();
              let buffer = '';
              while (true) {
                const chunk = await reader.read();
                if (chunk.done) {
                  break;
                }

                buffer += decoder.decode(chunk.value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';
                lines.forEach(readLine);
              }

              if (buffer.trim() !== '') {
                readLine(buffer);
              }
            } catch (error) {
              appendLog('Error: ' + (error.message || 'Import failed.'));
              setStatus('Import stopped before it could finish.');
            } finally {
              if (button) {
                button.disabled = false;
                button.textContent = defaultButtonText;
              }
            }
          });

          function readLine(line) {
            const trimmed = line.trim();
            if (trimmed === '') {
              return;
            }

            try {
              handleEvent(JSON.parse(trimmed));
            } catch (error) {
              appendLog(trimmed);
            }
          }

          function handleEvent(event) {
            updateCounts(event);

            if (event.type === 'page_start') {
              appendLog('Fetching ' + event.url);
            } else if (event.type === 'page_saved') {
              appendLog('Saved page ' + event.path);
            } else if (event.type === 'resource_saved') {
              appendLog('Saved resource ' + event.path);
            } else if (event.type === 'page_skipped_nonessential') {
              appendLog('Skipped non-essential ' + event.url);
            } else if (event.type === 'page_skipped_limit') {
              appendLog('Skipped extra page after limit ' + event.url);
            } else if (event.type === 'asset_start') {
              appendLog('Mirroring asset ' + event.url);
            } else if (event.type === 'asset_saved') {
              const size = Number(event.bytes || 0);
              appendLog((size >= largeAssetBytes ? 'Mirrored large asset ' : 'Mirrored asset ') + event.path + (size > 0 ? ' (' + formatBytes(size) + ')' : ''));
            } else if (event.type === 'asset_progress') {
              const total = Number(event.total_bytes || 0);
              setStatus('Downloading ' + event.url + ': ' + formatBytes(Number(event.bytes || 0)) + (total > 0 ? ' / ' + formatBytes(total) : ''));
            } else if (event.type === 'queued_asset_saved') {
              appendLog('Mirrored resource ' + event.path);
            } else if (event.type === 'asset_permissions_repaired') {
              appendLog('Repaired imported asset permissions: ' + Number(event.files || 0) + ' file(s), ' + Number(event.directories || 0) + ' folder(s)');
            } else if (event.type === 'asset_failed') {
              appendLog('Asset failed ' + event.url + ': ' + event.message);
            } else if (event.type === 'page_failed') {
              appendLog('Failed ' + event.url + ': ' + event.message);
            } else if (event.type === 'complete' || event.type === 'done') {
              const result = event.result || {};
              setProgress(100);
              setStatus(
                'Finished. Pages: ' + count(result.saved) +
                ', resources: ' + count(result.resources_saved) +
                ', assets: ' + count(result.assets_saved) +
                ', /wp-content assets: ' + Number(result.wp_content_assets_saved || 0) +
                ', failed: ' + count(result.failed) +
                ', page-limit skipped: ' + count(result.limit_skipped) +
                ', non-essential skipped: ' + count(result.nonessential_skipped)
              );
            } else if (event.type === 'fatal') {
              appendLog('Error: ' + event.message);
              setStatus('Import failed.');
            }
          }

          function updateCounts(event) {
            if (event.type === 'complete' || event.type === 'done' || event.type === 'fatal') {
              return;
            }
            if (!Object.prototype.hasOwnProperty.call(event, 'visited') && !Object.prototype.hasOwnProperty.call(event, 'max_pages')) {
              return;
            }

            const visited = Number(event.visited || 0);
            const maxPages = Number(event.max_pages || 0);
            if (maxPages > 0) {
              setProgress(Math.min(98, Math.round((visited / maxPages) * 100)));
            }

            setStatus(
              'Visited ' + visited + '/' + maxPages +
              ', queued ' + Number(event.queued || 0) +
              ', pages ' + Number(event.saved || 0) +
              ', resources ' + Number(event.resources_saved || 0) +
              ', assets ' + Number(event.assets_saved || 0) +
              ', /wp-content assets ' + Number(event.wp_content_assets_saved || 0) +
              ', page-limit skipped ' + Number(event.skipped_limit || 0) +
              ', failed ' + Number(event.failed || 0)
            );
          }

          function appendLog(message) {
            log.textContent += (log.textContent === '' ? '' : '\n') + message;
            log.scrollTop = log.scrollHeight;
          }

          function setStatus(message) {
            status.textContent = message;
          }

          function setProgress(value) {
            bar.style.width = Math.max(0, Math.min(100, value)) + '%';
          }

          function count(value) {
            return Array.isArray(value) ? value.length : 0;
          }

          function formatBytes(bytes) {
            if (bytes >= 1024 * 1024) {
              return (bytes / 1024 / 1024).toFixed(1) + ' MB';
            }
            return Math.round(bytes / 1024) + ' KB';
          }
        })();
        </script>

      <?php endif; ?>

      <?php if ($user['is_admin']): ?>
        <section class="wysite-panel">
          <div class="wysite-panel__heading">
            <div>
              <p class="wysite-kicker">Unmanaged HTML</p>
              <h3>HTML files not yet added to WYSiteIWYG</h3>
            </div>
            <?php if ($importCandidates !== []): ?>
              <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-all" onsubmit="return window.confirm('Import all <?= h((string) count($importCandidates)) ?> unmanaged HTML file(s) with the current theme? This will rewrite those files on disk.');">
                <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                <button class="wysite-button" type="submit">Import All</button>
              </form>
            <?php endif; ?>
          </div>
          <p class="wysite-muted">These files are present on the server but do not yet contain WYSiteIWYG markers. Importing applies the current theme and wraps the imported content in editable Page Content blocks.</p>

          <?php if ($importCandidates === []): ?>
            <p class="wysite-muted">No unmanaged HTML files were found outside <code>/edit/</code>.</p>
          <?php else: ?>
            <div class="wysite-table-wrap">
              <table class="wysite-table">
                <thead>
                  <tr>
                    <th>File</th>
                    <th>Detected Title</th>
                    <th>Excerpt</th>
                    <th></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($importCandidates as $candidate): ?>
                    <tr>
                      <td><code><?= h($candidate['path']) ?></code></td>
                      <td><?= h($candidate['title']) ?></td>
                      <td><?= h($candidate['excerpt'] !== '' ? $candidate['excerpt'] : '—') ?></td>
                      <td class="wysite-table__actions">
                        <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-page" onsubmit="return window.confirm('Import <?= h($candidate['path']) ?> with the current theme?');">
                          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                          <input type="hidden" name="path" value="<?= h($candidate['path']) ?>">
                          <input type="hidden" name="title" value="<?= h($candidate['title']) ?>">
                          <input type="hidden" name="excerpt" value="<?= h($candidate['excerpt']) ?>">
                          <input type="hidden" name="container_xpath" value="">
                          <input type="hidden" name="block_xpaths" value="">
                          <button class="wysite-button wysite-button--ghost" type="submit">Import</button>
                        </form>
                        <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=import&path=<?= rawurlencode($candidate['path']) ?>">Advanced</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>

      <?php if ($user['is_admin']): ?>
        <section class="wysite-grid">
          <article class="wysite-panel">
            <h3>Create a user</h3>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-user" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <label>
                <span>Username</span>
                <input type="text" name="username" required>
              </label>
              <label>
                <span>Password</span>
                <input type="password" name="password" required minlength="10">
              </label>
              <label class="wysite-checkbox">
                <input type="checkbox" name="is_admin" value="1">
                <span>Administrator</span>
              </label>
              <button class="wysite-button" type="submit">Create user</button>
            </form>
          </article>

          <article class="wysite-panel">
            <h3>Reset a password</h3>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=update-password" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <label>
                <span>Username</span>
                <input type="text" name="username" required>
              </label>
              <label>
                <span>New password</span>
                <input type="password" name="password" required minlength="10">
              </label>
              <button class="wysite-button" type="submit">Update password</button>
            </form>
          </article>
        </section>

        <section class="wysite-panel">
          <div class="wysite-panel__heading">
            <div>
              <p class="wysite-kicker">User management</p>
              <h3>Accounts</h3>
            </div>
          </div>
          <div class="wysite-table-wrap">
            <table class="wysite-table">
              <thead>
                <tr>
                  <th>User</th>
                  <th>Role</th>
                  <th>Created</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($users as $account): ?>
                  <tr>
                    <td><?= h($account['username']) ?></td>
                    <td><?= $account['is_admin'] ? 'Admin' : 'Editor' ?></td>
                    <td><?= h((string) $account['created_at']) ?></td>
                    <td class="wysite-table__actions">
                      <?php if ($account['username'] !== $user['username']): ?>
                        <form method="post" action="<?= h($appUrl) ?>/index.php?action=delete-user">
                          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                          <input type="hidden" name="username" value="<?= h($account['username']) ?>">
                          <button class="wysite-button wysite-button--ghost" type="submit">Delete</button>
                        </form>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>
      <?php endif; ?>
    </main>
    <?php
    layout('Dashboard', (string) ob_get_clean(), $appUrl, $siteTitle, $user);
} catch (Throwable $error) {
    Flash::push('error', $error->getMessage());

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

    redirect($appUrl . '/index.php?action=login');
}
