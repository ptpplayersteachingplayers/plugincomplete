<?php
/**
 * PTP Admin Payouts v2 - Unified Easy Tracking
 * Single dashboard for all payment tracking
 * 
 * Features:
 * - Overview stats at a glance
 * - Escrow status tracking
 * - Dispute management
 * - Payout history
 * - One-click actions
 */

defined('ABSPATH') || exit;

class PTP_Admin_Payouts_V2 {
    
    public static function init() {
        // Menu registered by main PTP_Admin class for correct ordering
        add_action('wp_ajax_ptp_admin_release_escrow', array(__CLASS__, 'ajax_release_escrow'));
        add_action('wp_ajax_ptp_admin_refund_escrow', array(__CLASS__, 'ajax_refund_escrow'));
        add_action('wp_ajax_ptp_admin_resolve_dispute', array(__CLASS__, 'ajax_resolve_dispute'));
        add_action('wp_ajax_ptp_admin_manual_payout', array(__CLASS__, 'ajax_manual_payout'));
    }
    
    /**
     * Get all stats for dashboard
     */
    public static function get_stats() {
        global $wpdb;
        
        $stats = new stdClass();
        
        // Escrow stats
        $stats->holding = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'holding'") ?: 0;
        $stats->holding_amount = $wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'holding'") ?: 0;
        
        $stats->awaiting_confirm = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'session_complete'") ?: 0;
        
        $stats->disputes = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'disputed'") ?: 0;
        
        // Payout stats - this week
        $week_start = date('Y-m-d', strtotime('monday this week'));
        $stats->released_this_week = $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'released' AND released_at >= %s",
            $week_start
        )) ?: 0;
        
        // Trainer pending balances (completed sessions not yet paid)
        $stats->pending_payouts = $wpdb->get_var("
            SELECT COALESCE(SUM(trainer_payout), 0) FROM {$wpdb->prefix}ptp_bookings 
            WHERE status = 'completed' AND (payout_status IS NULL OR payout_status = 'pending')
        ") ?: 0;
        
        // Trainers without Stripe Connect
        $stats->trainers_no_stripe = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}ptp_trainers 
            WHERE status = 'active' AND (stripe_account_id IS NULL OR stripe_account_id = '')
        ") ?: 0;
        
        // Total paid all time
        $stats->total_paid = $wpdb->get_var("SELECT COALESCE(SUM(trainer_amount), 0) FROM {$wpdb->prefix}ptp_escrow WHERE status = 'released'") ?: 0;
        
        return $stats;
    }
    
    /**
     * Get escrow records by status
     */
    public static function get_escrow_records($status = null, $limit = 50) {
        global $wpdb;
        
        $where = $status ? $wpdb->prepare("WHERE e.status = %s", $status) : "";
        
        return $wpdb->get_results("
            SELECT e.*, 
                   t.display_name as trainer_name, t.email as trainer_email, t.phone as trainer_phone,
                   t.stripe_account_id,
                   p.display_name as parent_name, p.email as parent_email,
                   b.session_date, b.start_time, b.location,
                   pl.name as player_name
            FROM {$wpdb->prefix}ptp_escrow e
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON e.trainer_id = t.id
            LEFT JOIN {$wpdb->prefix}ptp_parents p ON e.parent_id = p.id
            LEFT JOIN {$wpdb->prefix}ptp_bookings b ON e.booking_id = b.id
            LEFT JOIN {$wpdb->prefix}ptp_players pl ON b.player_id = pl.id
            {$where}
            ORDER BY e.created_at DESC
            LIMIT {$limit}
        ");
    }
    
    /**
     * Get trainers with pending balances
     */
    public static function get_trainer_balances() {
        global $wpdb;
        
        return $wpdb->get_results("
            SELECT 
                t.id, t.display_name, t.email, t.phone, t.stripe_account_id, t.stripe_payouts_enabled,
                COALESCE(SUM(b.trainer_payout), 0) as pending_balance,
                COUNT(b.id) as pending_sessions
            FROM {$wpdb->prefix}ptp_trainers t
            LEFT JOIN {$wpdb->prefix}ptp_bookings b ON t.id = b.trainer_id 
                AND b.status = 'completed' 
                AND (b.payout_status IS NULL OR b.payout_status = 'pending')
            WHERE t.status = 'active'
            GROUP BY t.id
            HAVING pending_balance > 0
            ORDER BY pending_balance DESC
        ");
    }
    
    /**
     * Render the admin page
     */
    public static function render_page() {
        $stats = self::get_stats();
        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
        
        ?>
        <div class="wrap ptp-payments-admin">
            <h1>Payments & Payouts</h1>
            
            <!-- Quick Stats -->
            <div class="ptp-stats-grid">
                <div class="ptp-stat-card gold">
                    <div class="ptp-stat-icon"><span class="dashicons dashicons-lock"></span></div>
                    <div class="ptp-stat-content">
                        <div class="ptp-stat-value">$<?php echo number_format($stats->holding_amount, 2); ?></div>
                        <div class="ptp-stat-label">In Escrow (<?php echo $stats->holding; ?> sessions)</div>
                    </div>
                </div>
                <div class="ptp-stat-card blue">
                    <div class="ptp-stat-icon"><span class="dashicons dashicons-clock"></span></div>
                    <div class="ptp-stat-content">
                        <div class="ptp-stat-value"><?php echo $stats->awaiting_confirm; ?></div>
                        <div class="ptp-stat-label">Awaiting Parent Confirm</div>
                    </div>
                </div>
                <div class="ptp-stat-card <?php echo $stats->disputes > 0 ? 'red' : 'gray'; ?>">
                    <div class="ptp-stat-icon"><span class="dashicons dashicons-warning"></span></div>
                    <div class="ptp-stat-content">
                        <div class="ptp-stat-value"><?php echo $stats->disputes; ?></div>
                        <div class="ptp-stat-label">Disputes to Resolve</div>
                    </div>
                </div>
                <div class="ptp-stat-card green">
                    <div class="ptp-stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="ptp-stat-content">
                        <div class="ptp-stat-value">$<?php echo number_format($stats->released_this_week, 2); ?></div>
                        <div class="ptp-stat-label">Released This Week</div>
                    </div>
                </div>
            </div>
            
            <?php if ($stats->trainers_no_stripe > 0): ?>
            <div class="ptp-alert warning">
                <strong> <?php echo $stats->trainers_no_stripe; ?> active trainer(s)</strong> haven't connected Stripe yet. 
                They won't receive automatic payouts until they do.
            </div>
            <?php endif; ?>
            
            <!-- Tabs -->
            <nav class="nav-tab-wrapper">
                <a href="?page=ptp-payments&tab=overview" class="nav-tab <?php echo $tab === 'overview' ? 'nav-tab-active' : ''; ?>">Overview</a>
                <a href="?page=ptp-payments&tab=escrow" class="nav-tab <?php echo $tab === 'escrow' ? 'nav-tab-active' : ''; ?>">Escrow</a>
                <a href="?page=ptp-payments&tab=disputes" class="nav-tab <?php echo $tab === 'disputes' ? 'nav-tab-active' : ''; ?>">
                    Disputes
                    <?php if ($stats->disputes > 0): ?>
                    <span class="ptp-badge red"><?php echo $stats->disputes; ?></span>
                    <?php endif; ?>
                </a>
                <a href="?page=ptp-payments&tab=balances" class="nav-tab <?php echo $tab === 'balances' ? 'nav-tab-active' : ''; ?>">Trainer Balances</a>
                <a href="?page=ptp-payments&tab=history" class="nav-tab <?php echo $tab === 'history' ? 'nav-tab-active' : ''; ?>">History</a>
            </nav>
            
            <div class="ptp-tab-content">
                <?php
                switch ($tab) {
                    case 'escrow':
                        self::render_escrow_tab();
                        break;
                    case 'disputes':
                        self::render_disputes_tab();
                        break;
                    case 'balances':
                        self::render_balances_tab();
                        break;
                    case 'history':
                        self::render_history_tab();
                        break;
                    default:
                        self::render_overview_tab();
                }
                ?>
            </div>
        </div>
        
        <?php self::render_styles(); ?>
        <?php self::render_scripts(); ?>
        <?php
    }
    
    /**
     * Overview Tab - Quick actions and recent activity
     */
    public static function render_overview_tab() {
        $recent_escrow = self::get_escrow_records(null, 10);
        $disputes = self::get_escrow_records('disputed', 5);
        ?>
        
        <div class="ptp-overview-grid">
            <!-- How It Works -->
            <div class="ptp-card">
                <h3>How Payments Work</h3>
                <div class="ptp-flow-diagram">
                    <div class="ptp-flow-step">
                        <span class="ptp-flow-num">1</span>
                        <strong>Parent Pays</strong>
                        <small>Funds held in escrow</small>
                    </div>
                    <div class="ptp-flow-arrow">→</div>
                    <div class="ptp-flow-step">
                        <span class="ptp-flow-num">2</span>
                        <strong>Session Happens</strong>
                        <small>Trainer marks complete</small>
                    </div>
                    <div class="ptp-flow-arrow">→</div>
                    <div class="ptp-flow-step">
                        <span class="ptp-flow-num">3</span>
                        <strong>24hr Window</strong>
                        <small>Parent confirms or disputes</small>
                    </div>
                    <div class="ptp-flow-arrow">→</div>
                    <div class="ptp-flow-step green">
                        <span class="ptp-flow-num">4</span>
                        <strong>Auto-Release</strong>
                        <small>50%→75% to trainer via Stripe</small>
                    </div>
                </div>
                <p class="ptp-note">
                    <strong>Fee Split:</strong> First session: 50/50. Repeat: PTP 25%, Trainer 75%
                </p>
            </div>
            
            <?php if (!empty($disputes)): ?>
            <!-- Disputes Needing Attention -->
            <div class="ptp-card urgent">
                <h3>Disputes Needing Attention</h3>
                <table class="ptp-table compact">
                    <thead>
                        <tr>
                            <th>Session</th>
                            <th>Amount</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($disputes as $d): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($d->trainer_name); ?></strong> → <?php echo esc_html($d->parent_name); ?>
                                <br><small><?php echo date('M j', strtotime($d->session_date)); ?></small>
                            </td>
                            <td>$<?php echo number_format($d->total_amount, 2); ?></td>
                            <td>
                                <a href="?page=ptp-payments&tab=disputes" class="button button-small">Review</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- Recent Activity -->
            <div class="ptp-card full-width">
                <h3>Recent Escrow Activity</h3>
                <?php if (empty($recent_escrow)): ?>
                    <p class="ptp-empty">No escrow records yet.</p>
                <?php else: ?>
                <table class="ptp-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Trainer</th>
                            <th>Parent</th>
                            <th>Amount</th>
                            <th>Trainer Gets</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_escrow as $e): ?>
                        <tr>
                            <td><?php echo date('M j, g:ia', strtotime($e->created_at)); ?></td>
                            <td>
                                <strong><?php echo esc_html($e->trainer_name); ?></strong>
                                <?php if (empty($e->stripe_account_id)): ?>
                                <span class="ptp-badge orange" title="No Stripe">!</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($e->parent_name); ?></td>
                            <td>$<?php echo number_format($e->total_amount, 2); ?></td>
                            <td class="ptp-green">$<?php echo number_format($e->trainer_amount, 2); ?></td>
                            <td><?php echo self::status_badge($e->status); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Escrow Tab - All escrow records
     */
    public static function render_escrow_tab() {
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $escrow = self::get_escrow_records($status_filter ?: null, 100);
        ?>
        
        <div class="ptp-filter-bar">
            <a href="?page=ptp-payments&tab=escrow" class="ptp-filter-btn <?php echo !$status_filter ? 'active' : ''; ?>">All</a>
            <a href="?page=ptp-payments&tab=escrow&status=holding" class="ptp-filter-btn <?php echo $status_filter === 'holding' ? 'active' : ''; ?>">Holding</a>
            <a href="?page=ptp-payments&tab=escrow&status=session_complete" class="ptp-filter-btn <?php echo $status_filter === 'session_complete' ? 'active' : ''; ?>">Awaiting Confirm</a>
            <a href="?page=ptp-payments&tab=escrow&status=confirmed" class="ptp-filter-btn <?php echo $status_filter === 'confirmed' ? 'active' : ''; ?>">Confirmed</a>
            <a href="?page=ptp-payments&tab=escrow&status=released" class="ptp-filter-btn <?php echo $status_filter === 'released' ? 'active' : ''; ?>">Released/a>
            <a href="?page=ptp-payments&tab=escrow&status=refunded" class="ptp-filter-btn <?php echo $status_filter === 'refunded' ? 'active' : ''; ?>">Refunded</a>
        </div>
        
        <?php if (empty($escrow)): ?>
            <div class="ptp-empty-state">
                <div class="ptp-empty-icon"><span class="dashicons dashicons-email-alt"></span></div>
                <h3>No escrow records found</h3>
                <p>Escrow records are created when parents pay for training sessions.</p>
            </div>
        <?php else: ?>
        <table class="ptp-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Session Date</th>
                    <th>Trainer</th>
                    <th>Parent / Player</th>
                    <th>Total</th>
                    <th>Trainer Gets</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($escrow as $e): ?>
                <tr data-escrow-id="<?php echo $e->id; ?>">
                    <td>#<?php echo $e->id; ?></td>
                    <td>
                        <?php echo date('M j, Y', strtotime($e->session_date)); ?>
                        <br><small><?php echo date('g:i A', strtotime($e->session_time)); ?></small>
                    </td>
                    <td>
                        <strong><?php echo esc_html($e->trainer_name); ?></strong>
                        <?php if (empty($e->stripe_account_id)): ?>
                        <br><span class="ptp-badge orange small">No Stripe</span>
                        <?php else: ?>
                        <br><span class="ptp-badge green small">Stripe OK</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php echo esc_html($e->parent_name); ?>
                        <?php if ($e->player_name): ?>
                        <br><small>Player: <?php echo esc_html($e->player_name); ?></small>
                        <?php endif; ?>
                    </td>
                    <td>$<?php echo number_format($e->total_amount, 2); ?></td>
                    <td class="ptp-green"><strong>$<?php echo number_format($e->trainer_amount, 2); ?></strong></td>
                    <td><?php echo self::status_badge($e->status); ?></td>
                    <td>
                        <?php if ($e->status === 'holding' || $e->status === 'session_complete' || $e->status === 'confirmed'): ?>
                            <button class="button button-small button-primary ptp-release-btn" data-id="<?php echo $e->id; ?>">
                                Release
                            </button>
                        <?php endif; ?>
                        <?php if ($e->status !== 'released' && $e->status !== 'refunded'): ?>
                            <button class="button button-small ptp-refund-btn" data-id="<?php echo $e->id; ?>">
                                Refund
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Disputes Tab
     */
    public static function render_disputes_tab() {
        $disputes = self::get_escrow_records('disputed', 100);
        ?>
        
        <?php if (empty($disputes)): ?>
            <div class="ptp-empty-state success">
                <div class="ptp-empty-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                <h3>No disputes!</h3>
                <p>All payments are running smoothly.</p>
            </div>
        <?php else: ?>
        
        <div class="ptp-disputes-list">
            <?php foreach ($disputes as $d): ?>
            <div class="ptp-dispute-card" data-escrow-id="<?php echo $d->id; ?>">
                <div class="ptp-dispute-header">
                    <div class="ptp-dispute-info">
                        <h4>Dispute #<?php echo $d->id; ?></h4>
                        <span class="ptp-dispute-date">Opened <?php echo human_time_diff(strtotime($d->disputed_at ?: $d->created_at)); ?> ago</span>
                    </div>
                    <div class="ptp-dispute-amount">
                        <strong>$<?php echo number_format($d->total_amount, 2); ?></strong>
                        <small>at stake</small>
                    </div>
                </div>
                
                <div class="ptp-dispute-parties">
                    <div class="ptp-dispute-party">
                        <label>Trainer</label>
                        <strong><?php echo esc_html($d->trainer_name); ?></strong>
                        <small><?php echo esc_html($d->trainer_email); ?></small>
                        <small><?php echo esc_html($d->trainer_phone); ?></small>
                    </div>
                    <div class="ptp-dispute-vs">VS</div>
                    <div class="ptp-dispute-party">
                        <label>Parent</label>
                        <strong><?php echo esc_html($d->parent_name); ?></strong>
                        <small><?php echo esc_html($d->parent_email); ?></small>
                    </div>
                </div>
                
                <div class="ptp-dispute-session">
                    <label>Session Details</label>
                    <p>
                        <?php echo date('l, F j, Y', strtotime($d->session_date)); ?> at <?php echo date('g:i A', strtotime($d->session_time)); ?>
                        <?php if ($d->location): ?>
                        <br>📍 <?php echo esc_html($d->location); ?>
                        <?php endif; ?>
                        <?php if ($d->player_name): ?>
                        <br>👤 Player: <?php echo esc_html($d->player_name); ?>
                        <?php endif; ?>
                    </p>
                </div>
                
                <?php if (!empty($d->dispute_reason)): ?>
                <div class="ptp-dispute-reason">
                    <label>Parent's Complaint</label>
                    <blockquote><?php echo esc_html($d->dispute_reason); ?></blockquote>
                </div>
                <?php endif; ?>
                
                <div class="ptp-dispute-actions">
                    <h4>Resolve This Dispute</h4>
                    <div class="ptp-dispute-options">
                        <button class="button button-primary ptp-resolve-btn" data-id="<?php echo $d->id; ?>" data-action="release">
                            Pay Trainer Full Amount ($<?php echo number_format($d->trainer_amount, 2); ?>)
                        </button>
                        <button class="button ptp-resolve-btn" data-id="<?php echo $d->id; ?>" data-action="partial">
                            Split 50/50
                        </button>
                        <button class="button ptp-resolve-btn" data-id="<?php echo $d->id; ?>" data-action="refund">
                            Full Refund to Parent
                        </button>
                    </div>
                    <div class="ptp-dispute-notes">
                        <label>Resolution Notes (optional)</label>
                        <textarea class="ptp-resolution-notes" placeholder="Add any notes about this resolution..."></textarea>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php
    }
    
    /**
     * Trainer Balances Tab
     */
    public static function render_balances_tab() {
        $trainers = self::get_trainer_balances();
        ?>
        
        <div class="ptp-card">
            <h3>Trainer Pending Balances</h3>
            <p class="ptp-note">These are completed sessions where the trainer hasn't been paid yet. Trainers with Stripe Connect receive automatic payouts.</p>
        </div>
        
        <?php if (empty($trainers)): ?>
            <div class="ptp-empty-state success">
                <div class="ptp-empty-icon">✨</div>
                <h3>All caught up!</h3>
                <p>No pending balances. All trainers have been paid.</p>
            </div>
        <?php else: ?>
        <table class="ptp-table">
            <thead>
                <tr>
                    <th>Trainer</th>
                    <th>Contact</th>
                    <th>Pending Sessions</th>
                    <th>Balance</th>
                    <th>Stripe Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($trainers as $t): ?>
                <tr>
                    <td><strong><?php echo esc_html($t->display_name); ?></strong></td>
                    <td>
                        <?php echo esc_html($t->email); ?>
                        <?php if ($t->phone): ?>
                        <br><small><?php echo esc_html($t->phone); ?></small>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $t->pending_sessions; ?> sessions</td>
                    <td class="ptp-green"><strong>$<?php echo number_format($t->pending_balance, 2); ?></strong></td>
                    <td>
                        <?php if (!empty($t->stripe_account_id) && $t->stripe_payouts_enabled): ?>
                            <span class="ptp-badge green">Connected</span>
                        <?php elseif (!empty($t->stripe_account_id)): ?>
                            <span class="ptp-badge orange">Incomplete</span>
                        <?php else: ?>
                            <span class="ptp-badge red">✗ Not Connected</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($t->stripe_account_id) && $t->stripe_payouts_enabled): ?>
                            <button class="button button-primary button-small ptp-payout-btn" 
                                    data-trainer-id="<?php echo $t->id; ?>" 
                                    data-amount="<?php echo $t->pending_balance; ?>">
                                Pay Now
                            </button>
                        <?php else: ?>
                            <span class="ptp-text-muted">Needs Stripe</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <?php
    }
    
    /**
     * History Tab
     */
    public static function render_history_tab() {
        global $wpdb;
        
        $payouts = $wpdb->get_results("
            SELECT p.*, t.display_name as trainer_name, t.email as trainer_email
            FROM {$wpdb->prefix}ptp_payouts p
            LEFT JOIN {$wpdb->prefix}ptp_trainers t ON p.trainer_id = t.id
            ORDER BY p.created_at DESC
            LIMIT 100
        ");
        
        $released = self::get_escrow_records('released', 50);
        ?>
        
        <h3>Recent Payouts</h3>
        
        <?php if (empty($payouts) && empty($released)): ?>
            <div class="ptp-empty-state">
                <div class="ptp-empty-icon"><span class="dashicons dashicons-email-alt"></span></div>
                <h3>No payout history yet</h3>
            </div>
        <?php else: ?>
        
        <?php if (!empty($released)): ?>
        <table class="ptp-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Trainer</th>
                    <th>Session</th>
                    <th>Amount Paid</th>
                    <th>Method</th>
                    <th>Transfer ID</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($released as $r): ?>
                <tr>
                    <td><?php echo date('M j, Y g:ia', strtotime($r->released_at ?: $r->created_at)); ?></td>
                    <td><strong><?php echo esc_html($r->trainer_name); ?></strong></td>
                    <td><?php echo date('M j', strtotime($r->session_date)); ?> - <?php echo esc_html($r->player_name ?: 'Session'); ?></td>
                    <td class="ptp-green">$<?php echo number_format($r->trainer_amount, 2); ?></td>
                    <td>
                        <?php if (!empty($r->stripe_transfer_id)): ?>
                            <span class="ptp-badge blue">Stripe</span>
                        <?php else: ?>
                            <span class="ptp-badge gray">Manual</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r->stripe_transfer_id)): ?>
                            <code><?php echo esc_html(substr($r->stripe_transfer_id, 0, 20)); ?>...</code>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <?php endif; ?>
        <?php
    }
    
    /**
     * Status badge helper
     */
    public static function status_badge($status) {
        $badges = array(
            'holding' => '<span class="ptp-badge gold">Holding</span>',
            'session_complete' => '<span class="ptp-badge blue">Awaiting Confirm</span>',
            'confirmed' => '<span class="ptp-badge green">Confirmed</span>',
            'disputed' => '<span class="ptp-badge red">Disputed/span>',
            'released' => '<span class="ptp-badge green">Released/span>',
            'refunded' => '<span class="ptp-badge gray">Refunded</span>',
        );
        return $badges[$status] ?? '<span class="ptp-badge">' . esc_html($status) . '</span>';
    }
    
    /**
     * AJAX: Release escrow funds
     */
    public static function ajax_release_escrow() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $escrow_id = intval($_POST['escrow_id']);
        
        if (class_exists('PTP_Escrow')) {
            $result = PTP_Escrow::release_funds($escrow_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            wp_send_json_success(array('message' => 'Funds released successfully!'));
        }
        
        wp_send_json_error(array('message' => 'Escrow system not available'));
    }
    
    /**
     * AJAX: Refund escrow
     */
    public static function ajax_refund_escrow() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $escrow_id = intval($_POST['escrow_id']);
        
        if (class_exists('PTP_Escrow')) {
            $result = PTP_Escrow::refund($escrow_id);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            wp_send_json_success(array('message' => 'Refund processed successfully!'));
        }
        
        wp_send_json_error(array('message' => 'Escrow system not available'));
    }
    
    /**
     * AJAX: Resolve dispute
     */
    public static function ajax_resolve_dispute() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $escrow_id = intval($_POST['escrow_id']);
        $action = sanitize_text_field($_POST['resolution']);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        global $wpdb;
        
        $escrow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ptp_escrow WHERE id = %d",
            $escrow_id
        ));
        
        if (!$escrow) {
            wp_send_json_error(array('message' => 'Escrow not found'));
        }
        
        switch ($action) {
            case 'release':
                // Pay trainer full amount
                if (class_exists('PTP_Escrow')) {
                    $result = PTP_Escrow::release_funds($escrow_id);
                }
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    array(
                        'resolution' => 'trainer_paid',
                        'resolution_notes' => $notes,
                        'resolved_at' => current_time('mysql'),
                        'resolved_by' => get_current_user_id(),
                    ),
                    array('id' => $escrow_id)
                );
                wp_send_json_success(array('message' => 'Dispute resolved - Trainer paid in full'));
                break;
                
            case 'partial':
                // 50/50 split
                $half = round($escrow->trainer_amount / 2, 2);
                // Would need to implement partial refund logic
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    array(
                        'status' => 'released',
                        'trainer_amount' => $half,
                        'resolution' => 'split',
                        'resolution_notes' => $notes,
                        'resolved_at' => current_time('mysql'),
                        'resolved_by' => get_current_user_id(),
                    ),
                    array('id' => $escrow_id)
                );
                wp_send_json_success(array('message' => 'Dispute resolved - Split 50/50'));
                break;
                
            case 'refund':
                // Full refund to parent
                if (class_exists('PTP_Escrow')) {
                    $result = PTP_Escrow::refund($escrow_id);
                }
                $wpdb->update(
                    $wpdb->prefix . 'ptp_escrow',
                    array(
                        'resolution' => 'refunded',
                        'resolution_notes' => $notes,
                        'resolved_at' => current_time('mysql'),
                        'resolved_by' => get_current_user_id(),
                    ),
                    array('id' => $escrow_id)
                );
                wp_send_json_success(array('message' => 'Dispute resolved - Parent refunded'));
                break;
                
            default:
                wp_send_json_error(array('message' => 'Invalid action'));
        }
    }
    
    /**
     * AJAX: Manual payout to trainer
     */
    public static function ajax_manual_payout() {
        check_ajax_referer('ptp_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $trainer_id = intval($_POST['trainer_id']);
        $amount = floatval($_POST['amount']);
        
        if (!$trainer_id || $amount <= 0) {
            wp_send_json_error(array('message' => 'Invalid parameters'));
        }
        
        // Use PTP_Stripe to create payout
        if (class_exists('PTP_Stripe')) {
            $result = PTP_Stripe::create_payout($trainer_id, $amount);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array('message' => $result->get_error_message()));
            }
            
            global $wpdb;
            
            // Update bookings as paid
            $wpdb->query($wpdb->prepare("
                UPDATE {$wpdb->prefix}ptp_bookings
                SET payout_status = 'paid', payout_at = %s
                WHERE trainer_id = %d 
                AND status = 'completed' 
                AND (payout_status IS NULL OR payout_status = 'pending')
            ", current_time('mysql'), $trainer_id));
            
            wp_send_json_success(array(
                'message' => 'Payout of $' . number_format($amount, 2) . ' sent successfully!'
            ));
        }
        
        wp_send_json_error(array('message' => 'Stripe not available'));
    }
    
    /**
     * Render CSS styles
     */
    public static function render_styles() {
        ?>
        <style>
        .ptp-payments-admin {
            max-width: 1400px;
        }
        
        /* Stats Grid */
        .ptp-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin: 20px 0;
        }
        
        .ptp-stat-card {
            display: flex;
            align-items: center;
            gap: 16px;
            background: #fff;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            border-left: 4px solid #ddd;
        }
        
        .ptp-stat-card.gold { border-left-color: #FCB900; }
        .ptp-stat-card.blue { border-left-color: #2196F3; }
        .ptp-stat-card.green { border-left-color: #4CAF50; }
        .ptp-stat-card.red { border-left-color: #f44336; }
        .ptp-stat-card.gray { border-left-color: #9e9e9e; }
        
        .ptp-stat-icon { font-size: 32px; }
        .ptp-stat-value { font-size: 28px; font-weight: 700; color: #1a1a1a; }
        .ptp-stat-label { font-size: 13px; color: #666; margin-top: 2px; }
        
        /* Alert */
        .ptp-alert {
            padding: 14px 20px;
            border-radius: 8px;
            margin: 16px 0;
        }
        .ptp-alert.warning {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
        }
        
        /* Tabs */
        .nav-tab-wrapper {
            margin-top: 20px;
            border-bottom: 2px solid #ddd;
        }
        .nav-tab {
            padding: 12px 20px;
            font-size: 14px;
            font-weight: 500;
        }
        .nav-tab-active {
            background: #FCB900;
            border-color: #FCB900;
            color: #000;
        }
        .ptp-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            margin-left: 6px;
        }
        .ptp-badge.red { background: #ffebee; color: #c62828; }
        .ptp-badge.green { background: #e8f5e9; color: #2e7d32; }
        .ptp-badge.blue { background: #e3f2fd; color: #1565c0; }
        .ptp-badge.gold { background: #fff8e1; color: #f57f17; }
        .ptp-badge.orange { background: #fff3e0; color: #e65100; }
        .ptp-badge.gray { background: #f5f5f5; color: #616161; }
        .ptp-badge.small { font-size: 10px; padding: 1px 6px; }
        
        /* Tab Content */
        .ptp-tab-content {
            background: #fff;
            padding: 24px;
            border-radius: 0 0 12px 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
        }
        
        /* Filter Bar */
        .ptp-filter-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .ptp-filter-btn {
            padding: 8px 16px;
            border: 1px solid #ddd;
            background: #fff;
            border-radius: 20px;
            text-decoration: none;
            color: #333;
            font-size: 13px;
            transition: all 0.2s;
        }
        .ptp-filter-btn:hover {
            border-color: #FCB900;
            color: #000;
        }
        .ptp-filter-btn.active {
            background: #FCB900;
            border-color: #FCB900;
            color: #000;
            font-weight: 600;
        }
        
        /* Tables */
        .ptp-table {
            width: 100%;
            border-collapse: collapse;
        }
        .ptp-table th,
        .ptp-table td {
            padding: 12px 16px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        .ptp-table th {
            background: #f8f9fa;
            font-size: 11px;
            text-transform: uppercase;
            font-weight: 600;
            color: #666;
        }
        .ptp-table tr:hover {
            background: #fffdf5;
        }
        .ptp-table.compact td {
            padding: 8px 12px;
        }
        .ptp-green { color: #2e7d32; }
        .ptp-text-muted { color: #999; font-size: 12px; }
        
        /* Cards */
        .ptp-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .ptp-card h3 {
            margin: 0 0 12px;
            font-size: 16px;
        }
        .ptp-card.urgent {
            background: #fff5f5;
            border: 1px solid #ffcdd2;
        }
        .ptp-card.full-width {
            grid-column: 1 / -1;
        }
        
        /* Overview Grid */
        .ptp-overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
        }
        
        /* Flow Diagram */
        .ptp-flow-diagram {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin: 16px 0;
        }
        .ptp-flow-step {
            background: #fff;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 12px 16px;
            text-align: center;
            min-width: 120px;
        }
        .ptp-flow-step.green {
            border-color: #4CAF50;
            background: #e8f5e9;
        }
        .ptp-flow-num {
            display: inline-block;
            width: 24px;
            height: 24px;
            background: #FCB900;
            color: #000;
            border-radius: 50%;
            font-weight: 700;
            font-size: 12px;
            line-height: 24px;
            margin-bottom: 6px;
        }
        .ptp-flow-step strong {
            display: block;
            font-size: 13px;
        }
        .ptp-flow-step small {
            display: block;
            font-size: 11px;
            color: #666;
        }
        .ptp-flow-arrow {
            font-size: 20px;
            color: #bbb;
        }
        .ptp-note {
            background: #fff;
            padding: 12px;
            border-radius: 8px;
            font-size: 13px;
            color: #666;
        }
        
        /* Empty States */
        .ptp-empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .ptp-empty-state.success {
            background: #e8f5e9;
            border-radius: 12px;
        }
        .ptp-empty-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        .ptp-empty-state h3 {
            margin: 0 0 8px;
        }
        .ptp-empty-state p {
            color: #666;
            margin: 0;
        }
        .ptp-empty {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        
        /* Dispute Cards */
        .ptp-disputes-list {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }
        .ptp-dispute-card {
            background: #fff;
            border: 2px solid #ffcdd2;
            border-radius: 16px;
            padding: 24px;
        }
        .ptp-dispute-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
            padding-bottom: 16px;
            border-bottom: 1px solid #eee;
        }
        .ptp-dispute-header h4 {
            margin: 0;
            font-size: 18px;
        }
        .ptp-dispute-date {
            color: #999;
            font-size: 13px;
        }
        .ptp-dispute-amount {
            text-align: right;
        }
        .ptp-dispute-amount strong {
            font-size: 24px;
            color: #c62828;
        }
        .ptp-dispute-amount small {
            display: block;
            color: #999;
        }
        .ptp-dispute-parties {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 20px;
        }
        .ptp-dispute-party {
            flex: 1;
            background: #f8f9fa;
            padding: 16px;
            border-radius: 12px;
        }
        .ptp-dispute-party label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 4px;
        }
        .ptp-dispute-party strong {
            display: block;
            font-size: 16px;
        }
        .ptp-dispute-party small {
            display: block;
            color: #666;
            font-size: 12px;
        }
        .ptp-dispute-vs {
            font-weight: 700;
            color: #999;
        }
        .ptp-dispute-session,
        .ptp-dispute-reason {
            margin-bottom: 20px;
        }
        .ptp-dispute-session label,
        .ptp-dispute-reason label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            color: #999;
            margin-bottom: 6px;
        }
        .ptp-dispute-reason blockquote {
            background: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 12px 16px;
            margin: 0;
            border-radius: 0 8px 8px 0;
            font-style: italic;
        }
        .ptp-dispute-actions {
            background: #f0f4f8;
            margin: -24px;
            margin-top: 20px;
            padding: 24px;
            border-radius: 0 0 14px 14px;
        }
        .ptp-dispute-actions h4 {
            margin: 0 0 16px;
            font-size: 14px;
        }
        .ptp-dispute-options {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .ptp-dispute-notes label {
            display: block;
            font-size: 12px;
            margin-bottom: 6px;
        }
        .ptp-dispute-notes textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            resize: vertical;
            min-height: 60px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .ptp-stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            .ptp-overview-grid {
                grid-template-columns: 1fr;
            }
            .ptp-dispute-parties {
                flex-direction: column;
            }
            .ptp-dispute-vs {
                display: none;
            }
        }
        </style>
        <?php
    }
    
    /**
     * Render JavaScript
     */
    public static function render_scripts() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            var nonce = '<?php echo wp_create_nonce('ptp_admin_nonce'); ?>';
            
            // Release escrow
            $('.ptp-release-btn').on('click', function() {
                var btn = $(this);
                var id = btn.data('id');
                
                if (!confirm('Release funds to trainer?')) return;
                
                btn.prop('disabled', true).text('Processing...');
                
                $.post(ajaxurl, {
                    action: 'ptp_admin_release_escrow',
                    nonce: nonce,
                    escrow_id: id
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + res.data.message);
                        btn.prop('disabled', false).text('Release');
                    }
                });
            });
            
            // Refund escrow
            $('.ptp-refund-btn').on('click', function() {
                var btn = $(this);
                var id = btn.data('id');
                
                if (!confirm('Refund payment to parent? This cannot be undone.')) return;
                
                btn.prop('disabled', true).text('Processing...');
                
                $.post(ajaxurl, {
                    action: 'ptp_admin_refund_escrow',
                    nonce: nonce,
                    escrow_id: id
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + res.data.message);
                        btn.prop('disabled', false).text('Refund');
                    }
                });
            });
            
            // Resolve dispute
            $('.ptp-resolve-btn').on('click', function() {
                var btn = $(this);
                var id = btn.data('id');
                var action = btn.data('action');
                var card = btn.closest('.ptp-dispute-card');
                var notes = card.find('.ptp-resolution-notes').val();
                
                var confirmMsg = {
                    'release': 'Pay trainer the full amount?',
                    'partial': 'Split payment 50/50 between trainer and parent?',
                    'refund': 'Refund the full amount to parent?'
                };
                
                if (!confirm(confirmMsg[action])) return;
                
                btn.prop('disabled', true).text('Processing...');
                
                $.post(ajaxurl, {
                    action: 'ptp_admin_resolve_dispute',
                    nonce: nonce,
                    escrow_id: id,
                    resolution: action,
                    notes: notes
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + res.data.message);
                        btn.prop('disabled', false);
                    }
                });
            });
            
            // Manual payout
            $('.ptp-payout-btn').on('click', function() {
                var btn = $(this);
                var trainerId = btn.data('trainer-id');
                var amount = btn.data('amount');
                
                if (!confirm('Send payout of $' + parseFloat(amount).toFixed(2) + ' to trainer?')) return;
                
                btn.prop('disabled', true).text('Sending...');
                
                $.post(ajaxurl, {
                    action: 'ptp_admin_manual_payout',
                    nonce: nonce,
                    trainer_id: trainerId,
                    amount: amount
                }, function(res) {
                    if (res.success) {
                        alert(res.data.message);
                        location.reload();
                    } else {
                        alert('Error: ' + res.data.message);
                        btn.prop('disabled', false).text('Pay Now');
                    }
                });
            });
        });
        </script>
        <?php
    }
}

// Initialize
PTP_Admin_Payouts_V2::init();
