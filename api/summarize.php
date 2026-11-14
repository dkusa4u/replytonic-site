<?php
/**
 * ReplyTonic — Text Summarizer
 * POST /api/summarize.php
 * Body: { text: "...", length: "short|medium|bullet|actions" }
 * Returns: { summary: "...", bullets: ["..."], actions: ["..."], word_count_original: N, word_count_summary: N }
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Auth + usage gate ─────────────────────────────────────────────────────────
$claims    = get_auth_user();
$today     = date('Y-m-d');
$db        = get_db();
$isPremium = false;
$userId    = null;
$guestIp   = null;

if ($claims) {
    $userId = (int)$claims['sub'];
    $uSt = $db->prepare('SELECT plan, plan_expires_at FROM users WHERE id = ?');
    $uSt->execute([$userId]);
    $uRow = $uSt->fetch();
    $plan = $uRow ? $uRow['plan'] : 'free';
    if ($plan === 'premium' && $uRow['plan_expires_at'] && strtotime($uRow['plan_expires_at']) < time()) {
        $db->prepare('UPDATE users SET plan=? WHERE id=?')->execute(['free', $userId]);
        $plan = 'free';
    }
    $isPremium = ($plan === 'premium');
} else {
    $guestIp = get_client_ip();
}

if (!$isPremium) {
    if ($userId) {
        $usSt = $db->prepare('SELECT rewrites_today FROM usage_log WHERE user_id=? AND log_date=?');
        $usSt->execute([$userId, $today]);
    } else {
        $usSt = $db->prepare('SELECT rewrites_today FROM usage_log WHERE guest_ip=? AND log_date=? AND user_id IS NULL');
        $usSt->execute([$guestIp, $today]);
    }
    $usRow     = $usSt->fetch();
    $usedToday = $usRow ? (int)$usRow['rewrites_today'] : 0;
    if ($usedToday >= FREE_DAILY_LIMIT) {
        http_response_code(429);
        echo json_encode(['error'=>'daily_limit','message'=>"You've used all ".FREE_DAILY_LIMIT." free rewrites for today. Upgrade to Premium for unlimited access.",'limit'=>FREE_DAILY_LIMIT]);
        exit;
    }
}

// ── Input ─────────────────────────────────────────────────────────────────────
$input  = json_decode(file_get_contents('php://input'), true) ?: [];
$text   = trim($input['text'] ?? '');
$length = $input['length'] ?? 'medium'; // short|medium|bullet|actions

if (!$text) { http_response_code(400); echo json_encode(['error'=>'Text is required']); exit; }
if (strlen($text) > 12000) { http_response_code(400); echo json_encode(['error'=>'Text too long (max 12000 chars)']); exit; }

$wordCount = str_word_count($text);

// ── Build prompt ──────────────────────────────────────────────────────────────
$lengthMap = [
    'short'   => '1-2 sentences (max 50 words)',
    'medium'  => '3-4 sentences (max 120 words)',
    'bullet'  => '4-6 bullet points',
    'actions' => '3-5 key action items or decisions',
];
$lengthDesc = $lengthMap[$length] ?? $lengthMap['medium'];

$sysPrompt  = "You are an expert at summarizing professional communications. Be concise, accurate, and preserve all critical information. Return ONLY valid JSON with no markdown, no code fences.";
$userPrompt = "Summarize this text in {$lengthDesc}. Return JSON: {\"summary\":\"2-sentence prose summary always included\",\"bullets\":[\"Key point 1\",\"Key point 2\"],\"actions\":[\"Action item 1 if any\"],\"topic\":\"one-word topic label\"}. For 'short' and 'medium' modes, bullets can be empty []. Always include a 2-sentence summary. Text:\n\n" . $text;

// ── Call OpenAI ───────────────────────────────────────────────────────────────
$payload = [
    'model'       => 'gpt-4o',
    'temperature' => 0.3,
    'max_tokens'  => 600,
    'messages'    => [
        ['role' => 'system', 'content' => $sysPrompt],
        ['role' => 'user',   'content' => $userPrompt],
    ],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_TIMEOUT        => 25,
]);
$raw = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);

if ($err) { http_response_code(503); echo json_encode(['error'=>'API request failed: '.$err]); exit; }

$resp    = json_decode($raw, true);
$content = $resp['choices'][0]['message']['content'] ?? '';

$content = preg_replace('/^```json\s*/i', '', trim($content));
$content = preg_replace('/^```\s*/i', '', $content);
$content = preg_replace('/```\s*$/i', '', $content);

$result = json_decode(trim($content), true);

if (!$result) {
    http_response_code(500);
    echo json_encode(['error'=>'Failed to parse AI response','raw'=>substr($content,0,300)]);
    exit;
}

// Add word count metadata
$result['word_count_original'] = $wordCount;
$result['word_count_summary']  = str_word_count($result['summary'] ?? '');

// ── Log usage ─────────────────────────────────────────────────────────────────
if (!$isPremium) {
    if ($userId) {
        $db->prepare('INSERT INTO usage_log (user_id,log_date,rewrites_today) VALUES (?,?,1) ON DUPLICATE KEY UPDATE rewrites_today=rewrites_today+1')
           ->execute([$userId, $today]);
    } else {
        $db->prepare('INSERT INTO usage_log (guest_ip,log_date,rewrites_today) VALUES (?,?,1) ON DUPLICATE KEY UPDATE rewrites_today=rewrites_today+1')
           ->execute([$guestIp, $today]);
    }
}

echo json_encode($result);
