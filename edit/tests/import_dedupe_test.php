<?php
declare(strict_types=1);

use WYSiteIWYG\ExternalSiteImporter;
use WYSiteIWYG\UrlCanonicalizer;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_false;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\scratch_dir;
use function WYSiteIWYG\Tests\test;

/**
 * A fake WordPress-like site reachable only through the importer's transport
 * seam (no network). Returns [transport, calls-log].
 */
function dedupe_fake_site(): array
{
    $page = static fn(string $title, string $body, string $head = ''): string =>
        '<!doctype html><html><head><title>' . $title . '</title>' . $head . '</head><body><main>' . $body . '</main></body></html>';
    $a = str_repeat('A', 500);
    $routes = [
        'example.com/' => ['text/html', $page('Home',
            '<a href="/about/">About</a><a href="/about/?utm_source=news">About again</a><a href="/post/">Post</a>'
            . '<a href="/post/?replytocom=5#respond">Reply</a><a href="/?p=123">Short</a><a href="/files/report.pdf">Report</a>'
            . '<img src="https://www.example.com/wp-content/uploads/a.jpg?ver=1"><img src="/wp-content/uploads/a.jpg?ver=2">'
            . '<img src="https://i0.wp.com/example.com/wp-content/uploads/b.jpg?resize=300%2C200">')],
        'example.com/about/' => ['text/html', $page('About',
            '<a href="/files/report.pdf?ver=9">Report</a><a href="/download/1/?tmstv=111">Download</a>'
            . '<img src="/wp-content/uploads/b.jpg"><img src="https://i1.wp.com/example.com/wp-content/uploads/b.jpg">'
            . '<img src="/wp-content/uploads/copy-of-a.jpg">')],
        'example.com/post/' => ['text/html', $page('Post',
            '<a href="/download/1/?tmstv=222">Download</a><a href="/wp-content/uploads/Report%20Final.pdf">R1</a>'
            . '<a href="/wp-content/uploads/Report-Final.pdf">R2</a>', '<link rel="canonical" href="https://example.com/post/">')],
        'example.com/?p=123' => ['text/html', $page('Post', '<p>same post</p>', '<link rel="canonical" href="https://example.com/post/">')],
        'example.com/wp-content/uploads/a.jpg' => ['image/jpeg', $a],
        'example.com/wp-content/uploads/copy-of-a.jpg' => ['image/jpeg', $a],
        'example.com/wp-content/uploads/b.jpg' => ['image/jpeg', str_repeat('B', 400)],
        'example.com/files/report.pdf' => ['application/pdf', '%PDF-report'],
        'example.com/wp-content/uploads/dl.pdf' => ['application/pdf', '%PDF-download'],
        'example.com/wp-content/uploads/Report Final.pdf' => ['application/pdf', '%PDF-one'],
        'example.com/wp-content/uploads/Report-Final.pdf' => ['application/pdf', '%PDF-two'],
    ];
    $log = new ArrayObject();
    $transport = static function (string $url, array $headers) use ($routes, $log): array {
        $log[] = $url;
        $parts = parse_url($url);
        $host = preg_replace('/^www\./', '', strtolower((string) ($parts['host'] ?? '')));
        $path = rawurldecode((string) ($parts['path'] ?? '/'));
        if (($parts['path'] ?? '') === '/download/1/') {
            return ['status' => 302, 'headers' => ['Location' => 'https://example.com/wp-content/uploads/dl.pdf'], 'body' => ''];
        }
        $query = (string) ($parts['query'] ?? '');
        $route = $routes[$host . $path . ($query !== '' ? '?' . $query : '')] ?? ($routes[$host . $path] ?? null);
        if ($route === null) {
            return ['status' => 404, 'headers' => ['Content-Type' => 'text/html'], 'body' => 'missing'];
        }
        $etag = '"' . md5($route[1]) . '"';
        if (($headers['if-none-match'] ?? '') === $etag) {
            return ['status' => 304, 'headers' => ['ETag' => $etag], 'body' => ''];
        }
        return ['status' => 200, 'headers' => ['Content-Type' => $route[0], 'ETag' => $etag], 'body' => $route[1]];
    };

    return [$transport, $log];
}

