<?php
/**
 * PTP Escrow - Secure Payment Hold System
 * Funds are held until session is confirmed complete
 * Version 25.1
 * 
 * FLOW:
 * 1. Parent pays → Funds captured to PTP platform account (NOT sent to trainer yet)
 * 2. Session occurs
 * 3. Trainer marks session "complete" 
 * 4. Parent has 24 hours to confirm or dispute
 * 5. After confirmation OR 24hr auto-release → Funds transfer to trainer
 * 6. If disputed → Admin reviews and decides
 */

defined('ABSPATH') || exit;

class PTP_Escrow {
    
    // Hours before auto-release if no dispute
    const AUTO_RELEASE_HOURS = 24;
    
    // Hours after session for trainer to mark complete
    const COMPLETION_WINDOW_HOURS = 48;
    
    // Escrow statuses
    const STATUS_HOLDING = 'holding';           // Payment captured, awaiting session
    const STATUS_SESSION_COMPLETE = 'session_complete'; // Trainer marked complete
    const STATUS_CONFIRMED = 'confirmed';       // Parent confirmed
    const STATUS_DISPUTED = 'disputed';         // Parent disputed
    const STATUS_RELEASED = 'released';         // Funds sent to trainer
    const STATUS_REFUNDED = 'refunded';         // Funds returned to parent
    
    public static function init() {
        // AJAX endpoints
        add_action('wp_ajax_ptp_trainer_complete_session', array(__CLASS__, 'trainer_complete_session'));
        add_action('wp_ajax_ptp_parent_confirm_session', array(__CLASS__, 'parent_confirm_session'));
        add_action('wp_ajax_ptp_parent_dispute_session', array(__CLASS__, 'parent_dispute_session'));
        add_action('wp_ajax_ptp_admin_resolve_dispute', array(__CLASS__, 'admin_resolve_dispute'));
        add_action('wp_ajax_ptp_admin_release_funds', array(__CLASS__, 'admin_release_funds'));
        
        // Cron for auto-release
        add_action('ptp_process_escrow_releases', array(__CLASS__, 'process_auto_releases'));
        
        // Schedule cron if not scheduled
        if (!wp_next_scheduled('ptp_process_escrow_releases')) {
            wp_schedule_event(time(), 'hourly', 'ptp_process_escrow_releases');
        }
    }
    
    /**
     * Create escrow hold when payment is captured
     * Called after successful payment
     */
    public static function create_hold($booking_id, $payment_intent_id, $amount) {
        global $wpdb;
        
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if (!$booking) {
            return new WP_Error('booking_not_found', 'Booking not found');
        }
        
        // Calculate amounts
        $platform_fee = round($amount * 0.25, 2);
        $trainer_amount = $amount - $platform_fee;
        
        // Create escrow record
        $result = $wpdb->insert(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'booking_id' => $booking_id,
                'trainer_id' => $booking->trainer_id,
                'parent_id' => $booking->parent_id,
                'payment_intent_id' => $payment_intent_id,
                'total_amount' => $amount,
                'platform_fee' => $platform_fee,
                'trainer_amount' => $trainer_amount,
                'status' => self::STATUS_HOLDING,
                'session_date' => $booking->session_date,
                'session_time' => $booking->start_time,
                'created_at' => current_time('mysql'),
                'release_eligible_at' => null, // Set when trainer marks complete
            ),
            array('%d', '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%s', '%s')
        );
        
        if (!$result) {
            return new WP_Error('escrow_failed', 'Failed to create escrow hold');
        }
        
        $escrow_id = $wpdb->insert_id;
        
