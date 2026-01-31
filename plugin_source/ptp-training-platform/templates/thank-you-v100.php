<?php
/**
 * Thank You Page v100 - Viral Machine Edition
 * 
 * Features:
 * - Social announcement opt-in (Instagram post feature)
 * - Integrated referral system
 * - One-click upsell for private training
 * - Confetti celebration
 * - Mobile-first PTP design
 * 
 * @since 100.0.0
 * @updated 115.4.0 - Enhanced order retrieval, email display fallback
 * @updated 130.3 - Added error handling wrapper
 * @updated 132.8 - Fixed Throwable catching for PHP 8+
 */

// v132.8: ULTRA-EARLY error protection - catches parse errors in this file
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// If we got here, the file at least parsed correctly
defined('ABSPATH') || exit;

// v130.3: Ensure WordPress is fully loaded
if (!function_exists('wp_create_nonce') || !function_exists('get_option')) {
    error_log('[PTP Thank You FATAL] WordPress not fully loaded');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Thank You</title></head><body style="font-family:sans-serif;text-align:center;padding:60px;"><h1>Thank You!</h1><p>Your payment was received. Check your email for confirmation.</p></div><!-- #ptp-scroll-wrapper -->
</body>
</html>';
    exit;
}

// v130.3: Error handling wrapper to catch and log any issues
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("[PTP Thank You ERROR] [$errno] $errstr in $errfile:$errline");
    return false; // Let PHP handle it as well
});

// v130.3: Register shutdown handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[PTP Thank You FATAL] ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

try {

global $wpdb;

// Get order/booking info - support both 'order' and 'order_id' params
$order_id = isset($_GET['order']) ? intval($_GET['order']) : (isset($_GET['order_id']) ? intval($_GET['order_id']) : 0);
$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : (isset($_GET['booking']) ? intval($_GET['booking']) : 0);

// v132.8: Also support 'bookings' (plural) from checkout-v77
if (!$booking_id && isset($_GET['bookings'])) {
    $bookings_param = sanitize_text_field($_GET['bookings']);
    $booking_ids_array = array_map('intval', explode(',', $bookings_param));
    if (!empty($booking_ids_array[0])) {
        $booking_id = $booking_ids_array[0];
        error_log('[PTP Thank You v132.8] Got booking_id from bookings param: ' . $booking_id);
    }
}

// v117.2.23: Also try booking_number lookup if booking_id is 0 (intval fails on alphanumeric)
$booking_number_param = isset($_GET['bn']) ? sanitize_text_field($_GET['bn']) : (isset($_GET['booking']) ? sanitize_text_field($_GET['booking']) : '');
if (!$booking_id && !empty($booking_number_param)) {
    // Try to find booking by booking_number
    $found_by_number = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE booking_number = %s ORDER BY id DESC LIMIT 1",
        $booking_number_param
    ));
    if ($found_by_number) {
        $booking_id = intval($found_by_number);
        error_log('[PTP Thank You v117.2.23] Found booking by booking_number: ' . $booking_id);
    }
}

// v115.3: Try WooCommerce session for order ID
if (!$order_id && function_exists('WC') && WC()->session) {
    $session_order_id = WC()->session->get('order_awaiting_payment');
    if ($session_order_id) {
        $order_id = intval($session_order_id);
        error_log('[PTP Thank You v115.3] Got order from session: ' . $order_id);
    }
}

// v115.3: Try cookie
if (!$order_id && isset($_COOKIE['ptp_last_order'])) {
    $order_id = intval($_COOKIE['ptp_last_order']);
    error_log('[PTP Thank You v115.3] Got order from cookie: ' . $order_id);
}

// v117.2.21: Try cookie for booking_id
if (!$booking_id && isset($_COOKIE['ptp_last_booking'])) {
    $booking_id = intval($_COOKIE['ptp_last_booking']);
    error_log('[PTP Thank You v117.2.21] Got booking from cookie: ' . $booking_id);
}

// v117.2.22: Try to find booking from payment_intent_id
$payment_intent_param = isset($_GET['payment_intent']) ? sanitize_text_field($_GET['payment_intent']) : '';
if (!$booking_id && !empty($payment_intent_param)) {
    $found_booking = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s ORDER BY id DESC LIMIT 1",
        $payment_intent_param
    ));
    if ($found_booking) {
        $booking_id = intval($found_booking);
        error_log('[PTP Thank You v117.2.22] Found booking from payment_intent: ' . $booking_id);
    }
}

// v117.2.22: Try to find recent booking for logged-in user (last 5 minutes)
if (!$booking_id && is_user_logged_in()) {
    $user_id = get_current_user_id();
    // Get parent_id for this user
    $parent_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
        $user_id
    ));
    if ($parent_id) {
        $found_booking = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE parent_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
             ORDER BY id DESC LIMIT 1",
            $parent_id
        ));
        if ($found_booking) {
            $booking_id = intval($found_booking);
            error_log('[PTP Thank You v117.2.22] Found recent booking for user: ' . $booking_id);
        }
    }
}

// v128.2.7: ENHANCED - Also try to find booking from checkout session trainer_id
$session_param_early = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
if (!$booking_id && !empty($session_param_early)) {
    $session_data_early = get_transient('ptp_checkout_' . $session_param_early);
    if ($session_data_early && !empty($session_data_early['trainer_id'])) {
        // Try to find booking for this trainer in last 10 minutes
        $trainer_id_check = intval($session_data_early['trainer_id']);
        $found_booking = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}ptp_bookings 
             WHERE trainer_id = %d AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)
             ORDER BY id DESC LIMIT 1",
            $trainer_id_check
        ));
        if ($found_booking) {
            $booking_id = intval($found_booking);
            error_log('[PTP Thank You v128.2.7] Found booking by trainer_id from session: ' . $booking_id);
        }
    }
}

// v117.2.22: Enhanced debug logging
// SECURITY: Sanitized logging - avoid exposing sensitive data
error_log('[PTP Thank You v117.2.22] ========== THANK YOU PAGE LOADED ==========');
error_log('[PTP Thank You v117.2.22] Has order_id: ' . ($order_id ? 'yes' : 'no') . ', Has booking_id: ' . ($booking_id ? 'yes' : 'no'));
// Note: Removed detailed GET params and cookie logging for security

// v117.2.28: BULLETPROOF recovery from checkout session if booking wasn't created
$session_param = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
$checkout_session_data = null;
$recovered_trainer = null;

if (!$booking_id && !empty($session_param)) {
    error_log('[PTP Thank You v117.2.28] ===== RECOVERY ATTEMPT =====');
    error_log('[PTP Thank You v117.2.28] Session: ' . $session_param);
    error_log('[PTP Thank You v117.2.28] Payment Intent: ' . $payment_intent_param);
    
    // Try to get checkout data from transient
    $checkout_session_data = get_transient('ptp_checkout_' . $session_param);
    
    if ($checkout_session_data) {
        error_log('[PTP Thank You v117.2.28] Found checkout session data!');
        error_log('[PTP Thank You v117.2.28] Full data: ' . json_encode($checkout_session_data));
        
        $session_trainer_id = intval($checkout_session_data['trainer_id'] ?? 0);
        $session_training_total = floatval($checkout_session_data['training_total'] ?? 0);
        
        // v117.2.28: If trainer_id is 0 but we have cart_items, check for training item
        if ($session_trainer_id === 0 && !empty($checkout_session_data['cart_items'])) {
            foreach ($checkout_session_data['cart_items'] as $item) {
                if (!empty($item['trainer_id'])) {
                    $session_trainer_id = intval($item['trainer_id']);
                    $session_training_total = floatval($item['total'] ?? $item['price'] ?? 0);
                    error_log('[PTP Thank You v117.2.28] Found trainer in cart_items: ' . $session_trainer_id);
                    break;
                }
            }
        }
        
        // v117.2.28: If still no training_total, use cart_total or final_total
        if ($session_training_total <= 0 && $session_trainer_id > 0) {
            $session_training_total = floatval($checkout_session_data['cart_total'] ?? $checkout_session_data['final_total'] ?? 0);
            error_log('[PTP Thank You v117.2.28] Using cart_total as training_total: ' . $session_training_total);
        }
        
        error_log('[PTP Thank You v117.2.28] Resolved trainer_id: ' . $session_trainer_id);
        error_log('[PTP Thank You v117.2.28] Resolved training_total: ' . $session_training_total);
        
        // Load trainer for display
        if ($session_trainer_id > 0) {
            $recovered_trainer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d",
                $session_trainer_id
            ));
            error_log('[PTP Thank You v117.2.28] Recovered trainer: ' . ($recovered_trainer ? $recovered_trainer->display_name : 'NOT FOUND'));
        }
        
        // v117.2.28: Create booking if we have trainer_id AND payment_intent (training_total can be 0 for free sessions)
        if ($session_trainer_id > 0 && !empty($payment_intent_param)) {
            // Check if booking already exists for this payment_intent
            $existing_booking = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ptp_bookings WHERE payment_intent_id = %s",
                $payment_intent_param
            ));
            
            if (!$existing_booking) {
                error_log('[PTP Thank You v117.2.28] Creating booking NOW as recovery...');
                
                // Get or create parent - with multiple email fallbacks
                $parent_data = $checkout_session_data['parent_data'] ?? array();
                $user_id = get_current_user_id();
                $parent_id = null;
                
                // v117.2.28: Get email with multiple fallbacks
                $parent_email = $parent_data['email'] ?? '';
                if (empty($parent_email) && $user_id > 0) {
                    $wp_user = get_userdata($user_id);
                    if ($wp_user) {
                        $parent_email = $wp_user->user_email;
                        error_log('[PTP Thank You v117.2.28] Using WP user email: ' . $parent_email);
                    }
                }
                if (empty($parent_email) && !empty($checkout_session_data['camper_data']['parent_email'])) {
                    $parent_email = $checkout_session_data['camper_data']['parent_email'];
                }
                
                error_log('[PTP Thank You v117.2.28] Parent email resolved: ' . ($parent_email ?: 'NONE'));
                error_log('[PTP Thank You v117.2.28] Parent data: ' . json_encode($parent_data));
                
                // Try to find or create parent
                if ($user_id > 0) {
                    $parent_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE user_id = %d",
                        $user_id
                    ));
                }
                if (!$parent_id && !empty($parent_email)) {
                    $parent_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$wpdb->prefix}ptp_parents WHERE email = %s",
                        $parent_email
                    ));
                }
                if (!$parent_id) {
                    // Create parent - even if email is empty, we need the record
                    $parent_first = $parent_data['first_name'] ?? '';
                    $parent_last = $parent_data['last_name'] ?? '';
                    $parent_phone = $parent_data['phone'] ?? '';
                    
                    // Try to get name from WP user if empty
                    if (empty($parent_first) && $user_id > 0) {
                        $wp_user = get_userdata($user_id);
                        if ($wp_user) {
                            $parent_first = $wp_user->first_name ?: $wp_user->display_name;
                            $parent_last = $wp_user->last_name;
                        }
                    }
                    
                    $wpdb->insert($wpdb->prefix . 'ptp_parents', array(
                        'user_id' => $user_id,
                        'first_name' => $parent_first,
                        'last_name' => $parent_last,
                        'email' => $parent_email,
                        'phone' => $parent_phone,
                        'created_at' => current_time('mysql'),
                    ));
                    $parent_id = $wpdb->insert_id;
                    error_log('[PTP Thank You v117.2.28] Created parent: ' . $parent_id . ' with email: ' . $parent_email);
                } else {
                    // Update existing parent with email if they don't have one
                    if (!empty($parent_email)) {
                        $existing_email = $wpdb->get_var($wpdb->prepare(
                            "SELECT email FROM {$wpdb->prefix}ptp_parents WHERE id = %d",
                            $parent_id
                        ));
                        if (empty($existing_email)) {
                            $wpdb->update(
                                $wpdb->prefix . 'ptp_parents',
                                array('email' => $parent_email),
                                array('id' => $parent_id)
                            );
                            error_log('[PTP Thank You v117.2.28] Updated parent ' . $parent_id . ' with email: ' . $parent_email);
                        }
                    }
                }
                
                // Create player if needed
                $player_id = intval($checkout_session_data['player_id'] ?? 0);
                $camper_data = $checkout_session_data['camper_data'] ?? array();
                if (!$player_id && $parent_id && !empty($camper_data['first_name'])) {
                    $player_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
                    $wpdb->insert($wpdb->prefix . 'ptp_players', array(
                        'parent_id' => $parent_id,
                        'first_name' => $camper_data['first_name'],
                        'last_name' => $camper_data['last_name'] ?? '',
                        'name' => $player_name,
                        'created_at' => current_time('mysql'),
                    ));
                    $player_id = $wpdb->insert_id;
                }
                
                // Create booking
                $training_package = $checkout_session_data['training_package'] ?? 'single';
                $sessions = array('single' => 1, 'pack3' => 3, 'pack5' => 5);
                $num_sessions = $sessions[$training_package] ?? 1;
                $platform_fee_pct = floatval(get_option('ptp_platform_fee_percent', 25));
                $platform_fee = round($session_training_total * ($platform_fee_pct / 100), 2);
                $trainer_payout = round($session_training_total - $platform_fee, 2);
                $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
                
                $booking_result = $wpdb->insert($wpdb->prefix . 'ptp_bookings', array(
                    'booking_number' => $booking_number,
                    'trainer_id' => $session_trainer_id,
                    'parent_id' => $parent_id,
                    'player_id' => $player_id,
                    'session_date' => $checkout_session_data['session_date'] ?? null,
                    'start_time' => $checkout_session_data['session_time'] ?? null,
                    'location' => $checkout_session_data['session_location'] ?? null,
                    'package_type' => $training_package,
                    'total_sessions' => $num_sessions,
                    'sessions_remaining' => $num_sessions,
                    'total_amount' => $session_training_total,
                    'amount_paid' => $session_training_total,
                    'platform_fee' => $platform_fee,
                    'trainer_payout' => $trainer_payout,
                    'payment_intent_id' => $payment_intent_param,
                    'payment_status' => 'paid',
                    'status' => 'confirmed',
                    'created_at' => current_time('mysql'),
                ));
                $booking_id = $wpdb->insert_id;
                
                if ($booking_id) {
                    error_log('[PTP Thank You v117.2.28] RECOVERY SUCCESS - Created booking: ' . $booking_id . ' (' . $booking_number . ')');
                    
                    // v117.2.28: Create escrow hold
                    if (class_exists('PTP_Escrow')) {
                        $escrow_id = PTP_Escrow::create_hold($booking_id, $payment_intent_param, $session_training_total);
                        if ($escrow_id) {
                            $wpdb->update(
                                $wpdb->prefix . 'ptp_bookings',
                                array('escrow_id' => $escrow_id, 'funds_held' => 1),
                                array('id' => $booking_id)
                            );
                            error_log('[PTP Thank You v117.2.28] Escrow created: ' . $escrow_id);
                        }
                    }
                    
                    // v117.2.28: Create package credits for multi-session packs
                    if ($num_sessions > 1 && $parent_id) {
                        $remaining_sessions = $num_sessions - 1;
                        $price_per_session = $session_training_total > 0 ? round($session_training_total / $num_sessions, 2) : 0;
                        $expires_at = date('Y-m-d H:i:s', strtotime('+1 year'));
                        
                        $credit_result = $wpdb->insert(
                            $wpdb->prefix . 'ptp_package_credits',
                            array(
                                'parent_id' => $parent_id,
                                'trainer_id' => $session_trainer_id,
                                'package_type' => $training_package,
                                'total_credits' => $num_sessions,
                                'remaining' => $remaining_sessions,
                                'price_per_session' => $price_per_session,
                                'total_paid' => $session_training_total,
                                'payment_intent_id' => $payment_intent_param,
                                'expires_at' => $expires_at,
                                'status' => 'active',
                                'created_at' => current_time('mysql'),
                            )
                        );
                        if ($credit_result) {
                            $credit_id = $wpdb->insert_id;
                            $wpdb->update(
                                $wpdb->prefix . 'ptp_bookings',
                                array('package_credit_id' => $credit_id),
                                array('id' => $booking_id)
                            );
                            error_log('[PTP Thank You v117.2.28] Package credits created: ' . $credit_id . ' with ' . $remaining_sessions . ' remaining');
                        }
                    }
                    
                    // Send emails now
                    if (class_exists('PTP_Unified_Checkout')) {
                        $camper_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
                        PTP_Unified_Checkout::instance()->notify_trainer($session_trainer_id, $booking_id, $camper_name);
                        error_log('[PTP Thank You v117.2.28] Called notify_trainer for booking ' . $booking_id);
                    }
                    
                    // v117.2.28: FALLBACK - Direct email send if we have parent_email
                    // Check if email was actually sent by notify_trainer
                    $email_sent_check = get_transient('ptp_training_email_sent_' . $booking_id);
                    if (!$email_sent_check && !empty($parent_email)) {
                        error_log('[PTP Thank You v117.2.28] notify_trainer may have failed - sending DIRECT FALLBACK email to: ' . $parent_email);
                        
                        $player_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
                        $date_display = !empty($checkout_session_data['session_date']) ? date('l, F j, Y', strtotime($checkout_session_data['session_date'])) : 'TBD';
                        $time_display = 'TBD';
                        if (!empty($checkout_session_data['session_time'])) {
                            $hour = intval(explode(':', $checkout_session_data['session_time'])[0]);
                            $time_display = ($hour > 12 ? $hour - 12 : ($hour ?: 12)) . ':00 ' . ($hour >= 12 ? 'PM' : 'AM');
                        }
                        $location_display = $checkout_session_data['session_location'] ?? 'TBD';
                        $package_labels = array('single' => 'Single Session', 'pack3' => '3-Session Pack', 'pack5' => '5-Session Pack');
                        $package_display = $package_labels[$training_package] ?? 'Training Session';
                        
                        $headers = array(
                            'Content-Type: text/html; charset=UTF-8',
                            'From: PTP Training <training@ptpsummercamps.com>',
                        );
                        
                        $subject = '✅ Training Session Confirmed - ' . $player_name;
                        $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;"><tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#0A0A0A;max-width:100%;">
<tr><td style="background:#FCB900;padding:24px;text-align:center;">
<div style="font-size:24px;font-weight:700;color:#0A0A0A;">PTP TRAINING</div>
</td></tr>
<tr><td style="padding:32px 24px;">
<h1 style="color:#fff;margin:0 0 20px;font-size:28px;">You\'re Locked In! 🔥</h1>
<p style="color:rgba(255,255,255,0.8);font-size:16px;line-height:1.6;margin:0 0 24px;">
Your training session with <strong style="color:#FCB900;">' . esc_html($recovered_trainer->display_name ?? 'your trainer') . '</strong> is confirmed.
</p>
<table width="100%" cellpadding="12" cellspacing="0" style="background:rgba(255,255,255,0.05);margin-bottom:24px;">
<tr><td style="color:rgba(0,0,0,0.5);font-size:12px;border-bottom:1px solid rgba(255,255,255,0.1);">PLAYER</td>
<td style="color:#fff;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.1);">' . esc_html($player_name ?: 'Your Player') . '</td></tr>
<tr><td style="color:rgba(0,0,0,0.5);font-size:12px;border-bottom:1px solid rgba(255,255,255,0.1);">PACKAGE</td>
<td style="color:#fff;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.1);">' . esc_html($package_display) . '</td></tr>
<tr><td style="color:rgba(0,0,0,0.5);font-size:12px;border-bottom:1px solid rgba(255,255,255,0.1);">DATE</td>
<td style="color:#fff;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.1);">' . esc_html($date_display) . '</td></tr>
<tr><td style="color:rgba(0,0,0,0.5);font-size:12px;border-bottom:1px solid rgba(255,255,255,0.1);">TIME</td>
<td style="color:#fff;font-weight:600;border-bottom:1px solid rgba(255,255,255,0.1);">' . esc_html($time_display) . '</td></tr>
<tr><td style="color:rgba(0,0,0,0.5);font-size:12px;">LOCATION</td>
<td style="color:#fff;font-weight:600;">' . esc_html($location_display) . '</td></tr>
</table>
<p style="color:rgba(0,0,0,0.6);font-size:14px;">Your trainer will reach out to confirm details. Questions? Reply to this email.</p>
</td></tr>
<tr><td style="background:#FCB900;padding:16px;text-align:center;">
<div style="color:#0A0A0A;font-size:12px;">PTP Training • Players Teaching Players</div>
</td></tr>
</table></td></tr></table></div><!-- #ptp-scroll-wrapper -->
</body>
</html>';
                        
                        $sent = wp_mail($parent_email, $subject, $body, $headers);
                        error_log('[PTP Thank You v117.2.28] FALLBACK email ' . ($sent ? 'SENT' : 'FAILED') . ' to: ' . $parent_email);
                        
                        if ($sent) {
                            set_transient('ptp_training_email_sent_' . $booking_id, array(
                                'parent' => $parent_email,
                                'method' => 'fallback',
                                'time' => time()
                            ), 24 * HOUR_IN_SECONDS);
                        }
                    }
                    
                    // Delete the transient to prevent duplicate creation
                    delete_transient('ptp_checkout_' . $session_param);
                } else {
                    error_log('[PTP Thank You v117.2.28] RECOVERY FAILED - DB error: ' . $wpdb->last_error);
                }
            } else {
                $booking_id = intval($existing_booking);
                error_log('[PTP Thank You v117.2.28] Booking already exists: ' . $booking_id);
            }
        }
    } else {
        error_log('[PTP Thank You v117.2.28] Checkout session transient not found (may have been deleted)');
    }
}

