<?php
/**
 * AJAX Handler Class
 * v35.5.0 - Fixed payment confirmation loading issue - returns success immediately
 */

defined('ABSPATH') || exit;

class PTP_Ajax {
    
    /**
     * Rate limiting - prevent abuse
     */
    private static $rate_limits = array(
        'login' => array('limit' => 5, 'window' => 300),           // 5 attempts per 5 minutes
        'register' => array('limit' => 3, 'window' => 300),        // 3 registrations per 5 minutes
        'forgot_password' => array('limit' => 3, 'window' => 300), // 3 requests per 5 minutes
        'add_player' => array('limit' => 10, 'window' => 60),
        'send_message' => array('limit' => 30, 'window' => 60),
        'create_booking' => array('limit' => 5, 'window' => 60),
    );
    
    /**
     * SECURITY: Validate uploaded file with server-side checks
     * Returns true if valid, or error message string if invalid
     * 
     * @param array $file $_FILES array element
     * @param array $allowed_types Array of allowed MIME types
     * @param array $allowed_extensions Array of allowed file extensions
     * @param int $max_size Maximum file size in bytes
     * @return true|string True if valid, error message if invalid
     */
    private static function validate_upload($file, $allowed_types, $allowed_extensions, $max_size = 5242880) {
        // Check upload error
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return 'Invalid upload';
        }
        
        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            return 'Invalid file extension';
        }
        
        // Server-side MIME type detection (don't trust client)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($real_type, $allowed_types)) {
            return 'Invalid file type';
        }
        
        // Validate file size
        if ($file['size'] > $max_size) {
            return 'File too large';
        }
        
        return true;
    }
    
    public static function init() {
        // Public actions
        add_action('wp_ajax_nopriv_ptp_login', array(__CLASS__, 'login'));
        add_action('wp_ajax_nopriv_ptp_register', array(__CLASS__, 'register'));
        add_action('wp_ajax_nopriv_ptp_get_trainers', array(__CLASS__, 'get_trainers'));
        add_action('wp_ajax_nopriv_ptp_get_available_slots', array(__CLASS__, 'get_available_slots'));
        add_action('wp_ajax_nopriv_ptp_submit_application', array(__CLASS__, 'submit_application'));
        add_action('wp_ajax_ptp_submit_application', array(__CLASS__, 'submit_application')); // Also for logged-in users
        
        // Guest booking actions (for guest checkout)
        add_action('wp_ajax_nopriv_ptp_create_booking', array(__CLASS__, 'create_booking'));
        // Note: ptp_create_payment_intent for checkout is handled by PTP_Cart_Checkout_V71
        // This handler is for booking-specific payment intents (legacy)
        add_action('wp_ajax_nopriv_ptp_create_booking_payment_intent', array(__CLASS__, 'create_payment_intent'));
        add_action('wp_ajax_nopriv_ptp_confirm_booking_payment', array(__CLASS__, 'confirm_booking_payment'));
        
        // New checkout flow (guest and logged in)
        add_action('wp_ajax_nopriv_ptp_process_checkout', array(__CLASS__, 'process_checkout'));
        add_action('wp_ajax_ptp_process_checkout', array(__CLASS__, 'process_checkout'));
        add_action('wp_ajax_nopriv_ptp_confirm_checkout_payment', array(__CLASS__, 'confirm_checkout_payment'));
        add_action('wp_ajax_ptp_confirm_checkout_payment', array(__CLASS__, 'confirm_checkout_payment'));
        
        // Logged in actions
        add_action('wp_ajax_ptp_get_trainers', array(__CLASS__, 'get_trainers'));
        add_action('wp_ajax_ptp_get_available_slots', array(__CLASS__, 'get_available_slots'));
        add_action('wp_ajax_ptp_create_booking', array(__CLASS__, 'create_booking'));
        add_action('wp_ajax_ptp_cancel_booking', array(__CLASS__, 'cancel_booking'));
        add_action('wp_ajax_ptp_confirm_session', array(__CLASS__, 'confirm_session'));
        add_action('wp_ajax_ptp_add_player', array(__CLASS__, 'add_player'));
        add_action('wp_ajax_ptp_update_player', array(__CLASS__, 'update_player'));
        add_action('wp_ajax_ptp_delete_player', array(__CLASS__, 'delete_player'));
        
        // Cart actions - remove training items
        add_action('wp_ajax_ptp_remove_training_item', array(__CLASS__, 'remove_training_item'));
        add_action('wp_ajax_nopriv_ptp_remove_training_item', array(__CLASS__, 'remove_training_item'));
        add_action('wp_ajax_ptp_clear_training_session', array(__CLASS__, 'clear_training_session'));
        add_action('wp_ajax_nopriv_ptp_clear_training_session', array(__CLASS__, 'clear_training_session'));
        
        add_action('wp_ajax_ptp_get_conversations', array(__CLASS__, 'get_conversations'));
        add_action('wp_ajax_ptp_get_messages', array(__CLASS__, 'get_messages'));
        add_action('wp_ajax_ptp_get_new_messages', array(__CLASS__, 'get_new_messages'));
        add_action('wp_ajax_ptp_send_message', array(__CLASS__, 'send_message'));
        add_action('wp_ajax_ptp_start_conversation', array(__CLASS__, 'start_conversation'));
        add_action('wp_ajax_ptp_update_profile', array(__CLASS__, 'update_profile'));
        add_action('wp_ajax_ptp_update_trainer_profile', array(__CLASS__, 'update_trainer_profile'));
        add_action('wp_ajax_ptp_upload_trainer_photo', array(__CLASS__, 'upload_trainer_photo'));
        add_action('wp_ajax_ptp_upload_trainer_cover_photo', array(__CLASS__, 'upload_trainer_cover_photo'));
        add_action('wp_ajax_ptp_complete_onboarding', array(__CLASS__, 'complete_onboarding'));
        add_action('wp_ajax_ptp_save_trainer_onboarding_v60', array(__CLASS__, 'save_trainer_onboarding_v60'));
        add_action('wp_ajax_ptp_autosave_profile', array(__CLASS__, 'autosave_profile'));
        add_action('wp_ajax_ptp_update_password', array(__CLASS__, 'update_password'));
        add_action('wp_ajax_ptp_update_notifications', array(__CLASS__, 'update_notifications'));
        add_action('wp_ajax_ptp_save_notification_prefs', array(__CLASS__, 'save_notification_prefs'));
        add_action('wp_ajax_ptp_update_availability', array(__CLASS__, 'update_availability'));
        add_action('wp_ajax_ptp_save_availability', array(__CLASS__, 'save_availability_v92'));
        add_action('wp_ajax_ptp_update_trainer_rate', array(__CLASS__, 'update_trainer_rate'));
        add_action('wp_ajax_ptp_request_payout', array(__CLASS__, 'request_payout'));
        add_action('wp_ajax_ptp_update_trainer_profile_quick', array(__CLASS__, 'update_trainer_profile_quick'));
        add_action('wp_ajax_ptp_update_trainer_profile_full', array(__CLASS__, 'update_trainer_profile_full')); // v116
        add_action('wp_ajax_ptp_submit_review', array(__CLASS__, 'submit_review'));
        
        // Payment actions (booking-specific, checkout handled by PTP_Cart_Checkout_V71)
        add_action('wp_ajax_ptp_create_booking_payment_intent', array(__CLASS__, 'create_payment_intent'));
        add_action('wp_ajax_ptp_confirm_booking_payment', array(__CLASS__, 'confirm_booking_payment'));
        
        // Package credits - book session using prepaid credits
        add_action('wp_ajax_ptp_book_with_credits', array(__CLASS__, 'book_with_credits'));
        add_action('wp_ajax_ptp_get_package_credits', array(__CLASS__, 'get_package_credits'));
        
        // Stripe Connect actions
        add_action('wp_ajax_ptp_create_stripe_connect_account', array(__CLASS__, 'create_stripe_connect_account'));
        add_action('wp_ajax_ptp_get_stripe_dashboard_link', array(__CLASS__, 'get_stripe_dashboard_link'));
        
        // Trainer geocoding (public - auto-saves trainer coordinates)
        add_action('wp_ajax_nopriv_ptp_save_trainer_coords', array(__CLASS__, 'save_trainer_coords'));
        add_action('wp_ajax_ptp_save_trainer_coords', array(__CLASS__, 'save_trainer_coords'));
        
        // Enhanced availability management
        add_action('wp_ajax_ptp_quick_update_availability', array(__CLASS__, 'quick_update_availability'));
        add_action('wp_ajax_ptp_toggle_day_availability', array(__CLASS__, 'toggle_day_availability'));
        add_action('wp_ajax_ptp_add_availability_exception', array(__CLASS__, 'add_availability_exception'));
        add_action('wp_ajax_ptp_remove_availability_exception', array(__CLASS__, 'remove_availability_exception'));
        
        // Fallback route for new schedule save (forwards to PTP_Availability class)
        add_action('wp_ajax_ptp_save_trainer_schedule', array(__CLASS__, 'save_trainer_schedule_fallback'));
        
        // Public messaging (send message to trainer from public profile)
        add_action('wp_ajax_ptp_send_public_message', array(__CLASS__, 'send_public_message'));
        add_action('wp_ajax_nopriv_ptp_send_public_message', array(__CLASS__, 'send_public_message_guest'));
        
        // Trainer availability calendar (public - for booking calendar)
        add_action('wp_ajax_nopriv_ptp_get_trainer_availability', array(__CLASS__, 'get_trainer_availability'));
        add_action('wp_ajax_ptp_get_trainer_availability', array(__CLASS__, 'get_trainer_availability'));
        
        // Training locations management
        add_action('wp_ajax_ptp_update_training_locations', array(__CLASS__, 'update_training_locations'));
        add_action('wp_ajax_ptp_get_training_locations', array(__CLASS__, 'get_training_locations'));
        
        // Profile Editor v60 handlers
        add_action('wp_ajax_ptp_save_trainer_profile', array(__CLASS__, 'save_trainer_profile'));
        add_action('wp_ajax_ptp_upload_gallery_image', array(__CLASS__, 'upload_gallery_image'));
        
        // Cart management
        add_action('wp_ajax_ptp_remove_cart_item', array(__CLASS__, 'remove_cart_item'));
        add_action('wp_ajax_nopriv_ptp_remove_cart_item', array(__CLASS__, 'remove_cart_item'));
        add_action('wp_ajax_ptp_remove_training_from_cart', array(__CLASS__, 'remove_training_from_cart'));
        add_action('wp_ajax_nopriv_ptp_remove_training_from_cart', array(__CLASS__, 'remove_training_from_cart'));
        
        // Jersey upsell toggle (v90)
        add_action('wp_ajax_ptp_toggle_jersey_upsell', array(__CLASS__, 'toggle_jersey_upsell'));
        add_action('wp_ajax_nopriv_ptp_toggle_jersey_upsell', array(__CLASS__, 'toggle_jersey_upsell'));
        
        // Camp add to cart (v117)
        add_action('wp_ajax_ptp_add_camp_to_cart', array(__CLASS__, 'add_camp_to_cart'));
        add_action('wp_ajax_nopriv_ptp_add_camp_to_cart', array(__CLASS__, 'add_camp_to_cart'));
        
        // v114.1: Order diagnostic endpoint
        add_action('wp_ajax_ptp_check_order_status', array(__CLASS__, 'check_order_status'));
        add_action('wp_ajax_nopriv_ptp_check_order_status', array(__CLASS__, 'check_order_status'));
    }
    
    /**
     * Verify nonce with detailed error
     */
    private static function verify_nonce() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce)) {
            error_log('PTP AJAX: Missing nonce');
            wp_send_json_error(array('message' => 'Security token missing. Please refresh the page.'));
            exit;
        }
        if (!wp_verify_nonce($nonce, 'ptp_nonce')) {
            error_log('PTP AJAX: Invalid nonce');
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'));
            exit;
        }
    }
    
    /**
     * Require login with redirect URL
     */
    private static function require_login() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => 'Please log in to continue',
                'login_url' => wp_login_url(),
                'code' => 'not_logged_in'
            ));
            exit;
        }
    }
    
    /**
     * Sanitize and validate integer - checks POST first, then GET
     */
    private static function get_int($key, $default = 0, $min = null, $max = null) {
        // Check POST first, then GET for frontend compatibility
        if (isset($_POST[$key])) {
            $value = intval($_POST[$key]);
        } elseif (isset($_GET[$key])) {
            $value = intval($_GET[$key]);
        } else {
            $value = $default;
        }
        if ($min !== null && $value < $min) $value = $min;
        if ($max !== null && $value > $max) $value = $max;
        return $value;
    }
    
    /**
     * Sanitize and validate string - checks POST first, then GET
     */
    private static function get_string($key, $default = '', $max_length = 1000) {
        // Check POST first, then GET for frontend compatibility
        if (isset($_POST[$key])) {
            $value = sanitize_text_field($_POST[$key]);
        } elseif (isset($_GET[$key])) {
            $value = sanitize_text_field($_GET[$key]);
        } else {
            $value = $default;
        }
        if (strlen($value) > $max_length) {
            $value = substr($value, 0, $max_length);
        }
        return $value;
    }
    
    /**
     * Sanitize and validate email
     */
    private static function get_email($key, $default = '') {
        $value = isset($_POST[$key]) ? sanitize_email($_POST[$key]) : $default;
        return is_email($value) ? $value : $default;
    }
    
    /**
     * Validate time format (HH:MM)
     */
    private static function validate_time($time, $default = '16:00') {
        if (empty($time)) return $default;
        $time = substr(trim($time), 0, 5);
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            return $default;
        }
        return $time;
    }
    
    /**
     * Validate date format (YYYY-MM-DD)
     */
    private static function validate_date($date) {
        if (empty($date)) return false;
        $date = trim($date);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        $parts = explode('-', $date);
        return checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]) ? $date : false;
    }
    
    /**
     * Check rate limit
     */
    private static function check_rate_limit($action) {
        if (!isset(self::$rate_limits[$action])) return true;
        
        $user_id = get_current_user_id();
        $ip = self::get_client_ip();
        $key = 'ptp_rate_' . $action . '_' . ($user_id ?: md5($ip));
        
        $data = get_transient($key);
        $limit = self::$rate_limits[$action]['limit'];
        $window = self::$rate_limits[$action]['window'];
        
        if ($data === false) {
            set_transient($key, array('count' => 1, 'start' => time()), $window);
            return true;
        }
        
        if ($data['count'] >= $limit) {
            return false;
        }
        
        $data['count']++;
        set_transient($key, $data, $window - (time() - $data['start']));
        return true;
    }
    
    /**
     * Get client IP safely
     * SECURITY: Only trust proxy headers if behind known proxy (configurable)
     */
    private static function get_client_ip() {
        // Check if we should trust proxy headers (set this in wp-config.php if behind load balancer)
        $trust_proxy = defined('PTP_TRUST_PROXY_HEADERS') && PTP_TRUST_PROXY_HEADERS;
        
        $ip = '';
        
        if ($trust_proxy) {
            // Only trust these headers if explicitly configured to do so
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                // Take the first IP (client's real IP) from the chain
                $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $ip = trim($forwarded[0]);
            } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
                $ip = $_SERVER['HTTP_X_REAL_IP'];
            }
        }
        
        // Fall back to REMOTE_ADDR (always available and trustworthy)
        if (empty($ip) && !empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
    }
    
    /**
     * Safe JSON response with logging
     */
    private static function send_error($message, $code = 'error', $log = true) {
        if ($log) {
            error_log('PTP AJAX Error [' . $code . ']: ' . $message);
        }
        wp_send_json_error(array('message' => $message, 'code' => $code));
        exit;
    }
    
    /**
     * Ensure database tables exist
     */
    private static function ensure_tables() {
        if (class_exists('PTP_Database')) {
            PTP_Database::create_tables();
        }
    }
    
    public static function login() {
        self::verify_nonce();
        
        // Rate limit login attempts to prevent brute force
        if (!self::check_rate_limit('login')) {
            wp_send_json_error(array('message' => 'Too many login attempts. Please wait 5 minutes before trying again.'));
        }
        
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = !empty($_POST['remember']);
        
        if (empty($email) || empty($password)) {
            wp_send_json_error(array('message' => 'Please enter email and password'));
        }
        
        // Prevent DoS with extremely long passwords
        if (strlen($password) > 256) {
            wp_send_json_error(array('message' => 'Invalid password'));
        }
        
        $user = wp_authenticate($email, $password);
        
        if (is_wp_error($user)) {
            wp_send_json_error(array('message' => 'Invalid email or password'));
        }
        
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, $remember);
        
        // SECURITY: Regenerate session ID to prevent session fixation attacks
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        
        wp_send_json_success(array(
            'message' => 'Login successful',
            'redirect' => PTP_User::get_dashboard_url($user->ID),
        ));
    }
    
    public static function register() {
        self::verify_nonce();
        
        // Rate limit registrations to prevent spam
        if (!self::check_rate_limit('register')) {
            wp_send_json_error(array('message' => 'Too many registration attempts. Please wait 5 minutes before trying again.'));
        }
        
        $name = sanitize_text_field($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $password = $_POST['password'] ?? '';
        $user_type = sanitize_text_field($_POST['user_type'] ?? 'parent');
        
        if (empty($name) || empty($email) || empty($password)) {
            wp_send_json_error(array('message' => 'Please fill in all required fields'));
        }
        
        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address'));
        }
        
        if (email_exists($email)) {
            wp_send_json_error(array('message' => 'An account with this email already exists'));
        }
        
        if (strlen($password) < 8) {
            wp_send_json_error(array('message' => 'Password must be at least 8 characters'));
        }
        
        if (strlen($password) > 256) {
            wp_send_json_error(array('message' => 'Password is too long'));
        }
        
        // SECURITY: Enforce password complexity
        if (!preg_match('/[a-z]/', $password)) {
            wp_send_json_error(array('message' => 'Password must contain at least one lowercase letter'));
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            wp_send_json_error(array('message' => 'Password must contain at least one uppercase letter'));
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            wp_send_json_error(array('message' => 'Password must contain at least one number'));
        }
        
        $name_parts = explode(' ', $name, 2);
        $user_id = PTP_User::create_user($email, $password, array(
            'first_name' => $name_parts[0],
            'last_name' => $name_parts[1] ?? '',
            'display_name' => $name,
            'phone' => $phone,
        ));
        
        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => $user_id->get_error_message()));
        }
        
        // Create parent profile
        if ($user_type === 'parent') {
            PTP_Parent::create($user_id, array(
                'display_name' => $name,
                'phone' => $phone,
            ));
        }
        
        // Log in the user
        PTP_User::login_user($user_id);
        
        wp_send_json_success(array(
            'message' => 'Account created successfully',
            'redirect' => $user_type === 'parent' ? home_url('/my-training/') : home_url('/become-a-trainer/'),
        ));
    }
    
    /**
     * Enhanced trainer search with comprehensive filters
     * Supports: location/radius, specialties, price range, rating, availability, playing level
     */
    public static function get_trainers() {
        global $wpdb;
        
        // Check if table exists
        $table_name = $wpdb->prefix . 'ptp_trainers';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
        
        if (!$table_exists) {
            // Try to create tables
            if (class_exists('PTP_Database')) {
                PTP_Database::create_tables();
            }
            wp_send_json_success(array(
                'trainers' => array(),
                'total' => 0,
                'message' => 'Database tables being initialized. Please refresh the page.'
            ));
            return;
        }

        // Parse filter parameters - use $_REQUEST to support both GET and POST
        $search = sanitize_text_field($_REQUEST['search'] ?? '');
        $specialty = sanitize_text_field($_REQUEST['specialty'] ?? '');
        $sort = sanitize_text_field($_REQUEST['sort'] ?? 'featured');
        $zip = sanitize_text_field($_REQUEST['zip'] ?? '');
        $radius = intval($_REQUEST['radius'] ?? 25);
        $min_price = floatval($_REQUEST['min_price'] ?? 0);
        $max_price = floatval($_REQUEST['max_price'] ?? 500);
        $min_rating = floatval($_REQUEST['min_rating'] ?? 0);
        $playing_level = sanitize_text_field($_REQUEST['playing_level'] ?? '');
        $featured_only = !empty($_REQUEST['featured_only']);
        $available_date = sanitize_text_field($_REQUEST['available_date'] ?? '');
        $page = max(1, intval($_REQUEST['page'] ?? 1));
        $per_page = min(50, max(6, intval($_REQUEST['per_page'] ?? 12)));
        $lat = floatval($_REQUEST['lat'] ?? 0);
        $lng = floatval($_REQUEST['lng'] ?? 0);

        // Check for cached response (only for default queries with no filters)
        $is_default_query = empty($search) && empty($specialty) && empty($zip) && 
                           $min_price == 0 && $max_price == 500 && $min_rating == 0 && 
                           empty($playing_level) && !$featured_only && empty($available_date) &&
                           $page == 1 && $lat == 0 && $lng == 0;
        
        if ($is_default_query) {
            $cache_key = 'ptp_trainers_default_' . $sort . '_' . $per_page;
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                wp_send_json_success($cached);
                return;
            }
        }

        // Clamp radius between 5-100 miles
        $radius = max(5, min(100, $radius));

        // Build query
        $where = array("t.status = 'active'");
        $params = array();
        $select_extra = '';
        $having = array();

        // Text search
        if (!empty($search)) {
            $where[] = "(t.display_name LIKE %s OR t.headline LIKE %s OR t.bio LIKE %s OR t.location LIKE %s OR t.college LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        // Specialty filter
        if (!empty($specialty)) {
            $where[] = "t.specialties LIKE %s";
            $params[] = '%' . $wpdb->esc_like($specialty) . '%';
        }

        // Price range
        if ($min_price > 0) {
            $where[] = "t.hourly_rate >= %f";
            $params[] = $min_price;
        }
        if ($max_price < 500) {
            $where[] = "t.hourly_rate <= %f";
            $params[] = $max_price;
        }

        // Rating filter
        if ($min_rating > 0) {
            $where[] = "t.average_rating >= %f";
            $params[] = $min_rating;
        }

        // Playing level filter
        if (!empty($playing_level)) {
            $levels = array_map('sanitize_text_field', explode(',', $playing_level));
            $placeholders = implode(',', array_fill(0, count($levels), '%s'));
            $where[] = "t.playing_level IN ($placeholders)";
            $params = array_merge($params, $levels);
        }

        // Featured only
        if ($featured_only) {
            $where[] = "t.is_featured = 1";
        }

        // Location/radius search - accept direct lat/lng OR zip code
        $user_lat = null;
        $user_lng = null;
        
        // First check for direct lat/lng coordinates (from geolocation)
        if (!empty($_REQUEST['lat']) && !empty($_REQUEST['lng'])) {
            $user_lat = floatval($_REQUEST['lat']);
            $user_lng = floatval($_REQUEST['lng']);
        }
        // Fall back to ZIP code geocoding
        elseif (!empty($zip) && class_exists('PTP_Geocoding')) {
            $geo = PTP_Geocoding::geocode($zip);
            if (!empty($geo['success']) && !empty($geo['latitude'])) {
                $user_lat = floatval($geo['latitude']);
                $user_lng = floatval($geo['longitude']);
            }
        }
        
        // If we have user coordinates, add distance calculation
        if ($user_lat !== null && $user_lng !== null) {
            // Add distance calculation to select - use COALESCE to handle trainers without coords
            $select_extra = ", CASE 
                WHEN t.latitude IS NOT NULL AND t.longitude IS NOT NULL THEN
                    (3959 * acos(
                        LEAST(1.0, GREATEST(-1.0,
                            cos(radians(%f)) * cos(radians(t.latitude)) *
                            cos(radians(t.longitude) - radians(%f)) +
                            sin(radians(%f)) * sin(radians(t.latitude))
                        ))
                    ))
                ELSE 9999
            END AS distance";

            // Prepend lat/lng params
            array_unshift($params, $user_lat, $user_lng, $user_lat);

            // Only filter by radius if explicitly set and not maximum
            if ($radius < 100) {
                $having[] = "distance <= %f";
                $params[] = $radius;
                // When filtering by radius, require coordinates
                $where[] = "t.latitude IS NOT NULL AND t.longitude IS NOT NULL";
            }
            // If radius is 100 (max), show all trainers but sorted by distance
        }

        // Availability filter - check if trainer has slots on specified date
        if (!empty($available_date) && class_exists('PTP_Availability')) {
            $date = self::validate_date($available_date);
            if ($date) {
                $day_of_week = date('w', strtotime($date));
                $where[] = "EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}ptp_availability a
                    WHERE a.trainer_id = t.id
                    AND a.day_of_week = %d
                    AND a.is_active = 1
                )";
                $params[] = $day_of_week;

                // Exclude trainers with blocked dates
                $where[] = "NOT EXISTS (
                    SELECT 1 FROM {$wpdb->prefix}ptp_availability_exceptions ae
                    WHERE ae.trainer_id = t.id
                    AND ae.exception_date = %s
                    AND ae.is_available = 0
                )";
                $params[] = $date;
            }
        }

        // Build ORDER BY
        // If user has location and no explicit sort preference, default to distance
        if ($sort === 'distance' || $sort === 'recommended' || $sort === 'featured') {
            if ($user_lat !== null && $user_lng !== null) {
                $orderby = 'distance ASC, t.is_featured DESC, t.sort_order ASC, t.average_rating DESC';
            } else {
                $orderby = 't.is_featured DESC, t.sort_order ASC, t.average_rating DESC';
            }
        } else {
            $orderby = 't.is_featured DESC, t.sort_order ASC, t.average_rating DESC';
        }
        
        switch ($sort) {
            case 'rating':
                $orderby = 't.average_rating DESC, t.review_count DESC';
                break;
            case 'price_low':
                $orderby = 't.hourly_rate ASC, t.average_rating DESC';
                break;
            case 'price_high':
                $orderby = 't.hourly_rate DESC, t.average_rating DESC';
                break;
            case 'reviews':
                $orderby = 't.review_count DESC, t.average_rating DESC';
                break;
            case 'newest':
                $orderby = 't.id DESC';
                break;
            // distance and featured/recommended already handled above
        }

        // Build WHERE clause
        $where_clause = implode(' AND ', $where);

        // Build HAVING clause
        $having_clause = !empty($having) ? 'HAVING ' . implode(' AND ', $having) : '';

        // Count total results (for pagination)
        $count_sql = "SELECT COUNT(DISTINCT t.id) FROM {$wpdb->prefix}ptp_trainers t WHERE $where_clause";
        if (!empty($having)) {
            // For HAVING clause, we need a subquery
            $count_sql = "SELECT COUNT(*) FROM (
                SELECT t.id $select_extra
                FROM {$wpdb->prefix}ptp_trainers t
                WHERE $where_clause
                GROUP BY t.id
                $having_clause
            ) AS filtered";
        }

        // Get params for count query (without pagination)
        $count_params = $params;
        
        // Handle empty params case - wpdb->prepare requires at least one param
        if (empty($count_params)) {
            $total = $wpdb->get_var($count_sql);
        } else {
            $total = $wpdb->get_var($wpdb->prepare($count_sql, $count_params));
        }
        
        // Ensure total is a valid number
        $total = intval($total) ?: 0;

        // Calculate pagination
        $offset = ($page - 1) * $per_page;
        $total_pages = $total > 0 ? ceil($total / $per_page) : 0;

        // Main query
        $sql = "SELECT t.* $select_extra
                FROM {$wpdb->prefix}ptp_trainers t
                WHERE $where_clause
                GROUP BY t.id
                $having_clause
                ORDER BY $orderby
                LIMIT %d OFFSET %d";

        $params[] = $per_page;
        $params[] = $offset;

        $trainers = $wpdb->get_results($wpdb->prepare($sql, $params));
        
        // Handle query errors
        if ($wpdb->last_error) {
            error_log('PTP get_trainers SQL error: ' . $wpdb->last_error);
            $trainers = array();
        }

        // Format trainers for response
        $level_labels = array(
            'pro' => 'PRO',
            'college_d1' => 'D1',
            'college_d2' => 'D2',
            'college_d3' => 'D3',
            'academy' => 'ACADEMY',
            'semi_pro' => 'SEMI-PRO'
        );

        $formatted = array();
        foreach ($trainers as $t) {
            // Try to get coordinates - first from trainer, then from first training location
            $lat = $t->latitude ? (float)$t->latitude : null;
            $lng = $t->longitude ? (float)$t->longitude : null;
            
            // If no trainer coordinates, try to get from training locations
            if (!$lat && !empty($t->training_locations)) {
                $locations = json_decode($t->training_locations, true);
                if (is_array($locations) && !empty($locations)) {
                    foreach ($locations as $loc) {
                        if (!empty($loc['lat']) && !empty($loc['lng'])) {
                            $lat = (float)$loc['lat'];
                            $lng = (float)$loc['lng'];
                            break;
                        }
                    }
                }
            }
            
            // If still no coordinates and we have a location string, try to geocode
            if (!$lat && !empty($t->location) && class_exists('PTP_Geocoding')) {
                $geo = PTP_Geocoding::geocode($t->location);
                if (!empty($geo['success']) && !empty($geo['latitude'])) {
                    $lat = (float)$geo['latitude'];
                    $lng = (float)$geo['longitude'];
                    
                    // Save for future use
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_trainers',
                        array('latitude' => $lat, 'longitude' => $lng),
                        array('id' => $t->id)
                    );
                }
            }
            
            // Ensure trainer has a slug - generate one if missing
            $slug = $t->slug;
            if (empty($slug)) {
                $slug = sanitize_title($t->display_name ?: 'trainer-' . $t->id);
                // Save the generated slug
                $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    array('slug' => $slug),
                    array('id' => $t->id)
                );
            }
            
            $formatted[] = array(
                'id' => (int)$t->id,
                'name' => $t->display_name,
                'slug' => $slug,
                'photo' => $t->photo_url ?: (class_exists('PTP_Images') ? PTP_Images::avatar($t->display_name, 400) : ''),
                'photo_thumb' => $t->photo_url ?: (class_exists('PTP_Images') ? PTP_Images::avatar($t->display_name, 200) : ''),
                'photo_large' => $t->photo_url ?: (class_exists('PTP_Images') ? PTP_Images::avatar($t->display_name, 800) : ''),
                'has_photo' => !empty($t->photo_url),
                'headline' => $t->headline ?: ($t->college ?: 'Private Trainer'),
                'bio' => wp_trim_words($t->bio ?: '', 20),
                'rate' => (int)($t->hourly_rate ?: 70),
                'rating' => $t->average_rating ? round((float)$t->average_rating, 1) : 5.0,
                'reviews' => (int)($t->review_count ?: 0),
                'happy_student_score' => (int)($t->happy_student_score ?? 97),
                'is_supercoach' => !empty($t->is_supercoach),
                'lat' => $lat,
                'lng' => $lng,
                'location' => $t->location ?: 'Philadelphia Area',
                'city' => $t->city ?: '',
                'state' => $t->state ?: '',
                'featured' => !empty($t->is_featured),
                'verified' => !empty($t->is_verified),
                'level' => isset($level_labels[$t->playing_level]) ? $level_labels[$t->playing_level] : '',
                'level_raw' => $t->playing_level ?: '',
                'college' => $t->college ?: '',
                'specialties' => $t->specialties ? explode(',', $t->specialties) : array(),
                'distance' => isset($t->distance) ? round($t->distance, 1) : null,
                'profile_url' => home_url('/trainer/' . $slug . '/'),
                'training_locations' => !empty($t->training_locations) ? json_decode($t->training_locations, true) : array(),
            );
        }

        $response = array(
            'trainers' => $formatted,
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int)$total_pages,
            'has_more' => $page < $total_pages,
            'filters_applied' => array(
                'search' => $search,
                'specialty' => $specialty,
                'zip' => $zip,
                'radius' => $radius,
                'min_price' => $min_price,
                'max_price' => $max_price,
                'min_rating' => $min_rating,
                'playing_level' => $playing_level,
                'featured_only' => $featured_only,
                'available_date' => $available_date,
            ),
            'user_location' => $user_lat ? array('lat' => $user_lat, 'lng' => $user_lng) : null,
        );
        
        // Cache default queries for 5 minutes
        if ($is_default_query && !empty($formatted)) {
            $cache_key = 'ptp_trainers_default_' . $sort . '_' . $per_page;
            set_transient($cache_key, $response, 5 * MINUTE_IN_SECONDS);
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Get available time slots for a trainer on a specific date
     * BULLETPROOF: Validation, caching, and graceful degradation
     */
    public static function get_available_slots() {
        try {
            $trainer_id = self::get_int('trainer_id', 0, 1);
            $date = self::get_string('date', '', 10);
            
            // Validate trainer ID
            if (!$trainer_id || $trainer_id < 1) {
                wp_send_json_error(array('message' => 'Invalid trainer', 'slots' => array()));
                return;
            }
            
            // Validate date
            $date = self::validate_date($date);
            if (!$date) {
                wp_send_json_error(array('message' => 'Invalid date format', 'slots' => array()));
                return;
            }
            
            // Don't allow dates too far in the past or future
            $date_ts = strtotime($date);
            $today_ts = strtotime('today');
            $max_future = strtotime('+90 days');
            
            if ($date_ts < $today_ts) {
                wp_send_json_success(array('slots' => array(), 'message' => 'Date is in the past'));
                return;
            }
            
            if ($date_ts > $max_future) {
                wp_send_json_success(array('slots' => array(), 'message' => 'Date is too far in the future'));
                return;
            }
            
            // Verify trainer exists and is active
            $trainer = PTP_Trainer::get($trainer_id);
            if (!$trainer || $trainer->status !== 'active') {
                wp_send_json_error(array('message' => 'Trainer not available', 'slots' => array()));
                return;
            }
            
            // Try cache first
            $cache_key = 'ptp_slots_' . $trainer_id . '_' . $date;
            $cached_slots = wp_cache_get($cache_key, 'ptp');
            if ($cached_slots !== false) {
                wp_send_json_success(array('slots' => $cached_slots));
                return;
            }
            
            // Ensure availability class exists
            if (!class_exists('PTP_Availability')) {
                wp_send_json_success(array('slots' => array(), 'message' => 'Availability system not ready'));
                return;
            }
            
            $slots = PTP_Availability::get_available_slots($trainer_id, $date);
            
            // Ensure we return an array
            if (!is_array($slots)) {
                $slots = array();
            }
            
            // Cache for 5 minutes
            wp_cache_set($cache_key, $slots, 'ptp', 300);
            
            wp_send_json_success(array('slots' => $slots));
            
        } catch (Exception $e) {
            error_log('PTP Get Slots Exception: ' . $e->getMessage());
            wp_send_json_success(array('slots' => array(), 'message' => 'Could not load times'));
        }
    }
    
    public static function create_booking() {
        self::verify_nonce();
        
        // Quick repair tables if needed
        PTP_Database::quick_repair();
        
        $is_guest = !empty($_POST['is_guest']);
        $parent = null;
        $player_id = 0;
        $user_id = 0;
        
        if ($is_guest) {
            // Guest booking flow
            $guest_email = sanitize_email($_POST['guest_email'] ?? '');
            $guest_first_name = sanitize_text_field($_POST['guest_first_name'] ?? '');
            $guest_last_name = sanitize_text_field($_POST['guest_last_name'] ?? '');
            $guest_phone = sanitize_text_field($_POST['guest_phone'] ?? '');
            
            if (empty($guest_email) || !is_email($guest_email)) {
                wp_send_json_error(array('message' => 'Valid email address is required'));
            }
            
            if (empty($guest_first_name) || empty($guest_last_name)) {
                wp_send_json_error(array('message' => 'Name is required'));
            }
            
            // Check if user exists
            $existing_user = get_user_by('email', $guest_email);
            
            if ($existing_user) {
                $user_id = $existing_user->ID;
                $parent = PTP_Parent::get_by_user_id($user_id);
                
                // Create parent profile if missing
                if (!$parent) {
                    $parent_id = PTP_Parent::create($user_id, array(
                        'display_name' => $guest_first_name . ' ' . $guest_last_name,
                        'phone' => $guest_phone,
                    ));
                    $parent = PTP_Parent::get($parent_id);
                }
            } else {
                // Check if they want to create an account
                $create_account = !empty($_POST['create_account']);
                $password = isset($_POST['password']) ? $_POST['password'] : '';
                
                if ($create_account && !empty($password)) {
                    if (strlen($password) < 8) {
                        wp_send_json_error(array('message' => 'Password must be at least 8 characters'));
                    }
                    
                    // Create full user account
                    $user_id = wp_create_user($guest_email, $password, $guest_email);
                    
                    if (is_wp_error($user_id)) {
                        wp_send_json_error(array('message' => 'Failed to create account: ' . $user_id->get_error_message()));
                    }
                    
                    // Update user info
                    wp_update_user(array(
                        'ID' => $user_id,
                        'first_name' => $guest_first_name,
                        'last_name' => $guest_last_name,
                        'display_name' => $guest_first_name . ' ' . $guest_last_name,
                    ));
                    
                    // Assign parent role
                    $user = new WP_User($user_id);
                    $user->set_role('ptp_parent');
                    
                } else {
                    // Create guest user (no password)
                    $random_password = wp_generate_password(20, true, true);
                    $user_id = wp_create_user($guest_email, $random_password, $guest_email);
                    
                    if (is_wp_error($user_id)) {
                        wp_send_json_error(array('message' => 'Failed to process booking: ' . $user_id->get_error_message()));
                    }
                    
                    // Update user info
                    wp_update_user(array(
                        'ID' => $user_id,
                        'first_name' => $guest_first_name,
                        'last_name' => $guest_last_name,
                        'display_name' => $guest_first_name . ' ' . $guest_last_name,
                    ));
                    
                    // Assign parent role
                    $user = new WP_User($user_id);
                    $user->set_role('ptp_parent');
                    
                    // Mark as guest account
                    update_user_meta($user_id, 'ptp_guest_account', true);
                }
                
                // Create parent profile
                $parent_id = PTP_Parent::create($user_id, array(
                    'display_name' => $guest_first_name . ' ' . $guest_last_name,
                    'phone' => $guest_phone,
                ));
                $parent = PTP_Parent::get($parent_id);
            }
            
            // Create the player
            $player_first_name = sanitize_text_field($_POST['player_first_name'] ?? '');
            $player_last_name = sanitize_text_field($_POST['player_last_name'] ?? '');
            $player_age = intval($_POST['player_age'] ?? 0);
            $player_skill = sanitize_text_field($_POST['player_skill'] ?? 'beginner');
            $player_goals = sanitize_textarea_field($_POST['player_goals'] ?? '');
            
            if (empty($player_first_name)) {
                wp_send_json_error(array('message' => 'Player name is required'));
            }
            
            global $wpdb;
            // v118.2: Include first_name and last_name fields
            $wpdb->insert($wpdb->prefix . 'ptp_players', array(
                'parent_id' => $parent->id,
                'name' => $player_first_name . ' ' . $player_last_name,
                'first_name' => $player_first_name,
                'last_name' => $player_last_name,
                'age' => $player_age,
                'skill_level' => $player_skill,
                'goals' => $player_goals,
                'created_at' => current_time('mysql'),
            ));
            $player_id = $wpdb->insert_id;
            
        } else {
            // Logged in user booking
            self::require_login();
            
            $user_id = get_current_user_id();
            $parent = PTP_Parent::get_by_user_id($user_id);
            
            // Auto-create parent profile if missing
            if (!$parent) {
                $user = wp_get_current_user();
                $parent_id = PTP_Parent::create($user_id, array(
                    'display_name' => $user->display_name ?: $user->user_login ?: 'Parent',
                ));
                
                if (is_wp_error($parent_id)) {
                    wp_send_json_error(array('message' => 'Could not create parent profile: ' . $parent_id->get_error_message()));
                }
                
                $parent = PTP_Parent::get($parent_id);
                
                if (!$parent) {
                    wp_send_json_error(array('message' => 'Parent profile creation failed'));
                }
            }
            
            $player_id = intval($_POST['player_id'] ?? 0);
            
            if (!$player_id) {
                wp_send_json_error(array('message' => 'No player selected. Please select or add a player first.'));
            }
            
            // Validate player belongs to parent
            if (!PTP_Player::belongs_to_parent($player_id, $parent->id)) {
                error_log("PTP Booking: Player $player_id does not belong to parent {$parent->id}");
                wp_send_json_error(array('message' => 'Invalid player selected. Please refresh the page and try again.'));
            }
            
            // Update parent phone if provided
            $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
            if (!empty($phone) && $parent) {
                global $wpdb;
                $wpdb->update(
                    $wpdb->prefix . 'ptp_parents',
                    array('phone' => $phone),
                    array('id' => $parent->id),
                    array('%s'),
                    array('%d')
                );
            }
        }
        
        $data = array(
            'trainer_id' => intval($_POST['trainer_id'] ?? 0),
            'parent_id' => $parent->id,
            'player_id' => $player_id,
            'session_date' => sanitize_text_field($_POST['date'] ?? ''),
            'start_time' => sanitize_text_field($_POST['time'] ?? ''),
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'notes' => sanitize_textarea_field($_POST['notes'] ?? ''),
        );
        
        // Handle recurring bookings (only for logged-in users)
        $recurring = sanitize_text_field($_POST['recurring'] ?? 'none');
        $recurring_count = intval($_POST['recurring_count'] ?? 8);
        
        if (!$is_guest && $recurring !== 'none' && $recurring_count > 1) {
            // Create recurring booking series
            global $wpdb;
            
            // Create recurring series record
            $wpdb->insert($wpdb->prefix . 'ptp_recurring_bookings', array(
                'parent_id' => $parent->id,
                'trainer_id' => $data['trainer_id'],
                'player_id' => $data['player_id'],
                'frequency' => $recurring,
                'total_sessions' => $recurring_count,
                'day_of_week' => date('w', strtotime($data['session_date'])),
                'preferred_time' => $data['start_time'],
                'status' => 'active',
                'created_at' => current_time('mysql'),
            ));
            $recurring_id = $wpdb->insert_id;
            
            // Determine interval
            $interval = $recurring === 'weekly' ? '+1 week' : '+2 weeks';
            
            // Create all bookings in the series
            $created_bookings = array();
            $current_date = $data['session_date'];
            
            for ($i = 0; $i < $recurring_count; $i++) {
                $booking_data = $data;
                $booking_data['session_date'] = $current_date;
                $booking_data['is_recurring'] = 1;
                $booking_data['recurring_id'] = $recurring_id;
                
                $result = PTP_Booking::create($booking_data);
                
                if (!is_wp_error($result)) {
                    $created_bookings[] = $result;
                }
                
                // Move to next date
                $current_date = date('Y-m-d', strtotime($current_date . ' ' . $interval));
            }
            
            // Update recurring series
            $wpdb->update(
                $wpdb->prefix . 'ptp_recurring_bookings',
                array('sessions_created' => count($created_bookings)),
                array('id' => $recurring_id)
            );
            
            // Return first booking for payment
            if (!empty($created_bookings)) {
                $booking = PTP_Booking::get($created_bookings[0]);
                
                wp_send_json_success(array(
                    'message' => 'Recurring bookings created',
                    'booking_id' => $created_bookings[0],
                    'booking_number' => $booking->booking_number,
                    'recurring_id' => $recurring_id,
                    'total_sessions' => count($created_bookings),
                    'redirect' => home_url('/booking-confirmation/?booking_id=' . $created_bookings[0] . '&bn=' . urlencode($booking->booking_number)),
                ));
            } else {
                wp_send_json_error(array('message' => 'Failed to create recurring bookings'));
            }
        }
        
        // Single booking
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PTP Create Booking: trainer_id=' . ($data['trainer_id'] ?? 'none') . ', date=' . ($data['session_date'] ?? 'none'));
        }
        $result = PTP_Booking::create($data);
        
        if (is_wp_error($result)) {
            error_log('PTP Create Booking Error: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        $booking = PTP_Booking::get($result);
        
        wp_send_json_success(array(
            'message' => 'Booking created successfully',
            'booking_id' => $result,
            'booking_number' => $booking->booking_number,
            'redirect' => home_url('/booking-confirmation/?booking_id=' . $result . '&bn=' . urlencode($booking->booking_number)),
        ));
    }
    
    public static function confirm_session() {
        self::verify_nonce();
        self::require_login();
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $user_id = get_current_user_id();
        
        $trainer = PTP_Trainer::get_by_user_id($user_id);
        $parent = PTP_Parent::get_by_user_id($user_id);
        
        if ($trainer) {
            $result = PTP_Booking::confirm_by_trainer($booking_id, $trainer->id);
        } elseif ($parent) {
            $result = PTP_Booking::confirm_by_parent($booking_id, $parent->id);
        } else {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Session confirmed'));
    }
    
    /**
     * Add a new player to parent account
     * BULLETPROOF: Rate limited, validated, with comprehensive error handling
     */
    public static function add_player() {
        try {
            self::verify_nonce();
            self::require_login();
            
            // Rate limit check
            if (!self::check_rate_limit('add_player')) {
                self::send_error('Too many requests. Please wait a moment and try again.', 'rate_limited');
            }
            
            $user_id = get_current_user_id();
            if (!$user_id) {
                self::send_error('Session expired. Please log in again.', 'session_expired');
            }
            
            global $wpdb;
            $parents_table = $wpdb->prefix . 'ptp_parents';
            $players_table = $wpdb->prefix . 'ptp_players';
            $charset = $wpdb->get_charset_collate();
            
            // Step 1: Check if parents table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$parents_table'");
            
            if ($table_exists) {
                // Table exists - check if it has the user_id column
                $column_exists = $wpdb->get_var("SHOW COLUMNS FROM $parents_table LIKE 'user_id'");
                
                if (!$column_exists) {
                    // Old table schema - need to drop and recreate or add column
                    // Safer to add the column
                    $wpdb->query("ALTER TABLE $parents_table ADD COLUMN user_id bigint(20) UNSIGNED NOT NULL AFTER id");
                    $wpdb->query("ALTER TABLE $parents_table ADD UNIQUE KEY user_id (user_id)");
                    error_log('PTP: Added user_id column to existing ptp_parents table');
                }
            } else {
                // Create fresh table
                $wpdb->query("CREATE TABLE $parents_table (
                    id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                    user_id bigint(20) UNSIGNED NOT NULL,
                    display_name varchar(100) NOT NULL DEFAULT '',
                    phone varchar(20) DEFAULT '',
                    location varchar(255) DEFAULT '',
                    latitude decimal(10,8) DEFAULT NULL,
                    longitude decimal(11,8) DEFAULT NULL,
                    total_sessions int(11) DEFAULT 0,
                    total_spent decimal(10,2) DEFAULT 0.00,
                    notification_email tinyint(1) DEFAULT 1,
                    notification_sms tinyint(1) DEFAULT 1,
                    created_at datetime DEFAULT CURRENT_TIMESTAMP,
                    updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY user_id (user_id)
                ) $charset");
            }
            
            // Step 2: Ensure players table exists - v118.2: Added first_name and last_name
            $wpdb->query("CREATE TABLE IF NOT EXISTS $players_table (
                id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_id bigint(20) UNSIGNED NOT NULL,
                name varchar(100) NOT NULL DEFAULT '',
                first_name varchar(50) DEFAULT '',
                last_name varchar(50) DEFAULT '',
                age int(11) DEFAULT NULL,
                skill_level varchar(20) DEFAULT 'beginner',
                position varchar(100) DEFAULT '',
                goals text,
                notes text,
                is_active tinyint(1) DEFAULT 1,
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY parent_id (parent_id)
            ) $charset");
            
            // v118.2: Add first_name/last_name columns if missing (for existing tables)
            $wpdb->query("ALTER TABLE $players_table ADD COLUMN IF NOT EXISTS first_name varchar(50) DEFAULT '' AFTER name");
            $wpdb->query("ALTER TABLE $players_table ADD COLUMN IF NOT EXISTS last_name varchar(50) DEFAULT '' AFTER first_name");
            
            // Step 3: Get or create parent profile
            $parent = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM $parents_table WHERE user_id = %d",
                $user_id
            ));
            
            if (!$parent) {
                // Create parent profile
                $user = wp_get_current_user();
                $display_name = $user->display_name ?: $user->user_login ?: 'Parent';
                $phone = get_user_meta($user_id, 'phone', true) ?: get_user_meta($user_id, 'billing_phone', true) ?: '';
                
                $inserted = $wpdb->insert(
                    $parents_table,
                    array(
                        'user_id' => $user_id,
                        'display_name' => sanitize_text_field($display_name),
                        'phone' => sanitize_text_field($phone),
                    ),
                    array('%d', '%s', '%s')
                );
                
                if ($inserted === false) {
                    error_log('PTP Add Player: Failed to create parent. Error: ' . $wpdb->last_error);
                    error_log('PTP Add Player: Query was: ' . $wpdb->last_query);
                    self::send_error('Could not create parent profile: ' . $wpdb->last_error, 'db_error');
                }
                
                $parent_id = $wpdb->insert_id;
                if (!$parent_id) {
                    // Maybe it was created by another request - try to fetch
                    $parent = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM $parents_table WHERE user_id = %d",
                        $user_id
                    ));
                    if (!$parent) {
                        self::send_error('Parent profile creation failed', 'parent_error');
                    }
                    $parent_id = $parent->id;
                }
            } else {
                $parent_id = $parent->id;
            }
            
            // Step 4: Validate player data
            $name = isset($_POST['name']) ? sanitize_text_field(trim($_POST['name'])) : '';
            $age = isset($_POST['age']) ? intval($_POST['age']) : 0;
            $skill_level = isset($_POST['skill_level']) ? sanitize_text_field($_POST['skill_level']) : 'beginner';
            $position = isset($_POST['position']) ? sanitize_text_field($_POST['position']) : '';
            $goals = isset($_POST['goals']) ? sanitize_textarea_field($_POST['goals']) : '';
            
            // v118.2: Parse first and last name
            $name_parts = explode(' ', $name, 2);
            $first_name = $name_parts[0] ?? '';
            $last_name = $name_parts[1] ?? '';
            
            if (empty($name) || strlen($name) < 2) {
                self::send_error('Please enter a valid player name', 'invalid_name');
            }
            
            if ($age < 4 || $age > 18) {
                self::send_error('Please enter age between 4 and 18', 'invalid_age');
            }
            
            $valid_skills = array('beginner', 'intermediate', 'advanced', 'elite');
            if (!in_array($skill_level, $valid_skills)) {
                $skill_level = 'beginner';
            }
            
            // Step 5: Check player limit
            $player_count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $players_table WHERE parent_id = %d",
                $parent_id
            ));
            if ($player_count >= 20) {
                self::send_error('Maximum 20 players allowed', 'player_limit');
            }
            
            // Step 6: Create player - v118.2: Include first_name and last_name
            $inserted = $wpdb->insert(
                $players_table,
                array(
                    'parent_id' => $parent_id,
                    'name' => $name,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'age' => $age,
                    'skill_level' => $skill_level,
                    'position' => $position,
                    'goals' => $goals,
                    'is_active' => 1,
                ),
                array('%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d')
            );
            
            if ($inserted === false) {
                error_log('PTP Add Player: Failed to create player. Error: ' . $wpdb->last_error);
                self::send_error('Could not add player: ' . $wpdb->last_error, 'player_error');
            }
            
            $player_id = $wpdb->insert_id;
            
            wp_send_json_success(array(
                'message' => 'Player added successfully',
                'player' => array(
                    'id' => $player_id,
                    'name' => $name,
                    'age' => $age,
                    'skill_level' => $skill_level,
                ),
            ));
            
        } catch (Exception $e) {
            error_log('PTP Add Player Exception: ' . $e->getMessage());
            self::send_error('An unexpected error occurred: ' . $e->getMessage(), 'exception');
        }
    }
    
    public static function send_message() {
        self::verify_nonce();
        self::require_login();
        
        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        
        if (empty($message)) {
            wp_send_json_error(array('message' => 'Message cannot be empty'));
        }
        
        $result = PTP_Messaging::send_message($conversation_id, get_current_user_id(), $message);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message_id' => $result));
    }
    
    public static function get_messages() {
        self::verify_nonce();
        self::require_login();
        
        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        
        $messages = PTP_Messaging::get_messages($conversation_id);
        PTP_Messaging::mark_as_read($conversation_id, get_current_user_id());
        
        wp_send_json_success(array('messages' => array_reverse($messages)));
    }
    
    /**
     * Get new messages since last ID (for polling)
     */
    public static function get_new_messages() {
        self::verify_nonce();
        self::require_login();
        
        $conversation_id = intval($_POST['conversation_id'] ?? 0);
        $last_id = intval($_POST['last_id'] ?? 0);
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_messages';
        
        // Get messages after last ID
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
             WHERE conversation_id = %d AND id > %d 
             ORDER BY created_at ASC",
            $conversation_id, $last_id
        ));
        
        // Mark as read
        PTP_Messaging::mark_as_read($conversation_id, get_current_user_id());
        
        wp_send_json_success(array('messages' => $messages));
    }
    
    /**
     * Start a new conversation
     */
    public static function start_conversation() {
        self::verify_nonce();
        self::require_login();
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $parent_id = intval($_POST['parent_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Trainer ID required'));
            return;
        }
        
        // Verify the trainer exists
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        // Get or create conversation
        $user_id = get_current_user_id();
        $is_trainer = PTP_User::is_trainer();
        
        if ($is_trainer) {
            // Trainer starting conversation with parent
            if (!$parent_id) {
                wp_send_json_error(array('message' => 'Parent ID required'));
                return;
            }
            $conversation = PTP_Messaging::get_or_create_conversation($trainer_id, $parent_id);
        } else {
            // Parent (or user) starting conversation with trainer
            $parent = PTP_Parent::get_by_user_id($user_id);
            
            // Auto-create parent profile if needed
            if (!$parent) {
                $current_user = wp_get_current_user();
                
                // Use the proper create method
                $result = PTP_Parent::create($user_id, array(
                    'display_name' => $current_user->display_name ?: $current_user->user_login,
                    'phone' => '',
                ));
                
                if (!is_wp_error($result)) {
                    // Add parent role if not already present
                    if (!in_array('ptp_parent', (array) $current_user->roles)) {
                        $current_user->add_role('ptp_parent');
                    }
                    $parent = PTP_Parent::get_by_user_id($user_id);
                }
            }
            
            if (!$parent) {
                wp_send_json_error(array('message' => 'Could not create your profile. Please try again.'));
                return;
            }
            
            $conversation = PTP_Messaging::get_or_create_conversation($trainer_id, $parent->id);
        }
        
        if (!$conversation) {
            wp_send_json_error(array('message' => 'Failed to create conversation'));
            return;
        }
        
        // Send initial message if provided
        if ($message) {
            PTP_Messaging::send_message($conversation->id, $user_id, $message);
            
            // Send email notification to trainer
            if (class_exists('PTP_Email')) {
                $current_user = wp_get_current_user();
                PTP_Email::send_new_message_notification($trainer->user_id, $current_user->display_name, $message);
            }
        }
        
        wp_send_json_success(array(
            'conversation_id' => $conversation->id,
            'message' => 'Message sent! The trainer will respond via the messaging system.',
            'redirect' => home_url('/messages/?conversation=' . $conversation->id)
        ));
    }
    
    public static function get_conversations() {
        self::verify_nonce();
        self::require_login();
        
        $conversations = PTP_Messaging::get_conversations_for_user(get_current_user_id());
        
        wp_send_json_success(array('conversations' => $conversations));
    }
    
    public static function update_availability() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
            return;
        }
        
        // Support multiple form formats:
        // Format 1 (account.php): available[0-6], start[0-6], end[0-6]
        // Format 2 (onboarding): availability[day][enabled/start/end]
        // Format 3 (alternative): availability[index][day/active/start/end]
        
        $availability_data = array();
        
        // Check for Format 1 (account.php style: separate arrays)
        $available = $_POST['available'] ?? array();
        $start_times = $_POST['start'] ?? array();
        $end_times = $_POST['end'] ?? array();
        
        if (!empty($start_times) || !empty($end_times) || !empty($available)) {
            // Build availability data from separate arrays
            for ($day = 0; $day <= 6; $day++) {
                $is_active = isset($available[$day]) && ($available[$day] === '1' || $available[$day] === 1);
                $start = isset($start_times[$day]) ? sanitize_text_field($start_times[$day]) : '09:00';
                $end = isset($end_times[$day]) ? sanitize_text_field($end_times[$day]) : '17:00';
                
                $availability_data[$day] = array(
                    'day' => $day,
                    'active' => $is_active,
                    'start' => $start,
                    'end' => $end,
                );
            }
        } else {
            // Check for Format 2 & 3
            $availability = $_POST['availability'] ?? array();
            
            if (!empty($availability) && is_array($availability)) {
                foreach ($availability as $key => $day_data) {
                    // Format 2: key is day number
                    if (is_numeric($key) && isset($day_data['start'])) {
                        $day = intval($key);
                        $is_active = !empty($day_data['enabled']) && ($day_data['enabled'] === '1' || $day_data['enabled'] === 1);
                        $availability_data[$day] = array(
                            'day' => $day,
                            'active' => $is_active,
                            'start' => sanitize_text_field($day_data['start'] ?? '09:00'),
                            'end' => sanitize_text_field($day_data['end'] ?? '17:00'),
                        );
                    }
                    // Format 3: nested with day key
                    elseif (isset($day_data['day'])) {
                        $day = intval($day_data['day']);
                        $is_active = !empty($day_data['active']) && ($day_data['active'] === '1' || $day_data['active'] === 1);
                        $availability_data[$day] = array(
                            'day' => $day,
                            'active' => $is_active,
                            'start' => sanitize_text_field($day_data['start'] ?? '09:00'),
                            'end' => sanitize_text_field($day_data['end'] ?? '17:00'),
                        );
                    }
                }
            }
        }
        
        // If still no data, return error
        if (empty($availability_data)) {
            wp_send_json_error(array('message' => 'No availability data provided'));
            return;
        }
        
        global $wpdb;
        
        // Ensure table exists
        if (class_exists('PTP_Availability')) {
            PTP_Availability::ensure_table();
        }
        
        $table = $wpdb->prefix . 'ptp_availability';
        
        // Clear existing availability
        $wpdb->delete($table, array('trainer_id' => $trainer->id));
        
        // Insert new availability
        foreach ($availability_data as $day => $data) {
            if ($day < 0 || $day > 6) continue;
            
            $start_time = $data['start'];
            $end_time = $data['end'];
            $is_active = $data['active'];
            
            // Normalize time format - ensure HH:MM:SS
            if (preg_match('/^\d{1,2}:\d{2}$/', $start_time)) {
                if (strlen($start_time) === 4) $start_time = '0' . $start_time;
                $start_time .= ':00';
            }
            if (preg_match('/^\d{1,2}:\d{2}$/', $end_time)) {
                if (strlen($end_time) === 4) $end_time = '0' . $end_time;
                $end_time .= ':00';
            }
            
            // Validate final format
            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $start_time)) {
                $start_time = '09:00:00';
            }
            if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $end_time)) {
                $end_time = '17:00:00';
            }
            
            $wpdb->insert($table, array(
                'trainer_id' => $trainer->id,
                'day_of_week' => $day,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'is_active' => $is_active ? 1 : 0,
            ));
        }
        
        // Clear cache
        wp_cache_delete('ptp_availability_' . $trainer->id . '_' . date('Y') . '_' . date('n'), 'ptp');
        
        wp_send_json_success(array('message' => 'Availability updated'));
    }
    
    public static function update_profile() {
        self::verify_nonce();
        self::require_login();
        
        $user_id = get_current_user_id();
        $trainer = PTP_Trainer::get_by_user_id($user_id);
        $parent = PTP_Parent::get_by_user_id($user_id);
        
        // Sanitize all inputs before passing to model
        $sanitized_data = array();
        $text_fields = array('first_name', 'last_name', 'display_name', 'phone', 'headline', 'location', 'college', 'team', 'position', 'instagram', 'playing_level');
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                $sanitized_data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        if (isset($_POST['email'])) {
            $sanitized_data['email'] = sanitize_email($_POST['email']);
        }
        if (isset($_POST['bio'])) {
            $sanitized_data['bio'] = sanitize_textarea_field($_POST['bio']);
        }
        if (isset($_POST['hourly_rate'])) {
            $sanitized_data['hourly_rate'] = floatval($_POST['hourly_rate']);
        }
        if (isset($_POST['travel_radius'])) {
            $sanitized_data['travel_radius'] = absint($_POST['travel_radius']);
        }
        if (isset($_POST['specialties']) && is_array($_POST['specialties'])) {
            $sanitized_data['specialties'] = array_map('sanitize_text_field', $_POST['specialties']);
        }
        
        if ($trainer) {
            PTP_Trainer::update($trainer->id, $sanitized_data);
        } elseif ($parent) {
            PTP_Parent::update($parent->id, $sanitized_data);
        }
        
        // Update WP user
        $user_data = array('ID' => $user_id);
        if (!empty($sanitized_data['first_name'])) $user_data['first_name'] = $sanitized_data['first_name'];
        if (!empty($sanitized_data['last_name'])) $user_data['last_name'] = $sanitized_data['last_name'];
        if (!empty($sanitized_data['display_name'])) $user_data['display_name'] = $sanitized_data['display_name'];
        
        wp_update_user($user_data);
        
        wp_send_json_success(array('message' => 'Profile updated'));
    }
    
    /**
     * Update trainer profile (headline, bio, rate, specialties, etc.)
     */
    public static function update_trainer_profile() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        // Handle photo upload if present
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            
            // SECURITY: Server-side MIME type validation (don't trust client-provided type)
            $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
            $allowed_extensions = array('jpg', 'jpeg', 'png', 'webp');
            
            // Validate file extension
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_extensions)) {
                wp_send_json_error(array('message' => 'Invalid file extension. Please upload JPG, PNG, or WebP.'));
            }
            
            // Server-side MIME type detection
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $real_type = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
            
            if (!in_array($real_type, $allowed_types)) {
                wp_send_json_error(array('message' => 'Invalid file type. Please upload JPG, PNG, or WebP.'));
            }
            
            if ($file['size'] > 2 * 1024 * 1024) {
                wp_send_json_error(array('message' => 'Photo too large. Maximum size is 2MB.'));
            }
            
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
            
            $upload = wp_handle_upload($file, array('test_form' => false));
            
            if (isset($upload['error'])) {
                wp_send_json_error(array('message' => 'Photo upload failed: ' . $upload['error']));
            }
            
            $attachment = array(
                'post_mime_type' => $upload['type'],
                'post_title' => sanitize_file_name($file['name']),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            
            $attach_id = wp_insert_attachment($attachment, $upload['file']);
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);
            
            PTP_Trainer::update($trainer->id, array('photo_url' => $upload['url']));
        }
        
        $data = array(
            'headline' => sanitize_text_field($_POST['headline'] ?? ''),
            'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
            'college' => sanitize_text_field($_POST['college'] ?? ''),
            'team' => sanitize_text_field($_POST['team'] ?? ''),
            'hourly_rate' => floatval($_POST['hourly_rate'] ?? 0),
            'specialties' => is_array($_POST['specialties'] ?? null) 
                ? implode(',', array_map('sanitize_text_field', $_POST['specialties'])) 
                : sanitize_text_field($_POST['specialties'] ?? ''),
        );
        
        // Optional fields
        if (isset($_POST['position'])) {
            $data['position'] = sanitize_text_field($_POST['position']);
        }
        if (isset($_POST['travel_radius'])) {
            $data['travel_radius'] = intval($_POST['travel_radius']);
        }
        if (isset($_POST['location'])) {
            $data['location'] = sanitize_text_field($_POST['location']);
        }
        if (isset($_POST['intro_video_url'])) {
            $video_url = esc_url_raw($_POST['intro_video_url']);
            // Validate it's a YouTube or Vimeo URL
            if (!empty($video_url) && (
                strpos($video_url, 'youtube.com') !== false || 
                strpos($video_url, 'youtu.be') !== false ||
                strpos($video_url, 'vimeo.com') !== false
            )) {
                $data['intro_video_url'] = $video_url;
            } else if (empty($video_url)) {
                $data['intro_video_url'] = '';
            }
        }
        if (isset($_POST['instagram'])) {
            // Clean Instagram handle
            $instagram = sanitize_text_field($_POST['instagram']);
            $instagram = preg_replace('/^@/', '', $instagram);
            $instagram = preg_replace('/^(https?:\/\/)?(www\.)?instagram\.com\//', '', $instagram);
            $instagram = preg_replace('/\/$/', '', $instagram);
            $data['instagram'] = $instagram;
        }
        if (isset($_POST['facebook'])) {
            // Clean Facebook handle
            $facebook = sanitize_text_field($_POST['facebook']);
            $facebook = preg_replace('/^(https?:\/\/)?(www\.)?facebook\.com\//', '', $facebook);
            $facebook = preg_replace('/\/$/', '', $facebook);
            $data['facebook'] = $facebook;
        }
        
        // Group session settings
        if (isset($_POST['accepts_groups'])) {
            $data['accepts_groups'] = intval($_POST['accepts_groups']) ? 1 : 0;
        }
        if (isset($_POST['group_max_size'])) {
            $data['group_max_size'] = min(5, max(2, intval($_POST['group_max_size'])));
        }
        
        PTP_Trainer::update($trainer->id, $data);
        
        wp_send_json_success(array('message' => 'Profile updated successfully'));
    }
    
    /**
     * Upload trainer profile photo
     */
    public static function upload_trainer_photo() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'No file uploaded or upload error'));
        }
        
        $file = $_FILES['photo'];
        
        // SECURITY: Server-side file validation (don't trust client-provided MIME type)
        $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
        $allowed_extensions = array('jpg', 'jpeg', 'png', 'webp');
        
        // Validate file extension
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_extensions)) {
            wp_send_json_error(array('message' => 'Invalid file extension. Please upload JPG, PNG, or WebP.'));
        }
        
        // Server-side MIME type detection
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($real_type, $allowed_types)) {
            wp_send_json_error(array('message' => 'Invalid file type. Please upload JPG, PNG, or WebP.'));
        }
        
        // Validate file size (5MB max - increased to allow higher quality)
        if ($file['size'] > 5 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'File too large. Maximum size is 5MB.'));
        }
        
        // Validate image dimensions (800x1000 minimum for quality)
        $image_size = @getimagesize($file['tmp_name']);
        if (!$image_size) {
            wp_send_json_error(array('message' => 'Could not read image. Please try a different file.'));
        }
        
        $min_width = 800;
        $min_height = 1000;
        if ($image_size[0] < $min_width || $image_size[1] < $min_height) {
            wp_send_json_error(array(
                'message' => sprintf(
                    'Image too small. Minimum size is %dx%d pixels. Your image is %dx%d. Please upload a higher resolution photo.',
                    $min_width, $min_height, $image_size[0], $image_size[1]
                )
            ));
        }
        
        // Include WordPress file handling functions
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Upload to WordPress media library
        $upload = wp_handle_upload($file, array('test_form' => false));
        
        if (isset($upload['error'])) {
            wp_send_json_error(array('message' => $upload['error']));
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title' => sanitize_file_name($file['name']),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        
        // Generate metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Update trainer profile with new photo URL
        PTP_Trainer::update($trainer->id, array('photo_url' => $upload['url']));
        
        wp_send_json_success(array(
            'message' => 'Photo uploaded successfully',
            'photo_url' => $upload['url']
        ));
    }
    
    /**
     * Upload trainer cover photo (landscape action photo)
     */
    public static function upload_trainer_cover_photo() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'No file uploaded or upload error'));
        }
        
        $file = $_FILES['photo'];
        
        // Validate file type
        $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
        if (!in_array($file['type'], $allowed_types)) {
            wp_send_json_error(array('message' => 'Invalid file type. Please upload JPG, PNG, or WebP.'));
        }
        
        // Validate file size (8MB max for landscape photos)
        if ($file['size'] > 8 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'File too large. Maximum size is 8MB.'));
        }
        
        // Validate image dimensions (landscape orientation preferred)
        $image_size = @getimagesize($file['tmp_name']);
        if (!$image_size) {
            wp_send_json_error(array('message' => 'Could not read image. Please try a different file.'));
        }
        
        $min_width = 800;
        $min_height = 300;
        if ($image_size[0] < $min_width || $image_size[1] < $min_height) {
            wp_send_json_error(array(
                'message' => sprintf(
                    'Image too small. Minimum size is %dx%d pixels. Your image is %dx%d. Please upload a higher resolution photo.',
                    $min_width, $min_height, $image_size[0], $image_size[1]
                )
            ));
        }
        
        // Include WordPress file handling functions
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Upload to WordPress media library
        $upload = wp_handle_upload($file, array('test_form' => false));
        
        if (isset($upload['error'])) {
            wp_send_json_error(array('message' => $upload['error']));
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title' => 'Cover - ' . sanitize_file_name($file['name']),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        
        // Generate metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Update trainer profile with new cover photo URL
        PTP_Trainer::update($trainer->id, array('cover_photo_url' => $upload['url']));
        
        wp_send_json_success(array(
            'message' => 'Cover photo uploaded successfully',
            'url' => $upload['url']
        ));
    }
    
    /**
     * Complete trainer onboarding (profile + availability in one step)
     */
    public static function complete_onboarding() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        // Handle main photo upload if present
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            
            $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
            if (!in_array($file['type'], $allowed_types)) {
                wp_send_json_error(array('message' => 'Invalid file type. Please upload JPG, PNG, or WebP.'));
            }
            
            if ($file['size'] > 5 * 1024 * 1024) {
                wp_send_json_error(array('message' => 'Photo too large. Maximum size is 5MB.'));
            }
            
            // Validate image dimensions
            $image_size = @getimagesize($file['tmp_name']);
            if ($image_size && ($image_size[0] < 800 || $image_size[1] < 1000)) {
                wp_send_json_error(array(
                    'message' => sprintf(
                        'Image too small. Minimum size is 800x1000 pixels. Your image is %dx%d.',
                        $image_size[0], $image_size[1]
                    )
                ));
            }
            
            $upload = wp_handle_upload($file, array('test_form' => false));
            
            if (isset($upload['error'])) {
                wp_send_json_error(array('message' => 'Photo upload failed: ' . $upload['error']));
            }
            
            $attachment = array(
                'post_mime_type' => $upload['type'],
                'post_title' => sanitize_file_name($file['name']),
                'post_content' => '',
                'post_status' => 'inherit'
            );
            
            $attach_id = wp_insert_attachment($attachment, $upload['file']);
            $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
            wp_update_attachment_metadata($attach_id, $attach_data);
            
            PTP_Trainer::update($trainer->id, array('photo_url' => $upload['url']));
        }
        
        // Handle gallery uploads
        $gallery_urls = array();
        
        // Keep existing gallery images (from hidden inputs)
        if (!empty($_POST['gallery_urls']) && is_array($_POST['gallery_urls'])) {
            foreach ($_POST['gallery_urls'] as $url) {
                // Skip base64 data URLs - only keep actual URLs
                if (strpos($url, 'data:image') === 0) {
                    // Base64 - need to save as file
                    $upload_result = self::save_base64_image($url);
                    if ($upload_result && !isset($upload_result['error'])) {
                        $gallery_urls[] = $upload_result['url'];
                    }
                } else {
                    // Regular URL - keep as is
                    $clean_url = esc_url_raw($url);
                    if (!empty($clean_url)) {
                        $gallery_urls[] = $clean_url;
                    }
                }
            }
        }
        
        // Keep legacy support for existing_gallery
        if (!empty($_POST['existing_gallery']) && is_array($_POST['existing_gallery'])) {
            foreach ($_POST['existing_gallery'] as $url) {
                $gallery_urls[] = esc_url_raw($url);
            }
        }
        
        // Upload new gallery images from gallery_files[] input
        if (!empty($_FILES['gallery_files']) && is_array($_FILES['gallery_files']['name'])) {
            $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
            
            for ($i = 0; $i < count($_FILES['gallery_files']['name']); $i++) {
                if ($_FILES['gallery_files']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['gallery_files']['size'][$i] > 2 * 1024 * 1024) continue;
                if (!in_array($_FILES['gallery_files']['type'][$i], $allowed_types)) continue;
                if (count($gallery_urls) >= 6) break; // Max 6 images
                
                $file = array(
                    'name' => $_FILES['gallery_files']['name'][$i],
                    'type' => $_FILES['gallery_files']['type'][$i],
                    'tmp_name' => $_FILES['gallery_files']['tmp_name'][$i],
                    'error' => $_FILES['gallery_files']['error'][$i],
                    'size' => $_FILES['gallery_files']['size'][$i],
                );
                
                $upload = wp_handle_upload($file, array('test_form' => false));
                if (!isset($upload['error'])) {
                    $gallery_urls[] = $upload['url'];
                }
            }
        }
        
        // Legacy support for gallery[] input
        if (!empty($_FILES['gallery']) && is_array($_FILES['gallery']['name'])) {
            $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
            
            for ($i = 0; $i < count($_FILES['gallery']['name']); $i++) {
                if ($_FILES['gallery']['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($_FILES['gallery']['size'][$i] > 2 * 1024 * 1024) continue;
                if (!in_array($_FILES['gallery']['type'][$i], $allowed_types)) continue;
                if (count($gallery_urls) >= 6) break;
                
                $file = array(
                    'name' => $_FILES['gallery']['name'][$i],
                    'type' => $_FILES['gallery']['type'][$i],
                    'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                    'error' => $_FILES['gallery']['error'][$i],
                    'size' => $_FILES['gallery']['size'][$i],
                );
                
                $upload = wp_handle_upload($file, array('test_form' => false));
                if (!isset($upload['error'])) {
                    $gallery_urls[] = $upload['url'];
                }
            }
        }
        
        // Update trainer profile data
        $profile_data = array(
            'headline' => sanitize_text_field($_POST['headline'] ?? ''),
            'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
            'college' => sanitize_text_field($_POST['college'] ?? ''),
            'team' => sanitize_text_field($_POST['team'] ?? ''),
            'location' => sanitize_text_field($_POST['location'] ?? ''),
            'travel_radius' => intval($_POST['travel_radius'] ?? 15),
            'hourly_rate' => floatval($_POST['hourly_rate'] ?? 0),
            'position' => sanitize_text_field($_POST['position'] ?? ''),
            'specialties' => is_array($_POST['specialties'] ?? null) 
                ? implode(',', array_map('sanitize_text_field', $_POST['specialties'])) 
                : '',
            'gallery' => !empty($gallery_urls) ? json_encode($gallery_urls) : null,
        );
        
        // Handle intro video URL
        if (isset($_POST['intro_video_url'])) {
            $video_url = esc_url_raw($_POST['intro_video_url']);
            // Validate it's a YouTube or Vimeo URL
            if (empty($video_url) || 
                strpos($video_url, 'youtube.com') !== false || 
                strpos($video_url, 'youtu.be') !== false || 
                strpos($video_url, 'vimeo.com') !== false) {
                $profile_data['intro_video_url'] = $video_url;
            }
        }
        
        // Handle training locations - now with structured data (name, address, lat, lng)
        if (isset($_POST['training_locations']) && is_array($_POST['training_locations'])) {
            $locations = array();
            foreach ($_POST['training_locations'] as $loc) {
                if (is_array($loc)) {
                    // New format with structured data
                    $name = isset($loc['name']) ? sanitize_text_field($loc['name']) : '';
                    if (!empty($name)) {
                        $locations[] = array(
                            'name' => $name,
                            'address' => isset($loc['address']) ? sanitize_text_field($loc['address']) : '',
                            'lat' => isset($loc['lat']) && $loc['lat'] !== '' ? floatval($loc['lat']) : null,
                            'lng' => isset($loc['lng']) && $loc['lng'] !== '' ? floatval($loc['lng']) : null,
                        );
                    }
                } else {
                    // Legacy format - plain string
                    $name = sanitize_text_field($loc);
                    if (!empty($name)) {
                        $locations[] = array(
                            'name' => $name,
                            'address' => '',
                            'lat' => null,
                            'lng' => null,
                        );
                    }
                }
            }
            $profile_data['training_locations'] = !empty($locations) ? json_encode($locations) : null;
        }
        
        // Handle lat/lng if provided
        if (!empty($_POST['latitude'])) {
            $profile_data['latitude'] = floatval($_POST['latitude']);
        }
        if (!empty($_POST['longitude'])) {
            $profile_data['longitude'] = floatval($_POST['longitude']);
        }
        
        // Handle custom SEO slug if provided
        if (!empty($_POST['slug'])) {
            $profile_data['slug'] = sanitize_text_field($_POST['slug']);
        }
        
        // Handle SafeSport document upload
        if (!empty($_FILES['safesport_doc']) && $_FILES['safesport_doc']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['safesport_doc'];
            $allowed_types = array('application/pdf', 'image/jpeg', 'image/png');
            
            if (in_array($file['type'], $allowed_types) && $file['size'] <= 5 * 1024 * 1024) {
                $upload = wp_handle_upload($file, array('test_form' => false));
                if (!isset($upload['error'])) {
                    $profile_data['safesport_doc_url'] = $upload['url'];
                    $profile_data['safesport_verified'] = 0; // Reset verification on new upload
                }
            }
        }
        
        // Handle background check document upload
        if (!empty($_FILES['background_doc']) && $_FILES['background_doc']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['background_doc'];
            $allowed_types = array('application/pdf', 'image/jpeg', 'image/png');
            
            if (in_array($file['type'], $allowed_types) && $file['size'] <= 5 * 1024 * 1024) {
                $upload = wp_handle_upload($file, array('test_form' => false));
                if (!isset($upload['error'])) {
                    $profile_data['background_doc_url'] = $upload['url'];
                    $profile_data['background_verified'] = 0; // Reset verification on new upload
                }
            }
        }
        
        // Handle tax information
        if (!empty($_POST['legal_name'])) {
            $profile_data['legal_name'] = sanitize_text_field($_POST['legal_name']);
        }
        if (!empty($_POST['tax_id_type'])) {
            $profile_data['tax_id_type'] = in_array($_POST['tax_id_type'], array('ssn', 'ein')) ? $_POST['tax_id_type'] : 'ssn';
        }
        if (!empty($_POST['tax_id'])) {
            // Only store last 4 digits - encrypt or hash full ID in production
            $tax_id = preg_replace('/\D/', '', $_POST['tax_id']);
            if (strlen($tax_id) >= 4) {
                $profile_data['tax_id_last4'] = substr($tax_id, -4);
            }
        }
        if (!empty($_POST['tax_address_line1'])) {
            $profile_data['tax_address_line1'] = sanitize_text_field($_POST['tax_address_line1']);
        }
        if (isset($_POST['tax_address_line2'])) {
            $profile_data['tax_address_line2'] = sanitize_text_field($_POST['tax_address_line2']);
        }
        if (!empty($_POST['tax_city'])) {
            $profile_data['tax_city'] = sanitize_text_field($_POST['tax_city']);
        }
        if (!empty($_POST['tax_state'])) {
            $profile_data['tax_state'] = sanitize_text_field($_POST['tax_state']);
        }
        if (!empty($_POST['tax_zip'])) {
            $profile_data['tax_zip'] = sanitize_text_field($_POST['tax_zip']);
        }
        
        // W-9 certification
        if (!empty($_POST['w9_certification']) && !empty($_POST['legal_name']) && !empty($_POST['tax_id'])) {
            $profile_data['w9_submitted'] = 1;
            $profile_data['w9_submitted_at'] = current_time('mysql');
        }
        
        // Contractor agreement
        if (!empty($_POST['contractor_agreement'])) {
            $profile_data['contractor_agreement_signed'] = 1;
            $profile_data['contractor_agreement_signed_at'] = current_time('mysql');
            $profile_data['contractor_agreement_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        PTP_Trainer::update($trainer->id, $profile_data);
        
        // Send contractor agreement email if just signed
        if (!empty($_POST['contractor_agreement']) && class_exists('PTP_Email')) {
            // Refresh trainer data to get signed_at timestamp
            $updated_trainer = PTP_Trainer::get($trainer->id);
            if ($updated_trainer && $updated_trainer->contractor_agreement_signed) {
                PTP_Email::send_contractor_agreement($trainer->id);
            }
        }
        
        // Update availability
        if (!empty($_POST['availability']) && is_array($_POST['availability'])) {
            global $wpdb;
            
            // Ensure table exists
            if (class_exists('PTP_Availability')) {
                PTP_Availability::ensure_table();
            }
            
            $table = $wpdb->prefix . 'ptp_availability';
            
            // Clear existing availability
            $wpdb->delete($table, array('trainer_id' => $trainer->id));
            
            foreach ($_POST['availability'] as $day => $slot) {
                $day = intval($day);
                if ($day < 0 || $day > 6) continue;
                
                $is_enabled = !empty($slot['enabled']) && ($slot['enabled'] === '1' || $slot['enabled'] === 1);
                
                // Normalize time format - ensure HH:MM:SS
                $start_time = sanitize_text_field($slot['start'] ?? '16:00');
                $end_time = sanitize_text_field($slot['end'] ?? '20:00');
                
                // Add seconds if not present
                if (preg_match('/^\d{2}:\d{2}$/', $start_time)) {
                    $start_time .= ':00';
                }
                if (preg_match('/^\d{2}:\d{2}$/', $end_time)) {
                    $end_time .= ':00';
                }
                
                // Validate format
                if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $start_time)) {
                    $start_time = '16:00:00';
                }
                if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $end_time)) {
                    $end_time = '20:00:00';
                }
                
                $result = $wpdb->insert($table, array(
                    'trainer_id' => $trainer->id,
                    'day_of_week' => $day,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'is_active' => $is_enabled ? 1 : 0,
                ));
                
                if ($result === false) {
                    error_log('PTP Onboarding: Failed to save availability for day ' . $day . ': ' . $wpdb->last_error);
                }
            }
            
            // Clear cache for this trainer's availability
            wp_cache_delete('ptp_availability_' . $trainer->id . '_' . date('Y') . '_' . date('n'), 'ptp');
        }
        
        // Mark onboarding as complete
        PTP_Trainer::complete_onboarding($trainer->id);
        
        // Send onboarding complete email
        if (class_exists('PTP_Email')) {
            PTP_Email::send_onboarding_complete($trainer->id);
        }
        
        wp_send_json_success(array(
            'message' => 'Profile completed successfully!',
            'redirect' => home_url('/trainer-dashboard/?welcome=1&finish_profile=1')
        ));
    }
    
    /**
     * Auto-save trainer profile (lightweight, no email, no redirect)
     */
    public static function autosave_profile() {
        self::verify_nonce();
        self::require_login();
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE user_id = %d",
            $user_id
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        // Build update data (only fields that are set)
        $update_data = array();
        $allowed_fields = array(
            'headline', 'bio', 'college', 'team', 'location', 'position'
        );
        
        foreach ($allowed_fields as $field) {
            if (isset($_POST[$field])) {
                $update_data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        // Handle numeric fields
        if (isset($_POST['travel_radius'])) {
            $update_data['travel_radius'] = intval($_POST['travel_radius']);
        }
        if (isset($_POST['hourly_rate'])) {
            $update_data['hourly_rate'] = floatval($_POST['hourly_rate']);
        }
        
        // Handle specialties
        if (isset($_POST['specialties']) && is_array($_POST['specialties'])) {
            $update_data['specialties'] = implode(',', array_map('sanitize_text_field', $_POST['specialties']));
        }
        
        // Handle training locations
        if (isset($_POST['training_locations']) && is_array($_POST['training_locations'])) {
            $locations = array();
            foreach ($_POST['training_locations'] as $loc) {
                if (is_array($loc) && !empty($loc['name'])) {
                    $locations[] = array(
                        'name' => sanitize_text_field($loc['name']),
                        'address' => sanitize_text_field($loc['address'] ?? ''),
                        'lat' => isset($loc['lat']) ? floatval($loc['lat']) : null,
                        'lng' => isset($loc['lng']) ? floatval($loc['lng']) : null,
                    );
                }
            }
            if (!empty($locations)) {
                $update_data['training_locations'] = json_encode($locations);
            }
        }
        
        // Handle coordinates
        if (!empty($_POST['latitude'])) {
            $update_data['latitude'] = floatval($_POST['latitude']);
        }
        if (!empty($_POST['longitude'])) {
            $update_data['longitude'] = floatval($_POST['longitude']);
        }
        
        // Quick update (no validation, no hooks)
        if (!empty($update_data)) {
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                $update_data,
                array('id' => $trainer->id)
            );
        }
        
        wp_send_json_success(array('message' => 'Auto-saved'));
    }
    
    /**
     * Save trainer onboarding v60 - Rover-inspired robust profiles
     * Handles: photos, structured bio, rich locations, session prefs, availability
     */
    public static function save_trainer_onboarding_v60() {
        check_ajax_referer('ptp_trainer_onboarding', 'ptp_nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        // v135.9: Check if this is a final submit or just a save
        $is_final_submit = !empty($_POST['final_submit']) && $_POST['final_submit'] === '1';
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        global $wpdb;
        
        // Handle main photo upload
        $photo_url = null;
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
            
            if (in_array($file['type'], $allowed_types) && $file['size'] <= 5 * 1024 * 1024) {
                $upload = wp_handle_upload($file, array('test_form' => false));
                if (!isset($upload['error'])) {
                    $photo_url = $upload['url'];
                }
            }
        }
        
        // Build profile data from ACTUAL form fields
        $profile_data = array();
        
        // Photo
        if ($photo_url) {
            $profile_data['photo_url'] = $photo_url;
        }
        
        // Basic info - first_name and last_name update display_name
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        if ($first_name || $last_name) {
            $profile_data['display_name'] = trim($first_name . ' ' . $last_name);
            // Also update WordPress user
            wp_update_user(array(
                'ID' => get_current_user_id(),
                'first_name' => $first_name,
                'last_name' => $last_name,
                'display_name' => $profile_data['display_name']
            ));
        }
        
        // Bio
        if (isset($_POST['bio'])) {
            $profile_data['bio'] = sanitize_textarea_field($_POST['bio']);
        }
        
        // Coaching why & training philosophy
        if (isset($_POST['coaching_why'])) {
            $profile_data['coaching_why'] = sanitize_textarea_field($_POST['coaching_why']);
        }
        if (isset($_POST['training_philosophy'])) {
            $profile_data['training_philosophy'] = sanitize_textarea_field($_POST['training_philosophy']);
        }
        
        // Playing level - form sends 'playing_experience', DB column is 'playing_level'
        if (isset($_POST['playing_experience'])) {
            $profile_data['playing_level'] = sanitize_text_field($_POST['playing_experience']);
        }
        
        // Teams played - form sends 'teams_played', store in 'team' column
        if (isset($_POST['teams_played'])) {
            $profile_data['team'] = sanitize_text_field($_POST['teams_played']);
        }
        
        // Years playing/coaching - combine into experience_years if provided
        $years_playing = intval($_POST['years_playing'] ?? 0);
        $years_coaching = intval($_POST['years_coaching'] ?? 0);
        if ($years_playing > 0) {
            $profile_data['experience_years'] = $years_playing;
        }
        
        // Certifications - store in headline or a custom field
        if (isset($_POST['certifications']) && !empty($_POST['certifications'])) {
            $profile_data['headline'] = sanitize_text_field($_POST['certifications']);
        }
        
        // Pricing & Location
        if (isset($_POST['hourly_rate'])) {
            $profile_data['hourly_rate'] = floatval($_POST['hourly_rate']);
        }
        if (isset($_POST['travel_radius'])) {
            $profile_data['travel_radius'] = intval($_POST['travel_radius']);
        }
        if (isset($_POST['city'])) {
            $profile_data['city'] = sanitize_text_field($_POST['city']);
        }
        if (isset($_POST['state'])) {
            $profile_data['state'] = sanitize_text_field($_POST['state']);
        }
        
        // Set location string for backwards compat
        if (!empty($profile_data['city']) && !empty($profile_data['state'])) {
            $profile_data['location'] = $profile_data['city'] . ', ' . $profile_data['state'];
        }
        
        // v136: Handle training locations with REQUIRED field type validation
        $training_locations = array();
        $valid_field_types = array('park', 'school', 'indoor', 'turf', 'private', 'club', 'other');
        
        if (isset($_POST['training_locations']) && is_array($_POST['training_locations'])) {
            foreach ($_POST['training_locations'] as $loc) {
                if (is_array($loc)) {
                    // New format with field type: {name, address, type, lat, lng}
                    $field_type = sanitize_text_field($loc['type'] ?? 'park');
                    
                    // Validate field type
                    if (!in_array($field_type, $valid_field_types)) {
                        $field_type = 'park';
                    }
                    
                    $location_data = array(
                        'name' => sanitize_text_field($loc['name'] ?? ''),
                        'address' => sanitize_text_field($loc['address'] ?? ''),
                        'type' => $field_type,
                        'lat' => floatval($loc['lat'] ?? 0),
                        'lng' => floatval($loc['lng'] ?? 0),
                    );
                    
                    // v136: Only add if has name AND type
                    if (!empty($location_data['name']) || !empty($location_data['address'])) {
                        $training_locations[] = $location_data;
                    }
                } else {
                    // Old format: just address string - convert with default type
                    $address = sanitize_text_field($loc);
                    if (!empty($address)) {
                        $training_locations[] = array(
                            'name' => $address,
                            'address' => $address,
                            'type' => 'park',
                            'lat' => 0,
                            'lng' => 0,
                        );
                    }
                }
            }
        }
        
        // v136: Always update training_locations (even if empty, to allow clearing)
        $profile_data['training_locations'] = !empty($training_locations) ? json_encode($training_locations) : null;
        
        // Update trainer profile
        if (!empty($profile_data)) {
            PTP_Trainer::update($trainer->id, $profile_data);
        }
        
        // Update availability
        if (!empty($_POST['availability']) && is_array($_POST['availability'])) {
            $table = $wpdb->prefix . 'ptp_availability';
            
            // Ensure table exists
            if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'ensure_table')) {
                PTP_Availability::ensure_table();
            }
            
            // Map string day names to numbers (0=Sunday, 1=Monday, etc.)
            $day_map = array(
                'sunday' => 0, 'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
                'thursday' => 4, 'friday' => 5, 'saturday' => 6
            );
            
            // Clear existing availability for this trainer
            $wpdb->delete($table, array('trainer_id' => $trainer->id));
            
            foreach ($_POST['availability'] as $day => $slot) {
                // Convert string day name to number
                $day_key = strtolower(trim($day));
                if (!isset($day_map[$day_key])) continue;
                
                $day_num = $day_map[$day_key];
                $is_enabled = !empty($slot['enabled']) && ($slot['enabled'] === '1' || $slot['enabled'] === 1 || $slot['enabled'] === true);
                
                // Get times, default to reasonable hours
                $start_time = sanitize_text_field($slot['start'] ?? '16:00');
                $end_time = sanitize_text_field($slot['end'] ?? '20:00');
                
                // Add seconds if not present
                if (preg_match('/^\d{2}:\d{2}$/', $start_time)) $start_time .= ':00';
                if (preg_match('/^\d{2}:\d{2}$/', $end_time)) $end_time .= ':00';
                
                // Insert the availability record
                $result = $wpdb->insert($table, array(
                    'trainer_id' => $trainer->id,
                    'day_of_week' => $day_num,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'is_active' => $is_enabled ? 1 : 0,
                ));
                
                if ($result === false) {
                    error_log('PTP Availability Insert Error: ' . $wpdb->last_error);
                }
            }
        }
        
        // Handle contract agreement
        if (!empty($_POST['agree_contract']) && $_POST['agree_contract'] === '1') {
            // Record the contract signature
            $contract_data = array(
                'contractor_agreement_signed' => 1,
                'contractor_agreement_signed_at' => current_time('mysql'),
                'contractor_agreement_ip' => sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '')
            );
            $wpdb->update(
                $wpdb->prefix . 'ptp_trainers',
                $contract_data,
                array('id' => $trainer->id),
                array('%d', '%s', '%s'),
                array('%d')
            );
            
            // Log the contract signing
            error_log(sprintf(
                'PTP Contract Signed: Trainer ID %d (%s) signed agreement at %s from IP %s',
                $trainer->id,
                $trainer->display_name,
                current_time('mysql'),
                $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ));
        }
        
        // v135.9: Only mark onboarding complete and notify admin if this is final submit
        // AND all required fields are complete
        if ($is_final_submit) {
            // Reload trainer to check completion
            $updated_trainer = PTP_Trainer::get($trainer->id);
            $completion = PTP_Trainer::get_completion_status($trainer->id);
            
            // Check minimum requirements for submission
            $required_complete = array(
                'photo' => $completion['photo'] ?? false,
                'bio' => $completion['bio'] ?? false,
                'rate' => $completion['rate'] ?? false,
                'location' => $completion['location'] ?? false,
                'training_locations' => $completion['training_locations'] ?? false,
                'availability' => $completion['availability'] ?? false,
                'contract' => $completion['contract'] ?? false,
            );
            
            $missing = array_keys(array_filter($required_complete, function($v) { return !$v; }));
            
            if (empty($missing)) {
                // All required fields complete - mark onboarding as complete
                if (method_exists('PTP_Trainer', 'complete_onboarding')) {
                    PTP_Trainer::complete_onboarding($trainer->id);
                }
            } else {
                // Some fields still missing - just save without completing
                error_log("PTP: Trainer #{$trainer->id} final submit but missing: " . implode(', ', $missing));
            }
        }
        
        // v133: Fire action for onboarding progress tracking
        do_action('ptp_trainer_onboarding_saved', $trainer->id);
        
        wp_send_json_success(array(
            'message' => 'Profile submitted! We\'ll review and activate your account within 24 hours.',
            'redirect' => home_url('/trainer-dashboard/?pending=1')
        ));
    }
    
    /**
     * Update password
     */
    public static function update_password() {
        self::verify_nonce();
        self::require_login();
        
        $user = wp_get_current_user();
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            wp_send_json_error(array('message' => 'Please fill in all password fields'));
        }
        
        // Verify current password
        if (!wp_check_password($current_password, $user->user_pass, $user->ID)) {
            wp_send_json_error(array('message' => 'Current password is incorrect'));
        }
        
        // Check passwords match
        if ($new_password !== $confirm_password) {
            wp_send_json_error(array('message' => 'New passwords do not match'));
        }
        
        // Check password strength
        if (strlen($new_password) < 8) {
            wp_send_json_error(array('message' => 'Password must be at least 8 characters'));
        }
        
        // Update password
        wp_set_password($new_password, $user->ID);
        
        // Re-authenticate user so they stay logged in
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID);
        
        wp_send_json_success(array('message' => 'Password updated successfully'));
    }
    
    /**
     * Update notification preferences
     */
    public static function update_notifications() {
        self::verify_nonce();
        self::require_login();
        
        $user_id = get_current_user_id();
        
        $preferences = array(
            'notify_booking' => !empty($_POST['notify_booking']),
            'notify_messages' => !empty($_POST['notify_messages']),
            'notify_reminders' => !empty($_POST['notify_reminders']),
            'notify_marketing' => !empty($_POST['notify_marketing']),
        );
        
        update_user_meta($user_id, 'ptp_notification_preferences', $preferences);
        
        wp_send_json_success(array('message' => 'Notification preferences saved'));
    }
    
    /**
     * Save trainer notification preferences (v92)
     */
    public static function save_notification_prefs() {
        self::verify_nonce('ptp_trainer_nonce');
        self::require_login();
        
        global $wpdb;
        
        $trainer_id = isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0;
        $prefs = isset($_POST['prefs']) ? json_decode(stripslashes($_POST['prefs']), true) : array();
        
        // Verify trainer ownership
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, user_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || $trainer->user_id != get_current_user_id()) {
            wp_send_json_error(array('message' => 'Unauthorized'));
            return;
        }
        
        // Save preferences as user meta
        update_user_meta($trainer->user_id, 'ptp_trainer_notif_sms_bookings', $prefs['sms_bookings'] ?? 1);
        update_user_meta($trainer->user_id, 'ptp_trainer_notif_sms_reminders', $prefs['sms_reminders'] ?? 1);
        update_user_meta($trainer->user_id, 'ptp_trainer_notif_sms_messages', $prefs['sms_messages'] ?? 1);
        
        wp_send_json_success(array('message' => 'Preferences saved'));
    }
    
    public static function submit_application() {
        self::verify_nonce();
        
        global $wpdb;
        
        // Auto-migrate: ensure all required columns exist
        $table = $wpdb->prefix . 'ptp_applications';
        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}", 0);
        
        $required_columns = array(
            'user_id' => "ALTER TABLE {$table} ADD COLUMN user_id bigint(20) UNSIGNED DEFAULT NULL AFTER id",
            'name' => "ALTER TABLE {$table} ADD COLUMN name varchar(100) NOT NULL DEFAULT '' AFTER email",
            'phone' => "ALTER TABLE {$table} ADD COLUMN phone varchar(20) DEFAULT '' AFTER name",
            'location' => "ALTER TABLE {$table} ADD COLUMN location varchar(255) DEFAULT '' AFTER phone",
            'college' => "ALTER TABLE {$table} ADD COLUMN college varchar(255) DEFAULT '' AFTER location",
            'team' => "ALTER TABLE {$table} ADD COLUMN team varchar(255) DEFAULT '' AFTER college",
            'playing_level' => "ALTER TABLE {$table} ADD COLUMN playing_level varchar(50) DEFAULT '' AFTER team",
            'position' => "ALTER TABLE {$table} ADD COLUMN position varchar(100) DEFAULT '' AFTER playing_level",
            'specialties' => "ALTER TABLE {$table} ADD COLUMN specialties text AFTER position",
            'instagram' => "ALTER TABLE {$table} ADD COLUMN instagram varchar(100) DEFAULT '' AFTER specialties",
            'facebook' => "ALTER TABLE {$table} ADD COLUMN facebook varchar(100) DEFAULT '' AFTER instagram",
            'headline' => "ALTER TABLE {$table} ADD COLUMN headline varchar(255) DEFAULT '' AFTER facebook",
            'bio' => "ALTER TABLE {$table} ADD COLUMN bio text AFTER headline",
            'hourly_rate' => "ALTER TABLE {$table} ADD COLUMN hourly_rate decimal(10,2) DEFAULT 0 AFTER bio",
            'travel_radius' => "ALTER TABLE {$table} ADD COLUMN travel_radius int(11) DEFAULT 15 AFTER hourly_rate",
        );
        
        foreach ($required_columns as $col => $sql) {
            if (!in_array($col, $existing_columns)) {
                $wpdb->query($sql);
            }
        }
        
        // Build name from first/last if provided separately
        $first_name = sanitize_text_field($_POST['first_name'] ?? '');
        $last_name = sanitize_text_field($_POST['last_name'] ?? '');
        $full_name = trim($first_name . ' ' . $last_name);
        if (empty($full_name)) {
            $full_name = sanitize_text_field($_POST['name'] ?? '');
        }
        
        // Build location from city/state if provided separately
        $city = sanitize_text_field($_POST['city'] ?? '');
        $state = sanitize_text_field($_POST['state'] ?? '');
        $location = trim($city . ', ' . $state, ', ');
        if (empty($location)) {
            $location = sanitize_text_field($_POST['location'] ?? '');
        }
        
        $email = sanitize_email($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        
        // Validate required fields
        if (empty($full_name) || empty($email)) {
            wp_send_json_error(array('message' => 'Name and email are required'));
        }
        
        // Validate password
        if (empty($password)) {
            wp_send_json_error(array('message' => 'Password is required'));
        }
        
        if (strlen($password) < 8) {
            wp_send_json_error(array('message' => 'Password must be at least 8 characters'));
        }
        
        if ($password !== $password_confirm) {
            wp_send_json_error(array('message' => 'Passwords do not match'));
        }
        
        // Check if email already exists
        if (email_exists($email)) {
            wp_send_json_error(array('message' => 'An account with this email already exists. Please log in or use a different email.'));
        }
        
        // Create WordPress user account
        $user_id = wp_create_user($email, $password, $email);
        
        if (is_wp_error($user_id)) {
            wp_send_json_error(array('message' => 'Could not create account: ' . $user_id->get_error_message()));
        }
        
        // Update user details
        wp_update_user(array(
            'ID' => $user_id,
            'first_name' => $first_name,
            'last_name' => $last_name,
            'display_name' => $full_name,
        ));
        
        // Add pending trainer role
        $user = get_user_by('ID', $user_id);
        $user->add_role('ptp_trainer');
        
        $data = array(
            'user_id' => $user_id,
            'name' => $full_name,
            'email' => $email,
            'phone' => sanitize_text_field($_POST['phone'] ?? ''),
            'location' => $location,
            'college' => sanitize_text_field($_POST['college'] ?? ''),
            'team' => sanitize_text_field($_POST['team'] ?? ''),
            'playing_level' => sanitize_text_field($_POST['playing_level'] ?? ''),
            'position' => sanitize_text_field($_POST['position'] ?? ''),
            'specialties' => is_array($_POST['specialties'] ?? null) ? implode(',', array_map('sanitize_text_field', $_POST['specialties'])) : '',
            'instagram' => sanitize_text_field($_POST['instagram'] ?? ''),
            'headline' => sanitize_text_field($_POST['headline'] ?? ''),
            'bio' => sanitize_textarea_field($_POST['bio'] ?? ''),
            'hourly_rate' => floatval($_POST['hourly_rate'] ?? 0),
            'travel_radius' => intval($_POST['travel_radius'] ?? 15),
            'status' => 'pending',
        );
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_applications', $data);
        
        if ($result === false) {
            error_log('PTP Application Insert Error: ' . $wpdb->last_error);
            wp_send_json_error(array('message' => 'Database error: ' . $wpdb->last_error));
        }
        
        // Send confirmation email to applicant
        if (class_exists('PTP_Email')) {
            PTP_Email::send_application_received($data['email'], $data['name']);
        }
        
        // Send notification to admin
        wp_mail(
            get_option('admin_email'),
            'New Trainer Application - ' . $data['name'],
            sprintf("New trainer application received:\n\nName: %s\nEmail: %s\nPhone: %s\nLocation: %s\nTeam/College: %s\nPlaying Level: %s\n\nReview in admin dashboard: %s",
                $data['name'], $data['email'], $data['phone'], $data['location'], 
                $data['team'] ?: $data['college'], $data['playing_level'],
                admin_url('admin.php?page=ptp-applications'))
        );
        
        wp_send_json_success(array('message' => 'Application submitted successfully! Your account has been created - you can log in once your application is approved.'));
    }
    
    public static function submit_review() {
        self::verify_nonce();
        self::require_login();
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $rating = intval($_POST['rating'] ?? 0);
        $review = sanitize_textarea_field($_POST['review'] ?? '');
        
        if (!$rating || $rating < 1 || $rating > 5) {
            wp_send_json_error(array('message' => 'Please select a rating'));
        }
        
        $result = PTP_Reviews::create($booking_id, $rating, $review);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Review submitted'));
    }
    
    public static function cancel_booking() {
        self::verify_nonce();
        self::require_login();
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['reason'] ?? '');
        
        $booking = PTP_Booking::get($booking_id);
        if (!$booking) {
            wp_send_json_error(array('message' => 'Booking not found'));
        }
        
        $user_id = get_current_user_id();
        $trainer = PTP_Trainer::get_by_user_id($user_id);
        $parent = PTP_Parent::get_by_user_id($user_id);
        
        // Verify ownership
        if ((!$trainer || $trainer->id != $booking->trainer_id) && (!$parent || $parent->id != $booking->parent_id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'cancelled',
                'cancelled_by' => $user_id,
                'cancellation_reason' => $reason,
                'cancelled_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        PTP_Notifications::booking_cancelled($booking_id, $user_id);
        
        wp_send_json_success(array('message' => 'Booking cancelled'));
    }
    
    /**
     * Remove a training item from cart session
     */
    public static function remove_training_item() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $key = sanitize_text_field($_POST['key'] ?? '');
        
        // Clear ALL training session data to ensure complete removal
        if (function_exists('WC') && WC()->session) {
            // Clear specific item from training_items array
            $training_items = WC()->session->get('ptp_training_items', array());
            if (!empty($key) && isset($training_items[$key])) {
                unset($training_items[$key]);
                WC()->session->set('ptp_training_items', $training_items);
            }
            
            // ALWAYS clear current training session
            WC()->session->set('ptp_current_training', null);
            
            // If removing URL training or session training, clear everything
            if ($key === 'url_training' || $key === 'session_training' || empty($training_items)) {
                WC()->session->set('ptp_training_items', array());
                WC()->session->set('ptp_current_training', null);
            }
        }
        
        wp_send_json_success(array('message' => 'Item removed', 'key' => $key));
    }
    
    /**
     * Clear all training session data (used when user wants to start fresh)
     */
    public static function clear_training_session() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (function_exists('WC') && WC()->session) {
            WC()->session->set('ptp_training_items', array());
            WC()->session->set('ptp_current_training', null);
        }
        
        wp_send_json_success(array('message' => 'Training session cleared'));
    }
    
    /**
     * Toggle jersey upsell in cart (v90)
     * World Cup 2026 x PTP Jersey - $50 add-on for summer camp orders
     */
    public static function toggle_jersey_upsell() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $add = sanitize_text_field($_POST['add'] ?? '0') === '1';
        
        if (function_exists('WC') && WC()->session) {
            // Ensure session is started
            if (!WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }
            
            WC()->session->set('ptp_jersey_upsell', $add);
            
            wp_send_json_success(array(
                'message' => $add ? 'Jersey added to cart' : 'Jersey removed from cart',
                'jersey_added' => $add,
                'price' => 50
            ));
        }
        
        wp_send_json_error(array('message' => 'Session error'));
    }
    
    /**
     * Add camp product to WooCommerce cart
     * Used by cart/checkout camp upsell
     */
    public static function add_camp_to_cart() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_cart')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $product_id = intval($_POST['product_id'] ?? 0);
        
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product'));
        }
        
        // Check product exists and is purchasable
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_purchasable()) {
            wp_send_json_error(array('message' => 'Product not available'));
        }
        
        // Check if already in cart
        if (function_exists('WC') && WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if ($cart_item['product_id'] == $product_id) {
                    wp_send_json_success(array(
                        'message' => 'Already in cart',
                        'already_added' => true
                    ));
                }
            }
            
            // Add to cart
            $cart_item_key = WC()->cart->add_to_cart($product_id);
            
            if ($cart_item_key) {
                wp_send_json_success(array(
                    'message' => 'Camp added to cart',
                    'cart_item_key' => $cart_item_key,
                    'product_name' => $product->get_name(),
                    'product_price' => $product->get_price()
                ));
            }
        }
        
        wp_send_json_error(array('message' => 'Could not add to cart'));
    }
    
    
    public static function update_player() {
        self::verify_nonce();
        self::require_login();
        
        $player_id = intval($_POST['player_id'] ?? 0);
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        
        if (!$parent || !PTP_Player::belongs_to_parent($player_id, $parent->id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        PTP_Player::update($player_id, $_POST);
        
        wp_send_json_success(array('message' => 'Player updated'));
    }
    
    public static function delete_player() {
        self::verify_nonce();
        self::require_login();
        
        $player_id = intval($_POST['player_id'] ?? 0);
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        
        if (!$parent || !PTP_Player::belongs_to_parent($player_id, $parent->id)) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        PTP_Player::delete($player_id);
        
        wp_send_json_success(array('message' => 'Player removed'));
    }
    
    /**
     * Create Stripe payment intent
     * Supports both existing booking and pre-booking (trainer_id + package)
     */
    public static function create_payment_intent() {
        self::verify_nonce();
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $package = intval($_POST['package'] ?? 1);
        
        // Check if Stripe is configured
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            wp_send_json_error(array('message' => 'Payment system not configured'));
        }
        
        // If we have a booking_id, use the existing flow
        if ($booking_id) {
            global $wpdb;
            $booking = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d
            ", $booking_id));
            
            if (!$booking) {
                wp_send_json_error(array('message' => 'Booking not found'));
            }
            
            // Verify ownership (if logged in)
            if (is_user_logged_in()) {
                $parent = PTP_Parent::get_by_user_id(get_current_user_id());
                if (!$parent || $parent->id != $booking->parent_id) {
                    wp_send_json_error(array('message' => 'Unauthorized'));
                }
            }
            
            $amount = $booking->total_amount;
            $metadata = array(
                'booking_id' => $booking_id,
                'trainer_id' => $booking->trainer_id,
                'parent_id' => $booking->parent_id,
            );
        } 
        // Pre-booking flow: calculate amount from trainer rate and package
        elseif ($trainer_id) {
            $trainer = PTP_Trainer::get($trainer_id);
            if (!$trainer) {
                wp_send_json_error(array('message' => 'Trainer not found'));
            }
            
            $rate = floatval($trainer->hourly_rate ?: 70);
            
            // Calculate package price
            switch ($package) {
                case 4:
                    $amount = round($rate * 4 * 0.95, 2);
                    break;
                case 8:
                    $amount = round($rate * 8 * 0.90, 2);
                    break;
                case 12:
                    $amount = round($rate * 12 * 0.85, 2);
                    break;
                default:
                    $amount = $rate;
            }
            
            $metadata = array(
                'trainer_id' => $trainer_id,
                'package' => $package,
                'type' => 'pre_booking',
            );
        } else {
            wp_send_json_error(array('message' => 'Invalid request'));
        }
        
        // Create payment intent
        $intent = PTP_Stripe::create_payment_intent($amount, $metadata);
        
        if (is_wp_error($intent)) {
            wp_send_json_error(array('message' => $intent->get_error_message()));
        }
        
        wp_send_json_success(array(
            'client_secret' => $intent['client_secret'],
            'amount' => $amount,
        ));
    }
    
    /**
     * Confirm booking after payment
     */
    public static function confirm_booking_payment() {
        self::verify_nonce();
        // Don't require login - guest bookings need this too
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        if (!$booking_id || !$payment_intent_id) {
            wp_send_json_error(array('message' => 'Invalid request'));
        }
        
        global $wpdb;
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'payment_intent_id' => $payment_intent_id,
                'payment_status' => 'paid',
                'status' => 'confirmed',
            ),
            array('id' => $booking_id),
            array('%s', '%s', '%s'),
            array('%d')
        );
        
        // Send notifications
        if (class_exists('PTP_Email')) {
            PTP_Email::send_booking_confirmation($booking_id);
            PTP_Email::send_trainer_new_booking($booking_id);
        }
        
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send_booking_confirmation($booking_id);
            PTP_SMS::send_trainer_new_booking($booking_id);
        }
        
        wp_send_json_success(array('message' => 'Payment confirmed'));
    }
    
    /**
     * Create Stripe Connect account for trainer
     */
    public static function create_stripe_connect_account() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        // Ensure PTP_Stripe is loaded and initialized
        if (!class_exists('PTP_Stripe')) {
            wp_send_json_error(array('message' => 'Payment system not available. Please contact support.'));
        }
        
        // Check configuration status
        $config = PTP_Stripe::get_config_status();
        
        if (!$config['has_secret_key']) {
            $mode = $config['test_mode'] ? 'Test' : 'Live';
            wp_send_json_error(array(
                'message' => "Stripe {$mode} Secret Key is not configured. Please add it in WordPress Admin → PTP Settings → Stripe."
            ));
        }
        
        if (!$config['connect_enabled']) {
            wp_send_json_error(array(
                'message' => 'Stripe Connect is not enabled. Please enable it in WordPress Admin → PTP Settings → Stripe → Enable Stripe Connect.'
            ));
        }
        
        // Try to start connect onboarding
        if (!method_exists('PTP_Stripe', 'start_connect_onboarding')) {
            wp_send_json_error(array('message' => 'Payment system method not available. Please update the plugin.'));
        }
        
        $result = PTP_Stripe::start_connect_onboarding($trainer->id);
        
        if (is_wp_error($result)) {
            $error_msg = $result->get_error_message();
            $error_code = $result->get_error_code();
            
            // Log for debugging
            error_log('PTP Stripe Connect Error for trainer ' . $trainer->id . ' [' . $error_code . ']: ' . $error_msg);
            
            // Provide friendly messages for common errors
            if (strpos($error_msg, 'signed up for Connect') !== false || strpos($error_msg, 'connect') !== false) {
                wp_send_json_error(array('message' => 'Stripe Connect must be set up in your Stripe Dashboard first. Go to Stripe Dashboard → Connect → Get Started.'));
            } elseif (strpos($error_msg, 'Invalid API Key') !== false || $error_code === 'no_api_key') {
                wp_send_json_error(array('message' => 'Invalid Stripe API key. Please check your keys in WordPress Admin → PTP Settings → Stripe.'));
            } elseif ($error_code === 'stripe_not_configured') {
                wp_send_json_error(array('message' => 'Stripe API keys are not configured. Please add them in WordPress Admin → PTP Settings → Stripe.'));
            } elseif ($error_code === 'connect_not_enabled') {
                wp_send_json_error(array('message' => 'Stripe Connect is disabled. Please enable it in WordPress Admin → PTP Settings → Stripe.'));
            } else {
                wp_send_json_error(array('message' => 'Unable to start payment setup: ' . $error_msg));
            }
        }
        
        wp_send_json_success(array(
            'url' => $result['url'],
            'message' => 'Redirecting to Stripe...'
        ));
    }
    
    /**
     * Get Stripe Connect dashboard link for trainer
     */
    public static function get_stripe_dashboard_link() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer || empty($trainer->stripe_account_id)) {
            wp_send_json_error(array('message' => 'No payment account found. Please connect your account first.'));
        }
        
        // First check if account needs to complete onboarding
        $account = PTP_Stripe::get_account($trainer->stripe_account_id);
        
        if (is_wp_error($account)) {
            wp_send_json_error(array('message' => 'Unable to access your payment account. Please reconnect.'));
        }
        
        // If not fully set up, send to onboarding
        if (!$account['charges_enabled'] || !$account['payouts_enabled']) {
            $link = PTP_Stripe::create_account_link($trainer->stripe_account_id);
            if (!is_wp_error($link)) {
                wp_send_json_success(array('url' => $link['url']));
            }
        }
        
        // Fully set up - send to dashboard
        $link = PTP_Stripe::create_login_link($trainer->stripe_account_id);
        
        if (is_wp_error($link)) {
            wp_send_json_error(array('message' => 'Unable to access Stripe dashboard. Please try again.'));
        }
        
        wp_send_json_success(array('url' => $link['url']));
    }
    
    /**
     * Save trainer coordinates from frontend geocoding
     */
    public static function save_trainer_coords() {
        $trainer_id = isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0;
        $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : 0;
        $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : 0;
        
        if (!$trainer_id || !$latitude || !$longitude) {
            wp_send_json_error(array('message' => 'Invalid data'));
        }
        
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array(
                'latitude' => $latitude,
                'longitude' => $longitude
            ),
            array('id' => $trainer_id),
            array('%f', '%f'),
            array('%d')
        );
        
        wp_send_json_success();
    }
    
    /**
     * Quick update availability for a specific day (inline editing from dashboard)
     * BULLETPROOF: Full validation, error handling, and fallbacks
     */
    public static function quick_update_availability() {
        // Log the incoming request for debugging (only in debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PTP Availability Save: day=' . ($_POST['day'] ?? 'none') . ', enabled=' . ($_POST['enabled'] ?? 'none'));
        }
        
        try {
            // Verify nonce
            $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
            if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_nonce')) {
                error_log('PTP Availability: Nonce verification failed');
                wp_send_json_error(array('message' => 'Security check failed. Please refresh the page.'));
                return;
            }
            
            // Check login
            if (!is_user_logged_in()) {
                error_log('PTP Availability: User not logged in');
                wp_send_json_error(array('message' => 'Please log in to continue'));
                return;
            }
            
            $user_id = get_current_user_id();
            
            // Get trainer
            $trainer = PTP_Trainer::get_by_user_id($user_id);
            if (!$trainer) {
                error_log('PTP Availability: Trainer not found for user ' . $user_id);
                wp_send_json_error(array('message' => 'Trainer profile not found'));
                return;
            }
            
            // Get and validate day
            $day = isset($_POST['day']) ? intval($_POST['day']) : -1;
            if ($day < 0 || $day > 6) {
                error_log('PTP Availability: Invalid day ' . $day);
                wp_send_json_error(array('message' => 'Invalid day selection'));
                return;
            }
            
            // Get and validate times
            $start = isset($_POST['start']) ? sanitize_text_field($_POST['start']) : '16:00';
            $end = isset($_POST['end']) ? sanitize_text_field($_POST['end']) : '20:00';
            
            // Clean up time format - remove any non-time characters
            $start = preg_replace('/[^0-9:]/', '', $start);
            $end = preg_replace('/[^0-9:]/', '', $end);
            
            // Ensure proper format
            if (!preg_match('/^\d{1,2}:\d{2}$/', $start)) $start = '16:00';
            if (!preg_match('/^\d{1,2}:\d{2}$/', $end)) $end = '20:00';
            
            // Pad to 5 characters (HH:MM)
            if (strlen($start) === 4) $start = '0' . $start;
            if (strlen($end) === 4) $end = '0' . $end;
            
            // Get enabled status
            $enabled_raw = isset($_POST['enabled']) ? $_POST['enabled'] : '0';
            $enabled = in_array($enabled_raw, array('1', 1, true, 'true'), true) ? 1 : 0;
            
            // Validate time logic
            $start_mins = intval(substr($start, 0, 2)) * 60 + intval(substr($start, 3, 2));
            $end_mins = intval(substr($end, 0, 2)) * 60 + intval(substr($end, 3, 2));
            if ($end_mins <= $start_mins) {
                error_log('PTP Availability: End time not after start time');
                wp_send_json_error(array('message' => 'End time must be after start time'));
                return;
            }
            
            // CRITICAL: Force table check/repair before any save operation
            if (class_exists('PTP_Availability')) {
                PTP_Availability::force_table_check();
            }
            
            global $wpdb;
            $table = $wpdb->prefix . 'ptp_availability';
            
            // Check for existing record
            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM $table WHERE trainer_id = %d AND day_of_week = %d",
                $trainer->id, $day
            ));
            
            $result = false;
            
            if ($existing && isset($existing->id)) {
                $result = $wpdb->update(
                    $table, 
                    array(
                        'start_time' => $start . ':00',
                        'end_time' => $end . ':00',
                        'is_active' => $enabled
                    ), 
                    array('id' => $existing->id)
                );
                error_log('PTP Availability: Updated existing record ' . $existing->id . ' result: ' . var_export($result, true));
            } else {
                $result = $wpdb->insert(
                    $table, 
                    array(
                        'trainer_id' => $trainer->id,
                        'day_of_week' => $day,
                        'start_time' => $start . ':00',
                        'end_time' => $end . ':00',
                        'is_active' => $enabled
                    )
                );
                error_log('PTP Availability: Inserted new record, result: ' . var_export($result, true));
            }
            
            if ($result === false) {
                $error = $wpdb->last_error;
                error_log('PTP Availability DB Error: ' . $error);
                wp_send_json_error(array('message' => 'Database error: ' . $error));
                return;
            }
            
            // Clear cache for this trainer's availability
            $current_month = date('n');
            $current_year = date('Y');
            wp_cache_delete('ptp_availability_' . $trainer->id . '_' . $current_year . '_' . $current_month, 'ptp');
            // Also clear next month in case they're booking ahead
            $next_month = $current_month == 12 ? 1 : $current_month + 1;
            $next_year = $current_month == 12 ? $current_year + 1 : $current_year;
            wp_cache_delete('ptp_availability_' . $trainer->id . '_' . $next_year . '_' . $next_month, 'ptp');
            
            wp_send_json_success(array(
                'message' => 'Availability saved',
                'day' => $day,
                'enabled' => $enabled,
                'start' => $start,
                'end' => $end
            ));
            
        } catch (Exception $e) {
            error_log('PTP Availability Exception: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }
    
    /**
     * Fallback handler for ptp_save_trainer_schedule
     * Routes to PTP_Availability::ajax_save_schedule if available
     */
    public static function save_trainer_schedule_fallback() {
        if (class_exists('PTP_Availability') && method_exists('PTP_Availability', 'ajax_save_schedule')) {
            PTP_Availability::ajax_save_schedule();
        } else {
            // Use the existing quick_update_availability logic
            self::quick_update_availability();
        }
    }
    
    /**
     * Toggle day availability on/off
     */
    public static function toggle_day_availability() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
            return;
        }
        
        $day = intval($_POST['day'] ?? -1);
        
        if ($day < 0 || $day > 6) {
            wp_send_json_error(array('message' => 'Invalid day'));
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_availability';
        
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, is_active FROM $table WHERE trainer_id = %d AND day_of_week = %d",
            $trainer->id, $day
        ));
        
        if ($existing) {
            $new_status = $existing->is_active ? 0 : 1;
            $wpdb->update($table, array('is_active' => $new_status), array('id' => $existing->id));
        } else {
            // Create new availability slot with default hours
            $wpdb->insert($table, array(
                'trainer_id' => $trainer->id,
                'day_of_week' => $day,
                'start_time' => '16:00:00',
                'end_time' => '20:00:00',
                'is_active' => 1
            ));
            $new_status = 1;
        }
        
        wp_send_json_success(array(
            'enabled' => (bool)$new_status,
            'message' => $new_status ? 'Day enabled' : 'Day disabled'
        ));
    }
    
    /**
     * Add availability exception (block specific date or add extra hours)
     */
    public static function add_availability_exception() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
            return;
        }
        
        $date = sanitize_text_field($_POST['date'] ?? '');
        $type = sanitize_text_field($_POST['type'] ?? 'blocked'); // blocked, available
        $start = sanitize_text_field($_POST['start'] ?? '');
        $end = sanitize_text_field($_POST['end'] ?? '');
        $reason = sanitize_text_field($_POST['reason'] ?? '');
        
        if (!$date || !strtotime($date)) {
            wp_send_json_error(array('message' => 'Invalid date'));
            return;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . 'ptp_availability_exceptions';
        
        // Create table if it doesn't exist
        $wpdb->query("CREATE TABLE IF NOT EXISTS $table (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            trainer_id bigint(20) UNSIGNED NOT NULL,
            exception_date date NOT NULL,
            exception_type enum('blocked','available') DEFAULT 'blocked',
            start_time time DEFAULT NULL,
            end_time time DEFAULT NULL,
            reason varchar(255) DEFAULT '',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY trainer_date (trainer_id, exception_date)
        ) " . $wpdb->get_charset_collate());
        
        $wpdb->insert($table, array(
            'trainer_id' => $trainer->id,
            'exception_date' => $date,
            'exception_type' => $type,
            'start_time' => $type === 'available' && $start ? $start . ':00' : null,
            'end_time' => $type === 'available' && $end ? $end . ':00' : null,
            'reason' => $reason
        ));
        
        wp_send_json_success(array(
            'id' => $wpdb->insert_id,
            'message' => $type === 'blocked' ? 'Date blocked' : 'Extra availability added'
        ));
    }
    
    /**
     * Remove availability exception
     */
    public static function remove_availability_exception() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
            return;
        }
        
        $exception_id = intval($_POST['exception_id'] ?? 0);
        
        if (!$exception_id) {
            wp_send_json_error(array('message' => 'Invalid exception ID'));
            return;
        }
        
        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'ptp_availability_exceptions',
            array('id' => $exception_id, 'trainer_id' => $trainer->id)
        );
        
        wp_send_json_success(array('message' => 'Exception removed'));
    }
    
    /**
     * Send message to trainer from public profile (logged in user)
     */
    public static function send_public_message() {
        self::verify_nonce();
        self::require_login();
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $subject = sanitize_text_field($_POST['subject'] ?? 'Inquiry from profile');
        
        if (!$trainer_id || empty($message)) {
            wp_send_json_error(array('message' => 'Trainer ID and message are required'));
            return;
        }
        
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $user_id = get_current_user_id();
        $user = wp_get_current_user();
        
        // Get or create conversation
        if (class_exists('PTP_Messaging')) {
            $conversation_id = PTP_Messaging::get_or_create_conversation($user_id, $trainer->user_id);
            
            if ($conversation_id) {
                PTP_Messaging::send_message($conversation_id, $user_id, $message);
                
                // Send email notification to trainer
                if (class_exists('PTP_Email')) {
                    PTP_Email::send_new_message_notification($trainer->user_id, $user->display_name, $message);
                }
                
                wp_send_json_success(array(
                    'message' => 'Message sent! The trainer will respond via the messaging system.',
                    'conversation_id' => $conversation_id
                ));
                return;
            }
        }
        
        // Fallback: Send email directly if messaging system not available
        $trainer_email = get_userdata($trainer->user_id)->user_email ?? '';
        if ($trainer_email) {
            $subject_line = '[PTP] New inquiry from ' . $user->display_name;
            $body = "You have a new message from {$user->display_name}:\n\n" . $message;
            $body .= "\n\n---\nReply to: " . $user->user_email;
            
            wp_mail($trainer_email, $subject_line, $body);
        }
        
        wp_send_json_success(array('message' => 'Message sent to trainer!'));
    }
    
    /**
     * Send message to trainer from public profile (guest user - creates inquiry)
     */
    public static function send_public_message_guest() {
        // Verify nonce from POST
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'ptp_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page.'));
            return;
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $email = sanitize_email($_POST['email'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        
        if (!$trainer_id || empty($message) || empty($name) || empty($email)) {
            wp_send_json_error(array('message' => 'Name, email, and message are required'));
            return;
        }
        
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        // Send email to trainer
        $trainer_email = get_userdata($trainer->user_id)->user_email ?? '';
        if ($trainer_email) {
            $subject = '[PTP] New inquiry from ' . $name;
            $body = "You have a new inquiry from your PTP profile:\n\n";
            $body .= "Name: $name\n";
            $body .= "Email: $email\n";
            if ($phone) $body .= "Phone: $phone\n";
            $body .= "\nMessage:\n" . $message;
            $body .= "\n\n---\nThis inquiry was sent from your PTP trainer profile.";
            
            wp_mail($trainer_email, $subject, $body, array('Reply-To: ' . $email));
        }
        
        // Also email schedule coordinator
        $coordinator_email = get_option('ptp_schedule_coordinator_email', 'luke@ptpsummercamps.com');
        if ($coordinator_email) {
            $subject = '[PTP] New trainer inquiry - ' . $trainer->display_name;
            $body = "New inquiry received:\n\n";
            $body .= "Trainer: " . $trainer->display_name . "\n";
            $body .= "From: $name ($email)\n";
            if ($phone) $body .= "Phone: $phone\n";
            $body .= "\nMessage:\n" . $message;
            
            wp_mail($coordinator_email, $subject, $body);
        }
        
        wp_send_json_success(array(
            'message' => 'Your message has been sent! ' . $trainer->display_name . ' will get back to you soon.'
        ));
    }
    
    /**
     * Get trainer availability for a month (for booking calendar)
     * Returns availability data for each day in the month
     * Format: { "2025-01-15": ["09:00", "10:00", "11:00"], ... }
     * v26.4: Added comprehensive debugging
     */
    public static function get_trainer_availability() {
        // Get parameters from GET or POST
        $trainer_id = isset($_GET['trainer_id']) ? intval($_GET['trainer_id']) : (isset($_POST['trainer_id']) ? intval($_POST['trainer_id']) : 0);
        $month = isset($_GET['month']) ? intval($_GET['month']) : (isset($_POST['month']) ? intval($_POST['month']) : date('n'));
        $year = isset($_GET['year']) ? intval($_GET['year']) : (isset($_POST['year']) ? intval($_POST['year']) : date('Y'));
        $debug = isset($_GET['debug']) || isset($_POST['debug']);
        
        $debug_info = array();
        
        // Validate trainer ID
        if (!$trainer_id || $trainer_id < 1) {
            wp_send_json_error(array('message' => 'Invalid trainer ID', 'trainer_id' => $trainer_id));
            return;
        }
        
        // Validate month (1-12)
        if ($month < 1 || $month > 12) {
            wp_send_json_error(array('message' => 'Invalid month'));
            return;
        }
        
        // Validate year (reasonable range)
        if ($year < 2020 || $year > 2100) {
            wp_send_json_error(array('message' => 'Invalid year'));
            return;
        }
        
        // Verify trainer exists and is active
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found', 'trainer_id' => $trainer_id));
            return;
        }
        if ($trainer->status !== 'active') {
            wp_send_json_error(array('message' => 'Trainer not active', 'status' => $trainer->status));
            return;
        }
        
        $debug_info['trainer'] = array('id' => $trainer->id, 'name' => $trainer->display_name, 'status' => $trainer->status);
        
        // Skip cache if debugging
        if (!$debug) {
            $cache_key = 'ptp_availability_' . $trainer_id . '_' . $year . '_' . $month;
            $cached = wp_cache_get($cache_key, 'ptp');
            if ($cached !== false) {
                wp_send_json_success(array('availability' => $cached, 'cached' => true));
                return;
            }
        }
        
        // Ensure availability class exists
        if (!class_exists('PTP_Availability')) {
            wp_send_json_error(array('message' => 'PTP_Availability class not found'));
            return;
        }
        
        // Check what's in the database for this trainer
        global $wpdb;
        $weekly_avail = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_availability WHERE trainer_id = %d ORDER BY day_of_week",
            $trainer_id
        ));
        
        $debug_info['weekly_availability_rows'] = count($weekly_avail);
        $debug_info['weekly_availability'] = array();
        foreach ($weekly_avail as $row) {
            $days = array('Sun','Mon','Tue','Wed','Thu','Fri','Sat');
            $debug_info['weekly_availability'][] = array(
                'day' => $days[$row->day_of_week] ?? $row->day_of_week,
                'start' => $row->start_time,
                'end' => $row->end_time,
                'active' => (bool)$row->is_active
            );
        }
        
        // Get all days in the month
        $days_in_month = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $today = date('Y-m-d');
        $availability = array();
        
        $debug_info['month'] = $month;
        $debug_info['year'] = $year;
        $debug_info['days_in_month'] = $days_in_month;
        $debug_info['today'] = $today;
        
        // Iterate through each day and get available slots
        for ($day = 1; $day <= $days_in_month; $day++) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            
            // Skip past dates
            if ($date < $today) {
                continue;
            }
            
            // Don't look more than 90 days ahead
            $date_ts = strtotime($date);
            $max_future = strtotime('+90 days');
            if ($date_ts > $max_future) {
                continue;
            }
            
            // Get available slots for this date
            $slots = PTP_Availability::get_available_slots($trainer_id, $date);
            
            if (!empty($slots) && is_array($slots)) {
                // Convert slots to simple time strings for frontend compatibility
                $time_strings = array();
                foreach ($slots as $slot) {
                    if (is_array($slot) && isset($slot['start'])) {
                        $time_strings[] = $slot['start'];
                    } elseif (is_object($slot) && isset($slot->start)) {
                        $time_strings[] = $slot->start;
                    } elseif (is_string($slot)) {
                        $time_strings[] = $slot;
                    }
                }
                
                if (!empty($time_strings)) {
                    $availability[$date] = $time_strings;
                }
            }
        }
        
        $debug_info['dates_with_availability'] = count($availability);
        
        // Cache for 5 minutes (skip if debugging)
        if (!$debug) {
            $cache_key = 'ptp_availability_' . $trainer_id . '_' . $year . '_' . $month;
            wp_cache_set($cache_key, $availability, 'ptp', 300);
        }
        
        $response = array('availability' => $availability);
        if ($debug) {
            $response['debug'] = $debug_info;
        }
        
        wp_send_json_success($response);
    }
    
    /**
     * Get training locations for current trainer
     */
    public static function get_training_locations() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $locations = array();
        if (!empty($trainer->training_locations)) {
            $decoded = json_decode($trainer->training_locations, true);
            if (is_array($decoded)) {
                $locations = $decoded;
            }
        }
        
        wp_send_json_success(array('locations' => $locations));
    }
    
    /**
     * Update training locations for current trainer
     */
    public static function update_training_locations() {
        self::verify_nonce();
        self::require_login();
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        // Get locations from POST
        $raw_locations = isset($_POST['locations']) ? $_POST['locations'] : array();
        
        // Handle JSON string or array
        if (is_string($raw_locations)) {
            $raw_locations = json_decode(stripslashes($raw_locations), true);
        }
        
        if (!is_array($raw_locations)) {
            $raw_locations = array();
        }
        
        $locations = array();
        $first_lat = null;
        $first_lng = null;
        
        foreach ($raw_locations as $loc) {
            if (!is_array($loc)) continue;
            
            $name = isset($loc['name']) ? sanitize_text_field($loc['name']) : '';
            if (empty($name)) continue;
            
            $lat = isset($loc['lat']) && $loc['lat'] !== '' ? floatval($loc['lat']) : null;
            $lng = isset($loc['lng']) && $loc['lng'] !== '' ? floatval($loc['lng']) : null;
            
            // Store first valid coordinates for trainer's main location
            if ($first_lat === null && $lat !== null && $lng !== null) {
                $first_lat = $lat;
                $first_lng = $lng;
            }
            
            $locations[] = array(
                'id' => isset($loc['id']) ? sanitize_text_field($loc['id']) : uniqid('loc_'),
                'name' => $name,
                'address' => isset($loc['address']) ? sanitize_text_field($loc['address']) : '',
                'lat' => $lat,
                'lng' => $lng,
            );
        }
        
        // Limit to 10 locations max
        $locations = array_slice($locations, 0, 10);
        
        // Build update data - include main lat/lng from first location
        $update_data = array(
            'training_locations' => !empty($locations) ? json_encode($locations) : null
        );
        
        // Also update trainer's main lat/lng so they show on the map
        if ($first_lat !== null && $first_lng !== null) {
            $update_data['latitude'] = $first_lat;
            $update_data['longitude'] = $first_lng;
        }
        
        // Update trainer
        $result = PTP_Trainer::update($trainer->id, $update_data);
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Failed to save locations'));
            return;
        }
        
        wp_send_json_success(array(
            'message' => 'Training locations updated',
            'locations' => $locations
        ));
    }
    
    /**
     * Process complete checkout - creates user, parent, player, booking, payment intent
     */
    public static function process_checkout() {
        error_log('PTP process_checkout: Starting...');
        
        // Use cart helper for nonce verification if available (standardized)
        if (class_exists('PTP_Cart_Helper')) {
            if (!PTP_Cart_Helper::verify_checkout_nonce()) {
                error_log('PTP process_checkout: Cart helper nonce failed');
                PTP_Cart_Helper::send_nonce_error();
                return;
            }
            // Rate limiting
            $rate_check = PTP_Cart_Helper::check_checkout_rate_limit();
            if (is_wp_error($rate_check)) {
                error_log('PTP process_checkout: Rate limit exceeded');
                PTP_Cart_Helper::send_rate_limit_error($rate_check);
                return;
            }
        } elseif (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_checkout_action')) {
            // Also try legacy nonce for backwards compatibility
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_checkout')) {
                error_log('PTP process_checkout: Nonce verification failed');
                wp_send_json_error(array('message' => 'Security check failed. Please refresh the page.'));
                return;
            }
        }
        
        global $wpdb;
        
        // Get form data
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $date = sanitize_text_field($_POST['date'] ?? '');
        $time = sanitize_text_field($_POST['time'] ?? '');
        $location = sanitize_text_field($_POST['location'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $notes = sanitize_textarea_field($_POST['player_notes'] ?? $_POST['notes'] ?? '');
        
        // Handle both package_type (from checkout.php) and session_type (legacy)
        $package_type = sanitize_text_field($_POST['package_type'] ?? $_POST['session_type'] ?? 'single');
        
        // Map package types to internal session types
        // Template sends: 'single', '5pack', '10pack'
        // Database expects: 'single', 'package_5', 'package_10'
        $session_type = $package_type;
        if ($package_type === '5pack') {
            $session_type = 'package_5';
        } elseif ($package_type === '10pack') {
            $session_type = 'package_10';
        }
        
        error_log("PTP process_checkout: trainer_id=$trainer_id, date=$date, time=$time, amount=$amount, package_type=$package_type, session_type=$session_type");
        
        // Validate session type
        $valid_types = array('single', 'package_5', 'package_10', 'group');
        if (!in_array($session_type, $valid_types)) {
            $session_type = 'single';
        }
        
        // Read session_count from POST if provided, otherwise calculate from session_type
        $session_count = intval($_POST['session_count'] ?? 0);
        if ($session_count <= 0) {
            $session_count = 1;
            if ($session_type === 'package_5') {
                $session_count = 5;
            } elseif ($session_type === 'package_10') {
                $session_count = 10;
            }
        }
        
        // For group sessions, count players
        $group_players = array();
        if ($session_type === 'group') {
            for ($i = 1; $i <= 3; $i++) {
                $name = sanitize_text_field($_POST["group_player_{$i}_name"] ?? '');
                $age = intval($_POST["group_player_{$i}_age"] ?? 0);
                if ($name && $age) {
                    $group_players[] = array('name' => $name, 'age' => $age);
                }
            }
        }
        
        // Validate required fields
        if (!$trainer_id || !$date || !$time || !$amount) {
            wp_send_json_error(array('message' => 'Missing required booking information'));
            return;
        }
        
        // Get trainer
        $trainer = PTP_Trainer::get($trainer_id);
        if (!$trainer || $trainer->status !== 'active') {
            wp_send_json_error(array('message' => 'Trainer is not available'));
            return;
        }
        
        // =====================================================
        // BUNDLE CHECKOUT: If user checked "add camp" checkbox,
        // create a bundle and redirect to unified bundle checkout
        // =====================================================
        $add_camp_id = intval($_POST['add_camp'] ?? 0);
        if ($add_camp_id > 0 && class_exists('PTP_Bundle_Checkout')) {
            error_log("PTP process_checkout: add_camp detected - redirecting to bundle checkout");
            
            // Map session_type to package format
            $package = 'single';
            if ($session_type === 'package_5') $package = '5pack';
            if ($session_type === 'package_10') $package = '10pack';
            
            // Create bundle with training details
            $bundle_checkout = PTP_Bundle_Checkout::instance();
            $bundle_result = $bundle_checkout->create_bundle_with_training(array(
                'trainer_id' => $trainer_id,
                'date' => $date,
                'time' => $time,
                'location' => $location,
                'package' => $package,
                'sessions' => $session_count,
                'amount' => $amount,
            ));
            
            if (is_wp_error($bundle_result)) {
                error_log("PTP process_checkout: Bundle creation failed - " . $bundle_result->get_error_message());
                wp_send_json_error(array('message' => 'Could not create bundle: ' . $bundle_result->get_error_message()));
                return;
            }
            
            $bundle_code = $bundle_result['bundle_code'];
            
            // Add camp to the bundle
            $camp_result = $bundle_checkout->add_camp_to_bundle($bundle_code, $add_camp_id);
            
            if (is_wp_error($camp_result)) {
                error_log("PTP process_checkout: Camp add failed - " . $camp_result->get_error_message());
                // Still redirect to bundle checkout - they can add camp there
            }
            
            // Store form data in session for bundle checkout to use
            if (!session_id() && !headers_sent()) {
                session_start();
            }
            $_SESSION['ptp_bundle_form_data'] = array(
                'email' => sanitize_email($_POST['email'] ?? ''),
                'first_name' => sanitize_text_field($_POST['first_name'] ?? ''),
                'last_name' => sanitize_text_field($_POST['last_name'] ?? ''),
                'phone' => sanitize_text_field($_POST['phone'] ?? ''),
                'player_first_name' => sanitize_text_field($_POST['player_first_name'] ?? ''),
                'player_last_name' => sanitize_text_field($_POST['player_last_name'] ?? ''),
                'player_age' => intval($_POST['player_age'] ?? 0),
                'player_skill' => sanitize_text_field($_POST['player_skill'] ?? ''),
                'player_notes' => sanitize_textarea_field($_POST['player_notes'] ?? ''),
            );
            
            // Return redirect response instead of payment intent
            wp_send_json_success(array(
                'redirect' => home_url('/bundle-checkout/?bundle=' . $bundle_code),
                'bundle_code' => $bundle_code,
                'message' => 'Bundle created! Redirecting to checkout...',
            ));
            return;
        }
        // =====================================================
        // END BUNDLE CHECKOUT
        // =====================================================
        
        $user_id = null;
        $parent_id = null;
        $player_id = null;
        
        // Check if logged in
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $parent = PTP_Parent::get_by_user_id($user_id);
            
            if (!$parent) {
                // Create parent profile
                $user = get_userdata($user_id);
                $parent_id = PTP_Parent::create($user_id, array(
                    'display_name' => $user->display_name,
                    'phone' => sanitize_text_field($_POST['phone'] ?? ''),
                ));
                if (is_wp_error($parent_id)) {
                    wp_send_json_error(array('message' => 'Could not create parent profile'));
                    return;
                }
            } else {
                $parent_id = $parent->id;
                // Update phone if provided
                $phone = sanitize_text_field($_POST['phone'] ?? '');
                if ($phone) {
                    $wpdb->update($wpdb->prefix . 'ptp_parents', array('phone' => $phone), array('id' => $parent_id));
                }
            }
        } else {
            // Guest checkout
            $email = sanitize_email($_POST['email'] ?? '');
            $first_name = sanitize_text_field($_POST['first_name'] ?? '');
            $last_name = sanitize_text_field($_POST['last_name'] ?? '');
            $phone = sanitize_text_field($_POST['phone'] ?? '');
            $create_account = isset($_POST['create_account']) && $_POST['create_account'] === '1';
            $password = $_POST['password'] ?? '';
            
            if (!$email || !$first_name) {
                wp_send_json_error(array('message' => 'Please provide your name and email'));
                return;
            }
            
            // Check if email exists
            $existing_user = get_user_by('email', $email);
            
            if ($existing_user) {
                // Use existing user's parent profile instead of blocking
                $user_id = $existing_user->ID;
                $parent = PTP_Parent::get_by_user_id($user_id);
                if ($parent) {
                    $parent_id = $parent->id;
                    // Update phone if provided
                    if ($phone) {
                        $wpdb->update($wpdb->prefix . 'ptp_parents', array('phone' => $phone), array('id' => $parent_id));
                    }
                } else {
                    // Create parent profile for existing user
                    $parent_id = PTP_Parent::create($user_id, array(
                        'display_name' => $first_name . ' ' . $last_name,
                        'phone' => $phone,
                    ));
                }
            } elseif ($create_account) {
                if (strlen($password) < 8) {
                    wp_send_json_error(array('message' => 'Password must be at least 8 characters'));
                    return;
                }
                
                // Create user account
                $user_id = wp_create_user($email, $password, $email);
                if (is_wp_error($user_id)) {
                    wp_send_json_error(array('message' => 'Could not create account: ' . $user_id->get_error_message()));
                    return;
                }
                
                wp_update_user(array(
                    'ID' => $user_id,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'display_name' => $first_name . ' ' . $last_name,
                    'role' => 'subscriber',
                ));
                
                // Log them in
                wp_set_current_user($user_id);
                wp_set_auth_cookie($user_id);
                
                // Create parent profile
                $parent_id = PTP_Parent::create($user_id, array(
                    'display_name' => $first_name . ' ' . $last_name,
                    'phone' => $phone,
                ));
            } else {
                // True guest checkout - no account needed
                // Store guest info directly in booking
                $user_id = 0; // Guest
                $parent_id = 0; // No parent record
                $guest_info = array(
                    'email' => $email,
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'phone' => $phone,
                );
            }
        }
        
        // Handle player
        $selected_player_id = $_POST['player_id'] ?? '';
        
        // For guest checkout or new player
        if ($selected_player_id === 'new' || empty($selected_player_id) || $parent_id === 0) {
            // Create new player (or store in notes for guest)
            $player_first = sanitize_text_field($_POST['player_first_name'] ?? '');
            $player_last = sanitize_text_field($_POST['player_last_name'] ?? '');
            $player_age = intval($_POST['player_age'] ?? 0);
            $player_skill = sanitize_text_field($_POST['player_skill'] ?? 'beginner');
            $player_notes = sanitize_textarea_field($_POST['player_notes'] ?? '');
            
            if (!$player_first || !$player_age) {
                wp_send_json_error(array('message' => 'Please provide player name and age'));
                return;
            }
            
            if ($parent_id > 0) {
                // Create player linked to parent
                $player_id = PTP_Player::create($parent_id, array(
                    'name' => $player_first . ' ' . $player_last,
                    'age' => $player_age,
                    'skill_level' => $player_skill,
                    'notes' => $player_notes,
                ));
                
                if (is_wp_error($player_id)) {
                    wp_send_json_error(array('message' => 'Could not create player profile'));
                    return;
                }
            } else {
                // Guest checkout - store player info in guest_info
                $player_id = 0;
                $guest_info['player_name'] = $player_first . ' ' . $player_last;
                $guest_info['player_age'] = $player_age;
                $guest_info['player_skill'] = $player_skill;
                $guest_info['player_notes'] = $player_notes;
            }
        } else {
            $player_id = intval($selected_player_id);
            // Verify player belongs to parent (skip for guests)
            if ($parent_id > 0 && !PTP_Player::belongs_to_parent($player_id, $parent_id)) {
                wp_send_json_error(array('message' => 'Invalid player selected'));
                return;
            }
        }
        
        // Create booking
        $session_datetime = $date . ' ' . $time;
        $end_time = date('H:i:s', strtotime($time . ' +1 hour'));
        
        // Build session type description
        $type_labels = array(
            'single' => 'Single Session',
            'package_5' => '5-Session Pack',
            'package_10' => '10-Session Pack',
            'group' => 'Group Session'
        );
        $type_label = $type_labels[$session_type] ?? 'Single Session';
        
        // For group sessions, add player info to notes
        $booking_notes = $notes;
        if ($session_type === 'group' && !empty($group_players)) {
            $player_list = array_map(function($p) { return $p['name'] . ' (age ' . $p['age'] . ')'; }, $group_players);
            $booking_notes .= "\n\nAdditional Players:\n- " . implode("\n- ", $player_list);
        }
        
        // For guest checkout, add guest info to notes
        if (isset($guest_info) && !empty($guest_info)) {
            $booking_notes .= "\n\n--- Guest Booking ---";
            $booking_notes .= "\nContact: " . $guest_info['first_name'] . ' ' . $guest_info['last_name'];
            $booking_notes .= "\nEmail: " . $guest_info['email'];
            $booking_notes .= "\nPhone: " . ($guest_info['phone'] ?: 'Not provided');
            $booking_notes .= "\nPlayer: " . ($guest_info['player_name'] ?? 'N/A');
            $booking_notes .= "\nPlayer Age: " . ($guest_info['player_age'] ?? 'N/A');
        }
        
        // Get guest email for Stripe receipt
        $customer_email = '';
        if (isset($guest_info['email'])) {
            $customer_email = $guest_info['email'];
        } elseif ($user_id > 0) {
            $user = get_userdata($user_id);
            $customer_email = $user ? $user->user_email : '';
        }
        
        // Get trainer's hourly rate
        $hourly_rate = floatval($trainer->hourly_rate ?: 80);
        
        $booking_data = array(
            'trainer_id' => $trainer_id,
            'parent_id' => $parent_id ?: 0,
            'player_id' => $player_id ?: 0,
            'session_date' => $date, // Just the date, not datetime
            'start_time' => $time,
            'end_time' => $end_time,
            'duration_minutes' => 60, // Fixed column name
            'location' => $location,
            'hourly_rate' => $hourly_rate, // Required field
            'total_amount' => $amount,
            'trainer_payout' => round($amount * 0.75, 2),
            'platform_fee' => round($amount * 0.25, 2),
            'notes' => $booking_notes,
            'status' => 'pending', // Fixed: use valid enum value
            'payment_status' => 'pending',
            'booking_number' => 'PTP-' . strtoupper(substr(md5(uniqid()), 0, 8)),
            'session_type' => $session_type,
            'session_count' => $session_count,
            'sessions_remaining' => $session_count,
        );
        
        // v118.1: Add guest_email to booking if guest checkout and column exists
        if (!empty($customer_email)) {
            // Check if column exists
            $columns = $wpdb->get_col("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings");
            if (in_array('guest_email', $columns)) {
                $booking_data['guest_email'] = $customer_email;
            }
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('PTP process_checkout: Creating booking for trainer_id=' . $trainer_id . ', amount=' . $amount);
        }
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $booking_data);
        $booking_id = $wpdb->insert_id;
        
        if (!$booking_id) {
            $db_error = $wpdb->last_error;
            error_log('PTP process_checkout: Failed to create booking - DB error: ' . $db_error);
            error_log('PTP process_checkout: Last query: ' . $wpdb->last_query);
            
            // Return user-friendly message but log detailed error
            $message = 'Could not create booking. ';
            if (strpos($db_error, 'Duplicate') !== false) {
                $message .= 'A booking may already exist for this time slot.';
            } elseif (strpos($db_error, "doesn't have a default value") !== false) {
                $message .= 'Missing required information. Please try again.';
            } else {
                $message .= 'Please try again or contact support.';
            }
            
            wp_send_json_error(array(
                'message' => $message,
                'debug' => WP_DEBUG ? $db_error : null
            ));
            return;
        }
        
        error_log("PTP process_checkout: Booking created with ID $booking_id");
        
        // Create Stripe Payment Intent
        if (!class_exists('PTP_Stripe')) {
            error_log('PTP process_checkout: PTP_Stripe class not found');
            wp_send_json_error(array('message' => 'Payment processing is not configured'));
            return;
        }
        
        if (!PTP_Stripe::is_enabled()) {
            error_log('PTP process_checkout: Stripe is not enabled');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $config = PTP_Stripe::get_config_status();
                error_log('PTP Stripe enabled: ' . ($config['is_enabled'] ? 'yes' : 'no') . ', test_mode: ' . ($config['test_mode'] ? 'yes' : 'no'));
            }
            wp_send_json_error(array('message' => 'Payment processing is not configured. Please check Stripe settings.'));
            return;
        }
        
        error_log('PTP process_checkout: Creating Stripe payment intent for amount ' . $amount);
        
        try {
            // Build metadata for Stripe
            $metadata = array(
                'booking_id' => $booking_id,
                'trainer_id' => $trainer_id,
                'parent_id' => $parent_id,
                'player_id' => $player_id,
                'session_type' => $session_type,
                'session_count' => $session_count,
            );
            
            // Use existing create_payment_intent method
            $payment_intent = PTP_Stripe::create_payment_intent($amount, $metadata);
            
            if (is_wp_error($payment_intent)) {
                error_log('PTP Stripe Error: ' . $payment_intent->get_error_message());
                wp_send_json_error(array('message' => 'Payment setup failed: ' . $payment_intent->get_error_message()));
                return;
            }
            
            if (empty($payment_intent['id']) || empty($payment_intent['client_secret'])) {
                error_log('PTP Stripe Error: Invalid payment intent response - missing id or client_secret');
                wp_send_json_error(array('message' => 'Payment setup failed: Invalid response from payment processor'));
                return;
            }
            
            error_log("PTP process_checkout: Payment intent created - ID: " . $payment_intent['id']);
            
            // Update booking with payment intent
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('payment_intent_id' => $payment_intent['id']),
                array('id' => $booking_id)
            );
            
            error_log("PTP process_checkout: SUCCESS - returning client_secret");
            
            wp_send_json_success(array(
                'booking_id' => $booking_id,
                'client_secret' => $payment_intent['client_secret'],
            ));
            
        } catch (Exception $e) {
            error_log('PTP Stripe Error: ' . $e->getMessage());
            wp_send_json_error(array('message' => 'Payment setup failed: ' . $e->getMessage()));
        }
    }
    
    /**
     * Confirm checkout payment and send emails
     * Updated to use standardized nonces and strict payment verification
     */
    public static function confirm_checkout_payment() {
        error_log('PTP confirm_checkout_payment: Starting...');
        
        // Use cart helper for nonce verification if available (standardized)
        if (class_exists('PTP_Cart_Helper')) {
            if (!PTP_Cart_Helper::verify_checkout_nonce()) {
                error_log('PTP confirm_checkout_payment: Cart helper nonce failed');
                PTP_Cart_Helper::send_nonce_error();
                return;
            }
        } elseif (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_checkout_action')) {
            // Also try legacy nonce for backwards compatibility
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'ptp_checkout')) {
                error_log('PTP confirm_checkout_payment: Nonce failed');
                wp_send_json_error(array('message' => 'Security check failed'));
                return;
            }
        }
        
        global $wpdb;
        
        $booking_id = intval($_POST['booking_id'] ?? 0);
        $payment_intent_id = sanitize_text_field($_POST['payment_intent_id'] ?? '');
        
        error_log("PTP confirm_checkout_payment: booking_id=$booking_id, intent=$payment_intent_id");
        
        if (!$booking_id || !$payment_intent_id) {
            wp_send_json_error(array('message' => 'Invalid payment data'));
            return;
        }
        
        // CRITICAL: Verify payment with Stripe first using cart helper
        if (class_exists('PTP_Cart_Helper')) {
            $verification = PTP_Cart_Helper::verify_stripe_payment($payment_intent_id);
            
            if (is_wp_error($verification)) {
                error_log('PTP confirm_checkout_payment: Payment verification failed - ' . $verification->get_error_message());
                wp_send_json_error(array(
                    'message' => $verification->get_error_message(),
                    'code' => $verification->get_error_code()
                ));
                return;
            }
            
            if ($verification !== true) {
                wp_send_json_error(array('message' => 'Payment could not be verified'));
                return;
            }
            
            error_log('PTP confirm_checkout_payment: Payment verified via cart helper');
        } else {
            // Fallback: Direct Stripe check with stricter verification
            $payment_verified = false;
            try {
                $payment_intent = PTP_Stripe::get_payment_intent($payment_intent_id);
                
                if (is_wp_error($payment_intent)) {
                    error_log('PTP confirm_checkout_payment: Stripe error - ' . $payment_intent->get_error_message());
                    wp_send_json_error(array('message' => 'Payment verification failed'));
                    return;
                }
                
                if (!empty($payment_intent['status']) && $payment_intent['status'] === 'succeeded') {
                    $payment_verified = true;
                    error_log('PTP confirm_checkout_payment: Stripe confirmed payment succeeded');
                } elseif (!empty($payment_intent['status']) && $payment_intent['status'] === 'processing') {
                    // Still processing - let it through but note it
                    $payment_verified = true;
                    error_log('PTP confirm_checkout_payment: Payment still processing');
                }
            } catch (Exception $e) {
                error_log('PTP confirm_checkout_payment: Exception - ' . $e->getMessage());
                wp_send_json_error(array('message' => 'Payment verification error'));
                return;
            }
            
            if (!$payment_verified) {
                wp_send_json_error(array('message' => 'Payment verification failed. Please contact support.'));
                return;
            }
        }
        
        // Start transaction
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::start_transaction();
        }
        
        try {
            // Get booking
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, 
                        t.display_name as trainer_name, t.user_id as trainer_user_id,
                        COALESCE(p.name, 'Guest Player') as player_name, 
                        COALESCE(p.age, 0) as player_age,
                        COALESCE(pa.display_name, 'Guest') as parent_name, 
                        COALESCE(pa.user_id, 0) as parent_user_id, 
                        COALESCE(pa.phone, '') as parent_phone
                 FROM {$wpdb->prefix}ptp_bookings b
                 JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                 LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                 LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
                 WHERE b.id = %d",
                $booking_id
            ));
            
            if (!$booking) {
                throw new Exception('Booking not found');
            }
            
            error_log('PTP confirm_checkout_payment: Got booking ' . $booking->booking_number);
            
            // Update booking status
            $updated = $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'confirmed',
                    'payment_status' => 'paid',
                    'paid_at' => current_time('mysql'),
                ),
                array('id' => $booking_id)
            );
            
            if ($updated === false) {
                throw new Exception('Could not update booking status');
            }
            
            error_log('PTP confirm_checkout_payment: Booking updated, rows=' . $updated);
            
            // Commit transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::commit_transaction();
                PTP_Cart_Helper::invalidate_cart_cache();
            }
            
            // v117.2.23: Send emails IMMEDIATELY (don't rely on WP Cron which is unreliable)
            try {
                self::do_send_booking_emails($booking_id);
                error_log('PTP confirm_checkout_payment: Sent emails immediately for booking ' . $booking_id);
                
                // Mark as sent so thank-you page doesn't duplicate
                set_transient('ptp_training_email_sent_' . $booking_id, array(
                    'time' => time(),
                    'source' => 'confirm_checkout_payment'
                ), 3600);
            } catch (Exception $email_ex) {
                // Non-fatal - log and continue, thank-you page will try again as fallback
                error_log('PTP confirm_checkout_payment: Email send failed - ' . $email_ex->getMessage());
            }
            
            // v117.2.23: Build redirect URL with NUMERIC booking_id (not booking_number)
            // The thank-you page uses intval() which fails on alphanumeric booking_number
            $redirect_url = home_url('/booking-confirmation/?booking_id=' . $booking_id . '&bn=' . urlencode($booking->booking_number));
            
            // Set cookie as backup for thank-you page to find the booking
            if (!headers_sent()) {
                setcookie('ptp_last_booking', $booking_id, time() + 3600, '/');
            }
            
            wp_send_json_success(array(
                'message' => 'Booking confirmed!',
                'redirect' => $redirect_url,
                'booking_id' => $booking_id,
                'booking_number' => $booking->booking_number,
            ));
            
        } catch (Exception $e) {
            // Rollback transaction
            if (class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::rollback_transaction();
            }
            
            error_log('PTP confirm_checkout_payment: Error - ' . $e->getMessage());
            error_log('CRITICAL: Payment ' . $payment_intent_id . ' may have succeeded but booking update failed');
            
            wp_send_json_error(array(
                'message' => 'Booking update failed. Your payment was received. Please contact support.',
                'payment_intent_id' => $payment_intent_id,
            ));
        }
    }
    
    /**
     * Schedule confirmation emails (called separately or via cron)
     */
    public static function schedule_booking_emails($booking_id) {
        // Schedule to run in 1 second (non-blocking)
        wp_schedule_single_event(time() + 1, 'ptp_send_booking_emails', array($booking_id));
    }
    
    /**
     * Actually send booking emails (called by cron or directly)
     */
    public static function do_send_booking_emails($booking_id) {
        global $wpdb;
        
        // v118.1: Include email fields directly from parent and trainer records
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    t.display_name as trainer_name, t.user_id as trainer_user_id, t.email as trainer_email,
                    COALESCE(p.name, 'Guest Player') as player_name, 
                    COALESCE(p.age, 0) as player_age,
                    COALESCE(pa.display_name, 'Guest') as parent_name, 
                    COALESCE(pa.user_id, 0) as parent_user_id, 
                    COALESCE(pa.phone, '') as parent_phone,
                    pa.email as parent_email
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             WHERE b.id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            error_log("[PTP AJAX] do_send_booking_emails: Booking #$booking_id not found");
            return;
        }
        
        $session_date = strtotime($booking->session_date);
        $email_data = array(
            'booking_number' => $booking->booking_number,
            'trainer_name' => $booking->trainer_name,
            'player_name' => $booking->player_name,
            'player_age' => $booking->player_age,
            'parent_name' => $booking->parent_name,
            'parent_phone' => $booking->parent_phone,
            'date' => date('l, F j, Y', $session_date),
            'time' => date('g:i A', strtotime($booking->start_time)) . ' - ' . date('g:i A', strtotime($booking->end_time)),
            'location' => $booking->location ?: 'To be confirmed',
            'total' => number_format($booking->total_amount, 2),
            'trainer_earnings' => number_format($booking->trainer_payout, 2),
            'notes' => $booking->notes,
        );
        
        self::send_booking_confirmation_emails($booking, $email_data);
    }
    
    /**
     * Send booking confirmation emails to parent, trainer, and admin
     * v118.1: Use email directly from records as fallback when WordPress user doesn't exist
     */
    private static function send_booking_confirmation_emails($booking, $data) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        // v118.1: Get email addresses with multiple fallbacks
        // Priority: WordPress user email > parent record email > guest_email from booking > parse from notes
        $parent_user = $booking->parent_user_id ? get_user_by('ID', $booking->parent_user_id) : false;
        $parent_email = '';
        
        if ($parent_user) {
            $parent_email = $parent_user->user_email;
        } elseif (!empty($booking->parent_email)) {
            $parent_email = $booking->parent_email;
        } elseif (!empty($booking->guest_email)) {
            $parent_email = $booking->guest_email;
        } else {
            // Last resort: try to parse from notes
            if (preg_match('/Email:\s*([^\s\n]+)/', $booking->notes ?? '', $matches)) {
                $parent_email = $matches[1];
            }
        }
        
        $trainer_user = $booking->trainer_user_id ? get_user_by('ID', $booking->trainer_user_id) : false;
        $trainer_email = $trainer_user ? $trainer_user->user_email : ($booking->trainer_email ?? '');
        
        $admin_email = get_option('admin_email');
        
        error_log("[PTP AJAX] send_booking_confirmation_emails: parent_email=$parent_email, trainer_email=$trainer_email");
        
        // Email template base styles
        $style_wrap = 'font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;';
        $style_card = 'background: #ffffff; border-radius: 12px; padding: 24px; margin-bottom: 20px; border: 1px solid #e5e7eb;';
        $style_header = 'background: #0E0F11; color: #ffffff; padding: 24px; border-radius: 12px 12px 0 0; text-align: center;';
        $style_h1 = 'margin: 0; font-size: 24px; font-weight: 700;';
        $style_detail = 'display: flex; padding: 12px 0; border-bottom: 1px solid #f3f4f6;';
        $style_label = 'color: #6b7280; font-size: 14px; width: 120px;';
        $style_value = 'color: #111827; font-size: 14px; font-weight: 600;';
        $style_btn = 'display: inline-block; background: #FCB900; color: #0E0F11; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: 700; font-size: 15px;';
        
        // 1. EMAIL TO PARENT
        if ($parent_email) {
            $parent_subject = "Booking Confirmed! Session with {$data['trainer_name']} - {$data['booking_number']}";
            
            $parent_body = "
            <div style='{$style_wrap}'>
                <div style='{$style_header}'>
                    <h1 style='{$style_h1}'>Booking Confirmed! ✓</h1>
                    <p style='margin: 8px 0 0; opacity: 0.8;'>Reference: {$data['booking_number']}</p>
                </div>
                <div style='{$style_card}'>
                    <h2 style='margin: 0 0 20px; font-size: 18px; color: #111;'>Session Details</h2>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Trainer</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['trainer_name']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Player</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['player_name']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Date</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['date']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Time</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['time']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Location</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['location']}</td>
                        </tr>
                        <tr>
                            <td style='padding: 16px 0 0; color: #111; font-size: 16px; font-weight: 700;'>Total Paid</td>
                            <td style='padding: 16px 0 0; color: #111; font-size: 20px; font-weight: 800; text-align: right;'>\${$data['total']}</td>
                        </tr>
                    </table>
                </div>
                <div style='text-align: center; padding: 20px 0;'>
                    <a href='" . home_url('/my-training/') . "' style='{$style_btn}'>View My Sessions</a>
                </div>
                <div style='text-align: center; color: #6b7280; font-size: 13px; padding: 20px 0;'>
                    <p>Free cancellation up to 24 hours before your session.</p>
                    <p style='margin-top: 8px;'>Questions? Reply to this email.</p>
                </div>
            </div>";
            
            $sent = wp_mail($parent_email, $parent_subject, $parent_body, $headers);
            error_log("[PTP AJAX] Parent email to $parent_email: " . ($sent ? 'sent' : 'failed'));
        } else {
            error_log("[PTP AJAX] No parent email available for booking");
        }
        
        // 2. EMAIL TO TRAINER
        if ($trainer_email) {
            $trainer_subject = "New Booking! {$data['player_name']} - {$data['date']}";
            
            $trainer_body = "
            <div style='{$style_wrap}'>
                <div style='{$style_header}'>
                    <h1 style='{$style_h1}'>New Session Booked!</h1>
                    <p style='margin: 8px 0 0; opacity: 0.8;'>You have a new training session</p>
                </div>
                <div style='{$style_card}'>
                    <h2 style='margin: 0 0 20px; font-size: 18px; color: #111;'>Session Details</h2>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Player</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['player_name']} ({$data['player_age']} yrs)</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Parent</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['parent_name']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Phone</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['parent_phone']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Date</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['date']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Time</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['time']}</td>
                        </tr>
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Location</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['location']}</td>
                        </tr>";
            
            if (!empty($data['notes'])) {
                $trainer_body .= "
                        <tr style='border-bottom: 1px solid #f3f4f6;'>
                            <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Notes</td>
                            <td style='padding: 12px 0; color: #111; font-size: 14px; text-align: right;'>{$data['notes']}</td>
                        </tr>";
            }
            
            $trainer_body .= "
                        <tr>
                            <td style='padding: 16px 0 0; color: #059669; font-size: 16px; font-weight: 700;'>Your Earnings</td>
                            <td style='padding: 16px 0 0; color: #059669; font-size: 20px; font-weight: 800; text-align: right;'>\${$data['trainer_earnings']}</td>
                        </tr>
                    </table>
                </div>
                <div style='text-align: center; padding: 20px 0;'>
                    <a href='" . home_url('/trainer-dashboard/') . "' style='{$style_btn}'>View Dashboard</a>
                </div>
            </div>";
            
            $sent = wp_mail($trainer_email, $trainer_subject, $trainer_body, $headers);
            error_log("[PTP AJAX] Trainer email to $trainer_email: " . ($sent ? 'sent' : 'failed'));
        } else {
            error_log("[PTP AJAX] No trainer email available for booking");
        }
        
        // 3. EMAIL TO ADMIN
        $admin_subject = "[PTP] New Booking: {$data['player_name']} with {$data['trainer_name']} - \${$data['total']}";
        
        $admin_body = "
        <div style='{$style_wrap}'>
            <div style='{$style_header}'>
                <h1 style='{$style_h1}'>New Booking Received</h1>
                <p style='margin: 8px 0 0; opacity: 0.8;'>Ref: {$data['booking_number']}</p>
            </div>
            <div style='{$style_card}'>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr style='border-bottom: 1px solid #f3f4f6;'>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Trainer</td>
                        <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['trainer_name']}</td>
                    </tr>
                    <tr style='border-bottom: 1px solid #f3f4f6;'>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Parent</td>
                        <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['parent_name']}</td>
                    </tr>
                    <tr style='border-bottom: 1px solid #f3f4f6;'>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Player</td>
                        <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['player_name']} ({$data['player_age']} yrs)</td>
                    </tr>
                    <tr style='border-bottom: 1px solid #f3f4f6;'>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Date/Time</td>
                        <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['date']}<br>{$data['time']}</td>
                    </tr>
                    <tr style='border-bottom: 1px solid #f3f4f6;'>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Location</td>
                        <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>{$data['location']}</td>
                    </tr>
                    <tr style='border-bottom: 1px solid #f3f4f6;'>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Total Charged</td>
                        <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>\${$data['total']}</td>
                    </tr>
                    <tr style='border-bottom: 1px solid #f3f4f6;'>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Trainer Payout</td>
                        <td style='padding: 12px 0; color: #111; font-size: 14px; font-weight: 600; text-align: right;'>\${$data['trainer_earnings']}</td>
                    </tr>
                    <tr>
                        <td style='padding: 12px 0; color: #6b7280; font-size: 14px;'>Platform Fee</td>
                        <td style='padding: 12px 0; color: #059669; font-size: 14px; font-weight: 600; text-align: right;'>\$" . number_format($booking->platform_fee, 2) . "</td>
                    </tr>
                </table>
            </div>
            <div style='text-align: center; padding: 20px 0;'>
                <a href='" . admin_url('admin.php?page=ptp-bookings') . "' style='{$style_btn}'>View in Admin</a>
            </div>
        </div>";
        
        wp_mail($admin_email, $admin_subject, $admin_body, $headers);
    }
    
    /**
     * Save a base64 encoded image to the uploads directory
     */
    private static function save_base64_image($base64_data) {
        // Extract the image data
        if (preg_match('/^data:image\/(\w+);base64,/', $base64_data, $matches)) {
            $type = $matches[1];
            $data = substr($base64_data, strpos($base64_data, ',') + 1);
            $data = base64_decode($data);
            
            if ($data === false) {
                return array('error' => 'Invalid base64 data');
            }
            
            // Validate image type
            $allowed_types = array('jpeg', 'jpg', 'png', 'webp');
            if (!in_array(strtolower($type), $allowed_types)) {
                return array('error' => 'Invalid image type');
            }
            
            // Generate unique filename
            $filename = 'gallery-' . wp_generate_password(12, false) . '.' . $type;
            
            // Get upload directory
            $upload_dir = wp_upload_dir();
            $upload_path = $upload_dir['path'] . '/' . $filename;
            $upload_url = $upload_dir['url'] . '/' . $filename;
            
            // Save the file
            if (file_put_contents($upload_path, $data)) {
                return array(
                    'url' => $upload_url,
                    'file' => $upload_path,
                    'type' => 'image/' . $type
                );
            }
            
            return array('error' => 'Failed to save image');
        }
        
        return array('error' => 'Invalid image format');
    }
    
    /**
     * Save trainer profile from Profile Editor v60
     * Handles all profile fields including availability, locations, gallery
     */
    public static function save_trainer_profile() {
        // Check nonce - support both old and new nonce formats
        $nonce_valid = false;
        if (isset($_POST['nonce']) && wp_verify_nonce($_POST['nonce'], 'ptp_nonce')) {
            $nonce_valid = true;
        }
        if (isset($_POST['ptp_profile_nonce']) && wp_verify_nonce($_POST['ptp_profile_nonce'], 'ptp_update_trainer_profile')) {
            $nonce_valid = true;
        }
        
        if (!$nonce_valid) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page.'));
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to continue'));
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        // Check if this is a single field update (new v69 style)
        if (isset($_POST['field']) && isset($_POST['value'])) {
            $field = sanitize_key($_POST['field']);
            $value = $_POST['value'];
            
            // Allowed fields
            $allowed_text = array('display_name', 'headline', 'college', 'position', 'location', 'playing_level', 'instagram', 'twitter', 'video_url', 'coaching_why', 'training_philosophy', 'training_policy');
            $allowed_numeric = array('hourly_rate', 'travel_radius');
            $allowed_json = array('specialties', 'gallery');
            $allowed_textarea = array('bio', 'coaching_why', 'training_philosophy', 'training_policy');
            
            $data = array();
            
            if (in_array($field, $allowed_text) && !in_array($field, $allowed_textarea)) {
                $data[$field] = sanitize_text_field($value);
            } elseif (in_array($field, $allowed_textarea)) {
                $data[$field] = sanitize_textarea_field($value);
            } elseif (in_array($field, $allowed_numeric)) {
                $data[$field] = floatval($value);
            } elseif (in_array($field, $allowed_json)) {
                // JSON fields - specialties, gallery
                $decoded = json_decode(stripslashes($value), true);
                if (is_array($decoded)) {
                    $data[$field] = json_encode(array_map('sanitize_text_field', $decoded));
                }
            } else {
                wp_send_json_error(array('message' => 'Invalid field'));
                return;
            }
            
            if (!empty($data)) {
                global $wpdb;
                $result = $wpdb->update(
                    $wpdb->prefix . 'ptp_trainers',
                    $data,
                    array('id' => $trainer->id)
                );
                
                if ($result !== false) {
                    wp_send_json_success(array('message' => 'Saved'));
                } else {
                    wp_send_json_error(array('message' => 'Database error'));
                }
            }
            return;
        }
        
        // Legacy bulk update mode
        $data = array();
        
        // Basic fields
        $text_fields = array('display_name', 'headline', 'bio', 'college', 'position', 'location', 'playing_level');
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        // Bio needs textarea sanitization
        if (isset($_POST['bio'])) {
            $data['bio'] = sanitize_textarea_field($_POST['bio']);
        }
        
        // Numeric fields
        if (isset($_POST['hourly_rate'])) {
            $data['hourly_rate'] = floatval($_POST['hourly_rate']);
        }
        if (isset($_POST['travel_radius'])) {
            $data['travel_radius'] = intval($_POST['travel_radius']);
        }
        
        // Photo URL
        if (isset($_POST['photo_url']) && !empty($_POST['photo_url'])) {
            $data['photo_url'] = esc_url_raw($_POST['photo_url']);
        }
        
        // Video URL
        if (isset($_POST['intro_video_url'])) {
            $video_url = esc_url_raw($_POST['intro_video_url']);
            if (!empty($video_url) && (
                strpos($video_url, 'youtube.com') !== false || 
                strpos($video_url, 'youtu.be') !== false ||
                strpos($video_url, 'vimeo.com') !== false
            )) {
                $data['intro_video_url'] = $video_url;
            } else {
                $data['intro_video_url'] = '';
            }
        }
        
        // Specialties
        if (isset($_POST['specialties']) && is_array($_POST['specialties'])) {
            $data['specialties'] = json_encode(array_map('sanitize_text_field', $_POST['specialties']));
        }
        
        // Gallery (JSON string from frontend)
        if (isset($_POST['gallery'])) {
            $gallery = json_decode(stripslashes($_POST['gallery']), true);
            if (is_array($gallery)) {
                $data['gallery'] = json_encode(array_map('esc_url_raw', $gallery));
            }
        }
        
        // Training locations (JSON string from frontend)
        if (isset($_POST['training_locations'])) {
            $locations = json_decode(stripslashes($_POST['training_locations']), true);
            if (is_array($locations)) {
                $clean_locations = array();
                foreach ($locations as $loc) {
                    if (!empty($loc['name'])) {
                        $clean_locations[] = array(
                            'id' => sanitize_text_field($loc['id'] ?? uniqid('loc_')),
                            'name' => sanitize_text_field($loc['name']),
                            'address' => sanitize_text_field($loc['address'] ?? ''),
                            'lat' => isset($loc['lat']) ? floatval($loc['lat']) : null,
                            'lng' => isset($loc['lng']) ? floatval($loc['lng']) : null,
                        );
                    }
                }
                $data['training_locations'] = json_encode($clean_locations);
            }
        }
        
        // Social links
        if (isset($_POST['instagram'])) {
            $instagram = sanitize_text_field($_POST['instagram']);
            $instagram = preg_replace('/^@/', '', $instagram);
            $instagram = preg_replace('/^(https?:\/\/)?(www\.)?instagram\.com\//', '', $instagram);
            $data['instagram'] = preg_replace('/\/$/', '', $instagram);
        }
        if (isset($_POST['facebook'])) {
            $data['facebook'] = esc_url_raw($_POST['facebook']);
        }
        
        // Update trainer
        $result = PTP_Trainer::update($trainer->id, $data);
        
        // Handle availability
        if (isset($_POST['availability']) && is_array($_POST['availability']) && class_exists('PTP_Availability')) {
            foreach ($_POST['availability'] as $day_index => $avail) {
                $is_enabled = !empty($avail['enabled']) && $avail['enabled'] === '1';
                $start_time = sanitize_text_field($avail['start'] ?? '09:00');
                $end_time = sanitize_text_field($avail['end'] ?? '18:00');
                
                PTP_Availability::set_weekly_slot(
                    $trainer->id,
                    intval($day_index),
                    $is_enabled,
                    $start_time,
                    $end_time
                );
            }
        }
        
        wp_send_json_success(array(
            'message' => 'Profile updated successfully',
            'completeness' => class_exists('PTP_Trainer_Profile') ? PTP_Trainer_Profile::get_completeness_score($trainer) : null
        ));
    }
    
    /**
     * Upload gallery image for trainer profile
     */
    public static function upload_gallery_image() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_upload_gallery')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to continue'));
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer profile not found'));
        }
        
        if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'No file uploaded or upload error'));
        }
        
        $file = $_FILES['image'];
        
        // Validate file type
        $allowed_types = array('image/jpeg', 'image/png', 'image/webp');
        if (!in_array($file['type'], $allowed_types)) {
            wp_send_json_error(array('message' => 'Invalid file type. Please upload JPG, PNG, or WebP.'));
        }
        
        // Validate file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            wp_send_json_error(array('message' => 'File too large. Maximum size is 5MB.'));
        }
        
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $upload = wp_handle_upload($file, array('test_form' => false));
        
        if (isset($upload['error'])) {
            wp_send_json_error(array('message' => $upload['error']));
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => $upload['type'],
            'post_title' => sanitize_file_name($file['name']),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        wp_send_json_success(array(
            'url' => $upload['url'],
            'attachment_id' => $attach_id
        ));
    }
    
    /**
     * Remove item from WooCommerce cart
     */
    public static function remove_cart_item() {
        // Check nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_cart_action')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $cart_item_key = isset($_POST['cart_item_key']) ? sanitize_text_field($_POST['cart_item_key']) : '';
        
        if (empty($cart_item_key)) {
            wp_send_json_error(array('message' => 'No item specified'));
        }
        
        if (!function_exists('WC') || !WC()->cart) {
            wp_send_json_error(array('message' => 'Cart not available'));
        }
        
        // Get cart from session
        WC()->cart->get_cart_from_session();
        
        // Remove the item
        $removed = WC()->cart->remove_cart_item($cart_item_key);
        
        if ($removed) {
            WC()->cart->calculate_totals();
            
            // Check if cart is now empty
            $cart_empty = WC()->cart->is_empty();
            
            // Also clear any training items if cart is empty
            if ($cart_empty && class_exists('PTP_Cart_Helper')) {
                PTP_Cart_Helper::clear_training_items();
            }
            
            wp_send_json_success(array(
                'message' => 'Item removed',
                'cart_empty' => $cart_empty,
                'redirect' => $cart_empty ? home_url('/shop/') : ''
            ));
        } else {
            wp_send_json_error(array('message' => 'Could not remove item'));
        }
    }
    
    /**
     * Remove training from cart
     */
    public static function remove_training_from_cart() {
        // Check nonce
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_cart_action')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        // Clear training items from session
        if (class_exists('PTP_Cart_Helper')) {
            PTP_Cart_Helper::clear_training_items();
        }
        
        // Also clear bundle if exists
        if (WC()->session) {
            WC()->session->set('ptp_active_bundle', null);
            WC()->session->set('ptp_training_items', array());
        }
        
        // Check if we still have camp items
        $has_camps = false;
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->get_cart_from_session();
            $has_camps = !WC()->cart->is_empty();
        }
        
        wp_send_json_success(array(
            'message' => 'Training removed',
            'has_camps' => $has_camps,
            'redirect' => $has_camps ? '' : home_url('/find-trainers/')
        ));
    }
    
    /**
     * Save availability v92 - for trainer dashboard SPA
     */
    public static function save_availability_v92() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_trainer_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $availability_json = isset($_POST['availability']) ? stripslashes($_POST['availability']) : '[]';
        $availability = json_decode($availability_json, true);
        
        if (!$trainer_id || !is_array($availability)) {
            wp_send_json_error(array('message' => 'Invalid data'));
        }
        
        // Verify user owns this trainer
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND user_id = %d",
            $trainer_id, get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Clear existing availability
        $wpdb->delete($wpdb->prefix . 'ptp_availability', array('trainer_id' => $trainer_id));
        
        // Insert new availability
        foreach ($availability as $slot) {
            $wpdb->insert($wpdb->prefix . 'ptp_availability', array(
                'trainer_id' => $trainer_id,
                'day_of_week' => intval($slot['day_of_week']),
                'start_time' => sanitize_text_field($slot['start_time']) . ':00',
                'end_time' => sanitize_text_field($slot['end_time']) . ':00',
                'is_active' => intval($slot['is_active']),
                'created_at' => current_time('mysql'),
            ));
        }
        
        wp_send_json_success(array('message' => 'Availability saved'));
    }
    
    /**
     * Update trainer hourly rate
     */
    public static function update_trainer_rate() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_trainer_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        $rate = intval($_POST['rate'] ?? 0);
        
        if (!$trainer_id || $rate < 20 || $rate > 500) {
            wp_send_json_error(array('message' => 'Invalid rate'));
        }
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND user_id = %d",
            $trainer_id, get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('hourly_rate' => $rate, 'updated_at' => current_time('mysql')),
            array('id' => $trainer_id)
        );
        
        wp_send_json_success(array('message' => 'Rate updated'));
    }
    
    /**
     * Request payout for trainer
     */
    public static function request_payout() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_trainer_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND user_id = %d",
            $trainer_id, get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Get pending payout amount
        $pending = floatval($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer_id
        )));
        
        if ($pending < 10) {
            wp_send_json_error(array('message' => 'Minimum payout is $10'));
        }
        
        // Check if trainer has Stripe connected
        if (empty($trainer->stripe_account_id)) {
            wp_send_json_error(array('message' => 'Please connect Stripe first'));
        }
        
        // Create payout record
        $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
            'trainer_id' => $trainer_id,
            'amount' => $pending,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
        ));
        
        // Update booking payout statuses to 'processing'
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->prefix}ptp_bookings 
             SET payout_status = 'processing' 
             WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'",
            $trainer_id
        ));
        
        wp_send_json_success(array('message' => 'Payout requested! Processing in 2-3 business days.'));
    }
    
    /**
     * Quick profile update from trainer dashboard v92
     */
    public static function update_trainer_profile_quick() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_trainer_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND user_id = %d",
            $trainer_id, get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $update_data = array('updated_at' => current_time('mysql'));
        
        if (isset($_POST['display_name'])) {
            $update_data['display_name'] = sanitize_text_field($_POST['display_name']);
        }
        if (isset($_POST['bio'])) {
            $update_data['bio'] = sanitize_textarea_field($_POST['bio']);
        }
        if (isset($_POST['phone'])) {
            $update_data['phone'] = sanitize_text_field($_POST['phone']);
        }
        if (isset($_POST['city'])) {
            $update_data['city'] = sanitize_text_field($_POST['city']);
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            $update_data,
            array('id' => $trainer_id)
        );
        
        wp_send_json_success(array('message' => 'Profile saved'));
    }
    
    /**
     * v116: Update trainer profile with all fields
     */
    public static function update_trainer_profile_full() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (empty($nonce) || !wp_verify_nonce($nonce, 'ptp_trainer_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
        }
        
        $trainer_id = intval($_POST['trainer_id'] ?? 0);
        
        global $wpdb;
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND user_id = %d",
            $trainer_id, get_current_user_id()
        ));
        
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $update_data = array('updated_at' => current_time('mysql'));
        
        // Basic info
        if (isset($_POST['display_name']) && !empty($_POST['display_name'])) {
            $update_data['display_name'] = sanitize_text_field($_POST['display_name']);
        }
        if (isset($_POST['headline'])) {
            $update_data['headline'] = sanitize_text_field($_POST['headline']);
        }
        if (isset($_POST['bio'])) {
            $update_data['bio'] = sanitize_textarea_field($_POST['bio']);
        }
        
        // Background
        if (isset($_POST['college'])) {
            $update_data['college'] = sanitize_text_field($_POST['college']);
        }
        if (isset($_POST['team'])) {
            $update_data['team'] = sanitize_text_field($_POST['team']);
        }
        if (isset($_POST['position'])) {
            $update_data['position'] = sanitize_text_field($_POST['position']);
        }
        if (isset($_POST['playing_level'])) {
            $update_data['playing_level'] = sanitize_text_field($_POST['playing_level']);
        }
        if (isset($_POST['specialties'])) {
            $specialties = json_decode(stripslashes($_POST['specialties']), true);
            if (is_array($specialties)) {
                $update_data['specialties'] = wp_json_encode(array_map('sanitize_text_field', $specialties));
            }
        }
        
        // Contact
        if (isset($_POST['phone'])) {
            $update_data['phone'] = sanitize_text_field($_POST['phone']);
        }
        if (isset($_POST['city'])) {
            $update_data['city'] = sanitize_text_field($_POST['city']);
        }
        if (isset($_POST['state'])) {
            $update_data['state'] = sanitize_text_field($_POST['state']);
        }
        if (isset($_POST['travel_radius'])) {
            $update_data['travel_radius'] = intval($_POST['travel_radius']);
        }
        
        // v124: Auto-geocode if city/state changed
        $new_city = isset($_POST['city']) ? sanitize_text_field($_POST['city']) : $trainer->city;
        $new_state = isset($_POST['state']) ? sanitize_text_field($_POST['state']) : $trainer->state;
        if (($new_city !== $trainer->city || $new_state !== $trainer->state) && !empty($new_city)) {
            $google_maps_key = get_option('ptp_google_maps_api_key', '');
            if ($google_maps_key) {
                $address = urlencode($new_city . ', ' . $new_state . ', USA');
                $geocode_url = "https://maps.googleapis.com/maps/api/geocode/json?address={$address}&key={$google_maps_key}";
                $response = wp_remote_get($geocode_url, array('timeout' => 5));
                if (!is_wp_error($response)) {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if (!empty($body['results'][0]['geometry']['location'])) {
                        $update_data['latitude'] = floatval($body['results'][0]['geometry']['location']['lat']);
                        $update_data['longitude'] = floatval($body['results'][0]['geometry']['location']['lng']);
                    }
                }
            }
        }
        
        // Social
        if (isset($_POST['instagram'])) {
            $update_data['instagram'] = sanitize_text_field(str_replace('@', '', $_POST['instagram']));
        }
        
        // Training Locations (v117.2)
        if (isset($_POST['training_locations'])) {
            $locations = json_decode(stripslashes($_POST['training_locations']), true);
            if (is_array($locations)) {
                $sanitized_locations = array();
                foreach ($locations as $loc) {
                    if (!empty($loc['name'])) {
                        $sanitized_locations[] = array(
                            'name' => sanitize_text_field($loc['name']),
                            'address' => sanitize_text_field($loc['address'] ?? '')
                        );
                    }
                }
                $update_data['training_locations'] = wp_json_encode($sanitized_locations);
            }
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            $update_data,
            array('id' => $trainer_id)
        );
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Database error'));
        }
        
        wp_send_json_success(array('message' => 'Profile saved'));
    }
    
    /**
     * Diagnostic: Check order creation status
     * Called from thank you page to verify order was created
     * 
     * @since 114.1
     */
    public static function check_order_status() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        $order_id = intval($_POST['order_id'] ?? 0);
        $session = sanitize_text_field($_POST['session'] ?? '');
        
        $response = array(
            'order_exists' => false,
            'order_data' => null,
            'session_data' => null,
            'error' => null,
        );
        
        // Check if order exists
        if ($order_id && function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order) {
                $response['order_exists'] = true;
                $response['order_data'] = array(
                    'id' => $order->get_id(),
                    'status' => $order->get_status(),
                    'total' => $order->get_total(),
                    'items_count' => count($order->get_items()),
                    'date' => $order->get_date_created() ? $order->get_date_created()->format('Y-m-d H:i:s') : null,
                );
            } else {
                $response['error'] = 'Order #' . $order_id . ' not found in WooCommerce';
            }
        }
        
        // Check if session data exists
        if ($session) {
            $session_data = get_transient('ptp_checkout_' . $session);
            if ($session_data) {
                $response['session_data'] = array(
                    'total' => $session_data['final_total'] ?? 0,
                    'items' => count($session_data['cart_items'] ?? []),
                    'has_woo' => $session_data['has_woo'] ?? false,
                );
            } else {
                $response['error'] = ($response['error'] ? $response['error'] . ' | ' : '') . 'Checkout session not found or expired';
            }
        }
        
        error_log('[PTP Order Check v114.1] ' . json_encode($response));
        wp_send_json_success($response);
    }
    
    /**
     * Get package credits for current user
     * Returns remaining session credits by trainer
     */
    public static function get_package_credits() {
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        // Get parent ID
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if (!$parent_id) {
            wp_send_json_success(array('credits' => array(), 'total' => 0));
        }
        
        // Get active credits
        $credits = $wpdb->get_results($wpdb->prepare("
            SELECT pc.*, 
                   t.display_name as trainer_name, 
                   t.photo_url as trainer_photo, 
                   t.slug as trainer_slug,
                   t.hourly_rate as trainer_rate
            FROM {$wpdb->prefix}ptp_package_credits pc
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON pc.trainer_id = t.id
            WHERE pc.parent_id = %d 
            AND pc.status = 'active'
            AND pc.remaining > 0
            AND (pc.expires_at IS NULL OR pc.expires_at > NOW())
            ORDER BY pc.expires_at ASC
        ", $parent_id));
        
        $total = 0;
        $formatted = array();
        foreach ($credits as $c) {
            $total += intval($c->remaining);
            $formatted[] = array(
                'id' => $c->id,
                'trainer_id' => $c->trainer_id,
                'trainer_name' => $c->trainer_name,
                'trainer_photo' => $c->trainer_photo,
                'trainer_slug' => $c->trainer_slug,
                'remaining' => intval($c->remaining),
                'total_credits' => intval($c->total_credits),
                'package_type' => $c->package_type,
                'expires_at' => $c->expires_at,
                'days_until_expiry' => $c->expires_at ? max(0, floor((strtotime($c->expires_at) - time()) / 86400)) : null,
            );
        }
        
        wp_send_json_success(array(
            'credits' => $formatted,
            'total' => $total,
        ));
    }
    
    /**
     * Book a session using package credits
     * No payment required - uses prepaid credits
     */
    public static function book_with_credits() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in to book'));
        }
        
        global $wpdb;
        $user_id = get_current_user_id();
        
        // Get parent ID
        $parent_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
            $user_id
        ));
        
        if (!$parent_id) {
            wp_send_json_error(array('message' => 'Parent profile not found'));
        }
        
        // Get request data
        $credit_id = intval($_POST['credit_id'] ?? 0);
        $player_id = intval($_POST['player_id'] ?? 0);
        $session_date = sanitize_text_field($_POST['session_date'] ?? '');
        $session_time = sanitize_text_field($_POST['session_time'] ?? '');
        $location = sanitize_text_field($_POST['location'] ?? '');
        
        if (!$credit_id) {
            wp_send_json_error(array('message' => 'No credit package selected'));
        }
        
        // Verify credit belongs to user and has remaining sessions
        $credit = $wpdb->get_row($wpdb->prepare("
            SELECT pc.*, t.display_name as trainer_name
            FROM {$wpdb->prefix}ptp_package_credits pc
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON pc.trainer_id = t.id
            WHERE pc.id = %d 
            AND pc.parent_id = %d
            AND pc.status = 'active'
            AND pc.remaining > 0
            AND (pc.expires_at IS NULL OR pc.expires_at > NOW())
        ", $credit_id, $parent_id));
        
        if (!$credit) {
            wp_send_json_error(array('message' => 'Invalid or expired credit package'));
        }
        
        // Generate booking number
        $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        
        // Create booking (no payment - using credit)
        $booking_data = array(
            'booking_number' => $booking_number,
            'trainer_id' => $credit->trainer_id,
            'parent_id' => $parent_id,
            'player_id' => $player_id ?: null,
            'session_date' => $session_date ?: null,
            'start_time' => $session_time ?: null,
            'location' => $location ?: null,
            'package_type' => 'credit',
            'total_sessions' => 1,
            'sessions_remaining' => 1,
            'total_amount' => $credit->price_per_session,
            'amount_paid' => 0, // Already paid via package
            'trainer_payout' => round($credit->price_per_session * 0.75, 2), // 25% platform fee
            'platform_fee' => round($credit->price_per_session * 0.25, 2),
            'payment_intent_id' => 'credit_' . $credit_id . '_' . time(),
            'package_credit_id' => $credit_id,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'created_at' => current_time('mysql'),
        );
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $booking_data);
        
        if (!$result) {
            error_log('[PTP Book Credits] Failed to create booking: ' . $wpdb->last_error);
            wp_send_json_error(array('message' => 'Failed to create booking'));
        }
        
        $booking_id = $wpdb->insert_id;
        
        // Deduct from credits
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->prefix}ptp_package_credits
            SET remaining = remaining - 1,
                updated_at = NOW(),
                status = CASE WHEN remaining - 1 <= 0 THEN 'exhausted' ELSE status END
            WHERE id = %d
        ", $credit_id));
        
        // Get updated remaining count
        $new_remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT remaining FROM {$wpdb->prefix}ptp_package_credits WHERE id = %d",
            $credit_id
        ));
        
        // Create escrow record for tracking (even though already paid)
        if (class_exists('PTP_Escrow')) {
            PTP_Escrow::create_hold($booking_id, $booking_data['payment_intent_id'], $credit->price_per_session);
        }
        
        // Get player name for notifications
        $player_name = '';
        if ($player_id) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name, name FROM {$wpdb->prefix}ptp_players WHERE id = %d",
                $player_id
            ));
            if ($player) {
                $player_name = $player->name ?: trim($player->first_name . ' ' . $player->last_name);
            }
        }
        
        // Send notifications
        if (class_exists('PTP_Unified_Checkout')) {
            PTP_Unified_Checkout::instance()->notify_trainer($credit->trainer_id, $booking_id, $player_name);
        }
        
        // Fire hooks
        do_action('ptp_booking_confirmed', $booking_id);
        do_action('ptp_credit_redeemed', $credit_id, $booking_id, $parent_id);
        
        // Schedule reminder if date/time set
        if ($session_date && $session_time) {
            $session_datetime = strtotime($session_date . ' ' . $session_time);
            $reminder_time = $session_datetime - (24 * 60 * 60);
            if ($reminder_time > time()) {
                wp_schedule_single_event($reminder_time, 'ptp_session_reminder', array($booking_id));
            }
        }
        
        error_log('[PTP Book Credits] Success: booking=' . $booking_id . ', credit=' . $credit_id . ', remaining=' . $new_remaining);
        
        wp_send_json_success(array(
            'message' => 'Session booked successfully!',
            'booking_id' => $booking_id,
            'booking_number' => $booking_number,
            'trainer_name' => $credit->trainer_name,
            'sessions_remaining' => intval($new_remaining),
            'redirect' => home_url('/thank-you/?booking_id=' . $booking_id),
        ));
    }
}
