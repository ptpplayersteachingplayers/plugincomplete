<?php
/**
 * PTP Stripe Diagnostic Tool
 * Tests payment and payout functionality
 * 
 * Access via: /wp-admin/admin.php?page=ptp-stripe-diagnostic
 */

defined('ABSPATH') || exit;

class PTP_Stripe_Diagnostic {
    
    private static $instance = null;
    private $results = array();
    private $stripe_initialized = false;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('wp_ajax_ptp_run_stripe_test', array($this, 'ajax_run_test'));
    }
    
    public function add_menu() {
        add_submenu_page(
            'ptp-training', // Parent slug - add to PTP menu
            'Stripe Diagnostic',
            'Stripe Diagnostic',
            'manage_options',
            'ptp-stripe-diagnostic',
            array($this, 'render_page')
        );
    }
    
    /**
     * Initialize Stripe SDK
     */
    private function init_stripe() {
        if ($this->stripe_initialized) {
            return true;
        }
        
        $secret_key = get_option('ptp_stripe_secret_key', '');
        
        if (empty($secret_key)) {
            return false;
        }
        
        if (!class_exists('\Stripe\Stripe')) {
            $vendor_path = PTP_PLUGIN_DIR . 'vendor/stripe/stripe-php/init.php';
            if (file_exists($vendor_path)) {
                require_once $vendor_path;
            } else {
                return false;
            }
        }
        
        \Stripe\Stripe::setApiKey($secret_key);
        $this->stripe_initialized = true;
        return true;
    }
    
    /**
     * Run all diagnostic tests
     */
    public function run_all_tests() {
        $results = array(
            'timestamp' => current_time('mysql'),
            'tests' => array()
        );
        
        // Test 0: Security Check (HTTPS, etc)
        $results['tests']['security'] = $this->test_security();
        
        // Test 1: Configuration Check
        $results['tests']['config'] = $this->test_configuration();
        
        // Test 2: API Connection
        $results['tests']['api_connection'] = $this->test_api_connection();
        
        // Test 3: Account Status
        $results['tests']['account'] = $this->test_account_status();
        
        // Test 4: Payment Intent (Test Mode)
        $results['tests']['payment_intent'] = $this->test_payment_intent();
        
        // Test 5: Connected Accounts (Trainers)
        $results['tests']['connected_accounts'] = $this->test_connected_accounts();
        
        // Test 6: Transfer Capability
        $results['tests']['transfer'] = $this->test_transfer_capability();
        
        // Test 7: Database Tables
        $results['tests']['database'] = $this->test_database_tables();
        
        // Test 8: Webhook Configuration
        $results['tests']['webhooks'] = $this->test_webhook_config();
        
        return $results;
    }
    
    /**
     * Test 0: Security Check
     */
    private function test_security() {
        $result = array(
            'name' => 'Security Requirements',
            'status' => 'pass',
            'details' => array()
        );
        
        // Check HTTPS
        if (is_ssl()) {
            $result['details'][] = '✅ Site is using HTTPS';
        } else {
            $result['status'] = 'fail';
            $result['details'][] = '❌ CRITICAL: Site must use HTTPS for payment processing';
        }
        
        // Check if site URL uses HTTPS
        $site_url = get_option('siteurl');
        if (strpos($site_url, 'https://') === 0) {
            $result['details'][] = '✅ Site URL configured for HTTPS';
        } else {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Site URL should use HTTPS';
        }
        
        // Check webhook secret is set
        $webhook_secret = get_option('ptp_stripe_webhook_secret', '');
        if (!empty($webhook_secret)) {
            $result['details'][] = '✅ Webhook signature verification enabled';
        } else {
            $result['status'] = 'fail';
            $result['details'][] = '❌ CRITICAL: Webhook secret not configured - webhooks will be rejected';
        }
        
        // Check if using test mode in production
        $secret_key = get_option('ptp_stripe_secret_key', '');
        $is_test = strpos($secret_key, 'sk_test_') === 0;
        $is_production = (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'production') 
                        || strpos(home_url(), 'localhost') === false;
        
        if ($is_test && $is_production) {
            $result['status'] = ($result['status'] === 'fail') ? 'fail' : 'warning';
            $result['details'][] = '⚠️ Using TEST mode keys on production site';
        } elseif ($is_test) {
            $result['details'][] = 'ℹ️ Using TEST mode (safe for development)';
        } else {
            $result['details'][] = '✅ Using LIVE mode keys';
        }
        
        // Check for exposed debug info
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY) {
            $result['status'] = ($result['status'] === 'fail') ? 'fail' : 'warning';
            $result['details'][] = '⚠️ WP_DEBUG_DISPLAY is on - errors may expose sensitive info';
        } else {
            $result['details'][] = '✅ Debug display is off';
        }
        
        return $result;
    }
    
    /**
     * Test 1: Configuration Check
     */
    private function test_configuration() {
        $result = array(
            'name' => 'Stripe Configuration',
            'status' => 'pass',
            'details' => array()
        );
        
        $secret_key = get_option('ptp_stripe_secret_key', '');
        $publishable_key = get_option('ptp_stripe_publishable_key', '');
        $connect_client_id = get_option('ptp_stripe_connect_client_id', '');
        $webhook_secret = get_option('ptp_stripe_webhook_secret', '');
        
        // Check secret key
        if (empty($secret_key)) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Secret key not configured';
        } else {
            $is_test = strpos($secret_key, 'sk_test_') === 0;
            $is_live = strpos($secret_key, 'sk_live_') === 0;
            if ($is_test) {
                $result['details'][] = '⚠️ Using TEST mode secret key';
            } elseif ($is_live) {
                $result['details'][] = '✅ Using LIVE mode secret key';
            } else {
                $result['status'] = 'fail';
                $result['details'][] = '❌ Invalid secret key format';
            }
        }
        
        // Check publishable key
        if (empty($publishable_key)) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Publishable key not configured';
        } else {
            $is_test = strpos($publishable_key, 'pk_test_') === 0;
            $is_live = strpos($publishable_key, 'pk_live_') === 0;
            if ($is_test) {
                $result['details'][] = '⚠️ Using TEST mode publishable key';
            } elseif ($is_live) {
                $result['details'][] = '✅ Using LIVE mode publishable key';
            } else {
                $result['status'] = 'fail';
                $result['details'][] = '❌ Invalid publishable key format';
            }
        }
        
        // Check Connect client ID
        if (empty($connect_client_id)) {
            $result['status'] = ($result['status'] === 'pass') ? 'warning' : $result['status'];
            $result['details'][] = '⚠️ Connect Client ID not configured (needed for trainer onboarding)';
        } else {
            $result['details'][] = '✅ Connect Client ID configured';
        }
        
        // Check webhook secret
        if (empty($webhook_secret)) {
            $result['status'] = ($result['status'] === 'pass') ? 'warning' : $result['status'];
            $result['details'][] = '⚠️ Webhook secret not configured';
        } else {
            $result['details'][] = '✅ Webhook secret configured';
        }
        
        // Check platform fee
        $platform_fee = get_option('ptp_platform_fee_percent', 20);
        $result['details'][] = "ℹ️ Platform fee: {$platform_fee}%";
        
        return $result;
    }
    
    /**
     * Test 2: API Connection
     */
    private function test_api_connection() {
        $result = array(
            'name' => 'Stripe API Connection',
            'status' => 'pass',
            'details' => array()
        );
        
        if (!$this->init_stripe()) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Could not initialize Stripe SDK';
            return $result;
        }
        
        try {
            // Try a simple API call
            $balance = \Stripe\Balance::retrieve();
            $result['details'][] = '✅ Successfully connected to Stripe API';
            
            // Show available balance
            foreach ($balance->available as $bal) {
                $amount = number_format($bal->amount / 100, 2);
                $result['details'][] = "ℹ️ Available balance: {$bal->currency} {$amount}";
            }
            
            // Show pending balance
            foreach ($balance->pending as $bal) {
                $amount = number_format($bal->amount / 100, 2);
                $result['details'][] = "ℹ️ Pending balance: {$bal->currency} {$amount}";
            }
            
        } catch (\Stripe\Exception\AuthenticationException $e) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Authentication failed: ' . $e->getMessage();
        } catch (Exception $e) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ API Error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Test 3: Account Status
     */
    private function test_account_status() {
        $result = array(
            'name' => 'Stripe Account Status',
            'status' => 'pass',
            'details' => array()
        );
        
        if (!$this->init_stripe()) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Stripe not initialized';
            return $result;
        }
        
        try {
            $account = \Stripe\Account::retrieve();
            
            $result['details'][] = "✅ Account ID: {$account->id}";
            $result['details'][] = "ℹ️ Business Type: " . ($account->business_type ?? 'Not set');
            $result['details'][] = "ℹ️ Country: " . ($account->country ?? 'Not set');
            
            if ($account->charges_enabled) {
                $result['details'][] = '✅ Charges enabled';
            } else {
                $result['status'] = 'warning';
                $result['details'][] = '⚠️ Charges NOT enabled';
            }
            
            if ($account->payouts_enabled) {
                $result['details'][] = '✅ Payouts enabled';
            } else {
                $result['status'] = 'warning';
                $result['details'][] = '⚠️ Payouts NOT enabled';
            }
            
            // Check capabilities
            if (isset($account->capabilities)) {
                $caps = (array) $account->capabilities;
                foreach ($caps as $cap => $status) {
                    $icon = ($status === 'active') ? '✅' : '⚠️';
                    $result['details'][] = "{$icon} Capability '{$cap}': {$status}";
                }
            }
            
        } catch (Exception $e) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Test 4: Payment Intent Creation
     */
    private function test_payment_intent() {
        $result = array(
            'name' => 'Payment Intent Test',
            'status' => 'pass',
            'details' => array()
        );
        
        if (!$this->init_stripe()) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Stripe not initialized';
            return $result;
        }
        
        // Only run in test mode
        $secret_key = get_option('ptp_stripe_secret_key', '');
        if (strpos($secret_key, 'sk_test_') !== 0) {
            $result['status'] = 'skip';
            $result['details'][] = 'ℹ️ Skipped - Only runs in TEST mode to avoid real charges';
            return $result;
        }
        
        try {
            // Create a test payment intent
            $intent = \Stripe\PaymentIntent::create(array(
                'amount' => 1000, // $10.00
                'currency' => 'usd',
                'payment_method_types' => array('card'),
                'metadata' => array(
                    'test' => 'ptp_diagnostic',
                    'timestamp' => time()
                )
            ));
            
            $result['details'][] = '✅ Payment Intent created successfully';
            $result['details'][] = "ℹ️ Intent ID: {$intent->id}";
            $result['details'][] = "ℹ️ Status: {$intent->status}";
            $result['details'][] = "ℹ️ Client Secret: " . substr($intent->client_secret, 0, 20) . '...';
            
            // Cancel the test intent
            $intent->cancel();
            $result['details'][] = '✅ Test intent cancelled';
            
        } catch (Exception $e) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Test 5: Connected Accounts (Trainers)
     */
    private function test_connected_accounts() {
        $result = array(
            'name' => 'Connected Trainer Accounts',
            'status' => 'pass',
            'details' => array()
        );
        
        global $wpdb;
        
        // Check trainers with Stripe accounts
        $trainers = $wpdb->get_results("
            SELECT t.id, t.display_name, t.stripe_account_id, t.stripe_connected_at, u.user_email
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
            WHERE t.stripe_account_id IS NOT NULL AND t.stripe_account_id != ''
            LIMIT 10
        ");
        
        $connected_count = count($trainers);
        $total_trainers = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers WHERE status = 'active'");
        
        $result['details'][] = "ℹ️ Total active trainers: {$total_trainers}";
        $result['details'][] = "ℹ️ Trainers with Stripe connected: {$connected_count}";
        
        if ($connected_count == 0) {
            $result['status'] = 'warning';
            $result['details'][] = '⚠️ No trainers have connected Stripe accounts';
            return $result;
        }
        
        // Verify each connected account
        if ($this->init_stripe()) {
            foreach ($trainers as $trainer) {
                try {
                    $account = \Stripe\Account::retrieve($trainer->stripe_account_id);
                    
                    $status_icons = array();
                    $status_icons[] = $account->charges_enabled ? '💳✅' : '💳❌';
                    $status_icons[] = $account->payouts_enabled ? '💰✅' : '💰❌';
                    
                    $result['details'][] = implode(' ', $status_icons) . " {$trainer->display_name} ({$trainer->stripe_account_id})";
                    
                } catch (Exception $e) {
                    $result['status'] = 'warning';
                    $result['details'][] = "❌ {$trainer->display_name}: " . $e->getMessage();
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Test 6: Transfer Capability
     */
    private function test_transfer_capability() {
        $result = array(
            'name' => 'Transfer/Payout Capability',
            'status' => 'pass',
            'details' => array()
        );
        
        if (!$this->init_stripe()) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Stripe not initialized';
            return $result;
        }
        
        // Only run in test mode
        $secret_key = get_option('ptp_stripe_secret_key', '');
        if (strpos($secret_key, 'sk_test_') !== 0) {
            $result['status'] = 'skip';
            $result['details'][] = 'ℹ️ Skipped - Only runs in TEST mode';
            return $result;
        }
        
        global $wpdb;
        
        // Get a connected trainer for testing
        $trainer = $wpdb->get_row("
            SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers 
            WHERE stripe_account_id IS NOT NULL AND stripe_account_id != ''
            LIMIT 1
        ");
        
        if (!$trainer) {
            $result['status'] = 'skip';
            $result['details'][] = 'ℹ️ Skipped - No connected trainer accounts to test with';
            return $result;
        }
        
        try {
            // Check if we can create transfers
            $account = \Stripe\Account::retrieve($trainer->stripe_account_id);
            
            if ($account->payouts_enabled) {
                $result['details'][] = '✅ Trainer account can receive transfers';
            } else {
                $result['status'] = 'warning';
                $result['details'][] = '⚠️ Trainer account cannot receive transfers yet';
            }
            
            // Check platform balance
            $balance = \Stripe\Balance::retrieve();
            $available = 0;
            foreach ($balance->available as $bal) {
                if ($bal->currency === 'usd') {
                    $available = $bal->amount;
                }
            }
            
            if ($available >= 100) {
                $result['details'][] = "✅ Platform has sufficient balance for transfers (\${$available})";
            } else {
                $result['details'][] = "ℹ️ Platform balance: \$" . number_format($available / 100, 2);
            }
            
        } catch (Exception $e) {
            $result['status'] = 'fail';
            $result['details'][] = '❌ Error: ' . $e->getMessage();
        }
        
        return $result;
    }
    
    /**
     * Test 7: Database Tables
     */
    private function test_database_tables() {
        $result = array(
            'name' => 'Database Tables',
            'status' => 'pass',
            'details' => array()
        );
        
        global $wpdb;
        
        $required_tables = array(
            'ptp_trainers' => array('stripe_account_id', 'stripe_charges_enabled', 'stripe_payouts_enabled'),
            'ptp_bookings' => array('payment_status', 'stripe_payment_intent', 'trainer_id'),
            'ptp_payouts' => array('trainer_id', 'amount', 'status', 'transaction_id')
        );
        
        foreach ($required_tables as $table => $columns) {
            $full_table = $wpdb->prefix . $table;
            
            // Check if table exists
            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'");
            
            if (!$exists) {
                $result['status'] = 'fail';
                $result['details'][] = "❌ Table '{$table}' does not exist";
                continue;
            }
            
            $result['details'][] = "✅ Table '{$table}' exists";
            
            // Check columns
            $actual_columns = $wpdb->get_col("SHOW COLUMNS FROM {$full_table}");
            
            foreach ($columns as $col) {
                if (in_array($col, $actual_columns)) {
                    $result['details'][] = "  ✅ Column '{$col}' exists";
                } else {
                    $result['status'] = 'warning';
                    $result['details'][] = "  ⚠️ Column '{$col}' missing";
                }
            }
        }
        
        // Check trainer Stripe status
        $stripe_connected = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers 
            WHERE stripe_account_id IS NOT NULL AND stripe_account_id != ''
        ");
        $result['details'][] = "ℹ️ Trainers with Stripe connected: {$stripe_connected}";
        
        // Check payout records
        $payout_stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as completed
            FROM {$wpdb->prefix}ptp_payouts
        ");
        
        if ($payout_stats) {
            $result['details'][] = "ℹ️ Total payout records: {$payout_stats->total}";
            $result['details'][] = "ℹ️ Pending payouts: \$" . number_format($payout_stats->pending ?? 0, 2);
            $result['details'][] = "ℹ️ Completed payouts: \$" . number_format($payout_stats->completed ?? 0, 2);
        }
        
        // Check bookings with payment info
        $booking_stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid,
                SUM(CASE WHEN stripe_payment_intent IS NOT NULL AND stripe_payment_intent != '' THEN 1 ELSE 0 END) as with_stripe
            FROM {$wpdb->prefix}ptp_bookings
        ");
        
        if ($booking_stats) {
            $result['details'][] = "ℹ️ Total bookings: {$booking_stats->total}";
            $result['details'][] = "ℹ️ Paid bookings: {$booking_stats->paid}";
            $result['details'][] = "ℹ️ Bookings with Stripe: {$booking_stats->with_stripe}";
        }
        
        return $result;
    }
    
    /**
     * Test 8: Webhook Configuration
     */
    private function test_webhook_config() {
        $result = array(
            'name' => 'Webhook Configuration',
            'status' => 'pass',
            'details' => array()
        );
        
        $webhook_url = rest_url('ptp/v1/stripe-webhook');
        $result['details'][] = "ℹ️ Webhook URL: {$webhook_url}";
        
        $webhook_secret = get_option('ptp_stripe_webhook_secret', '');
        if (!empty($webhook_secret)) {
            $result['details'][] = '✅ Webhook secret configured';
        } else {
            $result['status'] = 'warning';
            $result['details'][] = '⚠️ Webhook secret not configured';
        }
        
        // Check if REST API endpoint is registered
        $routes = rest_get_server()->get_routes();
        if (isset($routes['/ptp/v1/stripe-webhook'])) {
            $result['details'][] = '✅ Webhook endpoint registered';
        } else {
            $result['status'] = 'warning';
            $result['details'][] = '⚠️ Webhook endpoint not registered';
        }
        
        // Important webhook events
        $result['details'][] = 'ℹ️ Required webhook events:';
        $result['details'][] = '  • payment_intent.succeeded';
        $result['details'][] = '  • payment_intent.payment_failed';
        $result['details'][] = '  • charge.refunded';
        $result['details'][] = '  • account.updated';
        $result['details'][] = '  • transfer.created';
        
        return $result;
    }
    
    /**
     * AJAX handler for running tests
     */
    public function ajax_run_test() {
        check_ajax_referer('ptp_stripe_diagnostic', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $results = $this->run_all_tests();
        wp_send_json_success($results);
    }
    
    /**
     * Render the diagnostic page
     */
    public function render_page() {
        $results = $this->run_all_tests();
        ?>
        <style>
            .ptp-diagnostic {
                max-width: 900px;
                margin: 20px 0;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .ptp-diagnostic h1 {
                display: flex;
                align-items: center;
                gap: 12px;
                margin-bottom: 24px;
            }
            .ptp-diagnostic h1 img {
                width: 32px;
                height: 32px;
            }
            .ptp-test-card {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-bottom: 16px;
                overflow: hidden;
            }
            .ptp-test-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 16px 20px;
                background: #f8f9fa;
                border-bottom: 1px solid #eee;
                cursor: pointer;
            }
            .ptp-test-header:hover {
                background: #f0f0f0;
            }
            .ptp-test-name {
                font-weight: 600;
                font-size: 15px;
            }
            .ptp-test-status {
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .ptp-status-pass {
                background: #d4edda;
                color: #155724;
            }
            .ptp-status-fail {
                background: #f8d7da;
                color: #721c24;
            }
            .ptp-status-warning {
                background: #fff3cd;
                color: #856404;
            }
            .ptp-status-skip {
                background: #e2e3e5;
                color: #383d41;
            }
            .ptp-test-details {
                padding: 16px 20px;
                font-size: 13px;
                line-height: 1.8;
                background: #fff;
            }
            .ptp-test-details div {
                padding: 2px 0;
            }
            .ptp-summary {
                display: flex;
                gap: 24px;
                margin-bottom: 24px;
                padding: 20px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 8px;
            }
            .ptp-summary-item {
                text-align: center;
            }
            .ptp-summary-count {
                font-size: 32px;
                font-weight: 700;
            }
            .ptp-summary-label {
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
            }
            .ptp-pass { color: #28a745; }
            .ptp-fail { color: #dc3545; }
            .ptp-warning { color: #ffc107; }
            .ptp-skip { color: #6c757d; }
            .ptp-actions {
                margin-bottom: 24px;
            }
            .ptp-btn {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 12px 24px;
                background: #635bff;
                color: #fff;
                border: none;
                border-radius: 6px;
                font-size: 14px;
                font-weight: 600;
                cursor: pointer;
                text-decoration: none;
            }
            .ptp-btn:hover {
                background: #5851db;
                color: #fff;
            }
            .ptp-btn-secondary {
                background: #f0f0f0;
                color: #333;
            }
            .ptp-btn-secondary:hover {
                background: #e0e0e0;
                color: #333;
            }
            .ptp-info {
                background: #e7f3ff;
                border: 1px solid #b3d9ff;
                border-radius: 8px;
                padding: 16px 20px;
                margin-bottom: 24px;
                font-size: 14px;
            }
            .ptp-info a {
                color: #0066cc;
            }
        </style>
        
        <div class="wrap ptp-diagnostic">
            <h1>
                <svg width="32" height="32" viewBox="0 0 32 32" fill="none">
                    <rect width="32" height="32" rx="6" fill="#635BFF"/>
                    <path d="M14.5 12.5c0-1.1.9-2 2-2s2 .9 2 2-.9 2-2 2-2-.9-2-2zm-4 7c0-1.1.9-2 2-2h7c1.1 0 2 .9 2 2v.5c0 .3-.2.5-.5.5h-10c-.3 0-.5-.2-.5-.5v-.5z" fill="#fff"/>
                </svg>
                Stripe Diagnostic Tool
            </h1>
            
            <div class="ptp-info">
                <strong>About this tool:</strong> This diagnostic checks your Stripe integration for payments (parent purchases) and payouts (trainer earnings). 
                Run these tests after setup or when troubleshooting issues.
                <br><br>
                <strong>Stripe Dashboard:</strong> <a href="https://dashboard.stripe.com" target="_blank">dashboard.stripe.com</a> | 
                <strong>Connect Dashboard:</strong> <a href="https://dashboard.stripe.com/connect/accounts/overview" target="_blank">Connected Accounts</a>
            </div>
            
            <div class="ptp-actions">
                <button onclick="location.reload()" class="ptp-btn">
                    <span>🔄</span> Re-run Tests
                </button>
                <a href="<?php echo admin_url('admin.php?page=ptp-settings&tab=payments'); ?>" class="ptp-btn ptp-btn-secondary">
                    <span>⚙️</span> Payment Settings
                </a>
            </div>
            
            <?php
            // Calculate summary
            $summary = array('pass' => 0, 'fail' => 0, 'warning' => 0, 'skip' => 0);
            foreach ($results['tests'] as $test) {
                $summary[$test['status']]++;
            }
            ?>
            
            <div class="ptp-summary">
                <div class="ptp-summary-item">
                    <div class="ptp-summary-count ptp-pass"><?php echo $summary['pass']; ?></div>
                    <div class="ptp-summary-label">Passed</div>
                </div>
                <div class="ptp-summary-item">
                    <div class="ptp-summary-count ptp-fail"><?php echo $summary['fail']; ?></div>
                    <div class="ptp-summary-label">Failed</div>
                </div>
                <div class="ptp-summary-item">
                    <div class="ptp-summary-count ptp-warning"><?php echo $summary['warning']; ?></div>
                    <div class="ptp-summary-label">Warnings</div>
                </div>
                <div class="ptp-summary-item">
                    <div class="ptp-summary-count ptp-skip"><?php echo $summary['skip']; ?></div>
                    <div class="ptp-summary-label">Skipped</div>
                </div>
            </div>
            
            <?php foreach ($results['tests'] as $key => $test): ?>
            <div class="ptp-test-card">
                <div class="ptp-test-header" onclick="this.nextElementSibling.style.display = this.nextElementSibling.style.display === 'none' ? 'block' : 'none'">
                    <span class="ptp-test-name"><?php echo esc_html($test['name']); ?></span>
                    <span class="ptp-test-status ptp-status-<?php echo esc_attr($test['status']); ?>">
                        <?php echo esc_html($test['status']); ?>
                    </span>
                </div>
                <div class="ptp-test-details">
                    <?php foreach ($test['details'] as $detail): ?>
                    <div><?php echo esc_html($detail); ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div style="margin-top: 24px; padding: 16px; background: #f8f9fa; border-radius: 8px; font-size: 12px; color: #666;">
                Last tested: <?php echo esc_html($results['timestamp']); ?>
            </div>
        </div>
        <?php
    }
}

// Initialize
PTP_Stripe_Diagnostic::instance();
