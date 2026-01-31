/**
 * PTP Training Platform - Frontend JavaScript v42.3.3
 * Premium interactions and functionality
 * 
 * Changelog v42.3.3:
 * - Mobile touch optimization
 * - Improved touch target handling
 * - Better scroll behavior on mobile
 * - Fixed iOS input zoom prevention
 */

(function() {
    'use strict';

    console.log('PTP Training Platform v42.3.3 loaded');
    
    // Global namespace
    window.PTP = window.PTP || {};

    // ============================================
    // UTILITIES
    // ============================================
    PTP.utils = {
        ajax: async function(action, data = {}) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('nonce', ptp_ajax.nonce);
            for (const key in data) {
                if (data[key] instanceof File) {
                    formData.append(key, data[key]);
                } else if (Array.isArray(data[key])) {
                    data[key].forEach((item, index) => {
                        formData.append(key + '[]', item);
                    });
                } else {
                    formData.append(key, data[key]);
                }
            }
            const response = await fetch(ptp_ajax.ajax_url, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            return response.json();
        },

        formatCurrency: function(amount) {
            return '$' + parseFloat(amount).toFixed(0);
        },

        formatDate: function(dateStr) {
            const date = new Date(dateStr + 'T12:00:00');
            return date.toLocaleDateString('en-US', { 
                weekday: 'long', 
                month: 'long', 
                day: 'numeric' 
            });
        },

        debounce: function(func, wait) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }
    };

    // ============================================
    // MODALS
    // ============================================
    PTP.modal = {
        open: function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            }
        },

        close: function(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        },

        init: function() {
            // Close on overlay click
            document.querySelectorAll('.ptp-modal-overlay').forEach(overlay => {
                overlay.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.classList.remove('active');
                        document.body.style.overflow = '';
                    }
                });
            });

            // Close buttons
            document.querySelectorAll('.ptp-modal-close').forEach(btn => {
                btn.addEventListener('click', function() {
                    this.closest('.ptp-modal-overlay').classList.remove('active');
                    document.body.style.overflow = '';
                });
            });

            // ESC key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.querySelectorAll('.ptp-modal-overlay.active').forEach(modal => {
                        modal.classList.remove('active');
                    });
                    document.body.style.overflow = '';
                }
            });
        }
    };

    // ============================================
    // ALERTS / NOTIFICATIONS
    // ============================================
    PTP.alert = {
        show: function(message, type = 'info', duration = 5000) {
            const container = document.getElementById('ptp-alerts') || this.createContainer();
            
            const alert = document.createElement('div');
            alert.className = `ptp-alert ptp-alert-${type}`;
            alert.setAttribute('role', 'alert');
            alert.setAttribute('aria-live', 'polite');
            
            const msgSpan = document.createElement('span');
            msgSpan.textContent = message; // Safe - uses textContent, not innerHTML
            alert.appendChild(msgSpan);
            
            const closeBtn = document.createElement('button');
            closeBtn.setAttribute('type', 'button');
            closeBtn.setAttribute('aria-label', 'Dismiss');
            closeBtn.style.cssText = 'background: none; border: none; cursor: pointer; padding: 0 0 0 12px; opacity: 0.7; font-size: 18px;';
            closeBtn.textContent = '×';
            closeBtn.addEventListener('click', () => alert.remove());
            alert.appendChild(closeBtn);
            
            container.appendChild(alert);
            
            // Announce to screen readers
            alert.focus();
            
            if (duration > 0) {
                setTimeout(() => {
                    if (alert.parentElement) {
                        alert.style.opacity = '0';
                        alert.style.transform = 'translateX(100%)';
                        setTimeout(() => alert.remove(), 300);
                    }
                }, duration);
            }
            
            return alert;
        },

        createContainer: function() {
            const container = document.createElement('div');
            container.id = 'ptp-alerts';
            container.setAttribute('aria-label', 'Notifications');
            container.style.cssText = 'position: fixed; top: 20px; right: 20px; z-index: 10000; display: flex; flex-direction: column; gap: 10px; max-width: 400px;';
            document.body.appendChild(container);
            return container;
        },

        success: function(message) { return this.show(message, 'success'); },
        error: function(message) { return this.show(message, 'error'); },
        warning: function(message) { return this.show(message, 'warning'); },
        info: function(message) { return this.show(message, 'info'); }
    };

    // ============================================
    // FORMS
    // ============================================
    PTP.forms = {
        init: function() {
            // Floating labels
            document.querySelectorAll('.ptp-floating-group .ptp-input').forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.classList.add('focused');
                });
                input.addEventListener('blur', function() {
                    this.parentElement.classList.remove('focused');
                });
            });

            // Form validation styling
            document.querySelectorAll('.ptp-input, .ptp-select, .ptp-textarea').forEach(field => {
                field.addEventListener('invalid', function() {
                    this.style.borderColor = 'var(--ptp-error)';
                });
                field.addEventListener('input', function() {
                    this.style.borderColor = '';
                });
            });
        },

        serialize: function(form) {
            const data = {};
            new FormData(form).forEach((value, key) => {
                data[key] = value;
            });
            return data;
        }
    };

    // ============================================
    // TABS
    // ============================================
    PTP.tabs = {
        init: function() {
            document.querySelectorAll('.ptp-settings-tabs').forEach(tabGroup => {
                const tabs = tabGroup.querySelectorAll('.ptp-settings-tab');
                tabs.forEach(tab => {
                    tab.addEventListener('click', function(e) {
                        e.preventDefault();
                        const target = this.dataset.tab;
                        
                        // Update tabs
                        tabs.forEach(t => t.classList.remove('active'));
                        this.classList.add('active');
                        
                        // Update panels
                        const container = tabGroup.parentElement;
                        container.querySelectorAll('.ptp-settings-panel').forEach(panel => {
                            panel.classList.remove('active');
                        });
                        const targetPanel = container.querySelector(`[data-panel="${target}"]`);
                        if (targetPanel) targetPanel.classList.add('active');
                    });
                });
            });
        }
    };

    // ============================================
    // TRAINER SEARCH & FILTER
    // ============================================
    PTP.trainers = {
        filters: {
            specialty: '',
            sort: 'featured',
            location: '',
            search: ''
        },
        isLoading: false,

        init: function() {
            const grid = document.getElementById('trainers-grid');
            if (!grid) return;

            // Filter handlers
            const specialtyFilter = document.getElementById('filter-specialty');
            const sortFilter = document.getElementById('filter-sort');
            const searchInput = document.getElementById('trainer-search-input');

            if (specialtyFilter) {
                specialtyFilter.addEventListener('change', (e) => {
                    this.filters.specialty = e.target.value;
                    this.applyFilters();
                });
            }

            if (sortFilter) {
                sortFilter.addEventListener('change', (e) => {
                    this.filters.sort = e.target.value;
                    this.applyFilters();
                });
            }

            if (searchInput) {
                searchInput.addEventListener('input', PTP.utils.debounce((e) => {
                    this.filters.search = e.target.value;
                    this.applyFilters();
                }, 300));
            }

            // Search form
            const searchForm = document.getElementById('ptp-trainer-search');
            if (searchForm) {
                searchForm.addEventListener('submit', (e) => {
                    e.preventDefault();
                    const locationInput = searchForm.querySelector('input[name="location"]');
                    if (locationInput) {
                        this.filters.location = locationInput.value;
                        this.applyFilters();
                    }
                });
            }
        },

        applyFilters: async function() {
            const grid = document.getElementById('trainers-grid');
            if (!grid || this.isLoading) return;

            this.isLoading = true;
            grid.style.opacity = '0.5';
            grid.setAttribute('aria-busy', 'true');

            try {
                const response = await PTP.utils.ajax('ptp_get_trainers', {
                    search: this.filters.search,
                    specialty: this.filters.specialty,
                    sort: this.filters.sort,
                    location: this.filters.location
                });

                if (response.success && response.data.trainers) {
                    this.renderTrainers(response.data.trainers);
                }
            } catch (error) {
                console.error('Filter error:', error);
                PTP.alert.error('Could not load trainers. Please try again.');
            } finally {
                grid.style.opacity = '1';
                grid.setAttribute('aria-busy', 'false');
                this.isLoading = false;
            }
        },

        renderTrainers: function(trainers) {
            const grid = document.getElementById('trainers-grid');
            if (!grid) return;

            if (trainers.length === 0) {
                grid.innerHTML = '<div class="ptp-empty-state"><p>No trainers found matching your criteria.</p></div>';
                return;
            }

            // Re-render would need server-side template or client-side template
            // For now, we'll reload the page with query params
            const params = new URLSearchParams();
            if (this.filters.specialty) params.set('specialty', this.filters.specialty);
            if (this.filters.sort) params.set('sort', this.filters.sort);
            if (this.filters.search) params.set('search', this.filters.search);
            if (this.filters.location) params.set('location', this.filters.location);
            
            const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
            window.history.replaceState({}, '', newUrl);
        }
    };

    // ============================================
    // CALENDAR
    // ============================================
    PTP.calendar = {
        currentDate: new Date(),
        selectedDate: null,
        onDateSelect: null,

        init: function(containerId, options = {}) {
            this.container = document.getElementById(containerId);
            if (!this.container) return;

            this.onDateSelect = options.onDateSelect || function() {};
            this.render();
        },

        render: function() {
            const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 
                              'July', 'August', 'September', 'October', 'November', 'December'];
            
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();
            
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            let html = `
                <div class="ptp-calendar-header">
                    <h3 class="ptp-calendar-title">${monthNames[month]} ${year}</h3>
                    <div class="ptp-calendar-nav">
                        <button class="cal-prev">‹</button>
                        <button class="cal-next">›</button>
                    </div>
                </div>
                <div class="ptp-calendar-grid">
                    <div class="ptp-calendar-day-name">Sun</div>
                    <div class="ptp-calendar-day-name">Mon</div>
                    <div class="ptp-calendar-day-name">Tue</div>
                    <div class="ptp-calendar-day-name">Wed</div>
                    <div class="ptp-calendar-day-name">Thu</div>
                    <div class="ptp-calendar-day-name">Fri</div>
                    <div class="ptp-calendar-day-name">Sat</div>
            `;

            // Empty cells before first day
            for (let i = 0; i < firstDay.getDay(); i++) {
                html += '<div class="ptp-calendar-day disabled"></div>';
            }

            // Days of month
            for (let d = 1; d <= lastDay.getDate(); d++) {
                const date = new Date(year, month, d);
                const dateStr = date.toISOString().split('T')[0];
                const isPast = date < today;
                const isSelected = this.selectedDate === dateStr;
                
                html += `<div class="ptp-calendar-day ${isPast ? 'disabled' : 'available'} ${isSelected ? 'selected' : ''}" data-date="${dateStr}">${d}</div>`;
            }

            html += '</div>';
            this.container.innerHTML = html;

            // Attach events
            this.container.querySelector('.cal-prev').addEventListener('click', () => {
                this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                this.render();
            });

            this.container.querySelector('.cal-next').addEventListener('click', () => {
                this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                this.render();
            });

            this.container.querySelectorAll('.ptp-calendar-day.available').forEach(day => {
                day.addEventListener('click', () => {
                    this.container.querySelectorAll('.ptp-calendar-day').forEach(d => d.classList.remove('selected'));
                    day.classList.add('selected');
                    this.selectedDate = day.dataset.date;
                    this.onDateSelect(this.selectedDate);
                });
            });
        }
    };

    // ============================================
    // MESSAGING
    // ============================================
    PTP.messaging = {
        activeConversation: null,
        pollInterval: null,

        init: function() {
            const container = document.querySelector('.ptp-messaging');
            if (!container) return;

            // Conversation clicks
            document.querySelectorAll('.ptp-conversation-item').forEach(item => {
                item.addEventListener('click', () => {
                    this.loadConversation(item.dataset.conversationId);
                });
            });

            // Send message
            const sendBtn = document.querySelector('.ptp-chat-send');
            const input = document.querySelector('.ptp-chat-input input');
            
            if (sendBtn && input) {
                sendBtn.addEventListener('click', () => this.sendMessage(input.value));
                input.addEventListener('keypress', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage(input.value);
                    }
                });
            }

            // Start polling for new messages
            this.startPolling();
        },

        loadConversation: async function(conversationId) {
            this.activeConversation = conversationId;
            
            // Update active state
            document.querySelectorAll('.ptp-conversation-item').forEach(item => {
                item.classList.toggle('active', item.dataset.conversationId === conversationId);
            });

            // Load messages via AJAX
            const response = await PTP.utils.ajax('ptp_get_messages', { conversation_id: conversationId });
            if (response.success) {
                this.renderMessages(response.data.messages);
            }
        },

        renderMessages: function(messages) {
            const container = document.querySelector('.ptp-chat-messages');
            if (!container) return;

            // Clear existing messages
            container.innerHTML = '';
            
            // Build messages safely to prevent XSS
            messages.forEach(msg => {
                const msgDiv = document.createElement('div');
                msgDiv.className = `ptp-message ${msg.is_mine ? 'sent' : 'received'}`;
                
                const contentSpan = document.createElement('span');
                contentSpan.textContent = msg.content; // Safe - no HTML injection
                msgDiv.appendChild(contentSpan);
                
                const timeDiv = document.createElement('div');
                timeDiv.className = 'ptp-message-time';
                timeDiv.textContent = msg.time;
                msgDiv.appendChild(timeDiv);
                
                container.appendChild(msgDiv);
            });

            container.scrollTop = container.scrollHeight;
        },

        sendMessage: async function(content) {
            if (!content.trim() || !this.activeConversation) return;

            const input = document.querySelector('.ptp-chat-input input');
            input.value = '';
            input.focus();

            const response = await PTP.utils.ajax('ptp_send_message', {
                conversation_id: this.activeConversation,
                content: content
            });

            if (response.success) {
                this.loadConversation(this.activeConversation);
            }
        },

        startPolling: function() {
            // Clear any existing interval
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
            }
            
            this.pollInterval = setInterval(() => {
                if (this.activeConversation && document.visibilityState === 'visible') {
                    this.checkNewMessages();
                }
            }, 10000);
            
            // Clean up on page unload
            window.addEventListener('beforeunload', () => {
                if (this.pollInterval) {
                    clearInterval(this.pollInterval);
                }
            });
            
            // Pause polling when page is hidden
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden' && this.pollInterval) {
                    clearInterval(this.pollInterval);
                    this.pollInterval = null;
                } else if (document.visibilityState === 'visible' && !this.pollInterval) {
                    this.startPolling();
                }
            });
        },
        
        checkNewMessages: async function() {
            if (!this.activeConversation) return;
            
            try {
                const response = await PTP.utils.ajax('ptp_get_new_messages', {
                    conversation_id: this.activeConversation
                });
                
                if (response.success && response.data.messages && response.data.messages.length > 0) {
                    this.renderMessages(response.data.messages);
                }
            } catch (error) {
                console.error('Message polling error:', error);
            }
        }
    };

    // ============================================
    // TOGGLE SWITCHES
    // ============================================
    PTP.toggles = {
        init: function() {
            document.querySelectorAll('.ptp-toggle').forEach(toggle => {
                toggle.addEventListener('click', function() {
                    this.classList.toggle('active');
                    const input = this.querySelector('input[type="hidden"]');
                    if (input) {
                        input.value = this.classList.contains('active') ? '1' : '0';
                    }
                    // Trigger change event
                    this.dispatchEvent(new CustomEvent('toggle', { 
                        detail: { active: this.classList.contains('active') }
                    }));
                });
            });
        }
    };

    // ============================================
    // AUTH FORMS
    // ============================================
    PTP.auth = {
        init: function() {
            // Login form
            const loginForm = document.getElementById('ptp-login-form');
            if (loginForm) {
                loginForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const btn = this.querySelector('button[type="submit"]');
                    const errorEl = this.querySelector('.ptp-form-error');
                    
                    // Clear previous errors
                    if (errorEl) errorEl.style.display = 'none';
                    
                    PTP.auth.setLoading(btn, true);
                    
                    const formData = new FormData(this);
                    formData.append('action', 'ptp_login');
                    
                    try {
                        const response = await fetch(ptp_ajax.ajax_url, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            // Show success message briefly before redirect
                            PTP.alert.success('Login successful! Redirecting...');
                            setTimeout(() => {
                                window.location.href = data.data.redirect || ptp_ajax.home_url + '/my-training/';
                            }, 500);
                        } else {
                            const errorMessage = data.data?.message || data.data || 'Login failed. Please check your credentials.';
                            if (errorEl) {
                                errorEl.textContent = errorMessage;
                                errorEl.style.display = 'block';
                            } else {
                                PTP.alert.error(errorMessage);
                            }
                            PTP.auth.setLoading(btn, false);
                        }
                    } catch (e) {
                        console.error('Login error:', e);
                        PTP.alert.error('Connection error. Please check your internet and try again.');
                        PTP.auth.setLoading(btn, false);
                    }
                });
            }

            // Register form
            const registerForm = document.getElementById('ptp-register-form');
            if (registerForm) {
                registerForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    const btn = this.querySelector('button[type="submit"]');
                    const errorEl = this.querySelector('.ptp-form-error');
                    
                    // Clear previous errors
                    if (errorEl) errorEl.style.display = 'none';
                    
                    // Client-side validation
                    const password = this.querySelector('input[name="password"]');
                    if (password && password.value.length < 8) {
                        const msg = 'Password must be at least 8 characters';
                        if (errorEl) {
                            errorEl.textContent = msg;
                            errorEl.style.display = 'block';
                        } else {
                            PTP.alert.error(msg);
                        }
                        password.focus();
                        return;
                    }
                    
                    PTP.auth.setLoading(btn, true);
                    
                    const formData = new FormData(this);
                    formData.append('action', 'ptp_register');
                    
                    try {
                        const response = await fetch(ptp_ajax.ajax_url, {
                            method: 'POST',
                            body: formData,
                            credentials: 'same-origin'
                        });
                        
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            PTP.alert.success('Account created! Redirecting...');
                            setTimeout(() => {
                                window.location.href = data.data?.redirect || ptp_ajax.home_url + '/login/?registered=1';
                            }, 500);
                        } else {
                            const errorMessage = data.data?.message || data.data || 'Registration failed. Please try again.';
                            if (errorEl) {
                                errorEl.textContent = errorMessage;
                                errorEl.style.display = 'block';
                            } else {
                                PTP.alert.error(errorMessage);
                            }
                            PTP.auth.setLoading(btn, false);
                        }
                    } catch (e) {
                        console.error('Registration error:', e);
                        PTP.alert.error('Connection error. Please check your internet and try again.');
                        PTP.auth.setLoading(btn, false);
                    }
                });
            }
        },

        setLoading: function(btn, loading) {
            if (!btn) return;
            const text = btn.querySelector('.btn-text');
            const loader = btn.querySelector('.btn-loading');
            if (text) text.style.display = loading ? 'none' : '';
            if (loader) loader.style.display = loading ? 'inline-flex' : 'none';
            btn.disabled = loading;
            btn.setAttribute('aria-busy', loading ? 'true' : 'false');
        }
    };

    // ============================================
    // SMOOTH SCROLL
    // ============================================
    PTP.smoothScroll = {
        init: function() {
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        e.preventDefault();
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });
            });
        }
    };

    // ============================================
    // LAZY LOADING IMAGES
    // ============================================
    PTP.lazyLoad = {
        init: function() {
            if ('IntersectionObserver' in window) {
                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            const img = entry.target;
                            if (img.dataset.src) {
                                img.src = img.dataset.src;
                                img.removeAttribute('data-src');
                            }
                            observer.unobserve(img);
                        }
                    });
                });

                document.querySelectorAll('img[data-src]').forEach(img => {
                    observer.observe(img);
                });
            }
        }
    };

    // ============================================
    // INITIALIZE
    // ============================================
    document.addEventListener('DOMContentLoaded', function() {
        PTP.modal.init();
        PTP.forms.init();
        PTP.tabs.init();
        PTP.trainers.init();
        PTP.toggles.init();
        PTP.auth.init();
        PTP.smoothScroll.init();
        PTP.lazyLoad.init();
        PTP.messaging.init();

        console.log('PTP Training Platform v42.3.3 loaded');
    });

})();
