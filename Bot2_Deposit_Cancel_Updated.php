<?php
declare(strict_types=1);

/*
=========================================================
 DIGITAL PRODUCT TELEGRAM SHOP BOT
 BotBhai / PHP 8.1+
 Single File: bot.php

 IMPORTANT:
 - Do NOT define BOT_TOKEN manually.
 - Do NOT use polling.
 - No Withdraw system.
=========================================================
*/

date_default_timezone_set('Asia/Dhaka');

/* ======================================================
   CONFIG
====================================================== */

const ADMIN_ID = 6724025327;

const PAYMENT_NUMBER = '01400597672';

const MIN_DEPOSIT = 10;
const REFERRAL_BONUS = 1;

const DATA_DIR = __DIR__ . '/data';

const USERS_FILE    = DATA_DIR . '/users.json';
const PRODUCTS_FILE = DATA_DIR . '/products.json';
const DEPOSITS_FILE = DATA_DIR . '/deposits.json';
const ORDERS_FILE   = DATA_DIR . '/orders.json';
const STATES_FILE   = DATA_DIR . '/states.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';
const UPDATES_FILE  = DATA_DIR . '/processed_updates.json';
const LOGS_FILE     = DATA_DIR . '/admin_logs.json';
const APP_VERSION = 'Mail V3 Updated 1.1';


/* ======================================================
   BASIC HELPERS
====================================================== */

function ensureDataDir(): void
{
    // Render's local filesystem is ephemeral, so bot state is stored in PostgreSQL.
    // The directory is kept only for temporary/runtime compatibility.
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_json_store (
        name TEXT PRIMARY KEY,
        data JSONB NOT NULL,
        updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )");

    $defaults = [
        'users.json' => '{}', 'products.json' => '{}', 'deposits.json' => '{}',
        'orders.json' => '{}', 'states.json' => '{}', 'settings.json' => json_encode([
            'price'=>1,'catalog'=>[],'orders_enabled'=>true,'deposits_enabled'=>true,
            'referral_enabled'=>true,'broadcast_enabled'=>true,'maintenance'=>false,'premium_emojis'=>[]
        ]),
        'processed_updates.json' => '{}', 'admin_logs.json' => '{}'
    ];

    $stmt = $pdo->prepare("INSERT INTO bot_json_store(name,data) VALUES(:name, CAST(:data AS jsonb)) ON CONFLICT (name) DO NOTHING");
    foreach ($defaults as $name => $json) {
        $legacyPath = DATA_DIR . '/' . $name;
        if (is_file($legacyPath)) {
            $legacy = file_get_contents($legacyPath);
            if ($legacy !== false && json_decode($legacy, true) !== null) $json = $legacy;
        }
        $stmt->execute([':name'=>$name, ':data'=>$json]);
    }
}



function botToken(): string
{
    return trim((string)(getenv('BOT_TOKEN') ?: ($_ENV['BOT_TOKEN'] ?? '')));
}

function databaseUrl(): string
{
    return trim((string)(getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? '')));
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $url = databaseUrl();
    if ($url === '') {
        throw new RuntimeException('DATABASE_URL is not configured');
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host']) || empty($parts['user'])) {
        throw new RuntimeException('Invalid DATABASE_URL');
    }

    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    if ($scheme === 'postgres') $scheme = 'pgsql';
    if ($scheme !== 'pgsql') {
        throw new RuntimeException('Only PostgreSQL DATABASE_URL is supported');
    }

    $host = $parts['host'];
    $port = $parts['port'] ?? 5432;
    $user = rawurldecode($parts['user']);
    $pass = rawurldecode($parts['pass'] ?? '');
    $dbName = ltrim($parts['path'] ?? '', '/');
    $dbName = rawurldecode($dbName);

    $dsn = "pgsql:host={$host};port={$port};dbname={$dbName};sslmode=require";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

ensureDataDir();

// Browser/Render health check. Telegram webhook requests are POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'POST') === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'service'=>'telegram-bot','version'=>APP_VERSION]);
    exit;
}

function verifyWebhookSecret(): void
{
    $expected = trim((string)(getenv('WEBHOOK_SECRET') ?: ''));
    if ($expected === '') return;
    $received = (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
    if (!hash_equals($expected, $received)) {
        http_response_code(403);
        exit;
    }
}

verifyWebhookSecret();


function api(string $method, array $data = []): array
{
    $token = botToken();

    if ($token === '') {
        return [
            'ok' => false,
            'description' => 'BOT_TOKEN is not available'
        ];
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30
    ]);

    $result = curl_exec($ch);

    if ($result === false) {
        $error = curl_error($ch);
        curl_close($ch);

        return [
            'ok' => false,
            'description' => $error
        ];
    }

    curl_close($ch);

    $decoded = json_decode($result, true);

    return is_array($decoded)
        ? $decoded
        : [
            'ok' => false,
            'description' => 'Invalid Telegram response'
        ];
}


function jsonStoreName(string $file): string
{
    return basename($file);
}

function loadJson(string $file, array $default = []): array
{
    $stmt = db()->prepare('SELECT data::text AS data FROM bot_json_store WHERE name = :name');
    $stmt->execute([':name'=>jsonStoreName($file)]);
    $row = $stmt->fetch();
    if (!$row) return $default;
    $data = json_decode((string)$row['data'], true);
    return is_array($data) ? $data : $default;
}

function saveJson(string $file, array $data): bool
{
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;
    $stmt = db()->prepare("INSERT INTO bot_json_store(name,data,updated_at) VALUES(:name, CAST(:data AS jsonb), NOW())
        ON CONFLICT (name) DO UPDATE SET data = EXCLUDED.data, updated_at = NOW()");
    $stmt->execute([':name'=>jsonStoreName($file), ':data'=>$json]);
    return true;
}


function isDuplicateUpdate(array $update): bool
{
    $updateId = isset($update['update_id']) ? (string)$update['update_id'] : '';
    if ($updateId === '') {
        return false;
    }

    $seen = loadJson(UPDATES_FILE, []);
    if (isset($seen[$updateId])) {
        return true;
    }

    $seen[$updateId] = time();

    // Keep only the latest 2000 webhook update IDs.
    if (count($seen) > 2000) {
        asort($seen, SORT_NUMERIC);
        $seen = array_slice($seen, -2000, null, true);
    }

    saveJson(UPDATES_FILE, $seen);
    return false;
}


/* ======================================================
   DATABASE
====================================================== */

function users(): array
{
    return loadJson(USERS_FILE);
}

function saveUsers(array $data): bool
{
    return saveJson(USERS_FILE, $data);
}

function products(): array
{
    return loadJson(PRODUCTS_FILE);
}

function saveProducts(array $data): bool
{
    return saveJson(PRODUCTS_FILE, $data);
}

function deposits(): array
{
    return loadJson(DEPOSITS_FILE);
}

function saveDeposits(array $data): bool
{
    return saveJson(DEPOSITS_FILE, $data);
}

function orders(): array
{
    return loadJson(ORDERS_FILE);
}

function saveOrders(array $data): bool
{
    return saveJson(ORDERS_FILE, $data);
}

function states(): array
{
    return loadJson(STATES_FILE);
}

function saveStates(array $data): bool
{
    return saveJson(STATES_FILE, $data);
}

function settings(): array
{
    return loadJson(SETTINGS_FILE, [
        'price' => 1,
        'catalog' => [],
        'orders_enabled' => true,
        'deposits_enabled' => true,
        'referral_enabled' => true,
        'broadcast_enabled' => true,
        'maintenance' => false,
        'premium_emojis' => []
    ]);
}

function saveSettings(array $data): bool
{
    return saveJson(SETTINGS_FILE, $data);
}




/* ======================================================
   PREMIUM / CUSTOM EMOJI SYSTEM
====================================================== */

function premiumEmojiDefinitions(): array
{
    return [
        'cart'=>['🛒','🛒 Buy Product'],'deposit'=>['💵','💵 Deposit'],'balance'=>['💰','💰 Balance'],
        '2fa'=>['🔐','🔐 2FA'],'referral'=>['👥','👥 Referral'],'dashboard'=>['📊','📊 Dashboard'],
        'support'=>['🆘','🆘 Support'],'add'=>['📦','📦 Add Product/Stock'],'products'=>['📊','📊 Products/Stock'],
        'vpn'=>['📋','📋 VPN Orders'],'users'=>['👥','👥 Users'],'find'=>['🔎','🔎 Find User'],
        'xlsx'=>['📥','📥 Users XLSX'],'addbal'=>['💰','💰 Add Balance'],'deduct'=>['➖','➖ Deduct Balance'],
        'price'=>['💲','💲 Update Price'],'stats'=>['📈','📈 Statistics'],'broadcast'=>['📢','📢 Broadcast'],
        'settings'=>['⚙️','⚙️ Bot Settings'],'logs'=>['📜','📜 Admin Logs'],'delete'=>['🗑','🗑 Delete Product'],
        'menu'=>['📋','📋 Menu'],'success'=>['✅','✅ Success'],'error'=>['❌','❌ Error'],
        'warning'=>['⚠️','⚠️ Warning'],'info'=>['ℹ️','ℹ️ Info'],'lock'=>['🔒','🔒 Security'],'time'=>['⏳','⏳ Time / Pending']
    ];
}

function premiumEmojiSettings(): array
{
    $map = settings()['premium_emojis'] ?? [];
    return is_array($map) ? $map : [];
}

function savePremiumEmoji(string $key, string $customEmojiId): bool
{
    if (!isset(premiumEmojiDefinitions()[$key]) || trim($customEmojiId)==='') return false;
    $st=settings(); $map=$st['premium_emojis']??[]; if(!is_array($map))$map=[];
    $map[$key]=trim($customEmojiId); $st['premium_emojis']=$map;
    return saveSettings($st);
}

function premiumEmojiTag(string $id,string $fallback): string
{
    return '<tg-emoji emoji-id="'.htmlspecialchars($id,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'">'.$fallback.'</tg-emoji>';
}

function applyPremiumEmojis(string $text): string
{
    $map = premiumEmojiSettings();
    if (!$map) return $text;

    // Use placeholders first so duplicate fallback emoji do not become nested
    // <tg-emoji> tags when more than one slot uses the same Unicode emoji.
    $placeholders = [];
    $usedFallbacks = [];

    foreach (premiumEmojiDefinitions() as $key => [$fallback]) {
        $id = trim((string)($map[$key] ?? ''));
        if ($id === '' || isset($usedFallbacks[$fallback])) continue;

        $token = "__PREMIUM_EMOJI_" . strtoupper($key) . "__";
        $text = str_replace($fallback, $token, $text);
        $placeholders[$token] = premiumEmojiTag($id, $fallback);
        $usedFallbacks[$fallback] = true;
    }

    if ($placeholders) {
        $text = strtr($text, $placeholders);
    }

    return $text;
}

function extractCustomEmojiId(array $message): string
{
    foreach(['entities','caption_entities'] as $field){
        foreach(($message[$field]??[]) as $entity){
            if(($entity['type']??'')==='custom_emoji' && !empty($entity['custom_emoji_id'])) return (string)$entity['custom_emoji_id'];
        }
    }
    return '';
}

function premiumEmojiStatus(string $key): string
{
    return !empty(premiumEmojiSettings()[$key]) ? '✅ Set' : '❌ Not Set';
}

function premiumEmojiKeyboard(): array
{
    $rows=[]; $row=[];
    foreach(premiumEmojiDefinitions() as $key=>[$emoji,$label]){
        $row[]=['text'=>$emoji.' '.premiumEmojiStatus($key),'callback_data'=>'pem:set:'.$key];
        if(count($row)===2){$rows[]=$row;$row=[];}
    }
    if($row)$rows[]=$row;
    $rows[]=[['text'=>'🔄 Refresh','callback_data'=>'pem:list'],['text'=>'🧹 Reset All','callback_data'=>'pem:resetall']];
    return ['inline_keyboard'=>$rows];
}

function adminPremiumEmojiText(): string
{
    $count=count(array_filter(premiumEmojiSettings(),fn($v)=>(string)$v!==''));
    return "👑 <b>Premium Emoji Settings</b>\n\n"
        ."প্রতিটি slot চাপুন → তারপর একটি Telegram Premium/Custom Emoji পাঠান।\n\n"
        ."✅ Set: <b>{$count}</b>\n"
        ."⚠️ Keyboard button-এর label-এ custom emoji entity দেওয়া যায় না; message/text-এ Premium Emoji দেখাবে।";
}

function adminShowPremiumEmoji(): void
{
    sendMessage(ADMIN_ID,adminPremiumEmojiText(),premiumEmojiKeyboard());
}

/* ======================================================
   STATE
====================================================== */

function setState(int $userId, string $state, array $data = []): void
{
    $all = states();

    $all[(string)$userId] = [
        'state' => $state,
        'data' => $data
    ];

    saveStates($all);
}


function getState(int $userId): ?array
{
    $all = states();

    return $all[(string)$userId] ?? null;
}


function clearState(int $userId): void
{
    $all = states();

    unset($all[(string)$userId]);

    saveStates($all);
}


/* ======================================================
   USER SYSTEM
====================================================== */

function registerUser(array $from): array
{
    $id = (int)($from['id'] ?? 0);

    if ($id <= 0) {
        return [];
    }

    $all = users();

    $key = (string)$id;

    if (!isset($all[$key])) {

        $all[$key] = [
            'id' => $id,
            'first_name' => (string)($from['first_name'] ?? ''),
            'last_name' => (string)($from['last_name'] ?? ''),
            'username' => (string)($from['username'] ?? ''),
            'balance' => 0,
            'ref_by' => null,
            'ref_bonus_received' => false,
            'joined_at' => date('Y-m-d H:i:s')
        ];

        saveUsers($all);
    } else {

        $all[$key]['first_name'] = (string)($from['first_name'] ?? '');
        $all[$key]['last_name'] = (string)($from['last_name'] ?? '');
        $all[$key]['username'] = (string)($from['username'] ?? '');

        saveUsers($all);
    }

    return $all[$key];
}


function getUser(int $userId): ?array
{
    $all = users();

    return $all[(string)$userId] ?? null;
}


function changeBalance(int $userId, float $amount): bool
{
    $all = users();

    $key = (string)$userId;

    if (!isset($all[$key])) {
        return false;
    }

    $current = (float)($all[$key]['balance'] ?? 0);

    $newBalance = $current + $amount;

    if ($newBalance < 0) {
        return false;
    }

    $all[$key]['balance'] = round($newBalance, 2);

    return saveUsers($all);
}


function formatMoney(float $amount): string
{
    return number_format($amount, 2);
}


function isAdmin(int $userId): bool
{
    return $userId === ADMIN_ID;
}


/* ======================================================
   REFERRAL
====================================================== */

function processReferral(int $userId, ?int $refId): void
{
    if (empty(settings()['referral_enabled'])) return;
    if ($refId === null || $refId <= 0 || $refId === $userId) {
        return;
    }

    $all = users();

    $userKey = (string)$userId;
    $refKey  = (string)$refId;

    if (!isset($all[$userKey]) || !isset($all[$refKey])) {
        return;
    }

    if (!empty($all[$userKey]['ref_by'])) {
        return;
    }

    $all[$userKey]['ref_by'] = $refId;

    $all[$refKey]['balance'] = round(
        (float)$all[$refKey]['balance'] + REFERRAL_BONUS,
        2
    );

    $all[$refKey]['ref_bonus_received'] = true;

    saveUsers($all);

    sendMessage(
        $refId,
        "• Referral Bonus Received!\n\n"
        . "• Bonus: " . formatMoney(REFERRAL_BONUS) . "•"
    );
}


function getReferralCount(int $userId): int
{
    $count = 0;

    foreach (users() as $user) {
        if ((int)($user['ref_by'] ?? 0) === $userId) {
            $count++;
        }
    }

    return $count;
}


/* ======================================================
   TELEGRAM HELPERS
====================================================== */

function sendMessage(
    int $chatId,
    string $text,
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'text' => applyPremiumEmojis($text),
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode(
            $keyboard,
            JSON_UNESCAPED_UNICODE
        );
    }

    return api('sendMessage', $data);
}


function sendDocument(
    int $chatId,
    string $filePath,
    string $caption = '',
    ?array $keyboard = null
): array {

    if (!file_exists($filePath)) {
        return [
            'ok' => false,
            'description' => 'File not found'
        ];
    }

    $document = new CURLFile(
        $filePath,
        'text/csv',
        basename($filePath)
    );

    $data = [
        'chat_id' => $chatId,
        'document' => $document,
        'caption' => applyPremiumEmojis($caption),
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode(
            $keyboard,
            JSON_UNESCAPED_UNICODE
        );
    }

    return api('sendDocument', $data);
}


function answerCallback(string $callbackId, string $text = ''): void
{
    api('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text' => $text
    ]);
}


