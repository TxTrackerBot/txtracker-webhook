<?php
define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');

function logMessage($message) {
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " " . $message . PHP_EOL, FILE_APPEND);
}

$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received: " . json_encode($input));

$user_data_file = 'users.json';
$users = file_exists($user_data_file) ? json_decode(file_get_contents($user_data_file), true) : [];

// Обробка callback_query для кнопок
if (isset($input['callback_query'])) {
    $callback = $input['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $user_id = $callback['from']['id'];
    $data = $callback['data'];

    $users = file_exists($user_data_file) ? json_decode(file_get_contents($user_data_file), true) : [];
    $user_lang = $users[$user_id]['language'] ?? 'en';

    if ($data === 'payment_yes') {
        $users[$user_id]['step'] = 'waiting_payment_usdt';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru')
            ? "Оплатите 5 USDT по реквизитам:\n0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6\nПосле оплаты напишите 'Оплата выполнена'."
            : "Please pay 5 USDT to the address:\n0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6\nAfter payment, write 'Payment done'.";
        sendMessage($chat_id, $reply);
    } elseif ($data === 'payment_no') {
        $users[$user_id]['step'] = 'cancelled';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru') ? "Операция отменена." : "Operation cancelled.";
        sendMessage($chat_id, $reply);
    }

    // Відповідаємо на callback_query, щоб кнопки не "зависали"
    $callback_query_id = $callback['id'];
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/answerCallbackQuery";
    $post_fields = ['callback_query_id' => $callback_query_id];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
    exit;
}

// Якщо немає повідомлення - виходимо
if (!isset($input['message'])) exit;

$message = $input['message'];
$chat_id = $message['chat']['id'] ?? null;
$text = trim($message['text'] ?? '');
$user_id = $message['from']['id'] ?? null;
$first_name = $message['from']['first_name'] ?? '';

// Функція відправки повідомлень
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
    logMessage("Sent to $chat_id: $text");
}

// Функція відправки повідомлення з кнопками (inline keyboard)
function sendMessageWithButtons($chat_id, $text, $buttons) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $keyboard = ['inline_keyboard' => $buttons];
    $post_fields = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
        'reply_markup' => json_encode($keyboard)
    ];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    logMessage("Sent with buttons to $chat_id: $text");
}

// Завантажуємо дані користувача
$users = file_exists($user_data_file) ? json_decode(file_get_contents($user_data_file), true) : [];

if ($text === '/start') {
    $msg = "Выберите язык / Choose language:\nReply with <b>RU</b> for Russian\nReply with <b>EN</b> for English";
    sendMessage($chat_id, $msg);
    exit;
}

if (in_array(strtoupper($text), ['RU', 'EN'])) {
    $users[$user_id]['language'] = strtolower($text);
    $users[$user_id]['step'] = 'waiting_name';
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
    $reply = ($users[$user_id]['language'] == 'ru') 
        ? "Вы выбрали русский язык. Пожалуйста, введите ваше имя и фамилию."
        : "You chose English. Please enter your full name.";
    sendMessage($chat_id, $reply);
    exit;
}

$user_lang = $users[$user_id]['language'] ?? 'en'; // дефолт — англ
$step = $users[$user_id]['step'] ?? null;

switch ($step) {
    case 'waiting_name':
        $users[$user_id]['full_name'] = $text;
        $users[$user_id]['step'] = 'waiting_phone';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru') 
            ? "Спасибо, {$text}! Введите ваш номер телефона Telegram."
            : "Thanks, {$text}! Please enter your Telegram phone number.";
        sendMessage($chat_id, $reply);
        break;

    case 'waiting_phone':
        $users[$user_id]['phone'] = $text;
        $users[$user_id]['step'] = 'waiting_email';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $reply = ($user_lang == 'ru') 
            ? "Номер получен! Введите ваш Email."
            : "Phone received! Please enter your email.";
        sendMessage($chat_id, $reply);
        break;

    case 'waiting_email':
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
        $users[$user_id]['step'] = 'waiting_payment_check_confirm';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $msg = ($user_lang == 'ru')
            ? "Проверка одной квитанции стоит 5 долларов. Хотите продолжить?"
            : "Checking one receipt costs $5. Do you want to continue?";
        $buttons = [
            [
                ['text' => $user_lang == 'ru' ? 'Да' : 'Yes', 'callback_data' => 'payment_yes'],
                ['text' => $user_lang == 'ru' ? 'Нет' : 'No', 'callback_data' => 'payment_no']
            ]
        ];
        sendMessageWithButtons($chat_id, $msg, $buttons);
        break;

    case 'waiting_payment_usdt':
        if (mb_strtolower($text) == 'оплата выполнена' || mb_strtolower($text) == 'payment done') {
            $users[$user_id]['step'] = 'waiting_receipt';
            file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
            $reply = ($user_lang == 'ru')
                ? "Оплата получена. Теперь отправьте фото квитанции. На квитанции обязательно должно быть: номер квитанции, дата совершения перевода."
                : "Payment received. Now please send a photo of the receipt. The receipt must contain: receipt number and date of transfer.";
            sendMessage($chat_id, $reply);
        } else {
            $reply = ($user_lang == 'ru')
                ? "Пожалуйста, подтвердите оплату, написав 'Оплата выполнена'."
                : "Please confirm payment by writing 'Payment done'.";
            sendMessage($chat_id, $reply);
        }
        break;

    case 'waiting_receipt':
        if (isset($message['photo'])) {
            $photo_array = $message['photo'];
            $file_id = end($photo_array)['file_id'];
            $users[$user_id]['receipt_file_id'] = $file_id;
            $users[$user_id]['step'] = 'completed';
            file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
            $reply = ($user_lang == 'ru')
                ? "Квитанция получена. Ожидайте проверку в течение 30 минут."
                : "Receipt received. Please wait for verification within 30 minutes.";
            sendMessage($chat_id, $reply);
        } else {
            $reply = ($user_lang == 'ru')
                ? "Пожалуйста, отправьте фото квитанции."
                : "Please send a photo of the receipt.";
            sendMessage($chat_id, $reply);
        }
        break;

    case 'completed':
        $reply = ($user_lang == 'ru')
            ? "Вы уже завершили регистрацию. Спасибо!"
            : "You have already completed registration. Thank you!";
        sendMessage($chat_id, $reply);
        break;

    case 'cancelled':
        $reply = ($user_lang == 'ru')
            ? "Вы отменили операцию. Если хотите начать заново, отправьте /start."
            : "You cancelled the operation. If you want to start again, send /start.";
        sendMessage($chat_id, $reply);
        break;

    default:
        sendMessage($chat_id, ($user_lang == 'ru') 
            ? "Пожалуйста, отправьте /start, чтобы начать."
            : "Please send /start to begin.");
        break;
}

file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
?>
