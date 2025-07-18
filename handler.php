<?php
function logMessage($message) {
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " " . $message . PHP_EOL, FILE_APPEND);
}

$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received update: " . json_encode($input));

// Візьмемо chat_id і текст повідомлення, щоб відповісти
$chat_id = $input['message']['chat']['id'] ?? null;
$text = $input['message']['text'] ?? '';

if ($chat_id) {
    $response_text = "Привіт! Я отримав твоє повідомлення: " . $text;

    $token = getenv('TELEGRAM_TOKEN'); // Забираємо токен із змінної оточення

    $url = "https://api.telegram.org/bot$token/sendMessage";

    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $response_text,
    ];

    $ch = curl_init(); 
    curl_setopt($ch, CURLOPT_URL, $url); 
    curl_setopt($ch, CURLOPT_POST, true); 
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields); 
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
    $result = curl_exec($ch);
    logMessage("Telegram API response: " . $result);
    curl_close($ch);
}
