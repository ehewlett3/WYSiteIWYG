<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Optional AI assistance for the theme creator. Given an imported page and the set
 * of template roles (menu, page content, ...), it asks a user-configured model to
 * pick the best-matching element for each role and returns those picks as DOM-path
 * selections. Those pre-fill the manual region designer, which stays the source of
 * truth: the human confirms or overrides every pick before the template is saved.
 *
 * The API key lives in a git-ignored *.local.php file and is never sent to the
 * browser. All model calls go through the shared SSRF-guarded fetch, so a custom
 * endpoint cannot be pointed at an internal address.
 */
final class AiAssistant
{
    private const PROVIDERS = ['anthropic', 'openai'];
    private const MAX_SKELETON_CHARS = 12000;
    private const MAX_SKELETON_NODES = 500;
    private const REQUEST_TIMEOUT_SECONDS = 40;

    private const CLASSIFY_RETRY_SECONDS = 60;

    private string $settingsPath;
    private string $classifyCachePath;

    public function __construct(string $settingsPath)
    {
        $this->settingsPath = $settingsPath;
        $this->classifyCachePath = dirname($settingsPath) . '/ai-classify.local.php';
    }

    /** Full settings incl. the raw key — for internal use only, never rendered. */
    private function settings(): array
    {
        $defaults = [
            'provider' => 'anthropic',
            'model' => '',
            'base_url' => '',
            'api_key' => '',
            'enabled' => false,
        ];

        if (!is_file($this->settingsPath)) {
            return $defaults;
        }

        $data = require $this->settingsPath;
        return is_array($data) ? array_merge($defaults, $data) : $defaults;
    }

    /** Safe view for the dashboard: provider/model/base_url/enabled + has_key flag. */
    public function publicSettings(): array
    {
        $settings = $this->settings();
        return [
            'provider' => $settings['provider'],
            'model' => $settings['model'],
            'base_url' => $settings['base_url'],
            'enabled' => (bool) $settings['enabled'],
            'has_key' => ($settings['api_key'] ?? '') !== '',
        ];
    }

    public function isConfigured(): bool
    {
        $settings = $this->settings();
        return (bool) $settings['enabled']
            && (string) $settings['api_key'] !== ''
            && (string) $settings['model'] !== '';
    }

    /**
     * Persist settings from the dashboard form. A blank api_key preserves the stored
     * key (so the model can be changed without re-entering it); clear_key removes it.
     */
    public function save(array $input): void
    {
        $current = $this->settings();

        $provider = (string) ($input['provider'] ?? $current['provider']);
        if (!in_array($provider, self::PROVIDERS, true)) {
            throw new RuntimeException('Choose a supported AI provider.');
        }

        $model = trim((string) ($input['model'] ?? ''));
        if ($model !== '' && preg_match('/^[A-Za-z0-9._:\-]{1,100}$/', $model) !== 1) {
            throw new RuntimeException('That model name contains unexpected characters.');
        }

        $baseUrl = trim((string) ($input['base_url'] ?? ''));
        if ($baseUrl !== '') {
            $parts = parse_url($baseUrl);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
                throw new RuntimeException('The custom endpoint must be an http(s) URL.');
            }
            $baseUrl = rtrim($baseUrl, '/');
        }

        $apiKey = (string) ($input['api_key'] ?? '');
        if (!empty($input['clear_key'])) {
            $apiKey = '';
        } elseif ($apiKey === '') {
            $apiKey = (string) $current['api_key'];
            if ($apiKey !== '' && ($baseUrl !== (string) $current['base_url'] || $provider !== (string) $current['provider'])) {
                throw new RuntimeException('Re-enter the API key when changing the provider or endpoint (the saved key is only sent where it was saved for).');
            }
        }

        $data = [
            'provider' => $provider,
            'model' => $model,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'enabled' => !empty($input['enabled']),
        ];

