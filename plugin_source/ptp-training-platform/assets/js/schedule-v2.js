/**
 * PTP Schedule Calendar v2.0
 * Enhanced interactive calendar with:
 * - Keyboard shortcuts
 * - Bulk actions
 * - Conflict detection
 * - Real-time stats
 * - Export functionality
 * - Advanced filtering
 */

(function() {
    'use strict';

    // State
    let calendar = null;
    let selectedTrainers = [];
    let focusedTrainer = null;
    let selectedEvents = [];
    let filters = { status: 'all', payment: '', type: '', search: '' };

    // Initialize
    document.addEventListener('DOMContentLoaded', init);

    function init() {
        const calendarEl = document.getElementById('calendar');
        if (!calendarEl) return;

        initCalendar(calendarEl);
        initTrainerFilters();
        initModal();
        initRecurringModal();
        initExportModal();
        initKeyboardShortcuts();
        initFilters();
        initBulkActions();
        initSearch();
        loadDashboardStats();
        loadTrainerCounts();

        // Auto-refresh stats every 5 minutes
        setInterval(loadDashboardStats, 300000);
    }

    // ===== Calendar =====
    function initCalendar(el) {
        // Initialize selected trainers
        document.querySelectorAll('#trainerList input[type="checkbox"]:checked').forEach(cb => {
            selectedTrainers.push(parseInt(cb.value));
        });

        calendar = new FullCalendar.Calendar(el, {
            initialView: PTPSchedule.defaultView || 'timeGridWeek',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
            },
            slotMinTime: '06:00:00',
            slotMaxTime: '22:00:00',
            allDaySlot: false,
            nowIndicator: true,
            selectable: true,
            selectMirror: true,
            editable: true,
            eventDurationEditable: true,
            slotDuration: '00:30:00',
            height: 'auto',
            weekNumbers: true,
            navLinks: true,
            dayMaxEvents: 4,
            eventMaxStack: 3,

            events: function(info, success, failure) {
                const trainers = focusedTrainer ? [focusedTrainer] : selectedTrainers;
                
                const params = new URLSearchParams({
                    action: 'ptp_schedule_get_events',
                    nonce: PTPSchedule.nonce,
                    start: info.startStr,
                    end: info.endStr,
                    trainers: trainers.join(','),
                    status: filters.status,
                    payment: filters.payment,
                    type: filters.type,
                    search: filters.search
                });

                fetch(PTPSchedule.ajax + '?' + params)
                    .then(r => r.json())
                    .then(data => {
                        // Add trainer color to events
                        data.forEach(event => {
                            const trainer = PTPSchedule.trainers.find(t => t.id == event.extendedProps.trainer_id);
                            if (trainer) {
                                event.borderColor = trainer.color;
                            }
                            // Mark selected events
                            if (selectedEvents.includes(event.id)) {
                                event.classNames = (event.classNames || []).concat('selected');
                            }
                        });
                        success(data);
                    })
                    .catch(err => failure(err));
            },

            select: function(info) {
                openSessionModal();
                document.getElementById('sessionDate').value = info.startStr.split('T')[0];
                const time = info.startStr.split('T')[1];
                document.getElementById('startTime').value = time ? time.slice(0, 5) : '09:00';
                
                if (focusedTrainer) {
                    document.getElementById('trainerId').value = focusedTrainer;
                }
                calendar.unselect();
            },

            eventClick: function(info) {
                if (info.jsEvent.ctrlKey || info.jsEvent.metaKey) {
                    // Multi-select with Ctrl/Cmd
                    toggleEventSelection(info.event);
                } else {
                    openSessionModal(info.event);
                }
            },

            eventDrop: function(info) {
                quickUpdateEvent(info.event);
            },

            eventResize: function(info) {
                quickUpdateEvent(info.event);
            },

            eventDidMount: function(info) {
                // Add tooltip
                if (typeof tippy !== 'undefined') {
                    const props = info.event.extendedProps;
                    tippy(info.el, {
                        content: `
                            <div class="ptp-tooltip">
                                <strong>${props.player_name || 'Session'}</strong>
                                <div>${props.trainer_name || 'Unassigned'}</div>
                                <div class="ptp-tooltip-meta">
                                    <span class="status-${props.session_status}">${props.session_status}</span>
                                    ${props.price ? ' • $' + parseFloat(props.price).toFixed(0) : ''}
                                </div>
                                ${props.location_text ? '<div>📍 ' + props.location_text + '</div>' : ''}
                            </div>
                        `,
                        allowHTML: true,
                        theme: 'ptp',
                        placement: 'top',
                        arrow: true,
                    });
                }

                // Add right-click context menu
                info.el.addEventListener('contextmenu', (e) => {
                    e.preventDefault();
                    showContextMenu(e, info.event);
                });
            },

            viewDidMount: function(info) {
                // Save user's view preference
                saveViewPreference(info.view.type);
            }
        });

        calendar.render();
    }

    function toggleEventSelection(event) {
        const idx = selectedEvents.indexOf(event.id);
        if (idx > -1) {
            selectedEvents.splice(idx, 1);
            event.setProp('classNames', event.classNames.filter(c => c !== 'selected'));
        } else {
            selectedEvents.push(event.id);
            event.setProp('classNames', [...event.classNames, 'selected']);
        }
        updateBulkActionsBar();
    }

    function updateBulkActionsBar() {
        const bar = document.getElementById('bulkActionsBar');
        const count = document.getElementById('selectedCount');
        
        if (selectedEvents.length > 0) {
            bar.classList.add('show');
            count.textContent = selectedEvents.length + ' selected';
        } else {
            bar.classList.remove('show');
        }
    }

    function clearSelection() {
        selectedEvents = [];
        calendar.refetchEvents();
        updateBulkActionsBar();
    }

    function quickUpdateEvent(event) {
        const p = event.extendedProps;
        const formData = new FormData();
        formData.append('action', 'ptp_schedule_update_session');
        formData.append('nonce', PTPSchedule.nonce);
        formData.append('id', event.id);
        formData.append('session_date', event.startStr.split('T')[0]);
        formData.append('start_time', event.startStr.split('T')[1]?.slice(0, 5) || '09:00');
        formData.append('trainer_id', p.trainer_id);
        formData.append('customer_id', p.customer_id || 0);
        formData.append('parent_id', p.parent_id || 0);
        formData.append('player_id', p.player_id || 0);
        formData.append('player_name', p.player_name || '');
        formData.append('player_age', p.player_age || '');
        formData.append('session_status', p.session_status || 'scheduled');
        formData.append('payment_status', p.payment_status || 'unpaid');
        formData.append('session_type', p.session_type || '1on1');
        formData.append('location_text', p.location_text || '');
        formData.append('price', p.price || 0);
        formData.append('internal_notes', p.internal_notes || '');

        if (event.end) {
            const dur = (new Date(event.end) - new Date(event.start)) / 60000;
            formData.append('duration_minutes', dur);
        }

        fetch(PTPSchedule.ajax, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    toast('Session updated', 'success');
                    loadDashboardStats();
                } else {
                    calendar.refetchEvents();
                    toast('Failed to update', 'error');
                }
            });
    }

    function showContextMenu(e, event) {
        // Remove existing menus
        document.querySelectorAll('.ptp-context-menu').forEach(m => m.remove());
        
        const menu = document.createElement('div');
        menu.className = 'ptp-context-menu';
        menu.innerHTML = `
            <div class="ptp-context-item" data-action="edit">✏️ Edit</div>
            <div class="ptp-context-item" data-action="confirm">✅ Mark Confirmed</div>
            <div class="ptp-context-item" data-action="complete">✓ Mark Completed</div>
            <div class="ptp-context-item" data-action="cancel">❌ Cancel</div>
            <div class="ptp-context-divider"></div>
            <div class="ptp-context-item danger" data-action="delete">🗑️ Delete</div>
        `;
        
        menu.style.left = e.pageX + 'px';
        menu.style.top = e.pageY + 'px';
        document.body.appendChild(menu);
        
        menu.addEventListener('click', (e) => {
            const item = e.target.closest('.ptp-context-item');
            if (!item) return;
            
            const action = item.dataset.action;
            menu.remove();
            
            if (action === 'edit') {
                openSessionModal(event);
            } else if (action === 'delete') {
                if (confirm('Delete this session?')) {
                    deleteSession(event.id);
                }
            } else {
                quickStatusChange(event.id, action === 'confirm' ? 'confirmed' : action === 'complete' ? 'completed' : 'cancelled');
            }
        });
        
        // Close on click outside
        setTimeout(() => {
            document.addEventListener('click', function closeMenu(e) {
                if (!menu.contains(e.target)) {
                    menu.remove();
                    document.removeEventListener('click', closeMenu);
                }
            });
        }, 0);
    }

    function quickStatusChange(eventId, newStatus) {
        const formData = new FormData();
        formData.append('action', 'ptp_schedule_quick_status');
        formData.append('nonce', PTPSchedule.nonce);
        formData.append('id', eventId);
        formData.append('status', newStatus);

        fetch(PTPSchedule.ajax, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    calendar.refetchEvents();
                    loadDashboardStats();
                    toast('Status updated', 'success');
                } else {
                    toast('Failed to update status', 'error');
                }
            });
    }

    function deleteSession(eventId) {
        const formData = new FormData();
        formData.append('action', 'ptp_schedule_delete_session');
        formData.append('nonce', PTPSchedule.nonce);
        formData.append('id', eventId);

        fetch(PTPSchedule.ajax, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    calendar.refetchEvents();
                    loadDashboardStats();
                    loadTrainerCounts();
                    toast('Session deleted', 'success');
                } else {
                    toast('Failed to delete', 'error');
                }
            });
    }

    function saveViewPreference(view) {
        // Could save to user meta via AJAX
        localStorage.setItem('ptp_calendar_view', view);
    }

    // ===== Trainer Filters =====
    function initTrainerFilters() {
        document.getElementById('selectAll')?.addEventListener('click', function() {
            selectedTrainers = [];
            document.querySelectorAll('#trainerList .ptp-trainer-row').forEach(row => {
                const cb = row.querySelector('input[type="checkbox"]');
                if (cb) {
                    cb.checked = true;
                    selectedTrainers.push(parseInt(cb.value));
                    row.classList.add('selected');
                }
            });
            this.classList.add('active');
            document.getElementById('selectNone')?.classList.remove('active');
            calendar?.refetchEvents();
        });

        document.getElementById('selectNone')?.addEventListener('click', function() {
            selectedTrainers = [];
            document.querySelectorAll('#trainerList .ptp-trainer-row').forEach(row => {
                const cb = row.querySelector('input[type="checkbox"]');
                if (cb) {
                    cb.checked = false;
                    row.classList.remove('selected');
                }
            });
            this.classList.add('active');
            document.getElementById('selectAll')?.classList.remove('active');
            calendar?.refetchEvents();
        });

        document.getElementById('trainerList')?.addEventListener('click', function(e) {
            const row = e.target.closest('.ptp-trainer-row');
            if (!row) return;
            
            const cb = row.querySelector('input[type="checkbox"]');
            if (!cb) return;

            if (e.target.type !== 'checkbox') {
                cb.checked = !cb.checked;
            }

            const id = parseInt(cb.value);
            if (cb.checked) {
                if (!selectedTrainers.includes(id)) selectedTrainers.push(id);
                row.classList.add('selected');
            } else {
                selectedTrainers = selectedTrainers.filter(t => t !== id);
                row.classList.remove('selected');
            }

            updateFilterButtons();
            calendar?.refetchEvents();
        });

        document.getElementById('trainerList')?.addEventListener('dblclick', function(e) {
            const row = e.target.closest('.ptp-trainer-row');
            if (!row) return;
            enterFocusMode(row);
        });

        document.getElementById('exitFocus')?.addEventListener('click', exitFocusMode);

        // Quick action buttons
        document.getElementById('btnNewSession')?.addEventListener('click', () => {
            openSessionModal();
            document.getElementById('sessionDate').value = new Date().toISOString().split('T')[0];
            document.getElementById('startTime').value = '09:00';
        });

        document.getElementById('btnRecurring')?.addEventListener('click', () => {
            openRecurringModal();
        });

        document.getElementById('btnToday')?.addEventListener('click', () => {
            calendar?.today();
            calendar?.changeView('timeGridDay');
        });

        document.getElementById('btnThisWeek')?.addEventListener('click', () => {
            calendar?.today();
            calendar?.changeView('timeGridWeek');
        });

        document.getElementById('btnRefresh')?.addEventListener('click', () => {
            calendar?.refetchEvents();
            loadDashboardStats();
            loadTrainerCounts();
            toast('Refreshed', 'success');
        });
    }

    function updateFilterButtons() {
        const total = document.querySelectorAll('#trainerList .ptp-trainer-row').length;
        document.getElementById('selectAll')?.classList.toggle('active', selectedTrainers.length === total);
        document.getElementById('selectNone')?.classList.toggle('active', selectedTrainers.length === 0);
    }

    function enterFocusMode(row) {
        focusedTrainer = parseInt(row.dataset.id);
        document.getElementById('focusName').textContent = row.dataset.name;
        document.getElementById('focusBanner').classList.add('show');
        document.querySelectorAll('.ptp-trainer-row').forEach(r => r.classList.remove('focused'));
        row.classList.add('focused');
        calendar?.refetchEvents();
    }

    function exitFocusMode() {
        focusedTrainer = null;
        document.getElementById('focusBanner')?.classList.remove('show');
        document.querySelectorAll('.ptp-trainer-row').forEach(r => r.classList.remove('focused'));
        calendar?.refetchEvents();
    }

    // ===== Filters =====
    function initFilters() {
        // Status filter chips
        document.querySelectorAll('.ptp-status-filters .ptp-chip').forEach(chip => {
            chip.addEventListener('click', function() {
                document.querySelectorAll('.ptp-status-filters .ptp-chip').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                this.querySelector('input').checked = true;
                filters.status = this.dataset.status;
                calendar?.refetchEvents();
            });
        });

        // Payment filter
        document.getElementById('paymentFilter')?.addEventListener('change', function() {
            filters.payment = this.value;
            calendar?.refetchEvents();
        });

        // Type filter
        document.getElementById('typeFilter')?.addEventListener('change', function() {
            filters.type = this.value;
            calendar?.refetchEvents();
        });

        // Clear filters
        document.getElementById('clearFilters')?.addEventListener('click', () => {
            filters = { status: 'all', payment: '', type: '', search: '' };
            document.querySelector('.ptp-status-filters .ptp-chip[data-status="all"]')?.click();
            document.getElementById('paymentFilter').value = '';
            document.getElementById('typeFilter').value = '';
            document.getElementById('globalSearch').value = '';
            calendar?.refetchEvents();
        });
    }

    // ===== Search =====
    function initSearch() {
        const searchInput = document.getElementById('globalSearch');
        let timeout;

        searchInput?.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                filters.search = this.value.trim();
                calendar?.refetchEvents();
            }, 300);
        });
    }

    // ===== Bulk Actions =====
    function initBulkActions() {
        document.querySelectorAll('.ptp-bulk-bar button[data-action]').forEach(btn => {
            btn.addEventListener('click', function() {
                const action = this.dataset.action;
                if (selectedEvents.length === 0) return;

                const formData = new FormData();
                formData.append('action', 'ptp_schedule_bulk_action');
                formData.append('nonce', PTPSchedule.nonce);
                formData.append('bulk_action', action);
                selectedEvents.forEach(id => formData.append('ids[]', id));

                fetch(PTPSchedule.ajax, { method: 'POST', body: formData })
                    .then(r => r.json())
                    .then(result => {
                        if (result.success) {
                            toast(`Updated ${result.data.updated} sessions`, 'success');
                            clearSelection();
                            calendar?.refetchEvents();
                            loadDashboardStats();
                        } else {
                            toast('Bulk action failed', 'error');
                        }
                    });
            });
        });

        document.getElementById('clearSelection')?.addEventListener('click', clearSelection);
    }

    // ===== Session Modal =====
    function initModal() {
        const modal = document.getElementById('sessionModal');
        const form = document.getElementById('sessionForm');

        // Close handlers
        modal?.querySelectorAll('.ptp-modal-close, .ptp-modal-cancel').forEach(btn => {
            btn.addEventListener('click', closeSessionModal);
        });

        modal?.addEventListener('click', (e) => {
            if (e.target === modal) closeSessionModal();
        });

        // Tab switching
        modal?.querySelectorAll('.ptp-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                modal.querySelectorAll('.ptp-tab').forEach(t => t.classList.remove('active'));
                modal.querySelectorAll('.ptp-tab-content').forEach(c => c.classList.remove('active'));
                this.classList.add('active');
                modal.querySelector(`.ptp-tab-content[data-tab="${this.dataset.tab}"]`)?.classList.add('active');
            });
        });

        // Session type selector
        modal?.querySelectorAll('.ptp-type-option').forEach(opt => {
            opt.addEventListener('click', function() {
                modal.querySelectorAll('.ptp-type-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');
            });
        });

        // Duration selector
        modal?.querySelectorAll('.ptp-duration-option').forEach(opt => {
            opt.addEventListener('click', function() {
                modal.querySelectorAll('.ptp-duration-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');
                checkConflicts();
            });
        });

        // Price calculation
        document.getElementById('price')?.addEventListener('input', updatePaymentSummary);

        // Conflict checking
        ['trainerId', 'sessionDate', 'startTime'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', checkConflicts);
        });

        // Form submit
        form?.addEventListener('submit', function(e) {
            e.preventDefault();
            saveSession();
        });

        // Delete button
        document.getElementById('deleteSession')?.addEventListener('click', function() {
            const id = document.getElementById('sessionId').value;
            if (id && confirm('Are you sure you want to delete this session?')) {
                deleteSession(id);
                closeSessionModal();
            }
        });

        // Customer search
        initCustomerSearch();
    }

    function openSessionModal(event = null) {
        const modal = document.getElementById('sessionModal');
        const form = document.getElementById('sessionForm');
        const deleteBtn = document.getElementById('deleteSession');

        form.reset();
        document.getElementById('conflictWarning').style.display = 'none';
        document.getElementById('selectedCustomer').style.display = 'none';
        document.getElementById('playerId').innerHTML = '<option value="">-- Select or enter manually --</option>';

        // Reset tabs
        modal.querySelectorAll('.ptp-tab').forEach(t => t.classList.remove('active'));
        modal.querySelectorAll('.ptp-tab-content').forEach(c => c.classList.remove('active'));
        modal.querySelector('.ptp-tab[data-tab="details"]')?.classList.add('active');
        modal.querySelector('.ptp-tab-content[data-tab="details"]')?.classList.add('active');

        // Reset selectors
        modal.querySelectorAll('.ptp-type-option').forEach(o => o.classList.remove('active'));
        modal.querySelector('.ptp-type-option:first-child')?.classList.add('active');
        modal.querySelectorAll('.ptp-duration-option').forEach(o => o.classList.remove('active'));
        modal.querySelector('.ptp-duration-option input[value="60"]')?.closest('.ptp-duration-option')?.classList.add('active');

        if (event) {
            // Edit mode
            document.getElementById('modalTitle').textContent = 'Edit Session';
            deleteBtn.style.display = 'flex';
            
            const p = event.extendedProps;
            document.getElementById('sessionId').value = event.id;
            document.getElementById('sessionType').value = p.source;
            document.getElementById('trainerId').value = p.trainer_id || '';
            document.getElementById('sessionStatus').value = p.session_status || 'scheduled';
            document.getElementById('sessionDate').value = event.startStr.split('T')[0];
            document.getElementById('startTime').value = event.startStr.split('T')[1]?.slice(0, 5) || '';
            document.getElementById('playerName').value = p.player_name || '';
            document.getElementById('playerAge').value = p.player_age || '';
            document.getElementById('locationText').value = p.location_text || '';
            document.getElementById('price').value = p.price || '';
            document.getElementById('paymentStatus').value = p.payment_status || 'unpaid';
            document.getElementById('internalNotes').value = p.internal_notes || '';

            // Set duration
            const duration = p.duration_minutes || 60;
            modal.querySelectorAll('.ptp-duration-option').forEach(o => {
                o.classList.remove('active');
                if (o.querySelector('input').value == duration) {
                    o.classList.add('active');
                    o.querySelector('input').checked = true;
                }
            });

            // Set session type
            modal.querySelectorAll('.ptp-type-option').forEach(o => {
                o.classList.remove('active');
                if (o.querySelector('input').value === p.session_type) {
                    o.classList.add('active');
                    o.querySelector('input').checked = true;
                }
            });

            // Set customer if exists
            if (p.customer_id && p.customer_name) {
                document.getElementById('customerId').value = p.customer_id;
                document.getElementById('parentId').value = p.parent_id || '';
                document.getElementById('customerName').textContent = p.customer_name;
                document.getElementById('selectedCustomer').style.display = 'flex';
                
                if (p.parent_id) {
                    loadPlayersForParent(p.parent_id, p.player_id);
                }
            }

            // Source badge
            const sourceBadge = document.getElementById('sessionSource');
            if (p.source === 'booking') {
                sourceBadge.textContent = 'Parent Booking';
                sourceBadge.className = 'ptp-source-badge booking';
            } else {
                sourceBadge.textContent = 'Admin Session';
                sourceBadge.className = 'ptp-source-badge admin';
            }

            updatePaymentSummary();
        } else {
            // New session mode
            document.getElementById('modalTitle').textContent = 'New Session';
            deleteBtn.style.display = 'none';
            document.getElementById('sessionId').value = '';
            document.getElementById('sessionSource').className = 'ptp-source-badge';
            document.getElementById('sessionSource').textContent = '';

            // Set default date to today if not already set
            if (!document.getElementById('sessionDate').value) {
                document.getElementById('sessionDate').value = new Date().toISOString().split('T')[0];
            }
        }

        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeSessionModal() {
        document.getElementById('sessionModal')?.classList.remove('open');
        document.body.style.overflow = '';
    }

    function saveSession() {
        const form = document.getElementById('sessionForm');
        const formData = new FormData(form);
        
        const sessionId = document.getElementById('sessionId').value;
        formData.append('action', sessionId ? 'ptp_schedule_update_session' : 'ptp_schedule_create_session');
        formData.append('nonce', PTPSchedule.nonce);

        // Get duration from selector
        const duration = document.querySelector('.ptp-duration-option.active input')?.value || 60;
        formData.set('duration_minutes', duration);

        fetch(PTPSchedule.ajax, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    closeSessionModal();
                    calendar?.refetchEvents();
                    loadDashboardStats();
                    loadTrainerCounts();
                    toast(sessionId ? 'Session updated' : 'Session created', 'success');
                } else {
                    toast(result.data || 'Error saving session', 'error');
                }
            });
    }

    function checkConflicts() {
        const trainerId = document.getElementById('trainerId').value;
        const date = document.getElementById('sessionDate').value;
        const time = document.getElementById('startTime').value;
        const duration = document.querySelector('.ptp-duration-option.active input')?.value || 60;
        const excludeId = document.getElementById('sessionId').value;

        if (!trainerId || !date || !time) {
            document.getElementById('conflictWarning').style.display = 'none';
            return;
        }

        const params = new URLSearchParams({
            action: 'ptp_schedule_check_conflicts',
            nonce: PTPSchedule.nonce,
            trainer_id: trainerId,
            date: date,
            start_time: time,
            duration: duration,
            exclude_id: excludeId
        });

        fetch(PTPSchedule.ajax + '?' + params)
            .then(r => r.json())
            .then(result => {
                const warning = document.getElementById('conflictWarning');
                if (result.success && result.data.conflict) {
                    warning.style.display = 'flex';
                    document.getElementById('conflictMessage').textContent = 'This trainer has a scheduling conflict at this time';
                } else {
                    warning.style.display = 'none';
                }
            });
    }

    function updatePaymentSummary() {
        const price = parseFloat(document.getElementById('price').value) || 0;
        const fee = price * 0.20;
        const payout = price - fee;

        document.getElementById('summaryPrice').textContent = '$' + price.toFixed(2);
        document.getElementById('summaryFee').textContent = '-$' + fee.toFixed(2);
        document.getElementById('summaryPayout').textContent = '$' + payout.toFixed(2);
    }

    function initCustomerSearch() {
        const input = document.getElementById('customerSearch');
        const results = document.getElementById('customerResults');
        let timeout;

        input?.addEventListener('input', function() {
            clearTimeout(timeout);
            const q = this.value.trim();

            if (q.length < 2) {
                results.classList.remove('show');
                return;
            }

            timeout = setTimeout(() => {
                fetch(PTPSchedule.ajax + '?' + new URLSearchParams({
                    action: 'ptp_schedule_search_customers',
                    nonce: PTPSchedule.nonce,
                    q: q
                }))
                .then(r => r.json())
                .then(result => {
                    if (result.success && result.data.length) {
                        results.innerHTML = result.data.map(u => `
                            <div class="ptp-search-result" data-id="${u.user_id}" data-parent-id="${u.parent_id || ''}" data-name="${u.display_name}">
                                <strong>${u.display_name}</strong>
                                <small>${u.user_email}</small>
                            </div>
                        `).join('');
                        results.classList.add('show');
                    } else {
                        results.innerHTML = '<div class="ptp-search-result"><small>No results found</small></div>';
                        results.classList.add('show');
                    }
                });
            }, 300);
        });

        input?.addEventListener('blur', () => {
            setTimeout(() => results.classList.remove('show'), 200);
        });

        results?.addEventListener('click', function(e) {
            const item = e.target.closest('.ptp-search-result');
            if (item && item.dataset.id) {
                document.getElementById('customerId').value = item.dataset.id;
                document.getElementById('parentId').value = item.dataset.parentId || '';
                document.getElementById('customerName').textContent = item.dataset.name;
                document.getElementById('selectedCustomer').style.display = 'flex';
                input.value = '';
                results.classList.remove('show');
                
                if (item.dataset.parentId) {
                    loadPlayersForParent(item.dataset.parentId);
                }
            }
        });

        document.getElementById('clearCustomer')?.addEventListener('click', function() {
            document.getElementById('customerId').value = '';
            document.getElementById('parentId').value = '';
            document.getElementById('selectedCustomer').style.display = 'none';
            document.getElementById('playerId').innerHTML = '<option value="">-- Select or enter manually --</option>';
        });
        
        document.getElementById('playerId')?.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                document.getElementById('playerName').value = opt.dataset.name || '';
                document.getElementById('playerAge').value = opt.dataset.age || '';
            }
        });
    }

    function loadPlayersForParent(parentId, selectedPlayerId = null) {
        if (!parentId) return;

        fetch(PTPSchedule.ajax + '?' + new URLSearchParams({
            action: 'ptp_schedule_search_players',
            nonce: PTPSchedule.nonce,
            parent_id: parentId
        }))
        .then(r => r.json())
        .then(data => {
            let html = '<option value="">-- Select or enter manually --</option>';
            if (data.success && data.data.length) {
                data.data.forEach(p => {
                    const selected = selectedPlayerId && p.id == selectedPlayerId ? ' selected' : '';
                    html += `<option value="${p.id}" data-name="${p.name}" data-age="${p.age || ''}"${selected}>${p.name}${p.age ? ' (Age ' + p.age + ')' : ''}</option>`;
                });
            }
            document.getElementById('playerId').innerHTML = html;
            
            // Auto-fill player name if selected
            if (selectedPlayerId) {
                const opt = document.querySelector(`#playerId option[value="${selectedPlayerId}"]`);
                if (opt) {
                    document.getElementById('playerName').value = opt.dataset.name || '';
                    document.getElementById('playerAge').value = opt.dataset.age || '';
                }
            }
        });
    }

    // ===== Recurring Modal =====
    function initRecurringModal() {
        const modal = document.getElementById('recurringModal');
        const form = document.getElementById('recurringForm');

        modal?.querySelectorAll('.ptp-modal-close, .ptp-modal-cancel').forEach(btn => {
            btn.addEventListener('click', () => {
                modal.classList.remove('open');
                document.body.style.overflow = '';
            });
        });

        modal?.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('open');
                document.body.style.overflow = '';
            }
        });

        // Preview generation
        ['recurringTrainer', 'recurringFrequency', 'recurringTime', 'recurringStart', 'recurringCount'].forEach(id => {
            document.getElementById(id)?.addEventListener('change', updateRecurringPreview);
        });

        document.querySelectorAll('.ptp-day-selector input').forEach(cb => {
            cb.addEventListener('change', updateRecurringPreview);
        });

        // Set default start date
        document.getElementById('recurringStart').value = new Date().toISOString().split('T')[0];

        form?.addEventListener('submit', function(e) {
            e.preventDefault();
            createRecurringSessions();
        });
    }

    function openRecurringModal() {
        const modal = document.getElementById('recurringModal');
        document.getElementById('recurringForm').reset();
        document.getElementById('recurringStart').value = new Date().toISOString().split('T')[0];
        document.getElementById('previewDates').innerHTML = '';
        modal.classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function updateRecurringPreview() {
        const startDate = document.getElementById('recurringStart').value;
        const count = parseInt(document.getElementById('recurringCount').value) || 8;
        const frequency = document.getElementById('recurringFrequency').value;
        const time = document.getElementById('recurringTime').value;
        const days = Array.from(document.querySelectorAll('.ptp-day-selector input:checked')).map(cb => parseInt(cb.value));

        if (!startDate || days.length === 0) {
            document.getElementById('previewDates').innerHTML = '<p class="ptp-muted">Select days to see preview</p>';
            return;
        }

        const dates = [];
        const current = new Date(startDate);
        const interval = frequency === 'weekly' ? 7 : frequency === 'biweekly' ? 14 : 28;
        let sessionsFound = 0;
        let iterations = 0;
        const maxIterations = count * 7;

        while (sessionsFound < count && iterations < maxIterations) {
            iterations++;
            const dayOfWeek = current.getDay();

            if (days.includes(dayOfWeek)) {
                dates.push(new Date(current));
                sessionsFound++;
            }

            current.setDate(current.getDate() + 1);
        }

        const html = dates.slice(0, 10).map(d => {
            const dayName = d.toLocaleDateString('en-US', { weekday: 'short' });
            const dateStr = d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
            return `<div class="ptp-preview-date"><span>${dayName}</span> ${dateStr} @ ${time}</div>`;
        }).join('');

        document.getElementById('previewDates').innerHTML = html + (dates.length > 10 ? `<div class="ptp-muted">...and ${dates.length - 10} more</div>` : '');
    }

    function createRecurringSessions() {
        const form = document.getElementById('recurringForm');
        const formData = new FormData(form);
        formData.append('action', 'ptp_schedule_create_recurring');
        formData.append('nonce', PTPSchedule.nonce);

        fetch(PTPSchedule.ajax, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    document.getElementById('recurringModal').classList.remove('open');
                    document.body.style.overflow = '';
                    calendar?.refetchEvents();
                    loadDashboardStats();
                    loadTrainerCounts();
                    
                    let msg = `Created ${result.data.created} sessions`;
                    if (result.data.conflicts > 0) {
                        msg += ` (${result.data.conflicts} skipped due to conflicts)`;
                    }
                    toast(msg, 'success');
                } else {
                    toast(result.data || 'Failed to create sessions', 'error');
                }
            });
    }

    // ===== Export Modal =====
    function initExportModal() {
        const modal = document.getElementById('exportModal');
        const form = document.getElementById('exportForm');

        document.getElementById('btnExport')?.addEventListener('click', () => {
            // Set default date range to current month
            const now = new Date();
            const start = new Date(now.getFullYear(), now.getMonth(), 1);
            const end = new Date(now.getFullYear(), now.getMonth() + 1, 0);
            
            document.getElementById('exportStart').value = start.toISOString().split('T')[0];
            document.getElementById('exportEnd').value = end.toISOString().split('T')[0];
            
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        });

        modal?.querySelectorAll('.ptp-modal-close, .ptp-modal-cancel').forEach(btn => {
            btn.addEventListener('click', () => {
                modal.classList.remove('open');
                document.body.style.overflow = '';
            });
        });

        modal?.querySelectorAll('.ptp-export-option').forEach(opt => {
            opt.addEventListener('click', function() {
                modal.querySelectorAll('.ptp-export-option').forEach(o => o.classList.remove('active'));
                this.classList.add('active');
            });
        });

        form?.addEventListener('submit', function(e) {
            e.preventDefault();
            exportCalendar();
        });
    }

    function exportCalendar() {
        const form = document.getElementById('exportForm');
        const formData = new FormData(form);
        formData.append('action', 'ptp_schedule_export');
        formData.append('nonce', PTPSchedule.nonce);

        fetch(PTPSchedule.ajax, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(result => {
                if (result.success) {
                    // Download file
                    const blob = new Blob([result.data.data], { type: result.data.mime });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = result.data.filename;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);

                    document.getElementById('exportModal').classList.remove('open');
                    document.body.style.overflow = '';
                    toast('Export downloaded', 'success');
                } else {
                    toast('Export failed', 'error');
                }
            });
    }

    // ===== Keyboard Shortcuts =====
    function initKeyboardShortcuts() {
        document.getElementById('btnKeyboardHelp')?.addEventListener('click', () => {
            document.getElementById('keyboardModal').classList.add('open');
            document.body.style.overflow = 'hidden';
        });

        document.getElementById('keyboardModal')?.querySelectorAll('.ptp-modal-close').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('keyboardModal').classList.remove('open');
                document.body.style.overflow = '';
            });
        });

        document.addEventListener('keydown', function(e) {
            // Ignore if in input
            if (e.target.matches('input, textarea, select')) return;
            
            // Ignore if modal is open (except Escape)
            const modalOpen = document.querySelector('.ptp-modal.open');
            if (modalOpen && e.key !== 'Escape') return;

            switch(e.key.toLowerCase()) {
                case 'n':
                    e.preventDefault();
                    openSessionModal();
                    document.getElementById('sessionDate').value = new Date().toISOString().split('T')[0];
                    document.getElementById('startTime').value = '09:00';
                    break;
                case 'r':
                    e.preventDefault();
                    calendar?.refetchEvents();
                    loadDashboardStats();
                    loadTrainerCounts();
                    toast('Refreshed', 'success');
                    break;
                case 't':
                    e.preventDefault();
                    calendar?.today();
                    break;
                case 'w':
                    e.preventDefault();
                    calendar?.changeView('timeGridWeek');
                    break;
                case 'm':
                    e.preventDefault();
                    calendar?.changeView('dayGridMonth');
                    break;
                case 'd':
                    e.preventDefault();
                    calendar?.changeView('timeGridDay');
                    break;
                case 'arrowleft':
                    e.preventDefault();
                    calendar?.prev();
                    break;
                case 'arrowright':
                    e.preventDefault();
                    calendar?.next();
                    break;
                case '/':
                    e.preventDefault();
                    document.getElementById('globalSearch')?.focus();
                    break;
                case 'escape':
                    if (modalOpen) {
                        modalOpen.classList.remove('open');
                        document.body.style.overflow = '';
                    } else {
                        clearSelection();
                        exitFocusMode();
                    }
                    break;
                case '?':
                    e.preventDefault();
                    document.getElementById('keyboardModal').classList.add('open');
                    document.body.style.overflow = 'hidden';
                    break;
            }
        });
    }

    // ===== Stats =====
    function loadDashboardStats() {
        fetch(PTPSchedule.ajax + '?' + new URLSearchParams({
            action: 'ptp_schedule_get_dashboard_stats',
            nonce: PTPSchedule.nonce
        }))
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                const d = result.data;
                animateValue('statToday', d.today);
                animateValue('statWeek', d.week);
                animateValue('statPending', d.pending);
                document.getElementById('statRevenue').textContent = '$' + parseFloat(d.revenue).toLocaleString();
                document.getElementById('statTrainers').textContent = d.trainers;
            }
        });
    }

    function animateValue(elementId, value) {
        const el = document.getElementById(elementId);
        if (!el) return;
        
        const current = parseInt(el.textContent) || 0;
        if (current === value) return;
        
        const duration = 500;
        const steps = 20;
        const increment = (value - current) / steps;
        let step = 0;
        
        const timer = setInterval(() => {
            step++;
            el.textContent = Math.round(current + (increment * step));
            if (step >= steps) {
                el.textContent = value;
                clearInterval(timer);
            }
        }, duration / steps);
    }

    function loadTrainerCounts() {
        const now = new Date();
        const start = new Date(now);
        start.setDate(now.getDate() - now.getDay());
        const end = new Date(start);
        end.setDate(start.getDate() + 6);

        fetch(PTPSchedule.ajax + '?' + new URLSearchParams({
            action: 'ptp_schedule_get_trainer_stats',
            nonce: PTPSchedule.nonce,
            start: start.toISOString().split('T')[0],
            end: end.toISOString().split('T')[0]
        }))
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                document.querySelectorAll('.ptp-trainer-row').forEach(row => {
                    const id = row.dataset.id;
                    const count = result.data[id] || 0;
                    const el = row.querySelector('.sessions-count');
                    if (el) el.textContent = count + ' session' + (count !== 1 ? 's' : '');
                });
            }
        });
    }

    // ===== Toast =====
    function toast(message, type = 'success') {
        const container = document.getElementById('toastContainer');
        
        const el = document.createElement('div');
        el.className = 'ptp-toast ' + type;
        el.innerHTML = `
            <span class="ptp-toast-icon">${type === 'success' ? '✓' : type === 'error' ? '✕' : 'ℹ'}</span>
            <span class="ptp-toast-message">${message}</span>
        `;
        container.appendChild(el);

        setTimeout(() => {
            el.classList.add('fade-out');
            setTimeout(() => el.remove(), 300);
        }, 3000);
    }

})();
