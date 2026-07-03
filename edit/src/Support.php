<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

final class Filesystem
{
    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create directory: ' . $path);
        }
    }

    public static function atomicWrite(string $path, string $contents): void
    {
        self::ensureDirectory(dirname($path));
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $bytes = file_put_contents($tmp, $contents, LOCK_EX);

        if ($bytes === false) {
            throw new RuntimeException('Unable to write file: ' . $path);
        }

        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to move temporary file into place: ' . $path);
        }

        @chmod($path, 0644);

        // State (users, config, AI settings, throttle) is persisted as require()d PHP
        // files. Without this, OPcache can keep serving the previously compiled
        // version on the very next request, so a saved setting or theme change would
        // not take effect until the cache revalidates. Invalidate immediately.
        if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }
}

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['wysite_csrf'])) {
            $_SESSION['wysite_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['wysite_csrf'];
    }

    public static function validate(?string $token): bool
    {
        $sessionToken = $_SESSION['wysite_csrf'] ?? '';
        return $token !== null && $sessionToken !== '' && hash_equals($sessionToken, $token);
    }
}

final class Flash
{
    public static function push(string $type, string $message): void
    {
        $_SESSION['wysite_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function consume(): array
    {
        $messages = $_SESSION['wysite_flash'] ?? [];
        unset($_SESSION['wysite_flash']);
        return $messages;
    }
}

final class LoginThrottle
{
    private string $storePath;
    private int $windowSeconds;
    private int $maxAttempts;

    public function __construct(string $storePath, int $windowSeconds = 900, int $maxAttempts = 8)
    {
        $this->storePath = $storePath;
        $this->windowSeconds = $windowSeconds;
        $this->maxAttempts = $maxAttempts;
    }

    public function ensureAllowed(string $username, string $ipAddress): void
    {
        $data = $this->prune($this->load());
        $record = $data[$this->key($username, $ipAddress)] ?? null;
        $attempts = (array) ($record['attempts'] ?? []);

        if (count($attempts) >= $this->maxAttempts) {
            throw new RuntimeException('Too many sign-in attempts. Please wait a few minutes and try again.');
        }
    }

    public function recordFailure(string $username, string $ipAddress): void
    {
        $data = $this->prune($this->load());
        $key = $this->key($username, $ipAddress);
        $record = $data[$key] ?? ['attempts' => []];
        $record['attempts'][] = time();
        $data[$key] = $record;
        $this->save($data);
    }

    public function clear(string $username, string $ipAddress): void
    {
        $data = $this->prune($this->load());
        unset($data[$this->key($username, $ipAddress)]);
        $this->save($data);
    }

    private function key(string $username, string $ipAddress): string
    {
        return sha1(strtolower($username) . '|' . $ipAddress);
    }

    private function prune(array $data): array
    {
        $cutoff = time() - $this->windowSeconds;
        foreach ($data as $key => $record) {
            $attempts = array_values(
                array_filter(
                    (array) ($record['attempts'] ?? []),
                    static fn($timestamp): bool => (int) $timestamp >= $cutoff
                )
            );

            if ($attempts === []) {
                unset($data[$key]);
                continue;
            }

            $data[$key]['attempts'] = $attempts;
        }

        return $data;
    }

    private function load(): array
    {
        if (!is_file($this->storePath)) {
            return [];
        }

        $data = require $this->storePath;
        return is_array($data) ? $data : [];
    }

    private function save(array $data): void
    {
        $payload = "<?php\nreturn " . var_export($data, true) . ";\n";
        Filesystem::atomicWrite($this->storePath, $payload);
    }
}

final class AuthManager
{
    private string $storePath;
    private LoginThrottle $throttle;
    private int $idleTimeout = 28800;

    public function __construct(string $storePath)
    {
        $this->storePath = $storePath;
        $this->throttle = new LoginThrottle(dirname($storePath) . '/login-throttle.local.php');
    }

    public function isInstalled(): bool
    {
        $data = $this->load();
        return !empty($data['users']);
    }

    public function bootstrapAdmin(string $username, string $password): void
    {
        if ($this->isInstalled()) {
            throw new RuntimeException('WYSiteIWYG is already installed.');
        }

        $this->assertUsername($username);
        $this->assertPassword($password);

        $data = [
            'users' => [
                $username => [
                    'password_hash' => $this->hashPassword($password),
                    'is_admin' => true,
                    'created_at' => gmdate('c'),
                ],
            ],
        ];

        $this->save($data);
    }

    public function attempt(string $username, string $password): bool
    {
        $ipAddress = $this->clientIpAddress();
        $this->throttle->ensureAllowed($username, $ipAddress);

        $data = $this->load();
        $user = $data['users'][$username] ?? null;

        if (!$user || !password_verify($password, (string) ($user['password_hash'] ?? ''))) {
            $this->throttle->recordFailure($username, $ipAddress);
            return false;
        }

        if (password_needs_rehash((string) $user['password_hash'], $this->passwordAlgorithm(), $this->passwordOptions())) {
            $data['users'][$username]['password_hash'] = $this->hashPassword($password);
            $this->save($data);
        }

        $this->throttle->clear($username, $ipAddress);
        session_regenerate_id(true);
        $_SESSION['wysite_user'] = $username;
        $_SESSION['wysite_last_active'] = time();
        return true;
    }

    public function currentUser(): ?array
    {
        $username = $_SESSION['wysite_user'] ?? null;
        if (!is_string($username) || $username === '') {
            return null;
        }

        $lastActive = (int) ($_SESSION['wysite_last_active'] ?? 0);
        if ($lastActive > 0 && ($lastActive + $this->idleTimeout) < time()) {
            $this->logout();
            return null;
        }

        $data = $this->load();
        $user = $data['users'][$username] ?? null;
        if (!$user) {
            $this->logout();
            return null;
        }

        $_SESSION['wysite_last_active'] = time();

        return [
            'username' => $username,
            'is_admin' => (bool) ($user['is_admin'] ?? false),
            'created_at' => $user['created_at'] ?? null,
        ];
    }

    public function allUsers(): array
    {
        $users = [];
        foreach (($this->load()['users'] ?? []) as $username => $user) {
            $users[] = [
                'username' => $username,
                'is_admin' => (bool) ($user['is_admin'] ?? false),
                'created_at' => $user['created_at'] ?? null,
            ];
        }

        usort(
            $users,
            static fn(array $left, array $right): int => strcmp($left['username'], $right['username'])
        );

        return $users;
    }

    public function createUser(string $username, string $password, bool $isAdmin = false): void
    {
        $this->assertUsername($username);
        $this->assertPassword($password);

        $data = $this->load();
        if (isset($data['users'][$username])) {
            throw new RuntimeException('That username already exists.');
        }

        $data['users'][$username] = [
            'password_hash' => $this->hashPassword($password),
            'is_admin' => $isAdmin,
            'created_at' => gmdate('c'),
        ];

        $this->save($data);
    }

    public function updatePassword(string $username, string $password): void
    {
        $this->assertPassword($password);
        $data = $this->load();

        if (!isset($data['users'][$username])) {
            throw new RuntimeException('Unknown user account.');
        }

        $data['users'][$username]['password_hash'] = $this->hashPassword($password);
        $this->save($data);
    }

    public function passwordHashSummary(): array
    {
        return [
            'algorithm' => $this->passwordAlgorithmName(),
            'reversible' => false,
        ];
    }

    public function deleteUser(string $username): void
    {
        $data = $this->load();
        if (!isset($data['users'][$username])) {
            throw new RuntimeException('Unknown user account.');
        }

        $adminCount = 0;
        foreach ($data['users'] as $user) {
            if (!empty($user['is_admin'])) {
                $adminCount++;
            }
        }

        if (!empty($data['users'][$username]['is_admin']) && $adminCount < 2) {
            throw new RuntimeException('You must keep at least one administrator account.');
        }

        unset($data['users'][$username]);
        $this->save($data);
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private function load(): array
    {
        if (!is_file($this->storePath)) {
            return ['users' => []];
        }

        $data = require $this->storePath;
        return is_array($data) ? $data : ['users' => []];
    }

    private function save(array $data): void
    {
        $payload = "<?php\nreturn " . var_export($data, true) . ";\n";
        Filesystem::atomicWrite($this->storePath, $payload);
    }

    private function hashPassword(string $password): string
    {
        $hash = password_hash($password, $this->passwordAlgorithm(), $this->passwordOptions());
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Unable to hash the password.');
        }

        return $hash;
    }

    private function passwordAlgorithm()
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
    }

    private function passwordOptions(): array
    {
        if (defined('PASSWORD_ARGON2ID')) {
            return [
                'memory_cost' => 1 << 16,
                'time_cost' => 4,
                'threads' => 1,
            ];
        }

        return ['cost' => 12];
    }

    private function passwordAlgorithmName(): string
    {
        return defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt';
    }

    private function clientIpAddress(): string
    {
        $value = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return is_string($value) && $value !== '' ? $value : 'unknown';
    }

    private function assertUsername(string $username): void
    {
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            throw new RuntimeException('Usernames must be 3-32 characters and use only letters, numbers, dot, dash, or underscore.');
        }
    }

