<?php
/**
 * Unified Cart v92 - Full Width Desktop, Mobile UX, Jersey Upsell
 */
defined('ABSPATH') || exit;

global $wpdb;

// Training items from session
$training_items = array();
if (function_exists('WC') && WC()->session) {
    $training_items = WC()->session->get('ptp_training_items', array());
    
    $current_training = WC()->session->get('ptp_current_training', null);
    if ($current_training && !empty($current_training['trainer_id'])) {
        $already_added = false;
        foreach ($training_items as $t) {
            if (($t['trainer_id'] ?? 0) == $current_training['trainer_id']) {
                $already_added = true;
                break;
            }
        }
        if (!$already_added) {
            $training_items['session_training'] = $current_training;
        }
    }
}

// URL params for training
$url_trainer_id = intval($_GET['trainer_id'] ?? 0);
if ($url_trainer_id && $wpdb) {
    $url_trainer = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}ptp_trainers WHERE id = %d AND status = 'active'", 
        $url_trainer_id
    ));
    
    if ($url_trainer) {
        $url_pkg_key = sanitize_text_field($_GET['package'] ?? 'single');
        $url_session_date = sanitize_text_field($_GET['date'] ?? '');
        $url_session_time = sanitize_text_field($_GET['time'] ?? '');
        $url_session_loc = sanitize_text_field($_GET['location'] ?? '');
        
        $url_rate = intval($url_trainer->hourly_rate ?: 60);
        $url_pkgs = array(
            'single' => array('name' => 'Single Session', 'sessions' => 1, 'discount' => 0),
            'pack3' => array('name' => '3-Pack', 'sessions' => 3, 'discount' => 10),
            'pack5' => array('name' => '5-Pack', 'sessions' => 5, 'discount' => 15),
        );
        
        $url_sel = $url_pkgs[$url_pkg_key] ?? $url_pkgs['single'];
        $url_price = $url_pkg_key === 'single' ? $url_rate : intval($url_rate * $url_sel['sessions'] * (1 - $url_sel['discount']/100));
        
        $already_added = false;
        foreach ($training_items as $t) {
            if (($t['trainer_id'] ?? 0) == $url_trainer_id) {
                $already_added = true;
                break;
            }
        }
        
        if (!$already_added) {
            $training_items['url_training'] = array(
                'trainer_id' => $url_trainer_id,
                'trainer_name' => $url_trainer->display_name . ' - ' . $url_sel['name'],
                'trainer_photo' => $url_trainer->photo_url,
                'trainer_level' => $url_trainer->level,
                'price' => $url_price,
                'package' => $url_pkg_key,
                'sessions' => $url_sel['sessions'],
                'date' => $url_session_date,
                'time' => $url_session_time,
                'location' => $url_session_loc,
            );
        }
    }
}

// WooCommerce items (camps, clinics, merchandise)
$wc_items = array();
$wc_subtotal = 0;
$has_summer_camp = false;
if (function_exists('WC') && WC()->cart && !WC()->cart->is_empty()) {
    foreach (WC()->cart->get_cart() as $key => $item) {
        $product = $item['data'];
        $product_id = $item['product_id'];
        $qty = $item['quantity'];
        $price = floatval($product->get_price()) * $qty;
        
        $type = 'product';
        $cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'slugs'));
        $product_name_lower = strtolower($product->get_name());
        
        if (in_array('camps', $cats) || in_array('summer-camps', $cats) || strpos($product_name_lower, 'camp') !== false) {
            $type = 'camp';
            $has_summer_camp = true;
        } elseif (in_array('clinics', $cats) || in_array('clinic', $cats) || strpos($product_name_lower, 'clinic') !== false) {
            $type = 'clinic';
        } elseif (in_array('training', $cats) || in_array('private-training', $cats) || 
                  strpos($product_name_lower, 'pack') !== false || 
                  strpos($product_name_lower, 'session') !== false ||
                  strpos($product_name_lower, 'training') !== false) {
            $type = 'training';
        }
        
        $meta = array();
        if (!empty($item['ptp_session_date'])) $meta['date'] = $item['ptp_session_date'];
        if (!empty($item['ptp_player_name'])) $meta['player'] = $item['ptp_player_name'];
        if (!empty($item['ptp_location'])) $meta['location'] = $item['ptp_location'];
        
        $wc_items[] = array(
            'key' => $key,
            'product_id' => $product_id,
            'name' => $product->get_name(),
            'price' => $price,
            'qty' => $qty,
            'type' => $type,
            'image' => wp_get_attachment_url($product->get_image_id()) ?: '',
            'meta' => $meta
        );
        $wc_subtotal += $price;
    }
}

// Calculate training subtotal
$training_subtotal = 0;
foreach ($training_items as $t) {
    $training_subtotal += floatval($t['price'] ?? 0);
}

// Jersey upsell state (session based)
$jersey_upsell_added = false;
$jersey_price = 50;
if (function_exists('WC') && WC()->session) {
    $jersey_upsell_added = WC()->session->get('ptp_jersey_upsell', false);
}

$subtotal = $wc_subtotal + $training_subtotal;
if ($jersey_upsell_added) {
    $subtotal += $jersey_price;
}

// Processing fee (3% + $0.30)
$processing_fee = $subtotal > 0 ? round(($subtotal * 0.03) + 0.30, 2) : 0;
$total = $subtotal + $processing_fee;
$is_empty = empty($wc_items) && empty($training_items);
$item_count = count($training_items) + count($wc_items) + ($jersey_upsell_added ? 1 : 0);

// Build checkout URL
$checkout_url = home_url('/ptp-checkout/');
$checkout_params = array();
if ($url_trainer_id) {
    $checkout_params['trainer_id'] = $url_trainer_id;
    $checkout_params['package'] = $url_pkg_key ?? 'single';
    if (!empty($url_session_date)) $checkout_params['date'] = $url_session_date;
    if (!empty($url_session_time)) $checkout_params['time'] = $url_session_time;
    if (!empty($url_session_loc)) $checkout_params['location'] = $url_session_loc;
} elseif (!empty($training_items)) {
    $first_training = reset($training_items);
    if (!empty($first_training['trainer_id'])) {
        $checkout_params['trainer_id'] = $first_training['trainer_id'];
        $checkout_params['package'] = $first_training['package'] ?? 'single';
        if (!empty($first_training['date'])) $checkout_params['date'] = $first_training['date'];
        if (!empty($first_training['time'])) $checkout_params['time'] = $first_training['time'];
        if (!empty($first_training['location'])) $checkout_params['location'] = $first_training['location'];
    }
}
if (!empty($checkout_params)) {
    $checkout_url = add_query_arg($checkout_params, $checkout_url);
}
if ($jersey_upsell_added) {
    $checkout_url = add_query_arg('jersey_upsell', '1', $checkout_url);
}

