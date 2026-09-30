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
            <h3>Use your own API key for region detection and theme design</h3>
          </div>
          <p class="wysite-muted">
            <?php if ($aiSettings['enabled'] && $aiSettings['has_key']): ?>
              Active — “Use as template” pre-selects regions for you to review, and the Manager can design themes.
            <?php else: ?>
              Off — template regions are selected manually. Add a key to turn on region detection and theme design.
            <?php endif; ?>
          </p>
        </div>
        <p class="wysite-muted">When enabled, choosing “Use as template” sends a compact structural outline of the page (tags, ids, classes, short snippets — not the full page) to your chosen model, which proposes which element fills each template role. The picks are pre-loaded into the region designer for you to confirm or change; nothing is saved without your review. “Design a theme with AI” in the Manager sends your brief, plus an outline and CSS digest of any sample sites and screenshots you provide. Your API key is stored in <code>edit/storage/ai.local.php</code> (git-ignored) and is never sent to the browser.</p>
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
              <span>Default model</span>
              <input type="text" name="model" data-wysite-ai-model list="wysite-ai-models" value="<?= h((string) $aiSettings['model']) ?>" placeholder="Model id (type it, or load models to pick)" autocomplete="off">
            </label>
          </div>
          <datalist id="wysite-ai-models"></datalist>
          <fieldset class="wysite-ai-tasks">
            <legend>Model for each task</legend>
            <p class="wysite-muted">Mechanical tasks run well on a fast, inexpensive model; theme design needs your most capable one. Leave a task blank to use the default model. After loading models, “Suggest models” fills blank tasks by tier.</p>
            <?php foreach (\WYSiteIWYG\AiAssistant::TASKS as $taskId => $task): ?>
            <label>
              <span><?= h($task['label']) ?> <em>(<?= $task['tier'] === 'fast' ? 'fast model' : 'most capable model' ?>)</em></span>
              <input type="text" name="task_models[<?= h($taskId) ?>]" data-wysite-ai-task="<?= h($taskId) ?>" data-wysite-ai-tier="<?= h($task['tier']) ?>" list="wysite-ai-models" value="<?= h((string) ($aiSettings['task_models'][$taskId] ?? '')) ?>" placeholder="Default model" autocomplete="off">
              <small class="wysite-muted"><?= h($task['hint']) ?></small>
            </label>
            <?php endforeach; ?>
          </fieldset>
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
              <span>Enable AI assistance</span>
            </label>
            <div class="wysite-ai-controls__buttons">
              <button type="button" class="wysite-button wysite-button--ghost" data-wysite-ai-load>Load models</button>
              <button type="button" class="wysite-button wysite-button--ghost" data-wysite-ai-suggest hidden>Suggest models</button>
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
