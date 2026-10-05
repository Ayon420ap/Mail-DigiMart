<?php
declare(strict_types=1);

$token = trim((string)(getenv('BOT_TOKEN') ?: ''));
$url   = trim((string)(getenv('WEBHOOK_URL') ?: ''));
$secret = trim((string)(getenv('WEBHOOK_SECRET') ?: ''));

if ($token === '' || $url === '') {
    fwrite(STDERR, "BOT_TOKEN and WEBHOOK_URL are required.\n");
    exit(1);
}

if (!filter_var($url, FILTER_VALIDATE_URL) || !str_starts_with($url, 'https://')) {
    fwrite(STDERR, "WEBHOOK_URL must be a valid HTTPS URL.\n");
    exit(1);
}

$payload = ['url' => $url, 'drop_pending_updates' => false];
if ($secret !== '') $payload['secret_token'] = $secret;

$ch = curl_init("https://api.telegram.org/bot{$token}/setWebhook");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
]);
$response = curl_exec($ch);
$errno = curl_errno($ch);
$error = curl_error($ch);
curl_close($ch);

if ($errno !== 0 || $response === false) {
    fwrite(STDERR, "Telegram webhook request failed: {$error}\n");
    exit(1);
}

$data = json_decode($response, true);
if (!is_array($data) || !($data['ok'] ?? false)) {
    fwrite(STDERR, "Telegram rejected webhook: {$response}\n");
    exit(1);
}

echo "Webhook set successfully.\n";
