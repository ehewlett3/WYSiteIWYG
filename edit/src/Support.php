<?php
declare(strict_types=1);

namespace WYSiteIWYG;

use RuntimeException;

final class Filesystem
{
    /** Absolute site root; user-facing errors show paths relative to it. */
    private static string $displayRoot = '';

    private static ?Revisions $revisions = null;

    /** Bumped on every write/delete so per-request caches (listPages) can invalidate. */
    private static int $generation = 0;

    public static function generation(): int
    {
        return self::$generation;
    }

    /** Enable revision snapshots for writeSitePage() (set in bootstrap). */
    public static function setRevisions(?Revisions $revisions): void
    {
        self::$revisions = $revisions;
    }

    /**
     * Write a site page or active template, first copying the previous version
     * into revision history. Use this (not atomicWrite) for any content a user
     * could want back.
     */
    public static function writeSitePage(string $path, string $contents): void
    {
        self::$revisions?->snapshot($path, $contents);
        self::atomicWrite($path, $contents);
    }

    /** Delete a site page or template, keeping its last version in history. */
    public static function deleteSitePage(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }

        self::$revisions?->snapshot($path);
        self::$generation++;
        return @unlink($path);
    }

    public static function setDisplayRoot(string $rootPath): void
    {
        self::$displayRoot = rtrim(str_replace('\\', '/', $rootPath), '/');
    }

    /** A path as users should see it: root-relative, never the absolute server path. */
    public static function displayPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (self::$displayRoot !== '' && str_starts_with($path, self::$displayRoot . '/')) {
            return substr($path, strlen(self::$displayRoot) + 1);
        }

        return basename($path);
    }

    /** Throw a user-safe error and log the full path for the operator. */
    private static function fail(string $message, string $path): never
    {
        error_log('WYSiteIWYG: ' . $message . ' ' . $path);
        throw new RuntimeException($message . ' ' . self::displayPath($path));
    }

    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0775, true) && !is_dir($path)) {
            self::fail('Unable to create directory:', $path);
        }
    }

    /**
     * Write a file atomically (temp file + rename). $mode defaults to 0600 for
     * per-install *.local.php state (credentials, keys, tokens) and 0644 for
     * everything else; it is applied to the temp file before the rename so the
     * final file is never briefly world-readable.
     */
    public static function atomicWrite(string $path, string $contents, ?int $mode = null): void
    {
        $mode ??= str_ends_with($path, '.local.php') ? 0600 : 0644;

        self::ensureDirectory(dirname($path));
        $tmp = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $bytes = @file_put_contents($tmp, $contents, LOCK_EX);

        if ($bytes === false) {
            @unlink($tmp);
            self::fail('Unable to write file:', $path);
        }

        @chmod($tmp, $mode);
        self::$generation++;

        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            self::fail('Unable to move temporary file into place:', $path);
        }

        // State (users, config, AI settings, throttle) is persisted as require()d PHP
        // files. Without this, OPcache can keep serving the previously compiled
        // version on the very next request, so a saved setting or theme change would
        // not take effect until the cache revalidates. Invalidate immediately.
        if (str_ends_with($path, '.php') && function_exists('opcache_invalidate')) {
            @opcache_invalidate($path, true);
        }
    }

    /**
     * Run a read-modify-write of $path under an exclusive flock() on a sibling
     * .lock file, so two concurrent requests can't lose each other's update.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function withLock(string $path, callable $fn): mixed
    {
        self::ensureDirectory(dirname($path));
        $handle = @fopen($path . '.lock', 'c');
        if ($handle === false) {
            // Locking is best-effort: an unwritable lock file must not block saves.
            return $fn();
        }

        try {
            flock($handle, LOCK_EX);
            return $fn();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}

/**
 * Which file types may be written into the public web root by the importer and
 * uploads. Anything outside the allowlist is neutralized with a trailing .txt so
 * a hostile or compromised source site can never plant server-executable files.
 */
