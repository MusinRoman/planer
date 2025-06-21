<?php
session_start();
// Подключаемся к базе данных. Убедитесь, что путь к db_connect.php верен.
// Если db_connect.php находится в другой папке, например 'includes/', измените путь:
// require_once 'includes/db_connect.php';
require_once 'db_connect.php';

// Если пользователь не авторизован, перенаправляем на страницу входа
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Получаем ID задачи из GET-параметра
if (!isset($_GET['task_id'])) {
    // Если task_id не передан, возвращаемся на главную страницу с кэш-бастером
    header("Location: index.php?" . uniqid());
    exit();
}
$task_id = $_GET['task_id'];

// Получаем информацию о текущем пользователе
$current_user_id = $_SESSION['user_id'];
$current_username = $_SESSION['username'];
$is_current_user_admin = false;

// Проверяем, является ли текущий пользователь администратором
$stmt_user_role = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt_user_role->bind_param("i", $current_user_id);
$stmt_user_role->execute();
$result_user_role = $stmt_user_role->get_result();
if ($user_role_data = $result_user_role->fetch_assoc()) {
    if ($user_role_data['is_admin'] == 1) {
        $is_current_user_admin = true;
    }
}
$stmt_user_role->close();

// Получаем user_id задачи и ее отдел, чтобы определить, кто является создателем задачи (рестораном)
$task_owner_id = null;
$task_department = null;
$stmt_task_info = $conn->prepare("SELECT user_id, department FROM tasks WHERE id = ?");
$stmt_task_info->bind_param("i", $task_id);
$stmt_task_info->execute();
$result_task_info = $stmt_task_info->get_result();
if ($task_info_data = $result_task_info->fetch_assoc()) {
    $task_owner_id = $task_info_data['user_id'];
    $task_department = $task_info_data['department']; // Получаем отдел задачи
} else {
    // Если задача не найдена в активных, проверяем в архиве
    $stmt_task_info_archive = $conn->prepare("SELECT user_id, department FROM archive_tasks WHERE id = ?");
    $stmt_task_info_archive->bind_param("i", $task_id);
    $stmt_task_info_archive->execute();
    $result_task_info_archive = $stmt_task_info_archive->get_result();
    if ($task_info_data_archive = $result_task_info_archive->fetch_assoc()) {
        $task_owner_id = $task_info_data_archive['user_id'];
        $task_department = $task_info_data_archive['department'];
    } else {
        // Если задача не найдена нигде
        $_SESSION['message'] = "<p style='color: red;'>Задача не найдена.</p>";
        header("Location: index.php?" . uniqid()); // Возвращаемся с кэш-бастером
        exit();
    }
    $stmt_task_info_archive->close();
}
$stmt_task_info->close();


