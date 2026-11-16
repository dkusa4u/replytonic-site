<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

// ── Config + helpers ──────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// ── Usage gate ────────────────────────────────────────────────────────────────
$claims   = get_auth_user();
$today    = date('Y-m-d');
$db       = get_db();
$isPremium = false;
$userId   = null;
$guestIp  = null;

if ($claims) {
    $userId = (int)$claims['sub'];
    // Check plan from DB (not stale token)
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
    // Count today's rewrites
    if ($userId) {
        $usSt = $db->prepare('SELECT rewrites_today FROM usage_log WHERE user_id=? AND log_date=?');
        $usSt->execute([$userId, $today]);
    } else {
        $usSt = $db->prepare('SELECT rewrites_today FROM usage_log WHERE guest_ip=? AND log_date=? AND user_id IS NULL');
        $usSt->execute([$guestIp, $today]);
    }
    $usRow = $usSt->fetch();
    $usedToday = $usRow ? (int)$usRow['rewrites_today'] : 0;
    if ($usedToday >= FREE_DAILY_LIMIT) {
        http_response_code(429);
        echo json_encode([
            'error'       => 'daily_limit',
            'message'     => "You've used all " . FREE_DAILY_LIMIT . " free rewrites for today. Upgrade to Premium for unlimited rewrites.",
            'used'        => $usedToday,
            'limit'       => FREE_DAILY_LIMIT,
        ]);
        exit;
    }
}

// ── Input ─────────────────────────────────────────────────────────────────────
$input           = json_decode(file_get_contents('php://input'), true) ?: [];
$message         = trim($input['message']         ?? '');
$originalMessage = trim($input['originalMessage'] ?? '');
$platform        = $input['platform']        ?? 'Email';
$tone            = $input['tone']            ?? 'Professional';
$tone2           = $input['tone2']           ?? 'None';
$language        = $input['language']        ?? 'English';
$length          = $input['length']          ?? 'Same';
$emoji           = $input['emoji']           ?? false;
$sesquipedalian  = $input['sesquipedalian']  ?? false;
$humanTouch      = $input['humanTouch']      ?? false; // premium: bypass AI detectors
$customInstr     = trim($input['customInstructions'] ?? '');
$mode            = $input['mode']            ?? 'rewrite';
$useVoice        = $input['useVoice']        ?? false; // premium: inject personal voice

$warmth   = isset($input['warmth'])   ? (int)$input['warmth']   : 0; // 1-5 or 0 = not set
$firmness = isset($input['firmness']) ? (int)$input['firmness'] : 0; // 1-5 or 0 = not set

if (!$message && $mode !== 'screenshot-reply') { http_response_code(400); echo json_encode(['error'=>'Message is required']); exit; }

