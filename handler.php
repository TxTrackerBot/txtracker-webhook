<?php
// Простий PHP Telegram бот з сесіями у файлах

$token = getenv('TELEGRAM_TOKEN');
$input = json_decode(file_get_contents('php://input'), true);

$chat_id = $input['message']['chat']['id'] ?? null;
$text = trim($input['message']['text'] ?? '');
$callback_data = $input['callback_query']['data'] ?? null;
$callback_id = $input['callback_query']['id'] ?? null;

if (!$chat_id) {
    http_response_code(200);
    exit;
}

define('DATA_DIR', __DIR__ . '/data');
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0777, true);
}

// Функції для роботи з "сесією"
function saveSession($chat_id, $data) {
    file_put_contents(DATA_DIR . "/$chat_id.json", json_encode($data));
}
function loadSession($chat_id) {
    $file = DATA_DIR . "/$chat_id.json";
    if (file_exists($file)) {
        return json_decode(file_get_contents($file), true);
    }
    return ['stage' => 'start'];
}

// Відправка повідомлень
function sendMessage($chat_id, $text, $reply_markup = null) {
    global $token;
    $data = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $data['reply_markup'] = json_encode($reply_markup);
    }
    file_get_contents("https://api.telegram.org/bot$token/sendMessage?" . http_build_query($data));
}

// Обробка callback_query (для кнопок)
if ($callback_data && $callback_id) {
    $session = loadSession($chat_id);
    if ($callback_data === 'lang_uk') {
        $session['lang'] = 'uk';
        $session['stage'] = 'ask_name';
        saveSession($chat_id, $session);
        sendMessage($chat_id, "Дякую! Будь ласка, введіть ваше ПІБ:");
    } elseif ($callback_data === 'lang_ru') {
        $session['lang'] = 'ru';
        $session['stage'] = 'ask_name';
        saveSession($chat_id, $session);
        sendMessage($chat_id, "Спасибо! Пожалуйста, введите ваше ФИО:");
    }
    // Підтверджуємо callback, щоб кнопки не "крутило"
    file_get_contents("https://api.telegram.org/bot$token/answerCallbackQuery?callback_query_id=$callback_id");
    http_response_code(200);
    exit;
}

$session = loadSession($chat_id);

// Стартова логіка
if ($session['stage'] === 'start') {
    // Пропонуємо вибір мови
    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => 'Українська 🇺🇦', 'callback_data' => 'lang_uk'],
                ['text' => 'Русский 🇷🇺', 'callback_data' => 'lang_ru']
            ]
        ]
    ];
    sendMessage($chat_id, "Вітаємо! Будь ласка, оберіть мову / Пожалуйста, выберите язык:", $keyboard);
    $session['stage'] = 'lang_choice';
    saveSession($chat_id, $session);
    http_response_code(200);
    exit;
}

// Функція для відправки повідомлення з урахуванням мови
function getText($key, $lang) {
    $texts = [
        'uk' => [
            'ask_name' => "Введіть, будь ласка, ваше ПІБ:",
            'ask_phone' => "Введіть номер телефону:",
            'ask_email' => "Введіть ваш Email:",
            'ask_country' => "Вкажіть країну проживання:",
            'payment_info' => "Перевірка однієї квитанції коштує 5 доларів.\n" .
                              "Оплата доступна криптовалютою за реквізитами:\n" .
                              "BTC: bc1qexampleaddress...\n" .
                              "ETH: 0xExampleAddress...\n\n" .
                              "Після оплати надішліть, будь ласка, квитанцію для перевірки.",
            'thanks' => "Дякуємо! Очікуйте на перевірку.",
            'invalid_email' => "Невірний формат Email. Спробуйте ще раз.",
            'invalid_phone' => "Невірний формат телефону. Введіть номер у форматі +380XXXXXXXXX або подібному."
        ],
        'ru' => [
            'ask_name' => "Введите, пожалуйста, ваше ФИО:",
            'ask_phone' => "Введите номер телефона:",
            'ask_email' => "Введите ваш Email:",
            'ask_country' => "Укажите страну проживания:",
            'payment_info' => "Проверка одной квитанции стоит 5 долларов.\n" .
                              "Оплата доступна криптовалютой по реквизитам:\n" .
                              "BTC: bc1qexampleaddress...\n" .
                              "ETH: 0xExampleAddress...\n\n" .
                              "После оплаты отправьте, пожалуйста, квитанцию для проверки.",
            'thanks' => "Спасибо! Ожидайте проверку.",
            'invalid_email' => "Неверный формат Email. Попробуйте еще раз.",
            'invalid_phone' => "Неверный формат телефона. Введите номер в формате +7XXXXXXXXXX или подобном."
        ],
    ];
    return $texts[$lang][$key] ?? '';
}

// Валідація Email (проста)
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// Валідація телефону (проста, можна допрацювати)
function isValidPhone($phone) {
    return preg_match('/^\+?[0-9\s\-\(\)]{7,}$/', $phone);
}

// Обробка текстових повідомлень по стадіям збору даних
if ($session['stage'] === 'ask_name' && !empty($text)) {
    $session['name'] = $text;
    $session['stage'] = 'ask_phone';
    saveSession($chat_id, $session);
    sendMessage($chat_id, getText('ask_phone', $session['lang']));
    http_response_code(200);
    exit;
}

if ($session['stage'] === 'ask_phone' && !empty($text)) {
    if (!isValidPhone($text)) {
        sendMessage($chat_id, getText('invalid_phone', $session['lang']));
        http_response_code(200);
        exit;
    }
    $session['phone'] = $text;
    $session['stage'] = 'ask_email';
    saveSession($chat_id, $session);
    sendMessage($chat_id, getText('ask_email', $session['lang']));
    http_response_code(200);
    exit;
}

if ($session['stage'] === 'ask_email' && !empty($text)) {
    if (!isValidEmail($text)) {
        sendMessage($chat_id, getText('invalid_email', $session['lang']));
        http_response_code(200);
        exit;
    }
    $session['email'] = $text;
    $session['stage'] = 'ask_country';
    saveSession($chat_id, $session);
    sendMessage($chat_id, getText('ask_country', $session['lang']));
    http_response_code(200);
    exit;
}

if ($session['stage'] === 'ask_country' && !empty($text)) {
    $session['country'] = $text;
    $session['stage'] = 'payment_info';
    saveSession($chat_id, $session);
    sendMessage($chat_id, getText('payment_info', $session['lang']));
    http_response_code(200);
    exit;
}
// Обробка отримання квитанції (фото або документ)
if ($session['stage'] === 'payment_info') {
    // Перевіряємо, чи надіслано фото або документ
    if (!empty($input['message']['photo'])) {
        // Отримаємо file_id останнього фото (найкращої якості)
        $file_id = end($input['message']['photo'])['file_id'];
    } elseif (!empty($input['message']['document'])) {
        $file_id = $input['message']['document']['file_id'];
    } else {
        // Якщо ні фото, ні документ - нагадуємо надіслати квитанцію
        sendMessage($chat_id, getText('payment_info', $session['lang']));
        http_response_code(200);
        exit;
    }

    // Збережемо file_id у сесії (або можна завантажити файл з Telegram, якщо потрібно)
    $session['receipt_file_id'] = $file_id;
    $session['stage'] = 'receipt_received';
    saveSession($chat_id, $session);

    sendMessage($chat_id, getText('thanks', $session['lang']) . "\n\n" .
        ($session['lang'] === 'uk' ? "Ми перевіримо вашу квитанцію найближчим часом." : "Мы проверим вашу квитанцию в ближайшее время.")
    );
    http_response_code(200);
    exit;
}
