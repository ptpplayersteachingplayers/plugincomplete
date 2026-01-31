<?php
/**
 * PTP Checkout v113 - Payment Fixes + Error Handling
 */
defined('ABSPATH') || exit;

global $wpdb;

// Config
$logo = 'https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png';
$stripe_mode = get_option('ptp_stripe_test_mode', true) ? 'test' : 'live';

// v117.2.5: More robust Stripe key retrieval - check multiple option names
$stripe_pk = get_option('ptp_stripe_' . $stripe_mode . '_publishable', '');
if (empty($stripe_pk)) {
    $stripe_pk = get_option('ptp_stripe_publishable_key', '');
}

$stripe_sk = get_option('ptp_stripe_' . $stripe_mode . '_secret', '');
if (empty($stripe_sk)) {
    $stripe_sk = get_option('ptp_stripe_secret_key', '');
}

// v117.2.5: Debug mode - set to true to see diagnostic info
// SECURITY: Debug mode only available to admins
$ptp_debug = isset($_GET['debug']) && $_GET['debug'] === '1' && current_user_can('manage_options');

// User
$user = wp_get_current_user();
$logged_in = is_user_logged_in();

// Saved data - with table existence check
$parent = null;
$players = array();
if ($logged_in && $wpdb) {
    // Check if tables exist first
    $parents_table = $wpdb->prefix . 'ptp_parents';
    $players_table = $wpdb->prefix . 'ptp_players';
    
    $parents_exists = $wpdb->get_var("SHOW TABLES LIKE '{$parents_table}'") === $parents_table;
    $players_exists = $wpdb->get_var("SHOW TABLES LIKE '{$players_table}'") === $players_table;
    
    if ($parents_exists) {
        $parent = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$parents_table} WHERE user_id = %d", $user->ID));
        if ($parent && $players_exists) {
            $players = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$players_table} WHERE parent_id = %d ORDER BY first_name", $parent->id));
        }
    }
}

// Cart
$items = array();
$subtotal = 0;
$has_camps = false;
$has_training = false;
$has_summer_camp = false; // v91: For jersey upsell

// WooCommerce items
if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
    foreach (WC()->cart->get_cart() as $key => $item) {
        $p = $item['data'];
        $pid = $item['product_id'];
        $price = floatval($p->get_price()) * $item['quantity'];
        $is_camp = get_post_meta($pid, '_ptp_is_camp', true) === 'yes';
        
        // Also detect camp by product name/category as fallback
        $product_name = strtolower($p->get_name());
        $cats = wp_get_post_terms($pid, 'product_cat', array('fields' => 'slugs'));
        
        if (!$is_camp && (strpos($product_name, 'camp') !== false || strpos($product_name, 'clinic') !== false)) {
            $is_camp = true;
        }
        
        // Determine type
        if ($is_camp) {
            $has_camps = true;
            // Check if summer camp for jersey upsell
            if (in_array('camps', $cats) || in_array('summer-camps', $cats) || strpos($product_name, 'camp') !== false) {
                $has_summer_camp = true;
            }
            $camp_type = get_post_meta($pid, '_ptp_camp_type', true);
            if (strpos($product_name, 'clinic') !== false) {
                $type = 'clinic';
            } else {
                $type = $camp_type ?: 'camp';
            }
        } else {
            $type = 'product';
        }
        
        $items[] = array(
            'k' => $key, 'src' => 'woo', 'id' => $pid, 'name' => $p->get_name(), 'type' => $type, 'price' => $price, 'qty' => $item['quantity'],
            'img' => wp_get_attachment_image_url($p->get_image_id(), 'thumbnail'),
            'date' => get_post_meta($pid, '_ptp_start_date', true),
            'loc' => get_post_meta($pid, '_ptp_location_name', true),
        );
        $subtotal += $price;
    }
}

// Training
$trainer = null;
$trainer_id = intval($_GET['trainer_id'] ?? 0);
$session_date = sanitize_text_field($_GET['date'] ?? '');
$session_time = sanitize_text_field($_GET['time'] ?? '');
$session_loc = sanitize_text_field($_GET['location'] ?? '');
$pkg_key = sanitize_text_field($_GET['package'] ?? 'single');
$group_size = intval($_GET['group_size'] ?? $_GET['spots'] ?? 1);
$group_size = max(1, min(10, $group_size)); // Clamp between 1-10 for group sessions

// v131: Load group session if specified
$group_session = null;
$group_session_id = intval($_GET['group_session_id'] ?? 0);
if ($group_session_id && $wpdb) {
    $group_table = $wpdb->prefix . 'ptp_group_sessions';
    $group_table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$group_table}'") === $group_table;
    if ($group_table_exists) {
        $group_session = $wpdb->get_row($wpdb->prepare(
            "SELECT g.*, t.display_name as trainer_name, t.photo_url as trainer_photo, t.hourly_rate, t.playing_level, t.slug as trainer_slug
             FROM {$group_table} g
             JOIN {$wpdb->prefix}ptp_trainers t ON g.trainer_id = t.id
             WHERE g.id = %d AND g.status IN ('open', 'confirmed')",
            $group_session_id
        ));
        if ($group_session) {
            $trainer_id = $group_session->trainer_id;
            $session_date = $group_session->session_date;
            $session_time = date('H:i', strtotime($group_session->start_time));
            $session_loc = $group_session->location;
            // If no spots specified, default to 1 (user can add more up to available)
            $available_spots = $group_session->max_players - $group_session->current_players;
            if ($group_size > $available_spots) {
                $group_size = max(1, $available_spots);
            }
        }
    }
}

// Group size multipliers (for private group training pricing)
$group_multipliers = array(1 => 1, 2 => 1.6, 3 => 2, 4 => 2.4, 5 => 2.8, 6 => 3.2, 7 => 3.5, 8 => 3.8, 9 => 4.0, 10 => 4.2);
$group_mult = $group_multipliers[$group_size] ?? (1 + ($group_size - 1) * 0.4);
$group_labels = array(1 => 'Solo', 2 => 'Duo', 3 => 'Trio', 4 => 'Quad', 5 => '5 Players', 6 => '6 Players', 7 => '7 Players', 8 => '8 Players', 9 => '9 Players', 10 => '10 Players');

// Determine if this is a multi-player checkout (group session)
$is_multi_player = $group_size > 1 || $group_session;

if ($trainer_id && $wpdb) {
    $trainers_table = $wpdb->prefix . 'ptp_trainers';
    $trainers_exists = $wpdb->get_var("SHOW TABLES LIKE '{$trainers_table}'") === $trainers_table;
    if ($trainers_exists) {
        $trainer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$trainers_table} WHERE id = %d AND status = 'active'", $trainer_id));
    }
}

$pkgs = array(
    'single' => array('n' => 'Single', 's' => 1, 'd' => 0),
    'pack3' => array('n' => '3-Pack', 's' => 3, 'd' => 10),
    'pack5' => array('n' => '5-Pack', 's' => 5, 'd' => 15),
);

if ($trainer) {
    $has_training = true;
    $rate = intval($trainer->hourly_rate ?: 60);
    
    // v131: If this is a trainer group session, use price_per_player pricing
    if ($group_session) {
        $price_per_player = floatval($group_session->price_per_player);
        $training_price = intval($price_per_player * $group_size);
        $group_rate = $training_price;
        
        // Update packages for group session (just single option)
        $pkgs = array(
            'single' => array('n' => $group_session->title ?: 'Group Session', 's' => 1, 'd' => 0, 'p' => $training_price, 'v' => 0),
        );
        $pkg_key = 'single';
        $sel = $pkgs['single'];
    } else {
        // Standard private training pricing with group multiplier
        $group_rate = intval($rate * $group_mult);
        
        foreach ($pkgs as $k => &$p) {
            $p['p'] = $k === 'single' ? $group_rate : intval($group_rate * $p['s'] * (1 - $p['d']/100));
            $p['v'] = $k === 'single' ? 0 : ($group_rate * $p['s']) - $p['p'];
        }
        unset($p);
        
        $sel = $pkgs[$pkg_key] ?? $pkgs['single'];
        $training_price = $sel['p'];
    }
    
    // Format time for display
    $time_display = '';
    if ($session_time) {
        $h = intval(explode(':', $session_time)[0]);
        $time_display = ($h > 12 ? $h - 12 : ($h ?: 12)) . ':00 ' . ($h >= 12 ? 'PM' : 'AM');
    }
    
    // Build name with group size
    $group_label = $group_size > 1 ? ' (' . $group_labels[$group_size] . ' - ' . $group_size . ' players)' : '';
    $item_name = $group_session ? $group_session->title . $group_label : $trainer->display_name . ' - ' . $sel['n'] . $group_label;
    
    $items[] = array(
        'k' => 'training_' . $trainer->id, 'src' => 'training', 'id' => $trainer->id,
        'name' => $item_name,
        'type' => $group_session ? 'group_session' : 'training', 
        'price' => $training_price, 'qty' => $sel['s'],
        'img' => $trainer->photo_url,
        'date' => $session_date, 'time' => $time_display, 'loc' => $session_loc,
        'pkg' => $pkg_key, 'rate' => $rate, 'group_size' => $group_size,
        'group_session_id' => $group_session_id,
        'price_per_player' => $group_session ? floatval($group_session->price_per_player) : ($group_size > 1 ? intval($group_rate / $group_size) : $rate),
    );
    $subtotal += $training_price;
    
    // Store in WC session so cart page can also access it
    if (function_exists('WC') && WC()->session) {
        // Ensure session is started
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
        
        $training_session = array(
            'trainer_id' => $trainer_id,
            'trainer_name' => $trainer->display_name . ' - ' . $sel['n'] . $group_label,
            'trainer_photo' => $trainer->photo_url,
            'trainer_level' => $trainer->level,
            'price' => $training_price,
            'package' => $pkg_key,
            'sessions' => $sel['s'],
            'date' => $session_date,
            'time' => $session_time,
            'location' => $session_loc,
            'group_size' => $group_size,
            'per_player_rate' => $group_size > 1 ? intval($group_rate / $group_size) : $rate,
        );
        
        // Store in both session keys for compatibility
        WC()->session->set('ptp_current_training', $training_session);
        
        // Also store in training_items array (cart checks this too)
        $training_items = WC()->session->get('ptp_training_items', array());
        $training_items['trainer_' . $trainer_id] = $training_session;
        WC()->session->set('ptp_training_items', $training_items);
    }
}

// Jersey upsell (v90) - Check session or URL param
$jersey_upsell_added = false;
$jersey_price = 50;
if (function_exists('WC') && WC()->session) {
    $jersey_upsell_added = WC()->session->get('ptp_jersey_upsell', false);
}
// Also check URL param
if (isset($_GET['jersey_upsell']) && $_GET['jersey_upsell'] === '1') {
    $jersey_upsell_added = true;
    // Store in session for persistence
    if (function_exists('WC') && WC()->session) {
        WC()->session->set('ptp_jersey_upsell', true);
    }
}

if ($jersey_upsell_added) {
    $items[] = array(
        'k' => 'jersey_upsell', 'src' => 'upsell', 'id' => 0,
        'name' => 'World Cup 2026 x PTP Jersey (2nd Jersey)',
        'type' => 'addon', 'price' => $jersey_price, 'qty' => 1,
        'img' => '',
        'date' => '', 'loc' => '',
    );
    $subtotal += $jersey_price;
}

// Bundle discount - DISABLED v117.2: Only one discount allowed at a time
$bundle_discount = 0;
// Previously: 15% off when ordering training package + camp
// $is_training_package = $has_training && in_array($pkg_key, array('pack3', 'pack5'));
// if ($is_training_package && $has_camps) {
//     $bundle_discount = round($subtotal * 0.15, 2);
// }

// v87: Processing fee (3% + $0.30)
$amount_after_discount = $subtotal - $bundle_discount;
$processing_fee = round(($amount_after_discount * 0.03) + 0.30, 2);

$total = $subtotal - $bundle_discount + $processing_fee;
$empty = empty($items);
$cents = intval(round($total * 100));

// Create PaymentIntent upfront for Stripe Elements
$client_secret = '';
$checkout_session_id = wp_generate_uuid4();
$pi_error = '';

// Build description from cart items for Stripe
$item_names = array();
foreach ($items as $item) {
    $item_names[] = $item['name'];
}
$stripe_description = implode(', ', array_slice($item_names, 0, 3));
if (count($items) > 3) {
    $stripe_description .= ' + ' . (count($items) - 3) . ' more';
}
if (empty($stripe_description)) {
    $stripe_description = 'PTP Soccer - Camp/Training Registration';
}

// Get customer email if logged in
$customer_email = '';
if ($logged_in && $user->user_email) {
    $customer_email = $user->user_email;
}

if (!$empty && $cents >= 50 && !empty($stripe_sk)) {
    $pi_data = array(
        'amount' => $cents,
        'currency' => 'usd',
        'payment_method_types[]' => 'card',
        'description' => $stripe_description,
        'metadata[checkout_session]' => $checkout_session_id,
        'metadata[source]' => 'ptp_checkout',
        'metadata[items]' => implode(', ', $item_names),
    );
    
    // v115.5.2: Removed receipt_email - PTP handles confirmation emails, not Stripe
    if ($customer_email) {
        $pi_data['metadata[customer_email]'] = $customer_email;
    }
    
    $pi_response = wp_remote_post('https://api.stripe.com/v1/payment_intents', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $stripe_sk,
            'Content-Type' => 'application/x-www-form-urlencoded',
        ),
        'body' => $pi_data,
        'timeout' => 30,
    ));
    
    if (is_wp_error($pi_response)) {
        $pi_error = 'Network error: ' . $pi_response->get_error_message();
        error_log('[PTP Checkout] PaymentIntent creation failed: ' . $pi_error);
    } else {
        $response_code = wp_remote_retrieve_response_code($pi_response);
        $pi_body = json_decode(wp_remote_retrieve_body($pi_response), true);
        
        if (!empty($pi_body['client_secret'])) {
            $client_secret = $pi_body['client_secret'];
        } else {
            $pi_error = $pi_body['error']['message'] ?? ('Stripe error (HTTP ' . $response_code . ')');
            error_log('[PTP Checkout] PaymentIntent error: ' . json_encode($pi_body));
        }
    }
} elseif ($empty) {
    $pi_error = 'Cart is empty - please add items before checkout';
} elseif ($cents < 50) {
    $pi_error = 'Order total ($' . number_format($total, 2) . ') is below the minimum';
} elseif (empty($stripe_sk)) {
    $pi_error = 'Stripe payment keys not configured - please contact support';
}

// v117.2.8: Handle payment_error from redirect (when payment fails on thank-you page)
$payment_error_from_redirect = isset($_GET['payment_error']) ? sanitize_text_field($_GET['payment_error']) : '';
if (!empty($payment_error_from_redirect)) {
    $pi_error = $payment_error_from_redirect;
}

$levels = array('pro'=>'MLS PRO','college_d1'=>'NCAA D1','college_d2'=>'NCAA D2','college_d3'=>'NCAA D3','academy'=>'ACADEMY');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="theme-color" content="#0E0F11">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Checkout - PTP Soccer</title>

<!-- Open Graph / Shareability -->
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo esc_url(home_url('/ptp-checkout/')); ?>">
<meta property="og:title" content="Checkout - PTP Soccer">
<meta property="og:description" content="Complete your PTP Soccer registration for camps, clinics, and private training.">
<meta property="og:image" content="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png">
<meta name="twitter:card" content="summary">

