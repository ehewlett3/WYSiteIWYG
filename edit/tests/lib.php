<?php
declare(strict_types=1);

/**
 * Zero-dependency test harness for WYSiteIWYG. Test files (edit/tests/*_test.php)
 * register cases with test(); run.php executes them. Each case gets a fresh
 * scratch site from make_site() when it needs one, so destructive flows never
 * touch the live checkout.
 */

namespace WYSiteIWYG\Tests;

use RuntimeException;
use Throwable;

final class AssertionFailed extends RuntimeException
{
}

final class Registry
{
    /** @var array<int, array{name:string, fn:callable}> */
    public static array $tests = [];
    /** @var string[] */
    public static array $scratchDirs = [];
}

function test(string $name, callable $fn): void
{
    Registry::$tests[] = ['name' => $name, 'fn' => $fn];
}

function describe_value(mixed $value): string
{
    $out = var_export($value, true);
    return strlen($out) > 600 ? substr($out, 0, 600) . '…' : $out;
}

function assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(
            ($message !== '' ? $message . "\n" : '') .
            'Expected: ' . describe_value($expected) . "\n" .
            'Actual:   ' . describe_value($actual)
        );
    }
}

function assert_true(mixed $condition, string $message = 'Expected condition to be true.'): void
{
    if ($condition !== true) {
        throw new AssertionFailed($message);
    }
}

function assert_false(mixed $condition, string $message = 'Expected condition to be false.'): void
{
    if ($condition !== false) {
        throw new AssertionFailed($message);
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            ($message !== '' ? $message . "\n" : '') .
            'Expected to find ' . describe_value($needle) . ' in ' . describe_value($haystack)
        );
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        throw new AssertionFailed(
            ($message !== '' ? $message . "\n" : '') .
            'Did not expect ' . describe_value($needle) . ' in ' . describe_value($haystack)
        );
    }
}

/**
 * Assert that $fn throws. Returns the exception so callers can inspect it.
 * $messageContains optionally checks the exception message.
 */
function assert_throws(callable $fn, string $messageContains = '', string $class = Throwable::class): Throwable
{
    try {
        $fn();
    } catch (Throwable $error) {
        if (!$error instanceof $class) {
            throw new AssertionFailed('Expected ' . $class . ', got ' . get_class($error) . ': ' . $error->getMessage());
        }
        if ($messageContains !== '' && !str_contains($error->getMessage(), $messageContains)) {
            throw new AssertionFailed('Exception message ' . describe_value($error->getMessage()) . ' does not contain ' . describe_value($messageContains));
        }
        return $error;
    }

    throw new AssertionFailed('Expected an exception' . ($messageContains !== '' ? ' containing ' . describe_value($messageContains) : '') . ', none was thrown.');
}

/** Call a private/protected method (importer path mapping and similar internals). */
function call_private(object $object, string $method, mixed ...$args): mixed
{
    $reflection = new \ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($object, ...$args);
}

function scratch_dir(string $prefix = 'wysite-test-'): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create scratch dir ' . $dir);
    }
    $dir = (string) realpath($dir);
    Registry::$scratchDirs[] = $dir;
    return $dir;
}

function copy_tree(string $from, string $to): void
{
    if (!is_dir($to)) {
        mkdir($to, 0775, true);
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($items as $item) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        if ($item->isDir()) {
            if (!is_dir($target)) {
                mkdir($target, 0775, true);
            }
        } else {
            copy($item->getPathname(), $target);
        }
    }
}

function remove_tree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/**
 * A throwaway site root: <tmp>/edit with the shipped templates and themes and an
 * empty storage dir. Returns the wired-up services, like bootstrap.php does.
 *
 * @return array<string, mixed>
 */
function make_site(string $siteBaseUrl = '/'): array
{
    $root = scratch_dir();
    $edit = $root . '/edit';
    $source = dirname(__DIR__);

    mkdir($edit . '/storage', 0775, true);
    copy_tree($source . '/templates', $edit . '/templates');
    copy_tree($source . '/themes', $edit . '/themes');
    copy($source . '/storage/config.php', $edit . '/storage/config.php');
    copy($source . '/storage/.htaccess', $edit . '/storage/.htaccess');

    return wire_site($root, $siteBaseUrl);
}

/** @return array<string, mixed> */
function wire_site(string $root, string $siteBaseUrl = '/'): array
{
    $edit = $root . '/edit';
    $repository = new \WYSiteIWYG\BlockRepository($root, $edit);
    $themes = new \WYSiteIWYG\ThemeManager($edit . '/storage/config.php', $edit . '/themes', $edit . '/storage/state.local.php');
    $generator = new \WYSiteIWYG\SiteGenerator($root, $repository, $themes, $siteBaseUrl);
    $revisions = new \WYSiteIWYG\Revisions($root, $edit . '/storage/revisions');
    \WYSiteIWYG\Filesystem::setRevisions($revisions);

    return [
        'root' => $root,
        'edit' => $edit,
        'repository' => $repository,
        'themes' => $themes,
        'generator' => $generator,
        'revisions' => $revisions,
        'backups' => new \WYSiteIWYG\Backups($root, $edit . '/storage/backups'),
    ];
}

function fixture(string $name): string
{
    $path = __DIR__ . '/fixtures/' . $name;
    if (!is_file($path)) {
        throw new RuntimeException('Missing fixture ' . $name);
    }
    return (string) file_get_contents($path);
}

function run_all(?string $filter = null): int
{
    $passed = 0;
    $failed = 0;
    $failures = [];

    foreach (Registry::$tests as $case) {
        if ($filter !== null && stripos($case['name'], $filter) === false) {
            continue;
        }

        $scratchBefore = count(Registry::$scratchDirs);
        try {
            ($case['fn'])();
            $passed++;
            echo '.';
        } catch (Throwable $error) {
            $failed++;
            echo 'F';
            $failures[] = [$case['name'], $error];
        } finally {
            // Tidy scratch roots created by this case.
            foreach (array_splice(Registry::$scratchDirs, $scratchBefore) as $dir) {
                remove_tree($dir);
            }
        }
    }

    echo "\n\n";
    foreach ($failures as [$name, $error]) {
        echo "FAIL: {$name}\n";
        echo '  ' . str_replace("\n", "\n  ", $error->getMessage()) . "\n";
        if (!$error instanceof AssertionFailed) {
            echo '  at ' . $error->getFile() . ':' . $error->getLine() . "\n";
        }
        echo "\n";
    }

    echo $passed . ' passed, ' . $failed . " failed\n";
    return $failed === 0 ? 0 : 1;
}
