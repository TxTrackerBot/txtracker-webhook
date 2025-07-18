<?php
define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');

function logMessage($message) {
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " " . $message . PHP_EOL, FILE_APPEND);
}

$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received: " . json_encode($input));

if (!isset($input['message'])) exit;

$message = $input['message'];
$chat_id = $message['chat']['id'] ?? null;
$text = trim($message['text'] ?? '');
$user_id = $message['from']['id'] ?? null;
$first_name = $message['from']['first_name'] ?? '';

$user_data_file = 'users.json';
$users = file_exists($user_data_file) ? json_decode(file_get_contents($user_data_file), true) : [];

// Відправка повідомлення з кнопками
function sendMessage($chat_id, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard !== null) {
        $post_fields['reply_markup'] = json_encode($keyboard);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    logMessage("Sent to $chat_id: $text");
}

if ($text === '/start') {
    $keyboard = [
        'keyboard' => [
            [['text' => 'RU'], ['text' => 'EN']]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];
    $msg = "Выберите язык / Choose language:";
    sendMessage($chat_id, $msg, $keyboard);
    exit;
}

if (in_array(strtoupper($text), ['RU', 'EN'])) {
    $users[$user_id]['language'] = strtolower($text);
    $users[$user_id]['step'] = 'waiting_full_name';
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
    
    $reply = ($users[$user_id]['language'] == 'ru')
        ? "Вы выбрали русский язык. Пожалуйста, введите ваше имя и фамилию."
        : "You chose English. Please enter your full name.";
    sendMessage($chat_id, $reply);
    exit;
}

$user_lang = $users[$user_id]['language'] ?? 'en';
$step = $users[$user_id]['step'] ?? null;

switch ($step) {
    case 'waiting_full_name':
        $users[$user_id]['full_name'] = $text;
        $users[$user_id]['step'] = 'waiting_phone';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru') 
            ? "Спасибо, {$text}! Введите номер телефона в международном формате (например, +380XXXXXXXXX)."
            : "Thanks, {$text}! Please enter your phone number in international format (e.g. +380XXXXXXXXX).";
        sendMessage($chat_id, $reply);
        break;

    case 'waiting_phone':
        if (!preg_match('/^\+\d{7,15}$/', $text)) {
            $reply = ($user_lang == 'ru') 
                ? "Пожалуйста, введите корректный номер телефона, начиная с '+'."
                : "Please enter a valid phone number starting with '+'.";
            sendMessage($chat_id, $reply);
            break;
        }
        $users[$user_id]['phone'] = $text;
        $users[$user_id]['step'] = 'waiting_email';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru') 
            ? "Номер принят! Введите ваш Email."
            : "Phone number accepted! Please enter your email.";
        sendMessage($chat_id, $reply);
        break;

    case 'waiting_email':
        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
            $reply = ($user_lang == 'ru') 
                ? "Пожалуйста, введите корректный Email."
                : "Please enter a valid email address.";
            sendMessage($chat_id, $reply);
            break;
        }
        $users[$user_id]['email'] = $text;
        $users[$user_id]['step'] = 'waiting_country';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru') 
            ? "Спасибо! Теперь введите вашу страну."
            : "Thank you! Now please enter your country.";
        sendMessage($chat_id, $reply);
        break;

    case 'waiting_country':
        $users[$user_id]['country'] = $text;
        $users[$user_id]['step'] = 'waiting_receipts_count';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru')
            ? "Стоимость отслеживания одной транзакции с подробным анализом 5$. Сколько квитанций хотите проверить?"
            : "The cost of tracking one transaction with detailed analysis is $5. How many receipts do you want to check?";
        sendMessage($chat_id, $reply);
        break;

    // Кроки подальші робитимемо в наступних етапах
    default:
        sendMessage($chat_id, ($user_lang == 'ru') 
            ? "Пожалуйста, отправьте /start для начала."
            : "Please send /start to begin.");
        break;
}

file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
?>
