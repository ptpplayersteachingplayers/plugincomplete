<?php
/**
 * PTP Cart Helper v1.0.0
 * 
 * Centralized helper class for cart and checkout operations.
 * Provides single source of truth for:
 * - Cart state management
 * - Discount calculations
 * - Nonce handling
 * - Payment verification
 * 
 * Fixes issues identified in v60.2.2 evaluation:
 * - Cart state fragmentation
 * - Multiple discount calculation points
 * - Nonce inconsistency
 * - Missing payment verification
 * 
 * @since 60.3.0
 */

defined('ABSPATH') || exit;

class PTP_Cart_Helper {
    
    private static $instance = null;
    
    // Standardized nonce actions
    const NONCE_CART = 'ptp_cart_action';
    const NONCE_CHECKOUT = 'ptp_checkout_action';
    const NONCE_BUNDLE = 'ptp_bundle_checkout';
    
    // Bundle discount - default values (can be overridden in admin)
    const BUNDLE_DISCOUNT_PERCENT_DEFAULT = 15;
    
    // Processing fee defaults (can be overridden in admin)
    const PROCESSING_FEE_PERCENT_DEFAULT = 3.0;
    const PROCESSING_FEE_FIXED_DEFAULT = 0.30;
    
    /**
     * Get bundle discount percentage from settings
     */
    public static function get_bundle_discount_percent() {
        return floatval(get_option('ptp_bundle_discount_percent', self::BUNDLE_DISCOUNT_PERCENT_DEFAULT));
    }
    
    /**
     * Get processing fee settings
     */
    public static function get_processing_fee_settings() {
        return array(
            'enabled' => (bool) get_option('ptp_processing_fee_enabled', 1),
            'percent' => floatval(get_option('ptp_processing_fee_percent', self::PROCESSING_FEE_PERCENT_DEFAULT)),
            'fixed' => floatval(get_option('ptp_processing_fee_fixed', self::PROCESSING_FEE_FIXED_DEFAULT)),
        );
    }
    
    // Session keys - single source of truth
    const SESSION_CART_KEY = 'ptp_cart_state';
    const COOKIE_SESSION_ID = 'ptp_session'; // Must match Bundle Checkout cookie name
    
    // Cache keys
    const CACHE_PREFIX = 'ptp_cart_';
    const CACHE_EXPIRY = 300; // 5 minutes
    
    // Rate limiting
    const RATE_LIMIT_CHECKOUT_MAX = 10; // Max checkout attempts per window
    const RATE_LIMIT_CHECKOUT_WINDOW = 300; // 5 minute window
    const RATE_LIMIT_CART_MAX = 30; // Max cart operations per window
    const RATE_LIMIT_CART_WINDOW = 60; // 1 minute window
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Ensure session is started - both PHP and WooCommerce
        add_action('init', array($this, 'ensure_session'), 1);
        // Use wp_loaded instead of woocommerce_init to ensure WC is fully ready
        add_action('wp_loaded', array($this, 'ensure_wc_session'), 10);

        // v148: WooCommerce filter only if WC is active
        if (class_exists('WooCommerce')) {
            // Override WooCommerce cart count to include training items
            add_filter('woocommerce_cart_contents_count', array($this, 'adjust_cart_count'), 99);
        }

