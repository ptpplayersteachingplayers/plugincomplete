<?php
/**
 * PTP SMS System - v135.0
 * 
 * Updated to integrate with PTP SMS Hub plugin.
 * Checks SMS Hub settings first, falls back to legacy settings.
 * 
 * @since 135.0.0
 */

defined('ABSPATH') || exit;

class PTP_SMS_V71 {
    
    private static $twilio_sid;
    private static $twilio_token;
    private static $twilio_phone;
    private static $enabled = false;
    private static $initialized = false;
    
    /**
     * Initialize settings
     */
    public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;
        
        // v135: Check SMS Hub settings first (ptp_sms_*), then legacy (ptp_twilio_*)
        $hub_sid = get_option('ptp_sms_twilio_sid', '');
        $hub_token = get_option('ptp_sms_twilio_token', '');
        $hub_phone = get_option('ptp_sms_twilio_phone', '');
        
        if (!empty($hub_sid) && !empty($hub_token) && !empty($hub_phone)) {
            // Use SMS Hub settings
            self::$twilio_sid = $hub_sid;
            self::$twilio_token = $hub_token;
            self::$twilio_phone = $hub_phone;
            self::$enabled = true;
        } else {
            // Fall back to legacy PTP settings
            self::$twilio_sid = get_option('ptp_twilio_sid', '');
            self::$twilio_token = get_option('ptp_twilio_token', '');
            self::$twilio_phone = get_option('ptp_twilio_from', '');
            
            if (!empty(self::$twilio_sid) && !empty(self::$twilio_token) && !empty(self::$twilio_phone)) {
                self::$enabled = true;
            }
        }
        
        // Trainer onboarding hooks
        add_action('ptp_trainer_approved', array(__CLASS__, 'send_trainer_welcome'), 10, 1);
        add_action('ptp_trainer_onboarding_step', array(__CLASS__, 'send_onboarding_step_sms'), 10, 3);
        add_action('ptp_trainer_profile_complete', array(__CLASS__, 'send_profile_complete_sms'), 10, 1);
        add_action('ptp_trainer_stripe_connected', array(__CLASS__, 'send_stripe_connected_sms'), 10, 1);
        add_action('ptp_trainer_first_booking', array(__CLASS__, 'send_first_booking_sms'), 10, 2);
        
        // Booking hooks
        add_action('ptp_booking_confirmed', array(__CLASS__, 'send_booking_confirmation'), 10, 1);
        add_action('ptp_session_reminder', array(__CLASS__, 'send_session_reminder'), 10, 1);
        
        // Post-training follow-up hooks
        add_action('ptp_booking_completed', array(__CLASS__, 'send_post_training_followup'), 10, 2);
        add_action('ptp_session_completed', array(__CLASS__, 'send_post_training_followup'), 10, 2);
        