function editMessageText(
    int $chatId,
    int $messageId,
    string $text,
    ?array $keyboard = null
): array {

    $data = [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'text' => applyPremiumEmojis($text),
        'parse_mode' => 'HTML'
    ];

    if ($keyboard !== null) {
        $data['reply_markup'] = json_encode(
            $keyboard,
            JSON_UNESCAPED_UNICODE
        );
    }

    return api('editMessageText', $data);
}


function forwardMessage(
    int $toChatId,
    int $fromChatId,
    int $messageId
): array {

    return api('forwardMessage', [
        'chat_id' => $toChatId,
        'from_chat_id' => $fromChatId,
        'message_id' => $messageId
    ]);
}



/* ======================================================
   TOTP / 2FA
====================================================== */

/*
 * Generates a standard RFC 6238 TOTP code.
 * The secret is NOT saved to users.json or any permanent file.
 */
function normalizeTotpSecret(string $input): string
{
    $input = trim($input);

    // Also accept an otpauth:// URI pasted by the user.
    if (stripos($input, 'otpauth://') === 0) {
        $parts = parse_url($input);
        if (!empty($parts['query'])) {
            parse_str($parts['query'], $query);
            $input = (string)($query['secret'] ?? '');
        } else {
            $input = '';
        }
    }

    // Remove spaces, hyphens and common formatting characters.
    $input = strtoupper($input);
    $input = preg_replace('/[\s\-]/', '', $input) ?? '';

    return $input;
}

function base32DecodeTotp(string $secret): string|false
{
    $secret = normalizeTotpSecret($secret);

    if ($secret === '' || !preg_match('/^[A-Z2-7]+$/', $secret)) {
        return false;
    }

    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $buffer = 0;
    $bitsLeft = 0;
    $output = '';

    $length = strlen($secret);

    for ($i = 0; $i < $length; $i++) {
        $pos = strpos($alphabet, $secret[$i]);
        if ($pos === false) {
            return false;
        }

        $buffer = ($buffer << 5) | $pos;
        $bitsLeft += 5;

        if ($bitsLeft >= 8) {
            $bitsLeft -= 8;
            $output .= chr(($buffer >> $bitsLeft) & 0xFF);
        }
    }

    return $output !== '' ? $output : false;
}

function generateTotpCode(string $secret, ?int $timestamp = null): string|false
{
    $key = base32DecodeTotp($secret);

    if ($key === false) {
        return false;
    }

    $timestamp = $timestamp ?? time();
    $counter = intdiv($timestamp, 30);

    $high = intdiv($counter, 4294967296);
    $low = $counter % 4294967296;

    $binaryCounter = pack('N2', $high, $low);
    $hash = hash_hmac('sha1', $binaryCounter, $key, true);

    $offset = ord($hash[19]) & 0x0F;

    $binaryCode =
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF);

    return str_pad((string)($binaryCode % 1000000), 6, '0', STR_PAD_LEFT);
}

function totpRemainingSeconds(?int $timestamp = null): int
{
    $timestamp = $timestamp ?? time();
    return 30 - ($timestamp % 30);
}

function showTotpCode(int $userId, string $secret): void
{
    $secret = normalizeTotpSecret($secret);
    $code = generateTotpCode($secret);

    if ($code === false) {
        sendMessage(
            $userId,
            "❌ <b>2FA Key ভুল বা invalid.</b>\n\n"
            . "সঠিক Google Authenticator / Microsoft Authenticator Secret Key দিন।\n"
            . "উদাহরণ: <code>JBSWY3DPEHPK3PXP</code>"
        );
        return;
    }

    $remaining = totpRemainingSeconds();

    sendMessage(
        $userId,
        "🔐 <b>Your 2FA Code</b>\n\n"
        . "🔢 Code: <code>{$code}</code>\n"
        . "⏳ Valid for: <b>{$remaining} seconds</b>\n\n"
        . "⚠️ নতুন code পেতে নিচের button চাপুন।\n"
        . "🔒 আপনার 2FA Key bot-এর permanent database-এ save করা হচ্ছে না।",
        [
            'inline_keyboard' => [
                [['text' => '🔄 Generate Again', 'callback_data' => '2fa:again']]
            ]
        ]
    );
}

/* ======================================================
   KEYBOARDS
====================================================== */

function userKeyboard(): array
{
    return ['keyboard'=>[
        [['text'=>'🛒 Buy Product'],['text'=>'💵 Deposit']],
        [['text'=>'💰 Balance'],['text'=>'🔐 2FA Code']],
        [['text'=>'👥 Referral'],['text'=>'📊 Dashboard']],
        [['text'=>'🆘 Support']]
    ],'resize_keyboard'=>true];
}


function adminKeyboard(): array
{
    return ['keyboard'=>[
        [['text'=>'📦 Add Product/Stock'],['text'=>'📊 Products/Stock']],
        [['text'=>'📋 VPN Orders'],['text'=>'💵 Deposits']],
        [['text'=>'👥 Users'],['text'=>'🔎 Find User']],
        [['text'=>'📥 Users XLSX'],['text'=>'💰 Add Balance']],
        [['text'=>'➖ Deduct Balance'],['text'=>'💲 Update Price']],
        [['text'=>'📈 Statistics'],['text'=>'📢 Broadcast']],
        [['text'=>'⚙️ Bot Settings'],['text'=>'👑 Premium Emoji']],
        [['text'=>'📜 Admin Logs'],['text'=>'🗑 Delete Product']],
        [['text'=>'👤 User Menu']]
    ],'resize_keyboard'=>true];
}


function buyKeyboard(int $catalogId): array
{
    return [
        'inline_keyboard' => [
            [
                ['text' => '1', 'callback_data' => "qty:{$catalogId}:1"],
                ['text' => '10', 'callback_data' => "qty:{$catalogId}:10"],
                ['text' => '100', 'callback_data' => "qty:{$catalogId}:100"]
            ],
            [
                ['text' => 'Custom Quantity', 'callback_data' => "qty:{$catalogId}:custom"]
            ]
        ]
    ];
}


/* ======================================================
   PRODUCT SYSTEM
====================================================== */

function productPrice(): float
{
    return (float)(settings()['price'] ?? 1);
}

function catalogProducts(): array
{
    $settings = settings();
    $catalog = $settings['catalog'] ?? [];
    return is_array($catalog) ? $catalog : [];
}

function saveCatalogProducts(array $catalog): bool
{
    $settings = settings();
    $settings['catalog'] = $catalog;
    return saveSettings($settings);
}

function nextCatalogId(array $catalog): int
{
    $max = 0;
    foreach ($catalog as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id > $max) $max = $id;
    }
    return $max + 1;
}

function getCatalogProduct(int $catalogId): ?array
{
    foreach (catalogProducts() as $p) {
        if ((int)($p['id'] ?? 0) === $catalogId && ($p['active'] ?? true)) {
            return $p;
        }
    }
    return null;
}

function catalogName(array $p): string
{
    return (string)($p['name'] ?? $p['product_name'] ?? 'Product');
}

function catalogCategory(array $p): string
{
    return strtolower((string)($p['category'] ?? 'mail'));
}

function catalogPrice(array $p): float
{
    return (float)($p['price'] ?? productPrice());
}

function normalizeLegacyProducts(): void
{
    $all = products();
    $changed = false;
    foreach ($all as &$p) {
        if (!isset($p['catalog_id'])) {
            $p['catalog_id'] = 0;
            $p['category'] = 'legacy';
            $p['product_name'] = 'Legacy Mail';
            $p['price'] = productPrice();
            $changed = true;
        }
    }
    unset($p);
    if ($changed) saveProducts($all);
}
normalizeLegacyProducts();

function availableProducts(?int $catalogId = null): array
{
    $available = [];
    foreach (products() as $product) {
        if (($product['status'] ?? 'available') !== 'available') continue;
        if (!empty($product['sold'])) continue;
        if ($catalogId !== null && (int)($product['catalog_id'] ?? 0) !== $catalogId) continue;
        $available[] = $product;
    }
    return $available;
}

function stockCount(?int $catalogId = null): int
{
    $count = 0;

    if ($catalogId !== null) {
        $p = getCatalogProduct($catalogId);
        if ($p !== null && catalogCategory($p) === 'vpn') {
            $count += max(0, (int)($p['stock'] ?? 0));
        }
    }

    foreach (availableProducts($catalogId) as $product) {
        // VPN stock is count-based. Mail stock remains item/file-based.
        if ($catalogId !== null) {
            $p = getCatalogProduct($catalogId);
            if ($p !== null && catalogCategory($p) === 'vpn') continue;
        }
        $count++;
    }

    return $count;
}

function updateCatalogStock(int $catalogId, int $delta): bool
{
    $catalog = catalogProducts();
    $found = false;

    foreach ($catalog as &$p) {
        if ((int)($p['id'] ?? 0) !== $catalogId) continue;
        if (catalogCategory($p) !== 'vpn') return false;
        $current = max(0, (int)($p['stock'] ?? 0));
        $new = $current + $delta;
        if ($new < 0) return false;
        $p['stock'] = $new;
        $found = true;
        break;
    }
    unset($p);

    return $found ? saveCatalogProducts($catalog) : false;
}

function nextProductId(array $all): int
{
    $max = 0;
    foreach ($all as $product) {
        $id = (int)($product['id'] ?? 0);
        if ($id > $max) $max = $id;
    }
    return $max + 1;
}

function ensureDefaultMailCatalogs(): void
{
    $catalog = catalogProducts();
    $names = ['Hotmail', 'Outlook', 'Outlook FR'];
    $changed = false;
    foreach ($names as $name) {
        $found = false;
        foreach ($catalog as $p) {
            if (strcasecmp(catalogName($p), $name) === 0 && catalogCategory($p) === 'mail') {
                $found = true;
                break;
            }
        }
        if (!$found) {
            $catalog[] = [
                'id' => nextCatalogId($catalog),
                'category' => 'mail',
                'name' => $name,
                'price' => productPrice(),
                'active' => true,
                'created_at' => date('Y-m-d H:i:s')
            ];
            $changed = true;
        }
    }
    if ($changed) saveCatalogProducts($catalog);
}
ensureDefaultMailCatalogs();











/* ======================================================
   STOCK IMPORT
====================================================== */

function importTxt(string $filePath): array
{
    $lines = file($filePath, FILE_IGNORE_NEW_LINES);
    if ($lines === false) return [];
    $items = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') $items[] = $line;
    }
    return $items;
}

function importCsv(string $filePath): array
{
    $items = [];
    $handle = fopen($filePath, 'rb');
    if ($handle === false) return [];
    while (($row = fgetcsv($handle)) !== false) {
        $vals = [];
        foreach ($row as $value) {
            $value = trim((string)$value);
            if ($value !== '') $vals[] = $value;
        }
        if ($vals) {
            // One CSV row is one stock item. Multiple columns are preserved.
            $items[] = count($vals) === 1 ? $vals[0] : implode('|', $vals);
        }
    }
    fclose($handle);
    return $items;
}

function importXlsx(string $filePath): array
{
    if (!class_exists('ZipArchive')) return [];
    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) return [];

    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = @simplexml_load_string($sharedXml);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $v = '';
                foreach ($si->t as $t) $v .= (string)$t;
                $shared[] = trim($v);
            }
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        $zip->close();
        return [];
    }
    $xml = @simplexml_load_string($sheetXml);
    if ($xml === false) {
        $zip->close();
        return [];
    }

    $items = [];
    foreach ($xml->sheetData->row as $row) {
        $vals = [];
        foreach ($row->c as $cell) {
            $type = (string)($cell['t'] ?? '');
            $value = '';
            if ($type === 'inlineStr') {
                foreach ($cell->is->t as $t) $value .= (string)$t;
            } else {
                $value = (string)($cell->v ?? '');
                if ($type === 's') $value = $shared[(int)$value] ?? '';
            }
            $value = trim($value);
            if ($value !== '') $vals[] = $value;
        }
        if ($vals) {
            $items[] = count($vals) === 1 ? $vals[0] : implode('|', $vals);
        }
    }
    $zip->close();
    return $items;
}

function importStockFile(string $filePath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    return match ($ext) {
        'txt' => importTxt($filePath),
        'csv' => importCsv($filePath),
        'xlsx' => importXlsx($filePath),
        default => []
    };
}











/* ======================================================
   PRODUCT NOTIFICATION
====================================================== */

function notifyAllUsersProductAdded(int $added, string $name = '', float $price = 0): void
{
    if ($added <= 0) return;
    $label = $name !== '' ? $name : 'Product';
    $text = "🆕 <b>New Stock Added!</b>\n\n"
        . "📦 Product: <b>" . htmlspecialchars($label) . "</b>\n"
        . "📥 New Stock: <b>{$added}</b>\n"
        . "💰 Price: <b>" . formatMoney($price) . "৳</b>\n\n"
        . "🛒 Buy Product থেকে কিনতে পারবেন।";
    foreach (users() as $user) {
        $id = (int)($user['id'] ?? 0);
        if ($id > 0) sendMessage($id, $text);
    }
}


/* ======================================================
   PRODUCT DELIVERY
====================================================== */

function createProductCsv(array $selected): string|false
{
    $file = tempnam(sys_get_temp_dir(), 'products_');
    if ($file === false) return false;
    $csvPath = $file . '.csv';
    rename($file, $csvPath);
    $handle = fopen($csvPath, 'wb');
    if ($handle === false) return false;
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, ['SL', 'Product']);
    $sl = 1;
    foreach ($selected as $product) {
        fputcsv($handle, [$sl++, (string)($product['product'] ?? '')]);
    }
    fclose($handle);
    return $csvPath;
}

