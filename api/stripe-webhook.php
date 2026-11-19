<?php
/**
 * Stripe Webhook Handler — ReplyTonic
 * Events: checkout.session.completed, customer.subscription.created/updated/deleted,
 *         invoice.payment_succeeded, invoice.payment_failed
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// --- Signature verification ---
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if (!$sig_header || !$payload) {
    http_response_code(400);
    exit('Missing payload or signature');
}

// Parse t= and v1= from Stripe-Signature header
$parts = [];
foreach (explode(',', $sig_header) as $part) {
    [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
    $parts[$k] = $v;
}
$timestamp  = $parts['t']  ?? '';
$signature  = $parts['v1'] ?? '';

$signed_payload = $timestamp . '.' . $payload;
$expected_sig   = hash_hmac('sha256', $signed_payload, STRIPE_WEBHOOK_SECRET);

if (!hash_equals($expected_sig, $signature)) {
    http_response_code(400);
    exit('Invalid signature');
}

// Reject events older than 5 minutes (replay protection)
if (abs(time() - (int)$timestamp) > 300) {
    http_response_code(400);
    exit('Timestamp too old');
}

$event = json_decode($payload, true);
if (!$event) {
    http_response_code(400);
    exit('Invalid JSON');
}

$type = $event['type'] ?? '';
$obj  = $event['data']['object'] ?? [];

$pdo = get_db();

// --- Helper: look up user by stripe_customer_id ---
function get_user_by_customer(PDO $pdo, string $customer_id): ?array {
    $stmt = $pdo->prepare('SELECT id, email, plan FROM users WHERE stripe_customer_id = ? LIMIT 1');
    $stmt->execute([$customer_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// --- Helper: determine plan label from price_id ---
function plan_from_price(string $price_id): string {
    if ($price_id === STRIPE_PRICE_YEARLY)  return 'premium_yearly';
    if ($price_id === STRIPE_PRICE_MONTHLY) return 'premium_monthly';
    return 'premium';
}

// --- Helper: set user plan + expiry, store stripe_customer_id ---
function activate_plan(PDO $pdo, int $user_id, string $plan, string $customer_id,
                       ?int $current_period_end = null): void {
    $expires = $current_period_end ? date('Y-m-d H:i:s', $current_period_end) : null;
    $stmt = $pdo->prepare(
        'UPDATE users SET plan = ?, stripe_customer_id = ?, plan_expires_at = ? WHERE id = ?'
    );
    $stmt->execute([$plan, $customer_id, $expires, $user_id]);
}

// --- Helper: cancel / downgrade user plan ---
function cancel_plan(PDO $pdo, string $customer_id): void {
    $stmt = $pdo->prepare(
        "UPDATE users SET plan = 'free', plan_expires_at = NULL WHERE stripe_customer_id = ?"
    );
    $stmt->execute([$customer_id]);
}

// =====================================================
// Event dispatch
// =====================================================
switch ($type) {

    // -------------------------------------------------
    // Checkout completed — user just paid for the first time
    // -------------------------------------------------
    case 'checkout.session.completed':
        $user_id     = (int)($obj['client_reference_id'] ?? 0);
        $customer_id = $obj['customer'] ?? '';
        $sub_id      = $obj['subscription'] ?? '';

        if (!$user_id || !$customer_id) break;

        // Fetch subscription from Stripe to get price_id + period_end
        $sub_data = webhook_stripe_get('subscriptions/' . $sub_id);
        $item     = $sub_data['items']['data'][0] ?? [];
        $price_id = $item['price']['id'] ?? '';
        $period_end = $sub_data['current_period_end'] ?? null;
        $plan     = $price_id ? plan_from_price($price_id) : 'premium';

        $stmt = $pdo->prepare(
            'UPDATE users SET plan = ?, stripe_customer_id = ?, plan_expires_at = ? WHERE id = ?'
        );
        $stmt->execute([
            $plan,
            $customer_id,
            $period_end ? date('Y-m-d H:i:s', $period_end) : null,
            $user_id,
        ]);
        break;

    // -------------------------------------------------
    // Subscription created (fires after checkout on initial sign-up)
    // -------------------------------------------------
    case 'customer.subscription.created':
        $customer_id = $obj['customer'] ?? '';
        $status      = $obj['status'] ?? '';
        if (!$customer_id || $status !== 'active') break;

        $user = get_user_by_customer($pdo, $customer_id);
        if (!$user) break;

        $price_id   = $obj['items']['data'][0]['price']['id'] ?? '';
        $period_end = $obj['current_period_end'] ?? null;
        $plan       = $price_id ? plan_from_price($price_id) : 'premium';

        activate_plan($pdo, (int)$user['id'], $plan, $customer_id, $period_end);
        break;

    // -------------------------------------------------
    // Subscription updated (plan change, renewal, cancel-at-period-end set, etc.)
    // -------------------------------------------------
    case 'customer.subscription.updated':
        $customer_id = $obj['customer'] ?? '';
        $status      = $obj['status'] ?? '';
        if (!$customer_id) break;

        $user = get_user_by_customer($pdo, $customer_id);
        if (!$user) break;

        if (in_array($status, ['active', 'trialing'])) {
            $price_id   = $obj['items']['data'][0]['price']['id'] ?? '';
            $period_end = $obj['current_period_end'] ?? null;
            $plan       = $price_id ? plan_from_price($price_id) : 'premium';
            activate_plan($pdo, (int)$user['id'], $plan, $customer_id, $period_end);
        } elseif (in_array($status, ['canceled', 'unpaid', 'incomplete_expired'])) {
            cancel_plan($pdo, $customer_id);
        }
        break;

    // -------------------------------------------------
    // Subscription deleted / cancelled
    // -------------------------------------------------
    case 'customer.subscription.deleted':
        $customer_id = $obj['customer'] ?? '';
        if ($customer_id) cancel_plan($pdo, $customer_id);
        break;

    // -------------------------------------------------
    // Invoice paid — extend expiry on renewal
    // -------------------------------------------------
    case 'invoice.payment_succeeded':
        $customer_id = $obj['customer'] ?? '';
        $sub_id      = $obj['subscription'] ?? '';
        $billing_reason = $obj['billing_reason'] ?? '';

        // 'subscription_create' is handled by checkout.session.completed; skip here
        if ($billing_reason === 'subscription_create' || !$customer_id) break;

        $user = get_user_by_customer($pdo, $customer_id);
        if (!$user) break;

        // Extend expiry using the subscription's current period end
        if ($sub_id) {
            $sub_data   = webhook_stripe_get('subscriptions/' . $sub_id);
            $period_end = $sub_data['current_period_end'] ?? null;
            $price_id   = $sub_data['items']['data'][0]['price']['id'] ?? '';
            $plan       = $price_id ? plan_from_price($price_id) : ($user['plan'] ?: 'premium');
            activate_plan($pdo, (int)$user['id'], $plan, $customer_id, $period_end);
        }
        break;

    // -------------------------------------------------
    // Invoice payment failed — mark plan as past_due
    // -------------------------------------------------
    case 'invoice.payment_failed':
        $customer_id = $obj['customer'] ?? '';
        if (!$customer_id) break;

        // Keep the plan active but flag it; Stripe will retry and eventually send
        // customer.subscription.updated with status=unpaid which downgrades the plan.
        // We update plan_expires_at to now so access gates can check imminently.
        $stmt = $pdo->prepare(
            "UPDATE users SET plan_expires_at = NOW() WHERE stripe_customer_id = ? AND plan != 'free'"
        );
        $stmt->execute([$customer_id]);
        break;
}

// Always return 200 so Stripe doesn't retry
http_response_code(200);
echo json_encode(['received' => true]);

// =====================================================
// Stripe API GET helper (curl, no library)
// Prefixed to avoid name collision if ever included alongside stripe.php
// =====================================================
function webhook_stripe_get(string $endpoint): array {
    $ch = curl_init('https://api.stripe.com/v1/' . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
    $res = curl_exec($ch);
    curl_close($ch);
    return $res ? (json_decode($res, true) ?? []) : [];
}
