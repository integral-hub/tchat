<?php
/**
 * TeleChater — API Endpoint
 *
 * Available actions:
 *   init    — Identify/register user; get or create their Telegram thread
 *   send    — Send the user's message to Telegram (does NOT call getUpdates)
 *   fetch   — Fetch inbound support replies using a persistent offset
 *   Notice  — Notify admin that this user has the chat open
 *   close   — Notify admin that this user closed the chat
 *
 * KEY PERFORMANCE DECISIONS:
 *   - send  never calls getUpdates. It only POSTs to sendMessage and caches.
 *   - fetch uses a per-user offset stored in the cache file so getUpdates
 *     only returns NEW updates — not the full backlog every time.
 *   - cURL timeout is 8s so a slow Telegram response never hangs the browser.
 */

session_start();

if (empty($_SESSION['user']) || !isset($_SESSION['csrf'])) {
    http_response_code(401);
    exit;
}

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/setme/config.php';
require_once __DIR__ . '/setme/registry.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = sanitize($input['action'] ?? '');

switch ($action) {
    case 'init':   handleInit($input);   break;
    case 'send':   handleSend($input);   break;
    case 'fetch':  handleFetch($input);  break;
    case 'Notice': handleNotice($input); break;
    case 'close':  handleClose($input);  break;
    default:
        http_response_code(403);
        exit;
}

// ACTION: init
function handleInit(array $in): void {
    $type = $in['type'] ?? 'guest';

    if ($type === 'registered') {
        $email = sanitize($in['email'] ?? '');
        $name  = sanitize($in['name']  ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(['ok' => false, 'error' => 'A valid email address is required.']);
        }
        if (!$name) {
            jsonResponse(['ok' => false, 'error' => 'Name is required.']);
        }
        $userId      = generateRegisteredId($email);
        $displayName = $name;
        $userType    = 'registered';
    } else {
        $uuid = sanitize($in['uuid'] ?? '');
        if (!$uuid) {
            jsonResponse(['ok' => false, 'error' => 'Guest UUID is required.']);
        }
        $userId      = generateGuestId($uuid);
        $displayName = 'Anonymous';
        $userType    = 'guest';
    }

    $chatId = registerUserChat($userId, $displayName, $userType);
    if (!$chatId) {
        jsonResponse(['ok' => false, 'error' => 'Could not create support thread. Please try again.']);
    }

    $topicId = getUserTopicId($userId);

    jsonResponse([
        'ok'       => true,
        'userId'   => $userId,
        'chatId'   => $chatId,
        'topicId'  => $topicId,
        'name'     => $displayName,
        'userType' => $userType,
    ]);
}

// ACTION: send
// Sends to Telegram
// This keeps the send path fast: one Telegram API call, done.
function handleSend(array $in): void {
    $userId  = sanitize($in['userId']  ?? '');
    $chatId  = (int)($in['chatId']    ?? 0);
    $topicId = isset($in['topicId']) && $in['topicId'] ? (int)$in['topicId'] : null;
    $message = trim($in['message']    ?? '');
    $name    = sanitize($in['name']   ?? 'User');

    if (!$userId || !$chatId || !$message) {
        jsonResponse(['ok' => false, 'error' => 'userId, chatId, and message are required.']);
    }

    $params = [
        'chat_id'    => $chatId,
        'text'       => escapeMarkdown($message),
        'parse_mode' => 'Markdown',
    ];
    if ($topicId) {
        $params['message_thread_id'] = $topicId;
    }

    $result = tgApi('sendMessage', $params);

    if (!($result['ok'] ?? false)) {
        jsonResponse(['ok' => false, 'error' => $result['description'] ?? 'Failed to send message.']);
    }

    $msgId     = (int)($result['result']['message_id'] ?? 0);
    $timestamp = (int)($result['result']['date']       ?? time());

    cacheMessage($userId, [
        'id'        => $msgId,
        'text'      => $message,
        'side'      => 'user',
        'from'      => $name,
        'timestamp' => $timestamp,
        'time'      => formatTime($timestamp),
    ]);

    appendDeliveryLog($chatId, $name, $message, $msgId);

    jsonResponse([
        'ok'        => true,
        'messageId' => $msgId,
        'timestamp' => $timestamp,
        'time'      => formatTime($timestamp),
    ]);
}

