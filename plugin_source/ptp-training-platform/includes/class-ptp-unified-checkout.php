<?php
/**
 * PTP Unified Checkout Handler v85.6
 * 
 * Handles:
 * - Camper info (DOB, shirt size, team, skill level)
 * - Parent/Guardian info
 * - Emergency contact & medical info
 * - Insurance information
 * - Waiver acceptance
 * - WooCommerce orders (camps/clinics)
 * - Training bookings
 * - Bundle discounts
 * - Stripe Payment Intents
 */

defined('ABSPATH') || exit;

class PTP_Unified_Checkout {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        add_action('wp_ajax_ptp_unified_checkout', array($this, 'process_checkout'));
        add_action('wp_ajax_nopriv_ptp_unified_checkout', array($this, 'process_checkout'));
        
        // AJAX handler for saving checkout data (new flow)
        add_action('wp_ajax_ptp_save_checkout', array($this, 'save_checkout_data'));
        add_action('wp_ajax_nopriv_ptp_save_checkout', array($this, 'save_checkout_data'));
        
        // v115.5.1: AJAX handler for creating order after successful payment
        add_action('wp_ajax_ptp_create_order_after_payment', array($this, 'ajax_create_order_after_payment'));
        add_action('wp_ajax_nopriv_ptp_create_order_after_payment', array($this, 'ajax_create_order_after_payment'));
        
        // Handle payment return URL (for Affirm and other redirect methods)
        add_action('template_redirect', array($this, 'handle_payment_return'));
        
        // Auto-create thank-you page if missing
        add_action('init', array($this, 'ensure_thank_you_page'), 20);
        
        // Ensure tables exist
        add_action('init', array($this, 'maybe_create_tables'));
        