// v114.1: If no order ID, check recent orders for current user
if (!$order_id && is_user_logged_in() && function_exists('wc_get_customer_orders')) {
    $user_id = get_current_user_id();
    $recent_orders = wc_get_customer_orders(array(
        'customer_id' => $user_id,
        'limit' => 1,
        'status' => array('pending', 'processing', 'completed'),
    ));
    if (!empty($recent_orders)) {
        $order_id = $recent_orders[0]->get_id();
        error_log('[PTP Thank You v115.3] Found recent order for user ' . $user_id . ': ' . $order_id);
    }
}

// v115.5.1: REMOVED dangerous 5-minute fallback - could expose other customers' orders
// The cookie/session methods above are the only safe fallbacks for guest checkout

$booking = null;
$order = null;
$order_items = array();
$has_camp = false;
$has_training = false;
$camper_name = '';
$camp_name = '';
$camp_dates = '';
$camp_location = '';
$coach_name = '';
$coach_team = '';
$coach_photo = '';

// Get booking info
$training_date = '';
$training_time = '';
$training_location = '';
$training_package = '';
$training_sessions = 0;
$training_level = '';

if ($booking_id) {
    error_log('[PTP Thank You v128.2.7] Looking up booking #' . $booking_id);
    $booking = $wpdb->get_row($wpdb->prepare(
        "SELECT b.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug,
                t.headline as trainer_headline, t.playing_level as trainer_level, t.phone as trainer_phone,
                p.email as parent_email, p.phone as parent_phone, p.first_name as parent_first, p.last_name as parent_last,
                pl.first_name as player_first, pl.last_name as player_last,
                u_trainer.user_email as trainer_wp_email,
                u_parent.user_email as parent_wp_email
         FROM {$wpdb->prefix}ptp_bookings b
         LEFT JOIN {$wpdb->prefix}ptp_trainers t ON b.trainer_id = t.id
         LEFT JOIN {$wpdb->prefix}ptp_parents p ON b.parent_id = p.id
         LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
         LEFT JOIN {$wpdb->users} u_trainer ON t.user_id = u_trainer.ID
         LEFT JOIN {$wpdb->users} u_parent ON p.user_id = u_parent.ID
         WHERE b.id = %d", $booking_id
    ));
    error_log('[PTP Thank You v128.2.7] Booking query result: ' . ($booking ? 'FOUND - trainer=' . ($booking->trainer_name ?? 'none') . ', parent_email=' . ($booking->parent_email ?? 'none') : 'NOT FOUND'));
    if ($booking) {
        $has_training = true;
        $coach_name = $booking->trainer_name ?? '';
        $coach_photo = $booking->trainer_photo ?? '';
        
        // v117.2.11: Training-specific data
        // v123: Check both start_time (db column) and session_time (legacy)
        $training_date = $booking->session_date ?? '';
        $training_time = !empty($booking->start_time) ? $booking->start_time : ($booking->session_time ?? '');
        $training_location = $booking->location ?? '';
        $training_package = $booking->package_type ?? 'single';
        $training_sessions = $booking->total_sessions ?? 1;
        $training_level = $booking->trainer_level ?? '';
        
        // v117.2.26: Get package credits for multi-session packs
        $package_credits = null;
        $sessions_remaining = 0;
        if ($training_sessions > 1) {
            // Check for package credits created with this booking
            $package_credits = $wpdb->get_row($wpdb->prepare("
                SELECT pc.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.slug as trainer_slug
                FROM {$wpdb->prefix}ptp_package_credits pc
                LEFT JOIN {$wpdb->prefix}ptp_trainers t ON pc.trainer_id = t.id
                WHERE pc.payment_intent_id = %s
                OR (pc.parent_id = %d AND pc.trainer_id = %d AND pc.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR))
                ORDER BY pc.created_at DESC
                LIMIT 1
            ", $booking->payment_intent_id, $booking->parent_id, $booking->trainer_id));
            
            if ($package_credits) {
                $sessions_remaining = intval($package_credits->remaining);
                error_log('[PTP Thank You v117.2.26] Found package credits: ' . $package_credits->id . ' with ' . $sessions_remaining . ' remaining');
            }
        }
        
        // Get player name from booking
        $camper_name = trim(($booking->player_first ?? '') . ' ' . ($booking->player_last ?? ''));
        if (empty($camper_name) && !empty($booking->player_id)) {
            $player = $wpdb->get_row($wpdb->prepare(
                "SELECT first_name, last_name FROM {$wpdb->prefix}ptp_players WHERE id = %d",
                $booking->player_id
            ));
            if ($player) {
                $camper_name = trim($player->first_name . ' ' . $player->last_name);
            }
        }
        
        // v117.2.18: GUARANTEED EMAIL SENDING - Direct from thank-you page
        // Check if emails were already sent by notify_trainer
        $email_sent_data = get_transient('ptp_training_email_sent_' . $booking_id);
        $email_sent_key = 'ptp_ty_email_' . $booking_id;
        $ty_email_already_sent = get_transient($email_sent_key);
        
        if ($email_sent_data) {
            // Emails were sent by notify_trainer - log and skip
            error_log('[PTP Thank You v117.2.18] Emails already sent by notify_trainer at ' . date('Y-m-d H:i:s', $email_sent_data['time']) . ' to parent=' . ($email_sent_data['parent'] ?? 'none') . ', trainer=' . ($email_sent_data['trainer'] ?? 'none'));
        } elseif (!$ty_email_already_sent) {
            // Emails NOT sent yet - send them now as fallback
            error_log('[PTP Thank You v128.2.7] FALLBACK: Sending training booking emails for booking #' . $booking_id);
            
            // v128.2.7: Get email addresses with ENHANCED fallbacks
            $parent_email = $booking->parent_email ?: $booking->parent_wp_email ?: '';
            $trainer_email = $booking->trainer_wp_email ?: '';
            
            // v128.2.7: Try guest_email field from booking
            if (empty($parent_email) && !empty($booking->guest_email)) {
                $parent_email = $booking->guest_email;
                error_log('[PTP Thank You v128.2.7] Using guest_email: ' . $parent_email);
            }
            
            // v128.2.7: Try checkout session data
            $session_param_for_email = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
            if (empty($parent_email) && !empty($session_param_for_email)) {
                $checkout_data_email = get_transient('ptp_checkout_' . $session_param_for_email);
                if ($checkout_data_email && !empty($checkout_data_email['parent_data']['email'])) {
                    $parent_email = $checkout_data_email['parent_data']['email'];
                    error_log('[PTP Thank You v128.2.7] Using email from checkout session: ' . $parent_email);
                }
            }
            
            // Also try logged in user email
            if (empty($parent_email) && is_user_logged_in()) {
                $current_user = wp_get_current_user();
                $parent_email = $current_user->user_email;
                error_log('[PTP Thank You v128.2.7] Using logged in user email: ' . $parent_email);
            }
            
            // Format details for emails
            $date_display = !empty($training_date) ? date('l, F j, Y', strtotime($training_date)) : 'TBD - Trainer will confirm';
            $time_display = 'TBD';
            if (!empty($training_time)) {
                $time_parts = explode(':', $training_time);
                $hour = intval($time_parts[0]);
                $minute = isset($time_parts[1]) ? intval($time_parts[1]) : 0;
                $display_hour = $hour > 12 ? $hour - 12 : ($hour ?: 12);
                $ampm = $hour >= 12 ? 'PM' : 'AM';
                $time_display = $minute > 0 ? "{$display_hour}:" . str_pad($minute, 2, '0', STR_PAD_LEFT) . " {$ampm}" : "{$display_hour}:00 {$ampm}";
            }
            $location_display = !empty($training_location) ? $training_location : 'TBD - Trainer will confirm';
            $package_labels = array('single' => 'Single Session', 'pack3' => '3-Session Pack', 'pack5' => '5-Session Pack');
            $package_display = $package_labels[$training_package] ?? 'Training Session';
            $player_display = $camper_name ?: 'Player';
            
            // Email headers
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                'From: PTP Training <training@ptpsummercamps.com>',
                'Reply-To: training@ptpsummercamps.com'
            );
            
            // === SEND PARENT EMAIL ===
            if (!empty($parent_email)) {
                $parent_subject = '✅ Training Session Confirmed - ' . $player_display;
                $parent_body = ptp_get_training_confirmation_email($booking, $coach_name, $coach_photo, $player_display, $date_display, $time_display, $location_display, $package_display, $training_level);
                
                $sent = wp_mail($parent_email, $parent_subject, $parent_body, $headers);
                error_log('[PTP Thank You v128.2.7] Parent email ' . ($sent ? 'SENT' : 'FAILED') . ' to: ' . $parent_email);
            } else {
                error_log('[PTP Thank You v128.2.7] No parent email found after all fallbacks!');
            }
            
            // === SEND TRAINER EMAIL ===
            if (!empty($trainer_email)) {
                $trainer_subject = '🎉 New Training Booked - ' . $player_display;
                $trainer_payout = number_format($booking->trainer_payout ?? 0, 2);
                $trainer_body = ptp_get_trainer_notification_email($booking, $player_display, $date_display, $time_display, $location_display, $package_display, $trainer_payout);
                
                $sent = wp_mail($trainer_email, $trainer_subject, $trainer_body, $headers);
                error_log('[PTP Thank You v128.2.7] Trainer email ' . ($sent ? 'SENT' : 'FAILED') . ' to: ' . $trainer_email);
            } else {
                error_log('[PTP Thank You v128.2.7] No trainer email found!');
            }
            
            // Mark as sent (prevent duplicate sends on refresh)
            set_transient($email_sent_key, 1, 24 * HOUR_IN_SECONDS);
        }
    }
}