// ACTION: fetch
// Polls Telegram using a persistent per-user offset so each call only
// fetches genuinely new updates, not the entire backlog.
function handleFetch(array $in): void {
    $userId  = sanitize($in['userId']  ?? '');
    $chatId  = (int)($in['chatId']    ?? 0);
    $topicId = isset($in['topicId']) && $in['topicId'] ? (int)$in['topicId'] : null;
    $lastId  = (int)($in['lastMsgId'] ?? 0);

    if (!$userId || !$chatId) {
        jsonResponse(['ok' => false, 'error' => 'userId and chatId are required.']);
    }

    $cache    = loadUserCache($userId);
    $messages = $cache['messages'] ?? [];
    $tgOffset = (int)($cache['tgOffset'] ?? 0);

    // Highest inbound message_id already in cache
    $highestCachedInbound = 0;
    foreach ($messages as $m) {
        if ($m['side'] === 'support' && (int)$m['id'] > $highestCachedInbound) {
            $highestCachedInbound = (int)$m['id'];
        }
    }

    // Fetch only new updates from Telegram
    [$newInbound, $newTgOffset] = fetchInboundMessages(
        $chatId, $topicId, $highestCachedInbound, $tgOffset
    );

    $cacheChanged = false;

    if ($newInbound) {
        foreach ($newInbound as $msg) {
            $exists = false;
            foreach ($messages as $m) {
                if ((int)$m['id'] === (int)$msg['id']) { $exists = true; break; }
            }
            if (!$exists) {
                $messages[] = $msg;
                $cacheChanged = true;
            }
        }
        usort($messages, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    }

    if ($cacheChanged || $newTgOffset !== $tgOffset) {
        saveUserCache($userId, ['messages' => $messages, 'tgOffset' => $newTgOffset]);
    }

    $toSend = array_values(array_filter($messages, fn($m) => (int)$m['id'] > $lastId));

    $newLastId = $lastId;
    foreach ($messages as $m) {
        if ((int)$m['id'] > $newLastId) $newLastId = (int)$m['id'];
    }

    jsonResponse([
        'ok'        => true,
        'messages'  => $toSend,
        'lastMsgId' => $newLastId,
        'history'   => $lastId === 0 ? array_values($messages) : [],
    ]);
}

// ACTION: Notice
function handleNotice(array $in): void {
    $userId   = sanitize($in['userId']   ?? '');
    $name     = sanitize($in['name']     ?? 'Unknown');
    $chatId   = (int)($in['chatId']      ?? 0);
    $topicId  = isset($in['topicId']) && $in['topicId'] ? (int)$in['topicId'] : null;
    $userType = sanitize($in['userType'] ?? 'guest');
    $isNew    = (bool)($in['isNew']      ?? false);

    if ($isNew && $chatId) {
        $icon      = $userType === 'registered' ? '👤' : '👤';
        $typeLabel = $userType === 'registered' ? 'Registered User' : 'Guest';

        $forumGroupId = FORUM_GROUP_CHAT_ID;
        if ($forumGroupId && $topicId) {
            $cleanId = str_replace('-100', '', (string)$forumGroupId);
            $tgLink  = "https://t.me/c/{$cleanId}/{$topicId}";
        } else {
            $tgLink = "https://t.me/c/" . str_replace('-100', '', (string)$chatId) . "/1";
        }

        $text = "🟢 *Chat Opened*\n"
              . "{$icon} {$typeLabel}: *" . escapeMarkdown($name) . "*\n"
              . "🆔 `{$userId}`\n"
              . "🕐 " . date('Y-m-d H:i:s') . "\n"
              . "💬 [Open Thread]({$tgLink})";

        tgApi('sendMessage', [
            'chat_id'                  => ADMIN_CHAT_ID,
            'text'                     => $text,
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
        ]);
    }

    jsonResponse(['ok' => true]);
}

// ACTION: close
function handleClose(array $in): void {
    $userId   = sanitize($in['userId']   ?? '');
    $name     = sanitize($in['name']     ?? 'Unknown');
    $userType = sanitize($in['userType'] ?? 'guest');

    $icon = $userType === 'registered' ? '👤' : '👤';
    $text = "🔴 *Chat Closed*\n"
          . "{$icon} *" . escapeMarkdown($name) . "*\n"
          . "🆔 `{$userId}`\n"
          . "🕐 " . date('Y-m-d H:i:s');

    tgApi('sendMessage', [
        'chat_id'    => ADMIN_CHAT_ID,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ]);

    jsonResponse(['ok' => true]);
}

// ─
// USER CACHE
// Format: { "messages": [...], "tgOffset": 12345 }
// ─

function loadUserCache(string $userId): array {
    ensureDataDir();
    $file = cacheFile($userId);
    if (!file_exists($file)) return ['messages' => [], 'tgOffset' => 0];
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) return ['messages' => [], 'tgOffset' => 0];
    // Back-compat: old format was a flat array of messages
    if (array_is_list($data)) return ['messages' => $data, 'tgOffset' => 0];
    return ['messages' => $data['messages'] ?? [], 'tgOffset' => (int)($data['tgOffset'] ?? 0)];
}

