<?php
/**
 * PTP WooCommerce Compatibility Layer
 *
 * Provides WC() function and related shims to allow gradual migration
 * from WooCommerce to native PTP classes.
 *
 * This file should be loaded BEFORE any file that calls WC()
 * It only loads if WooCommerce is NOT active.
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

        /**
         * Magic getter for mailer, etc.
         */
        public function __get($name) {
            if ($name === 'mailer') {
                return new PTP_WC_Compat_Mailer();
            }
            return null;
        }

        /**
         * Get API object (stub)
         */
        public function api() {
            return new stdClass();
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

        public function destroy_session() {
            if (function_exists('ptp_session')) {
                ptp_session()->destroy();
            }
        }

        public function __call($method, $args) {
            // Log unknown method calls for migration tracking
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[PTP WC Compat] Unknown session method called: {$method}");
            }
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

        public function empty_cart($clear_persistent_cart = true) {
            if (function_exists('ptp_cart')) {
                ptp_cart()->empty_cart($clear_persistent_cart);
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

        public function get_discount_total() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_discount_total();
            }
            return 0;
        }

        public function add_to_cart($product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array()) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->wc_add_to_cart($product_id, $quantity, $variation_id, $variation, $cart_item_data);
            }
            return false;
        }

        public function remove_cart_item($cart_key) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->remove_cart_item($cart_key);
            }
            return false;
        }

        public function set_quantity($cart_key, $quantity, $refresh_totals = true) {
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

        public function remove_coupon($code) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->remove_coupon($code);
            }
            return false;
        }

        public function has_discount($code = '') {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->has_discount($code);
            }
            return false;
        }

        public function get_applied_coupons() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_applied_coupons();
            }
            return array();
        }

        public function needs_payment() {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->needs_payment();
            }
            return false;
        }

        public function get_cart_item($cart_key) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_cart_item($cart_key);
            }
            return null;
        }

        public function __call($method, $args) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[PTP WC Compat] Unknown cart method called: {$method}");
            }
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

        public function get_email() {
            return $this->get_billing_email();
        }

        public function get_display_name() {
            $user = wp_get_current_user();
            return $user->display_name ?? '';
        }

        public function __call($method, $args) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[PTP WC Compat] Unknown customer method called: {$method}");
            }
            return '';
        }
    }

    /**
     * Mailer compatibility wrapper (stub)
     */
    class PTP_WC_Compat_Mailer {
        public function get_emails() {
            return array();
        }

        public function __call($method, $args) {
            return null;
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
            if (function_exists('ptp_create_order')) {
                return ptp_create_order($args);
            }
            return new WP_Error('orders_unavailable', 'Order system not available');
        }
    }

    /**
     * wc_get_order() replacement
     */
    if (!function_exists('wc_get_order')) {
        function wc_get_order($order_id) {
            if (function_exists('ptp_get_order')) {
                return ptp_get_order($order_id);
            }
            return false;
        }
    }

    /**
     * wc_get_orders() replacement
     */
    if (!function_exists('wc_get_orders')) {
        function wc_get_orders($args = array()) {
            if (function_exists('ptp_get_orders')) {
                return ptp_get_orders($args);
            }
            return array();
        }
    }

    /**
     * wc_get_product() replacement
     */
    if (!function_exists('wc_get_product')) {
        function wc_get_product($product_id) {
            return new PTP_WC_Compat_Product($product_id);
        }
    }

    /**
     * wc_get_products() replacement
     */
    if (!function_exists('wc_get_products')) {
        function wc_get_products($args = array()) {
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

            if (!empty($args['include'])) {
                $query_args['post__in'] = (array) $args['include'];
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
            if (function_exists('ptp_format_price')) {
                return ptp_format_price($price, $args);
            }
            return '$' . number_format((float)$price, 2);
        }
    }

    /**
     * wc_add_notice() replacement
     */
    if (!function_exists('wc_add_notice')) {
        function wc_add_notice($message, $type = 'success', $data = array()) {
            if (function_exists('ptp_session')) {
                $notices = ptp_session()->get('ptp_notices', array());
                $notices[] = array('message' => $message, 'type' => $type, 'data' => $data);
                ptp_session()->set('ptp_notices', $notices);
            }
        }
    }

    /**
     * wc_print_notices() replacement
     */
    if (!function_exists('wc_print_notices')) {
        function wc_print_notices($return = false) {
            if (!function_exists('ptp_session')) {
                return $return ? '' : null;
            }

            $notices = ptp_session()->get('ptp_notices', array());
            $output = '';

            foreach ($notices as $notice) {
                $class = ($notice['type'] ?? 'success') === 'error' ? 'ptp-notice-error' : 'ptp-notice-success';
                $output .= '<div class="ptp-notice ' . esc_attr($class) . '">' . wp_kses_post($notice['message'] ?? '') . '</div>';
            }

            ptp_session()->set('ptp_notices', array());

            if ($return) {
                return $output;
            }
            echo $output;
        }
    }

    /**
     * wc_get_notices() replacement
     */
    if (!function_exists('wc_get_notices')) {
        function wc_get_notices($type = '') {
            if (!function_exists('ptp_session')) {
                return array();
            }

            $notices = ptp_session()->get('ptp_notices', array());

            if ($type) {
                return array_filter($notices, function($n) use ($type) {
                    return ($n['type'] ?? '') === $type;
                });
            }

            return $notices;
        }
    }

    /**
     * wc_clear_notices() replacement
     */
    if (!function_exists('wc_clear_notices')) {
        function wc_clear_notices() {
            if (function_exists('ptp_session')) {
                ptp_session()->set('ptp_notices', array());
            }
        }
    }

    /**
     * URL function replacements
     */
    if (!function_exists('wc_get_cart_url')) {
        function wc_get_cart_url() {
            $cart_page = get_page_by_path('cart');
            return $cart_page ? get_permalink($cart_page) : home_url('/cart/');
        }
    }

    if (!function_exists('wc_get_checkout_url')) {
        function wc_get_checkout_url() {
            $checkout_page = get_page_by_path('checkout');
            return $checkout_page ? get_permalink($checkout_page) : home_url('/checkout/');
        }
    }

    if (!function_exists('wc_get_page_permalink')) {
        function wc_get_page_permalink($page) {
            $pages = array(
                'cart' => 'cart',
                'checkout' => 'checkout',
                'myaccount' => 'account',
                'shop' => 'shop',
            );

            $slug = $pages[$page] ?? $page;
            $page_obj = get_page_by_path($slug);

            return $page_obj ? get_permalink($page_obj) : home_url('/' . $slug . '/');
        }
    }

    if (!function_exists('wc_get_page_id')) {
        function wc_get_page_id($page) {
            $pages = array(
                'cart' => 'cart',
                'checkout' => 'checkout',
                'myaccount' => 'account',
                'shop' => 'shop',
            );

            $slug = $pages[$page] ?? $page;
            $page_obj = get_page_by_path($slug);

            return $page_obj ? $page_obj->ID : 0;
        }
    }

    if (!function_exists('wc_get_account_endpoint_url')) {
        function wc_get_account_endpoint_url($endpoint) {
            $account_page = get_page_by_path('account');
            $base = $account_page ? get_permalink($account_page) : home_url('/account/');
            return trailingslashit($base) . $endpoint . '/';
        }
    }

    /**
     * Order item meta functions
     */
    if (!function_exists('wc_add_order_item_meta')) {
        function wc_add_order_item_meta($item_id, $key, $value, $unique = false) {
            global $wpdb;
            $table = $wpdb->prefix . 'ptp_native_order_item_meta';

            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
            if (!$table_exists) {
                // Create the table
                $charset = $wpdb->get_charset_collate();
                $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
                    meta_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    item_id BIGINT UNSIGNED NOT NULL,
                    meta_key VARCHAR(255) NOT NULL,
                    meta_value LONGTEXT,
                    KEY item_id (item_id),
                    KEY meta_key (meta_key(191))
                ) $charset");
            }

            return $wpdb->insert(
                $table,
                array('item_id' => $item_id, 'meta_key' => $key, 'meta_value' => maybe_serialize($value)),
                array('%d', '%s', '%s')
            );
        }
    }

    if (!function_exists('wc_get_order_item_meta')) {
        function wc_get_order_item_meta($item_id, $key, $single = true) {
            global $wpdb;
            $table = $wpdb->prefix . 'ptp_native_order_item_meta';

            $result = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM $table WHERE item_id = %d AND meta_key = %s",
                $item_id, $key
            ));

            return $result ? maybe_unserialize($result) : ($single ? '' : array());
        }
    }

    if (!function_exists('wc_update_order_item_meta')) {
        function wc_update_order_item_meta($item_id, $key, $value, $prev_value = '') {
            global $wpdb;
            $table = $wpdb->prefix . 'ptp_native_order_item_meta';

            // Check if exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM $table WHERE item_id = %d AND meta_key = %s",
                $item_id, $key
            ));

            if ($exists) {
                return $wpdb->update(
                    $table,
                    array('meta_value' => maybe_serialize($value)),
                    array('item_id' => $item_id, 'meta_key' => $key),
                    array('%s'),
                    array('%d', '%s')
                );
            } else {
                return wc_add_order_item_meta($item_id, $key, $value);
            }
        }
    }

    if (!function_exists('wc_delete_order_item_meta')) {
        function wc_delete_order_item_meta($item_id, $key, $value = '', $delete_all = false) {
            global $wpdb;
            $table = $wpdb->prefix . 'ptp_native_order_item_meta';

            return $wpdb->delete(
                $table,
                array('item_id' => $item_id, 'meta_key' => $key),
                array('%d', '%s')
            );
        }
    }

    /**
     * Misc WC functions
     */
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

    if (!function_exists('wc_clean')) {
        function wc_clean($var) {
            if (is_array($var)) {
                return array_map('wc_clean', $var);
            }
            return sanitize_text_field($var);
        }
    }

    if (!function_exists('wc_sanitize_textarea')) {
        function wc_sanitize_textarea($var) {
            return sanitize_textarea_field($var);
        }
    }

    if (!function_exists('wc_format_decimal')) {
        function wc_format_decimal($number, $dp = false, $trim_zeros = false) {
            $number = floatval($number);
            if ($dp !== false) {
                $number = round($number, $dp);
            }
            return $number;
        }
    }

    if (!function_exists('wc_get_template')) {
        function wc_get_template($template_name, $args = array(), $template_path = '', $default_path = '') {
            extract($args);

            // Try PTP templates first
            $ptp_template = PTP_PLUGIN_DIR . 'templates/' . $template_name;
            if (file_exists($ptp_template)) {
                include $ptp_template;
                return;
            }

            // Try theme
            $theme_template = get_stylesheet_directory() . '/ptp/' . $template_name;
            if (file_exists($theme_template)) {
                include $theme_template;
                return;
            }
        }
    }

    if (!function_exists('wc_get_template_html')) {
        function wc_get_template_html($template_name, $args = array(), $template_path = '', $default_path = '') {
            ob_start();
            wc_get_template($template_name, $args, $template_path, $default_path);
            return ob_get_clean();
        }
    }

    if (!function_exists('wc_is_checkout')) {
        function wc_is_checkout() {
            return is_page('checkout');
        }
    }

    if (!function_exists('wc_is_cart')) {
        function wc_is_cart() {
            return is_page('cart');
        }
    }

    if (!function_exists('is_woocommerce')) {
        function is_woocommerce() {
            return false;
        }
    }

    if (!function_exists('is_shop')) {
        function is_shop() {
            return is_page('shop');
        }
    }

    if (!function_exists('is_product')) {
        function is_product() {
            return is_singular('product') || is_singular('ptp_camp');
        }
    }

    if (!function_exists('is_product_category')) {
        function is_product_category($term = '') {
            return is_tax('product_cat', $term);
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
        public function get_status() { return $this->post->post_status ?? 'publish'; }

        public function get_price($context = 'view') {
            return (float) get_post_meta($this->id, '_price', true);
        }

        public function get_regular_price($context = 'view') {
            return (float) get_post_meta($this->id, '_regular_price', true);
        }

        public function get_sale_price($context = 'view') {
            return (float) get_post_meta($this->id, '_sale_price', true);
        }

        public function get_sku($context = 'view') {
            return get_post_meta($this->id, '_sku', true);
        }

        public function get_stock_quantity($context = 'view') {
            return (int) get_post_meta($this->id, '_stock', true);
        }

        public function get_image_id($context = 'view') {
            return get_post_thumbnail_id($this->id);
        }

        public function get_image($size = 'woocommerce_thumbnail', $attr = array(), $placeholder = true) {
            return get_the_post_thumbnail($this->id, $size, $attr);
        }

        public function is_in_stock() {
            $stock = get_post_meta($this->id, '_stock_status', true);
            return $stock !== 'outofstock';
        }

        public function is_purchasable() {
            return $this->get_status() === 'publish' && $this->is_in_stock();
        }

        public function get_permalink() {
            return get_permalink($this->id);
        }

        public function get_type() {
            $is_camp = get_post_meta($this->id, '_ptp_is_camp', true) === 'yes';
            return $is_camp ? 'camp' : 'simple';
        }

        public function is_type($type) {
            return $this->get_type() === $type;
        }

        public function exists() {
            return $this->post !== null;
        }

        public function get_meta($key, $single = true, $context = 'view') {
            return get_post_meta($this->id, $key, $single);
        }

        public function get_category_ids() {
            $terms = wp_get_object_terms($this->id, 'product_cat', array('fields' => 'ids'));
            return is_wp_error($terms) ? array() : $terms;
        }

        public function __call($method, $args) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("[PTP WC Compat] Unknown product method called: {$method}");
            }
            return null;
        }
    }

    // Mark that we're using compatibility layer
    if (!defined('PTP_WC_COMPAT_ACTIVE')) {
        define('PTP_WC_COMPAT_ACTIVE', true);
    }
}

/**
 * Helper to check if WooCommerce is active OR if we're using compat layer
 */
if (!function_exists('ptp_wc_active')) {
    function ptp_wc_active() {
        return class_exists('WooCommerce') || defined('PTP_WC_COMPAT_ACTIVE');
    }
}

/**
 * Helper to check if we're running without WooCommerce
 */
if (!function_exists('ptp_is_wc_independent')) {
    function ptp_is_wc_independent() {
        return defined('PTP_WC_COMPAT_ACTIVE') && !class_exists('WooCommerce');
    }
}
