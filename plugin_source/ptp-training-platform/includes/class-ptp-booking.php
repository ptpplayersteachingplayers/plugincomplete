<?php
/**
 * Booking Class
 */

defined('ABSPATH') || exit;

class PTP_Booking {
    
    public static function get($booking_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
    }
    
    public static function get_by_number($booking_number) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE booking_number = %s",
            $booking_number
        ));
    }
    
    public static function get_full($booking_id) {
        global $wpdb;
        // v119: Use LEFT JOIN for parents AND players to handle guest checkout
        return $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, 
                    t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug, t.user_id as trainer_user_id, t.email as trainer_email,
                    COALESCE(pa.display_name, 'Guest') as parent_name, pa.user_id as parent_user_id, pa.email as parent_email,
                    COALESCE(pl.name, 'Player') as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
             LEFT JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.id = %d",
            $booking_id
        ));
    }
    
    public static function create($data) {
        global $wpdb;
        
        // Ensure table has correct columns
        PTP_Database::quick_repair();
        
        // Validate required fields
        $required = array('trainer_id', 'parent_id', 'player_id', 'session_date', 'start_time');
        foreach ($required as $field) {
            if (empty($data[$field])) {
                return new WP_Error('missing_field', "Missing required field: $field");
            }
        }
        
        // Start transaction for atomicity
        $wpdb->query('START TRANSACTION');
        
        try {
            // Check for double booking (with row lock)
            if (self::is_slot_booked($data['trainer_id'], $data['session_date'], $data['start_time'])) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('slot_taken', 'This time slot is no longer available');
            }
            
            // Get trainer rate
            $trainer = PTP_Trainer::get($data['trainer_id']);
            if (!$trainer) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('invalid_trainer', 'Trainer not found');
            }
            
            $hourly_rate = floatval($trainer->hourly_rate ?: 70);
            $duration = intval($data['duration_minutes'] ?? 60);
            $total_amount = ($hourly_rate * $duration) / 60;
            $platform_fee = $total_amount * ptp_get_platform_fee(); // Uses admin-configurable setting
            $trainer_payout = $total_amount - $platform_fee;
            
            // Calculate end time
            $start_time = $data['start_time'];
            $end_time = date('H:i:s', strtotime($start_time) + ($duration * 60));
            
            // Build insert data - only use columns we're sure exist
            $insert_data = array(
                'booking_number' => self::generate_booking_number(),
                'trainer_id' => intval($data['trainer_id']),
                'parent_id' => intval($data['parent_id']),
                'player_id' => intval($data['player_id']),
                'session_date' => sanitize_text_field($data['session_date']),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'location' => sanitize_text_field($data['location'] ?? ''),
                'hourly_rate' => $hourly_rate,
                'total_amount' => $total_amount,
                'platform_fee' => $platform_fee,
                'trainer_payout' => $trainer_payout,
                'status' => 'confirmed',
                'payment_status' => 'paid',
                'notes' => sanitize_textarea_field($data['notes'] ?? ''),
            );
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Booking: Creating for trainer_id=' . $data['trainer_id'] . ', date=' . $data['session_date']);
            }
            
            $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $insert_data);
            
            if ($result === false) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('PTP Booking Create Failed: ' . $wpdb->last_error);
                }
                
                // Try one more repair and retry
                PTP_Database::repair_tables();
                $result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', $insert_data);
                
                if ($result === false) {
                    $wpdb->query('ROLLBACK');
                    return new WP_Error('db_error', 'Failed to create booking: ' . $wpdb->last_error);
                }
            }
            
            $booking_id = $wpdb->insert_id;
            
            // Commit the transaction
            $wpdb->query('COMMIT');
            
            // Send notifications (wrapped in try-catch to not break booking)
            try {
                if (class_exists('PTP_Notifications')) {
                    PTP_Notifications::booking_created($booking_id);
                }
            } catch (Exception $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('PTP Notification Error: ' . $e->getMessage());
                }
            }
            
            return $booking_id;
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Booking Exception: ' . $e->getMessage());
            }
            return new WP_Error('booking_error', 'An error occurred while creating the booking');
        }
    }
    
    public static function is_slot_booked($trainer_id, $date, $time) {
        global $wpdb;
        
        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND session_date = %s AND start_time = %s 
             AND status NOT IN ('cancelled', 'no_show')",
            $trainer_id, $date, $time
        ));
    }
    
    public static function update_status($booking_id, $status, $user_id = null) {
        global $wpdb;
        
        $valid_statuses = array('pending', 'confirmed', 'completed', 'cancelled', 'no_show');
        if (!in_array($status, $valid_statuses)) {
            return new WP_Error('invalid_status', 'Invalid booking status');
        }
        
        $update_data = array('status' => $status);
        
        if ($status === 'cancelled' && $user_id) {
            $update_data['cancelled_by'] = $user_id;
            $update_data['cancelled_at'] = current_time('mysql');
        }
        
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            $update_data,
            array('id' => $booking_id)
        );
        
        if ($result !== false && $status === 'completed') {
            $booking = self::get($booking_id);
            PTP_Trainer::update_stats($booking->trainer_id);
            PTP_Parent::update_stats($booking->parent_id);
            
            // Fire action for other integrations (trainer referrals, email automation, etc.)
            do_action('ptp_session_completed', $booking_id, $booking);
            do_action('ptp_booking_completed', $booking_id, $booking);
        }
        
        return $result;
    }
    
    public static function confirm_by_parent($booking_id, $parent_id) {
        global $wpdb;
        
        $booking = self::get($booking_id);
        if (!$booking || $booking->parent_id != $parent_id) {
            return new WP_Error('invalid_booking', 'Booking not found');
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'parent_confirmed' => 1,
                'parent_confirmed_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        // Check if both confirmed
        self::check_completion($booking_id);
        
        return true;
    }
    
    public static function confirm_by_trainer($booking_id, $trainer_id) {
        global $wpdb;
        
        $booking = self::get($booking_id);
        if (!$booking || $booking->trainer_id != $trainer_id) {
            return new WP_Error('invalid_booking', 'Booking not found');
        }
        
        $wpdb->update(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'trainer_confirmed' => 1,
                'trainer_confirmed_at' => current_time('mysql'),
            ),
            array('id' => $booking_id)
        );
        
        // Check if both confirmed
        self::check_completion($booking_id);
        
        return true;
    }
    
    private static function check_completion($booking_id) {
        global $wpdb;
        
        $booking = self::get($booking_id);
        
        if ($booking->parent_confirmed && $booking->trainer_confirmed) {
            self::update_status($booking_id, 'completed');
            
            // Release funds via escrow system (proper payout flow)
            if (class_exists('PTP_Escrow')) {
                $result = PTP_Escrow::release_funds($booking_id);
                if (is_wp_error($result) && defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('PTP Booking: Escrow release failed for booking ' . $booking_id . ': ' . $result->get_error_message());
                }
            }
            
            // Fire action for any custom payout handling
            do_action('ptp_booking_ready_for_payout', $booking_id, $booking);
        }
    }
    
    public static function get_trainer_bookings($trainer_id, $status = null, $upcoming = true) {
        global $wpdb;
        
        $where = "b.trainer_id = %d";
        $params = array($trainer_id);
        
        if ($status) {
            $where .= " AND b.status = %s";
            $params[] = $status;
        }
        
        if ($upcoming) {
            $where .= " AND b.session_date >= CURDATE()";
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, pa.display_name as parent_name, pl.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE $where
             ORDER BY b.session_date ASC, b.start_time ASC",
            $params
        ));
    }
    
    public static function get_pending_confirmations($trainer_id) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT b.*, pa.display_name as parent_name, pl.name as player_name
             FROM {$wpdb->prefix}ptp_bookings b
             JOIN {$wpdb->prefix}ptp_parents pa ON b.parent_id = pa.id
             JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
             WHERE b.trainer_id = %d AND b.status = 'confirmed' AND b.trainer_confirmed = 0
             AND b.session_date < CURDATE()
             ORDER BY b.session_date DESC",
            $trainer_id
        ));
    }
    
    private static function generate_booking_number() {
        return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
    }
    
    /**
     * Get all bookings for a trainer on a specific date
     * Used by booking wizard for availability checking
     */
    public static function get_trainer_bookings_for_date($trainer_id, $date) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, start_time, end_time, duration_minutes, status
             FROM {$wpdb->prefix}ptp_bookings
             WHERE trainer_id = %d 
             AND session_date = %s
             AND status NOT IN ('cancelled', 'no_show')
             ORDER BY start_time ASC",
            $trainer_id,
            $date
        ));
    }
}
