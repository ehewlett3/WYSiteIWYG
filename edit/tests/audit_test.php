<?php
declare(strict_types=1);

use WYSiteIWYG\AuditLog;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\scratch_dir;
use function WYSiteIWYG\Tests\test;

test('SEC-7c: the audit log records and filters actions, newest first', function (): void {
    $file = scratch_dir() . '/storage/audit.local.jsonl';
    $log = new AuditLog($file);
    $log->record('alice', 'save-block', ['path' => 'about/index.html', 'name' => 'page-content', 'ignored' => ['x']]);
    $log->record('bob', 'apply-theme', ['name' => 'acme']);
    $log->record('alice', 'delete-page', ['path' => 'old/index.html']);
    assert_same(['delete-page', 'apply-theme', 'save-block'], array_column($log->recent(), 'action'));
    assert_same(2, count($log->recent(10, 'alice')));
    assert_same('old/index.html', $log->recent(10, '', 'delete-page')[0]['detail']['path']);
    assert_same(1, count($log->recent(10, '', '', 'about')));
    assert_same('0600', substr(sprintf('%o', fileperms($file)), -4));
});