// Shareability - current URL
$share_url = home_url('/cart/');
$share_title = 'My PTP Soccer Cart';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5,viewport-fit=cover">
<meta name="theme-color" content="#0A0A0A">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Your Cart - PTP Soccer</title>

<!-- Open Graph / Shareability -->
<meta property="og:type" content="website">
<meta property="og:url" content="<?php echo esc_url($share_url); ?>">
<meta property="og:title" content="<?php echo esc_attr($share_title); ?>">
<meta property="og:description" content="View your PTP Soccer training, camps, and clinics.">
<meta property="og:image" content="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png">
<meta name="twitter:card" content="summary_large_image">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<style>
*{margin:0;padding:0;box-sizing:border-box}
:root{--gold:#FCB900;--black:#0A0A0A;--white:#fff;--gray:#F5F5F5;--gray2:#E5E7EB;--gray4:#9CA3AF;--gray6:#4B5563;--green:#22C55E;--red:#EF4444;--rad:12px}
/* v133.2: Hide scrollbar */
html{scroll-behavior:smooth;scrollbar-width:none;-ms-overflow-style:none}
html::-webkit-scrollbar{display:none;width:0}
body{font-family:Inter,-apple-system,BlinkMacSystemFont,sans-serif;background:var(--gray);color:var(--black);line-height:1.5;min-height:100vh;min-height:100dvh;-webkit-font-smoothing:antialiased;-webkit-text-size-adjust:100%;scrollbar-width:none;-ms-overflow-style:none}
body::-webkit-scrollbar{display:none;width:0}
h1,h2,h3{font-family:Oswald,system-ui,sans-serif;font-weight:700;text-transform:uppercase;line-height:1.1;margin:0}
a{color:inherit;text-decoration:none;-webkit-tap-highlight-color:transparent}
button{-webkit-tap-highlight-color:transparent}

/* FULL WIDTH CONTAINER */
.ptp-cart{padding:16px 16px 180px;max-width:100%}
@media(min-width:600px){.ptp-cart{padding:24px 24px 100px}}
@media(min-width:900px){.ptp-cart{padding:32px 40px 60px}}
@media(min-width:1200px){.ptp-cart{padding:40px 60px 60px}}
@media(min-width:1600px){.ptp-cart{padding:48px 80px 60px;max-width:2000px;margin:0 auto}}

/* Header */
.ptp-cart-header{display:flex;flex-direction:column;align-items:center;gap:16px;margin-bottom:20px}
@media(min-width:600px){.ptp-cart-header{flex-direction:row;justify-content:space-between;margin-bottom:28px}}
.ptp-logo{height:44px}
@media(min-width:600px){.ptp-logo{height:48px}}
.ptp-cart-title-row{display:flex;align-items:baseline;gap:10px}
.ptp-cart-title{font-size:22px}
@media(min-width:600px){.ptp-cart-title{font-size:28px}}
.ptp-cart-count{font-size:13px;color:var(--gray4)}

/* FULL WIDTH GRID - Items take full width, summary sidebar */
.ptp-cart-grid{display:grid;grid-template-columns:1fr;gap:20px}
@media(min-width:900px){.ptp-cart-grid{grid-template-columns:1fr 380px;gap:32px}}
@media(min-width:1100px){.ptp-cart-grid{grid-template-columns:1fr 420px;gap:40px}}
@media(min-width:1400px){.ptp-cart-grid{grid-template-columns:1fr 480px;gap:56px}}
@media(min-width:1700px){.ptp-cart-grid{grid-template-columns:1fr 520px;gap:72px}}

/* Cart Items Column */
.ptp-cart-items{display:flex;flex-direction:column;gap:16px}
@media(min-width:600px){.ptp-cart-items{gap:20px}}

/* Section Headers */
.ptp-cart-section{margin-bottom:0}
.ptp-section-title{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--gray4);margin-bottom:10px;padding-left:4px}
@media(min-width:600px){.ptp-section-title{font-size:12px;margin-bottom:12px}}

