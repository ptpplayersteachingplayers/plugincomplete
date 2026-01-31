<?php
/**
 * PTP Camp Emails - Email notifications for camp orders
 * 
 * Handles all camp-related email notifications without WooCommerce.
 * 
 * @version 146.0.0
 * @since 146.0.0
 */

defined('ABSPATH') || exit;

class PTP_Camp_Emails {
    
    private static $instance = null;
    
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Nothing to hook for now, all methods are static
    }
    
    /**
     * Send order confirmation email
     */
    public static function send_order_confirmation($order_id) {
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->billing_email;
        $subject = "PTP Camp Registration Confirmed - Order #{$order->order_number}";
        
        $html = self::get_email_header('Registration Confirmed!');
        
        $html .= '<p style="font-size: 16px; color: #333;">Hi ' . esc_html($order->billing_first_name) . ',</p>';
        $html .= '<p style="font-size: 16px; color: #333;">Thank you for registering for PTP Summer Camp! Your order has been confirmed and payment received.</p>';
        
        // Order summary
        $html .= '<div style="background: #f8f9fa; border-radius: 8px; padding: 20px; margin: 20px 0;">';
        $html .= '<h2 style="margin: 0 0 15px 0; color: #0A0A0A; font-size: 18px;">Order Summary</h2>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Order Number:</strong> ' . esc_html($order->order_number) . '</p>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Date:</strong> ' . date('F j, Y', strtotime($order->created_at)) . '</p>';
        $html .= '<p style="margin: 5px 0; color: #666;"><strong>Total:</strong> $' . number_format($order->total_amount, 2) . '</p>';
        $html .= '</div>';
        
        // Camper details
        $html .= '<h2 style="color: #0A0A0A; font-size: 18px; margin: 30px 0 15px 0;">Registered Campers</h2>';
        
        foreach ($order->items as $item) {
            $html .= '<div style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 15px; margin: 10px 0;">';
            $html .= '<h3 style="margin: 0 0 10px 0; color: #0A0A0A;">' . esc_html($item->camper_first_name . ' ' . $item->camper_last_name) . '</h3>';
            $html .= '<p style="margin: 5px 0; color: #666;"><strong>Camp:</strong> ' . esc_html($item->camp_name) . '</p>';
            if ($item->camp_dates) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Dates:</strong> ' . esc_html($item->camp_dates) . '</p>';
            }
            if ($item->camp_location) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Location:</strong> ' . esc_html($item->camp_location) . '</p>';
            }
            if ($item->camp_time) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Time:</strong> ' . esc_html($item->camp_time) . '</p>';
            }
            
            // Add-ons
            $addons = array();
            if ($item->care_bundle) $addons[] = 'Before + After Care';
            if ($item->jersey) $addons[] = 'Camp Jersey';
            if (!empty($addons)) {
                $html .= '<p style="margin: 5px 0; color: #666;"><strong>Add-ons:</strong> ' . implode(', ', $addons) . '</p>';
            }
            
            $html .= '</div>';
        }
        
        // What to bring
        $html .= '<div style="background: #FEF3C7; border-radius: 8px; padding: 20px; margin: 30px 0;">';
        $html .= '<h2 style="margin: 0 0 15px 0; color: #92400E; font-size: 18px;">What to Bring</h2>';
        $html .= '<ul style="margin: 0; padding-left: 20px; color: #92400E;">';
        $html .= '<li>Cleats (firm ground preferred)</li>';
        $html .= '<li>Shin guards</li>';
        $html .= '<li>Water bottle (labeled with name)</li>';
        $html .= '<li>Sunscreen (applied before arrival)</li>';
        $html .= '<li>Snacks for breaks</li>';
        $html .= '<li>Soccer ball (optional - we provide balls)</li>';
        $html .= '</ul>';
        $html .= '</div>';
        
        // Referral code
        if (!empty($order->referral_code_generated)) {
            $html .= '<div style="background: #FCB900; border-radius: 8px; padding: 20px; margin: 30px 0; text-align: center;">';
            $html .= '<h2 style="margin: 0 0 10px 0; color: #0A0A0A; font-size: 18px;">Share & Save!</h2>';
            $html .= '<p style="margin: 0 0 15px 0; color: #0A0A0A;">Give your friends $25 off their registration and you\'ll get $25 credit too!</p>';
            $html .= '<div style="background: #fff; display: inline-block; padding: 10px 30px; border-radius: 4px; font-size: 24px; font-weight: bold; font-family: monospace; letter-spacing: 2px;">' . esc_html($order->referral_code_generated) . '</div>';
            $html .= '</div>';
        }
        
        // Contact info
        $html .= '<p style="font-size: 14px; color: #666; margin-top: 30px;">Questions? Reply to this email or contact us at <a href="mailto:camps@ptpsummercamps.com" style="color: #FCB900;">camps@ptpsummercamps.com</a></p>';
        
        $html .= self::get_email_footer();
        
        return self::send($to, $subject, $html);
    }
    
    /**
     * Send reminder email (1 week before)
     */
    public static function send_camp_reminder($order_id, $days_before = 7) {
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->billing_email;
        $subject = "PTP Camp Reminder - See You Soon!";
        
        $html = self::get_email_header('Camp is Coming Up!');
        
        $html .= '<p style="font-size: 16px; color: #333;">Hi ' . esc_html($order->billing_first_name) . ',</p>';
        $html .= '<p style="font-size: 16px; color: #333;">Just a friendly reminder that camp is coming up in ' . $days_before . ' days!</p>';
        
        // Camper list
        $html .= '<h2 style="color: #0A0A0A; font-size: 18px; margin: 30px 0 15px 0;">Your Campers</h2>';
        
        foreach ($order->items as $item) {
            $html .= '<div style="background: #f8f9fa; border-radius: 8px; padding: 15px; margin: 10px 0;">';
            $html .= '<p style="margin: 0;"><strong>' . esc_html($item->camper_first_name) . '</strong> - ' . esc_html($item->camp_name) . '</p>';
            if ($item->camp_dates) {
                $html .= '<p style="margin: 5px 0 0 0; color: #666; font-size: 14px;">' . esc_html($item->camp_dates) . ' at ' . esc_html($item->camp_location) . '</p>';
            }
            $html .= '</div>';
        }
        
        // Check-in info
        $html .= '<div style="background: #DBEAFE; border-radius: 8px; padding: 20px; margin: 30px 0;">';
        $html .= '<h2 style="margin: 0 0 15px 0; color: #1E40AF; font-size: 18px;">Check-In Information</h2>';
        $html .= '<p style="margin: 0; color: #1E40AF;">Please arrive 15 minutes early on the first day for check-in. Look for the PTP tent at the main entrance.</p>';
        $html .= '</div>';
        
        $html .= self::get_email_footer();
        
        return self::send($to, $subject, $html);
    }
    
    /**
     * Send cancellation/refund email
     */
    public static function send_cancellation_email($order_id, $refund_amount = 0) {
        $order = PTP_Camp_Orders::get_order($order_id);
        if (!$order) {
            return false;
        }
        
        $to = $order->billing_email;
        $subject = "PTP Camp Registration Cancelled - Order #{$order->order_number}";
        
        $html = self::get_email_header('Registration Cancelled');
        
        $html .= '<p style="font-size: 16px; color: #333;">Hi ' . esc_html($order->billing_first_name) . ',</p>';
        $html .= '<p style="font-size: 16px; color: #333;">Your camp registration (Order #' . esc_html($order->order_number) . ') has been cancelled.</p>';
        
        if ($refund_amount > 0) {
            $html .= '<div style="background: #D1FAE5; border-radius: 8px; padding: 20px; margin: 20px 0;">';
            $html .= '<h2 style="margin: 0 0 10px 0; color: #065F46; font-size: 18px;">Refund Issued</h2>';
            $html .= '<p style="margin: 0; color: #065F46;">A refund of <strong>$' . number_format($refund_amount, 2) . '</strong> has been issued to your original payment method. Please allow 5-10 business days for the refund to appear.</p>';
            $html .= '</div>';
        }
        
        $html .= '<p style="font-size: 14px; color: #666; margin-top: 30px;">We hope to see you at a future camp! If you have any questions, please contact us at <a href="mailto:camps@ptpsummercamps.com" style="color: #FCB900;">camps@ptpsummercamps.com</a></p>';
        
        $html .= self::get_email_footer();
        
        return self::send($to, $subject, $html);
    }
    
    /**
     * Send email
     */
    private static function send($to, $subject, $html) {
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: PTP Soccer Camps <camps@ptpsummercamps.com>',
            'Reply-To: camps@ptpsummercamps.com',
        );
        
        $sent = wp_mail($to, $subject, $html, $headers);
        
        if (!$sent) {
            error_log("[PTP Camp Emails] Failed to send email to $to: $subject");
        } else {
            error_log("[PTP Camp Emails] Email sent to $to: $subject");
        }
        
        return $sent;
    }
    
    /**
     * Get email header HTML
     */
    private static function get_email_header($title = '') {
        $logo_url = PTP_PLUGIN_URL . 'assets/images/ptp-logo.png';
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif;">
    <table role="presentation" style="width: 100%; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" style="width: 100%; max-width: 600px; border-collapse: collapse; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background: #0A0A0A; padding: 30px; text-align: center;">
                            <img src="' . esc_url($logo_url) . '" alt="PTP Soccer Camps" style="max-width: 150px; height: auto;">
                        </td>
                    </tr>
                    
                    <!-- Gold accent bar -->
                    <tr>
                        <td style="background: #FCB900; height: 4px;"></td>
                    </tr>';
        
        if ($title) {
            $html .= '
                    <!-- Title -->
                    <tr>
                        <td style="padding: 30px 30px 0 30px; text-align: center;">
                            <h1 style="margin: 0; color: #0A0A0A; font-size: 28px; font-weight: 700;">' . esc_html($title) . '</h1>
                        </td>
                    </tr>';
        }
        
        $html .= '
                    <!-- Content -->
                    <tr>
                        <td style="padding: 30px;">';
        
        return $html;
    }
    
    /**
     * Get email footer HTML
     */
    private static function get_email_footer() {
        $html = '
                        </td>
                    </tr>
                    
                    <!-- Footer -->
                    <tr>
                        <td style="background: #f8f9fa; padding: 30px; text-align: center; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0 0 10px 0; color: #666; font-size: 14px;">
                                <a href="https://ptpsummercamps.com" style="color: #FCB900; text-decoration: none;">ptpsummercamps.com</a>
                            </p>
                            <p style="margin: 0; color: #999; font-size: 12px;">
                                PTP Soccer Camps | Philadelphia, PA<br>
                                &copy; ' . date('Y') . ' PTP Soccer Camps. All rights reserved.
                            </p>
                            <p style="margin: 15px 0 0 0;">
                                <a href="https://instagram.com/ptpsoccercamps" style="color: #666; text-decoration: none; margin: 0 10px;">Instagram</a>
                                <a href="https://facebook.com/ptpsoccercamps" style="color: #666; text-decoration: none; margin: 0 10px;">Facebook</a>
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        
        return $html;
    }
}

// Initialize
PTP_Camp_Emails::instance();
