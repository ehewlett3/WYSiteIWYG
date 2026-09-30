<?php
declare(strict_types=1);

use WYSiteIWYG\AiAssistant;
use WYSiteIWYG\ThemeManager;
use function WYSiteIWYG\Tests\assert_contains;
use function WYSiteIWYG\Tests\assert_not_contains;
use function WYSiteIWYG\Tests\assert_same;
use function WYSiteIWYG\Tests\assert_true;
use function WYSiteIWYG\Tests\make_site;
use function WYSiteIWYG\Tests\test;

/** An AiAssistant whose model calls go to $reply(payload) instead of the network. */
function ai_with_transport(array $site, callable $reply, array $settings = [], array &$calls = []): AiAssistant
{
    $ai = new AiAssistant($site['edit'] . '/storage/ai.local.php', static function (string $url, array $headers, array $payload, int $timeout) use ($reply, &$calls): array {
        $calls[] = ['url' => $url, 'payload' => $payload, 'timeout' => $timeout];
        return $reply($payload);
    });
    $ai->save($settings + ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5', 'api_key' => 'sk-test', 'enabled' => true]);
    return $ai;
}

function anthropic_reply(string $text, string $stop = 'end_turn'): array
{
    return ['content' => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => $text]], 'stop_reason' => $stop];
}

test('AI: each task uses its own model, falling back to the default', function (): void {
    $site = make_site();
    $calls = [];
    $ai = ai_with_transport($site, static fn(): array => anthropic_reply('{"/a.html":"blog-post"}'), [
        'task_models' => ['theme' => 'claude-opus-5-5', 'regions' => ''],
    ], $calls);

    assert_same('claude-opus-5-5', $ai->modelFor('theme'));
    assert_same('claude-haiku-4-5', $ai->modelFor('regions'));
    assert_same(['regions' => '', 'classify' => '', 'theme' => 'claude-opus-5-5'], $ai->publicSettings()['task_models']);
    assert_true(!array_key_exists('api_key', $ai->publicSettings()), 'the key never reaches the dashboard');

    assert_same(['/a.html' => 'blog-post'], $ai->classifyKinds([['path' => '/a.html', 'title' => 'A', 'outline' => '']], ['page', 'blog-post']));
    assert_same('claude-haiku-4-5', $calls[0]['payload']['model']);
});

test('AI: requests send no sampling parameters and read text after a thinking block', function (): void {
    $site = make_site();
    $calls = [];
    $ai = ai_with_transport($site, static fn(): array => anthropic_reply('{"main-menu": 4}'), [], $calls);

    $html = '<html><body><header><nav><ul><li><a href="/">Home</a></li></ul></nav></header><main><p>Hi</p></main></body></html>';
    $suggestions = $ai->suggestRoles($html, 'page', [['id' => 'main-menu', 'label' => 'Main menu', 'required' => true]]);

    assert_same(['html', 'body', 'header', 'nav', 'ul'], array_column($suggestions['main-menu'], 'tag'));
    assert_true(!array_key_exists('temperature', $calls[0]['payload']), 'current Claude models reject temperature');
    assert_true($calls[0]['payload']['max_tokens'] >= 4000, 'room for thinking as well as the reply');
});

test('AI: a refusal or truncated reply is reported, not parsed', function (): void {
    $site = make_site();
    foreach (['refusal' => 'declined', 'max_tokens' => 'ran out of output space'] as $stop => $message) {
        $ai = ai_with_transport($site, static fn(): array => anthropic_reply('{"main-menu": 1', $stop));
        try {
            $ai->suggestRoles('<html><body><nav></nav></body></html>', 'page', [['id' => 'main-menu', 'label' => 'Menu', 'required' => true]]);
            throw new RuntimeException('expected an error for ' . $stop);
        } catch (RuntimeException $error) {
            assert_contains($message, $error->getMessage());
        }
    }
});

test('AI: settings reject a blank default when a task has no model of its own', function (): void {
    $site = make_site();
    $ai = new AiAssistant($site['edit'] . '/storage/ai.local.php');
    try {
        $ai->save(['provider' => 'anthropic', 'model' => '', 'task_models' => ['theme' => 'claude-opus-5-5'], 'api_key' => 'k', 'enabled' => true]);
        throw new RuntimeException('expected a validation error');
    } catch (RuntimeException $error) {
        assert_contains('default model', $error->getMessage());
    }
    $ai->save(['provider' => 'anthropic', 'model' => '', 'task_models' => ['regions' => 'a', 'classify' => 'b', 'theme' => 'c'], 'api_key' => 'k', 'enabled' => true]);
    assert_true($ai->isConfigured('theme'));
});