function deliverProducts(int $userId, int $quantity, int $catalogId): bool
{
    if ($quantity <= 0) {
        sendMessage($userId, "❌ Invalid quantity.");
        return false;
    }

    $catalog = getCatalogProduct($catalogId);
    if ($catalog === null) {
        sendMessage($userId, "❌ Product not found or inactive.");
        return false;
    }

    $price = catalogPrice($catalog);
    $total = round($price * $quantity, 2);
    $user = getUser($userId);
    if ($user === null) return false;
    $balance = (float)($user['balance'] ?? 0);

    if ($balance < $total) {
        sendMessage($userId,
            "❌ <b>Insufficient Balance</b>\n\n"
            . "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>\n"
            . "🔢 Quantity: <b>{$quantity}</b>\n"
            . "💰 Total: <b>" . formatMoney($total) . "৳</b>\n"
            . "💳 Balance: <b>" . formatMoney($balance) . "৳</b>"
        );
        return false;
    }

    $all = products();
    $selected = [];
    foreach ($all as $key => $product) {
        if ((int)($product['catalog_id'] ?? 0) !== $catalogId) continue;
        if (($product['status'] ?? 'available') !== 'available') continue;
        if (!empty($product['sold'])) continue;
        $selected[] = ['key'=>$key, 'id'=>$product['id'] ?? 0, 'product'=>$product['product'] ?? ''];
        if (count($selected) >= $quantity) break;
    }

    if (count($selected) < $quantity) {
        sendMessage($userId,
            "❌ <b>Insufficient Stock</b>\n\n"
            . "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>\n"
            . "📦 Available: <b>" . count($selected) . "</b>\n"
            . "🔢 Requested: <b>{$quantity}</b>"
        );
        return false;
    }

    $deliveryRows = [];
    foreach ($selected as $item) {
        $key = $item['key'];
        $deliveryRows[] = [
            'id' => $all[$key]['id'],
            'product' => $all[$key]['product']
        ];
        $all[$key]['sold'] = true;
        $all[$key]['sold_to'] = $userId;
        $all[$key]['sold_at'] = date('Y-m-d H:i:s');
        $all[$key]['status'] = 'sold';
    }

    // Delivery rule:
    // 1-5 pcs  = direct Telegram message
    // 6+ pcs   = CSV file delivery (existing behavior)
    $csv = false;
    if ($quantity > 5) {
        $csv = createProductCsv($deliveryRows);
        if ($csv === false) {
            sendMessage($userId, "❌ Delivery file তৈরি করা যায়নি।");
            return false;
        }
    }

    if (!saveProducts($all)) {
        if ($csv !== false) @unlink($csv);
        sendMessage($userId, "❌ Stock save করা যায়নি।");
        return false;
    }

    if (!changeBalance($userId, -$total)) {
        // rollback stock if balance deduction fails
        $restore = products();
        foreach ($selected as $item) {
            foreach ($restore as &$p) {
                if ((int)($p['id'] ?? 0) === (int)$item['id']) {
                    $p['sold'] = false; $p['sold_to'] = null; $p['sold_at'] = null; $p['status'] = 'available';
                    break;
                }
            }
            unset($p);
        }
        saveProducts($restore);
        if ($csv !== false) @unlink($csv);
        sendMessage($userId, "❌ Balance update failed.");
        return false;
    }

    $orderId = 'ORD' . date('YmdHis') . random_int(100,999);
    $orders = orders();
    $orders[$orderId] = [
        'id'=>$orderId, 'user_id'=>$userId,
        'catalog_id'=>$catalogId,
        'category'=>catalogCategory($catalog),
        'product_name'=>catalogName($catalog),
        'price_each'=>$price,
        'quantity'=>$quantity,
        'total'=>$total,
        'product_ids'=>array_column($deliveryRows,'id'),
        'created_at'=>date('Y-m-d H:i:s')
    ];
    saveOrders($orders);

    if ($quantity <= 5) {
        // Small orders: deliver the mail/product directly in chat.
        $lines = [
            "✅ <b>Order Delivered</b>",
            "",
            "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>",
            "🔢 Quantity: <b>{$quantity}</b>",
            "💰 Total: <b>" . formatMoney($total) . "৳</b>",
            "🧾 Order: <code>{$orderId}</code>",
            "",
            "📩 <b>Your Product:</b>"
        ];
        foreach ($deliveryRows as $i => $row) {
            $value = htmlspecialchars((string)($row['product'] ?? ''));
            $lines[] = ($i + 1) . ". <code>{$value}</code>";
        }
        sendMessage($userId, implode("\n", $lines));
        return true;
    }

    // 6+ pcs: keep the existing CSV file delivery.
    $result = sendDocument(
        $userId, $csv,
        "✅ <b>Order Delivered</b>\n"
        . "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>\n"
        . "🔢 Quantity: <b>{$quantity}</b>\n"
        . "💰 Total: <b>" . formatMoney($total) . "৳</b>\n"
        . "🧾 Order: <code>{$orderId}</code>"
    );
    @unlink($csv);

    if (!($result['ok'] ?? false)) {
        sendMessage($userId, "⚠️ Order তৈরি হয়েছে, কিন্তু delivery file পাঠানো যায়নি। Admin-এর সাথে যোগাযোগ করুন।");
        sendMessage(ADMIN_ID, "⚠️ Delivery failed\nUser: <code>{$userId}</code>\nOrder: <code>{$orderId}</code>");
        return false;
    }
    return true;
}






/* ======================================================
   VPN ORDER / ADMIN DELIVERY
====================================================== */

function createVpnOrder(int $userId, int $quantity, int $catalogId): bool
{
    if ($quantity <= 0) {
        sendMessage($userId, "❌ Invalid quantity.");
        return false;
    }

    $catalog = getCatalogProduct($catalogId);
    if ($catalog === null || catalogCategory($catalog) !== 'vpn') {
        sendMessage($userId, "❌ VPN product not found or inactive.");
        return false;
    }

    $available = stockCount($catalogId);
    if ($available < $quantity) {
        sendMessage($userId,
            "❌ <b>Insufficient Stock</b>\n\n"
            . "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>\n"
            . "📦 Available: <b>{$available}</b> pcs\n"
            . "🔢 Requested: <b>{$quantity}</b> pcs"
        );
        return false;
    }

    $price = catalogPrice($catalog);
    $total = round($price * $quantity, 2);
    $user = getUser($userId);
    if ($user === null) return false;
    $balance = (float)($user['balance'] ?? 0);

    if ($balance < $total) {
        sendMessage($userId,
            "❌ <b>Insufficient Balance</b>\n\n"
            . "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>\n"
            . "🔢 Quantity: <b>{$quantity}</b>\n"
            . "💰 Total: <b>" . formatMoney($total) . "৳</b>\n"
            . "💳 Balance: <b>" . formatMoney($balance) . "৳</b>"
        );
        return false;
    }

    // VPN stock is count-based; reserve it immediately by reducing the count.
    if (!updateCatalogStock($catalogId, -$quantity)) {
        sendMessage($userId, "❌ Stock update failed. Please try again.");
        return false;
    }

    if (!changeBalance($userId, -$total)) {
        updateCatalogStock($catalogId, $quantity);
        sendMessage($userId, "❌ Balance update failed. Order cancelled.");
        return false;
    }

    $orderId = 'VPN' . date('YmdHis') . random_int(100, 999);
    $orders = orders();
    $orders[$orderId] = [
        'id' => $orderId,
        'user_id' => $userId,
        'catalog_id' => $catalogId,
        'category' => 'vpn',
        'product_name' => catalogName($catalog),
        'price_each' => $price,
        'quantity' => $quantity,
        'total' => $total,
        'status' => 'pending_delivery',
        'created_at' => date('Y-m-d H:i:s'),
        'delivered_at' => null
    ];

    if (!saveOrders($orders)) {
        changeBalance($userId, $total);
        updateCatalogStock($catalogId, $quantity);
        sendMessage($userId, "❌ Order save failed. Your balance and stock have been restored.");
        return false;
    }

    $stockLeft = stockCount($catalogId);

    sendMessage($userId,
        "⏳ <b>Experts</b>\n\n"
        . "ইতিমধ্যেই আমাদের টিম কাজ করা শুরু করেছে।\n"
        . "কিছুক্ষণের ভিতর আপনার order delivery হবে।\n\n"
        . "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>\n"
        . "🔢 Quantity: <b>{$quantity}</b>\n"
        . "💰 Total: <b>" . formatMoney($total) . "৳</b>\n"
        . "🧾 Order: <code>{$orderId}</code>"
    );

    sendMessage(ADMIN_ID,
        "🔔 <b>New VPN Order</b>\n\n"
        . "🧾 Order: <code>{$orderId}</code>\n"
        . "👤 User: <code>{$userId}</code>\n"
        . "📦 Product: <b>" . htmlspecialchars(catalogName($catalog)) . "</b>\n"
        . "🔢 Quantity: <b>{$quantity}</b>\n"
        . "💰 Total: <b>" . formatMoney($total) . "৳</b>\n"
        . "📦 Remaining Stock: <b>{$stockLeft}</b>\n\n"
        . "👇 Deliver চাপলে User-কে VPN details পাঠাতে পারবেন।",
        ['inline_keyboard' => [[
            ['text'=>'✅ Deliver Order','callback_data'=>'vpnorder:deliver:' . $orderId],
            ['text'=>'❌ Cancel','callback_data'=>'vpnorder:cancel:' . $orderId]
        ]]]
    );

    return true;
}

function deliverVpnOrder(string $orderId): void
{
    $orders = orders();
    if (!isset($orders[$orderId])) {
        sendMessage(ADMIN_ID, "❌ Order not found.");
        return;
    }

    $order = $orders[$orderId];
    if (($order['status'] ?? '') !== 'pending_delivery') {
        sendMessage(ADMIN_ID, "⚠️ This order is already processed.");
        return;
    }

    setState(ADMIN_ID, 'admin_vpn_delivery', ['order_id' => $orderId]);
    sendMessage(ADMIN_ID,
        "📤 <b>VPN Delivery</b>\n\n"
        . "Order: <code>{$orderId}</code>\n"
        . "👤 User: <code>" . (int)$order['user_id'] . "</code>\n"
        . "📦 Product: <b>" . htmlspecialchars((string)$order['product_name']) . "</b>\n"
        . "🔢 Quantity: <b>" . (int)$order['quantity'] . "</b>\n\n"
        . "এখন User-কে দেওয়ার <b>VPN details</b> পাঠান।\n"
        . "Text / Document / Photo পাঠাতে পারবেন।\n\n"
        . "/cancel দিয়ে বাতিল করতে পারবেন।"
    );
}

function completeVpnDelivery(string $orderId, array $message): void
{
    $orders = orders();
    if (!isset($orders[$orderId])) {
        clearState(ADMIN_ID);
        sendMessage(ADMIN_ID, "❌ Order not found.", adminKeyboard());
        return;
    }

    $order = $orders[$orderId];
    if (($order['status'] ?? '') !== 'pending_delivery') {
        clearState(ADMIN_ID);
        sendMessage(ADMIN_ID, "⚠️ This order is already processed.", adminKeyboard());
        return;
    }

    $userId = (int)($order['user_id'] ?? 0);
    if ($userId <= 0) {
        clearState(ADMIN_ID);
        sendMessage(ADMIN_ID, "❌ Invalid user.", adminKeyboard());
        return;
    }

    $sent = false;
    if (isset($message['document'])) {
        $r = api('sendDocument', [
            'chat_id' => $userId,
            'document' => $message['document']['file_id'],
            'caption' => "✅ <b>VPN Order Delivered</b>\n\nOrder: <code>{$orderId}</code>\nProduct: <b>" . htmlspecialchars((string)$order['product_name']) . "</b>\nQuantity: <b>" . (int)$order['quantity'] . "</b>",
            'parse_mode' => 'HTML'
        ]);
        $sent = (bool)($r['ok'] ?? false);
    } elseif (isset($message['photo'])) {
        $photos = $message['photo'];
        $photo = end($photos);
        $r = api('sendPhoto', [
            'chat_id' => $userId,
            'photo' => $photo['file_id'],
            'caption' => "✅ <b>VPN Order Delivered</b>\n\nOrder: <code>{$orderId}</code>\nProduct: <b>" . htmlspecialchars((string)$order['product_name']) . "</b>\nQuantity: <b>" . (int)$order['quantity'] . "</b>",
            'parse_mode' => 'HTML'
        ]);
        $sent = (bool)($r['ok'] ?? false);
    } elseif (isset($message['video'])) {
        $r = api('sendVideo', [
            'chat_id' => $userId,
            'video' => $message['video']['file_id'],
            'caption' => "✅ <b>VPN Order Delivered</b>\n\nOrder: <code>{$orderId}</code>",
            'parse_mode' => 'HTML'
        ]);
        $sent = (bool)($r['ok'] ?? false);
    } else {
        $text = trim((string)($message['text'] ?? ''));
        if ($text !== '') {
            $r = sendMessage($userId,
                "✅ <b>VPN Order Delivered</b>\n\n"
                . "📦 Product: <b>" . htmlspecialchars((string)$order['product_name']) . "</b>\n"
                . "🔢 Quantity: <b>" . (int)$order['quantity'] . "</b>\n"
                . "🧾 Order: <code>{$orderId}</code>\n\n"
                . htmlspecialchars($text)
            );
            $sent = (bool)($r['ok'] ?? false);
        }
    }

    if (!$sent) {
        sendMessage(ADMIN_ID, "❌ Delivery message পাঠানো যায়নি। আবার Text/Document/Photo পাঠান।");
        return;
    }

    $orders[$orderId]['status'] = 'delivered';
    $orders[$orderId]['delivered_at'] = date('Y-m-d H:i:s');
    saveOrders($orders);
    clearState(ADMIN_ID);

    sendMessage(ADMIN_ID,
        "✅ <b>VPN Order Delivered</b>\n\n"
        . "🧾 Order: <code>{$orderId}</code>\n"
        . "👤 User: <code>{$userId}</code>\n"
        . "📦 Product: <b>" . htmlspecialchars((string)$order['product_name']) . "</b>\n"
        . "📦 Stock automatically updated: <b>" . stockCount((int)$order['catalog_id']) . "</b>",
        adminKeyboard()
    );
}

function cancelVpnOrder(string $orderId): void
{
    $orders = orders();
    if (!isset($orders[$orderId])) {
        sendMessage(ADMIN_ID, "❌ Order not found.");
        return;
    }
    $order = $orders[$orderId];
    if (($order['status'] ?? '') !== 'pending_delivery') {
        sendMessage(ADMIN_ID, "⚠️ This order is already processed.");
        return;
    }

    $userId = (int)($order['user_id'] ?? 0);
    $catalogId = (int)($order['catalog_id'] ?? 0);
    $qty = max(0, (int)($order['quantity'] ?? 0));

    if ($catalogId > 0 && $qty > 0) updateCatalogStock($catalogId, $qty);

    $refund = (float)($order['total'] ?? 0);
    if ($userId > 0 && $refund > 0) changeBalance($userId, $refund);

    $orders[$orderId]['status'] = 'cancelled';
    $orders[$orderId]['cancelled_at'] = date('Y-m-d H:i:s');
    saveOrders($orders);

    if ($userId > 0) {
        sendMessage($userId,
            "❌ <b>VPN Order Cancelled</b>\n\n"
            . "Order: <code>{$orderId}</code>\n"
            . "💰 Refund: <b>" . formatMoney($refund) . "৳</b>\n"
            . "Your balance has been restored."
        );
    }
    sendMessage(ADMIN_ID,
        "✅ Order cancelled. Stock and balance restored.\nOrder: <code>{$orderId}</code>",
        adminKeyboard()
    );
}

