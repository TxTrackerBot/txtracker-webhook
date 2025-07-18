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

// Продовження збирати дані - наступні частини коду я пришлю далі...

http_response_code(200);
