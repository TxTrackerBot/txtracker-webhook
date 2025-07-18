<?php
date_default_timezone_set('Europe/Vilnius');

define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');
define('ADMIN_CHAT_ID', 5565195813);
define('USDT_ADDRESS', '0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6');
define('PRICE_PER_RECEIPT', 5); // цена за 1 квитанцию в USD

define('PAYMENTS_FILE', __DIR__ . '/storage/payments.json');

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

$payments = loadPayments();

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

function is_valid_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function is_valid_phone($phone) {
    return preg_match('/^\+\d{10,15}$/', $phone);
}

$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received update: " . json_encode($input));

if ($callback) {
    $data = $callback['data'];
    $callback_id = $callback['id'];
    $from_id = $callback['from']['id'];

    if ($from_id == ADMIN_CHAT_ID) {
        if (strpos($data, 'approve_payment:') === 0) {
            $uid = substr($data, strlen('approve_payment:'));
            global $payments;
            $payments[$uid]['status'] = 'approved';
            savePayments($payments);

            sendMessage($payments[$uid]['chat_id'], "Оплата підтверджена. Починаємо перевірку квитанцій.");
            sendMessage(ADMIN_CHAT_ID, "Оплата користувача $uid підтверджена.");

            // Відповідь на callback
            $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/answerCallbackQuery";
            $post = [
                'callback_query_id' => $callback_id,
                'text' => 'Оплата підтверджена',
                'show_alert' => false
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
            exit;
        }
        if (strpos($data, 'reject_payment:') === 0) {
            $uid = substr($data, strlen('reject_payment:'));
            global $payments;
            $payments[$uid]['status'] = 'rejected';
            savePayments($payments);

            sendMessage($payments[$uid]['chat_id'], "Оплата не підтверджена. Будь ласка, зв’яжіться з нами.");
            sendMessage(ADMIN_CHAT_ID, "Оплата користувача $uid відхилена.");

            // Відповідь на callback
            $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/answerCallbackQuery";
            $post = [
                'callback_query_id' => $callback_id,
                'text' => 'Оплата відхилена',
                'show_alert' => false
            ];
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_exec($ch);
            curl_close($ch);
            exit;
        }
    } else {
        // Якщо не адміністратор — відмовляємо у дії
        $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/answerCallbackQuery";
        $post = [
            'callback_query_id' => $callback_id,
            'text' => 'У вас немає прав на цю дію.',
            'show_alert' => true
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
        exit;
            }
        
        if (strpos($data, 'reject_payment:') === 0) {
            $uid = substr($data, strlen('reject_payment:'));
            if (isset($payments[$uid])) {
                $payments[$uid]['status'] = 'rejected';
                savePayments($payments);

                sendMessage($payments[$uid]['chat_id'], "Оплата не підтверджена. Будь ласка, зв’яжіться з нами.");
                sendMessage(ADMIN_CHAT_ID, "Оплата користувача $uid відхилена.");

                $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/answerCallbackQuery";
                $post = [
                    'callback_query_id' => $callback_id,
                    'text' => 'Оплата відхилена',
                    'show_alert' => false
                ];
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_exec($ch);
                curl_close($ch);
                exit;
            }
        }
    } else {
        $url = "https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/answerCallbackQuery";
        $post = [
            'callback_query_id' => $callback_id,
            'text' => 'У вас немає прав на цю дію.',
            'show_alert' => true
        ];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        curl_close($ch);
        exit;
    }
}

if (!isset($input['message'])) exit;

$message = $input['message'];
$chat_id = $message['chat']['id'] ?? null;
$user_id = $message['from']['id'] ?? null;
$text = trim($message['text'] ?? '');
$now = time();

foreach ($users as $uid => $udata) {
    if (isset($udata['last_activity']) && ($now - $udata['last_activity']) > 900) {
        unset($users[$uid]);
        logMessage("User $uid session timed out and cleared.");
    }
}
file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));

if ($user_id) {
    $users[$user_id]['last_activity'] = $now;
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
}

if (!isset($users[$user_id])) {
    $users[$user_id] = [
        'step' => 'start',
        'language' => null,
        'history' => [],
    ];
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
}