// Email template functions
if (!function_exists('ptp_get_training_confirmation_email')) {
    function ptp_get_training_confirmation_email($booking, $coach_name, $coach_photo, $player_name, $date, $time, $location, $package, $level) {
        $level_labels = array('pro'=>'MLS PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY','semi_pro'=>'SEMI-PRO');
        $level_display = $level_labels[$level] ?? 'PRO TRAINER';
        $avatar_url = $coach_photo ?: 'https://ui-avatars.com/api/?name=' . urlencode($coach_name) . '&size=80&background=FCB900&color=0A0A0A&bold=true';
        
        return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#0A0A0A;max-width:100%;">
<!-- Header -->
<tr><td style="background:#FCB900;padding:24px;text-align:center;">
<h1 style="margin:0;color:#0A0A0A;font-size:24px;font-weight:700;">TRAINING CONFIRMED! ⚽</h1>
</td></tr>
<!-- Trainer Card -->
<tr><td style="padding:24px;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:rgba(252,185,0,0.1);border:2px solid #FCB900;padding:16px;">
<tr>
<td width="80" style="vertical-align:top;">
<img src="' . esc_url($avatar_url) . '" width="72" height="72" style="border-radius:50%;border:3px solid #FCB900;">
</td>
<td style="padding-left:16px;vertical-align:top;">
<div style="color:#fff;font-size:18px;font-weight:700;">' . esc_html($coach_name) . '</div>
<div style="display:inline-block;background:#FCB900;color:#0A0A0A;font-size:10px;font-weight:600;padding:4px 10px;margin-top:6px;">' . esc_html($level_display) . '</div>
</td>
</tr>
</table>
</td></tr>
<!-- Session Details -->
<tr><td style="padding:0 24px 24px;">
<table width="100%" cellpadding="12" cellspacing="0" style="background:rgba(255,255,255,0.05);">
<tr>
<td width="50%" style="border-bottom:1px solid rgba(255,255,255,0.1);">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">PLAYER</div>
<div style="color:#fff;font-size:14px;font-weight:600;margin-top:4px;">' . esc_html($player_name) . '</div>
</td>
<td width="50%" style="border-bottom:1px solid rgba(255,255,255,0.1);">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">PACKAGE</div>
<div style="color:#fff;font-size:14px;font-weight:600;margin-top:4px;">' . esc_html($package) . '</div>
</td>
</tr>
<tr>
<td style="border-bottom:1px solid rgba(255,255,255,0.1);">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">DATE</div>
<div style="color:#fff;font-size:14px;font-weight:600;margin-top:4px;">' . esc_html($date) . '</div>
</td>
<td style="border-bottom:1px solid rgba(255,255,255,0.1);">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">TIME</div>
<div style="color:#fff;font-size:14px;font-weight:600;margin-top:4px;">' . esc_html($time) . '</div>
</td>
</tr>
<tr><td colspan="2">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">LOCATION</div>
<div style="color:#fff;font-size:14px;font-weight:600;margin-top:4px;">' . esc_html($location) . '</div>
</td></tr>
</table>
</td></tr>
<!-- What\'s Next -->
<tr><td style="padding:0 24px 24px;">
<div style="color:#FCB900;font-size:12px;font-weight:600;text-transform:uppercase;margin-bottom:12px;">WHAT\'S NEXT</div>
<table width="100%" cellpadding="0" cellspacing="8">
<tr><td style="background:rgba(255,255,255,0.05);padding:12px;">
<span style="display:inline-block;background:#FCB900;color:#0A0A0A;width:24px;height:24px;text-align:center;line-height:24px;font-weight:700;margin-right:12px;">1</span>
<span style="color:#fff;font-size:14px;">' . esc_html($coach_name) . ' will reach out to confirm details</span>
</td></tr>
<tr><td style="background:rgba(255,255,255,0.05);padding:12px;">
<span style="display:inline-block;background:#FCB900;color:#0A0A0A;width:24px;height:24px;text-align:center;line-height:24px;font-weight:700;margin-right:12px;">2</span>
<span style="color:#1a1a1a;font-size:14px;">Bring water, cleats, and a ball if you have one</span>
</td></tr>
<tr><td style="background:rgba(255,255,255,0.05);padding:12px;">
<span style="display:inline-block;background:#FCB900;color:#0A0A0A;width:24px;height:24px;text-align:center;line-height:24px;font-weight:700;margin-right:12px;">3</span>
<span style="color:#1a1a1a;font-size:14px;">Show up ready to work hard and have fun!</span>
</td></tr>
</table>
</td></tr>
<!-- Footer -->
<tr><td style="background:#FCB900;padding:16px;text-align:center;">
<div style="color:#0A0A0A;font-size:12px;">Questions? Reply to this email or text us at (610) 555-PTP1</div>
</td></tr>
</table>
</td></tr>
</table>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>';
    }
}

if (!function_exists('ptp_get_trainer_notification_email')) {
    function ptp_get_trainer_notification_email($booking, $player_name, $date, $time, $location, $package, $payout) {
        $needs_confirm = empty($booking->session_date);
        
        return '<!DOCTYPE html>
<html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">
<tr><td align="center">
<table width="600" cellpadding="0" cellspacing="0" style="background:#0A0A0A;max-width:100%;">
<!-- Header -->
<tr><td style="background:#FCB900;padding:24px;text-align:center;">
<h1 style="margin:0;color:#0A0A0A;font-size:24px;font-weight:700;">🎉 NEW BOOKING!</h1>
</td></tr>
<!-- Content -->
<tr><td style="padding:24px;">
<div style="color:#fff;font-size:16px;margin-bottom:20px;">You have a new training session booked!</div>
' . ($needs_confirm ? '<div style="background:#dc2626;color:#fff;padding:12px;margin-bottom:20px;font-weight:600;">⚠️ ACTION NEEDED: Confirm date/time with parent</div>' : '') . '
<!-- Booking Details -->
<table width="100%" cellpadding="12" cellspacing="0" style="background:rgba(255,255,255,0.05);margin-bottom:20px;">
<tr><td style="border-bottom:1px solid rgba(255,255,255,0.1);">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">PLAYER</div>
<div style="color:#1a1a1a;font-size:16px;font-weight:600;margin-top:4px;">' . esc_html($player_name) . '</div>
</td></tr>
<tr><td style="border-bottom:1px solid rgba(255,255,255,0.1);">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">PACKAGE</div>
<div style="color:#fff;font-size:14px;margin-top:4px;">' . esc_html($package) . '</div>
</td></tr>
<tr><td style="border-bottom:1px solid rgba(255,255,255,0.1);">
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">DATE & TIME</div>
<div style="color:#fff;font-size:14px;margin-top:4px;">' . esc_html($date) . ' at ' . esc_html($time) . '</div>
</td></tr>
<tr><td>
<div style="color:rgba(0,0,0,0.5);font-size:11px;text-transform:uppercase;">LOCATION</div>
<div style="color:#fff;font-size:14px;margin-top:4px;">' . esc_html($location) . '</div>
</td></tr>
</table>
<!-- Earnings -->
<div style="background:#FCB900;padding:16px;text-align:center;">
<div style="color:#0A0A0A;font-size:12px;text-transform:uppercase;">YOUR EARNINGS</div>
<div style="color:#0A0A0A;font-size:28px;font-weight:700;">$' . esc_html($payout) . '</div>
</div>
</td></tr>
<!-- CTA -->
<tr><td style="padding:0 24px 24px;">
<a href="' . esc_url(home_url('/trainer-dashboard/')) . '" style="display:block;background:#FCB900;color:#0A0A0A;text-align:center;padding:16px;font-size:14px;font-weight:700;text-decoration:none;text-transform:uppercase;">VIEW IN DASHBOARD →</a>
</td></tr>
</table>
</td></tr>
</table>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>';
    }
}

// Get WooCommerce order info
// v115.4: Run even with order_id=0 to use fallbacks
if (function_exists('wc_get_order')) {
    $order = null;
    
    // Try direct lookup if we have an ID
    if ($order_id) {
        $order = wc_get_order($order_id);
    }
    
    if ($order) {
        error_log('[PTP Thank You v115.4] ✓ Successfully loaded order #' . $order_id . 
            ', Status: ' . $order->get_status() . 
            ', Total: $' . $order->get_total());
    } else {
        if ($order_id) {
            error_log('[PTP Thank You v115.4] ✗ Order lookup failed for #' . $order_id);
        } else {
            error_log('[PTP Thank You v115.4] No order ID provided, trying fallbacks...');
        }
        
        // v114.1: Try alternate lookup via post
        if ($order_id) {
            $order_post = get_post($order_id);
            if ($order_post && in_array($order_post->post_type, ['shop_order', 'wc_order'])) {
                $order = wc_get_order($order_id);
                if ($order) {
                    error_log('[PTP Thank You v115.4] Recovered order #' . $order_id . ' via post lookup');
                }
            }
        }
        
        // v115.5.1: REMOVED dangerous fallback that could show other customers' orders
        // We should NEVER display an order without proper identification
    }
    
    if (!$order) {
        error_log('[PTP Thank You v115.4] ✗ NO ORDER FOUND after all fallbacks');
    }
}

// v115: CRITICAL - Trigger confirmation email if order exists and email not sent
if ($order) {
    $email_sent = $order->get_meta('_ptp_confirmation_email_sent') || $order->get_meta('_ptp_email_sent');
    
    if (!$email_sent) {
        error_log('[PTP Thank You v115] Email not yet sent for order #' . $order_id . ', triggering now...');
        
        // Use the wiring class if available
        if (class_exists('PTP_Order_Email_Wiring')) {
            PTP_Order_Email_Wiring::instance()->trigger_ptp_confirmation_email($order_id);
        }
        // Fallback to WooCommerce emails class
        elseif (class_exists('PTP_WooCommerce_Emails')) {
            PTP_WooCommerce_Emails::instance()->send_ptp_confirmation($order_id);
        }
        // Ultimate fallback - fire the WooCommerce action
        else {
            do_action('woocommerce_thankyou', $order_id);
        }
    } else {
        error_log('[PTP Thank You v115] Email already sent for order #' . $order_id);
    }
}

// Process order items ONLY if order exists
if ($order) {
    // Get camper name from order meta first
    $player_name = $order->get_meta('_player_name');
    if ($player_name) {
        $camper_name = $player_name;
    }
    
    // Also try _ptp_campers array
    $campers_data = $order->get_meta('_ptp_campers');
    if (is_array($campers_data) && !empty($campers_data)) {
        $first_camper = reset($campers_data);
        if (empty($camper_name) && !empty($first_camper['name'])) {
            $camper_name = $first_camper['name'];
        }
    }
    
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $pid = $product ? $product->get_id() : 0;
        $is_camp = get_post_meta($pid, '_ptp_is_camp', true) === 'yes';
        if (!$is_camp && $product) {
            $name = strtolower($product->get_name());
            $is_camp = strpos($name, 'camp') !== false || strpos($name, 'clinic') !== false;
        }
        
        if ($is_camp) {
            $has_camp = true;
            if (empty($camp_name)) {
                $camp_name = $item->get_name();
                
                // Try multiple meta keys for dates
                $camp_dates = get_post_meta($pid, '_ptp_start_date', true) 
                    ?: get_post_meta($pid, '_camp_date', true)
                    ?: get_post_meta($pid, '_camp_start_date', true)
                    ?: get_post_meta($pid, 'camp_date', true);
                    
                $camp_end = get_post_meta($pid, '_ptp_end_date', true)
                    ?: get_post_meta($pid, '_camp_end_date', true)
                    ?: get_post_meta($pid, 'camp_end_date', true);
                
                // Try multiple meta keys for location
                $camp_location = get_post_meta($pid, '_ptp_location_name', true)
                    ?: get_post_meta($pid, '_camp_location', true)
                    ?: get_post_meta($pid, 'camp_location', true)
                    ?: get_post_meta($pid, '_location', true);
                
                // Also check item meta for date/location
                if (empty($camp_dates)) {
                    $camp_dates = $item->get_meta('Camp Date') ?: $item->get_meta('camp_date') ?: $item->get_meta('Date');
                }
                if (empty($camp_location)) {
                    $camp_location = $item->get_meta('Camp Location') ?: $item->get_meta('Location') ?: $item->get_meta('camp_location');
                }
                
                // v115.5.2: Parse dates from product name as fallback
                // Matches patterns like "July 20-24, 2026" or "Jun 15 - 19, 2026" or "July 7-11"
                if (empty($camp_dates) && $camp_name) {
                    // Pattern: Month Day-Day, Year (e.g., "July 20-24, 2026")
                    if (preg_match('/([A-Za-z]+)\s+(\d{1,2})\s*[-–]\s*(\d{1,2}),?\s*(\d{4})/i', $camp_name, $matches)) {
                        $month = $matches[1];
                        $start_day = $matches[2];
                        $end_day = $matches[3];
                        $year = $matches[4];
                        $camp_dates = "$month $start_day, $year";
                        $camp_end = "$month $end_day, $year";
                    }
                    // Pattern: Month Day, Year (single day)
                    elseif (preg_match('/([A-Za-z]+)\s+(\d{1,2}),?\s*(\d{4})/i', $camp_name, $matches)) {
                        $camp_dates = $matches[1] . ' ' . $matches[2] . ', ' . $matches[3];
                    }
                }
                
                // v115.5.2: Parse location from product name as fallback  
                // Matches patterns like "– DeCou Soccer Complex –" or "@ Memorial Field"
                if (empty($camp_location) && $camp_name) {
                    // Try to extract location between dashes
                    if (preg_match('/[–-]\s*([^–-]+(?:Complex|Field|Park|Center|Facility|Stadium|Turf|Soccer)[^–-]*)\s*[–-]/i', $camp_name, $matches)) {
                        $camp_location = trim($matches[1]);
                    }
                    // Or after @ symbol
                    elseif (preg_match('/@\s*([^–-]+)/i', $camp_name, $matches)) {
                        $camp_location = trim($matches[1]);
                    }
                }
                
                // Get camper name from item meta
                $item_camper = $item->get_meta('Player Name') ?: $item->get_meta('Camper Name') ?: $item->get_meta('camper_name') ?: $item->get_meta('player_name');
                if ($item_camper && empty($camper_name)) {
                    $camper_name = $item_camper;
                }
                
                error_log("[PTP Thank You v115.5.2] Camp data: name=$camp_name, dates=$camp_dates, end=$camp_end, location=$camp_location, camper=$camper_name");
            }
        }
        
        $order_items[] = array(
            'name' => $item->get_name(),
            'type' => $is_camp ? 'camp' : 'product',
            'qty' => $item->get_quantity(),
            'total' => $item->get_total(),
            'date' => get_post_meta($pid, '_ptp_start_date', true) ?: get_post_meta($pid, '_camp_date', true),
            'end_date' => get_post_meta($pid, '_ptp_end_date', true) ?: get_post_meta($pid, '_camp_end_date', true),
            'location' => get_post_meta($pid, '_ptp_location_name', true) ?: get_post_meta($pid, '_camp_location', true),
            'address' => get_post_meta($pid, '_ptp_location_address', true) ?: get_post_meta($pid, '_camp_address', true),
        );
    }
}

// v115.5.1: Fallback for camper name with 6-level priority chain
// v115.5.3: Now collects ALL campers for multi-camper orders
$all_campers = []; // Array of all camper names

