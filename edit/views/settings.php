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

        $current = $settings->all();
    ?>
    <main class="wysite-dashboard">
      <form method="post" action="<?= h($appUrl) ?>/index.php?action=save-settings" class="wysite-form" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">

        <section class="wysite-panel">
          <div class="wysite-panel__heading">
            <div>
              <p class="wysite-kicker">Site identity</p>
              <h3>Name, tagline, and footer</h3>
            </div>
            <p class="wysite-muted">Shown in every theme's header, page titles, and footer. Saving rebuilds all pages. (A theme applied before this version may hard-code its own name; re-apply it from the Theme page to pick these up.)</p>
          </div>
          <div class="wysite-grid">
            <label><span>Site name</span><input type="text" name="site_name" required maxlength="120" value="<?= h((string) $current['site_name']) ?>"></label>
            <label><span>Tagline</span><input type="text" name="tagline" maxlength="120" value="<?= h((string) $current['tagline']) ?>"></label>
            <label><span>Language code</span><input type="text" name="language" required pattern="[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*" value="<?= h((string) $current['language']) ?>"></label>
            <label><span>Public site URL <em>(for feeds, sitemap, canonical links)</em></span><input type="url" name="canonical_base_url" placeholder="https://example.org/" value="<?= h((string) $current['canonical_base_url']) ?>"></label>
          </div>
          <label><span>Footer HTML <em>(scripts and event handlers are removed)</em></span><textarea name="footer_html" rows="3" class="wysite-code-field"><?= h((string) $current['footer_html']) ?></textarea></label>
          <div class="wysite-grid">
            <div>
              <label><span>Logo image</span><input type="file" name="logo_file" accept="image/png,image/jpeg,image/gif,image/webp,image/avif"></label>
              <?php if ($current['logo'] !== ''): ?>
                <p class="wysite-muted">Current: <code><?= h((string) $current['logo']) ?></code></p>
                <label class="wysite-checkbox"><input type="checkbox" name="remove_logo" value="1"><span>Remove the logo</span></label>
              <?php endif; ?>
            </div>
            <div>
              <label><span>Favicon</span><input type="file" name="favicon_file" accept="image/x-icon,image/png,image/svg+xml"></label>
              <?php if ($current['favicon'] !== ''): ?>
                <p class="wysite-muted">Current: <code><?= h((string) $current['favicon']) ?></code></p>
                <label class="wysite-checkbox"><input type="checkbox" name="remove_favicon" value="1"><span>Remove the favicon</span></label>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="wysite-panel">
          <div class="wysite-panel__heading">
            <div>
              <p class="wysite-kicker">Publishing</p>
              <h3>URLs, posts, and the live banner</h3>
            </div>
          </div>
          <div class="wysite-grid">
            <label>
              <span>New page files</span>
              <select name="url_style" class="wysite-theme-select">
                <option value="folder"<?= $current['url_style'] === 'folder' ? ' selected' : '' ?>>Folders — about/index.html (works on any server)</option>
                <option value="flat"<?= $current['url_style'] === 'flat' ? ' selected' : '' ?>>Flat files — about.html (needs the root .htaccess rewrites)</option>
              </select>
            </label>
            <label>
              <span>Blog post addresses</span>
              <select name="post_permalink" class="wysite-theme-select">
                <?php foreach (\WYSiteIWYG\SiteSettings::PERMALINK_PATTERNS as $pattern): ?>
                  <option value="<?= h($pattern) ?>"<?= $current['post_permalink'] === $pattern ? ' selected' : '' ?>>/<?= h(str_replace(['{slug}', '{yyyy}', '{mm}'], ['my-post', gmdate('Y'), gmdate('m')], $pattern)) ?>/</option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><span>Posts per blog page</span><input type="number" name="posts_per_page" min="1" max="100" value="<?= h((string) $current['posts_per_page']) ?>"></label>
          </div>
          <label class="wysite-checkbox">
            <input type="checkbox" name="live_banner" value="1"<?= $current['live_banner'] ? ' checked' : '' ?>>
            <span>Show the "Signed in — Edit" banner on public pages to signed-in editors (it never loads for ordinary visitors)</span>
          </label>
        </section>

        <section class="wysite-panel">
          <div class="wysite-panel__heading">
            <div>
              <p class="wysite-kicker">Advanced</p>
              <h3>Scanning, embeds, and migration</h3>
            </div>
          </div>
          <label><span>Folders to ignore <em>(one per line, relative to the site root — e.g. a neighbouring app)</em></span><textarea name="exclude_paths" rows="3" class="wysite-code-field" placeholder="shop&#10;forum"><?= h((string) $current['exclude_paths']) ?></textarea></label>
          <label><span>Known embed hosts <em>(space-separated; iframes from other hosts are kept but flagged in the migration report)</em></span><textarea name="embed_hosts" rows="2" class="wysite-code-field" placeholder="<?= h(implode(' ', \WYSiteIWYG\Sanitizer::defaultEmbedHosts())) ?>"><?= h((string) $current['embed_hosts']) ?></textarea></label>
          <div class="wysite-grid">
            <label><span>Import storage budget (MB per run)</span><input type="number" name="import_byte_budget_mb" min="50" value="<?= h((string) $current['import_byte_budget_mb']) ?>"></label>
            <label><span>External search URL <em>(replaces WordPress search forms; e.g. https://duckduckgo.com/)</em></span><input type="url" name="search_url" value="<?= h((string) $current['search_url']) ?>"></label>
            <label><span>Form endpoint <em>(where migrated contact forms should post)</em></span><input type="url" name="form_endpoint" value="<?= h((string) $current['form_endpoint']) ?>"></label>
          </div>
          <label class="wysite-checkbox">
            <input type="checkbox" name="keep_imported_scripts" value="1"<?= $current['keep_imported_scripts'] ? ' checked' : '' ?>>
            <span>Keep the original site's scripts when crawling an external site (off: scripts are removed, since they run with the editor's privileges)</span>
          </label>
        </section>

        <div class="wysite-hero-actions">
          <button class="wysite-button" type="submit">Save settings and rebuild pages</button>
        </div>
      </form>
    </main>
    <?php
