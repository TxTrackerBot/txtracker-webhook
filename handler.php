<?php
date_default_timezone_set('Europe/Vilnius');

define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');
define('ADMIN_CHAT_ID', 5565195813); 
define('USDT_ADDRESS', '0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6');
define('PRICE_PER_RECEIPT', 5); // цена за 1 квитанцию в USD

// Загрузка сохранённых данных пользователей
$user_data_file = __DIR__ . '/users.json';
$log_file = __DIR__ . '/log.txt';

if (!file_exists($user_data_file)) {
    file_put_contents($user_data_file, json_encode([]));
}
$users = json_decode(file_get_contents($user_data_file), true);

function logMessage($msg) {
    global $log_file;
    file_put_contents($log_file, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// Отправка сообщения с кнопками
function sendMessage($chat_id, $text, $buttons = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($buttons !== null) {
        $data['reply_markup'] = json_encode($buttons);
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);
    logMessage("Send to $chat_id: $text");
    return $result;
}

// Валидация email
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Валидация телефона в международном формате +1234567890
function is_valid_phone($phone) {
    return preg_match('/^\+\d{10,15}$/', $phone);
}

// Чтение входящего запроса
$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received update: " . json_encode($input));

if (!isset($input['message'])) exit;

$message = $input['message'];
$chat_id = $message['chat']['id'] ?? null;
$user_id = $message['from']['id'] ?? null;
$text = trim($message['text'] ?? '');
$now = time();

// Авто-таймаут 15 минут (удаляем пользователей, которые не завершили авторизацию)
foreach ($users as $uid => $udata) {
    if (isset($udata['last_activity']) && ($now - $udata['last_activity']) > 900) {
        unset($users[$uid]);
        logMessage("User $uid session timed out and cleared.");
    }
}
file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));

// Обновляем время последней активности
if ($user_id) {
    $users[$user_id]['last_activity'] = $now;
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
}

// Инициализация данных пользователя, если нет
if (!isset($users[$user_id])) {
    $users[$user_id] = [
        'step' => 'start',
        'language' => null,
        'history' => [],
    ];
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
}

// Функция для кнопок выбора языка
function languageButtons() {
    return [
        'keyboard' => [
            ['RU', 'EN']
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}

// Функция кнопок Да/Нет
function yesNoButtons($lang) {
    if ($lang === 'ru') {
        return [
            'keyboard' => [
                ['Да', 'Нет']
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    } else {
        return [
            'keyboard' => [
                ['Yes', 'No']
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }
}

// Обработка команд
if ($text === '/start') {
    $users[$user_id]['step'] = 'choose_language';
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
    sendMessage($chat_id, "Please choose your language / Выберите язык:", languageButtons());
    exit;
}

// Основной обработчик по шагам
$step = $users[$user_id]['step'];
$lang = $users[$user_id]['language'] ?? null;

switch ($step) {
    case 'choose_language':
        $lang_input = strtoupper($text);
        if ($lang_input !== 'RU' && $lang_input !== 'EN') {
            sendMessage($chat_id, "Please choose language / Выберите язык:", languageButtons());
            break;
        }
        $lang = strtolower($lang_input);
        $users[$user_id]['language'] = $lang;
        $users[$user_id]['step'] = 'enter_name';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        sendMessage($chat_id, ($lang === 'ru' ? "Вы выбрали русский. Введите ваше имя и фамилию." : "You chose English. Please enter your full name."));
        break;

    case 'enter_name':
        if (strlen($text) < 3) {
            sendMessage($chat_id, ($lang === 'ru' ? "Пожалуйста, введите корректное имя и фамилию." : "Please enter a valid full name."));
            break;
        }
        $users[$user_id]['name'] = $text;
        $users[$user_id]['step'] = 'enter_phone';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        sendMessage($chat_id, ($lang === 'ru' ? "Введите номер телефона в международном формате (начинается с +)." : "Enter your phone number in international format (starts with +)."));
        break;

    case 'enter_phone':
        if (!is_valid_phone($text)) {
            sendMessage($chat_id, ($lang === 'ru' ? "Неверный формат телефона. Попробуйте снова." : "Invalid phone format. Try again."));
            break;
        }
        $users[$user_id]['phone'] = $text;
        $users[$user_id]['step'] = 'enter_email';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        sendMessage($chat_id, ($lang === 'ru' ? "Введите адрес электронной почты." : "Enter your email address."));
        break;

    case 'enter_email':
        if (!is_valid_email($text)) {
            sendMessage($chat_id, ($lang === 'ru' ? "Неверный email. Попробуйте снова." : "Invalid email. Try again."));
            break;
        }
        $users[$user_id]['email'] = $text;
        $users[$user_id]['step'] = 'enter_receipt_count';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        sendMessage($chat_id, ($lang === 'ru' ? "Сколько квитанций хотите проверить?" : "How many receipts do you want to check?"));
        break;

    case 'enter_receipt_count':
        if (!is_numeric($text) || intval($text) < 1) {
            sendMessage($chat_id, ($lang === 'ru' ? "Введите число больше 0." : "Enter a number greater than 0."));
            break;
        }
        $count = intval($text);
        $users[$user_id]['receipt_count'] = $count;
        $users[$user_id]['step'] = 'confirm_payment';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $sum = $count * PRICE_PER_RECEIPT;
        $msg = ($lang === 'ru')
            ? "Стоимость отслеживания одной транзакции с подробным анализом 5$. Всего к оплате: $sum USDT.\nХотите продолжить?"
            : "The cost to check one receipt with detailed analysis is $5. Total amount: $sum USDT.\nDo you want to continue?";
        sendMessage($chat_id, $msg, yesNoButtons($lang));
        break;

    case 'confirm_payment':
        $answer = mb_strtolower($text);
        if (($lang === 'ru' && ($answer === 'да')) || ($lang === 'en' && $answer === 'yes'))