function languageButtons() {
    return [
        'keyboard' => [
            ['RU', 'EN']
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true,
    ];
}

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

if ($text === '/start') {
    $users[$user_id]['step'] = 'choose_language';
    file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
    sendMessage($chat_id, "Please choose your language / Выберите язык:", languageButtons());
    exit;
}

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

    // Записуємо суму до оплати
    $users[$user_id]['amount_to_pay'] = $count * PRICE_PER_RECEIPT;

    case 'confirm_payment':
    $answer = mb_strtolower($text);
    if (($lang === 'ru' && ($answer === 'да')) || ($lang === 'en' && $answer === 'yes')) {
        $users[$user_id]['step'] = 'waiting_for_payment';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        $sum = $users[$user_id]['amount_to_pay'];
        $msg = ($lang === 'ru')
            ? "Пожалуйста, отправьте $sum USDT на адрес:\n<code>" . USDT_ADDRESS . "</code>\n\nПосле оплаты нажмите кнопку ниже:"
            : "Please send $sum USDT to the following address:\n<code>" . USDT_ADDRESS . "</code>\n\nAfter payment, click the button below:";
        sendMessage($chat_id, $msg, [
            'keyboard' => [
                [($lang === 'ru') ? 'Я оплатил' : 'I have paid']
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ]);
    } else {
        sendMessage($chat_id, ($lang === 'ru') ? "Платёж отменён. Напишите /start чтобы начать заново." : "Payment cancelled. Type /start to begin again.");
        unset($users[$user_id]);
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
    }
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
        if (($lang === 'ru' && $answer === 'да') || ($lang === 'en' && $answer === 'yes')) {
            $users[$user_id]['step'] = 'waiting_for_payment';
            file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
            $sum = $users[$user_id]['receipt_count'] * PRICE_PER_RECEIPT;
            $msg = ($lang === 'ru')
                ? "Пожалуйста, отправьте $sum USDT на адрес:\n<code>" . USDT_ADDRESS . "</code>\n\nПосле оплаты нажмите кнопку ниже:"
                : "Please send $sum USDT to the following address:\n<code>" . USDT_ADDRESS . "</code>\n\nAfter payment, click the button below:";
            sendMessage($chat_id, $msg, [
                'keyboard' => [
                    [($lang === 'ru') ? 'Я оплатил' : 'I have paid']
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]);

            // Сохраняем платеж как ожидающий
            global $payments;
            $payments[$user_id] = [
                'chat_id' => $chat_id,
                'amount' => $sum,
                'status' => 'pending',
                'timestamp' => time(),
            ];
            savePayments($payments);
        } else {
            sendMessage($chat_id, ($lang === 'ru') ? "Платёж отменён. Напишите /start чтобы начать заново." : "Payment cancelled. Type /start to begin again.");
            unset($users[$user_id]);
            file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        }
        break;

    case 'waiting_for_payment':
    $confirm_text = ($lang === 'ru') ? 'я оплатил' : 'i have paid';
    if (mb_strtolower($text) === $confirm_text) {
        sendMessage($chat_id, ($lang === 'ru') 
            ? "Спасибо! Пожалуйста, отправьте ваши квитанции (в виде текста или фото)." 
            : "Thank you! Please send your receipts (as text or photo).");

        // Записуємо платіж у storage/payments.json
        global $payments;
        $payments[$user_id] = [
            'status' => 'pending',
            'chat_id' => $chat_id,
            'amount' => $users[$user_id]['amount_to_pay'],
            'timestamp' => time(),
        ];
        savePayments($payments);

        // Відправляємо адміну повідомлення з кнопками підтвердження
        $approveKeyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Підтвердити оплату', 'callback_data' => 'approve_payment:' . $user_id],
                    ['text' => '❌ Відхилити оплату', 'callback_data' => 'reject_payment:' . $user_id]
                ]
            ]
        ];
        sendMessage(ADMIN_CHAT_ID, "Користувач $user_id заявив про оплату $".$users[$user_id]['amount_to_pay'], $approveKeyboard);

        $users[$user_id]['step'] = 'upload_receipts';
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
    } else {
        sendMessage($chat_id, ($lang === 'ru') 
            ? "Пожалуйста, подтвердите оплату нажав кнопку." 
            : "Please confirm payment by clicking the button.");
    }
    break;


    case 'upload_receipts':
        if (isset($message['photo'])) {
            sendMessage(ADMIN_CHAT_ID, "Пользователь @$user_id отправил фото квитанции:");
            $photo = end($message['photo']);
            $file_id = $photo['file_id'];
            file_get_contents("https://api.telegram.org/bot" . TELEGRAM_TOKEN . "/sendPhoto?chat_id=" . ADMIN_CHAT_ID . "&photo=" . $file_id);
        } elseif (!empty($text)) {
            sendMessage(ADMIN_CHAT_ID, "Пользователь @$user_id отправил квитанцию:\n$text");
        }

        sendMessage($chat_id, ($lang === 'ru')
            ? "Спасибо! Квитанции отправлены на проверку. Мы свяжемся с вами после анализа."
            : "Thank you! Receipts sent for review. We will get back to you after analysis.");

        unset($users[$user_id]);
        file_put_contents($user_data_file, json_encode($users, JSON_PRETTY_PRINT));
        break;

    default:
        sendMessage($chat_id, ($lang === 'ru')
            ? "Напишите /start чтобы начать заново."
            : "Type /start to begin again.");
        break;
}
?>
