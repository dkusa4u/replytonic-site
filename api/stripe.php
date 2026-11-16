<?php
// api/stripe.php — create checkout session and customer portal (no Stripe library needed)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Auth-Token');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$claims = get_auth_user();
if (!$claims) json_out(['error' => 'Please log in to upgrade.'], 401);

$uid   = (int)$claims['sub'];
$email = $claims['email'];
$body  = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? 'checkout';

function stripe_post($path, $data) {
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
        CURLOPT_POSTFIELDS     => http_build_query($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    return json_decode($resp, true);
}

function stripe_get($path) {
    $ch = curl_init('https://api.stripe.com/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    return json_decode($resp, true);
}

switch ($action) {

    // ── Create checkout session ───────────────────────────────────────────
    case 'checkout': {
        $plan = $body['plan'] ?? 'monthly'; // 'monthly' or 'yearly'
        $priceId = ($plan === 'yearly') ? STRIPE_PRICE_YEARLY : STRIPE_PRICE_MONTHLY;

        // Get or create Stripe customer
        $db = get_db();
        $st = $db->prepare('SELECT stripe_customer_id FROM users WHERE id = ?');
        $st->execute([$uid]);
        $userRow = $st->fetch();
        $customerId = $userRow ? $userRow['stripe_customer_id'] : null;

        if (!$customerId) {
            $cust = stripe_post('/customers', ['email' => $email, 'metadata[user_id]' => $uid]);
            if (isset($cust['error'])) json_out(['error' => 'Could not create billing account. Please try again.'], 500);
            $customerId = $cust['id'];
            $db->prepare('UPDATE users SET stripe_customer_id = ? WHERE id = ?')->execute([$customerId, $uid]);
        }

        $session = stripe_post('/checkout/sessions', [
            'customer'                                              => $customerId,
            'line_items[0][price]'                                 => $priceId,
            'line_items[0][quantity]'                              => 1,
            'mode'                                                 => 'subscription',
            'success_url'                                          => APP_URL . '?upgrade=success',
            'cancel_url'                                           => APP_URL . '?upgrade=cancel',
            'subscription_data[metadata][user_id]'                 => $uid,
            'allow_promotion_codes'                                => 'true',
        ]);

        if (isset($session['error'])) json_out(['error' => 'Could not start checkout: ' . $session['error']['message']], 500);
        json_out(['success' => true, 'url' => $session['url']]);
    }

    // ── Customer portal (manage/cancel subscription) ──────────────────────
    case 'portal': {
        $db = get_db();
        $st = $db->prepare('SELECT stripe_customer_id FROM users WHERE id = ?');
        $st->execute([$uid]);
        $row = $st->fetch();
        if (!$row || !$row['stripe_customer_id']) json_out(['error' => 'No billing account found.'], 404);

        $portal = stripe_post('/billing_portal/sessions', [
            'customer'   => $row['stripe_customer_id'],
            'return_url' => APP_URL,
        ]);
        if (isset($portal['error'])) json_out(['error' => 'Could not open billing portal.'], 500);
        json_out(['success' => true, 'url' => $portal['url']]);
    }

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