        // Cleanup cron - defer to avoid early scheduling issues
        add_action('wp_loaded', array($this, 'schedule_cleanup_cron'), 20);
    }
    
    /**
     * Schedule cleanup cron (deferred to avoid Action Scheduler issues)
     */
    public function schedule_cleanup_cron() {
        add_action('ptp_cleanup_abandoned_carts', array($this, 'cleanup_abandoned_bundles'));
        
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('ptp_cleanup_abandoned_carts')) {
            wp_schedule_event(time(), 'daily', 'ptp_cleanup_abandoned_carts');
        }
    }
    
    /**
     * Adjust WooCommerce cart count to include training items
     * This fixes the cart badge in the header
     * v148: Works with both WC and native cart
     */
    public function adjust_cart_count($count) {
        // v148: If using native cart, get count from there
        if (function_exists('ptp_is_wc_independent') && ptp_is_wc_independent()) {
            if (function_exists('ptp_cart')) {
                return ptp_cart()->get_cart_contents_count();
            }
        }

        $cart_data = self::get_cart_data();
        $training_count = count($cart_data['training_items'] ?? array());
        return $count + $training_count;
    }
    
    /**
     * Ensure PHP session is available
     */
    public function ensure_session() {
        if (!session_id() && !headers_sent()) {
            session_start();
        }
    }
    
    /**
     * Ensure WooCommerce session is initialized
     * This is critical for cart persistence
     * v148: Works with both WC and native session
     */
    public function ensure_wc_session() {
        // v148: If using native session, use ptp_session()
        if (function_exists('ptp_is_wc_independent') && ptp_is_wc_independent()) {
            if (function_exists('ptp_session') && !ptp_session()->has_session()) {
                ptp_session()->set_customer_session_cookie(true);
            }
            return;
        }

        // Make sure WC is fully loaded
        if (!function_exists('WC') || !WC()->session || !did_action('woocommerce_after_register_post_type')) {
            return;
        }

        // Initialize WC session if not already done
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }

        // Don't force cart load - let WC handle it naturally
    }
    
    // =========================================================================
    // NONCE HELPERS - Standardized nonce creation and verification
    // =========================================================================
    
    /**
     * Create cart action nonce
     */
    public static function create_cart_nonce() {
        return wp_create_nonce(self::NONCE_CART);
    }
    
    /**
     * Create checkout action nonce
     */
    public static function create_checkout_nonce() {
        return wp_create_nonce(self::NONCE_CHECKOUT);
    }
    
    /**
     * Create bundle action nonce
     */
    public static function create_bundle_nonce() {
        return wp_create_nonce(self::NONCE_BUNDLE);
    }
    
    /**
     * Verify cart action nonce
     */
    public static function verify_cart_nonce($nonce = null) {
        $nonce = $nonce ?? ($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        return wp_verify_nonce($nonce, self::NONCE_CART);
    }
    
    /**
     * Verify checkout action nonce
     * Accepts both new standardized nonce and legacy nonce for backwards compatibility
     */
    public static function verify_checkout_nonce($nonce = null) {
        $nonce = $nonce ?? ($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        // Try new standardized nonce first
        if (wp_verify_nonce($nonce, self::NONCE_CHECKOUT)) {
            return true;
        }
        // Fall back to legacy nonce
        return wp_verify_nonce($nonce, 'ptp_checkout');
    }
    
    /**
     * Verify bundle action nonce
     */
    public static function verify_bundle_nonce($nonce = null) {
        $nonce = $nonce ?? ($_POST['nonce'] ?? $_GET['nonce'] ?? '');
        return wp_verify_nonce($nonce, self::NONCE_BUNDLE);
    }
    
    /**
     * Send nonce error response
     */
    public static function send_nonce_error() {
        wp_send_json_error(array(
            'message' => 'Security check failed. Please refresh the page and try again.',
            'code' => 'nonce_failed'
        ));
    }
    
    // =========================================================================
    // SESSION ID - Unique identifier for anonymous users
    // =========================================================================
    
    /**
     * Get or create session ID for current user/visitor
     * MUST match the session ID used by PTP_Bundle_Checkout
     */
    public static function get_session_id() {
        // Check cookie first (works for both logged-in and anonymous)
        if (!empty($_COOKIE[self::COOKIE_SESSION_ID])) {
            return sanitize_text_field($_COOKIE[self::COOKIE_SESSION_ID]);
        }
        
        // Generate new session ID
        $session_id = wp_generate_uuid4();
        
        if (!headers_sent()) {
            setcookie(
                self::COOKIE_SESSION_ID,
                $session_id,
                time() + (30 * DAY_IN_SECONDS),
                '/',
                '',
                is_ssl(),
                true
            );
        }
        
        $_COOKIE[self::COOKIE_SESSION_ID] = $session_id;
        
        return $session_id;
    }
    
    // =========================================================================
    // CART STATE - Single source of truth from database
    // =========================================================================
    
    /**
     * Get unified cart data from database (single source of truth)
     * All other storage mechanisms (session, cookie, WC session) are deprecated
     * v148: Works with both WC and native cart
     */
    public static function get_cart_data($force_refresh = false) {
        // v148: Use native session if WC-independent
        $wc_independent = function_exists('ptp_is_wc_independent') && ptp_is_wc_independent();

        if ($wc_independent) {
            if (function_exists('ptp_session') && !ptp_session()->has_session()) {
                ptp_session()->set_customer_session_cookie(true);
            }
        } else {
            // Ensure WooCommerce session is available
            if (function_exists('WC') && WC()->session && !WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
        }

        $session_id = self::get_session_id();
        $cache_key = self::CACHE_PREFIX . md5($session_id);

        // Check cache unless force refresh
        if (!$force_refresh) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        // Build cart data
        $data = array(
            'woo_items' => array(),
            'training_items' => array(),
            'woo_subtotal' => 0,
            'training_subtotal' => 0,
            'subtotal' => 0,
            'bundle_discount' => 0,
            'bundle_discount_percent' => self::get_bundle_discount_percent(),
            'total' => 0,
            'item_count' => 0,
            'has_bundle' => false,
            'has_camps' => false,
            'has_training' => false,
            'checkout_url' => '',
            'checkout_label' => 'Checkout',
            'bundle_code' => null,
        );

        // v148: Get cart items based on mode
        if ($wc_independent && function_exists('ptp_cart')) {
            // Native cart mode
            $native_cart = ptp_cart();
            $native_cart->calculate_totals();

            foreach ($native_cart->get_cart() as $cart_key => $cart_item) {
                $item_type = $cart_item['item_type'] ?? 'product';
                $is_camp = ($item_type === 'camp');
                $is_training = ($item_type === 'training');

                if ($is_camp) {
                    $data['has_camps'] = true;
                }
                if ($is_training) {
                    $data['has_training'] = true;
                }

                $item = array(
                    'key' => $cart_key,
                    'product_id' => $cart_item['item_id'],
                    'name' => $cart_item['metadata']['name'] ?? 'Item',
                    'quantity' => $cart_item['quantity'],
                    'price' => floatval($cart_item['price']),
                    'subtotal' => $cart_item['line_total'],
                    'line_total' => $cart_item['line_total'],
                    'image' => '',
                    'permalink' => '',
                    'is_camp' => $is_camp,
                    'type' => $item_type,
                    'metadata' => $cart_item['metadata'] ?? array(),
                );

                if ($is_training) {
                    $data['training_items'][] = $item;
                    $data['training_subtotal'] += $item['subtotal'];
                } else {
                    $data['woo_items'][] = $item;
                    $data['woo_subtotal'] += $item['subtotal'];
                }
            }
        } elseif (function_exists('WC') && WC()->cart) {
            // WooCommerce cart mode
            // Ensure cart totals are calculated
            if (!did_action('woocommerce_cart_loaded_from_session')) {
                WC()->cart->get_cart_from_session();
            }
            WC()->cart->calculate_totals();

            foreach (WC()->cart->get_cart() as $cart_key => $cart_item) {
                $product = $cart_item['data'];
                $product_id = $cart_item['product_id'];

                $is_camp = self::is_camp_product($product);
                if ($is_camp) {
                    $data['has_camps'] = true;
                }

                // Get price - use line_subtotal if available, otherwise calculate
                $item_subtotal = floatval($cart_item['line_subtotal'] ?? 0);
                if ($item_subtotal <= 0) {
                    // Fallback: calculate from product price × quantity
                    $item_subtotal = floatval($product->get_price()) * intval($cart_item['quantity']);
                }

                $item = array(
                    'key' => $cart_key,
                    'product_id' => $product_id,
                    'name' => $product->get_name(),
                    'quantity' => $cart_item['quantity'],
                    'price' => floatval($product->get_price()),
                    'subtotal' => $item_subtotal,
                    'line_total' => $item_subtotal,
                    'image' => self::get_product_full_image($product),
                    'permalink' => get_permalink($product_id),
                    'is_camp' => $is_camp,
                    'type' => $is_camp ? 'camp' : 'product',
                );

                $data['woo_items'][] = $item;
                $data['woo_subtotal'] += $item['subtotal'];
            }
        }
        
        // Get pending training from database (single source of truth)
        $training_items = self::get_pending_training_from_db($session_id);
        
        // ALSO check session for training items (for direct trainer profile → checkout flow)
        if (empty($training_items)) {
            $session_training = self::get_training_from_session();
            if (!empty($session_training)) {
                $training_items = $session_training;
            }
        }
        
        foreach ($training_items as $item) {
            $data['training_items'][] = $item;
            $data['training_subtotal'] += floatval($item['price']);
            $data['has_training'] = true;
            
            if (!empty($item['bundle_code'])) {
                $data['bundle_code'] = $item['bundle_code'];
            }
        }
        
        // Calculate totals using centralized function
        $totals = self::calculate_totals(
            $data['woo_subtotal'],
            $data['training_subtotal'],
            $data['has_camps'],
            $data['has_training']
        );
        
        $data['subtotal'] = $totals['subtotal'];
        $data['bundle_discount'] = $totals['bundle_discount'];
        $data['processing_fee'] = $totals['processing_fee'];
        $data['total'] = $totals['total'];
        $data['has_bundle'] = $totals['has_bundle'];
        $data['item_count'] = count($data['woo_items']) + count($data['training_items']);
        
        // Determine checkout URL
        $data['checkout_url'] = self::get_checkout_url($data);
        $data['checkout_label'] = self::get_checkout_label($data);
        
        // Cache the result
        set_transient($cache_key, $data, self::CACHE_EXPIRY);
        
        return $data;
    }
    
    /**
     * Get pending training items from database only
     * Updated to properly match bundles by session_id or user_id
     */
    private static function get_pending_training_from_db($session_id) {
        global $wpdb;
        
        $items = array();
        $table = $wpdb->prefix . 'ptp_bundles';
        
        error_log('[PTP Cart Helper] get_pending_training_from_db called');
        error_log('[PTP Cart Helper] session_id: ' . $session_id);
        
        // Check if table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            error_log('[PTP Cart Helper] ptp_bundles table does NOT exist');
            return $items;
        }
        
        // Query bundles table for active training
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        error_log('[PTP Cart Helper] user_id: ' . $user_id);
        
        // Build query - check by session_id OR user_id
        $sql = "SELECT * FROM {$table} 
                WHERE status IN ('active', 'partial')
                AND (expires_at IS NULL OR expires_at > NOW())
                AND trainer_id IS NOT NULL
                AND training_amount > 0";
        
        if ($user_id > 0) {
            $sql .= $wpdb->prepare(" AND (session_id = %s OR user_id = %d)", $session_id, $user_id);
        } else {
            $sql .= $wpdb->prepare(" AND session_id = %s", $session_id);
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT 1";
        
        error_log('[PTP Cart Helper] SQL: ' . $sql);
        
        $bundle = $wpdb->get_row($sql);
        
        if (!$bundle) {
            error_log('[PTP Cart Helper] No bundle found in database');
            // Debug: check what bundles exist
            $all_bundles = $wpdb->get_results("SELECT id, session_id, user_id, status, trainer_id, training_amount FROM {$table} ORDER BY created_at DESC LIMIT 5");
            error_log('[PTP Cart Helper] Recent bundles: ' . print_r($all_bundles, true));
        } else {
            error_log('[PTP Cart Helper] Bundle found: ' . print_r($bundle, true));
        }
        
        if ($bundle && $bundle->trainer_id) {
            $trainer = class_exists('PTP_Trainer') ? PTP_Trainer::get($bundle->trainer_id) : null;
            
            $package_names = array(
                'single' => 'Single Session',
                '5pack' => '5-Session Pack',
                '10pack' => '10-Session Pack',
                'package_5' => '5-Session Pack',
                'package_10' => '10-Session Pack',
            );
            
            $items[] = array(
                'type' => 'training',
                'bundle_code' => $bundle->bundle_code,
                'bundle_id' => $bundle->id,
                'trainer_id' => $bundle->trainer_id,
                'trainer_name' => $trainer ? $trainer->display_name : 'Trainer',
                'trainer_image' => $trainer ? ($trainer->photo_url ?: '') : '',
                'package' => $bundle->training_package,
                'package_name' => $package_names[$bundle->training_package] ?? 'Training Session',
                'sessions' => intval($bundle->training_sessions),
                'date' => $bundle->training_date,
                'time' => $bundle->training_time,
                'location' => $bundle->training_location,
                'price' => floatval($bundle->training_amount),
                'removable' => true,
            );
        }
        
        return $items;
    }
    
    /**
     * Get training items from session (for direct trainer profile → checkout flow)
     */
    private static function get_training_from_session() {
        $items = array();
        
        // Ensure session is started
        if (!session_id() && !headers_sent()) {
            @session_start();
        }
        
        // Try WooCommerce session first
        $training = array();
        if (function_exists('WC') && WC()->session) {
            $training = WC()->session->get('ptp_training_items', array());
        }
        
        // Fallback to PHP session
        if (empty($training) && isset($_SESSION['ptp_training_items'])) {
            $training = $_SESSION['ptp_training_items'];
        }
        
        if (!is_array($training) || empty($training)) {
            return $items;
        }
        
        $package_names = array(
            'single' => 'Single Session',
            '5pack' => '5-Session Pack',
            '10pack' => '10-Session Pack',
        );
        
        foreach ($training as $item) {
            // Get trainer info if we have an ID
            $trainer_image = $item['trainer_photo'] ?? '';
            $trainer_name = $item['trainer_name'] ?? 'Trainer';
            
            if (empty($trainer_image) && !empty($item['trainer_id'])) {
                global $wpdb;
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    intval($item['trainer_id'])
                ));
                if ($trainer) {
                    $trainer_image = $trainer->photo_url ?: '';
                    if (empty($trainer_name) || $trainer_name === 'Trainer') {
                        $trainer_name = $trainer->display_name;
                    }
                }
            }
            
            $items[] = array(
                'type' => 'training',
                'bundle_code' => '',
                'bundle_id' => 0,
                'trainer_id' => intval($item['trainer_id'] ?? 0),
                'trainer_name' => $trainer_name,
                'trainer_image' => $trainer_image,
                'package' => $item['session_type'] ?? 'single',
                'package_name' => $item['package_name'] ?? ($package_names[$item['session_type'] ?? 'single'] ?? 'Training Session'),
                'sessions' => intval($item['sessions'] ?? 1),
                'date' => $item['date'] ?? '',
                'time' => $item['time'] ?? '',
                'location' => $item['location'] ?? '',
                'price' => floatval($item['price'] ?? 0),
                'removable' => true,
                'from_session' => true, // Flag to identify session-based items
            );
        }
        
        return $items;
    }
    
    /**
     * Invalidate cart cache
     */
    public static function invalidate_cart_cache() {
        $session_id = self::get_session_id();
        $cache_key = self::CACHE_PREFIX . md5($session_id);
        delete_transient($cache_key);
    }
    
    /**
     * Clear all training items from cart (bundles table and session)
     */
    public static function clear_training_items() {
        global $wpdb;
        
        error_log('[PTP Cart Helper] clear_training_items called');
        
        // Start session if needed
        if (!session_id() && !headers_sent()) {
            session_start();
        }
        
        $session_id = self::get_session_id();
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $table = $wpdb->prefix . 'ptp_bundles';
        
        error_log('[PTP Cart Helper] Session ID: ' . $session_id . ', User ID: ' . $user_id);
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        
        if ($table_exists) {
            // Delete active bundles for this session/user
            if ($user_id > 0) {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} 
                     WHERE status IN ('active', 'partial', 'pending')
                     AND (session_id = %s OR user_id = %d)",
                    $session_id, $user_id
                ));
            } else {
                $deleted = $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$table} 
                     WHERE status IN ('active', 'partial', 'pending')
                     AND session_id = %s",
                    $session_id
                ));
            }
            error_log('[PTP Cart Helper] Deleted ' . $deleted . ' bundles from database');
        }
        
        // Clear WooCommerce session data
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('ptp_active_bundle', null);
            WC()->session->set('ptp_training_items', array());
            WC()->session->set('ptp_bundle_code', null);
            WC()->session->set('ptp_training_cart', array());
            WC()->session->set('ptp_selected_trainer', null);
            WC()->session->set('ptp_booking_data', null);
            error_log('[PTP Cart Helper] WC session data cleared');
        }
        
        // Clear PHP session variables
        $session_keys = array(
            'ptp_training_items',
            'ptp_bundle_code', 
            'ptp_training_cart',
            'ptp_active_bundle',
            'ptp_selected_trainer',
            'ptp_booking_data'
        );
        foreach ($session_keys as $key) {
            if (isset($_SESSION[$key])) {
                unset($_SESSION[$key]);
            }
        }
        error_log('[PTP Cart Helper] PHP session data cleared');
        
        // Invalidate cart cache
        self::invalidate_cart_cache();
        
        return true;
    }
    
    // =========================================================================
    // DISCOUNT CALCULATION - Single point of calculation
    // =========================================================================
    
    /**
     * Calculate all cart totals including bundle discount and processing fee
     * THIS IS THE ONLY PLACE TOTALS SHOULD BE CALCULATED
     * Settings are configurable in WP Admin → PTP Training → Settings → Checkout
     */
    public static function calculate_totals($woo_subtotal, $training_subtotal, $has_camps, $has_training) {
        $subtotal = floatval($woo_subtotal) + floatval($training_subtotal);
        $has_bundle = $has_camps && $has_training;
        $bundle_discount = 0;
        
        // Get bundle discount from settings
        $bundle_discount_percent = self::get_bundle_discount_percent();
        
        if ($has_bundle && $subtotal > 0) {
            $bundle_discount = round($subtotal * ($bundle_discount_percent / 100), 2);
        }
        
        $discounted_subtotal = $subtotal - $bundle_discount;
        
        // Get processing fee settings
        $fee_settings = self::get_processing_fee_settings();
        $processing_fee = 0;
        
        if ($fee_settings['enabled'] && $discounted_subtotal > 0) {
            $processing_fee = round(($discounted_subtotal * ($fee_settings['percent'] / 100)) + $fee_settings['fixed'], 2);
        }
        
        $total = $discounted_subtotal + $processing_fee;
        
        return array(
            'subtotal' => $subtotal,
            'bundle_discount' => $bundle_discount,
            'discounted_subtotal' => $discounted_subtotal,
            'processing_fee' => $processing_fee,
            'total' => $total,
            'has_bundle' => $has_bundle,
            'discount_percent' => $bundle_discount_percent,
            'processing_fee_enabled' => $fee_settings['enabled'],
            'processing_fee_percent' => $fee_settings['percent'],
            'processing_fee_fixed' => $fee_settings['fixed'],
        );
    }
    
    /**
     * Calculate discount for a specific amount (when camps present)
     */
    public static function calculate_bundle_discount($amount, $has_camps = null) {
        // If has_camps not specified, check cart
        if ($has_camps === null) {
            $cart_data = self::get_cart_data();
            $has_camps = $cart_data['has_camps'];
        }
        
        if (!$has_camps || $amount <= 0) {
            return 0;
        }
        
        $bundle_discount_percent = self::get_bundle_discount_percent();
        return round($amount * ($bundle_discount_percent / 100), 2);
    }
    
    // =========================================================================
    // PRODUCT IDENTIFICATION
    // =========================================================================
    
    /**
     * Check if a WooCommerce product is a camp/clinic
     */
    public static function is_camp_product($product) {
        if (!$product || !is_object($product)) {
            return false;
        }
        
        $product_id = $product->get_id();
        
        // Check meta first (fastest)
        $product_type = get_post_meta($product_id, '_ptp_product_type', true);
        if (in_array($product_type, array('camp', 'clinic'))) {
            return true;
        }
        
        // Check name
        $title = strtolower($product->get_name());
        if (strpos($title, 'camp') !== false || strpos($title, 'clinic') !== false) {
            return true;
        }
        
        // Check categories
        $cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
        $camp_cats = array('camps', 'clinics', 'camp', 'clinic', 'winter-clinics', 'summer-camps');
        
        if (is_array($cats) && array_intersect($cats, $camp_cats)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get full-size product image URL (not thumbnail)
     * Used for cart and checkout display
     */
    public static function get_product_full_image($product) {
        if (!$product || !is_object($product)) {
            return '';
        }
        
        $image_id = $product->get_image_id();
        
        if ($image_id) {
            // Try to get large size first, then full
            $image_url = wp_get_attachment_image_url($image_id, 'large');
            if (!$image_url) {
                $image_url = wp_get_attachment_image_url($image_id, 'medium_large');
            }
            if (!$image_url) {
                $image_url = wp_get_attachment_image_url($image_id, 'full');
            }
            
            if ($image_url) {
                return $image_url;
            }
        }
        
        // Fallback to WooCommerce placeholder
        return wc_placeholder_img_src('large');
    }
    
    /**
     * Get trainer photo URL with fallback
     */
    public static function get_trainer_photo($trainer, $size = 400) {
        if (!$trainer) {
            return '';
        }
        
        if (!empty($trainer->photo_url)) {
            return $trainer->photo_url;
        }
        
        // Generate avatar from name
        $name = $trainer->display_name ?: 'Trainer';
        return 'https://ui-avatars.com/api/?name=' . urlencode($name) . '&size=' . $size . '&background=FCB900&color=0A0A0A&bold=true';
    }
    
    // =========================================================================
    // CHECKOUT URL ROUTING
    // =========================================================================
    
    /**
     * Get appropriate checkout URL based on cart contents
     * All checkout flows now go through /ptp-checkout/
     */
    public static function get_checkout_url($cart_data = null) {
        if ($cart_data === null) {
            $cart_data = self::get_cart_data();
        }
        
        // All non-empty carts go to unified checkout
        if ($cart_data['has_training'] || count($cart_data['woo_items']) > 0) {
            return home_url('/ptp-checkout/');
        }
        
        // Empty cart
        return home_url('/ptp-cart/');
    }
    
    /**
     * Get checkout button label
     */
    public static function get_checkout_label($cart_data = null) {
        if ($cart_data === null) {
            $cart_data = self::get_cart_data();
        }
        
        if ($cart_data['has_bundle']) {
            return 'Bundle Checkout - Save ' . self::get_bundle_discount_percent() . '%';
        }
        
        if ($cart_data['has_training'] && !$cart_data['has_camps']) {
            return 'Complete Training Booking';
        }
        
        if (!$cart_data['has_training'] && count($cart_data['woo_items']) > 0) {
            return 'Proceed to Checkout';
        }
        
        return 'Browse Trainers';
    }
    
    // =========================================================================
    // PAYMENT VERIFICATION
    // =========================================================================
    
    /**
     * Verify Stripe payment status before confirming order
     * Returns true only if payment is confirmed successful
     */
    public static function verify_stripe_payment($payment_intent_id) {
        if (empty($payment_intent_id)) {
            return new WP_Error('no_payment_intent', 'No payment intent ID provided');
        }
        
        if (!class_exists('PTP_Stripe')) {
            return new WP_Error('stripe_not_available', 'Stripe class not available');
        }
        
        // Retrieve payment intent from Stripe
        $payment_intent = PTP_Stripe::get_payment_intent($payment_intent_id);
        
        if (is_wp_error($payment_intent)) {
            error_log('PTP Cart Helper: Stripe verification failed - ' . $payment_intent->get_error_message());
            return $payment_intent;
        }
        
        // Check status
        $status = $payment_intent['status'] ?? '';
        
        if ($status === 'succeeded') {
            return true;
        }
        
        if ($status === 'requires_payment_method') {
            return new WP_Error('payment_failed', 'Payment was not completed. Please try again.');
        }
        
        if ($status === 'requires_action') {
            return new WP_Error('requires_action', 'Payment requires additional authentication.');
        }
        
        if ($status === 'processing') {
            // Payment is still processing - may need to wait
            return new WP_Error('payment_processing', 'Payment is still processing. Please wait.');
        }
        
        if ($status === 'canceled') {
            return new WP_Error('payment_canceled', 'Payment was canceled.');
        }
        
        return new WP_Error('payment_status_unknown', 'Payment status: ' . $status);
    }
    
    // =========================================================================
    // TRANSACTION HANDLING
    // =========================================================================
    
    /**
     * Start database transaction
     */
    public static function start_transaction() {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
    }
    
    /**
     * Commit database transaction
     */
    public static function commit_transaction() {
        global $wpdb;
        $wpdb->query('COMMIT');
    }
    
    /**
     * Rollback database transaction
     */
    public static function rollback_transaction() {
        global $wpdb;
        $wpdb->query('ROLLBACK');
    }
    
    /**
     * Execute callback within transaction
     */
    public static function with_transaction($callback) {
        self::start_transaction();
        
        try {
            $result = $callback();
            self::commit_transaction();
            return $result;
        } catch (Exception $e) {
            self::rollback_transaction();
            throw $e;
        }
    }
    
    // =========================================================================
    // RATE LIMITING
    // =========================================================================
    
    /**
     * Check if an action is rate limited
     * 
     * @param string $action Action identifier (e.g., 'checkout', 'payment')
     * @param int $limit Maximum number of attempts
     * @param int $window Time window in seconds
     * @return bool|WP_Error True if allowed, WP_Error if rate limited
     */
    public static function check_rate_limit($action, $limit = 10, $window = 60) {
        $session_id = self::get_session_id();
        $key = 'ptp_rate_' . $action . '_' . md5($session_id);
        
        $current = get_transient($key);
        
        if ($current === false) {
            // First request - set counter
            set_transient($key, 1, $window);
            return true;
        }
        
        if ($current >= $limit) {
            error_log("PTP Rate Limit: $action blocked for session $session_id ($current/$limit in {$window}s)");
            return new WP_Error(
                'rate_limited',
                'Too many requests. Please wait a moment and try again.',
                array('retry_after' => $window)
            );
        }
        
        // Increment counter
        set_transient($key, $current + 1, $window);
        return true;
    }
    
    /**
     * Check checkout rate limit (stricter - 5 attempts per minute)
     */
    public static function check_checkout_rate_limit() {
        return self::check_rate_limit('checkout', 5, 60);
    }
    
    /**
     * Check payment confirmation rate limit (very strict - 3 attempts per minute)
     */
    public static function check_payment_rate_limit() {
        return self::check_rate_limit('payment_confirm', 3, 60);
    }
    
    /**
     * Check cart action rate limit (more lenient - 30 per minute)
     */
    public static function check_cart_rate_limit() {
        return self::check_rate_limit('cart_action', 30, 60);
    }
    
    /**
     * Send rate limit error response
     */
    public static function send_rate_limit_error($error = null) {
        if (!$error || !is_wp_error($error)) {
            $error = new WP_Error('rate_limited', 'Too many requests. Please wait a moment and try again.');
        }
        
        wp_send_json_error(array(
            'message' => $error->get_error_message(),
            'code' => 'rate_limited',
            'retry_after' => $error->get_error_data()['retry_after'] ?? 60
        ), 429);
    }
    
    // =========================================================================
    // BUNDLE MANAGEMENT
    // =========================================================================
    
    /**
     * Generate unique bundle code
     */
    public static function generate_bundle_code() {
        return 'BND-' . strtoupper(wp_generate_password(8, false, false));
    }
    
    /**
     * Get active bundle for current session
     */
    public static function get_active_bundle() {
        $cart_data = self::get_cart_data();
        
        if (empty($cart_data['bundle_code'])) {
            return null;
        }
        
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bundles 
             WHERE bundle_code = %s 
             AND status IN ('active', 'partial')
             AND (expires_at IS NULL OR expires_at > NOW())",
            $cart_data['bundle_code']
        ));
    }
    
    /**
     * Clear active bundle
     */
    public static function clear_bundle($bundle_code = null) {
        global $wpdb;
        
        if (!$bundle_code) {
            $bundle = self::get_active_bundle();
            $bundle_code = $bundle ? $bundle->bundle_code : null;
        }
        
        if ($bundle_code) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_bundles',
                array('status' => 'cancelled'),
                array('bundle_code' => $bundle_code)
            );
        }
        
        // Invalidate cache
        self::invalidate_cart_cache();
    }
    
    /**
     * Cleanup abandoned bundles (called by cron)
     */
    public function cleanup_abandoned_bundles() {
        global $wpdb;
        
        // Mark expired bundles as abandoned
        $wpdb->query(
            "UPDATE {$wpdb->prefix}ptp_bundles 
             SET status = 'abandoned' 
             WHERE status IN ('active', 'partial') 
             AND expires_at < NOW()"
        );
        
        // Delete old abandoned bundles (older than 30 days)
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}ptp_bundles 
             WHERE status = 'abandoned' 
             AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        error_log('PTP Cart Helper: Cleaned up abandoned bundles');
    }
    
    // =========================================================================
    // SANITIZATION HELPERS
    // =========================================================================
    
    /**
     * Sanitize customer data from POST
     */
    public static function sanitize_customer_data($data = null) {
        if ($data === null) {
            $data = $_POST;
        }
        
        return array(
            'first_name' => sanitize_text_field($data['first_name'] ?? ''),
            'last_name' => sanitize_text_field($data['last_name'] ?? ''),
            'email' => sanitize_email($data['email'] ?? ''),
            'phone' => sanitize_text_field($data['phone'] ?? ''),
        );
    }
    
    /**
     * Validate required customer fields
     */
    public static function validate_customer_data($customer) {
        $errors = array();
        
        if (empty($customer['first_name'])) {
            $errors[] = 'First name is required';
        }
        
        if (empty($customer['email']) || !is_email($customer['email'])) {
            $errors[] = 'Valid email is required';
        }
        
        return $errors;
    }
}

