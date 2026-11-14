<?php
/**
 * ReplyTonic — Plagiarism Checker
 * Uses GPT-4o to perform deep originality analysis:
 *   - Sentence-level originality scoring
 *   - Detection of copied/boilerplate phrases
 *   - Likely source categories identified
 *   - Originality score + verdict + rewrite suggestions
 */

// ── CORS ──────────────────────────────────────────────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Config ────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth_helper.php';

// ── Auth + rate limit ─────────────────────────────────────────────────────────
$user = verify_token_and_check_limit();   // dies with JSON error on failure
$uid  = $user['user_id'];
$plan = $user['plan'] ?? 'free';

// ── Input ─────────────────────────────────────────────────────────────────────
$input = json_decode(file_get_contents('php://input'), true);
$text  = trim($input['text'] ?? '');

if (strlen($text) < 30) {
    http_response_code(400);
    echo json_encode(['error' => 'Text must be at least 30 characters.']);
    exit;
}
if (strlen($text) > 8000) {
    http_response_code(400);
    echo json_encode(['error' => 'Text too long. Maximum 8,000 characters per check.']);
    exit;
}

// ── Build GPT-4o prompt ───────────────────────────────────────────────────────
$word_count = str_word_count($text);

$system_prompt = <<<'PROMPT'
You are an expert plagiarism detection engine with access to encyclopedic knowledge of published works, academic papers, news articles, marketing copy, Wikipedia, and common internet boilerplate text. Your job is to deeply analyze text for originality.

For every piece of text, you:
1. Identify sentences or phrases that appear verbatim or near-verbatim in known published sources
2. Flag boilerplate language, clichés, and stock phrases commonly found online
3. Detect text that follows generic templates (legal, marketing, academic)
4. Assess overall originality on a 0–100 scale where 100 = fully original
5. Provide specific, actionable rewriting suggestions

Respond ONLY with valid JSON. No markdown, no code blocks.
PROMPT;

$user_prompt = <<<PROMPT
Analyze the following text for plagiarism and originality. Provide a thorough analysis.

TEXT TO ANALYZE:
---
{$text}
---

Return a JSON object with this exact structure:
{
  "originality_score": <integer 0-100, where 100 = fully original>,
  "plagiarism_score": <integer 0-100, where 100 = fully plagiarized — always equals 100 minus originality_score>,
  "verdict": "<one sentence overall verdict>",
  "confidence": "<High | Medium | Low>",
  "risk_level": "<None | Low | Moderate | High | Critical>",
  "flagged_phrases": [
    {
      "phrase": "<exact phrase from the text>",
      "reason": "<why it was flagged: verbatim match, boilerplate, cliché, generic template, etc.>",
      "likely_source": "<Wikipedia | Academic paper | News article | Marketing copy | Legal template | Common internet | Unknown>",
      "severity": "<Low | Medium | High>"
    }
  ],
  "original_phrases": ["<phrase that is clearly original/unique>"],
  "source_categories": ["<categories of likely sources where matches were found>"],
  "explanation": "<2-3 sentence detailed explanation of the analysis>",
  "suggestions": [
    "<specific rewriting suggestion to improve originality>"
  ],
  "sentence_scores": [
    {
      "sentence": "<first ~60 chars of the sentence>",
      "originality": <0-100>
    }
  ]
}

Rules:
- flagged_phrases: include up to 8 most significant matches
- original_phrases: include up to 5 clearly original phrases
- sentence_scores: include ALL sentences (split on period/question mark/exclamation)
- suggestions: include 2-4 specific, actionable suggestions
- Be strict but fair — common words and short phrases should not be flagged
PROMPT;

// ── Call GPT-4o ────────────────────────────────────────────────────────────────
$payload = [
    'model'       => 'gpt-4o',
    'temperature' => 0.1,
    'max_tokens'  => 1200,
    'messages'    => [
        ['role' => 'system', 'content' => $system_prompt],
        ['role' => 'user',   'content' => $user_prompt],
    ],
];

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OPENAI_API_KEY,
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT    => 45,
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$response || $http_code !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'AI service unavailable. Please try again.']);
    exit;
}

$openai  = json_decode($response, true);
$content = $openai['choices'][0]['message']['content'] ?? '';

// Strip markdown code blocks if GPT wraps them
$content = preg_replace('/^```json\s*/i', '', trim($content));
$content = preg_replace('/\s*```$/i', '',  $content);

$result = json_decode($content, true);

if (!$result || !isset($result['originality_score'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to parse analysis. Please try again.']);
    exit;
}

// ── Log usage ─────────────────────────────────────────────────────────────────
log_usage($uid, 'plagiarism');

// ── Return ────────────────────────────────────────────────────────────────────
echo json_encode(array_merge($result, [
    'word_count' => $word_count,
    'char_count' => strlen($text),
]));
