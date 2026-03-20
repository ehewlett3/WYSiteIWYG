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
