<?php
// handler.php

define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');
define('USER_DATA_FILE', 'users.json');

function logMessage($msg) {
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " " . $msg . PHP_EOL, FILE_APPEND);
}

function sendMessage($chat_id, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $post_fields = ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML'];
    if ($keyboard) $post_fields['reply_markup'] = json_encode($keyboard);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post_fields,
        CURLOPT_RETURNTRANSFER => true
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    logMessage("Sent to $chat_id: $text");
}

$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received: " . json_encode($input));

if (!isset($input['message'])) exit;

$msg = $input['message'];
$chat_id = $msg['chat']['id'];
$text = trim($msg['text'] ?? '');
$user_id = $msg['from']['id'];

$users = file_exists(USER_DATA_FILE) ? json_decode(file_get_contents(USER_DATA_FILE), true) : [];
if (!is_array($users)) $users = [];
if (!isset($users[$user_id])) $users[$user_id] = ['step' => 'start', 'data' => []];
$step = $users[$user_id]['step'];

switch ($step) {
    case 'start':
        $keyboard = [
            'keyboard' => [[['text'=>'Русский'], ['text'=>'English']]],
            'resize_keyboard' => true, 'one_time_keyboard' => true
        ];
        sendMessage($chat_id, "Выберите язык / Choose language:", $keyboard);
        $users[$user_id]['step'] = 'choose_language';
        break;

    case 'choose_language':
        $lang = mb_strtolower($text);
        if (!in_array($lang, ['русский', 'english'])) {
            sendMessage($chat_id, "Пожалуйста, выберите язык кнопкой / Please choose language by button.");
            break;
        }
        $users[$user_id]['data']['language'] = $lang;
        $msg = ($lang == 'русский')
            ? "Введите ваше полное имя (Фамилия Имя):"
            : "Enter your full name (First and Last name):";
        sendMessage($chat_id, $msg);
        $users[$user_id]['step'] = 'waiting_name';
        break;

    case 'waiting_name':
        if (mb_strlen($text) < 3) {
            $msg = ($users[$user_id]['data']['language'] == 'русский') ? "Слишком короткое имя, введите снова:" : "Name too short, please enter again:";
            sendMessage($chat_id, $msg);
            break;
        }
        $users[$user_id]['data']['full_name'] = $text;
        $msg = ($users[$user_id]['data']['language'] == 'русский') ? "Введите номер телефона Telegram:" : "Enter your Telegram phone number:";
        sendMessage($chat_id, $msg);
        $users[$user_id]['step'] = 'waiting_phone';
        break;

    case 'waiting_phone':
        $users[$user_id]['data']['phone'] = $text;
        $msg = ($users[$user_id]['data']['language'] == 'русский') ? "Введите ваш email:" : "Enter your email:";
        sendMessage($chat_id, $msg);
        $users[$user_id]['step'] = 'waiting_email';
        break;

    case 'waiting_email':
        if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
            $msg = ($users[$user_id]['data']['language'] == 'русский') ? "Неверный email, введите заново:" : "Invalid email, please enter again:";
            sendMessage($chat_id, $msg);
            break;
        }
        $users[$user_id]['data']['email'] = $text;
        $msg = ($users[$user_id]['data']['language'] == 'русский') ? "Введите вашу страну:" : "Enter your country:";
        sendMessage($chat_id, $msg);
        $users[$user_id]['step'] = 'waiting_country';
        break;

    case 'waiting_country':
        $users[$user_id]['data']['country'] = $text;
        $lang = $users[$user_id]['data']['language'];
        $msg = ($lang == 'русский')
            ? "Проверка 1 квитанции стоит 5 долларов.\nВы хотите продолжить? (Да/Нет)"
            : "Check of 1 receipt costs 5 USD.\nDo you want to proceed? (Yes/No)";
        $keyboard = [
            'keyboard' => [[['text'=>($lang=='русский'?'Да':'Yes')], ['text'=>($lang=='русский'?'Нет':'No')]]],
            'resize_keyboard'=>true, 'one_time_keyboard'=>true
        ];
        sendMessage($chat_id, $msg, $keyboard);
        $users[$user_id]['step'] = 'waiting_payment_confirmation';
        break;

    case 'waiting_payment_confirmation':
        $lang = $users[$user_id]['data']['language'];
        $yes = ($lang == 'русский') ? 'да' : 'yes';
        $no = ($lang == 'русский') ? 'нет' : 'no';
        $answer = mb_strtolower($text);
        if ($answer == $yes) {
            $msg = ($lang == 'русский')
                ? "Отправьте оплату на USDT адрес:\n0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6\n\nПосле оплаты пришлите фото квитанции."
                : "Please send payment to USDT address:\n0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6\n\nAfter payment, send a photo of your receipt.";
            sendMessage($chat_id, $msg);
            $users[$user_id]['step'] = 'waiting_receipt';
        } elseif ($answer == $no) {
            $msg = ($lang == 'русский')
                ? "Оплата не подтверждена. Отправьте /start для начала заново."
                : "Payment not confirmed. Send /start to restart.";
            sendMessage($chat_id, $msg);
            $users[$user_id]['step'] = 'start';
        } else {
            sendMessage($chat_id, ($lang == 'русский' ? "Ответьте 'Да' или 'Нет'." : "Please answer 'Yes' or 'No'."));
        }
        break;

    case 'waiting_receipt':
        if (isset($msg['photo'])) {
            $photos = $msg['photo'];
            $file_id = end($photos)['file_id'];
            $users[$user_id]['data']['receipt_file_id'] = $file_id;
            $lang = $users[$user_id]['data']['language'];
            sendMessage($chat_id, ($lang == 'русский')
                ? "Квитанция получена! Ожидайте проверку в течение 30 минут."
                : "Receipt received! Please wait for verification within 30 minutes.");
            $users[$user_id]['step'] = 'completed';
            logMessage("User $user_id sent receipt file_id: $file_id");
        } else {
            $msg = ($users[$user_id]['data']['language'] == 'русский')
                ? "Пожалуйста, отправьте фото квитанции."
                : "Please send a photo of the payment receipt.";
            sendMessage($chat_id, $msg);
        }
        break;

    case 'completed':
        $lang = $users[$user_id]['data']['language'];
        sendMessage($chat_id, ($lang == 'русский')
            ? "Вы завершили регистрацию. Спасибо!"
            : "You have completed registration. Thank you!");
        break;

    default:
        sendMessage($chat_id, "Unknown step. Send /start to begin.");
        $users[$user_id]['step'] = 'start';
        break;
}

file_put_contents(USER_DATA_FILE, json_encode($users, JSON_PRETTY_PRINT));
