<?php
/**
 * ReplyTonic — Account management
 * Actions: get_info, change_password, delete_account
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$user = get_auth_user();   // returns decoded JWT payload or exits 401
$uid  = (int)$user['id'];
$db   = get_db();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

switch ($action) {

    // ── Get account info + today's usage ──────────────────────────────────────
    case 'get_info': {
        $st = $db->prepare('SELECT id, email, plan, plan_expires_at, created_at FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) json_out(['error' => 'User not found.'], 404);

        // Check if premium expired
        $plan = $u['plan'];
        if ($plan === 'premium' && $u['plan_expires_at'] && strtotime($u['plan_expires_at']) < time()) {
            $db->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute(['free', $uid]);
            $plan = 'free';
        }

        // Today's usage + all-time total
        $today = date('Y-m-d');
        $uSt = $db->prepare('SELECT rewrites_today FROM usage_log WHERE user_id=? AND log_date=?');
        $uSt->execute([$uid, $today]);
        $usageRow = $uSt->fetch(PDO::FETCH_ASSOC);
        $usedToday = $usageRow ? (int)$usageRow['rewrites_today'] : 0;

        $totSt = $db->prepare('SELECT COALESCE(SUM(rewrites_today),0) AS total FROM usage_log WHERE user_id=?');
        $totSt->execute([$uid]);
        $totalAll = (int)$totSt->fetchColumn();

        json_out([
            'email'            => $u['email'],
            'plan'             => $plan,
            'plan_expires_at'  => $u['plan_expires_at'],
            'created_at'       => $u['created_at'] ?? null,
            'used_today'       => $usedToday,
            'daily_limit'      => $plan === 'free' ? FREE_DAILY_LIMIT : null,
            'total_rewrites'   => $totalAll,
        ]);
    }

    // ── Change password ───────────────────────────────────────────────────────
    case 'change_password': {
        $current = $body['current_password'] ?? '';
        $newPass  = $body['new_password']     ?? '';
        $confirm  = $body['confirm_password'] ?? '';

        if (!$current || !$newPass || !$confirm)
            json_out(['error' => 'All three password fields are required.'], 400);
        if ($newPass !== $confirm)
            json_out(['error' => 'New passwords do not match.'], 400);
        if (strlen($newPass) < 8)
            json_out(['error' => 'New password must be at least 8 characters.'], 400);

        // Fetch current hash
        $st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password_hash']))
            json_out(['error' => 'Current password is incorrect.'], 401);

        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $uid]);

        json_out(['success' => true, 'message' => 'Password updated successfully.']);
    }

    // ── Delete account ────────────────────────────────────────────────────────
    case 'delete_account': {
        $confirm = $body['confirm'] ?? '';
        if ($confirm !== 'DELETE') json_out(['error' => 'Type DELETE to confirm.'], 400);

        // Wipe usage log then user row
        $db->prepare('DELETE FROM usage_log WHERE user_id = ?')->execute([$uid]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

        json_out(['success' => true, 'message' => 'Account deleted.']);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
