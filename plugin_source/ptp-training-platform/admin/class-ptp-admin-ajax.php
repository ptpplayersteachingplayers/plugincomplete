<?php
/**
 * PTP Admin AJAX Handlers
 * Session management, compliance emails, payouts
 */

defined('ABSPATH') || exit;

class PTP_Admin_Ajax {
    
    public static function init() {
        add_action('wp_ajax_ptp_admin_create_booking', array(__CLASS__, 'create_booking'));
        add_action('wp_ajax_ptp_admin_update_booking', array(__CLASS__, 'update_booking'));
        add_action('wp_ajax_ptp_admin_delete_booking', array(__CLASS__, 'delete_booking'));
        add_action('wp_ajax_ptp_admin_get_booking', array(__CLASS__, 'get_booking'));
        add_action('wp_ajax_ptp_admin_update_trainer', array(__CLASS__, 'update_trainer'));
        add_action('wp_ajax_ptp_admin_get_trainer', array(__CLASS__, 'get_trainer'));
        add_action('wp_ajax_ptp_admin_upload_trainer_photo', array(__CLASS__, 'upload_trainer_photo'));
        add_action('wp_ajax_ptp_admin_send_safesport_request', array(__CLASS__, 'send_safesport_request'));
        add_action('wp_ajax_ptp_admin_send_w9_request', array(__CLASS__, 'send_w9_request'));
        add_action('wp_ajax_ptp_admin_send_background_check_request', array(__CLASS__, 'send_background_check_request'));
        add_action('wp_ajax_ptp_admin_mark_verified', array(__CLASS__, 'mark_trainer_verified'));
        add_action('wp_ajax_ptp_admin_process_payout', array(__CLASS__, 'process_payout'));
        add_action('wp_ajax_ptp_admin_mark_payout_complete', array(__CLASS__, 'mark_payout_complete'));
        add_action('wp_ajax_ptp_admin_get_parent_players', array(__CLASS__, 'get_parent_players'));
    }
    