// --- НАЧАЛО: Логика пометки сообщений как прочитанных ---
if ($task_owner_id !== null) { // Убедимся, что задача существует
    $stmt_mark_read = null; // Инициализируем null

    if ($is_current_user_admin) {
        // Если текущий пользователь - АДМИН:
        // Помечаем как прочитанные все сообщения, отправленные НЕ АДМИНАМИ (т.е. пользователем-создателем задачи)
        // для этой конкретной задачи, которые были непрочитанными.
        $stmt_mark_read = $conn->prepare("
            UPDATE task_messages tm
            JOIN users sender ON tm.sender_id = sender.id
            SET tm.is_read = 1                        
            WHERE tm.task_id = ?
            AND sender.is_admin = 0
            AND tm.is_read = 0
        ");
        $stmt_mark_read->bind_param("i", $task_id);
    } else {
        // Если текущий пользователь - ОБЫЧНЫЙ ПОЛЬЗОВАТЕЛЬ (создатель задачи):
        // Помечаем как прочитанные все сообщения, отправленные АДМИНАМИ
        // для этой конкретной задачи, которые были непрочитанными.
        $stmt_mark_read = $conn->prepare("
            UPDATE task_messages tm
            JOIN users sender ON tm.sender_id = sender.id
            SET tm.is_read = 1                        
            WHERE tm.task_id = ?
            AND sender.is_admin = 1
            AND tm.is_read = 0
        ");
        $stmt_mark_read->bind_param("i", $task_id);
    }

    if (isset($stmt_mark_read) && $stmt_mark_read !== null) { // Проверяем, что prepared statement был создан и не null
        $stmt_mark_read->execute();
        $stmt_mark_read->close();
    }
}
// --- КОНЕЦ: Логика пометки сообщений как прочитанных ---

// --- НАЧАЛО: Обработка отправки сообщения ---
$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['send_message'])) {
    $message_text = trim($_POST['message_text']);
    $uploaded_file_paths = [];
    $upload_directory = 'uploads/'; // Убедитесь, что эта папка существует и доступна для записи

    if (!is_dir($upload_directory)) {
        mkdir($upload_directory, 0777, true);
    }

    if (isset($_FILES['message_files'])) {
        $allowed_types = [
            'image/jpeg', 'image/png', 'image/gif',
            'application/pdf',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .doc, .docx
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' // .xls, .xlsx
        ];
        $max_file_size = 5 * 1024 * 1024; // 5 МБ

        foreach ($_FILES['message_files']['name'] as $key => $name) {
            if ($_FILES['message_files']['error'][$key] == UPLOAD_ERR_OK) {
                $file_tmp_name = $_FILES['message_files']['tmp_name'][$key];
                $file_name = basename($name);
                $file_size = $_FILES['message_files']['size'][$key];
                $file_type = mime_content_type($file_tmp_name);

                if (!in_array($file_type, $allowed_types)) {
                    $_SESSION['message'] .= "<p style='color: red;'>Недопустимый тип файла: " . htmlspecialchars($file_name) . ".</p>";
                    continue;
                }
                if ($file_size > $max_file_size) {
                    $_SESSION['message'] .= "<p style='color: red;'>Размер файла " . htmlspecialchars($file_name) . " превышает 5 МБ.</p>";
                    continue;
                }

                $unique_file_name = uniqid() . '_' . $file_name;
                $destination_path = $upload_directory . $unique_file_name;

                if (move_uploaded_file($file_tmp_name, $destination_path)) {
                    $uploaded_file_paths[] = $destination_path;
                } else {
                    $_SESSION['message'] .= "<p style='color: red;'>Ошибка при загрузке файла: " . htmlspecialchars($file_name) . ".</p>";
                }
            } elseif ($_FILES['message_files']['error'][$key] != UPLOAD_ERR_NO_FILE) {
                $_SESSION['message'] .= "<p style='color: red;'>Ошибка загрузки файла " . htmlspecialchars($name) . ": Код ошибки " . $_FILES['message_files']['error'][$key] . "</p>";
            }
        }
    }
    $file_paths_json = !empty($uploaded_file_paths) ? json_encode($uploaded_file_paths) : null;

    if (!empty($message_text) || !empty($uploaded_file_paths)) {
        // is_read по умолчанию 0 (непрочитано) в базе данных, поэтому не нужно его указывать явно.
        $stmt_insert_message = $conn->prepare("INSERT INTO task_messages (task_id, sender_id, message_text, file_path) VALUES (?, ?, ?, ?)");
        $stmt_insert_message->bind_param("iiss", $task_id, $current_user_id, $message_text, $file_paths_json);

        if ($stmt_insert_message->execute()) {
            $_SESSION['message'] = "<p style='color: green;'>Сообщение успешно отправлено!</p>";

            // --- НАЧАЛО КОДА ДЛЯ ОТПРАВКИ СООБЩЕНИЯ В TELEGRAM ---
            $telegram_bot_token = '7943564685:AAFipcFLP_HD-5rxCl5WnfeepDKiHus3wuU'; // <--- ВАШ ТОКЕН BOT API
            // ID чатов для отделов. Убедитесь, что они верны для ваших групп/каналов.
            $department_chat_ids = [
                'IT' => '-1002237882885',
                'Офис-менеджер' => '-1002237882885',
                'Маркетинговый отдел' => '-1002237882885',
            ];

            $message_sender_name = $current_username;
            $message_prefix = "";
            $target_chat_id = null;
            // УКАЖИТЕ ВАШ РЕАЛЬНЫЙ ДОМЕН для ссылки на чат
            $task_page_url = "https://planer.vh159080.eurodir.ru/chat.php?task_id=" . $task_id;

            if ($is_current_user_admin) {
                // Если админ пишет пользователю
                $message_prefix = "📢 НОВОЕ СООБЩЕНИЕ ОТ АДМИНА! 📢\n\n";
                if ($task_owner_id) {
                    $stmt_owner_username = $conn->prepare("SELECT username FROM users WHERE id = ?");
                    $stmt_owner_username->bind_param("i", $task_owner_id);
                    $stmt_owner_username->execute();
                    $owner_row = $stmt_owner_username->get_result()->fetch_assoc();
                    $message_recipient_name = $owner_row['username'];
                    $stmt_owner_username->close();

                    // Отправляем сообщение обратно в отдел, если пользователь из отдела, или в общий
                    $target_chat_id = $department_chat_ids[$task_department] ?? '-1002237882885'; // Общий чат по умолчанию
                    $message_prefix .= "Задача для ресторана: " . htmlspecialchars($message_recipient_name) . "\n";
                }

            } else {
                // Если пользователь (ресторан) пишет админу
                $message_prefix = "💬 НОВОЕ СООБЩЕНИЕ ОТ РЕСТОРАНА! 💬\n\n";
                $target_chat_id = $department_chat_ids[$task_department] ?? null; // Отправляем в чат отдела задачи
                $message_prefix .= "От ресторана: " . htmlspecialchars($message_sender_name) . "\n";
                $message_prefix .= "Отдел задачи: " . htmlspecialchars($task_department) . "\n";
            }

            if ($target_chat_id) {
                $telegram_message_text = $message_prefix;
                $telegram_message_text .= "Текст сообщения: " . htmlspecialchars($message_text) . "\n";
                if (!empty($uploaded_file_paths)) {
                    $telegram_message_text .= "\nВложенные файлы:\n";
                    foreach ($uploaded_file_paths as $path) {
                        $file_name_for_telegram = basename($path);
                        $file_url = "https://planer.vh159080.eurodir.ru/" . $path; // <--- ВАШ ДОМЕН
                        $telegram_message_text .= "- [" . htmlspecialchars($file_name_for_telegram) . "](" . $file_url . ")\n";
                    }
                }
                $telegram_message_text .= "\nПросмотреть в чате: [Перейти в чат](" . $task_page_url . ")";

                $telegram_api_url = "https://api.telegram.org/bot" . $telegram_bot_token . "/sendMessage";
                $params = [
                    'chat_id'    => $target_chat_id,
                    'text'       => $telegram_message_text,
                    'parse_mode' => 'Markdown', // Для форматирования ссылок
                    'disable_web_page_preview' => false // Позволяет отображать превью ссылок
                ];

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $telegram_api_url);
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                $response = curl_exec($ch);

                if ($response === false) {
                    error_log("Ошибка отправки Telegram-сообщения: " . curl_error($ch));
                } else {
                    $responseData = json_decode($response, true);
                    if ($responseData['ok'] === false) {
                        error_log("Ошибка Telegram API: " . $responseData['description']);
                    }
                }
                curl_close($ch);
            } else {
                error_log("Не найден Chat ID для отдела или пользователя. Сообщение не отправлено.");
            }
            // --- КОНЕЦ КОДА ДЛЯ ОТПРАВКИ СООБЩЕНИЯ В TELEGRAM ---

        } else {
            $_SESSION['message'] = "<p style='color: red;'>Ошибка при отправке сообщения: " . $stmt_insert_message->error . "</p>";
        }
        $stmt_insert_message->close();
    } else {
        $_SESSION['message'] = "<p style='color: red;'>Сообщение не может быть пустым, если нет вложенных файлов.</p>";
    }
    // Перезагружаем страницу чата с кэш-бастером
    header("Location: chat.php?task_id=" . $task_id . "&" . uniqid());
    exit();
}
// --- КОНЕЦ: Обработка отправки сообщения ---