function adminVpnOrders(): void
{
    $orders = orders();
    $pending = [];
    foreach ($orders as $order) {
        if (($order['category'] ?? '') === 'vpn' && ($order['status'] ?? '') === 'pending_delivery') {
            $pending[] = $order;
        }
    }

    if (!$pending) {
        sendMessage(ADMIN_ID, "📋 <b>VPN Orders</b>\n\n✅ কোনো pending VPN order নেই।", adminKeyboard());
        return;
    }

    foreach (array_reverse($pending) as $order) {
        $id = (string)$order['id'];
        sendMessage(ADMIN_ID,
            "⏳ <b>Pending VPN Order</b>\n\n"
            . "🧾 Order: <code>{$id}</code>\n"
            . "👤 User: <code>" . (int)$order['user_id'] . "</code>\n"
            . "📦 Product: <b>" . htmlspecialchars((string)$order['product_name']) . "</b>\n"
            . "🔢 Quantity: <b>" . (int)$order['quantity'] . "</b>\n"
            . "💰 Total: <b>" . formatMoney((float)$order['total']) . "৳</b>\n"
            . "🕒 " . htmlspecialchars((string)$order['created_at']),
            ['inline_keyboard' => [[
                ['text'=>'✅ Deliver','callback_data'=>'vpnorder:deliver:' . $id],
                ['text'=>'❌ Cancel','callback_data'=>'vpnorder:cancel:' . $id]
            ]]]
        );
    }
}

/* ======================================================
   DEPOSIT SYSTEM
====================================================== */

function isTransactionIdUsed(string $trxId): bool
{
    $trxId = strtoupper(trim($trxId));
    if ($trxId === '') return false;

    foreach (deposits() as $deposit) {
        $old = strtoupper(trim((string)($deposit['trxid'] ?? '')));
        if ($old !== '' && hash_equals($old, $trxId)) {
            return true;
        }
    }
    return false;
}

function createDeposit(
    int $userId,
    string $method,
    float $amount,
    string $trxId,
    string $fileId
): string {

    $all = deposits();

    // A Transaction ID can only be used once across the whole bot.
    $normalizedTrxId = strtoupper(trim($trxId));
    foreach ($all as $deposit) {
        $oldTrxId = strtoupper(trim((string)($deposit['trxid'] ?? '')));
        if ($oldTrxId !== '' && hash_equals($oldTrxId, $normalizedTrxId)) {
            throw new RuntimeException('DUPLICATE_TXID');
        }
    }

    $id = 'DEP-' . date('YmdHis') . '-' . random_int(100, 999);

    $all[$id] = [
        'id' => $id,
        'user_id' => $userId,
        'method' => $method,
        'amount' => $amount,
        'trxid' => $trxId,
        'file_id' => $fileId,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
        'approved_at' => null
    ];

    saveDeposits($all);

    return $id;
}


function depositButtons(string $depositId): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text' => '• Approve',
                    'callback_data' => 'dep:approve:' . $depositId
                ],
                [
                    'text' => '• Reject',
                    'callback_data' => 'dep:reject:' . $depositId
                ]
            ]
        ]
    ];
}


/* ======================================================
   XLSX EXPORT
====================================================== */

function xlsxColumnName(int $number): string
{
    $result = '';

    while ($number > 0) {

        $number--;

        $result = chr(65 + ($number % 26)) . $result;

        $number = intdiv($number, 26);
    }

    return $result;
}


function xmlEscape(string $value): string
{
    return htmlspecialchars(
        $value,
        ENT_XML1 | ENT_QUOTES,
        'UTF-8'
    );
}


function createUsersXlsx(): string|false
{
    if (!class_exists('ZipArchive')) {
        return false;
    }

    $temp = tempnam(sys_get_temp_dir(), 'users_');

    if ($temp === false) {
        return false;
    }

    $xlsx = $temp . '.xlsx';

    rename($temp, $xlsx);

    $zip = new ZipArchive();

    if ($zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return false;
    }

    $userRows = array_values(users());

    $headers = [
        'ID',
        'First Name',
        'Last Name',
        'Username',
        'Balance',
        'Referral By',
        'Joined At'
    ];

    $rows = [$headers];

    foreach ($userRows as $user) {

        $rows[] = [
            (string)($user['id'] ?? ''),
            (string)($user['first_name'] ?? ''),
            (string)($user['last_name'] ?? ''),
            (string)($user['username'] ?? ''),
            (string)($user['balance'] ?? 0),
            (string)($user['ref_by'] ?? ''),
            (string)($user['joined_at'] ?? '')
        ];
    }

    $sheetRows = '';

    foreach ($rows as $rowIndex => $row) {

        $excelRow = $rowIndex + 1;

        $sheetRows .= '<row r="' . $excelRow . '">';

        foreach ($row as $colIndex => $value) {

            $cell = xlsxColumnName($colIndex + 1);

            $sheetRows .=
                '<c r="' . $cell . $excelRow . '" t="inlineStr">'
                . '<is><t>'
                . xmlEscape((string)$value)
                . '</t></is>'
                . '</c>';
        }

        $sheetRows .= '</row>';
    }

    $contentTypes =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rels =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>'
        . '<sheet name="Users" sheetId="1" r:id="rId1"/>'
        . '</sheets>'
        . '</workbook>';

    $workbookRels =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" '
        . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
        . 'Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $sheet =
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>'
        . $sheetRows
        . '</sheetData>'
        . '</worksheet>';

    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

    $zip->close();

    return $xlsx;
}


/* ======================================================
   ADMIN STOCK
====================================================== */

function addStockFromFile(string $filePath, string $originalName, int $catalogId): int
{
    $items = importStockFile($filePath, $originalName);
    if (!$items) return 0;

    $catalog = getCatalogProduct($catalogId);
    if ($catalog === null) return 0;

    $all = products();
    $nextId = nextProductId($all);
    $added = 0;

    foreach ($items as $item) {
        $item = trim((string)$item);
        if ($item === '') continue;
        $all[] = [
            'id' => $nextId++,
            'catalog_id' => $catalogId,
            'category' => catalogCategory($catalog),
            'product_name' => catalogName($catalog),
            'price' => catalogPrice($catalog),
            'product' => $item,
            'sold' => false,
            'sold_to' => null,
            'sold_at' => null,
            'status' => 'available',
            'added_at' => date('Y-m-d H:i:s')
        ];
        $added++;
    }

    if ($added > 0 && !saveProducts($all)) return 0;
    return $added;
}


/* ======================================================
   ADMIN PRODUCT LIST
====================================================== */

function adminStockList(): void
{
    $catalog = catalogProducts();
    $all = products();

    $text = "📦 <b>Products / Stock</b>\n\n";
    $totalAvail = stockCount();
    $totalSold = 0;
    foreach ($all as $p) if (!empty($p['sold'])) $totalSold++;

    $text .= "📊 Total Stock: <b>" . count($all) . "</b>\n"
        . "✅ Available: <b>{$totalAvail}</b>\n"
        . "💰 Sold: <b>{$totalSold}</b>\n\n";

    if (!$catalog) {
        $text .= "⚠️ No product created.";
    } else {
        foreach ($catalog as $p) {
            if (!($p['active'] ?? true)) continue;
            $id = (int)$p['id'];
            $name = htmlspecialchars(catalogName($p));
            $cat = catalogCategory($p) === 'vpn' ? '🔐 VPN' : '📧 Mail';
            $price = formatMoney(catalogPrice($p));
            $stock = stockCount($id);
            $text .= "{$cat} <b>{$name}</b>\n"
                . "🆔 ID: <code>{$id}</code> | 💰 {$price}৳ | 📦 Stock: <b>{$stock}</b>\n\n";
        }
    }

    sendMessage(ADMIN_ID, $text, adminKeyboard());
}


/* ======================================================
   ADMIN STATISTICS
====================================================== */

function adminStatistics(): void
{
    $userCount = count(users());

    $allProducts = products();

    $totalProducts = count($allProducts);
    $available = 0;
    $sold = 0;

    foreach ($allProducts as $product) {

        if (!empty($product['sold'])) {
            $sold++;
        } else {
            $available++;
        }
    }

    $allOrders = orders();

    $orderCount = count($allOrders);
    $sales = 0;

    foreach ($allOrders as $order) {
        $sales += (float)($order['total'] ?? 0);
    }

    $allDeposits = deposits();

    $pendingDeposits = 0;
    $approvedDeposits = 0;
    $depositAmount = 0;

    foreach ($allDeposits as $deposit) {

        $status = $deposit['status'] ?? '';

        if ($status === 'pending') {
            $pendingDeposits++;
        }

        if ($status === 'approved') {

            $approvedDeposits++;

            $depositAmount += (float)($deposit['amount'] ?? 0);
        }
    }

    $text =
        "• <b>Statistics</b>\n\n"
        . "• Users: <b>{$userCount}</b>\n"
        . "• Total Products: <b>{$totalProducts}</b>\n"
        . "• Available: <b>{$available}</b>\n"
        . "• Sold: <b>{$sold}</b>\n"
        . "• Orders: <b>{$orderCount}</b>\n"
        . "• Sales: <b>" . formatMoney($sales) . "•</b>\n"
        . "• Pending Deposits: <b>{$pendingDeposits}</b>\n"
        . "• Approved Deposits: <b>{$approvedDeposits}</b>\n"
        . "• Deposit Amount: <b>" . formatMoney($depositAmount) . "•</b>";

    sendMessage(
        ADMIN_ID,
        $text,
        adminKeyboard()
    );
}


/* ======================================================
   ADMIN DEPOSIT LIST
====================================================== */

function adminDepositList(): void
{
    $all = deposits();

    if (!$all) {

        sendMessage(
            ADMIN_ID,
            "• <b>Deposits</b>\n\n•••• Deposit ••••",
            adminKeyboard()
        );

        return;
    }

    $text = "• <b>Recent Deposits</b>\n\n";

    $shown = 0;

    foreach (array_reverse($all, true) as $id => $deposit) {

        $status = $deposit['status'] ?? 'pending';

        $emoji = match ($status) {
            'approved' => '•',
            'rejected' => '•',
            default => '•'
        };

        $text .=
            "{$emoji} <b>{$id}</b>\n"
            . "• User: <code>" . ($deposit['user_id'] ?? '') . "</code>\n"
            . "• Method: " . ($deposit['method'] ?? '') . "\n"
            . "• Amount: " . formatMoney((float)($deposit['amount'] ?? 0)) . "•\n"
            . "• TRX: <code>" . htmlspecialchars((string)($deposit['trxid'] ?? '')) . "</code>\n"
            . "• " . ($deposit['created_at'] ?? '') . "\n\n";

        if ($status === 'pending') {

            sendMessage(
                ADMIN_ID,
                $text,
                depositButtons((string)$id)
            );

            $text = '';
        }

        $shown++;

        if ($shown >= 10) {
            break;
        }
    }

    if ($text !== '') {
        sendMessage(
            ADMIN_ID,
            $text,
            adminKeyboard()
        );
    }
}


/* ======================================================
   ADMIN USERS
====================================================== */

function adminUsersList(): void
{
    $all = users();

    $text =
        "• <b>Users</b>\n\n"
        . "Total Users: <b>" . count($all) . "</b>\n\n";

    $i = 0;

    foreach ($all as $user) {

        $id = (int)($user['id'] ?? 0);

        $name = trim(
            (string)($user['first_name'] ?? '')
            . ' '
            . (string)($user['last_name'] ?? '')
        );

        $username = (string)($user['username'] ?? '');

        $text .=
            "• <b>" . htmlspecialchars($name ?: 'No Name') . "</b>\n"
            . "• <code>{$id}</code>\n"
            . "• @" . htmlspecialchars($username ?: 'none') . "\n"
            . "• " . formatMoney((float)($user['balance'] ?? 0)) . "•\n\n";

        $i++;

        if ($i >= 30) {
            break;
        }
    }

    sendMessage(
        ADMIN_ID,
        $text,
        adminKeyboard()
    );
}


/* ======================================================
   BROADCAST
====================================================== */

function broadcastToUsers(string $message): int
{
    $sent = 0;

    foreach (users() as $user) {

        $id = (int)($user['id'] ?? 0);

        if ($id <= 0) {
            continue;
        }

        $result = sendMessage($id, $message);

        if (($result['ok'] ?? false) === true) {
            $sent++;
        }
    }

    return $sent;
}



/* ======================================================
   ADMIN MANAGEMENT
====================================================== */
function adminLog(string $action, int $target = 0, string $details = ''): void
{
    $logs = loadJson(LOGS_FILE, []);
    $logs[] = ['time'=>date('Y-m-d H:i:s'),'admin'=>ADMIN_ID,'action'=>$action,'target'=>$target,'details'=>$details];
    if (count($logs) > 1000) $logs = array_slice($logs, -1000);
    saveJson(LOGS_FILE, $logs);
}

function isUserBlocked(int $userId): bool
{
    $u = getUser($userId);
    return (bool)($u['blocked'] ?? false);
}

function setUserBlocked(int $userId, bool $blocked): bool
{
    $all = users(); $key=(string)$userId;
    if (!isset($all[$key])) return false;
    $all[$key]['blocked']=$blocked;
    return saveUsers($all);
}

function adminUserSummary(int $id): string
{
    $u=getUser($id);
    if(!$u) return '❌ User not found.';
    $name=htmlspecialchars(trim(($u['first_name']??'').' '.($u['last_name']??'')) ?: 'Unknown');
    $username=htmlspecialchars((string)($u['username']??''));
    return "👤 <b>User</b>\n\nID: <code>{$id}</code>\nName: <b>{$name}</b>\nUsername: @".($username?:'none')."\n💰 Balance: <b>".formatMoney((float)($u['balance']??0))."৳</b>\n🚫 Blocked: <b>".(($u['blocked']??false)?'YES':'NO')."</b>\n🎁 Referrals: <b>".getReferralCount($id)."</b>";
}

function settingStatus(array $settings, string $key): string
{
    return !empty($settings[$key]) ? 'ON' : 'OFF';
}

function settingButton(array $settings, string $key, string $label): array
{
    return [
        'text' => $label . ' ' . settingStatus($settings, $key),
        'callback_data' => 'setting:' . $key
    ];
}

function adminSettingsText(): string
{
    $st = settings();

    return "⚙️ <b>Bot Settings</b>\n\n"
        . "🛒 Orders: <b>" . settingStatus($st, 'orders_enabled') . "</b>\n"
        . "💳 Deposits: <b>" . settingStatus($st, 'deposits_enabled') . "</b>\n"
        . "🎁 Referral: <b>" . settingStatus($st, 'referral_enabled') . "</b>\n"
        . "📢 Broadcast: <b>" . settingStatus($st, 'broadcast_enabled') . "</b>\n"
        . "🛠 Maintenance: <b>" . settingStatus($st, 'maintenance') . "</b>";
}

function adminSettingsKeyboard(): array
{
    $st = settings();

    return ['inline_keyboard' => [
        [
            settingButton($st, 'orders_enabled', '🛒 Orders'),
            settingButton($st, 'deposits_enabled', '💳 Deposits')
        ],
        [
            settingButton($st, 'referral_enabled', '🎁 Referral'),
            settingButton($st, 'broadcast_enabled', '📢 Broadcast')
        ],
        [
            settingButton($st, 'maintenance', '🛠 Maintenance')
        ],
        [
            ['text' => '🔄 Refresh', 'callback_data' => 'settings:list']
        ]
    ]];
}

function adminShowSettings(): void
{
    sendMessage(ADMIN_ID,adminSettingsText(),adminSettingsKeyboard());
}

function adminShowUser(int $id): void
{
    $u=getUser($id);
    if(!$u){sendMessage(ADMIN_ID,'❌ User not found.',adminKeyboard());return;}
    $blocked=!empty($u['blocked']);
    sendMessage(ADMIN_ID,adminUserSummary($id),['inline_keyboard'=>[
        [['text'=>'💰 Add Balance','callback_data'=>'useract:add:'.$id],['text'=>'➖ Deduct','callback_data'=>'useract:deduct:'.$id]],
        [['text'=>$blocked?'✅ Unblock':'🚫 Block','callback_data'=>'useract:block:'.$id]],
        [['text'=>'📨 Message','callback_data'=>'useract:msg:'.$id]]
    ]]);
}

