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

        $users = $auth->allUsers();
        $reauthField = static fn(): string => '<label><span>Your password</span><input type="password" name="current_password" required autocomplete="current-password"></label>';
    ?>
    <main class="wysite-dashboard">
      <section class="wysite-panel">
        <div class="wysite-panel__heading">
          <div>
            <p class="wysite-kicker">User management</p>
            <h3>Accounts</h3>
          </div>
          <p class="wysite-muted">Role changes, password resets, and deletions ask for your own password to confirm.</p>
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
                <?php $isSelf = $account['username'] === $user['username']; ?>
                <tr>
                  <td><?= h($account['username']) ?><?= $isSelf ? ' <span class="wysite-muted">(you)</span>' : '' ?></td>
                  <td><?= $account['is_admin'] ? 'Admin' : 'Editor' ?></td>
                  <td><?= h((string) $account['created_at']) ?></td>
                  <td class="wysite-table__actions">
                    <?php if ($isSelf): ?>
                      <a class="wysite-button wysite-button--ghost" href="<?= h($appUrl) ?>/index.php?action=account">Change my password</a>
                    <?php else: ?>
                    <details class="wysite-row-action">
                      <summary class="wysite-button wysite-button--ghost">Reset password</summary>
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=update-password" class="wysite-form">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="username" value="<?= h($account['username']) ?>">
                        <label><span>New password for <?= h($account['username']) ?></span><input type="password" name="password" required minlength="10" autocomplete="new-password"></label>
                        <?= $reauthField() ?>
                        <button class="wysite-button" type="submit">Set password</button>
                      </form>
                    </details>
                    <details class="wysite-row-action">
                      <summary class="wysite-button wysite-button--ghost"><?= $account['is_admin'] ? 'Make editor' : 'Make admin' ?></summary>
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=set-role" class="wysite-form">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="username" value="<?= h($account['username']) ?>">
                        <input type="hidden" name="role" value="<?= $account['is_admin'] ? 'editor' : 'admin' ?>">
                        <?= $reauthField() ?>
                        <button class="wysite-button" type="submit"><?= $account['is_admin'] ? 'Make editor' : 'Make admin' ?></button>
                      </form>
                    </details>
                    <details class="wysite-row-action">
                      <summary class="wysite-button wysite-button--ghost">Delete</summary>
                      <form method="post" action="<?= h($appUrl) ?>/index.php?action=delete-user" class="wysite-form" data-wysite-confirm="Delete the account &quot;<?= h($account['username']) ?>&quot;? This cannot be undone.">
                        <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
                        <input type="hidden" name="username" value="<?= h($account['username']) ?>">
                        <?= $reauthField() ?>
                        <button class="wysite-button" type="submit">Delete account</button>
                      </form>
                    </details>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>

      <section class="wysite-panel">
        <h3>Create a user</h3>
        <form method="post" action="<?= h($appUrl) ?>/index.php?action=create-user" class="wysite-form">
          <input type="hidden" name="csrf_token" value="<?= h(Csrf::token()) ?>">
          <label>
            <span>Username</span>
            <input type="text" name="username" required minlength="3" maxlength="32" autocomplete="off">
          </label>
          <label>
            <span>Password</span>
            <input type="password" name="password" required minlength="10" autocomplete="new-password">
          </label>
          <label class="wysite-checkbox">
            <input type="checkbox" name="is_admin" value="1">
            <span>Administrator</span>
          </label>
          <label>
            <span>Your password <em>(only needed when creating an administrator)</em></span>
            <input type="password" name="current_password" autocomplete="current-password">
          </label>
          <button class="wysite-button" type="submit">Create user</button>
        </form>
      </section>
    </main>
    <?php
