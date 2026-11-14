<?php
// api/history.php — cloud rewrite history (premium only)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';

$claims = get_auth_user();
if (!$claims) json_out(['error' => 'Not authenticated.'], 401);

$uid = (int)$claims['sub'];

// Verify premium
$db = get_db();
$userSt = $db->prepare('SELECT plan, plan_expires_at FROM users WHERE id = ?');
$userSt->execute([$uid]);
$userRow = $userSt->fetch();
$plan = $userRow ? $userRow['plan'] : 'free';
if ($plan === 'premium' && $userRow['plan_expires_at'] && strtotime($userRow['plan_expires_at']) < time()) {
    $plan = 'free';
}
// History: allow free users to read (up to 10), only premium gets full 100
$isPremium = ($plan === 'premium');

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? 'list';

switch ($action) {

    // ── List history ──────────────────────────────────────────────────────
    case 'list': {
        $limit = $isPremium ? 100 : 10;
        $st = $db->prepare('
            SELECT id, original_text, v1, platform, tone, language, mode, created_at
            FROM rewrite_history
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
        ');
        $st->execute([$uid, $limit]);
        $rows = $st->fetchAll();
        json_out(['success' => true, 'history' => $rows, 'plan' => $plan]);
    }

    // ── Save a rewrite to history ─────────────────────────────────────────
    case 'save': {
        $st = $db->prepare('
            INSERT INTO rewrite_history (user_id, original_text, v1, v2, v3, platform, tone, language, mode)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $st->execute([
            $uid,
            substr($body['original'] ?? '', 0, 2000),
            substr($body['v1'] ?? '', 0, 2000),
            substr($body['v2'] ?? '', 0, 2000),
            substr($body['v3'] ?? '', 0, 2000),
            substr($body['platform'] ?? 'Email', 0, 50),
            substr($body['tone'] ?? 'Professional', 0, 50),
            substr($body['language'] ?? 'English', 0, 50),
            substr($body['mode'] ?? 'rewrite', 0, 20),
        ]);

        // Keep max 100 items per user
        $db->prepare('
            DELETE FROM rewrite_history
            WHERE user_id = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM rewrite_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 100
                ) t
            )
        ')->execute([$uid, $uid]);

        json_out(['success' => true, 'id' => (int)$db->lastInsertId()]);
    }

    // ── Delete one entry ──────────────────────────────────────────────────
    case 'delete': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) json_out(['error' => 'Missing id.'], 400);
        $db->prepare('DELETE FROM rewrite_history WHERE id = ? AND user_id = ?')->execute([$id, $uid]);
        json_out(['success' => true]);
    }

    // ── Clear all ─────────────────────────────────────────────────────────
    case 'clear': {
        $db->prepare('DELETE FROM rewrite_history WHERE user_id = ?')->execute([$uid]);
        json_out(['success' => true]);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
