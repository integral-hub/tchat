# TeleChater — Telegram-backed Web Chat

A live chat system where **Telegram is the database**.
Each user (guest or registered) gets their own Telegram chat thread.
Admin replies from Telegram appear live in the web popup.

---

## Files

```
tchat/
|-- assets/    - All css and js files
|-- setme/
        config.php   - Bot credentials & helpers  * EDIT THIS FIRST
        registry.php - Registration logic
|-- api.php    - All AJAX backend logic
|-- index.php  - Web chat popup (embed anywhere)
|-- data/      - Auto-created; create .htaccess if not exist and stores user registry & delivery log
        user_chats.json
        delivery_log.jsonl
        .htaccess
```

---

## Setup (5 steps)

### 1. Create a Telegram Bot
1. Message [@BotFather](https://t.me/BotFather) on Telegram
2. Send `/newbot` and follow the prompts
3. Copy the **Bot Token** - paste into `config.php` as `BOT_TOKEN`
4. Copy your bot's username - paste as `BOT_USERNAME`

### 2. Create a Forum Supergroup (required)
This is the "database" where each admin can manage user/customer request/thread.

1. Create a new Telegram group
2. Go to **Edit Group - Group Type - Enable Topics**
3. Add your bot to this group and **make it an Admin** with permissions:
   - Manage Topics
   - Send Messages
   - Read Messages
4. Get the forum/group's Chat ID:
   - Add `@userinfobot` to the group and send `/start`
   - Or: forward a message from the group to `@userinfobot`
   - The ID will be a negative number like `-1001234567890`
5. Paste this into `config.php` as `FORUM_GROUP_CHAT_ID`

### 3. Get your Admin Chat ID (for notifications)
1. Message [@userinfobot](https://t.me/userinfobot) on Telegram: `/start`
2. Copy your personal Chat ID
3. Paste into `config.php` as `ADMIN_CHAT_ID`

### 4. Configure `config.php`
```php
define('BOT_TOKEN',          'YOUR_BOT_TOKEN_HERE');
define('ADMIN_CHAT_ID',      'YOUR_PERSONAL_CHAT_ID');
define('BOT_USERNAME',       'YourBotUsername');
define('FORUM_GROUP_CHAT_ID','YOUR_FORUM_GROUP_ID');   // e.g. -1001234567890
define('ENCRYPT_KEY',        'change-this-to-a-long-random-string-32'); // leave unchanged or change to hex code
```

### 5. Upload to your PHP server
- PHP 8.1+ with `curl` extension
- `data/` directory must be writable: `chmod 755 data/` or let it auto-create

---

## How It Works

```
User opens chat
      |
      
Frontend generates UUID (guest) or hashes email+name (registered)
      |

api.php ?action=init
  - Looks up or creates a Telegram Forum Topic for this user
  - Returns userId + chatId
      |
      
Session stored in sessionStorage (expires in 10 min)
      |
      
User sends message - api.php ?action=send
  - Sends to Telegram Forum Topic
  - Logs delivery to data/delivery_log.jsonl
  - Notifies admin via ADMIN_CHAT_ID
      |
      
Frontend polls api.php ?action=fetch every 4 seconds
  - Fetches new Telegram messages for this chat
  - Admin replies in Telegram appear in web popup

Chat opened/closed:
  - api.php ?action=notice - notifies admin (new session)
  - api.php ?action=close - notifies admin (session ended)
```

---

## Embedding on another page (optional)

Add this snippet to any HTML page (same server):

```html
<!-- Embed TeleChater widget -->
<script>
  (function() {
    var iframe = document.createElement('iframe');
    iframe.src = '/telechater/widget.php'; // or use index.php directly
    iframe.style = 'position:fixed;bottom:0;right:0;width:420px;height:650px;border:none;z-index:9999;';
    document.body.appendChild(iframe);
  })();
</script>
```

Or simply include the `<style>` and `<script>` sections from `index.php` directly in your page.

---

## Security Notes

- `ENCRYPT_KEY` should be a long random string — never expose it
- `data/` must not be web-accessible (add `.htaccess: Deny from all`)
- For registered users: add your own authentication check inside `handleInit()` in `api.php`

---

## Limitations

| Feature | Current approach |

| Message fetching | Long-polling getUpdates |

| User registry | JSON file |

| Auth | Derive ID from email+name |

| Multi-admin | Single ADMIN_CHAT_ID |

For high traffic, switch to a Telegram webhook that writes incoming messages to a
queue, and replace `getUpdates` polling with reading from that queue.
