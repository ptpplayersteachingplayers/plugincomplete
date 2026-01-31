<?php
/**
 * PTP Cron Jobs
 * Handles scheduled tasks like reminders, payouts, and cleanup
 */

defined('ABSPATH') || exit;

class PTP_Cron {
    
    public static function init() {
        // Register cron hooks
        add_action('ptp_send_session_reminders', array(__CLASS__, 'send_session_reminders'));
        add_action('ptp_send_hour_reminders', array(__CLASS__, 'send_hour_reminders'));
        add_action('ptp_send_review_requests', array(__CLASS__, 'send_review_requests'));
        add_action('ptp_process_payouts', array(__CLASS__, 'process_payouts'));
        add_action('ptp_process_refunds', array(__CLASS__, 'process_refunds'));
        add_action('ptp_cleanup_old_data', array(__CLASS__, 'cleanup_old_data'));
        add_action('ptp_send_completion_requests', array(__CLASS__, 'send_completion_requests'));
        add_action('ptp_auto_complete_sessions', array(__CLASS__, 'auto_complete_sessions'));
        add_action('ptp_process_recurring_bookings', array(__CLASS__, 'process_recurring_bookings'));
        
        // Compliance email hooks
        add_action('ptp_send_w9_email', array(__CLASS__, 'send_w9_email'), 10, 2);
        add_action('ptp_compliance_reminder', array(__CLASS__, 'send_compliance_reminder'), 10, 1);
        
        // Add 15-minute interval
        add_filter('cron_schedules', array(__CLASS__, 'add_cron_intervals'));
        
        // Schedule events on init
        add_action('init', array(__CLASS__, 'schedule_events'));
    }
    
    /**
     * Send W9 request email (scheduled after SafeSport email)
     */
    public static function send_w9_email($email, $name) {
        if (class_exists('PTP_Email')) {
            PTP_Email::send_w9_request($email, $name);
        }
    }
    