// Initialize
function ptp_cart_helper() {
    return PTP_Cart_Helper::instance();
}

add_action('plugins_loaded', 'ptp_cart_helper', 5);

// =========================================================================
// GLOBAL HELPER FUNCTIONS
// =========================================================================

/**
 * Get unified cart data
 */
function ptp_get_cart_data($force_refresh = false) {
    return PTP_Cart_Helper::get_cart_data($force_refresh);
}

/**
 * Calculate cart totals
 */
function ptp_calculate_cart_totals($woo_subtotal, $training_subtotal, $has_camps, $has_training) {
    return PTP_Cart_Helper::calculate_totals($woo_subtotal, $training_subtotal, $has_camps, $has_training);
}

/**
 * Verify Stripe payment
 */
function ptp_verify_stripe_payment($payment_intent_id) {
    return PTP_Cart_Helper::verify_stripe_payment($payment_intent_id);
}

/**
 * Get checkout URL
 */
function ptp_get_checkout_url() {
    return PTP_Cart_Helper::get_checkout_url();
}

/**
 * Check if cart has bundle discount
 */
function ptp_cart_has_bundle() {
    $cart = PTP_Cart_Helper::get_cart_data();
    return $cart['has_bundle'];
}

/**
 * Get bundle discount amount for cart
 */
function ptp_get_cart_bundle_discount() {
    $cart = PTP_Cart_Helper::get_cart_data();
    return $cart['bundle_discount'];
}

/**
 * Invalidate cart cache
 */
function ptp_invalidate_cart_cache() {
    PTP_Cart_Helper::invalidate_cart_cache();
}
