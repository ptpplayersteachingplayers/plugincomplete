<?php
/**
 * PTP Camp Checkout - Stripe Checkout Sessions
 * 
 * Handles Stripe Checkout Session creation for camp registrations.
 * This replaces WooCommerce checkout with direct Stripe integration.
 * 
 * @version 146.0.0
 * @since 146.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Checkout {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Register shortcode for checkout page
        add_shortcode('ptp_camp_checkout', array($this, 'render_checkout_shortcode'));
        
        // Thank you page shortcode
        add_shortcode('ptp_camp_thank_you', array($this, 'render_thank_you_shortcode'));
        
        // Enqueue scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_ptp_get_camp_products', array($this, 'ajax_get_products'));
        add_action('wp_ajax_nopriv_ptp_get_camp_products', array($this, 'ajax_get_products'));
        add_action('wp_ajax_ptp_calculate_camp_totals', array($this, 'ajax_calculate_totals'));
        add_action('wp_ajax_nopriv_ptp_calculate_camp_totals', array($this, 'ajax_calculate_totals'));
    }
    
    /**
     * Enqueue checkout scripts
     */
    public function enqueue_scripts() {
        if (!is_page(array('camp-checkout', 'camps', 'register-camp'))) {
            return;
        }
        
        wp_enqueue_script('stripe-js', 'https://js.stripe.com/v3/', array(), null, true);
        
        wp_enqueue_script(
            'ptp-camp-checkout',
            PTP_PLUGIN_URL . 'assets/js/camp-checkout.js',
            array('jquery', 'stripe-js'),
            PTP_VERSION,
            true
        );
        
        $stripe_key = '';
        if (class_exists('PTP_Stripe')) {
            PTP_Stripe::init();
            $stripe_key = PTP_Stripe::get_publishable_key();
        }
        
        wp_localize_script('ptp-camp-checkout', 'ptpCampCheckout', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ptp_nonce'),
            'stripeKey' => $stripe_key,
            'currency' => 'usd',
            'thankYouUrl' => home_url('/camp-thank-you/'),
            'siblingDiscount' => PTP_Camp_Orders::SIBLING_DISCOUNT_PCT,
            'referralDiscount' => PTP_Camp_Orders::REFERRAL_DISCOUNT,
            'careBundle' => PTP_Camp_Orders::CARE_BUNDLE_PRICE,
            'jerseyPrice' => PTP_Camp_Orders::JERSEY_PRICE,
            'processingRate' => PTP_Camp_Orders::PROCESSING_RATE,
            'processingFlat' => PTP_Camp_Orders::PROCESSING_FLAT,
        ));
        
        wp_enqueue_style(
            'ptp-camp-checkout',
            PTP_PLUGIN_URL . 'assets/css/camp-checkout.css',
            array(),
            PTP_VERSION
        );
    }
    
    /**
     * Create Stripe Checkout Session
     */
    public static function create_checkout_session($order_id, $totals) {
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe is not configured');
        }
        
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return new WP_Error('order_not_found', 'Order not found');
        }
        
        // Build line items for Stripe
        $line_items = array();
        
        // Main registration line item
        $line_items[] = array(
            'price_data' => array(
                'currency' => 'usd',
                'unit_amount' => round(($totals['subtotal'] - $totals['discount_amount']) * 100),
                'product_data' => array(
                    'name' => 'PTP Camp Registration',
                    'description' => self::build_registration_description($order),
                ),
            ),
            'quantity' => 1,
        );
        
        // Care bundle line item
        if ($totals['care_bundle_total'] > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'usd',
                    'unit_amount' => round($totals['care_bundle_total'] * 100),
                    'product_data' => array(
                        'name' => 'Before + After Care Bundle',
                        'description' => '8:00 AM - 4:30 PM extended care',
                    ),
                ),
                'quantity' => 1,
            );
        }
        
        // Jersey line item
        if ($totals['jersey_total'] > 0) {
            $jersey_count = intval($totals['jersey_total'] / PTP_Camp_Orders::JERSEY_PRICE);
            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'usd',
                    'unit_amount' => round(PTP_Camp_Orders::JERSEY_PRICE * 100),
                    'product_data' => array(
                        'name' => 'PTP Camp Jersey',
                        'description' => 'Custom camp jersey with name and number',
                    ),
                ),
                'quantity' => $jersey_count,
            );
        }
        
        // Processing fee line item
        if ($totals['processing_fee'] > 0) {
            $line_items[] = array(
                'price_data' => array(
                    'currency' => 'usd',
                    'unit_amount' => round($totals['processing_fee'] * 100),
                    'product_data' => array(
                        'name' => 'Payment Processing Fee',
                        'description' => '3% + $0.30 card processing',
                    ),
                ),
                'quantity' => 1,
            );
        }
        
        // Build checkout session data
        $checkout_data = array(
            'payment_method_types[0]' => 'card',
            'mode' => 'payment',
            'success_url' => add_query_arg(array(
                'order' => $order->order_number,
                'session_id' => '{CHECKOUT_SESSION_ID}',
            ), home_url('/camp-thank-you/')),
            'cancel_url' => add_query_arg('cancelled', '1', home_url('/camps/')),
            'customer_email' => $order->billing_email,
            'client_reference_id' => $order->order_number,
            'metadata[order_id]' => $order_id,
            'metadata[order_number]' => $order->order_number,
            'metadata[type]' => 'camp_registration',
            'metadata[camper_count]' => count($order->items),
            'expires_at' => time() + (30 * 60), // 30 minutes
        );
        
        // Add line items
        foreach ($line_items as $index => $item) {
            $checkout_data["line_items[$index][price_data][currency]"] = $item['price_data']['currency'];
            $checkout_data["line_items[$index][price_data][unit_amount]"] = $item['price_data']['unit_amount'];
            $checkout_data["line_items[$index][price_data][product_data][name]"] = $item['price_data']['product_data']['name'];
            if (!empty($item['price_data']['product_data']['description'])) {
                $checkout_data["line_items[$index][price_data][product_data][description]"] = $item['price_data']['product_data']['description'];
            }
            $checkout_data["line_items[$index][quantity]"] = $item['quantity'];
        }
        
        // Make API request
        $response = self::stripe_request('checkout/sessions', $checkout_data);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        if (empty($response['id']) || empty($response['url'])) {
            return new WP_Error('checkout_creation_failed', 'Failed to create checkout session');
        }
        
        return array(
            'id' => $response['id'],
            'url' => $response['url'],
        );
    }
    
    /**
     * Build registration description
     */
    private static function build_registration_description($order) {
        $campers = array();
        foreach ($order->items as $item) {
            $campers[] = $item->camper_first_name . ' ' . $item->camper_last_name . ' - ' . $item->camp_name;
        }
        
        $desc = implode('; ', array_slice($campers, 0, 3));
        if (count($campers) > 3) {
            $desc .= ' (+' . (count($campers) - 3) . ' more)';
        }
        
        return $desc;
    }
    
    /**
     * Make Stripe API request
     */
    private static function stripe_request($endpoint, $data = array(), $method = 'POST') {
        $test_mode = get_option('ptp_stripe_test_mode', true);
        
        if ($test_mode) {
            $secret_key = get_option('ptp_stripe_test_secret', '');
        } else {
            $secret_key = get_option('ptp_stripe_live_secret', '');
        }
        
        if (empty($secret_key)) {
            $secret_key = get_option('ptp_stripe_secret_key', '');
        }
        
        if (empty($secret_key)) {
            return new WP_Error('no_api_key', 'Stripe API key not configured');
        }
        
        $url = 'https://api.stripe.com/v1/' . $endpoint;
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Stripe-Version' => '2023-10-16',
            ),
            'timeout' => 60,
        );
        
        if ($method === 'POST' && !empty($data)) {
            $args['body'] = $data;
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('PTP Camp Checkout: Stripe API error - ' . $response->get_error_message());
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            $error_message = $body['error']['message'] ?? 'Unknown Stripe error';
            error_log('PTP Camp Checkout: Stripe error - ' . $error_message);
            return new WP_Error('stripe_error', $error_message);
        }
        
        return $body;
    }
    
    /**
     * Get camp products from Stripe/database
     */
    public static function get_camp_products($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'active' => true,
            'type' => 'camp',
            'orderby' => 'sort_order',
            'order' => 'ASC',
            'limit' => 50,
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array('1=1');
        
        if ($args['active']) {
            $where[] = 'active = 1';
        }
        
        if ($args['type']) {
            $where[] = $wpdb->prepare("product_type = %s", $args['type']);
        }
        
        $where_sql = implode(' AND ', $where);
        $order_sql = sanitize_sql_orderby($args['orderby'] . ' ' . $args['order']);
        
        $products = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ptp_stripe_products
            WHERE $where_sql
            ORDER BY $order_sql
            LIMIT {$args['limit']}
        ");
        
        // Format prices
        foreach ($products as &$product) {
            $product->price = $product->price_cents ? ($product->price_cents / 100) : 0;
            $product->price_formatted = '$' . number_format($product->price, 0);
            $product->spots_remaining = $product->camp_capacity 
                ? max(0, $product->camp_capacity - $product->camp_registered)
                : null;
        }
        
        return $products;
    }
    
    /**
     * Sync products from Stripe
     */
    public static function sync_products_from_stripe() {
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            return new WP_Error('stripe_not_configured', 'Stripe not configured');
        }
        
        // Fetch products from Stripe
        $response = self::stripe_request('products', array(
            'active' => 'true',
            'limit' => 100,
        ), 'GET');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        global $wpdb;
        $synced = 0;
        
        foreach ($response['data'] ?? array() as $product) {
            // Check if it's a camp product (via metadata)
            $is_camp = isset($product['metadata']['type']) && 
                       in_array($product['metadata']['type'], array('camp', 'clinic'));
            
            if (!$is_camp && stripos($product['name'], 'camp') === false && 
                stripos($product['name'], 'clinic') === false) {
                continue;
            }
            
            // Get default price
            $price_cents = 0;
            $price_id = '';
            if (!empty($product['default_price'])) {
                $price_response = self::stripe_request('prices/' . $product['default_price'], array(), 'GET');
                if (!is_wp_error($price_response) && isset($price_response['unit_amount'])) {
                    $price_cents = $price_response['unit_amount'];
                    $price_id = $product['default_price'];
                }
            }
            
            // Upsert product
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_stripe_products WHERE stripe_product_id = %s",
                $product['id']
            ));
            
            $data = array(
                'stripe_product_id' => $product['id'],
                'stripe_price_id' => $price_id,
                'name' => $product['name'],
                'description' => $product['description'] ?? '',
                'price_cents' => $price_cents,
                'product_type' => $product['metadata']['type'] ?? 'camp',
                'camp_dates' => $product['metadata']['dates'] ?? '',
                'camp_location' => $product['metadata']['location'] ?? '',
                'camp_time' => $product['metadata']['time'] ?? '',
                'camp_age_min' => $product['metadata']['age_min'] ?? null,
                'camp_age_max' => $product['metadata']['age_max'] ?? null,
                'camp_capacity' => $product['metadata']['capacity'] ?? null,
                'image_url' => $product['images'][0] ?? '',
                'active' => $product['active'] ? 1 : 0,
                'updated_at' => current_time('mysql'),
            );
            
            if ($existing) {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_stripe_products',
                    $data,
                    array('id' => $existing)
                );
            } else {
                $data['created_at'] = current_time('mysql');
                $wpdb->insert($wpdb->prefix . 'ptp_stripe_products', $data);
            }
            
            $synced++;
        }
        
        return array(
            'success' => true,
            'synced' => $synced,
        );
    }
    
    /**
     * AJAX: Get camp products
     */
    public function ajax_get_products() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $products = self::get_camp_products(array(
            'type' => sanitize_text_field($_POST['type'] ?? 'camp'),
        ));
        
        wp_send_json_success(array('products' => $products));
    }
    
    /**
     * AJAX: Calculate totals
     */
    public function ajax_calculate_totals() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $items = json_decode(stripslashes($_POST['items'] ?? '[]'), true);
        $referral_code = sanitize_text_field($_POST['referral_code'] ?? '');
        
        $totals = PTP_Camp_Orders::calculate_totals($items, array(
            'referral_code' => $referral_code,
        ));
        
        wp_send_json_success($totals);
    }
    
    /**
     * Render checkout shortcode
     */
    public function render_checkout_shortcode($atts) {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/camp/camp-checkout.php';
        return ob_get_clean();
    }
    
    /**
     * Render thank you shortcode
     */
    public function render_thank_you_shortcode($atts) {
        ob_start();
        include PTP_PLUGIN_DIR . 'templates/camp/camp-thank-you.php';
        return ob_get_clean();
    }
}

// Initialize
PTP_Camp_Checkout::instance();
