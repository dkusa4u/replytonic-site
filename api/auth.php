<?php
// api/auth.php — register, login, verify, delete account
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';

// ── MailerLite helper ─────────────────────────────────────────────────────────
function mailerlite_add_subscriber(string $email): void {
    if (!defined('MAILERLITE_API_KEY') || !MAILERLITE_API_KEY || !defined('MAILERLITE_GROUP_ID') || !MAILERLITE_GROUP_ID) return;
    $payload = json_encode(['email' => $email, 'groups' => [MAILERLITE_GROUP_ID]]);
    $ch = curl_init('https://connect.mailerlite.com/api/subscribers');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . MAILERLITE_API_KEY,
        ],
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);  // fire-and-forget; don't block registration on failure
    curl_close($ch);
}

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? '';

switch ($action) {

    // ── Register ──────────────────────────────────────────────────────────
    case 'register': {
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = $body['password'] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Invalid email address.'], 400);
        if (strlen($pass) < 6) json_out(['error' => 'Password must be at least 6 characters.'], 400);

        $db = get_db();
        $st = $db->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) json_out(['error' => 'An account with this email already exists.'], 409);

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $ins  = $db->prepare('INSERT INTO users (email, password_hash) VALUES (?, ?)');
        $ins->execute([$email, $hash]);
        $uid = (int)$db->lastInsertId();

        // ── Handle referral tracking ───────────────────────────────────────
        $refCode = trim($body['ref_code'] ?? '');
        if ($refCode) {
            try {
                $db->prepare('ALTER TABLE users ADD COLUMN IF NOT EXISTS referral_code VARCHAR(16) DEFAULT NULL, ADD COLUMN IF NOT EXISTS referred_by INT DEFAULT NULL')->execute();
                $rSt = $db->prepare('SELECT id FROM users WHERE referral_code = ? AND id != ?');
                $rSt->execute([$refCode, $uid]);
                $referrer = $rSt->fetch(PDO::FETCH_ASSOC);
                if ($referrer) {
                    $db->prepare('UPDATE users SET referred_by = ? WHERE id = ?')->execute([(int)$referrer['id'], $uid]);
                }
            } catch(Exception $e) {}
        }

        $token = jwt_encode([
            'sub'   => $uid,
            'email' => $email,
            'plan'  => 'free',
            'ver'   => 0,
            'iat'   => time(),
            'exp'   => time() + 60 * 60 * 24 * 90, // 90 days
        ], JWT_SECRET);

        // Add new user to MailerLite onboarding sequence (fire-and-forget)
        mailerlite_add_subscriber($email);

        json_out(['success' => true, 'token' => $token, 'user' => [
            'id' => $uid, 'email' => $email, 'plan' => 'free'
        ]]);
    }

    // ── Login ─────────────────────────────────────────────────────────────
    case 'login': {
        $email = strtolower(trim($body['email'] ?? ''));
        $pass  = $body['password'] ?? '';
        if (!$email || !$pass) json_out(['error' => 'Email and password are required.'], 400);

        $db = get_db();
        $st = $db->prepare('SELECT id, email, password_hash, plan, plan_expires_at FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();

        if (!$user || !password_verify($pass, $user['password_hash'])) {
            json_out(['error' => 'Incorrect email or password.'], 401);
        }

        // Auto-downgrade expired premium
        $plan = $user['plan'];
        if ($plan === 'premium' && $user['plan_expires_at'] && strtotime($user['plan_expires_at']) < time()) {
            $db->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute(['free', $user['id']]);
            $plan = 'free';
        }

        // Get current token_version so the new token stays valid
        $tokenVer = 0;
        try {
            $tvSt = $db->prepare('SELECT token_version FROM users WHERE id = ?');
            $tvSt->execute([$user['id']]);
            $tvRow = $tvSt->fetch(PDO::FETCH_ASSOC);
            if ($tvRow && isset($tvRow['token_version'])) $tokenVer = (int)$tvRow['token_version'];
        } catch(Exception $e) {}

        $token = jwt_encode([
            'sub'   => (int)$user['id'],
            'email' => $user['email'],
            'plan'  => $plan,
            'ver'   => $tokenVer,
            'iat'   => time(),
            'exp'   => time() + 60 * 60 * 24 * 90,
        ], JWT_SECRET);

        json_out(['success' => true, 'token' => $token, 'user' => [
            'id' => (int)$user['id'], 'email' => $user['email'], 'plan' => $plan
        ]]);
    }

    // ── Verify token (refresh user state) ─────────────────────────────────
    case 'verify': {
        $claims = get_auth_user();
        if (!$claims) json_out(['error' => 'Invalid or expired token.'], 401);

        $db = get_db();
        $st = $db->prepare('SELECT id, email, plan, plan_expires_at FROM users WHERE id = ?');
        $st->execute([$claims['sub']]);
        $user = $st->fetch();
        if (!$user) json_out(['error' => 'Account not found.'], 404);

        $plan = $user['plan'];
        if ($plan === 'premium' && $user['plan_expires_at'] && strtotime($user['plan_expires_at']) < time()) {
            $db->prepare('UPDATE users SET plan = ? WHERE id = ?')->execute(['free', $user['id']]);
            $plan = 'free';
        }

        json_out(['success' => true, 'user' => [
            'id' => (int)$user['id'], 'email' => $user['email'], 'plan' => $plan
        ]]);
    }

    // ── Delete account ─────────────────────────────────────────────────────

    // ── Forgot password — generate token & email link ─────────────────────────────────────────
    case 'forgot-password': {
        $email = strtolower(trim($body['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error' => 'Invalid email address.'], 400);

        $db = get_db();
        $st = $db->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        $user = $st->fetch();

        if ($user) {
            $token   = bin2hex(random_bytes(32));                            // 64-char hex
            $expires = date('Y-m-d H:i:s', time() + 3600);                  // 1 hour
            $db->prepare('UPDATE users SET reset_token = ?, reset_token_expires = ? WHERE id = ?')
               ->execute([$token, $expires, $user['id']]);

            $link    = (defined('APP_URL') ? APP_URL : 'https://replytonic.com') . '/?reset_token=' . $token;
            $subject = 'Reset your ReplyTonic password';
            $message = "Hi,\n\nClick the link below to reset your ReplyTonic password.\nThis link expires in 1 hour.\n\n" . $link . "\n\nIf you didn't request this, you can safely ignore this email.\n\n— The ReplyTonic Team";
            $headers = implode("\r\n", [
                'From: noreply@replytonic.com',
                'Reply-To: noreply@replytonic.com',
                'Content-Type: text/plain; charset=UTF-8',
                'X-Mailer: ReplyTonic',
            ]);
            mail($email, $subject, $message, $headers);
        }

        // Always return success — never reveal whether the email exists
        json_out(['success' => true, 'message' => 'If that email is registered, a reset link has been sent.']);
    }

    // ── Reset password — verify token & update password ───────────────────────────
    case 'reset-password': {
        $token = trim($body['token'] ?? '');
        $pass  = $body['password'] ?? '';

        if (!$token) json_out(['error' => 'Reset token is missing.'], 400);
        if (strlen($pass) < 6) json_out(['error' => 'Password must be at least 6 characters.'], 400);

        $db = get_db();
        $st = $db->prepare('SELECT id FROM users WHERE reset_token = ? AND reset_token_expires > NOW()');
        $st->execute([$token]);
        $user = $st->fetch();

        if (!$user) json_out(['error' => 'This reset link is invalid or has expired. Please request a new one.'], 400);

        $hash = password_hash($pass, PASSWORD_BCRYPT);
        $db->prepare('UPDATE users SET password_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?')
           ->execute([$hash, $user['id']]);

        json_out(['success' => true, 'message' => 'Password updated! You can now log in with your new password.']);
    }

    case 'delete': {
        $claims = get_auth_user();
        if (!$claims) json_out(['error' => 'Not authenticated.'], 401);
        $db = get_db();
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$claims['sub']]);
        json_out(['success' => true]);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
