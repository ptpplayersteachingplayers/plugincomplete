<?php
/**
 * PTP Training WooCommerce Integration v116
 * 
 * Enables training sessions to be added to WooCommerce cart alongside camps,
 * processed together in a single checkout, and sends one combined confirmation email.
 * 
 * ARCHITECTURE:
 * - Training sessions are added as virtual WooCommerce products with custom meta
 * - On order completion, booking records are created in ptp_bookings table
 * - Unified email template handles both camps and training sessions
 * - Preserves backward compatibility with standalone training checkout
 * 
 * @since 116.0.0
 */

defined('ABSPATH') || exit;

class PTP_Training_Woo_Integration {
    
    private static $instance = null;
    
    // Virtual product ID for training sessions
    private static $training_product_id = null;
    
    const TRAINING_PRODUCT_SKU = 'ptp-training-session';
    const TRAINING_PRODUCT_NAME = 'Private Training Session';
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Ensure virtual product exists
        add_action('init', array($this, 'ensure_training_product_exists'), 20);
        
        // AJAX: Add training to WooCommerce cart
        add_action('wp_ajax_ptp_add_training_to_woo_cart', array($this, 'ajax_add_training_to_cart'));
        add_action('wp_ajax_nopriv_ptp_add_training_to_woo_cart', array($this, 'ajax_add_training_to_cart'));
        
        // Cart item customization
        add_filter('woocommerce_get_item_data', array($this, 'display_training_cart_data'), 20, 2);
        add_filter('woocommerce_cart_item_name', array($this, 'customize_training_item_name'), 10, 3);
        add_filter('woocommerce_cart_item_thumbnail', array($this, 'customize_training_item_thumbnail'), 10, 3);
        add_action('woocommerce_before_calculate_totals', array($this, 'set_training_item_prices'), 20);
        
        // Ensure unique cart item key for training sessions
        add_filter('woocommerce_add_cart_item_data', array($this, 'add_unique_cart_key'), 10, 3);
        