    /**
     * Send compliance reminder email
     */
    public static function send_compliance_reminder($trainer_id) {
        global $wpdb;
        
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
            $trainer_id
        ));
        
        if (!$trainer) return;
        
        // Check what's still missing
        $missing_safesport = empty($trainer->safesport_verified) || !$trainer->safesport_verified;
        $missing_w9 = empty($trainer->w9_verified) || !$trainer->w9_verified;
        
        // If nothing is missing, don't send reminder
        if (!$missing_safesport && !$missing_w9) {
            return;
        }
        
        if (class_exists('PTP_Email')) {
            PTP_Email::send_compliance_reminder($trainer->email, $trainer->display_name, $missing_safesport, $missing_w9);
        }
    }
    
    /**
     * Add custom cron intervals
     */
    public static function add_cron_intervals($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 900,
            'display' => 'Every 15 Minutes'
        );
        return $schedules;
    }
    
    /**
     * Schedule cron events
     */
    public static function schedule_events() {
        // Session reminders (24 hours) - run every hour
        if (!wp_next_scheduled('ptp_send_session_reminders')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_session_reminders');
        }
        
        // 1-hour reminders - run every 15 minutes
        if (!wp_next_scheduled('ptp_send_hour_reminders')) {
            wp_schedule_event(time(), 'fifteen_minutes', 'ptp_send_hour_reminders');
        }
        
        // Review requests - run every hour
        if (!wp_next_scheduled('ptp_send_review_requests')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_review_requests');
        }
        
        // Payout processing - run twice daily
        if (!wp_next_scheduled('ptp_process_payouts')) {
            wp_schedule_event(time(), 'twicedaily', 'ptp_process_payouts');
        }
        
        // Refund processing - run hourly
        if (!wp_next_scheduled('ptp_process_refunds')) {
            wp_schedule_event(time(), 'hourly', 'ptp_process_refunds');
        }
        
        // Data cleanup - run weekly
        if (!wp_next_scheduled('ptp_cleanup_old_data')) {
            wp_schedule_event(time(), 'weekly', 'ptp_cleanup_old_data');
        }
        
        // Completion requests - run every hour
        if (!wp_next_scheduled('ptp_send_completion_requests')) {
            wp_schedule_event(time(), 'hourly', 'ptp_send_completion_requests');
        }
        
        // Auto-complete old sessions - run daily
        if (!wp_next_scheduled('ptp_auto_complete_sessions')) {
            wp_schedule_event(time(), 'daily', 'ptp_auto_complete_sessions');
        }
        
        // Process recurring bookings - run daily
        if (!wp_next_scheduled('ptp_process_recurring_bookings')) {
            wp_schedule_event(time(), 'daily', 'ptp_process_recurring_bookings');
        }
    }
    
    /**
     * Clear all scheduled events (on deactivation)
     */
    public static function clear_events() {
        wp_clear_scheduled_hook('ptp_send_session_reminders');
        wp_clear_scheduled_hook('ptp_send_hour_reminders');
        wp_clear_scheduled_hook('ptp_send_review_requests');
        wp_clear_scheduled_hook('ptp_process_payouts');
        wp_clear_scheduled_hook('ptp_process_refunds');
        wp_clear_scheduled_hook('ptp_cleanup_old_data');
        wp_clear_scheduled_hook('ptp_send_completion_requests');
        wp_clear_scheduled_hook('ptp_auto_complete_sessions');
        wp_clear_scheduled_hook('ptp_process_recurring_bookings');
    }
    
    /**
     * Send session reminders (24 hours before)
     */
    public static function send_session_reminders() {
        global $wpdb;
        
        if (!get_option('ptp_email_session_reminder', true) && !get_option('ptp_sms_session_reminder', true)) {
            return;
        }
        
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_bookings 
            WHERE session_date = %s 
            AND status IN ('confirmed', 'pending')
            AND reminder_sent = 0
        ", $tomorrow));
        
        foreach ($bookings as $booking) {
            if (get_option('ptp_email_session_reminder', true)) {
                PTP_Email::send_session_reminder($booking->id);
            }
            
            if (get_option('ptp_sms_session_reminder', true) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                PTP_SMS::send_session_reminder($booking->id);
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('reminder_sent' => 1),
                array('id' => $booking->id)
            );
        }
    }
    
    /**
     * Send 1-hour reminders (push notifications)
     */
    public static function send_hour_reminders() {
        global $wpdb;
        
        // Get sessions starting in the next 60-75 minutes
        $now = current_time('mysql');
        $hour_from_now = date('Y-m-d H:i:s', strtotime('+60 minutes'));
        $hour_15_from_now = date('Y-m-d H:i:s', strtotime('+75 minutes'));
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_bookings 
            WHERE CONCAT(session_date, ' ', start_time) BETWEEN %s AND %s
            AND status = 'confirmed'
            AND hour_reminder_sent = 0
        ", $hour_from_now, $hour_15_from_now));
        
        foreach ($bookings as $booking) {
            // Send push notification
            if (class_exists('PTP_Push_Notifications')) {
                do_action('ptp_session_reminder', $booking->id);
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('hour_reminder_sent' => 1),
                array('id' => $booking->id)
            );
        }
    }
    
    /**
     * Send review requests (after session completes)
     */
    public static function send_review_requests() {
        global $wpdb;
        
        if (!get_option('ptp_email_review_request', true)) {
            return;
        }
        
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT b.id 
            FROM {$wpdb->prefix}ptp_bookings b
            LEFT JOIN {$wpdb->prefix}ptp_reviews r ON b.id = r.booking_id
            WHERE b.session_date = %s 
            AND b.status = 'completed'
            AND b.review_request_sent = 0
            AND r.id IS NULL
        ", $yesterday));
        
        foreach ($bookings as $booking) {
            PTP_Email::send_review_request($booking->id);
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('review_request_sent' => 1),
                array('id' => $booking->id)
            );
        }
    }
    
    /**
     * Send completion confirmation requests
     */
    public static function send_completion_requests() {
        global $wpdb;
        
        $two_hours_ago = date('Y-m-d H:i:s', strtotime('-2 hours'));
        $now = current_time('mysql');
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id FROM {$wpdb->prefix}ptp_bookings 
            WHERE CONCAT(session_date, ' ', end_time) BETWEEN %s AND %s
            AND status = 'confirmed'
            AND completion_request_sent = 0
        ", $two_hours_ago, $now));
        
        foreach ($bookings as $booking) {
            if (class_exists('PTP_Push_Notifications')) {
                do_action('ptp_booking_completed', $booking->id);
            }
            
            // Send SMS post-training followup
            if (get_option('ptp_sms_post_training', true) && class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
                PTP_SMS::send_completion_request($booking->id);
            }
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array('completion_request_sent' => 1),
                array('id' => $booking->id)
            );
        }
    }
    
    /**
     * Auto-complete sessions that are 48+ hours old
     */
    public static function auto_complete_sessions() {
        global $wpdb;
        
        $two_days_ago = date('Y-m-d', strtotime('-2 days'));
        
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT id, trainer_id, total_price FROM {$wpdb->prefix}ptp_bookings 
            WHERE session_date <= %s 
            AND status = 'confirmed'
            AND payment_status = 'paid'
        ", $two_days_ago));
        
        $platform_fee = floatval(get_option('ptp_platform_fee', 25)) / 100;
        
        foreach ($bookings as $booking) {
            $trainer_payout = $booking->total_price * (1 - $platform_fee);
            
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'completed',
                    'payout_status' => 'pending',
                    'trainer_payout' => $trainer_payout,
                ),
                array('id' => $booking->id)
            );
            
            // Update trainer stats
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}ptp_trainers SET total_sessions = total_sessions + 1 WHERE id = %d",
                $booking->trainer_id
            ));
        }
    }
    
    /**
     * Process pending payouts via Stripe Connect
     */
    public static function process_payouts() {
        global $wpdb;
        
        if (!class_exists('PTP_Stripe') || !get_option('ptp_stripe_connect_enabled')) {
            return;
        }
        
        $min_payout = floatval(get_option('ptp_min_payout', 25));
        
        // Get trainers with pending payouts
        $trainers = $wpdb->get_results($wpdb->prepare("
            SELECT trainer_id, SUM(trainer_payout) as total_pending
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE status = 'completed' 
            AND payout_status = 'pending'
            AND trainer_payout > 0
            GROUP BY trainer_id
            HAVING total_pending >= %f
        ", $min_payout));
        
        foreach ($trainers as $row) {
            $trainer = PTP_Trainer::get($row->trainer_id);
            
            if (!$trainer || empty($trainer->stripe_account_id) || !$trainer->stripe_payouts_enabled) {
                continue;
            }
            
            // Get bookings to include in this payout
            $bookings = $wpdb->get_results($wpdb->prepare("
                SELECT id, trainer_payout FROM {$wpdb->prefix}ptp_bookings 
                WHERE trainer_id = %d AND status = 'completed' AND payout_status = 'pending'
            ", $row->trainer_id));
            
            $amount_cents = intval($row->total_pending * 100);
            
            // Create Stripe transfer
            $result = PTP_Stripe::create_transfer(
                $amount_cents,
                $trainer->stripe_account_id,
                'Trainer payout - ' . count($bookings) . ' sessions'
            );
            
            if (!is_wp_error($result)) {
                // Mark bookings as paid out
                $booking_ids = array_map(function($b) { return $b->id; }, $bookings);
                $placeholders = implode(',', array_fill(0, count($booking_ids), '%d'));
                
                $wpdb->query($wpdb->prepare(
                    "UPDATE {$wpdb->prefix}ptp_bookings 
                     SET payout_status = 'completed', 
                         payout_date = NOW(),
                         stripe_transfer_id = %s
                     WHERE id IN ($placeholders)",
                    array_merge(array($result['id']), $booking_ids)
                ));
                
                // Log payout
                $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
                    'trainer_id' => $row->trainer_id,
                    'amount' => $row->total_pending,
                    'stripe_transfer_id' => $result['id'],
                    'status' => 'completed',
                    'booking_count' => count($bookings),
                    'created_at' => current_time('mysql'),
                ));
                
                // Notify trainer
                if (class_exists('PTP_Push_Notifications')) {
                    PTP_Push_Notifications::send(
                        $trainer->user_id,
                        '💰 Payout Sent!',
                        '$' . number_format($row->total_pending, 2) . ' is on its way to your bank',
                        array('type' => 'payout', 'amount' => $row->total_pending)
                    );
                }
            }
        }
    }
    
    /**
     * Process pending refunds
     */
    public static function process_refunds() {
        global $wpdb;
        
        if (!class_exists('PTP_Stripe') || !PTP_Stripe::is_enabled()) {
            return;
        }
        
        // Get cancelled bookings needing refund
        $bookings = $wpdb->get_results("
            SELECT id, stripe_payment_id, total_price, cancelled_by, cancelled_at
            FROM {$wpdb->prefix}ptp_bookings 
            WHERE status = 'cancelled'
            AND payment_status = 'paid'
            AND refund_status = 'pending'
            AND stripe_payment_id IS NOT NULL
            AND stripe_payment_id != ''
        ");
        
        foreach ($bookings as $booking) {
            // Calculate refund amount based on cancellation policy
            $hours_before = (strtotime($booking->session_date . ' ' . $booking->start_time) - strtotime($booking->cancelled_at)) / 3600;
            
            $refund_percent = 100;
            if ($hours_before < 24 && $booking->cancelled_by === 'parent') {
                $refund_percent = 50; // 50% refund if cancelled < 24 hours by parent
            }
            if ($hours_before < 2 && $booking->cancelled_by === 'parent') {
                $refund_percent = 0; // No refund if cancelled < 2 hours by parent
            }
            if ($booking->cancelled_by === 'trainer') {
                $refund_percent = 100; // Full refund if trainer cancels
            }
            
            if ($refund_percent > 0) {
                $refund_amount = intval($booking->total_price * ($refund_percent / 100) * 100);
                
                $result = PTP_Stripe::create_refund($booking->stripe_payment_id, $refund_amount);
                
                if (!is_wp_error($result)) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_bookings',
                        array(
                            'refund_status' => 'completed',
                            'refund_amount' => $refund_amount / 100,
                            'stripe_refund_id' => $result['id'],
                        ),
                        array('id' => $booking->id)
                    );
                }
            } else {
                $wpdb->update(
                    $wpdb->prefix . 'ptp_bookings',
                    array('refund_status' => 'none'),
                    array('id' => $booking->id)
                );
            }
        }
    }
    
    /**
     * Cleanup old data
     */
    public static function cleanup_old_data() {
        global $wpdb;
        
        // Delete old notifications (90 days)
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}ptp_notifications 
            WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
        ");
        
        // Delete old FCM tokens (30 days inactive)
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}ptp_fcm_tokens 
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        
        // Delete expired sessions (failed payments after 24 hours)
        $wpdb->query("
            DELETE FROM {$wpdb->prefix}ptp_bookings 
            WHERE status = 'pending' 
            AND payment_status = 'pending'
            AND created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        
        // Archive old conversations (90 days no activity)
        $wpdb->query("
            UPDATE {$wpdb->prefix}ptp_conversations 
            SET status = 'archived'
            WHERE updated_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
            AND status = 'active'
        ");
    }
    
    /**
     * Process recurring bookings - creates future sessions automatically
     */
    public static function process_recurring_bookings() {
        global $wpdb;
        
        $recurring_table = $wpdb->prefix . 'ptp_recurring_bookings';
        $bookings_table = $wpdb->prefix . 'ptp_bookings';
        
        // Check if table exists
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $recurring_table)) === $recurring_table;
        if (!$table_exists) {
            return;
        }
        
        // Get active recurring bookings that need a new session created
        // Create sessions 3 days in advance
        $upcoming_date = date('Y-m-d', strtotime('+3 days'));
        
        $recurring = $wpdb->get_results($wpdb->prepare("
            SELECT r.*, t.hourly_rate, t.display_name as trainer_name
            FROM {$recurring_table} r
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON r.trainer_id = t.id
            WHERE r.status = 'active'
            AND r.next_booking_date <= %s
            AND r.sessions_created < r.total_sessions
        ", $upcoming_date));
        
        foreach ($recurring as $rec) {
            // Check if booking already exists for this date
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$bookings_table} WHERE recurring_id = %d AND session_date = %s",
                $rec->id, $rec->next_booking_date
            ));
            
            if ($existing) {
                // Already created, just update next date
                self::update_next_recurring_date($rec);
                continue;
            }
            
            // Generate booking number
            $booking_number = 'PTP-R' . strtoupper(substr(md5(uniqid()), 0, 8));
            
            // Calculate payout - First session 50% commission, then 25%
            // Check if this parent has had sessions with this trainer before
            $previous_sessions = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$bookings_table} 
                 WHERE parent_id = %d AND trainer_id = %d AND status IN ('completed', 'confirmed')
                 AND id != %d",
                $rec->parent_id, $rec->trainer_id, 0
            ));
            
            $trainer_rate = floatval($rec->hourly_rate ?: 60);
            
            // First session = 50% to trainer, After = 75% to trainer
            if ($previous_sessions == 0) {
                $trainer_payout = round($trainer_rate * 0.50, 2);
                $platform_fee = round($trainer_rate * 0.50, 2);
            } else {
                $trainer_payout = round($trainer_rate * 0.75, 2);
                $platform_fee = round($trainer_rate * 0.25, 2);
            }
            
            // Create the booking
            $is_first = ($previous_sessions == 0);
            $booking_data = array(
                'booking_number' => $booking_number,
                'trainer_id' => $rec->trainer_id,
                'parent_id' => $rec->parent_id,
                'player_id' => $rec->player_id,
                'session_date' => $rec->next_booking_date,
                'start_time' => $rec->preferred_time,
                'end_time' => date('H:i:s', strtotime($rec->preferred_time) + 3600),
                'duration_minutes' => 60,
                'location' => $rec->location,
                'hourly_rate' => $trainer_rate,
                'total_amount' => $trainer_rate,
                'platform_fee' => $platform_fee,
                'trainer_payout' => $trainer_payout,
                'status' => 'pending', // Needs payment confirmation
                'payment_status' => 'pending',
                'is_recurring' => 1,
                'recurring_id' => $rec->id,
                'notes' => sprintf(
                    "Auto-created recurring booking\nSession %d of %d\nFrequency: %s\nCommission: %s\nTrainer Payout: $%s",
                    $rec->sessions_created + 1,
                    $rec->total_sessions,
                    ucfirst($rec->frequency),
                    $is_first ? 'First Session 50%' : 'Repeat 25%',
                    number_format($trainer_payout, 2)
                ),
                'created_at' => current_time('mysql'),
            );
            
            $result = $wpdb->insert($bookings_table, $booking_data);
            
            if ($result) {
                $booking_id = $wpdb->insert_id;
                
                // Update recurring record
                $wpdb->update(
                    $recurring_table,
                    array(
                        'sessions_created' => $rec->sessions_created + 1,
                        'updated_at' => current_time('mysql'),
                    ),
                    array('id' => $rec->id)
                );
                
                // Update next booking date
                self::update_next_recurring_date($rec);
                
                // Send notification to parent about upcoming recurring session
                if (class_exists('PTP_Email')) {
                    do_action('ptp_recurring_session_created', $booking_id, $rec->id);
                }
                
                // Log
                error_log('[PTP Cron] Created recurring booking #' . $booking_number . ' for ' . $rec->next_booking_date);
            }
        }
        
        // Mark completed recurring bookings
        $wpdb->query("
            UPDATE {$recurring_table} 
            SET status = 'completed', updated_at = NOW()
            WHERE status = 'active' AND sessions_created >= total_sessions
        ");
    }
    
    /**
     * Update next recurring booking date
     */
    private static function update_next_recurring_date($rec) {
        global $wpdb;
        
        $interval = $rec->frequency === 'biweekly' ? '+2 weeks' : '+1 week';
        $next_date = date('Y-m-d', strtotime($rec->next_booking_date . ' ' . $interval));
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_recurring_bookings',
            array('next_booking_date' => $next_date, 'updated_at' => current_time('mysql')),
            array('id' => $rec->id)
        );
    }
    
    /**
     * Run specific task manually (for admin/testing)
     */
    public static function run_task($task) {
        switch ($task) {
            case 'reminders': self::send_session_reminders(); break;
            case 'hour_reminders': self::send_hour_reminders(); break;
            case 'reviews': self::send_review_requests(); break;
            case 'payouts': self::process_payouts(); break;
            case 'refunds': self::process_refunds(); break;
            case 'cleanup': self::cleanup_old_data(); break;
            case 'completions': self::send_completion_requests(); break;
            case 'auto_complete': self::auto_complete_sessions(); break;
            case 'recurring': self::process_recurring_bookings(); break;
        }
    }
}
