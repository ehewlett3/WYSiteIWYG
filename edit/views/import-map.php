<?php
declare(strict_types=1);

// Import map (THM-C): included by edit/index.php with $appUrl, $map, $siteBaseUrl in scope.
if (!isset($appUrl, $user)) {
    http_response_code(404);
    exit;
}

use WYSiteIWYG\Csrf;
use function WYSiteIWYG\h;

$roleLabels = [
    'main-menu' => 'Menu',
    'page-content' => 'Page content',
    'blog-post-content' => 'Post body',
    'blog-index-content' => 'Archive intro',
    'blog-items' => 'Post list',
    'title' => 'Page title',
];
$kindLabels = ['home' => 'Home page', 'page' => 'Pages', 'blog-post' => 'Blog posts', 'blog' => 'Archives (replaced by tag pages)'];
?>
<main class="wysite-dashboard">
  <section class="wysite-panel">
    <div class="wysite-panel__heading">
      <div>
        <p class="wysite-kicker">Import map</p>
        <h2><?= $map === null ? 'Find the menu and content on every imported page' : (int) $map['pages_total'] . ' page(s) in ' . count($map['clusters']) . ' layout(s)' ?></h2>
      </div>
      <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=manager">Back to Manager</a>
    </div>
    <p class="wysite-muted">WYSiteIWYG compares the imported pages with each other: what is the same on every page is the site's header, menu and footer; what changes from page to page is the content. Check each layout below, fix any region that looks wrong, then create a theme from the site and turn every page into an editable page.</p>
    <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-analyze" class="wysite-inline-form">
      <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
      <button class="wysite-button<?= $map !== null ? ' wysite-button--ghost' : '' ?>" type="submit"><?= $map === null ? 'Analyse imported pages' : 'Analyse again' ?></button>
    </form>
  </section>

  <?php if ($map !== null): ?>
    <?php foreach ($map['clusters'] as $cluster): ?>
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker"><?= h($kindLabels[$cluster['kind']] ?? $cluster['kind']) ?> · <?= count($cluster['pages']) ?> page(s)</p>
            <h3>Layout <?= h(strtoupper((string) $cluster['id'])) ?></h3>
          </div>
          <a class="wysite-button wysite-button--ghost" target="_blank" rel="noreferrer" href="<?= h($appUrl) ?>/index.php?action=import-region-preview&cluster=<?= rawurlencode((string) $cluster['id']) ?>&page=<?= rawurlencode((string) $cluster['representative']) ?>">See regions on <?= h((string) $cluster['representative']) ?></a>
        </div>
        <div class="wysite-table-wrap">
          <table class="wysite-table">
            <thead><tr><th>Region</th><th>Selector</th><th>Found on</th><th>Confidence</th></tr></thead>
            <tbody>
              <?php foreach ($cluster['roles'] as $role => $info): ?>
                <tr>
                  <td><strong><?= h($roleLabels[$role] ?? $role) ?></strong><?php if (!empty($info['note'])): ?><br><span class="wysite-muted"><?= h((string) $info['note']) ?></span><?php endif; ?></td>
                  <td>
                    <?php if ($role === 'title'): ?>
                      <code><?= h((string) $info['selector']) ?></code> <?= !empty($info['renders_in_content']) ? '(inside the content)' : '' ?>
                    <?php else: ?>
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-map-role" class="wysite-inline-form">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="cluster" value="<?= h((string) $cluster['id']) ?>">
                        <input type="hidden" name="role" value="<?= h((string) $role) ?>">
                        <input type="text" name="selector" class="wysite-code-field" value="<?= h((string) $info['selector']) ?>" aria-label="<?= h(($roleLabels[$role] ?? $role) . ' selector') ?>">
                        <button class="wysite-button wysite-button--ghost" type="submit">Check</button>
                      </form>
                    <?php endif; ?>
                  </td>
                  <td><?php if (isset($info['matches'])): ?><?= (int) $info['matches']['1'] ?> / <?= count($cluster['pages']) ?><?php endif; ?></td>
                  <td><span class="wysite-status wysite-status--<?= ((float) ($info['confidence'] ?? 0)) >= 0.75 ? 'pass' : (((float) ($info['confidence'] ?? 0)) >= 0.5 ? 'warn' : 'fail') ?>"><?= h((string) round(100 * (float) ($info['confidence'] ?? 0))) ?>%</span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($cluster['attention'] !== []): ?>
          <details class="wysite-muted">
            <summary><?= count(array_unique(array_column($cluster['attention'], 'page'))) ?> page(s) need attention</summary>
            <ul>
              <?php foreach (array_slice($cluster['attention'], 0, 50) as $item): ?>
                <li><a target="_blank" rel="noreferrer" href="<?= h($appUrl) ?>/index.php?action=import-region-preview&cluster=<?= rawurlencode((string) $cluster['id']) ?>&page=<?= rawurlencode((string) $item['page']) ?>"><code><?= h((string) $item['page']) ?></code></a>: <?= h($roleLabels[$item['role']] ?? $item['role']) ?> <?= (int) $item['matches'] === 0 ? 'not found' : 'found ' . (int) $item['matches'] . ' times' ?></li>
              <?php endforeach; ?>
            </ul>
          </details>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>

    <section class="wysite-panel">
      <div class="wysite-panel__heading">
        <div>
          <p class="wysite-kicker">Next step</p>
          <h3>Use what was found</h3>
        </div>
      </div>
      <form method="post" action="<?= h($appUrl) ?>/index.php?action=import-map-apply" class="wysite-form" data-wysite-confirm="Run the selected steps? A backup is taken first.">
        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
        <label class="wysite-checkbox"><input type="checkbox" name="create_theme" value="1" checked><span>Create a theme from this site's layouts (stylesheets, fonts and images are copied into the theme)</span></label>
        <label><span>Theme name</span><input type="text" name="theme_name" placeholder="My imported site"></label>
        <label class="wysite-checkbox"><input type="checkbox" name="rights_confirmed" value="1"><span>I have the right to reuse this site's design and assets</span></label>
        <label class="wysite-checkbox"><input type="checkbox" name="apply_theme" value="1" checked><span>Apply that theme to the whole site</span></label>
        <label class="wysite-checkbox"><input type="checkbox" name="use_menu" value="1" checked><span>Use the imported menu as the site menu</span></label>
        <label class="wysite-checkbox"><input type="checkbox" name="convert_pages" value="1" checked><span>Turn every imported page into an editable page, using the content region above</span></label>
        <label class="wysite-checkbox"><input type="checkbox" name="clean_markup" value="1"><span>Clean up page-builder markup in the content (helps when you apply a different theme)</span></label>
        <button class="wysite-button" type="submit">Run</button>
      </form>
    </section>
  <?php endif; ?>
</main>