if (empty($camper_name)) {
    if ($order) {
        // Level 1: _player_name meta (most common - single camper)
        $camper_name = $order->get_meta('_player_name');
        if ($camper_name) {
            $all_campers[] = $camper_name;
            error_log('[PTP Thank You v115.5] Camper name from _player_name: ' . $camper_name);
        }
        
        // Level 2: _ptp_campers_data array (new v115.5 format - supports multiple)
        $campers_data = $order->get_meta('_ptp_campers_data');
        if (is_array($campers_data) && !empty($campers_data)) {
            $all_campers = []; // Reset - use this as authoritative source
            foreach ($campers_data as $camper) {
                $name = isset($camper['first_name']) ? trim($camper['first_name'] . ' ' . ($camper['last_name'] ?? '')) : '';
                if ($name) {
                    $all_campers[] = $name;
                }
            }
            if (!empty($all_campers)) {
                $camper_name = $all_campers[0]; // Primary camper
                error_log('[PTP Thank You v115.5.3] Found ' . count($all_campers) . ' campers from _ptp_campers_data');
            }
        }
        
        // Level 3: Check item-level camper data (v115.5.3)
        if (empty($all_campers)) {
            foreach ($order->get_items() as $item) {
                $item_campers = $item->get_meta('_ptp_item_campers');
                if (is_array($item_campers) && !empty($item_campers)) {
                    foreach ($item_campers as $camper) {
                        $name = trim(($camper['first_name'] ?? '') . ' ' . ($camper['last_name'] ?? ''));
                        if ($name && !in_array($name, $all_campers)) {
                            $all_campers[] = $name;
                        }
                    }
                }
            }
            if (!empty($all_campers) && empty($camper_name)) {
                $camper_name = $all_campers[0];
                error_log('[PTP Thank You v115.5.3] Found ' . count($all_campers) . ' campers from item meta');
            }
        }
        
        // Level 4: _ptp_campers array (legacy format)
        if (empty($camper_name)) {
            $campers = $order->get_meta('_ptp_campers');
            if (is_array($campers) && !empty($campers)) {
                $first = reset($campers);
                $camper_name = isset($first['name']) ? $first['name'] : '';
                if (empty($camper_name) && isset($first['first_name'])) {
                    $camper_name = trim($first['first_name'] . ' ' . ($first['last_name'] ?? ''));
                }
                if ($camper_name) {
                    $all_campers[] = $camper_name;
                    error_log('[PTP Thank You v115.5] Camper name from _ptp_campers: ' . $camper_name);
                }
            }
        }
        
        // Level 5: Individual meta fields (_camper_first_name + _camper_last_name)
        if (empty($camper_name)) {
            $camper_first = $order->get_meta('_ptp_camper_first_name') ?: $order->get_meta('_camper_first_name');
            $camper_last = $order->get_meta('_ptp_camper_last_name') ?: $order->get_meta('_camper_last_name');
            if ($camper_first) {
                $camper_name = trim($camper_first . ' ' . $camper_last);
                $all_campers[] = $camper_name;
                error_log('[PTP Thank You v115.5] Camper name from individual meta: ' . $camper_name);
            }
        }
        
        // Level 6: Order item meta (player_name)
        if (empty($camper_name)) {
            foreach ($order->get_items() as $item) {
                $item_name = $item->get_meta('player_name') ?: $item->get_meta('Player Name') ?: $item->get_meta('Camper Name');
                if ($item_name) {
                    $camper_name = $item_name;
                    $all_campers[] = $camper_name;
                    error_log('[PTP Thank You v115.5] Camper name from item meta: ' . $camper_name);
                    break;
                }
            }
        }
        
        // Level 7: Billing first name (last resort)
        if (empty($camper_name)) {
            $camper_name = $order->get_billing_first_name();
            $all_campers[] = $camper_name;
            error_log('[PTP Thank You v115.5] Camper name fallback to billing: ' . $camper_name);
        }
    } elseif ($booking && isset($booking->player_name)) {
        $camper_name = $booking->player_name;
        $all_campers[] = $camper_name;
    }
}

// v117.2.28: FALLBACK - If no booking but we have recovered trainer from session, show training view
if (!$booking && $recovered_trainer && $checkout_session_data) {
    error_log('[PTP Thank You v117.2.28] Using recovered_trainer fallback for display');
    $has_training = true;
    $coach_name = $recovered_trainer->display_name;
    $coach_photo = $recovered_trainer->photo_url ?? '';
    $training_level = $recovered_trainer->playing_level ?? '';
    $training_package = $checkout_session_data['training_package'] ?? 'single';
    $training_date = $checkout_session_data['session_date'] ?? '';
    $training_time = $checkout_session_data['session_time'] ?? '';
    $training_location = $checkout_session_data['session_location'] ?? '';
    
    $sessions_map = array('single' => 1, 'pack3' => 3, 'pack5' => 5);
    $training_sessions = $sessions_map[$training_package] ?? 1;
    
    // v131: Check for multi-player data first
    $players_data = $checkout_session_data['players_data'] ?? array();
    $group_size = intval($checkout_session_data['group_size'] ?? 1);
    $group_session_id = intval($checkout_session_data['group_session_id'] ?? 0);
    
    if (!empty($players_data) && count($players_data) > 0) {
        // Multi-player checkout - add all players to all_campers
        foreach ($players_data as $player) {
            $player_name = trim(($player['first_name'] ?? '') . ' ' . ($player['last_name'] ?? ''));
            if (!empty($player_name)) {
                $all_campers[] = $player_name;
            }
        }
        // Set primary camper name
        if (!empty($all_campers)) {
            $camper_name = $all_campers[0];
        }
        error_log('[PTP Thank You v131] Multi-player checkout with ' . count($players_data) . ' players');
    } else {
        // Single player checkout
        $camper_data = $checkout_session_data['camper_data'] ?? array();
        if (!empty($camper_data['first_name'])) {
            $camper_name = trim(($camper_data['first_name'] ?? '') . ' ' . ($camper_data['last_name'] ?? ''));
            $all_campers[] = $camper_name;
        }
    }
    
    // Get parent email from session data
    $parent_data = $checkout_session_data['parent_data'] ?? array();
    if (!empty($parent_data['email'])) {
        $parent_email = $parent_data['email'];
    }
    
    // Get first player's data for booking object
    $first_player = !empty($players_data) ? $players_data[0] : ($checkout_session_data['camper_data'] ?? array());
    
    // Create a fake booking object for the template to use
    $booking = (object) array(
        'id' => 0,
        'booking_number' => 'PENDING',
        'trainer_name' => $coach_name,
        'trainer_photo' => $coach_photo,
        'trainer_level' => $training_level,
        'trainer_headline' => $recovered_trainer->headline ?? '',
        'trainer_slug' => $recovered_trainer->slug ?? '',
        'session_date' => $training_date,
        'session_time' => $training_time,
        'location' => $training_location,
        'package_type' => $training_package,
        'total_sessions' => $training_sessions,
        'player_first' => $first_player['first_name'] ?? '',
        'player_last' => $first_player['last_name'] ?? '',
        'parent_email' => $parent_email ?? '',
        'payment_intent_id' => $payment_intent_param,
        // v131: Group session data
        'group_size' => $group_size,
        'group_session_id' => $group_session_id,
        'players_data' => $players_data,
    );
    
    error_log('[PTP Thank You v117.2.28] Fallback booking object created for trainer: ' . $coach_name);
}

// v115.5.3: Format camper names for display
$camper_count = count($all_campers);
if ($camper_count > 1) {
    // Multiple campers: "LUKE & EMMA" or "LUKE, EMMA & SAM"
    $upper_names = array_map('strtoupper', $all_campers);
    if ($camper_count == 2) {
        $camper_name_upper = $upper_names[0] . ' & ' . $upper_names[1];
    } else {
        $last = array_pop($upper_names);
        $camper_name_upper = implode(', ', $upper_names) . ' & ' . $last;
    }
    
    $display_names = array_map(function($n) { return ucfirst(strtolower($n)); }, $all_campers);
    if ($camper_count == 2) {
        $camper_name_display = $display_names[0] . ' & ' . $display_names[1];
    } else {
        $last = array_pop($display_names);
        $camper_name_display = implode(', ', $display_names) . ' & ' . $last;
    }
    
    $campers_are_is = 'ARE'; // Plural verb
} else {
    $camper_name_upper = strtoupper($camper_name);
    $camper_name_display = ucfirst(strtolower($camper_name));
    $campers_are_is = 'IS'; // Singular verb
}

// Get parent email
$parent_email = '';
if ($order) {
    $parent_email = $order->get_billing_email();
} elseif ($booking) {
    $parent_email = $booking->parent_email ?? '';
}

// Get or generate referral code using existing system
$user_id = get_current_user_id();
$referral_code = '';
$referral_link = '';

// v128.2.8: Wrap in try-catch to prevent critical errors
// v132.8: Use Throwable for PHP 8+ compatibility
try {
    if ($user_id && class_exists('PTP_Referral_System')) {
        $referral_code = PTP_Referral_System::generate_code($user_id, 'parent');
        if ($referral_code) {
            $referral_link = home_url('/?ref=' . $referral_code);
        }
    }
} catch (Throwable $e) {
    error_log('[PTP Thank You v132.8] Referral code error: ' . $e->getMessage());
}

// Fallback for guests or if referral generation failed
if (empty($referral_code)) {
    $referral_code = strtoupper(substr(md5($parent_email . 'ptp'), 0, 8));
    $referral_link = home_url('/?ref=' . $referral_code);
}

// Check if announcement already submitted
$announcement_submitted = false;
$saved_ig_handle = '';
if ($order) {
    $announcement_submitted = $order->get_meta('_ptp_announce_optin') === 'yes';
    $saved_ig_handle = $order->get_meta('_ptp_ig_handle');
}

// Check if upsell already purchased
$upsell_purchased = false;
if ($order) {
    $upsell_purchased = $order->get_meta('_ptp_upsell_purchased') === 'yes';
}

// Upsell product settings
$upsell_enabled = get_option('ptp_thankyou_upsell_enabled', 'yes') === 'yes';
$upsell_product_id = get_option('ptp_thankyou_upsell_product_id', 0);
$upsell_price = get_option('ptp_thankyou_upsell_price', 89);
$upsell_regular_price = get_option('ptp_thankyou_upsell_regular_price', 149);

// Format dates nicely
$formatted_dates = '';
if ($camp_dates) {
    $formatted_dates = date('M j', strtotime($camp_dates));
    if (!empty($camp_end)) {
        $formatted_dates .= ' - ' . date('j, Y', strtotime($camp_end));
    } else {
        $formatted_dates .= ', ' . date('Y', strtotime($camp_dates));
    }
}

// v132.8: Wrap get_header in try-catch with minimal fallback
try {
    get_header();
} catch (Throwable $e) {
    error_log('[PTP Thank You v132.8] get_header() failed: ' . $e->getMessage());
    // Minimal header fallback
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
        <title>Thank You - PTP</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <?php wp_head(); ?>
    </head>
    <body class="ptp-thankyou-page">
    <?php
}
?>

<style>
/* PTP Thank You Page v100 - Viral Machine */
/* v123: Enhanced mobile optimization */
/* v133.2: Hide scrollbar */
:root {
    --ptp-gold: #FCB900;
    --ptp-black: #0A0A0A;
    --ptp-green: #22C55E;
    --ptp-blue: #3B82F6;
    --ptp-red: #EF4444;
}

/* v133.2: Hide scrollbar */
html, body { scrollbar-width: none; -ms-overflow-style: none; }
html::-webkit-scrollbar, body::-webkit-scrollbar { display: none; width: 0; }

/* Hide ALL footers aggressively */
body > footer,
.site-footer,
footer.footer,
#footer,
.footer,
footer#colophon,
.elementor-location-footer,
[data-elementor-type="footer"],
.ptp-ty-footer,
.footer-wrapper,
#site-footer,
.ast-footer,
.wp-footer,
footer[role="contentinfo"],
.page-footer,
.main-footer {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    overflow: hidden !important;
}

.ptp-ty {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    background: #FFFFFF;
    min-height: 100vh;
    min-height: 100dvh;
    padding: 0 0 40px;
    color: #1a1a1a;
}

/* v123: Safe area insets for notched phones */
@supports(padding: max(0px)) {
    .ptp-ty {
        padding-bottom: max(40px, env(safe-area-inset-bottom));
    }
}

.ptp-ty * { box-sizing: border-box; }

.ptp-ty h1, .ptp-ty h2, .ptp-ty h3 {
    font-family: 'Oswald', sans-serif;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    margin: 0;
    color: #1a1a1a !important;
}

/* Header Bar */
.ptp-ty-header {
    background: var(--ptp-gold);
    padding: 12px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.ptp-ty-logo {
    font-family: 'Oswald', sans-serif;
    font-weight: 700;
    font-size: 22px;
    color: var(--ptp-black);
    letter-spacing: 2px;
}

.ptp-ty-tagline {
    font-size: 10px;
    font-weight: 600;
    color: var(--ptp-black);
    letter-spacing: 1px;
    text-transform: uppercase;
    display: none;
}

@media (min-width: 480px) {
    .ptp-ty-tagline { display: block; }
    .ptp-ty-logo { font-size: 24px; }
}

/* Container - v123: Tighter mobile padding */
.ptp-ty-wrap {
    max-width: 600px;
    margin: 0 auto;
    padding: 20px 12px;
}

@media (min-width: 480px) {
    .ptp-ty-wrap { padding: 28px 16px; }
}

/* Desktop: Full width */
@media (min-width: 768px) {
    .ptp-ty {
        width: 100vw;
        max-width: 100%;
        margin-left: calc(-50vw + 50%);
        position: relative;
        padding: 0 0 60px;
    }
    
    .ptp-ty-page {
        min-height: 100vh;
        width: 100%;
    }
    
    .ptp-ty-header {
        width: 100%;
        padding: 12px 40px;
    }
    
    .ptp-ty-wrap {
        max-width: 900px;
        padding: 48px 40px;
        margin: 0 auto;
    }
    
    .ptp-ty-hero {
        padding: 40px 0;
    }
    
    .ptp-ty-hero h1 {
        font-size: 56px;
        color: #1a1a1a !important;
    }
    
    /* Single column - no grid */
    .ptp-ty-grid {
        display: block;
    }
    
    .ptp-ty-card {
        margin-bottom: 20px;
    }
    
    .ptp-ty-card-body {
        padding: 24px;
    }

    /* Footer actions side by side on desktop */
    .ptp-ty-footer-actions {
        flex-direction: row !important;
        gap: 16px;
        justify-content: center;
        max-width: 600px;
        margin: 24px auto 0;
    }
    
    .ptp-ty-footer-actions a {
        flex: 1;
        max-width: 280px;
    }
}

/* v123: Mobile training card optimizations */
@media (max-width: 480px) {
    .ptp-ty-trainer-card {
        flex-direction: column !important;
        text-align: center;
        gap: 12px !important;
        padding: 14px !important;
    }
    
    .ptp-ty-trainer-card > div:first-child {
        width: 64px !important;
        height: 64px !important;
    }
    
    .ptp-ty-details {
        grid-template-columns: 1fr !important;
        gap: 12px !important;
    }
    
    .ptp-ty-details > div[style*="grid-column"] {
        grid-column: 1 !important;
    }
    
    /* Simplified what's next on mobile */
    .ptp-ty-card-body > div[style*="margin-top:24px"] {
        margin-top: 16px !important;
        padding-top: 16px !important;
    }
    
    .ptp-ty-card-body > div[style*="margin-top:24px"] > div[style*="display:grid"] {
        gap: 8px !important;
    }
}

/* Success Badge */
.ptp-ty-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: 8px 20px;
    font-family: 'Oswald', sans-serif;
    font-weight: 700;
    font-size: 13px;
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 20px;
}

