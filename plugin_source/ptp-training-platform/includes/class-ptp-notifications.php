<?php
/**
 * Notifications Class
 */

defined('ABSPATH') || exit;

class PTP_Notifications {
    
    public static function create($user_id, $type, $title, $message, $data = array()) {
        global $wpdb;
        
        return $wpdb->insert($wpdb->prefix . 'ptp_notifications', array(
            'user_id' => $user_id,
            'type' => sanitize_text_field($type),
            'title' => sanitize_text_field($title),
            'message' => sanitize_textarea_field($message),
            'data' => json_encode($data),
        ));
    }
    
    public static function get_for_user($user_id, $limit = 20, $unread_only = false) {
        global $wpdb;
        
        $where = "user_id = %d";
        $params = array($user_id);
        
        if ($unread_only) {
            $where .= " AND is_read = 0";
        }
        
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_notifications WHERE $where ORDER BY created_at DESC LIMIT %d",
            $params
        ));
    }
    
    public static function get_unread_count($user_id) {
        global $wpdb;
        
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ptp_notifications WHERE user_id = %d AND is_read = 0",
            $user_id
        ));
    }
    
    public static function mark_as_read($notification_id, $user_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_notifications',
            array('is_read' => 1, 'read_at' => current_time('mysql')),
            array('id' => $notification_id, 'user_id' => $user_id)
        );
    }
    
    public static function mark_all_read($user_id) {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'ptp_notifications',
            array('is_read' => 1, 'read_at' => current_time('mysql')),
            array('user_id' => $user_id, 'is_read' => 0)
        );
    }
    
    public static function booking_created($booking_id) {
        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Notifications: booking_created failed - booking not found: ' . $booking_id);
            }
            return;
        }
        
        $date = date('l, F j', strtotime($booking->session_date));
        $time = date('g:i A', strtotime($booking->start_time));
        
        // Get user data with null checks
        $trainer_user = isset($booking->trainer_user_id) ? get_userdata($booking->trainer_user_id) : null;
        $parent_user = isset($booking->parent_user_id) ? get_userdata($booking->parent_user_id) : null;
        
        // Notify trainer (in-app notification)
        if ($booking->trainer_user_id) {
            self::create(
                $booking->trainer_user_id,
                'new_booking',
                'New Booking!',
                sprintf('%s booked a session with %s on %s at %s', 
                    $booking->parent_name, $booking->player_name, $date, $time),
                array('booking_id' => $booking_id)
            );
        }
        
        // Send email to trainer
        if ($trainer_user && is_email($trainer_user->user_email)) {
            $trainer_email = $trainer_user->user_email;
            $trainer_subject = 'New Booking - ' . ($booking->player_name ?: 'New Player');
            $trainer_body = self::render_booking_email_html(
                'trainer',
                $booking,
                $date,
                $time
            );
            
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: PTP <hello@ptpsummercamps.com>');
            $sent = wp_mail($trainer_email, $trainer_subject, $trainer_body, $headers);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Notifications: Trainer email ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $trainer_email);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Notifications: No valid trainer email for booking ' . $booking_id);
            }
        }
        
        // Notify parent (in-app notification)
        if ($booking->parent_user_id) {
            self::create(
                $booking->parent_user_id,
                'booking_confirmed',
                'Booking Confirmed!',
                sprintf('Your session with %s on %s at %s is confirmed!', 
                    $booking->trainer_name, $date, $time),
                array('booking_id' => $booking_id)
            );
        }
        
        // Send email to parent - with fallbacks for guest checkout
        $parent_email = null;
        if ($parent_user && is_email($parent_user->user_email)) {
            $parent_email = $parent_user->user_email;
        } elseif (!empty($booking->parent_email) && is_email($booking->parent_email)) {
            $parent_email = $booking->parent_email;
        } elseif (!empty($booking->guest_email) && is_email($booking->guest_email)) {
            $parent_email = $booking->guest_email;
        }
        
        if ($parent_email) {
            $parent_subject = 'Booking Confirmed - ' . ($booking->trainer_name ?: 'Your Trainer');
            $parent_body = self::render_booking_email_html(
                'parent',
                $booking,
                $date,
                $time
            );
            
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: PTP <hello@ptpsummercamps.com>');
            $sent = wp_mail($parent_email, $parent_subject, $parent_body, $headers);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Notifications: Parent email ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $parent_email);
            }
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Notifications: No valid parent email for booking ' . $booking_id);
            }
        }
        
        // v119: Also send to trainer email from record if WP user not found
        if (!$trainer_user && !empty($booking->trainer_email) && is_email($booking->trainer_email)) {
            $trainer_email = $booking->trainer_email;
            $trainer_subject = 'New Booking - ' . ($booking->player_name ?: 'New Player');
            $trainer_body = self::render_booking_email_html('trainer', $booking, $date, $time);
            $headers = array('Content-Type: text/html; charset=UTF-8', 'From: PTP <hello@ptpsummercamps.com>');
            $sent = wp_mail($trainer_email, $trainer_subject, $trainer_body, $headers);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('PTP Notifications: Trainer email (fallback) ' . ($sent ? 'sent' : 'FAILED') . ' to ' . $trainer_email);
            }
        }
    }
    
    /**
     * Render HTML email for booking notifications
     */
    private static function render_booking_email_html($type, $booking, $date, $time) {
        $logo_url = 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png';
        $dashboard_url = home_url($type === 'trainer' ? '/trainer-dashboard/' : '/parent-dashboard/');
        
        if ($type === 'trainer') {
            $heading = 'New Booking!';
            $message = 'You have a new training session booked.';
            $details = array(
                'Player' => $booking->player_name ?: 'N/A',
                'Parent' => $booking->parent_name ?: 'N/A',
                'Date' => $date,
                'Time' => $time,
                'Location' => $booking->location ?: 'To be determined',
            );
            $cta_text = 'View in Dashboard';
        } else {
            $heading = 'Booking Confirmed!';
            $message = 'Your training session has been confirmed.';
            $details = array(
                'Trainer' => $booking->trainer_name ?: 'N/A',
                'Player' => $booking->player_name ?: 'N/A',
                'Date' => $date,
                'Time' => $time,
                'Booking #' => $booking->booking_number ?: $booking->id,
            );
            $cta_text = 'View Booking';
        }
        
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #F5F5F5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #F5F5F5; padding: 40px 20px;">
        <tr>
            <td align="center">
                <table width="100%" style="max-width: 500px;" cellpadding="0" cellspacing="0">
                    <!-- Logo -->
                    <tr>
                        <td align="center" style="padding-bottom: 24px;">
                            <img src="<?php echo esc_url($logo_url); ?>" alt="PTP" width="80" style="max-width: 80px;">
                        </td>
                    </tr>
                    
                    <!-- Card -->
                    <tr>
                        <td style="background: #FFFFFF; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.08);">
                            <!-- Gold bar -->
                            <div style="height: 4px; background: #FCB900;"></div>
                            
                            <!-- Content -->
                            <div style="padding: 32px;">
                                <h1 style="margin: 0 0 8px; font-size: 24px; font-weight: 700; color: #0A0A0A; text-align: center;"><?php echo esc_html($heading); ?></h1>
                                <p style="margin: 0 0 24px; font-size: 15px; color: #525252; text-align: center;"><?php echo esc_html($message); ?></p>
                                
                                <!-- Details -->
                                <table width="100%" style="background: #F9FAFB; border-radius: 8px; margin-bottom: 24px;" cellpadding="12" cellspacing="0">
                                    <?php foreach ($details as $label => $value): ?>
                                    <tr>
                                        <td style="font-size: 13px; color: #6B7280; font-weight: 600; width: 100px; border-bottom: 1px solid #E5E7EB;"><?php echo esc_html($label); ?></td>
                                        <td style="font-size: 14px; color: #0A0A0A; border-bottom: 1px solid #E5E7EB;"><?php echo esc_html($value); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </table>
                                
                                <!-- CTA -->
                                <table width="100%" cellpadding="0" cellspacing="0">
                                    <tr>
                                        <td align="center">
                                            <a href="<?php echo esc_url($dashboard_url); ?>" style="display: inline-block; background: #FCB900; color: #0A0A0A; padding: 14px 28px; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 14px;"><?php echo esc_html($cta_text); ?></a>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 20px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #9CA3AF;">PTP - Players Teaching Players</p>
                            <p style="margin: 8px 0 0; font-size: 12px; color: #9CA3AF;">Questions? Reply to this email or call (610) 761-5230</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    public static function booking_cancelled($booking_id, $cancelled_by) {
        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking) return;
        
        $date = date('l, F j', strtotime($booking->session_date));
        
        // Determine who to notify
        if ($cancelled_by == $booking->trainer_user_id) {
            self::create(
                $booking->parent_user_id,
                'booking_cancelled',
                'Booking Cancelled',
                sprintf('Your session with %s on %s has been cancelled by the trainer.', 
                    $booking->trainer_name, $date),
                array('booking_id' => $booking_id)
            );
        } else {
            self::create(
                $booking->trainer_user_id,
                'booking_cancelled',
                'Booking Cancelled',
                sprintf('The session with %s on %s has been cancelled.', 
                    $booking->player_name, $date),
                array('booking_id' => $booking_id)
            );
        }
    }
    
    public static function session_reminder($booking_id) {
        $booking = PTP_Booking::get_full($booking_id);
        if (!$booking) return;
        
        $time = date('g:i A', strtotime($booking->start_time));
        
        // Remind trainer
        self::create(
            $booking->trainer_user_id,
            'session_reminder',
            'Session Tomorrow',
            sprintf('Reminder: You have a session with %s tomorrow at %s', 
                $booking->player_name, $time),
            array('booking_id' => $booking_id)
        );
        
        // Remind parent
        self::create(
            $booking->parent_user_id,
            'session_reminder',
            'Session Tomorrow',
            sprintf('Reminder: %s has a session with %s tomorrow at %s', 
                $booking->player_name, $booking->trainer_name, $time),
            array('booking_id' => $booking_id)
        );
    }
    
    public static function new_message($conversation_id, $sender_id) {
        $conversation = PTP_Messaging::get_conversation($conversation_id);
        if (!$conversation) return;
        
        $sender = get_userdata($sender_id);
        $sender_name = $sender ? $sender->display_name : 'Someone';
        
        $trainer = PTP_Trainer::get($conversation->trainer_id);
        $parent = PTP_Parent::get($conversation->parent_id);
        
        // Notify the other person
        if ($trainer && $trainer->user_id == $sender_id) {
            // Trainer sent, notify parent
            self::create(
                $parent->user_id,
                'new_message',
                'New Message',
                sprintf('You have a new message from %s', $trainer->display_name),
                array('conversation_id' => $conversation_id)
            );
        } elseif ($parent && $parent->user_id == $sender_id) {
            // Parent sent, notify trainer
            self::create(
                $trainer->user_id,
                'new_message',
                'New Message',
                sprintf('You have a new message from %s', $parent->display_name),
                array('conversation_id' => $conversation_id)
            );
        }
    }
}