// --- НАЧАЛО: Обработка редактирования сообщения ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_message'])) {
    $message_id = $_POST['message_id'];
    $edited_text = trim($_POST['edited_text']);
    $file_paths_json = null; // По умолчанию null, если нет новых файлов

    // Проверяем, что сообщение принадлежит текущему пользователю
    $stmt_check_owner = $conn->prepare("SELECT sender_id, file_path FROM task_messages WHERE id = ?");
    $stmt_check_owner->bind_param("i", $message_id);
    $stmt_check_owner->execute();
    $result_owner = $stmt_check_owner->get_result();
    $message_data = $result_owner->fetch_assoc();
    $stmt_check_owner->close();

    if ($message_data && $message_data['sender_id'] == $current_user_id) {
        // Логика загрузки новых файлов при редактировании (аналогично отправке нового сообщения)
        $new_uploaded_file_paths = [];
        $upload_directory = 'uploads/';

        if (!is_dir($upload_directory)) {
            mkdir($upload_directory, 0777, true);
        }

        if (isset($_FILES['edited_message_files']) && !empty($_FILES['edited_message_files']['name'][0])) {
            $allowed_types = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            $max_file_size = 5 * 1024 * 1024; // 5 МБ

            foreach ($_FILES['edited_message_files']['name'] as $key => $name) {
                if ($_FILES['edited_message_files']['error'][$key] == UPLOAD_ERR_OK) {
                    $file_tmp_name = $_FILES['edited_message_files']['tmp_name'][$key];
                    $file_name = basename($name);
                    $file_size = $_FILES['edited_message_files']['size'][$key];
                    $file_type = mime_content_type($file_tmp_name);

                    if (!in_array($file_type, $allowed_types)) {
                        $_SESSION['message'] .= "<p style='color: red;'>Недопустимый тип файла: " . htmlspecialchars($file_name) . ".</p>";
                        continue;
                    }
                    if ($file_size > $max_file_size) {
                        $_SESSION['message'] .= "<p style='color: red;'>Размер файла " . htmlspecialchars($file_name) . " превышает 5 МБ.</p>";
                        continue;
                    }

                    $unique_file_name = uniqid() . '_' . $file_name;
                    $destination_path = $upload_directory . $unique_file_name;

                    if (move_uploaded_file($file_tmp_name, $destination_path)) {
                        $new_uploaded_file_paths[] = $destination_path;
                    } else {
                        $_SESSION['message'] .= "<p style='color: red;'>Ошибка при загрузке файла: " . htmlspecialchars($file_name) . ".</p>";
                    }
                } elseif ($_FILES['edited_message_files']['error'][$key] != UPLOAD_ERR_NO_FILE) {
                    $_SESSION['message'] .= "<p style='color: red;'>Ошибка загрузки файла " . htmlspecialchars($name) . ": Код ошибки " . $_FILES['edited_message_files']['error'][$key] . "</p>";
                }
            }
        }

        // Объединяем старые и новые файлы
        $existing_files = json_decode($message_data['file_path'], true);
        if (!is_array($existing_files)) $existing_files = [];

        $all_files = array_merge($existing_files, $new_uploaded_file_paths);
        $file_paths_json = !empty($all_files) ? json_encode(array_values(array_unique($all_files))) : null; // Удаляем дубликаты


        $stmt_update_message = $conn->prepare("UPDATE task_messages SET message_text = ?, file_path = ?, is_edited = 1, edited_at = NOW() WHERE id = ?");
        $stmt_update_message->bind_param("ssi", $edited_text, $file_paths_json, $message_id);

        if ($stmt_update_message->execute()) {
            $_SESSION['message'] = "<p style='color: green;'>Сообщение успешно отредактировано!</p>";
        } else {
            $_SESSION['message'] = "<p style='color: red;'>Ошибка при редактировании сообщения: " . $stmt_update_message->error . "</p>";
        }
        $stmt_update_message->close();
    } else {
        $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для редактирования этого сообщения.</p>";
    }
    // Перезагружаем страницу чата с кэш-бастером
    header("Location: chat.php?task_id=" . $task_id . "&" . uniqid());
    exit();
}
// --- КОНЕЦ: Обработка редактирования сообщения ---

