<?php
/**
 * PTP Stripe Payment Integration v113
 * Handles payments, refunds, and Connect payouts
 * 
 * CHANGELOG v113:
 * - Improved payment intent creation with better error handling
 * - Added idempotency key support to prevent duplicate charges
 * - Better logging for debugging payment issues
 * - Standardized API response handling
 * - Added payment intent retrieval caching
 */

defined('ABSPATH') || exit;

class PTP_Stripe {
    
    private static $secret_key;
    private static $publishable_key;
    private static $webhook_secret;
    private static $connect_enabled = false;
    private static $test_mode = true;
    private static $initialized = false;
    
    // Cache for payment intents to avoid duplicate API calls
    private static $intent_cache = array();
    
    public static function init() {
        if (self::$initialized) return;
        
        self::$test_mode = get_option('ptp_stripe_test_mode', true);
        
        if (self::$test_mode) {
            self::$secret_key = get_option('ptp_stripe_test_secret', '');
            self::$publishable_key = get_option('ptp_stripe_test_publishable', '');
        } else {
            self::$secret_key = get_option('ptp_stripe_live_secret', '');
            self::$publishable_key = get_option('ptp_stripe_live_publishable', '');
        }
        
        // Fallback to legacy option names
        if (empty(self::$secret_key)) {
            self::$secret_key = get_option('ptp_stripe_secret_key', '');
        }
        if (empty(self::$publishable_key)) {
            self::$publishable_key = get_option('ptp_stripe_publishable_key', '');
        }
        
        self::$webhook_secret = get_option('ptp_stripe_webhook_secret', '');
        self::$connect_enabled = (bool) get_option('ptp_stripe_connect_enabled', false);
        
        self::$initialized = true;
        
        // Register webhook endpoint
        add_action('rest_api_init', array(__CLASS__, 'register_webhook'));
        
        // Add Stripe JS
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'));
    }
    
    /**
     * Ensure initialization (call before any API method)
     */
    private static function ensure_init() {
        if (!self::$initialized) {
            self::init();
        }
    }
    
    /**
     * Check if Stripe is configured
     */
    public static function is_enabled() {
        self::ensure_init();
        return !empty(self::$secret_key) && !empty(self::$publishable_key);
    }
    
    /**
     * Check if Connect is enabled
     */
    public static function is_connect_enabled() {
        self::ensure_init();
        return self::$connect_enabled && self::is_enabled();
    }
    
    /**
     * Get configuration status for debugging
     */
    public static function get_config_status() {
        self::ensure_init();
        return array(
            'test_mode' => self::$test_mode,
            'has_secret_key' => !empty(self::$secret_key),
            'has_publishable_key' => !empty(self::$publishable_key),
            'connect_enabled' => self::$connect_enabled,
            'is_enabled' => self::is_enabled(),
        );
    }
    
    /**
     * Get publishable key for frontend
     */
    public static function get_publishable_key() {
        self::ensure_init();
        return self::$publishable_key;
    }
    
