<?php
/**
 * PTP Cart & Checkout System - v71
 * AJAX-powered cart and checkout with Stripe integration
 * 
 * @since 71.0.0
 */

defined('ABSPATH') || exit;

class PTP_Cart_Checkout_V71 {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Cart AJAX handlers
        add_action('wp_ajax_ptp_add_to_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_nopriv_ptp_add_to_cart', array($this, 'ajax_add_to_cart'));
        add_action('wp_ajax_ptp_update_cart', array($this, 'ajax_update_cart'));
        add_action('wp_ajax_nopriv_ptp_update_cart', array($this, 'ajax_update_cart'));
        add_action('wp_ajax_ptp_remove_from_cart', array($this, 'ajax_remove_from_cart'));
        add_action('wp_ajax_nopriv_ptp_remove_from_cart', array($this, 'ajax_remove_from_cart'));
        add_action('wp_ajax_ptp_get_cart', array($this, 'ajax_get_cart'));
        add_action('wp_ajax_nopriv_ptp_get_cart', array($this, 'ajax_get_cart'));
        add_action('wp_ajax_ptp_apply_coupon', array($this, 'ajax_apply_coupon'));
        add_action('wp_ajax_nopriv_ptp_apply_coupon', array($this, 'ajax_apply_coupon'));
        
        // Checkout AJAX handlers
        add_action('wp_ajax_ptp_process_checkout', array($this, 'ajax_process_checkout'));
        add_action('wp_ajax_nopriv_ptp_process_checkout', array($this, 'ajax_process_checkout'));
        add_action('wp_ajax_ptp_create_payment_intent', array($this, 'ajax_create_payment_intent'));
        add_action('wp_ajax_nopriv_ptp_create_payment_intent', array($this, 'ajax_create_payment_intent'));
        add_action('wp_ajax_ptp_validate_checkout', array($this, 'ajax_validate_checkout'));
        add_action('wp_ajax_nopriv_ptp_validate_checkout', array($this, 'ajax_validate_checkout'));
        
        // Test endpoint to verify AJAX is working
        add_action('wp_ajax_ptp_checkout_test', array($this, 'ajax_checkout_test'));
        add_action('wp_ajax_nopriv_ptp_checkout_test', array($this, 'ajax_checkout_test'));
        
        // 3D Secure completion handler
        add_action('wp_ajax_ptp_confirm_payment_complete', array($this, 'ajax_confirm_payment_complete'));
        add_action('wp_ajax_nopriv_ptp_confirm_payment_complete', array($this, 'ajax_confirm_payment_complete'));
        
        // Processing fee
        add_action('woocommerce_cart_calculate_fees', array($this, 'add_processing_fee'));
        
