/**
 * assets/chat.js
 * 
 * Responsibilities:
 *   - Auth flow (guest / registered)
 *   - Session persistence via sessionStorage (10-min TTL, refreshed on activity)
 *   - Message send / receive / render
 *   - Unread badge on launcher
 *
 */

(function () {
  'use strict';

  // Constants 
  const API_ENDPOINT    = '/api.php';
  const SESSION_KEY     = 'telechat_session';
  const UUID_KEY        = 'telechat_uuid';
  const SESSION_TTL_MS  = 10 * 60 * 1000; // 10 minutes
  const POLL_INTERVAL   = 4000;            // ms between message polls
  const Notice_INTERVAL = 60_000;       // ms between notices

  // Application State 
  const state = {
    isOpen:    false,   // chat window visible
    isAuthed:  false,   // user has completed auth
    userId:    null,    // derived encrypted user ID
    chatId:    null,    // Telegram chat ID
    topicId:   null,    // Telegram forum topic ID (null if not using topics)
    name:      null,    // display name
    userType:  null,    // 'guest' | 'registered'
    lastMsgId: 0,       // highest message_id seen (for incremental fetch)
    unread:    0,       // messages received while window is closed
    pollTimer: null,
    noticeTimer: null,
  };

  // DOM References 
  const dom = {
    launcher:    document.getElementById('tc-launcher'),
    badge:       document.getElementById('tc-badge'),
    window:      document.getElementById('tc-window'),
    panelAuth:   document.getElementById('tc-panel-auth'),
    panelChat:   document.getElementById('tc-panel-chat'),

    // Auth
    tabGuest:    document.getElementById('tc-tab-guest'),
    tabReg:      document.getElementById('tc-tab-reg'),
    formGuest:   document.getElementById('tc-form-guest'),
    formReg:     document.getElementById('tc-form-reg'),
    regName:     document.getElementById('tc-reg-name'),
    regEmail:    document.getElementById('tc-reg-email'),
    btnGuest:    document.getElementById('tc-btn-guest'),
    btnReg:      document.getElementById('tc-btn-reg'),
    authError:   document.getElementById('tc-auth-error'),

    // Chat
    userBadge:   document.getElementById('tc-user-badge'),
    messages:    document.getElementById('tc-messages'),
    welcome:     document.getElementById('tc-welcome'),
    textarea:    document.getElementById('tc-textarea'),
    btnSend:     document.getElementById('tc-btn-send'),
    snack:       document.getElementById('tc-snack'),
    btnMinimize: document.getElementById('tc-btn-minimize'),
  };

  // Session Persistence

  /**
   * Persist the current session to sessionStorage with a fresh timestamp.
   * Called after any user activity so the TTL rolls forward.
   */
  function saveSession() {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify({
      userId:    state.userId,
      chatId:    state.chatId,
      topicId:   state.topicId,
      name:      state.name,
      userType:  state.userType,
      lastMsgId: state.lastMsgId,
      savedAt:   Date.now(),
    }));
  }

  /**
   * Try to restore a previous session from sessionStorage.
   * Returns true if a valid, non-expired session was found.
   */
  function loadSession() {
    try {
      const raw = sessionStorage.getItem(SESSION_KEY);
      if (!raw) return false;

      const data = JSON.parse(raw);
      if (!data?.savedAt || (Date.now() - data.savedAt) > SESSION_TTL_MS) {
        sessionStorage.removeItem(SESSION_KEY);
        return false;
      }

      Object.assign(state, {
        isAuthed:  true,
        userId:    data.userId,
        chatId:    data.chatId,
        topicId:   data.topicId ?? null,
        name:      data.name,
        userType:  data.userType,
        lastMsgId: data.lastMsgId ?? 0,
      });
      return true;
    } catch {
      return false;
    }
  }

  /** Remove the session from storage (used on sign-out / switch). */
  function clearSession() {
    sessionStorage.removeItem(SESSION_KEY);
    sessionStorage.removeItem(UUID_KEY);
  }

  // Guest UUID ─

  /** Generate or retrieve a persistent browser UUID for guest identification. */
  function getGuestUUID() {
    let uuid = sessionStorage.getItem(UUID_KEY);
    if (!uuid) {
      uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
      });
      sessionStorage.setItem(UUID_KEY, uuid);
    }
    return uuid;
  }

  // Launcher 

  dom.launcher.addEventListener('click', () => {
    state.isOpen ? closeWindow() : openWindow();
  });

  dom.btnMinimize.addEventListener('click', closeWindow);

  function openWindow() {
    state.isOpen = true;
    dom.launcher.classList.add('is-open');
    dom.window.classList.add('is-visible');
    clearUnreadBadge();

    if (state.isAuthed) {
      // Resume polling when window opens
      startPolling();
    }
  }

  function closeWindow() {
    state.isOpen = false;
    dom.launcher.classList.remove('is-open');
    dom.window.classList.remove('is-visible');
    stopPolling();

    if (state.isAuthed) {
      notifyClose();
    }
  }

  //  Auth Tabs 

  dom.tabGuest.addEventListener('click', () => switchAuthTab('guest'));
  dom.tabReg.addEventListener('click',   () => switchAuthTab('reg'));

  function switchAuthTab(tab) {
    const isGuest = tab === 'guest';
    dom.tabGuest.classList.toggle('is-active', isGuest);
    dom.tabReg.classList.toggle('is-active',   !isGuest);
    dom.formGuest.classList.toggle('tc-form--hidden', !isGuest);
    dom.formReg.classList.toggle('tc-form--hidden',    isGuest);
    clearAuthError();
  }

  // Auth Submit

  dom.btnGuest.addEventListener('click', () => {
    startAuth('guest');
  });

  dom.btnReg.addEventListener('click', () => {
    startAuth('registered');
  });

  // Allow Enter key to submit registered form fields
  [dom.regName, dom.regEmail].forEach(el => {
    el.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        e.preventDefault();
        startAuth('registered');
      }
    });
  });

  /**
   * Build the API payload and call init.
   */
  async function startAuth(type) {
    clearAuthError();

    const btn = type === 'guest' ? dom.btnGuest : dom.btnReg;
    btn.disabled = true;
    btn.textContent = 'Connecting…';

    const payload = { action: 'init', type };

    if (type === 'guest') {
      payload.uuid = getGuestUUID();
    } else {
      const name  = dom.regName.value.trim();
      const email = dom.regEmail.value.trim();
      if (!name)  { showAuthError('Please enter your name.');          btn.disabled = false; btn.textContent = 'Start Chatting'; return; }
      if (!email) { showAuthError('Please enter your email address.'); btn.disabled = false; btn.textContent = 'Start Chatting'; return; }
      payload.name  = name;
      payload.email = email;
    }

    try {
      const res = await apiCall(payload);
      if (!res.ok) {
        showAuthError(res.error ?? 'Connection failed. Please try again.');
        return;
      }

      // Populate state from server response
      Object.assign(state, {
        isAuthed:  true,
        userId:    res.userId,
        chatId:    res.chatId,
        topicId:   res.topicId ?? null,
        name:      res.name,
        userType:  res.userType,
        lastMsgId: 0,
      });

      saveSession();
      showChatPanel();
      loadHistory();           // fetch full history for this session
      startPolling();
      sendNotice(true);     // notify admin: new session opened
      startNotice();

    } catch {
      showAuthError('Network error. Please check your connection.');
    } finally {
      btn.disabled = false;
      btn.textContent = type === 'guest' ? 'Continue as Guest' : 'Start Chatting';
    }
  }

  // Panel Switchers 

  function showAuthPanel() {
    dom.panelAuth.classList.remove('tc-panel--hidden');
    dom.panelChat.classList.add('tc-panel--hidden');
  }

  function showChatPanel() {
    dom.panelAuth.classList.add('tc-panel--hidden');
    dom.panelChat.classList.remove('tc-panel--hidden');

    // Update user identity bar
    const icon = state.userType === 'registered' ? '👤' : '👤';
    dom.userBadge.textContent = icon + ' ' + (state.name || state.userId);

    scrollToBottom();
    dom.textarea.focus();
  }

  // Message History (on refresh)

  /**
   * Fetch all cached messages for this session from the server.
   * Called once after auth (including session restore on refresh).
   * Passes lastMsgId=0 so the server returns the full history array.
   */
  async function loadHistory() {
    try {
      const res = await apiCall({
        action:    'fetch',
        userId:    state.userId,
        chatId:    state.chatId,
        topicId:   state.topicId,
        lastMsgId: 0,
      });
      if (!res.ok) return;

      const history = res.history ?? [];
      if (history.length) {
        // Clear welcome placeholder
        const welcome = dom.messages.querySelector('.tc-welcome');
        if (welcome) welcome.remove();

        history.forEach(msg => renderMessage(msg));
        state.lastMsgId = res.lastMsgId ?? state.lastMsgId;
        saveSession();
        scrollToBottom();
      }
    } catch { /* silent */ }
  }

  // Send Message 

  dom.textarea.addEventListener('input', () => {
    dom.btnSend.disabled = !dom.textarea.value.trim();
    // Auto-resize textarea
    dom.textarea.style.height = 'auto';
    dom.textarea.style.height = Math.min(dom.textarea.scrollHeight, 120) + 'px';
  });

  dom.textarea.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      if (!dom.btnSend.disabled) sendMessage();
    }
  });

  dom.btnSend.addEventListener('click', sendMessage);

  async function sendMessage() {
    const text = dom.textarea.value.trim();
    if (!text || !state.chatId) return;

    // Clear input immediately
    dom.textarea.value = '';
    dom.textarea.style.height = 'auto';
    dom.btnSend.disabled = true;

    // Optimistic render — show bubble right away, don't wait for API
    const tempId = 'tmp_' + Date.now();
    renderMessage({
      id:      tempId,
      text,
      side:    'user',
      from:    state.name,
      time:    nowTime(),
      pending: true,
    });
    scrollToBottom();
    saveSession();

    // Re-enable the button immediately so the user can keep typing
    // The API call happens in the background
    dom.btnSend.disabled = false;

    try {
      const res = await apiCall({
        action:  'send',
        userId:  state.userId,
        chatId:  state.chatId,
        topicId: state.topicId,
        message: text,
        name:    state.name,
      });

      const el = dom.messages.querySelector(`[data-msg-id="${tempId}"]`);
      if (el) {
        if (res.ok) {
          el.dataset.msgId = res.messageId;
          el.querySelector('.tc-tick')?.classList.add('is-delivered');
          state.lastMsgId = Math.max(state.lastMsgId, res.messageId);
          saveSession();
        } else {
          // Visually dim the bubble to indicate failure
          el.querySelector('.tc-msg__bubble')?.classList.add('tc-msg--failed');
          showSnack('Message failed to send. Please try again.');
        }
      }
    } catch {
      const el = dom.messages.querySelector(`[data-msg-id="${tempId}"]`);
      el?.querySelector('.tc-msg__bubble')?.classList.add('tc-msg--failed');
      showSnack('Failed to send — please check your connection.');
    }
  }

  // Polling 

  function startPolling() {
    stopPolling();
    state.pollTimer = setInterval(pollMessages, POLL_INTERVAL);
  }

  function stopPolling() {
    if (state.pollTimer) {
      clearInterval(state.pollTimer);
      state.pollTimer = null;
    }
  }

  async function pollMessages() {
    if (!state.userId || !state.chatId) return;

    try {
      const res = await apiCall({
        action:    'fetch',
        userId:    state.userId,
        chatId:    state.chatId,
        topicId:   state.topicId,
        lastMsgId: state.lastMsgId,
      });

      if (!res.ok) return;

      const newMessages = res.messages ?? [];
      if (!newMessages.length) return;

      newMessages.forEach(msg => {
        // Skip if already rendered
        if (dom.messages.querySelector(`[data-msg-id="${msg.id}"]`)) return;

        renderMessage(msg);

        // Increment unread if window is closed
        if (!state.isOpen && msg.side === 'support') {
          state.unread++;
          updateUnreadBadge();
        }
      });

      state.lastMsgId = res.lastMsgId ?? state.lastMsgId;
      saveSession();
      scrollToBottom();

    } catch { /* silent */ }
  }

  // Render Message Bubble

  /**
   * Append a single message bubble to the messages container.
   * Handles both 'user' and 'support' sides.
   */
  function renderMessage(msg) {
    // Remove the welcome placeholder on first message
    const welcome = dom.messages.querySelector('.tc-welcome');
    if (welcome) welcome.remove();

    // Skip empty or system messages
    const text = (msg.text ?? '').trim();
    if (!text) return;

    const div = document.createElement('div');
    div.className = `tc-msg tc-msg--${msg.side}`;
    div.dataset.msgId = msg.id;

    // Support messages show the sender's name
    const senderHtml = msg.side === 'support'
      ? `<span class="tc-msg__sender">${escHtml(msg.from ?? 'Support')}</span>`
      : '';

    // Tick icon for user messages (delivery confirmation)
    const tickHtml = msg.side === 'user'
      ? `<svg class="tc-tick ${msg.pending ? '' : 'is-delivered'}" viewBox="0 0 24 24">
           <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.5"
                 stroke-linecap="round" stroke-linejoin="round" fill="none"/>
         </svg>`
      : '';

    div.innerHTML = `
      ${senderHtml}
      <div class="tc-msg__bubble">${escHtml(text)}</div>
      <span class="tc-msg__meta">
        ${escHtml(msg.time || nowTime())}
        ${tickHtml}
      </span>`;

    dom.messages.appendChild(div);
  }

  function renderWelcome() {
    dom.messages.innerHTML = `
      <div class="tc-welcome" id="tc-welcome">
        <span class="tc-welcome__icon">👋</span>
        <strong>Welcome! How can we help?</strong><br>
        Send us a message and our team will respond shortly.
      </div>`;
  }

  function scrollToBottom() {
    dom.messages.scrollTop = dom.messages.scrollHeight;
  }

  // Notice 

  function startNotice() {
    stopNotice();
    state.noticeTimer = setInterval(() => sendNotice(false), Notice_INTERVAL);
  }

  function stopNotice() {
    if (state.noticeTimer) {
      clearInterval(state.noticeTimer);
      state.noticeTimer = null;
    }
  }

  async function sendNotice(isNew = false) {
    if (!state.userId) return;
    apiCall({
      action:   'Notice',
      userId:   state.userId,
      chatId:   state.chatId,
      topicId:  state.topicId,
      name:     state.name,
      userType: state.userType,
      isNew,
    }).catch(() => {});
  }

  async function notifyClose() {
    if (!state.userId) return;
    apiCall({
      action:   'close',
      userId:   state.userId,
      name:     state.name,
      userType: state.userType,
    }).catch(() => {});
  }

  // Unread Badge 

  function updateUnreadBadge() {
    if (state.unread > 0) {
      dom.badge.textContent = state.unread > 9 ? '9+' : state.unread;
      dom.badge.classList.add('is-visible');
    }
  }

  function clearUnreadBadge() {
    state.unread = 0;
    dom.badge.classList.remove('is-visible');
  }

  // Snackbar

  let snackTimer = null;
  function showSnack(message) {
    dom.snack.textContent = message;
    dom.snack.classList.add('is-visible');
    clearTimeout(snackTimer);
    snackTimer = setTimeout(() => dom.snack.classList.remove('is-visible'), 3500);
  }

  // Auth Error 

  function showAuthError(msg) {
    dom.authError.textContent = msg;
  }

  function clearAuthError() {
    dom.authError.textContent = '';
  }

  // API Helper 

  async function apiCall(payload) {
    const response = await fetch(API_ENDPOINT, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    });
    if (!response.ok) throw new Error('HTTP ' + response.status);
    return response.json();
  }

  // Utilities 

  /** Escape HTML entities to prevent XSS in rendered messages. */
  function escHtml(str) {
    return String(str ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /** Format the current time as HH:MM. */
  function nowTime() {
    return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  }

  // Initialisation

  function init() {
    renderWelcome();

    if (loadSession()) {
      // Restore previous session — show chat panel immediately
      showChatPanel();
      loadHistory(); // re-fetch all cached messages for this session
      startNotice();
      // Don't open the window automatically; wait for user to click launcher
    } else {
      showAuthPanel();
    }

    // Notify admin when user leaves (tab close / navigate)
    window.addEventListener('beforeunload', () => {
      if (state.isAuthed) notifyClose();
    });

    // Pause / resume polling based on page visibility
    document.addEventListener('visibilitychange', () => {
      if (!state.isAuthed) return;
      if (document.hidden) {
        stopPolling();
      } else if (state.isOpen) {
        startPolling();
      }
    });
  }

  init();

})();