function adminRecentLogs(): void
{
    $logs=loadJson(LOGS_FILE,[]); $logs=array_slice($logs,-20);
    if(!$logs){sendMessage(ADMIN_ID,'📜 No admin logs yet.',adminKeyboard());return;}
    $out="📜 <b>Recent Admin Logs</b>\n\n";
    foreach(array_reverse($logs) as $l){
        $out.='🕒 '.htmlspecialchars((string)$l['time'])."\n".
              '• '.htmlspecialchars((string)$l['action'])." | User: <code>".(int)($l['target']??0)."</code>\n".
              htmlspecialchars((string)($l['details']??''))."\n\n";
    }
    sendMessage(ADMIN_ID,$out,adminKeyboard());
}

/* ======================================================
   SUPPORT
====================================================== */

function supportKeyboard(): array
{
    return [
        'inline_keyboard' => [
            [
                [
                    'text' => '• Contact Admin',
                    'callback_data' => 'support:contact'
                ]
            ]
        ]
    ];
}


function sendSupportToAdmin(
    int $userId,
    array $message
): void {

    $user = getUser($userId);

    $name = htmlspecialchars(
        trim(
            (string)($user['first_name'] ?? '')
            . ' '
            . (string)($user['last_name'] ?? '')
        )
    );

    $username = htmlspecialchars(
        (string)($user['username'] ?? '')
    );

    $header =
        "• <b>Support Message</b>\n\n"
        . "• {$name}\n"
        . "• <code>{$userId}</code>\n"
        . "• @" . ($username ?: 'none') . "\n\n";

    $replyKeyboard = [
        'inline_keyboard' => [
            [
                [
                    'text' => '•• Reply',
                    'callback_data' => 'support:reply:' . $userId
                ]
            ]
        ]
    ];

    if (isset($message['document'])) {

        $doc = $message['document'];

        api('sendDocument', [
            'chat_id' => ADMIN_ID,
            'document' => $doc['file_id'],
            'caption' => $header . "• Document",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(
                $replyKeyboard,
                JSON_UNESCAPED_UNICODE
            )
        ]);

        return;
    }

    if (isset($message['photo'])) {

        $photos = $message['photo'];

        $photo = end($photos);

        api('sendPhoto', [
            'chat_id' => ADMIN_ID,
            'photo' => $photo['file_id'],
            'caption' => $header . "• Photo",
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(
                $replyKeyboard,
                JSON_UNESCAPED_UNICODE
            )
        ]);

        return;
    }

    $text = (string)($message['text'] ?? '');

    sendMessage(
        ADMIN_ID,
        $header . htmlspecialchars($text),
        $replyKeyboard
    );
}


/* ======================================================
   START / MENU
====================================================== */

function showStart(int $userId): void
{
    $user = getUser($userId);

    $name = htmlspecialchars(
        (string)($user['first_name'] ?? 'User')
    );

    sendMessage(
        $userId,
        "• <b>Welcome {$name}!</b>\n\n"
        . "• Digital Product Shop\n\n"
        . "••••• Menu •••• •••• option •••••••• •••••",
        isAdmin($userId)
            ? adminKeyboard()
            : userKeyboard()
    );
}


/* ======================================================
   DEPOSIT FLOW
====================================================== */

function startDeposit(int $userId): void
{
    setState($userId, 'deposit_method');

    sendMessage(
        $userId,
        "• <b>Deposit</b>\n\n"
        . "Payment Number:\n"
        . "<code>" . PAYMENT_NUMBER . "</code>\n\n"
        . "Payment •••• •• Method •••••••• •••••",
        [
            'inline_keyboard' => [
                [
                    [
                        'text' => '• bKash',
                        'callback_data' => 'deposit:bkash'
                    ],
                    [
                        'text' => '• Nagad',
                        'callback_data' => 'deposit:nagad'
                    ]
                ],
                [
                    [
                        'text' => '❌ Cancel Deposit',
                        'callback_data' => 'deposit:cancel'
                    ]
                ]
            ]
        ]
    );
}


/* ======================================================
   MAIN UPDATE
====================================================== */

$raw = file_get_contents('php://input');

if ($raw === false || trim($raw) === '') {
    http_response_code(200);
    exit;
}

$update = json_decode($raw, true);

if (!is_array($update)) {
    http_response_code(200);
    exit;
}

// Telegram may retry webhook deliveries. Ignore an already-processed update
// so orders, deposits and balance changes are not accidentally duplicated.
if (isDuplicateUpdate($update)) {
    http_response_code(200);
    exit;
}


