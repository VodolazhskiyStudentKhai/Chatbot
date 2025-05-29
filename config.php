<?php
//Налаштування боту
$botToken = '';
$website = 'https://api.telegram.org/bot' . $botToken;

//БД
$host = 'localhost:3306';
$user = 'root';
$password = 'root';
$database = 'chatbot';

//Словник
define("BUTTON1", "Створити подію");
define("BUTTON2", "Минулі події");
define("BUTTON3", "Майбутні події");
define("BUTTON4", "Видалити подію");
define("BUTTON5", "Тести");
define("BUTTON6", "Статистика");

define("IBUTTONY", "Так");
define("IBUTTONN", "Ні");
define("IBUTTONQR", "Посилання");
define("IBUTTONREG", "Я буду");
define("IBUTTONUNREG", "Відмінити участь");
define("IBUTTONMEM", "Учасники події");
define("IBUTTONFORW", "➡ Вперед");
define("IBUTTONBACK", "⬅ Назад");

define("MSG_WELCOME", "Вітаю Вас, використайте меню!");
define("MSG_ADMINFLAG", "Ви маєте адмін-права!");
define("MSG_MENUINFO", "Будь-ласка використовуйте меню!");
define("MSG_DELETE", "Введіть ID події для видалення ('--' для скасування):");
define("MSG_EVUNDO", "Операція скасована!");
define("MSG_EVENTER", "Введіть назву події(-- для скасування):");
define("MSG_LIMLEN", "Назва занадто довга (макс 64 символів)");
define("MSG_ENTERDATE", "Введіть дату події:");
define("MSG_DATEFORMAT", "Дата повинна бути в форматі Рік-місяць-число(наприклад 2025-05-20)");

define("MSG_EVINFO", "Опишіть подію:");
define("MSG_EVADDED", "Подію було додано");
define("MSG_EVNOTFOUND", "Подію не знайдено або вона була видалена");
define("MSG_EVDELETED", "Подія видалена");
define("MSG_TESTUNDO", "Тест скасовано");
define("MSG_TESTAV", "Доступні тести:");
define("MSG_TESTSTAT", "Переглянути статистику:");
define("MSG_TESTNOTFOUND", "Тест не знайдено або видалено");
define("MSG_TESTRESULT", "Тест завершено. Результат ");
define("MSG_TESTCORRECT", "Правильна відповідь:");
define("MSG_QUESNOTFOUND", "Питання для тесту недоступні.");
define("MSG_REG", "Ви зареєструвалися на подію!");
define("MSG_UNREG", "Ви скасували реєстрацію на подію.");
define("MSG_ERREG", "Помилка при зміні статусу реєстрації.");
define("MSG_STAT", "Статистика для тесту <b>%s</b>:\nУнікальних користувачів: %d\nПройдено разів: %d\nЗагальний відсоток правильних відповідей: %.2f%%");
define("MSG_STATDIAG", "Відповіді:\nНеправильні — %d\nПравильні — %d");
define('MSG_NOMEMBERS', 'На подію «%s» нікого немає.');
define('MSG_MEMBERS_LIST', "Подія: «%s»\nУчасники:\n- %s");
define("MSG_TESTQUEST", "Бажаєте пройти тест <b>%s</b>?");
?>