// ── Screenshot-to-Reply (GPT-4o Vision) ──────────────────────────────────────
if ($mode === 'screenshot-reply') {
    $imageData     = $input['imageData']     ?? '';
    $imageMimeType = $input['imageMimeType'] ?? 'image/jpeg';
    $srLang   = trim($input['language'] ?? 'English');
    $srTone   = $input['tone'] ?? 'Professional';
    $srPlat   = $input['platform'] ?? 'WhatsApp';
    if (!$imageData) { http_response_code(400); echo json_encode(['error' => 'Image data is required.']); exit; }
    $srToneDesc = $toneMap[$srTone] ?? strtolower($srTone);
    $srPlatCtx  = $platMap[$srPlat] ?? $srPlat;
    $srLangInstr = ($srLang && $srLang !== 'English') ? "Write all 3 replies in {$srLang}." : 'Write in English.';
    $srUserText = "Look at this conversation screenshot. Generate 3 distinctly different {$srToneDesc} reply options for a {$srPlatCtx}. "
        . "Each reply should take a different angle or approach. "
        . "For each reply, provide a short 2-4 word label (e.g. 'Warm & Curious', 'Direct & Clear', 'Playful & Light'). "
        . "{$srLangInstr}\n\n"
        . "Respond ONLY with valid JSON: {\"v1\":\"...\",\"v1_label\":\"...\",\"v2\":\"...\",\"v2_label\":\"...\",\"v3\":\"...\",\"v3_label\":\"...\"}";
    $srPayload = json_encode([
        'model'           => 'gpt-4o',
        'temperature'     => 0.82,
        'max_tokens'      => 1100,
        'response_format' => ['type' => 'json_object'],
        'messages'        => [
            ['role' => 'system', 'content' => 'You are ReplyTonic, an expert AI message assistant. Analyze the conversation screenshot and generate 3 smart, context-aware reply options. Always return exactly the JSON format requested — nothing else.'],
            ['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => "data:{$imageMimeType};base64,{$imageData}", 'detail' => 'low']],
                ['type' => 'text', 'text' => $srUserText],
            ]],
        ],
    ]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $srPayload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$OPENAI_API_KEY}"], CURLOPT_TIMEOUT => 45]);
    $srResp = curl_exec($ch); $srCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$srResp || $srCode !== 200) { http_response_code(500); echo json_encode(['error' => 'Vision AI request failed. Please try again.']); exit; }
    $srData = json_decode($srResp, true); $srContent = $srData['choices'][0]['message']['content'] ?? ''; $srResult = json_decode($srContent, true);
    if (!isset($srResult['v1'], $srResult['v2'], $srResult['v3'])) { http_response_code(500); echo json_encode(['error' => 'Unexpected AI response. Please try again.']); exit; }
    if (!$isPremium) {
        if ($userId) { $db->prepare('INSERT INTO usage_log (user_id, log_date, rewrites_today) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1')->execute([$userId, $today]); }
        else { $db->prepare('INSERT INTO usage_log (guest_ip, log_date, rewrites_today) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1')->execute([$guestIp, $today]); }
    }
    $srOut = ['success' => true, 'v1' => $srResult['v1'], 'v2' => $srResult['v2'], 'v3' => $srResult['v3']];
    if (!empty($srResult['v1_label'])) $srOut['v1_label'] = $srResult['v1_label'];
    if (!empty($srResult['v2_label'])) $srOut['v2_label'] = $srResult['v2_label'];
    if (!empty($srResult['v3_label'])) $srOut['v3_label'] = $srResult['v3_label'];
    echo json_encode($srOut); exit;
}

