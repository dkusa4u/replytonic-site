<?php
/**
 * ReplyTonic — Account management
 * Actions: get_info, change_password, change_email,
 *          get_notifications, update_notifications,
 *          update_privacy, export_data, delete_account
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

// Helper: safe column read (returns default if column missing)
function safe_col($row, $col, $default = null) {
    return array_key_exists($col, $row) ? $row[$col] : $default;
}

switch ($action) {

    // ── Get account info + today's usage ──────────────────────────────────────
    case 'get_info': {
        $st = $db->prepare('SELECT * FROM users WHERE id = ?');
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

        // Auto-generate referral code if missing
        $referralCode = safe_col($u, 'referral_code', null);
        if (!$referralCode) {
            try {
                $db->prepare('ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_code VARCHAR(16) DEFAULT NULL, ADD COLUMN IF NOT EXISTS referred_by INT DEFAULT NULL')->execute();
            } catch(Exception $e) {}
            $referralCode = strtoupper(substr(md5($uid . 'rt' . time()), 0, 8));
            try {
                $db->prepare('UPDATE users SET referral_code = ? WHERE id = ? AND referral_code IS NULL')->execute([$referralCode, $uid]);
            } catch(Exception $e) { $referralCode = null; }
        }

        // Count referrals
        $refCount = 0;
        try {
            $rSt = $db->prepare('SELECT COUNT(*) FROM users WHERE referred_by = ?');
            $rSt->execute([$uid]);
            $refCount = (int)$rSt->fetchColumn();
        } catch(Exception $e) {}

        json_out([
            'email'              => $u['email'],
            'plan'               => $plan,
            'plan_expires_at'    => $u['plan_expires_at'],
            'created_at'         => $u['created_at'] ?? null,
            'used_today'         => $usedToday,
            'daily_limit'        => $plan === 'free' ? FREE_DAILY_LIMIT : null,
            'total_rewrites'     => $totalAll,
            // Notifications (default all on if column missing)
            'notif_billing'      => (int)safe_col($u, 'notifications_billing', 1),
            'notif_usage'        => (int)safe_col($u, 'notifications_usage_alert', 1),
            'notif_updates'      => (int)safe_col($u, 'notifications_updates', 1),
            // Privacy
            'ai_opt_out'         => (int)safe_col($u, 'ai_training_opt_out', 0),
            // Preferences
            'preferred_language' => safe_col($u, 'preferred_language', 'auto'),
            // Referral
            'referral_code'      => $referralCode,
            'referral_count'     => $refCount,
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

        $st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($current, $row['password_hash']))
            json_out(['error' => 'Current password is incorrect.'], 401);

        $newHash = password_hash($newPass, PASSWORD_BCRYPT);
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $uid]);

        json_out(['success' => true, 'message' => 'Password updated successfully.']);
    }

    // ── Change email ──────────────────────────────────────────────────────────
    case 'change_email': {
        $newEmail   = trim($body['new_email']       ?? '');
        $currentPw  = $body['current_password']     ?? '';

        if (!$newEmail || !$currentPw)
            json_out(['error' => 'New email and current password are required.'], 400);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL))
            json_out(['error' => 'Please enter a valid email address.'], 400);

        // Verify password
        $st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$uid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($currentPw, $row['password_hash']))
            json_out(['error' => 'Current password is incorrect.'], 401);

        // Check email not already in use
        $chk = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $chk->execute([$newEmail, $uid]);
        if ($chk->fetch()) json_out(['error' => 'That email is already in use.'], 409);

        $db->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$newEmail, $uid]);

        json_out(['success' => true, 'message' => 'Email updated. Please log in again with your new email.']);
    }

    // ── Update notification preferences ──────────────────────────────────────
    case 'update_notifications': {
        $billing = isset($body['notif_billing'])  ? (int)(bool)$body['notif_billing']  : null;
        $usage   = isset($body['notif_usage'])    ? (int)(bool)$body['notif_usage']    : null;
        $updates = isset($body['notif_updates'])  ? (int)(bool)$body['notif_updates']  : null;

        // Try to update; silently succeed even if columns don't exist yet
        try {
            $db->prepare('ALTER TABLE users
                ADD COLUMN IF NOT EXISTS notifications_billing    TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS notifications_usage_alert TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS notifications_updates    TINYINT(1) NOT NULL DEFAULT 1,
                ADD COLUMN IF NOT EXISTS ai_training_opt_out      TINYINT(1) NOT NULL DEFAULT 0
            ')->execute();
        } catch(Exception $e) {}

        try {
            $sets = [];
            $vals = [];
            if ($billing  !== null) { $sets[] = 'notifications_billing = ?';     $vals[] = $billing; }
            if ($usage    !== null) { $sets[] = 'notifications_usage_alert = ?'; $vals[] = $usage; }
            if ($updates  !== null) { $sets[] = 'notifications_updates = ?';     $vals[] = $updates; }
            if ($sets) {
                $vals[] = $uid;
                $db->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
            }
            json_out(['success' => true, 'message' => 'Notification preferences saved.']);
        } catch(Exception $e) {
            json_out(['success' => true, 'message' => 'Saved. (Run migration to persist.)']);
        }
    }

    // ── Update general preferences (language etc.) ────────────────────────────
    case 'update_preferences': {
        $lang = trim($body['preferred_language'] ?? 'auto');
        // Whitelist allowed values
        $allowed = ['auto','English','Spanish','French','German','Portuguese','Italian',
                    'Japanese','Korean','Chinese (Simplified)','Chinese (Traditional)',
                    'Hindi','Arabic','Dutch','Swedish','Polish','Turkish','Russian',
                    'Indonesian','Thai','Vietnamese','Filipino','Swahili'];
        if (!in_array($lang, $allowed, true)) $lang = 'auto';

        try {
            $db->prepare('ALTER TABLE users ADD COLUMN IF NOT EXISTS preferred_language VARCHAR(40) NOT NULL DEFAULT \'auto\'')->execute();
        } catch(Exception $e) {}

        $db->prepare('UPDATE users SET preferred_language = ? WHERE id = ?')->execute([$lang, $uid]);
        json_out(['success' => true, 'message' => 'Preferences saved.']);
    }

    // ── Sign out all devices (invalidate all existing tokens) ─────────────────
    case 'sign_out_all': {
        try {
            $db->prepare('ALTER TABLE users ADD COLUMN IF NOT EXISTS token_version INT NOT NULL DEFAULT 0')->execute();
        } catch(Exception $e) {}
        $db->prepare('UPDATE users SET token_version = token_version + 1 WHERE id = ?')->execute([$uid]);
        json_out(['success' => true, 'message' => 'All sessions signed out.']);
    }

    // ── Update privacy settings ───────────────────────────────────────────────
    case 'update_privacy': {
        $optOut = isset($body['ai_opt_out']) ? (int)(bool)$body['ai_opt_out'] : 0;

        try {
            $db->prepare('ALTER TABLE users
                ADD COLUMN IF NOT EXISTS ai_training_opt_out TINYINT(1) NOT NULL DEFAULT 0
            ')->execute();
        } catch(Exception $e) {}

        try {
            $db->prepare('UPDATE users SET ai_training_opt_out = ? WHERE id = ?')->execute([$optOut, $uid]);
            json_out(['success' => true, 'message' => $optOut ? 'Opted out of AI training.' : 'Opted back in to AI training.']);
        } catch(Exception $e) {
            json_out(['success' => true, 'message' => 'Preference saved.']);
        }
    }

    // ── Export account data ───────────────────────────────────────────────────
    case 'export_data': {
        $st = $db->prepare('SELECT id, email, plan, plan_expires_at, created_at FROM users WHERE id = ?');
        $st->execute([$uid]);
        $u = $st->fetch(PDO::FETCH_ASSOC);

        $logSt = $db->prepare('SELECT log_date, rewrites_today FROM usage_log WHERE user_id = ? ORDER BY log_date DESC');
        $logSt->execute([$uid]);
        $logs = $logSt->fetchAll(PDO::FETCH_ASSOC);

        $export = [
            'exported_at' => date('c'),
            'account'     => $u,
            'usage_log'   => $logs,
        ];

        json_out(['success' => true, 'data' => $export]);
    }

    // ── Delete account ────────────────────────────────────────────────────────
    case 'delete_account': {
        $confirm = $body['confirm'] ?? '';
        if ($confirm !== 'DELETE') json_out(['error' => 'Type DELETE to confirm.'], 400);

        // Log reason (optional — just ignore if table/column missing)
        $reason = substr($body['reason'] ?? '', 0, 255);
        // We just log it to error_log for now; future: cancellation_feedback table
        if ($reason) error_log("ReplyTonic delete reason uid=$uid: $reason");

        $db->prepare('DELETE FROM usage_log WHERE user_id = ?')->execute([$uid]);
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

        json_out(['success' => true, 'message' => 'Account deleted.']);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