function saveUserCache(string $userId, array $cache): void {
    ensureDataDir();
    file_put_contents(cacheFile($userId), json_encode($cache), LOCK_EX);
}

function cacheMessage(string $userId, array $message): void {
    $cache    = loadUserCache($userId);
    $messages = $cache['messages'] ?? [];
    foreach ($messages as $m) {
        if ((int)$m['id'] === (int)$message['id']) return;
    }
    $messages[] = $message;
    usort($messages, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);
    $cache['messages'] = $messages;
    saveUserCache($userId, $cache);
}

function cacheFile(string $userId): string {
    return DATA_DIR . '/msgs_' . preg_replace('/[^a-z0-9_]/', '', $userId) . '.json';
}

// ─
// TELEGRAM FETCH 
// ─

function fetchInboundMessages(int $chatId, ?int $topicId, int $afterMsgId, int $tgOffset): array {
    $params = [
        'limit'           => 100,
        'allowed_updates' => ['message'],
        'timeout'         => 0,
    ];
    if ($tgOffset > 0) {
        $params['offset'] = $tgOffset;
    }

    $result = tgApi('getUpdates', $params);
    if (!($result['ok'] ?? false)) return [[], $tgOffset];

    $updates   = $result['result'] ?? [];
    $messages  = [];
    $newOffset = $tgOffset;

    foreach ($updates as $update) {
        $updateId = (int)($update['update_id'] ?? 0);
        // Always advance offset past every update received, not just ones used
        if ($updateId >= $newOffset) {
            $newOffset = $updateId + 1;
        }

        $msg = $update['message'] ?? null;
        if (!$msg) continue;

        if ((int)($msg['chat']['id'] ?? 0) !== $chatId) continue;
        if ($topicId && (int)($msg['message_thread_id'] ?? 0) !== $topicId) continue;

        $msgId = (int)($msg['message_id'] ?? 0);
        if ($msgId <= $afterMsgId) continue;

        $text = $msg['text'] ?? '';
        if (!$text) continue;

        // Skip system messages auto generated
        if (str_contains($text, '━━━━━━━━')
         || str_contains($text, '🆕 *New Conversation*')
         || str_contains($text, '🟢 *Chat Opened*')
         || str_contains($text, '🔴 *Chat Closed*')) {
            continue;
        }

        $from  = $msg['from'] ?? [];
        // Bot = message sent via handleSend; already cached, skip
        if ((bool)($from['is_bot'] ?? false)) continue;

        $fromName  = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $timestamp = (int)($msg['date'] ?? time());

        $messages[] = [
            'id'        => $msgId,
            'text'      => $text,
            'side'      => 'support',
            'from'      => $fromName ?: 'Support',
            'timestamp' => $timestamp,
            'time'      => formatTime($timestamp),
        ];
    }

    return [$messages, $newOffset];
}