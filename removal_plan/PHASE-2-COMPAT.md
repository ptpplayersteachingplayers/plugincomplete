# Phase 2: WC Compatibility Layer

## File: `includes/class-ptp-wc-compat.php`

This file provides a `WC()` function replacement that routes calls to PTP native classes.
This allows existing code to work during migration without immediate changes.

```php
<?php
/**
 * PTP WooCommerce Compatibility Layer
 * 
 * Provides WC() function and related shims to allow gradual migration
 * from WooCommerce to native PTP classes.
 * 
 * This file should be loaded BEFORE any file that calls WC()
 * 
 * @version 1.0.0
 * @since 148.0.0
 */

defined('ABSPATH') || exit;

// Only define if WooCommerce is not active
if (!function_exists('WC') && !class_exists('WooCommerce')) {
    
    /**
     * PTP WC Compatibility Class
     * Mimics WooCommerce() singleton structure
     */
    class PTP_WC_Compat {
        
        /** @var PTP_WC_Compat */
        private static $instance = null;
        
        /** @var PTP_WC_Compat_Session */
        public $session = null;
        
        /** @var PTP_WC_Compat_Cart */
        public $cart = null;
        
        /** @var PTP_WC_Compat_Customer */
        public $customer = null;
        
        /**
         * Get instance
         */
        public static function instance() {
            if (is_null(self::$instance)) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        /**
         * Constructor
         */
        public function __construct() {
            $this->session = new PTP_WC_Compat_Session();
            $this->cart = new PTP_WC_Compat_Cart();
            $this->customer = new PTP_WC_Compat_Customer();
        }
    }
    
    /**
     * Session compatibility wrapper
     */
    class PTP_WC_Compat_Session {
        
        public function get($key, $default = null) {
            if (function_exists('ptp_session')) {
                return ptp_session()->get($key, $default);
            }
            return $default;
        }
        
        public function set($key, $value) {
            if (function_exists('ptp_session')) {
                ptp_session()->set($key, $value);
            }
        }
        
        public function has_session() {
            if (function_exists('ptp_session')) {
                return ptp_session()->has_session();
            }
            return false;
        }
        
        public function set_customer_session_cookie($set = true) {
            if (function_exists('ptp_session')) {
                ptp_session()->set_customer_session_cookie($set);
            }
        }
        
        public function __call($method, $args) {
            // Log unknown method calls for migration tracking
            error_log("[PTP WC Compat] Unknown session method called: {$method}");
            return null;
        }
    }
    
    /**
     * Cart compatibility wrapper
     */
    class PTP_WC_Compat_Cart {
        
        public function get_cart() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_cart();
            }
            return array();
        }
        
        public function get_cart_from_session() {
            return $this->get_cart();
        }
        
        public function calculate_totals() {
            if (function_exists('ptp_cart')) {
                ptp_cart()->calculate_totals();
            }
        }
        
        public function get_cart_contents_count() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_cart_contents_count();
            }
            return 0;
        }
        
        public function is_empty() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->is_empty();
            }
            return true;
        }
        
        public function empty_cart() {
            if (function_exists('ptp_cart')) {
                ptp_cart()->empty_cart();
            }
        }
        
        public function get_subtotal() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_subtotal();
            }
            return 0;
        }
        
        public function get_total($context = 'view') {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_total($context);
            }
            return 0;
        }
        
        public function get_cart_total() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_cart_total();
            }
            return '$0.00';
        }
        
        public function add_to_cart($product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array()) {
            // Convert WC-style add_to_cart to PTP format
            if (function_exists('ptp_cart')) {
                $price = get_post_meta($product_id, '_price', true) ?: 0;
                $is_camp = get_post_meta($product_id, '_ptp_is_camp', true) === 'yes';
                
                $item_type = $is_camp ? 'camp' : 'training';
                $metadata = array_merge($cart_item_data, array(
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'variation' => $variation,
                ));
                
                return ptp_cart()->add_to_cart($item_type, $product_id, $quantity, $price, $metadata);
            }
            return false;
        }
        
        public function remove_cart_item($cart_key) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->remove_cart_item($cart_key);
            }
            return false;
        }
        
        public function set_quantity($cart_key, $quantity) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->set_quantity($cart_key, $quantity);
            }
            return false;
        }
        
        public function get_fees() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_fees();
            }
            return array();
        }
        
        public function apply_coupon($code) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->apply_coupon($code);
            }
            return false;
        }
        
        public function __call($method, $args) {
            error_log("[PTP WC Compat] Unknown cart method called: {$method}");
            return null;
        }
    }
    
    /**
     * Customer compatibility wrapper
     */
    class PTP_WC_Compat_Customer {
        
        public function get_id() {
            return get_current_user_id();
        }
        
        public function get_billing_email() {
            $user = wp_get_current_user();
            return $user->user_email ?? '';
        }
        
        public function get_billing_first_name() {
            return get_user_meta(get_current_user_id(), 'first_name', true);
        }
        
        public function get_billing_last_name() {
            return get_user_meta(get_current_user_id(), 'last_name', true);
        }
        
        public function get_billing_phone() {
            return get_user_meta(get_current_user_id(), 'billing_phone', true);
        }
        
        public function __call($method, $args) {
            error_log("[PTP WC Compat] Unknown customer method called: {$method}");
            return '';
        }
    }
    
    /**
     * Main WC() function replacement
     */
    function WC() {
        return PTP_WC_Compat::instance();
    }
    
    // =========================================================================
    // WC Function Replacements
    // =========================================================================
    
    /**
     * wc_create_order() replacement
     */
    if (!function_exists('wc_create_order')) {
        function wc_create_order($args = array()) {
            return ptp_create_order($args);
        }
    }
    
    /**
     * wc_get_order() replacement
     */
    if (!function_exists('wc_get_order')) {
        function wc_get_order($order_id) {
            return ptp_get_order($order_id);
        }
    }
    
    /**
     * wc_get_orders() replacement
     */
    if (!function_exists('wc_get_orders')) {
        function wc_get_orders($args = array()) {
            return ptp_get_orders($args);
        }
    }
    
    /**
     * wc_get_product() replacement
     */
    if (!function_exists('wc_get_product')) {
        function wc_get_product($product_id) {
            // Return a simple product object
            return new PTP_WC_Compat_Product($product_id);
        }
    }
    
    /**
     * wc_get_products() replacement
     */
    if (!function_exists('wc_get_products')) {
        function wc_get_products($args = array()) {
            // Query posts with product-like structure
            $query_args = array(
                'post_type' => array('product', 'ptp_camp'),
                'posts_per_page' => $args['limit'] ?? 10,
                'post_status' => 'publish',
            );
            
            if (!empty($args['category'])) {
                $query_args['tax_query'] = array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'slug',
                        'terms' => $args['category'],
                    ),
                );
            }
            
            $posts = get_posts($query_args);
            
            return array_map(function($post) {
                return new PTP_WC_Compat_Product($post->ID);
            }, $posts);
        }
    }
    
    /**
     * wc_price() replacement
     */
    if (!function_exists('wc_price')) {
        function wc_price($price, $args = array()) {
            return ptp_price($price, $args);
        }
    }
    
    /**
     * wc_add_notice() replacement
     */
    if (!function_exists('wc_add_notice')) {
        function wc_add_notice($message, $type = 'success') {
            // Store in session for display
            $notices = ptp_session()->get('ptp_notices', array());
            $notices[] = array('message' => $message, 'type' => $type);
            ptp_session()->set('ptp_notices', $notices);
        }
    }
    
    /**
     * wc_print_notices() replacement
     */
    if (!function_exists('wc_print_notices')) {
        function wc_print_notices() {
            $notices = ptp_session()->get('ptp_notices', array());
            
            foreach ($notices as $notice) {
                $class = $notice['type'] === 'error' ? 'ptp-notice-error' : 'ptp-notice-success';
                echo '<div class="ptp-notice ' . esc_attr($class) . '">' . esc_html($notice['message']) . '</div>';
            }
            
            ptp_session()->set('ptp_notices', array());
        }
    }
    
    /**
     * URL function replacements
     */
    if (!function_exists('wc_get_cart_url')) {
        function wc_get_cart_url() {
            return home_url('/cart/');
        }
    }
    
    if (!function_exists('wc_get_checkout_url')) {
        function wc_get_checkout_url() {
            return home_url('/checkout/');
        }
    }
    
    if (!function_exists('wc_get_page_permalink')) {
        function wc_get_page_permalink($page) {
            $pages = array(
                'cart' => '/cart/',
                'checkout' => '/checkout/',
                'myaccount' => '/my-account/',
                'shop' => '/shop/',
            );
            return home_url($pages[$page] ?? '/');
        }
    }
    
    if (!function_exists('wc_get_account_endpoint_url')) {
        function wc_get_account_endpoint_url($endpoint) {
            return home_url('/my-account/' . $endpoint . '/');
        }
    }
    
    /**
     * Order item meta functions
     */
    if (!function_exists('wc_add_order_item_meta')) {
        function wc_add_order_item_meta($item_id, $key, $value, $unique = false) {
            global $wpdb;
            return $wpdb->insert(
                $wpdb->prefix . 'ptp_order_item_meta',
                array('item_id' => $item_id, 'meta_key' => $key, 'meta_value' => maybe_serialize($value)),
                array('%d', '%s', '%s')
            );
        }
    }
    
    if (!function_exists('wc_update_order_item_meta')) {
        function wc_update_order_item_meta($item_id, $key, $value) {
            global $wpdb;
            return $wpdb->update(
                $wpdb->prefix . 'ptp_order_item_meta',
                array('meta_value' => maybe_serialize($value)),
                array('item_id' => $item_id, 'meta_key' => $key),
                array('%s'),
                array('%d', '%s')
            );
        }
    }
    
    if (!function_exists('wc_get_product_id_by_sku')) {
        function wc_get_product_id_by_sku($sku) {
            global $wpdb;
            return $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s",
                $sku
            ));
        }
    }
    
    if (!function_exists('wc_placeholder_img_src')) {
        function wc_placeholder_img_src($size = 'woocommerce_thumbnail') {
            return PTP_PLUGIN_URL . 'assets/images/placeholder.png';
        }
    }
    
    /**
     * Simple product compatibility class
     */
    class PTP_WC_Compat_Product {
        
        private $id;
        private $post;
        
        public function __construct($product_id) {
            $this->id = (int) $product_id;
            $this->post = get_post($product_id);
        }
        
        public function get_id() { return $this->id; }
        public function get_name() { return $this->post->post_title ?? ''; }
        public function get_title() { return $this->get_name(); }
        public function get_slug() { return $this->post->post_name ?? ''; }
        public function get_description() { return $this->post->post_content ?? ''; }
        public function get_short_description() { return $this->post->post_excerpt ?? ''; }
        
        public function get_price() {
            return (float) get_post_meta($this->id, '_price', true);
        }
        
        public function get_regular_price() {
            return (float) get_post_meta($this->id, '_regular_price', true);
        }
        
        public function get_sale_price() {
            return (float) get_post_meta($this->id, '_sale_price', true);
        }
        
        public function get_sku() {
            return get_post_meta($this->id, '_sku', true);
        }
        
        public function get_image_id() {
            return get_post_thumbnail_id($this->id);
        }
        
        public function get_image($size = 'woocommerce_thumbnail') {
            return get_the_post_thumbnail($this->id, $size);
        }
        
        public function is_in_stock() {
            $stock = get_post_meta($this->id, '_stock_status', true);
            return $stock !== 'outofstock';
        }
        
        public function get_permalink() {
            return get_permalink($this->id);
        }
        
        public function get_type() {
            return get_post_meta($this->id, '_ptp_is_camp', true) === 'yes' ? 'camp' : 'simple';
        }
        
        public function __call($method, $args) {
            error_log("[PTP WC Compat] Unknown product method called: {$method}");
            return null;
        }
    }
    
    // Mark that we're using compatibility layer
    define('PTP_WC_COMPAT_ACTIVE', true);
    
    error_log('[PTP] WooCommerce compatibility layer loaded');
}
```

---

## Loading Order

This file must be loaded FIRST, before any other PTP files that use WC().

In `ptp-training-platform.php`, add at the top of `includes()`:

```php
private function includes() {
    // Load WC compatibility layer FIRST (only if WC not active)
    if (!class_exists('WooCommerce')) {
        require_once PTP_PLUGIN_DIR . 'includes/class-ptp-wc-compat.php';
    }
    
    // Then load native PTP classes
    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-session.php';
    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-cart.php';
    require_once PTP_PLUGIN_DIR . 'includes/class-ptp-order-manager.php';
    
    // ... rest of includes
}
```

---

## Benefits of This Approach

1. **Gradual Migration**: Existing code continues to work
2. **No Emergency**: Can migrate files one at a time
3. **Logging**: Unknown method calls are logged for tracking
4. **Testable**: Can compare behavior with WC active vs compat layer
5. **Reversible**: Can switch back to WC by simply activating it