    private function assertPassword(string $password): void
    {
        if (strlen($password) < 10) {
            throw new RuntimeException('Passwords must be at least 10 characters long.');
        }
    }
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function request_data(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    return $_POST;
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

function apply_security_headers(): void
{
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

/**
 * A per-request Content-Security-Policy nonce. The editor's own inline/module
 * scripts carry this nonce so a strict script-src can allow them while blocking
 * any script that arrives with untrusted (imported) HTML.
 */
function csp_nonce(): string
{
    static $nonce = '';
    if ($nonce === '') {
        $nonce = base64_encode(random_bytes(16));
    }

    return $nonce;
}

/**
 * Send a Content-Security-Policy for the current response.
 *
 * - 'dashboard': locked down — scripts only from same-origin files (no inline JS;
 *   the dashboard's confirms and import UI live in dashboard.js). Inline styles are
 *   allowed for the dashboard-theme variables block.
 * - 'preview': the preview/designer render untrusted imported HTML in the app
 *   origin. `strict-dynamic` + a nonce means only the editor's own nonced scripts
 *   (and modules they import) run; any script inside the imported page — inline or
 *   same-origin mirrored — is blocked, so it cannot reach the session/CSRF token.
 */
function send_csp(string $profile): void
{
    $nonce = csp_nonce();

    if ($profile === 'preview') {
        header(
            "Content-Security-Policy: default-src 'self' data: https: http:; " .
            "script-src 'self' 'nonce-{$nonce}' 'strict-dynamic'; " .
            "style-src 'self' 'unsafe-inline' https: http: data:; " .
            "img-src 'self' data: https: http:; " .
            "font-src 'self' data: https: http:; " .
            "media-src 'self' data: https: http:; " .
            "frame-src 'self' https: http: data:; " .
            "object-src 'none'; base-uri 'self'"
        );
        return;
    }

    header(
        "Content-Security-Policy: default-src 'self'; " .
        "script-src 'self'; " .
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
        "img-src 'self' data:; " .
        "font-src 'self' data: https://fonts.gstatic.com; " .
        "connect-src 'self'; " .
        "object-src 'none'; base-uri 'self'; form-action 'self'"
    );
}

/**
 * SSRF guard shared by the site importer and the AI client: refuse a URL whose
 * host resolves to a private, reserved, loopback, or link-local address (this
 * includes cloud metadata endpoints such as 169.254.169.254). A residual
 * DNS-rebinding window remains between this check and the socket connect.
 */
function assert_public_url(string $url): void
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {
        throw new RuntimeException('The URL has no host to fetch.');
    }

    $literal = trim($host, '[]');
    if (filter_var($literal, FILTER_VALIDATE_IP)) {
        if (!is_public_ip($literal)) {
            throw new RuntimeException('Refusing to fetch a private or reserved address (' . $literal . ').');
        }
        return;
    }

    $ips = resolve_host_ips($host);
    if ($ips === []) {
        throw new RuntimeException('Could not resolve host: ' . $host . '.');
    }

    foreach ($ips as $ip) {
        if (!is_public_ip($ip)) {
            throw new RuntimeException('Refusing to fetch ' . $host . ' — it resolves to a private or reserved address (' . $ip . ').');
        }
    }
}

function is_public_ip(string $ip): bool
{
    // Treat an IPv4-mapped IPv6 address (::ffff:1.2.3.4) as its IPv4 form.
    if (stripos($ip, '::ffff:') === 0 && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ip = substr($ip, 7);
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }

    // Belt-and-suspenders for ranges not always covered by the reserved flag:
    // 100.64.0.0/10 (CGNAT) and 0.0.0.0/8.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach ([['100.64.0.0', 10], ['0.0.0.0', 8]] as [$net, $bits]) {
            $mask = -1 << (32 - $bits);
            if (($long & $mask) === (ip2long($net) & $mask)) {
                return false;
            }
        }
    }