// ── Ghosting Recovery ─────────────────────────────────────────────────────────
if ($mode === 'ghosting') {
    $ghostCtx   = $input['ghostContext'] ?? 'General';
    $timeGap    = $input['timeGap']      ?? '1 week';
    $grLang     = trim($input['language'] ?? 'English');
    $grLangInstr = ($grLang && $grLang !== 'English') ? "Write all 3 messages in {$grLang}." : 'Write in English.';
    $ctxMap = ['Job/Client' => 'professional job application or client relationship', 'Friend' => 'friendship', 'Dating' => 'dating or romantic interest', 'Networking' => 'professional networking', 'General' => 'general communication'];
    $ctxDesc   = $ctxMap[$ghostCtx] ?? $ghostCtx;
    $lastMsgLine = $message ? "Their last message / what was sent:\n\"{$message}\"\n\n" : '';
    $grPrompt = "Someone hasn't responded for {$timeGap} in a {$ctxDesc} context. "
        . $lastMsgLine
        . "Generate 3 distinct, natural follow-up messages that feel zero-awkward and prompt a response. "
        . "Each should have a different angle: e.g. one casual/friendly, one light humor, one value-add or question. "
        . "For each, provide a short 2-4 word label. {$grLangInstr}\n\n"
        . "Respond ONLY with valid JSON: {\"v1\":\"...\",\"v1_label\":\"...\",\"v2\":\"...\",\"v2_label\":\"...\",\"v3\":\"...\",\"v3_label\":\"...\"}";
    $grPayload = json_encode(['model' => 'gpt-4o', 'temperature' => 0.82, 'max_tokens' => 900, 'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => 'You are ReplyTonic, an expert in human communication. You craft follow-up messages that feel genuine, non-desperate, and natural. Always return exactly the JSON format requested — nothing else.'], ['role' => 'user', 'content' => $grPrompt]]]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $grPayload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$OPENAI_API_KEY}"], CURLOPT_TIMEOUT => 30]);
    $grResp = curl_exec($ch); $grCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$grResp || $grCode !== 200) { http_response_code(500); echo json_encode(['error' => 'AI request failed. Please try again.']); exit; }
    $grData = json_decode($grResp, true); $grContent = $grData['choices'][0]['message']['content'] ?? ''; $grResult = json_decode($grContent, true);
    if (!isset($grResult['v1'], $grResult['v2'], $grResult['v3'])) { http_response_code(500); echo json_encode(['error' => 'Unexpected AI response. Please try again.']); exit; }
    if (!$isPremium) {
        if ($userId) { $db->prepare('INSERT INTO usage_log (user_id, log_date, rewrites_today) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1')->execute([$userId, $today]); }
        else { $db->prepare('INSERT INTO usage_log (guest_ip, log_date, rewrites_today) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1')->execute([$guestIp, $today]); }
    }
    $grOut = ['success' => true, 'v1' => $grResult['v1'], 'v2' => $grResult['v2'], 'v3' => $grResult['v3']];
    if (!empty($grResult['v1_label'])) $grOut['v1_label'] = $grResult['v1_label'];
    if (!empty($grResult['v2_label'])) $grOut['v2_label'] = $grResult['v2_label'];
    if (!empty($grResult['v3_label'])) $grOut['v3_label'] = $grResult['v3_label'];
    echo json_encode($grOut); exit;
}

// ── Troll Aikido ──────────────────────────────────────────────────────────────
if ($mode === 'troll-aikido') {
    $taLang     = trim($input['language'] ?? 'English');
    $taLangInstr = ($taLang && $taLang !== 'English') ? "Write all 3 responses in {$taLang}." : 'Write in English.';
    $taPrompt = "Here is a hostile, negative, or troll-like message:\n\"{$message}\"\n\n"
        . "First, classify this message in 2-4 words (e.g. 'Passive-Aggressive', 'Outright Trolling', 'Angry Frustration', 'Bad Faith Criticism').\n\n"
        . "Then generate 3 strategic response options:\n"
        . "1. Witty Jujitsu — clever, disarming humor that makes you look calm and smart\n"
        . "2. Calm Fact-Check — polite, factual correction without emotion\n"
        . "3. Kill With Kindness — so gracious and warm it completely defuses the attack\n\n"
        . "{$taLangInstr}\n\n"
        . "Respond ONLY with valid JSON: {\"classification\":\"...\",\"v1\":\"...\",\"v1_label\":\"Witty Jujitsu\",\"v2\":\"...\",\"v2_label\":\"Calm Fact-Check\",\"v3\":\"...\",\"v3_label\":\"Kill With Kindness\"}";
    $taPayload = json_encode(['model' => 'gpt-4o', 'temperature' => 0.82, 'max_tokens' => 900, 'response_format' => ['type' => 'json_object'], 'messages' => [['role' => 'system', 'content' => 'You are ReplyTonic, a master of de-escalation and graceful communication. You help people respond to negativity with intelligence and class. Always return exactly the JSON format requested — nothing else.'], ['role' => 'user', 'content' => $taPrompt]]]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $taPayload, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer {$OPENAI_API_KEY}"], CURLOPT_TIMEOUT => 30]);
    $taResp = curl_exec($ch); $taCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if (!$taResp || $taCode !== 200) { http_response_code(500); echo json_encode(['error' => 'AI request failed. Please try again.']); exit; }
    $taData = json_decode($taResp, true); $taContent = $taData['choices'][0]['message']['content'] ?? ''; $taResult = json_decode($taContent, true);
    if (!isset($taResult['v1'], $taResult['v2'], $taResult['v3'])) { http_response_code(500); echo json_encode(['error' => 'Unexpected AI response. Please try again.']); exit; }
    if (!$isPremium) {
        if ($userId) { $db->prepare('INSERT INTO usage_log (user_id, log_date, rewrites_today) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1')->execute([$userId, $today]); }
        else { $db->prepare('INSERT INTO usage_log (guest_ip, log_date, rewrites_today) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1')->execute([$guestIp, $today]); }
    }
    echo json_encode(['success' => true, 'classification' => $taResult['classification'] ?? 'Negative Message', 'v1' => $taResult['v1'], 'v2' => $taResult['v2'], 'v3' => $taResult['v3'], 'v1_label' => $taResult['v1_label'] ?? 'Witty Jujitsu', 'v2_label' => $taResult['v2_label'] ?? 'Calm Fact-Check', 'v3_label' => $taResult['v3_label'] ?? 'Kill With Kindness']);
    exit;
}

// ── Tone & platform descriptions ──────────────────────────────────────────────
$toneMap = [
  'Professional' => 'professional and business-appropriate',
  'Formal'       => 'formal, polished, and highly professional',
  'Friendly'     => 'warm, friendly, and approachable',
  'Assertive'    => 'confident, direct, and assertive',
  'Empathetic'   => 'empathetic, understanding, and emotionally intelligent',
  'Witty'        => 'clever, witty, and lightly humorous',
  'Direct'       => 'clear, concise, and straight to the point',
  'Casual'       => 'casual and conversational',
  'Apologetic'   => 'apologetic, sincere, and solution-focused',
];
$platMap = [
  'Email'     => 'professional email',
  'WhatsApp'  => 'WhatsApp message',
  'LinkedIn'  => 'LinkedIn professional message',
  'SMS'       => 'short SMS text message',
  'Slack'     => 'Slack workplace message',
  'Text'      => 'casual text message',
  'Facebook'  => 'Facebook message',
];

$toneDesc        = $toneMap[$tone]  ?? strtolower($tone);
$tone2Desc       = ($tone2 && $tone2 !== 'None' && $tone2 !== $tone) ? ($toneMap[$tone2] ?? strtolower($tone2)) : null;
$blendedToneDesc = $tone2Desc ? "a blend of {$toneDesc} and {$tone2Desc}" : $toneDesc;
$platCtx         = $platMap[$platform] ?? $platform;

$lengthInstruction = match($length) {
  'Shorter' => 'Each version should be noticeably shorter than the input.',
  'Longer'  => 'Each version should be more detailed and longer than the input.',
  default   => 'Keep each version roughly the same length as the input.',
};
$emojiInstruction    = $emoji          ? 'Naturally weave in 1–2 relevant emojis per version.'   : 'Do not use any emojis.';
$languageInstruction = ($language && $language !== 'English') ? "Write all 3 versions in {$language}." : 'Write in English.';
$sesqInstruction     = $sesquipedalian ? 'IMPORTANT: Use elaborate, sophisticated, sesquipedalian vocabulary — select the most impressive, uncommon, and intellectually formidable words available. Replace simple words with their grandiloquent equivalents. The output should sound erudite and intellectually formidable while still conveying the original meaning with perspicuity.' : '';
$customInstrLine     = $customInstr    ? "Additional user instructions: {$customInstr}" : '';

// Warmth / Firmness sliders instruction
$sliderInstruction = '';
if ($warmth >= 1 && $warmth <= 5) {
    $wDesc = ['icy cold and detached', 'cool and minimal', 'neutral', 'warm and personable', 'very warm, caring, and heartfelt'][$warmth - 1];
    $sliderInstruction .= "WARMTH LEVEL {$warmth}/5: Be {$wDesc}. ";
}
if ($firmness >= 1 && $firmness <= 5) {
    $fDesc = ['very gentle, flexible, and accommodating', 'slightly firm but mostly open', 'balanced — firm yet friendly', 'firm, direct, and confident', 'very firm, assertive, and unwavering'][$firmness - 1];
    $sliderInstruction .= "FIRMNESS LEVEL {$firmness}/5: Be {$fDesc}.";
}

// ── Human Touch (premium): make output bypass AI detectors ───────────────────
$humanTouchInstruction = '';
if ($humanTouch && $isPremium) {
    $humanTouchInstruction = 'HUMAN TOUCH MODE: Write in a way that is completely undetectable as AI-generated. '
        . 'Use natural imperfections, varied sentence rhythm, occasional contractions, conversational phrasing, '
        . 'and subtle personal touches. Avoid overly polished or symmetric structure. '
        . 'Mix longer and shorter sentences. Sound genuinely human, not like AI output.';
}

// ── My Voice (premium): inject personal style ────────────────────────────────
$voiceInstruction = '';
if ($useVoice && $isPremium && $userId) {
    $vSt = $db->prepare('SELECT style_summary FROM user_voices WHERE user_id = ?');
    $vSt->execute([$userId]);
    $vRow = $vSt->fetch();
    if ($vRow && $vRow['style_summary']) {
        $voiceInstruction = "MY VOICE PROFILE — Match this writing style closely:\n" . $vRow['style_summary'];
    }
}

// ── Build prompt ──────────────────────────────────────────────────────────────
if ($mode === 'reply') {
  $userPrompt = "A user received this message:\n\"{$message}\"\n\n"
              . "Generate 3 distinctly different {$blendedToneDesc} reply strategies for a {$platCtx}. "
              . "Each reply should take a noticeably different approach or angle. "
              . "For each reply, also provide a short 2-4 word label that captures its unique approach (e.g. 'Direct & Clear', 'Warm & Empathetic', 'Brief & Assertive', 'Firm but Polite', 'Enthusiastic & Open'). "
              . "{$lengthInstruction} {$emojiInstruction} {$languageInstruction}\n\n"
              . "Respond ONLY with valid JSON: {\"v1\":\"...\",\"v1_label\":\"...\",\"v2\":\"...\",\"v2_label\":\"...\",\"v3\":\"...\",\"v3_label\":\"...\"}";
} elseif ($mode === 'followup') {
} elseif ($mode === 'followup') {
  $src = $originalMessage ?: $message;
  $userPrompt = "Original message sent:\n\"{$src}\"\n\n"
              . "Generate 3 distinct {$blendedToneDesc} follow-up message versions for a {$platCtx}. "
              . "Each should feel natural, reference the original, and prompt a response. "
              . "{$lengthInstruction} {$emojiInstruction} {$languageInstruction}";
} else {
  $userPrompt = "Rewrite this message in 3 distinct {$blendedToneDesc} versions for a {$platCtx}:\n\n\"{$message}\"\n\n"
              . "Each version should sound different from the others while conveying the same core meaning. "
              . "{$lengthInstruction} {$emojiInstruction} {$languageInstruction}";
}
if ($sesqInstruction)       $userPrompt .= "\n\n" . $sesqInstruction;
if ($sliderInstruction)     $userPrompt .= "\n\n" . $sliderInstruction;
if ($customInstrLine)       $userPrompt .= "\n\n" . $customInstrLine;
if ($humanTouchInstruction) $userPrompt .= "\n\n" . $humanTouchInstruction;
if ($voiceInstruction)      $userPrompt .= "\n\n" . $voiceInstruction;
if ($mode !== 'reply') {
  $userPrompt .= "\n\nRespond ONLY with valid JSON: {\"v1\":\"...\",\"v2\":\"...\",\"v3\":\"...\"}";
}

// ── Call OpenAI ───────────────────────────────────────────────────────────────
$payload = json_encode([
  'model'           => 'gpt-4o',
  'temperature'     => 0.82,
  'max_tokens'      => ($mode === 'reply' ? 1100 : 900),
  'response_format' => ['type' => 'json_object'],
  'messages'        => [
    ['role' => 'system', 'content' =>
      'You are ReplyTonic, an expert AI message rewriter. '
    . 'You produce polished, context-aware message rewrites that feel natural and human. '
    . 'Always return exactly the JSON format requested — nothing else.'],
    ['role' => 'user', 'content' => $userPrompt],
  ],
]);

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    "Authorization: Bearer {$OPENAI_API_KEY}",
  ],
  CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
  http_response_code(500);
  echo json_encode(['error' => 'AI request failed. Please try again.']);
  exit;
}

