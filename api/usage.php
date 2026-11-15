<?php
/**
 * ReplyTonic — Usage Stats Endpoint
 * Returns the user's daily usage count and limit.
 * Used by the Chrome extension popup.
 */

// CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

// ── Auth ───────────────────────────────────────────────────────────────────────
function getBearerToken() {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
    return null;
}

function verifyJWT($token, $secret) {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    $sig   = base64_decode(strtr($parts[2], '-_', '+/'));
    $valid = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
    if (!hash_equals($valid, $sig)) return false;
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (!$payload || (isset($payload['exp']) && $payload['exp'] < time())) return false;
    return $payload;
}

$token   = getBearerToken();
$payload = $token ? verifyJWT($token, JWT_SECRET) : null;

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$userId = (int)($payload['sub'] ?? 0);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

// ── DB ─────────────────────────────────────────────────────────────────────────
$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// Get user plan
$stmt = $pdo->prepare('SELECT plan FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'user_not_found']);
    exit;
}

$isPremium = ($user['plan'] ?? 'free') !== 'free';

// Daily usage count
$today = date('Y-m-d');
$stmt  = $pdo->prepare(
    "SELECT COALESCE(rewrites_today, 0) FROM usage_log
     WHERE user_id = ? AND log_date = ?"
);
$stmt->execute([$userId, $today]);
$row  = $stmt->fetchColumn();
$used = (int)($row !== false ? $row : 0);

$limit = $isPremium ? 999 : FREE_DAILY_LIMIT;

echo json_encode([
    'used'      => $used,
    'limit'     => $limit,
    'remaining' => max(0, $limit - $used),
    'plan'      => $user['plan'] ?? 'free',
    'is_premium'=> $isPremium,
    'resets_at' => date('Y-m-d', strtotime('+1 day')) . ' 00:00:00',
]);