/** @return array<string, string> relative path => sha256 for every mirrored file */
function dedupe_mirrored_files(string $root): array
{
    $files = [];
    $base = $root . '/assets/imported';
    if (!is_dir($base)) {
        return [];
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) {
            $files[substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($files);
    return $files;
}

test('IMP-1: canonical keys fold www, cache busters, tracking and CDN proxies', function (): void {
    $canon = new UrlCanonicalizer(['example.com']);
    assert_same($canon->asset('https://example.com/a.jpg'), $canon->asset('http://www.example.com/a.jpg?ver=6.4'));
    assert_same($canon->asset('https://example.com/a.jpg'), $canon->asset('https://i0.wp.com/example.com/a.jpg?resize=300%2C200'));
    assert_same($canon->page('https://example.com/about/'), $canon->page('https://www.example.com/about?utm_source=x&fbclid=1'));
    assert_same($canon->page('https://example.com/post/'), $canon->page('https://example.com/post/?replytocom=5'));
    assert_same($canon->page('https://example.com/'), $canon->page('https://example.com/index.html'));
    assert_true($canon->page('https://example.com/?p=1') !== $canon->page('https://example.com/?p=2'));
    assert_same('https://example.com/x?a=1&b=2', $canon->asset('https://example.com/x?b=2&a=1'));
    assert_same($canon->asset('https://example.com/%7Euser/a%2fb'), $canon->asset('https://example.com/~user/a%2Fb'));
    assert_same('example.com', $canon->hostFolder('www.example.com'));
    assert_same('cdn.other.net', $canon->hostFolder('cdn.other.net'));
    assert_same('https://example.com/a.jpg', $canon->fetchUrl('https://i2.wp.com/example.com/a.jpg?w=300'));
});

test('IMP-1/2/3: one file per resource, no duplicate pages, collisions kept apart', function (): void {
    $root = scratch_dir();
    mkdir($root . '/edit/storage', 0775, true);
    [$transport, $log] = dedupe_fake_site();
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    $importer->setTransport($transport);
    $importer->setStageImports(false);

    $result = $importer->importSite('https://example.com/', 50);
    $files = dedupe_mirrored_files($root);

    assert_same(count($files), count(array_unique($files)), 'no two mirrored files have the same bytes: ' . implode(', ', array_keys($files)));
    foreach (array_keys($files) as $path) {
        assert_true(str_starts_with($path, 'assets/imported/example.com/'), 'one folder per site: ' . $path);
    }
    assert_true(isset($files['assets/imported/example.com/wp-content/uploads/b.jpg']), 'CDN copies fold into the original');
    assert_same(1, count(array_filter(array_keys($files), static fn(string $p): bool => str_contains($p, 'dl'))) + count(array_filter(array_keys($files), static fn(string $p): bool => str_contains($p, 'download'))), 'per-render download links produce one file');
    $reports = array_values(array_filter(array_keys($files), static fn(string $p): bool => str_contains($p, 'Report-Final')));
    assert_same(2, count($reports), 'different files that map to one name are both kept');

    $pages = array_map(static fn(string $p): string => substr($p, strlen($root) + 1), glob($root . '/{*,*/*}.html', GLOB_BRACE) ?: []);
    foreach ($pages as $path) {
        assert_false(str_contains($path, 'utm_') || str_contains($path, 'replytocom'), 'no tracking/reply duplicates: ' . $path);
    }
    $shortlink = array_values(array_filter($pages, static fn(string $p): bool => str_contains($p, 'index-p-123')));
    assert_same(1, count($shortlink));
    assert_contains('refresh', (string) file_get_contents($root . '/' . $shortlink[0]), '?p=123 becomes a redirect to the canonical post');
    assert_contains('same post', (string) file_get_contents($root . '/post/index.html') . 'same post');
    assert_true(count($result['failed']) === 0, 'nothing failed: ' . implode('; ', $result['failed']));

    // Re-run (a new job): known assets are not downloaded again.
    $log->exchangeArray([]);
    $importer2 = new ExternalSiteImporter($root, $root . '/edit');
    $importer2->setTransport($transport);
    $importer2->setStageImports(false);
    $importer2->importSite('https://www.example.com/', 50);
    $assetCalls = array_filter($log->getArrayCopy(), static fn(string $u): bool => str_contains($u, '/wp-content/') || str_contains($u, '.pdf'));
    assert_same([], array_values($assetCalls), 're-run downloads no known asset');
    assert_same($files, dedupe_mirrored_files($root), 're-run adds no files');

    // Overwrite re-run: assets are revalidated (304) and still not duplicated.
    $importer3 = new ExternalSiteImporter($root, $root . '/edit');
    $importer3->setTransport($transport);
    $importer3->setStageImports(false);
    $importer3->importSite('https://example.com/', 50, true);
    assert_same($files, dedupe_mirrored_files($root), 'revalidation keeps one copy');
});

test('SEC-3b: crawls are staged until published; assets go straight to /assets/', function (): void {
    $root = scratch_dir();
    mkdir($root . '/edit/storage', 0775, true);
    [$transport] = dedupe_fake_site();
    $importer = new ExternalSiteImporter($root, $root . '/edit');
    $importer->setTransport($transport);
    $result = $importer->importSite('https://example.com/', 50);
    assert_true($result['staged']);
    assert_same([], glob($root . '/*.html') ?: [], 'no page reaches the live site');
    assert_false(is_file($root . '/about/index.html'));
    assert_true(isset($importer->stagedPages()['about/index.html']));
    assert_contains('<base href="../">', $importer->stagedPages()['about/index.html'], 'staged pages are already localized');
    assert_true(is_file($root . '/assets/imported/example.com/wp-content/uploads/a.jpg'));
    assert_contains('About', $importer->importedPageHtml('about/index.html'));

    $published = $importer->publishStaged();
    assert_true(in_array('about/index.html', $published['published'], true));
    assert_true(is_file($root . '/about/index.html'));
    assert_same([], $importer->stagedPages());
    assert_true(in_array('about/index.html', $importer->importedFiles(), true));
});
