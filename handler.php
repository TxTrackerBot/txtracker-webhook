<?php
date_default_timezone_set('Europe/Vilnius');

define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');
define('ADMIN_CHAT_ID', 5565195813);
define('USDT_ADDRESS', '0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6');
define('PRICE_PER_RECEIPT', 5);

define('PAYMENTS_FILE', __DIR__ . '/storage/payments.json');
define('USER_DATA_FILE', __DIR__ . '/users.json');
define('LOG_FILE', __DIR__ . '/log.txt');

// --- Функції для роботи з файлами ---
function loadPayments() {
    if (!file_exists(PAYMENTS_FILE)) {
        file_put_contents(PAYMENTS_FILE, json_encode([]));
    }
    $data = file_get_contents(PAYMENTS_FILE);
    return json_decode($data, true) ?: [];
}

function savePayments($payments) {
    file_put_contents(PAYMENTS_FILE, json_encode($payments, JSON_PRETTY_PRINT));
}

function loadUsers() {
    if (!file_exists(USER_DATA_FILE)) {
        file_put_contents(USER_DATA_FILE, json_encode([]));
    }
    $data = file_get_contents(USER_DATA_FILE);
    return json_decode($data, true) ?: [];
}

function saveUsers($users) {
    file_put_contents(USER_DATA_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

function logMessage($msg) {
    file_put_contents(LOG_FILE, date('[Y-m-d H:i:s] ') . $msg . PHP_EOL, FILE_APPEND);
}

// --- Отправка сообщений в Telegram ---
function sendMessage($chat_id, $text, $buttons = null) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML',
    ];
    if ($buttons !== null) {
        // Якщо це inline клавіатура — кодуємо в JSON
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


// --- Валидация email и телефона ---
function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function sendContactRequest($chat_id) {
    $keyboard = [
        'keyboard' => [
            [
                ['text' => '📱 Поделиться контактом', 'request_contact' => true]
            ]
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];

    $data = [
        'chat_id' => $chat_id,
        'text' => "Пожалуйста, поделитесь своим номером телефона:",
        'reply_markup' => json_encode($keyboard)
    ];

    file_get_contents("https://api.telegram.org/bot8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA/sendMessage?" . http_build_query($data));
}



// --- Кнопки ---
function languageButtons() {
    return [
        'keyboard' => [['RU', 'EN']],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}

function yesNoButtons($lang) {
    if ($lang === 'ru') {
        return [
            'keyboard' => [['Да', 'Нет']],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }
    return [
        'keyboard' => [['Yes', 'No']],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}

// --- Основная логика ---

$payments = loadPayments();
$users = loadUsers();

$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received update: " . json_encode($input));
$callback = $input['callback_query'] ?? null;

// --- Обработка callback ---
if ($callback_query) {
    $data = $callback_query['data'];
    $callback_id = $callback_query['id'];
    $admin_id = $callback_query['from']['id'];

    if ($admin_id == ADMIN_CHAT_ID) {
        if (strpos($data, 'approve_payment:') === 0) {
            $uid = substr($data, strlen('approve_payment:'));
            global $payments;
            if (isset($payments[$uid])) {
                $payments[$uid]['status'] = 'approved';
                savePayments($payments);

                sendMessage($payments[$uid]['chat_id'], "✅ Оплата подтверждена. Начинаем проверку квитанций.");
                sendMessage(ADMIN_CHAT_ID, "✅ Оплата пользователя $uid подтверждена.");

                answerCallback($callback_id, 'Оплата подтверждена');
            }
            exit;
        }

        if (strpos($data, 'reject_payment:') === 0) {
            $uid = substr($data, strlen('reject_payment:'));
            global $payments;
            if (isset($payments[$uid])) {
                $payments[$uid]['status'] = 'rejected';
                savePayments($payments);

                sendMessage($payments[$uid]['chat_id'], "❌ Оплата не подтверждена. Пожалуйста, свяжитесь с нами.");
                sendMessage(ADMIN_CHAT_ID, "❌ Оплата пользователя $uid отклонена.");

                answerCallback($callback_id, 'Оплата отклонена');
            }
            exit;
        }
    } else {
        answerCallback($callback_id, '⛔ У вас нет прав на это действие.', true);
        exit;
    }
}

function answerCallback($callback_id, $text, $show_alert = false) {
    $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/answerCallbackQuery";
    $post = [
        'callback_query_id' => $callback_id,
        'text' => $text,
        'show_alert' => $show_alert,
    ];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}


if (!isset($input['message'])) {
    exit;
}

$message = $input['message'];
$chat_id = $message['chat']['id'] ?? null;
$user_id = $message['from']['id'] ?? null;
$text = trim($message['text'] ?? '');
$now = time();

// Очистка устаревших сессий
foreach ($users as $uid => $userdata) {
    if (isset($userdata['last_activity']) && ($now - $userdata['last_activity']) > 900) {
        unset($users[$uid]);
        logMessage("User $uid session cleared due to inactivity.");
    }
}
saveUsers($users);

if ($user_id) {
    $users[$user_id]['last_activity'] = $now;
    saveUsers($users);
}

if (!isset($users[$user_id])) {
    $users[$user_id] = [
        'step' => 'start',
        'language' => null,
        'history' => [],
    ];
    saveUsers($users);
}

$step = $users[$user_id]['step'];
$lang = $users[$user_id]['language'] ?? null;

if ($text === '/start') {
    $users[$user_id]['step'] = 'choose_language';
    saveUsers($users);
    sendMessage($chat_id, "Please choose your language / Выберите язык:", languageButtons());
    exit;
}

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
        saveUsers($users);
        sendMessage($chat_id, $lang === 'ru' ? "Вы выбрали русский. Введите ваше имя и фамилию." : "You chose English. Please enter your full name.");
        break;

    case 'enter_name':
    if (mb_strlen($text) < 3) {
        sendMessage($chat_id, $lang === 'ru' ? "Пожалуйста, введите корректное имя и фамилию." : "Please enter a valid full name.");
        break;
    }

    $users[$user_id]['name'] = $text;
    $users[$user_id]['step'] = 'wait_phone';
    saveUsers($users);

    sendContactRequest($chat_id); // Показуємо кнопку
    break;

case 'wait_phone':
    if (isset($update['message']['contact'])) {
        $contact = $update['message']['contact'];
        $phone = $contact['phone_number'];

        $users[$user_id]['phone'] = $phone;
        $users[$user_id]['step'] = 'enter_email';
        saveUsers($users);

        sendMessage($chat_id, $lang === 'ru'
            ? "Контакт получен. Теперь введите ваш адрес электронной почты."
            : "Contact received. Now enter your email address.");
    } else {
        sendMessage($chat_id, $lang === 'ru'
            ? "Пожалуйста, нажмите кнопку ниже и поделитесь своим контактом."
            : "Please press the button below and share your contact.");
        sendContactRequest($chat_id);
    }
    break;



        $users[$user_id]['phone'] = $text;
        $users[$user_id]['step'] = 'enter_email';
        saveUsers($users);
        sendMessage($chat_id, $lang === 'ru' ? "Введите адрес электронной почты." : "Enter your email address.");
        break;

    case 'enter_email':
        if (!is_valid_email($text)) {
            sendMessage($chat_id, $lang === 'ru' ? "Неверный email. Попробуйте снова." : "Invalid email. Try again.");
            break;
        }
        $users[$user_id]['email'] = $text;
        $users[$user_id]['step'] = 'enter_receipt_count';
        saveUsers($users);
        sendMessage($chat_id, $lang === 'ru' ? "Сколько квитанций хотите проверить?" : "How many receipts do you want to check?");
        break;

    case 'enter_receipt_count':
        if (!is_numeric($text) || intval($text) < 1) {
            sendMessage($chat_id, $lang === 'ru' ? "Введите число больше 0." : "Enter a number greater than 0.");
            break;
        }
        $count = intval($text);
        $users[$user_id]['receipt_count'] = $count;
        $users[$user_id]['step'] = 'confirm_payment';
        saveUsers($users);

        $sum = $count * PRICE_PER_RECEIPT;
        $msg = $lang === 'ru'
            ? "Стоимость отслеживания одной транзакции с подробным анализом 5$. Всего к оплате: $sum USDT.\nХотите продолжить?"
            : "The cost to check one receipt with detailed analysis is $5. Total amount: $sum USDT.\nDo you want to continue?";
        sendMessage($chat_id, $msg, yesNoButtons($lang));
        break;

    case 'confirm_payment':
        $answer = mb_strtolower($text);
        if (($lang === 'ru' && $answer === 'да') || ($lang === 'en' && $answer === 'yes')) {
            $users[$user_id]['step'] = 'waiting_for_payment';
            saveUsers($users);
            $sum = $users[$user_id]['receipt_count'] * PRICE_PER_RECEIPT;
            $msg = $lang === 'ru'
                ? "Пожалуйста, отправьте $sum USDT на адрес:\n<code>" . USDT_ADDRESS . "</code>\n\nПосле оплаты нажмите кнопку ниже:"
                : "Please send $sum USDT to the following address:\n<code>" . USDT_ADDRESS . "</code>\n\nAfter payment, click the button below:";
            sendMessage($chat_id, $msg, [
                'keyboard' => [
                    [$lang === 'ru' ? 'Я оплатил' : 'I have paid']
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]);

            // Сохраняем платеж как ожидающий
$payments[$user_id] = [
    'chat_id' => $chat_id,
    'amount' => $sum,
    'status' => 'pending',
    'timestamp' => time(),
];
savePayments($payments);
break;

default:
    sendMessage($chat_id, $lang === 'ru'
        ? "Платёж отменён. Напишите /start чтобы начать заново."
        : "Payment cancelled. Type /start to begin again.");
    unset($users[$user_id]);
    saveUsers($users);
    break;


   case 'waiting_for_payment':
    $confirm_text = $lang === 'ru' ? 'я оплатил' : 'i have paid';
    if (mb_strtolower($text) === $confirm_text) {
        sendMessage($chat_id, $lang === 'ru'
            ? "Спасибо! Пожалуйста, прикрепите фото или скриншот перевода, на котором видны:\n- Хеш транзакции (TXID)\n- Сумма перевода\n- Дата совершения операции\nЭто необходимо для подтверждения оплаты."
            : "Thank you!\nPlease attach a photo or screenshot of the transaction that clearly shows:\n- Transaction hash (TXID)\n- Amount sent\n- Date of the transaction\nThis is required to confirm your payment.");
    }
    break;

            // Обновляем платеж, если нужно
            $payments[$user_id] = [
                'status' => 'pending',
                'chat_id' => $chat_id,
                'amount' => $users[$user_id]['receipt_count'] * PRICE_PER_RECEIPT,
                'timestamp' => time(),
            ];
            savePayments($payments);

            // Отправляем админу сообщение с кнопками подтверждения
   $approveKeyboard = [
    'inline_keyboard' => [
        [
            ['text' => '✅ Подтвердить оплату', 'callback_data' => 'approve_payment:' . $user_id],
            ['text' => '❌ Отклонить оплату', 'callback_data' => 'reject_payment:' . $user_id]
        ]
    ]
];

sendPhoto($adminId, $file_id, "Новая оплата от пользователя <b>$user_id</b>.", $approveKeyboard);
    sendMessage($chat_id, "📤 Ваш чек отправлен на проверку. Ожидайте подтверждение.");
    break;
}

if (isset($data['callback_query'])) {
    $callback = $data['callback_query'];
    $data_parts = explode(':', $callback['data']);
    $action = $data_parts[0];
    $user_id = $data_parts[1];

    if ($action === 'approve_payment') {
        sendMessage($user_id, "✅ Оплату подтверждено! 💸\nUSDT для следующей оплаты: <code>$usdtWallet</code>");
        sendMessage($adminId, "✅ Оплата пользователя <b>$user_id</b> подтверждена.");
    } elseif ($action === 'reject_payment') {
        sendMessage($user_id, "❌ Оплата отклонена. Ожидаем чек о переводе средств ");
        sendMessage($adminId, "❌ Оплата пользователя <b>$user_id</b> отклонена.");
    }

        break;

   case 'upload_receipts':
    if (isset($message['photo'])) {
        $photo = end($message['photo']);
        $file_id = $photo['file_id'];

        $postData = [
            'chat_id' => ADMIN_CHAT_ID,
            'photo' => $file_id,
            'caption' => "Фото квитанции от пользователя для проверки транзакций $user_id"
        ];

        $ch = curl_init("https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendPhoto");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);

        sendMessage(ADMIN_CHAT_ID, "Пользователь $user_id отправил фото квитанции для проверки транзакций.");
    } elseif (!empty($text)) {
        sendMessage(ADMIN_CHAT_ID, "Пользователь $user_id отправил квитанции для проверки транзакций:\n" . $text);
    }

    sendMessage($chat_id, $lang === 'ru'
        ? "Спасибо! Квитанции отправлены на проверку. Мы свяжемся с вами после анализа."
        : "Thank you! Receipts sent for review. We will get back to you after analysis.");

    unset($users[$user_id]);
    saveUsers($users);
    break;

default:
    sendMessage($chat_id, $lang === 'ru' ? "Напишите /start чтобы начать заново." : "Type /start to begin again.");
    break;
