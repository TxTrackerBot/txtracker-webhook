<?php
// handler.php

// Токен твого бота
define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');

// Функція логування
function logMessage($message) {
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " " . $message . PHP_EOL, FILE_APPEND);
}

// Отримуємо дані від Telegram
$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received update: " . json_encode($input));

// Перевірка, що прийшло повідомлення
if (!isset($input['message'])) {
    exit; // Немає повідомлення, нічого робити
}

$message = $input['message'];
$chat_id = $message['chat']['id'] ?? null;
$text = $message['text'] ?? '';
$user_id = $message['from']['id'] ?? null;
$first_name = $message['from']['first_name'] ?? '';
$lang = $message['from']['language_code'] ?? 'en';

// Масив збережених користувачів (у реалі — замінити на БД або файл)
$user_data_file = 'users.json';
$users = file_exists($user_data_file) ? json_decode(file_get_contents($user_data_file), true) : [];

// Функція відправки повідомлення
function sendMessage($chat_id, $text) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    logMessage("Sent response to $chat_id: " . $text);
}

// Логіка вибору мови через команду /start або кнопку (тут спрощено)
if ($text === '/start') {
    $welcome_text = "Hello! Please choose your language / Пожалуйста выберите язык:\n" .
                    "Reply with 'EN' for English or 'RU' for Russian.";
    sendMessage($chat_id, $welcome_text);
    exit;
}

// Обробка вибору мови користувачем
if (in_array(strtoupper($text), ['EN', 'RU'])) {
    $chosen_lang = strtolower($text);
    // Зберігаємо мову користувача
    $users[$user_id]['language'] = $chosen_lang;
    $users[$user_id]['first_name'] = $first_name;
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));

    $msg = $chosen_lang === 'ru' 
        ? "Вы выбрали русский язык. Пожалуйста, введите ваше имя."
        : "You chose English. Please enter your name.";
    sendMessage($chat_id, $msg);
    exit;
}

// Якщо користувач вже обрав мову, читаємо з даних
$user_lang = $users[$user_id]['language'] ?? $lang; // або по дефолту telegram lang

// Логіка збору даних клієнта по кроках: ім'я -> телефон -> місто -> оплата
// Для прикладу зробимо просту поетапну логіку

if (!isset($users[$user_id]['step'])) {
    $users[$user_id]['step'] = 'waiting_name';
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
}

switch ($users[$user_id]['step']) {
    case 'waiting_name':
        // Очікуємо ім'я
        $users[$user_id]['name'] = $text;
        $users[$user_id]['step'] = 'waiting_phone';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $msg = $user_lang === 'ru' ? "Спасибо, {$users[$user_id]['name']}! Теперь введите ваш телефон." : 
                                    "Thanks, {$users[$user_id]['name']}! Now please enter your phone number.";
        sendMessage($chat_id, $msg);
        break;

    case 'waiting_phone':
        // Прості перевірки на телефон можна додати
        $users[$user_id]['phone'] = $text;
        $users[$user_id]['step'] = 'waiting_city';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $msg = $user_lang === 'ru' ? "Получено! Введите ваш город." : 
                                    "Got it! Please enter your city.";
        sendMessage($chat_id, $msg);
        break;

    case 'waiting_city':
        $users[$user_id]['city'] = $text;
        $users[$user_id]['step'] = 'waiting_payment';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $msg = $user_lang === 'ru' ? "Спасибо! Теперь пришлите квитанцию об оплате." : 
                                    "Thank you! Now please send the payment receipt.";
        sendMessage($chat_id, $msg);
        break;

    case 'waiting_payment':
        // Очікуємо фото квитанції або текст (спрощено)
        if (isset($message['photo'])) {
            // Отримуємо file_id останнього фото
            $photo_array = $message['photo'];
            $file_id = end($photo_array)['file_id'];
            $users[$user_id]['payment_receipt_file_id'] = $file_id;
            $users[$user_id]['step'] = 'completed';
            file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
            $msg = $user_lang === 'ru' ? "Квитанция получена. Спасибо за оплату!" : 
                                        "Receipt received. Thank you for your payment!";
            sendMessage($chat_id, $msg);
        } else {
            $msg = $user_lang === 'ru' ? "Пожалуйста, отправьте фото квитанции." : 
                                        "Please send a photo of the payment receipt.";
            sendMessage($chat_id, $msg);
        }
        break;

    case 'completed':
        $msg = $user_lang === 'ru' ? "Вы уже завершили регистрацию. Спасибо!" : 
                                    "You have already completed registration. Thank you!";
        sendMessage($chat_id, $msg);
        break;

    default:
        sendMessage($chat_id, "Unexpected step. Please send /start to begin.");
        break;
}

?>
