<?php
// db.php — PDO connection singleton + pure-PHP JWT helpers
// This file is safe to commit (no credentials — those live in config.php)

require_once __DIR__ . '/config.php';

function get_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
    return $pdo;
}

// ── Pure-PHP HS256 JWT (no library needed) ──────────────────────────────────

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode($data) {
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode(array $payload, $secret) {
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $body    = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

function jwt_decode($token, $secret) {
    if (!is_string($token)) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    [$header, $body, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    if (!hash_equals($expected, $sig)) return false;
    $data = json_decode(base64url_decode($body), true);
    if (!$data) return false;
    if (isset($data['exp']) && $data['exp'] < time()) return false;
    return $data;
}

// ── Helpers ─────────────────────────────────────────────────────────────────

function json_out($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function get_auth_user() {
    // Try Authorization: Bearer <token>
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$hdr) {
        // Also check X-Auth-Token header
        $hdr = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    }
    $token = '';
    if (preg_match('/Bearer\s+(.+)/i', $hdr, $m)) {
        $token = $m[1];
    }
    if (!$token) return null;
    return jwt_decode($token, JWT_SECRET);
}

function get_client_ip() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', $_SERVER[$k])[0];
            return trim($ip);
        }
    }
    return '0.0.0.0';
}
