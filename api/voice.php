<?php
// api/voice.php — personal writing style trainer (premium only)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

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
if ($plan !== 'premium') json_out(['error' => 'My Voice is a premium feature. Upgrade to unlock it.'], 403);

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? 'get';

switch ($action) {

    // ── Get saved voice profile ───────────────────────────────────────────
    case 'get': {
        $st = $db->prepare('SELECT sample_messages, style_summary, updated_at FROM user_voices WHERE user_id = ?');
        $st->execute([$uid]);
        $row = $st->fetch();
        json_out(['success' => true, 'voice' => $row ?: null]);
    }

    // ── Save voice samples + generate AI summary ──────────────────────────
    case 'train': {
        $samples = trim($body['samples'] ?? '');
        if (strlen($samples) < 50) json_out(['error' => 'Please provide at least 2-3 sample messages (50+ characters) so the AI can learn your style.'], 400);
        if (strlen($samples) > 8000) $samples = substr($samples, 0, 8000);

        // Ask GPT-4o to summarize writing style
        $prompt = "You are analyzing someone's writing style. Based on these message samples, write a concise style profile (3-5 bullet points) describing: vocabulary level, sentence length, tone markers, punctuation habits, and unique patterns. This will be used to match their voice in future rewrites.\n\nSAMPLES:\n$samples\n\nSTYLE PROFILE:";

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $GLOBALS['OPENAI_API_KEY'],
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model'       => 'gpt-4o',
                'temperature' => 0.3,
                'max_tokens'  => 300,
                'messages'    => [
                    ['role' => 'user', 'content' => $prompt]
                ],
            ]),
        ]);
        $resp = curl_exec($ch);
        curl_close($ch);

        $decoded  = json_decode($resp, true);
        $summary  = $decoded['choices'][0]['message']['content'] ?? '';
        if (!$summary) $summary = '(Style analysis unavailable — samples saved)';

        // Upsert
        $db->prepare('
            INSERT INTO user_voices (user_id, sample_messages, style_summary)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE sample_messages = VALUES(sample_messages), style_summary = VALUES(style_summary)
        ')->execute([$uid, $samples, $summary]);

        json_out(['success' => true, 'summary' => $summary]);
    }

    // ── Delete voice profile ──────────────────────────────────────────────
    case 'delete': {
        $db->prepare('DELETE FROM user_voices WHERE user_id = ?')->execute([$uid]);
        json_out(['success' => true]);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