/* Hero */
.ptp-ty-hero {
    text-align: center;
    margin-bottom: 32px;
}

.ptp-ty-hero h1 {
    font-size: clamp(32px, 8vw, 48px);
    line-height: 1.1;
    margin-bottom: 8px;
    color: #1a1a1a !important;
}

.ptp-ty-hero h1 .gold { color: var(--ptp-gold) !important; }

.ptp-ty-subtitle {
    color: rgba(0,0,0,0.5);
    font-size: 14px;
}

/* Cards */
.ptp-ty-card {
    background: #f8f8f8;
    border: 2px solid #e5e5e5;
    margin-bottom: 16px;
    transition: all 0.3s ease;
}

.ptp-ty-card.highlight {
    border-color: var(--ptp-gold);
    box-shadow: 0 0 30px rgba(252, 185, 0, 0.15);
}

.ptp-ty-card-header {
    background: #f0f0f0;
    padding: 14px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    border-bottom: 1px solid #e5e5e5;
}

.ptp-ty-card-header.gold {
    background: var(--ptp-gold);
    border-bottom: none;
}

.ptp-ty-card-header.gold * {
    color: var(--ptp-black) !important;
}

.ptp-ty-card-icon {
    font-size: 22px;
}

.ptp-ty-card-title {
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 700;
    letter-spacing: 1px;
}

.ptp-ty-card-subtitle {
    font-size: 12px;
    color: rgba(0,0,0,0.5);
    margin-top: 2px;
}

.ptp-ty-card-body {
    padding: 20px;
}

/* Order Details */
.ptp-ty-details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
}