test('AI theme design: templates are validated, repaired once, sanitized, and written', function (): void {
    $site = make_site();
    $good = <<<'HTML'
<!doctype html>
<html lang="{{SITE_LANG}}">
<head>
<meta charset="utf-8">
<!-- WYSITE:META title="{{TITLE}}" kind="page" excerpt="{{EXCERPT}}" -->
<title>{{TITLE}} | {{SITE_NAME}}</title>
<link rel="stylesheet" href="{{THEME_CSS_HREF}}">
<link rel="stylesheet" href="https://evil.example/x.css">
{{HEAD_META}}
</head>
<body class="{{BODY_CLASS}}">
<header><a href="/">{{SITE_NAME}}</a><nav>
<!-- WYSITE:BEGIN name="main-menu" type="menu" label="Main Menu" -->
{{MAIN_MENU}}
<!-- WYSITE:END name="main-menu" -->
</nav></header>
<main>{{PAGE_CONTENT_BLOCKS}}</main>
<script>alert(1)</script>
{{WYSITE_PUBLIC_BRIDGE}}
</body>
</html>
HTML;
    $broken = str_replace('{{PAGE_CONTENT_BLOCKS}}', '', $good);
    $meta = '{"description":"Calm.","preview_blurb":"Navy and gold.","dashboard":{"bg":"#fff","surface":"#fff","ink":"#111","accent":"#123","muted":"red;}body{x:1"},"variables":[{"name":"accent","label":"Accent","type":"color","default":"#123456"},{"name":"unused","type":"color","default":"#000"}]}';
    $css = "@import url(\"https://fonts.googleapis.com/css2?family=Lora:wght@400;700&display=swap\");\n@import url(https://evil.example/a.css);\n:root{--accent:#123456}\nbody{color:var(--accent);background:url(https://evil.example/bg.png)}";

    $calls = [];
    $ai = ai_with_transport($site, static function (array $payload) use ($broken, $good, $meta, $css): array {
        $isRepair = str_contains(json_encode($payload), 'failed validation');
        return anthropic_reply($isRepair
            ? "===== PAGE.HTML =====\n" . $good
            : "===== THEME.JSON =====\n" . $meta . "\n===== SITE.CSS =====\n" . $css . "\n===== PAGE.HTML =====\n```html\n" . $broken . "\n```");
    }, ['task_models' => ['theme' => 'claude-opus-5-5']], $calls);

    $themes = $site['themes'];
    $design = $ai->generateTheme(
        ['name' => 'Harbour Light', 'brief' => 'Navy and gold', 'with_home' => false],
        [['url' => 'https://example.org/', 'html' => '<html><body><nav class="menu">x</nav></body></html>', 'css' => 'body{color:red} /* c */ .menu{display:flex}']],
        [['media_type' => 'image/png', 'data' => 'iVBORw0KGgo=']],
        static fn(string $kind, string $html): array => $themes->templateErrors($kind, ThemeManager::sanitizeGeneratedTemplate($html))
    );

    assert_same(2, count($calls), 'one design call and one repair call');
    assert_same('claude-opus-5-5', $calls[0]['payload']['model']);
    assert_true($calls[0]['timeout'] > 60, 'theme design gets a long timeout');
    assert_same('image', $calls[0]['payload']['messages'][0]['content'][2]['type']);
    assert_contains('.menu{display:flex}', json_encode($calls[0]['payload'], JSON_UNESCAPED_SLASHES));

    $id = $themes->createGeneratedTheme('Harbour Light', $design['meta'], $design['css'], $design['templates']);
    $dir = $site['edit'] . '/themes/' . $id;
    $page = (string) file_get_contents($dir . '/page.html');
    $siteCss = (string) file_get_contents($dir . '/site.css');
    $manifest = json_decode((string) file_get_contents($dir . '/theme.json'), true);

    assert_not_contains('<script', $page);
    assert_not_contains('evil.example', $page);
    assert_contains('{{THEME_CSS_HREF}}', $page);
    assert_contains('fonts.googleapis.com/css2?family=Lora:wght@400;700', $siteCss);
    assert_not_contains('evil.example', $siteCss);
    assert_same(['accent'], array_column($manifest['variables'], 'name'), 'only variables the CSS uses');
    assert_true(!isset($manifest['dashboard']['muted']), 'unsafe dashboard values are dropped');
    assert_contains('<body class="{{BODY_CLASS}}">', $page, 'sanitizing must not lose body attributes');
    $themes->assertThemeIsValid($id);

    $site['generator']->applyTheme($id);
    assert_contains('family=Lora', (string) file_get_contents($site['root'] . '/assets/site.css'));
});

test('AI theme design: an invalid generated theme leaves nothing behind', function (): void {
    $site = make_site();
    try {
        $site['themes']->createGeneratedTheme('Broken', [], 'body{}', ['page' => '<html><body>no tokens</body></html>']);
        throw new RuntimeException('expected validation to fail');
    } catch (RuntimeException $error) {
        assert_contains('is invalid', $error->getMessage());
    }
    assert_true(!is_dir($site['edit'] . '/themes/broken'));
});

test('AI: the CSS digest leads with landmark rules and drops comments and data URIs', function (): void {
    $css = "/* x */ .zzz{a:b} body{margin:0;background:url(data:image/png;base64,AAAA)} @media (min-width:1px){ nav a{color:red} }";
    $digest = AiAssistant::cssDigest($css, 1000);
    assert_true(strpos($digest, 'body{') < strpos($digest, '.zzz{'));
    assert_contains('nav a{color:red}', $digest);
    assert_not_contains('/* x */', $digest);
    assert_not_contains('AAAA', $digest);
});