        // Update booking with escrow info
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'escrow_id' => $escrow_id,
                'escrow_status' => self::STATUS_HOLDING,
                'funds_held' => 1,
            ),
            array('id' => $booking_id)
        );
        
        // Log the hold
        self::log_event($escrow_id, 'hold_created', 'Payment of $' . number_format($amount, 2) . ' held in escrow');
        
        return $escrow_id;
    }
    
    /**
     * Trainer marks session as complete
     */
    public static function trainer_complete_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        $trainer = PTP_Trainer::get_by_user_id(get_current_user_id());
        if (!$trainer) {
            wp_send_json_error(array('message' => 'Trainer not found'));
        }
        
        global $wpdb;
        
        // Get escrow record
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, b.session_date, b.start_time
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
            WHERE e.booking_id = %d AND e.trainer_id = %d
        ", $booking_id, $trainer->id));
        
        // FALLBACK: If no escrow record exists (legacy booking), create one or complete directly
        if (!$escrow) {
            // Get the booking to verify ownership and get details
            $booking = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$wpdb->prefix}ptp_bookings 
                WHERE id = %d AND trainer_id = %d
            ", $booking_id, $trainer->id));
            
            if (!$booking) {
                wp_send_json_error(array('message' => 'Booking not found or access denied'));
            }
            
            // Check if session time has passed
            $session_datetime = strtotime($booking->session_date . ' ' . $booking->start_time);
            if (time() < $session_datetime) {
                wp_send_json_error(array('message' => 'Cannot complete session before scheduled time'));
            }
            
            // For legacy bookings without escrow, complete directly (no 24hr hold)
            $wpdb->update(
                $wpdb->prefix . 'ptp_bookings',
                array(
                    'status' => 'completed',
                    'trainer_confirmed' => 1,
                    'trainer_confirmed_at' => current_time('mysql'),
                    'completed_at' => current_time('mysql'),
                ),
                array('id' => $booking_id)
            );
            
            // Fire completion hooks
            do_action('ptp_session_completed', $booking_id, $booking);
            do_action('ptp_booking_completed', $booking_id, $booking);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Escrow: Legacy booking ' . $booking_id . ' completed directly (no escrow record)');
            }
            
            wp_send_json_success(array(
                'message' => 'Session marked complete!',
                'legacy_mode' => true,
            ));
            return;
        }
        
        if ($escrow->status !== self::STATUS_HOLDING) {
            wp_send_json_error(array('message' => 'Session already processed'));
        }
        
        // Check if session time has passed
        $session_datetime = strtotime($escrow->session_date . ' ' . $escrow->session_time);
        if (time() < $session_datetime) {
            wp_send_json_error(array('message' => 'Cannot complete session before scheduled time'));
        }
        
        // Calculate when funds become eligible for auto-release
        $release_eligible = date('Y-m-d H:i:s', strtotime('+' . self::AUTO_RELEASE_HOURS . ' hours'));
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_SESSION_COMPLETE,
                'trainer_completed_at' => current_time('mysql'),
                'release_eligible_at' => $release_eligible,
            ),
            array('id' => $escrow->id)
        );
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'status' => 'completed',
                'escrow_status' => self::STATUS_SESSION_COMPLETE,
                'completed_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        // Log event
        self::log_event($escrow->id, 'trainer_completed', 'Trainer marked session as complete');
        
        // Notify parent to confirm
        self::notify_parent_to_confirm($escrow->id);
        
        // Notify trainer that session is awaiting confirmation
        if (class_exists('PTP_Email')) {
            PTP_Email::send_trainer_awaiting_confirmation($booking_id);
        }
        
        wp_send_json_success(array(
            'message' => 'Session marked complete! The parent has ' . self::AUTO_RELEASE_HOURS . ' hours to confirm. Funds will be released automatically after that.',
            'release_eligible_at' => $release_eligible,
        ));
    }
    
    /**
     * Parent confirms session was completed satisfactorily
     */
    public static function parent_confirm_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $rating = isset($_POST['rating']) ? intval($_POST['rating']) : 0;
        $feedback = isset($_POST['feedback']) ? sanitize_textarea_field($_POST['feedback']) : '';
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        global $wpdb;
        
        // Get parent
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        if (!$parent) {
            wp_send_json_error(array('message' => 'Parent not found'));
        }
        
        // Get escrow record
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_escrow
            WHERE booking_id = %d AND parent_id = %d
        ", $booking_id, $parent->id));
        
        if (!$escrow) {
            wp_send_json_error(array('message' => 'Escrow record not found'));
        }
        
        if (!in_array($escrow->status, array(self::STATUS_SESSION_COMPLETE, self::STATUS_HOLDING))) {
            wp_send_json_error(array('message' => 'Session already processed'));
        }
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_CONFIRMED,
                'parent_confirmed_at' => current_time('mysql'),
                'parent_rating' => $rating ?: null,
                'parent_feedback' => $feedback ?: null,
            ),
            array('id' => $escrow->id)
        );
        
        // Log event
        self::log_event($escrow->id, 'parent_confirmed', 'Parent confirmed session completion');
        
        // Release funds immediately
        $release_result = self::release_funds($escrow->id);
        
        if (is_wp_error($release_result)) {
            // Still mark as confirmed, admin will need to manually release
            wp_send_json_success(array(
                'message' => 'Session confirmed! Funds will be released to the trainer shortly.',
            ));
        }
        
        wp_send_json_success(array(
            'message' => 'Session confirmed! Funds have been released to the trainer.',
        ));
    }
    
    /**
     * Parent disputes session
     */
    public static function parent_dispute_session() {
        check_ajax_referer('ptp_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Please log in'));
        }
        
        $booking_id = isset($_POST['booking_id']) ? intval($_POST['booking_id']) : 0;
        $reason = isset($_POST['reason']) ? sanitize_textarea_field($_POST['reason']) : '';
        
        if (!$booking_id) {
            wp_send_json_error(array('message' => 'Invalid booking'));
        }
        
        if (empty($reason)) {
            wp_send_json_error(array('message' => 'Please provide a reason for the dispute'));
        }
        
        global $wpdb;
        
        // Get parent
        $parent = PTP_Parent::get_by_user_id(get_current_user_id());
        if (!$parent) {
            wp_send_json_error(array('message' => 'Parent not found'));
        }
        
        // Get escrow record
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}ptp_escrow
            WHERE booking_id = %d AND parent_id = %d
        ", $booking_id, $parent->id));
        
        if (!$escrow) {
            wp_send_json_error(array('message' => 'Escrow record not found'));
        }
        
        if (!in_array($escrow->status, array(self::STATUS_SESSION_COMPLETE, self::STATUS_HOLDING))) {
            wp_send_json_error(array('message' => 'Cannot dispute this session'));
        }
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_DISPUTED,
                'disputed_at' => current_time('mysql'),
                'dispute_reason' => $reason,
            ),
            array('id' => $escrow->id)
        );
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array('escrow_status' => self::STATUS_DISPUTED),
            array('id' => $booking_id)
        );
        
        // Log event
        self::log_event($escrow->id, 'disputed', 'Parent disputed session: ' . $reason);
        
        // Notify admin
        self::notify_admin_dispute($escrow->id);
        
        // Notify trainer
        self::notify_trainer_dispute($escrow->id);
        
        wp_send_json_success(array(
            'message' => 'Dispute submitted. Our team will review and contact both parties within 24-48 hours.',
        ));
    }
    
    /**
     * Release funds to trainer
     */
    public static function release_funds($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.stripe_account_id, t.display_name as trainer_name
             FROM {$wpdb->prefix}ptp_escrow e
             JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
             WHERE e.id = %d",
            $escrow_id
        ));
        
        if (!$escrow) {
            return new WP_Error('not_found', 'Escrow record not found');
        }
        
        if ($escrow->status === self::STATUS_RELEASED) {
            return new WP_Error('already_released', 'Funds already released');
        }
        
        if ($escrow->status === self::STATUS_REFUNDED) {
            return new WP_Error('already_refunded', 'Funds already refunded');
        }
        
        // Check if trainer has Stripe Connect
        if (empty($escrow->stripe_account_id)) {
            // Mark as pending manual payout
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array(
                    'status' => self::STATUS_RELEASED,
                    'released_at' => current_time('mysql'),
                    'release_method' => 'pending_manual',
                    'release_notes' => 'Trainer does not have Stripe Connect. Manual payout required.',
                ),
                array('id' => $escrow_id)
            );
            
            self::log_event($escrow_id, 'release_pending', 'Funds marked for manual payout - trainer needs Stripe Connect');
            
            return array('status' => 'pending_manual');
        }
        
        // Transfer funds to trainer via Stripe Connect
        $transfer = PTP_Stripe::create_transfer(
            round($escrow->trainer_amount * 100), // cents
            $escrow->stripe_account_id,
            'Session payment - Booking #' . $escrow->booking_id
        );
        
        if (is_wp_error($transfer)) {
            self::log_event($escrow_id, 'release_failed', 'Transfer failed: ' . $transfer->get_error_message());
            return $transfer;
        }
        
        // Update escrow record
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_RELEASED,
                'released_at' => current_time('mysql'),
                'stripe_transfer_id' => $transfer['id'],
                'release_method' => 'stripe_connect',
            ),
            array('id' => $escrow_id)
        );
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'escrow_status' => self::STATUS_RELEASED,
                'payout_status' => 'paid',
                'payout_at' => current_time('mysql'),
            ),
            array('id' => $escrow->booking_id)
        );
        
        // Log event
        self::log_event($escrow_id, 'funds_released', 'Transferred $' . number_format($escrow->trainer_amount, 2) . ' to trainer');
        
        // Notify trainer
        self::notify_trainer_payment($escrow_id);
        
        return array(
            'status' => 'released',
            'transfer_id' => $transfer['id'],
            'amount' => $escrow->trainer_amount,
        );
    }
    
    /**
     * Refund payment to parent
     */
    public static function refund($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT e.*, t.stripe_account_id 
             FROM {$wpdb->prefix}ptp_escrow e
             LEFT JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
             WHERE e.id = %d",
            $escrow_id
        ));
        
        if (!$escrow) {
            return new WP_Error('not_found', 'Escrow record not found');
        }
        
        if ($escrow->status === self::STATUS_REFUNDED) {
            return new WP_Error('already_refunded', 'Already refunded');
        }
        
        if ($escrow->status === self::STATUS_RELEASED) {
            return new WP_Error('already_released', 'Cannot refund - funds already released to trainer');
        }
        
        // Process Stripe refund if we have a payment intent
        if (!empty($escrow->payment_intent_id) && class_exists('PTP_Stripe')) {
            $refund = PTP_Stripe::create_refund($escrow->payment_intent_id, round($escrow->total_amount * 100));
            
            if (is_wp_error($refund)) {
                self::log_event($escrow_id, 'refund_failed', 'Stripe refund failed: ' . $refund->get_error_message());
                // Continue anyway to mark as refunded for manual processing
            } else {
                self::log_event($escrow_id, 'refund_processed', 'Stripe refund processed: ' . ($refund['id'] ?? 'success'));
            }
        }
        
        // Update escrow status
        $wpdb->update(
            $wpdb->prefix . 'ptp_escrow',
            array(
                'status' => self::STATUS_REFUNDED,
                'refunded_at' => current_time('mysql'),
            ),
            array('id' => $escrow_id)
        );
        
        // Update booking
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'escrow_status' => self::STATUS_REFUNDED,
                'status' => 'refunded',
            ),
            array('id' => $escrow->booking_id)
        );
        
        // Log event
        self::log_event($escrow_id, 'refunded', 'Refunded $' . number_format($escrow->total_amount, 2) . ' to parent');
        
        // Notify parent
        if (class_exists('PTP_Email') && method_exists('PTP_Email', 'send_refund_notification')) {
            PTP_Email::send_refund_notification($escrow->parent_id, $escrow->total_amount, $escrow->booking_id);
        }
        
        return array(
            'status' => 'refunded',
            'amount' => $escrow->total_amount,
        );
    }
    
    /**
     * Process automatic releases for confirmed sessions past the waiting period
     */
    public static function process_auto_releases() {
        global $wpdb;
        
        // Get escrow records eligible for auto-release
        $eligible = $wpdb->get_results("
            SELECT * FROM {$wpdb->prefix}ptp_escrow
            WHERE status = '" . self::STATUS_SESSION_COMPLETE . "'
            AND release_eligible_at IS NOT NULL
            AND release_eligible_at <= NOW()
        ");
        
        foreach ($eligible as $escrow) {
            // Auto-confirm and release
            $wpdb->update(
                $wpdb->prefix . 'ptp_escrow',
                array(
                    'status' => self::STATUS_CONFIRMED,
                    'parent_confirmed_at' => current_time('mysql'),
                    'auto_confirmed' => 1,
                ),
                array('id' => $escrow->id)
            );
            
            self::log_event($escrow->id, 'auto_confirmed', 'Auto-confirmed after ' . self::AUTO_RELEASE_HOURS . ' hours with no dispute');
            
            // Release funds
            self::release_funds($escrow->id);
        }
        
        return count($eligible);
    }
    
    /**
     * Admin resolves dispute
     */
    public static function admin_resolve_dispute() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $escrow_id = isset($_POST['escrow_id']) ? intval($_POST['escrow_id']) : 0;
        $resolution = isset($_POST['resolution']) ? sanitize_text_field($_POST['resolution']) : '';
        $notes = isset($_POST['notes']) ? sanitize_textarea_field($_POST['notes']) : '';
        $refund_percent = isset($_POST['refund_percent']) ? intval($_POST['refund_percent']) : 0;
        
        if (!$escrow_id || !in_array($resolution, array('release', 'refund', 'partial'))) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }
        
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE id = %d",
            $escrow_id
        ));
        
        if (!$escrow) {
            wp_send_json_error(array('message' => 'Escrow not found'));
        }
        
        switch ($resolution) {
            case 'release':
                // Release full amount to trainer
                $result = self::release_funds($escrow_id);
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    array(
                        'dispute_resolution' => 'released_to_trainer',
                        'dispute_resolved_at' => current_time('mysql'),
                        'dispute_resolved_by' => get_current_user_id(),
                        'resolution_notes' => $notes,
                    ),
                    array('id' => $escrow_id)
                );
                self::log_event($escrow_id, 'dispute_resolved', 'Admin released funds to trainer: ' . $notes);
                break;
                
            case 'refund':
                // Full refund to parent
                $refund = PTP_Stripe::create_refund($escrow->payment_intent_id);
                if (!is_wp_error($refund)) {
                    $wpdb->update(
                        $wpdb->prefix . 'ptp_escrow',
                        array(
                            'status' => self::STATUS_REFUNDED,
                            'dispute_resolution' => 'refunded_to_parent',
                            'dispute_resolved_at' => current_time('mysql'),
                            'dispute_resolved_by' => get_current_user_id(),
                            'resolution_notes' => $notes,
                            'refund_amount' => $escrow->total_amount,
                        ),
                        array('id' => $escrow_id)
                    );
                    self::log_event($escrow_id, 'dispute_resolved', 'Admin issued full refund: ' . $notes);
                }
                break;
                
            case 'partial':
                // Partial refund and partial release
                $refund_amount = round($escrow->total_amount * ($refund_percent / 100), 2);
                $trainer_gets = $escrow->trainer_amount - round($escrow->trainer_amount * ($refund_percent / 100), 2);
                
                // Process partial refund
                $refund = PTP_Stripe::create_refund($escrow->payment_intent_id, $refund_amount);
                
                // Release remaining to trainer
                if (!is_wp_error($refund) && $trainer_gets > 0) {
                    $trainer_stripe_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT stripe_account_id FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                        $escrow->trainer_id
                    ));
                    
                    if ($trainer_stripe_id) {
                        // Transfer reduced amount
                        $transfer = PTP_Stripe::create_transfer(
                            round($trainer_gets * 100),
                            $trainer_stripe_id,
                            'Partial dispute resolution - Booking #' . $escrow->booking_id
                        );
                    }
                }
                
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    array(
                        'status' => self::STATUS_RELEASED,
                        'dispute_resolution' => 'partial_refund',
                        'dispute_resolved_at' => current_time('mysql'),
                        'dispute_resolved_by' => get_current_user_id(),
                        'resolution_notes' => $notes,
                        'refund_amount' => $refund_amount,
                        'released_at' => current_time('mysql'),
                    ),
                    array('id' => $escrow_id)
                );
                self::log_event($escrow_id, 'dispute_resolved', "Partial resolution: {$refund_percent}% refunded, rest to trainer. {$notes}");
                break;
        }
        
        // Notify both parties
        self::notify_dispute_resolved($escrow_id, $resolution);
        
        wp_send_json_success(array('message' => 'Dispute resolved successfully'));
    }
    
    /**
     * Admin manually releases funds
     */
    public static function admin_release_funds() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $escrow_id = isset($_POST['escrow_id']) ? intval($_POST['escrow_id']) : 0;
        
        if (!$escrow_id) {
            wp_send_json_error(array('message' => 'Invalid escrow ID'));
        }
        
        $result = self::release_funds($escrow_id);
        
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        }
        
        wp_send_json_success(array('message' => 'Funds released successfully'));
    }
    
    /**
     * Get escrow status for booking
     */
    public static function get_status($booking_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare("
            SELECT e.*, 
                   t.display_name as trainer_name,
                   p.name as player_name,
                   pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
            LEFT JOIN {$wpdb->prefix}ptp_players p ON b.player_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.booking_id = %d
        ", $booking_id));
    }
    
    /**
     * Log escrow event
     */
    private static function log_event($escrow_id, $event_type, $message) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'ptp_escrow_log',
            array(
                'escrow_id' => $escrow_id,
                'event_type' => $event_type,
                'message' => $message,
                'user_id' => get_current_user_id() ?: 0,
                'created_at' => current_time('mysql'),
            )
        );
    }
    
    /**
     * Notify parent to confirm session
     */
    private static function notify_parent_to_confirm($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.display_name as trainer_name, pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.id = %d
        ", $escrow_id));
        
        if (!$escrow) return;
        
        // Send email using the proper template
        if (class_exists('PTP_Email')) {
            PTP_Email::send_session_completion_request($escrow->booking_id);
        }
        
        // Send SMS if enabled
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled()) {
            PTP_SMS::send_completion_request($escrow->booking_id);
        }
        
        // Send push notification
        if (class_exists('PTP_Push_Notifications')) {
            PTP_Push_Notifications::send(
                $escrow->parent_user_id,
                'Please Confirm Your Session',
                "Your session with {$escrow->trainer_name} is marked complete. Tap to confirm.",
                array('type' => 'session_confirm', 'booking_id' => $escrow->booking_id)
            );
        }
    }
    
    /**
     * Notify admin of dispute
     */
    private static function notify_admin_dispute($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.display_name as trainer_name, pa.display_name as parent_name
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.id = %d
        ", $escrow_id));
        
        $admin_email = get_option('admin_email');
        $subject = "[PTP] Payment Dispute - Booking #{$escrow->booking_id}";
        $message = "A payment dispute has been filed.\n\n";
        $message .= "Booking: #{$escrow->booking_id}\n";
        $message .= "Trainer: {$escrow->trainer_name}\n";
        $message .= "Parent: {$escrow->parent_name}\n";
        $message .= "Amount: $" . number_format($escrow->total_amount, 2) . "\n";
        $message .= "Reason: {$escrow->dispute_reason}\n\n";
        $message .= "Review at: " . admin_url('admin.php?page=ptp-disputes&escrow=' . $escrow_id);
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Notify trainer of dispute
     */
    private static function notify_trainer_dispute($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.user_id as trainer_user_id
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            WHERE e.id = %d
        ", $escrow_id));
        
        $trainer_user = get_user_by('ID', $escrow->trainer_user_id);
        if (!$trainer_user) return;
        
        $subject = "Session Payment Under Review";
        $message = "A parent has raised a concern about a recent session. Your payment of $" . number_format($escrow->trainer_amount, 2) . " is on hold while our team reviews.\n\n";
        $message .= "We'll contact you within 24-48 hours if we need any information.\n\n";
        $message .= "View details: " . home_url('/trainer-dashboard/');
        
        wp_mail($trainer_user->user_email, $subject, $message);
    }
    
    /**
     * Notify trainer of payment release
     */
    private static function notify_trainer_payment($escrow_id) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, t.user_id as trainer_user_id, t.display_name as trainer_name, t.phone as trainer_phone
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            WHERE e.id = %d
        ", $escrow_id));
        
        if (!$escrow) return;
        
        // Send email
        if (class_exists('PTP_Email')) {
            PTP_Email::send_payout_processed($escrow->trainer_id, $escrow->trainer_amount, 'Stripe Connect');
        }
        
        // Send SMS
        if (class_exists('PTP_SMS') && PTP_SMS::is_enabled() && !empty($escrow->trainer_phone)) {
            PTP_SMS::send_payout_notification($escrow->trainer_phone, $escrow->trainer_amount);
        }
        
        // Send push notification
        if (class_exists('PTP_Push_Notifications')) {
            PTP_Push_Notifications::send(
                $escrow->trainer_user_id,
                '💰 Payout Sent!',
                '$' . number_format($escrow->trainer_amount, 2) . ' is on its way to your bank',
                array('type' => 'payout', 'amount' => $escrow->trainer_amount)
            );
        }
    }
    
    /**
     * Notify both parties of dispute resolution
     */
    private static function notify_dispute_resolved($escrow_id, $resolution) {
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare("
            SELECT e.*, 
                   t.user_id as trainer_user_id,
                   pa.user_id as parent_user_id
            FROM {$wpdb->prefix}ptp_escrow e
            JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            JOIN {$wpdb->prefix}ptp_parents pa ON e.parent_id = pa.id
            WHERE e.id = %d
        ", $escrow_id));
        
        // Email both parties about resolution
        $resolution_text = array(
            'release' => 'Funds have been released to the trainer.',
            'refund' => 'A full refund has been issued.',
            'partial' => 'A partial resolution has been applied.',
        );
        
        $trainer_user = get_user_by('ID', $escrow->trainer_user_id);
        $parent_user = get_user_by('ID', $escrow->parent_user_id);
        
        $subject = "Dispute Resolved - Booking #{$escrow->booking_id}";
        $message = "Your dispute has been reviewed and resolved.\n\n";
        $message .= "Resolution: " . ($resolution_text[$resolution] ?? $resolution) . "\n\n";
        $message .= "If you have questions, please contact support.";
        
        if ($trainer_user) {
            wp_mail($trainer_user->user_email, $subject, $message);
        }
        if ($parent_user) {
            wp_mail($parent_user->user_email, $subject, $message);
        }
    }
}

// Initialize
PTP_Escrow::init();
