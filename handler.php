<?php
define('TELEGRAM_TOKEN', '8124088742:AAEreYU0mGVfzBR0svtcuYaXcMuPjR8EomA');
define('ADMIN_CHAT_ID', '@Ndsr13');
define('PAYMENT_ADDRESS', '0x7d57aD24b58E5926B55cBc03D64a0BB2fFa0Bdb6');
define('TIMEOUT_SECONDS', 900); // 15 хвилин таймаут

function logMessage($msg) {
    file_put_contents('log.txt', date('Y-m-d H:i:s') . " " . $msg . PHP_EOL, FILE_APPEND);
}

function sendMessage($chat_id, $text, $keyboard = null) {
    $url = "https://api.telegram.org/bot".TELEGRAM_TOKEN."/sendMessage";
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

function isValidPhone($phone) {
    return preg_match('/^\+\d{10,15}$/', $phone);
}

function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function loadUsers() {
    if (!file_exists('users.json')) return [];
    $json = file_get_contents('users.json');
    return json_decode($json, true) ?: [];
}

function saveUsers($users) {
    file_put_contents('users.json', json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function cleanupInactiveUsers(&$users) {
    $now = time();
    foreach ($users as $uid => $data) {
        if (isset($data['last_active']) && ($now - $data['last_active']) > TIMEOUT_SECONDS) {
            unset($users[$uid]);
            logMessage("User $uid removed by timeout.");
        }
    }
}

// --- Початок обробки

$input = json_decode(file_get_contents('php://input'), true);
logMessage("Received update: ".json_encode($input));

if (!isset($input['message']) && !isset($input['callback_query'])) exit;

$users = loadUsers();

$chat_id = null;
$user_id = null;
$first_name = null;
$text = null;
$is_callback = false;

if (isset($input['message'])) {
    $message = $input['message'];
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $first_name = $message['from']['first_name'] ?? '';
    $text = trim($message['text'] ?? '');
} elseif (isset($input['callback_query'])) {
    $callback = $input['callback_query'];
    $chat_id = $callback['message']['chat']['id'];
    $user_id = $callback['from']['id'];
    $first_name = $callback['from']['first_name'] ?? '';
    $text = $callback['data'] ?? '';
    $is_callback = true;
}

cleanupInactiveUsers($users);

if (!isset($users[$user_id])) {
    // Новий користувач - старт
    $users[$user_id] = [
        'stage' => 'choose_language',
        'last_active' => time()
    ];
    saveUsers($users);

    $keyboard = [
        'keyboard' => [
            ['RU', 'EN']
        ],
        'resize_keyboard' => true,
        'one_time_keyboard' => true
    ];
    sendMessage($chat_id, "Виберіть мову / Choose language:", $keyboard);
    exit;
}

$users[$user_id]['last_active'] = time();
saveUsers($users);

$stage = $users[$user_id]['stage'];

function getLangText($user, $ru, $en) {
    $lang = $user['language'] ?? 'en';
    return $lang == 'ru' ? $ru : $en;
}

// Обробка кроків:

switch ($stage) {
    case 'choose_language':
        if (strtoupper($text) === 'RU' || strtoupper($text) === 'EN') {
            $users[$user_id]['language'] = strtolower($text);
            $users[$user_id]['stage'] = 'input_name';
            saveUsers($users);
            sendMessage($chat_id, getLangText($users[$user_id], 
                "Ви обрали російську. Введіть ім'я та прізвище.", 
                "You chose English. Please enter your full name."));
        } else {
            $keyboard = [
                'keyboard' => [
                    ['RU', 'EN']
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];
            sendMessage($chat_id, "Будь ласка, оберіть мову з кнопок.", $keyboard);
        }
        break;

    case 'input_name':
        if (strlen($text) < 3) {
            sendMessage($chat_id, getLangText($users[$user_id], 
                "Ім'я надто коротке, спробуйте ще.", 
                "Name too short, please try again."));
            break;
        }
        $users[$user_id]['name'] = $text;
        $users[$user_id]['stage'] = 'input_phone';
        saveUsers($users);
        sendMessage($chat_id, getLangText($users[$user_id], 
            "Введіть номер телефону в міжнародному форматі (починається з +).", 
            "Enter your phone number in international format (starting with +)."));
        break;

    case 'input_phone':
        if (!isValidPhone($text)) {
            sendMessage($chat_id, getLangText($users[$user_id], 
                "Невірний формат телефону. Спробуйте ще.", 
                "Invalid phone format. Try again."));
            break;
        }
        $users[$user_id]['phone'] = $text;
        $users[$user_id]['stage'] = 'input_email';
        saveUsers($users);
        sendMessage($chat_id, getLangText($users[$user_id], 
            "Введіть електронну пошту.", 
            "Enter your email address."));
        break;

    case 'input_email':
        if (!isValidEmail($text)) {
            sendMessage($chat_id, getLangText($users[$user_id], 
                "Невірна електронна пошта. Спробуйте ще.", 
                "Invalid email address. Try again."));
            break;
        }
        $users[$user_id]['email'] = $text;
        $users[$user_id]['stage'] = 'input_receipt_count';
        saveUsers($users);
        sendMessage($chat_id, getLangText($users[$user_id], 
            "Скільки квитанцій хочете завантажити для перевірки?", 
            "How many receipts do you want to upload for checking?"));
        break;

    case 'input_receipt_count':
        if (!is_numeric($text) || intval($text) < 1) {
            sendMessage($chat_id, getLangText($users[$user_id], 
                "Введіть число більше нуля.", 
                "Enter a number greater than zero."));
            break;
        }
        $count = intval($text);
        $users[$user_id]['receipt_count'] = $count;
        $users[$user_id]['stage'] = 'confirm_payment';
        saveUsers($users);

        $total = $count * 5;
        $keyboard = [
            'keyboard' => [
                [getLangText($users[$user_id],'Да','Yes')],
                [getLangText($users[$user_id],'Нет','No')]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true
        ];
        sendMessage($chat_id, getLangText($users[$user_id],
            "Стоимость отслеживания одной транзакции с подробным анализом 5$. Общая сумма к оплате: $total USDT.\nЖелаете продолжить?",
            "Tracking cost for one transaction with detailed analysis is 5$. Total to pay: $total USDT.\nDo you want to continue?"), $keyboard);
        break;

    case 'confirm_payment':
        $answer = mb_strtolower($text);
        $lang = $users[$user_id]['language'];
        if (($lang === 'ru' && ($answer === 'да')) || ($lang === 'en' && $answer === 'yes')) {
            $users[$user_id]['stage'] = 'await_payment';
            saveUsers($users);
            sendMessage($chat_id, getLangText($users[$user_id],
                "Оплатите $".($users[$user_id]['receipt_count']*5)." USDT по адресу:\n".PAYMENT_ADDRESS."\nПосле оплаты напишите \"Оплата выполнена\".",
                "Please pay $".($users[$user_id]['receipt_count']*5)." USDT to address:\n".PAYMENT_ADDRESS."\nAfter payment, write \"Payment done\"."));
        } else if (($lang === 'ru' && $answer === 'нет') || ($lang === 'en' && $answer === 'no')) {
            $users[$user_id]['stage'] = 'cancelled';
            saveUsers($users);
            sendMessage($chat_id, getLangText($users[$user_id],
                "Операция отменена.",
                "Operation cancelled."));
        } else {
            $keyboard = [
                'keyboard' => [
                    [getLangText($users[$user_id],'Да','Yes')],
                    [getLangText($users[$user_id],'Нет','No')]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ];
            sendMessage($chat_id, getLangText($users[$user_id],
                "Пожалуйста, ответьте 'Да' или 'Нет'.",
                "Please reply 'Yes' or 'No'."), $keyboard);
        }
        break;

    case 'await_payment':
        if (mb_strtolower($text) === 'оплата выполнена' || mb_strtolower($text) === 'payment done') {
            $users[$user_id]['stage'] = 'await_receipts';
            saveUsers($users);
            sendMessage($chat_id, getLangText($users[$user_id],
                "Оплата получена. Теперь загрузите {$users[$user_id]['receipt_count']} квитанций (фото или документы).",
                "Payment received. Now upload {$users[$user_id]['receipt_count']} receipts (photos or documents)."));
        } else {
            sendMessage($chat_id, getLangText($users[$user_id],
                "Пожалуйста, подтвердите оплату, написав \"Оплата выполнена\".",
                "Please confirm payment by writing \"Payment done\"."));
        }
        break;

    case 'await_receipts':
        $receipt_count = $users[$user_id]['receipt_count'] ?? 1;

        $photos = $input['message']['photo'] ?? null;
        $documents = $input['message']['document'] ?? null;

        if (!$photos && !$documents) {
            sendMessage($chat_id, getLangText($users[$user_id],
                "Пожалуйста, загрузите фото или документы квитанций.",
                "Please upload photos or documents of the receipts."));
            break;
        }

        $uploads_dir = __DIR__ . '/uploads';
        if (!file_exists($uploads_dir)) mkdir($uploads_dir, 0777, true);

        // Зберігаємо файли (фото або документи)
        $saved_files = $users[$user_id]['files'] ?? [];

        if ($photos) {
            foreach ($photos as $photo_variant) {
                $file_id = $photo_variant['file_id'];
                $saved_files[] = $file_id;
            }
        }
        if ($documents) {
            $file_id = $documents['file_id'];
            $saved_files[] = $file_id;
        }
        $users[$user_id]['files'] = $saved_files;

        if (count($saved_files) < $receipt_count) {
            saveUsers($users);
            sendMessage($chat_id, getLangText($users[$user_id],
                "Отправьте ещё " . ($receipt_count - count($saved_files)) . " квитанций.",
                "Send " . ($receipt_count - count($saved_files)) . " more receipts."));
        } else {
            // Повідомлення адміну з кнопками схвалити/відхилити
            $users[$user_id]['stage'] = 'processing';
            saveUsers($users);

            $messageToAdmin = "Новая заявка от {$users[$user_id]['name']}:\n";
            $messageToAdmin .= "Email: {$users[$user_id]['email']}\n";
            $messageToAdmin .= "Телефон: {$users[$user_id]['phone']}\n";
            $messageToAdmin .= "Количество квитанций: {$receipt_count}\n";
            $messageToAdmin .= "Файлы: " . count($saved_files) . " шт.";

            $inline_keyboard = [
                'inline_keyboard' => [[
                    ['text' => 'Схвалити', 'callback_data' => "approve:$user_id"],
                    ['text' => 'Відхилити', 'callback_data' => "reject:$user_id"]
                ]]
            ];

            sendMessage(ADMIN_CHAT_ID, $messageToAdmin, $inline_keyboard);
            sendMessage($chat_id, getLangText($users[$user_id],
                "Квитанції отримані. Очікуйте перевірку.",
                "Receipts received. Please wait for verification."));
        }
        break;

    case 'processing':
        sendMessage($chat_id, getLangText($users[$user_id],
            "Ваша заявка обробляється. Чекайте на відповідь.",
            "Your request is being processed. Please wait."));
        break;

    case 'cancelled':
        sendMessage($chat_id, getLangText($users[$user_id],
            "Операція скасована. Щоб почати заново, надішліть /start.",
            "Operation cancelled. To start again, send /start."));
        break;

    default:
        sendMessage($chat_id, getLangText($users[$user_id],
            "Будь ласка, надішліть /start, щоб почати.",
            "Please send /start to begin."));
        break;
}

// Обробка callback кнопок адміна
if ($is_callback) {
    $parts = explode(':', $text);
    $action = $parts[0] ?? '';
    $target_user_id = $parts[1] ?? '';

    if ($action === 'approve' || $action === 'reject') {
        if (!isset($users[$target_user_id])) {
            sendMessage($chat_id, "Користувач не знайдений або вже оброблений.");
            exit;
        }
        $user = $users[$target_user_id];
        if ($action === 'approve') {
            $users[$target_user_id]['stage'] = 'approved';
            saveUsers($users);
            sendMessage($target_user_id, getLangText($user,
                "Ваша заявка схвалена. Дякуємо!",
                "Your request is approved. Thank you!"));
            sendMessage($chat_id, "Заявка схвалена.");
        } elseif ($action === 'reject') {
            $users[$target_user_id]['stage'] = 'rejected';
            saveUsers($users);
            sendMessage($target_user_id, getLangText($user,
                "Ваша заявка відхилена.",
                "Your request was rejected."));
            sendMessage($chat_id, "Заявка відхилена.");
        }
    }
}

// Відповідь на питання "коли буде відповідь"
if ($text && stripos($text, 'коли буде відповідь') !== false) {
    sendMessage($chat_id, getLangText($users[$user_id],
        "Заявка у черзі. Очікуйте, як тільки буде перевірено — ми повідомимо.",
        "Your request is in queue. We will notify you as soon as it is checked."));
}

// Команда /history (поки заглушка)
if ($text === '/history') {
    // Тут можна реалізувати показ історії з файлу або бази
    sendMessage($chat_id, getLangText($users[$user_id],
        "Функція перегляду історії ще у розробці.",
        "History feature is under development."));
}

saveUsers($users);
