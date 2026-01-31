<?php
/**
 * PTP Stripe Connect Integration - v71
 * Trainer onboarding and payouts via Stripe Connect
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_Stripe_Connect_V71 {
    
    private static $instance = null;
    private $secret_key;
    private $publishable_key;
    private $client_id;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        $this->secret_key = get_option('ptp_stripe_secret_key', '');
        $this->publishable_key = get_option('ptp_stripe_publishable_key', '');
        $this->client_id = get_option('ptp_stripe_connect_client_id', '');
        
        // AJAX handlers
        add_action('wp_ajax_ptp_stripe_connect_start', array($this, 'ajax_start_connect'));
        add_action('wp_ajax_ptp_stripe_connect_callback', array($this, 'handle_oauth_callback'));
        add_action('wp_ajax_ptp_stripe_account_status', array($this, 'ajax_account_status'));
        add_action('wp_ajax_ptp_stripe_dashboard_link', array($this, 'ajax_dashboard_link'));
        add_action('wp_ajax_ptp_stripe_disconnect', array($this, 'ajax_disconnect'));
        add_action('wp_ajax_ptp_request_payout', array($this, 'ajax_request_payout'));
        add_action('wp_ajax_ptp_get_earnings', array($this, 'ajax_get_earnings'));
        
        // OAuth callback (non-AJAX)
        add_action('init', array($this, 'check_oauth_callback'));
        
        // Webhook handler
        add_action('rest_api_init', array($this, 'register_webhook'));
    }
    
    /**
     * Initialize Stripe API
     */
    private function init_stripe() {
        if (!class_exists('\Stripe\Stripe')) {
            require_once PTP_PLUGIN_DIR . 'vendor/stripe/stripe-php/init.php';
        }
        \Stripe\Stripe::setApiKey($this->secret_key);
    }
    
    /**
     * Get Connect onboarding URL
     */
    public function get_connect_url($trainer_id) {
        if (empty($this->client_id)) {
            return new WP_Error('not_configured', 'Stripe Connect not configured');
        }
        
        $state = wp_create_nonce('stripe_connect_' . $trainer_id);
        set_transient('ptp_stripe_connect_' . $state, $trainer_id, 1800);
        
        $redirect_uri = home_url('/trainer-dashboard/?stripe_callback=1');
        
        $params = array(
            'client_id' => $this->client_id,
            'response_type' => 'code',
            'scope' => 'read_write',
            'redirect_uri' => $redirect_uri,
            'state' => $state,
            'stripe_user[business_type]' => 'individual',
            'stripe_user[country]' => 'US',
            'suggested_capabilities[]' => 'transfers'
        );
        
        return 'https://connect.stripe.com/oauth/authorize?' . http_build_query($params);
    }
    
    /**
     * AJAX: Start Stripe Connect flow
     */
    public function ajax_start_connect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Login required'));
        }
        
        global $wpdb;
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        $url = $this->get_connect_url($trainer_id);
        
        if (is_wp_error($url)) {
            wp_send_json_error(array('message' => $url->get_error_message()));
        }
        
        wp_send_json_success(array('connect_url' => $url));
    }
    
    /**
     * Check for OAuth callback on page load
     */
    public function check_oauth_callback() {
        if (!isset($_GET['stripe_callback']) || !isset($_GET['code'])) {
            return;
        }
        
        $code = sanitize_text_field($_GET['code']);
        $state = sanitize_text_field($_GET['state'] ?? '');
        
        // Verify state
        $trainer_id = get_transient('ptp_stripe_connect_' . $state);
        if (!$trainer_id) {
            add_action('wp_footer', function() {
                echo '<script>alert("Connection failed: Invalid state");</script>';
            });
            return;
        }
        
        delete_transient('ptp_stripe_connect_' . $state);
        
        // Exchange code for account ID
        $result = $this->exchange_oauth_code($code);
        
        if (is_wp_error($result)) {
            add_action('wp_footer', function() use ($result) {
                echo '<script>alert("Connection failed: ' . esc_js($result->get_error_message()) . '");</script>';
            });
            return;
        }
        
        // Save Connect account ID
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_account_id' => $result['stripe_user_id'],
                'stripe_connected_at' => current_time('mysql')
            ),
            array('id' => $trainer_id)
        );
        
        // Redirect to clean URL
        wp_redirect(home_url('/trainer-dashboard/?tab=earnings&connected=1'));
        exit;
    }
    
    /**
     * Exchange OAuth code for Stripe account
     */
    private function exchange_oauth_code($code) {
        $response = wp_remote_post('https://connect.stripe.com/oauth/token', array(
            'body' => array(
                'client_secret' => $this->secret_key,
                'code' => $code,
                'grant_type' => 'authorization_code'
            )
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('stripe_error', $body['error_description']);
        }
        
        return $body;
    }
    
    /**
     * AJAX: Get Stripe account status
     */
    public function ajax_account_status() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT stripe_account_id, stripe_connected_at 
             FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer || !$trainer->stripe_account_id) {
            wp_send_json_success(array(
                'connected' => false,
                'connect_url' => null
            ));
        }
        
        // Get account details from Stripe
        $this->init_stripe();
        
        try {
            $account = \Stripe\Account::retrieve($trainer->stripe_account_id);
            
            wp_send_json_success(array(
                'connected' => true,
                'account_id' => $trainer->stripe_account_id,
                'charges_enabled' => $account->charges_enabled,
                'payouts_enabled' => $account->payouts_enabled,
                'details_submitted' => $account->details_submitted,
                'connected_at' => $trainer->stripe_connected_at
            ));
            
        } catch (Exception $e) {
            wp_send_json_success(array(
                'connected' => true,
                'error' => $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX: Get Stripe Express dashboard link
     */
    public function ajax_dashboard_link() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        $account_id = $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$account_id) {
            wp_send_json_error(array('message' => 'Not connected'));
        }
        
        $this->init_stripe();
        
        try {
            $link = \Stripe\Account::createLoginLink($account_id);
            wp_send_json_success(array('url' => $link->url));
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * AJAX: Disconnect Stripe account
     */
    public function ajax_disconnect() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        
        $account_id = $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if ($account_id) {
            // Revoke access via Stripe API
            $this->init_stripe();
            try {
                \Stripe\OAuth::deauthorize(array(
                    'client_id' => $this->client_id,
                    'stripe_user_id' => $account_id
                ));
            } catch (Exception $e) {
                // Continue anyway
            }
        }
        
        // Clear from database
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('stripe_account_id' => null, 'stripe_connected_at' => null),
            array('user_id' => get_current_user_id())
        );
        
        wp_send_json_success(array('message' => 'Disconnected'));
    }
    
    /**
     * AJAX: Get trainer earnings
     */
    public function ajax_get_earnings() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        global $wpdb;
        
        $trainer_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        // Get earnings totals
        $total = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_payouts WHERE trainer_id = %d",
            $trainer_id
        ));
        
        $available = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d AND status = 'available'",
            $trainer_id
        ));
        
        $pending = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d AND status = 'pending'",
            $trainer_id
        ));
        
        $paid = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d AND status = 'paid'",
            $trainer_id
        ));
        
        // This month
        $this_month = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())",
            $trainer_id
        ));
        
        // Recent transactions
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT p.*, b.session_date, par.first_name as parent_name
             FROM {$wpdb->prefix}ptp_payouts p
             LEFT JOIN {$wpdb->prefix}ptp_bookings b ON p.booking_id = b.id
             LEFT JOIN {$wpdb->prefix}ptp_parents par ON b.parent_id = par.id
             WHERE p.trainer_id = %d
             ORDER BY p.created_at DESC LIMIT 20",
            $trainer_id
        ));
        
        wp_send_json_success(array(
            'total' => floatval($total),
            'available' => floatval($available),
            'pending' => floatval($pending),
            'paid' => floatval($paid),
            'this_month' => floatval($this_month),
            'transactions' => $transactions
        ));
    }
    
    /**
     * AJAX: Request payout
     */
    public function ajax_request_payout() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to request a payout'));
        }
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, stripe_account_id, stripe_payouts_enabled FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer account not found'));
        }
        
        if (!$trainer->stripe_account_id) {
            wp_send_json_error(array('message' => 'Please connect your Stripe account first'));
        }
        
        if (!$trainer->stripe_payouts_enabled) {
            wp_send_json_error(array('message' => 'Your Stripe account is not fully set up for payouts. Please complete your Stripe onboarding.'));
        }
        
        // Get available balance from completed sessions
        $available = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}ptp_payouts 
             WHERE trainer_id = %d AND status = 'pending'",
            $trainer->id
        ));
        
        $min_payout = 25.00;
        if ($available < $min_payout) {
            wp_send_json_error(array('message' => 'Minimum payout is $' . number_format($min_payout, 2) . '. Your current balance is $' . number_format($available, 2)));
        }
        
        $this->init_stripe();
        
        try {
            // Verify the connected account can receive transfers
            $account = \Stripe\Account::retrieve($trainer->stripe_account_id);
            
            if (!$account->payouts_enabled) {
                wp_send_json_error(array('message' => 'Your Stripe account cannot receive payouts yet. Please complete your account setup in Stripe.'));
            }
            
            // Create transfer
            $transfer = \Stripe\Transfer::create(array(
                'amount' => intval($available * 100), // Convert to cents
                'currency' => 'usd',
                'destination' => $trainer->stripe_account_id,
                'description' => 'PTP Training Payout - ' . date('M j, Y'),
                'metadata' => array(
                    'trainer_id' => $trainer->id,
                    'payout_date' => date('Y-m-d H:i:s')
                )
            ));
            
            // Update payout records
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_payouts 
                 SET status = 'completed', 
                     processed_at = %s, 
                     transaction_id = %s
                 WHERE trainer_id = %d AND status = 'pending'",
                current_time('mysql'),
                $transfer->id,
                $trainer->id
            ));
            
            // Log successful payout
            error_log("PTP Stripe: Payout of \${$available} to trainer {$trainer->id} - Transfer {$transfer->id}");
            
            wp_send_json_success(array(
                'message' => 'Payout of $' . number_format($available, 2) . ' initiated! Funds typically arrive in 2-3 business days.',
                'amount' => $available
            ));
            
        } catch (\Stripe\Exception\InvalidRequestException $e) {
            error_log('PTP Stripe Payout Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Unable to process payout. Please ensure your Stripe account is fully set up.'));
        } catch (\Stripe\Exception\ApiConnectionException $e) {
            error_log('PTP Stripe Connection Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Unable to connect to payment processor. Please try again.'));
        } catch (Exception $e) {
            error_log('PTP Stripe Payout Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'An error occurred processing your payout. Please contact support.'));
        }
    }
    
    /**
     * Process payment and split with trainer
     */
    public function process_payment_with_split($amount, $trainer_id, $metadata = array()) {
        global $wpdb;
        
        $trainer_account = $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer_account) {
            return new WP_Error('no_connect', 'Trainer not connected to Stripe');
        }
        
        $platform_fee_percent = floatval(get_option('ptp_platform_fee_percent', 25));
        $trainer_amount = $amount * (1 - ($platform_fee_percent / 100));
        
        $this->init_stripe();
        
        try {
            $intent = \Stripe\PaymentIntent::create(array(
                'amount' => intval($amount * 100),
                'currency' => 'usd',
                'automatic_payment_methods' => array(
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ),
                'transfer_data' => array(
                    'destination' => $trainer_account,
                    'amount' => intval($trainer_amount * 100)
                ),
                'metadata' => $metadata
            ));
            
            return $intent;
            
        } catch (Exception $e) {
            return new WP_Error('stripe_error', $e->getMessage());
        }
    }
    
    /**
     * Create payout record
     */
    public static function create_payout_record($booking_id, $order_id) {
        global $wpdb;
        
        // Get booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) return false;
        
        // Get order total for this item
        $order = wc_get_order($order_id);
        if (!$order) return false;
        
        $session_price = floatval($booking->price ?? 75);
        $platform_fee_percent = floatval(get_option('ptp_platform_fee_percent', 25));
        $trainer_amount = $session_price * (1 - ($platform_fee_percent / 100));
        
        return $wpdb->insert(
            $wpdb->prefix . 'ptp_payouts',
            array(
                'trainer_id' => $booking->trainer_id,
                'booking_id' => $booking_id,
                'order_id' => $order_id,
                'total_amount' => $session_price,
                'platform_fee' => $session_price - $trainer_amount,
                'trainer_amount' => $trainer_amount,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            )
        );
    }
    
    /**
     * Register webhook endpoint
     */
    public function register_webhook() {
        register_rest_route('ptp/v1', '/stripe-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_webhook'),
            'permission_callback' => '__return_true'
        ));
    }
    
    /**
     * Handle Stripe webhook
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
        $webhook_secret = get_option('ptp_stripe_webhook_secret', '');
        
        // SECURITY: Always require webhook signature verification
        if (empty($webhook_secret)) {
            error_log('PTP Stripe: Webhook secret not configured - rejecting request');
            return new WP_REST_Response(array('error' => 'Webhook not configured'), 400);
        }
        
        if (empty($sig_header)) {
            error_log('PTP Stripe: Missing signature header');
            return new WP_REST_Response(array('error' => 'Missing signature'), 400);
        }
        
        $this->init_stripe();
        
        try {
            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $webhook_secret);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            error_log('PTP Stripe: Invalid signature - ' . $e->getMessage());
            return new WP_REST_Response(array('error' => 'Invalid signature'), 400);
        } catch (Exception $e) {
            error_log('PTP Stripe: Webhook error - ' . $e->getMessage());
            return new WP_REST_Response(array('error' => 'Webhook error'), 400);
        }
        
        // Log the event for debugging
        error_log('PTP Stripe: Received event ' . $event->type);
        
        // Handle event types
        switch ($event->type) {
            case 'payment_intent.succeeded':
                $this->handle_payment_success($event->data->object);
                break;
                
            case 'payment_intent.payment_failed':
                $this->handle_payment_failed($event->data->object);
                break;
                
            case 'charge.refunded':
                $this->handle_refund($event->data->object);
                break;
                
            case 'account.updated':
                $this->handle_account_updated($event->data->object);
                break;
                
            case 'transfer.created':
                $this->handle_transfer_created($event->data->object);
                break;
        }
        
        return new WP_REST_Response(array('received' => true), 200);
    }
    
    /**
     * Handle successful payment
     */
    private function handle_payment_success($payment_intent) {
        global $wpdb;
        
        $booking_id = $payment_intent->metadata->booking_id ?? null;
        
        if ($booking_id) {
            // Update booking status
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'confirmed', 
                    'payment_status' => 'paid',
                    'paid_at' => current_time('mysql'),
                    'stripe_payment_intent' => $payment_intent->id
                ),
                array('id' => intval($booking_id)),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
            
            error_log("PTP Stripe: Booking {$booking_id} marked as paid");
        }
    }
    
    /**
     * Handle failed payment
     */
    private function handle_payment_failed($payment_intent) {
        global $wpdb;
        
        $booking_id = $payment_intent->metadata->booking_id ?? null;
        
        if ($booking_id) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('payment_status' => 'failed'),
                array('id' => intval($booking_id))
            );
            
            error_log("PTP Stripe: Payment failed for booking {$booking_id}");
        }
    }
    
    /**
     * Handle refund
     */
    private function handle_refund($charge) {
        global $wpdb;
        
        // Find booking by payment intent
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE stripe_payment_intent = %s",
            $charge->payment_intent
        ));
        
        if ($booking) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('payment_status' => 'refunded', 'status' => 'cancelled'),
                array('id' => $booking->id)
            );
            
            error_log("PTP Stripe: Booking {$booking->id} refunded");
        }
    }
    
    /**
     * Handle transfer created (payout to trainer)
     */
    private function handle_transfer_created($transfer) {
        error_log('PTP Stripe: Transfer created - ' . $transfer->id . ' to ' . $transfer->destination);
    }
    
    /**
     * Handle account status update
     */
    private function handle_account_updated($account) {
        global $wpdb;
        
        // Update trainer's Stripe status
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'stripe_charges_enabled' => $account->charges_enabled ? 1 : 0,
                'stripe_payouts_enabled' => $account->payouts_enabled ? 1 : 0
            ),
            array('stripe_account_id' => $account->id)
        );
        
        error_log('PTP Stripe: Account updated - ' . $account->id);
    }
}

// Initialize
PTP_Stripe_Connect_V71::instance();
