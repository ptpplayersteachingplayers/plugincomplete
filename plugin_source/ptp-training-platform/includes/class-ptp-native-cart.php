<?php
/**
 * PTP Native Cart Manager
 *
 * Replaces WooCommerce cart with native implementation.
 * Provides the same API as WC_Cart for easy migration.
 *
 * Supports:
 * - Training sessions
 * - Camp registrations
 * - Package purchases
 * - Add-ons (jerseys, etc.)
 *
 * @version 1.0.0
 * @since 148.0.0
 */

defined('ABSPATH') || exit;

class PTP_Native_Cart {

    /** @var PTP_Native_Cart */
    private static $instance = null;

    /** @var array Cart items */
    private $items = array();

    /** @var array Applied fees */
    private $fees = array();

    /** @var array Applied coupons/discounts */
    private $discounts = array();

    /** @var bool */
    private $dirty = false;

    /** @var bool */
    private $loaded = false;

    /** @var string */
    private $table_name;

    /** @var float Cached totals */
    private $subtotal = 0;
    private $discount_total = 0;
    private $fee_total = 0;
    private $total = 0;

    /**
     * Get instance (singleton)
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
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'ptp_native_cart_items';

        // Load cart after session is ready
        add_action('init', array($this, 'load_cart'), 5);

        // Save cart on shutdown
        add_action('shutdown', array($this, 'save_cart'), 20);
    }

    /**
     * Load cart from database
     */
    public function load_cart() {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;

        if (!function_exists('ptp_session')) {
            return;
        }

        $session_key = ptp_session()->get_session_key();
        if (empty($session_key)) {
            return;
        }

        global $wpdb;

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            $this->items = array();
            return;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE session_key = %s ORDER BY added_at ASC",
            $session_key
        ));

        $this->items = array();

        foreach ($rows as $row) {
            $metadata = json_decode($row->metadata, true) ?: array();
            $cart_key = $this->generate_cart_key($row->item_type, $row->item_id, $metadata);
            $this->items[$cart_key] = array(
                'cart_item_id' => $row->cart_item_id,
                'item_type' => $row->item_type,
                'item_id' => (int) $row->item_id,
                'quantity' => (int) $row->quantity,
                'price' => (float) $row->price,
                'metadata' => $metadata,
                'line_total' => (float) $row->price * (int) $row->quantity,
                'data' => $this->get_item_data($row->item_type, $row->item_id),
            );
        }

        // Load fees and discounts from session
        $this->fees = ptp_session()->get('ptp_cart_fees', array());
        $this->discounts = ptp_session()->get('ptp_cart_discounts', array());

        $this->calculate_totals();
    }

    /**
     * Get item data object (for WC compatibility)
     */
    private function get_item_data($item_type, $item_id) {
        return (object) array(
            'item_type' => $item_type,
            'item_id' => $item_id,
        );
    }

    /**
     * Save cart to database
     */
    public function save_cart() {
        if (!$this->dirty) {
            return;
        }

        if (!function_exists('ptp_session')) {
            return;
        }

        global $wpdb;

        $session_key = ptp_session()->get_session_key();
        if (empty($session_key)) {
            return;
        }

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        if (!$table_exists) {
            return;
        }

        // Delete existing items
        $wpdb->delete($this->table_name, array('session_key' => $session_key), array('%s'));

        // Insert current items
        foreach ($this->items as $item) {
            $wpdb->insert(
                $this->table_name,
                array(
                    'session_key' => $session_key,
                    'item_type' => $item['item_type'],
                    'item_id' => $item['item_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'metadata' => json_encode($item['metadata']),
                    'added_at' => current_time('mysql'),
                ),
                array('%s', '%s', '%d', '%d', '%f', '%s', '%s')
            );
        }

        // Save fees and discounts to session
        ptp_session()->set('ptp_cart_fees', $this->fees);
        ptp_session()->set('ptp_cart_discounts', $this->discounts);

        $this->dirty = false;
    }

    /**
     * Generate unique cart key for item
     */
    private function generate_cart_key($item_type, $item_id, $metadata = array()) {
        $key_parts = array($item_type, $item_id);

        // Include relevant metadata in key for unique identification
        if (!empty($metadata)) {
            $filtered_meta = $metadata;
            // Remove non-unique fields
            unset($filtered_meta['name'], $filtered_meta['variation'], $filtered_meta['product_id']);
            if (!empty($filtered_meta)) {
                ksort($filtered_meta);
                $key_parts[] = md5(json_encode($filtered_meta));
            }
        }

        return implode('_', $key_parts);
    }

    /**
     * Add item to cart
     * Matches WC()->cart->add_to_cart()
     *
     * @param string $item_type 'training', 'camp', 'package', 'addon', 'product'
     * @param int $item_id
     * @param int $quantity
     * @param float $price
     * @param array $metadata
     * @return string|false Cart key or false on failure
     */
    public function add_to_cart($item_type, $item_id, $quantity = 1, $price = 0, $metadata = array()) {
        // Ensure cart is loaded
        if (!$this->loaded) {
            $this->load_cart();
        }

        $cart_key = $this->generate_cart_key($item_type, $item_id, $metadata);

        // If item exists, update quantity
        if (isset($this->items[$cart_key])) {
            $this->items[$cart_key]['quantity'] += $quantity;
            $this->items[$cart_key]['line_total'] = $this->items[$cart_key]['price'] * $this->items[$cart_key]['quantity'];
        } else {
            // Add new item
            $this->items[$cart_key] = array(
                'cart_item_id' => null,
                'item_type' => $item_type,
                'item_id' => (int) $item_id,
                'quantity' => (int) $quantity,
                'price' => (float) $price,
                'metadata' => $metadata,
                'line_total' => (float) $price * (int) $quantity,
                'data' => $this->get_item_data($item_type, $item_id),
            );
        }

        $this->dirty = true;
        $this->calculate_totals();

        do_action('ptp_cart_item_added', $cart_key, $item_id, $quantity, $item_type, $metadata);

        return $cart_key;
    }

    /**
     * WC-compatible add_to_cart (takes product_id as first param)
     */
    public function wc_add_to_cart($product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array()) {
        // Determine item type
        $is_camp = get_post_meta($product_id, '_ptp_is_camp', true) === 'yes';
        $price = (float) get_post_meta($product_id, '_price', true);

        $item_type = $is_camp ? 'camp' : 'product';

        $metadata = array_merge($cart_item_data, array(
            'product_id' => $product_id,
            'variation_id' => $variation_id,
            'variation' => $variation,
            'name' => get_the_title($product_id),
        ));

        return $this->add_to_cart($item_type, $product_id, $quantity, $price, $metadata);
    }

    /**
     * Add training session to cart
     * Helper method for training bookings
     */
    public function add_training($trainer_id, $session_data, $price) {
        return $this->add_to_cart('training', $trainer_id, 1, $price, array(
            'trainer_id' => $trainer_id,
            'session_date' => $session_data['date'] ?? '',
            'start_time' => $session_data['start_time'] ?? '',
            'end_time' => $session_data['end_time'] ?? '',
            'duration' => $session_data['duration'] ?? 60,
            'location' => $session_data['location'] ?? '',
            'player_ids' => $session_data['player_ids'] ?? array(),
        ));
    }

    /**
     * Add camp registration to cart
     * Helper method for camp bookings
     */
    public function add_camp($camp_id, $player_data, $price) {
        return $this->add_to_cart('camp', $camp_id, 1, $price, array(
            'camp_id' => $camp_id,
            'player_name' => $player_data['name'] ?? '',
            'player_age' => $player_data['age'] ?? '',
            'player_id' => $player_data['id'] ?? 0,
            'week' => $player_data['week'] ?? '',
        ));
    }

    /**
     * Remove item from cart
     * Matches WC()->cart->remove_cart_item()
     *
     * @param string $cart_key
     * @return bool
     */
    public function remove_cart_item($cart_key) {
        if (!isset($this->items[$cart_key])) {
            return false;
        }

        $removed_item = $this->items[$cart_key];
        unset($this->items[$cart_key]);

        $this->dirty = true;
        $this->calculate_totals();

        do_action('ptp_cart_item_removed', $cart_key, $removed_item);

        return true;
    }

    /**
     * Set item quantity
     * Matches WC()->cart->set_quantity()
     *
     * @param string $cart_key
     * @param int $quantity
     * @return bool
     */
    public function set_quantity($cart_key, $quantity) {
        if (!isset($this->items[$cart_key])) {
            return false;
        }

        if ($quantity <= 0) {
            return $this->remove_cart_item($cart_key);
        }

        $this->items[$cart_key]['quantity'] = (int) $quantity;
        $this->items[$cart_key]['line_total'] = $this->items[$cart_key]['price'] * $quantity;

        $this->dirty = true;
        $this->calculate_totals();

        return true;
    }

    /**
     * Get all cart items
     * Matches WC()->cart->get_cart()
     *
     * @return array
     */
    public function get_cart() {
        if (!$this->loaded) {
            $this->load_cart();
        }
        return $this->items;
    }

    /**
     * Get cart from session (alias for get_cart)
     * Matches WC()->cart->get_cart_from_session()
     */
    public function get_cart_from_session() {
        return $this->get_cart();
    }

    /**
     * Check if cart is empty
     * Matches WC()->cart->is_empty()
     *
     * @return bool
     */
    public function is_empty() {
        if (!$this->loaded) {
            $this->load_cart();
        }
        return empty($this->items);
    }

    /**
     * Get cart contents count
     * Matches WC()->cart->get_cart_contents_count()
     *
     * @return int
     */
    public function get_cart_contents_count() {
        if (!$this->loaded) {
            $this->load_cart();
        }
        $count = 0;
        foreach ($this->items as $item) {
            $count += $item['quantity'];
        }
        return $count;
    }

    /**
     * Empty the cart
     * Matches WC()->cart->empty_cart()
     */
    public function empty_cart($clear_persistent_cart = true) {
        $this->items = array();
        $this->fees = array();
        $this->discounts = array();
        $this->dirty = true;

        // Clear from database immediately
        if ($clear_persistent_cart) {
            global $wpdb;

            if (function_exists('ptp_session')) {
                $session_key = ptp_session()->get_session_key();
                if ($session_key) {
                    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
                    if ($table_exists) {
                        $wpdb->delete($this->table_name, array('session_key' => $session_key), array('%s'));
                    }
                }
            }
        }

        $this->calculate_totals();

        do_action('ptp_cart_emptied');
    }

    /**
     * Calculate cart totals
     * Matches WC()->cart->calculate_totals()
     */
    public function calculate_totals() {
        $this->subtotal = 0;

        foreach ($this->items as $key => $item) {
            $this->items[$key]['line_total'] = $item['price'] * $item['quantity'];
            $this->subtotal += $this->items[$key]['line_total'];
        }

        // Calculate discounts
        $this->discount_total = 0;
        foreach ($this->discounts as $discount) {
            if (isset($discount['type']) && $discount['type'] === 'percent') {
                $this->discount_total += $this->subtotal * ($discount['amount'] / 100);
            } else {
                $this->discount_total += $discount['amount'] ?? 0;
            }
        }

        // Calculate fees
        $this->fee_total = 0;
        foreach ($this->fees as $fee) {
            if (!empty($fee['tax_class'])) {
                // Percentage fee
                $this->fee_total += ($this->subtotal - $this->discount_total) * ($fee['amount'] / 100);
            } else {
                $this->fee_total += $fee['amount'];
            }
        }

        $this->total = $this->subtotal - $this->discount_total + $this->fee_total;

        // Ensure non-negative
        $this->total = max(0, $this->total);

        do_action('ptp_cart_totals_calculated', $this);
    }

    /**
     * Get cart subtotal
     * Matches WC()->cart->get_subtotal()
     *
     * @return float
     */
    public function get_subtotal() {
        if (!$this->loaded) {
            $this->load_cart();
        }
        return $this->subtotal;
    }

    /**
     * Get cart total
     * Matches WC()->cart->get_total()
     *
     * @param string $context 'view' or 'edit'
     * @return float|string
     */
    public function get_total($context = 'view') {
        if (!$this->loaded) {
            $this->load_cart();
        }
        if ($context === 'edit') {
            return $this->total;
        }
        return ptp_format_price($this->total);
    }

    /**
     * Get cart total (formatted)
     * Matches WC()->cart->get_cart_total()
     */
    public function get_cart_total() {
        return ptp_format_price($this->total);
    }

    /**
     * Get discount total
     */
    public function get_discount_total() {
        return $this->discount_total;
    }

    /**
     * Add fee to cart
     */
    public function add_fee($name, $amount, $taxable = false, $tax_class = '') {
        $this->fees[$name] = array(
            'name' => $name,
            'amount' => $amount,
            'taxable' => $taxable,
            'tax_class' => $tax_class,
        );
        $this->dirty = true;
        $this->calculate_totals();
    }

    /**
     * Get cart fees
     * Matches WC()->cart->get_fees()
     */
    public function get_fees() {
        return $this->fees;
    }

    /**
     * Check if cart has discount
     */
    public function has_discount($code = '') {
        if (empty($code)) {
            return !empty($this->discounts);
        }
        return isset($this->discounts[$code]);
    }

    /**
     * Get applied coupons
     */
    public function get_applied_coupons() {
        return array_keys($this->discounts);
    }

    /**
     * Apply coupon/discount
     * Matches WC()->cart->apply_coupon()
     */
    public function apply_coupon($code) {
        // Check if already applied
        if (isset($this->discounts[$code])) {
            return new WP_Error('coupon_already_applied', 'This coupon has already been applied.');
        }

        // Validate coupon code
        $coupon = $this->validate_coupon($code);

        if (is_wp_error($coupon)) {
            return $coupon;
        }

        $this->discounts[$code] = $coupon;
        $this->dirty = true;
        $this->calculate_totals();

        do_action('ptp_coupon_applied', $code, $coupon);

        return true;
    }

    /**
     * Validate coupon code
     */
    private function validate_coupon($code) {
        // Check referral codes
        if (class_exists('PTP_Referral_System') && method_exists('PTP_Referral_System', 'validate_code')) {
            $referral = PTP_Referral_System::validate_code($code);
            if ($referral) {
                return array(
                    'code' => $code,
                    'type' => 'fixed',
                    'amount' => $referral['discount_amount'] ?? 25,
                    'source' => 'referral',
                );
            }
        }

        // Check promo codes in database
        global $wpdb;

        // Check referral_codes table
        $promo = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_referral_codes
             WHERE code = %s AND uses_remaining > 0
             AND (expires_at IS NULL OR expires_at > NOW())",
            strtoupper($code)
        ));

        if ($promo) {
            return array(
                'code' => $code,
                'type' => $promo->discount_type ?? 'fixed',
                'amount' => (float) ($promo->discount_amount ?? 25),
                'source' => 'promo',
            );
        }

        return new WP_Error('invalid_coupon', 'This coupon code is not valid.');
    }

    /**
     * Remove coupon
     */
    public function remove_coupon($code) {
        if (isset($this->discounts[$code])) {
            unset($this->discounts[$code]);
            $this->dirty = true;
            $this->calculate_totals();

            do_action('ptp_coupon_removed', $code);

            return true;
        }
        return false;
    }

    /**
     * Get items by type
     */
    public function get_items_by_type($type) {
        return array_filter($this->items, function($item) use ($type) {
            return $item['item_type'] === $type;
        });
    }

    /**
     * Check if cart has training items
     */
    public function has_training() {
        return !empty($this->get_items_by_type('training'));
    }

    /**
     * Check if cart has camp items
     */
    public function has_camps() {
        return !empty($this->get_items_by_type('camp'));
    }

    /**
     * Check if cart needs payment
     */
    public function needs_payment() {
        return $this->total > 0;
    }

    /**
     * Get cart item by key
     */
    public function get_cart_item($cart_key) {
        return $this->items[$cart_key] ?? null;
    }

    /**
     * Create database table
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'ptp_native_cart_items';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            cart_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            session_key VARCHAR(64) NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            item_id BIGINT UNSIGNED NOT NULL,
            quantity INT UNSIGNED DEFAULT 1,
            price DECIMAL(10,2) NOT NULL,
            metadata JSON,
            added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY session_key (session_key),
            KEY item_type (item_type)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

/**
 * Global helper function
 */
function ptp_cart() {
    return PTP_Native_Cart::instance();
}

/**
 * Format price for display
 */
if (!function_exists('ptp_format_price')) {
    function ptp_format_price($amount, $args = array()) {
        $defaults = array(
            'currency' => 'USD',
            'decimal_separator' => '.',
            'thousand_separator' => ',',
            'decimals' => 2,
        );
        $args = wp_parse_args($args, $defaults);

        $formatted = number_format(
            (float) $amount,
            $args['decimals'],
            $args['decimal_separator'],
            $args['thousand_separator']
        );

        return '$' . $formatted;
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', function() {
    PTP_Native_Cart::instance();
}, 6); // After session (5)
