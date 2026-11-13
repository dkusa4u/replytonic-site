<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit; }

// ── API Key ──────────────────────────────────────────────────────────────────
// Key is stored in config.php (not tracked by git — safe to edit on Hostinger)
require_once __DIR__ . '/config.php';

// ── Input ────────────────────────────────────────────────────────────────────
$input           = json_decode(file_get_contents('php://input'), true) ?: [];
$message         = trim($input['message']         ?? '');
$originalMessage = trim($input['originalMessage'] ?? '');
$platform        = $input['platform']  ?? 'Email';
$tone            = $input['tone']      ?? 'Professional';
$tone2           = $input['tone2']     ?? 'None';
$language        = $input['language']  ?? 'English';
$length          = $input['length']    ?? 'Same';
$emoji           = $input['emoji']     ?? false;
$mode            = $input['mode']      ?? 'rewrite';

if (!$message) { http_response_code(400); echo json_encode(['error'=>'Message is required']); exit; }

// ── Tone & platform descriptions ─────────────────────────────────────────────
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

$toneDesc  = $toneMap[$tone]  ?? strtolower($tone);
$tone2Desc = ($tone2 && $tone2 !== 'None' && $tone2 !== $tone) ? ($toneMap[$tone2] ?? strtolower($tone2)) : null;
$blendedToneDesc = $tone2Desc ? "a blend of {$toneDesc} and {$tone2Desc}" : $toneDesc;
$platCtx   = $platMap[$platform] ?? $platform;

$lengthInstruction = match($length) {
  'Shorter' => 'Each version should be noticeably shorter than the input.',
  'Longer'  => 'Each version should be more detailed and longer than the input.',
  default   => 'Keep each version roughly the same length as the input.',
};
$emojiInstruction    = $emoji   ? 'Naturally weave in 1–2 relevant emojis per version.' : 'Do not use any emojis.';
$languageInstruction = ($language && $language !== 'English') ? "Write all 3 versions in {$language}." : 'Write in English.';

// ── Build prompt ─────────────────────────────────────────────────────────────
if ($mode === 'reply') {
  $userPrompt = "A user received this message:\n\"{$originalMessage}\"\n\n"
              . "They want to reply with:\n\"{$message}\"\n\n"
              . "Generate 3 distinct {$blendedToneDesc} reply versions for a {$platCtx}. "
              . "{$lengthInstruction} {$emojiInstruction} {$languageInstruction}";
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
$userPrompt .= "\n\nRespond ONLY with valid JSON: {\"v1\":\"...\",\"v2\":\"...\",\"v3\":\"...\"}";

// ── Call OpenAI ───────────────────────────────────────────────────────────────
$payload = json_encode([
  'model'           => 'gpt-4o',
  'temperature'     => 0.82,
  'max_tokens'      => 900,
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
  CURLOPT_TIMEOUT        => 30,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $httpCode !== 200) {
  http_response_code(500);
  echo json_encode(['error' => 'AI request failed. Please try again.']);
  exit;
}

$data = json_decode($response, true);
$content = $data['choices'][0]['message']['content'] ?? '';
$result  = json_decode($content, true);

if (!isset($result['v1'], $result['v2'], $result['v3'])) {
  http_response_code(500);
  echo json_encode(['error' => 'Unexpected AI response. Please try again.']);
  exit;
}

echo json_encode(['success' => true, 'v1' => $result['v1'], 'v2' => $result['v2'], 'v3' => $result['v3']]);
