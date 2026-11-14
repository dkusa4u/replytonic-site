<?php
// api/stripe-webhook.php — handle Stripe subscription lifecycle events
// Set webhook URL in Stripe Dashboard → Developers → Webhooks:
//   https://replytonic.com/api/stripe-webhook.php
// Events to listen for:
//   customer.subscription.created, customer.subscription.updated, customer.subscription.deleted

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$payload = file_get_contents('php://input');
$sig     = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify webhook signature
$event = stripe_webhook_verify($payload, $sig, STRIPE_WEBHOOK_SECRET);
if (!$event) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    exit;
}

$type = $event['type'];
$sub  = $event['data']['object'];

$db = get_db();

function set_user_premium($customerId, $subscriptionId, $expiresAt, $plan) {
    global $db;
    $db->prepare('
        UPDATE users
        SET plan = ?, stripe_subscription_id = ?, plan_expires_at = ?
        WHERE stripe_customer_id = ?
    ')->execute([$plan, $subscriptionId, $expiresAt, $customerId]);
}

switch ($type) {

    case 'customer.subscription.created':
    case 'customer.subscription.updated': {
        $customerId     = $sub['customer'];
        $subscriptionId = $sub['id'];
        $status         = $sub['status'];
        // active or trialing = premium
        $isPremium = in_array($status, ['active', 'trialing']);

        $periodEnd = $sub['current_period_end'] ?? null;
        $expiresAt = $periodEnd ? date('Y-m-d H:i:s', $periodEnd) : null;

        set_user_premium($customerId, $subscriptionId, $expiresAt, $isPremium ? 'premium' : 'free');
        break;
    }

    case 'customer.subscription.deleted': {
        $customerId = $sub['customer'];
        $db->prepare('
            UPDATE users SET plan = ?, plan_expires_at = NULL WHERE stripe_customer_id = ?
        ')->execute(['free', $customerId]);
        break;
    }
}

http_response_code(200);
echo json_encode(['received' => true]);

// ── Verify Stripe webhook signature ──────────────────────────────────────────
function stripe_webhook_verify($payload, $sigHeader, $secret) {
    if (!$sigHeader) return false;
    $parts = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = explode('=', $part, 2);
        $parts[$k] = $v;
    }
    $timestamp = $parts['t'] ?? 0;
    $v1        = $parts['v1'] ?? '';
    // Reject if timestamp is more than 5 minutes old
    if (abs(time() - $timestamp) > 300) return false;
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    if (!hash_equals($expected, $v1)) return false;
    return json_decode($payload, true);
}
