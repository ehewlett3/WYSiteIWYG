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

        $justPublished = $generator->publishDueDrafts();
        foreach ($justPublished as $publishedPath) {
            Flash::push('success', 'Published scheduled draft ' . $publishedPath . '.');
        }
        $pages = array_values(array_filter($repository->listPages(), static fn(array $p): bool => ($p['paged'] ?? '') === ''));
        $drafts = $repository->listDrafts();
        $hasHome = is_file($rootPath . '/index.html');
        $checklist = [];
        if ($user['is_admin'] && !$settings->get('checklist_dismissed')) {
            $checklist = [
                ['done' => $settings->isCustomized('site_name'), 'label' => 'Set your site name and tagline', 'href' => $appUrl . '/index.php?action=settings'],
                ['done' => $themes->currentThemeId() !== $themes->defaultThemeId() || is_file($rootPath . '/assets/site.css'), 'label' => 'Pick and apply a theme', 'href' => $appUrl . '/index.php?action=themes'],
                ['done' => $hasHome, 'label' => 'Create or edit the homepage (and its menu)', 'href' => $hasHome ? $appUrl . '/index.php?action=preview&path=index.html' : '#create-page'],
                ['done' => $repository->listBlogPosts() !== [], 'label' => 'Write your first blog post', 'href' => '#create-post'],
            ];
        }
    ?>
    <main class="wysite-dashboard">
      <section class="wysite-panel wysite-panel--hero">
        <div>
          <p class="wysite-kicker">Transparent editing</p>
          <h2>Edit the real HTML, in place.</h2>
          <p>Open a page, click the in-page badge, and edit the static file directly. Editable sections are delimited with HTML comments, so the output stays static.</p>
        </div>
        <div class="wysite-hero-actions">
          <?php if ($hasHome): ?>
            <a class="wysite-button" href="<?= h($appUrl) ?>/index.php?action=preview&path=index.html">Edit homepage</a>
          <?php else: ?>
            <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-page">
              <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
              <input type="hidden" name="title" value="<?= h($siteTitle) ?>">
              <input type="hidden" name="slug" value="index">
              <button class="wysite-button" type="submit">Create homepage</button>
            </form>
          <?php endif; ?>
          <a class="wysite-button wysite-button--ghost" href="<?= h($siteBaseUrl) ?>" target="_blank" rel="noreferrer">Open live site</a>
        </div>
      </section>

      <?php if ($checklist !== []): ?>
      <section class="wysite-panel wysite-checklist">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Get started</p>
            <h3><?= count(array_filter($checklist, static fn(array $item): bool => $item['done'])) ?> of <?= count($checklist) ?> done</h3>
          </div>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=dismiss-checklist">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <button class="wysite-button wysite-button--ghost" type="submit">Dismiss</button>
          </form>
        </div>
        <ul class="wysite-checklist__items">
          <?php foreach ($checklist as $item): ?>
            <li class="<?= $item['done'] ? 'is-done' : '' ?>"><a href="<?= h($item['href']) ?>"><?= h($item['label']) ?></a></li>
          <?php endforeach; ?>
        </ul>
      </section>
      <?php endif; ?>

      <section class="wysite-grid">
        <article class="wysite-panel" id="create-page">
          <h3>Create a page</h3>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-page" class="wysite-form">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <label>
              <span>Page title</span>
              <input type="text" name="title" required data-wysite-slug-source>
            </label>
            <label>
              <span>Slug <em>(optional)</em></span>
              <input type="text" name="slug" placeholder="about/team" data-wysite-slug-preview="<?= h($siteBaseUrl) ?>">
            </label>
            <p class="wysite-muted wysite-url-preview" data-wysite-url-preview hidden></p>
            <label class="wysite-checkbox"><input type="checkbox" name="add_to_menu" value="1" checked><span>Add to the main menu</span></label>
            <label class="wysite-checkbox"><input type="checkbox" name="draft" value="1"><span>Save as a draft (private until published)</span></label>
            <button class="wysite-button" type="submit">Create page</button>
          </form>
        </article>

        <article class="wysite-panel" id="create-post">
          <h3>Create a blog post</h3>
          <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-post" class="wysite-form">
            <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
            <label>
              <span>Post title</span>
              <input type="text" name="title" required data-wysite-slug-source>
            </label>
            <label>
              <span>Slug <em>(optional)</em></span>
              <input type="text" name="slug" placeholder="launch-notes" data-wysite-slug-preview="<?= h($siteBaseUrl) ?>" data-wysite-permalink="<?= h((string) $settings->get('post_permalink')) ?>">
            </label>
            <p class="wysite-muted wysite-url-preview" data-wysite-url-preview hidden></p>
            <label>
              <span>Excerpt <em>(optional — taken from the start of the post when blank)</em></span>
              <textarea name="excerpt" rows="3"></textarea>
            </label>
            <label>
              <span>Hashtags</span>
              <input type="text" name="hashtags" value="#blog" placeholder="#blog #workflow">
            </label>
            <label class="wysite-checkbox"><input type="checkbox" name="add_to_menu" value="1"><span>Add to the main menu</span></label>
            <label class="wysite-checkbox"><input type="checkbox" name="draft" value="1" checked><span>Save as a draft (off the blog and feeds until published)</span></label>
            <button class="wysite-button" type="submit">Create post</button>
          </form>
        </article>
      </section>

      <?php if ($drafts !== []): ?>
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Drafts</p>
            <h3>Not public yet</h3>
          </div>
        </div>
        <div class="wysite-table-wrap">
          <table class="wysite-table">
            <thead><tr><th>Draft</th><th>Type</th><th>Scheduled</th><th>Updated</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($drafts as $draft): ?>
                <tr>
                  <td><strong><?= h($draft['title']) ?></strong><div class="wysite-muted">will publish at <?= h(public_page_url($siteBaseUrl, $draft['public_path'])) ?></div></td>
                  <td><?= h(kind_label($draft['kind'])) ?></td>
                  <td><?= h($draft['publish_at'] !== '' ? $draft['publish_at'] : '—') ?></td>
                  <td><?= h(gmdate('Y-m-d H:i', $draft['modified'])) ?></td>
                  <td class="wysite-table__actions">
                    <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=preview&path=<?= rawurlencode($draft['path']) ?>">Edit</a>
                    <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=metadata&path=<?= rawurlencode($draft['path']) ?>">Details</a>
                    <form method="post" action="<?= h($appUrl) ?>/index.php?action=publish-page">
                      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                      <input type="hidden" name="path" value="<?= h($draft['path']) ?>">
                      <button class="wysite-button wysite-button--ghost" type="submit">Publish</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
      <?php endif; ?>

      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Managed pages</p>
            <h3>Pages managed by WYSiteIWYG</h3>
          </div>
          <div class="wysite-table-filters" data-wysite-table-filter="managed-pages">
            <input type="search" placeholder="Filter by title, path or #tag" aria-label="Filter pages" data-wysite-filter-text>
            <select class="wysite-theme-select" aria-label="Filter by type" data-wysite-filter-kind>
              <option value="">All types</option>
              <?php foreach (['home', 'page', 'blog-post', 'blog'] as $kindOption): ?>
                <option value="<?= h($kindOption) ?>"><?= h(kind_label($kindOption)) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="wysite-table-wrap">
          <table class="wysite-table" id="managed-pages">
            <thead>
              <tr>
                <th>Page</th>
                <th>Type</th>
                <th>Hashtags</th>
                <th>Updated</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pages as $page): ?>
                <?php $isGenerated = ($page['generated'] ?? '') === 'tag-index'; ?>
                <tr data-kind="<?= h($page['kind']) ?>" data-search="<?= h(strtolower($page['title'] . ' ' . $page['path'] . ' ' . $page['hashtags'])) ?>">
                  <td>
                    <strong><?= h($page['title']) ?></strong>
                    <div class="wysite-muted"><?= h($page['path']) ?><?= !empty($page['exclude_template']) ? ' · excluded from template rebuilds' : '' ?></div>
                  </td>
                  <td><?= h(kind_label($page['kind'])) ?><?= $isGenerated ? ' <span class="wysite-muted">(generated)</span>' : '' ?></td>
                  <td><?= h($page['hashtags'] !== '' ? $page['hashtags'] : '—') ?></td>
                  <td><?= h(gmdate('Y-m-d', (int) (@filemtime($rootPath . '/' . $page['path']) ?: 0))) ?></td>
                  <td class="wysite-table__actions">
                    <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=preview&path=<?= rawurlencode($page['path']) ?>">Edit</a>
                    <a class="wysite-button wysite-button--ghost" href="<?= h(public_page_url($siteBaseUrl, $page['path'])) ?>" target="_blank" rel="noreferrer">View</a>
                    <?php if (!$isGenerated): ?>
                      <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=metadata&path=<?= rawurlencode($page['path']) ?>">Details</a>
                      <?php if ($page['path'] !== 'index.html'): ?>
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=delete-page" data-wysite-confirm="Delete <?= h($page['path']) ?>? Its last version is kept in the page history.">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="path" value="<?= h($page['path']) ?>">
                        <button class="wysite-button wysite-button--ghost" type="submit">Delete</button>
                      </form>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    </main>
    <?php