// --- НАЧАЛО: Обработка удаления сообщения ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_message'])) {
    $message_id = $_POST['message_id'];

    // Проверяем, что сообщение принадлежит текущему пользователю
    $stmt_check_owner = $conn->prepare("SELECT sender_id, file_path FROM task_messages WHERE id = ?");
    $stmt_check_owner->bind_param("i", $message_id);
    $stmt_check_owner->execute();
    $result_owner = $stmt_check_owner->get_result();
    $message_data = $result_owner->fetch_assoc();
    $stmt_check_owner->close();

    if ($message_data && $message_data['sender_id'] == $current_user_id) {
        // Вместо полного удаления, помечаем как удаленное
        $stmt_delete_message = $conn->prepare("UPDATE task_messages SET is_deleted = 1, deleted_at = NOW(), message_text = '' WHERE id = ?"); // Очищаем текст
        $stmt_delete_message->bind_param("i", $message_id);

        if ($stmt_delete_message->execute()) {
            // Также удаляем связанные файлы с сервера, если они были
            $file_paths_to_delete = json_decode($message_data['file_path'], true);
            if (is_array($file_paths_to_delete)) {
                foreach ($file_paths_to_delete as $file_path) {
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }
            }
            // Очищаем file_path в БД, так как файлы удалены
            $stmt_clear_filepath = $conn->prepare("UPDATE task_messages SET file_path = NULL WHERE id = ?");
            $stmt_clear_filepath->bind_param("i", $message_id);
            $stmt_clear_filepath->execute();
            $stmt_clear_filepath->close();

            $_SESSION['message'] = "<p style='color: green;'>Сообщение успешно удалено.</p>";
        } else {
            $_SESSION['message'] = "<p style='color: red;'>Ошибка при удалении сообщения: " . $stmt_delete_message->error . "</p>";
        }
        $stmt_delete_message->close();
    } else {
        $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для удаления этого сообщения.</p>";
    }
    // Перезагружаем страницу чата с кэш-бастером
    header("Location: chat.php?task_id=" . $task_id . "&" . uniqid());
    exit();
}
// --- КОНЕЦ: Обработка удаления сообщения ---

// --- НАЧАЛО: Удаление отдельного файла из сообщения ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_single_file'])) {
    $message_id = $_POST['message_id'];
    $file_to_delete_path = $_POST['file_path_to_delete'];

    $stmt_get_files = $conn->prepare("SELECT sender_id, file_path FROM task_messages WHERE id = ?");
    $stmt_get_files->bind_param("i", $message_id);
    $stmt_get_files->execute();
    $result_files = $stmt_get_files->get_result();
    $message_data = $result_files->fetch_assoc();
    $stmt_get_files->close();

    if ($message_data && $message_data['sender_id'] == $current_user_id) {
        $existing_files = json_decode($message_data['file_path'], true);
        if (!is_array($existing_files)) $existing_files = [];

        $updated_files = array_filter($existing_files, function($path) use ($file_to_delete_path) {
            return $path !== $file_to_delete_path;
        });

        // Удаляем файл с сервера
        if (file_exists($file_to_delete_path)) {
            unlink($file_to_delete_path);
            $_SESSION['message'] = "<p style='color: green;'>Файл успешно удален.</p>";
        } else {
            $_SESSION['message'] = "<p style='color: orange;'>Файл не найден на сервере, но запись будет удалена из БД.</p>";
        }

        $new_file_paths_json = !empty($updated_files) ? json_encode(array_values($updated_files)) : null;

        $stmt_update_file_path = $conn->prepare("UPDATE task_messages SET file_path = ? WHERE id = ?");
        $stmt_update_file_path->bind_param("si", $new_file_paths_json, $message_id);
        $stmt_update_file_path->execute();
        $stmt_update_file_path->close();
    } else {
        $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для удаления этого файла.</p>";
    }
    // Перезагружаем страницу чата с кэш-бастером
    header("Location: chat.php?task_id=" . $task_id . "&" . uniqid());
    exit();
}
// --- КОНЕЦ: Удаление отдельного файла из сообщения ---