    return true;
}

/** @return string[] Resolved A/AAAA addresses for a hostname (cached per request). */
function resolve_host_ips(string $host): array
{
    static $cache = [];
    $key = strtolower($host);
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $ips = [];
    $v4 = @gethostbynamel($host);
    if (is_array($v4)) {
        $ips = array_merge($ips, $v4);
    }

    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ipv6'])) {
                    $ips[] = (string) $record['ipv6'];
                }
            }
        }
    }

    $ips = array_values(array_unique(array_filter($ips, static fn($ip): bool => is_string($ip) && $ip !== '')));
    $cache[$key] = $ips;

    return $ips;
}

/**
 * Neutralize active content in an untrusted HTML document before it is rendered
 * in the app origin: remove <script> elements, strip on* event-handler attributes,
 * drop javascript: URLs, and remove iframe srcdoc. Used as defense-in-depth for the
 * template designer, alongside the strict preview CSP.
 */
function strip_active_content(string $html): string
{
    if (trim($html) === '') {
        return $html;
    }

    if (!class_exists(\DOMDocument::class)) {
        return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
    }

    $dom = new \DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();

    if (!$loaded) {
        return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? $html;
    }

    foreach (iterator_to_array($dom->getElementsByTagName('script')) as $script) {
        $script->parentNode?->removeChild($script);
    }

    $urlAttrs = ['href', 'src', 'action', 'formaction', 'poster', 'data', 'background', 'xlink:href'];
    $xpath = new \DOMXPath($dom);
    foreach ($xpath->query('//*') as $element) {
        if (!$element instanceof \DOMElement || !$element->hasAttributes()) {
            continue;
        }

        $remove = [];
        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            $value = html_entity_decode((string) $attribute->nodeValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (str_starts_with($name, 'on') || $name === 'srcdoc') {
                $remove[] = $attribute->nodeName;
                continue;
            }

            if (in_array($name, $urlAttrs, true) && preg_match('#^\s*javascript:#i', $value) === 1) {
                $remove[] = $attribute->nodeName;
            }
        }

        foreach ($remove as $name) {
            $element->removeAttribute($name);
        }
    }

    $out = $dom->saveHTML() ?: $html;
    return preg_replace('/<\?xml\s+encoding=["\']UTF-8["\']\??>\s*/i', '', $out) ?? $out;
}
