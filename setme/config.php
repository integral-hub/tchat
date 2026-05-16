<?php
/**
 * TeleChater Configuration
 * Replace these values with your actual Telegram bot credentials
 */

define('BOT_TOKEN',          'YOUR_BOT_TOKEN_HERE');
define('ADMIN_CHAT_ID',      'YOUR_PERSONAL_CHAT_ID'); 
define('BOT_USERNAME',       'YourBotUsername'); // MyBot
define('FORUM_GROUP_CHAT_ID','YOUR_FORUM_GROUP_ID');   // e.g. -1001234567890

/**
 * HMAC key used to derive deterministic user IDs.
 * Change this to a long random string (32+ characters).
 * WARNING: Changing this invalidates all existing user sessions.
 */
define('ENCRYPT_KEY', 'change-this-to-a-very-long-random-string-32+chars!!');
 
//  Session ─
 
/** How long (seconds) a sessionStorage session stays valid without activity */
define('SESSION_TTL_SECONDS', 600); // 10 minutes
 
//  Telegram API base URL 
define('TG_API_BASE', 'https://api.telegram.org/bot' . BOT_TOKEN);
 
// ─
// HELPER FUNCTIONS
// ─
 
/**
 * Generate a stable encrypted user ID for a guest.
 * Derived from a browser-generated UUID so it survives page refreshes.
 */
function generateGuestId(string $uuid): string {
    $hash = hash_hmac('sha256', 'guest:' . $uuid, ENCRYPT_KEY);
    return 'g_' . substr($hash, 0, 24);
}
 
/**
 * Generate a stable encrypted user ID for a registered user.
 * Derived from email so the same user always gets the same ID.
 */
function generateRegisteredId(string $email): string {
    $hash = hash_hmac('sha256', 'reg:' . strtolower(trim($email)), ENCRYPT_KEY);
    return 'u_' . substr($hash, 0, 24);
}
 
/**
 * Make a Telegram Bot API request.
 *
 * @param  string $method Telegram method name (e.g. 'sendMessage')
 * @param  array  $params Request parameters
 * @return array  Decoded JSON response
 */
function tgApi(string $method, array $params = []): array {
    $url = TG_API_BASE . '/' . $method;
 
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,   // fail fast — don't block the browser for 15s
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
 
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
 
    if ($curlError) {
        return ['ok' => false, 'description' => 'cURL error: ' . $curlError];
    }
 
    return json_decode($response, true) ?? ['ok' => false, 'description' => 'Invalid JSON response'];
}
 
/**
 * Sanitize a string value from user input.
 */
function sanitize(string $input): string {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}
 
/**
 * Escape special Markdown v1 characters in a string.
 */
function escapeMarkdown(string $text): string {
    return str_replace(['_', '*', '`', '['], ['\_', '\*', '\`', '\['], $text);
}
 
/**
 * Format a Unix timestamp as HH:MM.
 */
function formatTime(int $timestamp): string {
    return date('H:i', $timestamp);
}
 
/**
 * Output a JSON response and terminate execution.
 */
function jsonResponse(array $data): never {
    echo json_encode($data);
    exit;
}