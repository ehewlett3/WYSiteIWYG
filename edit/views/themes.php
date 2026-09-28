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

        $dashboardThemeId = $themes->dashboardThemeId();
        $allPages = $repository->listPages();
        $applyCount = count(array_filter($allPages, static fn(array $p): bool => empty($p['exclude_template'])));
        $excludedCount = count($allPages) - $applyCount;
        // A representative page of each kind for the preview picker.
        $previewChoices = [];
        foreach ($allPages as $candidatePage) {
            $label = kind_label($candidatePage['kind']);
            if (($candidatePage['paged'] ?? '') === '' && !isset($previewChoices[$label])) {
                $previewChoices[$label] = $candidatePage['path'];
            }
        }
        $currentVariables = $themes->variables($currentTheme['id']);
        $savedVariables = (array) (((array) $settings->get('theme_variables'))[$currentTheme['id']] ?? []);
    ?>
    <main class="wysite-dashboard">
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Theme selector</p>
            <h3>Preview a theme, then apply it to your site</h3>
          </div>
          <div class="wysite-hero-actions">
            <p class="wysite-muted">Current theme: <?= h($currentTheme['name']) ?></p>
            <?php if ($user['is_admin']): ?>
            <a class="wysite-button" href="<?= h($appUrl) ?>/index.php?action=manager">New theme</a>
            <?php endif; ?>
          </div>
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
                <?php if ($previewChoices !== []): ?>
                <form method="get" action="<?= h($appUrl) ?>/index.php" class="wysite-inline-form">
                  <input type="hidden" name="action" value="preview">
                  <input type="hidden" name="theme" value="<?= h($theme['id']) ?>">
                  <select name="path" class="wysite-theme-select" aria-label="Page to preview">
                    <?php foreach ($previewChoices as $choiceLabel => $choicePath): ?>
                      <option value="<?= h($choicePath) ?>"><?= h($choiceLabel) ?> — <?= h($choicePath) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button class="wysite-button wysite-button--ghost" type="submit">Preview</button>
                </form>
                <?php endif; ?>
                <?php if ($user['is_admin']): ?>
                  <form method="post" action="<?= h($appUrl) ?>/index.php?action=apply-theme" data-wysite-confirm="Apply <?= h($theme['name']) ?>? This rewrites <?= (int) $applyCount ?> page(s)<?= $excludedCount > 0 ? ' (' . (int) $excludedCount . ' excluded page(s) are left as they are)' : '' ?> and the active templates. A backup is taken first.">
                    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="theme" value="<?= h($theme['id']) ?>">
                    <button class="wysite-button" type="submit">Apply Theme</button>
                  </form>
                  <?php if ($theme['id'] !== $currentTheme['id'] && $theme['id'] !== $themes->defaultThemeId()): ?>
                  <form method="post" action="<?= h($appUrl) ?>/index.php?action=delete-theme" data-wysite-confirm="Delete the &quot;<?= h($theme['name']) ?>&quot; theme? This permanently removes its files and cannot be undone.">
                    <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                    <input type="hidden" name="theme" value="<?= h($theme['id']) ?>">
                    <button class="wysite-button wysite-button--ghost" type="submit">Delete</button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <?php if ($user['is_admin'] && $currentVariables !== []): ?>
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Customize</p>
            <h3><?= h($currentTheme['name']) ?> colours and fonts</h3>
          </div>
          <p class="wysite-muted">Changes apply to the published stylesheet right away and are kept when you re-apply this theme.</p>
        </div>
        <form method="post" action="<?= h($appUrl) ?>/index.php?action=save-theme-variables" class="wysite-form">
          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
          <div class="wysite-grid">
            <?php foreach ($currentVariables as $variable): ?>
              <?php $value = (string) ($savedVariables[$variable['name']] ?? '') ?: $variable['default']; ?>
              <label>
                <span><?= h($variable['label']) ?></span>
                <?php if ($variable['type'] === 'color' && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1): ?>
                  <input type="color" name="var[<?= h($variable['name']) ?>]" value="<?= h($value) ?>">
                <?php else: ?>
                  <input type="text" name="var[<?= h($variable['name']) ?>]" value="<?= h($value) ?>">
                <?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div class="wysite-hero-actions">
            <button class="wysite-button" type="submit">Save customizations</button>
            <button class="wysite-button wysite-button--ghost" type="submit" name="reset" value="1">Reset to theme defaults</button>
          </div>
        </form>
      </section>
      <?php endif; ?>

      <?php if ($user['is_admin']): ?>
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Dashboard theme</p>
            <h3>Theme for the editor itself</h3>
          </div>
        </div>
        <form method="post" action="<?= h($appUrl) ?>/index.php?action=set-dashboard-theme" class="wysite-form">
          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
          <label>
            <span>Dashboard theme</span>
            <select name="dashboard_theme" class="wysite-theme-select">
              <option value="default"<?= $dashboardThemeId === '' ? ' selected' : '' ?>>Default (built-in)</option>
              <?php foreach ($availableThemes as $theme): ?>
                <?php if (!empty($theme['has_dashboard'])): ?>
                <option value="<?= h($theme['id']) ?>"<?= $theme['id'] === $dashboardThemeId ? ' selected' : '' ?>><?= h($theme['name']) ?></option>
                <?php endif; ?>
              <?php endforeach; ?>
            </select>
          </label>
          <button class="wysite-button" type="submit">Save dashboard theme</button>
        </form>
      </section>
      <?php endif; ?>
    </main>
    <?php