<link rel="preconnect" href="https://js.stripe.com">
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FCB900;--black:#0A0A0A;--white:#fff;--gray:#F5F5F5;--gray2:#E5E7EB;--gray4:#9CA3AF;--gray6:#4B5563;--green:#22C55E;--red:#EF4444;--rad:10px}
/* v133.2: Hide scrollbar */
html,body{scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar,body::-webkit-scrollbar{display:none;width:0}
body{font-family:system-ui,-apple-system,sans-serif;background:#0E0F11;color:var(--white);line-height:1.5;-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%}
h1,h2,h3{font-family:Oswald,system-ui,sans-serif;font-weight:700;text-transform:uppercase;line-height:1.1}
a{color:inherit;text-decoration:none;-webkit-tap-highlight-color:transparent}
input,select,textarea,button{-webkit-tap-highlight-color:transparent}
/* Main layout */
.page{display:grid;min-height:100vh;min-height:100dvh}
@media(min-width:900px){.page{grid-template-columns:1fr 420px}}
@media(min-width:1100px){.page{grid-template-columns:1fr 480px}}
@media(min-width:1300px){.page{grid-template-columns:1fr 520px}}
/* Form section */
.form{background:var(--white);color:var(--black);padding:16px 16px 160px;overflow-y:auto;-webkit-overflow-scrolling:touch}
@media(min-width:400px){.form{padding:20px 20px 140px}}
@media(min-width:600px){.form{padding:28px 32px 100px}}
@media(min-width:900px){.form{padding:40px 48px 60px}}
@media(min-width:1100px){.form{padding:40px 60px 60px}}
/* Header */
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid var(--gray2)}
@media(min-width:600px){.header{margin-bottom:24px;padding-bottom:16px}}
.logo{height:28px}
@media(min-width:600px){.logo{height:32px}}
.back{font-size:12px;color:var(--gray4);padding:8px;margin:-8px}
@media(min-width:600px){.back{font-size:13px}}
/* Title */
.title{font-size:clamp(20px,5vw,28px);margin-bottom:4px}
.subtitle{color:var(--gray4);font-size:12px}
@media(min-width:600px){.subtitle{font-size:13px}}
/* Express checkout */
.express{margin:20px 0;padding:16px;background:var(--gray);border-radius:var(--rad);transition:opacity 0.3s}
@media(min-width:600px){.express{margin:24px 0;padding:20px}}
.express-t{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--gray4);text-align:center;margin-bottom:10px}
@media(min-width:600px){.express-t{font-size:11px;margin-bottom:12px}}
#express-el{min-height:44px}
.express-locked{text-align:center;padding:14px;background:rgba(0,0,0,0.03);border-radius:8px;font-size:12px;color:var(--gray4)}
@media(min-width:600px){.express-locked{padding:16px;font-size:13px}}
.express.unlocked{opacity:1 !important;pointer-events:auto !important}
.express.unlocked .express-locked{display:none}
.express.unlocked #express-el{display:block !important}
/* Divider */
.divider{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--gray4);text-align:center;margin:16px 0;display:flex;align-items:center;gap:10px}
@media(min-width:600px){.divider{font-size:12px;margin:20px 0;gap:12px}}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:var(--gray2)}
/* Sections */
.sec{margin-bottom:20px}
@media(min-width:600px){.sec{margin-bottom:24px}}
.sec-h{display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap}
@media(min-width:600px){.sec-h{gap:10px;margin-bottom:14px}}
.sec-n{width:22px;height:22px;background:var(--black);color:var(--white);border-radius:50%;font-family:Oswald,sans-serif;font-size:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
@media(min-width:600px){.sec-n{width:24px;height:24px;font-size:12px}}
.sec-t{font-size:13px}
@media(min-width:600px){.sec-t{font-size:14px}}
.sec-opt{margin-left:auto;font-size:9px;font-weight:600;color:var(--gray4);text-transform:uppercase;letter-spacing:.05em}
@media(min-width:600px){.sec-opt{font-size:10px}}
.sec-b{margin-left:auto;font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:4px 8px;background:var(--gold);color:var(--black);border-radius:4px}
@media(min-width:600px){.sec-b{font-size:9px}}
/* Form fields */
.row{margin-bottom:10px}
@media(min-width:600px){.row{margin-bottom:12px}}
.grid{display:grid;grid-template-columns:1fr;gap:10px}
@media(min-width:450px){.grid{grid-template-columns:1fr 1fr}}
.label{display:block;font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--gray6);margin-bottom:4px}
@media(min-width:600px){.label{font-size:11px}}
.input,.select,.textarea{width:100%;padding:14px;font-size:16px;font-family:inherit;border:2px solid var(--gray2);border-radius:8px;background:var(--white);transition:.15s;-webkit-appearance:none;appearance:none}
@media(min-width:600px){.input,.select,.textarea{padding:12px 14px;font-size:14px}}
.input:focus,.select:focus,.textarea:focus{outline:none;border-color:var(--gold)}
.select{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 14px center}
.textarea{min-height:80px;resize:vertical}
.hint{font-size:10px;color:var(--gray4);margin-top:3px}
@media(min-width:600px){.hint{font-size:11px}}
/* Saved players */
.saved{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}
.saved-p{padding:12px 14px;border:2px solid var(--gray2);border-radius:8px;cursor:pointer;transition:.15s;display:flex;align-items:center;gap:8px;font-size:13px;flex:1;min-width:140px}
@media(min-width:600px){.saved-p{padding:10px 14px;flex:none}}
.saved-p:active,.saved-p.sel{border-color:var(--gold);background:rgba(252,185,0,.05)}
.saved-p .n{font-weight:600}
.saved-p .a{font-size:10px;color:var(--gray4)}
@media(min-width:600px){.saved-p .a{font-size:11px}}
.add-btn{padding:12px 14px;border:2px dashed var(--gray4);border-radius:8px;cursor:pointer;color:var(--gray4);font-size:13px;width:100%;text-align:center}
@media(min-width:600px){.add-btn{width:auto;padding:10px 14px}}
/* Package selector */
.pkgs{display:flex;flex-direction:column;gap:8px}
@media(min-width:600px){.pkgs{gap:6px}}
.pkg{display:flex;align-items:center;gap:10px;padding:14px 12px;border:2px solid var(--gray2);border-radius:8px;cursor:pointer;transition:.1s}
@media(min-width:600px){.pkg{padding:12px}}
.pkg:active,.pkg.sel{border-color:var(--gold);background:rgba(252,185,0,.03)}
.pkg input{width:18px;height:18px;accent-color:var(--gold);flex-shrink:0}
@media(min-width:600px){.pkg input{width:16px;height:16px}}
.pkg-i{flex:1;min-width:0}
.pkg-n{font-family:Oswald,sans-serif;font-size:13px;font-weight:600;text-transform:uppercase}
.pkg-d{font-size:11px;color:var(--gray4)}
.pkg-p{font-family:Oswald,sans-serif;font-size:16px;font-weight:700;white-space:nowrap}
.pkg-v{font-size:10px;color:var(--green)}
/* Recurring Sessions */
.recurring-box{margin-top:16px;border:2px solid var(--gray2);border-radius:10px;overflow:hidden;transition:border-color .2s}
.recurring-box.active{border-color:var(--gold);background:linear-gradient(135deg,#fffbeb 0%,#fff 100%)}
.recurring-toggle{display:flex;align-items:center;gap:12px;padding:14px;cursor:pointer;position:relative}
@media(min-width:600px){.recurring-toggle{padding:16px}}
.recurring-toggle input{display:none}
.recurring-check{width:22px;height:22px;border:2px solid var(--gray3);border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:all .15s}
.recurring-toggle input:checked + .recurring-check{background:var(--gold);border-color:var(--gold)}
.recurring-toggle input:checked + .recurring-check::after{content:'✓';color:var(--black);font-size:14px;font-weight:700}
.recurring-label{flex:1;min-width:0}
.recurring-label strong{display:block;font-size:14px;font-weight:600}
.recurring-label small{font-size:11px;color:var(--gray5)}
.recurring-badge{background:var(--green);color:#fff;font-size:9px;font-weight:700;padding:4px 8px;border-radius:4px;text-transform:uppercase;flex-shrink:0}
.recurring-options{padding:0 14px 14px;border-top:1px solid var(--gray2)}
@media(min-width:600px){.recurring-options{padding:0 16px 16px}}
.recurring-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px}
.recurring-field .label{margin-bottom:6px}
.recurring-freq{display:flex;gap:8px}
.freq-opt{flex:1;padding:10px;border:2px solid var(--gray2);border-radius:6px;text-align:center;cursor:pointer;font-size:13px;font-weight:500;transition:all .15s}
.freq-opt:hover{border-color:var(--gray3)}
.freq-opt.sel{border-color:var(--gold);background:rgba(252,185,0,.08)}
.freq-opt input{display:none}
.recurring-summary{margin-top:14px;padding:12px;background:rgba(252,185,0,.08);border-radius:8px}
.recurring-day,.recurring-total,.recurring-savings{display:flex;align-items:center;gap:8px;font-size:13px;margin-bottom:8px}
.recurring-day:last-child,.recurring-total:last-child,.recurring-savings:last-child{margin-bottom:0}
.recurring-day svg,.recurring-savings svg{width:16px;height:16px;color:var(--gold);flex-shrink:0}
.recurring-total{justify-content:space-between}
.recurring-total strong{font-family:Oswald,sans-serif;font-size:18px}
.recurring-savings{color:var(--green)}
.recurring-savings strong{font-weight:700}
/* Trainer card */
.trainer-card{display:flex;gap:12px;padding:12px;background:var(--gray);border-radius:8px;margin-bottom:14px}
.trainer-photo{width:48px;height:48px;border-radius:50%;border:2px solid var(--gold);overflow:hidden;flex-shrink:0}
@media(min-width:600px){.trainer-photo{width:50px;height:50px}}
.trainer-photo img{width:100%;height:100%;object-fit:cover}
.trainer-name{font-size:14px;font-weight:600}
.trainer-level{font-size:10px;color:var(--gold);font-weight:600}
.trainer-rate{font-size:12px;color:var(--gray4)}
/* Waiver */
.waiver{background:var(--gray);border-radius:8px;padding:14px;margin-bottom:12px}
@media(min-width:600px){.waiver{padding:16px}}
.waiver-t{font-size:13px;font-weight:700;margin-bottom:10px;font-family:Oswald,sans-serif;text-transform:uppercase}
@media(min-width:600px){.waiver-t{font-size:14px}}
.waiver-scroll{font-size:11px;color:var(--gray6);line-height:1.7;max-height:180px;overflow-y:auto;padding:12px;background:#fff;border:1px solid var(--gray2);border-radius:6px;margin-bottom:12px;-webkit-overflow-scrolling:touch}
@media(min-width:600px){.waiver-scroll{max-height:200px}}
.waiver-scroll h4{font-size:12px;font-weight:700;margin:12px 0 6px;color:var(--black)}
.waiver-scroll h4:first-child{margin-top:0}
.waiver-scroll p{margin-bottom:8px}
.waiver-chk{display:flex;align-items:flex-start;gap:10px;padding-top:12px;border-top:1px solid var(--gray2)}
.waiver-chk input{width:20px;height:20px;margin-top:2px;accent-color:var(--gold);flex-shrink:0}
@media(min-width:600px){.waiver-chk input{width:18px;height:18px}}
.waiver-chk label{font-size:13px;cursor:pointer}
/* Discount Sections */
.discount-sec{background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);border:2px solid var(--gold);border-radius:10px;padding:14px;margin-bottom:14px}
@media(min-width:600px){.discount-sec{padding:16px;margin-bottom:16px}}
.discount-sec-t{font-family:Oswald,sans-serif;font-size:13px;font-weight:700;text-transform:uppercase;margin-bottom:10px;display:flex;align-items:center;gap:8px}
@media(min-width:600px){.discount-sec-t{font-size:14px}}
.discount-sec-t svg{width:16px;height:16px}
@media(min-width:600px){.discount-sec-t svg{width:18px;height:18px}}
.discount-row{display:flex;flex-direction:column;gap:8px;margin-bottom:10px}
@media(min-width:500px){.discount-row{flex-direction:row;gap:10px;align-items:center}}
.discount-row:last-child{margin-bottom:0}
.discount-input{width:100%;padding:12px;border:2px solid var(--gray2);border-radius:6px;font-size:16px;-webkit-appearance:none}
@media(min-width:500px){.discount-input{flex:1}}
@media(min-width:600px){.discount-input{padding:10px 12px;font-size:14px}}
.discount-btn{width:100%;padding:12px 16px;background:var(--gold);color:var(--black);border:none;border-radius:6px;font-family:Oswald,sans-serif;font-size:13px;font-weight:600;cursor:pointer}
@media(min-width:500px){.discount-btn{width:auto}}
.discount-btn:hover{background:#e5a800}
.discount-opt{display:flex;align-items:flex-start;gap:10px;padding:12px;background:#fff;border-radius:6px;cursor:pointer;transition:.15s}
.discount-opt:hover,.discount-opt:active{background:rgba(252,185,0,.1)}
.discount-opt input{width:20px;height:20px;accent-color:var(--gold);flex-shrink:0;margin-top:2px}
@media(min-width:600px){.discount-opt input{width:18px;height:18px}}
.discount-opt label{font-size:13px;cursor:pointer;flex:1}
.discount-opt .save{font-size:11px;color:var(--green);font-weight:600;white-space:nowrap}
.discount-applied{background:rgba(34,197,94,.1);border:1px solid var(--green);border-radius:6px;padding:10px 12px;font-size:12px;color:var(--green);display:flex;align-items:center;gap:6px}
@media(min-width:600px){.discount-applied{padding:8px 12px}}
/* Before/After Care Addon */
.addon-sec{background:var(--white);border:2px solid var(--gray2);border-radius:10px;margin-bottom:12px;overflow:hidden}
@media(min-width:600px){.addon-sec{border-radius:12px;margin-bottom:16px}}
.care-addon{border-color:#dbeafe;background:linear-gradient(135deg,#eff6ff 0%,#dbeafe 100%)}
.addon-check{display:block}
.addon-check input{display:none}
.addon-check label{display:flex;align-items:center;gap:10px;padding:12px 14px;cursor:pointer;transition:all 0.2s}
@media(min-width:600px){.addon-check label{gap:14px;padding:16px 18px}}
.addon-check input:checked + label{background:rgba(59,130,246,0.1)}
.addon-icon{font-size:22px;flex-shrink:0}
@media(min-width:600px){.addon-icon{font-size:26px}}
.addon-info{flex:1;min-width:0}
.addon-title{display:block;font-family:Oswald,sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;color:var(--black)}
@media(min-width:600px){.addon-title{font-size:14px}}
.addon-desc{display:block;font-size:10px;color:var(--gray5);margin-top:1px}
@media(min-width:600px){.addon-desc{font-size:12px;margin-top:2px}}
.addon-price{font-family:Oswald,sans-serif;font-size:16px;font-weight:700;color:#2563eb;flex-shrink:0}
@media(min-width:600px){.addon-price{font-size:18px}}
.addon-check input:checked + label .addon-price{color:#16a34a}
.addon-check label::before{content:'';width:20px;height:20px;border:2px solid var(--gray3);border-radius:5px;flex-shrink:0;transition:all 0.15s;background:var(--white)}
@media(min-width:600px){.addon-check label::before{width:24px;height:24px;border-radius:6px}}
.addon-check input:checked + label::before{background:#2563eb;border-color:#2563eb;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 16 16' fill='white' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M13.78 4.22a.75.75 0 010 1.06l-7.25 7.25a.75.75 0 01-1.06 0L2.22 9.28a.75.75 0 011.06-1.06L6 10.94l6.72-6.72a.75.75 0 011.06 0z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:center}
/* Upgrade Section - Research-Based Price Anchoring */
.upgrade-sec{background:var(--white) !important;border:2px solid var(--black) !important;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,0.06)}
.upgrade-header{display:flex;align-items:center;gap:10px;padding:14px 16px;background:var(--black)}
@media(min-width:600px){.upgrade-header{gap:14px;padding:18px 20px}}
.upgrade-header-icon{width:36px;height:36px;background:var(--gold);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
@media(min-width:600px){.upgrade-header-icon{width:44px;height:44px;border-radius:12px}}
.upgrade-header-icon svg{width:18px;height:18px;stroke:var(--black)}
@media(min-width:600px){.upgrade-header-icon svg{width:24px;height:24px}}
.upgrade-header-text{flex:1}
.upgrade-header-title{font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;color:var(--white);letter-spacing:0.02em}
@media(min-width:600px){.upgrade-header-title{font-size:20px}}
.upgrade-header-sub{margin-top:4px}
.upgrade-social-proof{font-size:13px;color:var(--gold);font-weight:500}
@media(min-width:600px){.upgrade-social-proof{font-size:14px}}
.upgrade-opts{padding:14px}
@media(min-width:600px){.upgrade-opts{padding:20px}}
.upgrade-anchor{background:var(--gray);border-radius:8px;padding:10px 12px;margin-bottom:12px;display:flex;justify-content:space-between;align-items:center}
@media(min-width:600px){.upgrade-anchor{padding:12px 16px;margin-bottom:16px;border-radius:10px}}
.upgrade-anchor-label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray5)}
@media(min-width:600px){.upgrade-anchor-label{font-size:10px}}
.upgrade-anchor-row{display:flex;align-items:baseline;gap:6px}
.upgrade-anchor-item{font-size:11px;color:var(--gray6)}
@media(min-width:600px){.upgrade-anchor-item{font-size:13px}}
.upgrade-anchor-price{font-family:Oswald,sans-serif;font-size:18px;font-weight:700;color:var(--black)}
@media(min-width:600px){.upgrade-anchor-price{font-size:20px}}
.upgrade-opt{display:flex;align-items:center;gap:10px;padding:12px;background:var(--white);border:2px solid var(--gray2);border-radius:10px;margin-bottom:10px;cursor:pointer;transition:all 0.2s;position:relative}
@media(min-width:600px){.upgrade-opt{gap:14px;padding:16px;border-radius:12px;margin-bottom:12px}}
.upgrade-opt:hover{border-color:var(--gray3);transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,0.06)}
.upgrade-opt.selected{background:#f0fdf4;border-color:var(--green);box-shadow:0 0 0 3px rgba(34,197,94,0.15)}
.upgrade-opt input[type="radio"]{width:20px;height:20px;accent-color:var(--green);flex-shrink:0;cursor:pointer}
@media(min-width:600px){.upgrade-opt input[type="radio"]{width:24px;height:24px}}
.upgrade-opt-info{flex:1;min-width:0}
.upgrade-opt-row{margin-bottom:2px}
@media(min-width:600px){.upgrade-opt-row{margin-bottom:4px}}
.upgrade-opt-title{font-family:Oswald,sans-serif;font-size:14px;font-weight:700;text-transform:uppercase;color:var(--black)}
@media(min-width:600px){.upgrade-opt-title{font-size:18px}}
.upgrade-opt-desc{font-size:11px;color:var(--gray5);line-height:1.3}
@media(min-width:600px){.upgrade-opt-desc{font-size:13px;line-height:1.4}}
.upgrade-opt-price{text-align:right;flex-shrink:0}
.upgrade-opt-anchor{display:block;font-family:Oswald,sans-serif;font-size:14px;color:var(--gray4);text-decoration:line-through;line-height:1}
@media(min-width:600px){.upgrade-opt-anchor{font-size:20px}}
.upgrade-opt-amount{display:block;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;color:var(--black);margin-top:2px}
@media(min-width:600px){.upgrade-opt-amount{font-size:18px}}
.upgrade-opt-save{display:inline-block;font-size:9px;font-weight:700;color:#fff;background:var(--green);padding:3px 8px;border-radius:3px;margin-top:4px;letter-spacing:0.02em}
@media(min-width:600px){.upgrade-opt-save{font-size:11px;padding:4px 10px;border-radius:4px;margin-top:6px}}
.upgrade-popular{border:3px solid #f59e0b;background:linear-gradient(135deg,#fffbeb 0%,#fef3c7 100%);transform:scale(1.01)}
.upgrade-popular:hover{transform:scale(1.02)}
.upgrade-popular.selected{border-color:#f59e0b;background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);box-shadow:0 0 0 3px rgba(245,158,11,0.2)}
.upgrade-popular-badge{position:absolute;top:-10px;left:50%;transform:translateX(-50%);font-family:Oswald,sans-serif;font-size:9px;font-weight:700;background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;padding:4px 12px;border-radius:20px;white-space:nowrap;letter-spacing:0.04em}
@media(min-width:600px){.upgrade-popular-badge{font-size:11px;padding:5px 14px;top:-12px}}
.upgrade-popular .upgrade-opt-save{background:linear-gradient(135deg,#f59e0b,#d97706)}
.upgrade-premium{border:3px solid var(--gold);background:linear-gradient(135deg,#0a0a0a 0%,#1f1f1f 100%);margin-top:16px}
@media(min-width:600px){.upgrade-premium{margin-top:20px}}
.upgrade-premium:hover{transform:translateY(-2px);box-shadow:0 8px 30px rgba(252,185,0,0.2)}
.upgrade-premium.selected{background:linear-gradient(135deg,#0a0a0a 0%,#1f1f1f 100%) !important;border-color:var(--gold) !important;box-shadow:0 0 0 4px rgba(252,185,0,0.3),0 8px 30px rgba(252,185,0,0.15)}
.upgrade-premium-badge{position:absolute;top:-10px;left:16px;font-family:Oswald,sans-serif;font-size:9px;font-weight:700;background:var(--gold);color:var(--black);padding:4px 10px;border-radius:4px;letter-spacing:0.04em}
@media(min-width:600px){.upgrade-premium-badge{font-size:11px;padding:5px 12px;top:-12px;left:20px}}
.upgrade-premium .upgrade-opt-title{color:var(--gold);font-size:16px}
@media(min-width:600px){.upgrade-premium .upgrade-opt-title{font-size:22px}}
.upgrade-premium .upgrade-opt-desc{color:rgba(255,255,255,0.7);font-size:10px}
@media(min-width:600px){.upgrade-premium .upgrade-opt-desc{font-size:13px}}
.upgrade-premium .upgrade-opt-anchor{color:rgba(255,255,255,0.5);font-size:12px}
@media(min-width:600px){.upgrade-premium .upgrade-opt-anchor{font-size:18px}}
.upgrade-premium .upgrade-opt-amount{color:var(--gold);font-size:22px}
@media(min-width:600px){.upgrade-premium .upgrade-opt-amount{font-size:30px}}
.upgrade-premium .upgrade-opt-save{background:var(--green);font-size:10px}
@media(min-width:600px){.upgrade-premium .upgrade-opt-save{font-size:12px;padding:5px 12px}}
.upgrade-premium input[type="radio"]{accent-color:var(--gold)}
.upgrade-value-stack{display:flex;flex-wrap:wrap;gap:4px;margin-top:8px}
@media(min-width:600px){.upgrade-value-stack{gap:6px;margin-top:10px}}
.upgrade-value-stack span{font-size:8px;color:rgba(255,255,255,0.6);background:rgba(255,255,255,0.1);padding:3px 6px;border-radius:3px}
@media(min-width:600px){.upgrade-value-stack span{font-size:10px;padding:4px 8px;border-radius:4px}}
.upgrade-note{text-align:center;padding:10px 16px 16px;font-size:11px;color:var(--gray5)}
@media(min-width:600px){.upgrade-note{padding:12px 20px 20px;font-size:12px}}
.upgrade-note a{color:var(--gold);font-weight:600;text-decoration:none}
.upgrade-note a:hover{text-decoration:underline}
/* Camp Picker Modal */
.camp-picker-modal{position:fixed;top:0;left:0;right:0;bottom:0;z-index:9999;display:flex;align-items:center;justify-content:center;padding:12px}
@media(min-width:600px){.camp-picker-modal{padding:20px}}
.camp-picker-overlay{position:absolute;inset:0;background:rgba(0,0,0,0.7);backdrop-filter:blur(4px)}
.camp-picker-content{position:relative;background:var(--white);border-radius:12px;width:100%;max-width:500px;max-height:85vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,0.3)}
@media(min-width:600px){.camp-picker-content{border-radius:16px;max-height:80vh}}
.camp-picker-close{position:absolute;top:10px;right:10px;width:32px;height:32px;border:none;background:var(--gray);border-radius:50%;font-size:18px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--gray5);z-index:1}
@media(min-width:600px){.camp-picker-close{top:12px;right:12px;width:36px;height:36px;font-size:20px}}
.camp-picker-close:hover{background:var(--gray2);color:var(--black)}
.camp-picker-title{font-family:Oswald,sans-serif;font-size:18px;font-weight:700;text-transform:uppercase;color:var(--black);padding:18px 18px 6px;margin:0}
@media(min-width:600px){.camp-picker-title{font-size:22px;padding:24px 24px 8px}}
.camp-picker-subtitle{font-size:12px;color:var(--gray5);padding:0 18px 14px;margin:0;border-bottom:1px solid var(--gray2)}
@media(min-width:600px){.camp-picker-subtitle{font-size:14px;padding:0 24px 16px}}
.camp-picker-list{flex:1;overflow-y:auto;padding:12px}
@media(min-width:600px){.camp-picker-list{padding:16px}}
.camp-picker-item{display:flex;align-items:center;gap:10px;padding:12px;background:var(--gray);border:2px solid transparent;border-radius:8px;margin-bottom:8px;cursor:pointer;transition:all 0.15s}
@media(min-width:600px){.camp-picker-item{gap:12px;padding:14px 16px;border-radius:10px;margin-bottom:10px}}
.camp-picker-item:hover{border-color:var(--gray3)}
.camp-picker-item.selected{background:#f0fdf4;border-color:var(--green)}
.camp-picker-item input{display:none}
.camp-picker-info{flex:1;min-width:0}
.camp-picker-name{display:block;font-family:Oswald,sans-serif;font-size:12px;font-weight:600;text-transform:uppercase;color:var(--black)}
@media(min-width:600px){.camp-picker-name{font-size:14px}}
.camp-picker-meta{display:block;font-size:10px;color:var(--gray5);margin-top:2px}
@media(min-width:600px){.camp-picker-meta{font-size:12px}}
.camp-picker-check{width:24px;height:24px;border:2px solid var(--gray3);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;color:transparent;flex-shrink:0;transition:all 0.15s}
@media(min-width:600px){.camp-picker-check{width:28px;height:28px;font-size:16px}}
.camp-picker-item.selected .camp-picker-check{background:var(--green);border-color:var(--green);color:#fff}
.camp-picker-empty{text-align:center;padding:30px 16px;color:var(--gray5);font-size:13px}
@media(min-width:600px){.camp-picker-empty{padding:40px 20px;font-size:14px}}
.camp-picker-footer{display:flex;justify-content:space-between;align-items:center;padding:12px 18px;border-top:1px solid var(--gray2);background:var(--gray)}
@media(min-width:600px){.camp-picker-footer{padding:16px 24px}}
.camp-picker-selected{font-size:12px;color:var(--gray6)}
@media(min-width:600px){.camp-picker-selected{font-size:14px}}
.camp-picker-selected span{font-weight:700;color:var(--black)}
.camp-picker-confirm{font-family:Oswald,sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;background:var(--gold);color:var(--black);border:none;padding:10px 18px;border-radius:6px;cursor:pointer;transition:all 0.15s}
@media(min-width:600px){.camp-picker-confirm{font-size:14px;padding:12px 24px;border-radius:8px}}
.camp-picker-confirm:hover:not(:disabled){background:#e5a800}
.camp-picker-confirm:disabled{opacity:0.5;cursor:not-allowed}
/* Team Roster Cards */
.team-player-card{background:var(--gray);border:1px solid var(--gray2);border-radius:8px;padding:12px;margin-bottom:10px;position:relative}
.team-player-card:last-child{margin-bottom:0}
.team-player-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.team-player-num{font-weight:700;font-size:13px;color:var(--black)}
.team-player-remove{font-size:12px;color:var(--red);cursor:pointer;padding:6px;margin:-6px}
.team-player-remove:hover,.team-player-remove:active{text-decoration:underline}
.team-player-fields{display:grid;grid-template-columns:1fr;gap:8px}
@media(min-width:450px){.team-player-fields{grid-template-columns:1fr 1fr}}
.team-player-fields .input,.team-player-fields .select{padding:10px;font-size:16px}
@media(min-width:600px){.team-player-fields .input,.team-player-fields .select{padding:8px 10px;font-size:13px}}
.team-player-fields label{font-size:10px;font-weight:600;text-transform:uppercase;color:var(--gray6);margin-bottom:3px;display:block}
@media(min-width:600px){.team-player-fields label{font-size:11px}}
/* Payment */
#pay-el{min-height:80px;padding:14px;border:2px solid var(--gray2);border-radius:8px}
.pay-err{color:var(--red);font-size:12px;margin-top:8px;display:none}
/* Submit button */
.submit{width:100%;padding:18px;background:var(--gold);color:var(--black);border:none;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;border-radius:10px;cursor:pointer;margin-top:16px;transition:.15s}
@media(min-width:600px){.submit{padding:16px;margin-top:20px}}
.submit:active{background:#e5a800}
.submit:disabled{opacity:.5;cursor:not-allowed}
.submit .spin{display:none;width:18px;height:18px;border:2px solid transparent;border-top-color:var(--black);border-radius:50%;animation:spin 1s linear infinite;margin-right:8px;vertical-align:middle}
.submit.loading .spin{display:inline-block}
@keyframes spin{to{transform:rotate(360deg)}}
/* Trust badges */
.trust{display:flex;justify-content:center;gap:12px;margin-top:14px;flex-wrap:wrap}
@media(min-width:600px){.trust{gap:16px;margin-top:16px}}
.trust span{font-size:10px;color:var(--gray4);display:flex;align-items:center;gap:4px}
@media(min-width:600px){.trust span{font-size:11px}}
/* Order Summary */
.summary{background:linear-gradient(180deg,#0E0F11 0%,#1a1b1e 100%);padding:16px 14px;display:none}
@media(min-width:600px){.summary{padding:20px 18px}}
@media(min-width:900px){.summary{display:block;position:sticky;top:0;height:100vh;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:28px 24px}}
@media(min-width:1100px){.summary{padding:32px 28px}}
.summary.open{display:block;position:fixed;top:0;left:0;right:0;bottom:70px;z-index:99;overflow-y:auto;-webkit-overflow-scrolling:touch}
.sum-t{font-size:10px;color:var(--gray4);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid rgba(255,255,255,.1);letter-spacing:0.08em}
@media(min-width:600px){.sum-t{font-size:11px;margin-bottom:16px;padding-bottom:10px}}
@media(min-width:900px){.sum-t{font-size:13px;margin-bottom:20px;padding-bottom:14px}}
.sum-item{display:flex;gap:8px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06);align-items:center}
@media(min-width:600px){.sum-item{gap:10px;padding:12px 0}}
@media(min-width:900px){.sum-item{gap:14px;padding:16px 0}}
.sum-item:last-of-type{border:none}
.sum-img{width:36px;height:36px;border-radius:6px;overflow:hidden;background:#333;flex-shrink:0}
@media(min-width:600px){.sum-img{width:40px;height:40px}}
@media(min-width:900px){.sum-img{width:52px;height:52px;border-radius:8px}}
.sum-img img{width:100%;height:100%;object-fit:cover}
.sum-info{flex:1;min-width:0}
.sum-type{font-size:7px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:2px 5px;border-radius:2px;display:inline-block;margin-bottom:3px}
@media(min-width:600px){.sum-type{font-size:8px;padding:3px 6px}}
.sum-type.camp{background:rgba(252,185,0,.2);color:var(--gold)}
.sum-type.clinic{background:rgba(59,130,246,.2);color:#60A5FA}
.sum-type.training{background:rgba(34,197,94,.2);color:#4ADE80}
.sum-type.product{background:rgba(156,163,175,.2);color:#9CA3AF}
.sum-type.addon{background:rgba(139,92,246,.2);color:#A78BFA}
/* v91: Jersey Upsell */
.jersey-upsell{background:linear-gradient(135deg,#1a1b1e 0%,#252629 100%);border:2px solid var(--gold);border-radius:8px;padding:10px 12px;margin:10px 0}
.jersey-upsell-badge{display:none}
.jersey-upsell-content{display:flex;gap:8px;align-items:center}
.jersey-upsell-img{width:28px;height:28px;background:var(--gold);border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.jersey-upsell-img svg{width:14px;height:14px;color:var(--black)}
.jersey-upsell-info{flex:1;min-width:0}
.jersey-upsell-title{font-family:Oswald,sans-serif;font-size:10px;font-weight:600;text-transform:uppercase;color:var(--white);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jersey-upsell-desc{display:none}
.jersey-upsell-price{display:none}
.jersey-upsell-right{display:flex;align-items:center;gap:8px;flex-shrink:0}
.jersey-upsell-cost{font-family:Oswald,sans-serif;font-size:14px;font-weight:700;color:var(--gold)}
.jersey-upsell-was{font-size:9px;color:var(--gray4);text-decoration:line-through}
.jersey-upsell-btn{padding:5px 10px;background:var(--gold);color:var(--black);border:none;border-radius:4px;font-family:Oswald,sans-serif;font-size:9px;font-weight:700;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;gap:3px;transition:.15s;white-space:nowrap}
.jersey-upsell-btn:hover{background:#e5a800}
.jersey-upsell-btn:active{transform:scale(.98)}
.jersey-upsell-btn svg{width:10px;height:10px}
.jersey-upsell-btn.added{background:rgba(34,197,94,.15);color:var(--green);border:1px solid var(--green)}
/* v91: Jersey in summary when added */
.sum-jersey{display:flex;gap:6px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.06);align-items:center}
.sum-jersey-img{width:24px;height:24px;background:var(--gold);border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.sum-jersey-img svg{width:12px;height:12px;color:var(--black)}
.sum-jersey-info{flex:1;min-width:0}
.sum-jersey-remove{font-size:14px;color:#EF4444;cursor:pointer;padding:2px 6px;margin-left:4px;font-weight:bold}
/* Upgrade camps in summary */
.sum-upgrade-header{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:var(--gold);padding:10px 0 6px;border-top:1px solid rgba(255,255,255,.08);margin-top:8px}
.sum-upgrade-item{display:flex;gap:6px;padding:6px 0;align-items:center}
.sum-upgrade-icon{width:20px;height:20px;background:var(--green);border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:10px;color:#fff}
.sum-upgrade-info{flex:1;min-width:0}
.sum-upgrade-name{font-size:10px;font-weight:500;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.sum-upgrade-meta{font-size:8px;color:var(--gray4)}
.sum-name{font-size:11px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
@media(min-width:600px){.sum-name{font-size:12px}}
@media(min-width:900px){.sum-name{font-size:14px;white-space:normal}}
.sum-meta{font-size:9px;color:var(--gray4)}
@media(min-width:600px){.sum-meta{font-size:10px}}
@media(min-width:900px){.sum-meta{font-size:12px}}
.sum-group{font-size:10px;color:#16a34a;font-weight:600;margin-top:2px}
@media(min-width:600px){.sum-group{font-size:11px}}
@media(min-width:900px){.sum-group{font-size:12px}}
.sum-price{font-family:Oswald,sans-serif;font-size:13px;font-weight:600}
@media(min-width:600px){.sum-price{font-size:14px}}
@media(min-width:900px){.sum-price{font-size:18px}}
/* Summary totals */
.sum-totals{margin-top:12px;padding-top:12px;border-top:1px solid rgba(255,255,255,.1)}
@media(min-width:600px){.sum-totals{margin-top:16px;padding-top:14px}}
@media(min-width:900px){.sum-totals{margin-top:24px;padding-top:20px}}
.sum-line{display:flex;justify-content:space-between;padding:4px 0;font-size:11px}
@media(min-width:600px){.sum-line{padding:5px 0;font-size:12px}}
@media(min-width:900px){.sum-line{padding:8px 0;font-size:14px}}
.sum-line.disc{color:var(--green)}
.sum-line.fee{color:rgba(255,255,255,.6);font-size:10px}
@media(min-width:600px){.sum-line.fee{font-size:11px}}
@media(min-width:900px){.sum-line.fee{font-size:13px}}
.sum-total{display:flex;justify-content:space-between;padding-top:10px;margin-top:6px;border-top:1px solid rgba(255,255,255,.1);font-family:Oswald,sans-serif;font-size:18px;font-weight:700}
@media(min-width:600px){.sum-total{padding-top:12px;font-size:20px}}
@media(min-width:900px){.sum-total{padding-top:16px;font-size:26px;margin-top:10px}}
/* Bundle banner */
.bundle{background:linear-gradient(135deg,var(--gold) 0%,#FFD54F 100%);color:var(--black);padding:10px;border-radius:8px;margin-bottom:12px;font-size:11px}
@media(min-width:600px){.bundle{margin-bottom:16px;font-size:12px}}
.bundle strong{font-weight:700}
/* Guarantee */
.guarantee{background:rgba(34,197,94,.08);border:1px solid rgba(34,197,94,.15);border-radius:8px;padding:10px;margin-top:12px}
@media(min-width:600px){.guarantee{padding:12px;margin-top:16px}}
.guarantee h4{font-size:10px;color:var(--green);margin-bottom:2px;font-weight:600}
@media(min-width:600px){.guarantee h4{font-size:11px}}
.guarantee p{font-size:9px;color:var(--gray4);line-height:1.4;margin:0}
@media(min-width:600px){.guarantee p{font-size:10px}}
/* Mobile bar - v91: Conversion optimized */
.mobile-bar{display:flex;position:fixed;bottom:0;left:0;right:0;background:var(--black);padding:10px 12px;padding-bottom:calc(10px + env(safe-area-inset-bottom,0px));z-index:100;box-shadow:0 -4px 20px rgba(0,0,0,.4);align-items:center;gap:10px;border-top:2px solid var(--gold)}
@media(min-width:600px){.mobile-bar{padding:12px 16px;padding-bottom:calc(12px + env(safe-area-inset-bottom,0px));gap:12px}}
@media(min-width:900px){.mobile-bar{display:none}}
.mobile-toggle{display:flex;align-items:center;gap:4px;color:var(--gray4);font-size:11px;cursor:pointer;padding:6px;margin:-6px;flex-shrink:0}
@media(min-width:600px){.mobile-toggle{font-size:12px}}
.mobile-info{flex:1;min-width:0}
.mobile-items{font-size:10px;color:var(--gray4);margin-bottom:2px}
@media(min-width:600px){.mobile-items{font-size:11px}}
.mobile-total{font-family:Oswald,sans-serif;font-size:20px;font-weight:700;color:var(--white)}
@media(min-width:600px){.mobile-total{font-size:22px}}
.mobile-cta{background:var(--gold);color:var(--black);padding:12px 16px;border-radius:8px;font-family:Oswald,sans-serif;font-size:12px;font-weight:700;text-transform:uppercase;cursor:pointer;border:none;white-space:nowrap;transition:.15s}
@media(min-width:600px){.mobile-cta{padding:12px 20px;font-size:13px}}
.mobile-cta:active{background:#e5a800;transform:scale(.98)}
/* Empty state */
.empty{display:flex;align-items:center;justify-content:center;min-height:100vh;min-height:100dvh;text-align:center;padding:40px 20px}
.empty h2{font-size:20px;margin:16px 0 10px;color:var(--gray6)}
@media(min-width:600px){.empty h2{font-size:22px;margin:20px 0 10px}}
.empty p{color:var(--gray4);margin-bottom:20px;font-size:14px}
@media(min-width:600px){.empty p{margin-bottom:24px}}
.empty a{display:inline-block;padding:14px 24px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-weight:700;text-transform:uppercase;border-radius:8px}
@media(min-width:600px){.empty a{padding:14px 28px}}
</style>
</head>
<body style="margin: 0; padding: 0; overflow-y: scroll !important; height: auto !important; position: static !important;">
<script>
// v133.2.1: Force scroll to work
(function(){
    document.documentElement.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important;';
    document.body.style.cssText = 'overflow-y: scroll !important; height: auto !important; position: static !important; margin: 0; padding: 0;';
    document.body.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
    document.documentElement.classList.remove('modal-open', 'menu-open', 'no-scroll', 'overflow-hidden');
})();
</script>
<div id="ptp-scroll-wrapper" style="width: 100%;">

<?php if ($stripe_mode === 'test'): ?>
<div style="background:#ff9800;color:#000;padding:10px;text-align:center;font-family:sans-serif;font-size:14px;font-weight:600;">
    ⚠️ TEST MODE - No real charges will be made. <a href="<?php echo admin_url('admin.php?page=ptp-settings'); ?>" style="color:#000;text-decoration:underline;">Switch to Live Mode</a>
</div>
<?php endif; ?>

<?php if ($ptp_debug): ?>
<div style="background:#1e1e2e;color:#cdd6f4;padding:20px;font-family:monospace;font-size:12px;line-height:1.6;margin:10px;border-radius:8px;border:2px solid #f38ba8;">
    <h3 style="color:#f9e2af;margin:0 0 12px;font-size:14px;font-family:sans-serif;">🔧 PTP CHECKOUT DEBUG (v117.2.4)</h3>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;">
        <div>
            <strong style="color:#89b4fa;">Stripe Config:</strong><br>
            Mode: <span style="color:<?php echo $stripe_mode === 'test' ? '#f9e2af' : '#a6e3a1'; ?>"><?php echo $stripe_mode; ?></span><br>
            PK Set: <span style="color:<?php echo !empty($stripe_pk) ? '#a6e3a1' : '#f38ba8'; ?>"><?php echo !empty($stripe_pk) ? 'YES (' . substr($stripe_pk, 0, 12) . '...)' : 'NO'; ?></span><br>
            SK Set: <span style="color:<?php echo !empty($stripe_sk) ? '#a6e3a1' : '#f38ba8'; ?>"><?php echo !empty($stripe_sk) ? 'YES (' . substr($stripe_sk, 0, 12) . '...)' : 'NO'; ?></span><br>
            Client Secret: <span style="color:<?php echo !empty($client_secret) ? '#a6e3a1' : '#f38ba8'; ?>"><?php echo !empty($client_secret) ? 'YES' : 'NO'; ?></span><br>
            PI Error: <span style="color:#f38ba8;"><?php echo $pi_error ?: 'None'; ?></span>
        </div>
        <div>
            <strong style="color:#89b4fa;">Cart:</strong><br>
            Items: <?php echo count($items); ?><br>
            Has Training: <span style="color:<?php echo $has_training ? '#a6e3a1' : '#f9e2af'; ?>"><?php echo $has_training ? 'YES' : 'NO'; ?></span><br>
            Has Camps: <span style="color:<?php echo $has_camps ? '#a6e3a1' : '#f9e2af'; ?>"><?php echo $has_camps ? 'YES' : 'NO'; ?></span><br>
            Subtotal: $<?php echo number_format($subtotal, 2); ?><br>
            Total: $<?php echo number_format($total, 2); ?><br>
            Cents: <?php echo $cents; ?>
        </div>
        <div>
            <strong style="color:#89b4fa;">Training:</strong><br>
            Trainer ID (URL): <?php echo $trainer_id ?: 'None'; ?><br>
            Trainer Found: <span style="color:<?php echo $trainer ? '#a6e3a1' : '#f38ba8'; ?>"><?php echo $trainer ? 'YES - ' . $trainer->display_name : 'NO'; ?></span><br>
            <?php if ($trainer): ?>
            Rate: $<?php echo intval($trainer->hourly_rate ?: 60); ?>/hr<br>
            Package: <?php echo $pkg_key; ?><br>
            Date: <?php echo $session_date ?: 'None'; ?><br>
            Time: <?php echo $session_time ?: 'None'; ?>
            <?php endif; ?>
        </div>
        <div>
            <strong style="color:#89b4fa;">User:</strong><br>
            Logged In: <span style="color:<?php echo $logged_in ? '#a6e3a1' : '#f9e2af'; ?>"><?php echo $logged_in ? 'YES' : 'NO'; ?></span><br>
            <?php if ($logged_in): ?>
            User ID: <?php echo $user->ID; ?><br>
            Email: <?php echo $user->user_email; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php if (!empty($items)): ?>
    <div style="margin-top:12px;padding-top:12px;border-top:1px solid #45475a;">
        <strong style="color:#89b4fa;">Items:</strong>
        <?php foreach ($items as $i => $item): ?>
        <div style="background:#313244;padding:8px;border-radius:4px;margin-top:6px;">
            [<?php echo $i; ?>] <span style="color:#f9e2af;"><?php echo esc_html($item['name']); ?></span> 
            | Type: <?php echo $item['type']; ?> 
            | Price: $<?php echo number_format($item['price'], 2); ?>
            | Src: <?php echo $item['src']; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <div style="margin-top:12px;font-size:11px;color:#6c7086;">
        Add <code>?debug=1</code> to URL to show this panel. Session: <?php echo $checkout_session_id; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($empty): ?>
<div class="form empty">
    <div>
        <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#D1D5DB" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        <h2>Your Cart is Empty</h2>
        <p>Browse our camps or find a trainer to get started.</p>
        <a href="<?php echo home_url('/shop/'); ?>">View Programs</a>
    </div>
</div>
<?php else: ?>

<?php
// Build cart URL with training params so "Back to Cart" preserves training selection
$cart_url = home_url('/ptp-cart/');
if ($trainer_id) {
    $cart_params = array('trainer_id' => $trainer_id, 'package' => $pkg_key);
    if ($session_date) $cart_params['date'] = $session_date;
    if ($session_time) $cart_params['time'] = $session_time;
    if ($session_loc) $cart_params['location'] = $session_loc;
    $cart_url = add_query_arg($cart_params, $cart_url);
}
?>
<div class="page">
    <div class="form">
        <div class="header">
            <a href="<?php echo home_url(); ?>"><img src="<?php echo esc_url($logo); ?>" class="logo" alt="PTP"></a>
            <a href="<?php echo esc_url($cart_url); ?>" class="back">← Cart</a>
        </div>
        
        <h1 class="title">Complete Registration</h1>
        <p class="subtitle">Secure checkout</p>
        
        <?php if ($stripe_pk): ?>
        <div class="express" id="express-section" style="opacity:0.4;pointer-events:none;">
            <div class="express-t">Express Checkout</div>
            <div class="express-locked" id="express-locked">
                <span>🔒 Complete required fields below to unlock</span>
            </div>
            <div id="express-el" style="display:none;"></div>
        </div>
        <div class="divider">or pay with card</div>
        <?php endif; ?>
        
        <form id="form" method="post" action="#" onsubmit="return false;">
            <input type="hidden" name="action" value="ptp_save_checkout">
            <?php wp_nonce_field('ptp_checkout', 'ptp_checkout_nonce'); ?>
            <input type="hidden" name="cart_total" id="cartTotal" value="<?php echo $total; ?>">
            <input type="hidden" name="cart_items" id="cartItems" value="<?php echo esc_attr(implode(', ', array_column($items, 'name'))); ?>">
            <?php if ($trainer): ?>
            <input type="hidden" name="trainer_id" value="<?php echo $trainer->id; ?>">
            <input type="hidden" name="training_package" id="pkgInput" value="<?php echo esc_attr($pkg_key); ?>">
            <input type="hidden" name="training_total" id="trainingTotal" value="<?php echo esc_attr($training_price); ?>">
            <input type="hidden" name="session_date" value="<?php echo esc_attr($session_date); ?>">
            <input type="hidden" name="session_time" value="<?php echo esc_attr($session_time); ?>">
            <input type="hidden" name="session_location" value="<?php echo esc_attr($session_loc); ?>">
            <input type="hidden" name="group_size" value="<?php echo esc_attr($group_size); ?>">
            <?php endif; ?>
            
            <?php if ($logged_in && $parent): ?>
            <!-- Account Status Banner -->
            <div class="account-banner" style="background:linear-gradient(135deg,#0A0A0A 0%,#1a1a1a 100%);border:2px solid var(--gold);border-radius:12px;padding:16px 20px;margin-bottom:24px;display:flex;align-items:center;gap:16px;">
                <div class="account-avatar" style="width:48px;height:48px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;font-family:Oswald,sans-serif;font-weight:700;font-size:20px;color:#0A0A0A;flex-shrink:0;">
                    <?php echo esc_html(strtoupper(substr($user->display_name ?: $parent->first_name, 0, 1))); ?>
                </div>
                <div style="flex:1;min-width:0;">
                    <div style="font-weight:600;color:#fff;font-size:15px;">Welcome back, <?php echo esc_html($parent->first_name ?: $user->first_name); ?>! 👋</div>
                    <div style="font-size:13px;color:rgba(255,255,255,0.6);margin-top:2px;">
                        <?php if (count($players)): ?>
                        <?php echo count($players); ?> saved player<?php echo count($players) > 1 ? 's' : ''; ?> • 
                        <?php endif; ?>
                        <?php if (!empty($parent->emergency_name)): ?>
                        Emergency contact saved
                        <?php else: ?>
                        Add emergency contact below
                        <?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo home_url('/parent-dashboard/'); ?>" style="font-size:12px;color:var(--gold);text-decoration:none;white-space:nowrap;">My Account →</a>
            </div>
            <?php elseif (!$logged_in): ?>
            <!-- Login Prompt for Guests -->
            <div class="login-prompt" style="background:#f8f8f8;border:1px solid #e5e5e5;border-radius:12px;padding:14px 18px;margin-bottom:24px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                <span style="font-size:14px;color:#666;">Have an account?</span>
                <a href="<?php echo esc_url(home_url('/login/?redirect_to=' . urlencode($_SERVER['REQUEST_URI']))); ?>" style="font-size:14px;color:var(--gold);font-weight:600;text-decoration:none;">Sign in for faster checkout →</a>
            </div>
            <?php endif; ?>
            
            <?php if ($has_training && $trainer): ?>
            <div class="sec">
                <div class="sec-h"><span class="sec-n">1</span><h2 class="sec-t">Training Package</h2></div>
                <div class="trainer-card">
                    <div class="trainer-photo"><img src="<?php echo esc_url($trainer->photo_url ?: 'https://ui-avatars.com/api/?name='.urlencode($trainer->display_name).'&size=100&background=FCB900&color=0A0A0A&bold=true'); ?>" alt="" loading="lazy"></div>
                    <div>
                        <div class="trainer-name"><?php echo esc_html($trainer->display_name); ?></div>
                        <div class="trainer-level"><?php echo esc_html($levels[$trainer->playing_level] ?? 'PRO'); ?></div>
                        <div class="trainer-rate">$<?php echo $rate; ?>/hr</div>
                    </div>
                </div>
                <div class="pkgs">
                    <?php foreach ($pkgs as $k => $p): ?>
                    <label class="pkg<?php echo $pkg_key === $k ? ' sel' : ''; ?>" data-pk="<?php echo $k; ?>" data-pr="<?php echo $p['p']; ?>">
                        <input type="radio" name="pkg_sel" value="<?php echo $k; ?>" <?php checked($pkg_key, $k); ?>>
                        <div class="pkg-i"><div class="pkg-n"><?php echo $p['n']; ?></div><div class="pkg-d"><?php echo $p['s']; ?> session<?php echo $p['s'] > 1 ? 's' : ''; ?></div></div>
                        <div><div class="pkg-p">$<?php echo $p['p']; ?></div><?php if ($p['v']): ?><div class="pkg-v">Save $<?php echo $p['v']; ?></div><?php endif; ?></div>
                    </label>
                    <?php endforeach; ?>
                </div>
                
                <!-- Recurring Sessions Option -->
                <div class="recurring-box" id="recurringBox">
                    <label class="recurring-toggle">
                        <input type="checkbox" name="recurring_enabled" id="recurringEnabled" value="1">
                        <span class="recurring-check"></span>
                        <span class="recurring-label">
                            <strong>Make it recurring</strong>
                            <small>Same day & time every week - never miss a session</small>
                        </span>
                        <span class="recurring-badge">POPULAR</span>
                    </label>
                    
                    <div class="recurring-options" id="recurringOptions" style="display:none;">
                        <div class="recurring-row">
                            <div class="recurring-field">
                                <label class="label">Frequency</label>
                                <div class="recurring-freq">
                                    <label class="freq-opt sel" data-freq="weekly">
                                        <input type="radio" name="recurring_frequency" value="weekly" checked>
                                        <span>Weekly</span>
                                    </label>
                                    <label class="freq-opt" data-freq="biweekly">
                                        <input type="radio" name="recurring_frequency" value="biweekly">
                                        <span>Biweekly</span>
                                    </label>
                                </div>
                            </div>
                            <div class="recurring-field">
                                <label class="label">Duration</label>
                                <select name="recurring_weeks" id="recurringWeeks" class="select">
                                    <option value="4">4 weeks</option>
                                    <option value="8" selected>8 weeks</option>
                                    <option value="12">12 weeks</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="recurring-summary" id="recurringSummary">
                            <div class="recurring-day">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                <span>Every <strong id="recurringDayName"><?php echo $session_date ? date('l', strtotime($session_date)) : 'Week'; ?></strong> at <strong id="recurringTimeName"><?php 
                                    if ($session_time) {
                                        $h = intval(explode(':', $session_time)[0]);
                                        echo ($h > 12 ? $h - 12 : ($h ?: 12)) . ':00 ' . ($h >= 12 ? 'PM' : 'AM');
                                    } else {
                                        echo 'your selected time';
                                    }
                                ?></strong></span>
                            </div>
                            <div class="recurring-total">
                                <span>Total sessions:</span>
                                <strong id="recurringTotalSessions">8</strong>
                            </div>
                            <div class="recurring-savings">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                <span>You'll save <strong id="recurringSavings">15%</strong> with auto-pay</span>
                            </div>
                        </div>
                        
                        <input type="hidden" name="recurring_day" id="recurringDay" value="<?php echo $session_date ? date('N', strtotime($session_date)) : ''; ?>">
                        <input type="hidden" name="recurring_time" id="recurringTime" value="<?php echo esc_attr($session_time); ?>">
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="sec">
                <div class="sec-h">
                    <span class="sec-n"><?php echo $has_training ? '2' : '1'; ?></span>
                    <h2 class="sec-t"><?php echo $is_multi_player ? 'Player Info (' . $group_size . ' spots)' : 'Camper Info'; ?></h2>
                    <span class="sec-b">Required</span>
                </div>
                
                <?php if ($is_multi_player && $group_session): ?>
                <!-- v131: Group Session Info Banner -->
                <div class="group-session-info" style="background:linear-gradient(135deg,rgba(252,185,0,0.1) 0%,rgba(252,185,0,0.05) 100%);border:1px solid rgba(252,185,0,0.3);border-radius:10px;padding:14px 16px;margin-bottom:20px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" style="width:18px;height:18px;flex-shrink:0;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span style="font-weight:600;font-size:14px;color:var(--black);"><?php echo esc_html($group_session->title); ?></span>
                    </div>
                    <div style="font-size:12px;color:#666;">
                        <strong><?php echo $group_size; ?> player spot<?php echo $group_size > 1 ? 's' : ''; ?></strong> × $<?php echo number_format($group_session->price_per_player, 0); ?>/player = <strong style="color:var(--gold);">$<?php echo number_format($training_price, 0); ?></strong>
                        <?php 
                        $available_spots = $group_session->max_players - $group_session->current_players;
                        if ($available_spots > $group_size): ?>
                        <span style="margin-left:8px;color:#22C55E;">(<?php echo $available_spots - $group_size; ?> more spots available)</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php elseif ($is_multi_player && $group_size > 1): ?>
                <!-- v131: Private Group Training Info Banner -->
                <div class="group-session-info" style="background:rgba(252,185,0,0.08);border:1px solid rgba(252,185,0,0.2);border-radius:10px;padding:14px 16px;margin-bottom:20px;">
                    <div style="display:flex;align-items:center;gap:10px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2" style="width:18px;height:18px;flex-shrink:0;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        <span style="font-size:13px;color:#333;"><strong><?php echo $group_labels[$group_size]; ?> Training</strong> - Fill in details for all <?php echo $group_size; ?> players</span>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($is_multi_player): ?>
                <!-- v131: Multi-Player Fields for Group Sessions -->
                <input type="hidden" name="group_player_count" id="groupPlayerCount" value="<?php echo $group_size; ?>">
                <input type="hidden" name="group_session_id" value="<?php echo $group_session_id; ?>">
                
                <div id="multiPlayerContainer">
                    <?php for ($p_idx = 0; $p_idx < $group_size; $p_idx++): 
                        $player_num = $p_idx + 1;
                        $has_saved = isset($players[$p_idx]);
                        $saved_player = $has_saved ? $players[$p_idx] : null;
                    ?>
                    <div class="group-player-card" data-player-index="<?php echo $p_idx; ?>" style="background:#fafafa;border:2px solid <?php echo $p_idx === 0 ? 'var(--gold)' : '#e5e5e5'; ?>;border-radius:12px;padding:16px;margin-bottom:16px;position:relative;">
                        <div class="group-player-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                            <span class="group-player-num" style="font-family:Oswald,sans-serif;font-weight:700;font-size:14px;text-transform:uppercase;color:var(--black);">Player <?php echo $player_num; ?> of <?php echo $group_size; ?></span>
                            <?php if ($p_idx > 0): ?>
                            <span class="group-player-copy" onclick="copyFromPlayer1(<?php echo $p_idx; ?>)" style="font-size:11px;color:var(--gold);cursor:pointer;padding:4px 8px;background:rgba(252,185,0,0.1);border-radius:4px;">Copy from Player 1</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($players && count($players) > 0): ?>
                        <div class="saved-players-select" style="margin-bottom:12px;">
                            <label class="label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:4px;display:block;">Select Saved Player</label>
                            <select class="select saved-player-select" data-index="<?php echo $p_idx; ?>" onchange="selectSavedPlayer(this, <?php echo $p_idx; ?>)" style="padding:10px;font-size:14px;width:100%;">
                                <option value="">-- Enter New Player --</option>
                                <?php foreach ($players as $sp_idx => $sp): 
                                    $sp_full_name = !empty($sp->name) ? $sp->name : trim(($sp->first_name ?? '') . ' ' . ($sp->last_name ?? ''));
                                ?>
                                <option value="<?php echo $sp->id; ?>" data-fn="<?php echo esc_attr($sp->first_name ?? ''); ?>" data-ln="<?php echo esc_attr($sp->last_name ?? ''); ?>" data-dob="<?php echo esc_attr($sp->date_of_birth ?? ''); ?>" data-team="<?php echo esc_attr($sp->team ?? ''); ?>" data-skill="<?php echo esc_attr($sp->skill_level ?? ''); ?>" <?php echo ($has_saved && $saved_player->id == $sp->id) ? 'selected' : ''; ?>>
                                    <?php echo esc_html($sp_full_name ?: 'Saved Player'); ?><?php if ($sp->age): ?> (Age <?php echo $sp->age; ?>)<?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        
                        <input type="hidden" name="players[<?php echo $p_idx; ?>][player_id]" class="player-id-field" value="<?php echo $saved_player->id ?? ''; ?>">
                        
                        <div class="group-player-fields">
                            <div class="row grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                <div>
                                    <label class="label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:4px;display:block;">First Name *</label>
                                    <input type="text" name="players[<?php echo $p_idx; ?>][first_name]" class="input player-fn" value="<?php echo esc_attr($saved_player->first_name ?? ''); ?>" required style="padding:10px;font-size:14px;">
                                </div>
                                <div>
                                    <label class="label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:4px;display:block;">Last Name *</label>
                                    <input type="text" name="players[<?php echo $p_idx; ?>][last_name]" class="input player-ln" value="<?php echo esc_attr($saved_player->last_name ?? ''); ?>" required style="padding:10px;font-size:14px;">
                                </div>
                            </div>
                            <div class="row grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:10px;">
                                <div>
                                    <label class="label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:4px;display:block;">Date of Birth *</label>
                                    <input type="date" name="players[<?php echo $p_idx; ?>][dob]" class="input player-dob" value="<?php echo esc_attr($saved_player->date_of_birth ?? ''); ?>" required style="padding:10px;font-size:14px;">
                                </div>
                                <div>
                                    <label class="label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:4px;display:block;">T-Shirt Size</label>
                                    <select name="players[<?php echo $p_idx; ?>][shirt_size]" class="select player-shirt" style="padding:10px;font-size:14px;">
                                        <option value="">Select</option>
                                        <option value="YS">Youth S</option><option value="YM">Youth M</option><option value="YL">Youth L</option>
                                        <option value="AS">Adult S</option><option value="AM">Adult M</option><option value="AL">Adult L</option><option value="AXL">Adult XL</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row grid" style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                                <div>
                                    <label class="label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:4px;display:block;">Team/Club</label>
                                    <input type="text" name="players[<?php echo $p_idx; ?>][team]" class="input player-team" value="<?php echo esc_attr($saved_player->team ?? ''); ?>" placeholder="Optional" style="padding:10px;font-size:14px;">
                                </div>
                                <div>
                                    <label class="label" style="font-size:10px;font-weight:600;text-transform:uppercase;color:#666;margin-bottom:4px;display:block;">Skill Level</label>
                                    <select name="players[<?php echo $p_idx; ?>][skill]" class="select player-skill" style="padding:10px;font-size:14px;">
                                        <option value="">Select</option>
                                        <option value="beginner" <?php echo ($saved_player->skill_level ?? '') === 'beginner' ? 'selected' : ''; ?>>Beginner</option>
                                        <option value="intermediate" <?php echo ($saved_player->skill_level ?? '') === 'intermediate' ? 'selected' : ''; ?>>Intermediate</option>
                                        <option value="advanced" <?php echo ($saved_player->skill_level ?? '') === 'advanced' ? 'selected' : ''; ?>>Advanced</option>
                                        <option value="elite" <?php echo ($saved_player->skill_level ?? '') === 'elite' ? 'selected' : ''; ?>>Elite</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endfor; ?>
                </div>
                
                <?php else: ?>
                <!-- Single Player Fields (Original) -->
                <?php if ($players): ?>
                <div class="saved">
                    <?php foreach ($players as $i => $p): 
                        // Use first_name if available, otherwise parse from name field
                        $display_name = !empty($p->first_name) ? $p->first_name : (!empty($p->name) ? explode(' ', $p->name)[0] : 'Player');
                        $full_name = !empty($p->name) ? $p->name : trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? ''));
                    ?>
                    <div class="saved-p<?php echo $i === 0 ? ' sel' : ''; ?>" data-id="<?php echo $p->id; ?>" data-fn="<?php echo esc_attr($p->first_name ?: explode(' ', $p->name ?? '')[0]); ?>" data-ln="<?php echo esc_attr($p->last_name ?: (count(explode(' ', $p->name ?? '')) > 1 ? explode(' ', $p->name ?? '')[1] : '')); ?>" data-name="<?php echo esc_attr($full_name); ?>">
                        <span class="n"><?php echo esc_html($full_name ?: 'Saved Player'); ?></span>
                        <?php if ($p->age): ?><span class="a">Age <?php echo $p->age; ?></span><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <div class="add-btn" id="addNew">+ Add New</div>
                </div>
                <input type="hidden" name="player_id" id="playerId" value="<?php echo $players[0]->id ?? ''; ?>">
                <?php endif; ?>
                
                <div id="playerFields" style="<?php echo $players ? 'display:none' : ''; ?>">
                    <div class="row grid">
                        <div><label class="label">First Name *</label><input type="text" name="camper_first_name" class="input" <?php echo !$players ? 'required' : ''; ?>></div>
                        <div><label class="label">Last Name *</label><input type="text" name="camper_last_name" class="input" <?php echo !$players ? 'required' : ''; ?>></div>
                    </div>
                    <div class="row grid">
                        <div><label class="label">Date of Birth *</label><input type="date" name="camper_dob" class="input" <?php echo !$players ? 'required' : ''; ?>></div>
                        <div><label class="label">T-Shirt Size *</label>
                            <select name="camper_shirt_size" class="select" <?php echo !$players ? 'required' : ''; ?>>
                                <option value="">Select</option>
                                <option value="YS">Youth S</option><option value="YM">Youth M</option><option value="YL">Youth L</option>
                                <option value="AS">Adult S</option><option value="AM">Adult M</option><option value="AL">Adult L</option><option value="AXL">Adult XL</option>
                            </select>
                        </div>
                    </div>
                    <div class="row grid">
                        <div><label class="label">Team/Club</label><input type="text" name="camper_team" class="input" placeholder="Optional"></div>
                        <div><label class="label">Skill Level</label>
                            <select name="camper_skill" class="select">
                                <option value="">Select</option>
                                <option value="beginner">Beginner</option><option value="intermediate">Intermediate</option>
                                <option value="advanced">Advanced</option><option value="elite">Elite</option>
                            </select>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="sec">
                <div class="sec-h"><span class="sec-n"><?php echo $has_training ? '3' : '2'; ?></span><h2 class="sec-t">Parent/Guardian</h2></div>
                <div class="row grid">
                    <div><label class="label">First Name *</label><input type="text" name="parent_first_name" class="input" value="<?php echo esc_attr($parent->first_name ?? ($logged_in ? $user->first_name : '')); ?>" required></div>
                    <div><label class="label">Last Name *</label><input type="text" name="parent_last_name" class="input" value="<?php echo esc_attr($parent->last_name ?? ($logged_in ? $user->last_name : '')); ?>" required></div>
                </div>
                <div class="row grid">
                    <div><label class="label">Email *</label><input type="email" name="parent_email" class="input" value="<?php echo esc_attr($parent->email ?? ($logged_in ? $user->user_email : '')); ?>" required></div>
                    <div><label class="label">Phone *</label><input type="tel" name="parent_phone" class="input" value="<?php echo esc_attr($parent->phone ?? ''); ?>" required></div>
                </div>
            </div>
            
            <div class="sec">
                <div class="sec-h"><span class="sec-n"><?php echo $has_training ? '4' : '3'; ?></span><h2 class="sec-t">Emergency & Medical</h2></div>
                <?php if ($parent && !empty($parent->emergency_name)): ?>
                <div class="saved-info" style="background:rgba(34,197,94,0.1);border:1px solid rgba(34,197,94,0.3);padding:12px 16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:10px;">
                    <span style="color:#22C55E;">✓</span>
                    <span style="color:#666;font-size:14px;">Using your saved emergency contact info</span>
                    <button type="button" onclick="document.getElementById('emergencyFields').style.display='block';this.parentElement.style.display='none';" style="margin-left:auto;background:none;border:none;color:var(--gold);cursor:pointer;font-size:13px;">Edit</button>
                </div>
                <?php endif; ?>
                <div id="emergencyFields" style="<?php echo ($parent && !empty($parent->emergency_name)) ? 'display:none;' : ''; ?>">
                    <div class="row grid">
                        <div><label class="label">Emergency Contact *</label><input type="text" name="emergency_name" class="input" value="<?php echo esc_attr($parent->emergency_name ?? ''); ?>" required></div>
                        <div><label class="label">Emergency Phone *</label><input type="tel" name="emergency_phone" class="input" value="<?php echo esc_attr($parent->emergency_phone ?? ''); ?>" required></div>
                    </div>
                    <div class="row">
                        <label class="label">Relationship *</label>
                        <select name="emergency_relation" class="select" required>
                            <option value="">Select</option>
                            <option value="parent" <?php echo ($parent->emergency_relation ?? '') === 'parent' ? 'selected' : ''; ?>>Parent</option>
                            <option value="grandparent" <?php echo ($parent->emergency_relation ?? '') === 'grandparent' ? 'selected' : ''; ?>>Grandparent</option>
                            <option value="aunt_uncle" <?php echo ($parent->emergency_relation ?? '') === 'aunt_uncle' ? 'selected' : ''; ?>>Aunt/Uncle</option>
                            <option value="sibling" <?php echo ($parent->emergency_relation ?? '') === 'sibling' ? 'selected' : ''; ?>>Sibling (18+)</option>
                            <option value="other" <?php echo ($parent->emergency_relation ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                </div>
                <?php if ($parent && !empty($parent->emergency_name)): ?>
                <input type="hidden" name="emergency_name_saved" value="<?php echo esc_attr($parent->emergency_name); ?>">
                <input type="hidden" name="emergency_phone_saved" value="<?php echo esc_attr($parent->emergency_phone); ?>">
                <input type="hidden" name="emergency_relation_saved" value="<?php echo esc_attr($parent->emergency_relation); ?>">
                <?php endif; ?>
                <div class="row"><label class="label">Allergies/Medical</label><textarea name="medical_info" class="textarea" placeholder="List any allergies or conditions..."><?php echo esc_textarea($parent->medical_info ?? ''); ?></textarea><p class="hint">Leave blank if none</p></div>
            </div>
            
            <div class="sec">
                <div class="sec-h"><span class="sec-n"><?php echo $has_training ? '5' : '4'; ?></span><h2 class="sec-t">Waiver</h2></div>
                <div class="waiver">
                    <div class="waiver-t">Liability Waiver, Release & Participation Agreement</div>
                    <div class="waiver-scroll">
                        <p><strong>PTP-PLAYERSTEACHINGPLAYERS LLC</strong><br>
                        A Pennsylvania Limited Liability Company<br>
                        EIN: [Registered Business]</p>
                        
                        <h4>1. ASSUMPTION OF RISK</h4>
                        <p>I, the undersigned parent/guardian, acknowledge that soccer and athletic training activities involve inherent risks including but not limited to: physical contact with other participants, falls, collisions, muscle strains, sprains, fractures, concussions, heat-related illness, and other injuries that may result from participation. I understand these risks cannot be eliminated regardless of the care taken to avoid injuries. I VOLUNTARILY ASSUME ALL RISKS associated with my child's participation in programs operated by PTP-PLAYERSTEACHINGPLAYERS LLC ("PTP").</p>
                        
                        <h4>2. RELEASE AND WAIVER OF LIABILITY</h4>
                        <p>In consideration of my child being permitted to participate in PTP programs, I hereby RELEASE, WAIVE, DISCHARGE, AND COVENANT NOT TO SUE PTP-PLAYERSTEACHINGPLAYERS LLC, its owners, members, managers, officers, employees, coaches, trainers, volunteers, agents, representatives, successors, and assigns (collectively "Released Parties") from any and all liability, claims, demands, actions, or causes of action whatsoever arising out of or related to any loss, damage, or injury, including death, that may be sustained by my child, or to any property belonging to my child, while participating in PTP programs or activities, or while on or about the premises where the activities are being conducted.</p>
                        
                        <h4>3. INDEMNIFICATION</h4>
                        <p>I agree to INDEMNIFY AND HOLD HARMLESS the Released Parties from any loss, liability, damage, or costs, including court costs and attorney fees, that may incur due to my child's participation in PTP programs, whether caused by the negligence of Released Parties or otherwise.</p>
                        
                        <h4>4. MEDICAL AUTHORIZATION</h4>
                        <p>In the event of an emergency, I authorize PTP staff to secure appropriate medical treatment for my child, including transportation to a medical facility. I understand that PTP is not responsible for medical expenses incurred. I certify that my child is physically able to participate and has no medical conditions that would prevent safe participation, except as disclosed in the registration information.</p>
                        
                        <h4>5. PHOTO/VIDEO RELEASE</h4>
                        <p>I grant PTP-PLAYERSTEACHINGPLAYERS LLC permission to photograph and/or video record my child during program activities. I authorize PTP to use such photographs and recordings for promotional, advertising, educational, and commercial purposes in any media without compensation to me or my child. I waive any right to inspect or approve the finished product.</p>
                        
                        <h4>6. CONDUCT POLICY</h4>
                        <p>I understand that PTP reserves the right to dismiss any participant whose conduct is deemed detrimental to the program or other participants, without refund. Participants and parents/guardians are expected to demonstrate good sportsmanship and respect for coaches, staff, and other participants.</p>
                        
                        <h4>7. REFUND POLICY</h4>
                        <p>• Full refund: Cancellations made 14+ days before program start date<br>
                        • 50% refund: Cancellations made 7-14 days before program start date<br>
                        • No refund: Cancellations made within 7 days of program start date<br>
                        • No refunds for early departure or missed sessions</p>
                        
                        <h4>8. GOVERNING LAW</h4>
                        <p>This Agreement shall be governed by the laws of the Commonwealth of Pennsylvania. Any disputes shall be resolved in the courts of Chester County, Pennsylvania.</p>
                        
                        <h4>9. ACKNOWLEDGMENT</h4>
                        <p>I HAVE READ THIS RELEASE AND WAIVER OF LIABILITY, ASSUMPTION OF RISK, AND INDEMNITY AGREEMENT. I FULLY UNDERSTAND ITS TERMS AND UNDERSTAND THAT I AM GIVING UP SUBSTANTIAL RIGHTS, INCLUDING MY RIGHT TO SUE. I ACKNOWLEDGE THAT I AM SIGNING THE AGREEMENT FREELY AND VOLUNTARILY, AND INTEND BY MY ELECTRONIC SIGNATURE TO BE A COMPLETE AND UNCONDITIONAL RELEASE OF ALL LIABILITY TO THE GREATEST EXTENT ALLOWED BY LAW.</p>
                        
                        <p style="margin-top:12px;font-size:10px;color:#666;">© <?php echo date('Y'); ?> PTP-PLAYERSTEACHINGPLAYERS LLC. All rights reserved.</p>
                    </div>
                    <div class="waiver-chk">
                        <input type="checkbox" name="waiver_accepted" id="waiver" required>
                        <label for="waiver">I have read, understand, and agree to the <strong>Liability Waiver, Release & Participation Agreement</strong> on behalf of my child.</label>
                    </div>
                    <div class="waiver-chk" style="margin-top:12px;">
                        <input type="checkbox" name="photo_consent" id="photoConsent" value="1">
                        <label for="photoConsent">I consent to PTP using photos/videos of my child for promotional materials, social media, and email marketing. <span style="color:#666;font-size:11px;">(Optional)</span></label>
                    </div>
                </div>
            </div>
            
            <?php if ($has_camps): 
                // Get camp price for sibling discount calculation
                $camp_price = 0;
                foreach ($items as $item) {
                    if ($item['type'] === 'camp') {
                        $camp_price = $item['price'];
                        break;
                    }
                }
            ?>
            <!-- Camp Discount Section -->
            <div class="sec">
                <div class="sec-h"><span class="sec-n"><?php echo $has_training ? '6' : '5'; ?></span><h2 class="sec-t">Discounts & Upgrades</h2><span class="sec-opt">OPTIONAL</span></div>
                
                <?php 
                // Get all available camps for the picker
                $available_camps = [];
                $current_camp_id = 0;
                foreach ($items as $item) {
                    if ($item['type'] === 'camp') {
                        $current_camp_id = $item['id'] ?? 0;
                        break;
                    }
                }
                
                // Query other camps from WooCommerce
                // First try with category filter
                $camp_args = [
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'posts_per_page' => 50,
                    'post__not_in' => $current_camp_id ? [$current_camp_id] : [],
                    'tax_query' => [[
                        'taxonomy' => 'product_cat',
                        'field' => 'slug',
                        'terms' => ['camps', 'summer-camps', 'camp', 'summer-camp', 'soccer-camps', 'soccer-camp'],
                        'operator' => 'IN',
                    ]],
                ];
                $camp_query = new WP_Query($camp_args);
                
                // If no camps found, try broader query
                if (!$camp_query->have_posts()) {
                    // Try finding camps by product name or meta
                    $camp_args = [
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'posts_per_page' => 30,
                        'post__not_in' => $current_camp_id ? [$current_camp_id] : [],
                        's' => 'camp', // Search for "camp" in title/content
                    ];
                    $camp_query = new WP_Query($camp_args);
                }
                
                // If still no camps, get any products (for demo)
                if (!$camp_query->have_posts()) {
                    $camp_args = [
                        'post_type' => 'product',
                        'post_status' => 'publish',
                        'posts_per_page' => 20,
                        'post__not_in' => $current_camp_id ? [$current_camp_id] : [],
                    ];
                    $camp_query = new WP_Query($camp_args);
                }
                
                if ($camp_query->have_posts()) {
                    while ($camp_query->have_posts()) {
                        $camp_query->the_post();
                        $pid = get_the_ID();
                        $product = wc_get_product($pid);
                        if ($product && $product->is_purchasable()) {
                            // Get dates - try multiple meta keys
                            $dates = get_post_meta($pid, '_ptp_camp_dates', true);
                            if (!$dates) $dates = get_post_meta($pid, '_ptp_start_date', true);
                            if (!$dates) $dates = get_post_meta($pid, '_event_date', true);
                            if (!$dates) $dates = get_post_meta($pid, '_camp_date', true);
                            
                            // Get location - try multiple meta keys
                            $location = get_post_meta($pid, '_ptp_location_name', true);
                            if (!$location) $location = get_post_meta($pid, '_ptp_location', true);
                            if (!$location) $location = get_post_meta($pid, '_camp_location', true);
                            
                            // Format dates if needed
                            if ($dates && strtotime($dates)) {
                                $dates = date('M j', strtotime($dates));
                                $end_date = get_post_meta($pid, '_ptp_end_date', true);
                                if ($end_date && strtotime($end_date)) {
                                    $dates .= ' - ' . date('M j', strtotime($end_date));
                                }
                            }
                            
                            $available_camps[] = [
                                'id' => $pid,
                                'name' => get_the_title(),
                                'price' => $product->get_price(),
                                'dates' => $dates,
                                'location' => $location,
                            ];
                        }
                    }
                    wp_reset_postdata();
                }
                
                // Price calculations with proper anchoring
                $single_price = $camp_price;
                // 2-pack: Add 1 more camp at 10% off
                $pack2_regular = $single_price;
                $pack2_price = round($single_price * 0.90);
                $pack2_save = $pack2_regular - $pack2_price;
                // 3-pack: Add 2 more camps at 20% off each
                $pack3_regular = $single_price * 2;
                $pack3_price = round($single_price * 2 * 0.80);
                $pack3_save = $pack3_regular - $pack3_price;
                // All-Access
                $aa_value = 5930;
                $aa_price = 4000;
                $aa_save = $aa_value - $aa_price;
                // Before/After Care
                $care_price = 60;
                ?>
                
                <!-- BEFORE/AFTER CARE ADDON -->
                <div class="addon-sec care-addon">
                    <div class="addon-check">
                        <input type="checkbox" name="before_after_care" id="beforeAfterCare" value="1">
                        <label for="beforeAfterCare">
                            <span class="addon-icon">🌅</span>
                            <span class="addon-info">
                                <span class="addon-title">Add Before & After Care</span>
                                <span class="addon-desc">Drop off at 8am, pick up at 5pm — extended supervision included</span>
                            </span>
                            <span class="addon-price">+$<?php echo $care_price; ?></span>
                        </label>
                    </div>
                </div>
                
                <!-- UPGRADE & SAVE Section - Research-Based Price Anchoring -->
                <div class="discount-sec upgrade-sec">
                    <div class="upgrade-header">
                        <div class="upgrade-header-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>
                        </div>
                        <div class="upgrade-header-text">
                            <div class="upgrade-header-title">Add More Weeks & Save</div>
                            <div class="upgrade-header-sub">
                                <span class="upgrade-social-proof">🔥 73% of families register for multiple weeks</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="upgrade-opts">
                        <!-- ANCHOR: Current Selection -->
                        <div class="upgrade-anchor">
                            <span class="upgrade-anchor-label">YOUR CURRENT SELECTION</span>
                            <div class="upgrade-anchor-row">
                                <span class="upgrade-anchor-item">1 Camp Week</span>
                                <span class="upgrade-anchor-price">$<?php echo number_format($single_price, 0); ?></span>
                            </div>
                        </div>
                        
                        <!-- 2-CAMP PACK -->
                        <div class="upgrade-opt" data-upgrade="2pack" data-price="<?php echo $pack2_price; ?>" data-save="<?php echo $pack2_save; ?>" data-camps-needed="1">
                            <input type="radio" name="upgrade_pack" id="upgrade2pack" value="2pack">
                            <div class="upgrade-opt-info">
                                <div class="upgrade-opt-row">
                                    <span class="upgrade-opt-title">Add 2nd Week</span>
                                </div>
                                <span class="upgrade-opt-desc">Pick any additional camp week</span>
                            </div>
                            <div class="upgrade-opt-price">
                                <!-- Anchor price LARGER, sale price smaller (research-backed) -->
                                <span class="upgrade-opt-anchor">$<?php echo number_format($pack2_regular, 0); ?></span>
                                <span class="upgrade-opt-amount">+$<?php echo number_format($pack2_price, 0); ?></span>
                                <span class="upgrade-opt-save">SAVE $<?php echo number_format($pack2_save, 0); ?></span>
                            </div>
                        </div>
                        
                        <!-- 3-CAMP PACK - MOST POPULAR (Decoy Effect Target) -->
                        <div class="upgrade-opt upgrade-popular" data-upgrade="3pack" data-price="<?php echo $pack3_price; ?>" data-save="<?php echo $pack3_save; ?>" data-camps-needed="2">
                            <div class="upgrade-popular-badge">MOST POPULAR</div>
                            <input type="radio" name="upgrade_pack" id="upgrade3pack" value="3pack">
                            <div class="upgrade-opt-info">
                                <div class="upgrade-opt-row">
                                    <span class="upgrade-opt-title">Add 2 More Weeks</span>
                                </div>
                                <span class="upgrade-opt-desc">Best value for summer training</span>
                            </div>
                            <div class="upgrade-opt-price">
                                <span class="upgrade-opt-anchor">$<?php echo number_format($pack3_regular, 0); ?></span>
                                <span class="upgrade-opt-amount">+$<?php echo number_format($pack3_price, 0); ?></span>
                                <span class="upgrade-opt-save">SAVE $<?php echo number_format($pack3_save, 0); ?></span>
                            </div>
                        </div>
                        
                        <!-- ALL-ACCESS - PREMIUM (Aspirational Anchor) -->
                        <div class="upgrade-opt upgrade-premium" data-upgrade="allaccess" data-price="<?php echo $aa_price; ?>" data-save="<?php echo $aa_save; ?>" data-camps-needed="0">
                            <div class="upgrade-premium-badge">⭐ BEST VALUE</div>
                            <input type="radio" name="upgrade_pack" id="upgradeAA" value="allaccess">
                            <div class="upgrade-opt-info">
                                <div class="upgrade-opt-row">
                                    <span class="upgrade-opt-title">Go All-Access</span>
                                </div>
                                <span class="upgrade-opt-desc">Complete year-round development</span>
                                <div class="upgrade-value-stack">
                                    <span>6 Camp Weeks</span>
                                    <span>12 Private Sessions</span>
                                    <span>6 Skills Clinics</span>
                                    <span>Video Analysis</span>
                                    <span>Mentorship</span>
                                </div>
                            </div>
                            <div class="upgrade-opt-price">
                                <span class="upgrade-opt-anchor">$<?php echo number_format($aa_value, 0); ?> value</span>
                                <span class="upgrade-opt-amount">$<?php echo number_format($aa_price, 0); ?></span>
                                <span class="upgrade-opt-save">SAVE $<?php echo number_format($aa_save, 0); ?> (33%)</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Hidden fields for form submission -->
                    <input type="hidden" name="upgrade_selected" id="upgradeSelected" value="">
                    <input type="hidden" name="upgrade_amount" id="upgradeAmount" value="0">
                    <input type="hidden" name="upgrade_camps" id="upgradeCamps" value="">
                    <input type="hidden" name="before_after_care_amount" id="careAmount" value="0">
                    
                    <div class="upgrade-note">
                        <a href="<?php echo home_url('/all-access/'); ?>" target="_blank">Learn more about All-Access Pass →</a>
                    </div>
                </div>
                
                <!-- CAMP PICKER MODAL -->
                <div class="camp-picker-modal" id="campPickerModal" style="display:none;">
                    <div class="camp-picker-overlay"></div>
                    <div class="camp-picker-content">
                        <button type="button" class="camp-picker-close">&times;</button>
                        <h3 class="camp-picker-title">Select Your Additional Camp<span id="campPickerPlural">s</span></h3>
                        <p class="camp-picker-subtitle">Choose <span id="campsNeededCount">1</span> more camp week<span id="campPickerPlural2">s</span> to add to your order</p>
                        
                        <div class="camp-picker-list">
                            <?php foreach ($available_camps as $camp): 
                                $camp_display = esc_attr($camp['name']);
                                if ($camp['dates']) $camp_display .= ' (' . esc_attr($camp['dates']) . ')';
                            ?>
                            <label class="camp-picker-item" data-camp-id="<?php echo $camp['id']; ?>" data-camp-price="<?php echo $camp['price']; ?>" data-camp-name="<?php echo $camp_display; ?>">
                                <input type="checkbox" name="additional_camps[]" value="<?php echo $camp['id']; ?>">
                                <div class="camp-picker-info">
                                    <span class="camp-picker-name"><?php echo esc_html($camp['name']); ?></span>
                                    <span class="camp-picker-meta">
                                        <?php if ($camp['dates']): ?><?php echo esc_html($camp['dates']); ?><?php endif; ?>
                                        <?php if ($camp['location']): ?> · <?php echo esc_html($camp['location']); ?><?php endif; ?>
                                    </span>
                                </div>
                                <span class="camp-picker-check">✓</span>
                            </label>
                            <?php endforeach; ?>
                            
                            <?php if (empty($available_camps)): ?>
                            <p class="camp-picker-empty">No additional camps available at this time. Please check back soon or contact us at <a href="mailto:info@ptpsoccercamps.com" style="color:var(--gold)">info@ptpsoccercamps.com</a></p>
                            <?php endif; ?>
                        </div>
                        <!-- Debug: <?php echo count($available_camps); ?> camps found -->
                        
                        <div class="camp-picker-footer">
                            <div class="camp-picker-selected">
                                <span id="selectedCampsCount">0</span> of <span id="requiredCampsCount">1</span> selected
                            </div>
                            <button type="button" class="camp-picker-confirm" id="confirmCampSelection" disabled>
                                Confirm Selection
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Available camps count for JS -->
                <script>var availableCampsCount = <?php echo count($available_camps); ?>;</script>
                
                <!-- Add Another Camper (Sibling Discount) -->
                <div class="discount-sec">
                    <div class="discount-sec-t">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Add Sibling – Save 10%
                    </div>
                    <p style="font-size:12px;color:#666;margin-bottom:10px;">Register a sibling to the same camp and save 10% on their registration!</p>
                    <div class="discount-opt">
                        <input type="checkbox" name="add_sibling" id="addSibling" value="1" data-camp-price="<?php echo $camp_price; ?>">
                        <label for="addSibling">Yes, add a sibling (+$<?php echo number_format($camp_price * 0.9, 2); ?>)</label>
                        <span class="save">Save $<?php echo number_format($camp_price * 0.1, 2); ?></span>
                    </div>
                    <div id="siblingFields" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid rgba(0,0,0,.1);">
                        <div class="row grid" style="margin-bottom:8px;">
                            <div><label class="label">Sibling First Name *</label><input type="text" name="sibling_first_name" class="input" id="sibFirstName"></div>
                            <div><label class="label">Sibling Last Name *</label><input type="text" name="sibling_last_name" class="input" id="sibLastName"></div>
                        </div>
                        <div class="row grid" style="margin-bottom:8px;">
                            <div><label class="label">Date of Birth *</label><input type="date" name="sibling_dob" class="input" id="sibDOB"></div>
                            <div><label class="label">T-Shirt Size *</label>
                                <select name="sibling_shirt" class="select" id="sibShirt">
                                    <option value="">Select</option>
                                    <option value="YS">Youth S</option><option value="YM">Youth M</option><option value="YL">Youth L</option>
                                    <option value="AS">Adult S</option><option value="AM">Adult M</option><option value="AL">Adult L</option><option value="AXL">Adult XL</option>
                                </select>
                            </div>
                        </div>
                        <div class="discount-applied" style="background:rgba(252,185,0,.1);border-color:var(--gold);color:var(--black);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Sibling added! 10% discount applied to their registration.</span>
                        </div>
                    </div>
                </div>
                
                <!-- Referral Code -->
                <div class="discount-sec">
                    <div class="discount-sec-t">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L15.09 8.26L22 9.27L17 14.14L18.18 21.02L12 17.77L5.82 21.02L7 14.14L2 9.27L8.91 8.26L12 2"/></svg>
                        Referral Code – Save $25
                    </div>
                    <p style="font-size:12px;color:#666;margin-bottom:10px;">Have a code from a friend? Get $25 off!</p>
                    <div class="discount-row">
                        <input type="text" name="referral_code" id="referralCode" class="discount-input" placeholder="Enter referral code" style="text-transform:uppercase">
                        <button type="button" class="discount-btn" id="applyReferral">Apply</button>
                    </div>
                    <input type="hidden" name="referral_discount" id="referralDiscount" value="0">
                    <input type="hidden" name="referral_validated" id="referralValidated" value="">
                    <div id="referralApplied" class="discount-applied" style="display:none;margin-top:10px;">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><polyline points="20 6 9 17 4 12"/></svg>
                        <span id="referralMsg">$25 discount applied!</span>
                    </div>
                    <div id="referralError" style="display:none;margin-top:10px;color:var(--red);font-size:12px;"></div>
                </div>
                
                <!-- Team Registration -->
                <div class="discount-sec" style="padding:20px;">
                    <div class="discount-sec-t">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        Team Registration – Save 15%
                    </div>
                    <p style="font-size:12px;color:#666;margin-bottom:10px;">Registering 5+ players from the same team? Register all players now and get 15% off!</p>
                    <div class="discount-opt">
                        <input type="checkbox" name="team_registration" id="teamReg" value="1">
                        <label for="teamReg">This is a team registration (5+ players)</label>
                        <span class="save">Save 15%</span>
                    </div>
                    <input type="hidden" name="team_discount_pct" id="teamDiscountPct" value="0">
                    <input type="hidden" name="team_player_count" id="teamPlayerCount" value="0">
                    
                    <div id="teamFields" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid rgba(0,0,0,.1);">
                        <div class="row grid" style="margin-bottom:12px;">
                            <div><label class="label">Team/Club Name *</label>
                                <input type="text" name="team_name" class="input" id="teamName" placeholder="e.g., FC Lightning U12">
                            </div>
                            <div><label class="label">Coach/Manager Email *</label>
                                <input type="email" name="team_contact_email" class="input" id="teamEmail" placeholder="For coordination">
                            </div>
                        </div>
                        
                        <div style="background:#fff;border:1px solid var(--gray2);border-radius:8px;padding:12px;margin-bottom:12px;">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                                <span style="font-weight:600;font-size:13px;">Team Roster</span>
                                <button type="button" id="addTeamPlayer" class="discount-btn" style="padding:8px 14px;font-size:12px;">+ Add Player</button>
                            </div>
                            
                            <div id="teamRoster">
                                <!-- Player entries will be added here -->
                            </div>
                            
                            <p style="font-size:11px;color:#666;margin-top:10px;">Minimum 5 players required for team discount. Each player needs: name, date of birth, and t-shirt size.</p>
                        </div>
                        
                        <div id="teamDiscountMsg" class="discount-applied" style="display:none;background:rgba(252,185,0,.1);border-color:var(--gold);color:var(--black);">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><polyline points="20 6 9 17 4 12"/></svg>
                            <span id="teamDiscountText">15% team discount applied!</span>
                        </div>
                        <div id="teamMinMsg" style="display:block;font-size:12px;color:var(--gray6);padding:8px 0;">
                            Add at least 5 players to activate the 15% team discount
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="sec">
                <div class="sec-h"><span class="sec-n"><?php echo $has_camps ? ($has_training ? '7' : '6') : ($has_training ? '6' : '5'); ?></span><h2 class="sec-t">Payment</h2></div>
                <?php if (empty($client_secret) && !empty($stripe_pk)): ?>
                <!-- v117.2.5: Show payment error immediately -->
                <div style="padding:20px;background:#fee2e2;border:2px solid #ef4444;border-radius:10px;color:#991b1b;text-align:center;margin-bottom:16px;">
                    <strong style="font-size:16px;">⚠️ Payment System Error</strong><br><br>
                    <span style="font-size:14px;"><?php echo esc_html($pi_error ?: 'Unable to initialize payment. Please refresh the page.'); ?></span>
                    <?php if ($ptp_debug): ?>
                    <div style="margin-top:12px;padding:12px;background:#fecaca;border-radius:6px;text-align:left;font-size:11px;font-family:monospace;">
                        <strong>Debug Info:</strong><br>
                        Stripe Mode: <?php echo $stripe_mode; ?><br>
                        PK: <?php echo !empty($stripe_pk) ? 'Set' : 'MISSING'; ?><br>
                        SK: <?php echo !empty($stripe_sk) ? 'Set' : 'MISSING'; ?><br>
                        Total: $<?php echo number_format($total, 2); ?> (<?php echo $cents; ?> cents)<br>
                        Items: <?php echo count($items); ?><br>
                        PI Error: <?php echo $pi_error ?: 'None'; ?>
                    </div>
                    <?php endif; ?>
                    <br><button type="button" onclick="window.location.reload()" style="padding:10px 20px;background:#ef4444;color:white;border:none;border-radius:6px;font-weight:600;cursor:pointer;">Refresh Page</button>
                </div>
                <?php elseif (empty($stripe_pk)): ?>
                <div style="padding:20px;background:#fef3c7;border:2px solid #f59e0b;border-radius:10px;color:#92400e;text-align:center;">
                    <strong>⚠️ Payment Not Configured</strong><br>
                    Please contact support - the payment system is not set up.
                </div>
                <?php endif; ?>
                <div id="pay-el"></div>
                <div class="pay-err" id="payErr"></div>
                <input type="hidden" name="payment_method_id" id="pmId">
            </div>
            
            <button type="submit" class="submit" id="submitBtn" <?php echo (empty($client_secret) || empty($stripe_pk)) ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : ''; ?>>
                <span class="spin"></span>
                <span>Complete Registration · $<span id="submitTotal"><?php echo number_format($total, 2); ?></span></span>
            </button>
            
            <div class="trust">
                <span>🔒 256-bit SSL</span>
                <span>🛡 Secure</span>
                <span>✓ Guaranteed</span>
            </div>
        </form>
    </div>
    
    <div class="summary" id="summary">
        <h3 class="sum-t">ORDER SUMMARY</h3>
        
        <?php foreach ($items as $item): ?>
        <div class="sum-item">
            <div class="sum-img"><?php if ($item['img']): ?><img src="<?php echo esc_url($item['img']); ?>" alt="" loading="lazy"><?php endif; ?></div>
            <div class="sum-info">
                <span class="sum-type <?php echo $item['type']; ?>"><?php 
                    if ($item['type'] === 'camp') echo 'Camp';
                    elseif ($item['type'] === 'clinic') echo 'Clinic';
                    elseif ($item['type'] === 'training') echo 'Training';
                    else echo 'Item';
                ?></span>
                <div class="sum-name"><?php echo esc_html($item['name']); ?></div>
                <?php if ($item['date']): ?>
                <div class="sum-meta"><?php echo date('M j', strtotime($item['date'])); ?><?php if (!empty($item['time'])): ?> · <?php echo $item['time']; ?><?php endif; ?><?php if ($item['loc']): ?> · <?php echo esc_html($item['loc']); ?><?php endif; ?></div>
                <?php endif; ?>
                <?php if (!empty($item['group_size']) && $item['group_size'] > 1): ?>
                <div class="sum-group">$<?php echo intval($item['price'] / $item['group_size']); ?> per player</div>
                <?php endif; ?>
            </div>
            <div class="sum-price" data-key="<?php echo esc_attr($item['k']); ?>">$<?php echo number_format($item['price'], 2); ?></div>
        </div>
        <?php endforeach; ?>
        
        <?php // v91: Jersey item if already added ?>
        <?php if ($jersey_upsell_added): ?>
        <div class="sum-jersey" id="jerseyItem">
            <div class="sum-jersey-img">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.38 3.46L16 2a4 4 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10a2 2 0 002 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/></svg>
            </div>
            <div class="sum-jersey-info">
                <div class="sum-name">WC 2026 Jersey</div>
            </div>
            <div>
                <div class="sum-price">$<?php echo number_format($jersey_price, 2); ?></div>
                <span class="sum-jersey-remove" id="removeJersey">×</span>
            </div>
        </div>
        <?php endif; ?>
        
        <?php // v91: Jersey upsell card (show only for summer camps, when not already added) ?>
        <?php if ($has_summer_camp && !$jersey_upsell_added): ?>
        <div class="jersey-upsell" id="jerseyUpsell">
            <div class="jersey-upsell-content">
                <div class="jersey-upsell-img">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.38 3.46L16 2a4 4 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10a2 2 0 002 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/></svg>
                </div>
                <div class="jersey-upsell-info">
                    <div class="jersey-upsell-title">WC 2026 Jersey</div>
                </div>
                <div class="jersey-upsell-right">
                    <span class="jersey-upsell-cost">$50</span>
                    <span class="jersey-upsell-was">$75</span>
                    <button type="button" class="jersey-upsell-btn" id="addJerseyBtn">+ Add</button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php // v117: Camp upsell (show when training in cart but NO camps) ?>
        <?php if ($has_training && !$has_camps && !$has_summer_camp): 
            // Get upcoming camps for checkout upsell
            $checkout_camps = array();
            if (function_exists('wc_get_products')) {
                $camp_prods = wc_get_products(array(
                    'status' => 'publish',
                    'limit' => 2,
                    'category' => array('camps', 'summer-camps'),
                    'orderby' => 'date',
                    'order' => 'ASC',
                ));
                foreach ($camp_prods as $cp) {
                    $checkout_camps[] = array(
                        'id' => $cp->get_id(),
                        'name' => $cp->get_name(),
                        'price' => $cp->get_price(),
                    );
                }
            }
            if (!empty($checkout_camps)):
        ?>
        <div class="checkout-camp-upsell" style="background:#0A0A0A;border-radius:10px;padding:16px;margin-top:14px;">
            <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">
                <div style="width:36px;height:36px;background:rgba(252,185,0,0.15);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2" width="20" height="20"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                </div>
                <div>
                    <div style="color:#FCB900;font-family:Oswald,sans-serif;font-weight:700;font-size:14px;text-transform:uppercase;">⚽ Add a Summer Camp</div>
                    <div style="color:rgba(255,255,255,0.6);font-size:11px;">Train all week with PTP coaches</div>
                </div>
            </div>
            <?php foreach ($checkout_camps as $cc): ?>
            <div style="display:flex;align-items:center;gap:10px;background:#1a1a1a;border-radius:8px;padding:10px;margin-bottom:8px;" data-camp-id="<?php echo $cc['id']; ?>">
                <div style="flex:1;min-width:0;">
                    <div style="color:#fff;font-size:12px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?php echo esc_html($cc['name']); ?></div>
                    <div style="color:#FCB900;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;">$<?php echo number_format($cc['price'], 0); ?></div>
                </div>
                <button type="button" onclick="addCheckoutCamp(<?php echo $cc['id']; ?>, this)" style="background:#FCB900;color:#0A0A0A;border:none;padding:10px 14px;border-radius:6px;font-family:Oswald,sans-serif;font-size:11px;font-weight:700;text-transform:uppercase;cursor:pointer;white-space:nowrap;min-height:44px;">+ Add</button>
            </div>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(home_url('/ptp-shop-page/')); ?>" style="display:block;text-align:center;color:#FCB900;font-size:12px;margin-top:8px;text-decoration:none;">View All Camps →</a>
        </div>
        <?php endif; endif; ?>
        
        <!-- Upgrade Camps Container (populated by JS) -->
        <div id="upgradeCampsContainer" style="display:none;"></div>
        
        <div class="sum-totals">
            <div class="sum-line"><span>Subtotal</span><span id="sumSub">$<?php echo number_format($subtotal, 2); ?></span></div>
            <?php if ($bundle_discount > 0): ?>
            <div class="sum-line disc"><span>Bundle Discount (15%)</span><span id="sumBundle">-$<?php echo number_format($bundle_discount, 2); ?></span></div>
            <?php endif; ?>
            <div class="sum-line disc" id="siblingLine" style="display:none;"><span>Sibling Registration</span><span id="sumSibling">+$0.00</span></div>
            <div class="sum-line disc" id="siblingDiscLine" style="display:none;"><span>Sibling Discount (10%)</span><span id="sumSiblingDisc">-$0.00</span></div>
            <div class="sum-line disc" id="referralLine" style="display:none;"><span>Referral Discount</span><span id="sumReferral">-$25.00</span></div>
            <div class="sum-line" id="teamPlayersLine" style="display:none;"><span id="teamPlayersLabel">Team Players (0)</span><span id="sumTeamPlayers">+$0.00</span></div>
            <div class="sum-line disc" id="teamLine" style="display:none;"><span>Team Discount (15%)</span><span id="sumTeam">-$0.00</span></div>
            <div class="sum-line" id="jerseyLine" style="<?php echo $jersey_upsell_added ? '' : 'display:none;'; ?>"><span>Jersey Add-On</span><span id="sumJersey">$<?php echo number_format($jersey_price, 2); ?></span></div>
            <div class="sum-line" id="careLine" style="display:none;"><span>Before & After Care</span><span id="sumCare">+$0.00</span></div>
            <div class="sum-line" id="upgradeLine" style="display:none;"><span id="upgradeLabel">Camp Pack Upgrade</span><span id="sumUpgrade">+$0.00</span></div>
            <div class="sum-line disc" id="upgradeSaveLine" style="display:none;"><span id="upgradeSaveLabel">Multi-Week Savings</span><span id="sumUpgradeSave">-$0.00</span></div>
            <div class="sum-line fee"><span>Card Processing (3% + $0.30)</span><span id="sumFee">$<?php echo number_format($processing_fee, 2); ?></span></div>
            <div class="sum-total"><span>Total</span><span id="sumTotal">$<?php echo number_format($total, 2); ?></span></div>
        </div>
        
        <!-- Hidden fields for discount tracking -->
        <input type="hidden" name="base_subtotal" id="baseSubtotal" value="<?php echo $subtotal; ?>">
        <input type="hidden" name="bundle_discount" id="bundleDiscount" value="<?php echo $bundle_discount; ?>">
        <input type="hidden" name="sibling_amount" id="siblingAmount" value="0">
        <input type="hidden" name="sibling_discount" id="siblingDiscountVal" value="0">
        <input type="hidden" name="referral_amount" id="referralAmount" value="0">
        <input type="hidden" name="team_players_amount" id="teamPlayersAmountField" value="0">
        <input type="hidden" name="team_discount_amount" id="teamDiscountAmount" value="0">
        <input type="hidden" name="jersey_upsell" id="jerseyUpsellField" value="<?php echo $jersey_upsell_added ? '1' : '0'; ?>">
        <input type="hidden" name="jersey_amount" id="jerseyAmountField" value="<?php echo $jersey_upsell_added ? 50 : 0; ?>">
        <input type="hidden" name="final_total" id="finalTotal" value="<?php echo $total; ?>">
        
        <div class="guarantee">
            <h4>✓ Satisfaction Guaranteed</h4>
            <p>Full refund up to 14 days before program start.</p>
        </div>
    </div>
</div>

<div class="mobile-bar">
    <div class="mobile-toggle" id="mobileToggle">▲</div>
    <div class="mobile-info">
        <div class="mobile-items"><?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?></div>
        <div class="mobile-total">$<span id="mobileTotal"><?php echo number_format($total, 2); ?></span></div>
    </div>
    <button type="button" class="mobile-cta" id="mobileCta">Complete Order</button>
</div>

<?php if ($stripe_pk && $client_secret): ?>
<script src="https://js.stripe.com/v3/"></script>
<script>
console.log('[PTP Checkout v131] Script loaded');

// v131: Multi-player form functions
function selectSavedPlayer(selectEl, playerIndex) {
    var card = selectEl.closest('.group-player-card');
    var option = selectEl.options[selectEl.selectedIndex];
    
    if (!option.value) {
        // Clear fields for new player entry
        card.querySelector('.player-id-field').value = '';
        card.querySelector('.player-fn').value = '';
        card.querySelector('.player-ln').value = '';
        card.querySelector('.player-dob').value = '';
        card.querySelector('.player-team').value = '';
        card.querySelector('.player-skill').value = '';
        return;
    }
    
    // Populate fields from saved player data
    card.querySelector('.player-id-field').value = option.value;
    card.querySelector('.player-fn').value = option.dataset.fn || '';
    card.querySelector('.player-ln').value = option.dataset.ln || '';
    card.querySelector('.player-dob').value = option.dataset.dob || '';
    card.querySelector('.player-team').value = option.dataset.team || '';
    card.querySelector('.player-skill').value = option.dataset.skill || '';
}

function copyFromPlayer1(targetIndex) {
    var player1Card = document.querySelector('.group-player-card[data-player-index="0"]');
    var targetCard = document.querySelector('.group-player-card[data-player-index="' + targetIndex + '"]');
    
    if (!player1Card || !targetCard) return;
    
    // Copy team and skill (not name/dob since those are unique per player)
    targetCard.querySelector('.player-team').value = player1Card.querySelector('.player-team').value;
    targetCard.querySelector('.player-skill').value = player1Card.querySelector('.player-skill').value;
    
    // Visual feedback
    targetCard.style.borderColor = 'var(--gold)';
    setTimeout(function() {
        targetCard.style.borderColor = '#e5e5e5';
    }, 1000);
}

// v131: Validate all players in multi-player form
function validateMultiPlayerForm() {
    var playerCards = document.querySelectorAll('.group-player-card');
    var errors = [];
    
    playerCards.forEach(function(card, index) {
        var fn = card.querySelector('.player-fn').value.trim();
        var ln = card.querySelector('.player-ln').value.trim();
        var dob = card.querySelector('.player-dob').value.trim();
        
        if (!fn) errors.push('Player ' + (index + 1) + ': First name required');
        if (!ln) errors.push('Player ' + (index + 1) + ': Last name required');
        if (!dob) errors.push('Player ' + (index + 1) + ': Date of birth required');
    });
    
    return errors;
}

// v131: Collect all player data from multi-player form
function collectMultiPlayerData() {
    var playerCards = document.querySelectorAll('.group-player-card');
    var players = [];
    
    playerCards.forEach(function(card, index) {
        players.push({
            player_id: card.querySelector('.player-id-field').value,
            first_name: card.querySelector('.player-fn').value.trim(),
            last_name: card.querySelector('.player-ln').value.trim(),
            dob: card.querySelector('.player-dob').value,
            shirt_size: card.querySelector('.player-shirt') ? card.querySelector('.player-shirt').value : '',
            team: card.querySelector('.player-team').value.trim(),
            skill: card.querySelector('.player-skill').value
        });
    });
    
    return players;
}

(function(){
// v117.2.9: Immediately prevent form submission until proper handler is set up
var form = document.getElementById('form');
if (form) {
    form.onsubmit = function(e) { 
        e.preventDefault(); 
        e.stopPropagation();
        console.log('[PTP Checkout] Early form handler triggered - Stripe still loading');
        return false; 
    };
    console.log('[PTP Checkout v117.2.9] Early form handler installed');
}

var stripe, els, payEl;
var clientSecret='<?php echo esc_js($client_secret); ?>';
var checkoutSession='<?php echo esc_js($checkout_session_id); ?>';
var stripeReady = false;

try {
    stripe=Stripe('<?php echo esc_js($stripe_pk); ?>');
    els=stripe.elements({
        clientSecret: clientSecret,
        appearance:{
            theme:'stripe',
            variables:{colorPrimary:'#FCB900',borderRadius:'8px'}
        }
    });
    payEl=els.create('payment',{
        layout:'tabs',
        wallets: {applePay: 'auto', googlePay: 'auto'}
    });
    payEl.mount('#pay-el');
    stripeReady = true;
    console.log('[PTP Checkout v117.2.9] Stripe Elements mounted successfully');
} catch (stripeInitErr) {
    console.error('[PTP Checkout v117.2.9] Stripe initialization failed:', stripeInitErr);
    document.getElementById('pay-el').innerHTML = '<div style="padding:20px;background:#fee2e2;border:1px solid #ef4444;border-radius:8px;color:#991b1b;text-align:center;"><strong>Payment Error:</strong> ' + stripeInitErr.message + '<br><br>Please refresh or contact support.</div>';
    document.getElementById('submitBtn').disabled = true;
}

// v117.2.8: Show payment error from redirect if present
<?php if (!empty($payment_error_from_redirect)): ?>
setTimeout(function() {
    var errEl = document.getElementById('payErr');
    if (errEl) {
        errEl.textContent = <?php echo json_encode($payment_error_from_redirect); ?>;
        errEl.style.display = 'block';
        // Scroll to error
        errEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
}, 500);
<?php endif; ?>

<?php if ($cents >= 50): ?>
// Express checkout
var expEl=els.create('expressCheckout',{buttonType:{applePay:'buy',googlePay:'buy'}});
expEl.mount('#express-el');
expEl.on('confirm',async function(e){
    if (!validateRequiredFields()) {
        showErr('Please complete all required fields first');
        return;
    }
    // v115.5.1: Save form data first, then confirm payment with session in return URL
    var formData = new FormData(document.getElementById('form'));
    formData.append('checkout_session', checkoutSession);
    await fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',body:formData});
    
    var {error}=await stripe.confirmPayment({elements:els,confirmParams:{return_url:'<?php echo home_url('/thank-you/?session='); ?>'+checkoutSession}});
    if(error)showErr(error.message);
});

// Required fields validation
function validateRequiredFields() {
    // v131: Check if this is a multi-player form
    var isMultiPlayer = document.getElementById('groupPlayerCount') !== null;
    
    if (isMultiPlayer) {
        // Validate all player cards
        var playerCards = document.querySelectorAll('.group-player-card');
        var valid = true;
        
        playerCards.forEach(function(card, index) {
            var fn = card.querySelector('.player-fn');
            var ln = card.querySelector('.player-ln');
            var dob = card.querySelector('.player-dob');
            
            if (!fn || !fn.value.trim()) {
                valid = false;
                if (fn) fn.style.borderColor = '#ef4444';
            } else if (fn) {
                fn.style.borderColor = '';
            }
            
            if (!ln || !ln.value.trim()) {
                valid = false;
                if (ln) ln.style.borderColor = '#ef4444';
            } else if (ln) {
                ln.style.borderColor = '';
            }
            
            if (!dob || !dob.value.trim()) {
                valid = false;
                if (dob) dob.style.borderColor = '#ef4444';
            } else if (dob) {
                dob.style.borderColor = '';
            }
        });
        
        // Still need to validate parent/guardian and emergency fields
        var parentRequired = ['parent_first_name', 'parent_last_name', 'parent_email', 'parent_phone'];
        parentRequired.forEach(function(name) {
            var el = document.querySelector('[name="' + name + '"]');
            if (el && !el.value.trim()) {
                valid = false;
                el.style.borderColor = '#ef4444';
            } else if (el) {
                el.style.borderColor = '';
            }
        });
        
        // Check waiver
        var waiver = document.getElementById('waiver');
        if (waiver && !waiver.checked) {
            valid = false;
        }
        
        return valid;
    }
    
    // Original single-player validation
    var required = [
        'camperFirst', 'camperLast', 'camperDob', 'camperShirt',
        'parentFirst', 'parentLast', 'parentEmail', 'parentPhone',
        'emergName', 'emergPhone', 'emergRel'
    ];
    var valid = true;
    required.forEach(function(id) {
        var el = document.getElementById(id);
        if (el && !el.value.trim()) {
            valid = false;
        }
    });
    // Check waiver
    var waiver = document.getElementById('waiver');
    if (waiver && !waiver.checked) {
        valid = false;
    }
    return valid;
}

// Check and unlock express checkout when fields are filled
function checkExpressUnlock() {
    var section = document.getElementById('express-section');
    if (validateRequiredFields()) {
        section.classList.add('unlocked');
    } else {
        section.classList.remove('unlocked');
    }
}

// Listen for input changes
document.querySelectorAll('input, select, textarea').forEach(function(el) {
    el.addEventListener('change', checkExpressUnlock);
    el.addEventListener('input', checkExpressUnlock);
});
<?php endif; ?>

// v87: Hide Bank, Cash App tabs - keep Card and Affirm
setTimeout(function() {
    var hidePaymentTabs = function() {
        document.querySelectorAll('.p-Tab, [data-testid]').forEach(function(tab) {
            var text = (tab.textContent || '').toLowerCase();
            if (text.includes('bank') || text.includes('cash app') || text.includes('$5')) {
                tab.style.display = 'none';
            }
        });
    };
    hidePaymentTabs();
    var observer = new MutationObserver(hidePaymentTabs);
    var paymentEl = document.getElementById('pay-el');
    if (paymentEl) observer.observe(paymentEl, {childList: true, subtree: true});
}, 1000);

var pkgs=<?php echo json_encode($pkgs); ?>,wooSub=<?php echo $subtotal - ($has_training ? $training_price : 0); ?>,bundlePct=<?php echo $bundle_discount > 0 ? 15 : 0; ?>,curPkg='<?php echo $pkg_key; ?>';

function updateTotals(tp){
    var sub=wooSub+tp,disc=bundlePct?Math.round(sub*bundlePct)/100:0;
    var afterDisc=sub-disc;
    var fee=Math.round((afterDisc*0.03+0.30)*100)/100; // 3% + $0.30
    var tot=afterDisc+fee;
    document.getElementById('sumSub').textContent='$'+sub.toFixed(2);
    var d=document.getElementById('sumDisc');if(d)d.textContent='-$'+disc.toFixed(2);
    var f=document.getElementById('sumFee');if(f)f.textContent='$'+fee.toFixed(2);
    document.getElementById('sumTotal').textContent='$'+tot.toFixed(2);
    document.getElementById('submitTotal').textContent=tot.toFixed(2);
    document.getElementById('mobileTotal').textContent=tot.toFixed(2);
    document.getElementById('cartTotal').value=tot.toFixed(2);
    var tp=document.querySelector('[data-key^="training_"]');if(tp)tp.textContent='$'+tp.toFixed(2);
    els.update({amount:Math.round(tot*100)});
}

document.querySelectorAll('.pkg').forEach(function(p){p.onclick=function(){
    document.querySelectorAll('.pkg').forEach(function(x){x.classList.remove('sel')});
    p.classList.add('sel');p.querySelector('input').checked=true;
    curPkg=p.dataset.pk;document.getElementById('pkgInput').value=curPkg;
    var trainingTotalEl=document.getElementById('trainingTotal');if(trainingTotalEl)trainingTotalEl.value=p.dataset.pr;
    updateTotals(parseInt(p.dataset.pr));
    updateRecurringSummary();
}});

// ============================================
// RECURRING SESSIONS HANDLERS
// ============================================
var recurringEnabled = document.getElementById('recurringEnabled');
var recurringOptions = document.getElementById('recurringOptions');
var recurringBox = document.getElementById('recurringBox');
var recurringWeeks = document.getElementById('recurringWeeks');

if (recurringEnabled) {
    recurringEnabled.onchange = function() {
        if (this.checked) {
            recurringOptions.style.display = 'block';
            recurringBox.classList.add('active');
        } else {
            recurringOptions.style.display = 'none';
            recurringBox.classList.remove('active');
        }
        updateRecurringSummary();
    };
}

// Frequency toggle
document.querySelectorAll('.freq-opt').forEach(function(opt) {
    opt.onclick = function() {
        document.querySelectorAll('.freq-opt').forEach(function(x) { x.classList.remove('sel'); });
        opt.classList.add('sel');
        opt.querySelector('input').checked = true;
        updateRecurringSummary();
    };
});

// Weeks selector
if (recurringWeeks) {
    recurringWeeks.onchange = updateRecurringSummary;
}

function updateRecurringSummary() {
    var totalEl = document.getElementById('recurringTotalSessions');
    var savingsEl = document.getElementById('recurringSavings');
    if (!totalEl || !recurringEnabled || !recurringEnabled.checked) return;
    
    var weeks = parseInt(recurringWeeks.value) || 8;
    var freq = document.querySelector('.freq-opt.sel input')?.value || 'weekly';
    var sessions = freq === 'biweekly' ? Math.ceil(weeks / 2) : weeks;
    
    // Multiply by package sessions
    var pkgSessions = curPkg === 'pack5' ? 5 : (curPkg === 'pack3' ? 3 : 1);
    var totalSessions = sessions * pkgSessions;
    
    totalEl.textContent = totalSessions;
    
    // Calculate savings (15% for recurring)
    var savingsPct = curPkg === 'single' ? '15%' : (curPkg === 'pack3' ? '22%' : '28%');
    savingsEl.textContent = savingsPct;
}

updateRecurringSummary();

document.querySelectorAll('.saved-p').forEach(function(p){p.onclick=function(){
    document.querySelectorAll('.saved-p').forEach(function(x){x.classList.remove('sel')});
    p.classList.add('sel');document.getElementById('playerId').value=p.dataset.id;
    document.getElementById('playerFields').style.display='none';
}});

var addBtn=document.getElementById('addNew');
if(addBtn)addBtn.onclick=function(){
    document.querySelectorAll('.saved-p').forEach(function(x){x.classList.remove('sel')});
    document.getElementById('playerId').value='';
    document.getElementById('playerFields').style.display='block';
    document.querySelectorAll('#playerFields [required]').forEach(function(f){f.required=true});
};

// v117.2.10: Add null check
var mobileToggle = document.getElementById('mobileToggle');
if (mobileToggle) {
    mobileToggle.onclick=function(){document.getElementById('summary').classList.toggle('open')};
}

// v91: Mobile CTA scrolls to and triggers form submit
var mobileCta = document.getElementById('mobileCta');
if (mobileCta) {
    mobileCta.onclick = function() {
        var submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
            setTimeout(function() { submitBtn.click(); }, 400);
        }
    };
}

// ============================================
// v91: JERSEY UPSELL HANDLERS
// ============================================
var addJerseyBtn = document.getElementById('addJerseyBtn');
var removeJerseyBtn = document.getElementById('removeJersey');

function toggleJersey(add) {
    var formData = new FormData();
    formData.append('action', 'ptp_toggle_jersey_upsell');
    formData.append('nonce', '<?php echo wp_create_nonce('ptp_cart'); ?>');
    formData.append('add', add ? '1' : '0');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
        if (data.success) {
            discountState.jerseyAdded = add;
            document.getElementById('jerseyUpsellField').value = add ? '1' : '0';
            document.getElementById('jerseyAmountField').value = add ? discountState.jerseyPrice : 0;
            
            // Update UI
            var upsellCard = document.getElementById('jerseyUpsell');
            var jerseyItem = document.getElementById('jerseyItem');
            
            if (add) {
                // Hide upsell card, show item
                if (upsellCard) upsellCard.style.display = 'none';
                // Create jersey item if not exists
                if (!jerseyItem) {
                    var itemHtml = '<div class="sum-jersey" id="jerseyItem">' +
                        '<div class="sum-jersey-img"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.38 3.46L16 2a4 4 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10a2 2 0 002 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/></svg></div>' +
                        '<div class="sum-jersey-info"><div class="sum-name">WC 2026 Jersey</div></div>' +
                        '<div><div class="sum-price">$' + discountState.jerseyPrice.toFixed(2) + '</div><span class="sum-jersey-remove" id="removeJersey">×</span></div></div>';
                    var sumTotals = document.querySelector('.sum-totals');
                    if (sumTotals) sumTotals.insertAdjacentHTML('beforebegin', itemHtml);
                    // Re-bind remove handler
                    document.getElementById('removeJersey').onclick = function() { toggleJersey(false); };
                } else {
                    jerseyItem.style.display = 'flex';
                }
                if (addJerseyBtn) {
                    addJerseyBtn.textContent = '✓ Added';
                    addJerseyBtn.classList.add('added');
                }
            } else {
                // Show upsell card, hide item
                if (upsellCard) upsellCard.style.display = 'block';
                if (jerseyItem) jerseyItem.style.display = 'none';
                if (addJerseyBtn) {
                    addJerseyBtn.textContent = '+ Add';
                    addJerseyBtn.classList.remove('added');
                }
            }
            
            updateAllTotals();
            
            // Update mobile item count
            var mobileItems = document.querySelector('.mobile-items');
            if (mobileItems) {
                var itemCount = <?php echo count($items); ?> + (add ? 1 : 0) - (discountState.jerseyAdded && !add ? 1 : 0);
                mobileItems.textContent = itemCount + ' item' + (itemCount !== 1 ? 's' : '');
            }
        }
    })
    .catch(function(err) {
        console.error('Jersey toggle error:', err);
    });
}

if (addJerseyBtn) {
    addJerseyBtn.onclick = function() { toggleJersey(true); };
}
if (removeJerseyBtn) {
    removeJerseyBtn.onclick = function() { toggleJersey(false); };
}

// ============================================
// DISCOUNT CALCULATION SYSTEM
// ============================================
var discountState = {
    baseSubtotal: <?php echo $subtotal; ?>,
    bundleDiscount: <?php echo $bundle_discount; ?>,
    campPrice: <?php echo isset($camp_price) ? $camp_price : 0; ?>,
    siblingAdded: false,
    siblingAmount: 0,
    siblingDiscount: 0,
    referralDiscount: 0,
    teamDiscount: 0,
    teamDiscountPct: 0,
    teamPlayersAmount: 0,
    jerseyAdded: <?php echo $jersey_upsell_added ? 'true' : 'false'; ?>,
    jerseyPrice: <?php echo $jersey_price; ?>,
    upgradeSelected: '',
    upgradeAmount: 0,
    upgradeSave: 0,
    upgradeCamps: [],
    careAdded: false,
    careAmount: 0
};

// Before/After Care handling
var careCheckbox = document.getElementById('beforeAfterCare');
if (careCheckbox) {
    careCheckbox.onchange = function() {
        discountState.careAdded = this.checked;
        discountState.careAmount = this.checked ? <?php echo isset($care_price) ? $care_price : 60; ?> : 0;
        document.getElementById('careAmount').value = discountState.careAmount;
        updateAllTotals();
    };
}

// Camp Picker Modal Logic
var campPickerModal = document.getElementById('campPickerModal');
var campsNeeded = 0;
var selectedCamps = []; // Array of {id, name} objects
var currentUpgradeOpt = null;

console.log('PTP Checkout: Available camps count:', typeof availableCampsCount !== 'undefined' ? availableCampsCount : 0);

function openCampPicker(needed) {
    console.log('Opening camp picker, need', needed, 'camps');
    campsNeeded = needed;
    selectedCamps = [];
    
    // Check if we have camps available
    var campItems = document.querySelectorAll('.camp-picker-item');
    console.log('Camp items found in modal:', campItems.length);
    
    if (campItems.length === 0) {
        alert('No additional camp weeks are currently available. Please contact us for more options!');
        // Deselect the upgrade option
        if (currentUpgradeOpt) {
            currentUpgradeOpt.classList.remove('selected');
            var radio = currentUpgradeOpt.querySelector('input[type="radio"]');
            if (radio) radio.checked = false;
            currentUpgradeOpt = null;
        }
        discountState.upgradeSelected = '';
        discountState.upgradeAmount = 0;
        document.getElementById('upgradeSelected').value = '';
        document.getElementById('upgradeAmount').value = 0;
        updateAllTotals();
        return;
    }
    
    // Update modal text
    document.getElementById('campsNeededCount').textContent = needed;
    document.getElementById('campPickerPlural').textContent = needed > 1 ? 's' : '';
    document.getElementById('campPickerPlural2').textContent = needed > 1 ? 's' : '';
    document.getElementById('requiredCampsCount').textContent = needed;
    document.getElementById('selectedCampsCount').textContent = '0';
    
    // Reset all checkboxes
    campItems.forEach(function(item) {
        item.classList.remove('selected');
        var checkbox = item.querySelector('input');
        if (checkbox) checkbox.checked = false;
    });
    
    // Disable confirm button
    document.getElementById('confirmCampSelection').disabled = true;
    
    // Show modal
    campPickerModal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeCampPicker() {
    campPickerModal.style.display = 'none';
    document.body.style.overflow = '';
}

// Camp picker item selection
document.querySelectorAll('.camp-picker-item').forEach(function(item) {
    item.onclick = function(e) {
        e.preventDefault();
        var checkbox = this.querySelector('input');
        var campId = this.dataset.campId;
        var campName = this.dataset.campName;
        
        console.log('Camp item clicked:', campId, campName);
        
        if (this.classList.contains('selected')) {
            // Deselect
            this.classList.remove('selected');
            if (checkbox) checkbox.checked = false;
            selectedCamps = selectedCamps.filter(function(c) { return c.id !== campId; });
        } else {
            // Check if we can select more
            if (selectedCamps.length < campsNeeded) {
                this.classList.add('selected');
                if (checkbox) checkbox.checked = true;
                selectedCamps.push({ id: campId, name: campName });
            }
        }
        
        // Update count and button state
        document.getElementById('selectedCampsCount').textContent = selectedCamps.length;
        document.getElementById('confirmCampSelection').disabled = selectedCamps.length !== campsNeeded;
        
        console.log('Selected camps:', selectedCamps.length, '/', campsNeeded);
    };
});

// Confirm camp selection
var confirmCampBtn = document.getElementById('confirmCampSelection');
if (confirmCampBtn) {
    confirmCampBtn.onclick = function() {
        console.log('Confirm button clicked, selectedCamps:', selectedCamps.length, 'needed:', campsNeeded);
        
        if (selectedCamps.length === campsNeeded && currentUpgradeOpt) {
            // Store camp IDs
            var campIds = selectedCamps.map(function(c) { return c.id; });
            discountState.upgradeCamps = campIds;
        document.getElementById('upgradeCamps').value = campIds.join(',');
        
        console.log('Camps confirmed:', campIds);
        
        // Display selected camps on the upgrade option
        var campNames = selectedCamps.map(function(c) { return c.name; }).join(', ');
        var displayEl = currentUpgradeOpt.querySelector('.selected-camps-display');
        if (!displayEl) {
            displayEl = document.createElement('div');
            displayEl.className = 'selected-camps-display';
            displayEl.style.cssText = 'background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 12px;margin-top:8px;font-size:11px;color:#166534;';
            currentUpgradeOpt.querySelector('.upgrade-opt-info').appendChild(displayEl);
        }
        displayEl.innerHTML = '<strong>✓ Selected:</strong> ' + campNames;
        displayEl.style.display = 'block';
        
        // Update order summary with selected camps
        updateSummaryUpgradeCamps(selectedCamps, discountState.upgradeSelected);
        
        closeCampPicker();
        updateAllTotals();
    }
    };
}

// Update order summary with upgrade camps
function updateSummaryUpgradeCamps(camps, upgradeType) {
    console.log('updateSummaryUpgradeCamps called with:', camps, upgradeType);
    var container = document.getElementById('upgradeCampsContainer');
    console.log('Container found:', !!container);
    if (!container) return;
    
    if (!camps || camps.length === 0) {
        container.style.display = 'none';
        container.innerHTML = '';
        return;
    }
    
    var packLabel = upgradeType === '2pack' ? '2-Camp Pack' : (upgradeType === '3pack' ? '3-Camp Pack' : 'Upgrade');
    
    var html = '<div class="sum-upgrade-header">➕ ' + packLabel + ' Added</div>';
    camps.forEach(function(camp) {
        // Parse camp name to extract date if present
        var name = camp.name;
        var meta = '';
        var parenMatch = name.match(/\(([^)]+)\)/);
        if (parenMatch) {
            meta = parenMatch[1];
            name = name.replace(/\s*\([^)]+\)/, '').trim();
        }
        
        html += '<div class="sum-upgrade-item">' +
            '<div class="sum-upgrade-icon">⚽</div>' +
            '<div class="sum-upgrade-info">' +
                '<div class="sum-upgrade-name">' + name + '</div>' +
                (meta ? '<div class="sum-upgrade-meta">' + meta + '</div>' : '') +
            '</div>' +
        '</div>';
    });
    
    container.innerHTML = html;
    container.style.display = 'block';
    console.log('Container updated, display:', container.style.display);
}

// Clear summary upgrade camps
function clearSummaryUpgradeCamps() {
    var container = document.getElementById('upgradeCampsContainer');
    if (container) {
        container.style.display = 'none';
        container.innerHTML = '';
    }
}

// Show All-Access in summary
function showAllAccessInSummary() {
    var container = document.getElementById('upgradeCampsContainer');
    if (!container) return;
    
    var html = '<div class="sum-upgrade-header">⭐ All-Access Pass</div>' +
        '<div class="sum-upgrade-item">' +
            '<div class="sum-upgrade-icon" style="background:var(--gold);color:var(--black);">✓</div>' +
            '<div class="sum-upgrade-info">' +
                '<div class="sum-upgrade-name">6 Camp Weeks Included</div>' +
            '</div>' +
        '</div>' +
        '<div class="sum-upgrade-item">' +
            '<div class="sum-upgrade-icon" style="background:var(--gold);color:var(--black);">✓</div>' +
            '<div class="sum-upgrade-info">' +
                '<div class="sum-upgrade-name">12 Private Sessions</div>' +
            '</div>' +
        '</div>' +
        '<div class="sum-upgrade-item">' +
            '<div class="sum-upgrade-icon" style="background:var(--gold);color:var(--black);">✓</div>' +
            '<div class="sum-upgrade-info">' +
                '<div class="sum-upgrade-name">6 Skills Clinics</div>' +
            '</div>' +
        '</div>' +
        '<div class="sum-upgrade-item">' +
            '<div class="sum-upgrade-icon" style="background:var(--gold);color:var(--black);">✓</div>' +
            '<div class="sum-upgrade-info">' +
                '<div class="sum-upgrade-name">Video Analysis + Mentorship</div>' +
            '</div>' +
        '</div>';
    
    container.innerHTML = html;
    container.style.display = 'block';
}

// Close modal handlers
var campPickerClose = document.querySelector('.camp-picker-close');
var campPickerOverlay = document.querySelector('.camp-picker-overlay');

if (campPickerClose) {
    campPickerClose.onclick = function() {
        // If closing without selecting, deselect the upgrade option
        if (selectedCamps.length !== campsNeeded) {
            var selectedOpt = document.querySelector('.upgrade-opt.selected');
            if (selectedOpt && selectedOpt.dataset.campsNeeded > 0) {
                selectedOpt.classList.remove('selected');
                selectedOpt.querySelector('input').checked = false;
                discountState.upgradeSelected = '';
                discountState.upgradeAmount = 0;
                discountState.upgradeSave = 0;
                discountState.upgradeCamps = [];
                document.getElementById('upgradeSelected').value = '';
                document.getElementById('upgradeAmount').value = 0;
                document.getElementById('upgradeCamps').value = '';
                clearSummaryUpgradeCamps();
                updateAllTotals();
            }
        }
        closeCampPicker();
    };
}

if (campPickerOverlay) {
    campPickerOverlay.onclick = function() {
        if (campPickerClose) campPickerClose.click();
    };
}

function updateAllTotals() {
    // Calculate sibling values
    if (discountState.siblingAdded && discountState.campPrice > 0) {
        discountState.siblingAmount = discountState.campPrice;
        discountState.siblingDiscount = discountState.campPrice * 0.10; // 10% off sibling
    } else {
        discountState.siblingAmount = 0;
        discountState.siblingDiscount = 0;
    }
    
    // Calculate team registration (additional players + 15% discount on all)
    var teamPlayerCount = document.getElementById('teamPlayerCount') ? parseInt(document.getElementById('teamPlayerCount').value) || 0 : 0;
    var teamPlayersAmount = 0;
    
    if (discountState.teamDiscountPct > 0 && teamPlayerCount > 0) {
        // First player already in cart, add remaining players at camp price
        // Since the main camper form is filled separately, ALL team players are additional
        teamPlayersAmount = discountState.campPrice * teamPlayerCount;
        
        // Total camp amount for discount calculation
        var totalCampAmount = discountState.campPrice + teamPlayersAmount + discountState.siblingAmount - discountState.siblingDiscount;
        discountState.teamDiscount = totalCampAmount * (discountState.teamDiscountPct / 100);
        discountState.teamPlayersAmount = teamPlayersAmount;
    } else {
        discountState.teamDiscount = 0;
        discountState.teamPlayersAmount = 0;
    }
    
    // Calculate new subtotal (includes sibling, team players, jersey, upgrades, and care)
    var jerseyAmount = discountState.jerseyAdded ? discountState.jerseyPrice : 0;
    var upgradeAmount = discountState.upgradeAmount || 0;
    var careAmount = discountState.careAmount || 0;
    var newSubtotal = discountState.baseSubtotal + discountState.siblingAmount + (discountState.teamPlayersAmount || 0) + jerseyAmount + upgradeAmount + careAmount;
    
    // Calculate total discounts
    var totalDiscounts = discountState.bundleDiscount + discountState.siblingDiscount + discountState.referralDiscount + discountState.teamDiscount;
    
    // Calculate amount after discounts
    var afterDiscount = newSubtotal - totalDiscounts;
    
    // Processing fee
    var fee = (afterDiscount * 0.03) + 0.30;
    fee = Math.round(fee * 100) / 100;
    
    // Final total
    var finalTotal = afterDiscount + fee;
    
    // Update display
    document.getElementById('sumSub').textContent = '$' + newSubtotal.toFixed(2);
    document.getElementById('sumFee').textContent = '$' + fee.toFixed(2);
    document.getElementById('sumTotal').textContent = '$' + finalTotal.toFixed(2);
    document.getElementById('submitTotal').textContent = finalTotal.toFixed(2);
    document.getElementById('mobileTotal').textContent = finalTotal.toFixed(2);
    
    // Update before/after care line
    var careLine = document.getElementById('careLine');
    if (careLine) {
        if (discountState.careAdded) {
            careLine.style.display = 'flex';
            document.getElementById('sumCare').textContent = '+$' + discountState.careAmount.toFixed(2);
        } else {
            careLine.style.display = 'none';
        }
    }
    
    // Update sibling lines
    var siblingLine = document.getElementById('siblingLine');
    var siblingDiscLine = document.getElementById('siblingDiscLine');
    if (siblingLine && siblingDiscLine) {
        if (discountState.siblingAdded) {
            siblingLine.style.display = 'flex';
            siblingDiscLine.style.display = 'flex';
            document.getElementById('sumSibling').textContent = '+$' + discountState.siblingAmount.toFixed(2);
            document.getElementById('sumSiblingDisc').textContent = '-$' + discountState.siblingDiscount.toFixed(2);
        } else {
            siblingLine.style.display = 'none';
            siblingDiscLine.style.display = 'none';
        }
    }
    
    // Update referral line
    var referralLine = document.getElementById('referralLine');
    if (referralLine) {
        if (discountState.referralDiscount > 0) {
            referralLine.style.display = 'flex';
            document.getElementById('sumReferral').textContent = '-$' + discountState.referralDiscount.toFixed(2);
        } else {
            referralLine.style.display = 'none';
        }
    }
    
    // Update team discount line
    var teamLine = document.getElementById('teamLine');
    var teamPlayersLine = document.getElementById('teamPlayersLine');
    var teamPlayerCountVal = document.getElementById('teamPlayerCount') ? parseInt(document.getElementById('teamPlayerCount').value) || 0 : 0;
    
    if (teamPlayersLine) {
        if (discountState.teamPlayersAmount > 0) {
            teamPlayersLine.style.display = 'flex';
            document.getElementById('teamPlayersLabel').textContent = 'Team Players (' + teamPlayerCountVal + ')';
            document.getElementById('sumTeamPlayers').textContent = '+$' + discountState.teamPlayersAmount.toFixed(2);
        } else {
            teamPlayersLine.style.display = 'none';
        }
    }
    
    if (teamLine) {
        if (discountState.teamDiscount > 0) {
            teamLine.style.display = 'flex';
            document.getElementById('sumTeam').textContent = '-$' + discountState.teamDiscount.toFixed(2);
        } else {
            teamLine.style.display = 'none';
        }
    }
    
    // v91: Update jersey line
    var jerseyLine = document.getElementById('jerseyLine');
    if (jerseyLine) {
        if (discountState.jerseyAdded) {
            jerseyLine.style.display = 'flex';
            document.getElementById('sumJersey').textContent = '$' + discountState.jerseyPrice.toFixed(2);
        } else {
            jerseyLine.style.display = 'none';
        }
    }
    
    // Update upgrade line
    var upgradeLine = document.getElementById('upgradeLine');
    var upgradeSaveLine = document.getElementById('upgradeSaveLine');
    if (upgradeLine) {
        if (discountState.upgradeAmount > 0) {
            upgradeLine.style.display = 'flex';
            var upgradeLabels = {
                '2pack': '2-Camp Pack (+1 week)',
                '3pack': '3-Camp Pack (+2 weeks)',
                'allaccess': 'All-Access Pass'
            };
            document.getElementById('upgradeLabel').textContent = upgradeLabels[discountState.upgradeSelected] || 'Camp Pack Upgrade';
            document.getElementById('sumUpgrade').textContent = '+$' + discountState.upgradeAmount.toFixed(2);
            
            // Show savings line
            if (upgradeSaveLine && discountState.upgradeSave > 0) {
                upgradeSaveLine.style.display = 'flex';
                document.getElementById('sumUpgradeSave').textContent = '-$' + discountState.upgradeSave.toFixed(2);
            }
        } else {
            upgradeLine.style.display = 'none';
            if (upgradeSaveLine) upgradeSaveLine.style.display = 'none';
        }
    }
    
    // Update hidden fields
    document.getElementById('siblingAmount').value = discountState.siblingAmount;
    document.getElementById('siblingDiscountVal').value = discountState.siblingDiscount;
    document.getElementById('referralAmount').value = discountState.referralDiscount;
    document.getElementById('teamPlayersAmountField').value = discountState.teamPlayersAmount || 0;
    document.getElementById('teamDiscountAmount').value = discountState.teamDiscount;
    document.getElementById('finalTotal').value = finalTotal;
    
    // Update Stripe amount
    var cents = Math.round(finalTotal * 100);
    if (typeof els !== 'undefined' && els.update) {
        els.update({amount: cents});
    }
}

// Sibling discount toggle
var siblingCheck = document.getElementById('addSibling');
var siblingFields = document.getElementById('siblingFields');
if (siblingCheck && siblingFields) {
    siblingCheck.onchange = function() {
        siblingFields.style.display = this.checked ? 'block' : 'none';
        discountState.siblingAdded = this.checked;
        updateAllTotals();
    };
}

// Upgrade pack selection
var upgradeOpts = document.querySelectorAll('.upgrade-opt');
console.log('PTP Checkout: Found', upgradeOpts.length, 'upgrade options');

upgradeOpts.forEach(function(opt) {
    var radio = opt.querySelector('input[type="radio"]');
    if (radio) {
        radio.onchange = function() {
            console.log('Upgrade radio changed:', opt.dataset.upgrade);
            
            // Remove selected class from all and clear camp displays
            upgradeOpts.forEach(function(o) { 
                o.classList.remove('selected');
                var display = o.querySelector('.selected-camps-display');
                if (display) display.style.display = 'none';
            });
            
            // Clear previous upgrade camps from summary when switching
            clearSummaryUpgradeCamps();
            
            if (this.checked) {
                opt.classList.add('selected');
                currentUpgradeOpt = opt; // Track which option is selected
                
                var price = parseFloat(opt.dataset.price) || 0;
                var save = parseFloat(opt.dataset.save) || 0;
                var upgrade = opt.dataset.upgrade;
                var campsNeededCount = parseInt(opt.dataset.campsNeeded) || 0;
                
                console.log('Upgrade selected:', upgrade, 'price:', price, 'campsNeeded:', campsNeededCount);
                
                discountState.upgradeSelected = upgrade;
                discountState.upgradeAmount = price;
                discountState.upgradeSave = save;
                
                document.getElementById('upgradeSelected').value = upgrade;
                document.getElementById('upgradeAmount').value = price;
                
                // If camps need to be selected, open the picker
                if (campsNeededCount > 0) {
                    openCampPicker(campsNeededCount);
                } else {
                    // All-Access doesn't need camp selection - show in summary
                    discountState.upgradeCamps = [];
                    document.getElementById('upgradeCamps').value = '';
                    // Show All-Access in summary
                    showAllAccessInSummary();
                    updateAllTotals();
                }
            } else {
                currentUpgradeOpt = null;
                discountState.upgradeSelected = '';
                discountState.upgradeAmount = 0;
                discountState.upgradeSave = 0;
                discountState.upgradeCamps = [];
                
                document.getElementById('upgradeSelected').value = '';
                document.getElementById('upgradeAmount').value = 0;
                document.getElementById('upgradeCamps').value = '';
                clearSummaryUpgradeCamps();
                updateAllTotals();
            }
        };
    }
    
    // Click on opt card to toggle radio
    opt.onclick = function(e) {
        // Don't trigger if clicking on radio itself
        if (e.target.tagName === 'INPUT') return;
        
        console.log('Upgrade card clicked:', this.dataset.upgrade);
        
        var radio = this.querySelector('input[type="radio"]');
        if (radio) {
            // If already checked, uncheck it (toggle off)
            if (radio.checked) {
                radio.checked = false;
                this.classList.remove('selected');
                currentUpgradeOpt = null;
                discountState.upgradeSelected = '';
                discountState.upgradeAmount = 0;
                discountState.upgradeSave = 0;
                discountState.upgradeCamps = [];
                document.getElementById('upgradeSelected').value = '';
                document.getElementById('upgradeAmount').value = 0;
                document.getElementById('upgradeCamps').value = '';
                // Clear camps display
                var display = this.querySelector('.selected-camps-display');
                if (display) display.style.display = 'none';
                clearSummaryUpgradeCamps();
                updateAllTotals();
            } else {
                // Select this option
                radio.checked = true;
                radio.dispatchEvent(new Event('change'));
            }
        }
    };
});

// Team registration toggle
var teamCheck = document.getElementById('teamReg');
var teamFields = document.getElementById('teamFields');
var teamRoster = document.getElementById('teamRoster');
var teamPlayerCount = 0;

function createPlayerCard(num) {
    var card = document.createElement('div');
    card.className = 'team-player-card';
    card.id = 'teamPlayer_' + num;
    card.innerHTML = `
        <div class="team-player-header">
            <span class="team-player-num">Player ${num}</span>
            <span class="team-player-remove" onclick="removeTeamPlayer(${num})">Remove</span>
        </div>
        <div class="team-player-fields">
            <div>
                <label>First Name *</label>
                <input type="text" name="team_players[${num}][first_name]" class="input" required>
            </div>
            <div>
                <label>Last Name *</label>
                <input type="text" name="team_players[${num}][last_name]" class="input" required>
            </div>
            <div>
                <label>Date of Birth *</label>
                <input type="date" name="team_players[${num}][dob]" class="input" required>
            </div>
            <div>
                <label>T-Shirt Size *</label>
                <select name="team_players[${num}][shirt_size]" class="select" required>
                    <option value="">Select</option>
                    <option value="YS">Youth S</option>
                    <option value="YM">Youth M</option>
                    <option value="YL">Youth L</option>
                    <option value="AS">Adult S</option>
                    <option value="AM">Adult M</option>
                    <option value="AL">Adult L</option>
                    <option value="AXL">Adult XL</option>
                </select>
            </div>
            <div>
                <label>Parent/Guardian Email *</label>
                <input type="email" name="team_players[${num}][parent_email]" class="input" required>
            </div>
            <div>
                <label>Parent/Guardian Phone *</label>
                <input type="tel" name="team_players[${num}][parent_phone]" class="input" required>
            </div>
            <div style="grid-column: 1 / -1;">
                <label>Medical/Allergy Info (if any)</label>
                <input type="text" name="team_players[${num}][medical]" class="input" placeholder="Leave blank if none">
            </div>
        </div>
    `;
    return card;
}

function addTeamPlayer() {
    teamPlayerCount++;
    var card = createPlayerCard(teamPlayerCount);
    teamRoster.appendChild(card);
    updateTeamDiscount();
    document.getElementById('teamPlayerCount').value = teamPlayerCount;
}

function removeTeamPlayer(num) {
    var card = document.getElementById('teamPlayer_' + num);
    if (card) {
        card.remove();
        // Renumber remaining players
        var cards = teamRoster.querySelectorAll('.team-player-card');
        teamPlayerCount = cards.length;
        cards.forEach(function(c, i) {
            c.querySelector('.team-player-num').textContent = 'Player ' + (i + 1);
        });
        updateTeamDiscount();
        document.getElementById('teamPlayerCount').value = teamPlayerCount;
    }
}

function updateTeamDiscount() {
    var cards = teamRoster.querySelectorAll('.team-player-card');
    var count = cards.length;
    var discountMsg = document.getElementById('teamDiscountMsg');
    var minMsg = document.getElementById('teamMinMsg');
    var discountText = document.getElementById('teamDiscountText');
    
    if (count >= 5) {
        discountState.teamDiscountPct = 15;
        document.getElementById('teamDiscountPct').value = 15;
        if (discountMsg) discountMsg.style.display = 'flex';
        if (minMsg) minMsg.style.display = 'none';
        if (discountText) discountText.textContent = '15% team discount applied! ' + count + ' players registered.';
    } else {
        discountState.teamDiscountPct = 0;
        document.getElementById('teamDiscountPct').value = 0;
        if (discountMsg) discountMsg.style.display = 'none';
        if (minMsg) {
            minMsg.style.display = 'block';
            minMsg.textContent = 'Add ' + (5 - count) + ' more player' + (5 - count > 1 ? 's' : '') + ' to activate the 15% team discount';
        }
    }
    updateAllTotals();
}

// Make functions globally available
window.addTeamPlayer = addTeamPlayer;
window.removeTeamPlayer = removeTeamPlayer;

// Add player button
var addPlayerBtn = document.getElementById('addTeamPlayer');
if (addPlayerBtn) {
    addPlayerBtn.onclick = addTeamPlayer;
}

if (teamCheck && teamFields) {
    teamCheck.onchange = function() {
        teamFields.style.display = this.checked ? 'block' : 'none';
        if (this.checked && teamPlayerCount === 0) {
            // Auto-add first 5 players when team reg is enabled
            for (var i = 0; i < 5; i++) {
                addTeamPlayer();
            }
        }
        if (!this.checked) {
            discountState.teamDiscountPct = 0;
            document.getElementById('teamDiscountPct').value = 0;
        }
        updateAllTotals();
    };
}

// Referral code application
var referralBtn = document.getElementById('applyReferral');
var referralInput = document.getElementById('referralCode');
var referralApplied = document.getElementById('referralApplied');
var referralError = document.getElementById('referralError');
if (referralBtn && referralInput) {
    referralBtn.onclick = function() {
        var code = referralInput.value.trim().toUpperCase();
        if (!code) {
            referralError.textContent = 'Please enter a referral code';
            referralError.style.display = 'block';
            return;
        }
        
        referralError.style.display = 'none';
        referralBtn.textContent = '...';
        referralBtn.disabled = true;
        
        // Validate referral code via AJAX
        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=ptp_validate_referral&code=' + encodeURIComponent(code) + '&nonce=<?php echo wp_create_nonce('ptp_checkout'); ?>'
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                var discount = data.data.discount || 25;
                discountState.referralDiscount = discount;
                document.getElementById('referralDiscount').value = discount;
                document.getElementById('referralValidated').value = code;
                referralApplied.style.display = 'flex';
                document.getElementById('referralMsg').textContent = '$' + discount + ' discount applied!';
                referralInput.disabled = true;
                referralBtn.style.display = 'none';
                updateAllTotals();
            } else {
                referralError.textContent = data.data?.message || 'Invalid referral code';
                referralError.style.display = 'block';
                referralBtn.textContent = 'Apply';
                referralBtn.disabled = false;
            }
        })
        .catch(() => {
            referralError.textContent = 'Error validating code. Try again.';
            referralError.style.display = 'block';
            referralBtn.textContent = 'Apply';
            referralBtn.disabled = false;
        });
    };
    
    // Allow Enter key to submit
    referralInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            referralBtn.click();
        }
    });
}

function showErr(m){var e=document.getElementById('payErr');e.textContent=m;e.style.display='block';console.error('[PTP Checkout Error]', m);}

document.getElementById('form').onsubmit=async function(e){
    e.preventDefault();
    console.log('[PTP Checkout v117.2.9] Form submitted, stripeReady=' + stripeReady);
    
    // v117.2.9: Check if Stripe is ready
    if (!stripeReady) {
        showErr('Payment system not ready. Please refresh the page.');
        return;
    }
    
    var btn=document.getElementById('submitBtn');btn.disabled=true;btn.classList.add('loading');
    document.getElementById('payErr').style.display='none';
    
    // v130: Timeout safety - reset button if stuck loading for 45 seconds
    var checkoutTimeout = setTimeout(function() {
        if (btn.classList.contains('loading')) {
            console.error('[PTP Checkout] Timeout - resetting button');
            btn.disabled = false;
            btn.classList.remove('loading');
            showErr('Request timed out. Please check your connection and try again.');
        }
    }, 45000);
    
    if(!document.getElementById('waiver').checked){clearTimeout(checkoutTimeout);showErr('Please accept the waiver');btn.disabled=false;btn.classList.remove('loading');return}
    
    try{
        // Step 1: Save form data to server
        console.log('[PTP Checkout] Step 1: Saving form data...');
        var formData = new FormData(document.getElementById('form'));
        formData.append('checkout_session', checkoutSession);
        formData.append('save_checkout_data', '1');
        
        console.log('[PTP Checkout] Sending to:', '<?php echo admin_url('admin-ajax.php'); ?>');
        var res=await fetch('<?php echo admin_url('admin-ajax.php'); ?>',{method:'POST',body:formData});
        console.log('[PTP Checkout] Response status:', res.status);
        var data=await res.json();
        console.log('[PTP Checkout] Save response:', data);
        
        if(!data.success){
            showErr(data.data?.message||'Please check your form and try again');
            btn.disabled=false;btn.classList.remove('loading');
            return;
        }
        
        // Step 2: Confirm payment with Stripe Elements (clientSecret already in Elements)
        console.log('[PTP Checkout] Step 2: Confirming payment with Stripe...');
        console.log('[PTP Checkout] Return URL:', '<?php echo home_url('/thank-you/?session='); ?>' + checkoutSession);
        var{error:confirmErr, paymentIntent}=await stripe.confirmPayment({
            elements:els,
            confirmParams:{
                return_url: '<?php echo home_url('/thank-you/?session='); ?>' + checkoutSession
            },
            redirect:'if_required'
        });
        
        console.log('[PTP Checkout] Stripe result - error:', confirmErr, 'paymentIntent:', paymentIntent);
        
        if(confirmErr){
            console.error('Payment error:', confirmErr);
            showErr(confirmErr.message);
            btn.disabled=false;btn.classList.remove('loading');
            return;
        }
        
        // v115.5.1: Payment succeeded - create WooCommerce order BEFORE redirect
        console.log('[PTP Checkout] Payment succeeded, creating order...', paymentIntent);
        
        try {
            var orderFormData = new FormData();
            orderFormData.append('action', 'ptp_create_order_after_payment');
            orderFormData.append('checkout_session', checkoutSession);
            orderFormData.append('payment_intent_id', paymentIntent ? paymentIntent.id : '');
            orderFormData.append('ptp_checkout_nonce', document.querySelector('[name="ptp_checkout_nonce"]').value);
            
            var orderRes = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: orderFormData
            });
            var orderData = await orderRes.json();
            
            // v117.2.22: Enhanced debugging
            console.log('[PTP Checkout v117.2.22] AJAX Response:', JSON.stringify(orderData));
            
            // v117.2.21: Build redirect URL with both order_id AND booking_id
            var redirectUrl = '<?php echo home_url('/thank-you/'); ?>?';
            var params = [];
            
            if (orderData.success && orderData.data) {
                console.log('[PTP Checkout v117.2.22] Success! Data:', orderData.data);
                if (orderData.data.order_id) {
                    params.push('order=' + orderData.data.order_id);
                    console.log('[PTP Checkout] Order created: #' + orderData.data.order_id);
                }
                if (orderData.data.booking_id) {
                    params.push('booking=' + orderData.data.booking_id);
                    console.log('[PTP Checkout] Booking created: #' + orderData.data.booking_id);
                }
            } else {
                console.error('[PTP Checkout v117.2.22] AJAX failed or no data:', orderData);
            }
            
            params.push('session=' + checkoutSession);
            if (paymentIntent) {
                params.push('payment_intent=' + paymentIntent.id);
            }
            
            window.location.href = redirectUrl + params.join('&');
        } catch (orderErr) {
            console.error('[PTP Checkout] Order creation error:', orderErr);
            // Fallback redirect
            window.location.href = '<?php echo home_url('/thank-you/?session='); ?>' + checkoutSession + (paymentIntent ? '&payment_intent=' + paymentIntent.id : '');
        }
        
    }catch(err){
        clearTimeout(checkoutTimeout);
        console.error('Checkout error:', err);
        showErr('Error occurred. Please try again.');
        btn.disabled=false;btn.classList.remove('loading');
    }
};
console.log('[PTP Checkout v117.2.9] Full form handler installed, checkout script ready');

// v130: Virtual keyboard detection - adjust mobile bar when keyboard opens
(function() {
    var mobileBar = document.querySelector('.mobile-bar');
    if (!mobileBar || !('visualViewport' in window)) return;
    
    window.visualViewport.addEventListener('resize', function() {
        var keyboardHeight = window.innerHeight - window.visualViewport.height;
        if (keyboardHeight > 100) {
            // Keyboard open - hide mobile bar to not obstruct
            mobileBar.style.transform = 'translateY(100%)';
            mobileBar.style.opacity = '0';
        } else {
            // Keyboard closed - restore
            mobileBar.style.transform = 'translateY(0)';
            mobileBar.style.opacity = '1';
        }
    });
    
    mobileBar.style.transition = 'transform 0.2s ease, opacity 0.2s ease';
})();
})();

// Camp Add to Cart for checkout upsell
function addCheckoutCamp(productId, btn) {
    btn.disabled = true;
    btn.textContent = 'Adding...';
    
    var formData = new FormData();
    formData.append('action', 'ptp_add_camp_to_cart');
    formData.append('product_id', productId);
    formData.append('nonce', '<?php echo wp_create_nonce('ptp_cart'); ?>');
    
    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
        method: 'POST',
        body: formData
    })
    .then(function(r){ return r.json(); })
    .then(function(data) {
        if (data.success) {
            btn.textContent = '✓ Added';
            btn.style.background = '#22C55E';
            setTimeout(function() { window.location.reload(); }, 800);
        } else {
            btn.textContent = 'Try Again';
            btn.disabled = false;
        }
    })
    .catch(function() {
        btn.textContent = 'Try Again';
        btn.disabled = false;
    });
}
</script>
<?php endif; ?>
<?php if (!$empty && $stripe_pk && empty($client_secret)): ?>
<script>
// PaymentIntent creation failed - show error
document.addEventListener('DOMContentLoaded', function() {
    var payEl = document.getElementById('pay-el');
    if (payEl) {
        payEl.innerHTML = '<div style="padding:20px;background:#fee2e2;border:1px solid #ef4444;border-radius:8px;color:#991b1b;text-align:center;"><strong>Payment system error:</strong> <?php echo esc_js($pi_error); ?><br><br>Please refresh the page or contact support.</div>';
    }
    var btn = document.getElementById('submitBtn');
    if (btn) btn.disabled = true;
    
    // Prevent form submission
    var form = document.getElementById('form');
    if (form) {
        form.onsubmit = function(e) {
            e.preventDefault();
            alert('Payment system error: <?php echo esc_js($pi_error); ?>');
            return false;
        };
    }
});
</script>
<?php endif; ?>
<?php if (!$empty && empty($stripe_pk)): ?>
<script>
// Stripe not configured at all
document.addEventListener('DOMContentLoaded', function() {
    var payEl = document.getElementById('pay-el');
    if (payEl) {
        payEl.innerHTML = '<div style="padding:20px;background:#fef3c7;border:2px solid #f59e0b;border-radius:8px;color:#92400e;text-align:center;"><strong>⚠️ Payment Not Configured</strong><br><br>Stripe API keys are missing. Please contact support or check PTP Settings in WordPress admin.</div>';
    }
    var btn = document.getElementById('submitBtn');
    if (btn) {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
    }
    
    // Prevent form submission completely
    var form = document.getElementById('form');
    if (form) {
        form.onsubmit = function(e) {
            e.preventDefault();
            alert('Payment system not configured. Stripe API keys are missing.');
            return false;
        };
    }
});
</script>
<?php endif; ?>
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">

</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