        // Shortcodes - DISABLED: Using unified checkout handler instead
        // add_shortcode('ptp_cart', array($this, 'render_cart_shortcode'));
        // add_shortcode('ptp_checkout', array($this, 'render_checkout_shortcode'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // Save player data to order
        add_action('woocommerce_checkout_create_order', array($this, 'save_player_data'), 10, 2);
    }
    
    /**
     * Enqueue cart/checkout scripts
     */
    public function enqueue_scripts() {
        // Only load Stripe and checkout JS on actual CHECKOUT pages, not cart
        if (is_page('ptp-checkout') || is_page('checkout')) {
            wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
            
            wp_enqueue_script(
                'ptp-checkout',
                PTP_PLUGIN_URL . 'assets/js/ptp-checkout.js',
                array('jquery', 'stripe-js'),
                PTP_V71_VERSION,
                true
            );
            
            // Get Stripe key from PTP_Stripe class if available
            $stripe_key = '';
            if (class_exists('PTP_Stripe')) {
                $stripe_key = PTP_Stripe::get_publishable_key();
            }
            // Fallback to options
            if (empty($stripe_key)) {
                $stripe_key = get_option('ptp_stripe_test_mode', true)
                    ? get_option('ptp_stripe_test_publishable', get_option('ptp_stripe_publishable_key', ''))
                    : get_option('ptp_stripe_live_publishable', get_option('ptp_stripe_publishable_key', ''));
            }
            
            wp_localize_script('ptp-checkout', 'ptpCheckout', array(
                'ajax' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ptp_nonce'),
                'stripeKey' => $stripe_key,
                'currency' => get_woocommerce_currency(),
                'cartUrl' => home_url('/ptp-cart/'),
                'checkoutUrl' => home_url('/ptp-checkout/'),
                'thankYouUrl' => home_url('/order-received/'),
                'isLoggedIn' => is_user_logged_in(),
                'processingFeePercent' => floatval(get_option('ptp_processing_fee_percent', 3.2))
            ));
            
            wp_enqueue_style(
                'ptp-checkout',
                PTP_PLUGIN_URL . 'assets/css/ptp-checkout.css',
                array(),
                PTP_V71_VERSION
            );
        }
    }
    
    /**
     * Add processing fee
     */
    public function add_processing_fee($cart) {
        if (is_admin() && !defined('DOING_AJAX')) return;
        
        $fee_percent = floatval(get_option('ptp_processing_fee_percent', 3.2));
        if ($fee_percent <= 0) return;
        
        $subtotal = $cart->get_subtotal();
        $fee = round($subtotal * ($fee_percent / 100), 2);
        
        if ($fee > 0) {
            $cart->add_fee(__('Processing Fee', 'ptp'), $fee, false);
        }
    }
    
    /**
     * AJAX: Add to cart
     */
    public function ajax_add_to_cart() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 1);
        $variation_id = intval($_POST['variation_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product'));
        }
        
        // Custom cart item data
        $cart_item_data = array();
        
        // Player info if provided
        if (!empty($_POST['player_name'])) {
            $cart_item_data['player_name'] = sanitize_text_field($_POST['player_name']);
        }
        if (!empty($_POST['player_age'])) {
            $cart_item_data['player_age'] = intval($_POST['player_age']);
        }
        
        // Session/booking data
        if (!empty($_POST['session_date'])) {
            $cart_item_data['session_date'] = sanitize_text_field($_POST['session_date']);
        }
        if (!empty($_POST['session_time'])) {
            $cart_item_data['session_time'] = sanitize_text_field($_POST['session_time']);
        }
        if (!empty($_POST['trainer_id'])) {
            $cart_item_data['trainer_id'] = intval($_POST['trainer_id']);
        }
        if (!empty($_POST['booking_id'])) {
            $cart_item_data['booking_id'] = intval($_POST['booking_id']);
        }
        
        // Add to cart
        $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, array(), $cart_item_data);
        
        if ($cart_item_key) {
            wp_send_json_success(array(
                'cart_item_key' => $cart_item_key,
                'cart_count' => WC()->cart->get_cart_contents_count(),
                'cart_total' => WC()->cart->get_cart_total(),
                'message' => 'Added to cart'
            ));
        } else {
            wp_send_json_error(array('message' => 'Could not add to cart'));
        }
    }
    
    /**
     * AJAX: Update cart item
     */
    public function ajax_update_cart() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
        $quantity = intval($_POST['quantity'] ?? 1);
        
        if (!$cart_item_key) {
            wp_send_json_error(array('message' => 'Invalid cart item'));
        }
        
        WC()->cart->set_quantity($cart_item_key, $quantity);
        WC()->cart->calculate_totals();
        
        wp_send_json_success($this->get_cart_data());
    }
    
    /**
     * AJAX: Remove from cart
     */
    public function ajax_remove_from_cart() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
        
        if (!$cart_item_key) {
            wp_send_json_error(array('message' => 'Invalid cart item'));
        }
        
        WC()->cart->remove_cart_item($cart_item_key);
        WC()->cart->calculate_totals();
        
        wp_send_json_success($this->get_cart_data());
    }
    
    /**
     * AJAX: Get cart contents
     */
    public function ajax_get_cart() {
        check_ajax_referer('ptp_nonce', 'nonce');
        wp_send_json_success($this->get_cart_data());
    }
    
    /**
     * Get cart data array - includes both WooCommerce and PTP training items
     */
    private function get_cart_data() {
        // Use PTP_Cart_Helper as single source of truth (includes training from ptp_bundles)
        if (class_exists('PTP_Cart_Helper')) {
            $helper_data = PTP_Cart_Helper::get_cart_data(true); // force refresh
            
            $items = array();
            
            // Add WooCommerce items
            foreach ($helper_data['woo_items'] as $item) {
                $items[] = array(
                    'key' => $item['key'],
                    'type' => 'camp',
                    'product_id' => $item['product_id'],
                    'name' => $item['name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'line_total' => $item['subtotal'],
                    'image' => $item['image'],
                );
            }
            
            // Add training items from bundles
            foreach ($helper_data['training_items'] as $item) {
                $items[] = array(
                    'key' => 'training_' . ($item['bundle_id'] ?? 0),
                    'type' => 'training',
                    'product_id' => 0,
                    'name' => ($item['trainer_name'] ?? 'Training') . ' - ' . ($item['package_name'] ?? 'Session'),
                    'quantity' => 1,
                    'price' => $item['price'],
                    'line_total' => $item['price'],
                    'image' => $item['trainer_image'] ?? '',
                    'trainer_id' => $item['trainer_id'] ?? 0,
                    'date' => $item['date'] ?? '',
                    'time' => $item['time'] ?? '',
                    'location' => $item['location'] ?? '',
                    'bundle_code' => $item['bundle_code'] ?? '',
                );
            }
            
            return array(
                'items' => $items,
                'count' => count($items),
                'subtotal' => $helper_data['subtotal'],
                'bundle_discount' => $helper_data['bundle_discount'],
                'processing_fee' => $helper_data['processing_fee'] ?? 0,
                'total' => $helper_data['total'],
                'total_formatted' => '$' . number_format($helper_data['total'], 2),
                'is_empty' => empty($items),
                'has_training' => $helper_data['has_training'],
                'has_camps' => $helper_data['has_camps'],
                'checkout_url' => $helper_data['checkout_url'],
            );
        }
        
        // Fallback to WooCommerce-only if helper not available
        WC()->cart->calculate_totals();
        
        $items = array();
        foreach (WC()->cart->get_cart() as $key => $item) {
            $product = $item['data'];
            $items[] = array(
                'key' => $key,
                'product_id' => $item['product_id'],
                'name' => $product->get_name(),
                'quantity' => $item['quantity'],
                'price' => floatval($product->get_price()),
                'line_total' => floatval($item['line_total']),
                'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail'),
                'player_name' => $item['player_name'] ?? '',
                'session_date' => $item['session_date'] ?? '',
                'session_time' => $item['session_time'] ?? ''
            );
        }
        
        $fees = array();
        foreach (WC()->cart->get_fees() as $fee) {
            $fees[] = array(
                'name' => $fee->name,
                'amount' => floatval($fee->amount)
            );
        }
        
        return array(
            'items' => $items,
            'count' => WC()->cart->get_cart_contents_count(),
            'subtotal' => floatval(WC()->cart->get_subtotal()),
            'fees' => $fees,
            'total' => floatval(WC()->cart->get_total('edit')),
            'total_formatted' => WC()->cart->get_cart_total(),
            'is_empty' => WC()->cart->is_empty()
        );
    }
    
    /**
     * AJAX: Apply coupon
     */
    public function ajax_apply_coupon() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $coupon_code = sanitize_text_field($_POST['coupon_code'] ?? '');
        
        if (empty($coupon_code)) {
            wp_send_json_error(array('message' => 'Please enter a coupon code'));
        }
        
        $result = WC()->cart->apply_coupon($coupon_code);
        
        if ($result) {
            WC()->cart->calculate_totals();
            $data = $this->get_cart_data();
            $data['message'] = 'Coupon applied!';
            wp_send_json_success($data);
        } else {
            wp_send_json_error(array('message' => 'Invalid coupon code'));
        }
    }
    
    /**
     * AJAX: Validate checkout fields
     */
    public function ajax_validate_checkout() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $errors = array();
        
        // Billing validation
        $required_fields = array(
            'billing_first_name' => 'First name',
            'billing_last_name' => 'Last name',
            'billing_email' => 'Email',
            'billing_phone' => 'Phone'
        );
        
        foreach ($required_fields as $field => $label) {
            if (empty($_POST[$field])) {
                $errors[$field] = "$label is required";
            }
        }
        
        // Email validation
        if (!empty($_POST['billing_email']) && !is_email($_POST['billing_email'])) {
            $errors['billing_email'] = 'Please enter a valid email';
        }
        
        // Player fields if cart has camps
        if ($this->cart_has_camp_products()) {
            if (empty($_POST['player_first_name'])) {
                $errors['player_first_name'] = 'Player first name is required';
            }
            if (empty($_POST['player_last_name'])) {
                $errors['player_last_name'] = 'Player last name is required';
            }
        }
        
        if (!empty($errors)) {
            wp_send_json_error(array('errors' => $errors));
        }
        
        wp_send_json_success(array('valid' => true));
    }
    
    /**
     * AJAX: Create Stripe Payment Intent
     */
    public function ajax_create_payment_intent() {
        // Log for debugging
        error_log('PTP Checkout: ajax_create_payment_intent called');
        
        // Manual nonce verification with better error handling
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        
        if (empty($nonce)) {
            error_log('PTP Checkout: Missing nonce');
            wp_send_json_error(array('message' => 'Security token missing. Please refresh the page.'));
            return;
        }
        
        if (!wp_verify_nonce($nonce, 'ptp_nonce')) {
            error_log('PTP Checkout: Invalid nonce - received: ' . substr($nonce, 0, 10) . '...');
            wp_send_json_error(array('message' => 'Session expired. Please refresh the page and try again.'));
            return;
        }
        
        // Ensure WooCommerce is available
        if (!function_exists('WC') || !WC()->cart) {
            error_log('PTP Checkout: WooCommerce cart not available');
            wp_send_json_error(array('message' => 'Cart not available. Please refresh and try again.'));
            return;
        }
        
        // Calculate cart total
        WC()->cart->calculate_totals();
        $total = floatval(WC()->cart->get_total('edit'));
        
        error_log('PTP Checkout: Cart total = ' . $total);
        
        if ($total < 0.50) {
            wp_send_json_error(array('message' => 'Order total too low ($' . number_format($total, 2) . ')'));
            return;
        }
        
        // Get Stripe secret key
        $test_mode = get_option('ptp_stripe_test_mode', true);
        $secret_key = '';
        
        if ($test_mode) {
            $secret_key = get_option('ptp_stripe_test_secret', '');
            if (empty($secret_key)) {
                $secret_key = get_option('ptp_stripe_secret_key', '');
            }
        } else {
            $secret_key = get_option('ptp_stripe_live_secret', '');
            if (empty($secret_key)) {
                $secret_key = get_option('ptp_stripe_secret_key', '');
            }
        }
        
        if (empty($secret_key)) {
            error_log('PTP Checkout: No Stripe secret key configured');
            wp_send_json_error(array('message' => 'Payment system not configured. Please contact support.'));
            return;
        }
        
        error_log('PTP Checkout: Using Stripe key starting with: ' . substr($secret_key, 0, 12) . '...');
        
        // Create payment intent via Stripe API
        $amount = intval($total * 100); // Convert to cents
        $currency = strtolower(get_woocommerce_currency());
        
        $stripe_body = array(
            'amount' => $amount,
            'currency' => $currency,
            'automatic_payment_methods[enabled]' => 'true',
            'automatic_payment_methods[allow_redirects]' => 'never',
            'metadata[site]' => get_bloginfo('name'),
            'metadata[order_source]' => 'ptp_checkout',
        );
        
        // v114.1: Removed receipt_email - WooCommerce handles confirmation emails
        
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Stripe-Version' => '2023-10-16',
            ),
            'body' => $stripe_body,
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            error_log('PTP Checkout: Stripe API error - ' . $response->get_error_message());
            wp_send_json_error(array('message' => 'Payment service unavailable. Please try again.'));
            return;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        error_log('PTP Checkout: Stripe response code: ' . $http_code);
        
        if ($http_code !== 200 || isset($body['error'])) {
            $error_msg = $body['error']['message'] ?? 'Payment initialization failed';
            error_log('PTP Checkout: Stripe error - ' . $error_msg);
            wp_send_json_error(array('message' => $error_msg));
            return;
        }
        
        if (empty($body['client_secret']) || empty($body['id'])) {
            error_log('PTP Checkout: Invalid Stripe response - missing client_secret or id');
            wp_send_json_error(array('message' => 'Invalid payment response. Please try again.'));
            return;
        }
        
        error_log('PTP Checkout: Payment intent created successfully - ' . $body['id']);
        
        wp_send_json_success(array(
            'clientSecret' => $body['client_secret'],
            'intentId' => $body['id'],
            'amount' => $amount,
            'currency' => $currency
        ));
    }
    
    /**
     * AJAX: Test endpoint to verify AJAX is working
     */
    public function ajax_checkout_test() {
        // Simple test - no nonce required for diagnostics
        $cart_available = function_exists('WC') && WC()->cart ? 'yes' : 'no';
        $cart_total = 0;
        $cart_count = 0;
        
        if ($cart_available === 'yes') {
            WC()->cart->calculate_totals();
            $cart_total = WC()->cart->get_total('edit');
            $cart_count = WC()->cart->get_cart_contents_count();
        }
        
        $test_mode = get_option('ptp_stripe_test_mode', true);
        $has_secret = !empty(get_option($test_mode ? 'ptp_stripe_test_secret' : 'ptp_stripe_live_secret'));
        if (!$has_secret) {
            $has_secret = !empty(get_option('ptp_stripe_secret_key'));
        }
        
        wp_send_json_success(array(
            'status' => 'ok',
            'ajax_working' => true,
            'woocommerce_cart' => $cart_available,
            'cart_total' => $cart_total,
            'cart_items' => $cart_count,
            'stripe_configured' => $has_secret ? 'yes' : 'no',
            'stripe_test_mode' => $test_mode ? 'yes' : 'no',
            'user_logged_in' => is_user_logged_in() ? 'yes' : 'no',
            'php_version' => PHP_VERSION,
            'time' => current_time('mysql'),
        ));
    }
    
    /**
     * AJAX: Process checkout
     * Fixed in v75.1: Properly creates and confirms Payment Intent with payment_method_id
     */
    public function ajax_process_checkout() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        error_log('[PTP Checkout V71] Processing checkout...');
        
        // Get payment method from frontend
        $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
        
        if (empty($payment_method_id)) {
            error_log('[PTP Checkout V71] ERROR: No payment_method_id provided');
            wp_send_json_error(array('message' => 'Payment method not provided'));
            return;
        }
        
        error_log('[PTP Checkout V71] Payment method ID: ' . $payment_method_id);
        
        // Check if PTP_Stripe is available
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            error_log('[PTP Checkout V71] ERROR: PTP_Stripe not available');
            wp_send_json_error(array('message' => 'Payment system not configured'));
            return;
        }
        
        // Create order first
        $order = wc_create_order();
        
        if (is_wp_error($order)) {
            error_log('[PTP Checkout V71] ERROR: Could not create order - ' . $order->get_error_message());
            wp_send_json_error(array('message' => 'Could not create order'));
            return;
        }
        
        error_log('[PTP Checkout V71] Order created: ' . $order->get_id());
        
        // Add cart items to order (WooCommerce products - camps, etc.)
        foreach (WC()->cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $item_id = $order->add_product($product, $cart_item['quantity']);
            
            // Add custom meta
            if (!empty($cart_item['player_name'])) {
                wc_add_order_item_meta($item_id, 'Player Name', $cart_item['player_name']);
            }
            if (!empty($cart_item['session_date'])) {
                wc_add_order_item_meta($item_id, 'Event Date', $cart_item['session_date']);
            }
            if (!empty($cart_item['session_time'])) {
                wc_add_order_item_meta($item_id, 'Event Time', $cart_item['session_time']);
            }
            if (!empty($cart_item['trainer_id'])) {
                wc_add_order_item_meta($item_id, '_trainer_id', $cart_item['trainer_id']);
            }
        }
        
        // Add training items from PTP Cart (private training sessions)
        if (class_exists('PTP_Cart_Helper')) {
            $cart_data = PTP_Cart_Helper::get_cart_data(true);
            $training_items = $cart_data['training_items'] ?? array();
            
            if (!empty($training_items)) {
                error_log('[PTP Checkout V71] Adding ' . count($training_items) . ' training items to order');
                
                foreach ($training_items as $training_item) {
                    // Add training as a fee (since it's not a WooCommerce product)
                    $item_name = ($training_item['trainer_name'] ?? 'Training') . ' - ' . ($training_item['package_name'] ?? 'Session');
                    $item_price = floatval($training_item['price'] ?? 0);
                    
                    if ($item_price > 0) {
                        $fee = new WC_Order_Item_Fee();
                        $fee->set_name($item_name);
                        $fee->set_amount($item_price);
                        $fee->set_total($item_price);
                        $fee->set_tax_status('none');
                        $order->add_item($fee);
                        
                        // Store trainer info in order meta
                        if (!empty($training_item['trainer_id'])) {
                            $order->update_meta_data('_trainer_id', $training_item['trainer_id']);
                            $order->update_meta_data('_training_date', $training_item['date'] ?? '');
                            $order->update_meta_data('_training_time', $training_item['time'] ?? '');
                            $order->update_meta_data('_training_location', $training_item['location'] ?? '');
                            $order->update_meta_data('_training_package', $training_item['package_name'] ?? '');
                        }
                        
                        // Store bundle code if present
                        if (!empty($training_item['bundle_code'])) {
                            $order->update_meta_data('_bundle_code', $training_item['bundle_code']);
                        }
                        
                        error_log('[PTP Checkout V71] Added training item: ' . $item_name . ' ($' . $item_price . ')');
                    }
                }
            }
            
            // Apply bundle discount if both camps and training present
            $bundle_discount = floatval($cart_data['bundle_discount'] ?? 0);
            if ($bundle_discount > 0) {
                $discount_fee = new WC_Order_Item_Fee();
                $discount_fee->set_name('Bundle Discount (' . intval($cart_data['bundle_discount_percent'] ?? 15) . '% off)');
                $discount_fee->set_amount(-$bundle_discount);
                $discount_fee->set_total(-$bundle_discount);
                $discount_fee->set_tax_status('none');
                $order->add_item($discount_fee);
                
                $order->update_meta_data('_bundle_discount', $bundle_discount);
                error_log('[PTP Checkout V71] Applied bundle discount: -$' . $bundle_discount);
            }
        }
        
        // Add fees
        foreach (WC()->cart->get_fees() as $fee) {
            $order->add_fee($fee);
        }
        
        // Set billing info - check both formats (with and without billing_ prefix)
        $billing_fields = array(
            'first_name', 'last_name', 'email', 'phone',
            'address_1', 'address_2', 'city', 'state', 'postcode', 'country'
        );
        
        foreach ($billing_fields as $field) {
            // Try billing_ prefix first, then without prefix
            $value = sanitize_text_field($_POST['billing_' . $field] ?? $_POST[$field] ?? '');
            if ($value) {
                $setter = "set_billing_$field";
                if (method_exists($order, $setter)) {
                    $order->$setter($value);
                }
            }
        }
        
        // Set user
        if (is_user_logged_in()) {
            $order->set_customer_id(get_current_user_id());
        }
        
        // Set payment method
        $order->set_payment_method('stripe');
        $order->set_payment_method_title('Credit Card');
        
        // Add player meta to order
        if (!empty($_POST['player_first_name'])) {
            $player_name = sanitize_text_field($_POST['player_first_name']) . ' ' . 
                          sanitize_text_field($_POST['player_last_name'] ?? '');
            $order->update_meta_data('_player_name', $player_name);
        }
        
        // Calculate totals
        $order->calculate_totals();
        $total = floatval($order->get_total());
        
        error_log('[PTP Checkout V71] Order total: $' . $total);
        
        if ($total <= 0) {
            $order->delete(true);
            wp_send_json_error(array('message' => 'Invalid order total'));
            return;
        }
        
        // Save order with pending status first
        $order->set_status('pending');
        $order->save();
        
        // Create Payment Intent with metadata
        $metadata = array(
            'order_id' => $order->get_id(),
            'customer_email' => $order->get_billing_email(),
            'site' => get_bloginfo('name'),
        );
        
        $payment_intent = PTP_Stripe::create_payment_intent($total, $metadata);
        
        if (is_wp_error($payment_intent)) {
            error_log('[PTP Checkout V71] ERROR: Failed to create payment intent - ' . $payment_intent->get_error_message());
            $order->update_status('failed', 'Payment intent creation failed: ' . $payment_intent->get_error_message());
            wp_send_json_error(array('message' => 'Payment setup failed: ' . $payment_intent->get_error_message()));
            return;
        }
        
        error_log('[PTP Checkout V71] Payment intent created: ' . $payment_intent['id']);
        
        // Store the payment intent ID
        $order->update_meta_data('_stripe_intent_id', $payment_intent['id']);
        $order->save();
        
        // Confirm the payment intent with the payment method
        $confirm_result = $this->confirm_payment_intent($payment_intent['id'], $payment_method_id);
        
        if (is_wp_error($confirm_result)) {
            error_log('[PTP Checkout V71] ERROR: Payment confirmation failed - ' . $confirm_result->get_error_message());
            $order->update_status('failed', 'Payment failed: ' . $confirm_result->get_error_message());
            wp_send_json_error(array('message' => $confirm_result->get_error_message()));
            return;
        }
        
        error_log('[PTP Checkout V71] Payment confirm result status: ' . ($confirm_result['status'] ?? 'unknown'));
        
        // Check payment status
        $status = $confirm_result['status'] ?? '';
        
        if ($status === 'requires_action' || $status === 'requires_source_action') {
            // 3D Secure required - return client_secret for frontend to handle
            error_log('[PTP Checkout V71] 3D Secure required');
            wp_send_json_success(array(
                'requires_action' => true,
                'client_secret' => $confirm_result['client_secret'],
                'order_id' => $order->get_id(),
            ));
            return;
        }
        
        if ($status === 'succeeded') {
            // Payment successful
            error_log('[PTP Checkout V71] Payment succeeded');
            $order->payment_complete($payment_intent['id']);
            $order->add_order_note('Payment completed via Stripe. Payment Intent: ' . $payment_intent['id']);
            
            // Clear both WooCommerce cart and PTP training cart
            WC()->cart->empty_cart();
            
            // Clear PTP training cart from database
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::clear_training_items();
            }
            
            wp_send_json_success(array(
                'order_id' => $order->get_id(),
                'order_key' => $order->get_order_key(),
                'redirect_url' => $order->get_checkout_order_received_url()
            ));
            return;
        }
        
        // Unexpected status
        error_log('[PTP Checkout V71] Unexpected payment status: ' . $status);
        $order->update_status('failed', 'Unexpected payment status: ' . $status);
        wp_send_json_error(array('message' => 'Payment could not be processed. Please try again.'));
    }
    
    /**
     * Confirm a payment intent with a payment method
     * Uses the Stripe API directly via wp_remote_request
     */
    private function confirm_payment_intent($payment_intent_id, $payment_method_id) {
        // Get secret key - try multiple option names for compatibility
        $secret_key = get_option('ptp_stripe_test_mode', true) 
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        if (empty($secret_key)) {
            return new WP_Error('no_key', 'Stripe API key not configured');
        }
        
        $url = 'https://api.stripe.com/v1/payment_intents/' . $payment_intent_id . '/confirm';
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'payment_method' => $payment_method_id,
                'return_url' => home_url('/checkout/order-received/'),
            ),
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            return new WP_Error('stripe_error', $body['error']['message'] ?? 'Payment failed');
        }
        
        return $body;
    }
    
    /**
     * AJAX: Confirm payment complete (called after 3D Secure)
     * Verifies payment succeeded and completes the WooCommerce order
     */
    public function ajax_confirm_payment_complete() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        error_log('[PTP Confirm Payment] Order ID: ' . $order_id . ', Payment Intent: ' . $payment_intent_id);
        
        if (!$order_id || !$payment_intent_id) {
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Order not found'));
            return;
        }
        
        // Verify payment intent status with Stripe
        if (class_exists('PTP_Stripe')) {
            $intent = PTP_Stripe::get_payment_intent($payment_intent_id);
            
            if (is_wp_error($intent)) {
                error_log('[PTP Confirm Payment] Error getting intent: ' . $intent->get_error_message());
                wp_send_json_error(array('message' => 'Could not verify payment'));
                return;
            }
            
            $status = $intent['status'] ?? '';
            error_log('[PTP Confirm Payment] Intent status: ' . $status);
            
            if ($status !== 'succeeded') {
                wp_send_json_error(array('message' => 'Payment not completed. Status: ' . $status));
                return;
            }
        }
        
        // Complete the order
        $order->payment_complete($payment_intent_id);
        $order->add_order_note('Payment confirmed via 3D Secure. Payment Intent: ' . $payment_intent_id);
        
        // Clear both WooCommerce cart and PTP training cart
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        
        // Clear PTP training cart from database
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::clear_training_items();
        }
        
        error_log('[PTP Confirm Payment] Order completed successfully');
        
        wp_send_json_success(array(
            'order_id' => $order_id,
            'redirect_url' => $order->get_checkout_order_received_url()
        ));
    }
    
    /**
     * Check if cart has camp products
     */
    private function cart_has_camp_products() {
        foreach (WC()->cart->get_cart() as $item) {
            $product_id = $item['product_id'];
            $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
            if (array_intersect($categories, array('camps', 'clinics', 'camp', 'clinic'))) {
                return true;
            }
            // Check for camp meta
            if (get_post_meta($product_id, '_is_camp', true) || get_post_meta($product_id, '_camp_date', true)) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get trainer ID from cart
     */
    private function get_cart_trainer_id() {
        foreach (WC()->cart->get_cart() as $item) {
            if (!empty($item['trainer_id'])) {
                return intval($item['trainer_id']);
            }
        }
        return null;
    }
    
    /**
     * Get trainer's Stripe Connect account
     */
    private function get_trainer_stripe_account($trainer_id) {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
    }
    
    /**
     * Calculate trainer's portion of payment
     */
    private function calculate_trainer_amount($trainer_id) {
        $total = 0;
        $platform_fee_percent = floatval(get_option('ptp_platform_fee_percent', 25));
        
        foreach (WC()->cart->get_cart() as $item) {
            if (isset($item['trainer_id']) && $item['trainer_id'] == $trainer_id) {
                $total += floatval($item['line_total']);
            }
        }
        
        // Subtract platform fee
        $trainer_amount = $total * (1 - ($platform_fee_percent / 100));
        
        return round($trainer_amount, 2);
    }
    
    /**
     * Save player data to order
     */
    public function save_player_data($order, $data) {
        if (!empty($_POST['player_first_name'])) {
            $order->update_meta_data('_player_first_name', sanitize_text_field($_POST['player_first_name']));
        }
        if (!empty($_POST['player_last_name'])) {
            $order->update_meta_data('_player_last_name', sanitize_text_field($_POST['player_last_name']));
        }
        if (!empty($_POST['player_dob'])) {
            $order->update_meta_data('_player_dob', sanitize_text_field($_POST['player_dob']));
        }
        if (!empty($_POST['player_team'])) {
            $order->update_meta_data('_player_team', sanitize_text_field($_POST['player_team']));
        }
    }
    
    /**
     * Render cart shortcode
     */
    public function render_cart_shortcode() {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/ptp-cart.php';
        return ob_get_clean();
    }
    
    /**
     * Render checkout shortcode
     */
    public function render_checkout_shortcode() {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/checkout-v71.php';
        return ob_get_clean();
    }
}

// Initialize
PTP_Cart_Checkout_V71::instance();
