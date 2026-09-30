<?php
declare(strict_types=1);

const VK_ROOT = __DIR__ . '/..';

$configFile = VK_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    if (!str_contains($_SERVER['REQUEST_URI'] ?? '', '/install')) {
        header('Location: /install/');
        exit;
    }
    return;
}

$config = require $configFile;
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Dhaka');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name($config['app']['session_name'] ?? 'viralkeno_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Lax',
]);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

try {
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $config['db']['host'],
        $config['db']['port'] ?? 3306,
        $config['db']['name'],
        $config['db']['charset'] ?? 'utf8mb4'
    );
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("SET time_zone = '+06:00'");
} catch (Throwable $e) {
    http_response_code(503);
    error_log('ViralKeno database connection error: ' . $e->getMessage());
    exit(($config['app']['debug'] ?? false) ? 'Database connection failed: ' . htmlspecialchars($e->getMessage()) : 'সাময়িকভাবে সাইটটি অনুপলব্ধ। পরে আবার চেষ্টা করুন।');
}

try {
    $pdo->exec("UPDATE articles SET status='published', updated_at=NOW() WHERE status='scheduled' AND published_at<=NOW()");
} catch (Throwable $e) {
    error_log('ViralKeno scheduler error: ' . $e->getMessage());
}

function db(): PDO
{
    global $pdo;
    return $pdo;
}

function cfg(string $path, mixed $default = null): mixed
{
    global $config;
    $value = $config;
    foreach (explode('.', $path) as $key) {
        if (!is_array($value) || !array_key_exists($key, $value)) return $default;
        $value = $value[$key];
    }
    return $value;
}

function e(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_url(): string
{
    return rtrim((string)cfg('app.url', ''), '/');
}

function url(string $path = '', array $query = []): string
{
    $path = ltrim($path, '/');
    if (!cfg('app.pretty_urls', true) && $path !== '') {
        $query = ['route' => $path] + $query;
        return base_url() . '/index.php?' . http_build_query($query);
    }
    $target = base_url() . '/' . $path;
    return $query ? $target . '?' . http_build_query($query) : $target;
}

function route_path(): string
{
    if (isset($_GET['route'])) return trim((string)$_GET['route'], '/');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    if ($base && $base !== '/' && str_starts_with($path, $base)) $path = substr($path, strlen($base));
    return trim($path, '/');
}

function csrf_token(): string

{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('নিরাপত্তা যাচাই ব্যর্থ হয়েছে। পেজটি রিফ্রেশ করে আবার চেষ্টা করুন।');
    }
}

