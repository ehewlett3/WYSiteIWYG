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

        $aiSettings = $ai->publicSettings();
    ?>
    <main class="wysite-dashboard">
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">AI assistance (optional)</p>
            <h3>Auto-tag template regions with your own API key</h3>
          </div>
          <p class="wysite-muted">
            <?php if ($aiSettings['enabled'] && $aiSettings['has_key']): ?>
              Active — “Use as template” will pre-select regions for you to review.
            <?php else: ?>
              Off — template regions are selected manually. Add a key to enable pre-selection.
            <?php endif; ?>
          </p>
        </div>
        <p class="wysite-muted">When enabled, choosing “Use as template” sends a compact structural outline of the page (tags, ids, classes, short snippets — not the full page) to your chosen model, which proposes which element fills each template role. The picks are pre-loaded into the region designer for you to confirm or change; nothing is saved without your review. Your API key is stored in <code>edit/storage/ai.local.php</code> (git-ignored) and is never sent to the browser.</p>
        <form method="post" action="<?= h($appUrl) ?>/index.php?action=save-ai-settings" class="wysite-form">
          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
          <div class="wysite-grid">
            <label>
              <span>Provider</span>
              <select name="provider" class="wysite-theme-select">
                <option value="anthropic"<?= $aiSettings['provider'] === 'anthropic' ? ' selected' : '' ?>>Anthropic (Claude)</option>
                <option value="openai"<?= $aiSettings['provider'] === 'openai' ? ' selected' : '' ?>>OpenAI-compatible</option>
              </select>
            </label>
            <label>
              <span>Model</span>
              <input type="text" name="model" data-wysite-ai-model value="<?= h((string) $aiSettings['model']) ?>" placeholder="Model id (type it, or load and pick below)" autocomplete="off">
              <select data-wysite-ai-model-select class="wysite-theme-select" aria-label="Loaded models">
                <option value="">Load models to choose from a list…</option>
              </select>
            </label>
          </div>
          <label>
            <span>API key <?= $aiSettings['has_key'] ? '<em>(a key is saved — leave blank to keep it)</em>' : '<em>(none saved yet)</em>' ?></span>
            <input type="password" name="api_key" data-wysite-ai-key autocomplete="off" placeholder="<?= $aiSettings['has_key'] ? '••••••••••••' : 'Paste your API key' ?>">
          </label>
          <label>
            <span>Custom endpoint base URL <em>(optional; for self-hosted or OpenAI-compatible gateways)</em></span>
            <input type="url" name="base_url" data-wysite-ai-baseurl value="<?= h((string) $aiSettings['base_url']) ?>" placeholder="https://api.openai.com">
          </label>
          <div class="wysite-ai-controls">
            <label class="wysite-checkbox">
              <input type="checkbox" name="enabled" value="1" <?= $aiSettings['enabled'] ? 'checked' : '' ?>>
              <span>Enable AI region pre-selection</span>
            </label>
            <div class="wysite-ai-controls__buttons">
              <button type="button" class="wysite-button wysite-button--ghost" data-wysite-ai-load>Load models</button>
              <button type="button" class="wysite-button wysite-button--ghost" data-wysite-ai-test>Test connection</button>
            </div>
          </div>
          <p class="wysite-muted" data-wysite-ai-status hidden></p>
          <?php if ($aiSettings['has_key']): ?>
          <label class="wysite-checkbox">
            <input type="checkbox" name="clear_key" value="1">
            <span>Remove the saved API key</span>
          </label>
          <?php endif; ?>
          <button class="wysite-button" type="submit">Save AI settings</button>
        </form>
      </section>
    </main>
    <?php
