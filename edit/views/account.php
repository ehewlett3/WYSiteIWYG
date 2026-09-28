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

    ?>
    <main class="wysite-dashboard">
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">Your account</p>
            <h3>Change my password</h3>
          </div>
          <p class="wysite-muted">Signed in as <?= h($user['username']) ?> (<?= $user['is_admin'] ? 'administrator' : 'editor' ?>).</p>
        </div>
        <form method="post" action="<?= h($appUrl) ?>/index.php?action=change-own-password" class="wysite-form">
          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
          <label>
            <span>Current password</span>
            <input type="password" name="current_password" required autocomplete="current-password">
          </label>
          <label>
            <span>New password</span>
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
          </label>
          <label>
            <span>Confirm new password</span>
            <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password">
          </label>
          <button class="wysite-button" type="submit">Change password</button>
        </form>
      </section>
    </main>
    <?php