    private static function verify_admin() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            exit;
        }
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'ptp_admin_nonce')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            exit;
        }
    }
    
    public static function create_booking() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $parent_id = intval($_POST['parent_id']);
        $player_id = intval($_POST['player_id']);
        $session_date = sanitize_text_field($_POST['session_date']);
        $start_time = sanitize_text_field($_POST['start_time']);
        $end_time = sanitize_text_field($_POST['end_time']);
        $location = sanitize_text_field($_POST['location']);
        $hourly_rate = floatval($_POST['hourly_rate']);
        $notes = sanitize_textarea_field($_POST['notes']);
        $status = sanitize_text_field($_POST['status']);
        
        $start = strtotime($start_time);
        $end = strtotime($end_time);
        $duration = ($end - $start) / 60;
        $total_amount = ($duration / 60) * $hourly_rate;
        $booking_number = 'PTP-' . strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'ptp_bookings',
            array(
                'booking_number' => $booking_number,
                'trainer_id' => $trainer_id,
                'parent_id' => $parent_id,
                'player_id' => $player_id,
                'session_date' => $session_date,
                'start_time' => $start_time,
                'end_time' => $end_time,
                'duration_minutes' => $duration,
                'location' => $location,
                'hourly_rate' => $hourly_rate,
                'total_amount' => $total_amount,
                'notes' => $notes,
                'status' => $status,
                'payment_status' => 'admin_created',
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id()
            )
        );
        
        if ($result) {
            wp_send_json_success(array('message' => 'Session created', 'booking_number' => $booking_number));
        } else {
            wp_send_json_error(array('message' => 'Database error'));
        }
    }
    
    public static function update_booking() {
        self::verify_admin();
        global $wpdb;
        
        $booking_id = intval($_POST['booking_id']);
        $data = array();
        
        $fields = array('session_date', 'start_time', 'end_time', 'location', 'notes', 'status');
        foreach ($fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        if (isset($_POST['hourly_rate'])) {
            $data['hourly_rate'] = floatval($_POST['hourly_rate']);
        }
        
        $result = $wpdb->update($wpdb->prefix . 'ptp_bookings', $data, array('id' => $booking_id));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Booking updated'));
        } else {
            wp_send_json_error(array('message' => 'Update failed'));
        }
    }
    
    public static function get_booking() {
        self::verify_admin();
        global $wpdb;
        
        $booking_id = intval($_POST['booking_id']);
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_bookings WHERE id = %d",
            $booking_id
        ));
        
        if ($booking) {
            wp_send_json_success($booking);
        } else {
            wp_send_json_error(array('message' => 'Not found'));
        }
    }
    
    public static function delete_booking() {
        self::verify_admin();
        global $wpdb;
        
        $booking_id = intval($_POST['booking_id']);
        $result = $wpdb->delete($wpdb->prefix . 'ptp_bookings', array('id' => $booking_id));
        
        if ($result) {
            wp_send_json_success(array('message' => 'Deleted'));
        } else {
            wp_send_json_error(array('message' => 'Delete failed'));
        }
    }
    
    public static function get_trainer() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if ($trainer) {
            wp_send_json_success($trainer);
        } else {
            wp_send_json_error(array('message' => 'Not found'));
        }
    }
    
    public static function update_trainer() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $data = array();
        
        // Text fields
        $text_fields = array('display_name', 'phone', 'location', 'headline', 'college', 'team', 'playing_level', 'status');
        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = sanitize_text_field($_POST[$field]);
            }
        }
        
        // URL fields (photo, intro video)
        $url_fields = array('photo_url', 'intro_video_url');
        foreach ($url_fields as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = esc_url_raw($_POST[$field]);
            }
        }
        
        if (isset($_POST['bio'])) {
            $data['bio'] = sanitize_textarea_field($_POST['bio']);
        }
        if (isset($_POST['hourly_rate'])) {
            $data['hourly_rate'] = floatval($_POST['hourly_rate']);
        }
        
        // Checkboxes
        $checkboxes = array('safesport_verified', 'w9_submitted', 'background_verified', 'is_verified', 'is_featured');
        foreach ($checkboxes as $field) {
            if (isset($_POST[$field])) {
                $data[$field] = intval($_POST[$field]) ? 1 : 0;
            }
        }
        
        // Sort order
        if (isset($_POST['sort_order'])) {
            $data['sort_order'] = intval($_POST['sort_order']);
        }
        
        $result = $wpdb->update($wpdb->prefix . 'ptp_trainers', $data, array('id' => $trainer_id));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Trainer updated'));
        } else {
            wp_send_json_error(array('message' => 'Update failed: ' . $wpdb->last_error));
        }
    }
    
    /**
     * Handle admin trainer photo upload
     */
    public static function upload_trainer_photo() {
        self::verify_admin();
        
        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(array('message' => 'No file uploaded or upload error'));
            return;
        }
        
        $trainer_id = intval($_POST['trainer_id']);
        if (!$trainer_id) {
            wp_send_json_error(array('message' => 'Invalid trainer ID'));
            return;
        }
        
        // Validate file type
        $allowed_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        $file_type = wp_check_filetype($_FILES['photo']['name']);
        if (!in_array($_FILES['photo']['type'], $allowed_types)) {
            wp_send_json_error(array('message' => 'Invalid file type. Allowed: JPEG, PNG, GIF, WebP'));
            return;
        }
        
        // Handle upload
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        
        $attachment_id = media_handle_upload('photo', 0);
        
        if (is_wp_error($attachment_id)) {
            wp_send_json_error(array('message' => 'Upload failed: ' . $attachment_id->get_error_message()));
            return;
        }
        
        $photo_url = wp_get_attachment_url($attachment_id);
        
        // Update trainer record
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->prefix . 'ptp_trainers',
            array('photo_url' => $photo_url),
            array('id' => $trainer_id)
        );
        
        if ($result !== false) {
            wp_send_json_success(array(
                'message' => 'Photo uploaded successfully',
                'photo_url' => $photo_url,
                'attachment_id' => $attachment_id
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to update trainer record'));
        }
    }
    
    public static function send_safesport_request() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->user_email) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $result = self::send_compliance_email($trainer, 'safesport');
        
        if ($result) {
            $wpdb->update($wpdb->prefix . 'ptp_trainers', 
                array('safesport_requested_at' => current_time('mysql')), 
                array('id' => $trainer_id)
            );
            wp_send_json_success(array('message' => 'SafeSport request sent to ' . $trainer->user_email));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email'));
        }
    }
    
    public static function send_w9_request() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->user_email) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $result = self::send_compliance_email($trainer, 'w9');
        
        if ($result) {
            $wpdb->update($wpdb->prefix . 'ptp_trainers', 
                array('w9_requested_at' => current_time('mysql')), 
                array('id' => $trainer_id)
            );
            wp_send_json_success(array('message' => 'W9 request sent to ' . $trainer->user_email));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email'));
        }
    }
    
    public static function send_background_check_request() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $trainer = $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_email FROM {$wpdb->prefix}ptp_trainers t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID WHERE t.id = %d",
            $trainer_id
        ));
        
        if (!$trainer || !$trainer->user_email) {
            wp_send_json_error(array('message' => 'Trainer not found'));
            return;
        }
        
        $result = self::send_compliance_email($trainer, 'background');
        
        if ($result) {
            wp_send_json_success(array('message' => 'Background check request sent'));
        } else {
            wp_send_json_error(array('message' => 'Failed to send email'));
        }
    }
    
    public static function mark_trainer_verified() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $verified = intval($_POST['verified']);
        
        $data = array('is_verified' => $verified);
        
        if ($verified && isset($_POST['safesport'])) {
            $data['safesport_verified'] = 1;
        }
        if ($verified && isset($_POST['w9'])) {
            $data['w9_submitted'] = 1;
        }
        if ($verified && isset($_POST['background'])) {
            $data['background_verified'] = 1;
        }
        
        $result = $wpdb->update($wpdb->prefix . 'ptp_trainers', $data, array('id' => $trainer_id));
        
        if ($result !== false) {
            wp_send_json_success(array('message' => 'Verification updated'));
        } else {
            wp_send_json_error(array('message' => 'Update failed'));
        }
    }
    
    private static function send_compliance_email($trainer, $type) {
        $first_name = explode(' ', $trainer->display_name)[0];
        $dashboard_url = home_url('/trainer-dashboard/');
        
        $subjects = array(
            'safesport' => 'Action Required: SafeSport Certification - PTP Training',
            'w9' => 'Action Required: W-9 Tax Form - PTP Training',
            'background' => 'Action Required: Background Check - PTP Training'
        );
        
        $subject = isset($subjects[$type]) ? $subjects[$type] : 'Action Required - PTP Training';
        
        $body = self::get_compliance_email_body($type, $first_name, $dashboard_url);
        
        add_filter('wp_mail_content_type', array(__CLASS__, 'set_html_content_type'));
        $result = wp_mail($trainer->user_email, $subject, $body);
        remove_filter('wp_mail_content_type', array(__CLASS__, 'set_html_content_type'));
        
        return $result;
    }
    
    public static function set_html_content_type() {
        return 'text/html';
    }
    
    private static function get_compliance_email_body($type, $first_name, $dashboard_url) {
        $content = '';
        
        if ($type === 'safesport') {
            $content = '<h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#0E0F11;">SafeSport Certification Required</h1>
            <p style="margin:0 0 24px;font-size:16px;color:#6B7280;">Hi ' . esc_html($first_name) . ', to complete your trainer profile, you need to complete SafeSport training.</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">SafeSport teaches how to recognize and prevent abuse in athletics. It\'s required for all PTP trainers.</p>
            <p style="margin:0 0 24px;font-size:14px;color:#374151;"><strong>Cost:</strong> Free | <strong>Duration:</strong> 90 min | <strong>Valid:</strong> 2 years</p>
            <p style="margin:0;"><a href="https://safesport.org" style="display:inline-block;background:#FCB900;color:#0E0F11;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:700;">Complete SafeSport Training</a></p>';
        } elseif ($type === 'w9') {
            $content = '<h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#0E0F11;">W-9 Form Required</h1>
            <p style="margin:0 0 24px;font-size:16px;color:#6B7280;">Hi ' . esc_html($first_name) . ', as an independent contractor, we need your W-9 on file for tax purposes.</p>
            <p style="margin:0 0 24px;font-size:14px;color:#374151;">The IRS requires a W-9 for any contractor paid $600+ annually. This allows us to issue your 1099-NEC.</p>
            <p style="margin:0;"><a href="https://www.irs.gov/pub/irs-pdf/fw9.pdf" style="display:inline-block;background:#FCB900;color:#0E0F11;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:700;">Download W-9 Form</a></p>';
        } elseif ($type === 'background') {
            $content = '<h1 style="margin:0 0 8px;font-size:26px;font-weight:800;color:#0E0F11;">Background Check Required</h1>
            <p style="margin:0 0 24px;font-size:16px;color:#6B7280;">Hi ' . esc_html($first_name) . ', to ensure player safety, all PTP trainers must complete a background check.</p>
            <p style="margin:0 0 24px;font-size:14px;color:#374151;"><strong>Cost:</strong> Covered by PTP | <strong>Duration:</strong> 2-5 business days</p>
            <p style="margin:0;"><a href="' . esc_url($dashboard_url) . '" style="display:inline-block;background:#FCB900;color:#0E0F11;padding:14px 28px;text-decoration:none;border-radius:8px;font-weight:700;">Start Background Check</a></p>';
        }
        
        return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
        <body style="margin:0;padding:0;background:#0E0F11;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#0E0F11;">
        <tr><td align="center" style="padding:40px 20px;">
        <table width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
        <tr><td align="center" style="padding-bottom:24px;"><img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" width="100"></td></tr>
        <tr><td style="background:#fff;border-radius:16px;padding:36px;">' . $content . '</td></tr>
        <tr><td style="padding:24px;text-align:center;color:#9CA3AF;font-size:13px;">PTP Training - Elite 1-on-1 Training</td></tr>
        </table></td></tr></table></body></html>';
    }
    
    public static function process_payout() {
        self::verify_admin();
        global $wpdb;
        
        $trainer_id = intval($_POST['trainer_id']);
        $amount = floatval($_POST['amount']);
        $method = sanitize_text_field($_POST['method']);
        
        $result = $wpdb->insert($wpdb->prefix . 'ptp_payouts', array(
            'trainer_id' => $trainer_id,
            'amount' => $amount,
            'method' => $method,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'created_by' => get_current_user_id()
        ));
        
        if ($result) {
            wp_send_json_success(array('message' => 'Payout created', 'payout_id' => $wpdb->insert_id));
        } else {
            wp_send_json_error(array('message' => 'Failed to create payout'));
        }
    }
    
    public static function mark_payout_complete() {
        self::verify_admin();
        global $wpdb;
        
        $payout_id = intval($_POST['payout_id']);
        $transaction_id = sanitize_text_field($_POST['transaction_id']);
        
        $payout = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_payouts WHERE id = %d",
            $payout_id
        ));
        
        if (!$payout) {
            wp_send_json_error(array('message' => 'Payout not found'));
            return;
        }
        
        $wpdb->update($wpdb->prefix . 'ptp_payouts', array(
            'status' => 'completed',
            'transaction_id' => $transaction_id,
            'completed_at' => current_time('mysql')
        ), array('id' => $payout_id));
        
        wp_send_json_success(array('message' => 'Payout marked complete'));
    }
    
    public static function get_parent_players() {
        self::verify_admin();
        global $wpdb;
        
        $parent_id = intval($_POST['parent_id']);
        $players = $wpdb->get_results($wpdb->prepare(
            "SELECT id, name FROM {$wpdb->prefix}ptp_players WHERE parent_id = %d AND is_active = 1",
            $parent_id
        ));
        
        wp_send_json_success($players);
    }
}

// Initialize on plugins_loaded to ensure WordPress is ready
add_action('plugins_loaded', array('PTP_Admin_Ajax', 'init'));