$data    = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';
$result  = json_decode($content, true);

if (!isset($result['v1'], $result['v2'], $result['v3'])) {
  http_response_code(500);
  echo json_encode(['error' => 'Unexpected AI response. Please try again.']);
  exit;
}

// ── Increment usage counter ───────────────────────────────────────────────────
if (!$isPremium) {
    if ($userId) {
        $db->prepare('
            INSERT INTO usage_log (user_id, log_date, rewrites_today)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1
        ')->execute([$userId, $today]);
    } else {
        $db->prepare('
            INSERT INTO usage_log (guest_ip, log_date, rewrites_today)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE rewrites_today = rewrites_today + 1
        ')->execute([$guestIp, $today]);
    }
}

// ── Auto-save to cloud history (logged-in users) ──────────────────────────────
if ($userId && $mode !== 'followup') {
    try {
        $db->prepare('
            INSERT INTO rewrite_history (user_id, original_text, v1, v2, v3, platform, tone, language, mode)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $userId,
            substr($message, 0, 2000),
            substr($result['v1'], 0, 2000),
            substr($result['v2'], 0, 2000),
            substr($result['v3'], 0, 2000),
            substr($platform, 0, 50),
            substr($tone, 0, 50),
            substr($language, 0, 50),
            substr($mode, 0, 20),
        ]);
        // Prune to 100 items
        $db->prepare('
            DELETE FROM rewrite_history
            WHERE user_id = ? AND id NOT IN (
                SELECT id FROM (
                    SELECT id FROM rewrite_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 100
                ) t
            )
        ')->execute([$userId, $userId]);
    } catch (Exception $e) { /* non-critical */ }
}

$out = ['success' => true, 'v1' => $result['v1'], 'v2' => $result['v2'], 'v3' => $result['v3']];
if ($mode === 'reply') {
  if (!empty($result['v1_label'])) $out['v1_label'] = $result['v1_label'];
  if (!empty($result['v2_label'])) $out['v2_label'] = $result['v2_label'];
  if (!empty($result['v3_label'])) $out['v3_label'] = $result['v3_label'];
}
echo json_encode($out);