// --- ВЫБОРКА СООБЩЕНИЙ ---
$messages = [];
// Выбираем сообщения и присоединяем информацию об отправителе
$stmt_messages = $conn->prepare("
    SELECT tm.*, u.username AS sender_username, u.is_admin AS sender_is_admin
    FROM task_messages tm
    JOIN users u ON tm.sender_id = u.id
    WHERE tm.task_id = ?
    ORDER BY tm.sent_at ASC
");
$stmt_messages->bind_param("i", $task_id);
$stmt_messages->execute();
$result_messages = $stmt_messages->get_result();
while ($row = $result_messages->fetch_assoc()) {
    $messages[] = $row;
}
$stmt_messages->close();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Чат по Задаче #<?php echo htmlspecialchars($task_id); ?></title><strong>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat+Alternates&family=Montserrat:wght@500;600;700&display=swap');
        body {
            font-family: "Montserrat", sans-serif;
            margin: 0;
            background-color: #f4f4f4;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 900px;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #0056b3;
            margin-top: 0;
            margin-bottom: 20px;
        }
        .message-area {
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
            max-height: 500px;
            overflow-y: auto;
            background-color: #f9f9f9;
        }
        .message {
            margin-bottom: 15px;
            padding: 10px;
            border-radius: 8px;
            background-color: #e0f7fa; /* Голубой фон для сообщений */
            position: relative;
        }
        .message.sent {
            background-color: #dcf8c6; /* Зеленый фон для отправленных сообщений */
            text-align: right;
        }
        .message .sender {
            font-weight: bold;
            color: #0056b3;
            font-size: 0.9em;
            margin-bottom: 5px;
            display: block; /* чтобы занимал всю ширину */
        }
        .message.sent .sender {
            color: #28a745;
        }
        .message .timestamp {
            font-size: 0.75em;
            color: #888;
            margin-top: 5px;
            display: block;
        }
        .message-text {
            word-wrap: break-word;
            white-space: pre-wrap; /* Сохраняет переносы строк */
        }
        .message-form {
            margin-top: 20px;
            border-top: 1px solid #eee;
            padding-top: 20px;
        }
        textarea, input[type="file"] {
            font-family: "Montserrat", sans-serif;
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button[type="submit"], .button-back {
            font-family: "Montserrat", sans-serif;
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none; /* Для ссылки */
            display: inline-block; /* Для ссылки */
            margin-right: 10px;
        }
        button[type="submit"]:hover, .button-back:hover {
            background-color: #0056b3;
        }
        .back-link {
            margin-top: 20px;
            display: block;
            text-align: center;
        }

        /* Styles for attached files in messages */
        .message-files {
            margin-top: 10px;
            border-top: 1px dashed #ccc;
            padding-top: 5px;
        }
        .message-files a {
            display: block;
            background-color: #f0f8ff; /* Очень светлый голубой */
            padding: 5px 8px;
            border-radius: 5px;
            margin-bottom: 3px;
            text-decoration: none;
            color: #007bff;
            font-size: 0.85em;
        }
        .message-files a:hover {
            background-color: #e0f2ff;
        }

        /* Edit and Delete buttons for messages */
        .message-actions {
            position: absolute;
            bottom: 5px;
            left: 5px;
            display: flex;
            gap: 5px;
        }
        .message-actions button {
            background: none;
            border: none;
            cursor: pointer;
            font-size: 0.9em;
            color: #007bff;
            padding: 3px 5px;
            border-radius: 3px;
        }
        .message-actions button:hover {
            background-color: #eee;
        }
        .message-actions .edit-button { color: #ffc107; }
        .message-actions .delete-button { color: #dc3545; }

        .message-edited {
            font-size: 0.7em;
            color: #a0a0a0;
            margin-left: 10px;
        }
        .message.deleted {
            font-style: italic;
            color: #888;
            background-color: #f0f0f0;
        }

        /* Styles for file preview on send/edit form */
        #selected-files-preview, .selected-files-preview {
            margin-top: 10px;
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 4px;
            background-color: #fcfcfc;
            margin-bottom: 15px;
        }
        .file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dashed #eee;
        }
        .file-item:last-child {
            border-bottom: none;
        }
        .remove-file-button {
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 3px 8px;
            cursor: pointer;
            font-size: 0.8em;
        }
        .remove-file-button:hover {
            background-color: #c82333;
        }

        /* Modal styles */
        .modal {
            display: none; /* Hidden by default */
            position: fixed; /* Stay in place */
            z-index: 1; /* Sit on top */
            left: 0;
            top: 0;
            width: 100%; /* Full width */
            height: 100%; /* Full height */
            overflow: auto; /* Enable scroll if needed */
            background-color: rgba(0,0,0,0.4); /* Black w/ opacity */
            padding-top: 60px;
        }
        .modal-content {
            background-color: #fefefe;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            border-radius: 8px;
            width: 80%; /* Could be more responsive */
            max-width: 600px;
            position: relative;
        }
        .close-button {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
        }
        .close-button:hover,
        .close-button:focus {
            color: black;
            text-decoration: none;
            cursor: pointer;
        }
        .modal-files-list {
            margin-top: 10px;
            border-top: 1px dashed #ccc;
            padding-top: 10px;
        }
        .modal-file-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 5px 0;
            border-bottom: 1px dashed #eee;
        }
        .modal-file-item:last-child {
            border-bottom: none;
        }
        .modal-remove-file-button {
            background-color: #dc3545;
            color: white;
            border: none;
            border-radius: 3px;
            padding: 3px 8px;
            cursor: pointer;
            font-size: 0.8em;
            margin-left: 10px;
        }
        .modal-remove-file-button:hover {
            background-color: #c82333;
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                padding: 15px;
            }
            .message-actions {
                position: static; /* Кнопки действий под сообщением на мобильных */
                display: flex;
                justify-content: flex-end;
                margin-top: 5px;
            }
            .message-actions button {
                font-size: 0.8em;
                padding: 5px 8px;
            }
            .modal-content {
                width: 95%;
                margin: 20px auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Чат по Задаче #<?php echo htmlspecialchars($task_id); ?></h1>
        <a href="index.php?nocache=<?php echo time(); ?>" class="button-back">Вернуться к задачам</a>

        <p><?php echo $message; ?></p>

        <div class="message-area">
            <?php if (empty($messages)): ?>
                <p>Сообщений пока нет. Начните переписку!</p>
            <?php else: ?>
                <?php foreach ($messages as $msg):
                    $is_sender_admin = ($msg['sender_is_admin'] == 1);
                    $is_current_user_sender = ($msg['sender_id'] == $current_user_id);
                    $message_class = $is_current_user_sender ? 'sent' : 'received';
                    $sender_name = htmlspecialchars($msg['sender_username']);

                    // Определяем имя отправителя для отображения
                    if ($is_sender_admin) {
                        $sender_display_name = 'Администратор: ' . $sender_name;
                    } else {
                        // Для обычного пользователя отображаем имя, если это не его сообщение
                        // Или просто "Вы", если это его сообщение
                        $sender_display_name = $is_current_user_sender ? 'Вы' : 'Ресторан: ' . $sender_name;
                    }

                    // Обработка удаленных сообщений
                    if ($msg['is_deleted'] == 1) {
                        echo "<div class='message deleted'>";
                        echo "<span class='sender'>" . htmlspecialchars($sender_display_name) . "</span>";
                        echo "<p class='message-text'><i>Это сообщение было удалено.</i></p>";
                        echo "<span class='timestamp'>Удалено: " . date('d.m.Y H:i', strtotime($msg['deleted_at'])) . "</span>";
                        echo "</div>";
                        continue; // Пропускаем остальную часть цикла для удаленных сообщений
                    }
                ?>
                    <div class="message <?php echo $message_class; ?>">
                        <span class="sender"><?php echo $sender_display_name; ?></span>
                        <p class="message-text"><?php echo nl2br(htmlspecialchars($msg['message_text'])); ?></p>

                        <?php if (!empty($msg['file_path'])): ?>
                            <div class="message-files">
                                <?php
                                $files = json_decode($msg['file_path'], true);
                                if (is_array($files)):
                                    foreach ($files as $index => $file_path):
                                        $file_name = basename($file_path);
                                ?>
                                        <a href="<?php echo htmlspecialchars($file_path); ?>" target="_blank"><?php echo htmlspecialchars($file_name); ?></a>
                                <?php
                                    endforeach;
                                endif;
                                ?>
                            </div>
                        <?php endif; ?>

                        <span class="timestamp">
                            <?php echo date('d.m.Y H:i', strtotime($msg['sent_at'])); ?>
                            <?php if ($msg['is_edited'] == 1): ?>
                                <span class="message-edited">(отредактировано: <?php echo date('d.m.Y H:i', strtotime($msg['edited_at'])); ?>)</span>
                            <?php endif; ?>
                        </span>

                        <?php if ($is_current_user_sender): // Только отправитель может редактировать/удалять ?>
                            <div class="message-actions">
                                <button class="edit-button" onclick="openEditModal(<?php echo $msg['id']; ?>, `<?php echo htmlspecialchars(addslashes($msg['message_text'])); ?>`, `<?php echo htmlspecialchars(addslashes($msg['file_path'])); ?>`)">Редактировать</button>
                                <form action="chat.php?task_id=<?php echo htmlspecialchars($task_id); ?>" method="post" style="display:inline;" onsubmit="return confirm('Вы уверены, что хотите удалить это сообщение?');">
                                    <input type="hidden" name="message_id" value="<?php echo htmlspecialchars($msg['id']); ?>">
                                    <button type="submit" name="delete_message" class="delete-button">Удалить</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="message-form">
            <h3>Отправить сообщение</h3>
            <form action="chat.php?task_id=<?php echo htmlspecialchars($task_id); ?>" method="post" enctype="multipart/form-data">
                <textarea name="message_text" rows="3" placeholder="Введите ваше сообщение..."></textarea>
                <label for="message_files">Прикрепить файлы (фото или документ, до 5МБ каждый):</label>
                <input type="file" id="message_files" name="message_files[]" multiple accept="image/*, .pdf, .doc, .docx, .xls, .xlsx">
                <div id="new-message-files-preview" class="selected-files-preview"></div>
                <button type="submit" name="send_message">Отправить</button>
            </form>
        </div>
    </div>

    <div id="editMessageModal" class="modal">
        <div class="modal-content">
            <span class="close-button" onclick="closeEditModal()">&times;</span>
            <h2>Редактировать сообщение</h2>
            <form id="editMessageForm" action="chat.php?task_id=<?php echo htmlspecialchars($task_id); ?>" method="post" enctype="multipart/form-data">
                <input type="hidden" name="message_id" id="edit_message_id">
                <textarea name="edited_text" id="edited_message_text" rows="5" required></textarea>

                <h4>Вложенные файлы:</h4>
                <div id="existing-files-preview" class="modal-files-list">
                    </div>

                <label for="edited_message_files">Добавить новые файлы (до 5МБ каждый):</label>
                <input type="file" id="edited_message_files" name="edited_message_files[]" multiple accept="image/*, .pdf, .doc, .docx, .xls, .xlsx">
                <div id="edited-new-files-preview" class="selected-files-preview"></div>

                <button type="submit" name="edit_message">Сохранить изменения</button>
            </form>
        </div>
    </div>

    <script>
        // Функция для прокрутки чата вниз при загрузке
        window.onload = function() {
            const messageArea = document.querySelector('.message-area');
            messageArea.scrollTop = messageArea.scrollHeight;
        };

        // --- JavaScript для предварительного просмотра и удаления файлов при отправке нового сообщения ---
        document.addEventListener('DOMContentLoaded', function() {
            const newMessageFileInput = document.getElementById('message_files');
            const newMessageFilesPreview = document.getElementById('new-message-files-preview');
            let newFilesArray = []; // Для новых сообщений

            function updateNewMessageFileInput() {
                const dataTransfer = new DataTransfer();
                newFilesArray.forEach(file => dataTransfer.items.add(file));
                newMessageFileInput.files = dataTransfer.files;
            }

            function renderNewMessageSelectedFiles() {
                newMessageFilesPreview.innerHTML = '';
                if (newFilesArray.length === 0) {
                    newMessageFilesPreview.style.display = 'none';
                    return;
                }
                newMessageFilesPreview.style.display = 'block';

                newFilesArray.forEach((file, index) => {
                    const fileItem = document.createElement('div');
                    fileItem.classList.add('file-item');
                    fileItem.innerHTML = `
                        <span>${file.name} (${(file.size / 1024 / 1024).toFixed(2)} МБ)</span>
                        <button type="button" class="remove-file-button" data-index="${index}" data-origin="new">Удалить</button>
                    `;
                    newMessageFilesPreview.appendChild(fileItem);
                });

                newMessageFilesPreview.querySelectorAll('.remove-file-button[data-origin="new"]').forEach(button => {
                    button.addEventListener('click', function() {
                        const indexToRemove = parseInt(this.dataset.index);
                        newFilesArray.splice(indexToRemove, 1);
                        updateNewMessageFileInput();
                        renderNewMessageSelectedFiles();
                    });
                });
            }

            newMessageFileInput.addEventListener('change', function() {
                // Добавляем новые выбранные файлы к существующему массиву
                for (let i = 0; i < this.files.length; i++) {
                    newFilesArray.push(this.files[i]);
                }
                renderNewMessageSelectedFiles();
            });
            renderNewMessageSelectedFiles(); // Изначальная отрисовка


            // --- JavaScript для модального окна редактирования сообщений ---
            const editMessageModal = document.getElementById('editMessageModal');
            const editedMessageTextarea = document.getElementById('edited_message_text');
            const editMessageIdInput = document.getElementById('edit_message_id');
            const existingFilesPreview = document.getElementById('existing-files-preview');
            const editedNewMessageFileInput = document.getElementById('edited_message_files');
            const editedNewFilesPreview = document.getElementById('edited-new-files-preview');

            let editedNewFilesArray = []; // Для новых файлов, добавляемых в модальном окне редактирования

            function updateEditedNewMessageFileInput() {
                const dataTransfer = new DataTransfer();
                editedNewFilesArray.forEach(file => dataTransfer.items.add(file));
                editedNewMessageFileInput.files = dataTransfer.files;
            }

            function renderEditedNewMessageSelectedFiles() {
                editedNewFilesPreview.innerHTML = '';
                if (editedNewFilesArray.length === 0) {
                    editedNewFilesPreview.style.display = 'none';
                    return;
                }
                editedNewFilesPreview.style.display = 'block';

                editedNewFilesArray.forEach((file, index) => {
                    const fileItem = document.createElement('div');
                    fileItem.classList.add('file-item');
                    fileItem.innerHTML = `
                        <span>${file.name} (${(file.size / 1024 / 1024).toFixed(2)} МБ)</span>
                        <button type="button" class="remove-file-button" data-index="${index}" data-origin="edit-new">Удалить</button>
                    `;
                    editedNewFilesPreview.appendChild(fileItem);
                });

                editedNewFilesPreview.querySelectorAll('.remove-file-button[data-origin="edit-new"]').forEach(button => {
                    button.addEventListener('click', function() {
                        const indexToRemove = parseInt(this.dataset.index);
                        editedNewFilesArray.splice(indexToRemove, 1);
                        updateEditedNewMessageFileInput();
                        renderEditedNewMessageSelectedFiles();
                    });
                });
            }

            editedNewMessageFileInput.addEventListener('change', function() {
                for (let i = 0; i < this.files.length; i++) {
                    editedNewFilesArray.push(this.files[i]);
                }
                renderEditedNewMessageSelectedFiles();
            });


            window.openEditModal = function(messageId, messageText, filePathsJson) {
                editMessageIdInput.value = messageId;
                editedMessageTextarea.value = messageText;

                existingFilesPreview.innerHTML = '';
                if (filePathsJson) {
                    const existingFiles = JSON.parse(filePathsJson);
                    if (Array.isArray(existingFiles) && existingFiles.length > 0) {
                        existingFiles.forEach(filePath => {
                            const fileName = filePath.substring(filePath.lastIndexOf('/') + 1);
                            const fileItem = document.createElement('div');
                            fileItem.classList.add('modal-file-item');
                            fileItem.innerHTML = `
                                <a href="${filePath}" target="_blank">${fileName}</a>
                                <button type="button" class="modal-remove-file-button" data-message-id="${messageId}" data-file-path="${filePath}">Удалить</button>
                            `;
                            existingFilesPreview.appendChild(fileItem);
                        });
                        existingFilesPreview.style.display = 'block';
                    } else {
                         existingFilesPreview.style.display = 'none';
                    }
                } else {
                    existingFilesPreview.style.display = 'none';
                }

                // Сброс новых файлов при открытии модального окна редактирования
                editedNewFilesArray = [];
                updateEditedNewMessageFileInput();
                renderEditedNewMessageSelectedFiles();

                editMessageModal.style.display = 'block';

                // Добавляем обработчик для удаления существующих файлов
                existingFilesPreview.querySelectorAll('.modal-remove-file-button').forEach(button => {
                    button.onclick = function() {
                        const confirmDelete = confirm('Вы уверены, что хотите удалить этот файл из сообщения?');
                        if (confirmDelete) {
                            const msgId = this.dataset.messageId;
                            const filePath = this.dataset.filePath;

                            // Создаем скрытую форму для отправки POST-запроса
                            const form = document.createElement('form');
                            form.method = 'POST';
                            form.action = `chat.php?task_id=<?php echo htmlspecialchars($task_id); ?>`; // Указываем action

                            const msgIdInput = document.createElement('input');
                            msgIdInput.type = 'hidden';
                            msgIdInput.name = 'message_id';
                            msgIdInput.value = msgId;
                            form.appendChild(msgIdInput);

                            const filePathInput = document.createElement('input');
                            filePathInput.type = 'hidden';
                            filePathInput.name = 'file_path_to_delete';
                            filePathInput.value = filePath;
                            form.appendChild(filePathInput);

                            const deleteActionInput = document.createElement('input');
                            deleteActionInput.type = 'hidden';
                            deleteActionInput.name = 'delete_single_file';
                            deleteActionInput.value = '1';
                            form.appendChild(deleteActionInput);

                            document.body.appendChild(form); // Добавляем форму в DOM
                            form.submit(); // Отправляем форму
                        }
                    };
                });
            };

            window.closeEditModal = function() {
                editMessageModal.style.display = 'none';
            };

            // Закрытие модального окна при клике вне его
            window.onclick = function(event) {
                if (event.target == editMessageModal) {
                    closeEditModal();
                }
            };
        });
    </script>
</body>
</html>