        // v117.2.18: Fallback email trigger for training bookings
        add_action('ptp_training_booked_fallback', array($this, 'handle_training_email_fallback'), 10, 3);
    }
    
    /**
     * Handle fallback email sending for training bookings
     * Called from thank-you page if email wasn't sent initially
     */
    public function handle_training_email_fallback($booking_id, $trainer_id, $booking) {
        global $wpdb;
        
        // Check if already processed
        $already_sent = get_transient('ptp_training_email_' . $booking_id);
        if ($already_sent) {
            error_log('[PTP Email Fallback v117.2.18] Already processed booking #' . $booking_id);
            return;
        }
        
        // Get player name
        $player = null;
        if (!empty($booking->player_id)) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_players WHERE id = %d",
                $booking->player_id
            ));
        }
        $camper_name = $player ? trim($player->first_name . ' ' . $player->last_name) : 'Player';
        
        // Call notify_trainer
        $this->notify_trainer($trainer_id, $booking_id, $camper_name);
        
        // Mark as processed
        set_transient('ptp_training_email_' . $booking_id, 1, 24 * HOUR_IN_SECONDS);
        
        error_log('[PTP Email Fallback v117.2.18] Processed fallback email for booking #' . $booking_id);
    }
    
    /**
     * Save checkout data for later order creation (called before payment confirmation)
     * v114: Now stores cart items for reliable order creation after redirect
     */
    public function save_checkout_data() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['ptp_checkout_nonce'] ?? '', 'ptp_checkout')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }
        
        $checkout_session = sanitize_text_field($_POST['checkout_session'] ?? '');
        if (empty($checkout_session)) {
            wp_send_json_error(array('message' => 'Invalid checkout session.'));
            return;
        }
        
        error_log('[PTP Checkout v114] save_checkout_data starting for session: ' . $checkout_session);
        
        // Collect form data
        $parent_data = array(
            'first_name' => sanitize_text_field($_POST['parent_first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['parent_last_name'] ?? ''),
            'email' => sanitize_email($_POST['parent_email'] ?? ''),
            'phone' => sanitize_text_field($_POST['parent_phone'] ?? ''),
        );
        
        $player_id = intval($_POST['player_id'] ?? 0);
        
        // v131: Handle multi-player checkout for group sessions
        $group_player_count = intval($_POST['group_player_count'] ?? 0);
        $group_session_id = intval($_POST['group_session_id'] ?? 0);
        $group_size = intval($_POST['group_size'] ?? 1);
        $players_data = array();
        
        if ($group_player_count > 0 && !empty($_POST['players'])) {
            // Multi-player checkout
            foreach ($_POST['players'] as $idx => $player_input) {
                $players_data[] = array(
                    'player_id' => intval($player_input['player_id'] ?? 0),
                    'first_name' => sanitize_text_field($player_input['first_name'] ?? ''),
                    'last_name' => sanitize_text_field($player_input['last_name'] ?? ''),
                    'dob' => sanitize_text_field($player_input['dob'] ?? ''),
                    'shirt_size' => sanitize_text_field($player_input['shirt_size'] ?? ''),
                    'team' => sanitize_text_field($player_input['team'] ?? ''),
                    'skill_level' => sanitize_text_field($player_input['skill'] ?? ''),
                );
            }
            
            // Use first player as primary camper_data for compatibility
            if (!empty($players_data)) {
                $camper_data = $players_data[0];
            } else {
                $camper_data = array(
                    'first_name' => '',
                    'last_name' => '',
                    'dob' => '',
                    'shirt_size' => '',
                    'team' => '',
                    'skill_level' => '',
                );
            }
            
            error_log('[PTP Checkout v131] Multi-player checkout with ' . count($players_data) . ' players');
        } else {
            // Single player checkout (original)
            $camper_data = array(
                'first_name' => sanitize_text_field($_POST['camper_first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['camper_last_name'] ?? ''),
                'dob' => sanitize_text_field($_POST['camper_dob'] ?? ''),
                'shirt_size' => sanitize_text_field($_POST['camper_shirt_size'] ?? ''),
                'team' => sanitize_text_field($_POST['camper_team'] ?? ''),
                'skill_level' => sanitize_text_field($_POST['camper_skill'] ?? ''),
            );
        }
        
        $emergency_data = array(
            'name' => sanitize_text_field($_POST['emergency_name'] ?? ''),
            'phone' => sanitize_text_field($_POST['emergency_phone'] ?? ''),
            'relation' => sanitize_text_field($_POST['emergency_relation'] ?? ''),
        );
        
        // Validation - allow existing player selection
        if (empty($parent_data['first_name']) || empty($parent_data['email'])) {
            wp_send_json_error(array('message' => 'Please fill in all required parent/guardian fields.'));
            return;
        }
        
        // If no existing player selected, new camper data required
        if (!$player_id && empty($camper_data['first_name'])) {
            wp_send_json_error(array('message' => 'Please provide camper information.'));
            return;
        }
        
        if (empty($emergency_data['name']) || empty($emergency_data['phone'])) {
            wp_send_json_error(array('message' => 'Emergency contact information is required.'));
            return;
        }
        
        $waiver_accepted = !empty($_POST['waiver']) || !empty($_POST['waiver_accepted']);
        if (!$waiver_accepted) {
            wp_send_json_error(array('message' => 'You must accept the waiver to continue.'));
            return;
        }
        
        // v114: Capture WooCommerce cart items BEFORE the redirect
        $cart_items_data = array();
        $has_woo = false;
        
        if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
            $has_woo = true;
            foreach (WC()->cart->get_cart() as $cart_key => $cart_item) {
                $product = $cart_item['data'];
                $product_id = $cart_item['product_id'];
                $variation_id = $cart_item['variation_id'] ?? 0;
                
                $cart_items_data[] = array(
                    'product_id' => $product_id,
                    'variation_id' => $variation_id,
                    'quantity' => $cart_item['quantity'],
                    'line_subtotal' => $cart_item['line_subtotal'],
                    'line_total' => $cart_item['line_total'],
                    'name' => $product->get_name(),
                    'price' => $product->get_price(),
                );
            }
            error_log('[PTP Checkout v114] Captured ' . count($cart_items_data) . ' cart items');
        }
        
        // Sibling info
        $sibling_data = null;
        if (!empty($_POST['sibling_first_name'])) {
            $sibling_data = array(
                'first_name' => sanitize_text_field($_POST['sibling_first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['sibling_last_name'] ?? ''),
                'dob' => sanitize_text_field($_POST['sibling_dob'] ?? ''),
                'shirt_size' => sanitize_text_field($_POST['sibling_shirt_size'] ?? ''),
            );
        }
        
        // Before/After Care
        $before_after_care = !empty($_POST['before_after_care']);
        $care_amount = floatval($_POST['before_after_care_amount'] ?? 0);
        
        // Upgrade pack
        $upgrade_selected = sanitize_text_field($_POST['upgrade_selected'] ?? '');
        $upgrade_amount = floatval($_POST['upgrade_amount'] ?? 0);
        $upgrade_camps = sanitize_text_field($_POST['upgrade_camps'] ?? '');
        
        // Referral
        $referral_code = sanitize_text_field($_POST['referral_validated'] ?? '');
        $referral_discount = floatval($_POST['referral_discount'] ?? 0);
        
        // Jersey upsell
        $jersey_added = !empty($_POST['jersey_upsell']) || !empty($_POST['jersey_added']);
        $jersey_amount = floatval($_POST['jersey_amount'] ?? ($_POST['jersey_upsell'] ? 50 : 0));
        
        // Final total
        $final_total = floatval($_POST['final_total'] ?? $_POST['cart_total'] ?? 0);
        
        // Store checkout data with cart items
        $checkout_data = array(
            'parent_data' => $parent_data,
            'player_id' => $player_id,
            'camper_data' => $camper_data,
            'sibling_data' => $sibling_data,
            'emergency_data' => $emergency_data,
            'medical_info' => sanitize_textarea_field($_POST['medical_info'] ?? ''),
            'insurance_data' => array(
                'provider' => sanitize_text_field($_POST['insurance_provider'] ?? ''),
                'policy' => sanitize_text_field($_POST['insurance_policy'] ?? ''),
                'group' => sanitize_text_field($_POST['insurance_group'] ?? ''),
            ),
            'waiver_accepted' => true,
            'photo_consent' => !empty($_POST['photo_consent']),
            'trainer_id' => intval($_POST['trainer_id'] ?? 0),
            'training_package' => sanitize_text_field($_POST['training_package'] ?? 'single'),
            'training_total' => floatval($_POST['training_total'] ?? 0),
            'session_date' => sanitize_text_field($_POST['session_date'] ?? ''),
            'session_time' => sanitize_text_field($_POST['session_time'] ?? ''),
            'session_location' => sanitize_text_field($_POST['session_location'] ?? ''),
            'cart_total' => floatval($_POST['cart_total'] ?? 0),
            'final_total' => $final_total,
            'user_id' => get_current_user_id(),
            // v114: Include cart items for order creation
            'has_woo' => $has_woo,
            'cart_items' => $cart_items_data,
            // Add-ons and discounts
            'before_after_care' => $before_after_care,
            'care_amount' => $care_amount,
            'upgrade_selected' => $upgrade_selected,
            'upgrade_amount' => $upgrade_amount,
            'upgrade_camps' => $upgrade_camps,
            'referral_code' => $referral_code,
            'referral_discount' => $referral_discount,
            'jersey_added' => $jersey_added,
            'jersey_amount' => $jersey_amount,
            'created_at' => current_time('mysql'),
            // v131: Multi-player / group session support
            'players_data' => $players_data,
            'group_player_count' => $group_player_count,
            'group_session_id' => $group_session_id,
            'group_size' => $group_size,
        );
        
        $saved = set_transient('ptp_checkout_' . $checkout_session, $checkout_data, 2 * HOUR_IN_SECONDS);
        error_log('[PTP Checkout v114] Transient saved: ' . ($saved ? 'YES' : 'NO') . ' for session ' . $checkout_session);
        error_log('[PTP Checkout v114] Data includes ' . count($cart_items_data) . ' cart items, total: $' . $final_total);
        // v117.2.24: Enhanced training data logging
        error_log('[PTP Checkout v117.2.24] ===== TRAINING DATA CAPTURED =====');
        error_log('[PTP Checkout v117.2.24] trainer_id from POST: ' . ($_POST['trainer_id'] ?? 'NOT IN POST'));
        error_log('[PTP Checkout v117.2.24] training_total from POST: ' . ($_POST['training_total'] ?? 'NOT IN POST'));
        error_log('[PTP Checkout v117.2.24] training_package from POST: ' . ($_POST['training_package'] ?? 'NOT IN POST'));
        error_log('[PTP Checkout v117.2.24] Stored trainer_id: ' . ($checkout_data['trainer_id'] ?? 'NOT SET'));
        error_log('[PTP Checkout v117.2.24] Stored training_total: ' . ($checkout_data['training_total'] ?? 'NOT SET'));
        error_log('[PTP Checkout v117.2.24] Stored training_package: ' . ($checkout_data['training_package'] ?? 'NOT SET'));
        error_log('[PTP Checkout v117.2.24] parent_email: ' . ($checkout_data['parent_data']['email'] ?? 'NOT SET'));
        
        wp_send_json_success(array('message' => 'Checkout data saved', 'session' => $checkout_session));
    }
    
    /**
     * v115.5.1: Create WooCommerce order after successful Stripe payment
     * Called from JS after stripe.confirmPayment() succeeds
     */
    public function ajax_create_order_after_payment() {
        error_log('[PTP Order v115.5.1] ========== CREATING ORDER AFTER PAYMENT ==========');
        
        // Verify nonce
        if (!wp_verify_nonce($_POST['ptp_checkout_nonce'] ?? '', 'ptp_checkout')) {
            error_log('[PTP Order v115.5.1] Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $checkout_session = sanitize_text_field($_POST['checkout_session'] ?? '');
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        error_log('[PTP Order v115.5.1] Session: ' . $checkout_session . ', PI: ' . $payment_intent_id);
        
        if (empty($checkout_session)) {
            wp_send_json_error(array('message' => 'Missing checkout session'));
            return;
        }
        
        // Get checkout data from transient
        $checkout_data = get_transient('ptp_checkout_' . $checkout_session);
        
        if (empty($checkout_data)) {
            error_log('[PTP Order v115.5.1] No checkout data found for session: ' . $checkout_session);
            wp_send_json_error(array('message' => 'Checkout data not found'));
            return;
        }
        
        // Verify payment with Stripe if we have PI ID
        $intent = null;
        if (!empty($payment_intent_id)) {
            $secret_key = get_option('ptp_stripe_test_mode', true) 
                ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
                : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
            
            if (!empty($secret_key)) {
                $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
                    'headers' => array('Authorization' => 'Bearer ' . $secret_key),
                    'timeout' => 30,
                ));
                
                if (!is_wp_error($response)) {
                    $intent = json_decode(wp_remote_retrieve_body($response), true);
                    error_log('[PTP Order v115.5.1] Payment status: ' . ($intent['status'] ?? 'unknown'));
                    
                    if (($intent['status'] ?? '') !== 'succeeded') {
                        wp_send_json_error(array('message' => 'Payment not completed'));
                        return;
                    }
                }
            }
        }
        
        // Create the order
        $result = $this->create_orders_from_session($checkout_data, $payment_intent_id, $intent ?? array());
        
        // v117.2.21: Success if we have EITHER order_id OR booking_id
        if (!empty($result['order_id']) || !empty($result['booking_id'])) {
            // Delete the transient
            delete_transient('ptp_checkout_' . $checkout_session);
            
            // Mark as processed
            set_transient('ptp_processed_' . ($payment_intent_id ?: $checkout_session), true, DAY_IN_SECONDS);
            
            // Set cookie for thank-you page
            if (!headers_sent()) {
                if (!empty($result['order_id'])) {
                    setcookie('ptp_last_order', $result['order_id'], time() + 3600, '/');
                }
                if (!empty($result['booking_id'])) {
                    setcookie('ptp_last_booking', $result['booking_id'], time() + 3600, '/');
                }
            }
            
            error_log('[PTP Order v117.2.21] Success - order_id: ' . ($result['order_id'] ?? 'none') . ', booking_id: ' . ($result['booking_id'] ?? 'none'));
            wp_send_json_success(array(
                'order_id' => $result['order_id'] ?? null,
                'booking_id' => $result['booking_id'] ?? null,
                'message' => 'Order/booking created'
            ));
        } else {
            error_log('[PTP Order v117.2.21] Failed to create order or booking');
            wp_send_json_error(array('message' => 'Failed to create order'));
        }
    }
    
    /**
     * Ensure thank-you page exists
     */
    public function ensure_thank_you_page() {
        $page = get_page_by_path('thank-you');
        
        if (!$page) {
            // Create the page immediately
            $page_id = wp_insert_post(array(
                'post_title' => 'Thank You',
                'post_name' => 'thank-you',
                'post_content' => '[ptp_thank_you]',
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_author' => 1,
            ));
            
            if ($page_id && !is_wp_error($page_id)) {
                error_log('[PTP] Auto-created thank-you page: ' . $page_id);
                // Flush rewrite rules so the page works immediately
                flush_rewrite_rules();
            }
        }
    }
    
    /**
     * Handle return from Stripe redirect payment (Affirm, etc.)
     */
    public function handle_payment_return() {
        // Only handle on thank-you page
        if (strpos($_SERVER['REQUEST_URI'], '/thank-you/') === false && 
            strpos($_SERVER['REQUEST_URI'], '/order-received/') === false) {
            return;
        }
        
        $payment_intent_id = sanitize_text_field($_GET['payment_intent'] ?? '');
        $session_id = sanitize_text_field($_GET['session'] ?? '');
        
        // Need either payment_intent or session
        if (empty($payment_intent_id) && empty($session_id)) {
            return;
        }
        
        // Check if we already processed this
        $process_key = $payment_intent_id ?: $session_id;
        $already_processed = get_transient('ptp_processed_' . $process_key);
        if ($already_processed) {
            return; // Let normal page load happen
        }
        
        error_log('[PTP Return] Processing return - PI: ' . $payment_intent_id . ', Session: ' . $session_id);
        
        // Get Stripe secret key
        $secret_key = get_option('ptp_stripe_test_mode', true) 
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        if (empty($secret_key)) {
            return; // Can't verify, let page load normally
        }
        
        // Get checkout data first to find payment intent if we only have session
        $checkout_data = get_transient('ptp_checkout_' . $session_id);
        
        // If we have session but no payment_intent, we need to find the PI from the session's metadata
        if (empty($payment_intent_id) && !empty($session_id)) {
            // Search for PaymentIntent with this checkout_session in metadata
            $search_response = wp_remote_get('https://api.stripe.com/v1/payment_intents?limit=5', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $secret_key,
                ),
                'timeout' => 30,
            ));
            
            if (!is_wp_error($search_response)) {
                $search_body = json_decode(wp_remote_retrieve_body($search_response), true);
                foreach (($search_body['data'] ?? array()) as $pi) {
                    if (($pi['metadata']['checkout_session'] ?? '') === $session_id) {
                        $payment_intent_id = $pi['id'];
                        break;
                    }
                }
            }
        }
        
        if (empty($payment_intent_id)) {
            error_log('[PTP Return] Could not find PaymentIntent for session: ' . $session_id);
            return; // Can't find PI, let page load normally
        }
        
        // Retrieve PaymentIntent from Stripe
        $response = wp_remote_get('https://api.stripe.com/v1/payment_intents/' . $payment_intent_id, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            error_log('[PTP Return] Stripe API error: ' . $response->get_error_message());
            return;
        }
        
        $intent = json_decode(wp_remote_retrieve_body($response), true);
        
        if (empty($intent) || isset($intent['error'])) {
            error_log('[PTP Return] Invalid PaymentIntent: ' . json_encode($intent));
            return;
        }
        
        $status = $intent['status'] ?? '';
        error_log('[PTP Return] PaymentIntent status: ' . $status);
        
        // Check if payment succeeded
        if ($status !== 'succeeded') {
            // Payment not complete - redirect back to checkout with error
            $error_msg = 'Payment was not completed. Status: ' . $status;
            if ($status === 'requires_payment_method') {
                $error_msg = 'Payment failed. Please try again with a different payment method.';
            }
            
            // v117.2.8: Preserve checkout params for retry
            $redirect_url = home_url('/ptp-checkout/');
            $redirect_params = array('payment_error' => $error_msg);
            
            // Try to get original params from checkout data
            if ($checkout_data) {
                if (!empty($checkout_data['trainer_id'])) {
                    $redirect_params['trainer_id'] = $checkout_data['trainer_id'];
                }
                if (!empty($checkout_data['training_package'])) {
                    $redirect_params['package'] = $checkout_data['training_package'];
                }
                if (!empty($checkout_data['session_date'])) {
                    $redirect_params['date'] = $checkout_data['session_date'];
                }
                if (!empty($checkout_data['session_time'])) {
                    $redirect_params['time'] = $checkout_data['session_time'];
                }
                if (!empty($checkout_data['session_location'])) {
                    $redirect_params['location'] = $checkout_data['session_location'];
                }
            }
            
            wp_redirect(add_query_arg($redirect_params, $redirect_url));
            exit;
        }
        
        // Get checkout data from transient
        $checkout_session = $intent['metadata']['checkout_session'] ?? $session_id;
        if (empty($checkout_data)) {
            $checkout_data = get_transient('ptp_checkout_' . $checkout_session);
        }
        
        if (empty($checkout_data)) {
            error_log('[PTP Return] No checkout data found for session: ' . $checkout_session);
            // Mark as processed anyway to prevent loop
            set_transient('ptp_processed_' . $process_key, true, DAY_IN_SECONDS);
            return;
        }
        
        // Create orders from saved checkout data
        $result = $this->create_orders_from_session($checkout_data, $payment_intent_id, $intent);
        
        // Delete the transient
        delete_transient('ptp_checkout_' . $checkout_session);
        
        // Mark as processed to prevent duplicate processing on refresh
        set_transient('ptp_processed_' . $process_key, true, DAY_IN_SECONDS);
        
        // Redirect to clean thank you page
        $redirect_url = home_url('/thank-you/');
        if (!empty($result['order_id'])) {
            $redirect_url = add_query_arg('order', $result['order_id'], $redirect_url);
        }
        if (!empty($result['booking_id'])) {
            $redirect_url = add_query_arg('booking', $result['booking_id'], $redirect_url);
        }
        
        error_log('[PTP Return] Redirecting to: ' . $redirect_url);
        wp_redirect($redirect_url);
        exit;
    }
    
    /**
     * Create orders from saved checkout session data
     * v114: Uses stored cart items instead of relying on WC()->cart
     */
    private function create_orders_from_session($data, $payment_intent_id, $intent) {
        global $wpdb;
        
        $result = array('order_id' => null, 'booking_id' => null);
        
        $parent_data = $data['parent_data'] ?? array();
        $camper_data = $data['camper_data'] ?? array();
        $player_id = $data['player_id'] ?? 0;
        $trainer_id = $data['trainer_id'] ?? 0;
        $total = $data['final_total'] ?? $data['total'] ?? 0;
        
        // v114: Get cart items from saved data, not WC()->cart
        $has_woo = $data['has_woo'] ?? false;
        $cart_items_data = $data['cart_items'] ?? array();
        
        error_log('[PTP Return v114] Creating orders - Total: $' . $total . ', Trainer: ' . $trainer_id . ', Cart items: ' . count($cart_items_data));
        
        // Get or create user
        $user_id = $data['user_id'] ?? get_current_user_id();
        if (!$user_id && !empty($parent_data['email'])) {
            $user = get_user_by('email', $parent_data['email']);
            if ($user) {
                $user_id = $user->ID;
            } else {
                // Create new user
                $username = $this->generate_unique_username($parent_data['email']);
                $password = wp_generate_password();
                $user_id = wp_create_user($username, $password, $parent_data['email']);
                
                if (!is_wp_error($user_id)) {
                    wp_update_user(array(
                        'ID' => $user_id,
                        'first_name' => $parent_data['first_name'],
                        'last_name' => $parent_data['last_name'],
                        'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                    ));
                    update_user_meta($user_id, 'billing_phone', $parent_data['phone']);
                    update_user_meta($user_id, 'billing_first_name', $parent_data['first_name']);
                    update_user_meta($user_id, 'billing_last_name', $parent_data['last_name']);
                    update_user_meta($user_id, 'billing_email', $parent_data['email']);
                    error_log('[PTP Return v114] Created new user: ' . $user_id);
                }
            }
        }
        
        // Get or create parent record
        $parent_id = null;
        $emergency_data = $data['emergency_data'] ?? array();
        $medical_info = sanitize_textarea_field($data['medical_info'] ?? '');
        
        if (!empty($parent_data['email'])) {
            // First try to find by user_id if logged in
            if ($user_id > 0) {
                $parent_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                    $user_id
                ));
            }
            
            // If not found, try by email
            if (!$parent_id) {
                $parent_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s",
                    $parent_data['email']
                ));
            }
            
            if (!$parent_id) {
                // Create new parent record
                $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
                    'user_id' => $user_id,
                    'first_name' => $parent_data['first_name'],
                    'last_name' => $parent_data['last_name'],
                    'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                    'email' => $parent_data['email'],
                    'phone' => $parent_data['phone'],
                    'emergency_name' => $emergency_data['name'] ?? '',
                    'emergency_phone' => $emergency_data['phone'] ?? '',
                    'emergency_relation' => $emergency_data['relation'] ?? '',
                    'medical_info' => $medical_info,
                    'created_at' => current_time('mysql'),
                ));
                $parent_id = $wpdb->insert_id;
                error_log('[PTP Return v117.2.18] Created parent record: ' . $parent_id . ' for email: ' . $parent_data['email']);
            } else {
                // Update existing parent with latest info (including email if missing)
                $update_data = array(
                    'first_name' => $parent_data['first_name'],
                    'last_name' => $parent_data['last_name'],
                    'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                    'email' => $parent_data['email'],  // Always update email
                    'phone' => $parent_data['phone'],
                );
                
                // Only update emergency contact if provided
                if (!empty($emergency_data['name'])) {
                    $update_data['emergency_name'] = $emergency_data['name'];
                    $update_data['emergency_phone'] = $emergency_data['phone'] ?? '';
                    $update_data['emergency_relation'] = $emergency_data['relation'] ?? '';
                }
                if (!empty($medical_info)) {
                    $update_data['medical_info'] = $medical_info;
                }
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_parents',
                    $update_data,
                    array('id' => $parent_id)
                );
                error_log('[PTP Return v117.2.18] Updated parent record: ' . $parent_id . ' with email: ' . $parent_data['email']);
            }
        }
        
        // Get camper name
        $camper_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
        if (empty($camper_name) && $player_id && $parent_id) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
                $player_id, $parent_id
            ));
            if ($player) {
                $camper_name = trim($player->first_name . ' ' . $player->last_name);
            }
        }
        // v114.1: Fallback to Stripe metadata
        if (empty($camper_name) && !empty($intent['metadata']['camper_name'])) {
            $camper_name = $intent['metadata']['camper_name'];
        }
        if (empty($camper_name)) {
            $camper_name = $parent_data['first_name'] ?? 'Camper';
        }
        
        // Create WooCommerce order from stored cart items
        // v117.2.12: Check if this is a training-only checkout (no WC products needed)
        $is_training_only = ($trainer_id > 0) && (($data['training_total'] ?? 0) > 0) && empty($cart_items_data);
        
        // Get total from multiple sources as fallback
        if ($total <= 0 && !empty($intent['amount'])) {
            $total = floatval($intent['amount']) / 100; // Stripe amount is in cents
            error_log('[PTP Return v114.1] Using Stripe amount as total: $' . $total);
        }
        
        // v117.2.12: Only create WC order for camp/product purchases, NOT for training-only
        if (function_exists('wc_create_order') && $total > 0 && !$is_training_only) {
            error_log('[PTP Return v117.2.12] Creating WooCommerce order - has_woo=' . ($has_woo ? 'true' : 'false') . ', items=' . count($cart_items_data) . ', total=$' . $total);
            
            $order = wc_create_order(array(
                'customer_id' => $user_id,
                'status' => 'pending',
            ));
            
            if (!is_wp_error($order)) {
                // Add items from saved cart data if available
                if (!empty($cart_items_data)) {
                    foreach ($cart_items_data as $item_data) {
                        $product_id = $item_data['product_id'];
                        $product = wc_get_product($product_id);
                        
                        if ($product) {
                            $item_id = $order->add_product($product, $item_data['quantity'], array(
                                'subtotal' => $item_data['line_subtotal'],
                                'total' => $item_data['line_total'],
                            ));
                            
                            if ($item_id) {
                                wc_add_order_item_meta($item_id, 'Player Name', $camper_name);
                                if (!empty($camper_data['dob'])) {
                                    $age = (new DateTime($camper_data['dob']))->diff(new DateTime())->y;
                                    wc_add_order_item_meta($item_id, 'Player Age', $age);
                                }
                                wc_add_order_item_meta($item_id, 'T-Shirt Size', $camper_data['shirt_size'] ?? '');
                                wc_add_order_item_meta($item_id, '_player_id', $player_id);
                            }
                        }
                    }
                } else {
                    // v114.1: No cart items - add a line item for the payment
                    error_log('[PTP Return v114.1] No cart items - creating order with fee line item');
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name('PTP Camp Registration - ' . $camper_name);
                    $fee->set_total($total);
                    $order->add_item($fee);
                }
                
                // Set billing details
                $order->set_billing_first_name($parent_data['first_name'] ?? '');
                $order->set_billing_last_name($parent_data['last_name'] ?? '');
                $order->set_billing_email($parent_data['email'] ?? '');
                $order->set_billing_phone($parent_data['phone'] ?? '');
                
                // Add-ons: Before/After Care
                if (!empty($data['before_after_care']) && ($data['care_amount'] ?? 0) > 0) {
                    $care_fee = new WC_Order_Item_Fee();
                    $care_fee->set_name('Before & After Care');
                    $care_fee->set_total($data['care_amount']);
                    $order->add_item($care_fee);
                    $order->update_meta_data('_before_after_care', 'yes');
                    $order->update_meta_data('_before_after_care_amount', $data['care_amount']);
                }
                
                // Add-ons: Upgrade Pack
                if (!empty($data['upgrade_selected']) && ($data['upgrade_amount'] ?? 0) > 0) {
                    $upgrade_labels = array(
                        '2pack' => '2-Camp Pack (+1 Camp)',
                        '3pack' => '3-Camp Pack (+2 Camps)',
                        'allaccess' => 'All-Access Pass',
                    );
                    $upgrade_label = $upgrade_labels[$data['upgrade_selected']] ?? 'Camp Pack Upgrade';
                    
                    $upgrade_fee = new WC_Order_Item_Fee();
                    $upgrade_fee->set_name($upgrade_label);
                    $upgrade_fee->set_total($data['upgrade_amount']);
                    $order->add_item($upgrade_fee);
                    $order->update_meta_data('_upgrade_pack', $data['upgrade_selected']);
                    $order->update_meta_data('_upgrade_amount', $data['upgrade_amount']);
                    
                    if (!empty($data['upgrade_camps'])) {
                        $order->update_meta_data('_upgrade_camp_ids', $data['upgrade_camps']);
                    }
                }
                
                // Add-ons: Referral discount
                if (!empty($data['referral_code']) && ($data['referral_discount'] ?? 0) > 0) {
                    $referral_fee = new WC_Order_Item_Fee();
                    $referral_fee->set_name('Referral Discount (' . $data['referral_code'] . ')');
                    $referral_fee->set_total(-$data['referral_discount']);
                    $order->add_item($referral_fee);
                    $order->update_meta_data('_referral_code', $data['referral_code']);
                    $order->update_meta_data('_referral_discount', $data['referral_discount']);
                }
                
                // Add-ons: World Cup Jersey (optional)
                if (!empty($data['jersey_added'])) {
                    $jersey_fee = new WC_Order_Item_Fee();
                    $jersey_fee->set_name('World Cup 2026 x PTP Jersey');
                    $jersey_fee->set_total(50);
                    $order->add_item($jersey_fee);
                }
                
                // Set payment info
                $order->set_payment_method('stripe');
                $order->set_payment_method_title('Credit Card');
                $order->set_transaction_id($payment_intent_id);
                
                // Order meta
                $order->update_meta_data('_stripe_payment_intent', $payment_intent_id);
                $order->update_meta_data('_player_id', $player_id);
                $order->update_meta_data('_player_name', $camper_name);
                $order->update_meta_data('_ptp_waiver_accepted', 'yes');
                $order->update_meta_data('_ptp_waiver_date', current_time('mysql'));
                
                if (!empty($data['emergency_data'])) {
                    $order->update_meta_data('_ptp_emergency_name', $data['emergency_data']['name'] ?? '');
                    $order->update_meta_data('_ptp_emergency_phone', $data['emergency_data']['phone'] ?? '');
                    $order->update_meta_data('_ptp_emergency_relationship', $data['emergency_data']['relation'] ?? '');
                }
                
                if (!empty($data['medical_info'])) {
                    $order->update_meta_data('_ptp_medical_info', $data['medical_info']);
                }
                
                // Camper data as array for compatibility
                $campers = array(array(
                    'full_name' => $camper_name,
                    'first_name' => $camper_data['first_name'] ?? '',
                    'last_name' => $camper_data['last_name'] ?? '',
                    'age' => !empty($camper_data['dob']) ? (new DateTime($camper_data['dob']))->diff(new DateTime())->y : '',
                    'shirt_size' => $camper_data['shirt_size'] ?? '',
                    'team' => $camper_data['team'] ?? '',
                    'skill_level' => $camper_data['skill_level'] ?? '',
                ));
                $order->update_meta_data('_ptp_campers', $campers);
                
                $order->calculate_totals();
                $order->payment_complete($payment_intent_id);
                $order->add_order_note('Paid via PTP Checkout (redirect return). PaymentIntent: ' . $payment_intent_id);
                $order->save();
                
                $result['order_id'] = $order->get_id();
                
                // Clear cart if it exists
                if (function_exists('WC') && WC()->cart) {
                    WC()->cart->empty_cart();
                }
                
                error_log('[PTP Return v114.1] WooCommerce order created: ' . $order->get_id());
                
                // v114.1: FORCE send confirmation email directly via wp_mail
                $customer_email = $order->get_billing_email();
                if (!empty($customer_email)) {
                    $site_name = 'PTP Soccer Camps';
                    $order_total = $order->get_total();
                    $order_number = $order->get_order_number();
                    $logo_url = 'https://ptpsummercamps.com/wp-content/uploads/2024/01/ptp-logo.png';
                    
                    // Get order items for email
                    $items_html = '';
                    foreach ($order->get_items() as $item) {
                        $items_html .= '<tr>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; font-family: Inter, Arial, sans-serif;">' . esc_html($item->get_name()) . '</td>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right; font-family: Inter, Arial, sans-serif; font-weight: 600;">$' . number_format($item->get_total(), 2) . '</td>
                        </tr>';
                    }
                    foreach ($order->get_items('fee') as $fee) {
                        $items_html .= '<tr>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; font-family: Inter, Arial, sans-serif;">' . esc_html($fee->get_name()) . '</td>
                            <td style="padding: 12px; border-bottom: 1px solid #eee; text-align: right; font-family: Inter, Arial, sans-serif; font-weight: 600;">$' . number_format($fee->get_total(), 2) . '</td>
                        </tr>';
                    }
                    
                    $headers = array(
                        'Content-Type: text/html; charset=UTF-8',
                        'From: PTP <luke@ptpsummercamps.com>',
                        'Reply-To: luke@ptpsummercamps.com',
                    );
                    
                    $subject = '⚽ You\'re In! Order #' . $order_number . ' Confirmed';
                    $message = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; background: #ffffff; border: 2px solid #0A0A0A;">
                    <!-- Header -->
                    <tr>
                        <td style="background: #0A0A0A; padding: 25px; text-align: center;">
                            <img src="' . $logo_url . '" alt="PTP Soccer Camps" style="max-width: 180px; height: auto;">
                        </td>
                    </tr>
                    
                    <!-- Gold Banner -->
                    <tr>
                        <td style="background: #FCB900; padding: 20px; text-align: center;">
                            <h1 style="margin: 0; font-family: Oswald, Arial, sans-serif; font-size: 28px; font-weight: 700; color: #0A0A0A; text-transform: uppercase; letter-spacing: 1px;">YOU\'RE IN! ⚽</h1>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding: 35px 30px;">
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 16px; color: #333; margin: 0 0 20px;">
                                Hi <strong>' . esc_html($order->get_billing_first_name()) . '</strong>,
                            </p>
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 16px; color: #333; margin: 0 0 25px;">
                                Thanks for signing up! <strong>' . esc_html($camper_name) . '</strong> is registered and ready to train with the pros.
                            </p>
                            
                            <!-- Order Box -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background: #f9f9f9; border: 2px solid #0A0A0A; margin-bottom: 25px;">
                                <tr>
                                    <td style="background: #0A0A0A; padding: 12px 15px;">
                                        <span style="font-family: Oswald, Arial, sans-serif; font-size: 14px; font-weight: 600; color: #FCB900; text-transform: uppercase; letter-spacing: 1px;">ORDER #' . $order_number . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            ' . $items_html . '
                                            <tr style="background: #FCB900;">
                                                <td style="padding: 15px; font-family: Oswald, Arial, sans-serif; font-size: 16px; font-weight: 700; text-transform: uppercase;">TOTAL</td>
                                                <td style="padding: 15px; text-align: right; font-family: Oswald, Arial, sans-serif; font-size: 20px; font-weight: 700;">$' . number_format($order_total, 2) . '</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Player Info -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom: 25px;">
                                <tr>
                                    <td style="font-family: Oswald, Arial, sans-serif; font-size: 12px; font-weight: 600; color: #666; text-transform: uppercase; letter-spacing: 1px; padding-bottom: 8px;">PLAYER</td>
                                </tr>
                                <tr>
                                    <td style="font-family: Inter, Arial, sans-serif; font-size: 18px; font-weight: 600; color: #0A0A0A;">' . esc_html($camper_name) . '</td>
                                </tr>
                            </table>
                            
                            <!-- What\'s Next -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background: #0A0A0A; padding: 20px; margin-bottom: 25px;">
                                <tr>
                                    <td>
                                        <p style="font-family: Oswald, Arial, sans-serif; font-size: 14px; font-weight: 600; color: #FCB900; text-transform: uppercase; letter-spacing: 1px; margin: 0 0 10px;">WHAT\'S NEXT?</p>
                                        <p style="font-family: Inter, Arial, sans-serif; font-size: 14px; color: #ffffff; margin: 0;">
                                            We\'ll send camp details and check-in instructions before your session. Questions? Just reply to this email.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 14px; color: #666; margin: 0;">
                                See you on the field! 🏆
                            </p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background: #0A0A0A; padding: 25px; text-align: center;">
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 12px; color: #888; margin: 0 0 10px;">
                                <a href="https://ptpsummercamps.com" style="color: #FCB900; text-decoration: none;">ptpsummercamps.com</a>
                            </p>
                            <p style="font-family: Inter, Arial, sans-serif; font-size: 11px; color: #666; margin: 0;">
                                Players Teaching Players | ' . date('Y') . '
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
                    
                    $sent = wp_mail($customer_email, $subject, $message, $headers);
                    error_log('[PTP Return v114.1] Direct confirmation email to ' . $customer_email . ': ' . ($sent ? 'SENT' : 'FAILED'));
                    
                    // Also notify admin
                    $admin_email = get_option('admin_email');
                    $admin_subject = '🎯 New Order #' . $order_number . ' - $' . number_format($order_total, 2) . ' - ' . esc_html($camper_name);
                    $admin_message = '
                    <div style="font-family: Inter, Arial, sans-serif; max-width: 500px;">
                        <div style="background: #FCB900; padding: 15px; border: 2px solid #0A0A0A;">
                            <h2 style="margin: 0; font-family: Oswald, Arial, sans-serif; text-transform: uppercase;">New Order #' . $order_number . '</h2>
                        </div>
                        <div style="padding: 20px; background: #f9f9f9; border: 2px solid #0A0A0A; border-top: none;">
                            <p><strong>Customer:</strong> ' . esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()) . '</p>
                            <p><strong>Email:</strong> ' . esc_html($customer_email) . '</p>
                            <p><strong>Phone:</strong> ' . esc_html($order->get_billing_phone()) . '</p>
                            <p><strong>Player:</strong> ' . esc_html($camper_name) . '</p>
                            <p style="font-size: 24px; font-weight: bold; color: #0A0A0A;">Total: $' . number_format($order_total, 2) . '</p>
                            <p><a href="' . admin_url('post.php?post=' . $order->get_id() . '&action=edit') . '" style="display: inline-block; background: #0A0A0A; color: #FCB900; padding: 12px 25px; text-decoration: none; font-weight: bold; text-transform: uppercase;">View Order</a></p>
                        </div>
                    </div>';
                    
                    wp_mail($admin_email, $admin_subject, $admin_message, $headers);
                    error_log('[PTP Return v114.1] Admin notification sent to ' . $admin_email);
                } else {
                    error_log('[PTP Return v114.1] WARNING: No billing email on order - cannot send confirmation');
                }
                
                // Also try WooCommerce native emails as backup
                try {
                    if (class_exists('WC_Emails')) {
                        $wc_emails = WC_Emails::instance();
                        $emails = $wc_emails->get_emails();
                        if (isset($emails['WC_Email_Customer_Processing_Order'])) {
                            $emails['WC_Email_Customer_Processing_Order']->trigger($order->get_id(), $order);
                            error_log('[PTP Return v114.1] WC Processing email triggered');
                        }
                        if (isset($emails['WC_Email_New_Order'])) {
                            $emails['WC_Email_New_Order']->trigger($order->get_id(), $order);
                            error_log('[PTP Return v114.1] WC New Order email triggered');
                        }
                    }
                } catch (Exception $e) {
                    error_log('[PTP Return v114.1] WC Email error: ' . $e->getMessage());
                }
            } else {
                error_log('[PTP Return v114.1] Failed to create WC order: ' . $order->get_error_message());
                
                // v114.1: Fallback email when WC order creation fails
                if (!empty($parent_data['email'])) {
                    // Send custom confirmation email
                    $admin_email = get_option('admin_email');
                    $headers = array('Content-Type: text/html; charset=UTF-8');
                    
                    // Customer email
                    $customer_subject = 'Your PTP Soccer Camp Order Confirmation';
                    $customer_message = sprintf(
                        "<p>Hi %s,</p>
                        <p>Thank you for your order! Your camp reservation for %s has been confirmed.</p>
                        <p><strong>Order Total: $%.2f</strong></p>
                        <p>You will receive further instructions soon.</p>
                        <p>- PTP Soccer Camps Team</p>",
                        esc_html($parent_data['first_name']),
                        esc_html($camper_name),
                        floatval($total)
                    );
                    
                    wp_mail($parent_data['email'], $customer_subject, $customer_message, $headers);
                    error_log('[PTP Return v114.1] Fallback confirmation email sent to: ' . $parent_data['email']);
                    
                    // Admin notification
                    $admin_subject = '[PTP] Order Failed - Manual Review Needed (' . $camper_name . ')';
                    $admin_message = sprintf(
                        "<p>An order failed to create in WooCommerce but was logged in PTP system.</p>
                        <p><strong>Customer:</strong> %s (%s)</p>
                        <p><strong>Camper:</strong> %s</p>
                        <p><strong>Amount:</strong> $%.2f</p>
                        <p>Please manually create the order in WooCommerce.</p>",
                        esc_html($parent_data['first_name'] . ' ' . $parent_data['last_name']),
                        esc_html($parent_data['email']),
                        esc_html($camper_name),
                        floatval($total)
                    );
                    
                    wp_mail($admin_email, $admin_subject, $admin_message, $headers);
                    error_log('[PTP Return v114.1] Admin alert sent to: ' . $admin_email);
                }
            }
        } else {
            error_log('[PTP Return v117.2.12] Skipped WooCommerce order creation - total=$' . $total . 
                ', wc_create_order=' . (function_exists('wc_create_order') ? 'true' : 'false') .
                ', is_training_only=' . ($is_training_only ? 'true' : 'false'));
        }
        
        // Create training booking if trainer selected
        // v117.2.24: Enhanced logging for debugging
        error_log('[PTP Return v117.2.24] ===== TRAINING BOOKING CHECK =====');
        error_log('[PTP Return v117.2.24] trainer_id: ' . $trainer_id);
        error_log('[PTP Return v117.2.24] training_total: ' . ($data['training_total'] ?? 'NOT SET'));
        error_log('[PTP Return v117.2.24] training_package: ' . ($data['training_package'] ?? 'NOT SET'));
        error_log('[PTP Return v117.2.24] parent_id: ' . ($parent_id ?? 'NOT SET'));
        error_log('[PTP Return v117.2.24] parent_email: ' . ($parent_data['email'] ?? 'NOT SET'));
        
        if ($trainer_id > 0 && ($data['training_total'] ?? 0) > 0) {
            // Get or create player record
            $player_id_for_booking = $player_id;
            if (!$player_id_for_booking && $parent_id && !empty($camper_data['first_name'])) {
                $player_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
                $wpdb->insert($wpdb->prefix . 'ptp_players', array(
                    'parent_id' => $parent_id,
                    'name' => $player_name,  // Required for SMS queries
                    'first_name' => $camper_data['first_name'],
                    'last_name' => $camper_data['last_name'] ?? '',
                    'dob' => $camper_data['dob'] ?? null,
                    'age' => !empty($camper_data['dob']) ? (int)date_diff(date_create($camper_data['dob']), date_create('today'))->y : null,
                    'shirt_size' => $camper_data['shirt_size'] ?? null,
                    'created_at' => current_time('mysql'),
                ));
                $player_id_for_booking = $wpdb->insert_id;
                error_log('[PTP Return v117.2.25] Created player: ' . $player_id_for_booking . ' - ' . $player_name);
            }
            
            // Create booking
            $training_package = $data['training_package'] ?? 'single';
            $sessions = array('single' => 1, 'pack3' => 3, 'pack5' => 5);
            $num_sessions = $sessions[$training_package] ?? 1;
            $platform_fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
            $trainer_payout = round(($data['training_total'] ?? 0) * (1 - $platform_fee_pct / 100), 2);
            
            // Generate booking number
            $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
            
            // v122: Build insert data that works with BOTH schema versions
            $booking_insert = array(
                'booking_number' => $booking_number,
                'trainer_id' => $trainer_id,
                'parent_id' => $parent_id,
                'player_id' => $player_id_for_booking ?: 0,
                'session_date' => !empty($data['session_date']) ? $data['session_date'] : null,
                'start_time' => !empty($data['session_time']) ? $data['session_time'] : null,
                'location' => !empty($data['session_location']) ? $data['session_location'] : '',
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'payment_intent_id' => $payment_intent_id,
                'created_at' => current_time('mysql'),
            );
            
            // v122: Check which columns exist and add appropriate data
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings");
            
            // New schema columns
            if (in_array('package_type', $columns)) {
                $booking_insert['package_type'] = $training_package;
            }
            if (in_array('total_sessions', $columns)) {
                $booking_insert['total_sessions'] = $num_sessions;
            }
            if (in_array('sessions_remaining', $columns)) {
                $booking_insert['sessions_remaining'] = $num_sessions;
            }
            if (in_array('total_amount', $columns)) {
                $booking_insert['total_amount'] = $data['training_total'];
            }
            if (in_array('amount_paid', $columns)) {
                $booking_insert['amount_paid'] = $data['training_total'];
            }
            if (in_array('platform_fee', $columns)) {
                $booking_insert['platform_fee'] = round(($data['training_total'] ?? 0) * ($platform_fee_pct / 100), 2);
            }
            if (in_array('trainer_payout', $columns)) {
                $booking_insert['trainer_payout'] = $trainer_payout;
            }
            
            // Old schema columns (for backward compat)
            if (in_array('session_type', $columns)) {
                $booking_insert['session_type'] = $training_package;
            }
            if (in_array('session_count', $columns)) {
                $booking_insert['session_count'] = $num_sessions;
            }
            if (in_array('hourly_rate', $columns)) {
                $booking_insert['hourly_rate'] = round(($data['training_total'] ?? 0) / $num_sessions, 2);
            }
            if (in_array('end_time', $columns) && !empty($data['session_time'])) {
                // Calculate end time (1 hour after start)
                $start = strtotime($data['session_time']);
                if ($start) {
                    $booking_insert['end_time'] = date('H:i:s', $start + 3600);
                }
            }
            
            // Store parent email in guest_email as backup
            if (in_array('guest_email', $columns) && !empty($parent_data['email'])) {
                $booking_insert['guest_email'] = $parent_data['email'];
            }
            
            // v132: Store multi-player data in group_players column
            if (in_array('group_players', $columns) && !empty($data['players_data'])) {
                $booking_insert['group_players'] = json_encode($data['players_data']);
                error_log('[PTP Checkout v132] Storing ' . count($data['players_data']) . ' players in group_players');
            }
            
            error_log('[PTP Return v122] Attempting booking insert with columns: ' . implode(', ', array_keys($booking_insert)));
            
            $insert_result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $booking_insert);
            
            if ($insert_result === false) {
                error_log('[PTP Return v122] BOOKING INSERT FAILED!');
                error_log('[PTP Return v122] MySQL Error: ' . $wpdb->last_error);
                error_log('[PTP Return v122] Last Query: ' . $wpdb->last_query);
                error_log('[PTP Return v122] Insert Data: ' . json_encode($booking_insert));
                
                // Fire hook for debugging
                do_action('ptp_booking_insert_failed', $booking_insert, $wpdb->last_error);
            } else {
                $result['booking_id'] = $wpdb->insert_id;
                error_log('[PTP Return v122] Booking created successfully: ' . $result['booking_id']);
            }
            
            error_log('[PTP Return v114] Booking created: ' . $result['booking_id']);
            
            // ========================================
            // CREATE ESCROW HOLD - Funds held until session complete
            // ========================================
            if ($result['booking_id'] && class_exists('PTP_Escrow')) {
                $escrow_id = PTP_Escrow::create_hold(
                    $result['booking_id'],
                    $payment_intent_id,
                    $data['training_total']
                );
                
                if (is_wp_error($escrow_id)) {
                    error_log('[PTP Return v117.2.25] Escrow creation failed: ' . $escrow_id->get_error_message());
                } else {
                    error_log('[PTP Return v117.2.25] Escrow created: ' . $escrow_id);
                    
                    // Update booking with escrow info
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array(
                            'escrow_id' => $escrow_id,
                            'funds_held' => 1,
                            'payment_status' => 'paid',
                        ),
                        array('id' => $result['booking_id'])
                    );
                }
            }
            
            // ========================================
            // PACKAGE CREDITS - For multi-session packs
            // ========================================
            if ($num_sessions > 1 && $result['booking_id'] && $parent_id) {
                // Create package credit record (sessions remaining after first one)
                $remaining_sessions = $num_sessions - 1; // First session is booked now
                $price_per_session = round(($data['training_total'] ?? 0) / $num_sessions, 2);
                $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
                
                $credit_result = $wpdb->insert(
                    $wpdb->prefix . 'ptp_package_credits',
                    array(
                        'parent_id' => $parent_id,
                        'trainer_id' => $trainer_id,
                        'package_type' => $training_package,
                        'total_credits' => $num_sessions,
                        'remaining' => $remaining_sessions,
                        'price_per_session' => $price_per_session,
                        'total_paid' => $data['training_total'] ?? 0,
                        'payment_intent_id' => $payment_intent_id,
                        'expires_at' => $expires_at,
                        'status' => 'active',
                        'created_at' => current_time('mysql'),
                    ),
                    array('%d', '%d', '%s', '%d', '%d', '%f', '%f', '%s', '%s', '%s', '%s')
                );
                
                if ($credit_result) {
                    $credit_id = $wpdb->insert_id;
                    $result['package_credit_id'] = $credit_id;
                    $result['sessions_remaining'] = $remaining_sessions;
                    
                    // Link booking to credit
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array('package_credit_id' => $credit_id),
                        array('id' => $result['booking_id'])
                    );
                    
                    error_log('[PTP Return v117.2.26] Package credit created: ' . $credit_id . ' with ' . $remaining_sessions . ' remaining sessions for trainer ' . $trainer_id);
                } else {
                    error_log('[PTP Return v117.2.26] Failed to create package credit: ' . $wpdb->last_error);
                }
            }
            
            // ========================================
            // NOTIFICATIONS - Email & SMS
            // ========================================
            // Notify trainer (handles both email + SMS for trainer and parent)
            $this->notify_trainer($trainer_id, $result['booking_id'], $camper_name);
            
            // Fire hooks for additional integrations (SMS class listens to these)
            do_action('ptp_booking_confirmed', $result['booking_id']);
            do_action('ptp_training_booking_created', $result['booking_id'], $trainer_id, $data);
            do_action('ptp_package_credits_created', $result['package_credit_id'] ?? null, $parent_id, $trainer_id, $num_sessions);
            
            // Schedule session reminder (24 hours before)
            if (!empty($data['session_date']) && !empty($data['session_time'])) {
                $session_datetime = strtotime($data['session_date'] . ' ' . $data['session_time']);
                $reminder_time = $session_datetime - (24 * 60 * 60); // 24 hours before
                
                if ($reminder_time > time()) {
                    wp_schedule_single_event($reminder_time, 'ptp_session_reminder', array($result['booking_id']));
                    error_log('[PTP Return v117.2.25] Session reminder scheduled for: ' . date('Y-m-d H:i', $reminder_time));
                }
            }
        }
        
        return $result;
    }
    
    /**
     * Create required tables
     */
    public function maybe_create_tables() {
        if (get_option('ptp_checkout_tables_v86') === '1') return;
        
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        
        // Parents table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_parents (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint(20) UNSIGNED DEFAULT NULL,
            first_name varchar(100) DEFAULT NULL,
            last_name varchar(100) DEFAULT NULL,
            email varchar(255) DEFAULT NULL,
            phone varchar(50) DEFAULT NULL,
            address text DEFAULT NULL,
            city varchar(100) DEFAULT NULL,
            state varchar(50) DEFAULT NULL,
            zip varchar(20) DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY email (email)
        ) $charset");
        
        // Players/Campers table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_players (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id bigint(20) UNSIGNED NOT NULL,
            first_name varchar(100) NOT NULL,
            last_name varchar(100) DEFAULT NULL,
            dob date DEFAULT NULL,
            age int DEFAULT NULL,
            shirt_size varchar(10) DEFAULT NULL,
            team varchar(255) DEFAULT NULL,
            skill_level varchar(50) DEFAULT NULL,
            position varchar(50) DEFAULT NULL,
            emergency_name varchar(255) DEFAULT NULL,
            emergency_phone varchar(50) DEFAULT NULL,
            emergency_relation varchar(100) DEFAULT NULL,
            medical_info text DEFAULT NULL,
            insurance_provider varchar(255) DEFAULT NULL,
            insurance_policy varchar(100) DEFAULT NULL,
            insurance_group varchar(100) DEFAULT NULL,
            waiver_accepted tinyint(1) DEFAULT 0,
            waiver_accepted_at datetime DEFAULT NULL,
            waiver_ip varchar(45) DEFAULT NULL,
            photo_consent tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY parent_id (parent_id)
        ) $charset");
        
        // Add photo_consent column if it doesn't exist (for existing databases)
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$wpdb->prefix}ptp_players LIKE 'photo_consent'");
        if (empty($column_exists)) {
            $wpdb->query("ALTER TABLE {$wpdb->prefix}ptp_players ADD COLUMN photo_consent tinyint(1) DEFAULT 1 AFTER waiver_ip");
        }
        
        // Bookings table
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_bookings (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            parent_id bigint(20) UNSIGNED NOT NULL,
            player_id bigint(20) UNSIGNED DEFAULT NULL,
            session_date date DEFAULT NULL,
            session_time time DEFAULT NULL,
            location varchar(255) DEFAULT NULL,
            package_type varchar(50) DEFAULT 'single',
            total_sessions int DEFAULT 1,
            sessions_remaining int DEFAULT 1,
            sessions_completed int DEFAULT 0,
            amount_paid decimal(10,2) DEFAULT 0,
            trainer_payout decimal(10,2) DEFAULT 0,
            payment_intent_id varchar(255) DEFAULT NULL,
            status varchar(50) DEFAULT 'pending',
            notes text DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_id (trainer_id),
            KEY parent_id (parent_id),
            KEY status (status)
        ) $charset");
        
        update_option('ptp_checkout_tables_v86', '1');
    }
    
    /**
     * Process checkout
     */
    public function process_checkout() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['ptp_checkout_nonce'] ?? '', 'ptp_checkout')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
            return;
        }
        
        global $wpdb;
        
        // ========================================
        // COLLECT ALL FORM DATA
        // ========================================
        
        $payment_method_id = sanitize_text_field($_POST['payment_method_id'] ?? '');
        $cart_total = floatval($_POST['cart_total'] ?? 0);
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $training_package = sanitize_text_field($_POST['training_package'] ?? 'single');
        $bundle_code = sanitize_text_field($_POST['bundle_code'] ?? '');
        
        // Session booking data
        $session_date = sanitize_text_field($_POST['session_date'] ?? '');
        $session_time = sanitize_text_field($_POST['session_time'] ?? '');
        $session_location = sanitize_text_field($_POST['session_location'] ?? '');
        
        // Parent/Guardian info
        $parent_data = array(
            'first_name' => sanitize_text_field($_POST['parent_first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['parent_last_name'] ?? ''),
            'email' => sanitize_email($_POST['parent_email'] ?? ''),
            'phone' => sanitize_text_field($_POST['parent_phone'] ?? ''),
        );
        
        // Camper info
        $player_id = intval($_POST['player_id'] ?? 0);
        $camper_data = array(
            'first_name' => sanitize_text_field($_POST['camper_first_name'] ?? ''),
            'last_name' => sanitize_text_field($_POST['camper_last_name'] ?? ''),
            'dob' => sanitize_text_field($_POST['camper_dob'] ?? ''),
            'shirt_size' => sanitize_text_field($_POST['camper_shirt_size'] ?? ''),
            'team' => sanitize_text_field($_POST['camper_team'] ?? ''),
            'skill_level' => sanitize_text_field($_POST['camper_skill'] ?? ''),
        );
        
        // Emergency & Medical
        $emergency_data = array(
            'name' => sanitize_text_field($_POST['emergency_name'] ?? ''),
            'phone' => sanitize_text_field($_POST['emergency_phone'] ?? ''),
            'relation' => sanitize_text_field($_POST['emergency_relation'] ?? ''),
        );
        
        $medical_info = sanitize_textarea_field($_POST['medical_info'] ?? '');
        
        // Insurance
        $insurance_data = array(
            'provider' => sanitize_text_field($_POST['insurance_provider'] ?? ''),
            'policy' => sanitize_text_field($_POST['insurance_policy'] ?? ''),
            'group' => sanitize_text_field($_POST['insurance_group'] ?? ''),
        );
        
        // Waiver
        $waiver_accepted = isset($_POST['waiver_accepted']) && $_POST['waiver_accepted'];
        
        // Photo/Media Consent
        $photo_consent = isset($_POST['photo_consent']) && $_POST['photo_consent'];
        
        // Before/After Care
        $before_after_care = isset($_POST['before_after_care']) && $_POST['before_after_care'];
        $care_amount = floatval($_POST['before_after_care_amount'] ?? 0);
        
        // Upgrade pack
        $upgrade_selected = sanitize_text_field($_POST['upgrade_selected'] ?? '');
        $upgrade_amount = floatval($_POST['upgrade_amount'] ?? 0);
        $upgrade_camps = sanitize_text_field($_POST['upgrade_camps'] ?? '');
        $upgrade_camp_ids = array_filter(array_map('intval', explode(',', $upgrade_camps)));
        
        // ========================================
        // VALIDATION
        // ========================================
        
        if (empty($parent_data['first_name']) || empty($parent_data['email'])) {
            wp_send_json_error(array('message' => 'Please fill in all required parent/guardian fields.'));
            return;
        }
        
        if (!$player_id && empty($camper_data['first_name'])) {
            wp_send_json_error(array('message' => 'Please provide camper information.'));
            return;
        }
        
        if (empty($emergency_data['name']) || empty($emergency_data['phone'])) {
            wp_send_json_error(array('message' => 'Emergency contact information is required.'));
            return;
        }
        
        if (!$waiver_accepted) {
            wp_send_json_error(array('message' => 'You must accept the waiver and agreement to continue.'));
            return;
        }
        
        // Check for deferred payment flow (Elements will handle payment method)
        $create_intent_only = isset($_POST['create_intent_only']) && $_POST['create_intent_only'];
        
        if (!$create_intent_only && empty($payment_method_id)) {
            wp_send_json_error(array('message' => 'Payment information is required.'));
            return;
        }
        
        // ========================================
        // USER & PARENT CREATION
        // ========================================
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            $existing_user = get_user_by('email', $parent_data['email']);
            if ($existing_user) {
                $user_id = $existing_user->ID;
            } else {
                $username = $this->generate_unique_username($parent_data['email']);
                $password = wp_generate_password();
                
                $user_id = wp_create_user($username, $password, $parent_data['email']);
                if (is_wp_error($user_id)) {
                    wp_send_json_error(array('message' => 'Could not create account.'));
                    return;
                }
                
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => $parent_data['first_name'],
                    'last_name' => $parent_data['last_name'],
                    'display_name' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
                ));
                
                update_user_meta($user_id, 'billing_phone', $parent_data['phone']);
                update_user_meta($user_id, 'billing_first_name', $parent_data['first_name']);
                update_user_meta($user_id, 'billing_last_name', $parent_data['last_name']);
                update_user_meta($user_id, 'billing_email', $parent_data['email']);
            }
        }
        
        // Get or create parent record
        $parent_id = $this->get_or_create_parent($user_id, $parent_data);
        
        // ========================================
        // PLAYER/CAMPER CREATION
        // ========================================
        
        if ($player_id) {
            // Verify player belongs to parent & update with new data
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_players WHERE id = %d AND parent_id = %d",
                $player_id, $parent_id
            ));
            
            if ($existing) {
                // Update emergency & medical for this registration
                $wpdb->update(
                    $wpdb->prefix . 'ptp_players',
                    array(
                        'emergency_name' => $emergency_data['name'],
                        'emergency_phone' => $emergency_data['phone'],
                        'emergency_relation' => $emergency_data['relation'],
                        'medical_info' => $medical_info,
                        'insurance_provider' => $insurance_data['provider'],
                        'insurance_policy' => $insurance_data['policy'],
                        'insurance_group' => $insurance_data['group'],
                        'waiver_accepted' => 1,
                        'waiver_accepted_at' => current_time('mysql'),
                        'waiver_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    ),
                    array('id' => $player_id)
                );
                
                $camper_data['first_name'] = $existing->first_name;
                $camper_data['last_name'] = $existing->last_name;
            } else {
                $player_id = 0; // Invalid, create new
            }
        }
        
        if (!$player_id && !empty($camper_data['first_name'])) {
            // Calculate age from DOB
            $age = null;
            if (!empty($camper_data['dob'])) {
                $dob = new DateTime($camper_data['dob']);
                $now = new DateTime();
                $age = $dob->diff($now)->y;
            }
            
            $wpdb->insert(
                $wpdb->prefix . 'ptp_players',
                array(
                    'parent_id' => $parent_id,
                    'first_name' => $camper_data['first_name'],
                    'last_name' => $camper_data['last_name'],
                    'dob' => $camper_data['dob'] ?: null,
                    'age' => $age,
                    'shirt_size' => $camper_data['shirt_size'],
                    'team' => $camper_data['team'],
                    'skill_level' => $camper_data['skill_level'],
                    'emergency_name' => $emergency_data['name'],
                    'emergency_phone' => $emergency_data['phone'],
                    'emergency_relation' => $emergency_data['relation'],
                    'medical_info' => $medical_info,
                    'insurance_provider' => $insurance_data['provider'],
                    'insurance_policy' => $insurance_data['policy'],
                    'insurance_group' => $insurance_data['group'],
                    'waiver_accepted' => 1,
                    'waiver_accepted_at' => current_time('mysql'),
                    'waiver_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                    'photo_consent' => $photo_consent ? 1 : 0,
                    'created_at' => current_time('mysql'),
                )
            );
            $player_id = $wpdb->insert_id;
        }
        
        // ========================================
        // CALCULATE TOTALS
        // ========================================
        
        $woo_total = 0;
        $training_total = 0;
        $has_woo = function_exists('WC') && WC()->cart && !WC()->cart->is_empty();
        
        if ($has_woo) {
            WC()->cart->calculate_totals();
            $woo_total = floatval(WC()->cart->get_subtotal());
        }
        
        // Training pricing
        $trainer = null;
        if ($trainer_id) {
            $trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $trainer_id
            ));
            
            if ($trainer) {
                $rate = intval($trainer->hourly_rate ?: 60);
                $packages = array(
                    'single' => $rate,
                    'pack3' => intval($rate * 3 * 0.9),
                    'pack5' => intval($rate * 5 * 0.85),
                );
                $training_total = $packages[$training_package] ?? $rate;
            }
        }
        
        $subtotal = $woo_total + $training_total;
        
        // Bundle discount
        $bundle_discount = 0;
        $bundle = null;
        if ($bundle_code && $trainer_id && $has_woo) {
            $bundle = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_bundles WHERE bundle_code = %s",
                $bundle_code
            ));
            if ($bundle) {
                $discount_pct = floatval($bundle->discount_percent ?: 15);
                $bundle_discount = round($subtotal * ($discount_pct / 100), 2);
            }
        }
        
        $total = $subtotal - $bundle_discount;
        
        // ========================================
        // STRIPE PAYMENT
        // ========================================
        
        $secret_key = get_option('ptp_stripe_test_mode', true) 
            ? get_option('ptp_stripe_test_secret', get_option('ptp_stripe_secret_key', ''))
            : get_option('ptp_stripe_live_secret', get_option('ptp_stripe_secret_key', ''));
        
        if (empty($secret_key)) {
            wp_send_json_error(array('message' => 'Payment system not configured. Please contact support.'));
            return;
        }
        
        $amount_cents = intval(round($total * 100));
        
        if ($amount_cents < 50) {
            wp_send_json_error(array('message' => 'Order total must be at least $0.50'));
            return;
        }
        
        $camper_name = trim($camper_data['first_name'] . ' ' . $camper_data['last_name']);
        $create_intent_only = isset($_POST['create_intent_only']) && $_POST['create_intent_only'];
        
        // Build description from cart items
        $cart_items_str = sanitize_text_field($_POST['cart_items'] ?? '');
        $description_parts = array();
        if (!empty($cart_items_str)) {
            $cart_item_names = array_map('trim', explode(',', $cart_items_str));
            $description_parts = array_slice($cart_item_names, 0, 3);
        }
        if ($trainer) {
            $description_parts[] = 'Training with ' . $trainer->display_name;
        }
        $stripe_description = !empty($description_parts) 
            ? implode(', ', $description_parts) . ' - ' . $camper_name
            : 'PTP Registration - ' . $camper_name;
        
        // Build PaymentIntent data
        $intent_data = array(
            'amount' => $amount_cents,
            'currency' => 'usd',
            'automatic_payment_methods[enabled]' => 'true',
            'description' => $stripe_description,
            // v114.1: Removed receipt_email - WooCommerce handles confirmation emails
            'metadata[parent_name]' => $parent_data['first_name'] . ' ' . $parent_data['last_name'],
            'metadata[parent_email]' => $parent_data['email'],
            'metadata[camper_name]' => $camper_name,
            'metadata[player_id]' => $player_id,
            'metadata[checkout_session]' => wp_generate_uuid4(),
            'metadata[cart_items]' => $cart_items_str,
        );
        
        // Stripe Connect for trainer
        if ($trainer && !empty($trainer->stripe_account_id) && $training_total > 0) {
            $platform_fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
            $trainer_amount = intval(round($training_total * (1 - $platform_fee_pct / 100) * 100));
            
            $intent_data['transfer_data[destination]'] = $trainer->stripe_account_id;
            $intent_data['transfer_data[amount]'] = $trainer_amount;
            $intent_data['metadata[trainer_id]'] = $trainer_id;
        }
        
        // Store form data in session for webhook/return handling
        $checkout_data = array(
            'parent_data' => $parent_data,
            'camper_data' => $camper_data,
            'emergency_data' => $emergency_data,
            'medical_info' => $medical_info,
            'insurance_data' => $insurance_data,
            'waiver_accepted' => $waiver_accepted,
            'photo_consent' => $photo_consent,
            'cart_items' => $_POST['cart_items'] ?? '',
            'trainer_id' => $trainer_id,
            'training_package' => $training_package,
            'training_total' => $training_total,
            'camp_total' => $camp_total,
            'bundle_discount' => $bundle_discount,
            'total' => $total,
        );
        set_transient('ptp_checkout_' . $intent_data['metadata[checkout_session]'], $checkout_data, HOUR_IN_SECONDS);
        
        $response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => $intent_data,
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            error_log('[PTP Checkout] Stripe error: ' . $response->get_error_message());
            wp_send_json_error(array('message' => 'Payment failed. Please try again.'));
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            error_log('[PTP Checkout] Stripe error: ' . json_encode($body['error']));
            $msg = $body['error']['message'] ?? 'Payment failed.';
            if (strpos($msg, 'declined') !== false) {
                $msg = 'Your card was declined. Please try a different card.';
            }
            wp_send_json_error(array('message' => $msg));
            return;
        }
        
        $payment_intent_id = $body['id'] ?? '';
        $client_secret = $body['client_secret'] ?? '';
        $status = $body['status'] ?? '';
        
        // For deferred confirmation flow (Affirm support), return client_secret
        // The frontend will call stripe.confirmPayment() with this
        if ($status === 'requires_payment_method' || $status === 'requires_confirmation') {
            wp_send_json_success(array(
                'client_secret' => $client_secret,
                'payment_intent_id' => $payment_intent_id,
                'redirect_url' => home_url('/thank-you/?payment_intent=' . $payment_intent_id . '&session=' . $intent_data['metadata[checkout_session]']),
            ));
            return;
        }
        
        // Handle 3D Secure or other action required
        if ($status === 'requires_action' || $status === 'requires_source_action') {
            wp_send_json_success(array(
                'requires_action' => true,
                'client_secret' => $client_secret,
                'payment_intent_id' => $payment_intent_id,
                'redirect_url' => home_url('/thank-you/?payment_intent=' . $payment_intent_id . '&session=' . $intent_data['metadata[checkout_session]']),
            ));
            return;
        }
        
        // ========================================
        // PAYMENT SUCCEEDED - CREATE ORDERS
        // ========================================
        
        if ($status === 'succeeded') {
            $order_id = null;
            $booking_id = null;
            
            // WooCommerce order
            if ($has_woo) {
                $order = wc_create_order(array(
                    'customer_id' => $user_id,
                    'status' => 'pending', // Start as pending, payment_complete will change to processing and trigger emails
                ));
                
                if (!is_wp_error($order)) {
                    // Add items
                    foreach (WC()->cart->get_cart() as $cart_item) {
                        $product = $cart_item['data'];
                        $item_id = $order->add_product(
                            $product,
                            $cart_item['quantity'],
                            array(
                                'subtotal' => $cart_item['line_subtotal'],
                                'total' => $cart_item['line_total'],
                            )
                        );
                        
                        if ($item_id) {
                            wc_add_order_item_meta($item_id, 'Player Name', $camper_name);
                            wc_add_order_item_meta($item_id, 'Player Age', $camper_data['dob'] ? (new DateTime($camper_data['dob']))->diff(new DateTime())->y : '');
                            wc_add_order_item_meta($item_id, 'T-Shirt Size', $camper_data['shirt_size']);
                            wc_add_order_item_meta($item_id, '_player_id', $player_id);
                        }
                    }
                    
                    // Billing
                    $order->set_billing_first_name($parent_data['first_name']);
                    $order->set_billing_last_name($parent_data['last_name']);
                    $order->set_billing_email($parent_data['email']);
                    $order->set_billing_phone($parent_data['phone']);
                    
                    // Bundle discount
                    if ($bundle_discount > 0) {
                        $fee = new WC_Order_Item_Fee();
                        $fee->set_name('Bundle Discount');
                        $fee->set_total(-$bundle_discount);
                        $order->add_item($fee);
                    }
                    
                    $order->calculate_totals();
                    $order->set_payment_method('stripe');
                    $order->set_payment_method_title('Credit Card');
                    $order->set_transaction_id($payment_intent_id);
                    
                    // Meta
                    $order->update_meta_data('_stripe_payment_intent', $payment_intent_id);
                    $order->update_meta_data('_player_id', $player_id);
                    $order->update_meta_data('_player_name', $camper_name);
                    $order->update_meta_data('_emergency_contact', $emergency_data['name'] . ' - ' . $emergency_data['phone']);
                    $order->update_meta_data('_medical_info', $medical_info);
                    $order->update_meta_data('_waiver_accepted', 'yes');
                    $order->update_meta_data('_waiver_accepted_at', current_time('mysql'));
                    $order->update_meta_data('_photo_consent', $photo_consent ? 'yes' : 'no');
                    
                    if ($bundle_code) {
                        $order->update_meta_data('_bundle_code', $bundle_code);
                        $order->update_meta_data('_bundle_discount', $bundle_discount);
                    }
                    
                    // Before/After Care
                    if ($before_after_care) {
                        $order->update_meta_data('_before_after_care', 'yes');
                        $order->update_meta_data('_before_after_care_amount', $care_amount);
                        
                        // Add as fee
                        $care_fee = new WC_Order_Item_Fee();
                        $care_fee->set_name('Before & After Care');
                        $care_fee->set_total($care_amount);
                        $order->add_item($care_fee);
                    }
                    
                    // Upgrade Pack
                    if ($upgrade_selected) {
                        $order->update_meta_data('_upgrade_pack', $upgrade_selected);
                        $order->update_meta_data('_upgrade_amount', $upgrade_amount);
                        
                        $upgrade_labels = array(
                            '2pack' => '2-Camp Pack (+1 Camp)',
                            '3pack' => '3-Camp Pack (+2 Camps)',
                            'allaccess' => 'All-Access Pass',
                        );
                        $upgrade_label = $upgrade_labels[$upgrade_selected] ?? 'Camp Pack Upgrade';
                        
                        // Add as fee
                        $upgrade_fee = new WC_Order_Item_Fee();
                        $upgrade_fee->set_name($upgrade_label);
                        $upgrade_fee->set_total($upgrade_amount);
                        $order->add_item($upgrade_fee);
                        
                        // Save selected additional camps
                        if (!empty($upgrade_camp_ids)) {
                            $order->update_meta_data('_upgrade_camp_ids', $upgrade_camp_ids);
                            
                            // Get camp names for display
                            $camp_names = array();
                            foreach ($upgrade_camp_ids as $camp_id) {
                                $camp_product = wc_get_product($camp_id);
                                if ($camp_product) {
                                    $camp_names[] = $camp_product->get_name();
                                }
                            }
                            $order->update_meta_data('_upgrade_camp_names', implode(', ', $camp_names));
                        }
                    }
                    
                    $order->payment_complete($payment_intent_id);
                    $order->add_order_note('Paid via PTP Checkout. Payment Intent: ' . $payment_intent_id);
                    $order->save();
                    
                    $order_id = $order->get_id();
                    
                    WC()->cart->empty_cart();
                    
                    // Trigger WooCommerce emails properly
                    error_log('[PTP Checkout] Triggering emails for order #' . $order_id);
                    
                    // Method 1: Trigger status change hook (most reliable)
                    do_action('woocommerce_order_status_pending_to_processing_notification', $order_id, $order);
                    
                    // Method 2: Trigger new order actions
                    do_action('woocommerce_new_order', $order_id, $order);
                    do_action('woocommerce_checkout_order_processed', $order_id, array(), $order);
                    
                    // Method 3: Trigger customer email directly as fallback
                    if (class_exists('WC_Emails')) {
                        $wc_emails = WC_Emails::instance();
                        $emails = $wc_emails->get_emails();
                        
                        // Customer processing order email
                        if (isset($emails['WC_Email_Customer_Processing_Order'])) {
                            $emails['WC_Email_Customer_Processing_Order']->trigger($order_id, $order);
                            error_log('[PTP Checkout] Customer processing email triggered');
                        }
                        
                        // Admin new order email
                        if (isset($emails['WC_Email_New_Order'])) {
                            $emails['WC_Email_New_Order']->trigger($order_id, $order);
                            error_log('[PTP Checkout] Admin new order email triggered');
                        }
                    }
                    
                    error_log('[PTP Checkout] Order #' . $order_id . ' created successfully, emails triggered');
                }
            }
            
            // Training booking
            if ($trainer_id && $training_total > 0) {
                $sessions = array('single' => 1, 'pack3' => 3, 'pack5' => 5);
                $num_sessions = $sessions[$training_package] ?? 1;
                $platform_fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
                $trainer_payout = round($training_total * (1 - $platform_fee_pct / 100), 2);
                
                // Determine status based on whether date was selected
                $booking_status = !empty($session_date) ? 'confirmed' : 'pending_schedule';
                
                $wpdb->insert(
                    $wpdb->prefix . 'ptp_bookings',
                    array(
                        'trainer_id' => $trainer_id,
                        'parent_id' => $parent_id,
                        'player_id' => $player_id,
                        'session_date' => !empty($session_date) ? $session_date : null,
                        'start_time' => !empty($session_time) ? $session_time : null,
                        'location' => !empty($session_location) ? $session_location : null,
                        'package_type' => $training_package,
                        'total_sessions' => $num_sessions,
                        'sessions_remaining' => $num_sessions,
                        'amount_paid' => $training_total,
                        'trainer_payout' => $trainer_payout,
                        'payment_intent_id' => $payment_intent_id,
                        'status' => $booking_status,
                        'created_at' => current_time('mysql'),
                    )
                );
                $booking_id = $wpdb->insert_id;
                
                // Update bundle
                if ($bundle_code) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bundles',
                        array(
                            'training_booking_id' => $booking_id,
                            'training_status' => 'completed',
                            'camp_order_id' => $order_id,
                            'camp_status' => $order_id ? 'completed' : 'pending',
                            'payment_intent_id' => $payment_intent_id,
                            'payment_status' => 'completed',
                            'completed_at' => current_time('mysql'),
                            'status' => 'completed',
                        ),
                        array('bundle_code' => $bundle_code)
                    );
                }
                
                // Notify trainer
                $this->notify_trainer($trainer_id, $booking_id, $camper_name);
            }
            
            // Redirect URL
            $redirect_url = home_url('/thank-you/');
            if ($order_id) {
                $order = wc_get_order($order_id);
                if ($order) {
                    $redirect_url = $order->get_checkout_order_received_url();
                }
            } elseif ($booking_id) {
                $redirect_url = home_url('/my-account/?booking=' . $booking_id . '&success=1');
            }
            
            wp_send_json_success(array(
                'order_id' => $order_id,
                'booking_id' => $booking_id,
                'redirect_url' => $redirect_url,
            ));
            return;
        }
        
        wp_send_json_error(array('message' => 'Payment could not be processed. Status: ' . $status));
    }
    
    /**
     * Generate unique username
     */
    private function generate_unique_username($email) {
        $base = sanitize_user(current(explode('@', $email)));
        $base = preg_replace('/[^a-zA-Z0-9]/', '', $base);
        if (empty($base)) $base = 'user';
        
        $username = $base;
        $i = 1;
        while (username_exists($username)) {
            $username = $base . $i;
            $i++;
        }
        return $username;
    }
    
    /**
     * Get or create parent
     */
    private function get_or_create_parent($user_id, $data) {
        global $wpdb;
        
        $parent = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if ($parent) {
            // Update
            $wpdb->update(
                $wpdb->prefix . 'ptp_parents',
                array(
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'phone' => $data['phone'],
                ),
                array('id' => $parent->id)
            );
            return $parent->id;
        }
        
        // Create
        $wpdb->insert(
            $wpdb->prefix . 'ptp_parents',
            array(
                'user_id' => $user_id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'created_at' => current_time('mysql'),
            )
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Notify trainer of booking
     */
    public function notify_trainer($trainer_id, $booking_id, $camper_name) {
        global $wpdb;
        
        error_log('[PTP Notify v128.2.7] Starting notify_trainer for booking ' . $booking_id . ', trainer ' . $trainer_id);
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID 
             WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer) {
            error_log('[PTP Notify v128.2.7] Trainer not found: ' . $trainer_id);
            return;
        }
        
        // Get booking with parent info from multiple sources
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    p.first_name as parent_first, p.last_name as parent_last, p.email as parent_email, p.phone as parent_phone,
                    pl.first_name as player_first, pl.last_name as player_last,
                    u.user_email as wp_user_email
             FROM {$wpdb->prefix}ptp_bookings b
             LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            error_log('[PTP Notify v128.2.7] Booking not found: ' . $booking_id);
            return;
        }
        
        // v128.2.7: Get parent email with enhanced fallbacks
        $parent_email = $booking->parent_email;
        if (empty($parent_email) && !empty($booking->wp_user_email)) {
            $parent_email = $booking->wp_user_email;
            error_log('[PTP Notify v128.2.7] Using WP user email as fallback: ' . $parent_email);
        }
        
        // v128.2.7: Check guest_email column (used for guest checkout)
        if (empty($parent_email) && !empty($booking->guest_email)) {
            $parent_email = $booking->guest_email;
            error_log('[PTP Notify v128.2.7] Using guest_email as fallback: ' . $parent_email);
        }
        
        // v128.2.7: Try to get from recent checkout session transient
        if (empty($parent_email) && !empty($booking->payment_intent_id)) {
            // Search for transient with this payment intent
            $all_transients = $wpdb->get_results(
                "SELECT option_name, option_value FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_ptp_checkout_%' 
                 AND option_value LIKE '%" . esc_sql($booking->payment_intent_id) . "%'
                 LIMIT 1"
            );
            if (!empty($all_transients)) {
                $checkout_data = maybe_unserialize($all_transients[0]->option_value);
                if (is_array($checkout_data) && !empty($checkout_data['parent_data']['email'])) {
                    $parent_email = $checkout_data['parent_data']['email'];
                    error_log('[PTP Notify v128.2.7] Found email from checkout transient: ' . $parent_email);
                    
                    // Also update the parent record with this email if missing
                    if ($booking->parent_id && empty($booking->parent_email)) {
                        $wpdb->update(
                            $wpdb->prefix . 'ptp_parents',
                            array('email' => $parent_email),
                            array('id' => $booking->parent_id)
                        );
                        error_log('[PTP Notify v128.2.7] Updated parent #' . $booking->parent_id . ' with email: ' . $parent_email);
                    }
                }
            }
        }
        
        error_log('[PTP Notify v128.2.7] Final parent_email: ' . ($parent_email ?: 'NONE'));
        error_log('[PTP Notify v128.2.7] Parent phone: ' . ($booking->parent_phone ?: 'NONE'));
        
        // Format session details
        $date_display = !empty($booking->session_date) ? date('l, F j, Y', strtotime($booking->session_date)) : 'TBD - Trainer will confirm';
        $time_display = 'TBD';
        
        // v123: Check both start_time (db column) and session_time (legacy)
        $session_time = !empty($booking->start_time) ? $booking->start_time : (!empty($booking->session_time) ? $booking->session_time : '');
        if (!empty($session_time)) {
            $time_parts = explode(':', $session_time);
            $hour = intval($time_parts[0]);
            $minute = isset($time_parts[1]) ? intval($time_parts[1]) : 0;
            $ampm = $hour >= 12 ? 'PM' : 'AM';
            $displayHour = $hour > 12 ? $hour - 12 : ($hour == 0 ? 12 : $hour);
            $time_display = $minute > 0 ? "{$displayHour}:" . str_pad($minute, 2, '0', STR_PAD_LEFT) . " {$ampm}" : "{$displayHour}:00 {$ampm}";
            error_log('[PTP Notify v123] Time extracted: ' . $session_time . ' -> ' . $time_display);
        }
        $location_display = !empty($booking->location) ? $booking->location : 'TBD - Trainer will confirm';
        $package_labels = array('single' => 'Single Session', 'pack3' => '3-Session Pack', 'pack5' => '5-Session Pack');
        $package_display = $package_labels[$booking->package_type] ?? ucfirst($booking->package_type);
        $player_name = trim(($booking->player_first ?? '') . ' ' . ($booking->player_last ?? ''));
        if (empty($player_name)) $player_name = $camper_name;
        
        // Email headers - BRANDED FROM ADDRESS
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: PTP Training <training@ptpsummercamps.com>',
            'Reply-To: training@ptpsummercamps.com'
        );
        
        // ========================================
        // 1. TRAINER EMAIL
        // ========================================
        if (!empty($trainer->user_email)) {
            $trainer_subject = '🎉 New Training Booked - ' . $player_name;
            $trainer_body = $this->get_trainer_booking_email($trainer, $booking, $player_name, $date_display, $time_display, $location_display, $package_display);
            
            $sent = wp_mail($trainer->user_email, $trainer_subject, $trainer_body, $headers);
            error_log('[PTP Notify v117.2.18] Trainer email ' . ($sent ? 'SENT' : 'FAILED') . ' to: ' . $trainer->user_email);
        } else {
            error_log('[PTP Notify v117.2.18] No trainer email found');
        }
        
        // ========================================
        // 2. PARENT EMAIL
        // ========================================
        if (!empty($parent_email)) {
            $parent_subject = '✅ Training Session Confirmed - ' . $player_name;
            $parent_body = $this->get_parent_booking_email($trainer, $booking, $player_name, $date_display, $time_display, $location_display, $package_display);
            
            $sent = wp_mail($parent_email, $parent_subject, $parent_body, $headers);
            error_log('[PTP Notify v117.2.18] Parent email ' . ($sent ? 'SENT' : 'FAILED') . ' to: ' . $parent_email);
            
            // Mark as sent so thank-you page doesn't duplicate
            if ($sent) {
                set_transient('ptp_training_email_sent_' . $booking_id, array(
                    'parent' => $parent_email,
                    'trainer' => $trainer->user_email ?? '',
                    'time' => time()
                ), 24 * HOUR_IN_SECONDS);
            }
        } else {
            error_log('[PTP Notify v117.2.18] No parent email found - EMAIL NOT SENT');
        }
        
        // ========================================
        // 3. SMS NOTIFICATIONS
        // ========================================
        if (class_exists('PTP_SMS_V71') && PTP_SMS_V71::is_enabled()) {
            // Trainer SMS
            if (!empty($trainer->phone)) {
                $trainer_sms = "🎉 New booking! {$player_name} booked a {$package_display}. ";
                if (!empty($booking->session_date)) {
                    $trainer_sms .= date('M j', strtotime($booking->session_date)) . " at {$time_display}. ";
                }
                $trainer_sms .= "Check your dashboard for details.";
                
                PTP_SMS_V71::send($trainer->phone, $trainer_sms);
                error_log('[PTP Training v117.2.13] Trainer SMS sent to: ' . $trainer->phone);
            }
            
            // Parent SMS
            if (!empty($booking->parent_phone)) {
                $parent_sms = "✅ {$player_name}'s training with {$trainer->display_name} is confirmed! ";
                if (!empty($booking->session_date)) {
                    $parent_sms .= date('M j', strtotime($booking->session_date)) . " at {$time_display}";
                    if (!empty($booking->location)) {
                        $parent_sms .= " @ " . substr($booking->location, 0, 30);
                    }
                    $parent_sms .= ". ";
                } else {
                    $parent_sms .= "Your trainer will reach out to confirm date/time. ";
                }
                $parent_sms .= "Questions? Reply to this text.";
                
                PTP_SMS_V71::send($booking->parent_phone, $parent_sms);
                error_log('[PTP Training v117.2.13] Parent SMS sent to: ' . $booking->parent_phone);
            }
        }
        
        // Trigger action for other integrations
        do_action('ptp_training_booked', $booking_id, $trainer_id, $booking);
    }
    
    /**
     * Generate trainer booking email HTML
     */
    private function get_trainer_booking_email($trainer, $booking, $player_name, $date_display, $time_display, $location_display, $package_display) {
        $dashboard_url = home_url('/trainer-dashboard/');
        $earnings = number_format($booking->trainer_payout, 2);
        $needs_confirmation = empty($booking->session_date);
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0A0A0A;font-family:Inter,-apple-system,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0A0A0A;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align:center;padding-bottom:32px;">
                            <div style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#FCB900;letter-spacing:3px;">PTP</div>
                            <div style="font-size:11px;color:rgba(255,255,255,0.5);letter-spacing:2px;text-transform:uppercase;margin-top:4px;">TRAINING</div>
                        </td>
                    </tr>
                    
                    <!-- Main Card -->
                    <tr>
                        <td style="background:#1a1a1a;border:2px solid #FCB900;padding:32px;">
                            <h1 style="font-family:Oswald,sans-serif;font-size:28px;font-weight:700;color:#FCB900;margin:0 0 8px;text-transform:uppercase;">New Training Booked! 🎉</h1>
                            <p style="color:rgba(255,255,255,0.7);font-size:16px;margin:0 0 24px;">Great news, ' . esc_html($trainer->display_name) . '! A new session has been booked.</p>
                            
                            <!-- Session Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(252,185,0,0.1);border:1px solid rgba(252,185,0,0.3);margin-bottom:24px;">
                                <tr>
                                    <td style="padding:20px;">
                                        <table width="100%" cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Player</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($player_name) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Package</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($package_display) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Date</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($date_display) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Time</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($time_display) . '</span>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:8px 0;">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Location</span><br>
                                                    <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($location_display) . '</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Earnings -->
                            <div style="background:#FCB900;padding:16px 20px;margin-bottom:24px;text-align:center;">
                                <span style="font-size:11px;color:#0A0A0A;text-transform:uppercase;letter-spacing:1px;font-weight:600;">Your Earnings</span><br>
                                <span style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#0A0A0A;">$' . $earnings . '</span>
                            </div>
                            
                            ' . ($needs_confirmation ? '
                            <!-- Action Required -->
                            <div style="background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);padding:16px;margin-bottom:24px;">
                                <p style="color:#EF4444;font-weight:600;margin:0 0 8px;">⚠️ Action Required</p>
                                <p style="color:rgba(255,255,255,0.7);font-size:14px;margin:0;">Contact the parent to confirm the session date, time, and location.</p>
                            </div>
                            ' : '
                            <!-- Confirmed -->
                            <div style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);padding:16px;margin-bottom:24px;">
                                <p style="color:#22C55E;font-weight:600;margin:0 0 8px;">✓ Session Confirmed</p>
                                <p style="color:rgba(255,255,255,0.7);font-size:14px;margin:0;">Make sure to reach out to the parent before the session to introduce yourself.</p>
                            </div>
                            ') . '
                            
                            <!-- CTA -->
                            <a href="' . esc_url($dashboard_url) . '" style="display:block;background:#FCB900;color:#0A0A0A;text-decoration:none;padding:16px 24px;text-align:center;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;letter-spacing:1px;">View Full Details →</a>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="text-align:center;padding-top:32px;">
                            <p style="color:rgba(255,255,255,0.4);font-size:12px;margin:0;">Players Teaching Players</p>
                            <p style="color:rgba(255,255,255,0.3);font-size:11px;margin:8px 0 0;">Questions? Reply to this email or text us.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
    
    /**
     * Generate parent booking confirmation email HTML
     */
    private function get_parent_booking_email($trainer, $booking, $player_name, $date_display, $time_display, $location_display, $package_display) {
        $level_labels = array('pro'=>'MLS Professional','college_d1'=>'NCAA Division 1','college_d2'=>'NCAA Division 2','college_d3'=>'NCAA Division 3','academy'=>'Academy/ECNL','semi_pro'=>'Semi-Professional');
        $trainer_level = $level_labels[$trainer->playing_level] ?? 'Pro Trainer';
        $trainer_photo = $trainer->photo_url ?: 'https://ui-avatars.com/api/?name=' . urlencode($trainer->display_name) . '&size=120&background=FCB900&color=0A0A0A&bold=true';
        $needs_confirmation = empty($booking->session_date);
        $amount_paid = number_format($booking->amount_paid, 2);
        
        return '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;background:#0A0A0A;font-family:Inter,-apple-system,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#0A0A0A;padding:40px 20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;">
                    <!-- Header -->
                    <tr>
                        <td style="text-align:center;padding-bottom:32px;">
                            <div style="font-family:Oswald,sans-serif;font-size:32px;font-weight:700;color:#FCB900;letter-spacing:3px;">PTP</div>
                            <div style="font-size:11px;color:rgba(255,255,255,0.5);letter-spacing:2px;text-transform:uppercase;margin-top:4px;">PRIVATE TRAINING</div>
                        </td>
                    </tr>
                    
                    <!-- Success Badge -->
                    <tr>
                        <td style="text-align:center;padding-bottom:24px;">
                            <span style="display:inline-block;background:#22C55E;color:#fff;font-size:12px;font-weight:600;padding:8px 20px;text-transform:uppercase;letter-spacing:1px;">✓ Booking Confirmed</span>
                        </td>
                    </tr>
                    
                    <!-- Main Card -->
                    <tr>
                        <td style="background:#1a1a1a;border:2px solid rgba(255,255,255,0.1);padding:32px;">
                            <h1 style="font-family:Oswald,sans-serif;font-size:24px;font-weight:700;color:#fff;margin:0 0 8px;text-transform:uppercase;">' . esc_html($player_name) . ' IS LOCKED IN! 🔥</h1>
                            <p style="color:rgba(255,255,255,0.6);font-size:15px;margin:0 0 28px;">Training session booked successfully. Here are your details:</p>
                            
                            <!-- Trainer Card -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(252,185,0,0.08);border:2px solid #FCB900;margin-bottom:24px;">
                                <tr>
                                    <td style="padding:20px;">
                                        <table cellpadding="0" cellspacing="0">
                                            <tr>
                                                <td style="width:80px;vertical-align:top;">
                                                    <img src="' . esc_url($trainer_photo) . '" width="70" height="70" style="border-radius:50%;border:3px solid #FCB900;" alt="">
                                                </td>
                                                <td style="vertical-align:top;padding-left:16px;">
                                                    <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">Your Trainer</span><br>
                                                    <span style="font-family:Oswald,sans-serif;font-size:20px;font-weight:700;color:#fff;">' . esc_html($trainer->display_name) . '</span><br>
                                                    <span style="display:inline-block;background:#FCB900;color:#0A0A0A;font-size:10px;font-weight:600;padding:4px 10px;margin-top:6px;text-transform:uppercase;letter-spacing:1px;">' . esc_html($trainer_level) . '</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Session Details -->
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">📅 Date</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($date_display) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">🕐 Time</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($time_display) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">📍 Location</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($location_display) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;border-bottom:1px solid rgba(255,255,255,0.1);">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">📦 Package</span><br>
                                        <span style="font-size:16px;color:#fff;font-weight:600;">' . esc_html($package_display) . '</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding:12px 0;">
                                        <span style="font-size:10px;color:rgba(255,255,255,0.5);text-transform:uppercase;letter-spacing:1px;">💳 Amount Paid</span><br>
                                        <span style="font-size:16px;color:#FCB900;font-weight:700;">$' . $amount_paid . '</span>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- What\'s Next -->
                            <div style="background:rgba(255,255,255,0.05);padding:20px;margin-bottom:24px;">
                                <p style="font-family:Oswald,sans-serif;font-size:14px;font-weight:600;color:#FCB900;margin:0 0 12px;text-transform:uppercase;letter-spacing:1px;">What\'s Next</p>
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td style="padding:8px 0;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;font-size:12px;font-weight:700;text-align:center;line-height:24px;margin-right:12px;">1</span>
                                            <span style="color:#fff;font-size:14px;">' . esc_html($trainer->display_name) . ' will reach out to confirm details</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;font-size:12px;font-weight:700;text-align:center;line-height:24px;margin-right:12px;">2</span>
                                            <span style="color:#fff;font-size:14px;">Bring water, cleats, and a ball if you have one</span>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:8px 0;">
                                            <span style="display:inline-block;width:24px;height:24px;background:#FCB900;color:#0A0A0A;font-size:12px;font-weight:700;text-align:center;line-height:24px;margin-right:12px;">3</span>
                                            <span style="color:#fff;font-size:14px;">Show up ready to train and have fun!</span>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <p style="color:rgba(255,255,255,0.5);font-size:13px;text-align:center;margin:0;">Questions? Just reply to this email or text us anytime.</p>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="text-align:center;padding-top:32px;">
                            <p style="color:rgba(255,255,255,0.4);font-size:12px;margin:0;">Players Teaching Players</p>
                            <p style="color:rgba(255,255,255,0.3);font-size:11px;margin:8px 0 0;">Teaching what team coaches don\'t.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    }
}

// Initialize
PTP_Unified_Checkout::instance();
