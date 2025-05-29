<?php
require_once 'db.php';
require_once 'config.php';

$update = file_get_contents('php://input');
$update = json_decode($update, true);

if($update == null)
	return;

$callback_query = $update["callback_query"] ?? null;

$db = DB::getInstance($host, $user, $password, $database);

if ($callback_query) {
    $chatId = $callback_query["message"]["chat"]["id"];
    $data = $callback_query["data"];
    handleCallback($chatId, $data);
    exit;
}

$chatId = $update["message"]["chat"]["id"];
$message = $update["message"]["text"] ?? '';

$first_name = $update["message"]["from"]["first_name"] ?? '';
$second_name = $update["message"]["from"]["last_name"] ?? '';
$nickname = $update["message"]["from"]["username"] ?? '';

$role = getRole($chatId);

createOrUpdateUser($chatId, $first_name, $second_name, $nickname);

if (strpos($message, '/start') === 0) {
	$data = explode(' ', $message);
	$command = $data[0];
	$param = $data[1] ?? null;

    if ($param === null) {
        getMainKeyboard($chatId, MSG_WELCOME);
    } else {
        switch (substr($param, 0, 1)) {
            case 'e':
                $eventId = intval(substr($param, strlen('e')));
                sendEventToUser($chatId, $eventId);
                break;
            case 't':
                $testId = intval(substr($param, strlen('t')));
                sendQuestionToUser($chatId, $testId);
                break;
        }
    }
} elseif ($message == BUTTON1 || getPendingStep($chatId) != -1) {
    checkEvent($chatId);
} elseif ($message == BUTTON2) {
    sendEvents($chatId, 0, 1);
} elseif ($message == BUTTON3) {
    sendEvents($chatId, 0, 0);
} elseif ($message == BUTTON4) {
    startPendingDeleteEvent($chatId);
} elseif ($message === BUTTON5) {
    sendTests($chatId);
} elseif ($message === BUTTON6) {
    sendTests($chatId, 0, 1);
}
else {
	getMainKeyboard($chatId, MSG_MENUINFO);
}

$db->closeConnection();

function getRole($user_id) {
    global $db;
    $result = $db->query("SELECT id_role FROM user_list WHERE id_user = ?", [$user_id], "i");
    return !empty($result) ? $result[0]['id_role'] : -1;
}

function createOrUpdateUser($user_id, $first_name, $second_name, $nickname) {
    global $db;
    $db->execute(
        "INSERT INTO user_list (id_user, first_name, second_name, nickname, id_role) VALUES (?, ?, ?, ?, DEFAULT) 
         ON DUPLICATE KEY UPDATE first_name = VALUES(first_name), second_name = VALUES(second_name), nickname = VALUES(nickname)",
        [$user_id, $first_name, $second_name, $nickname],
        "isss"
    );
}

