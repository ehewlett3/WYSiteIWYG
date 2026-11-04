<?php
declare(strict_types=1);

/**
 * UX-8: WYSiteIWYG from the command line, without web request time limits.
 *
 *   php edit/cli.php import <url> [--max-pages=500] [--overwrite] [--keep-scripts]
 *   php edit/cli.php analyze                 analyse imported pages (Import map)
 *   php edit/cli.php publish-due             publish scheduled drafts (for cron)
 *   php edit/cli.php rescan [--apply]        security re-scan of saved content
 *   php edit/cli.php dedupe [--apply]        merge duplicate imported files
 *   php edit/cli.php rebuild                 re-render every page
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require __DIR__ . '/bootstrap.php';
$args = array_slice($argv, 1);
$command = (string) array_shift($args);
$options = [];
$positional = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? true;
    } else {
        $positional[] = $arg;
    }
}
$out = static function (string $line): void {
    fwrite(STDOUT, $line . PHP_EOL);
};

try {
    switch ($command) {
        case 'import':
            $url = (string) ($positional[0] ?? '');
            if ($url === '') {
                throw new RuntimeException('Usage: php edit/cli.php import <url> [--max-pages=500] [--overwrite] [--keep-scripts]');
            }
            $app['backups']->create('site-import');
            $importer = $app['externalImporter'];
            $result = $importer->importSite($url, (int) ($options['max-pages'] ?? 500), !empty($options['overwrite']), static function (array $event) use ($out): void {
                if (in_array($event['type'] ?? '', ['page_saved', 'page_failed', 'page_duplicate', 'resource_saved'], true)) {
                    $out(str_pad((string) $event['type'], 16) . ' ' . ($event['path'] ?? $event['url'] ?? '') . (isset($event['message']) ? ' — ' . $event['message'] : ''));
                }
            }, isset($options['keep-scripts']) ? true : null);
            $app['generator']->writeMigrationRedirects($importer->queryRedirects());
            $out(sprintf('Saved %d page(s)%s, %d asset(s); %d failed, %d duplicate(s) folded, %d left after the page limit.',
                count($result['saved']), !empty($result['staged']) ? ' to staging (review them in Manager → Import map)' : '',
                count($result['assets_saved']), count($result['failed']), count($result['duplicates']), count($result['limit_skipped'])));
            break;

        case 'analyze':
            $map = (new WYSiteIWYG\ImportMap($app['rootPath'], $app['editPath'], $app['repository'], $app['generator'], $app['externalImporter']))->analyze();
            foreach ($map['clusters'] as $cluster) {
                $out(strtoupper((string) $cluster['id']) . ' ' . $cluster['kind'] . ' (' . count($cluster['pages']) . ' pages)');
                foreach ($cluster['roles'] as $role => $info) {
                    $out('  ' . str_pad((string) $role, 20) . ' ' . $info['selector'] . '  ' . round(100 * (float) ($info['confidence'] ?? 0)) . '%');
                }
            }
            break;

        case 'publish-due':
            $published = $app['generator']->publishDueDrafts();
            $out('Published ' . count($published) . ' scheduled draft(s).' . ($published !== [] ? ' ' . implode(', ', $published) : ''));
            break;

        case 'rescan':
            $apply = !empty($options['apply']);
            if ($apply) {
                $app['backups']->create('rescan');
            }
            $report = $app['rescan']->run($app['externalImporter']->importedFiles(), $apply);
            foreach ($report['blocks'] as $row) {
                $out($row['path'] . ' [' . $row['block'] . ']: ' . implode(', ', $row['removed']));
            }
            foreach (array_merge($report['files'], $report['templates']) as $row) {
                $out($row['path'] . ': ' . implode(', ', $row['removed']));
            }
            if ($apply) {
                $app['generator']->rebuildAllPages();
            }
            $out(WYSiteIWYG\SecurityRescan::count($report) . ' item(s) ' . ($apply ? 'cleaned.' : 'need cleaning (run with --apply).'));
            break;

        case 'dedupe':
            $scan = $app['dedupe']->scan();
            $count = array_sum(array_map(static fn(array $g): int => count($g['duplicates']), $scan['groups']));
            $out($count . ' duplicate file(s), ' . count($scan['pages']) . ' duplicate page(s), ' . round($scan['reclaimable'] / 1048576, 1) . ' MB reclaimable.');
            if (!empty($options['apply'])) {
                $app['backups']->create('dedupe');
                $done = $app['dedupe']->apply($scan);
                $out('Moved ' . $done['files_moved'] . ' file(s) to trash ' . $done['trash'] . ', updated ' . $done['files_rewritten'] . ' file(s), redirected ' . $done['pages_redirected'] . ' page(s).');
            }
            break;

        case 'rebuild':
            $out('Rebuilt ' . $app['generator']->rebuildAllPages() . ' page(s).');
            break;

        default:
            $out((string) preg_replace('/^.*?\/\*\*\n|\*\/.*$/s', '', (string) file_get_contents(__FILE__, false, null, 0, 900)));
            exit($command === '' || $command === 'help' ? 0 : 1);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
