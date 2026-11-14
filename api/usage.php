<?php
// api/usage.php — check and increment daily usage
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? ($_GET['action'] ?? 'check');

switch ($action) {

    // ── Check remaining uses ───────────────────────────────────────────────
    case 'check': {
        $claims = get_auth_user();
        $today  = date('Y-m-d');
        $db     = get_db();

        if ($claims) {
            // Logged-in user
            $uid = $claims['sub'];

            // Check real plan in DB (token may be stale)
            $userSt = $db->prepare('SELECT plan, plan_expires_at FROM users WHERE id = ?');
            $userSt->execute([$uid]);
            $userRow = $userSt->fetch();
            $plan = $userRow ? $userRow['plan'] : 'free';
            if ($plan === 'premium' && $userRow['plan_expires_at'] && strtotime($userRow['plan_expires_at']) < time()) {
                $plan = 'free';
            }

            if ($plan === 'premium') {
                json_out(['success' => true, 'plan' => 'premium', 'used' => 0, 'limit' => -1, 'remaining' => -1]);
            }

            $st = $db->prepare('SELECT rewrites_today FROM usage_log WHERE user_id = ? AND log_date = ?');
            $st->execute([$uid, $today]);
            $row = $st->fetch();
            $used = $row ? (int)$row['rewrites_today'] : 0;
            $limit = FREE_DAILY_LIMIT;
            json_out(['success' => true, 'plan' => 'free', 'used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used)]);

        } else {
            // Guest (by IP)
            $ip = get_client_ip();
            $st = $db->prepare('SELECT rewrites_today FROM usage_log WHERE guest_ip = ? AND log_date = ? AND user_id IS NULL');
            $st->execute([$ip, $today]);
            $row = $st->fetch();
            $used = $row ? (int)$row['rewrites_today'] : 0;
            $limit = FREE_DAILY_LIMIT;
            json_out(['success' => true, 'plan' => 'guest', 'used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used)]);
        }
    }

    // ── Increment usage (called after successful rewrite) ──────────────────
    case 'increment': {
        $claims = get_auth_user();
        $today  = date('Y-m-d');
        $db     = get_db();

        if ($claims) {
            $uid = $claims['sub'];
            // Upsert
            $db->prepare('
                INSERT INTO usage_log (user_id, log_date, rewrites_today)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1
            ')->execute([$uid, $today]);
        } else {
            $ip = get_client_ip();
            $db->prepare('
                INSERT INTO usage_log (guest_ip, log_date, rewrites_today)
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1
            ')->execute([$ip, $today]);
        }
        json_out(['success' => true]);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}

// Helper: check if current caller can proceed (called from rewrite.php)
function check_usage_gate() {
    $claims = get_auth_user();
    $today  = date('Y-m-d');
    $db     = get_db();

    if ($claims) {
        $uid = $claims['sub'];
        $userSt = $db->prepare('SELECT plan, plan_expires_at FROM users WHERE id = ?');
        $userSt->execute([$uid]);
        $userRow = $userSt->fetch();
        $plan = $userRow ? $userRow['plan'] : 'free';
        if ($plan === 'premium' && $userRow['plan_expires_at'] && strtotime($userRow['plan_expires_at']) < time()) {
            $plan = 'free';
        }
        if ($plan === 'premium') return ['allowed' => true, 'plan' => 'premium', 'uid' => $uid];

        $st = $db->prepare('SELECT rewrites_today FROM usage_log WHERE user_id = ? AND log_date = ?');
        $st->execute([$uid, $today]);
        $row = $st->fetch();
        $used = $row ? (int)$row['rewrites_today'] : 0;
        if ($used >= FREE_DAILY_LIMIT) return ['allowed' => false, 'plan' => 'free', 'used' => $used];
        return ['allowed' => true, 'plan' => 'free', 'uid' => $uid, 'used' => $used];

    } else {
        $ip = get_client_ip();
        $st = $db->prepare('SELECT rewrites_today FROM usage_log WHERE guest_ip = ? AND log_date = ? AND user_id IS NULL');
        $st->execute([$ip, $today]);
        $row = $st->fetch();
        $used = $row ? (int)$row['rewrites_today'] : 0;
        if ($used >= FREE_DAILY_LIMIT) return ['allowed' => false, 'plan' => 'guest', 'used' => $used];
        return ['allowed' => true, 'plan' => 'guest', 'ip' => $ip, 'used' => $used];
    }
}

function increment_usage($uid = null, $ip = null) {
    $today = date('Y-m-d');
    $db    = get_db();
    if ($uid) {
        $db->prepare('
            INSERT INTO usage_log (user_id, log_date, rewrites_today)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1
        ')->execute([$uid, $today]);
    } elseif ($ip) {
        $db->prepare('
            INSERT INTO usage_log (guest_ip, log_date, rewrites_today)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1
        ')->execute([$ip, $today]);
    }
}