        // Save training meta to order
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'save_training_order_meta'), 20, 4);
        
        // Process training items on order completion - use multiple hooks for reliability
        add_action('woocommerce_order_status_processing', array($this, 'process_training_bookings'), 5);
        add_action('woocommerce_order_status_completed', array($this, 'process_training_bookings'), 5);
        add_action('woocommerce_payment_complete', array($this, 'process_training_bookings'), 5);
        
        // Prevent duplicate product purchases
        add_filter('woocommerce_add_to_cart_validation', array($this, 'validate_training_cart_item'), 10, 5);
        
        // REST API for mobile app
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Ensure virtual training product exists in WooCommerce
     */
    public function ensure_training_product_exists() {
        if (!function_exists('wc_get_product_id_by_sku')) {
            return;
        }
        
        $product_id = wc_get_product_id_by_sku(self::TRAINING_PRODUCT_SKU);
        
        if (!$product_id) {
            $product = new WC_Product_Simple();
            $product->set_name(self::TRAINING_PRODUCT_NAME);
            $product->set_sku(self::TRAINING_PRODUCT_SKU);
            $product->set_regular_price(0); // Price set dynamically
            $product->set_virtual(true);
            $product->set_sold_individually(false);
            $product->set_catalog_visibility('hidden');
            $product->set_status('publish');
            $product->set_manage_stock(false);
            $product->set_tax_status('none');
            $product->set_short_description('Private training session with a PTP trainer');
            
            // Add custom meta
            $product->update_meta_data('_ptp_product_type', 'training');
            $product->update_meta_data('_ptp_is_training', 'yes');
            
            $product_id = $product->save();
            
            if ($product_id) {
                update_option('ptp_training_product_id', $product_id);
                error_log("PTP Training Woo Integration: Created training product ID $product_id");
            }
        }
        
        self::$training_product_id = $product_id ?: get_option('ptp_training_product_id');
    }
    
    /**
     * Get training product ID
     */
    public static function get_training_product_id() {
        if (self::$training_product_id) {
            return self::$training_product_id;
        }
        
        self::$training_product_id = get_option('ptp_training_product_id');
        
        if (!self::$training_product_id && function_exists('wc_get_product_id_by_sku')) {
            $product_id = wc_get_product_id_by_sku(self::TRAINING_PRODUCT_SKU);
            if ($product_id) {
                update_option('ptp_training_product_id', $product_id);
                self::$training_product_id = $product_id;
            }
        }
        
        return self::$training_product_id;
    }
    
    /**
     * Add unique key to cart item data for training sessions
     */
    public function add_unique_cart_key($cart_item_data, $product_id, $variation_id) {
        if (!empty($cart_item_data['ptp_training'])) {
            $cart_item_data['unique_key'] = md5(
                $cart_item_data['trainer_id'] . 
                $cart_item_data['session_date'] . 
                $cart_item_data['start_time'] . 
                microtime()
            );
        }
        return $cart_item_data;
    }
    
    /**
     * AJAX: Add training session to WooCommerce cart
     */
    public function ajax_add_training_to_cart() {
        // Verify nonce - accept multiple nonce types for flexibility
        $nonce_valid = wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_nonce') || 
                       wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart_action') ||
                       wp_verify_nonce($_POST['security'] ?? '', 'ptp_nonce');
        
        if (!$nonce_valid) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page.'));
        }
        
        // Required data
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $session_date = sanitize_text_field($_POST['session_date'] ?? '');
        $start_time = sanitize_text_field($_POST['start_time'] ?? '');
        $end_time = sanitize_text_field($_POST['end_time'] ?? '');
        $package_type = sanitize_text_field($_POST['package_type'] ?? 'single');
        $sessions = intval($_POST['sessions'] ?? 1);
        $price = floatval($_POST['price'] ?? 0);
        
        // Player info
        $player_name = sanitize_text_field($_POST['player_name'] ?? '');
        $player_age = intval($_POST['player_age'] ?? 0);
        $player_id = intval($_POST['player_id'] ?? 0);
        
        // Location
        $location = sanitize_text_field($_POST['location'] ?? '');
        $location_address = sanitize_text_field($_POST['location_address'] ?? '');
        
        // Notes
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        // Validation
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Please select a trainer'));
        }
        
        if ($price <= 0) {
            wp_send_json_error(array('message' => 'Invalid price'));
        }
        
        // Get trainer data
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found or not active'));
        }
        
        // Get product ID
        $product_id = self::get_training_product_id();
        if (!$product_id) {
            // Try to create it
            $this->ensure_training_product_exists();
            $product_id = self::get_training_product_id();
            
            if (!$product_id) {
                wp_send_json_error(array('message' => 'Training product not configured. Please contact support.'));
            }
        }
        
        // Ensure WooCommerce cart is initialized
        if (!function_exists('WC') || !WC()->cart) {
            wc_load_cart();
        }
        
        // Cart item data with all training details
        $cart_item_data = array(
            'ptp_training' => true,
            'ptp_item_type' => 'training',
            'trainer_id' => $trainer_id,
            'trainer_name' => $trainer->display_name,
            'trainer_photo' => $trainer->photo_url,
            'trainer_slug' => $trainer->slug,
            'session_date' => $session_date,
            'start_time' => $start_time,
            'end_time' => $end_time,
            'package_type' => $package_type,
            'sessions' => $sessions,
            'price' => $price,
            'player_name' => $player_name,
            'player_age' => $player_age,
            'player_id' => $player_id,
            'location' => $location,
            'location_address' => $location_address,
            'notes' => $notes,
        );
        
        // Add to WooCommerce cart
        try {
            $cart_item_key = WC()->cart->add_to_cart($product_id, 1, 0, array(), $cart_item_data);
            
            if (!$cart_item_key) {
                wp_send_json_error(array('message' => 'Failed to add to cart. Please try again.'));
            }
        } catch (Exception $e) {
            error_log('PTP Training Woo Integration: Cart add error - ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error adding to cart: ' . $e->getMessage()));
        }
        
        // Clear any legacy bundle cart data to avoid conflicts
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::invalidate_cart_cache();
        }
        
        // Fire action for integrations
        do_action('ptp_training_added_to_woo_cart', $cart_item_key, $cart_item_data, $trainer);
        
        wp_send_json_success(array(
            'message' => 'Training session added to cart!',
            'cart_item_key' => $cart_item_key,
            'cart_url' => wc_get_cart_url(),
            'checkout_url' => wc_get_checkout_url(),
            'cart_count' => WC()->cart->get_cart_contents_count(),
            'trainer_name' => $trainer->display_name,
        ));
    }
    
    /**
     * Validate training cart item (prevent duplicates)
     */
    public function validate_training_cart_item($passed, $product_id, $quantity, $variation_id = 0, $cart_item_data = array()) {
        if (empty($cart_item_data['ptp_training'])) {
            return $passed;
        }
        
        // Check for duplicate trainer + date + time combination
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['ptp_training']) && 
                $cart_item['trainer_id'] == $cart_item_data['trainer_id'] &&
                $cart_item['session_date'] == $cart_item_data['session_date'] &&
                $cart_item['start_time'] == $cart_item_data['start_time']) {
                
                wc_add_notice(__('This training session is already in your cart.', 'ptp'), 'error');
                return false;
            }
        }
        
        return $passed;
    }
    
    /**
     * Display training cart item data
     */
    public function display_training_cart_data($item_data, $cart_item) {
        if (empty($cart_item['ptp_training'])) {
            return $item_data;
        }
        
        // Trainer
        if (!empty($cart_item['trainer_name'])) {
            $item_data[] = array(
                'key' => __('Trainer', 'ptp'),
                'value' => esc_html($cart_item['trainer_name']),
            );
        }
        
        // Player
        if (!empty($cart_item['player_name'])) {
            $player = $cart_item['player_name'];
            if (!empty($cart_item['player_age'])) {
                $player .= ' (Age ' . $cart_item['player_age'] . ')';
            }
            $item_data[] = array(
                'key' => __('Player', 'ptp'),
                'value' => esc_html($player),
            );
        }
        
        // Date & Time
        if (!empty($cart_item['session_date'])) {
            $date_display = date('l, F j, Y', strtotime($cart_item['session_date']));
            if (!empty($cart_item['start_time'])) {
                $date_display .= ' at ' . date('g:i A', strtotime($cart_item['start_time']));
            }
            $item_data[] = array(
                'key' => __('Session', 'ptp'),
                'value' => esc_html($date_display),
            );
        } elseif (!empty($cart_item['package_type']) && $cart_item['package_type'] !== 'single') {
            // Package without specific date
            $item_data[] = array(
                'key' => __('Schedule', 'ptp'),
                'value' => __('To be scheduled with trainer', 'ptp'),
            );
        }
        
        // Package type
        if (!empty($cart_item['package_type']) && $cart_item['package_type'] !== 'single') {
            $package_labels = array(
                '4pack' => '4-Session Package',
                '8pack' => '8-Session Package',
                'pack3' => '3-Session Package',
                'pack5' => '5-Session Package',
                'monthly' => 'Monthly Package',
            );
            $label = $package_labels[$cart_item['package_type']] ?? ucfirst($cart_item['package_type']);
            $item_data[] = array(
                'key' => __('Package', 'ptp'),
                'value' => esc_html($label),
            );
        }
        
        // Location
        if (!empty($cart_item['location'])) {
            $item_data[] = array(
                'key' => __('Location', 'ptp'),
                'value' => esc_html($cart_item['location']),
            );
        }
        
        return $item_data;
    }
    
    /**
     * Customize training item name in cart
     */
    public function customize_training_item_name($name, $cart_item, $cart_item_key) {
        if (empty($cart_item['ptp_training'])) {
            return $name;
        }
        
        $trainer_name = $cart_item['trainer_name'] ?? 'Trainer';
        $package_type = $cart_item['package_type'] ?? 'single';
        
        if ($package_type === 'single') {
            return sprintf('Training Session with %s', esc_html($trainer_name));
        } else {
            $sessions = $cart_item['sessions'] ?? 1;
            return sprintf('%d-Session Package with %s', $sessions, esc_html($trainer_name));
        }
    }
    
    /**
     * Customize training item thumbnail
     */
    public function customize_training_item_thumbnail($thumbnail, $cart_item, $cart_item_key) {
        if (empty($cart_item['ptp_training'])) {
            return $thumbnail;
        }
        
        if (!empty($cart_item['trainer_photo'])) {
            return '<img src="' . esc_url($cart_item['trainer_photo']) . '" alt="' . esc_attr($cart_item['trainer_name'] ?? '') . '" class="ptp-cart-trainer-photo" style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #FCB900;">';
        }
        
        // Fallback avatar
        $trainer_name = $cart_item['trainer_name'] ?? 'T';
        $avatar_url = 'https://ui-avatars.com/api/?name=' . urlencode($trainer_name) . '&size=64&background=FCB900&color=0A0A0A&bold=true';
        return '<img src="' . esc_url($avatar_url) . '" alt="" class="ptp-cart-trainer-photo" style="width:64px;height:64px;border-radius:50%;">';
    }
    
    /**
     * Set dynamic price for training items
     */
    public function set_training_item_prices($cart) {
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }
        
        if (did_action('woocommerce_before_calculate_totals') >= 2) {
            return;
        }
        
        foreach ($cart->get_cart() as $cart_item) {
            if (!empty($cart_item['ptp_training']) && !empty($cart_item['price'])) {
                $cart_item['data']->set_price(floatval($cart_item['price']));
            }
        }
    }
    
    /**
     * Save training meta to order line item
     */
    public function save_training_order_meta($item, $cart_item_key, $values, $order) {
        if (empty($values['ptp_training'])) {
            return;
        }
        
        // Core training identification
        $item->add_meta_data('_ptp_training', 'yes', true);
        $item->add_meta_data('_ptp_item_type', 'training', true);
        
        // Trainer details
        if (!empty($values['trainer_id'])) {
            $item->add_meta_data('_trainer_id', intval($values['trainer_id']), true);
        }
        if (!empty($values['trainer_name'])) {
            $item->add_meta_data('Trainer', sanitize_text_field($values['trainer_name']), true);
        }
        if (!empty($values['trainer_photo'])) {
            $item->add_meta_data('_trainer_photo', esc_url_raw($values['trainer_photo']), true);
        }
        
        // Session details
        if (!empty($values['session_date'])) {
            $item->add_meta_data('_session_date', sanitize_text_field($values['session_date']), true);
            $item->add_meta_data('Session Date', date('l, F j, Y', strtotime($values['session_date'])), true);
        }
        if (!empty($values['start_time'])) {
            $item->add_meta_data('_start_time', sanitize_text_field($values['start_time']), true);
            $item->add_meta_data('Session Time', date('g:i A', strtotime($values['start_time'])), true);
        }
        if (!empty($values['end_time'])) {
            $item->add_meta_data('_end_time', sanitize_text_field($values['end_time']), true);
        }
        
        // Package info
        if (!empty($values['package_type'])) {
            $item->add_meta_data('_package_type', sanitize_text_field($values['package_type']), true);
        }
        if (!empty($values['sessions'])) {
            $item->add_meta_data('_sessions', intval($values['sessions']), true);
        }
        
        // Player info
        if (!empty($values['player_name'])) {
            $item->add_meta_data('Player Name', sanitize_text_field($values['player_name']), true);
        }
        if (!empty($values['player_age'])) {
            $item->add_meta_data('Player Age', intval($values['player_age']), true);
        }
        if (!empty($values['player_id'])) {
            $item->add_meta_data('_player_id', intval($values['player_id']), true);
        }
        
        // Location
        if (!empty($values['location'])) {
            $item->add_meta_data('Location', sanitize_text_field($values['location']), true);
        }
        if (!empty($values['location_address'])) {
            $item->add_meta_data('_location_address', sanitize_text_field($values['location_address']), true);
        }
        
        // Notes
        if (!empty($values['notes'])) {
            $item->add_meta_data('_notes', sanitize_textarea_field($values['notes']), true);
        }
        
        // Price (for reference)
        if (!empty($values['price'])) {
            $item->add_meta_data('_training_price', floatval($values['price']), true);
        }
    }
    
    /**
     * Process training items and create bookings on order completion
     */
    public function process_training_bookings($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        // Check if already processed
        if ($order->get_meta('_ptp_training_bookings_processed')) {
            return;
        }
        
        global $wpdb;
        $bookings_created = array();
        $has_training_items = false;
        
        foreach ($order->get_items() as $item_id => $item) {
            // Check if this is a training item
            if ($item->get_meta('_ptp_training') !== 'yes') {
                continue;
            }
            
            $has_training_items = true;
            
            // Extract all training data from order item
            $trainer_id = intval($item->get_meta('_trainer_id'));
            $session_date = $item->get_meta('_session_date');
            $start_time = $item->get_meta('_start_time');
            $end_time = $item->get_meta('_end_time');
            $package_type = $item->get_meta('_package_type') ?: 'single';
            $sessions = intval($item->get_meta('_sessions')) ?: 1;
            $player_name = $item->get_meta('Player Name');
            $player_age = $item->get_meta('Player Age');
            $player_id = intval($item->get_meta('_player_id'));
            $location = $item->get_meta('Location');
            $location_address = $item->get_meta('_location_address');
            $notes = $item->get_meta('_notes');
            $price = floatval($item->get_meta('_training_price')) ?: $item->get_total();
            
            if (!$trainer_id) {
                error_log("PTP Training Woo Integration: No trainer_id for order item $item_id in order $order_id");
                continue;
            }
            
            // Get or create parent record
            $parent_id = $this->get_or_create_parent($order);
            
            // Get or create player record
            if (!$player_id && $player_name) {
                $player_id = $this->get_or_create_player($parent_id, $player_name, $player_age);
            }
            
            // Generate booking number
            $booking_number = 'PTP-' . strtoupper(wp_generate_password(8, false, false));
            
            // Calculate trainer earnings
            // Note: Checkout v77+ uses tiered commission (50% first, 75% repeat)
            // This legacy WooCommerce flow uses flat rate from settings
            $platform_fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
            $trainer_amount = round($price * (1 - $platform_fee_pct / 100), 2);
            $platform_fee = $price - $trainer_amount;
            
            // Determine status
            $booking_status = !empty($session_date) ? 'confirmed' : 'pending_schedule';
            
            // Create booking record
            $booking_data = array(
                'booking_number' => $booking_number,
                'parent_id' => $parent_id,
                'player_id' => $player_id ?: null,
                'trainer_id' => $trainer_id,
                'woo_order_id' => $order_id,
                'woo_item_id' => $item_id,
                'session_date' => $session_date ?: null,
                'start_time' => $start_time ?: null,
                'end_time' => $end_time ?: null,
                'location' => $location ?: null,
                'location_address' => $location_address ?: null,
                'total_amount' => $price,
                'trainer_amount' => $trainer_amount,
                'platform_fee' => $platform_fee,
                'payment_status' => 'paid',
                'payment_method' => $order->get_payment_method(),
                'status' => $booking_status,
                'package_type' => $package_type,
                'total_sessions' => $sessions,
                'sessions_remaining' => $sessions,
                'notes' => $notes ?: null,
                'source' => 'woo_unified',
                'created_at' => current_time('mysql'),
            );
            
            // Check which columns exist in the table
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings");
            $insert_data = array();
            $format = array();
            
            foreach ($booking_data as $key => $value) {
                // Map our data keys to actual column names
                $column_map = array(
                    'booking_number' => 'booking_number',
                    'parent_id' => 'parent_id',
                    'player_id' => 'player_id',
                    'trainer_id' => 'trainer_id',
                    'woo_order_id' => 'woo_order_id',
                    'woo_item_id' => 'woo_item_id',
                    'session_date' => 'session_date',
                    'start_time' => 'start_time',
                    'end_time' => 'end_time',
                    'location' => 'location',
                    'total_amount' => 'total_amount',
                    'trainer_amount' => 'trainer_amount',
                    'platform_fee' => 'platform_fee',
                    'payment_status' => 'payment_status',
                    'status' => 'status',
                    'package_type' => 'package_type',
                    'total_sessions' => 'total_sessions',
                    'sessions_remaining' => 'sessions_remaining',
                    'notes' => 'notes',
                    'source' => 'source',
                    'created_at' => 'created_at',
                );
                
                // Also try alternative column names
                $alt_map = array(
                    'total_amount' => 'amount_paid',
                    'trainer_amount' => 'trainer_payout',
                );
                
                $col_name = $column_map[$key] ?? $key;
                
                if (in_array($col_name, $columns)) {
                    $insert_data[$col_name] = $value;
                    $format[] = is_int($value) ? '%d' : (is_float($value) ? '%f' : '%s');
                } elseif (isset($alt_map[$key]) && in_array($alt_map[$key], $columns)) {
                    $insert_data[$alt_map[$key]] = $value;
                    $format[] = is_int($value) ? '%d' : (is_float($value) ? '%f' : '%s');
                }
            }
            
            $inserted = $wpdb->insert(
                $wpdb->prefix . 'ptp_bookings',
                $insert_data,
                $format
            );
            
            if ($inserted) {
                $booking_id = $wpdb->insert_id;
                $bookings_created[] = $booking_id;
                
                // Update order item with booking reference
                wc_update_order_item_meta($item_id, '_ptp_booking_id', $booking_id);
                wc_update_order_item_meta($item_id, '_ptp_booking_number', $booking_number);
                
                // Create escrow record if escrow system is active
                if (class_exists('PTP_Escrow') && method_exists('PTP_Escrow', 'create_escrow')) {
                    PTP_Escrow::create_escrow($booking_id, $parent_id, $trainer_id, $price, $trainer_amount);
                }
                
                // Notify trainer
                $this->notify_trainer_new_booking($booking_id, $trainer_id);
                
                // Fire action for other integrations
                do_action('ptp_training_booking_created_from_woo', $booking_id, $order_id, $item_id);
                
                error_log("PTP Training Woo Integration: Created booking #$booking_id ($booking_number) from order #$order_id item #$item_id");
            } else {
                error_log("PTP Training Woo Integration: Failed to create booking for order #$order_id item #$item_id - " . $wpdb->last_error);
            }
        }
        
        if ($has_training_items) {
            // Mark as processed to prevent duplicate bookings
            $order->update_meta_data('_ptp_training_bookings_processed', current_time('mysql'));
            $order->update_meta_data('_ptp_booking_ids', $bookings_created);
            $order->save();
            
            // Add order note
            if (!empty($bookings_created)) {
                $count = count($bookings_created);
                $order->add_order_note(sprintf(
                    'PTP: Created %d training booking(s): %s',
                    $count,
                    implode(', ', array_map(function($id) { return "#$id"; }, $bookings_created))
                ));
            }
        }
    }
    
    /**
     * Get or create parent record from order
     */
    private function get_or_create_parent($order) {
        global $wpdb;
        
        $user_id = $order->get_user_id();
        $email = $order->get_billing_email();
        
        // Try to find existing parent by user ID
        if ($user_id) {
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                $user_id
            ));
            
            if ($parent_id) {
                return $parent_id;
            }
        }
        
        // Try to find by email
        if ($email) {
            $parent_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s",
                $email
            ));
            
            if ($parent_id) {
                // Update user_id if we now have one
                if ($user_id) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_parents',
                        array('user_id' => $user_id),
                        array('id' => $parent_id)
                    );
                }
                return $parent_id;
            }
        }
        
        // Create new parent record
        $display_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            array(
                'user_id' => $user_id ?: 0,
                'display_name' => $display_name,
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $email,
                'phone' => $order->get_billing_phone(),
                'created_at' => current_time('mysql'),
            )
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get or create player record
     */
    private function get_or_create_player($parent_id, $player_name, $player_age = null) {
        global $wpdb;
        
        if (!$player_name) {
            return 0;
        }
        
        // Try to find existing player for this parent with same name
        $player_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND name = %s",
            $parent_id, $player_name
        ));
        
        if ($player_id) {
            return $player_id;
        }
        
        // Create new player
        $wpdb->insert(
            $wpdb->prefix . 'ptp_players',
            array(
                'parent_id' => $parent_id,
                'name' => $player_name,
                'age' => $player_age ?: null,
                'created_at' => current_time('mysql'),
            )
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Notify trainer and parent of new booking
     */
    private function notify_trainer_new_booking($booking_id, $trainer_id) {
        // Send trainer notification email
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_trainer_new_booking')) {
            $result = PTP_Email::send_trainer_new_booking($booking_id);
            error_log("[PTP Training Woo] Trainer email for booking #$booking_id: " . ($result ? 'sent' : 'failed'));
        }
        
        // v118.1: Also send parent booking confirmation email
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_booking_confirmation')) {
            $result = PTP_Email::send_booking_confirmation($booking_id);
            error_log("[PTP Training Woo] Parent email for booking #$booking_id: " . ($result ? 'sent' : 'failed'));
        }
        
        // Use existing SMS system if available
        if (class_exists('PTP_SMS') && method_exists('PTP_SMS', 'send_trainer_new_booking')) {
            PTP_SMS::send_trainer_new_booking($booking_id);
        }
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('ptp/v1', '/cart/training', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_add_training_to_cart'),
            'permission_callback' => '__return_true',
        ));
    }
    
    /**
     * REST API: Add training to cart
     */
    public function rest_add_training_to_cart($request) {
        $_POST = $request->get_params();
        $_POST['nonce'] = wp_create_nonce('ptp_nonce');
        
        ob_start();
        $this->ajax_add_training_to_cart();
        $response = ob_get_clean();
        
        return json_decode($response, true);
    }
    
    // =========================================================================
    // STATIC HELPER METHODS
    // =========================================================================
    
    /**
     * Check if order has training items
     */
    public static function order_has_training($order) {
        if (!$order) {
            return false;
        }
        
        foreach ($order->get_items() as $item) {
            if ($item->get_meta('_ptp_training') === 'yes' || 
                $item->get_meta('_ptp_item_type') === 'training') {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if order has camp items
     */
    public static function order_has_camps($order) {
        if (!$order) {
            return false;
        }
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            
            // Skip training items
            if ($item->get_meta('_ptp_training') === 'yes') {
                continue;
            }
            
            if (get_post_meta($product_id, '_ptp_is_camp', true) === 'yes') {
                return true;
            }
            
            if (has_term(array('camps', 'clinics', 'camp', 'summer-camps'), 'product_cat', $product_id)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get training items from order
     */
    public static function get_training_items($order) {
        $training_items = array();
        
        if (!$order) {
            return $training_items;
        }
        
        global $wpdb;
        
        foreach ($order->get_items() as $item_id => $item) {
            if ($item->get_meta('_ptp_training') !== 'yes') {
                continue;
            }
            
            $trainer_id = $item->get_meta('_trainer_id');
            $trainer = null;
            
            if ($trainer_id) {
                $trainer = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                    $trainer_id
                ));
            }
            
            $training_items[] = array(
                'item_id' => $item_id,
                'item' => $item,
                'trainer_id' => $trainer_id,
                'trainer' => $trainer,
                'trainer_name' => $item->get_meta('Trainer'),
                'trainer_photo' => $item->get_meta('_trainer_photo'),
                'player_name' => $item->get_meta('Player Name'),
                'player_age' => $item->get_meta('Player Age'),
                'session_date' => $item->get_meta('_session_date'),
                'start_time' => $item->get_meta('_start_time'),
                'end_time' => $item->get_meta('_end_time'),
                'location' => $item->get_meta('Location'),
                'package_type' => $item->get_meta('_package_type'),
                'sessions' => $item->get_meta('_sessions'),
                'price' => $item->get_total(),
                'booking_id' => $item->get_meta('_ptp_booking_id'),
                'booking_number' => $item->get_meta('_ptp_booking_number'),
            );
        }
        
        return $training_items;
    }
    
    /**
     * Get camp items from order
     */
    public static function get_camp_items($order) {
        $camp_items = array();
        
        if (!$order) {
            return $camp_items;
        }
        
        foreach ($order->get_items() as $item_id => $item) {
            $product_id = $item->get_product_id();
            
            // Skip training items
            if ($item->get_meta('_ptp_training') === 'yes') {
                continue;
            }
            
            $is_camp = get_post_meta($product_id, '_ptp_is_camp', true) === 'yes' ||
                       has_term(array('camps', 'clinics', 'camp', 'summer-camps'), 'product_cat', $product_id);
            
            if (!$is_camp) {
                // Also check product name
                $product = wc_get_product($product_id);
                if ($product) {
                    $name_lower = strtolower($product->get_name());
                    $is_camp = (strpos($name_lower, 'camp') !== false || strpos($name_lower, 'clinic') !== false);
                }
            }
            
            if (!$is_camp) {
                continue;
            }
            
            $product = wc_get_product($product_id);
            
            $camp_items[] = array(
                'item_id' => $item_id,
                'item' => $item,
                'product_id' => $product_id,
                'product' => $product,
                'name' => $item->get_name(),
                'price' => $item->get_total(),
                'camp_type' => get_post_meta($product_id, '_ptp_camp_type', true) ?: 'camp',
                'start_date' => get_post_meta($product_id, '_ptp_start_date', true),
                'end_date' => get_post_meta($product_id, '_ptp_end_date', true),
                'daily_start' => get_post_meta($product_id, '_ptp_daily_start', true),
                'daily_end' => get_post_meta($product_id, '_ptp_daily_end', true),
                'location_name' => get_post_meta($product_id, '_ptp_location_name', true),
                'address' => get_post_meta($product_id, '_ptp_address', true),
                'city' => get_post_meta($product_id, '_ptp_city', true),
                'state' => get_post_meta($product_id, '_ptp_state', true),
                'player_name' => $item->get_meta('Player Name'),
                'player_age' => $item->get_meta('Player Age'),
            );
        }
        
        return $camp_items;
    }
}

// Initialize
add_action('plugins_loaded', function() {
    if (class_exists('WooCommerce')) {
        PTP_Training_Woo_Integration::instance();
    }
}, 20);

/**
 * Helper function to add training to WooCommerce cart
 */
function ptp_add_training_to_woo_cart($data) {
    if (!class_exists('PTP_Training_Woo_Integration')) {
        return array('success' => false, 'message' => 'Integration not available');
    }
    
    $_POST = $data;
    $_POST['nonce'] = wp_create_nonce('ptp_nonce');
    
    ob_start();
    PTP_Training_Woo_Integration::instance()->ajax_add_training_to_cart();
    $response = ob_get_clean();
    
    return json_decode($response, true);
}
