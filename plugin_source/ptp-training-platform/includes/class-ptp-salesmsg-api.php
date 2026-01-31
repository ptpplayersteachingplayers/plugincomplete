<?php
/**
 * PTP Salesmsg API Integration
 * REST endpoints for Salesmsg AI Textbot integration
 * 
 * DISABLED BY DEFAULT - Enable via: update_option('ptp_salesmsg_enabled', true)
 * 
 * @version 1.0.0
 * @since 56.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class PTP_Salesmsg_API {
    
    const NAMESPACE = 'ptp/v1';
    const API_KEY_OPTION = 'ptp_salesmsg_api_key';
    
    /**
     * Initialize
     */
    public static function init() {
        // Only register routes if explicitly enabled
        if (!get_option('ptp_salesmsg_enabled', false)) {
            return;
        }
        
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
        
        // Generate API key if enabled but no key exists
        if (!get_option(self::API_KEY_OPTION)) {
            update_option(self::API_KEY_OPTION, 'ptp_' . wp_generate_password(32, false, false));
        }
    }
    
    /**
     * Register REST routes
     */
    public static function register_routes() {
        // Search trainers
        register_rest_route(self::NAMESPACE, '/salesmsg/search-trainers', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'search_trainers'),
            'permission_callback' => array(__CLASS__, 'verify_api_key'),
        ));
        
        // Get available slots
        register_rest_route(self::NAMESPACE, '/salesmsg/available-slots', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'get_available_slots'),
            'permission_callback' => array(__CLASS__, 'verify_api_key'),
        ));
        
        // Create booking
        register_rest_route(self::NAMESPACE, '/salesmsg/create-booking', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'create_booking'),
            'permission_callback' => array(__CLASS__, 'verify_api_key'),
        ));
        
        // Get trainer details
        register_rest_route(self::NAMESPACE, '/salesmsg/trainer/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainer_details'),
            'permission_callback' => array(__CLASS__, 'verify_api_key'),
        ));
        
        // Get trainers summary
        register_rest_route(self::NAMESPACE, '/salesmsg/trainers-summary', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'get_trainers_summary'),
            'permission_callback' => array(__CLASS__, 'verify_api_key'),
        ));
    }
    
    /**
     * Verify API key
     */
    public static function verify_api_key($request) {
        $api_key = $request->get_header('X-PTP-API-Key');
        if (!$api_key) {
            $api_key = $request->get_param('api_key');
        }
        
        if (empty($api_key)) {
            return new WP_Error('missing_api_key', 'API key required', array('status' => 401));
        }
        
        $stored_key = get_option(self::API_KEY_OPTION);
        if (!$stored_key || !hash_equals($stored_key, $api_key)) {
            return new WP_Error('invalid_api_key', 'Invalid API key', array('status' => 401));
        }
        
        return true;
    }
    
    /**
     * Search trainers by location
     */
    public static function search_trainers($request) {
        global $wpdb;
        
        $location = sanitize_text_field($request->get_param('location'));
        $date = sanitize_text_field($request->get_param('date'));
        $max_results = absint($request->get_param('max_results')) ?: 5;
        
        if (empty($location)) {
            return new WP_Error('missing_location', 'Location is required', array('status' => 400));
        }
        
        $location_pattern = '%' . $wpdb->esc_like($location) . '%';
        
        $trainers = $wpdb->get_results($wpdb->prepare(
            "SELECT id, slug, display_name, location, hourly_rate, headline, college,
                    average_rating, review_count, total_sessions
             FROM {$wpdb->prefix}ptp_trainers
             WHERE status = 'active'
             AND (location LIKE %s OR training_locations LIKE %s)
             ORDER BY is_featured DESC, average_rating DESC
             LIMIT %d",
            $location_pattern,
            $location_pattern,
            min($max_results, 10)
        ));
        
        $results = array();
        foreach ($trainers as $t) {
            $trainer_data = array(
                'id' => absint($t->id),
                'name' => esc_html($t->display_name),
                'slug' => esc_attr($t->slug),
                'location' => esc_html($t->location),
                'hourly_rate' => floatval($t->hourly_rate),
                'headline' => esc_html($t->headline),
                'college' => esc_html($t->college),
                'rating' => floatval($t->average_rating),
                'reviews' => absint($t->review_count),
                'profile_url' => esc_url(home_url('/trainer/' . $t->slug)),
            );
            
            // Add availability if date provided
            if ($date && class_exists('PTP_Availability')) {
                $slots = PTP_Availability::get_available_slots($t->id, $date);
                $trainer_data['available_slots'] = count($slots);
                $trainer_data['next_available'] = !empty($slots) ? esc_html($slots[0]['display']) : null;
            }
            
            $results[] = $trainer_data;
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'trainers' => $results,
            'count' => count($results),
        ));
    }
    
    /**
     * Get available slots for a trainer
     */
    public static function get_available_slots($request) {
        global $wpdb;
        
        $trainer_id = absint($request->get_param('trainer_id'));
        $date = sanitize_text_field($request->get_param('date'));
        $days_ahead = absint($request->get_param('days_ahead')) ?: 0;
        
        if (!$trainer_id || empty($date)) {
            return new WP_Error('missing_params', 'trainer_id and date required', array('status' => 400));
        }
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT id, display_name, hourly_rate FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        $availability = array();
        $days_to_check = max(1, min($days_ahead + 1, 14));
        
        for ($i = 0; $i < $days_to_check; $i++) {
            $check_date = date('Y-m-d', strtotime($date . " +{$i} days"));
            
            if (class_exists('PTP_Availability')) {
                $slots = PTP_Availability::get_available_slots($trainer_id, $check_date);
                if (!empty($slots)) {
                    $availability[] = array(
                        'date' => $check_date,
                        'day_name' => date('l', strtotime($check_date)),
                        'slots' => $slots,
                        'count' => count($slots),
                    );
                }
            }
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'trainer' => array(
                'id' => absint($trainer->id),
                'name' => esc_html($trainer->display_name),
                'hourly_rate' => floatval($trainer->hourly_rate),
            ),
            'availability' => $availability,
        ));
    }
    
    /**
     * Create a booking
     */
    public static function create_booking($request) {
        global $wpdb;
        
        // Get and sanitize all params
        $trainer_id = absint($request->get_param('trainer_id'));
        $date = sanitize_text_field($request->get_param('date'));
        $time = sanitize_text_field($request->get_param('time'));
        $parent_name = sanitize_text_field($request->get_param('parent_name'));
        $parent_email = sanitize_email($request->get_param('parent_email'));
        $parent_phone = sanitize_text_field($request->get_param('parent_phone'));
        $player_name = sanitize_text_field($request->get_param('player_name'));
        $player_age = absint($request->get_param('player_age'));
        $location = sanitize_text_field($request->get_param('location'));
        $notes = sanitize_textarea_field($request->get_param('notes'));
        
        // Validate required fields
        if (!$trainer_id || empty($date) || empty($time) || empty($parent_name) || empty($parent_email) || empty($player_name)) {
            return new WP_Error('missing_params', 'Required: trainer_id, date, time, parent_name, parent_email, player_name', array('status' => 400));
        }
        
        if (!is_email($parent_email)) {
            return new WP_Error('invalid_email', 'Invalid email address', array('status' => 400));
        }
        
        // Normalize time format
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            $time = date('H:i:s', strtotime($time));
        }
        
        // Get trainer
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        // Check slot availability with transaction
        $wpdb->query('START TRANSACTION');
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date = %s AND start_time = %s 
             AND status NOT IN ('cancelled', 'rejected')
             FOR UPDATE",
            $trainer_id, $date, $time
        ));
        
        if ($existing) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('slot_taken', 'This time slot is no longer available', array('status' => 409));
        }
        
        // Calculate amounts
        $hourly_rate = floatval($trainer->hourly_rate ?: 80);
        $platform_fee_percent = floatval(get_option('ptp_platform_fee', 25));
        $total_amount = $hourly_rate;
        $trainer_payout = round($total_amount * (1 - $platform_fee_percent / 100), 2);
        $platform_fee = round($total_amount * ($platform_fee_percent / 100), 2);
        
        if (empty($location)) {
            $location = $trainer->location;
        }
        
        // Create booking
        $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(wp_rand(), true)), 0, 8));
        $end_time = date('H:i:s', strtotime($time . ' +1 hour'));
        
        $booking_notes = $notes ?: '';
        $booking_notes .= "\n\n[Booked via SMS - Salesmsg]";
        $booking_notes .= "\nContact: " . $parent_name;
        $booking_notes .= "\nEmail: " . $parent_email;
        if ($parent_phone) {
            $booking_notes .= "\nPhone: " . $parent_phone;
        }
        $booking_notes .= "\nPlayer: " . $player_name;
        if ($player_age) {
            $booking_notes .= " (Age: " . $player_age . ")";
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'trainer_id' => $trainer_id,
                'parent_id' => 0,
                'player_id' => 0,
                'session_date' => $date,
                'start_time' => $time,
                'end_time' => $end_time,
                'duration_minutes' => 60,
                'location' => $location,
                'hourly_rate' => $hourly_rate,
                'total_amount' => $total_amount,
                'trainer_payout' => $trainer_payout,
                'platform_fee' => $platform_fee,
                'notes' => $booking_notes,
                'status' => 'pending',
                'payment_status' => 'pending',
                'booking_number' => $booking_number,
                'session_type' => 'single',
                'session_count' => 1,
                'sessions_remaining' => 1,
                'created_at' => current_time('mysql'),
            )
        );
        
        if (!$result) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('booking_error', 'Could not create booking', array('status' => 500));
        }
        
        $booking_id = $wpdb->insert_id;
        $wpdb->query('COMMIT');
        
        // Build checkout URL
        $checkout_url = add_query_arg(
            array('booking' => $booking_number, 'email' => rawurlencode($parent_email)),
            home_url('/training-checkout/')
        );
        
        $formatted_date = date('l, F j', strtotime($date));
        $formatted_time = date('g:i A', strtotime($time));
        
        return rest_ensure_response(array(
            'success' => true,
            'booking' => array(
                'id' => $booking_id,
                'booking_number' => $booking_number,
                'trainer_name' => esc_html($trainer->display_name),
                'date' => $date,
                'time' => $time,
                'formatted_date' => $formatted_date,
                'formatted_time' => $formatted_time,
                'location' => esc_html($location),
                'player_name' => esc_html($player_name),
                'amount' => $total_amount,
            ),
            'checkout_url' => esc_url($checkout_url),
            'sms_message' => sprintf(
                'Training booked! %s, %s at %s. Pay here: %s',
                esc_html($trainer->display_name),
                $formatted_date,
                $formatted_time,
                $checkout_url
            ),
        ));
    }
    
    /**
     * Get trainer details
     */
    public static function get_trainer_details($request) {
        global $wpdb;
        
        $trainer_id = absint($request->get_param('id'));
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'",
            $trainer_id
        ));
        
        if (!$trainer) {
            return new WP_Error('not_found', 'Trainer not found', array('status' => 404));
        }
        
        // Get next 7 days availability
        $availability = array();
        for ($i = 0; $i < 7; $i++) {
            $date = date('Y-m-d', strtotime("+{$i} days"));
            if (class_exists('PTP_Availability')) {
                $slots = PTP_Availability::get_available_slots($trainer_id, $date);
                if (!empty($slots)) {
                    $availability[] = array(
                        'date' => $date,
                        'day' => date('l', strtotime($date)),
                        'slots' => count($slots),
                        'times' => array_slice(array_column($slots, 'display'), 0, 4),
                    );
                }
            }
        }
        
        return rest_ensure_response(array(
            'id' => absint($trainer->id),
            'name' => esc_html($trainer->display_name),
            'slug' => esc_attr($trainer->slug),
            'headline' => esc_html($trainer->headline),
            'bio' => esc_html(wp_trim_words($trainer->bio, 100)),
            'college' => esc_html($trainer->college),
            'location' => esc_html($trainer->location),
            'hourly_rate' => floatval($trainer->hourly_rate),
            'rating' => floatval($trainer->average_rating),
            'review_count' => absint($trainer->review_count),
            'availability' => $availability,
            'profile_url' => esc_url(home_url('/trainer/' . $trainer->slug)),
        ));
    }
    
    /**
     * Get all trainers summary
     */
    public static function get_trainers_summary($request) {
        global $wpdb;
        
        $trainers = $wpdb->get_results(
            "SELECT id, display_name, location, hourly_rate, college, average_rating
             FROM {$wpdb->prefix}ptp_trainers 
             WHERE status = 'active'
             ORDER BY is_featured DESC, average_rating DESC
             LIMIT 50"
        );
        
        $summary = array();
        foreach ($trainers as $t) {
            $summary[] = array(
                'id' => absint($t->id),
                'name' => esc_html($t->display_name),
                'location' => esc_html($t->location),
                'rate' => floatval($t->hourly_rate),
                'rating' => floatval($t->average_rating),
                'college' => esc_html($t->college),
            );
        }
        
        return rest_ensure_response(array(
            'trainers' => $summary,
            'count' => count($summary),
        ));
    }
    
    /**
     * Get API key (for admin)
     */
    public static function get_api_key() {
        return get_option(self::API_KEY_OPTION, '');
    }
    
    /**
     * Regenerate API key
     */
    public static function regenerate_api_key() {
        $new_key = 'ptp_' . wp_generate_password(32, false, false);
        update_option(self::API_KEY_OPTION, $new_key);
        return $new_key;
    }
}

// Initialize on plugins_loaded
add_action('plugins_loaded', array('PTP_Salesmsg_API', 'init'), 99);