/* ======================================================
   CALLBACK QUERY
====================================================== */

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $callbackId = (string)($callback['id'] ?? '');
    $cb = (string)($callback['data'] ?? '');
    $from = $callback['from'] ?? [];
    $userId = (int)($from['id'] ?? 0);
    if ($userId <= 0) { http_response_code(200); exit; }

    registerUser($from);
    if (!isAdmin($userId) && isUserBlocked($userId)) { answerCallback($callbackId, 'Your account is blocked.'); sendMessage($userId, '🚫 আপনার account বর্তমানে blocked।'); http_response_code(200); exit; }
    if (!isAdmin($userId) && !empty(settings()['maintenance'])) { answerCallback($callbackId, 'Maintenance mode'); sendMessage($userId, '🛠 Bot বর্তমানে maintenance mode-এ আছে।'); http_response_code(200); exit; }
    answerCallback($callbackId);

    /* USER: 2FA refresh */
    if ($cb === '2fa:again') {
        sendMessage(
            $userId,
            "🔐 <b>2FA Code</b>\n\n"
            . "আপনার 2FA Secret Key আবার পাঠান।\n"
            . "উদাহরণ: <code>JBSWY3DPEHPK3PXP</code>"
        );
        setState($userId, 'user_2fa_key');
        http_response_code(200);
        exit;
    }

    /* USER: category menu */
    if ($cb === 'buycat:mail' || $cb === 'buycat:vpn') {
        $category = substr($cb, 7);
        $rows = [];
        foreach (catalogProducts() as $p) {
            if (!($p['active'] ?? true) || catalogCategory($p) !== $category) continue;
            $id = (int)$p['id'];
            $stock = stockCount($id);
            $name = catalogName($p);
            $price = catalogPrice($p);
            $rows[] = [[
                'text' => ($category === 'mail' ? '📧 ' : '🔐 ')
                    . $name . " | {$stock} pcs | " . formatMoney($price) . "৳",
                'callback_data' => "cat:{$id}"
            ]];
        }
        if (!$rows) {
            sendMessage($userId, "❌ এই category-তে কোনো product নেই।");
        } else {
            sendMessage($userId,
                ($category === 'mail' ? "📧 <b>Mail Products</b>" : "🔐 <b>VPN Products</b>")
                . "\n\nProduct নির্বাচন করুন:",
                ['inline_keyboard' => $rows]
            );
        }
        http_response_code(200); exit;
    }

    /* USER: selected product */
    if (str_starts_with($cb, 'cat:')) {
        $catalogId = (int)substr($cb, 4);
        $p = getCatalogProduct($catalogId);
        if ($p === null) {
            sendMessage($userId, "❌ Product not found.");
        } else {
            $stock = stockCount($catalogId);
            $price = catalogPrice($p);
            sendMessage($userId,
                "🛒 <b>" . htmlspecialchars(catalogName($p)) . "</b>\n\n"
                . "📦 Stock: <b>{$stock}</b>\n"
                . "💰 Price: <b>" . formatMoney($price) . "৳ / pcs</b>\n\n"
                . "Quantity নির্বাচন করুন:",
                buyKeyboard($catalogId)
            );
        }
        http_response_code(200); exit;
    }

    /* USER: quantity */
    if (str_starts_with($cb, 'qty:')) {
        $parts = explode(':', $cb, 3);
        $catalogId = (int)($parts[1] ?? 0);
        $value = $parts[2] ?? '';
        if ($value === 'custom') {
            setState($userId, 'custom_quantity', ['catalog_id' => $catalogId]);
            sendMessage($userId, "🔢 <b>Custom Quantity</b>\n\nকতটি কিনবেন লিখুন। উদাহরণ: <code>25</code>");
        } else {
            $quantity = (int)$value;
            $selectedProduct = getCatalogProduct($catalogId);
            if ($selectedProduct !== null && catalogCategory($selectedProduct) === 'vpn') {
                createVpnOrder($userId, $quantity, $catalogId);
            } else {
                deliverProducts($userId, $quantity, $catalogId);
            }
        }
        http_response_code(200); exit;
    }

    /* ADMIN: add product category */
    if ($cb === 'admincat:mail' || $cb === 'admincat:vpn') {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $category = substr($cb, 9);
        if ($category === 'mail') {
            sendMessage(ADMIN_ID, "📧 <b>Mail Product</b>\n\nএকটি product নির্বাচন করুন:", [
                'inline_keyboard' => [
                    [['text'=>'Hotmail','callback_data'=>'adminmail:Hotmail']],
                    [['text'=>'Outlook','callback_data'=>'adminmail:Outlook']],
                    [['text'=>'Outlook FR','callback_data'=>'adminmail:Outlook FR']],
                    [['text'=>'➕ Custom Mail Product','callback_data'=>'adminmail:custom']]
                ]
            ]);
        } else {
            $rows = [
                [['text'=>'➕ New VPN Product','callback_data'=>'adminvpn:new']]
            ];
            foreach (catalogProducts() as $vp) {
                if (!($vp['active'] ?? true) || catalogCategory($vp) !== 'vpn') continue;
                $vid = (int)$vp['id'];
                $rows[] = [['text'=>'📦 '.catalogName($vp).' | Stock '.stockCount($vid),'callback_data'=>'vpnstockadd:'.$vid]];
            }
            sendMessage(ADMIN_ID,
                "🔐 <b>VPN Stock</b>\n\n"
                . "New product তৈরি করতে <b>New VPN Product</b> চাপুন।\n"
                . "আগের product-এ stock বাড়াতে product নির্বাচন করুন।",
                ['inline_keyboard'=>$rows]
            );
        }
        http_response_code(200); exit;
    }

    /* ADMIN: VPN stock management */
    if ($cb === 'adminvpn:new') {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        setState(ADMIN_ID, 'admin_vpn_name');
        sendMessage(ADMIN_ID, "🔐 <b>New VPN Product</b>\n\nProduct name লিখুন।\nউদাহরণ: <code>Turbo VPN 7 Day</code>");
        http_response_code(200); exit;
    }

    if (str_starts_with($cb, 'vpnstockadd:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $id = (int)substr($cb, 12);
        $p = getCatalogProduct($id);
        if ($p === null || catalogCategory($p) !== 'vpn') {
            sendMessage(ADMIN_ID, "❌ VPN product not found.");
        } else {
            setState(ADMIN_ID, 'admin_vpn_stock_add', ['catalog_id'=>$id]);
            sendMessage(ADMIN_ID,
                "📦 <b>New Stock Add</b>\n\n"
                . "Product: <b>" . htmlspecialchars(catalogName($p)) . "</b>\n"
                . "Current Stock: <b>" . stockCount($id) . "</b> pcs\n\n"
                . "কত পিস নতুন stock add করবেন?\n"
                . "উদাহরণ: <code>10</code>"
            );
        }
        http_response_code(200); exit;
    }

    /* ADMIN: mail product selection */
    if (str_starts_with($cb, 'adminmail:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $name = substr($cb, 10);
        if ($name === 'custom') {
            setState(ADMIN_ID, 'admin_mail_name');
            sendMessage(ADMIN_ID, "📧 Custom Mail Product-এর নাম লিখুন।");
        } else {
            setState(ADMIN_ID, 'admin_product_price', ['category'=>'mail','name'=>$name]);
            sendMessage(ADMIN_ID, "💰 <b>{$name}</b>-এর প্রতি পিস Price লিখুন।\nউদাহরণ: <code>1.50</code>");
        }
        http_response_code(200); exit;
    }

    /* ADMIN: update price selection */
    if ($cb === 'adminprice:list') {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $rows = [];
        foreach (catalogProducts() as $p) {
            if (!($p['active'] ?? true)) continue;
            $id=(int)$p['id'];
            $rows[]=[['text'=>catalogName($p)." | ".formatMoney(catalogPrice($p))."৳",'callback_data'=>"adminprice:{$id}"]];
        }
        sendMessage(ADMIN_ID, "💲 কোন product-এর price update করবেন?", ['inline_keyboard'=>$rows]);
        http_response_code(200); exit;
    }

    if (str_starts_with($cb, 'adminprice:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $id=(int)substr($cb,11);
        $p=getCatalogProduct($id);
        if ($p===null) { sendMessage(ADMIN_ID,"❌ Product not found."); }
        else {
            setState(ADMIN_ID,'admin_update_price',['catalog_id'=>$id]);
            sendMessage(ADMIN_ID,"💲 <b>".htmlspecialchars(catalogName($p))."</b>\nনতুন price লিখুন।");
        }
        http_response_code(200); exit;
    }

    /* ADMIN: delete product */
    if ($cb === 'admindelete:list') {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $rows=[];
        foreach(catalogProducts() as $p){
            if (!($p['active']??true)) continue;
            $id=(int)$p['id'];
            $rows[]=[['text'=>catalogName($p)." | Stock ".stockCount($id),'callback_data'=>"admindelete:{$id}"]];
        }
        sendMessage(ADMIN_ID,"🗑 কোন product delete করবেন?",['inline_keyboard'=>$rows]);
        http_response_code(200); exit;
    }

    if (str_starts_with($cb,'admindelete:')) {
        if(!isAdmin($userId)){http_response_code(200);exit;}
        $id=(int)substr($cb,12);
        $catalog=catalogProducts();
        foreach($catalog as $k=>$p){
            if((int)($p['id']??0)===$id){
                $catalog[$k]['active']=false;
                saveCatalogProducts($catalog);
                sendMessage(ADMIN_ID,"✅ Product <b>".htmlspecialchars(catalogName($p))."</b> inactive করা হয়েছে।\nSold history রাখা হয়েছে।",adminKeyboard());
                http_response_code(200);exit;
            }
        }
        sendMessage(ADMIN_ID,"❌ Product not found.");
        http_response_code(200);exit;
    }

    /* VPN ORDER ADMIN ACTIONS */
    if (str_starts_with($cb, 'vpnorder:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $parts = explode(':', $cb, 3);
        $action = $parts[1] ?? '';
        $orderId = $parts[2] ?? '';
        if ($action === 'deliver') {
            deliverVpnOrder($orderId);
        } elseif ($action === 'cancel') {
            cancelVpnOrder($orderId);
        }
        http_response_code(200); exit;
    }

    /* ADMIN: PREMIUM EMOJI */
    if ($cb === 'pem:list') {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        adminShowPremiumEmoji(); http_response_code(200); exit;
    }
    if ($cb === 'pem:resetall') {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $st=settings(); $st['premium_emojis']=[]; saveSettings($st); clearState(ADMIN_ID);
        adminLog('PREMIUM_EMOJI_RESET_ALL');
        sendMessage(ADMIN_ID,'🧹 <b>সব Premium Emoji Reset করা হয়েছে.</b>',premiumEmojiKeyboard());
        http_response_code(200); exit;
    }
    if (str_starts_with($cb,'pem:set:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $key=substr($cb,8); $defs=premiumEmojiDefinitions();
        if(!isset($defs[$key])){http_response_code(200);exit;}
        setState(ADMIN_ID,'admin_premium_emoji',['key'=>$key]);
        sendMessage(ADMIN_ID,"👑 <b>".htmlspecialchars($defs[$key][1],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')."</b>\n\nএকটি <b>Telegram Premium/Custom Emoji</b> পাঠান।\nসাধারণ emoji পাঠালে save হবে না।\n\n↩️ Cancel: <code>/cancel</code>");
        http_response_code(200); exit;
    }

    /* ADMIN: SETTINGS */
    if ($cb === 'settings:list') {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        adminShowSettings(); http_response_code(200); exit;
    }
    if (str_starts_with($cb, 'setting:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $key=substr($cb,8);
        $allowed=['orders_enabled','deposits_enabled','referral_enabled','broadcast_enabled','maintenance'];
        if(in_array($key,$allowed,true)){
            $st=settings(); $st[$key]=empty($st[$key]); saveSettings($st);
            adminLog('SETTING_TOGGLE',0,$key.'='.($st[$key]?'ON':'OFF'));
        }
        adminShowSettings(); http_response_code(200); exit;
    }

    /* ADMIN: USER ACTIONS */
    if (str_starts_with($cb, 'useract:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $parts=explode(':',$cb,3); $action=$parts[1]??''; $target=(int)($parts[2]??0);
        if(!$target || !getUser($target)){sendMessage(ADMIN_ID,'❌ User not found.');http_response_code(200);exit;}
        if($action==='block'){
            $blocked=!isUserBlocked($target); setUserBlocked($target,$blocked); adminLog($blocked?'BLOCK_USER':'UNBLOCK_USER',$target);
            sendMessage($target,$blocked?'🚫 আপনার account blocked করা হয়েছে।':'✅ আপনার account unblock করা হয়েছে।'); adminShowUser($target);
        } elseif($action==='add' || $action==='deduct'){
            setState(ADMIN_ID,'admin_user_balance',['target'=>$target,'mode'=>$action]);
            sendMessage(ADMIN_ID,($action==='add'?'💰':'➖')." Amount লিখুন।\nUser: <code>{$target}</code>\nউদাহরণ: <code>50</code>");
        } elseif($action==='msg'){
            setState(ADMIN_ID,'admin_user_message',['target'=>$target]); sendMessage(ADMIN_ID,"📨 User <code>{$target}</code>-এর জন্য message লিখুন।");
        }
        http_response_code(200); exit;
    }

    /* DEPOSIT CANCEL */
    if ($cb === 'deposit:cancel') {
        clearState($userId);
        sendMessage(
            $userId,
            "❌ <b>Deposit Cancelled</b>\n\nআপনি Deposit process থেকে বের হয়ে গেছেন।",
            userKeyboard()
        );
        http_response_code(200);
        exit;
    }

    /* DEPOSIT METHOD — ONLY bKash / Nagad
       Keep callback_data fixed and normalize harmless formatting so
       premium/custom emoji in the visible button text can never break it. */
    if (str_starts_with($cb, 'deposit:')) {
        $rawMethod = substr($cb, 8);
        $methodKey = strtolower(preg_replace('/[^a-z]/i', '', (string)$rawMethod));

        if ($methodKey === 'bkash') {
            $method = 'BKASH';
            $label = 'bKash';
        } elseif ($methodKey === 'nagad') {
            $method = 'NAGAD';
            $label = 'Nagad';
        } else {
            sendMessage($userId, "❌ <b>Invalid Deposit Method</b>\nশুধু bKash অথবা Nagad দিয়ে Deposit করা যাবে।");
            http_response_code(200);
            exit;
        }

        setState($userId, 'deposit_amount', ['method' => $method]);

        sendMessage(
            $userId,
            "💳 <b>{$label} Deposit</b>\n\n"
            . "Deposit amount লিখুন।\n"
            . "Minimum: <b>" . MIN_DEPOSIT . "৳</b>\n\n"
            . "📌 Amount দেওয়ার পর শুধু Transaction ID / TXID দিতে হবে।",
            [
                'keyboard' => [
                    [['text' => '❌ Cancel Deposit']]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ]
        );

        http_response_code(200);
        exit;
    }

    /* DEPOSIT APPROVE / REJECT */
    if (str_starts_with($cb, 'dep:')) {
        if (!isAdmin($userId)) { http_response_code(200); exit; }
        $parts=explode(':',$cb);
        $action=$parts[1]??''; $depositId=$parts[2]??'';
        $all=deposits();
        if(!isset($all[$depositId])){sendMessage(ADMIN_ID,"❌ Deposit not found.");http_response_code(200);exit;}
        $deposit=$all[$depositId];
        if(($deposit['status']??'')!=='pending'){sendMessage(ADMIN_ID,"⚠️ Deposit already processed.");http_response_code(200);exit;}
        $target=(int)$deposit['user_id'];
        if($action==='approve'){
            $amount=(float)$deposit['amount'];
            if(changeBalance($target,$amount)){
                $all[$depositId]['status']='approved';
                $all[$depositId]['approved_at']=date('Y-m-d H:i:s');
                saveDeposits($all);
                sendMessage($target,"✅ <b>Deposit Approved!</b>\n\nAmount: <b>".formatMoney($amount)."৳</b>\nBalance updated.");
                sendMessage(ADMIN_ID,"✅ Deposit Approved\nUser: <code>{$target}</code>\nAmount: ".formatMoney($amount)."৳",adminKeyboard());
            } else sendMessage(ADMIN_ID,"❌ Balance update failed.");
        } elseif($action==='reject'){
            $all[$depositId]['status']='rejected'; saveDeposits($all);
            sendMessage($target,"❌ <b>Deposit Rejected</b>\nDeposit: <code>{$depositId}</code>");
            sendMessage(ADMIN_ID,"❌ Deposit Rejected\nUser: <code>{$target}</code>",adminKeyboard());
        }
        http_response_code(200); exit;
    }

    /* SUPPORT */
    if ($cb === 'support:contact') {
        setState($userId,'support_message');
        sendMessage($userId,"🆘 Support Message পাঠান।");
        http_response_code(200); exit;
    }

    if (str_starts_with($cb,'support:reply:')) {
        if(!isAdmin($userId)){http_response_code(200);exit;}
        $target=(int)substr($cb,15);
        setState(ADMIN_ID,'admin_support_reply',['user_id'=>$target]);
        sendMessage(ADMIN_ID,"👤 User <code>{$target}</code>-কে reply পাঠান।");
        http_response_code(200);exit;
    }

    http_response_code(200); exit;
}

/* ======================================================
   MESSAGE
====================================================== */

$message = $update['message'] ?? null;

if (!is_array($message)) {
    http_response_code(200);
    exit;
}

$from = $message['from'] ?? [];

$userId = (int)($from['id'] ?? 0);

$chatId = (int)($message['chat']['id'] ?? $userId);

if ($userId <= 0) {
    http_response_code(200);
    exit;
}

registerUser($from);
if (!isAdmin($userId) && isUserBlocked($userId)) { sendMessage($userId,'🚫 আপনার account বর্তমানে blocked।'); http_response_code(200); exit; }
if (!isAdmin($userId) && !empty(settings()['maintenance'])) { sendMessage($userId,'🛠 Bot বর্তমানে maintenance mode-এ আছে।'); http_response_code(200); exit; }


/* ======================================================
   /START
====================================================== */

$text = trim((string)($message['text'] ?? ''));

if (str_starts_with($text, '/start')) {

    $parts = preg_split(
        '/\s+/',
        $text
    );

    if (
        isset($parts[1])
        && ctype_digit($parts[1])
    ) {

        $refId = (int)$parts[1];

        processReferral(
            $userId,
            $refId
        );
    }

    clearState($userId);

    showStart($userId);

    http_response_code(200);
    exit;
}


/* ======================================================
   ADMIN COMMANDS
====================================================== */

if (isAdmin($userId)) {

    /* /addbalance */

    if (preg_match(
        '/^\/addbalance\s+(\d+)\s+([0-9]+(?:\.[0-9]+)?)$/i',
        $text,
        $m
    )) {

        $target = (int)$m[1];
        $amount = (float)$m[2];

        if (!getUser($target)) {

            sendMessage(
                ADMIN_ID,
                "• User not found."
            );

        } elseif ($amount <= 0) {

            sendMessage(
                ADMIN_ID,
                "• Invalid amount."
            );

        } else {

            changeBalance(
                $target,
                $amount
            );

            sendMessage(
                ADMIN_ID,
                "• Balance Added\n\n"
                . "• User: <code>{$target}</code>\n"
                . "• Amount: "
                . formatMoney($amount)
                . "•"
            );

            sendMessage(
                $target,
                "• <b>Balance Added</b>\n\n"
                . "• Amount: <b>"
                . formatMoney($amount)
                . "•</b>\n\n"
                . "Admin ••••• Balance update •••••••"
            );
        }

        http_response_code(200);
        exit;
    }


    /* /deductbalance */

    if (preg_match(
        '/^\/deductbalance\s+(\d+)\s+([0-9]+(?:\.[0-9]+)?)$/i',
        $text,
        $m
    )) {

        $target = (int)$m[1];
        $amount = (float)$m[2];

        $targetUser = getUser($target);

        if (!$targetUser) {

            sendMessage(
                ADMIN_ID,
                "• User not found."
            );

        } elseif (
            (float)$targetUser['balance'] < $amount
        ) {

            sendMessage(
                ADMIN_ID,
                "• User-•• Balance •••"
            );

        } else {

            changeBalance(
                $target,
                -$amount
            );

            sendMessage(
                ADMIN_ID,
                "• Balance Deducted\n\n"
                . "• User: <code>{$target}</code>\n"
                . "• Amount: "
                . formatMoney($amount)
                . "•"
            );

            sendMessage(
                $target,
                "• <b>Balance Deducted</b>\n\n"
                . "Amount: <b>"
                . formatMoney($amount)
                . "•</b>"
            );
        }

        http_response_code(200);
        exit;
    }
}


/* ======================================================
   STATE HANDLING
====================================================== */

$stateData = getState($userId);

if ($stateData) {

    $state = $stateData['state'] ?? '';
    $data = $stateData['data'] ?? [];

    /* USER DEPOSIT CANCEL — works at Method / Amount / TXID stages */
    if (
        in_array($state, ['deposit_method', 'deposit_amount', 'deposit_trxid'], true)
        && in_array(mb_strtolower(trim($text)), ['❌ cancel deposit', 'cancel deposit', '/cancel'], true)
    ) {
        clearState($userId);
        sendMessage(
            $userId,
            "❌ <b>Deposit Cancelled</b>\n\nআপনি Deposit process থেকে বের হয়ে গেছেন।",
            userKeyboard()
        );
        http_response_code(200);
        exit;
    }

    /* ADMIN PREMIUM EMOJI STATE */
    if ($state === 'admin_premium_emoji' && isAdmin($userId)) {
        if (trim($text)==='/cancel') { clearState(ADMIN_ID); adminShowPremiumEmoji(); http_response_code(200); exit; }
        $key=(string)($data['key']??'');
        $id=extractCustomEmojiId($message);
        if($id===''){
            sendMessage(ADMIN_ID,'❌ <b>Premium Emoji পাওয়া যায়নি.</b>\n\nএকটি Telegram Premium/Custom Emoji সরাসরি পাঠান।');
            http_response_code(200); exit;
        }
        if(!savePremiumEmoji($key,$id)){
            sendMessage(ADMIN_ID,'❌ Premium Emoji save করা যায়নি। আবার চেষ্টা করুন।');
            http_response_code(200); exit;
        }
        clearState(ADMIN_ID); adminLog('PREMIUM_EMOJI_SET',0,$key.'='.$id);
        sendMessage(ADMIN_ID,'✅ <b>Premium Emoji Set Successfully!</b>\n\nSlot: <code>'.htmlspecialchars($key,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</code>\nএখন থেকে এই slot-এর emoji message/text-এ Premium Emoji হিসেবে দেখাবে.',premiumEmojiKeyboard());
        http_response_code(200); exit;
    }

    /* USER 2FA STATE - must run before all other user states */
    if ($state === 'user_2fa_key') {
        $secret = normalizeTotpSecret($text);

        if ($secret === '') {
            sendMessage(
                $userId,
                "❌ <b>2FA Key পাওয়া যায়নি</b>\n\n"
                . "আপনার 2FA Secret Key পাঠান।\n"
                . "উদাহরণ: <code>JBSWY3DPEHPK3PXP</code>"
            );
            http_response_code(200);
            exit;
        }

        $code = generateTotpCode($secret);

        if ($code === false) {
            sendMessage(
                $userId,
                "❌ <b>2FA Key ভুল</b>\n\n"
                . "সঠিক Base32 Secret Key পাঠান।\n"
                . "উদাহরণ: <code>JBSWY3DPEHPK3PXP</code>"
            );
            http_response_code(200);
            exit;
        }

        // Secret is only used in memory for this request and then discarded.
        clearState($userId);
        $remaining = totpRemainingSeconds();

        sendMessage(
            $userId,
            "🔐 <b>2FA Code</b>\n\n"
            . "🔢 Code: <code>{$code}</code>\n"
            . "⏳ Valid: <b>{$remaining} seconds</b>\n\n"
            . "🔒 2FA Key permanent database-এ save করা হয়নি।",
            [
                'inline_keyboard' => [
                    [['text' => '🔄 Generate Again', 'callback_data' => '2fa:again']]
                ]
            ]
        );

        http_response_code(200);
        exit;
    }

    /* CUSTOM QUANTITY */
    if ($state === 'custom_quantity') {
        if (!ctype_digit($text) || (int)$text <= 0) {
            sendMessage($userId, "❌ Valid quantity দিন। উদাহরণ: <code>25</code>");
            http_response_code(200); exit;
        }
        $quantity=(int)$text;
        $catalogId=(int)($data['catalog_id']??0);
        clearState($userId);
        $selectedProduct = getCatalogProduct($catalogId);
        if ($selectedProduct !== null && catalogCategory($selectedProduct) === 'vpn') {
            createVpnOrder($userId, $quantity, $catalogId);
        } else {
            deliverProducts($userId,$quantity,$catalogId);
        }
        http_response_code(200); exit;
    }


    /* DEPOSIT AMOUNT */

    if ($state === 'deposit_amount') {

        if (
            !is_numeric($text)
            || (float)$text < MIN_DEPOSIT
        ) {
            sendMessage(
                $userId,
                "❌ Minimum Deposit " . MIN_DEPOSIT . "৳"
            );

            http_response_code(200);
            exit;
        }

        $amount = (float)$text;

        setState(
            $userId,
            'deposit_trxid',
            [
                'method' => $data['method'] ?? '',
                'amount' => $amount
            ]
        );

        sendMessage(
            $userId,
            "💵 <b>Amount: " . formatMoney($amount) . "৳</b>

"
            . "🧾 এখন আপনার <b>Transaction ID / TXID</b> পাঠান।

"
            . "📌 <b>Screenshot লাগবে না।</b>
"
            . "শুধু TXID পাঠালেই হবে।",
            [
                'keyboard' => [
                    [['text' => '❌ Cancel Deposit']]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ]
        );

        http_response_code(200);
        exit;
    }



    /* DEPOSIT TXID — ONLY TEXT TXID, ONLY bKash / Nagad */
    if ($state === 'deposit_trxid') {
        if (isset($message['photo']) || isset($message['document']) || isset($message['video']) ||
            isset($message['audio']) || isset($message['voice']) || isset($message['animation']) ||
            !isset($message['text'])) {
            sendMessage($userId, "❌ <b>শুধু Transaction ID / TXID পাঠান।</b>

Screenshot, Photo, Document, Video বা অন্য কোনো file গ্রহণ করা হবে না।");
            http_response_code(200); exit;
        }
        $trxId = trim((string)$text);
        if ($trxId === '' || mb_strlen($trxId) < 6 || mb_strlen($trxId) > 40 || !preg_match('/^[A-Za-z0-9_-]+$/', $trxId)) {
            sendMessage($userId, "❌ <b>Invalid Transaction ID / TXID</b>

শুধু সঠিক bKash/Nagad TXID পাঠান।");
            http_response_code(200); exit;
        }
        $method = strtoupper((string)($data['method'] ?? ''));
        $amount = (float)($data['amount'] ?? 0);
        if (!in_array($method, ['BKASH', 'NAGAD'], true) || $amount < MIN_DEPOSIT) {
            clearState($userId);
            sendMessage($userId, "❌ <b>Deposit information invalid.</b>
আবার Deposit থেকে শুরু করুন।");
            http_response_code(200); exit;
        }
        if (isTransactionIdUsed($trxId)) {
            sendMessage(
                $userId,
                "❌ <b>এই Transaction ID আগে ব্যবহার করা হয়েছে।</b>\n\nএকটি TXID শুধু <b>একবার</b> ব্যবহার করা যাবে। নতুন/সঠিক TXID পাঠান।"
            );
            http_response_code(200); exit;
        }

        try {
            $depositId = createDeposit($userId, $method, $amount, $trxId, '');
        } catch (RuntimeException $e) {
            if ($e->getMessage() === 'DUPLICATE_TXID') {
                sendMessage(
                    $userId,
                    "❌ <b>এই Transaction ID আগে ব্যবহার করা হয়েছে।</b>\n\nএকটি TXID শুধু <b>একবার</b> ব্যবহার করা যাবে। নতুন/সঠিক TXID পাঠান।"
                );
                http_response_code(200); exit;
            }
            throw $e;
        }

        clearState($userId);

        sendMessage(
            $userId,
            "✅ <b>Deposit Request Submitted</b>

"
            . "🆔 Deposit: <code>{$depositId}</code>
"
            . "💳 Method: <b>"
            . htmlspecialchars((string)($data['method'] ?? ''))
            . "</b>
"
            . "💵 Amount: <b>"
            . formatMoney((float)($data['amount'] ?? 0))
            . "৳</b>
"
            . "🧾 TXID: <code>"
            . htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8')
            . "</code>

"
            . "⏳ Admin approval-এর জন্য অপেক্ষা করুন।"
        );

        $adminMessage =
            "💵 <b>New Deposit Request</b>

"
            . "🆔 Deposit: <code>{$depositId}</code>
"
            . "👤 User: <code>{$userId}</code>
"
            . "💳 Method: <b>"
            . htmlspecialchars((string)($data['method'] ?? ''))
            . "</b>
"
            . "💵 Amount: <b>"
            . formatMoney((float)($data['amount'] ?? 0))
            . "৳</b>
"
            . "🧾 TXID: <code>"
            . htmlspecialchars($trxId, ENT_QUOTES, 'UTF-8')
            . "</code>
"
            . "📅 Date: " . date('Y-m-d H:i:s');

        sendMessage(
            ADMIN_ID,
            $adminMessage,
            depositButtons($depositId)
        );

        http_response_code(200);
        exit;
    }

    /* SUPPORT MESSAGE */

    if ($state === 'support_message') {

        sendSupportToAdmin(
            $userId,
            $message
        );

        clearState($userId);

        sendMessage(
            $userId,
            "• ••••• message Admin-•• •••• •••••• ••••••\n\n"
            . "Admin reply •••• •••• message ••••••"
        );

        http_response_code(200);
        exit;
    }


    /* ADMIN SUPPORT REPLY */

    if (
        $state === 'admin_support_reply'
        && isAdmin($userId)
    ) {

        $targetUser = (int)($data['user_id'] ?? 0);

        if ($targetUser <= 0) {
            clearState(ADMIN_ID);

            http_response_code(200);
            exit;
        }

        if (isset($message['document'])) {

            api('sendDocument', [
                'chat_id' => $targetUser,
                'document' => $message['document']['file_id'],
                'caption' => "• <b>Admin Reply</b>",
                'parse_mode' => 'HTML'
            ]);

        } elseif (isset($message['photo'])) {

            $photos = $message['photo'];

            $photo = end($photos);

            api('sendPhoto', [
                'chat_id' => $targetUser,
                'photo' => $photo['file_id'],
                'caption' => "• <b>Admin Reply</b>",
                'parse_mode' => 'HTML'
            ]);

        } else {

            sendMessage(
                $targetUser,
                "• <b>Admin Reply</b>\n\n"
                . htmlspecialchars($text)
            );
        }

        clearState(ADMIN_ID);

        sendMessage(
            ADMIN_ID,
            "• Reply sent to User <code>{$targetUser}</code>.",
            adminKeyboard()
        );

        http_response_code(200);
        exit;
    }
}



/* ======================================================
   2FA BUTTON — MUST RUN BEFORE OTHER TEXT HANDLERS
====================================================== */
if (trim($text) === '🔐 2FA Code') {
    setState($userId, 'user_2fa_key');

    sendMessage(
        $userId,
        "🔐 <b>2FA Code Generator</b>\n\n"
        . "আপনার <b>2FA Secret Key</b> পাঠান।\n\n"
        . "উদাহরণ:\n"
        . "<code>JBSWY3DPEHPK3PXP</code>\n\n"
        . "Key পাঠানোর পর আমি আপনার 6-digit 2FA code তৈরি করে দেব।"
    );

    http_response_code(200);
    exit;
}

/* ======================================================
   2FA KEY STATE
====================================================== */
if ($state === 'user_2fa_key') {
    $secret = normalizeTotpSecret($text);

    if ($secret === '') {
        sendMessage(
            $userId,
            "❌ 2FA Key পাওয়া যায়নি।\n\n"
            . "আপনার Secret Key আবার পাঠান।"
        );
        http_response_code(200);
        exit;
    }

    $code = generateTotpCode($secret);

    if ($code === false) {
        sendMessage(
            $userId,
            "❌ <b>Invalid 2FA Key</b>\n\n"
            . "সঠিক Secret Key পাঠান।\n"
            . "উদাহরণ: <code>JBSWY3DPEHPK3PXP</code>"
        );
        http_response_code(200);
        exit;
    }

    clearState($userId);

    $remaining = totpRemainingSeconds();

    sendMessage(
        $userId,
        "🔐 <b>2FA Code</b>\n\n"
        . "🔢 Code: <code>{$code}</code>\n"
        . "⏳ Valid for: <b>{$remaining} seconds</b>\n\n"
        . "🔒 Secret Key permanent database-এ save করা হয়নি।",
        [
            'inline_keyboard' => [
                [['text' => '🔄 Generate Again', 'callback_data' => '2fa:again']]
            ]
        ]
    );

    http_response_code(200);
    exit;
}


/* ======================================================
   USER MENU
====================================================== */


/* BUY PRODUCT */

if ($text === '🛒 Buy Product') {
    $catalog = catalogProducts();
    $mailStock = 0; $vpnStock = 0;
    foreach ($catalog as $p) {
        if (!($p['active'] ?? true)) continue;
        if (catalogCategory($p)==='mail') $mailStock += stockCount((int)$p['id']);
        if (catalogCategory($p)==='vpn') $vpnStock += stockCount((int)$p['id']);
    }

    sendMessage($userId,
        "🛒 <b>Buy Product</b>\n\n"
        . "📧 Mail Stock: <b>{$mailStock}</b>\n"
        . "🔐 VPN Stock: <b>{$vpnStock}</b>\n\n"
        . "Category নির্বাচন করুন:",
        ['inline_keyboard'=>[
            [['text'=>"📧 Mail ({$mailStock})",'callback_data'=>'buycat:mail']],
            [['text'=>"🔐 VPN ({$vpnStock})",'callback_data'=>'buycat:vpn']]
        ]]
    );
    http_response_code(200); exit;
}


/* BALANCE */

if ($text === '💰 Balance') {

    $user = getUser($userId);

    $balance = (float)($user['balance'] ?? 0);

    sendMessage(
        $userId,
        "• <b>Your Balance</b>\n\n"
        . "• Balance: <b>"
        . formatMoney($balance)
        . "•</b>"
    );

    http_response_code(200);
    exit;
}


/* DEPOSIT */

if ($text === '💵 Deposit') {
    if (!isAdmin($userId) && empty(settings()['deposits_enabled'])) { sendMessage($userId,'⛔ Deposit বর্তমানে বন্ধ।'); http_response_code(200); exit; }

    startDeposit($userId);

    http_response_code(200);
    exit;
}


/* REFERRAL */

if ($text === '👥 Referral') {
    if (empty(settings()['referral_enabled']) && !isAdmin($userId)) { sendMessage($userId,'⛔ Referral system বর্তমানে বন্ধ।'); http_response_code(200); exit; }

    $count = getReferralCount($userId);

    $user = getUser($userId);

    $username = defined('BOT_USERNAME')
        ? (string)BOT_USERNAME
        : '';

    $link = '';

    if ($username !== '') {
        $link =
            "https://t.me/"
            . ltrim($username, '@')
            . "?start="
            . $userId;
    }

    $textReply =
        "• <b>Referral System</b>\n\n"
        . "• Referrals: <b>{$count}</b>\n"
        . "• Bonus/User: <b>"
        . formatMoney(REFERRAL_BONUS)
        . "•</b>\n\n";

    if ($link !== '') {

        $textReply .=
            "• Your Referral Link:\n"
            . "<code>{$link}</code>";
    }

    sendMessage(
        $userId,
        $textReply
    );

    http_response_code(200);
    exit;
}


/* DASHBOARD */

if ($text === '📊 Dashboard') {

    $user = getUser($userId);

    $balance = (float)($user['balance'] ?? 0);

    $myOrders = 0;

    foreach (orders() as $order) {

        if ((int)($order['user_id'] ?? 0) === $userId) {
            $myOrders++;
        }
    }

    sendMessage(
        $userId,
        "• <b>Dashboard</b>\n\n"
        . "• Balance: <b>"
        . formatMoney($balance)
        . "•</b>\n"
        . "• Orders: <b>{$myOrders}</b>\n"
        . "• Referrals: <b>"
        . getReferralCount($userId)
        . "</b>"
    );

    http_response_code(200);
    exit;
}


/* SUPPORT */

if ($text === '🆘 Support') {

    setState(
        $userId,
        'support_message'
    );

    sendMessage(
        $userId,
        "• <b>Support</b>\n\n"
        . "••••• •••••• ••••• •••• Document/Photo ••••••"
    );

    http_response_code(200);
    exit;
}


/* ======================================================
   ADMIN MENU
====================================================== */

if (isAdmin($userId)) {


    /* ADMIN: USER SEARCH */
    if ($text === '🔎 Find User') {
        setState(ADMIN_ID,'admin_find_user');
        sendMessage(ADMIN_ID,'🔎 User ID অথবা @username লিখুন।');
        http_response_code(200); exit;
    }
    if ($text === '👑 Premium Emoji') { adminShowPremiumEmoji(); http_response_code(200); exit; }

    if ($text === '⚙️ Bot Settings') { adminShowSettings(); http_response_code(200); exit; }
    if ($text === '📜 Admin Logs') { adminRecentLogs(); http_response_code(200); exit; }

/* ADD PRODUCT / STOCK */
    if ($text === '📦 Add Product/Stock') {
        setState(ADMIN_ID, 'admin_choose_category');
        sendMessage(ADMIN_ID, "📦 <b>Add Product / Stock</b>\n\nCategory নির্বাচন করুন:", [
            'inline_keyboard' => [
                [['text'=>'📧 Mail','callback_data'=>'admincat:mail']],
                [['text'=>'🔐 VPN','callback_data'=>'admincat:vpn']]
            ]
        ]);
        http_response_code(200); exit;
    }



    /* PRODUCTS / STOCK */

    if ($text === '📊 Products/Stock') {

        adminStockList();

        http_response_code(200);
        exit;
    }


    /* DEPOSITS */

    if ($text === '💵 Deposits') {

        adminDepositList();

        http_response_code(200);
        exit;
    }


    /* VPN ORDERS */
    if ($text === '📋 VPN Orders') {
        adminVpnOrders();
        http_response_code(200);
        exit;
    }


    /* USERS */

    if ($text === '👥 Users') {

        adminUsersList();

        http_response_code(200);
        exit;
    }


    /* USERS XLSX */

    if ($text === '📥 Users XLSX') {

        $xlsx = createUsersXlsx();

        if ($xlsx === false) {

            sendMessage(
                ADMIN_ID,
                "• XLSX •••• ••• ••••••"
            );

        } else {

            sendDocument(
                ADMIN_ID,
                $xlsx,
                "• All Users XLSX"
            );

            @unlink($xlsx);
        }

        http_response_code(200);
        exit;
    }


    /* ADD BALANCE */

    if ($text === '💰 Add Balance') {

        sendMessage(
            ADMIN_ID,
            "• <b>Add Balance</b>\n\n"
            . "Command ••••••• ••••:\n\n"
            . "<code>/addbalance USER_ID AMOUNT</code>\n\n"
            . "Example:\n"
            . "<code>/addbalance 123456789 50</code>"
        );

        http_response_code(200);
        exit;
    }


    /* DEDUCT BALANCE */

    if ($text === '➖ Deduct Balance') {

        sendMessage(
            ADMIN_ID,
            "• <b>Deduct Balance</b>\n\n"
            . "Command ••••••• ••••:\n\n"
            . "<code>/deductbalance USER_ID AMOUNT</code>\n\n"
            . "Example:\n"
            . "<code>/deductbalance 123456789 10</code>"
        );

        http_response_code(200);
        exit;
    }


    /* UPDATE PRICE */
    if ($text === '💲 Update Price') {
        sendMessage(ADMIN_ID, "💲 <b>Update Product Price</b>\n\nProduct নির্বাচন করুন:", [
            'inline_keyboard' => [
                [['text'=>'📋 Select Product','callback_data'=>'adminprice:list']]
            ]
        ]);
        http_response_code(200); exit;
    }



    /* STATISTICS */

    if ($text === '📈 Statistics') {

        adminStatistics();

        http_response_code(200);
        exit;
    }


    /* BROADCAST */

    if ($text === '📢 Broadcast') {
    if (empty(settings()['broadcast_enabled'])) { sendMessage(ADMIN_ID,'⛔ Broadcast disabled in settings.',adminKeyboard()); http_response_code(200); exit; }

        setState(
            ADMIN_ID,
            'admin_broadcast'
        );

        sendMessage(
            ADMIN_ID,
            "• •• Message •••••• •••••• ••• •••• ••••••"
        );

        http_response_code(200);
        exit;
    }


    /* DELETE PRODUCT */
    if ($text === '🗑 Delete Product') {
        sendMessage(ADMIN_ID, "🗑 <b>Delete Product</b>\n\nProduct নির্বাচন করুন:", [
            'inline_keyboard' => [
                [['text'=>'🗑 Select Product','callback_data'=>'admindelete:list']]
            ]
        ]);
        http_response_code(200); exit;
    }



    /* USER MENU */

    if ($text === '👤 User Menu') {

        sendMessage(
            ADMIN_ID,
            "• <b>User Menu</b>\n\n"
            . "User Balance manage ••••:\n\n"
            . "<code>/addbalance USER_ID AMOUNT</code>\n"
            . "<code>/deductbalance USER_ID AMOUNT</code>\n\n"
            . "••••••:\n"
            . "<code>/addbalance 123456789 100</code>",
            adminKeyboard()
        );

        http_response_code(200);
        exit;
    }


    /* ADMIN: MANUAL VPN DELIVERY */
    if ($state === 'admin_vpn_delivery' && isAdmin($userId)) {
        $orderId=(string)($data['order_id']??'');
        if ($text === '/cancel') {
            clearState(ADMIN_ID);
            sendMessage(ADMIN_ID,"❌ VPN delivery cancelled. Order এখনো pending থাকবে।",adminKeyboard());
            http_response_code(200); exit;
        }
        completeVpnDelivery($orderId,$message);
        http_response_code(200); exit;
    }

        /* ADMIN: USER SEARCH */
    if ($state === 'admin_find_user') {
        $q=trim($text); $found=0;
        if(ctype_digit($q)) $found=(int)$q;
        else { foreach(users() as $u){ if(strcasecmp(ltrim((string)($u['username']??''),'@'),ltrim($q,'@'))===0){$found=(int)$u['id'];break;} } }
        clearState(ADMIN_ID);
        if($found>0) adminShowUser($found); else sendMessage(ADMIN_ID,'❌ User পাওয়া যায়নি.',adminKeyboard());
        http_response_code(200); exit;
    }

    /* ADMIN: USER BALANCE */
    if ($state === 'admin_user_balance') {
        $amount=(float)$text; $target=(int)($data['target']??0); $mode=(string)($data['mode']??'add');
        if($amount<=0){sendMessage(ADMIN_ID,'❌ Valid amount দিন।');http_response_code(200);exit;}
        $delta=$mode==='deduct'?-abs($amount):abs($amount);
        if(changeBalance($target,$delta)){
            adminLog(strtoupper($mode).'_BALANCE',$target,formatMoney($amount).'৳');
            sendMessage($target,($delta>0?'💰 Balance Added: ':'➖ Balance Deducted: ').formatMoney($amount).'৳');
            sendMessage(ADMIN_ID,'✅ Balance updated.\n\n'.adminUserSummary($target),adminKeyboard());
        } else sendMessage(ADMIN_ID,'❌ Balance update failed.');
        clearState(ADMIN_ID); http_response_code(200); exit;
    }

    /* ADMIN: USER MESSAGE */
    if ($state === 'admin_user_message') {
        $target=(int)($data['target']??0);
        if($text===''){sendMessage(ADMIN_ID,'❌ Message লিখুন।');http_response_code(200);exit;}
        $res=sendMessage($target,htmlspecialchars($text));
        adminLog('MESSAGE_USER',$target,$text);
        clearState(ADMIN_ID);
        sendMessage(ADMIN_ID,($res['ok']??false)?'✅ Message sent.':'❌ Message failed.',adminKeyboard());
        http_response_code(200); exit;
    }

/* ADMIN: PRODUCT CREATION STATES */

    if ($state === 'admin_mail_name') {
        $name = trim($text);
        if ($name === '') { sendMessage(ADMIN_ID,"❌ Product name দিন।"); http_response_code(200); exit; }
        setState(ADMIN_ID,'admin_product_price',['category'=>'mail','name'=>$name]);
        sendMessage(ADMIN_ID,"💰 <b>".htmlspecialchars($name)."</b>-এর প্রতি পিস Price লিখুন।");
        http_response_code(200); exit;
    }

    if ($state === 'admin_vpn_name') {
        $name = trim($text);
        if ($name === '') { sendMessage(ADMIN_ID,"❌ VPN product name দিন।"); http_response_code(200); exit; }
        setState(ADMIN_ID,'admin_product_price',['category'=>'vpn','name'=>$name]);
        sendMessage(ADMIN_ID,"💰 <b>".htmlspecialchars($name)."</b>-এর প্রতি পিস Price লিখুন।");
        http_response_code(200); exit;
    }

    if ($state === 'admin_product_price') {
        if (!is_numeric($text) || (float)$text <= 0) {
            sendMessage(ADMIN_ID,"❌ Valid price দিন। উদাহরণ: <code>2.50</code>");
            http_response_code(200); exit;
        }
        $price=(float)$text;
        $category=(string)($data['category']??'mail');
        $name=trim((string)($data['name']??'Product'));
        $catalog=catalogProducts();
        $id=nextCatalogId($catalog);
        $catalog[]=[
            'id'=>$id,'category'=>$category,'name'=>$name,'price'=>$price,
            'stock'=>0,'active'=>true,'created_at'=>date('Y-m-d H:i:s')
        ];
        if (!saveCatalogProducts($catalog)) {
            clearState(ADMIN_ID); sendMessage(ADMIN_ID,"❌ Product save failed.");
            http_response_code(200); exit;
        }

        if ($category === 'vpn') {
            setState(ADMIN_ID,'admin_vpn_initial_stock',['catalog_id'=>$id]);
            sendMessage(ADMIN_ID,
                "✅ <b>VPN Product Created!</b>\n\n"
                ."📦 Product: <b>".htmlspecialchars($name)."</b>\n"
                ."💰 Price: <b>".formatMoney($price)."৳</b>\n\n"
                ."এখন <b>কত পিস Stock</b> আছে লিখুন।\n"
                ."উদাহরণ: <code>10</code>\n\n"
                ."⚠️ VPN-এর জন্য কোনো file upload লাগবে না।"
            );
        } else {
            setState(ADMIN_ID,'admin_product_stock',['catalog_id'=>$id]);
            sendMessage(ADMIN_ID,
                "✅ Product created!\n\n"
                ."📦 Product: <b>".htmlspecialchars($name)."</b>\n"
                ."💰 Price: <b>".formatMoney($price)."৳</b>\n\n"
                ."এখন এই product-এর stock <b>TXT / CSV / XLSX</b> file upload করুন।\n"
                ."প্রতি line/row = ১টি stock item হবে।"
            );
        }
        http_response_code(200); exit;
    }

    /* ADMIN: ADD VPN STOCK COUNT */
    if ($state === 'admin_vpn_stock_add') {
        if (!ctype_digit($text) || (int)$text <= 0) {
            sendMessage(ADMIN_ID,"❌ সঠিক সংখ্যা দিন। উদাহরণ: <code>10</code>");
            http_response_code(200); exit;
        }
        $id=(int)($data['catalog_id']??0);
        $add=(int)$text;
        $p=getCatalogProduct($id);
        if ($p===null || catalogCategory($p)!=='vpn') {
            clearState(ADMIN_ID); sendMessage(ADMIN_ID,"❌ VPN product not found.",adminKeyboard());
            http_response_code(200); exit;
        }
        if (!updateCatalogStock($id,$add)) {
            sendMessage(ADMIN_ID,"❌ Stock update failed.",adminKeyboard());
            clearState(ADMIN_ID); http_response_code(200); exit;
        }
        clearState(ADMIN_ID);
        sendMessage(ADMIN_ID,
            "✅ <b>New Stock Added</b>\n\n"
            ."📦 Product: <b>".htmlspecialchars(catalogName($p))."</b>\n"
            ."➕ New Stock: <b>{$add}</b> pcs\n"
            ."📦 Total Stock: <b>".stockCount($id)."</b> pcs\n"
            ."💰 Price: <b>".formatMoney(catalogPrice($p))."৳</b>",
            adminKeyboard()
        );
        notifyAllUsersProductAdded($add,catalogName($p),catalogPrice($p));
        http_response_code(200); exit;
    }

    /* ADMIN: INITIAL VPN STOCK COUNT */
    if ($state === 'admin_vpn_initial_stock') {
        if (!ctype_digit($text) || (int)$text < 0) {
            sendMessage(ADMIN_ID,"❌ সঠিক stock সংখ্যা দিন। উদাহরণ: <code>10</code>");
            http_response_code(200); exit;
        }
        $id=(int)($data['catalog_id']??0);
        $add=(int)$text;
        $p=getCatalogProduct($id);
        if ($p===null || catalogCategory($p)!=='vpn') {
            clearState(ADMIN_ID); sendMessage(ADMIN_ID,"❌ VPN product not found.",adminKeyboard());
            http_response_code(200); exit;
        }
        if (!updateCatalogStock($id,$add)) {
            sendMessage(ADMIN_ID,"❌ Stock save failed.",adminKeyboard());
            clearState(ADMIN_ID); http_response_code(200); exit;
        }
        clearState(ADMIN_ID);
        sendMessage(ADMIN_ID,
            "✅ <b>VPN Product Ready</b>\n\n"
            ."📦 Product: <b>".htmlspecialchars(catalogName($p))."</b>\n"
            ."💰 Price: <b>".formatMoney(catalogPrice($p))."৳</b>\n"
            ."📦 New Stock: <b>{$add}</b> pcs\n"
            ."📦 Total Stock: <b>".stockCount($id)."</b> pcs\n\n"
            ."এখন User order করলে Admin নিজে VPN details দিয়ে delivery করতে পারবেন।",
            adminKeyboard()
        );
        if ($add > 0) notifyAllUsersProductAdded($add,catalogName($p),catalogPrice($p));
        http_response_code(200); exit;
    }

    /* ADMIN: STOCK FILE FOR SELECTED PRODUCT */
    if ($state === 'admin_product_stock') {
        if (!isset($message['document'])) {
            sendMessage(ADMIN_ID,"📥 এই product-এর TXT / CSV / XLSX file upload করুন।");
            http_response_code(200); exit;
        }

        $document=$message['document'];
        $fileId=(string)($document['file_id']??'');
        $fileName=(string)($document['file_name']??'stock.txt');
        if ($fileId==='') { sendMessage(ADMIN_ID,"❌ File ID missing."); http_response_code(200); exit; }

        $fileInfo=api('getFile',['file_id'=>$fileId]);
        $filePath=(string)($fileInfo['result']['file_path']??'');
        if (!($fileInfo['ok']??false) || $filePath==='') {
            sendMessage(ADMIN_ID,"❌ Telegram file info পাওয়া যায়নি."); http_response_code(200); exit;
        }

        $token=botToken();
        if ($token==='') { sendMessage(ADMIN_ID,"❌ BOT_TOKEN unavailable."); http_response_code(200); exit; }

        $downloadUrl="https://api.telegram.org/file/bot".$token."/".$filePath;
        $tmpFile=tempnam(sys_get_temp_dir(),'stock_');
        if ($tmpFile===false) { sendMessage(ADMIN_ID,"❌ Temporary file error."); http_response_code(200); exit; }

        $fp=fopen($tmpFile,'wb');
        if ($fp===false) { @unlink($tmpFile); sendMessage(ADMIN_ID,"❌ Temporary file open failed."); http_response_code(200); exit; }

        $ch=curl_init($downloadUrl);
        curl_setopt_array($ch,[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60]);
        curl_exec($ch); curl_close($ch); fclose($fp);

        $catalogId=(int)($data['catalog_id']??0);
        $p=getCatalogProduct($catalogId);
        $added=addStockFromFile($tmpFile,$fileName,$catalogId);
        @unlink($tmpFile);
        clearState(ADMIN_ID);

        if ($added<=0) {
            sendMessage(ADMIN_ID,"❌ কোনো stock add হয়নি। Supported: TXT / CSV / XLSX",adminKeyboard());
        } else {
            $name=$p?catalogName($p):'Product';
            $price=$p?catalogPrice($p):0;
            $stock=stockCount($catalogId);
            sendMessage(ADMIN_ID,
                "✅ <b>Stock Added Successfully</b>\n\n"
                ."📦 Product: <b>".htmlspecialchars($name)."</b>\n"
                ."➕ Added: <b>{$added}</b>\n"
                ."📦 Available Stock: <b>{$stock}</b>\n"
                ."💰 Price: <b>".formatMoney($price)."৳</b>",
                adminKeyboard()
            );
            notifyAllUsersProductAdded($added,$name,$price);
        }
        http_response_code(200); exit;
    }


    /* ADMIN UPDATE PRICE STATE */
    if ($state === 'admin_update_price') {
        if (!is_numeric($text) || (float)$text <= 0) {
            sendMessage(ADMIN_ID,"❌ Valid price দিন।");
            http_response_code(200); exit;
        }
        $id=(int)($data['catalog_id']??0);
        $catalog=catalogProducts();
        $found=false;
        foreach($catalog as &$p){
            if((int)($p['id']??0)===$id){
                $p['price']=(float)$text; $found=true; break;
            }
        }
        unset($p);
        if($found){
            saveCatalogProducts($catalog);
            // Keep existing unsold stock price in sync.
            $all=products();
            foreach($all as &$stock){
                if((int)($stock['catalog_id']??0)===$id && empty($stock['sold'])) $stock['price']=(float)$text;
            }
            unset($stock); saveProducts($all);
            sendMessage(ADMIN_ID,"✅ Price updated: <b>".formatMoney((float)$text)."৳</b>",adminKeyboard());
        } else sendMessage(ADMIN_ID,"❌ Product not found.");
        clearState(ADMIN_ID);
        http_response_code(200); exit;
    }


    /* ADMIN BROADCAST STATE */

    $state = getState(ADMIN_ID);

    if (
        $state
        && ($state['state'] ?? '') === 'admin_broadcast'
    ) {

        if ($text === '') {

            sendMessage(
                ADMIN_ID,
                "• Text message ••••"
            );

            http_response_code(200);
            exit;
        }

        clearState(ADMIN_ID);

        $sent = broadcastToUsers(
            $text
        );

        sendMessage(
            ADMIN_ID,
            "• <b>Broadcast Complete</b>\n\n"
            . "• Sent: <b>{$sent}</b>",
            adminKeyboard()
        );

        http_response_code(200);
        exit;
    }


    /* ADMIN DELETE PRODUCT */

    $state = getState(ADMIN_ID);

    if (
        $state
        && ($state['state'] ?? '') === 'admin_delete_product'
    ) {

        if (
            !ctype_digit($text)
            || (int)$text <= 0
        ) {

            sendMessage(
                ADMIN_ID,
                "• Valid Product ID ••••"
            );

            http_response_code(200);
            exit;
        }

        $productId = (int)$text;

        $all = products();

        $found = false;

        foreach ($all as $key => $product) {

            if ((int)($product['id'] ?? 0) === $productId) {

                unset($all[$key]);

                $found = true;

                break;
            }
        }

        if ($found) {

            $all = array_values($all);

            saveProducts($all);

            sendMessage(
                ADMIN_ID,
                "• Product deleted.\n\n"
                . "• Product ID: <code>{$productId}</code>",
                adminKeyboard()
            );

        } else {

            sendMessage(
                ADMIN_ID,
                "• Product ID ••••• ••••••"
            );
        }

        clearState(ADMIN_ID);

        http_response_code(200);
        exit;
    }
}


/* ======================================================
   ADMIN DIRECT FILE SUPPORT
====================================================== */

if (
    isAdmin($userId)
    && isset($message['document'])
) {

    $state = getState(ADMIN_ID);

    if (!$state) {

        sendMessage(
            ADMIN_ID,
            "•• File •••••••\n\n"
            . "Stock ••• •••• ••• • Add Product/Stock ••••••",
            adminKeyboard()
        );
    }

    http_response_code(200);
    exit;
}


/* ======================================================
   UNKNOWN TEXT
====================================================== */

if ($text !== '') {

    sendMessage(
        $userId,
        "• •• command ••••• •••••••\n\n"
        . "Menu •••• •••• option •••••••• •••••",
        isAdmin($userId)
            ? adminKeyboard()
            : userKeyboard()
    );
}

http_response_code(200);
exit;
?>