    /**
     * Enqueue Stripe JS
     */
    public static function enqueue_scripts() {
        if (!self::is_enabled()) return;
        
        // Only load on checkout pages
        if (is_page(array('checkout', 'ptp-checkout', 'book-session'))) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
            wp_localize_script('ptp-frontend', 'ptpStripe', array(
                'publishableKey' => self::$publishable_key,
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ptp_nonce'),
            ));
        }
    }
    
    /**
     * Make Stripe API request with improved error handling
     */
    private static function api_request($endpoint, $method = 'POST', $data = array(), $idempotency_key = null) {
        self::ensure_init();
        
        if (empty(self::$secret_key)) {
            error_log('PTP Stripe API: No secret key configured');
            return new WP_Error('no_api_key', 'Stripe API key not configured');
        }
        
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $headers = array(
            'Authorization' => 'Bearer ' . self::$secret_key,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Stripe-Version' => '2023-10-16', // Pin API version for consistency
        );
        
        // Add idempotency key to prevent duplicate charges
        if ($idempotency_key) {
            $headers['Idempotency-Key'] = $idempotency_key;
        }
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 60,
        );
        
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = $data;
        }
        
        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("PTP Stripe API: $method $endpoint");
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('PTP Stripe API: WP Error - ' . $response->get_error_message());
            return $response;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("PTP Stripe API: Response code $http_code");
        }
        
        if (isset($body['error'])) {
            $error_message = $body['error']['message'] ?? 'Unknown Stripe error';
            $error_code = $body['error']['code'] ?? 'stripe_error';
            $error_type = $body['error']['type'] ?? 'api_error';
            
            error_log("PTP Stripe API Error [$error_type/$error_code]: $error_message");
            
            return new WP_Error($error_code, $error_message, $body['error']);
        }
        
        return $body;
    }
    
    /**
     * Create Payment Intent with idempotency support
     * Funds held in platform account until session confirmed
     */
    public static function create_payment_intent($amount, $metadata = array(), $idempotency_key = null) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PTP Stripe: create_payment_intent called with amount ' . $amount);
        }
        
        if (!self::is_enabled()) {
            error_log('PTP Stripe: Not enabled - check API keys');
            return new WP_Error('stripe_not_configured', 'Stripe is not configured. Please check API keys in PTP Settings.');
        }
        
        // Validate amount
        $amount = floatval($amount);
        if ($amount < 0.50) {
            return new WP_Error('invalid_amount', 'Payment amount must be at least $0.50');
        }
        
        // Generate idempotency key if not provided (prevents duplicate charges on retry)
        if (!$idempotency_key && !empty($metadata['booking_id'])) {
            $idempotency_key = 'ptp_booking_' . $metadata['booking_id'] . '_' . time();
        } elseif (!$idempotency_key) {
            $idempotency_key = 'ptp_' . wp_generate_uuid4();
        }
        
        $data = array(
            'amount' => round($amount * 100), // Convert to cents
            'currency' => 'usd',
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
        );
        
        // Add metadata as properly formatted keys
        if (!empty($metadata)) {
            foreach ($metadata as $key => $value) {
                if (!empty($value)) {
                    $data["metadata[{$key}]"] = is_array($value) ? json_encode($value) : $value;
                }
            }
        }
        
        // Add site identifier
        $data['metadata[site]'] = get_bloginfo('name');
        $data['metadata[environment]'] = self::$test_mode ? 'test' : 'live';
        
        // Add transfer group for tracking (but don't transfer yet)
        if (!empty($metadata['booking_id'])) {
            $data['transfer_group'] = 'booking_' . $metadata['booking_id'];
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PTP Stripe: Creating payment intent with idempotency key: ' . $idempotency_key);
        }
        
        $result = self::api_request('payment_intents', 'POST', $data, $idempotency_key);
        
        if (is_wp_error($result)) {
            error_log('PTP Stripe: Payment intent creation failed - ' . $result->get_error_message());
            return $result;
        }
        
        if (empty($result['id']) || empty($result['client_secret'])) {
            error_log('PTP Stripe: Invalid response - missing id or client_secret');
            return new WP_Error('invalid_response', 'Invalid response from Stripe');
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PTP Stripe: Payment intent created - ID: ' . $result['id']);
        }
        
        // Cache the result
        self::$intent_cache[$result['id']] = $result;
        
        return $result;
    }
    
    /**
     * Confirm Payment Intent
     */
    public static function confirm_payment_intent($payment_intent_id) {
        return self::api_request("payment_intents/{$payment_intent_id}/confirm", 'POST');
    }
    
    /**
     * Retrieve Payment Intent with caching
     */
    public static function get_payment_intent($payment_intent_id) {
        if (empty($payment_intent_id)) {
            return new WP_Error('no_intent_id', 'Payment intent ID is required');
        }
        
        // Check cache first
        if (isset(self::$intent_cache[$payment_intent_id])) {
            return self::$intent_cache[$payment_intent_id];
        }
        
        $result = self::api_request("payment_intents/{$payment_intent_id}", 'GET');
        
        if (!is_wp_error($result)) {
            self::$intent_cache[$payment_intent_id] = $result;
        }
        
        return $result;
    }
    
    /**
     * Create refund
     */
    public static function create_refund($payment_intent_id, $amount = null, $reason = 'requested_by_customer') {
        $data = array(
            'payment_intent' => $payment_intent_id,
            'reason' => $reason,
        );
        
        if ($amount) {
            $data['amount'] = round($amount * 100);
        }
        
        return self::api_request('refunds', 'POST', $data);
    }
    
    /**
     * Create Stripe Connect account for trainer (Express - works for individuals)
     * Trainers don't need to be a business - they can receive payments as individuals
     */
    public static function create_connect_account($trainer_id, $email, $user_data = array()) {
        self::ensure_init();
        
        if (!self::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe API keys are not configured');
        }
        
        if (!self::$connect_enabled) {
            return new WP_Error('connect_not_enabled', 'Stripe Connect is not enabled. Please enable it in WordPress Admin → PTP Settings → Stripe');
        }
        
        // Get trainer info for better onboarding
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        // Express accounts are perfect for individuals - no business required
        // They handle tax forms, identity verification, etc. automatically
        $data = array(
            'type' => 'express',
            'country' => 'US',
            'email' => $email,
            'capabilities[card_payments][requested]' => 'true',
            'capabilities[transfers][requested]' => 'true',
            'business_type' => 'individual', // Key: This allows individuals, not just businesses
            'metadata[trainer_id]' => $trainer_id,
            'metadata[platform]' => 'ptp_training',
            'settings[payouts][schedule][interval]' => 'daily', // Fast payouts
            'settings[payouts][schedule][delay_days]' => 2, // 2-day rolling
        );
        
        // Add business profile for better UX
        if ($trainer) {
            $data['business_profile[name]'] = $trainer->display_name . ' Training';
            $data['business_profile[product_description]'] = 'Private 1-on-1 training sessions';
            $data['business_profile[mcc]'] = '7941'; // Sports instruction
            $data['business_profile[url]'] = home_url('/trainer/' . $trainer->slug . '/');
        }
        
        $account = self::api_request('accounts', 'POST', $data);
        
        if (is_wp_error($account)) {
            error_log('PTP Stripe Connect Error: ' . $account->get_error_message());
            return $account;
        }
        
        // Save account ID to trainer
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_account_id' => $account['id'],
                'stripe_charges_enabled' => 0,
                'stripe_payouts_enabled' => 0,
            ),
            array('id' => $trainer_id)
        );
        
        return $account;
    }
    
    /**
     * Create Connect account onboarding link with proper return URLs
     */
    public static function create_account_link($account_id, $refresh_url = null, $return_url = null) {
        if (!$refresh_url) {
            $refresh_url = home_url('/trainer-onboarding/?stripe_refresh=1');
        }
        if (!$return_url) {
            $return_url = home_url('/trainer-dashboard/?tab=earnings&stripe_connected=1');
        }
        
        $data = array(
            'account' => $account_id,
            'refresh_url' => $refresh_url,
            'return_url' => $return_url,
            'type' => 'account_onboarding',
            'collect' => 'eventually_due', // Collect only required info upfront
        );
        
        return self::api_request('account_links', 'POST', $data);
    }
    
    /**
     * Complete onboarding flow - get or create account then return link
     */
    public static function start_connect_onboarding($trainer_id) {
        self::ensure_init();
        
        if (!self::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe API keys are not configured');
        }
        
        if (!self::$connect_enabled) {
            return new WP_Error('connect_not_enabled', 'Stripe Connect is not enabled');
        }
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email 
             FROM {$wpdb->prefix}ptp_trainers t
             JOIN {$wpdb->prefix}users u ON t.user_id = u.ID
             WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }
        
        $account_id = $trainer->stripe_account_id;
        
        // Create account if doesn't exist
        if (!$account_id) {
            $account = self::create_connect_account($trainer_id, $trainer->user_email);
            
            if (is_wp_error($account)) {
                return $account;
            }
            
            $account_id = $account['id'];
        }
        
        // Check if account needs onboarding
        $account_status = self::get_account($account_id);
        
        if (is_wp_error($account_status)) {
            // Account might have been deleted, create new one
            $account = self::create_connect_account($trainer_id, $trainer->user_email);
            if (is_wp_error($account)) {
                return $account;
            }
            $account_id = $account['id'];
        }
        
        // Create onboarding link
        $link = self::create_account_link($account_id);
        
        if (is_wp_error($link)) {
            return $link;
        }
        
        return array(
            'url' => $link['url'],
            'account_id' => $account_id,
        );
    }
    
    /**
     * Create login link for Connect dashboard
     */
    public static function create_login_link($account_id) {
        return self::api_request("accounts/{$account_id}/login_links", 'POST');
    }
    
    /**
     * Get Connect account status
     */
    public static function get_account($account_id) {
        return self::api_request("accounts/{$account_id}", 'GET');
    }
    
    /**
     * Create direct payout to trainer (if not using Connect)
     */
    public static function create_payout($trainer_id, $amount) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d
        ", $trainer_id));
        
        if (!$trainer) {
            return new WP_Error('trainer_not_found', 'Trainer not found');
        }
        
        // If using Connect, transfer to connected account
        if (self::$connect_enabled && $trainer->stripe_account_id) {
            $data = array(
                'amount' => round($amount * 100),
                'currency' => 'usd',
                'destination' => $trainer->stripe_account_id,
                'metadata[trainer_id]' => $trainer_id,
            );
            
            return self::api_request('transfers', 'POST', $data);
        }
        
        // Otherwise, log for manual payout
        return array(
            'status' => 'pending_manual',
            'amount' => $amount,
            'trainer_id' => $trainer_id,
            'message' => 'Payout queued for manual processing',
        );
    }
    
    /**
     * Create transfer to connected account (alias for create_payout)
     */
    public static function create_transfer($amount_cents, $destination_account_id, $description = '') {
        $data = array(
            'amount' => $amount_cents,
            'currency' => 'usd',
            'destination' => $destination_account_id,
            'description' => $description,
        );
        
        return self::api_request('transfers', 'POST', $data);
    }
    
    /**
     * Process booking payment
     */
    public static function process_booking_payment($booking_id, $payment_method_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, t.stripe_account_id, t.display_name as trainer_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) {
            return new WP_Error('booking_not_found', 'Booking not found');
        }
        
        // Create payment intent
        $intent = self::create_payment_intent($booking->total_amount, array(
            'booking_id' => $booking_id,
            'trainer_id' => $booking->trainer_id,
            'trainer_stripe_account' => $booking->stripe_account_id,
        ));
        
        if (is_wp_error($intent)) {
            return $intent;
        }
        
        // Confirm payment
        $confirm = self::api_request("payment_intents/{$intent['id']}/confirm", 'POST', array(
            'payment_method' => $payment_method_id,
        ));
        
        if (is_wp_error($confirm)) {
            return $confirm;
        }
        
        // Update booking with payment info
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'payment_intent_id' => $intent['id'],
                'payment_status' => $confirm['status'] === 'succeeded' ? 'paid' : $confirm['status'],
            ),
            array('id' => $booking_id)
        );
        
        return $confirm;
    }
    
    /**
     * Handle webhook events
     */
    public static function register_webhook() {
        register_rest_route('ptp/v1', '/stripe-webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle_webhook'),
            'permission_callback' => '__return_true',
        ));
    }
    
    public static function handle_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $request->get_header('stripe-signature');
        
        // Verify webhook signature
        if (self::$webhook_secret) {
            $timestamp = null;
            $signature = null;
            
            foreach (explode(',', $sig_header) as $part) {
                list($key, $value) = explode('=', $part, 2);
                if ($key === 't') $timestamp = $value;
                if ($key === 'v1') $signature = $value;
            }
            
            $signed_payload = $timestamp . '.' . $payload;
            $expected = hash_hmac('sha256', $signed_payload, self::$webhook_secret);
            
            if (!hash_equals($expected, $signature)) {
                return new WP_Error('invalid_signature', 'Invalid webhook signature', array('status' => 400));
            }
        }
        
        $event = json_decode($payload, true);
        
        if (!$event || !isset($event['type'])) {
            return new WP_Error('invalid_payload', 'Invalid webhook payload', array('status' => 400));
        }
        
        global $wpdb;
        
        switch ($event['type']) {
            case 'payment_intent.succeeded':
                $payment_intent = $event['data']['object'];
                $booking_id = $payment_intent['metadata']['booking_id'] ?? null;
                
                if ($booking_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array('payment_status' => 'paid'),
                        array('id' => $booking_id)
                    );
                    
                    // Send confirmation emails
                    PTP_Email::send_booking_confirmation($booking_id);
                    PTP_Email::send_trainer_new_booking($booking_id);
                    
                    // Send SMS if enabled
                    if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                        PTP_SMS::send_booking_confirmation($booking_id);
                        PTP_SMS::send_trainer_new_booking($booking_id);
                    }
                }
                break;
                
            case 'payment_intent.payment_failed':
                $payment_intent = $event['data']['object'];
                $booking_id = $payment_intent['metadata']['booking_id'] ?? null;
                
                if ($booking_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array('payment_status' => 'failed'),
                        array('id' => $booking_id)
                    );
                }
                break;
                
            case 'charge.refunded':
                $charge = $event['data']['object'];
                $payment_intent_id = $charge['payment_intent'];
                
                $wpdb->query($wpdb->prepare("
                    UPDATE {$wpdb->prefix}ptp_bookings 
                    SET payment_status = 'refunded' 
                    WHERE payment_intent_id = %s
                ", $payment_intent_id));
                break;
                
            case 'account.updated':
                // Connect account status changed
                $account = $event['data']['object'];
                $trainer_id = $account['metadata']['trainer_id'] ?? null;
                
                if ($trainer_id) {
                    $charges_enabled = $account['charges_enabled'] ? 1 : 0;
                    $payouts_enabled = $account['payouts_enabled'] ? 1 : 0;
                    
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_trainers',
                        array(
                            'stripe_charges_enabled' => $charges_enabled,
                            'stripe_payouts_enabled' => $payouts_enabled,
                        ),
                        array('id' => $trainer_id)
                    );
                }
                break;
                
            case 'product.created':
                // Training created in Stripe - trigger email notification
                $product = $event['data']['object'];
                $trainer_id = $product['metadata']['trainer_id'] ?? null;
                
                error_log("[PTP Stripe Webhook] product.created received: " . ($product['id'] ?? 'unknown'));
                
                if ($trainer_id) {
                    // Get price info if available
                    $price_amount = null;
                    if (!empty($product['default_price'])) {
                        $price = self::api_request('prices/' . $product['default_price'], 'GET');
                        if (!is_wp_error($price) && isset($price['unit_amount'])) {
                            $price_amount = $price['unit_amount'];
                        }
                    }
                    
                    $product_data = array(
                        'id' => $product['id'],
                        'name' => $product['name'] ?? 'Training Session',
                        'description' => $product['description'] ?? '',
                        'default_price_amount' => $price_amount,
                    );
                    
                    // Store product in database for reference
                    $wpdb->insert(
                        $wpdb->prefix . 'ptp_stripe_products',
                        array(
                            'stripe_product_id' => $product['id'],
                            'trainer_id' => $trainer_id,
                            'name' => $product_data['name'],
                            'description' => $product_data['description'],
                            'price_cents' => $price_amount,
                            'active' => $product['active'] ? 1 : 0,
                            'created_at' => current_time('mysql'),
                        ),
                        array('%s', '%d', '%s', '%s', '%d', '%d', '%s')
                    );
                    
                    // Send email notification
                    if (class_exists('PTP_Email')) {
                        PTP_Email::send_new_training_notification($trainer_id, $product_data);
                    }
                    
                    error_log("[PTP Stripe Webhook] Training notification sent to trainer #$trainer_id for product: " . $product['id']);
                } else {
                    error_log("[PTP Stripe Webhook] product.created - no trainer_id in metadata, skipping notification");
                }
                break;
                
            case 'product.updated':
                // Training updated in Stripe - sync to database
                $product = $event['data']['object'];
                $trainer_id = $product['metadata']['trainer_id'] ?? null;
                
                if ($trainer_id) {
                    $price_amount = null;
                    if (!empty($product['default_price'])) {
                        $price = self::api_request('prices/' . $product['default_price'], 'GET');
                        if (!is_wp_error($price) && isset($price['unit_amount'])) {
                            $price_amount = $price['unit_amount'];
                        }
                    }
                    
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_stripe_products',
                        array(
                            'name' => $product['name'] ?? 'Training Session',
                            'description' => $product['description'] ?? '',
                            'price_cents' => $price_amount,
                            'active' => $product['active'] ? 1 : 0,
                            'updated_at' => current_time('mysql'),
                        ),
                        array('stripe_product_id' => $product['id']),
                        array('%s', '%s', '%d', '%d', '%s'),
                        array('%s')
                    );
                    
                    error_log("[PTP Stripe Webhook] Product updated: " . $product['id']);
                }
                break;
                
            case 'product.deleted':
                // Training deleted in Stripe - mark inactive
                $product = $event['data']['object'];
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_stripe_products',
                    array('active' => 0, 'updated_at' => current_time('mysql')),
                    array('stripe_product_id' => $product['id']),
                    array('%d', '%s'),
                    array('%s')
                );
                
                error_log("[PTP Stripe Webhook] Product deleted/deactivated: " . $product['id']);
                break;
            
            case 'checkout.session.completed':
                // Camp checkout completed - dispatch to camp order handler
                $session = $event['data']['object'];
                $type = $session['metadata']['type'] ?? '';
                
                error_log("[PTP Stripe Webhook] checkout.session.completed: " . $session['id'] . " (type: $type)");
                
                if ($type === 'camp_registration') {
                    // Dispatch to camp orders handler
                    do_action('ptp_stripe_webhook_checkout.session.completed', $event);
                }
                break;
            
            case 'checkout.session.expired':
                // Camp checkout expired
                $session = $event['data']['object'];
                $type = $session['metadata']['type'] ?? '';
                
                error_log("[PTP Stripe Webhook] checkout.session.expired: " . $session['id']);
                
                if ($type === 'camp_registration') {
                    do_action('ptp_stripe_webhook_checkout.session.expired', $event);
                }
                break;
        }
        
        // Dispatch generic event for other handlers
        do_action('ptp_stripe_webhook_received', $event);
        
        return array('received' => true);
    }
    
    /**
     * Cancel and refund booking
     */
    public static function refund_booking($booking_id, $reason = 'requested_by_customer') {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->payment_intent_id) {
            return new WP_Error('no_payment', 'No payment found for this booking');
        }
        
        $refund = self::create_refund($booking->payment_intent_id, null, $reason);
        
        if (is_wp_error($refund)) {
            return $refund;
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'payment_status' => 'refunded',
            ),
            array('id' => $booking_id)
        );
        
        return $refund;
    }
    
    /**
     * Get payment history for booking
     */
    public static function get_payment_history($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->payment_intent_id) {
            return array();
        }
        
        $intent = self::get_payment_intent($booking->payment_intent_id);
        
        if (is_wp_error($intent)) {
            return array();
        }
        
        return array(
            'payment_intent' => $intent,
            'amount' => $intent['amount'] / 100,
            'status' => $intent['status'],
            'created' => date('Y-m-d H:i:s', $intent['created']),
        );
    }
    
    /**
     * Create customer for saved cards
     */
    public static function create_customer($user_id, $email, $name) {
        $data = array(
            'email' => $email,
            'name' => $name,
            'metadata[user_id]' => $user_id,
        );
        
        $customer = self::api_request('customers', 'POST', $data);
        
        if (!is_wp_error($customer)) {
            update_user_meta($user_id, 'ptp_stripe_customer_id', $customer['id']);
        }
        
        return $customer;
    }
    
    /**
     * Get or create Stripe customer
     */
    public static function get_or_create_customer($user_id) {
        $customer_id = get_user_meta($user_id, 'ptp_stripe_customer_id', true);
        
        if ($customer_id) {
            return $customer_id;
        }
        
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'User not found');
        }
        
        $customer = self::create_customer($user_id, $user->user_email, $user->display_name);
        
        if (is_wp_error($customer)) {
            return $customer;
        }
        
        return $customer['id'];
    }
    
    /**
     * Check if Connect account is fully set up
     */
    public static function is_account_complete($account_id) {
        if (empty($account_id)) {
            return false;
        }
        
        $account = self::get_account($account_id);
        
        if (is_wp_error($account)) {
            return false;
        }
        
        return !empty($account['charges_enabled']) && !empty($account['payouts_enabled']);
    }
}
