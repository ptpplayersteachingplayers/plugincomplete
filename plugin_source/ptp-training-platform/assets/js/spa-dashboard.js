/**
 * PTP SPA Dashboard - v71
 * Single Page Application for Trainer & Parent Dashboards
 * Complete with messaging, scheduling, and mobile optimization
 */

(function() {
    'use strict';
    
    // ===========================================
    // CONFIGURATION
    // ===========================================
    
    const config = {
        ajax: window.ptpSpa?.ajax || '/wp-admin/admin-ajax.php',
        nonce: window.ptpSpa?.nonce || '',
        userId: window.ptpSpa?.userId || 0,
        isTrainer: window.ptpSpa?.isTrainer || false,
        pollInterval: 3000,
        messageMaxLength: 1000
    };
    
    // ===========================================
    // STATE MANAGEMENT
    // ===========================================
    
    const state = {
        currentTab: 'overview',
        activeConversation: null,
        lastMessageId: 0,
        schedule: {},
        messages: [],
        isLoading: false,
        isMobile: window.innerWidth < 768
    };
    
    // ===========================================
    // DOM CACHE
    // ===========================================
    
    let dom = {};
    
    function cacheDom() {
        dom = {
            dashboard: document.querySelector('.ptp-spa-dashboard'),
            navLinks: document.querySelectorAll('.ptp-dashboard-nav a'),
            tabPanels: document.querySelectorAll('.ptp-tab-panel'),
            loader: document.querySelector('.ptp-tab-loader'),
            messagesPanel: document.querySelector('.ptp-messages-panel'),
            messagesList: document.querySelector('.ptp-messages-list'),
            messageInput: document.querySelector('.ptp-message-input'),
            sendBtn: document.querySelector('.ptp-send-btn'),
            scheduleEditor: document.querySelector('.ptp-schedule-editor'),
            mobileBackBtn: document.querySelector('.ptp-mobile-back')
        };
    }
    
    // ===========================================
    // INITIALIZATION
    // ===========================================
    
    function init() {
        cacheDom();
        
        if (!dom.dashboard) return;
        
        bindEvents();
        handleInitialTab();
        startMessagePolling();
        handleResize();
    }
    
    function bindEvents() {
        // Tab navigation
        dom.navLinks.forEach(link => {
            link.addEventListener('click', handleTabClick);
        });
        
        // Message sending
        if (dom.sendBtn) {
            dom.sendBtn.addEventListener('click', sendMessage);
        }
        
        if (dom.messageInput) {
            dom.messageInput.addEventListener('keydown', e => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            dom.messageInput.addEventListener('input', autoResizeTextarea);
        }
        
        // Mobile back button
        if (dom.mobileBackBtn) {
            dom.mobileBackBtn.addEventListener('click', handleMobileBack);
        }
        
        // Window resize
        window.addEventListener('resize', debounce(handleResize, 150));
        
        // Browser navigation
        window.addEventListener('popstate', handlePopState);
        
        // Conversation clicks
        document.addEventListener('click', e => {
            const convItem = e.target.closest('.ptp-conversation-item');
            if (convItem) {
                openConversation(convItem.dataset.conversationId);
            }
        });
        
        // Schedule editor events
        document.addEventListener('click', e => {
            if (e.target.matches('.ptp-add-block-btn')) {
                addTimeBlock(e.target.dataset.day);
            }
            if (e.target.matches('.ptp-remove-block-btn')) {
                removeTimeBlock(e.target);
            }
            if (e.target.matches('.ptp-copy-schedule-btn')) {
                showCopyScheduleModal(e.target.dataset.day);
            }
            if (e.target.matches('.ptp-save-schedule-btn')) {
                saveSchedule();
            }
        });
        
        // Profile form
        const profileForm = document.querySelector('.ptp-profile-form');
        if (profileForm) {
            profileForm.addEventListener('submit', handleProfileSubmit);
        }
    }
    
    function handleInitialTab() {
        const urlParams = new URLSearchParams(window.location.search);
        const tab = urlParams.get('tab') || 'overview';
        const conversation = urlParams.get('conversation');
        
        switchTab(tab);
        
        if (conversation && tab === 'messages') {
            setTimeout(() => openConversation(conversation), 100);
        }
    }
    
    function handleResize() {
        state.isMobile = window.innerWidth < 768;
        document.body.classList.toggle('ptp-mobile', state.isMobile);
    }
    
    // ===========================================
    // TAB NAVIGATION
    // ===========================================
    
    function handleTabClick(e) {
        e.preventDefault();
        const tab = e.currentTarget.dataset.tab;
        switchTab(tab);
        updateUrl({ tab });
    }
    
    function switchTab(tabId) {
        state.currentTab = tabId;
        
        // Update nav active states
        dom.navLinks.forEach(link => {
            link.classList.toggle('active', link.dataset.tab === tabId);
        });
        
        // Show/hide panels
        dom.tabPanels.forEach(panel => {
            panel.classList.toggle('active', panel.dataset.tab === tabId);
        });
        
        // Load tab content
        loadTabContent(tabId);
    }
    
    async function loadTabContent(tabId) {
        const panel = document.querySelector(`.ptp-tab-panel[data-tab="${tabId}"]`);
        if (!panel || panel.dataset.loaded === 'true') return;
        
        showLoader(panel);
        
        try {
            const action = config.isTrainer ? 'ptp_load_trainer_tab' : 'ptp_load_parent_tab';
            const response = await ajax(action, { tab: tabId });
            
            if (response.success && response.data.html) {
                panel.innerHTML = response.data.html;
                panel.dataset.loaded = 'true';
                initTabFeatures(tabId, panel);
            }
        } catch (error) {
            console.error('Tab load error:', error);
            panel.innerHTML = `<div class="ptp-error">Failed to load content. <button onclick="location.reload()">Retry</button></div>`;
        }
        
        hideLoader(panel);
    }
    
    function initTabFeatures(tabId, panel) {
        switch(tabId) {
            case 'schedule':
                initScheduleEditor(panel);
                break;
            case 'messages':
                initMessaging(panel);
                break;
            case 'earnings':
                initEarningsChart(panel);
                break;
        }
    }
    
    // ===========================================
    // MESSAGING SYSTEM
    // ===========================================
    
    let messagePollInterval = null;
    
    function initMessaging(panel) {
        cacheDom();
        
        // Load initial conversations
        loadConversations();
    }
    
    async function loadConversations() {
        try {
            const response = await ajax('ptp_get_conversations');
            if (response.success) {
                renderConversations(response.data.conversations);
            }
        } catch (error) {
            console.error('Load conversations error:', error);
        }
    }
    
    function renderConversations(conversations) {
        const list = document.querySelector('.ptp-conversations-list');
        if (!list) return;
        
        if (conversations.length === 0) {
            list.innerHTML = `
                <div class="ptp-empty-state">
                    <div class="ptp-empty-icon">💬</div>
                    <p>No conversations yet</p>
                </div>
            `;
            return;
        }
        
        list.innerHTML = conversations.map(conv => `
            <div class="ptp-conversation-item ${conv.unread_count > 0 ? 'has-unread' : ''}" 
                 data-conversation-id="${conv.id}">
                <div class="ptp-conversation-avatar">
                    ${conv.other_photo 
                        ? `<img src="${conv.other_photo}" alt="">`
                        : `<span class="ptp-avatar-placeholder">${conv.other_name.charAt(0)}</span>`
                    }
                </div>
                <div class="ptp-conversation-info">
                    <div class="ptp-conversation-name">${escapeHtml(conv.other_name)}</div>
                    <div class="ptp-conversation-preview">${escapeHtml(conv.last_message || '')}</div>
                </div>
                <div class="ptp-conversation-meta">
                    ${conv.last_message_at ? `<span class="ptp-conversation-time">${formatTime(conv.last_message_at)}</span>` : ''}
                    ${conv.unread_count > 0 ? `<span class="ptp-unread-count">${conv.unread_count}</span>` : ''}
                </div>
            </div>
        `).join('');
    }
    
    async function openConversation(conversationId) {
        state.activeConversation = conversationId;
        state.lastMessageId = 0;
        
        // Update UI
        document.querySelectorAll('.ptp-conversation-item').forEach(item => {
            item.classList.toggle('active', item.dataset.conversationId === conversationId);
            if (item.dataset.conversationId === conversationId) {
                item.classList.remove('has-unread');
                const badge = item.querySelector('.ptp-unread-count');
                if (badge) badge.remove();
            }
        });
        
        // Show chat panel on mobile
        const chatPanel = document.querySelector('.ptp-chat-panel');
        if (chatPanel) {
            chatPanel.classList.add('active');
            if (state.isMobile) {
                document.body.style.overflow = 'hidden';
            }
        }
        
        // Load messages
        await loadMessages(conversationId);
        
        // Update URL
        updateUrl({ tab: 'messages', conversation: conversationId });
        
        // Start polling
        startMessagePolling();
    }
    
    async function loadMessages(conversationId) {
        const messagesList = document.querySelector('.ptp-messages-list');
        if (!messagesList) return;
        
        messagesList.innerHTML = '<div class="ptp-loading"><div class="ptp-spinner"></div></div>';
        
        try {
            const response = await ajax('ptp_get_messages', { conversation_id: conversationId });
            
            if (response.success) {
                renderMessages(response.data.messages);
                
                // Update header
                const chatName = document.querySelector('.ptp-chat-name');
                if (chatName) {
                    chatName.textContent = response.data.other_name;
                }
                
                if (response.data.messages.length > 0) {
                    state.lastMessageId = response.data.messages[response.data.messages.length - 1].id;
                }
                
                scrollToBottom();
            }
        } catch (error) {
            console.error('Load messages error:', error);
            messagesList.innerHTML = '<p class="ptp-error">Failed to load messages</p>';
        }
    }
    
    function renderMessages(messages) {
        const messagesList = document.querySelector('.ptp-messages-list');
        if (!messagesList) return;
        
        if (messages.length === 0) {
            messagesList.innerHTML = '<p class="ptp-empty-messages">No messages yet. Say hello!</p>';
            return;
        }
        
        messagesList.innerHTML = messages.map(msg => `
            <div class="ptp-message ${msg.is_mine ? 'sent' : 'received'}">
                <div class="ptp-message-bubble">${escapeHtml(msg.message)}</div>
                <div class="ptp-message-time">${formatTime(msg.created_at)}</div>
            </div>
        `).join('');
    }
    
    async function sendMessage() {
        const input = document.querySelector('.ptp-message-input');
        if (!input) return;
        
        const message = input.value.trim();
        if (!message || !state.activeConversation) return;
        
        const sendBtn = document.querySelector('.ptp-send-btn');
        if (sendBtn) sendBtn.disabled = true;
        
        input.value = '';
        autoResizeTextarea.call(input);
        
        // Optimistic UI update
        appendMessage({
            message,
            is_mine: true,
            created_at: new Date().toISOString()
        });
        
        try {
            const response = await ajax('ptp_send_message', {
                conversation_id: state.activeConversation,
                message
            });
            
            if (response.success) {
                state.lastMessageId = response.data.message_id;
            }
        } catch (error) {
            console.error('Send message error:', error);
        }
        
        if (sendBtn) sendBtn.disabled = false;
        input.focus();
    }
    
    function appendMessage(msg) {
        const messagesList = document.querySelector('.ptp-messages-list');
        if (!messagesList) return;
        
        // Remove empty state if present
        const emptyState = messagesList.querySelector('.ptp-empty-messages');
        if (emptyState) emptyState.remove();
        
        const div = document.createElement('div');
        div.className = `ptp-message ${msg.is_mine ? 'sent' : 'received'}`;
        div.innerHTML = `
            <div class="ptp-message-bubble">${escapeHtml(msg.message)}</div>
            <div class="ptp-message-time">${formatTime(msg.created_at)}</div>
        `;
        messagesList.appendChild(div);
        scrollToBottom();
    }
    
    function startMessagePolling() {
        stopMessagePolling();
        messagePollInterval = setInterval(pollNewMessages, config.pollInterval);
    }
    
    function stopMessagePolling() {
        if (messagePollInterval) {
            clearInterval(messagePollInterval);
            messagePollInterval = null;
        }
    }
    
    async function pollNewMessages() {
        if (!state.activeConversation) return;
        
        try {
            const response = await ajax('ptp_get_new_messages', {
                conversation_id: state.activeConversation,
                last_id: state.lastMessageId
            });
            
            if (response.success && response.data.messages.length > 0) {
                response.data.messages.forEach(msg => {
                    appendMessage(msg);
                    state.lastMessageId = Math.max(state.lastMessageId, parseInt(msg.id));
                });
            }
        } catch (error) {
            // Silent fail for polling
        }
    }
    
    function handleMobileBack() {
        const chatPanel = document.querySelector('.ptp-chat-panel');
        if (chatPanel) {
            chatPanel.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        state.activeConversation = null;
        stopMessagePolling();
        
        updateUrl({ tab: 'messages' });
    }
    
    function scrollToBottom() {
        const messagesArea = document.querySelector('.ptp-messages-area');
        if (messagesArea) {
            messagesArea.scrollTop = messagesArea.scrollHeight;
        }
    }
    
    // ===========================================
    // SCHEDULE EDITOR
    // ===========================================
    
    function initScheduleEditor(panel) {
        loadSchedule();
    }
    
    async function loadSchedule() {
        try {
            const response = await ajax('ptp_get_availability_blocks');
            if (response.success) {
                state.schedule = response.data.schedule || {};
                renderSchedule();
            }
        } catch (error) {
            console.error('Load schedule error:', error);
        }
    }
    
    function renderSchedule() {
        const editor = document.querySelector('.ptp-schedule-grid');
        if (!editor) return;
        
        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        
        editor.innerHTML = days.map((day, index) => {
            const dayBlocks = state.schedule[index] || [];
            
            return `
                <div class="ptp-schedule-day" data-day="${index}">
                    <div class="ptp-schedule-day-header">
                        <h4>${day}</h4>
                        <div class="ptp-schedule-actions">
                            <button class="ptp-copy-schedule-btn" data-day="${index}" title="Copy to other days">📋</button>
                        </div>
                    </div>
                    <div class="ptp-time-blocks">
                        ${dayBlocks.map((block, blockIndex) => renderTimeBlock(block, index, blockIndex)).join('')}
                    </div>
                    <button class="ptp-add-block-btn" data-day="${index}">+ Add Time Block</button>
                </div>
            `;
        }).join('');
    }
    
    function renderTimeBlock(block, day, index) {
        return `
            <div class="ptp-time-block ${block.type}" data-day="${day}" data-index="${index}">
                <select class="ptp-block-type" onchange="window.ptpUpdateBlockType(${day}, ${index}, this.value)">
                    <option value="available" ${block.type === 'available' ? 'selected' : ''}>Available</option>
                    <option value="unavailable" ${block.type === 'unavailable' ? 'selected' : ''}>Break</option>
                </select>
                <input type="time" class="ptp-block-start" value="${block.start}" 
                       onchange="window.ptpUpdateBlockTime(${day}, ${index}, 'start', this.value)">
                <span>to</span>
                <input type="time" class="ptp-block-end" value="${block.end}"
                       onchange="window.ptpUpdateBlockTime(${day}, ${index}, 'end', this.value)">
                <button class="ptp-remove-block-btn" data-day="${day}" data-index="${index}">×</button>
            </div>
        `;
    }
    
    function addTimeBlock(day) {
        if (!state.schedule[day]) {
            state.schedule[day] = [];
        }
        
        // Default to 9am-5pm
        state.schedule[day].push({
            type: 'available',
            start: '09:00',
            end: '17:00'
        });
        
        renderSchedule();
    }
    
    function removeTimeBlock(btn) {
        const day = parseInt(btn.dataset.day);
        const index = parseInt(btn.dataset.index);
        
        if (state.schedule[day]) {
            state.schedule[day].splice(index, 1);
            renderSchedule();
        }
    }
    
    // Global functions for inline handlers
    window.ptpUpdateBlockType = function(day, index, type) {
        if (state.schedule[day] && state.schedule[day][index]) {
            state.schedule[day][index].type = type;
        }
    };
    
    window.ptpUpdateBlockTime = function(day, index, field, value) {
        if (state.schedule[day] && state.schedule[day][index]) {
            state.schedule[day][index][field === 'start' ? 'start' : 'end'] = value;
        }
    };
    
    async function saveSchedule() {
        const btn = document.querySelector('.ptp-save-schedule-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving...';
        }
        
        try {
            const response = await ajax('ptp_save_availability_blocks', {
                schedule: JSON.stringify(state.schedule)
            });
            
            if (response.success) {
                showToast('Schedule saved successfully!', 'success');
            } else {
                showToast('Failed to save schedule', 'error');
            }
        } catch (error) {
            console.error('Save schedule error:', error);
            showToast('Failed to save schedule', 'error');
        }
        
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save Schedule';
        }
    }
    
    function showCopyScheduleModal(fromDay) {
        const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        const fromDayName = days[fromDay];
        
        const modal = document.createElement('div');
        modal.className = 'ptp-modal-overlay active';
        modal.innerHTML = `
            <div class="ptp-modal">
                <div class="ptp-modal-header">
                    <h3 class="ptp-modal-title">Copy ${fromDayName}'s Schedule</h3>
                    <button class="ptp-modal-close">&times;</button>
                </div>
                <div class="ptp-modal-body">
                    <p>Select days to copy to:</p>
                    <div class="ptp-copy-days">
                        ${days.map((day, index) => index != fromDay ? `
                            <label class="ptp-checkbox">
                                <input type="checkbox" value="${index}">
                                ${day}
                            </label>
                        ` : '').join('')}
                    </div>
                </div>
                <div class="ptp-modal-footer">
                    <button class="ptp-btn ptp-btn-outline ptp-modal-cancel">Cancel</button>
                    <button class="ptp-btn ptp-btn-primary ptp-confirm-copy">Copy</button>
                </div>
            </div>
        `;
        
        document.body.appendChild(modal);
        
        modal.querySelector('.ptp-modal-close').onclick = () => modal.remove();
        modal.querySelector('.ptp-modal-cancel').onclick = () => modal.remove();
        modal.querySelector('.ptp-confirm-copy').onclick = () => {
            const selected = Array.from(modal.querySelectorAll('input:checked')).map(cb => parseInt(cb.value));
            copyScheduleToDays(fromDay, selected);
            modal.remove();
        };
    }
    
    function copyScheduleToDays(fromDay, toDays) {
        const sourceBlocks = state.schedule[fromDay] || [];
        
        toDays.forEach(day => {
            state.schedule[day] = JSON.parse(JSON.stringify(sourceBlocks));
        });
        
        renderSchedule();
        showToast(`Schedule copied to ${toDays.length} day(s)`, 'success');
    }
    
    // ===========================================
    // EARNINGS CHART
    // ===========================================
    
    function initEarningsChart(panel) {
        const chartCanvas = panel.querySelector('#earningsChart');
        if (!chartCanvas || !window.Chart) return;
        
        // Simple earnings visualization
        // In production, this would load actual data
    }
    
    // ===========================================
    // PROFILE FORM
    // ===========================================
    
    async function handleProfileSubmit(e) {
        e.preventDefault();
        
        const form = e.target;
        const formData = new FormData(form);
        const btn = form.querySelector('button[type="submit"]');
        
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving...';
        }
        
        try {
            const data = Object.fromEntries(formData.entries());
            const response = await ajax('ptp_save_trainer_profile', data);
            
            if (response.success) {
                showToast('Profile updated successfully!', 'success');
            } else {
                showToast(response.data?.message || 'Failed to update profile', 'error');
            }
        } catch (error) {
            console.error('Profile save error:', error);
            showToast('Failed to update profile', 'error');
        }
        
        if (btn) {
            btn.disabled = false;
            btn.textContent = 'Save Profile';
        }
    }
    
    // ===========================================
    // UTILITIES
    // ===========================================
    
    async function ajax(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', config.nonce);
        
        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });
        
        const response = await fetch(config.ajax, {
            method: 'POST',
            body: formData
        });
        
        return response.json();
    }
    
    function updateUrl(params) {
        const url = new URL(window.location);
        
        Object.entries(params).forEach(([key, value]) => {
            if (value) {
                url.searchParams.set(key, value);
            } else {
                url.searchParams.delete(key);
            }
        });
        
        history.pushState({}, '', url);
    }
    
    function handlePopState() {
        handleInitialTab();
    }
    
    function showLoader(container) {
        const loader = document.createElement('div');
        loader.className = 'ptp-tab-loader';
        loader.innerHTML = '<div class="ptp-spinner"></div>';
        container.appendChild(loader);
    }
    
    function hideLoader(container) {
        const loader = container.querySelector('.ptp-tab-loader');
        if (loader) loader.remove();
    }
    
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.className = `ptp-toast ptp-toast-${type}`;
        toast.textContent = message;
        
        document.body.appendChild(toast);
        
        setTimeout(() => toast.classList.add('show'), 10);
        
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    function formatTime(dateStr) {
        const date = new Date(dateStr);
        const now = new Date();
        const diff = now - date;
        
        if (diff < 60000) return 'Just now';
        if (diff < 3600000) return Math.floor(diff / 60000) + 'm';
        if (diff < 86400000) return Math.floor(diff / 3600000) + 'h';
        if (diff < 604800000) return Math.floor(diff / 86400000) + 'd';
        
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    }
    
    function autoResizeTextarea() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    }
    
    function debounce(func, wait) {
        let timeout;
        return function(...args) {
            clearTimeout(timeout);
            timeout = setTimeout(() => func.apply(this, args), wait);
        };
    }
    
    // ===========================================
    // TOAST STYLES (injected)
    // ===========================================
    
    const toastStyles = document.createElement('style');
    toastStyles.textContent = `
        .ptp-toast {
            position: fixed;
            bottom: 100px;
            left: 50%;
            transform: translateX(-50%) translateY(20px);
            padding: 12px 24px;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            font-weight: 500;
            background: #0A0A0A;
            color: #fff;
            border: 2px solid #0A0A0A;
            opacity: 0;
            transition: all 0.3s ease;
            z-index: 10000;
        }
        
        .ptp-toast.show {
            opacity: 1;
            transform: translateX(-50%) translateY(0);
        }
        
        .ptp-toast-success {
            background: #4CAF50;
            border-color: #4CAF50;
        }
        
        .ptp-toast-error {
            background: #F44336;
            border-color: #F44336;
        }
        
        .ptp-toast-warning {
            background: #FF9800;
            border-color: #FF9800;
            color: #0A0A0A;
        }
        
        @media (max-width: 767px) {
            .ptp-toast {
                bottom: 120px;
                left: 16px;
                right: 16px;
                transform: translateY(20px);
            }
            
            .ptp-toast.show {
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(toastStyles);
    
    // ===========================================
    // START
    // ===========================================
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
})();
