<?php
declare(strict_types=1);

use WYSiteIWYG\SystemCheck;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

test('DROP-2: an unwritable templates folder is a clear failure row', function (): void {
    $site = make_site();
    $check = new SystemCheck($site['root'], $site['edit']);
    assert_false(SystemCheck::hasFailures($check->run()));

    chmod($site['edit'] . '/templates', 0555);
    try {
        $rows = $check->run();
        $failed = array_filter($rows, static fn(array $row): bool => $row['status'] === 'fail' && str_contains($row['label'], 'edit/templates'));
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            return; // root ignores permissions
        }
        assert_true(count($failed) === 1, 'expected a failure row for edit/templates');
        assert_true(SystemCheck::hasFailures($rows));
    } finally {
        chmod($site['edit'] . '/templates', 0775);
    }
});