/* Item Cards - Responsive */
.ptp-item{background:var(--white);border-radius:var(--rad);padding:14px;display:flex;gap:12px;box-shadow:0 2px 12px rgba(0,0,0,.04);transition:transform .15s,box-shadow .15s}
@media(min-width:600px){.ptp-item{padding:18px;gap:16px}}
@media(min-width:900px){.ptp-item:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.08)}}
.ptp-item-img{width:60px;height:60px;border-radius:10px;background:var(--gray);overflow:hidden;flex-shrink:0}
@media(min-width:600px){.ptp-item-img{width:80px;height:80px;border-radius:var(--rad)}}
.ptp-item-img img{width:100%;height:100%;object-fit:cover}
.ptp-item-img.camp{background:linear-gradient(135deg,#22C55E,#16A34A)}
.ptp-item-img.clinic{background:linear-gradient(135deg,#3B82F6,#2563EB)}
.ptp-item-img.training{background:linear-gradient(135deg,#FCB900,#F59E0B)}
.ptp-item-img.jersey{background:linear-gradient(135deg,#8B5CF6,#6D28D9)}
.ptp-item-icon{width:100%;height:100%;display:flex;align-items:center;justify-content:center}
.ptp-item-icon svg{width:28px;height:28px;stroke:var(--white);stroke-width:1.5}
@media(min-width:600px){.ptp-item-icon svg{width:36px;height:36px}}
.ptp-item-info{flex:1;min-width:0;display:flex;flex-direction:column;justify-content:center}
.ptp-item-type{font-size:9px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--gray4);margin-bottom:2px}
@media(min-width:600px){.ptp-item-type{font-size:10px}}
.ptp-item-name{font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;margin-bottom:4px;line-height:1.2}
@media(min-width:600px){.ptp-item-name{font-size:16px}}
.ptp-item-meta{font-size:11px;color:var(--gray6);line-height:1.5}
@media(min-width:600px){.ptp-item-meta{font-size:12px}}
.ptp-item-meta span{display:inline-block;margin-right:10px}
@media(min-width:600px){.ptp-item-meta span{display:block;margin-right:0}}
.ptp-item-actions{display:flex;flex-direction:column;align-items:flex-end;justify-content:space-between;gap:8px;flex-shrink:0}
.ptp-item-price{font-family:Oswald,sans-serif;font-size:18px;font-weight:600}
@media(min-width:600px){.ptp-item-price{font-size:22px}}
.ptp-item-remove{font-size:11px;color:var(--red);cursor:pointer;padding:8px 10px;margin:-8px -10px;border-radius:6px;transition:background .15s}
@media(min-width:600px){.ptp-item-remove{font-size:12px}}
.ptp-item-remove:hover,.ptp-item-remove:active{background:rgba(239,68,68,.08)}

/* ========== QUANTITY CONTROLS ========== */
.ptp-item-qty{display:flex;align-items:center;gap:8px;margin-top:8px}
.ptp-qty-btn{width:28px;height:28px;border:2px solid var(--gray2);background:var(--white);border-radius:6px;font-size:16px;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.ptp-qty-btn:hover{border-color:var(--gold);background:var(--gold);color:var(--black)}
.ptp-qty-val{min-width:24px;text-align:center;font-family:Oswald,sans-serif;font-size:14px;font-weight:600}
a.ptp-item-name{color:inherit;text-decoration:none}
a.ptp-item-name:hover{color:var(--gold)}
a.ptp-item-img{display:block}

/* ========== JERSEY UPSELL - World Cup x PTP ========== */
.ptp-upsell{background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);border-radius:var(--rad);padding:20px;margin-bottom:16px;color:var(--white);position:relative;overflow:hidden}
@media(min-width:600px){.ptp-upsell{padding:24px}}
.ptp-upsell::before{content:'';position:absolute;top:-50%;right:-20%;width:200px;height:200px;background:radial-gradient(circle,rgba(252,185,0,.2) 0%,transparent 70%);pointer-events:none}
.ptp-upsell-badge{position:absolute;top:12px;right:12px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:10px;font-weight:700;text-transform:uppercase;padding:4px 10px;border-radius:4px}
@media(min-width:600px){.ptp-upsell-badge{font-size:11px;padding:5px 12px}}
.ptp-upsell-content{display:flex;gap:16px;align-items:center}
@media(min-width:600px){.ptp-upsell-content{gap:20px}}
.ptp-upsell-img{width:70px;height:70px;background:linear-gradient(135deg,#8B5CF6,#6D28D9);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
@media(min-width:600px){.ptp-upsell-img{width:90px;height:90px;border-radius:var(--rad)}}
.ptp-upsell-img svg{width:36px;height:36px;stroke:var(--white)}
@media(min-width:600px){.ptp-upsell-img svg{width:48px;height:48px}}
.ptp-upsell-info{flex:1;min-width:0}
.ptp-upsell-title{font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase;margin-bottom:4px;color:var(--gold)}
@media(min-width:600px){.ptp-upsell-title{font-size:18px}}
.ptp-upsell-desc{font-size:12px;color:rgba(255,255,255,.8);margin-bottom:12px;line-height:1.4}
@media(min-width:600px){.ptp-upsell-desc{font-size:13px}}
.ptp-upsell-price{display:flex;align-items:baseline;gap:8px;margin-bottom:12px}
.ptp-upsell-price-current{font-family:Oswald,sans-serif;font-size:24px;font-weight:700;color:var(--white)}
@media(min-width:600px){.ptp-upsell-price-current{font-size:28px}}
.ptp-upsell-price-original{font-size:14px;color:rgba(255,255,255,.5);text-decoration:line-through}
.ptp-upsell-btn{display:inline-flex;align-items:center;gap:8px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:13px;font-weight:600;text-transform:uppercase;padding:12px 20px;border:none;border-radius:8px;cursor:pointer;transition:all .2s}
@media(min-width:600px){.ptp-upsell-btn{font-size:14px;padding:14px 24px}}
.ptp-upsell-btn:hover{background:#e5a800;transform:scale(1.02)}
.ptp-upsell-btn.added{background:var(--green);pointer-events:none}
.ptp-upsell-btn svg{width:16px;height:16px}

/* ========== ORDER SUMMARY - Desktop Sticky ========== */
.ptp-summary{display:none}
@media(min-width:900px){
    .ptp-summary{display:block;background:var(--white);border-radius:var(--rad);padding:24px;box-shadow:0 4px 20px rgba(0,0,0,.06);position:sticky;top:24px;height:fit-content}
}
@media(min-width:1100px){.ptp-summary{padding:28px}}
.ptp-summary-title{font-size:16px;margin-bottom:20px;padding-bottom:14px;border-bottom:2px solid var(--gray)}
@media(min-width:1100px){.ptp-summary-title{font-size:18px}}
.ptp-summary-items{max-height:300px;overflow-y:auto;margin-bottom:16px;padding-right:8px}
.ptp-summary-items::-webkit-scrollbar{width:4px}
.ptp-summary-items::-webkit-scrollbar-thumb{background:var(--gray2);border-radius:2px}
.ptp-sum-item{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--gray)}
.ptp-sum-item:last-child{border:none}
.ptp-sum-img{width:40px;height:40px;border-radius:6px;background:var(--gray);overflow:hidden;flex-shrink:0}
.ptp-sum-img img{width:100%;height:100%;object-fit:cover}
.ptp-sum-info{flex:1;min-width:0}
.ptp-sum-name{font-size:12px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ptp-sum-meta{font-size:10px;color:var(--gray4)}
.ptp-sum-price{font-family:Oswald,sans-serif;font-size:14px;font-weight:600}
.ptp-summary-row{display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px}
.ptp-summary-row.fee{color:var(--gray4);font-size:12px}
.ptp-summary-row.total{font-family:Oswald,sans-serif;font-size:24px;font-weight:700;margin-top:14px;padding-top:14px;border-top:2px solid var(--gray)}
.ptp-promo{display:flex;gap:8px;margin:16px 0}
.ptp-promo input{flex:1;padding:12px;border:2px solid var(--gray2);border-radius:8px;font-size:14px;-webkit-appearance:none}
.ptp-promo input:focus{outline:none;border-color:var(--gold)}
.ptp-promo button{padding:12px 18px;background:var(--black);color:var(--white);border:none;font-family:Oswald,sans-serif;font-size:12px;font-weight:600;text-transform:uppercase;border-radius:8px;cursor:pointer;white-space:nowrap}
.ptp-promo button:hover{background:#333}
.ptp-checkout-btn{display:block;width:100%;padding:18px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:16px;font-weight:600;text-transform:uppercase;text-align:center;border:none;cursor:pointer;border-radius:var(--rad);margin-top:16px;transition:all .2s}
.ptp-checkout-btn:hover{background:#e5a800;transform:translateY(-2px);box-shadow:0 8px 24px rgba(252,185,0,.3)}
.ptp-trust{display:flex;justify-content:center;gap:16px;margin-top:16px;padding-top:16px;border-top:1px solid var(--gray);flex-wrap:wrap}
.ptp-trust span{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--gray4)}
.ptp-trust svg{width:14px;height:14px;stroke:var(--green)}

/* ========== MOBILE BOTTOM SHEET - ORDER SUMMARY ========== */
.ptp-mobile-sheet{display:none;position:fixed;bottom:0;left:0;right:0;z-index:1000;background:var(--white);border-radius:20px 20px 0 0;box-shadow:0 -8px 40px rgba(0,0,0,.15);max-height:85vh;overflow:hidden;transform:translateY(calc(100% - 80px));transition:transform .3s cubic-bezier(.4,0,.2,1)}
@media(max-width:899px){.ptp-mobile-sheet{display:block}}
.ptp-mobile-sheet.expanded{transform:translateY(0)}
.ptp-sheet-handle{width:100%;padding:12px;display:flex;justify-content:center;cursor:pointer}
.ptp-sheet-handle::after{content:'';width:40px;height:4px;background:var(--gray2);border-radius:2px}
.ptp-sheet-header{display:flex;justify-content:space-between;align-items:center;padding:0 20px 16px;border-bottom:1px solid var(--gray)}
.ptp-sheet-title{font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase}
.ptp-sheet-total{font-family:Oswald,sans-serif;font-size:22px;font-weight:700;color:var(--gold)}
.ptp-sheet-toggle{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--gray4);cursor:pointer;padding:8px;margin:-8px}
.ptp-sheet-toggle svg{width:16px;height:16px;transition:transform .3s}
.ptp-mobile-sheet.expanded .ptp-sheet-toggle svg{transform:rotate(180deg)}
.ptp-sheet-content{padding:20px;overflow-y:auto;max-height:calc(85vh - 160px);-webkit-overflow-scrolling:touch}
.ptp-sheet-items{margin-bottom:20px}
.ptp-sheet-item{display:flex;gap:12px;padding:12px 0;border-bottom:1px solid var(--gray)}
.ptp-sheet-item:last-child{border:none}
.ptp-sheet-img{width:50px;height:50px;border-radius:8px;background:var(--gray);overflow:hidden;flex-shrink:0}
.ptp-sheet-img img{width:100%;height:100%;object-fit:cover}
.ptp-sheet-info{flex:1;min-width:0}
.ptp-sheet-name{font-size:13px;font-weight:600;margin-bottom:2px}
.ptp-sheet-meta{font-size:11px;color:var(--gray4)}
.ptp-sheet-price{font-family:Oswald,sans-serif;font-size:16px;font-weight:600}
.ptp-sheet-summary{padding-top:16px;border-top:1px solid var(--gray)}
.ptp-sheet-row{display:flex;justify-content:space-between;font-size:14px;margin-bottom:8px}
.ptp-sheet-row.fee{color:var(--gray4);font-size:12px}
.ptp-sheet-row.total{font-family:Oswald,sans-serif;font-size:22px;font-weight:700;margin-top:12px;padding-top:12px;border-top:2px solid var(--gray)}
.ptp-sheet-promo{display:flex;gap:8px;margin:16px 0}
.ptp-sheet-promo input{flex:1;padding:14px;border:2px solid var(--gray2);border-radius:8px;font-size:16px;-webkit-appearance:none}
.ptp-sheet-promo input:focus{outline:none;border-color:var(--gold)}
.ptp-sheet-promo button{padding:14px 18px;background:var(--black);color:var(--white);border:none;font-family:Oswald,sans-serif;font-size:12px;font-weight:600;text-transform:uppercase;border-radius:8px;cursor:pointer}
.ptp-sheet-checkout{display:block;width:100%;padding:18px;background:var(--gold);color:var(--black);font-family:Oswald,sans-serif;font-size:16px;font-weight:600;text-transform:uppercase;text-align:center;border:none;cursor:pointer;border-radius:var(--rad);margin-top:16px}
.ptp-sheet-checkout:active{background:#e5a800}
.ptp-sheet-trust{display:flex;justify-content:center;gap:12px;margin-top:14px;flex-wrap:wrap}
.ptp-sheet-trust span{display:flex;align-items:center;gap:4px;font-size:10px;color:var(--gray4)}
.ptp-sheet-trust svg{width:12px;height:12px;stroke:var(--green)}

/* Mobile bottom spacer */
@media(max-width:899px){.ptp-cart{padding-bottom:100px}}

/* Empty Cart */
.ptp-empty{text-align:center;padding:80px 20px}
@media(min-width:600px){.ptp-empty{padding:120px 20px}}
.ptp-empty-icon{width:80px;height:80px;background:var(--gray2);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px}
.ptp-empty-icon svg{width:40px;height:40px;stroke:var(--gray4)}
.ptp-empty h2{font-size:24px;margin-bottom:10px}
@media(min-width:600px){.ptp-empty h2{font-size:28px}}
.ptp-empty p{color:var(--gray4);font-size:15px;margin-bottom:28px}
.ptp-empty-btns{display:flex;flex-direction:column;gap:12px;max-width:300px;margin:0 auto}
@media(min-width:450px){.ptp-empty-btns{flex-direction:row;justify-content:center;max-width:none}}
.ptp-empty-btn{padding:16px 28px;font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase;border-radius:10px;text-align:center}
.ptp-empty-btn.gold{background:var(--gold);color:var(--black)}
.ptp-empty-btn.outline{background:transparent;border:2px solid var(--black);color:var(--black)}
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

<div class="ptp-cart">
    <!-- Header -->
    <div class="ptp-cart-header">
        <a href="<?php echo home_url(); ?>"><img src="https://ptpsummercamps.com/wp-content/uploads/2025/11/PTP-LOGO-2.png" alt="PTP Soccer" class="ptp-logo"></a>
        <?php if(!$is_empty): ?>
        <div class="ptp-cart-title-row">
            <h1 class="ptp-cart-title">Your Cart</h1>
            <span class="ptp-cart-count"><?php echo $item_count; ?> item<?php echo $item_count !== 1 ? 's' : ''; ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if($is_empty): ?>
    <!-- Empty State -->
    <div class="ptp-empty">
        <div class="ptp-empty-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
        </div>
        <h2>Your Cart is Empty</h2>
        <p>Add training sessions, camps, or clinics to get started</p>
        <div class="ptp-empty-btns">
            <a href="<?php echo home_url('/find-trainers/'); ?>" class="ptp-empty-btn gold">Find Trainers</a>
            <a href="<?php echo home_url('/ptp-shop-page/'); ?>" class="ptp-empty-btn outline">View Camps</a>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Cart Grid -->
    <div class="ptp-cart-grid">
        <!-- Items Column -->
        <div class="ptp-cart-items">
            <?php 
            // Sort items by type
            $camps = array_filter($wc_items, function($i) { return $i['type'] === 'camp'; });
            $clinics = array_filter($wc_items, function($i) { return $i['type'] === 'clinic'; });
            $wc_training = array_filter($wc_items, function($i) { return $i['type'] === 'training'; });
            $other = array_filter($wc_items, function($i) { return !in_array($i['type'], array('camp', 'clinic', 'training')); });
            ?>
            
            <?php if(!empty($training_items)): ?>
            <div class="ptp-cart-section">
                <p class="ptp-section-title">Private Training</p>
                <?php foreach($training_items as $key => $t): ?>
                <div class="ptp-item">
                    <div class="ptp-item-img training">
                        <?php if(!empty($t['trainer_photo'])): ?>
                        <img src="<?php echo esc_url($t['trainer_photo']); ?>" alt="">
                        <?php else: ?>
                        <div class="ptp-item-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg></div>
                        <?php endif; ?>
                    </div>
                    <div class="ptp-item-info">
                        <span class="ptp-item-type"><?php 
                            $pkg = $t['package'] ?? 'single';
                            if ($pkg === 'pack5') echo '5-Pack Training';
                            elseif ($pkg === 'pack3') echo '3-Pack Training';
                            else echo 'Training Session';
                        ?></span>
                        <div class="ptp-item-name"><?php echo esc_html($t['trainer_name'] ?? 'Private Training'); ?></div>
                        <div class="ptp-item-meta">
                            <?php if(!empty($t['date'])): ?><span>📅 <?php echo date('M j, Y', strtotime($t['date'])); ?></span><?php endif; ?>
                            <?php if(!empty($t['time'])): ?><span>🕐 <?php echo date('g:i A', strtotime($t['time'])); ?></span><?php endif; ?>
                            <?php if(!empty($t['location'])): ?><span>📍 <?php echo esc_html($t['location']); ?></span><?php endif; ?>
                            <?php if(!empty($t['sessions']) && $t['sessions'] > 1): ?><span>🎯 <?php echo $t['sessions']; ?> sessions</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="ptp-item-actions">
                        <div class="ptp-item-price">$<?php echo number_format(floatval($t['price'] ?? 0), 2); ?></div>
                        <span class="ptp-item-remove" data-key="<?php echo esc_attr($key); ?>" data-type="training">Remove</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($camps)): ?>
            <div class="ptp-cart-section">
                <p class="ptp-section-title">Summer Camps</p>
                <?php foreach($camps as $item): 
                    $product = wc_get_product($item['product_id'] ?? 0);
                    $product_url = $product ? get_permalink($product->get_id()) : '#';
                ?>
                <div class="ptp-item">
                    <a href="<?php echo esc_url($product_url); ?>" class="ptp-item-img camp">
                        <?php if($item['image']): ?><img src="<?php echo esc_url($item['image']); ?>" alt="">
                        <?php else: ?><div class="ptp-item-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div><?php endif; ?>
                    </a>
                    <div class="ptp-item-info">
                        <span class="ptp-item-type">Summer Camp</span>
                        <a href="<?php echo esc_url($product_url); ?>" class="ptp-item-name"><?php echo esc_html($item['name']); ?></a>
                        <div class="ptp-item-meta">
                            <?php if(!empty($item['meta']['date'])): ?><span>📅 <?php echo esc_html($item['meta']['date']); ?></span><?php endif; ?>
                            <?php if(!empty($item['meta']['player'])): ?><span>👤 <?php echo esc_html($item['meta']['player']); ?></span><?php endif; ?>
                            <?php if(!empty($item['meta']['location'])): ?><span>📍 <?php echo esc_html($item['meta']['location']); ?></span><?php endif; ?>
                        </div>
                        <div class="ptp-item-qty">
                            <button class="ptp-qty-btn" data-action="minus" data-key="<?php echo esc_attr($item['key']); ?>">−</button>
                            <span class="ptp-qty-val"><?php echo $item['qty']; ?></span>
                            <button class="ptp-qty-btn" data-action="plus" data-key="<?php echo esc_attr($item['key']); ?>">+</button>
                        </div>
                    </div>
                    <div class="ptp-item-actions">
                        <div class="ptp-item-price">$<?php echo number_format($item['price'], 2); ?></div>
                        <span class="ptp-item-remove" data-key="<?php echo esc_attr($item['key']); ?>" data-type="wc">Remove</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($clinics)): ?>
            <div class="ptp-cart-section">
                <p class="ptp-section-title">Clinics</p>
                <?php foreach($clinics as $item): ?>
                <div class="ptp-item">
                    <div class="ptp-item-img clinic">
                        <?php if($item['image']): ?><img src="<?php echo esc_url($item['image']); ?>" alt="">
                        <?php else: ?><div class="ptp-item-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></div><?php endif; ?>
                    </div>
                    <div class="ptp-item-info">
                        <span class="ptp-item-type">Clinic</span>
                        <div class="ptp-item-name"><?php echo esc_html($item['name']); ?></div>
                        <div class="ptp-item-meta">
                            <?php if(!empty($item['meta']['date'])): ?><span>📅 <?php echo esc_html($item['meta']['date']); ?></span><?php endif; ?>
                            <?php if(!empty($item['meta']['player'])): ?><span>👤 <?php echo esc_html($item['meta']['player']); ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="ptp-item-actions">
                        <div class="ptp-item-price">$<?php echo number_format($item['price'], 2); ?></div>
                        <span class="ptp-item-remove" data-key="<?php echo esc_attr($item['key']); ?>" data-type="wc">Remove</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <?php if(!empty($other)): ?>
            <div class="ptp-cart-section">
                <p class="ptp-section-title">Other Items</p>
                <?php foreach($other as $item): ?>
                <div class="ptp-item">
                    <div class="ptp-item-img"><?php if($item['image']): ?><img src="<?php echo esc_url($item['image']); ?>" alt=""><?php endif; ?></div>
                    <div class="ptp-item-info">
                        <div class="ptp-item-name"><?php echo esc_html($item['name']); ?></div>
                        <div class="ptp-item-meta"><?php if($item['qty'] > 1): ?><span>Qty: <?php echo $item['qty']; ?></span><?php endif; ?></div>
                    </div>
                    <div class="ptp-item-actions">
                        <div class="ptp-item-price">$<?php echo number_format($item['price'], 2); ?></div>
                        <span class="ptp-item-remove" data-key="<?php echo esc_attr($item['key']); ?>" data-type="wc">Remove</span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            
            <!-- Jersey Added Item (if upsell was added) -->
            <?php if($jersey_upsell_added): ?>
            <div class="ptp-cart-section">
                <p class="ptp-section-title">Add-Ons</p>
                <div class="ptp-item">
                    <div class="ptp-item-img jersey">
                        <div class="ptp-item-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.38 3.46L16 2a4 4 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10a2 2 0 002 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/></svg>
                        </div>
                    </div>
                    <div class="ptp-item-info">
                        <span class="ptp-item-type">Limited Edition</span>
                        <div class="ptp-item-name">World Cup 2026 x PTP Jersey</div>
                        <div class="ptp-item-meta"><span>⚽ 2nd Jersey for Camper</span></div>
                    </div>
                    <div class="ptp-item-actions">
                        <div class="ptp-item-price">$<?php echo number_format($jersey_price, 2); ?></div>
                        <span class="ptp-item-remove" data-key="jersey_upsell" data-type="upsell">Remove</span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ========== JERSEY UPSELL (Show for Summer Camps if not already added) ========== -->
            <?php if($has_summer_camp && !$jersey_upsell_added): ?>
            <div class="ptp-upsell" id="jerseyUpsell">
                <span class="ptp-upsell-badge">Limited Edition</span>
                <div class="ptp-upsell-content">
                    <div class="ptp-upsell-img">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M20.38 3.46L16 2a4 4 0 01-8 0L3.62 3.46a2 2 0 00-1.34 2.23l.58 3.47a1 1 0 00.99.84H6v10a2 2 0 002 2h8a2 2 0 002-2V10h2.15a1 1 0 00.99-.84l.58-3.47a2 2 0 00-1.34-2.23z"/></svg>
                    </div>
                    <div class="ptp-upsell-info">
                        <div class="ptp-upsell-title">🏆 World Cup 2026 x PTP Jersey</div>
                        <div class="ptp-upsell-desc">Get a 2nd exclusive jersey for your camper! Perfect for practice or to rep PTP at school.</div>
                        <div class="ptp-upsell-price">
                            <span class="ptp-upsell-price-current">$50</span>
                            <span class="ptp-upsell-price-original">$75</span>
                        </div>
                        <button type="button" class="ptp-upsell-btn" id="addJerseyBtn">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            Add to Cart
                        </button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- ========== CAMP UPSELL (Show when Training in cart but NO camps) ========== -->
            <?php 
            $has_training = !empty($training_items);
            $show_camp_upsell = $has_training && !$has_summer_camp;
            
            if ($show_camp_upsell):
                // Get upcoming camps
                $camp_upsells = array();
                if (function_exists('wc_get_products')) {
                    $camp_products = wc_get_products(array(
                        'status' => 'publish',
                        'limit' => 4,
                        'category' => array('camps', 'summer-camps'),
                        'orderby' => 'date',
                        'order' => 'ASC',
                    ));
                    
                    foreach ($camp_products as $camp_product) {
                        $camp_upsells[] = array(
                            'id' => $camp_product->get_id(),
                            'name' => $camp_product->get_name(),
                            'price' => $camp_product->get_price(),
                            'image' => wp_get_attachment_url($camp_product->get_image_id()),
                            'url' => get_permalink($camp_product->get_id()),
                        );
                    }
                }
                
                if (!empty($camp_upsells)):
            ?>
            <div class="ptp-camp-upsell-section" id="campUpsellSection">
                <div class="ptp-camp-upsell-header">
                    <div class="ptp-camp-upsell-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#FCB900" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    </div>
                    <div>
                        <div class="ptp-camp-upsell-title">⚽ ADD A SUMMER CAMP</div>
                        <div class="ptp-camp-upsell-sub">Train all week with PTP coaches!</div>
                    </div>
                </div>
                <div class="ptp-camp-upsell-grid">
                    <?php foreach ($camp_upsells as $camp): ?>
                    <div class="ptp-camp-card" data-product-id="<?php echo esc_attr($camp['id']); ?>">
                        <div class="ptp-camp-card-img">
                            <?php if ($camp['image']): ?>
                            <img src="<?php echo esc_url($camp['image']); ?>" alt="<?php echo esc_attr($camp['name']); ?>">
                            <?php else: ?>
                            <div class="ptp-camp-card-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                            <?php endif; ?>
                        </div>
                        <div class="ptp-camp-card-body">
                            <div class="ptp-camp-card-name"><?php echo esc_html($camp['name']); ?></div>
                            <div class="ptp-camp-card-price">$<?php echo number_format($camp['price'], 0); ?></div>
                            <button type="button" class="ptp-camp-add-btn" onclick="addCampToCart(<?php echo $camp['id']; ?>, this)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                                Add to Cart
                            </button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <a href="<?php echo esc_url(home_url('/ptp-shop-page/')); ?>" class="ptp-camp-view-all">View All Camps →</a>
            </div>
            <style>
            .ptp-camp-upsell-section{background:#0A0A0A;border-radius:16px;padding:20px;margin-top:16px}
            .ptp-camp-upsell-header{display:flex;align-items:center;gap:14px;margin-bottom:20px}
            .ptp-camp-upsell-icon{width:44px;height:44px;background:rgba(252,185,0,0.15);border-radius:10px;display:flex;align-items:center;justify-content:center}
            .ptp-camp-upsell-icon svg{width:24px;height:24px}
            .ptp-camp-upsell-title{color:#FCB900;font-family:Oswald,sans-serif;font-size:16px;font-weight:700;text-transform:uppercase}
            .ptp-camp-upsell-sub{color:rgba(255,255,255,0.7);font-size:13px;margin-top:2px}
            .ptp-camp-upsell-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:12px}
            @media(min-width:640px){.ptp-camp-upsell-grid{grid-template-columns:repeat(4,1fr)}}
            .ptp-camp-card{background:#1a1a1a;border-radius:12px;overflow:hidden;border:2px solid transparent;transition:all .2s}
            .ptp-camp-card:hover{border-color:#FCB900}
            .ptp-camp-card-img{aspect-ratio:1;background:#252525;overflow:hidden}
            .ptp-camp-card-img img{width:100%;height:100%;object-fit:cover}
            .ptp-camp-card-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#444}
            .ptp-camp-card-placeholder svg{width:32px;height:32px}
            .ptp-camp-card-body{padding:12px}
            .ptp-camp-card-name{color:#fff;font-size:12px;font-weight:600;line-height:1.3;margin-bottom:6px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:32px}
            .ptp-camp-card-price{color:#FCB900;font-family:Oswald,sans-serif;font-size:18px;font-weight:700;margin-bottom:10px}
            .ptp-camp-add-btn{width:100%;background:#FCB900;color:#0A0A0A;border:none;padding:10px 12px;border-radius:8px;font-family:Oswald,sans-serif;font-size:12px;font-weight:600;text-transform:uppercase;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:all .2s;min-height:44px}
            .ptp-camp-add-btn:hover{background:#E5A800}
            .ptp-camp-add-btn:active{transform:scale(0.96)}
            .ptp-camp-add-btn.added{background:#22C55E;pointer-events:none}
            .ptp-camp-add-btn.loading{opacity:0.7;pointer-events:none}
            .ptp-camp-add-btn svg{width:14px;height:14px}
            .ptp-camp-view-all{display:block;text-align:center;color:#FCB900;font-size:13px;font-weight:600;margin-top:16px;text-decoration:none}
            .ptp-camp-view-all:hover{text-decoration:underline}
            </style>
            <?php endif; endif; ?>
        </div>
        
        <!-- ========== DESKTOP ORDER SUMMARY (Sticky Sidebar) ========== -->
        <div class="ptp-summary">
            <h3 class="ptp-summary-title">Order Summary</h3>
            
            <div class="ptp-summary-items">
                <?php foreach($training_items as $t): ?>
                <div class="ptp-sum-item">
                    <div class="ptp-sum-img"><?php if(!empty($t['trainer_photo'])): ?><img src="<?php echo esc_url($t['trainer_photo']); ?>" alt=""><?php endif; ?></div>
                    <div class="ptp-sum-info">
                        <div class="ptp-sum-name"><?php echo esc_html($t['trainer_name'] ?? 'Training'); ?></div>
                        <div class="ptp-sum-meta"><?php echo !empty($t['date']) ? date('M j', strtotime($t['date'])) : 'Training'; ?></div>
                    </div>
                    <div class="ptp-sum-price">$<?php echo number_format(floatval($t['price'] ?? 0), 2); ?></div>
                </div>
                <?php endforeach; ?>
                <?php foreach($wc_items as $item): ?>
                <div class="ptp-sum-item">
                    <div class="ptp-sum-img"><?php if($item['image']): ?><img src="<?php echo esc_url($item['image']); ?>" alt=""><?php endif; ?></div>
                    <div class="ptp-sum-info">
                        <div class="ptp-sum-name"><?php echo esc_html($item['name']); ?></div>
                        <div class="ptp-sum-meta"><?php echo ucfirst($item['type']); ?></div>
                    </div>
                    <div class="ptp-sum-price">$<?php echo number_format($item['price'], 2); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if($jersey_upsell_added): ?>
                <div class="ptp-sum-item">
                    <div class="ptp-sum-img" style="background:linear-gradient(135deg,#8B5CF6,#6D28D9)"></div>
                    <div class="ptp-sum-info">
                        <div class="ptp-sum-name">WC 2026 x PTP Jersey</div>
                        <div class="ptp-sum-meta">Add-On</div>
                    </div>
                    <div class="ptp-sum-price">$<?php echo number_format($jersey_price, 2); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if($training_subtotal > 0): ?>
            <div class="ptp-summary-row"><span>Training</span><span>$<?php echo number_format($training_subtotal, 2); ?></span></div>
            <?php endif; ?>
            <?php if($wc_subtotal > 0): ?>
            <div class="ptp-summary-row"><span>Camps & Clinics</span><span>$<?php echo number_format($wc_subtotal, 2); ?></span></div>
            <?php endif; ?>
            <?php if($jersey_upsell_added): ?>
            <div class="ptp-summary-row"><span>Jersey Add-On</span><span>$<?php echo number_format($jersey_price, 2); ?></span></div>
            <?php endif; ?>
            <div class="ptp-summary-row fee"><span>Card Processing (3% + $0.30)</span><span>$<?php echo number_format($processing_fee, 2); ?></span></div>
            <div class="ptp-summary-row total"><span>Total</span><span>$<?php echo number_format($total, 2); ?></span></div>
            
            <div class="ptp-promo">
                <input type="text" placeholder="Promo code" id="promoCode">
                <button type="button" id="applyPromo">Apply</button>
            </div>
            
            <a href="<?php echo esc_url($checkout_url); ?>" class="ptp-checkout-btn">Checkout →</a>
            
            <div class="ptp-trust">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Secure</span>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Guaranteed</span>
            </div>
        </div>
    </div>
    
    <!-- ========== MOBILE BOTTOM SHEET - ORDER SUMMARY ========== -->
    <div class="ptp-mobile-sheet" id="mobileSheet">
        <div class="ptp-sheet-handle" id="sheetHandle"></div>
        <div class="ptp-sheet-header">
            <div>
                <div class="ptp-sheet-title">Order Summary</div>
                <div class="ptp-sheet-total" id="mobileTotal">$<?php echo number_format($total, 2); ?></div>
            </div>
            <div class="ptp-sheet-toggle" id="sheetToggle">
                <span id="toggleText">View Details</span>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 15l-6-6-6 6"/></svg>
            </div>
        </div>
        <div class="ptp-sheet-content">
            <div class="ptp-sheet-items">
                <?php foreach($training_items as $t): ?>
                <div class="ptp-sheet-item">
                    <div class="ptp-sheet-img"><?php if(!empty($t['trainer_photo'])): ?><img src="<?php echo esc_url($t['trainer_photo']); ?>" alt=""><?php endif; ?></div>
                    <div class="ptp-sheet-info">
                        <div class="ptp-sheet-name"><?php echo esc_html($t['trainer_name'] ?? 'Training'); ?></div>
                        <div class="ptp-sheet-meta"><?php echo !empty($t['date']) ? date('M j', strtotime($t['date'])) : 'Training'; ?></div>
                    </div>
                    <div class="ptp-sheet-price">$<?php echo number_format(floatval($t['price'] ?? 0), 2); ?></div>
                </div>
                <?php endforeach; ?>
                <?php foreach($wc_items as $item): ?>
                <div class="ptp-sheet-item">
                    <div class="ptp-sheet-img"><?php if($item['image']): ?><img src="<?php echo esc_url($item['image']); ?>" alt=""><?php endif; ?></div>
                    <div class="ptp-sheet-info">
                        <div class="ptp-sheet-name"><?php echo esc_html($item['name']); ?></div>
                        <div class="ptp-sheet-meta"><?php echo ucfirst($item['type']); ?></div>
                    </div>
                    <div class="ptp-sheet-price">$<?php echo number_format($item['price'], 2); ?></div>
                </div>
                <?php endforeach; ?>
                <?php if($jersey_upsell_added): ?>
                <div class="ptp-sheet-item">
                    <div class="ptp-sheet-img" style="background:linear-gradient(135deg,#8B5CF6,#6D28D9);border-radius:8px"></div>
                    <div class="ptp-sheet-info">
                        <div class="ptp-sheet-name">WC 2026 x PTP Jersey</div>
                        <div class="ptp-sheet-meta">Add-On</div>
                    </div>
                    <div class="ptp-sheet-price">$<?php echo number_format($jersey_price, 2); ?></div>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="ptp-sheet-summary">
                <?php if($training_subtotal > 0): ?>
                <div class="ptp-sheet-row"><span>Training</span><span>$<?php echo number_format($training_subtotal, 2); ?></span></div>
                <?php endif; ?>
                <?php if($wc_subtotal > 0): ?>
                <div class="ptp-sheet-row"><span>Camps & Clinics</span><span>$<?php echo number_format($wc_subtotal, 2); ?></span></div>
                <?php endif; ?>
                <?php if($jersey_upsell_added): ?>
                <div class="ptp-sheet-row"><span>Jersey Add-On</span><span>$<?php echo number_format($jersey_price, 2); ?></span></div>
                <?php endif; ?>
                <div class="ptp-sheet-row fee"><span>Card Processing</span><span>$<?php echo number_format($processing_fee, 2); ?></span></div>
                <div class="ptp-sheet-row total"><span>Total</span><span>$<?php echo number_format($total, 2); ?></span></div>
            </div>
            
            <div class="ptp-sheet-promo">
                <input type="text" placeholder="Promo code" id="mobilePromoCode">
                <button type="button" id="mobileApplyPromo">Apply</button>
            </div>
            
            <a href="<?php echo esc_url($checkout_url); ?>" class="ptp-sheet-checkout">Checkout →</a>
            
            <div class="ptp-sheet-trust">
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Secure</span>
                <span><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>Guaranteed</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(function(){
    // Mobile Sheet Toggle
    var sheet = document.getElementById('mobileSheet');
    var toggle = document.getElementById('sheetToggle');
    var handle = document.getElementById('sheetHandle');
    var toggleText = document.getElementById('toggleText');
    
    function toggleSheet() {
        if (!sheet) return;
        sheet.classList.toggle('expanded');
        if (sheet.classList.contains('expanded')) {
            toggleText.textContent = 'Hide Details';
        } else {
            toggleText.textContent = 'View Details';
        }
    }
    
    if (toggle) toggle.addEventListener('click', toggleSheet);
    if (handle) handle.addEventListener('click', toggleSheet);
    
    // Touch swipe for mobile sheet
    var startY = 0;
    var currentY = 0;
    if (sheet) {
        sheet.addEventListener('touchstart', function(e) {
            startY = e.touches[0].clientY;
        }, {passive: true});
        
        sheet.addEventListener('touchmove', function(e) {
            currentY = e.touches[0].clientY;
        }, {passive: true});
        
        sheet.addEventListener('touchend', function() {
            var diff = startY - currentY;
            if (diff > 50 && !sheet.classList.contains('expanded')) {
                sheet.classList.add('expanded');
                toggleText.textContent = 'Hide Details';
            } else if (diff < -50 && sheet.classList.contains('expanded')) {
                sheet.classList.remove('expanded');
                toggleText.textContent = 'View Details';
            }
        });
    }
    
    // Remove Item Handlers
    document.querySelectorAll('.ptp-item-remove').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            var key = this.dataset.key;
            var type = this.dataset.type;
            
            if (type === 'wc') {
                window.location.href = '<?php echo wc_get_cart_url(); ?>?remove_item=' + key + '&_wpnonce=<?php echo wp_create_nonce('woocommerce-cart'); ?>';
            } else if (type === 'training') {
                this.textContent = 'Removing...';
                this.style.pointerEvents = 'none';
                
                var formData = new FormData();
                formData.append('action', 'ptp_remove_training_item');
                formData.append('key', key);
                formData.append('nonce', '<?php echo wp_create_nonce('ptp_cart'); ?>');
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(){ window.location.href = '<?php echo home_url('/cart/'); ?>'; })
                .catch(function(){ window.location.href = '<?php echo home_url('/cart/'); ?>'; });
            } else if (type === 'upsell') {
                this.textContent = 'Removing...';
                this.style.pointerEvents = 'none';
                
                var formData = new FormData();
                formData.append('action', 'ptp_toggle_jersey_upsell');
                formData.append('add', '0');
                formData.append('nonce', '<?php echo wp_create_nonce('ptp_cart'); ?>');
                
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                    method: 'POST',
                    body: formData
                })
                .then(function(){ window.location.reload(); })
                .catch(function(){ window.location.reload(); });
            }
        });
    });
    
    // Quantity Change Handlers
    document.querySelectorAll('.ptp-qty-btn').forEach(function(btn){
        btn.addEventListener('click', function(e){
            e.preventDefault();
            var action = this.dataset.action;
            var key = this.dataset.key;
            var qtyEl = this.parentElement.querySelector('.ptp-qty-val');
            var currentQty = parseInt(qtyEl.textContent) || 1;
            var delta = action === 'plus' ? 1 : -1;
            var newQty = currentQty + delta;
            
            if (newQty < 1) return;
            
            qtyEl.textContent = newQty;
            this.style.opacity = '0.5';
            
            // Update cart via WooCommerce
            var formData = new FormData();
            formData.append('action', 'ptp_update_cart_qty');
            formData.append('cart_key', key);
            formData.append('delta', delta);
            formData.append('nonce', '<?php echo wp_create_nonce('ptp_cart_action'); ?>');
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(r){ return r.json(); })
            .then(function(data){
                if (data.success) {
                    window.location.reload();
                } else {
                    qtyEl.textContent = currentQty;
                    btn.style.opacity = '1';
                }
            })
            .catch(function(){
                qtyEl.textContent = currentQty;
                btn.style.opacity = '1';
            });
        });
    });
    
    // Jersey Upsell Add Button
    var addJerseyBtn = document.getElementById('addJerseyBtn');
    if (addJerseyBtn) {
        addJerseyBtn.addEventListener('click', function() {
            this.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;animation:spin 1s linear infinite"><circle cx="12" cy="12" r="10" opacity=".3"/><path d="M12 2a10 10 0 0 1 10 10"/></svg> Adding...';
            this.disabled = true;
            
            var formData = new FormData();
            formData.append('action', 'ptp_toggle_jersey_upsell');
            formData.append('add', '1');
            formData.append('nonce', '<?php echo wp_create_nonce('ptp_cart'); ?>');
            
            fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                method: 'POST',
                body: formData
            })
            .then(function(r){ return r.json(); })
            .then(function(data) {
                if (data.success) {
                    addJerseyBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px"><polyline points="20 6 9 17 4 12"/></svg> Added!';
                    addJerseyBtn.classList.add('added');
                    setTimeout(function() { window.location.reload(); }, 500);
                } else {
                    addJerseyBtn.innerHTML = 'Try Again';
                    addJerseyBtn.disabled = false;
                }
            })
            .catch(function() {
                addJerseyBtn.innerHTML = 'Try Again';
                addJerseyBtn.disabled = false;
            });
        });
    }
})();

// Camp Add to Cart Function (outside IIFE so it's globally accessible)
function addCampToCart(productId, btn) {
    btn.classList.add('loading');
    btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;animation:spin 1s linear infinite"><circle cx="12" cy="12" r="10" opacity=".3"/><path d="M12 2a10 10 0 0 1 10 10"/></svg> Adding...';
    
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
            btn.classList.remove('loading');
            btn.classList.add('added');
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px"><polyline points="20 6 9 17 4 12"/></svg> Added!';
            setTimeout(function() { window.location.reload(); }, 800);
        } else {
            btn.classList.remove('loading');
            btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg> Try Again';
        }
    })
    .catch(function() {
        btn.classList.remove('loading');
        btn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg> Try Again';
    });
}
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
</div><!-- #ptp-scroll-wrapper -->
</body>
</html>
