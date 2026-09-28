<?php
declare(strict_types=1);

// Dashboard view partial, included by edit/index.php with its variables in scope.
if (!isset($appUrl, $user)) {
    http_response_code(404);
    exit;
}

use WYSiteIWYG\Csrf;
use WYSiteIWYG\Flash;
use function WYSiteIWYG\h;

        $hasDemoContent = $generator->hasDemoContent();
        $importCandidates = $repository->listImportCandidates();
        $kindGuesses = [];
        foreach ($importCandidates as $candidate) {
            $kindGuesses[$candidate['path']] = guess_template_kind($candidate['path']);
        }
        // AI guesses are fetched by dashboard.js from ai-kinds after the page
        // renders, so a slow model call never blocks the Manager (UX-7).
        $aiKindsEnabled = $importCandidates !== [] && $ai->isConfigured();
        $builderThemeId = $themes->builderThemeId();
        $templateKinds = [
            'home' => 'Home / front page',
            'page' => 'Page',
            'blog' => 'Blog index / archive',
            'blog-post' => 'Blog post',
        ];
        $builderTemplates = [];
        if ($builderThemeId !== '') {
            foreach ($templateKinds as $templateKind => $templateLabel) {
                $filename = basename($repository->activeTemplateRelativePath($templateKind));
                $builderTemplates[$templateKind] = is_file($rootPath . '/edit/themes/' . $builderThemeId . '/' . $filename);
            }
        }
    ?>
    <main class="wysite-dashboard">
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Site content</p>
            <h3>Demo &amp; bulk actions</h3>
          </div>
        </div>
        <div class="wysite-hero-actions">
          <?php if (!$hasDemoContent): ?>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=deploy-demo" data-wysite-confirm="Deploy the demo site? Documentation pages are added at the site root; any existing page at the same path is skipped and left untouched.">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <button class="wysite-button" type="submit">Deploy demo site</button>
          </form>
          <?php else: ?>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=delete-demo" data-wysite-confirm="Delete all demo pages and posts? This removes every page flagged as demo content and cannot be undone.">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <button class="wysite-button wysite-button--ghost" type="submit">Delete demo content</button>
          </form>
          <?php endif; ?>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=convert-folder-urls" data-wysite-confirm="Move every page like about.html to about/index.html? Public URLs stay the same and the old files become redirects, so the site works without the root .htaccess rewrite rules. A backup is taken first.">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <button class="wysite-button wysite-button--ghost" type="submit">Convert to folder URLs</button>
          </form>
          <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=report">Site report (broken links &amp; migration)</a>
          <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=purge-preview">Delete all pages…</a>
        </div>
      </section>

      <?php
        $systemRows = $systemCheck->run();
        if (!empty($_GET['http']) && $app['siteOrigin'] !== '') {
            $flat = null;
            foreach ($repository->listPages() as $candidatePage) {
                if (preg_match('#(^|/)index\.html?$#', $candidatePage['path']) !== 1) {
                    $flat = $candidatePage['path'];
                    break;
                }
            }
            $systemRows = array_merge($systemRows, $systemCheck->probe($app['siteOrigin'] . $siteBaseUrl, $flat));
        }
      ?>
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">System status</p>
            <h3><?= \WYSiteIWYG\SystemCheck::hasFailures($systemRows) ? 'Problems need attention' : 'Server ready' ?></h3>
          </div>
          <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=manager&amp;http=1">Run HTTP checks</a>
        </div>
        <details<?= \WYSiteIWYG\SystemCheck::hasFailures($systemRows) || !empty($_GET['http']) ? ' open' : '' ?>>
          <summary>Details</summary>
          <?= render_system_rows($systemRows) ?>
        </details>
      </section>

      <?php $backupList = $backups->list(); ?>
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Backups</p>
            <h3>Site snapshots</h3>
          </div>
          <p class="wysite-muted">Taken automatically before theme apply, template edits, Import All, Delete all pages, and Delete demo content (the newest 10 are kept).</p>
        </div>
        <?php if ($backupList === []): ?>
          <p class="wysite-muted">No backups yet.</p>
        <?php else: ?>
          <div class="wysite-table-wrap">
            <table class="wysite-table">
              <thead><tr><th>Taken (UTC)</th><th>Before</th><th>Files</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($backupList as $backup): ?>
                  <tr>
                    <td><?= h(gmdate('Y-m-d H:i:s', $backup['time'])) ?></td>
                    <td><?= h(str_replace('-', ' ', substr($backup['name'], 17))) ?></td>
                    <td><?= h((string) $backup['files']) ?></td>
                    <td class="wysite-table__actions">
                      <?php if ($backup['bytes'] > 0): ?>
                        <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=backup-download&name=<?= rawurlencode($backup['name']) ?>">Download</a>
                      <?php endif; ?>
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=restore-backup" data-wysite-confirm="Restore all <?= h((string) $backup['files']) ?> file(s) from this backup? Pages and templates will be put back as they were; pages created since are kept.">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="name" value="<?= h($backup['name']) ?>">
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

      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Template manager</p>
            <h3>Build a theme from your pages</h3>
          </div>
          <p class="wysite-muted">Select or create a theme to build into, then choose "Use as template" on the pages below to add its templates. This does not touch your live site until you Apply the theme.</p>
        </div>

        <div class="wysite-grid">
          <article class="wysite-panel">
            <h3>Build target</h3>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=set-builder-theme" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <label>
                <span>Building templates into</span>
                <select name="theme" class="wysite-theme-select">
                  <option value="">— none selected —</option>
                  <?php foreach ($availableThemes as $theme): ?>
                  <option value="<?= h($theme['id']) ?>"<?= $theme['id'] === $builderThemeId ? ' selected' : '' ?>><?= h($theme['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <button class="wysite-button" type="submit">Set build target</button>
            </form>
          </article>
          <article class="wysite-panel">
            <h3>Create a new theme</h3>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-theme" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <label>
                <span>Theme name</span>
                <input type="text" name="name" required placeholder="e.g. Kamloops Mission">
              </label>
              <button class="wysite-button" type="submit">Create &amp; select</button>
            </form>
          </article>
        </div>

        <?php if ($builderThemeId === ''): ?>
          <p class="wysite-muted">No build target selected. Create a theme (recommended for an imported site) or pick one above, then add templates from the pages below.</p>
        <?php else: ?>
          <p class="wysite-muted">Templates for <strong><?= h($themes->getTheme($builderThemeId)['name']) ?></strong> (<code>edit/themes/<?= h($builderThemeId) ?>/</code>). Apply this theme from the Theme page when it's complete.</p>
          <div class="wysite-table-wrap">
            <table class="wysite-table">
              <thead><tr><th>Kind</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <?php foreach ($templateKinds as $templateKind => $templateLabel): ?>
                <tr>
                  <td><?= h($templateLabel) ?><?php if ($templateKind === 'home'): ?> <span class="wysite-muted">(optional; falls back to Page)</span><?php endif; ?></td>
                  <td><?= !empty($builderTemplates[$templateKind]) ? 'Established' : '<span class="wysite-muted">Not set</span>' ?></td>
                  <td class="wysite-table__actions">
                    <?php if (!empty($builderTemplates[$templateKind])): ?>
                    <form method="post" action="<?= h($appUrl) ?>/index.php?action=clear-template" data-wysite-confirm="Clear the <?= h($templateLabel) ?> template from this theme?">
                      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                      <input type="hidden" name="kind" value="<?= h($templateKind) ?>">
                      <button class="wysite-button wysite-button--ghost" type="submit">Clear</button>
                    </form>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>

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
            <?php if ($builderThemeId === ''): ?>
              <p class="wysite-flash wysite-flash--error">Choose or create a build-target theme above first — templates are saved into that theme.</p>
            <?php endif; ?>
            <p class="wysite-muted">Fetch one external page, select the menu and content regions in the browser, and save them as a template in the build-target theme<?= $builderThemeId !== '' ? ' (<code>edit/themes/' . h($builderThemeId) . '/</code>)' : '' ?>. Apply that theme when it's complete.</p>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=external-template-fetch" class="wysite-form">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <label>
                <span>Source URL</span>
                <input type="url" name="url" required placeholder="https://example.com/about/">
              </label>
              <label>
                <span>Template type</span>
                <select name="kind" class="wysite-theme-select">
                  <option value="home">Home / front page template</option>
                  <option value="page" selected>Page template</option>
                  <option value="blog-post">Blog post template</option>
                  <option value="blog">Blog / archive template</option>
                </select>
              </label>
              <button class="wysite-button" type="submit"<?= $builderThemeId === '' ? ' disabled' : '' ?>>Fetch and Select Sections</button>
            </form>
          </article>

          <article>
            <h3>Import an external site</h3>
            <p class="wysite-muted">Crawl source-site HTML pages from a starting URL, save feeds and other static resources locally, rewrite source-domain page links, and mirror referenced assets and feed media into <code>/assets/imported/</code>. This is intended as a first migration pass for WordPress-style sites before importing pages into WYSite blocks.</p>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=external-site-import" class="wysite-form" data-wysite-external-import-form="1" data-wysite-confirm="Import HTML pages, feeds, and referenced assets from this external site? Existing local files will only be replaced if overwrite is checked.">
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
            <?php foreach ($externalImporter->unfinishedImportJobs() as $unfinishedJob): ?>
              <div class="wysite-flash wysite-flash--error">
                Unfinished import of <code><?= h($unfinishedJob['start_url']) ?></code> (<?= (int) $unfinishedJob['saved'] ?> page(s) saved, <?= (int) $unfinishedJob['queued'] ?> URL(s) left).
                <button type="button" class="wysite-button wysite-button--ghost" data-wysite-resume-job="<?= h($unfinishedJob['id']) ?>" data-wysite-resume-url="<?= h($unfinishedJob['start_url']) ?>">Resume</button>
                <form method="post" action="<?= h($appUrl) ?>/index.php?action=discard-import-job" class="wysite-inline-form">
                  <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                  <input type="hidden" name="job" value="<?= h($unfinishedJob['id']) ?>">
                  <button type="submit" class="wysite-button wysite-button--ghost">Discard</button>
                </form>
              </div>
            <?php endforeach; ?>
            <div class="wysite-import-progress" data-wysite-external-import-progress hidden>
              <div class="wysite-import-progress__bar"><span data-wysite-import-progress-bar></span></div>
              <p class="wysite-muted" data-wysite-import-progress-status>Preparing import...</p>
              <pre class="wysite-import-progress__log" data-wysite-import-progress-log></pre>
            </div>
          </article>

          <article>
            <h3>Import a WordPress export</h3>
            <p class="wysite-muted">The most faithful migration: in WordPress go to Tools → Export → All content, then upload the .xml file here. Posts keep their dates, tags, authors, excerpts and permalinks; drafts stay private.</p>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=wxr-import" class="wysite-form" enctype="multipart/form-data" data-wysite-confirm="Import this WordPress export? Existing pages at the same addresses are skipped unless you chose to overwrite them. A backup is taken first.">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <label>
                <span>WordPress export (.xml)</span>
                <input type="file" name="wxr" accept=".xml,application/xml,text/xml" required>
              </label>
              <label class="wysite-checkbox"><input type="checkbox" name="mirror_media" value="1" checked><span>Download images and media into /assets/imported/</span></label>
              <label class="wysite-checkbox"><input type="checkbox" name="overwrite" value="1"><span>Overwrite pages that already exist</span></label>
              <button class="wysite-button" type="submit">Import export file</button>
            </form>
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

      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Unmanaged pages</p>
            <h3>HTML files not yet added to WYSiteIWYG</h3>
          </div>
          <?php if ($importCandidates !== []): ?>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-all" data-wysite-confirm="Import all <?= h((string) count($importCandidates)) ?> unmanaged HTML file(s) with the current theme? This will rewrite those files on disk.">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <button class="wysite-button" type="submit">Import All</button>
            </form>
          <?php endif; ?>
        </div>
        <?php if ($aiKindsEnabled): ?><p class="wysite-muted" data-wysite-ai-kinds-url="<?= h($appUrl) ?>/index.php?action=ai-kinds" hidden>Asking the AI assistant to suggest template kinds…</p><?php endif; ?>
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
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-page" data-wysite-confirm="Import <?= h($candidate['path']) ?> with the current theme?">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="path" value="<?= h($candidate['path']) ?>">
                        <input type="hidden" name="title" value="<?= h($candidate['title']) ?>">
                        <input type="hidden" name="excerpt" value="<?= h($candidate['excerpt']) ?>">
                        <input type="hidden" name="container_xpath" value="">
                        <input type="hidden" name="block_xpaths" value="">
                        <button class="wysite-button wysite-button--ghost" type="submit">Import</button>
                      </form>
                      <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=import&path=<?= rawurlencode($candidate['path']) ?>">Advanced</a>
                      <?php $guessKind = $kindGuesses[$candidate['path']] ?? 'page'; ?>
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=template-from-page">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="path" value="<?= h($candidate['path']) ?>">
                        <select name="kind" class="wysite-theme-select" aria-label="Template kind" data-wysite-ai-kind="<?= h($candidate['path']) ?>">
                          <option value="home"<?= $guessKind === 'home' ? ' selected' : '' ?>>Home template</option>
                          <option value="page"<?= $guessKind === 'page' ? ' selected' : '' ?>>Page template</option>
                          <option value="blog"<?= $guessKind === 'blog' ? ' selected' : '' ?>>Blog index template</option>
                          <option value="blog-post"<?= $guessKind === 'blog-post' ? ' selected' : '' ?>>Blog post template</option>
                        </select>
                        <button class="wysite-button wysite-button--ghost" type="submit">Use as template</button>
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
