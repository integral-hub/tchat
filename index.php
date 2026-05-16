<?php

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
   // 'domain' => 'tchat.test', // set your domain in production
    'secure' => true,      // HTTPS only (critical)
    'httponly' => true,    // JS cannot read session cookie
    'samesite' => 'Strict' // blocks CSRF from external sites
]);

session_start();

// Create persistent user identity
if (!isset($_SESSION['user'])) {
    $_SESSION['user'] = bin2hex(random_bytes(32));
}

// CSRF token for state-changing requests
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TeleChater — Live Support</title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;500;600;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">

  <!-- Widget styles -->
  <link rel="stylesheet" href="assets/chat.css">
</head>
<body>

<!-- 
     DEMO PAGE  —  Replace this section with your actual website content.
     The chat widget below is independent and can be dropped into any page. 
-->
<div class="tc-demo-page">
  <h1>Your Website</h1>
  <p>The support chat widget is in the bottom-right corner ↘</p>
</div>
<!-- END DEMO PAGE -->


<!-- TELECHATER WIDGET
     Everything below is the self-contained chat widget.
-->

<!-- Launcher button (always visible) -->
<button id="tc-launcher" aria-label="Open live chat" title="Chat with us">
  <!-- Chat icon (shown when closed) -->
  <svg class="tc-ico-chat" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path d="M12 2C6.477 2 2 6.21 2 11.43c0 2.843 1.336 5.394 3.454 7.11L4.5 22l4.237-2.117A11.5 11.5 0 0 0 12 20.857C17.523 20.857 22 16.647 22 11.43S17.523 2 12 2Z"/>
  </svg>
  <!-- Close icon (shown when open) -->
  <svg class="tc-ico-close" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
    <path d="M18 6L6 18M6 6l12 12" stroke="#fff" stroke-width="2.5" stroke-linecap="round" fill="none"/>
  </svg>
  <!-- Unread badge -->
  <span id="tc-badge" aria-label="Unread messages"></span>
</button>


<!-- Chat window -->
<div id="tc-window" role="dialog" aria-modal="true" aria-label="Live support chat">

  <!--  Window Header  -->
  <header class="tc-header">
    <div class="tc-header__avatar" aria-hidden="true">💬</div>
    <div class="tc-header__info">
      <strong class="tc-header__title">Live Support</strong>
      <span class="tc-header__status">Online — replies within minutes</span>
    </div>
    <div class="tc-header__actions">
      <button id="tc-btn-minimize" class="tc-header__btn" title="Minimise chat" aria-label="Minimise chat">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M5 12h14" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/>
        </svg>
      </button>
    </div>
  </header>


  <!--  Auth Panel ─ -->
  <!--
    Shown before the user authenticates.
    Hidden immediately after successful sign-in.
    The chat panel is NOT accessible until auth is complete.
  -->
  <section id="tc-panel-auth" class="tc-panel tc-auth" aria-label="Sign in to chat">

    <h2 class="tc-auth__title">Start a conversation</h2>
    <p class="tc-auth__subtitle">Continue as a guest or sign in for a personalised experience.</p>

    <!-- Tab switcher -->
    <div class="tc-tabs" role="tablist">
      <button id="tc-tab-guest" class="tc-tab is-active" role="tab" aria-selected="true"  aria-controls="tc-form-guest">Guest</button>
      <button id="tc-tab-reg"   class="tc-tab"           role="tab" aria-selected="false" aria-controls="tc-form-reg">Sign In</button>
    </div>

    <!-- Guest form -->
    <div id="tc-form-guest" class="tc-form" role="tabpanel">
      <p style="font-size:.8rem;color:var(--tc-muted);text-align:center;line-height:1.5;">
        You'll chat as <strong style="color:var(--tc-text)">Anonymous</strong>.<br>
        No account needed.
      </p>
      <button id="tc-btn-guest" class="tc-btn-primary">Continue as Guest</button>
    </div>

    <!-- Registered form -->
    <div id="tc-form-reg" class="tc-form tc-form--hidden" role="tabpanel">
      <div class="tc-field">
        <label class="tc-field__label" for="tc-reg-name">Full Name</label>
        <input
          id="tc-reg-name"
          class="tc-field__input"
          type="text"
          placeholder="Your name"
          autocomplete="name"
        >
      </div>
      <div class="tc-field">
        <label class="tc-field__label" for="tc-reg-email">Email Address</label>
        <input
          id="tc-reg-email"
          class="tc-field__input"
          type="email"
          placeholder="you@example.com"
          autocomplete="email"
        >
      </div>
      <button id="tc-btn-reg" class="tc-btn-primary">Start Chatting</button>
    </div>

    <!-- Inline error message (shown on validation/network failure) -->
    <p id="tc-auth-error" class="tc-form-error" role="alert" aria-live="polite"></p>

  </section>
  <!-- END Auth Panel -->


  <!--  Chat Panel ─ -->
  <!--
    Hidden until the user completes auth.
    Restored from session on page refresh.
  -->
  <section id="tc-panel-chat" class="tc-panel tc-panel--hidden" aria-label="Chat messages">

    <!-- Identity bar -->
    <div class="tc-user-bar">
      <span>Chatting as</span>
      <span id="tc-user-badge" class="tc-user-bar__badge">—</span>
    </div>

    <!-- Messages scroll area -->
    <div id="tc-messages" class="tc-messages" role="log" aria-live="polite" aria-label="Chat messages">
      <!-- Populated dynamically by chat.js -->
    </div>

    <!-- Message input bar -->
    <div class="tc-input-bar">
      <textarea
        id="tc-textarea"
        class="tc-input-bar__textarea"
        placeholder="Type a message…"
        rows="1"
        aria-label="Message input"
      ></textarea>
      <button
        id="tc-btn-send"
        class="tc-input-bar__send"
        disabled
        aria-label="Send message"
      >
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M22 2L11 13M22 2L15 22l-4-9-9-4 20-7Z"
                stroke="#fff" stroke-width="2"
                stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
      </button>
    </div>

  </section>
  <!-- END Chat Panel -->


  <!-- Toast notification (errors / info) -->
  <div id="tc-snack" class="tc-snack" role="status" aria-live="assertive"></div>

</div>
<!-- END Chat Window -->


<!-- Widget logic -->
<script src="assets/chat.js"></script>

</body>
</html>