function setting(string $key, mixed $default = ''): mixed
{
    static $settings;
    if ($settings === null) {
        $settings = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings[$key] ?? $default;
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function query_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ?: null;
}

function paginate(int $page, int $perPage, int $total): array
{
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min(max(1, $page), $pages);
    return ['page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $perPage, 'per_page' => $perPage, 'total' => $total];
}

function reading_time(string $html): int
{
    $words = preg_split('/\s+/u', trim(strip_tags($html))) ?: [];
    return max(1, (int)ceil(count($words) / 180));
}

function article_image(?string $image): string
{
    if ($image && (str_starts_with($image, 'http://') || str_starts_with($image, 'https://'))) return $image;
    if ($image) return base_url() . '/' . ltrim($image, '/');
    return base_url() . '/public/placeholders/default.svg';
}

function admin_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $id = (int)($_SESSION['admin_id'] ?? 0);
    $sessionToken = $_SESSION['admin_session_token'] ?? '';
    if (!$id || !$sessionToken) return $user = null;
    $row = query_one('SELECT a.* FROM admins a JOIN admin_sessions s ON s.admin_id=a.id WHERE a.id=? AND s.token_hash=? AND s.revoked_at IS NULL AND s.expires_at>NOW()', [$id, hash('sha256', $sessionToken)]);
    return $user = ($row && $row['is_active']) ? $row : null;
}

function require_admin(): array
{
    $user = admin_user();
    if (!$user) {
        header('Location: ' . base_url() . '/admin/index.php?action=login');
        exit;
    }
    return $user;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = compact('type', 'message');
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function redirect(string $target): void
{
    header('Location: ' . $target);
    exit;
}

function slugify(string $text): string
{
    $text = trim(mb_strtolower($text));
    $text = preg_replace('/[^\p{L}\p{N}]+/u', '-', $text) ?? '';
    $text = trim($text, '-');
    return mb_substr($text ?: 'story-' . date('Ymd-His'), 0, 180);
}

function unique_slug(string $table, string $slug, int $excludeId = 0): string
{
    $allowed = ['articles', 'categories', 'tags', 'pages'];
    if (!in_array($table, $allowed, true)) throw new InvalidArgumentException('Invalid slug table');
    $base = slugify($slug);
    $candidate = $base;
    $n = 2;
    while (query_one("SELECT id FROM {$table} WHERE slug=? AND id<>? LIMIT 1", [$candidate, $excludeId])) {
        $candidate = $base . '-' . $n++;
    }
    return $candidate;
}

function safe_embed(?string $url): string
{
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) return '';
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
    if (in_array($host, ['youtube.com', 'www.youtube.com', 'youtu.be'], true)) {
        $id = $host === 'youtu.be' ? trim((string)parse_url($url, PHP_URL_PATH), '/') : '';
        if ($host !== 'youtu.be') parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        if ($host !== 'youtu.be') $id = $q['v'] ?? basename((string)parse_url($url, PHP_URL_PATH));
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', $id);
        return $id ? 'https://www.youtube-nocookie.com/embed/' . $id : '';
    }
    if (in_array($host, ['facebook.com', 'www.facebook.com'], true)) return 'https://www.facebook.com/plugins/video.php?href=' . rawurlencode($url) . '&show_text=false';
    return '';
}

function sanitize_html(string $html): string
{
    $allowedTags = ['p','br','h2','h3','h4','strong','em','b','i','u','ul','ol','li','blockquote','a','img','figure','figcaption','hr','code','pre'];
    $allowedAttrs = ['href','src','alt','title','width','height','loading','rel','target'];
    $doc = new DOMDocument('1.0', 'UTF-8');
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?><div id="vk-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('vk-root');
    if (!$root) return '';
    $walk = function (DOMNode $node) use (&$walk, $allowedTags, $allowedAttrs): void {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                if (!in_array(strtolower($child->tagName), $allowedTags, true)) {
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    if (!in_array($name, $allowedAttrs, true)) $child->removeAttribute($attr->name);
                }
                foreach (['href','src'] as $attr) {
                    if (!$child->hasAttribute($attr)) continue;
                    $value = trim($child->getAttribute($attr));
                    if ($value !== '' && !preg_match('#^(https?://|/|uploads/|public/)#i', $value)) $child->removeAttribute($attr);
                }
                if ($child->tagName === 'a') $child->setAttribute('rel', 'noopener');
                if ($child->tagName === 'img') $child->setAttribute('loading', 'lazy');
            }
            $walk($child);
        }
    };
    $walk($root);
    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}

function render_ad(string $slot): string
{
    $row = query_one('SELECT * FROM advertisement_slots WHERE slot_key=? AND is_enabled=1 LIMIT 1', [$slot]);
    if (!$row) return '';
    $code = trim((string)$row['ad_code']);
    $body = $code !== '' ? $code : '<span>ADVERTISEMENT</span><small> বিজ্ঞাপনের স্থান</small>';
    return '<aside class="ad-slot ad-' . e($slot) . '" aria-label="Advertisement">' . $body . '</aside>';
}

function save_uploaded_image(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('ছবি আপলোড ব্যর্থ হয়েছে।');
    if (($file['size'] ?? 0) > 3 * 1024 * 1024) throw new RuntimeException('ছবির সর্বোচ্চ সাইজ 3MB।');
    $info = @getimagesize($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $mime = $info['mime'] ?? '';
    if (!$info || !isset($allowed[$mime]) || $info[0] > 4000 || $info[1] > 4000) throw new RuntimeException('শুধু JPG, PNG বা WebP এবং সর্বোচ্চ 4000×4000 ছবি দিন।');
    $basename = bin2hex(random_bytes(16));
    if ($mime !== 'image/webp' && function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $bytes = file_get_contents($file['tmp_name']);
        $image = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if ($image) {
            if (function_exists('imagepalettetotruecolor')) @imagepalettetotruecolor($image);
            @imagealphablending($image, true);
            @imagesavealpha($image, true);
            $webpTarget = VK_ROOT . '/uploads/images/' . $basename . '.webp';
            if (@imagewebp($image, $webpTarget, 82)) {
                imagedestroy($image);
                return 'uploads/images/' . $basename . '.webp';
            }
            imagedestroy($image);
        }
    }
    $name = $basename . '.' . $allowed[$mime];
    $target = VK_ROOT . '/uploads/images/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $target)) throw new RuntimeException('আপলোড ফোল্ডারে লেখা যাচ্ছে না।');
    return 'uploads/images/' . $name;
}