.ptp-ty-detail-label {
    font-size: 10px;
    color: rgba(0,0,0,0.4);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.ptp-ty-detail-value {
    font-family: 'Oswald', sans-serif;
    font-size: 15px;
    font-weight: 600;
}

/* Coach Card */
.ptp-ty-coach {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px;
    background: #f8f8f8;
    border: 2px solid var(--ptp-gold);
    margin-top: 16px;
}

.ptp-ty-coach-avatar {
    width: 56px;
    height: 56px;
    background: var(--ptp-gold);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Oswald', sans-serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--ptp-black);
    overflow: hidden;
}

.ptp-ty-coach-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.ptp-ty-coach-info { flex: 1; }

.ptp-ty-coach-label {
    font-size: 10px;
    color: var(--ptp-gold);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.ptp-ty-coach-name {
    font-family: 'Oswald', sans-serif;
    font-size: 18px;
    font-weight: 700;
}

.ptp-ty-coach-team {
    font-size: 12px;
    color: rgba(0,0,0,0.5);
}

.ptp-ty-coach-badge {
    background: rgba(252,185,0,0.2);
    color: var(--ptp-gold);
    padding: 4px 8px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
}

/* Testimonial */
.ptp-ty-testimonial {
    background: #fafafa;
    border: 1px solid #e5e5e5;
    padding: 20px;
    margin-bottom: 20px;
    position: relative;
}

.ptp-ty-testimonial-label {
    position: absolute;
    top: -10px;
    left: 16px;
    background: var(--ptp-black);
    padding: 0 8px;
    font-size: 10px;
    color: rgba(0,0,0,0.4);
    letter-spacing: 2px;
    text-transform: uppercase;
}

.ptp-ty-testimonial p {
    font-size: 14px;
    line-height: 1.6;
    font-style: italic;
    color: rgba(0,0,0,0.8);
    margin: 0 0 12px;
}

.ptp-ty-testimonial cite {
    font-size: 12px;
    color: var(--ptp-gold);
    font-weight: 600;
    font-style: normal;
}

/* Instagram Opt-in */
.ptp-ty-ig-input {
    display: flex;
    gap: 8px;
    margin-bottom: 16px;
}

.ptp-ty-ig-prefix {
    background: #f0f0f0;
    padding: 12px 14px;
    font-size: 16px;
    color: rgba(0,0,0,0.5);
}

.ptp-ty-ig-field {
    flex: 1;
    background: #f8f8f8;
    border: 2px solid #e5e5e5;
    padding: 12px 14px;
    font-size: 16px;
    color: #1a1a1a;
    outline: none;
    transition: border-color 0.2s;
}

.ptp-ty-ig-field:focus {
    border-color: var(--ptp-gold);
}

.ptp-ty-checkbox {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    cursor: pointer;
    padding: 14px;
    background: transparent;
    border: 2px solid #e5e5e5;
    transition: all 0.2s;
}

.ptp-ty-checkbox.checked {
    background: rgba(252,185,0,0.1);
    border-color: var(--ptp-gold);
}

.ptp-ty-checkbox-box {
    width: 24px;
    height: 24px;
    border: 2px solid #ccc;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: all 0.2s;
}

.ptp-ty-checkbox.checked .ptp-ty-checkbox-box {
    background: var(--ptp-gold);
    border-color: var(--ptp-gold);
    color: var(--ptp-black);
}

.ptp-ty-checkbox-text strong {
    display: block;
    font-size: 14px;
    margin-bottom: 2px;
}

.ptp-ty-checkbox-text span {
    font-size: 12px;
    color: rgba(0,0,0,0.5);
}

.ptp-ty-preview {
    margin-top: 16px;
    padding: 16px;
    background: rgba(252,185,0,0.1);
    border: 2px solid rgba(252,185,0,0.3);
    display: none;
}

.ptp-ty-preview.show { display: block; }

.ptp-ty-preview-label {
    font-size: 10px;
    color: var(--ptp-gold);
    letter-spacing: 2px;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.ptp-ty-preview p {
    font-size: 13px;
    line-height: 1.5;
    color: rgba(0,0,0,0.8);
    margin: 0;
}

/* Photo Upload */
.ptp-ty-photo-upload {
    margin: 16px 0;
}

.ptp-ty-photo-label {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px;
    background: #f5f5f5;
    border: 2px dashed #ccc;
    cursor: pointer;
    transition: all 0.2s;
}

.ptp-ty-photo-label:hover {
    border-color: var(--ptp-gold);
    background: rgba(252,185,0,0.05);
}

.ptp-ty-photo-icon {
    font-size: 24px;
}

.ptp-ty-photo-text {
    font-size: 14px;
    color: rgba(0,0,0,0.7);
}

.ptp-ty-photo-preview {
    position: relative;
    margin-top: 12px;
    max-width: 200px;
}

.ptp-ty-photo-preview img {
    width: 100%;
    height: auto;
    border: 2px solid var(--ptp-gold);
}

.ptp-ty-photo-remove {
    position: absolute;
    top: -8px;
    right: -8px;
    width: 24px;
    height: 24px;
    background: #e53e3e;
    color: #fff;
    border: none;
    border-radius: 50%;
    font-size: 14px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Submit Button */
.ptp-ty-submit-btn {
    width: 100%;
    padding: 16px 24px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    border: none;
    font-weight: 700;
    font-size: 14px;
    letter-spacing: 1px;
    text-transform: uppercase;
    cursor: pointer;
    margin-top: 16px;
    transition: all 0.2s;
}

.ptp-ty-submit-btn:hover {
    background: #e5a800;
    transform: translateY(-1px);
}

.ptp-ty-submit-btn:disabled {
    background: #e5e5e5;
    color: #999;
    cursor: not-allowed;
    transform: none;
}

.ptp-ty-submit-btn.success {
    background: var(--ptp-green);
    color: #fff;
}

.ptp-ty-submit-note {
    font-size: 12px;
    color: rgba(0,0,0,0.5);
    text-align: center;
    margin: 8px 0 0;
}

/* Referral */
.ptp-ty-ref-link {
    display: flex;
    align-items: center;
    gap: 8px;
    background: #f8f8f8;
    padding: 10px 14px;
    margin-bottom: 16px;
    border: 1px solid #e5e5e5;
}

.ptp-ty-ref-link code {
    flex: 1;
    font-size: 13px;
    color: #0066cc;
    font-family: monospace;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.ptp-ty-ref-copy {
    background: var(--ptp-gold);
    color: var(--ptp-black);
    border: none;
    padding: 8px 14px;
    font-weight: 700;
    font-size: 11px;
    letter-spacing: 1px;
    cursor: pointer;
    text-transform: uppercase;
    transition: all 0.2s;
}

.ptp-ty-ref-copy.copied {
    background: var(--ptp-green);
    color: #fff;
}

.ptp-ty-share-btns {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
}

.ptp-ty-share-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    background: transparent;
    border: 2px solid #e5e5e5;
    color: #1a1a1a;
    padding: 12px;
    font-weight: 600;
    font-size: 12px;
    letter-spacing: 1px;
    cursor: pointer;
    transition: all 0.2s;
}

.ptp-ty-share-btn:hover {
    border-color: var(--ptp-gold);
    color: var(--ptp-gold);
}

/* Upsell */
.ptp-ty-upsell-badge {
    position: absolute;
    top: 14px;
    right: -30px;
    background: var(--ptp-red);
    color: #fff;
    padding: 4px 36px;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 1px;
    transform: rotate(45deg);
    text-transform: uppercase;
}

.ptp-ty-upsell-content {
    display: flex;
    align-items: flex-start;
    gap: 16px;
}

.ptp-ty-upsell-icon {
    width: 70px;
    height: 70px;
    background: var(--ptp-gold);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: var(--ptp-black);
    flex-shrink: 0;
}

.ptp-ty-upsell-icon strong {
    font-family: 'Oswald', sans-serif;
    font-size: 26px;
    line-height: 1;
}

.ptp-ty-upsell-icon span {
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 1px;
}

.ptp-ty-upsell-info { flex: 1; }

.ptp-ty-upsell-info p {
    font-size: 14px;
    color: rgba(0,0,0,0.7);
    margin: 0 0 10px;
    line-height: 1.5;
}

.ptp-ty-upsell-price {
    display: flex;
    align-items: baseline;
    gap: 10px;
}

.ptp-ty-upsell-price .sale {
    font-family: 'Oswald', sans-serif;
    font-size: 28px;
    font-weight: 700;
    color: var(--ptp-gold);
}

.ptp-ty-upsell-price .regular {
    font-size: 16px;
    color: rgba(0,0,0,0.4);
    text-decoration: line-through;
}

.ptp-ty-upsell-price .savings {
    background: var(--ptp-green);
    color: #fff;
    padding: 4px 8px;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1px;
}

.ptp-ty-upsell-features {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6px;
    margin: 14px 0;
    padding: 12px;
    background: #f8f8f8;
    font-size: 12px;
    color: rgba(0,0,0,0.6);
}

.ptp-ty-upsell-btn {
    width: 100%;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    border: none;
    padding: 16px;
    font-family: 'Oswald', sans-serif;
    font-size: 17px;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.ptp-ty-upsell-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(252,185,0,0.3);
}

.ptp-ty-upsell-btn.added {
    background: var(--ptp-green);
    color: #fff;
}

.ptp-ty-upsell-btn:disabled {
    cursor: default;
    transform: none;
    box-shadow: none;
}

.ptp-ty-upsell-note {
    text-align: center;
    font-size: 11px;
    color: rgba(0,0,0,0.4);
    margin-top: 12px;
}

/* Training CTA */
.ptp-ty-training-cta {
    background: linear-gradient(135deg, rgba(252,185,0,0.1) 0%, rgba(252,185,0,0.05) 100%);
    border: 2px solid var(--ptp-gold) !important;
}

.ptp-ty-training-btn {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    padding: 16px 32px;
    font-family: 'Oswald', sans-serif;
    font-size: 16px;
    font-weight: 700;
    text-transform: uppercase;
    text-decoration: none;
    letter-spacing: 1px;
    transition: all 0.2s;
}

.ptp-ty-training-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(252,185,0,0.3);
    color: var(--ptp-black);
}

/* Steps */
.ptp-ty-steps { }

.ptp-ty-step {
    display: flex;
    gap: 14px;
    padding: 14px 0;
    border-bottom: 1px solid #eee;
}

.ptp-ty-step:last-child { border: none; }

.ptp-ty-step-num {
    width: 30px;
    height: 30px;
    background: var(--ptp-gold);
    color: var(--ptp-black);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Oswald', sans-serif;
    font-size: 14px;
    font-weight: 700;
    flex-shrink: 0;
}

.ptp-ty-step-title {
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 2px;
}

.ptp-ty-step-desc {
    font-size: 13px;
    color: rgba(0,0,0,0.5);
}

/* Footer */
.ptp-ty-footer {
    text-align: center;
    padding-top: 32px;
    border-top: 1px solid #e5e5e5;
    margin-top: 24px;
}

.ptp-ty-footer-logo {
    font-family: 'Oswald', sans-serif;
    font-size: 22px;
    font-weight: 700;
    color: var(--ptp-gold);
    letter-spacing: 3px;
    margin-bottom: 6px;
}

.ptp-ty-footer-tagline {
    font-size: 11px;
    color: rgba(0,0,0,0.4);
    letter-spacing: 2px;
    text-transform: uppercase;
}

.ptp-ty-footer-links {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 16px;
}

.ptp-ty-footer-links a {
    color: rgba(0,0,0,0.5);
    text-decoration: none;
    font-size: 12px;
    transition: color 0.2s;
}

.ptp-ty-footer-links a:hover {
    color: var(--ptp-gold);
}

/* Confetti */
.ptp-confetti {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    pointer-events: none;
    z-index: 9999;
    overflow: hidden;
}

.ptp-confetti-piece {
    position: absolute;
    width: 10px;
    height: 10px;
    top: -20px;
    animation: confettiFall 3s linear forwards;
}

@keyframes confettiFall {
    0% { transform: translateY(0) rotate(0deg); opacity: 1; }
    100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
}

/* Mobile */
@media (max-width: 599px) {
    .ptp-ty-wrap { padding: 24px 12px; }
    .ptp-ty-details { grid-template-columns: 1fr; gap: 12px; }
    .ptp-ty-share-btns { grid-template-columns: 1fr; }
    .ptp-ty-upsell-content { flex-direction: column; }
    .ptp-ty-upsell-features { grid-template-columns: 1fr; }
    .ptp-ty-tagline { display: none; }
}
</style>

<!-- PTP Debug v117.2.22: booking_id=<?php echo $booking_id; ?>, order_id=<?php echo $order_id; ?>, has_training=<?php echo $has_training ? 'true' : 'false'; ?>, has_camp=<?php echo $has_camp ? 'true' : 'false'; ?>, booking_found=<?php echo ($booking ? 'yes' : 'no'); ?> -->

<div class="ptp-ty">
    <!-- Confetti -->
    <div class="ptp-confetti" id="confetti"></div>

    <div class="ptp-ty-wrap">
        <!-- Hero -->
        <div class="ptp-ty-hero">
            <div class="ptp-ty-badge">
                <span>✓</span> REGISTRATION CONFIRMED
            </div>
            <h1><span class="gold"><?php echo esc_html($camper_name_upper ?: 'YOU\'RE'); ?></span> <?php echo $camper_name ? $campers_are_is : 'ALL'; ?><br>LOCKED IN 🔥</h1>
            <?php if ($parent_email): ?>
            <p class="ptp-ty-subtitle">Confirmation sent to <strong><?php echo esc_html($parent_email); ?></strong></p>
            <?php else: ?>
            <p class="ptp-ty-subtitle">Check your email for confirmation details</p>
            <?php endif; ?>
        </div>

        <!-- Order Details - Training -->
        <?php if ($has_training && $booking && !$has_camp): 
            $level_labels = array('pro'=>'MLS PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY','semi_pro'=>'SEMI-PRO');
            $package_labels = array('single'=>'Single Session','pack3'=>'3-Session Pack','pack5'=>'5-Session Pack');
            $formatted_training_date = $training_date ? date('l, F j, Y', strtotime($training_date)) : 'TBD';
            $formatted_training_time = '';
            // v123: Improved time formatting with minutes
            if ($training_time) {
                $time_parts = explode(':', $training_time);
                $h = intval($time_parts[0]);
                $m = isset($time_parts[1]) ? intval($time_parts[1]) : 0;
                $display_hour = $h > 12 ? $h - 12 : ($h ?: 12);
                $ampm = $h >= 12 ? 'PM' : 'AM';
                $formatted_training_time = $m > 0 ? "{$display_hour}:" . str_pad($m, 2, '0', STR_PAD_LEFT) . " {$ampm}" : "{$display_hour}:00 {$ampm}";
            }
            // v121: Generate booking number if missing
            // v130.3: Only update DB for real bookings (id > 0)
            if ($booking->id > 0 && (empty($booking->booking_number) || $booking->booking_number === 'PENDING')) {
                $new_bn = 'PTP-' . strtoupper(substr(md5($booking->id . time()), 0, 8));
                $wpdb->update($wpdb->prefix . 'ptp_bookings', array('booking_number' => $new_bn), array('id' => $booking->id));
                $booking->booking_number = $new_bn;
            }
            
            $display_booking_number = $booking->booking_number ?: ($booking_id ? 'PTP-' . $booking_id : 'Confirmed');
        ?>
        
        <!-- v128.1: Simplified Training Confirmation Card -->
        <div class="ptp-ty-card highlight">
            <div class="ptp-ty-card-header gold">
                <span class="ptp-ty-card-icon">⚽</span>
                <div>
                    <div class="ptp-ty-card-title">Training Booked!</div>
                    <div class="ptp-ty-card-subtitle"><?php echo esc_html($display_booking_number); ?></div>
                </div>
            </div>
            <div class="ptp-ty-card-body">
                <!-- Trainer Card -->
                <div class="ptp-ty-trainer-card" style="display:flex;gap:14px;align-items:center;background:rgba(252,185,0,0.08);border:2px solid var(--ptp-gold);padding:14px;margin-bottom:16px;">
                    <div style="width:60px;height:60px;border-radius:50%;overflow:hidden;flex-shrink:0;border:3px solid var(--ptp-gold);">
                        <?php if ($coach_photo): ?>
                            <img src="<?php echo esc_url($coach_photo); ?>" alt="" style="width:100%;height:100%;object-fit:cover;">
                        <?php else: ?>
                            <div style="width:100%;height:100%;background:var(--ptp-gold);display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-size:20px;font-weight:700;color:var(--ptp-black);">
                                <?php echo strtoupper(substr($coach_name, 0, 2)); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1;min-width:0;">
                        <div style="font-family:Oswald,sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html($coach_name); ?></div>
                        <div style="display:inline-block;background:var(--ptp-gold);color:var(--ptp-black);font-family:Oswald,sans-serif;font-size:9px;font-weight:600;padding:3px 8px;letter-spacing:1px;">
                            <?php echo esc_html($level_labels[$training_level] ?? 'PRO TRAINER'); ?>
                        </div>
                    </div>
                </div>
                
                <!-- Session Details -->
                <div class="ptp-ty-details" style="grid-template-columns:1fr 1fr;">
                    <div>
                        <div class="ptp-ty-detail-label">📅 Date</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($formatted_training_date); ?></div>
                    </div>
                    <div>
                        <div class="ptp-ty-detail-label">🕐 Time</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($formatted_training_time ?: 'TBD'); ?></div>
                    </div>
                    <div>
                        <div class="ptp-ty-detail-label">📍 Location</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($training_location ?: 'TBD'); ?></div>
                    </div>
                    <div>
                        <div class="ptp-ty-detail-label">📦 Package</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($package_labels[$training_package] ?? $training_package); ?></div>
                    </div>
                    
                    <?php 
                    // v131: Show all players for group sessions
                    $show_players = $all_campers;
                    $player_count = count($show_players);
                    
                    if ($player_count > 1): ?>
                    <!-- Multi-Player List -->
                    <div style="grid-column:1/-1;">
                        <div class="ptp-ty-detail-label">👥 Players (<?php echo $player_count; ?>)</div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:6px;">
                            <?php foreach ($show_players as $idx => $player_name_item): ?>
                            <div style="background:rgba(252,185,0,0.1);border:1px solid rgba(252,185,0,0.3);padding:8px 12px;border-radius:6px;font-size:13px;font-weight:600;">
                                <span style="color:var(--ptp-gold);font-weight:700;"><?php echo $idx + 1; ?>.</span>
                                <?php echo esc_html($player_name_item); ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php elseif ($camper_name): ?>
                    <div style="grid-column:1/-1;">
                        <div class="ptp-ty-detail-label">👤 Player</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camper_name); ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- What's Next Card -->
        <div class="ptp-ty-card" style="margin-top:16px;">
            <div class="ptp-ty-card-header">
                <span class="ptp-ty-card-icon">📋</span>
                <div class="ptp-ty-card-title">What's Next</div>
            </div>
            <div class="ptp-ty-card-body">
                <div class="ptp-ty-steps">
                    <div class="ptp-ty-step">
                        <div class="ptp-ty-step-num">1</div>
                        <div>
                            <div class="ptp-ty-step-title"><?php echo esc_html($coach_name); ?> Will Reach Out</div>
                            <div class="ptp-ty-step-desc">Your trainer will text or call to confirm session details</div>
                        </div>
                    </div>
                    <div class="ptp-ty-step">
                        <div class="ptp-ty-step-num">2</div>
                        <div>
                            <div class="ptp-ty-step-title">Check Your Email</div>
                            <div class="ptp-ty-step-desc">Confirmation with all details sent to your inbox</div>
                        </div>
                    </div>
                    <div class="ptp-ty-step">
                        <div class="ptp-ty-step-num">3</div>
                        <div>
                            <div class="ptp-ty-step-title">Show Up Ready</div>
                            <div class="ptp-ty-step-desc">Bring water, cleats, and a ball if you have one!</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Footer Actions -->
        <?php 
        // Build Google Calendar URL
        $cal_title = 'PTP Training with ' . $coach_name;
        $cal_date = $training_date ?: date('Y-m-d', strtotime('+7 days'));
        $cal_time_start = $training_time ?: '09:00';
        $cal_location = $training_location ?: '';
        
        // Format for Google Calendar (YYYYMMDDTHHMMSS)
        $cal_datetime = date('Ymd', strtotime($cal_date)) . 'T' . str_replace(':', '', $cal_time_start) . '00';
        $cal_datetime_end = date('Ymd', strtotime($cal_date)) . 'T' . date('His', strtotime($cal_time_start . ' +1 hour'));
        
        $gcal_url = 'https://calendar.google.com/calendar/render?action=TEMPLATE' .
            '&text=' . urlencode($cal_title) .
            '&dates=' . $cal_datetime . '/' . $cal_datetime_end .
            '&location=' . urlencode($cal_location) .
            '&details=' . urlencode('Private soccer training session with ' . $coach_name . '. Booking #' . $display_booking_number);
        ?>
        <div class="ptp-ty-footer-actions" style="display:flex;flex-direction:column;gap:12px;margin-top:24px;">
            <?php if ($training_date && $training_date !== 'TBD'): ?>
            <a href="<?php echo esc_url($gcal_url); ?>" target="_blank" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:16px 24px;background:#4285F4;color:#fff;font-family:Oswald,sans-serif;font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;text-decoration:none;">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                Add to Calendar
            </a>
            <?php endif; ?>
            <a href="<?php echo home_url('/my-training/'); ?>" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:16px 24px;background:var(--ptp-gold);color:var(--ptp-black);font-family:Oswald,sans-serif;font-size:15px;font-weight:700;text-transform:uppercase;letter-spacing:1px;text-decoration:none;">
                🏠 View My Dashboard
            </a>
            <a href="<?php echo home_url('/find-trainers/'); ?>" style="display:block;text-align:center;padding:14px 24px;background:transparent;border:2px solid #e5e5e5;color:rgba(0,0,0,0.6);font-family:Oswald,sans-serif;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;text-decoration:none;">
                Book Another Session
            </a>
        </div>
        
        <?php 
        // v128.1: Skip all the upsell/referral/testimonial sections for training - keep it simple
        // Jump directly to footer
        endif; // End training-only section 
        
        if ($has_training && !$has_camp):
            // Close training section and skip to footer
        ?>
        
        <!-- Footer -->
        <div class="ptp-ty-footer" style="margin-top:48px;">
            <div class="ptp-ty-footer-logo">PTP</div>
            <div class="ptp-ty-footer-tagline">Players Teaching Players</div>
        </div>
        
    </div><!-- .ptp-ty-wrap -->
</div><!-- .ptp-ty -->

<!-- Confetti Script for Training -->
<script>
(function() {
    // Confetti for celebration
    const confettiEl = document.getElementById('confetti');
    if (confettiEl) {
        const colors = ['#FCB900', '#0A0A0A', '#22C55E', '#fff'];
        for (let i = 0; i < 50; i++) {
            const piece = document.createElement('div');
            piece.className = 'ptp-confetti-piece';
            piece.style.left = Math.random() * 100 + '%';
            piece.style.background = colors[Math.floor(Math.random() * colors.length)];
            piece.style.animationDelay = Math.random() * 2 + 's';
            piece.style.animationDuration = (2 + Math.random() * 2) + 's';
            confettiEl.appendChild(piece);
        }
        setTimeout(() => confettiEl.remove(), 5000);
    }
})();
</script>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
<?php 
// Exit early for training-only pages to skip all the camp/referral sections
return;
endif; 

// Continue with camp-related sections below (only reached if has_camp is true)
?>
        
        <!-- CAMP UPSELL FOR TRAINING PURCHASES (only if has_camp) -->
        <?php
        // Get upcoming camps for upsell
        $upcoming_camps = array();
        if (function_exists('wc_get_products')) {
            $camp_args = array(
                'limit' => 4,
                'status' => 'publish',
                'orderby' => 'meta_value',
                'order' => 'ASC',
                'meta_key' => '_ptp_start_date',
                'meta_query' => array(
                    array(
                        'key' => '_ptp_is_camp',
                        'value' => 'yes',
                    ),
                    array(
                        'key' => '_ptp_start_date',
                        'value' => date('Y-m-d'),
                        'compare' => '>=',
                        'type' => 'DATE',
                    ),
                ),
            );
            $upcoming_camps = wc_get_products($camp_args);
        }
        
        if (!empty($upcoming_camps)):
        ?>
        <div class="ptp-ty-camp-upsell" style="margin-top:32px;">
            <div style="background:linear-gradient(135deg,rgba(252,185,0,0.15),rgba(252,185,0,0.05));border:2px solid var(--ptp-gold);padding:24px;">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
                    <span style="font-size:28px;">🏕️</span>
                    <div>
                        <div style="font-family:Oswald,sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;color:var(--ptp-gold);">Level Up at Camp!</div>
                        <div style="font-size:13px;color:rgba(0,0,0,0.6);">Combine 1-on-1 training with group experience</div>
                    </div>
                </div>
                
                <div style="font-size:14px;color:rgba(255,255,255,0.8);margin-bottom:20px;line-height:1.5;">
                    Players who do both private training <strong>AND</strong> camps improve 2x faster. Get the individual attention plus learn to apply skills in game situations.
                </div>
                
                <div style="display:grid;gap:12px;margin-bottom:20px;">
                    <?php foreach (array_slice($upcoming_camps, 0, 3) as $camp): 
                        $camp_id = $camp->get_id();
                        $camp_date = get_post_meta($camp_id, '_ptp_start_date', true);
                        $camp_location = get_post_meta($camp_id, '_ptp_location_name', true);
                        $camp_price = $camp->get_price();
                    ?>
                    <a href="<?php echo get_permalink($camp_id); ?>" style="display:flex;align-items:center;gap:14px;background:rgba(255,255,255,0.05);border:1px solid rgba(255,255,255,0.1);padding:14px;text-decoration:none;color:inherit;transition:all 0.2s;" onmouseover="this.style.borderColor='var(--ptp-gold)';this.style.background='rgba(252,185,0,0.1)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.1)';this.style.background='rgba(255,255,255,0.05)'">
                        <div style="width:56px;height:56px;background:var(--ptp-gold);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <span style="font-size:24px;">⚽</span>
                        </div>
                        <div style="flex:1;min-width:0;">
                            <div style="font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html($camp->get_name()); ?></div>
                            <div style="font-size:12px;color:rgba(0,0,0,0.5);">
                                <?php if ($camp_date): echo date('M j', strtotime($camp_date)); endif; ?>
                                <?php if ($camp_location): echo ' • ' . esc_html($camp_location); endif; ?>
                            </div>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-family:Oswald,sans-serif;font-size:16px;font-weight:700;color:var(--ptp-gold);">$<?php echo number_format($camp_price); ?></div>
                            <div style="font-size:10px;color:rgba(0,0,0,0.4);text-transform:uppercase;">View →</div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                
                <a href="<?php echo home_url('/camps/'); ?>" style="display:block;text-align:center;padding:14px 24px;background:var(--ptp-gold);color:var(--ptp-black);font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;letter-spacing:1px;text-decoration:none;transition:all 0.2s;" onmouseover="this.style.background='#E5A800'" onmouseout="this.style.background='var(--ptp-gold)'">
                    Browse All Camps →
                </a>
                
                <div style="margin-top:16px;display:flex;align-items:center;gap:8px;justify-content:center;">
                    <span style="font-size:14px;">✨</span>
                    <span style="font-size:12px;color:rgba(0,0,0,0.5);">Book a camp in the next 48 hours and get priority placement!</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php elseif ($has_camp || $order): ?>
        <!-- Order Details - Camp -->
        <div class="ptp-ty-card">
            <div class="ptp-ty-card-header gold">
                <span class="ptp-ty-card-icon">📋</span>
                <div>
                    <div class="ptp-ty-card-title">Camp Details</div>
                    <div class="ptp-ty-card-subtitle">Order #<?php echo $order ? $order->get_order_number() : $booking_id; ?></div>
                </div>
            </div>
            <div class="ptp-ty-card-body">
                <div class="ptp-ty-details">
                    <div>
                        <div class="ptp-ty-detail-label">Camp</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camp_name ?: 'PTP Soccer Camp'); ?></div>
                    </div>
                    <div>
                        <div class="ptp-ty-detail-label">Location</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camp_location ?: 'TBD'); ?></div>
                    </div>
                    <div>
                        <div class="ptp-ty-detail-label">Dates</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($formatted_dates ?: 'TBD'); ?></div>
                    </div>
                    <div>
                        <div class="ptp-ty-detail-label">Camper</div>
                        <div class="ptp-ty-detail-value"><?php echo esc_html($camper_name_display ?: '-'); ?></div>
                    </div>
                </div>

                <?php if ($coach_name): ?>
                <div class="ptp-ty-coach">
                    <div class="ptp-ty-coach-avatar">
                        <?php if ($coach_photo): ?>
                            <img src="<?php echo esc_url($coach_photo); ?>" alt="">
                        <?php else: ?>
                            <?php echo strtoupper(substr($coach_name, 0, 2)); ?>
                        <?php endif; ?>
                    </div>
                    <div class="ptp-ty-coach-info">
                        <div class="ptp-ty-coach-label">Your Coach</div>
                        <div class="ptp-ty-coach-name"><?php echo esc_html($coach_name); ?></div>
                        <?php if ($coach_team): ?>
                        <div class="ptp-ty-coach-team"><?php echo esc_html($coach_team); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="ptp-ty-coach-badge">MLS PRO</div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Testimonial -->
        <div class="ptp-ty-testimonial">
            <div class="ptp-ty-testimonial-label">Parent Review</div>
            <p>"My son improved more in 4 days than an entire season of rec league. The coaches actually PLAY with the kids—it's not just drills. He can't stop talking about it."</p>
            <cite>— Sarah M., Villanova</cite>
        </div>
        <?php endif; ?>

        <!-- Two Column Grid for Desktop -->
        <div class="ptp-ty-grid">
            <!-- Section 1: Social Announcement -->
            <div class="ptp-ty-card <?php echo !$announcement_submitted ? 'highlight' : ''; ?>" id="announce-card">
            <div class="ptp-ty-card-header <?php echo !$announcement_submitted ? 'gold' : ''; ?>">
                <span class="ptp-ty-card-icon">📸</span>
                <div>
                    <div class="ptp-ty-card-title">Announce It</div>
                    <div class="ptp-ty-card-subtitle">Let your friends know <?php echo esc_html($camper_name_display ?: 'your player'); ?> is training with pros</div>
                </div>
            </div>
            <div class="ptp-ty-card-body">
                <?php if ($announcement_submitted): ?>
                    <p style="color:var(--ptp-green);font-weight:600;">✓ Announcement submitted! We'll tag <?php echo esc_html($saved_ig_handle); ?> when we post.</p>
                <?php else: ?>
                    <p style="font-size:14px;color:rgba(0,0,0,0.7);margin:0 0 16px;line-height:1.5;">
                        Want us to post <?php echo esc_html($camper_name_display ?: 'your player'); ?>'s announcement on our Instagram? Your network will see it, and trust us—they'll be jealous.
                    </p>
                    
                    <div class="ptp-ty-ig-input">
                        <div class="ptp-ty-ig-prefix">@</div>
                        <input type="text" class="ptp-ty-ig-field" id="ig-handle" placeholder="yourhandle" value="">
                    </div>

                    <!-- Photo Upload -->
                    <div class="ptp-ty-photo-upload" id="photo-upload-section">
                        <label for="camper-photo" class="ptp-ty-photo-label">
                            <span class="ptp-ty-photo-icon">📷</span>
                            <span class="ptp-ty-photo-text" id="photo-text">Upload a photo of your player (optional)</span>
                            <input type="file" id="camper-photo" accept="image/*" style="display:none;">
                        </label>
                        <div class="ptp-ty-photo-preview" id="photo-preview" style="display:none;">
                            <img id="photo-preview-img" src="" alt="Preview">
                            <button type="button" class="ptp-ty-photo-remove" onclick="removePhoto()">✕</button>
                        </div>
                    </div>

                    <label class="ptp-ty-checkbox" id="announce-checkbox">
                        <div class="ptp-ty-checkbox-box"><span style="display:none;">✓</span></div>
                        <div class="ptp-ty-checkbox-text">
                            <strong>Yes, post <?php echo esc_html($camper_name_display ?: 'my player'); ?>'s announcement!</strong>
                            <span>We'll tag you so your followers see it</span>
                        </div>
                    </label>

                    <div class="ptp-ty-preview" id="ig-preview">
                        <div class="ptp-ty-preview-label">Preview</div>
                        <p>
                            <?php if ($has_training && $booking): ?>
                            🔥 <strong><?php echo esc_html($camper_name_upper ?: 'YOUR PLAYER'); ?></strong> is training with <?php echo esc_html($booking->trainer_name ?? 'a PTP Pro'); ?>!<br><br>
                            1-on-1 skill development with a professional athlete.<br><br>
                            Book your player → link in bio<br><br>
                            <?php else: ?>
                            🔥 <strong><?php echo esc_html($camper_name_upper ?: 'YOUR CAMPER'); ?></strong> is locked in for <?php echo esc_html($camp_name ?: 'PTP Soccer Camp'); ?>!<br><br>
                            Training with pro coaches this <?php echo esc_html($formatted_dates ?: 'soon'); ?> in <?php echo esc_html($camp_location ?: 'Pennsylvania'); ?>.<br><br>
                            Spots filling up → link in bio<br><br>
                            <?php endif; ?>
                            <span id="preview-handle">@yourhandle</span>
                        </p>
                    </div>

                    <!-- Submit Button -->
                    <button type="button" class="ptp-ty-submit-btn" id="submit-announcement" onclick="submitAnnouncement()">
                        <span class="btn-text">SUBMIT ANNOUNCEMENT</span>
                        <span class="btn-loading" style="display:none;">SUBMITTING...</span>
                    </button>
                    <p class="ptp-ty-submit-note">We'll review and post within 24-48 hours</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Section 2: Referral -->
        <div class="ptp-ty-card" id="referral-card">
            <div class="ptp-ty-card-header">
                <span class="ptp-ty-card-icon">🎁</span>
                <div>
                    <div class="ptp-ty-card-title">Give $25, Get $25</div>
                    <div class="ptp-ty-card-subtitle">Share with a friend—you both win</div>
                </div>
            </div>
            <div class="ptp-ty-card-body">
                <p style="font-size:14px;color:rgba(255,255,255,0.7);margin:0 0 16px;line-height:1.5;">
                    Know another family whose kid would love this? Share your link—they get $25 off, you get $25 credit toward your next camp.
                </p>

                <div class="ptp-ty-ref-link">
                    <code id="ref-link"><?php echo esc_html($referral_link); ?></code>
                    <button class="ptp-ty-ref-copy" id="copy-btn" onclick="copyRefLink()">COPY</button>
                </div>

                <div class="ptp-ty-share-btns">
                    <a href="sms:?body=<?php echo urlencode("My kid's doing PTP Soccer - actual pros coach AND play with the kids. Use my link for \$25 off: " . $referral_link); ?>" class="ptp-ty-share-btn" onclick="trackShare('sms')">
                        💬 TEXT
                    </a>
                    <a href="mailto:?subject=<?php echo urlencode('Check out PTP Soccer'); ?>&body=<?php echo urlencode("My kid's doing PTP Soccer camps - the coaches are actual MLS players who play WITH the kids. Use my link and we both get \$25 off: " . $referral_link); ?>" class="ptp-ty-share-btn" onclick="trackShare('email')">
                        ✉️ EMAIL
                    </a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode($referral_link); ?>" target="_blank" class="ptp-ty-share-btn" onclick="trackShare('facebook')">
                        📤 SHARE
                    </a>
                </div>

                <div style="margin-top:16px;padding:12px;background:rgba(252,185,0,0.05);border:1px dashed rgba(252,185,0,0.3);text-align:center;">
                    <span style="font-size:11px;color:rgba(0,0,0,0.5);letter-spacing:1px;text-transform:uppercase;">
                        Pre-written message included • Just hit send
                    </span>
                </div>
            </div>
        </div>
        
        <?php 
        // v116: Enhanced "Share Your Trainer" CTA - more prominent share prompt
        if ($has_training && $booking && !empty($booking->trainer_name)):
            $trainer_first = explode(' ', $booking->trainer_name)[0];
            $share_trainer_text = "I just booked soccer training with {$trainer_first} on PTP! They're amazing - use my link for 20% off your first session: {$referral_link}";
            $share_trainer_encoded = urlencode($share_trainer_text);
        ?>
        <div class="ptp-ty-card ptp-ty-share-trainer-cta" id="share-trainer-cta" style="border:2px solid var(--ptp-gold);background:linear-gradient(135deg, #0A0A0A 0%, #1a1a1a 100%);animation:pulseGlow 2s ease-in-out infinite;">
            <style>
                @keyframes pulseGlow {
                    0%, 100% { box-shadow: 0 0 0 0 rgba(252, 185, 0, 0.4); }
                    50% { box-shadow: 0 0 20px 4px rgba(252, 185, 0, 0.2); }
                }
                .ptp-share-trainer-btns {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 10px;
                    margin-top: 16px;
                }
                @media (min-width: 500px) {
                    .ptp-share-trainer-btns { grid-template-columns: repeat(4, 1fr); }
                }
                .ptp-share-trainer-btn {
                    display: flex;
                    flex-direction: column;
                    align-items: center;
                    justify-content: center;
                    gap: 6px;
                    padding: 14px 12px;
                    border-radius: 8px;
                    text-decoration: none;
                    font-size: 13px;
                    font-weight: 600;
                    transition: all 0.2s ease;
                    border: none;
                    cursor: pointer;
                    text-align: center;
                }
                .ptp-share-trainer-btn span:first-child { font-size: 20px; line-height: 1; }
                .ptp-share-trainer-btn.primary { background: var(--ptp-gold); color: #0A0A0A; }
                .ptp-share-trainer-btn.primary:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(252, 185, 0, 0.4); }
                .ptp-share-trainer-btn.whatsapp { background: #25D366; color: white; }
                .ptp-share-trainer-btn.email { background: #3B82F6; color: white; }
                .ptp-share-trainer-btn.copy { background: #f0f0f0; color: white; }
                .ptp-share-trainer-btn.copy.copied { background: #22C55E; }
                .ptp-share-trainer-btn:hover { transform: translateY(-2px); }
            </style>
            <div class="ptp-ty-card-header" style="background:var(--ptp-gold);margin:-20px -20px 20px;padding:16px 20px;border-radius:0;">
                <span class="ptp-ty-card-icon" style="font-size:24px;">📣</span>
                <div>
                    <div class="ptp-ty-card-title" style="color:#0A0A0A;">Share <?php echo esc_html($trainer_first); ?> with Friends</div>
                    <div class="ptp-ty-card-subtitle" style="color:rgba(0,0,0,0.7);">They get 20% off • You get $25 credit</div>
                </div>
            </div>
            <div class="ptp-ty-card-body" style="padding:0;">
                <p style="font-size:14px;color:rgba(255,255,255,0.8);margin:0 0 16px;line-height:1.6;">
                    Know another family whose kid could benefit from training with <?php echo esc_html($trainer_first); ?>? Share now — message is pre-written, just hit send!
                </p>
                
                <div class="ptp-share-trainer-btns">
                    <a href="sms:?body=<?php echo $share_trainer_encoded; ?>" class="ptp-share-trainer-btn primary" onclick="trackShare('sms_trainer')">
                        <span>💬</span>
                        <span>Text</span>
                    </a>
                    <a href="https://wa.me/?text=<?php echo $share_trainer_encoded; ?>" target="_blank" class="ptp-share-trainer-btn whatsapp" onclick="trackShare('whatsapp_trainer')">
                        <span>📱</span>
                        <span>WhatsApp</span>
                    </a>
                    <a href="mailto:?subject=<?php echo urlencode('Amazing soccer trainer!'); ?>&body=<?php echo $share_trainer_encoded; ?>" class="ptp-share-trainer-btn email" onclick="trackShare('email_trainer')">
                        <span>✉️</span>
                        <span>Email</span>
                    </a>
                    <button type="button" class="ptp-share-trainer-btn copy" id="copy-trainer-btn" onclick="copyTrainerLink()">
                        <span>🔗</span>
                        <span>Copy Link</span>
                    </button>
                </div>
                
                <div style="margin-top:16px;padding:10px;background:rgba(252,185,0,0.1);border:1px dashed rgba(252,185,0,0.3);border-radius:6px;text-align:center;">
                    <span style="font-size:11px;color:rgba(0,0,0,0.6);">💡 Parents who share get <strong style="color:var(--ptp-gold);">3x more referral credits</strong> on average</span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        </div><!-- End .ptp-ty-grid -->

        <!-- Section 3: Private Training CTA -->
        <?php 
        $training_cta_enabled = get_option('ptp_thankyou_training_cta_enabled', 'yes') === 'yes';
        $training_cta_url = get_option('ptp_thankyou_training_cta_url', '/find-trainers/');
        
        // For training bookings, link to same trainer
        $trainer_link = $training_cta_url;
        $trainer_cta_text = 'Browse Trainers & Book';
        $coach_first_name = '';
        
        if ($has_training && $booking && !empty($booking->trainer_slug)) {
            $trainer_link = home_url('/trainer/' . $booking->trainer_slug . '/');
            $coach_first_name = !empty($booking->trainer_name) ? explode(' ', $booking->trainer_name)[0] : '';
            $trainer_cta_text = $coach_first_name ? 'Book Again with ' . $coach_first_name : 'Book Again';
        }
        ?>
        <?php if ($training_cta_enabled): ?>
        <div class="ptp-ty-card ptp-ty-training-cta">
            <div class="ptp-ty-card-body" style="text-align:center;padding:28px 20px;">
                <h3 style="font-size:22px;color:var(--ptp-gold);margin-bottom:12px;">⚡ Want Even More Improvement?</h3>
                <p style="color:rgba(255,255,255,0.7);margin-bottom:20px;line-height:1.6;">
                    <?php if ($has_training && $coach_first_name): ?>
                    Book more sessions with <?php echo esc_html($coach_first_name); ?> and keep the momentum going.
                    <?php else: ?>
                    Book a private 1-on-1 session with one of our pro coaches. Work on specific skills before camp and hit the ground running.
                    <?php endif; ?>
                </p>
                
                <div class="ptp-ty-training-features" style="display:flex;justify-content:center;gap:20px;margin-bottom:20px;flex-wrap:wrap;">
                    <span style="font-size:12px;color:rgba(0,0,0,0.6);">✓ MLS & D1 Coaches</span>
                    <span style="font-size:12px;color:rgba(0,0,0,0.6);">✓ Personalized Training</span>
                    <span style="font-size:12px;color:rgba(0,0,0,0.6);">✓ Flexible Scheduling</span>
                </div>
                
                <?php if ($has_training && $booking && $coach_photo): ?>
                <div style="display:flex;align-items:center;justify-content:center;gap:12px;margin-bottom:20px;padding:12px;background:rgba(0,0,0,0.2);border-radius:8px;">
                    <img src="<?php echo esc_url($coach_photo); ?>" alt="" style="width:44px;height:44px;border-radius:50%;border:2px solid var(--ptp-gold);object-fit:cover;">
                    <span style="font-weight:600;"><?php echo esc_html($booking->trainer_name ?? 'Your Trainer'); ?></span>
                </div>
                <?php endif; ?>
                
                <a href="<?php echo esc_url(home_url($trainer_link)); ?>" class="ptp-ty-training-btn">
                    🎯 <?php echo esc_html($trainer_cta_text); ?>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- What's Next -->
        <div class="ptp-ty-card">
            <div class="ptp-ty-card-header">
                <span class="ptp-ty-card-icon">📋</span>
                <div class="ptp-ty-card-title">What's Next</div>
            </div>
            <div class="ptp-ty-card-body">
                <div class="ptp-ty-steps">
                    <div class="ptp-ty-step">
                        <div class="ptp-ty-step-num">1</div>
                        <div>
                            <div class="ptp-ty-step-title">Check Your Email</div>
                            <div class="ptp-ty-step-desc">Confirmation + what to bring</div>
                        </div>
                    </div>
                    <div class="ptp-ty-step">
                        <div class="ptp-ty-step-num">2</div>
                        <div>
                            <div class="ptp-ty-step-title">48 Hours Before Camp</div>
                            <div class="ptp-ty-step-desc">You'll get a text with exact location & parking</div>
                        </div>
                    </div>
                    <div class="ptp-ty-step">
                        <div class="ptp-ty-step-num">3</div>
                        <div>
                            <div class="ptp-ty-step-title">Show Up Ready</div>
                            <div class="ptp-ty-step-desc">Cleats, shin guards, water bottle, and energy!</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- CTA Buttons -->
        <div class="ptp-ty-footer-actions" style="display:flex;flex-direction:column;gap:12px;margin-top:24px;">
            <a href="<?php echo home_url('/my-training/'); ?>" class="ptp-ty-btn" style="display:flex;align-items:center;justify-content:center;gap:8px;padding:16px 28px;background:var(--ptp-gold);color:var(--ptp-black);font-family:'Oswald',sans-serif;font-size:15px;font-weight:600;text-transform:uppercase;text-decoration:none;letter-spacing:1px;transition:all 0.2s;">
                🏠 View My Dashboard
            </a>
            <a href="<?php echo home_url('/find-trainers/'); ?>" style="display:flex;align-items:center;justify-content:center;padding:16px 28px;border:2px solid rgba(255,255,255,0.3);color:#1a1a1a;font-family:'Oswald',sans-serif;font-size:15px;font-weight:600;text-transform:uppercase;text-decoration:none;letter-spacing:1px;transition:all 0.2s;">
                Book Another Session
            </a>
        </div>
    </div>
</div>

<script>
(function() {
    // Data
    const orderData = {
        orderId: <?php echo intval($order_id); ?>,
        bookingId: <?php echo intval($booking_id); ?>,
        camperName: '<?php echo esc_js($camper_name_upper); ?>',
        campName: '<?php echo esc_js($camp_name); ?>',
        referralCode: '<?php echo esc_js($referral_code); ?>',
        nonce: '<?php echo wp_create_nonce('ptp_thankyou_nonce'); ?>'
    };

    // Confetti effect
    function launchConfetti() {
        const container = document.getElementById('confetti');
        const colors = ['#FCB900', '#FFFFFF', '#22C55E'];
        
        for (let i = 0; i < 50; i++) {
            const piece = document.createElement('div');
            piece.className = 'ptp-confetti-piece';
            piece.style.left = Math.random() * 100 + '%';
            piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
            piece.style.animationDelay = Math.random() * 0.5 + 's';
            piece.style.animationDuration = (2 + Math.random() * 2) + 's';
            container.appendChild(piece);
        }

        setTimeout(() => {
            container.innerHTML = '';
        }, 4000);
    }

    // Launch confetti on load
    launchConfetti();

    // Instagram announcement handling
    const igInput = document.getElementById('ig-handle');
    const checkbox = document.getElementById('announce-checkbox');
    const preview = document.getElementById('ig-preview');
    const previewHandle = document.getElementById('preview-handle');
    const photoInput = document.getElementById('camper-photo');
    const photoPreview = document.getElementById('photo-preview');
    const photoPreviewImg = document.getElementById('photo-preview-img');
    const photoText = document.getElementById('photo-text');
    const photoUploadSection = document.getElementById('photo-upload-section');
    let isChecked = false;
    let selectedFile = null;
    let compressedFile = null;

    // Helper: Compress image before upload
    async function compressImage(file, maxWidth = 1200, maxHeight = 1200, quality = 0.8) {
        return new Promise((resolve, reject) => {
            try {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        try {
                            let width = img.width;
                            let height = img.height;
                            
                            // Calculate new dimensions
                            if (width > maxWidth || height > maxHeight) {
                                const ratio = Math.min(maxWidth / width, maxHeight / height);
                                width = Math.round(width * ratio);
                                height = Math.round(height * ratio);
                            }
                            
                            // Create canvas and draw
                            const canvas = document.createElement('canvas');
                            canvas.width = width;
                            canvas.height = height;
                            const ctx = canvas.getContext('2d');
                            ctx.drawImage(img, 0, 0, width, height);
                            
                            // Convert to blob
                            canvas.toBlob(function(blob) {
                                if (blob) {
                                    const compressedFile = new File([blob], file.name.replace(/\.[^/.]+$/, '.jpg'), {
                                        type: 'image/jpeg',
                                        lastModified: Date.now()
                                    });
                                    resolve(compressedFile);
                                } else {
                                    resolve(file); // Fallback to original
                                }
                            }, 'image/jpeg', quality);
                        } catch (canvasErr) {
                            console.warn('Canvas compression failed:', canvasErr);
                            resolve(file);
                        }
                    };
                    img.onerror = function() {
                        console.warn('Image load failed for compression');
                        resolve(file);
                    };
                    img.src = e.target.result;
                };
                reader.onerror = function() {
                    console.warn('FileReader failed');
                    resolve(file);
                };
                reader.readAsDataURL(file);
            } catch (err) {
                console.warn('Compression error:', err);
                resolve(file);
            }
        });
    }

    // Helper: Validate image file
    function validateImageFile(file) {
        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/heic', 'image/heif'];
        const maxSize = 10 * 1024 * 1024; // 10MB before compression
        
        if (!file) {
            return { valid: false, error: 'No file selected' };
        }
        
        // Check file type (be lenient - some mobile devices report weird types)
        const isImage = file.type.startsWith('image/') || 
                        validTypes.includes(file.type.toLowerCase()) ||
                        /\.(jpg|jpeg|png|gif|webp|heic|heif)$/i.test(file.name);
        
        if (!isImage) {
            return { valid: false, error: 'Please select an image file (JPG, PNG, GIF, or WebP)' };
        }
        
        if (file.size > maxSize) {
            return { valid: false, error: 'Image must be less than 10MB. Try a smaller photo.' };
        }
        
        return { valid: true };
    }

    // Helper: Show upload status
    function showPhotoStatus(message, isError = false) {
        if (!photoText) return;
        photoText.textContent = message;
        photoText.style.color = isError ? '#EF4444' : 'rgba(255,255,255,0.7)';
    }

    if (checkbox) {
        checkbox.addEventListener('click', function() {
            isChecked = !isChecked;
            this.classList.toggle('checked', isChecked);
            const checkmark = this.querySelector('.ptp-ty-checkbox-box span');
            if (checkmark) checkmark.style.display = isChecked ? 'block' : 'none';
            
            if (isChecked && igInput && igInput.value) {
                if (preview) preview.classList.add('show');
            } else {
                if (preview) preview.classList.remove('show');
            }
        });
    }

    if (igInput) {
        igInput.addEventListener('input', function() {
            const handle = this.value.replace('@', '');
            if (previewHandle) previewHandle.textContent = '@' + (handle || 'yourhandle');
            
            if (isChecked && handle && preview) {
                preview.classList.add('show');
            }
        });
    }

    // Photo upload handling - more robust
    if (photoInput) {
        photoInput.addEventListener('change', async function(e) {
            const file = e.target.files && e.target.files[0];
            if (!file) return;
            
            // Validate
            const validation = validateImageFile(file);
            if (!validation.valid) {
                showPhotoStatus(validation.error, true);
                this.value = '';
                return;
            }
            
            // Show processing state
            showPhotoStatus('Processing photo...');
            if (photoUploadSection) photoUploadSection.style.opacity = '0.7';
            
            try {
                // Compress if needed
                let processedFile = file;
                if (file.size > 1024 * 1024) { // Compress if > 1MB
                    processedFile = await compressImage(file);
                    console.log('[PTP] Compressed:', file.size, '->', processedFile.size);
                }
                
                selectedFile = file;
                compressedFile = processedFile;
                
                // Show preview
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (photoPreviewImg) {
                        photoPreviewImg.src = e.target.result;
                        photoPreviewImg.onerror = function() {
                            showPhotoStatus('Could not preview image', true);
                        };
                    }
                    if (photoPreview) photoPreview.style.display = 'block';
                    
                    const sizeMB = (processedFile.size / 1024 / 1024).toFixed(1);
                    showPhotoStatus(file.name.substring(0, 20) + (file.name.length > 20 ? '...' : '') + ' (' + sizeMB + 'MB)');
                    if (photoUploadSection) photoUploadSection.style.opacity = '1';
                };
                reader.onerror = function() {
                    showPhotoStatus('Could not read image', true);
                    if (photoUploadSection) photoUploadSection.style.opacity = '1';
                };
                reader.readAsDataURL(file);
                
            } catch (err) {
                console.error('[PTP] Photo processing error:', err);
                showPhotoStatus('Error processing photo. Try another image.', true);
                if (photoUploadSection) photoUploadSection.style.opacity = '1';
                this.value = '';
            }
        });
    }

    // Remove photo - with null checks
    window.removePhoto = function() {
        selectedFile = null;
        compressedFile = null;
        if (photoInput) photoInput.value = '';
        if (photoPreview) photoPreview.style.display = 'none';
        if (photoPreviewImg) photoPreviewImg.src = '';
        showPhotoStatus('Upload a photo of your camper (optional)');
        if (photoText) photoText.style.color = 'rgba(255,255,255,0.7)';
    };

    // Submit announcement - more robust
    window.submitAnnouncement = async function() {
        const handle = igInput ? igInput.value.replace(/^@/, '').trim() : '';
        const submitBtn = document.getElementById('submit-announcement');
        if (!submitBtn) return;
        
        const btnText = submitBtn.querySelector('.btn-text');
        const btnLoading = submitBtn.querySelector('.btn-loading');
        
        // Validation
        if (!handle) {
            alert('Please enter your Instagram handle');
            if (igInput) igInput.focus();
            return;
        }
        
        // Validate handle format
        if (!/^[a-zA-Z0-9_.]{1,30}$/.test(handle)) {
            alert('Please enter a valid Instagram handle (letters, numbers, underscores, periods only)');
            if (igInput) igInput.focus();
            return;
        }
        
        if (!isChecked) {
            alert('Please check the box to confirm you want us to post the announcement');
            return;
        }
        
        // Show loading
        submitBtn.disabled = true;
        if (btnText) btnText.style.display = 'none';
        if (btnLoading) btnLoading.style.display = 'inline';
        
        // Create form data for file upload
        const formData = new FormData();
        formData.append('action', 'ptp_save_social_announcement');
        formData.append('nonce', orderData.nonce || '');
        formData.append('order_id', orderData.orderId || '');
        formData.append('booking_id', orderData.bookingId || '');
        formData.append('ig_handle', handle);
        formData.append('opt_in', '1');
        formData.append('camper_name', orderData.camperName || '');
        formData.append('camp_name', orderData.campName || '');
        
        // Use compressed file if available
        if (compressedFile) {
            formData.append('camper_photo', compressedFile);
        } else if (selectedFile) {
            formData.append('camper_photo', selectedFile);
        }
        
        // Retry logic
        let attempts = 0;
        const maxAttempts = 2;
        
        async function attemptSubmit() {
            attempts++;
            try {
                const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                });
                
                if (!response.ok) {
                    throw new Error('Server returned ' + response.status);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    // Success state
                    submitBtn.classList.add('success');
                    if (btnLoading) btnLoading.style.display = 'none';
                    if (btnText) {
                        btnText.textContent = '✓ SUBMITTED!';
                        btnText.style.display = 'inline';
                    }
                    
                    // Update card to show success
                    const card = document.getElementById('announce-card');
                    if (card) {
                        card.classList.remove('highlight');
                        const header = card.querySelector('.ptp-ty-card-header');
                        if (header) header.classList.remove('gold');
                        
                        // Replace form with success message after delay
                        setTimeout(() => {
                            const cardBody = card.querySelector('.ptp-ty-card-body');
                            if (cardBody) {
                                cardBody.innerHTML = '<p style="color:var(--ptp-green);font-weight:600;">✓ Announcement submitted! We\'ll tag @' + handle + ' when we post.</p>';
                            }
                        }, 1500);
                    }
                } else {
                    throw new Error(data.data || 'Submission failed');
                }
            } catch (err) {
                console.error('[PTP] Announcement submit error:', err);
                
                // Retry once
                if (attempts < maxAttempts) {
                    console.log('[PTP] Retrying submission...');
                    await new Promise(r => setTimeout(r, 1000));
                    return attemptSubmit();
                }
                
                // Final failure
                alert('Something went wrong. Please try again or contact us at luke@ptpsummercamps.com');
                submitBtn.disabled = false;
                if (btnText) {
                    btnText.style.display = 'inline';
                    btnText.textContent = 'SUBMIT ANNOUNCEMENT';
                }
                if (btnLoading) btnLoading.style.display = 'none';
            }
        }
        
        await attemptSubmit();
    };

    // Copy referral link
    window.copyRefLink = function() {
        const link = document.getElementById('ref-link').textContent;
        const btn = document.getElementById('copy-btn');
        
        // Track the copy
        trackShare('copy');
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(link).then(() => {
                btn.textContent = '✓ COPIED';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.textContent = 'COPY';
                    btn.classList.remove('copied');
                }, 2000);
            });
        } else {
            // Fallback
            const input = document.createElement('input');
            input.value = link;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            btn.textContent = '✓ COPIED';
            btn.classList.add('copied');
            setTimeout(() => {
                btn.textContent = 'COPY';
                btn.classList.remove('copied');
            }, 2000);
        }
    };
    
    // v116: Copy trainer share link
    window.copyTrainerLink = function() {
        const link = orderData.referralLink || '<?php echo esc_js($referral_link); ?>';
        const btn = document.getElementById('copy-trainer-btn');
        
        trackShare('copy_trainer');
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(link).then(() => {
                if (btn) {
                    btn.innerHTML = '<span>✓</span><span>Copied!</span>';
                    btn.classList.add('copied');
                    setTimeout(() => {
                        btn.innerHTML = '<span>🔗</span><span>Copy Link</span>';
                        btn.classList.remove('copied');
                    }, 2000);
                }
            });
        } else {
            const input = document.createElement('input');
            input.value = link;
            document.body.appendChild(input);
            input.select();
            document.execCommand('copy');
            document.body.removeChild(input);
            if (btn) {
                btn.innerHTML = '<span>✓</span><span>Copied!</span>';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = '<span>🔗</span><span>Copy Link</span>';
                    btn.classList.remove('copied');
                }, 2000);
            }
        }
    };

    // Track share clicks
    window.trackShare = function(platform) {
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'ptp_track_thankyou_share',
                platform: platform,
                order_id: orderData.orderId,
                referral_code: orderData.referralCode
            })
        }).catch(err => console.error('Share tracking error:', err));
    };
})();
</script>
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
<?php

} catch (Throwable $e) {
    error_log('[PTP Thank You EXCEPTION] [' . get_class($e) . '] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('[PTP Thank You EXCEPTION] Stack trace: ' . $e->getTraceAsString());
    
    // Show a friendly fallback page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Thank You - PTP</title>
        <style>
            body { font-family: -apple-system, sans-serif; background: #0A0A0A; color: #fff; min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0; }
            .ty-fallback { text-align: center; padding: 40px 20px; max-width: 500px; }
            .ty-fallback h1 { font-size: 48px; margin: 0 0 16px; }
            .ty-fallback h2 { color: #FCB900; font-size: 24px; margin: 0 0 24px; }
            .ty-fallback p { color: rgba(255,255,255,0.7); line-height: 1.6; margin: 0 0 24px; }
            .ty-fallback a { display: inline-block; background: #FCB900; color: #0A0A0A; padding: 14px 28px; font-weight: 700; text-decoration: none; border-radius: 8px; }
        </style>
    </head>
<body style="margin: 0; padding: 0; min-height: 100vh; overflow-y: auto;">
<div id="ptp-scroll-wrapper" style="width: 100%;">
        <div class="ty-fallback">
            <h1>✓</h1>
            <h2>Payment Received!</h2>
            <p>Your booking is being processed. You'll receive a confirmation email shortly with all the details.</p>
            <p>If you don't receive an email within 10 minutes, please contact us.</p>
            <a href="<?php echo home_url(); ?>">Return Home</a>
        </div>
    </div><!-- #ptp-scroll-wrapper -->
</body>
</html>
    <?php
}

// Restore error handler
restore_error_handler();