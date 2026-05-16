<?php
/**
 * TeleChater — User Chat Registration
 * 
 * Maps userId → { chatId, topicId, displayName, userType, createdAt }.
 * Stored as a JSON file; protected from web access via data/.htaccess.
 * 
 */

require_once  'config.php';

// File path 
define('REGISTRY_FILE',   'data/user_chats.json');
define('DELIVERY_LOG',    'data/delivery_log.jsonl');
define('DATA_DIR',        'data');

/**
 * Boot: ensure data directory and files exist.
 */
function ensureDataDir(): void {
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    if (!file_exists(REGISTRY_FILE)) {
        file_put_contents(REGISTRY_FILE, '{}');
    }
    if (!file_exists(DELIVERY_LOG)) {
        file_put_contents(DELIVERY_LOG, '');
    }
    // Deny web access to data directory
    $htaccess = DATA_DIR . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
    }
}

/**
 * Load the full user registry from disk.
 */
function loadRegistry(): array {
    ensureDataDir();
    $content = file_get_contents(REGISTRY_FILE);
    return json_decode($content, true) ?? [];
}

/**
 * Save the registry back to disk atomically.
 */
function saveRegistry(array $registry): void {
    file_put_contents(REGISTRY_FILE, json_encode($registry, JSON_PRETTY_PRINT), LOCK_EX);
}

/**
 * Look up a user by their userId.
 * Returns the registry entry or null if not found.
 */
function findUser(string $userId): ?array {
    $registry = loadRegistry();
    return $registry[$userId] ?? null;
}

/**
 * Register a new user and create their dedicated Telegram chat/topic.
 * Returns the chatId on success, or false on failure.
 */
function registerUserChat(string $userId, string $displayName, string $userType): int|false {
    $registry = loadRegistry();

    // Already registered — return existing chatId
    if (isset($registry[$userId])) {
        return (int) $registry[$userId]['chatId'];
    }

    // Attempt to create a forum topic (one topic per user in a supergroup)
    [$chatId, $topicId] = createTelegramThread($userId, $displayName, $userType);
    if (!$chatId) return false;

    // Send the intro message inside this user's topic
    sendIntroMessage($chatId, $topicId, $userId, $displayName, $userType);

    // Persist to registry
    $registry[$userId] = [
        'chatId'      => $chatId,
        'topicId'     => $topicId,
        'displayName' => $displayName,
        'userType'    => $userType,
        'createdAt'   => time(),
    ];
    saveRegistry($registry);

    return (int) $chatId;
}

/**
 * Retrieve the topicId for a user (null if not using forum topics).
 */
function getUserTopicId(string $userId): ?int {
    $user = findUser($userId);
    return isset($user['topicId']) ? (int)$user['topicId'] : null;
}

/**
 * Append one entry to the delivery log (JSONL format).
 */
function appendDeliveryLog(int $chatId, string $name, string $preview, ?int $msgId): void {
    ensureDataDir();
    $entry = json_encode([
        'ts'      => time(),
        'chatId'  => $chatId,
        'name'    => $name,
        'preview' => mb_substr($preview, 0, 80),
        'msgId'   => $msgId,
    ]);
    file_put_contents(DELIVERY_LOG, $entry . "\n", FILE_APPEND | LOCK_EX);
}

//  Private helpers 

/**
 * Create a Telegram thread for the user.
 * Uses forum topics if FORUM_GROUP_CHAT_ID is configured, otherwise
 * falls back to the admin chat directly.
 *
 * @return array [chatId, topicId|null]
 */
function createTelegramThread(string $userId, string $displayName, string $userType): array {
    $forumGroupId = FORUM_GROUP_CHAT_ID;

    if ($forumGroupId) {
        // Create a topic inside the forum supergroup
        $topicName = ($userType === 'registered' ? '👤 ' : '👤 ')
            . $displayName
            . ' [' . substr($userId, 0, 8) . ']';

        $result = tgApi('createForumTopic', [
            'chat_id' => $forumGroupId,
            'name'    => $topicName,
        ]);

        if (!($result['ok'] ?? false)) {
            return [false, null];
        }

        return [(int)$forumGroupId, (int)$result['result']['message_thread_id']];
    }

    // Fallback: use admin chat without topics
    return [(int)ADMIN_CHAT_ID, null];
}

/**
 * Send the conversation intro message into a user's chat/topic.
 */
function sendIntroMessage(int $chatId, ?int $topicId, string $userId, string $displayName, string $userType): void {
    $icon = $userType === 'registered' ? '👤' : '👤';
    $text = "━━━━━━━━━━━━━━━━━━━━━\n"
          . "🆕 *New Conversation*\n"
          . "{$icon} *" . escapeMarkdown($displayName) . "*\n"
          . "🆔 `{$userId}`\n"
          . "🕐 " . date('Y-m-d H:i:s') . "\n"
          . "━━━━━━━━━━━━━━━━━━━━━\n"
          . "_Reply in this thread to respond to this user._";

    $params = [
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'Markdown',
    ];

    if ($topicId) {
        $params['message_thread_id'] = $topicId;
    }

    tgApi('sendMessage', $params);
}