final class AssetPolicy
{
    private const ALLOWED_EXTENSIONS = [
        // images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'tif', 'tiff', 'heic',
        // fonts
        'woff', 'woff2', 'ttf', 'otf', 'eot',
        // styles and scripts
        'css', 'js', 'mjs', 'map',
        // audio and video
        'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'wav', 'flac', 'mp4', 'm4v', 'mov', 'webm', 'ogv', 'vtt', 'srt',
        // documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'csv', 'epub', 'md',
        // archives
        'zip', 'gz', 'tgz', 'tar', '7z', 'rar',
        // feeds and data
        'xml', 'json', 'txt', 'rss', 'atom',
    ];

    private const DENIED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'inc',
        'shtml', 'cgi', 'pl', 'py', 'rb', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'htpasswd', 'ini',
    ];

    public const ASSETS_HTACCESS = <<<'APACHE'
# Written by WYSiteIWYG. Mirrored and uploaded files are data, never code.
<FilesMatch "\.(?i:php\d?|phtml|pht|phar|phps|inc|shtml|cgi|pl|py|rb|sh|asp|aspx|jsp)$">
  Require all denied
</FilesMatch>
<IfModule mod_php.c>
  php_flag engine off
</IfModule>
<IfModule mod_php7.c>
  php_flag engine off
</IfModule>
<IfModule mod_php8.c>
  php_flag engine off
</IfModule>
Options -ExecCGI
<IfModule mod_headers.c>
  <FilesMatch "\.(?i:svg|html?|xml)$">
    Header set Content-Security-Policy "script-src 'none'; object-src 'none'"
  </FilesMatch>
</IfModule>

APACHE;

    public static function isAllowedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * Make a single filename safe to publish: no leading dots, no executable
     * extension anywhere in the name (shell.php.png becomes shell-php.png), and a
     * final extension from the allowlist (otherwise ".txt" is appended).
     */
    public static function safeFilename(string $filename): string
    {
        $filename = ltrim($filename, '.');
        if ($filename === '') {
            return 'file.txt';
        }

        $parts = explode('.', $filename);
        $extension = count($parts) > 1 ? strtolower((string) array_pop($parts)) : '';
        $stem = '';
        foreach ($parts as $index => $part) {
            if ($index === 0) {
                $stem = $part;
                continue;
            }
            $stem .= (in_array(strtolower($part), self::DENIED_EXTENSIONS, true) ? '-' : '.') . $part;
        }

        if ($extension === '') {
            return $stem . '.txt';
        }

        // A disallowed extension is folded into the stem rather than kept before
        // .txt: Apache's AddHandler matches any extension in a name, so
        // shell.php.txt could still run as PHP; shell-php.txt cannot.
        return self::isAllowedExtension($extension)
            ? $stem . '.' . $extension
            : $stem . '-' . $extension . '.txt';
    }

    /** Apply safeFilename() to the last segment of a root-relative path. */
    public static function safeRelativePath(string $relativePath): string
    {
        $segments = explode('/', $relativePath);
        $last = array_pop($segments);
        $segments[] = self::safeFilename((string) $last);
        return implode('/', $segments);
    }

    /** Create <root>/assets/.htaccess (PHP off, scripts blocked in SVG/HTML) if missing. */
    public static function ensureAssetsHtaccess(string $rootPath): void
    {
        $path = rtrim($rootPath, '/') . '/assets/.htaccess';
        if (is_file($path)) {
            return;
        }

        Filesystem::atomicWrite($path, self::ASSETS_HTACCESS);
    }
}

/**
 * First-run setup token. Until an administrator exists, anyone who can reach
 * /edit/ could otherwise claim the site; the install form instead requires a
 * random token written to edit/storage/install-token.local.php (or supplied via
 * the WYSITE_INSTALL_TOKEN environment variable). Reading it proves filesystem
 * access to the server.
 */
