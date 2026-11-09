<?php
declare(strict_types=1);

use WYSiteIWYG\EditLocks;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\scratch_dir;
use function WYSiteIWYG\Tests\test;

test('UX-10: soft edit locks tell others who is editing, and release', function (): void {
    $locks = new EditLocks(scratch_dir());
    assert_same(null, $locks->acquire('about/index.html', 'alice'));
    assert_same('alice', $locks->holder('about/index.html', 'bob'));
    assert_same(null, $locks->holder('about/index.html', 'alice'));
    assert_same('alice', $locks->acquire('about/index.html', 'bob'));
    $locks->release('about/index.html', 'bob');
    assert_same('alice', $locks->holder('about/index.html', 'bob'), 'only the holder releases');
    $locks->release('about/index.html', 'alice');
    assert_same(null, $locks->holder('about/index.html', 'bob'));
});
