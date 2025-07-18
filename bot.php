<?php
require_once('config.php');

$offset = 0;

while (true) {
    $updates = json_decode(file_get_contents("https://api.telegram.org/bot$token/getUpdates?offset=$offset"), true);

    foreach ($updates["result"] as $update) {
        $chat_id = $update["message"]["chat"]["id"];
        $text = $update["message"]["text"];

        // Відповідь
        file_get_contents("https://api.telegram.org/bot$token/sendMessage?chat_id=$chat_id&text=Привіт, я активний!");

        // Лог
        file_put_contents("log.txt", json_encode($update, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

        // Зсув offset
        $offset = $update["update_id"] + 1;
    }

    sleep(1);
}
?>