        // Message notification hooks
        add_action('ptp_message_sent', array(__CLASS__, 'send_message_notification'), 10, 3);
    }
    
    /**
     * Check if SMS is enabled
     */
    public static function is_enabled() {
        return self::$enabled;
    }
    
    /**
     * Send SMS
     */
    public static function send($to, $message) {
        if (!self::$enabled) {
            error_log('PTP SMS: Not enabled');
            return new WP_Error('sms_disabled', 'SMS is not configured');
        }
        
        $to = self::format_phone($to);
        if (!$to) {
            return new WP_Error('invalid_phone', 'Invalid phone number');
        }
        
        return self::send_via_twilio($to, $message);
    }
    
    /**
     * Send via Twilio
     */
    private static function send_via_twilio($to, $message) {
        $url = "https://api.twilio.com/2010-04-01/Accounts/" . self::$twilio_sid . "/Messages.json";
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode(self::$twilio_sid . ':' . self::$twilio_token),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'To' => $to,
                'From' => self::$twilio_phone,
                'Body' => $message,
            ),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            self::log_error('Twilio API Error', $response->get_error_message());
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error_code'])) {
            self::log_error('Twilio Error', $body['error_message']);
            return new WP_Error('twilio_error', $body['error_message']);
        }
        
        self::log_message($to, $message, $body['sid'] ?? 'unknown');
        
        return $body['sid'] ?? true;
    }
    
    /**
     * Send via Salesmsg
     */
    private static function send_via_salesmsg($to, $message) {
        $api_key = get_option('ptp_salesmsg_api_key', '');
        
        $response = wp_remote_post('https://api.salesmsg.com/v1/messages', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode(array(
                'to' => $to,
                'message' => $message,
            )),
            'timeout' => 30,
        ));
        
        if (is_wp_error($response)) {
            self::log_error('Salesmsg API Error', $response->get_error_message());
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($body['error'])) {
            self::log_error('Salesmsg Error', $body['error']);
            return new WP_Error('salesmsg_error', $body['error']);
        }
        
        self::log_message($to, $message, $body['id'] ?? 'unknown');
        
        return $body['id'] ?? true;
    }
    
    /**
     * Format phone number for SMS (E.164 format)
     */
    private static function format_phone($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        
        if (strlen($phone) === 10) {
            return '+1' . $phone;
        }
        
        if (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            return '+' . $phone;
        }
        
        if (strlen($phone) > 10) {
            return '+' . $phone;
        }
        
        return false;
    }
    
    // ===========================================
    // TRAINER ONBOARDING SMS
    // ===========================================
    
    /**
     * Send welcome SMS when trainer is approved
     */
    public static function send_trainer_welcome($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || empty($trainer->phone)) return;
        
        $first_name = explode(' ', $trainer->display_name)[0];
        $login_url = home_url('/login/');
        
        $message = "🎉 Welcome to PTP Training, {$first_name}!\n\n";
        $message .= "Your trainer application has been approved.\n\n";
        $message .= "Next steps:\n";
        $message .= "1. Complete your profile\n";
        $message .= "2. Set up your schedule\n";
        $message .= "3. Connect payments\n\n";
        $message .= "Login: {$login_url}";
        
        return self::send($trainer->phone, $message);
    }
    
    /**
     * Send onboarding step completion SMS
     */
    public static function send_onboarding_step_sms($trainer_id, $step, $is_complete) {
        if (!$is_complete) return;
        
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || empty($trainer->phone)) return;
        
        $first_name = explode(' ', $trainer->display_name)[0];
        
        $messages = array(
            'photo' => "Great photo, {$first_name}! 📸 You're making a great first impression. Next: Add your bio and specialties.",
            'bio' => "Bio added! ✍️ Parents love learning about their trainers. Next: Set your training locations.",
            'locations' => "Training locations set! 📍 You're almost ready. Next: Set your availability schedule.",
            'schedule' => "Schedule configured! 📅 One more step: Connect your payments to get paid.",
            'payments' => "Payments connected! 💳 You're ready to start receiving bookings. Share your profile!"
        );
        
        if (!isset($messages[$step])) return;
        
        return self::send($trainer->phone, "PTP: " . $messages[$step]);
    }
    
    /**
     * Send profile completion celebration SMS
     */
    public static function send_profile_complete_sms($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || empty($trainer->phone)) return;
        
        $first_name = explode(' ', $trainer->display_name)[0];
        $profile_url = home_url('/trainer/' . ($trainer->slug ?: sanitize_title($trainer->display_name)) . '/');
        
        $message = "🎊 Congratulations {$first_name}!\n\n";
        $message .= "Your trainer profile is 100% complete and LIVE.\n\n";
        $message .= "Share your profile to get bookings:\n{$profile_url}\n\n";
        $message .= "Pro tip: Share on social media and with local soccer programs!";
        
        return self::send($trainer->phone, $message);
    }
    
    /**
     * Send Stripe connected notification
     */
    public static function send_stripe_connected_sms($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer || empty($trainer->phone)) return;
        
        $first_name = explode(' ', $trainer->display_name)[0];
        
        $message = "PTP: Payment setup complete! 💰\n\n";
        $message .= "{$first_name}, you're now ready to receive payouts. ";
        $message .= "When you complete sessions, earnings go directly to your account within 2-3 business days.";
        
        return self::send($trainer->phone, $message);
    }
    
    /**
     * Send first booking celebration
     */
    public static function send_first_booking_sms($trainer_id, $booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.phone as trainer_phone,
                   p.name as player_name
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->trainer_phone) return;
        
        $first_name = explode(' ', $booking->trainer_name)[0];
        $date = date('D, M j', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));
        
        $message = "🎉 FIRST BOOKING! {$first_name}!\n\n";
        $message .= "Your first training session is booked:\n";
        $message .= "Player: {$booking->player_name}\n";
        $message .= "{$date} at {$time}\n\n";
        $message .= "You're officially a PTP Trainer! Make it a great session!";
        
        return self::send($booking->trainer_phone, $message);
    }
    
    // ===========================================
    // BOOKING SMS
    // ===========================================
    
    /**
     * Send booking confirmation SMS to parent
     * v130.6: Use LEFT JOIN for guest checkout support
     */
    public static function send_booking_confirmation($booking_id) {
        global $wpdb;
        
        // v130.6: Use LEFT JOIN to handle guest checkouts
        // Check if guest_phone column exists
        $has_guest_phone = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings LIKE 'guest_phone'");
        
        $phone_field = $has_guest_phone 
            ? "COALESCE(pa.phone, b.guest_phone, '')" 
            : "COALESCE(pa.phone, '')";
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name,
                   COALESCE(p.name, 'Player') as player_name,
                   {$phone_field} as parent_phone,
                   pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->parent_phone) {
            error_log("[PTP SMS] send_booking_confirmation: No phone for booking #$booking_id");
            return false;
        }
        
        $date = date('D, M j', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));
        
        $message = "PTP Training Confirmed!\n\n";
        $message .= "{$booking->player_name} with {$booking->trainer_name}\n";
        $message .= "{$date} at {$time}\n";
        if ($booking->location) {
            $message .= "Location: {$booking->location}\n";
        }
        $message .= "\nRef: {$booking->booking_number}";
        
        // Add referral link if user exists
        if ($booking->parent_user_id) {
            $referral_code = '';
            if (class_exists('PTP_Referral_System')) {
                $referral_code = PTP_Referral_System::generate_code($booking->parent_user_id, 'parent');
            } else {
                $referral_code = get_user_meta($booking->parent_user_id, 'ptp_referral_code', true);
            }
            if ($referral_code) {
                $referral_link = home_url('/?ref=' . $referral_code);
                $message .= "\n\nShare & get \$25: " . $referral_link;
            }
        }
        
        // Send to parent
        $sent = self::send($booking->parent_phone, $message);
        error_log("[PTP SMS] Parent confirmation to {$booking->parent_phone}: " . ($sent ? 'sent' : 'failed'));
        
        return $sent;
    }
    
    /**
     * Send new booking notification to trainer
     * v130.6: Use LEFT JOIN for guest checkout support
     */
    public static function send_trainer_new_booking($booking_id) {
        global $wpdb;
        
        // Check if guest_phone column exists
        $has_guest_phone = $wpdb->get_var("SHOW COLUMNS FROM {$wpdb->prefix}ptp_bookings LIKE 'guest_phone'");
        
        $phone_field = $has_guest_phone 
            ? "COALESCE(pa.phone, b.guest_phone, '')" 
            : "COALESCE(pa.phone, '')";
        
        // v130.6: Use LEFT JOIN to handle guest checkouts
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.phone as trainer_phone,
                   t.display_name as trainer_name,
                   COALESCE(p.name, 'New Player') as player_name,
                   COALESCE(p.age, 0) as player_age,
                   COALESCE(pa.display_name, 'Guest') as parent_name,
                   {$phone_field} as parent_phone
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking || !$booking->trainer_phone) {
            error_log("[PTP SMS] send_trainer_new_booking: No trainer phone for booking #$booking_id");
            return false;
        }
        
        $date = date('D, M j', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));
        $earnings = number_format($booking->trainer_payout, 2);
        
        $message = "New PTP Booking!\n\n";
        $message .= "Player: {$booking->player_name}";
        if ($booking->player_age) $message .= ", {$booking->player_age}yo";
        $message .= "\nParent: {$booking->parent_name}";
        if ($booking->parent_phone) $message .= "\nPhone: {$booking->parent_phone}";
        $message .= "\n{$date} at {$time}";
        if ($booking->location) {
            $message .= "\nLocation: {$booking->location}";
        }
        $message .= "\n\nYour Earnings: \${$earnings}";
        
        $sent = self::send($booking->trainer_phone, $message);
        error_log("[PTP SMS] Trainer notification to {$booking->trainer_phone}: " . ($sent ? 'sent' : 'failed'));
        
        return $sent;
    }
    
    /**
     * Send session reminder
     */
    public static function send_session_reminder($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.phone as trainer_phone,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.phone as parent_phone
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) return false;
        
        $time = date('g:i A', strtotime($booking->start_time));
        
        // Parent reminder
        if ($booking->parent_phone) {
            $message = "⏰ PTP Reminder\n\n";
            $message .= "{$booking->player_name}'s session is tomorrow at {$time} with {$booking->trainer_name}.";
            if ($booking->location) {
                $message .= "\n📍 {$booking->location}";
            }
            self::send($booking->parent_phone, $message);
        }
        
        // Trainer reminder
        if ($booking->trainer_phone) {
            $message = "⏰ PTP Reminder\n\n";
            $message .= "Session with {$booking->player_name} tomorrow at {$time}.\n";
            $message .= "Parent: {$booking->parent_name}";
            if ($booking->location) {
                $message .= "\n📍 {$booking->location}";
            }
            self::send($booking->trainer_phone, $message);
        }
        
        return true;
    }
    
    /**
     * Send post-training follow-up SMS
     * Prompts parents to leave a review
     */
    public static function send_post_training_followup($booking_id, $booking = null) {
        global $wpdb;
        
        if (!$booking) {
            $booking = $wpdb->get_row($wpdb->prepare("
                SELECT b.*, 
                       t.display_name as trainer_name, t.slug as trainer_slug,
                       p.name as player_name,
                       pa.phone as parent_phone, pa.display_name as parent_name
                FROM {$wpdb->prefix}ptp_bookings b
                LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
                LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
                LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
                WHERE b.id = %d
            ", $booking_id));
        }
        
        if (!$booking || !$booking->parent_phone) return false;
        
        // Extract first name from parent name
        $parent_first = 'there';
        if ($booking->parent_name) {
            $parent_name = $booking->parent_name;
            // Handle email-as-name
            if (strpos($parent_name, '@') !== false) {
                $parent_name = explode('@', $parent_name)[0];
                $parent_name = preg_replace('/[0-9]+/', '', $parent_name);
                $parent_name = str_replace(array('.', '_', '-'), ' ', $parent_name);
                $parent_name = ucwords(trim($parent_name));
            }
            $parts = explode(' ', $parent_name);
            $parent_first = $parts[0] ?: 'there';
        }
        
        $player_name = $booking->player_name ?: 'your player';
        $review_url = home_url('/trainer/' . ($booking->trainer_slug ?: 'profile') . '/?review=' . $booking_id);
        
        $message = "Hi {$parent_first}! Hope {$player_name} had a great session with {$booking->trainer_name} today!\n\n";
        $message .= "Quick feedback helps other families:\n{$review_url}\n\n";
        $message .= "Thanks for choosing PTP!";
        
        self::send($booking->parent_phone, $message);
        
        error_log("[PTP SMS] Post-training follow-up sent for booking #{$booking_id}");
        
        return true;
    }
    
    /**
     * Send message notification
     */
    public static function send_message_notification($conversation_id, $sender_id, $message_preview) {
        global $wpdb;
        
        $conversation = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, 
                    t.display_name as trainer_name, t.phone as trainer_phone, t.user_id as trainer_user_id,
                    p.display_name as parent_name, p.phone as parent_phone, p.user_id as parent_user_id
             FROM {$wpdb->prefix}ptp_conversations c
             JOIN {$wpdb->prefix}ptp_trainers t ON c.trainer_id = t.id
             JOIN {$wpdb->prefix}ptp_parents p ON c.parent_id = p.id
             WHERE c.id = %d",
            $conversation_id
        ));
        
        if (!$conversation) return;
        
        // Determine recipient (the one who didn't send)
        if ($sender_id == $conversation->trainer_user_id) {
            // Trainer sent, notify parent
            $recipient_phone = $conversation->parent_phone;
            $sender_name = $conversation->trainer_name;
        } else {
            // Parent sent, notify trainer
            $recipient_phone = $conversation->trainer_phone;
            $sender_name = $conversation->parent_name;
        }
        
        if (!$recipient_phone) return;
        
        $preview = strlen($message_preview) > 50 ? substr($message_preview, 0, 47) . '...' : $message_preview;
        $messages_url = home_url('/messages/?conversation=' . $conversation_id);
        
        $message = "PTP: New message from {$sender_name}\n\n";
        $message .= "\"{$preview}\"\n\n";
        $message .= "Reply: {$messages_url}";
        
        return self::send($recipient_phone, $message);
    }
    
    /**
     * Log SMS message
     */
    private static function log_message($to, $message, $sid) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'ptp_sms_log';
        
        // Create table if not exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                phone_to varchar(20) NOT NULL,
                message text NOT NULL,
                provider varchar(20) DEFAULT 'twilio',
                provider_sid varchar(50),
                status varchar(20) DEFAULT 'sent',
                created_at datetime DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id)
            ) $charset;");
        }
        
        $wpdb->insert($table, array(
            'phone_to' => $to,
            'message' => $message,
            'provider' => self::$provider,
            'provider_sid' => $sid,
            'status' => 'sent',
            'created_at' => current_time('mysql')
        ));
    }
    
    /**
     * Log error
     */
    private static function log_error($type, $message) {
        error_log("PTP SMS Error [{$type}]: {$message}");
    }
    
    /**
     * Send payout notification to trainer
     * (Migrated from old PTP_SMS class)
     */
    public static function send_payout_notification($trainer_phone, $amount) {
        if (!$trainer_phone) return false;
        
        $message = "PTP Payout Processed\n\n";
        $message .= "Amount: $" . number_format($amount, 2) . "\n";
        $message .= "Funds will arrive in 1-3 business days.\n\n";
        $message .= "View details: " . home_url('/trainer-dashboard/?tab=earnings');
        
        return self::send($trainer_phone, $message);
    }
    
    /**
     * Send session completion confirmation request
     * (Migrated from old PTP_SMS class)
     */
    public static function send_completion_request($booking_id) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare("
            SELECT b.*, 
                   t.display_name as trainer_name, t.phone as trainer_phone,
                   p.name as player_name,
                   pa.display_name as parent_name, pa.phone as parent_phone
            FROM {$wpdb->prefix}ptp_bookings b
            JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
            WHERE b.id = %d
        ", $booking_id));
        
        if (!$booking) return false;
        
        $confirm_url = home_url('/confirm-session/?booking=' . $booking_id);
        
        if ($booking->parent_phone) {
            $message = "PTP: Please confirm {$booking->player_name}'s session was completed today.\n\n";
            $message .= "Confirm: {$confirm_url}";
            self::send($booking->parent_phone, $message);
        }
        
        return true;
    }
    
    /**
     * Create SMS log table
     * (Migrated from old PTP_SMS class)
     */
    public static function create_table() {
        global $wpdb;
        
        $charset = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}ptp_sms_log (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            phone_to varchar(20) NOT NULL,
            message text NOT NULL,
            twilio_sid varchar(50),
            status varchar(20) DEFAULT 'pending',
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY phone_to (phone_to),
            KEY created_at (created_at)
        ) {$charset};";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

// Initialize
add_action('plugins_loaded', array('PTP_SMS_V71', 'init'), 15);

// Backwards compatibility alias - allows PTP_SMS::method() calls to work
if (!class_exists('PTP_SMS')) {
    class_alias('PTP_SMS_V71', 'PTP_SMS');
}
