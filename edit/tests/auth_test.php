<?php
declare(strict_types=1);

use WYSiteIWYG\AuthManager;
use WYSiteIWYG\InstallToken;
use WYSiteIWYG\LoginThrottle;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_throws;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\scratch_dir;
use function WYSiteIWYG\Tests\test;

test('SEC-3: install token is required, 0600, and removed after use', function (): void {
    $dir = scratch_dir();
    $token = new InstallToken($dir . '/install-token.local.php');
    assert_false($token->verify('anything'));
    $token->ensure();
    assert_same('0600', substr(sprintf('%o', fileperms($dir . '/install-token.local.php')), -4));
    $value = require $dir . '/install-token.local.php';
    assert_same(32, strlen($value));
    assert_false($token->verify(''));
    assert_false($token->verify('wrong'));
    assert_true($token->verify($value));
    $token->ensure();
    assert_true($token->verify($value), 'ensure() must not rotate an existing token');
    $token->clear();
    assert_false(is_file($dir . '/install-token.local.php'));
});

test('SEC-3: WYSITE_INSTALL_TOKEN environment variable is accepted', function (): void {
    $dir = scratch_dir();
    putenv('WYSITE_INSTALL_TOKEN=env-secret-123');
    try {
        $token = new InstallToken($dir . '/install-token.local.php');
        $token->ensure();
        assert_false(is_file($dir . '/install-token.local.php'));
        assert_true($token->verify('env-secret-123'));
    } finally {
        putenv('WYSITE_INSTALL_TOKEN');
    }
});

test('SEC-7a: user store is written 0600', function (): void {
    $dir = scratch_dir();
    $auth = new AuthManager($dir . '/users.local.php');
    $auth->bootstrapAdmin('admin', 'correct horse battery');
    assert_same('0600', substr(sprintf('%o', fileperms($dir . '/users.local.php')), -4));
    assert_throws(fn() => $auth->bootstrapAdmin('admin2', 'correct horse battery'), 'already installed');
});

test('SEC-7b: per-IP bucket stops username spraying', function (): void {
    $dir = scratch_dir();
    $throttle = new LoginThrottle($dir . '/throttle.local.php', 900, 8, 5);
    for ($i = 0; $i < 5; $i++) {
        $throttle->ensureAllowed('user' . $i, '203.0.113.9');
        $throttle->recordFailure('user' . $i, '203.0.113.9');
    }
    assert_throws(fn() => $throttle->ensureAllowed('fresh-name', '203.0.113.9'), 'Too many');
    $throttle->ensureAllowed('fresh-name', '198.51.100.1');
});

test('SEC-7c/7d/UX-4: password checks, self-service change, role changes', function (): void {
    $dir = scratch_dir();
    $auth = new AuthManager($dir . '/users.local.php');
    $auth->bootstrapAdmin('admin', 'first password 1');
    $auth->createUser('editor', 'editor password', false);

    assert_false($auth->verifyPassword('ghost', 'whatever'));
    assert_true($auth->verifyPassword('admin', 'first password 1'));

    assert_throws(fn() => $auth->changeOwnPassword('editor', 'nope nope nope', 'new password 22'), 'current password');
    $auth->changeOwnPassword('editor', 'editor password', 'new password 22');
    assert_true($auth->verifyPassword('editor', 'new password 22'));

    assert_throws(fn() => $auth->setAdmin('admin', false), 'at least one administrator');
    $auth->setAdmin('editor', true);
    $auth->setAdmin('admin', false);
    $roles = array_column($auth->allUsers(), 'is_admin', 'username');
    assert_same(['admin' => false, 'editor' => true], $roles);
});