final class InstallToken
{
    public const RELATIVE_PATH = 'edit/storage/install-token.local.php';

    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** Create the token file if it doesn't exist yet (no-op when the env var is set). */
    public function ensure(): void
    {
        if ($this->environmentToken() !== '' || $this->storedToken() !== '') {
            return;
        }

        $token = bin2hex(random_bytes(16));
        $payload = "<?php\n// WYSiteIWYG first-run setup token. Enter it on the install page;\n" .
            "// this file is deleted once the first administrator is created.\nreturn " . var_export($token, true) . ";\n";
        Filesystem::atomicWrite($this->path, $payload, 0600);
    }

    public function verify(string $given): bool
    {
        $given = trim($given);
        if ($given === '') {
            return false;
        }

        foreach ([$this->environmentToken(), $this->storedToken()] as $expected) {
            if ($expected !== '' && hash_equals($expected, $given)) {
                return true;
            }
        }

        return false;
    }

    public function usesEnvironment(): bool
    {
        return $this->environmentToken() !== '';
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private function environmentToken(): string
    {
        $value = getenv('WYSITE_INSTALL_TOKEN');
        return is_string($value) ? trim($value) : '';
    }

    private function storedToken(): string
    {
        if (!is_file($this->path)) {
            return '';
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($this->path, true);
        }
        $value = require $this->path;
        return is_string($value) ? trim($value) : '';
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
    /** Hard cap on stored buckets so junk usernames can't grow the file forever. */
    private const MAX_KEYS = 2000;

    private string $storePath;
    private int $windowSeconds;
    private int $maxAttempts;
    private int $maxAttemptsPerIp;

    public function __construct(string $storePath, int $windowSeconds = 900, int $maxAttempts = 8, int $maxAttemptsPerIp = 30)
    {
        $this->storePath = $storePath;
        $this->windowSeconds = $windowSeconds;
        $this->maxAttempts = $maxAttempts;
        $this->maxAttemptsPerIp = $maxAttemptsPerIp;
    }

    public function ensureAllowed(string $username, string $ipAddress): void
    {
        $data = $this->prune($this->load());
        $userAttempts = (array) ($data[$this->key($username, $ipAddress)]['attempts'] ?? []);
        $ipAttempts = (array) ($data[$this->ipKey($ipAddress)]['attempts'] ?? []);

        // Two buckets: one per username+IP (a targeted guess) and one per IP
        // (spraying many usernames from one address).
        if (count($userAttempts) >= $this->maxAttempts || count($ipAttempts) >= $this->maxAttemptsPerIp) {
            throw new RuntimeException('Too many sign-in attempts. Please wait a few minutes and try again.');
        }
    }

    public function recordFailure(string $username, string $ipAddress): void
    {
        Filesystem::withLock($this->storePath, function () use ($username, $ipAddress): void {
            $data = $this->prune($this->load());
            $now = time();
            foreach ([$this->key($username, $ipAddress), $this->ipKey($ipAddress)] as $key) {
                $record = $data[$key] ?? ['attempts' => []];
                $record['attempts'][] = $now;
                $data[$key] = $record;
            }
            $this->save($this->cap($data));
        });
    }

    public function clear(string $username, string $ipAddress): void
    {
        Filesystem::withLock($this->storePath, function () use ($username, $ipAddress): void {
            $data = $this->prune($this->load());
            unset($data[$this->key($username, $ipAddress)]);
            $this->save($data);
        });
    }

    private function key(string $username, string $ipAddress): string
    {
        return sha1(strtolower($username) . '|' . $ipAddress);
    }

    private function ipKey(string $ipAddress): string
    {
        return sha1('ip|' . $ipAddress);
    }

    /** Keep only the most recently active buckets once the cap is exceeded. */
    private function cap(array $data): array
    {
        if (count($data) <= self::MAX_KEYS) {
            return $data;
        }

        uasort(
            $data,
            static fn(array $left, array $right): int => max((array) ($right['attempts'] ?? [0])) <=> max((array) ($left['attempts'] ?? [0]))
        );

        return array_slice($data, 0, self::MAX_KEYS, true);
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
    /**
     * Hashes of a throwaway password with the same cost parameters as real ones.
     * Verifying against one when the username is unknown makes a miss take as long
     * as a wrong password, so response time doesn't reveal which usernames exist.
     */
    private const DUMMY_ARGON2ID_HASH = '$argon2id$v=19$m=65536,t=4,p=1$MXY5cnJuaHdWVXU2TVR0bA$kCBYf4NtGTWxR5EtLUpftcoWhC/BsyEfF1dJJ7aDqig';
    private const DUMMY_BCRYPT_HASH = '$2y$12$2Y4//kjE4hfXSZmEvMKk/OrE4/5Mw3yUWD5M2JZ/oMPl3lUWsJju6';

    /** Non-secret "an editor may be signed in" hint read by public pages. */
    public const HINT_COOKIE = 'wysite_editor';

    private string $storePath;
    private LoginThrottle $throttle;
    private int $idleTimeout = 28800;
    private ?string $hintCookiePath = null;
    private bool $hintCookieSecure = false;

    /**
     * Enable the editor hint cookie. It is deliberately readable by JavaScript and
     * carries no credential: it only tells a public page whether it's worth asking
     * /edit/ for the session status, so ordinary visitors never touch PHP or get a
     * session cookie.
     */
    public function setHintCookie(string $path, bool $secure): void
    {
        $this->hintCookiePath = $path;
        $this->hintCookieSecure = $secure;
    }

    private function sendHintCookie(bool $signedIn): void
    {
        if ($this->hintCookiePath === null || headers_sent()) {
            return;
        }

        setcookie(self::HINT_COOKIE, $signedIn ? '1' : '', [
            'expires' => $signedIn ? 0 : time() - 42000,
            'path' => $this->hintCookiePath,
            'secure' => $this->hintCookieSecure,
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }

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

        $hash = $this->hashPassword($password);
        $this->mutate(static function (array $data) use ($username, $hash): array {
            if (!empty($data['users'])) {
                throw new RuntimeException('WYSiteIWYG is already installed.');
            }

            $data['users'] = [
                $username => [
                    'password_hash' => $hash,
                    'is_admin' => true,
                    'created_at' => gmdate('c'),
                ],
            ];

            return $data;
        });
    }

    public function attempt(string $username, string $password): bool
    {
        $ipAddress = $this->clientIpAddress();
        $this->throttle->ensureAllowed($username, $ipAddress);

        if (!$this->verifyPassword($username, $password)) {
            $this->throttle->recordFailure($username, $ipAddress);
            return false;
        }

        $user = $this->load()['users'][$username];
        if (password_needs_rehash((string) $user['password_hash'], $this->passwordAlgorithm(), $this->passwordOptions())) {
            $hash = $this->hashPassword($password);
            $this->mutate(static function (array $data) use ($username, $hash): array {
                if (isset($data['users'][$username])) {
                    $data['users'][$username]['password_hash'] = $hash;
                }
                return $data;
            });
        }

        $this->throttle->clear($username, $ipAddress);
        session_regenerate_id(true);
        $_SESSION['wysite_user'] = $username;
        $_SESSION['wysite_last_active'] = time();
        $this->sendHintCookie(true);
        return true;
    }

    /**
     * Check a password without signing in (re-authentication before sensitive
     * account changes). Unknown users are verified against a dummy hash so the
     * timing matches a wrong password.
     */
    public function verifyPassword(string $username, string $password): bool
    {
        $user = $this->load()['users'][$username] ?? null;
        if (!is_array($user)) {
            password_verify($password, defined('PASSWORD_ARGON2ID') ? self::DUMMY_ARGON2ID_HASH : self::DUMMY_BCRYPT_HASH);
            return false;
        }

        return password_verify($password, (string) ($user['password_hash'] ?? ''));
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

        $hash = $this->hashPassword($password);
        $this->mutate(static function (array $data) use ($username, $hash, $isAdmin): array {
            if (isset($data['users'][$username])) {
                throw new RuntimeException('That username already exists.');
            }

            $data['users'][$username] = [
                'password_hash' => $hash,
                'is_admin' => $isAdmin,
                'created_at' => gmdate('c'),
            ];

            return $data;
        });
    }

    public function updatePassword(string $username, string $password): void
    {
        $this->assertPassword($password);
        $hash = $this->hashPassword($password);

        $this->mutate(static function (array $data) use ($username, $hash): array {
            if (!isset($data['users'][$username])) {
                throw new RuntimeException('Unknown user account.');
            }

            $data['users'][$username]['password_hash'] = $hash;
            return $data;
        });
    }

    /** Self-service password change: the current password must be supplied. */
    public function changeOwnPassword(string $username, string $currentPassword, string $newPassword): void
    {
        if (!$this->verifyPassword($username, $currentPassword)) {
            throw new RuntimeException('Your current password was not correct.');
        }

        $this->updatePassword($username, $newPassword);
    }

    /** Promote or demote an account, always keeping at least one administrator. */
    public function setAdmin(string $username, bool $isAdmin): void
    {
        $this->mutate(static function (array $data) use ($username, $isAdmin): array {
            if (!isset($data['users'][$username])) {
                throw new RuntimeException('Unknown user account.');
            }

            $data['users'][$username]['is_admin'] = $isAdmin;
            $admins = array_filter($data['users'], static fn(array $user): bool => !empty($user['is_admin']));
            if ($admins === []) {
                throw new RuntimeException('You must keep at least one administrator account.');
            }

            return $data;
        });
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
        $this->mutate(static function (array $data) use ($username): array {
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
            return $data;
        });
    }

    public function logout(): void
    {
        $_SESSION = [];
        $this->sendHintCookie(false);

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

    /** Locked read-modify-write of the user store. */
    private function mutate(callable $change): void
    {
        Filesystem::withLock($this->storePath, function () use ($change): void {
            $this->save($change($this->load()));
        });
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

/**
 * libxml percent-encodes braces in URL attributes on save, turning
 * href="{{THEME_CSS_HREF}}" into href="%7B%7BTHEME_CSS_HREF%7D%7D". Put template
 * placeholder tokens back after any DOM round-trip.
 */
function restore_placeholder_tokens(string $html): string
{
    return preg_replace('/%7B%7B([A-Z0-9_]+)%7D%7D/i', '{{$1}}', $html) ?? $html;
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
            "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
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
        "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
    );
}

/**
 * SSRF guard shared by the site importer and the AI client: refuse a URL whose
 * host resolves to a private, reserved, loopback, or link-local address (this
 * includes cloud metadata endpoints such as 169.254.169.254). Returns the first
 * validated IP; connect to it via pin_url() so a DNS-rebinding answer between the
 * check and the connection can't redirect the request.
 */
function assert_public_url(string $url): string
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
        return $literal;
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

    // Prefer IPv4 (more widely routable from shared hosts).
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
    }

    return $ips[0];
}

/**
 * Validate $url and describe how to connect to the exact IP that passed the check:
 * - 'resolve': a CURLOPT_RESOLVE entry ("host:port:ip");
 * - 'url' / 'host_header' / 'ssl': for PHP streams, the URL with the IP as host,
 *   the Host header to send, and ssl context options that still verify the
 *   certificate against the real hostname (SNI + peer_name).
 *
 * @return array{ip:string, host:string, port:int, resolve:string, url:string, host_header:string, ssl:array}
 */
function pin_url(string $url): array
{
    $ip = assert_public_url($url);
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? 'http'));
    $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
    $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
    $ipHost = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;

    $pinnedUrl = $scheme . '://' . $ipHost . (isset($parts['port']) ? ':' . $port : '')
        . ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    $hostHeader = (str_contains($host, ':') ? '[' . $host . ']' : $host) . (isset($parts['port']) ? ':' . $port : '');

    return [
        'ip' => $ip,
        'host' => $host,
        'port' => $port,
        'resolve' => $host . ':' . $port . ':' . $ip,
        'url' => $pinnedUrl,
        'host_header' => 'Host: ' . $hostHeader,
        'ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true],
    ];
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