        Filesystem::atomicWrite($this->settingsPath, "<?php\nreturn " . var_export($data, true) . ";\n");
    }

    /**
     * List the models available for the configured (or supplied) provider/key, for
     * the settings dropdown. $override lets the dashboard test values typed into the
     * form before they are saved (a blank api_key falls back to the stored key).
     *
     * @return string[] model ids, sorted
     */
    public function listModels(array $override = []): array
    {
        $settings = $this->effective($override);
        if ($settings['api_key'] === '') {
            throw new RuntimeException('Enter an API key first.');
        }

        if ($settings['provider'] === 'anthropic') {
            $endpoint = ($settings['base_url'] !== '' ? $settings['base_url'] : 'https://api.anthropic.com') . '/v1/models?limit=1000';
            $headers = ['x-api-key: ' . $settings['api_key'], 'anthropic-version: 2023-06-01'];
        } else {
            $endpoint = ($settings['base_url'] !== '' ? $settings['base_url'] : 'https://api.openai.com') . '/v1/models';
            $headers = ['authorization: Bearer ' . $settings['api_key']];
        }

        $response = $this->httpGetJson($endpoint, $headers);
        $data = $response['data'] ?? null;
        if (!is_array($data)) {
            throw new RuntimeException($this->apiErrorMessage($response) ?? 'The provider did not return a model list.');
        }

        $ids = [];
        foreach ($data as $model) {
            if (is_array($model) && isset($model['id']) && is_string($model['id'])) {
                $ids[] = $model['id'];
            }
        }
        sort($ids);

        return $ids;
    }

    /**
     * Verify a provider/key configuration by listing its models. Returns the model
     * count on success; throws with the provider's error message on failure.
     */
    public function testConnection(array $override = []): int
    {
        return count($this->listModels($override));
    }

    /**
     * Classify pages into template kinds (home/page/blog/blog-post) in one call, to
     * seed the "Use as template" dropdowns. Best-effort: returns [] on any failure.
     *
     * @param array<int,array{path:string,title:string,outline:string}> $pages
     * @param string[] $allowedKinds
     * @return array<string,string> path => kind
     */
    public function classifyKinds(array $pages, array $allowedKinds): array
    {
        if (!$this->isConfigured() || $pages === []) {
            return [];
        }

        $lines = [];
        foreach ($pages as $index => $page) {
            $lines[] = ($index + 1) . '. path="' . (string) $page['path'] . '"'
                . ' title="' . $this->clip((string) $page['title'], 80) . '"'
                . ' summary="' . $this->clip((string) $page['outline'], 200) . '"';
        }

        $system = 'You classify web pages into template kinds for a static site builder. '
            . 'Allowed kinds: ' . implode(', ', $allowedKinds) . '. '
            . 'home = the site front/landing page; blog = a list or archive of posts; '
            . 'blog-post = a single dated article/post; page = anything else. '
            . 'Respond with ONLY a JSON object mapping each exact path to one allowed kind.';
        $user = "Pages:\n" . implode("\n", $lines);

        try {
            $decoded = $this->extractJsonObject($this->callModel($system, $user));
        } catch (RuntimeException) {
            return [];
        }
        if ($decoded === null) {
            return [];
        }

        $out = [];
        foreach ($decoded as $path => $kind) {
            if (is_string($path) && is_string($kind) && in_array($kind, $allowedKinds, true)) {
                $out[$path] = $kind;
            }
        }

        return $out;
    }

    /**
     * Classify kinds for dashboard "Use as template" defaults, cached by path+mtime
     * so a page is only sent to the model once (until it changes). If there are
     * uncached pages, one batched call fills them — but no more than once per
     * CLASSIFY_RETRY_SECONDS, so a slow or failing provider can't stall repeated
     * dashboard loads.
     *
     * @param array<int,array{path:string,title:string,outline:string,mtime:int}> $candidates
     * @return array<string,string> path => kind
     */
    public function cachedKinds(array $candidates, array $allowedKinds): array
    {
        if (!$this->isConfigured() || $candidates === []) {
            return [];
        }

        $cache = $this->loadClassifyCache();
        $entries = is_array($cache['entries'] ?? null) ? $cache['entries'] : [];
        $lastAttempt = (int) ($cache['attempted_at'] ?? 0);

        $result = [];
        $missing = [];
        foreach ($candidates as $candidate) {
            $key = sha1($candidate['path']) . '|' . (int) $candidate['mtime'];
            if (isset($entries[$key]) && in_array($entries[$key], $allowedKinds, true)) {
                $result[$candidate['path']] = $entries[$key];
            } else {
                $missing[] = $candidate;
            }
        }

        if ($missing !== [] && (time() - $lastAttempt) >= self::CLASSIFY_RETRY_SECONDS) {
            $guesses = $this->classifyKinds(
                array_map(
                    static fn(array $c): array => ['path' => $c['path'], 'title' => $c['title'], 'outline' => $c['outline']],
                    $missing
                ),
                $allowedKinds
            );

            foreach ($missing as $candidate) {
                if (isset($guesses[$candidate['path']])) {
                    $kind = $guesses[$candidate['path']];
                    $result[$candidate['path']] = $kind;
                    $entries[sha1($candidate['path']) . '|' . (int) $candidate['mtime']] = $kind;
                }
            }

            // Cap the cache so it can't grow without bound across many file changes.
            if (count($entries) > 500) {
                $entries = array_slice($entries, -500, null, true);
            }

            $this->saveClassifyCache(['attempted_at' => time(), 'entries' => $entries]);
        }

        return $result;
    }

    private function loadClassifyCache(): array
    {
        if (!is_file($this->classifyCachePath)) {
            return [];
        }
        $data = require $this->classifyCachePath;
        return is_array($data) ? $data : [];
    }

    private function saveClassifyCache(array $data): void
    {
        Filesystem::atomicWrite($this->classifyCachePath, "<?php\nreturn " . var_export($data, true) . ";\n");
    }

    /** Merge form-supplied overrides over stored settings (blank key keeps stored). */
    private function effective(array $override): array
    {
        $stored = $this->settings();
        $provider = in_array($override['provider'] ?? '', self::PROVIDERS, true)
            ? (string) $override['provider']
            : (string) $stored['provider'];
        $model = isset($override['model']) && $override['model'] !== ''
            ? (string) $override['model']
            : (string) $stored['model'];
        $baseUrl = array_key_exists('base_url', $override)
            ? rtrim((string) $override['base_url'], '/')
            : (string) $stored['base_url'];
        $apiKey = isset($override['api_key']) && $override['api_key'] !== ''
            ? (string) $override['api_key']
            : (string) $stored['api_key'];

        // Never send the stored key to an endpoint it wasn't saved for, or anyone
        // with admin access could point it at their own server and capture it.
        $usesStoredKey = !(isset($override['api_key']) && $override['api_key'] !== '');
        if ($usesStoredKey && $apiKey !== '' && ($baseUrl !== (string) $stored['base_url'] || $provider !== (string) $stored['provider'])) {
            throw new RuntimeException('Re-enter the API key to use it with a different provider or endpoint.');
        }

        return ['provider' => $provider, 'model' => $model, 'base_url' => $baseUrl, 'api_key' => $apiKey];
    }

    /**
     * Ask the model to map each template role to an element in the page.
     *
     * @param array $roles templateRoles() entries: [{id,label,required}, ...]
     * @return array<string, array<int, array{tag:string,index:int}>> role id => DOM path
     */
    public function suggestRoles(string $html, string $kind, array $roles): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('AI assistance is not configured.');
        }

        [$skeleton, $refMap] = $this->buildSkeleton($html);
        if ($refMap === []) {
            return [];
        }

        $reply = $this->callModel(
            $this->systemPrompt(),
            $this->userPrompt($kind, $roles, $skeleton)
        );

        $decoded = $this->extractJsonObject($reply);
        if ($decoded === null) {
            throw new RuntimeException('The model did not return a usable JSON mapping.');
        }

        $roleIds = array_map(static fn(array $role): string => (string) $role['id'], $roles);
        $suggestions = [];
        foreach ($decoded as $roleId => $ref) {
            if (!is_string($roleId) || !in_array($roleId, $roleIds, true)) {
                continue;
            }
            $refInt = is_numeric($ref) ? (int) $ref : -1;
            if (!isset($refMap[$refInt])) {
                continue;
            }
            $suggestions[$roleId] = $this->computeDomPath($refMap[$refInt]);
        }

        return $suggestions;
    }

    /**
     * Build a compact, ref-annotated outline of the page's body. Only structure is
     * sent to the model (tag, id, a few classes, aria-role, a short text snippet) —
     * never the full page — to bound tokens and avoid leaking content wholesale.
     *
     * @return array{0:string,1:array<int,DOMElement>}
     */
    private function buildSkeleton(string $html): array
    {
        if (!class_exists(DOMDocument::class) || trim($html) === '') {
            return ['', []];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);
        $root = $body instanceof DOMElement ? $body : $dom->documentElement;
        if (!$root instanceof DOMElement) {
            return ['', []];
        }

        $lines = [];
        $refMap = [];
        $ref = 0;
        $skip = ['script', 'style', 'noscript', 'svg', 'template', 'link', 'meta'];

        $walk = function (DOMElement $element, int $depth) use (&$walk, &$lines, &$refMap, &$ref, $skip): void {
            if ($ref >= self::MAX_SKELETON_NODES) {
                return;
            }

            $tag = strtolower($element->tagName);
            if (in_array($tag, $skip, true)) {
                return;
            }

            $ref++;
            $refMap[$ref] = $element;

            $descriptor = '[' . $ref . '] ' . str_repeat('  ', min($depth, 10)) . $tag;
            $id = trim($element->getAttribute('id'));
            if ($id !== '') {
                $descriptor .= '#' . $this->clip($id, 40);
            }
            $class = trim($element->getAttribute('class'));
            if ($class !== '') {
                $classes = array_slice(preg_split('/\s+/', $class) ?: [], 0, 4);
                $descriptor .= '.' . $this->clip(implode('.', $classes), 60);
            }
            $role = trim($element->getAttribute('role'));
            if ($role !== '') {
                $descriptor .= ' role=' . $this->clip($role, 20);
            }

            $snippet = $this->directText($element);
            if ($snippet !== '') {
                $descriptor .= ' "' . $this->clip($snippet, 50) . '"';
            }

            $lines[] = $descriptor;

            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMElement) {
                    $walk($child, $depth + 1);
                }
            }
        };

        $walk($root, 0);

        $skeleton = implode("\n", $lines);
        if (strlen($skeleton) > self::MAX_SKELETON_CHARS) {
            $skeleton = substr($skeleton, 0, self::MAX_SKELETON_CHARS) . "\n… (outline truncated)";
        }

        return [$skeleton, $refMap];
    }

    /** First run of directly-contained text (not descendant text), collapsed. */
    private function directText(DOMElement $element): string
    {
        $text = '';
        foreach ($element->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $text .= ' ' . $child->textContent;
            }
        }

        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function clip(string $value, int $max): string
    {
        $value = trim($value);
        return strlen($value) > $max ? substr($value, 0, $max) . '…' : $value;
    }

    /** DOM path in the same tag/nth-of-tag form the browser's buildDomPath produces. */
    private function computeDomPath(DOMElement $element): array
    {
        $path = [];
        $current = $element;

        while ($current instanceof DOMElement) {
            $tag = strtolower($current->tagName);
            $index = 1;
            $sibling = $current->previousSibling;
            while ($sibling) {
                if ($sibling instanceof DOMElement && strtolower($sibling->tagName) === $tag) {
                    $index++;
                }
                $sibling = $sibling->previousSibling;
            }

            array_unshift($path, ['tag' => $tag, 'index' => $index]);
            if ($tag === 'html') {
                break;
            }
            $parent = $current->parentNode;
            $current = $parent instanceof DOMElement ? $parent : null;
        }

        return $path;
    }

    private function systemPrompt(): string
    {
        return 'You label regions of an HTML page so it can be turned into a reusable site template. '
            . 'You are given a compact outline of the page where every element is prefixed with a numeric [ref]. '
            . 'For each requested role, choose the single element whose [ref] best fits that role, or null if nothing fits. '
            . 'Prefer the smallest element that fully contains the region (e.g. the <ul>/<nav> for a menu, the main content container for page content). '
            . 'Respond with ONLY a JSON object mapping each role id to a ref number or null. No prose, no code fences.';
    }

    private function userPrompt(string $kind, array $roles, string $skeleton): string
    {
        $roleLines = [];
        foreach ($roles as $role) {
            $roleLines[] = '- "' . (string) $role['id'] . '": ' . (string) $role['label']
                . $this->roleHint((string) $role['id']);
        }

        return 'Template kind: ' . $kind . "\n\n"
            . "Roles to locate:\n" . implode("\n", $roleLines) . "\n\n"
            . "Page outline:\n" . $skeleton . "\n\n"
            . 'Return JSON like {"' . (string) ($roles[0]['id'] ?? 'main-menu') . '": 12, ...}.';
    }

    private function roleHint(string $roleId): string
    {
        return match ($roleId) {
            'main-menu' => ' — the primary site navigation list (usually a <ul> inside a <nav> or header).',
            'page-content' => ' — the main editable content area of the page body.',
            'blog-post-content' => ' — the article body of a single blog post.',
            'blog-index-content' => ' — the intro/heading area above the list of posts on an archive page.',
            'blog-items' => ' — the container that holds the repeating list of post cards/links.',
            default => '',
        };
    }

    private function extractJsonObject(string $reply): ?array
    {
        $reply = trim($reply);
        // Strip code fences if the model added them despite instructions.
        $reply = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $reply) ?? $reply;

        $start = strpos($reply, '{');
        $end = strrpos($reply, '}');
        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $json = substr($reply, $start, $end - $start + 1);
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function callModel(string $systemPrompt, string $userPrompt): string
    {
        $settings = $this->settings();
        $provider = (string) $settings['provider'];
        $model = (string) $settings['model'];
        $apiKey = (string) $settings['api_key'];
        $baseUrl = (string) $settings['base_url'];

        if ($provider === 'anthropic') {
            $endpoint = ($baseUrl !== '' ? $baseUrl : 'https://api.anthropic.com') . '/v1/messages';
            $headers = [
                'content-type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ];
            $payload = [
                'model' => $model,
                'max_tokens' => 1024,
                'temperature' => 0,
                'system' => $systemPrompt,
                'messages' => [['role' => 'user', 'content' => $userPrompt]],
            ];
            $response = $this->httpPostJson($endpoint, $headers, $payload);
            $text = $response['content'][0]['text'] ?? null;
            if (!is_string($text)) {
                throw new RuntimeException($this->apiErrorMessage($response) ?? 'Unexpected response from Anthropic.');
            }
            return $text;
        }

        // openai / openai-compatible
        $endpoint = ($baseUrl !== '' ? $baseUrl : 'https://api.openai.com') . '/v1/chat/completions';
        $headers = [
            'content-type: application/json',
            'authorization: Bearer ' . $apiKey,
        ];
        $payload = [
            'model' => $model,
            'temperature' => 0,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        $response = $this->httpPostJson($endpoint, $headers, $payload);
        $text = $response['choices'][0]['message']['content'] ?? null;
        if (!is_string($text)) {
            throw new RuntimeException($this->apiErrorMessage($response) ?? 'Unexpected response from the OpenAI-compatible endpoint.');
        }
        return $text;
    }

    private function apiErrorMessage(array $response): ?string
    {
        $message = $response['error']['message'] ?? ($response['error'] ?? null);
        return is_string($message) && $message !== '' ? 'AI provider error: ' . $message : null;
    }

    /**
     * POST JSON to a model endpoint through the shared SSRF guard, with automatic
     * redirect following disabled. Returns the decoded JSON response body.
     */
    private function httpPostJson(string $url, array $headers, array $payload): array
    {
        $pin = pin_url($url);
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Unable to encode the AI request.');
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize the AI request.');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'WYSiteIWYG',
                // Connect to the IP the SSRF check validated (no DNS rebinding).
                CURLOPT_RESOLVE => [$pin['resolve']],
            ]);
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($raw === false) {
                throw new RuntimeException('AI request failed: ' . ($error !== '' ? $error : 'no response') . '.');
            }
            return $this->decodeResponse((string) $raw, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", array_merge([$pin['host_header']], $headers)),
                'content' => $body,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => $pin['ssl'],
        ]);
        $raw = @file_get_contents($pin['url'], false, $context);
        if ($raw === false) {
            throw new RuntimeException('AI request failed.');
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $this->decodeResponse((string) $raw, $status);
    }

    /** GET JSON from a provider endpoint through the shared SSRF guard. */
    private function httpGetJson(string $url, array $headers): array
    {
        $pin = pin_url($url);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Unable to initialize the request.');
            }
            curl_setopt_array($ch, [
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_USERAGENT => 'WYSiteIWYG',
                // Connect to the IP the SSRF check validated (no DNS rebinding).
                CURLOPT_RESOLVE => [$pin['resolve']],
            ]);
            $raw = curl_exec($ch);
            $error = curl_error($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($raw === false) {
                throw new RuntimeException('Request failed: ' . ($error !== '' ? $error : 'no response') . '.');
            }
            return $this->decodeResponse((string) $raw, $status);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", array_merge([$pin['host_header']], $headers)),
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => $pin['ssl'],
        ]);
        $raw = @file_get_contents($pin['url'], false, $context);
        if ($raw === false) {
            throw new RuntimeException('Request failed.');
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return $this->decodeResponse((string) $raw, $status);
    }

    private function decodeResponse(string $raw, int $status): array
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('The AI provider returned a non-JSON response (HTTP ' . $status . ').');
        }
        if ($status >= 400) {
            throw new RuntimeException($this->apiErrorMessage($decoded) ?? ('The AI provider returned HTTP ' . $status . '.'));
        }
        return $decoded;
    }
}