function getMainKeyboard($chatId, $text) {
	global $role;

	$isAdmin = $role === 1;
	
    if ($isAdmin) {
        $keyboard = [
            'keyboard' => [
                [BUTTON1, BUTTON4],
                [BUTTON2, BUTTON3],
                [BUTTON5, BUTTON6]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    } else {
        $keyboard = [
            'keyboard' => [
                [BUTTON2, BUTTON3],
                [BUTTON5]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }
    sendTelegramRequest($chatId, $text, ['reply_markup' => $keyboard]);
}

function validateDate($date, $format = 'Y-m-d') {
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

function startPendingDeleteEvent($chatId) {
    global $db;
	global $role;
	
	if($role !== 1) {
		return;
	}
	
    $db->execute("INSERT INTO pending_events(user_id, step) VALUES (?, -2) ON DUPLICATE KEY UPDATE step = -2", [$chatId], "i");
    sendMessage($chatId, MSG_DELETE);
}

function deleteEventById($chatId, $eventId) {
    global $db;
    $affected = $db->execute("DELETE FROM events WHERE event_id = ?", [$eventId], "i");
    undoEvent($chatId);
    ($affected > 0) ? sendMessage($chatId, MSG_EVDELETED) : sendMessage($chatId, MSG_EVNOTFOUND);
}

function startPendingEvent($chatId) {
    global $db;
    $db->execute("INSERT INTO pending_events(user_id) VALUES (?) ON DUPLICATE KEY UPDATE user_id = user_id", [$chatId], "i");
}

function updatePendingEvent($chatId, $value, $column, $step) {
    $allowed = ['event_name', 'event_date', 'event_info'];
    if (!in_array($column, $allowed)) return false;

    global $db;
    return $db->execute("UPDATE pending_events SET $column = ?, step = ? WHERE user_id = ?", [$value, $step, $chatId], "sii");
}

function finalizeEvent($chatId) {
    global $db;
    $result = $db->query("SELECT event_name, event_date, event_info FROM pending_events WHERE user_id = ?", [$chatId], "i");

    if (!empty($result)) {
        $row = $result[0];
        $db->execute("INSERT INTO events(event_name, event_date, event_info) VALUES (?, ?, ?)", [$row['event_name'], $row['event_date'], $row['event_info']], "sss");
        $db->execute("DELETE FROM pending_events WHERE user_id = ?", [$chatId], "i");
    }
}

function undoEvent($chatId) {
    global $db;
    $db->execute("DELETE FROM pending_events WHERE user_id = ?", [$chatId], "i");
}

function getPendingStep($chatId) {
    global $db;
    $result = $db->query("SELECT step FROM pending_events WHERE user_id = ?", [$chatId], "i");
    return !empty($result) ? $result[0]['step'] : -1;
}

function checkEvent($chatId) {
    global $message;
	global $role;
	
	if($role !== 1) {
		return;
	}
	
    if ($message === "--") {
        undoEvent($chatId);
        sendMessage($chatId, MSG_EVUNDO);
        return;
    }

    $step = getPendingStep($chatId);

    switch ($step) {
        case -2:
            if (!ctype_digit($message)) {
                sendMessage($chatId, MSG_DELETE);
                return;
            }
            deleteEventById($chatId, $message);
            break;
        case -1:
            startPendingEvent($chatId);
            sendMessage($chatId, MSG_EVENTER);
            break;
        case 0:
            if (mb_strlen($message) > 64) {
                sendMessage($chatId, MSG_LIMLEN);
                return;
            }
            updatePendingEvent($chatId, $message, "event_name", $step + 1);
            sendMessage($chatId, MSG_ENTERDATE);
            break;
        case 1:
            if (!validateDate($message)) {
                sendMessage($chatId, MSG_DATEFORMAT);
                return;
            }
            updatePendingEvent($chatId, $message, "event_date", $step + 1);
            sendMessage($chatId, MSG_EVINFO);
            break;
        case 2:
            updatePendingEvent($chatId, $message, "event_info", $step + 1);
            finalizeEvent($chatId);
            sendMessage($chatId, MSG_EVADDED);
            break;
    }
}

function isValidTest($testId) {
    global $db;
    $result = $db->query("SELECT t.test_id FROM tests t JOIN test_questions tq ON t.test_id = tq.test_id WHERE t.test_id = ?", [$testId], "i");
    return !empty($result);
}

function isValidEvent($eventId) {
    global $db;
    $result = $db->query("SELECT * FROM events WHERE event_id = ?", [$eventId], "i");
    return !empty($result);
}

function startPendingTest($chatId, $testId) {
    global $db;
    $db->execute("INSERT INTO pending_tests (user_id, test_id, question_id, step) VALUES (?, ?, 0, 0) ON DUPLICATE KEY UPDATE test_id = ?, question_id = 0, step = 0", [$chatId, $testId, $testId], "iii");
}

function updatePendingTest($chatId, $questionId, $step) {
    global $db;
    $db->execute("UPDATE pending_tests SET question_id = ?, step = ? WHERE user_id = ?", [$questionId, $step, $chatId], "iii");
}

function clearPendingTest($chatId) {
    global $db;
    $db->execute("DELETE FROM pending_tests WHERE user_id = ?", [$chatId], "i");
}

function getNextQuestion($testId, $currentQuestionId = 0) {
    global $db;
    $result = $db->query("
        SELECT q.id, q.question_text, q.options, q.correct_option FROM questions q
        JOIN test_questions tq ON q.id = tq.question_id WHERE tq.test_id = ? AND q.id > ?
        ORDER BY q.id ASC LIMIT 1
    ", [$testId, $currentQuestionId], "ii");
    return !empty($result) ? $result[0] : null;
}

function sendQuestion($chatId, $question) {
    global $callback_query;
    $messageId = $callback_query["message"]["message_id"];

    $options = json_decode($question['options'], true);
    if (!$options) {
        return;
    }

    $keyboard = ['inline_keyboard' => []];

    foreach ($options as $index => $option) {
        $keyboard['inline_keyboard'][] = [
            ['text' => trim($option), 'callback_data' => "answer_{$question['id']}_" . ($index + 1)]
        ];
    }
    sendTelegramRequest($chatId, $question['question_text'], ['action' => 'editMessageText', 'reply_markup' => $keyboard]);
}

function handleCallback($chatId, $data) {
    if (($eventId = findParam($data, 'show_')) !== null) {
        showEvent($chatId, $eventId);
    } elseif (($eventId = findParam($data, 'members_')) !== null) {
        showMembers($chatId, $eventId);
    } elseif (($eventId = findParam($data, 'fshow_')) !== null) {
        showEvent($chatId, $eventId, 1);
    } elseif (($page = findParam($data, 'prev_')) !== null) {
        sendEvents($chatId, $page, 1);
    } elseif (($page = findParam($data, 'future_')) !== null) {
        sendEvents($chatId, $page, 0);
    } elseif (($testId = findParam($data, 'start_test_')) !== null) {
        start_t($chatId, $testId);
    } elseif (strpos($data, 'answer_') === 0) {
        handleAnswer($chatId, $data);
    } elseif (($testId = findParam($data, 'test_')) !== null) {
        sendQuestionToUser($chatId, $testId);
    } elseif (($testId = findParam($data, 'stat_')) !== null) {
        getStatById($chatId, $testId);
    } elseif (($page = findParam($data, 'tests_')) !== null) {
        sendTests($chatId, $page);
    } elseif (($page = findParam($data, 'stats_')) !== null) {
        sendTests($chatId, $page, 1);
    } elseif (($testId = findParam($data, 'qrt_')) !== null) {
        genTestQR($chatId, $testId);
    } elseif (($eventId = findParam($data, 'qre_')) !== null) {
        genEventQR($chatId, $eventId);
    } elseif (($eventId = findParam($data, 'reg_')) !== null) {
        showEventButtons($chatId, $eventId);
    } elseif ($data === 'cancel_test') {
        editLastMsg($chatId, MSG_TESTUNDO);
    }
}

function findParam($data, $prefix) {
    if (strpos($data, $prefix) === 0) {
        return intval(substr($data, strlen($prefix)));
    }
    return null;
}

function handleAnswer($chatId, $data) {
    $parts = explode('_', $data);
    $questionId = $parts[1];
    $selectedOption = $parts[2];

    global $db;
    $result = $db->query("
        SELECT tq.test_id, q.correct_option FROM questions q JOIN test_questions tq ON q.id = tq.question_id WHERE q.id = ?
    ", [$questionId], "i");

    if (empty($result)) return;

    $question = $result[0];
    $testId = $question['test_id'];
    $correctOption = $question['correct_option'];
    $isCorrect = ($selectedOption == $correctOption) ? 1 : 0;

    saveTestResult($chatId, $testId, $questionId, $selectedOption, $isCorrect);

    $nextQuestion = getNextQuestion($testId, $questionId);
    if ($nextQuestion) {
        sendQuestion($chatId, $nextQuestion);
        updatePendingTest($chatId, $nextQuestion['id'], 1);
    } else {
        $results = getTestResults($chatId, $testId);
        $messageText = MSG_TESTRESULT . "<b>{$results['correct']}/{$results['total']}</b>\n\n";
        foreach ($results['errors'] as $error) {
            $part = MSG_TESTCORRECT;
            $messageText .= "{$error['question_text']} $part <i>{$error['correct_answer']}</i>\n";
        }
        clearPendingTest($chatId);
        editLastMsg($chatId, $messageText);
    }
}

function showEvent($chatId, $eventId, $reg = 0) {
    global $db;
    $result = $db->query("SELECT * FROM events WHERE event_id = ?", [$eventId], "i");

    if (!empty($result)) {
        showEventButtons($chatId, $eventId, 0, $reg ? 0 : 1);
    }
}

function showMembers($chatId, $eventId) {
    global $db;
    $result = $db->query("
        SELECT e.event_name, u.first_name, u.second_name, u.nickname FROM events e
        LEFT JOIN event_registrations er ON e.event_id = er.event_id LEFT JOIN user_list u ON er.user_id = u.id_user WHERE e.event_id = ?
    ", [$eventId], "i");

    $eventName = null;
    $members = [];

    foreach ($result as $row) {
        if ($eventName === null) {
            $eventName = $row['event_name'];
        }
        if ($row['first_name'] !== null || $row['second_name'] !== null || $row['nickname'] !== null) {
            $fullName = trim("{$row['first_name']} {$row['second_name']}");
            $nickname = $row['nickname'] ? "(@{$row['nickname']})" : '';
            $members[] = "$fullName $nickname";
        }
    }

    $message = empty($members) ? sprintf(MSG_NOMEMBERS, $eventName) : sprintf(MSG_MEMBERS_LIST, $eventName, implode("\n- ", $members));
    sendMessage($chatId, $message);
}

function saveTestResult($chatId, $testId, $questionId, $selectedOption, $isCorrect) {
    global $db;
    $result = $db->query("
        SELECT COALESCE(MAX(attempt), 0) + 1 AS next_attempt FROM test_results
        WHERE user_id = ? AND test_id = ? AND question_id = ?
    ", [$chatId, $testId, $questionId], "iii");

    $nextAttempt = !empty($result) ? $result[0]['next_attempt'] : 1;

    return $db->execute("
        INSERT INTO test_results (user_id, test_id, question_id, selected_option, is_correct, attempt, answered_at)
        VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ", [$chatId, $testId, $questionId, $selectedOption, $isCorrect, $nextAttempt], "iiiiii");
}

function getTestResults($chatId, $testId) {
    global $db;
    $summary = $db->query("
        SELECT COUNT(*) as total, SUM(is_correct) as correct FROM test_results
        WHERE user_id = ? AND test_id = ? AND attempt = (SELECT MAX(attempt) FROM test_results WHERE user_id = ? AND test_id = ?)
    ", [$chatId, $testId, $chatId, $testId], "iiii");

    $errors = $db->query("
        SELECT q.question_text, q.options, q.correct_option FROM test_results tr
        JOIN questions q ON tr.question_id = q.id
        WHERE tr.user_id = ? AND tr.test_id = ? AND tr.is_correct = 0 AND tr.attempt = (SELECT MAX(attempt) FROM test_results WHERE user_id = ? AND test_id = ?)
    ", [$chatId, $testId, $chatId, $testId], "iiii");

    $errorDetails = [];
    foreach ($errors as $row) {
        $options = json_decode($row['options'], true);
        $correctText = $options[$row['correct_option'] - 1] ?? $row['correct_option'];
        $errorDetails[] = [
            'question_text' => $row['question_text'],
            'correct_answer' => $correctText
        ];
    }

    return [
        'correct' => !empty($summary) ? $summary[0]['correct'] : 0,
        'total' => !empty($summary) ? $summary[0]['total'] : 0,
        'errors' => $errorDetails
    ];
}

function start_t($chatId, $testId) {
    startPendingTest($chatId, $testId);

    $question = getNextQuestion($testId);
    if ($question) {
        sendQuestion($chatId, $question);
        updatePendingTest($chatId, $question['id'], 1);
    } else {
        sendMessage($chatId, MSG_QUESNOTFOUND);
        clearPendingTest($chatId);
    }
}

function sendEvents($chatId, $page = 0, $past = 1) {
    global $db;
	global $role;
	
    $limit = 5;
    $offset = $page * $limit;

    if ($past) {
        $countResult = $db->query("SELECT COUNT(*) as total FROM events WHERE event_date < CURDATE()");
        $totalEvents = !empty($countResult) ? $countResult[0]['total'] : 0;
        $events = $db->query("SELECT event_id, event_name, event_date FROM events WHERE event_date < CURDATE() ORDER BY event_date DESC LIMIT ? OFFSET ?", [$limit, $offset], "ii");
        $msg = BUTTON2;
        $p = "prev_";
        $cb = "show_";
    } else {
        $countResult = $db->query("SELECT COUNT(*) as total FROM events WHERE event_date >= CURDATE()");
        $totalEvents = !empty($countResult) ? $countResult[0]['total'] : 0;
        $events = $db->query("SELECT event_id, event_name, event_date FROM events WHERE event_date >= CURDATE() ORDER BY event_date ASC LIMIT ? OFFSET ?", [$limit, $offset], "ii");
        $msg = BUTTON3;
        $p = "future_";
        $cb = "fshow_";
    }

    $buttons = [];

    foreach ($events as $row) {
        $button = $role ? sprintf("%d. %s | %s", $row['event_id'], $row['event_name'], $row['event_date']) : sprintf("%s | %s", $row['event_name'], $row['event_date']);
        $buttons[] = [['text' => $button, 'callback_data' => $cb . "{$row['event_id']}"]];
    }

    if ($page > 0) {
        $buttons[] = [['text' => IBUTTONBACK, 'callback_data' => $p . ($page - 1)]];
    }

    if ($offset + $limit < $totalEvents) {
        $buttons[] = [['text' => IBUTTONFORW, 'callback_data' => $p . ($page + 1)]];
    }

    if (empty($buttons) && $page > 0) {
        return;
    }

    $keyboard = ['inline_keyboard' => $buttons];
	
    sendTelegramRequest($chatId, $msg, ['reply_markup' => $keyboard]);
}

function sendTests($chatId, $page = 0, $stat = 0) {
    global $db;
	global $role;

    $limit = 5;
    $offset = $page * $limit;
    $tests = $db->query("SELECT test_id, test_name FROM tests ORDER BY test_id ASC LIMIT ? OFFSET ?", [$limit, $offset], "ii");

    if (!$stat) {
        $cb = "test_";
        $cbp = "tests_";
		$msg = MSG_TESTAV;
    } else {
		if($role !== 1) {
			return;
		}
		
        $cb = "stat_";
        $cbp = "stats_";
		$msg = MSG_TESTSTAT;
    }

    $buttons = [];
    foreach ($tests as $row) {
        $buttons[] = [['text' => "{$row['test_id']}. {$row['test_name']}", 'callback_data' => $cb . "{$row['test_id']}"]];
    }

    if ($page > 0) {
        $buttons[] = [['text' => IBUTTONBACK, 'callback_data' => $cbp . ($page - 1)]];
    }

    if (count($tests) == $limit) {
        $buttons[] = [['text' => IBUTTONFORW, 'callback_data' => $cbp . ($page + 1)]];
    }

    $keyboard = ['inline_keyboard' => $buttons];
    
	sendTelegramRequest($chatId, $msg, ['reply_markup' => $keyboard]);
}

function getEventNameById($eventId) {
    global $db;
    $result = $db->query("SELECT event_name FROM events WHERE event_id = ?", [$eventId], "i");
    return !empty($result) ? $result[0]['event_name'] : null;
}

function getTestNameById($testId) {
    global $db;
    $result = $db->query("SELECT test_name FROM tests WHERE test_id = ?", [$testId], "i");
    return !empty($result) ? $result[0]['test_name'] : null;
}

function sendEventToUser($chatId, $eventId) {
    global $db;
    $result = $db->query("SELECT event_date >= CURDATE() AS is_upcoming FROM events WHERE event_id = ?", [$eventId], "i");

    $isFuture = !empty($result) ? $result[0]['is_upcoming'] : null;

    if ($isFuture === null) {
        sendMessage($chatId, MSG_EVNOTFOUND);
        return;
    }

    showEvent($chatId, $eventId, $isFuture);
}

function isEventFuture($eventId) {
    global $db;
    $result = $db->query("SELECT event_date >= CURDATE() AS is_upcoming FROM events WHERE event_id = ?", [$eventId], "i");
    return !empty($result) ? $result[0]['is_upcoming'] : null;
}

function sendQuestionToUser($chatId, $testId, $parseMode = 'HTML') {
    if (!isValidTest($testId)) {
        sendMessage($chatId, MSG_TESTNOTFOUND);
        return;
    }

    $testName = getTestNameById($testId);
    $msg = sprintf(MSG_TESTQUEST, $testName);

    $keyboard = [
        'inline_keyboard' => [
            [
                ['text' => IBUTTONY, 'callback_data' => "start_test_$testId"],
                ['text' => IBUTTONN, 'callback_data' => 'cancel_test']
            ],
            [
                ['text' => IBUTTONQR, 'callback_data' => "qrt_$testId"]
            ]
        ]
    ];

    sendTelegramRequest($chatId, $msg, ['reply_markup' => $keyboard]);
}

function getStatById($chatId, $testId) {
    if (!isValidTest($testId)) {
        sendMessage($chatId, MSG_TESTNOTFOUND);
        return;
    }

    $stats = getTestStatistics($testId);
    $testName = getTestNameById($testId);
    $d = [100.0 - $stats['percentage_correct'], $stats['percentage_correct']];
    $d_i = [$stats['total_answers'] - $stats['correct_answers'], $stats['correct_answers']];

    $message = sprintf(MSG_STAT, $testName, $stats['total_users'], $stats['total_attempts'], $stats['percentage_correct']);
    sendMessage($chatId, $message);
    $dgMsg = sprintf(MSG_STATDIAG, $d_i[0], $d_i[1]);
    createPieChart($chatId, $dgMsg, $d);
}

function getTestStatistics($testId) {
    global $db;
    $result = $db->query("
        SELECT COUNT(DISTINCT user_id) AS total_users,
            COUNT(DISTINCT CONCAT(user_id, '-', attempt)) AS total_attempts,
            COUNT(DISTINCT question_id) AS total_questions,
            COUNT(*) AS total_answers,
            SUM(is_correct = 1) AS correct_answers FROM test_results WHERE test_id = ?
    ", [$testId], "i");

    if (empty($result)) {
        return [
            'total_users' => 0,
            'total_attempts' => 0,
            'total_questions' => 0,
            'total_answers' => 0,
            'correct_answers' => 0,
            'percentage_correct' => 0.0
        ];
    }

    $row = $result[0];
    $percentageCorrect = $row['total_answers'] > 0 ? round(($row['correct_answers'] / $row['total_answers']) * 100, 2) : 0.0;

    return [
        'total_users' => $row['total_users'],
        'total_attempts' => $row['total_attempts'],
        'total_questions' => $row['total_questions'],
        'total_answers' => $row['total_answers'],
        'correct_answers' => $row['correct_answers'],
        'percentage_correct' => $percentageCorrect
    ];
}

function isUserRegistered($user_id, $event_id) {
    global $db;
    $result = $db->query("SELECT user_id FROM event_registrations WHERE user_id = ? AND event_id = ?", [$user_id, $event_id], "ii");
    return !empty($result);
}

function toggleRegistration($user_id, $event_id) {
    global $db;
	
	if(!isValidEvent($event_id)) {
		return false;
	}
	
    $isRegistered = isUserRegistered($user_id, $event_id);

    $stmt_s = $isRegistered ? "DELETE FROM event_registrations WHERE user_id = ? AND event_id = ?" : "INSERT INTO event_registrations (user_id, event_id) VALUES (?, ?)";
    $success = $db->execute($stmt_s, [$user_id, $event_id], "ii");

    return $success ? ($isRegistered ? -1 : 1) : false;
}

function showEventButtons($chatId, $eventId, $rep = 1, $past = 0) {
    global $db;
	$role = getRole($chatId);

    if ($rep) {
        $result = toggleRegistration($chatId, $eventId);
        if ($result === 1) {
            sendMessage($chatId, MSG_REG);
        } elseif ($result === -1) {
            sendMessage($chatId, MSG_UNREG);
        } else {
            sendMessage($chatId, MSG_ERREG);
        }
    }

    $result = $db->query("SELECT * FROM events WHERE event_id = ?", [$eventId], "i");

    if (!empty($result)) {
        $row = $result[0];
        $message = "{$row['event_id']}. {$row['event_name']} ({$row['event_date']})\n\n{$row['event_info']}";
        $isRegistered = isUserRegistered($chatId, $eventId);
        $buttonText = $isRegistered ? IBUTTONUNREG : IBUTTONREG;
		$keyboard = [];

        if (!$past) {
            $keyboard = [
                'inline_keyboard' => [
                    [
                        ['text' => $buttonText, 'callback_data' => "reg_$eventId"]
                    ],
                    [
                        ['text' => IBUTTONQR, 'callback_data' => "qre_$eventId"]
                    ]
                ]
            ];
        }
        if ($role === 1) {
            $keyboard['inline_keyboard'][] = [
                ['text' => IBUTTONMEM, 'callback_data' => "members_$eventId"]
            ];
        }
		sendTelegramRequest($chatId, $message, ['action' => ($rep) ? 'editMessageText':'sendMessage', 'reply_markup' => $keyboard]);    
	}
}

function genTestQR($chatId, $testId) {
    if (!isValidTest($testId)) {
        sendMessage($chatId, MSG_TESTNOTFOUND);
        return;
    }

    $testName = getTestNameById($testId);
    $link = "https://t.me/events539st_bot?start=t" . $testId;
    genQR($chatId, $testName, $link);
}

function genEventQR($chatId, $eventId) {
    $link = "https://t.me/events539st_bot?start=e" . $eventId;
	$eventName = getEventNameById($eventId);
    genQR($chatId, $eventName, $link);
}

function genQR($chatId, $caption, $qrData) {
    require_once 'lib/phpqrcode/qrlib.php';

    $filename = "images/qr_" . uniqid() . ".png";
    if (!is_dir('images')) {
        mkdir('images', 0755, true);
    }

    QRcode::png($qrData, $filename, 'L', 4, 2);
    sendPhoto($chatId, $caption, $filename);
    unlink($filename);
}

function createPieChart($chatId, $caption, $data) {
    $width = 400;
    $height = 400;
    $centerX = $width / 2;
    $centerY = $height / 2;
    $radius = 150;

    $image = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($image, 255, 255, 255);
    imagefill($image, 0, 0, $white);

    $colors = [
        imagecolorallocate($image, 231, 76, 60),
        imagecolorallocate($image, 46, 204, 113),
    ];

    $black = imagecolorallocate($image, 0, 0, 0);
    $total = array_sum($data);
    $angleStart = 0;

    foreach ($data as $i => $value) {
        if ($value <= 0) continue;

        $angle = ($value / $total) * 360;
        $angleEnd = $angleStart + $angle;
        $midAngle = deg2rad(($angleStart + $angleEnd) / 2);

        $color = $colors[$i];
        imagefilledarc($image, $centerX, $centerY, 300, 300, (int)$angleStart, (int)$angleEnd, $color, IMG_ARC_PIE);

        $textX = $centerX + cos($midAngle) * $radius / 2;
        $textY = $centerY + sin($midAngle) * $radius / 2;

        $percentage = round(($value / $total) * 100, 2);
        $label = "{$percentage}%";
        imagestring($image, 3, (int)$textX - 20, (int)$textY - 7, $label, $black);

        $angleStart += $angle;
    }

    if (!is_dir('images')) {
        mkdir('images', 0755, true);
    }

    $filename = "images/pie_" . uniqid() . ".png";
    imagepng($image, $filename);
    imagedestroy($image);

    sendPhoto($chatId, $caption, $filename);
    unlink($filename);
}

function sendPhoto($chatId, $caption, $filename) {
    $url = $GLOBALS['website'] . '/sendPhoto';
    $data = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
        'photo' => curl_file_create(__DIR__ . '/' . $filename)
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_exec($ch);
    curl_close($ch);
}

function sendMessage($chatId, $response) {
    sendTelegramRequest($chatId, $response);
}

function editLastMsg($chatId, $text) {
    sendTelegramRequest($chatId, $text, ['action' => 'editMessageText']);
}

function sendTelegramRequest($chatId, $text, $options = []) {
    $defaults = [
        'action' => 'sendMessage',
        'parse_mode' => 'HTML',
        'message_id' => null,
        'reply_markup' => null
    ];

    $options = array_merge($defaults, $options);

    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $options['parse_mode']
    ];

    if ($options['action'] === 'editMessageText') {
        global $callback_query;
        $data['message_id'] = $options['message_id'] ?? $callback_query["message"]["message_id"];
    }

    if ($options['reply_markup']) {
        $data['reply_markup'] = json_encode($options['reply_markup']);
    }

    $url = $GLOBALS['website'] . "/" . $options['action'] . "?" . http_build_query($data);
    file_get_contents($url);
}
?>
