<?php
session_start(); // Начинаем сессию
require_once 'db_connect.php'; // Подключаем файл с подключением к БД

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_username = $_SESSION['username'];
$current_user_id = $_SESSION['user_id'];
$current_user_is_admin = false;
$stmt_admin_check = $conn->prepare("SELECT is_admin FROM users WHERE id = ?");
$stmt_admin_check->bind_param("i", $current_user_id);
$stmt_admin_check->execute();
$result_admin_check = $stmt_admin_check->get_result();
if ($result_admin_check->num_rows > 0) {
    $user_data = $result_admin_check->fetch_assoc();
    if ($user_data['is_admin'] == 1) {
        $current_user_is_admin = true;
    }
}
$stmt_admin_check->close();

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']); // Удаляем сообщение из сессии, чтобы оно не отображалось повторно
}

if (isset($_GET['logout'])) {
    session_destroy(); // Удаляем все данные сессии
    header("Location: login.php"); // Перенаправляем на страницу входа
    exit();
}

// Функция для удаления файлов
function delete_files($file_paths_json) {
    if ($file_paths_json) {
        $file_paths = json_decode($file_paths_json, true);
        if (is_array($file_paths)) {
            foreach ($file_paths as $path) {
                if (file_exists($path)) {
                    unlink($path); // Удаляем файл
                    error_log("Удален файл: " . $path);
                } else {
                    error_log("Файл не найден при удалении: " . $path);
                }
            }
        }
    }
}

// --- ОБРАБОТКА POST-ЗАПРОСОВ (остается с перезагрузкой для простоты) ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($_POST['submit_new_task'])) {
        $task_description = trim($_POST['task_description']);
        $task_subject = trim($_POST['task_subject']); // Добавляем эту строку
        $desired_due_date = $_POST['desired_due_date'];
        $task_department = $_POST['task_department'];
        $uploaded_file_paths = [];
        $upload_directory = 'uploads/'; // Папка для сохранения файлов

        if (!is_dir($upload_directory)) {
            mkdir($upload_directory, 0777, true);
        }

        if (isset($_FILES['task_files'])) {
            $allowed_types = [
                'image/jpeg', 'image/png', 'image/gif',
                'application/pdf',
                'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ];
            $max_file_size = 5 * 1024 * 1024; // 5 МБ

            foreach ($_FILES['task_files']['name'] as $key => $name) {
                if ($_FILES['task_files']['error'][$key] == UPLOAD_ERR_OK) {
                    $file_tmp_name = $_FILES['task_files']['tmp_name'][$key];
                    $file_name = basename($name);
                    $file_size = $_FILES['task_files']['size'][$key];
                    $file_type = mime_content_type($file_tmp_name); // Определяем MIME-тип файла

                    if (!in_array($file_type, $allowed_types)) {
                        $_SESSION['message'] .= "<p style='color: red;'>Недопустимый тип файла: " . htmlspecialchars($file_name) . ".</p>";
                        continue; // Пропускаем этот файл
                    }
                    if ($file_size > $max_file_size) {
                        $_SESSION['message'] .= "<p style='color: red;'>Размер файла " . htmlspecialchars($file_name) . " превышает 5 МБ.</p>";
                        continue; // Пропускаем этот файл
                    }

                    $unique_file_name = uniqid() . '_' . $file_name;
                    $destination_path = $upload_directory . $unique_file_name;

                    if (move_uploaded_file($file_tmp_name, $destination_path)) {
                        $uploaded_file_paths[] = $destination_path; // Сохраняем путь для записи в БД
                    } else {
                        $_SESSION['message'] .= "<p style='color: red;'>Ошибка при загрузке файла: " . htmlspecialchars($file_name) . ".</p>";
                    }
                } elseif ($_FILES['task_files']['error'][$key] != UPLOAD_ERR_NO_FILE) {
                    // Обработка других ошибок загрузки, кроме отсутствия файла
                    $_SESSION['message'] .= "<p style='color: red;'>Ошибка загрузки файла " . htmlspecialchars($name) . ": Код ошибки " . $_FILES['task_files']['error'][$key] . "</p>";
                }
            }
        }
        $file_paths_json = !empty($uploaded_file_paths) ? json_encode($uploaded_file_paths) : null;

        if (!empty($task_description) && !empty($task_subject) && !empty($task_department)) { // Добавили проверку на task_subject
            $stmt = $conn->prepare("INSERT INTO tasks (task_subject, task_description, desired_due_date, department, user_id, file_paths, is_archived) VALUES (?, ?, ?, ?, ?, ?, 0)"); // Добавили task_subject
            $stmt->bind_param("ssssis", $task_subject, $task_description, $desired_due_date, $task_department, $current_user_id, $file_paths_json); // Добавили 's' для task_subject

            if ($stmt->execute()) {
                $_SESSION['message'] .= "<p style='color: green;'>Ваша задача успешно отправлена!</p>";

                // --- НАЧАЛО КОДА ДЛЯ ОТПРАВКИ СООБЩЕНИЯ В TELEGRAM ПО ОТДЕЛАМ ---
                $telegram_bot_token = '7943564685:AAFipcFLP_HD-5rxCl5WnfeepDKiHus3wuU'; // Ваш токен
                $department_chat_ids = [
                    'IT' => '-1002237882885',
                    'Офис-менеджер' => '-1002237882885',
                    'Маркетинговый отдел' => '-1002237882885',
                    'Отдел логистики' => '-1002237882885',
                    'Ремонтный отдел' => '-1002237882885',
                    'Бухгалтерия' => '-1002237882885',
                ];
                $target_chat_id = $department_chat_ids[$task_department] ?? null;

                if ($target_chat_id) {
                    $message_text = "✨ НОВАЯ ЗАДАЧА! ✨\n\n";
                    $message_text .= "Описание: " . htmlspecialchars($task_description) . "\n";
                    $message_text .= "Желаемый срок: " . ($desired_due_date ? date('d-m-Y', strtotime($desired_due_date)) : "Не указан") . "\n";
                    $message_text .= "Отдел: " . htmlspecialchars($task_department) . "\n";
                    $message_text .= "От ресторана: " . htmlspecialchars($current_username) . "\n";

                    // Добавляем ссылки на вложенные файлы в сообщение Telegram
                    if (!empty($uploaded_file_paths)) {
                        $message_text .= "\nВложенные файлы:\n";
                        foreach ($uploaded_file_paths as $path) {
                            $file_name_for_telegram = basename($path);
                            // Предполагаем, что файлы доступны по URL /uploads/filename.ext
                            // Убедитесь, что ваш веб-сервер настроен для отдачи файлов из этой папки
                            $file_url = "https://planer.vh159080.eurodir.ru/" . $path; // УКАЖИТЕ ВАШ ДОМЕН
                            $message_text .= "- [" . htmlspecialchars($file_name_for_telegram) . "](" . $file_url . ")\n";
                        }
                    }
                    $message_text .= "\nПроверьте в панели администратора: [Панель Админа](https://planer.vh159080.eurodir.ru/administratorgg/admin_login.php)";

                    $telegram_api_url = "https://api.telegram.org/bot" . $telegram_bot_token . "/sendMessage";
                    $params = [
                        'chat_id'    => $target_chat_id,
                        'text'       => $message_text,
                        'parse_mode' => 'Markdown',
                        'disable_web_page_preview' => false // Разрешаем предпросмотр, если есть ссылки на файлы
                    ];

                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $telegram_api_url);
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);

                    if ($response === false) {
                        error_log("Ошибка отправки Telegram-сообщения в отдел " . $task_department . ": " . curl_error($ch));
                    } else {
                        $responseData = json_decode($response, true);
                        if ($responseData['ok'] === false) {
                            error_log("Ошибка Telegram API для отдела " . $task_department . ": " . $responseData['description']);
                        }
                    }
                    curl_close($ch);
                } else {
                    error_log("Не найден Chat ID для отдела: " . $task_department . ". Сообщение не отправлено.");
                }

            } else {
                $_SESSION['message'] .= "<p style='color: red;'>Ошибка при отправке задачи: " . $stmt->error . "</p>";
            }
            $stmt->close();
            header("Location: index.php");
            exit();
        } else {
            $_SESSION['message'] .= "<p style='color: red;'>Пожалуйста, введите тему, описание задачи и выберите отдел.</p>"; // Обновили сообщение
            header("Location: index.php");
            exit();
        }
    }
    if (isset($_POST['archive_my_task'])) {
        $task_id = $_POST['task_id'];
        $stmt_check_owner = $conn->prepare("SELECT user_id FROM tasks WHERE id = ?");
        $stmt_check_owner->bind_param("i", $task_id);
        $stmt_check_owner->execute();
        $result_owner = $stmt_check_owner->get_result();
        $task_owner_data = $result_owner->fetch_assoc();
        $stmt_check_owner->close();

        if ($task_owner_data && $task_owner_data['user_id'] == $current_user_id) {
            $stmt_archive = $conn->prepare("UPDATE tasks SET is_archived = 1 WHERE id = ?");
            $stmt_archive->bind_param("i", $task_id);

            if ($stmt_archive->execute()) {
                $_SESSION['message'] = "<p style='color: green;'>Задача успешно заархивирована!</p>";
            } else {
                $_SESSION['message'] = "<p style='color: red;'>Ошибка при архивировании задачи: " . $stmt_archive->error . "</p>";
            }
            $stmt_archive->close();
        } else {
            $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для архивирования этой задачи.</p>";
        }
        header("Location: index.php");
        exit();
    }
    if (isset($_POST['restore_my_archived_task'])) {
        $task_id = $_POST['task_id'];
        $stmt_check_owner = $conn->prepare("SELECT user_id FROM tasks WHERE id = ?");
        $stmt_check_owner->bind_param("i", $task_id);
        $stmt_check_owner->execute();
        $result_owner = $stmt_check_owner->get_result();
        $task_owner_data = $result_owner->fetch_assoc();
        $stmt_check_owner->close();

        if ($task_owner_data && $task_owner_data['user_id'] == $current_user_id) {
            $stmt_restore = $conn->prepare("UPDATE tasks SET is_archived = 0 WHERE id = ?");
            $stmt_restore->bind_param("i", $task_id);

            if ($stmt_restore->execute()) {
                $_SESSION['message'] = "<p style='color: green;'>Задача успешно восстановлена из архива!</p>";
            } else {
                $_SESSION['message'] = "<p style='color: red;'>Ошибка при восстановлении задачи из архива: " . $stmt_restore->error . "</p>";
            }
            $stmt_restore->close();
        } else {
            $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для восстановления этой задачи из архива.</p>";
        }
        header("Location: index.php?tab=archived-tasks");
        exit();
    }
    if (isset($_POST['delete_my_archived_task'])) {
        $task_id = $_POST['task_id'];
        $stmt_check_owner = $conn->prepare("SELECT user_id, file_paths FROM tasks WHERE id = ? AND is_archived = 1");
        $stmt_check_owner->bind_param("i", $task_id);
        $stmt_check_owner->execute();
        $result_owner = $stmt_check_owner->get_result();
        $task_data = $result_owner->fetch_assoc();
        $stmt_check_owner->close();

        if ($task_data && $task_data['user_id'] == $current_user_id) {
            // Удаляем файлы, прикрепленные к самой задаче
            delete_files($task_data['file_paths']);

            // --- НАЧАЛО ДОБАВЛЕННОГО КОДА: УДАЛЕНИЕ ФАЙЛОВ ИЗ СООБЩЕНИЙ ЧАТА ---
            $stmt_get_message_files = $conn->prepare("SELECT file_path FROM task_messages WHERE task_id = ?");
            $stmt_get_message_files->bind_param("i", $task_id);
            $stmt_get_message_files->execute();
            $result_message_files = $stmt_get_message_files->get_result();
            $message_files_to_delete = [];
            while ($row = $result_message_files->fetch_assoc()) {
                if ($row['file_path']) {
                    $decoded_paths = json_decode($row['file_path'], true);
                    if (is_array($decoded_paths)) {
                        $message_files_to_delete = array_merge($message_files_to_delete, $decoded_paths);
                    }
                }
            }
            $stmt_get_message_files->close();

            foreach ($message_files_to_delete as $path) {
                if (file_exists($path)) {
                    unlink($path);
                    error_log("Удален файл сообщения при удалении задачи из архива: " . $path);
                } else {
                    error_log("Файл сообщения не найден при удалении задачи из архива: " . $path);
                }
            }
            // --- КОНЕЦ ДОБАВЛЕННОГО КОДА ---

            // Удаляем сообщения, связанные с задачей
            $stmt_delete_messages = $conn->prepare("DELETE FROM task_messages WHERE task_id = ?");
            $stmt_delete_messages->bind_param("i", $task_id);
            $stmt_delete_messages->execute();
            $stmt_delete_messages->close();

            // Удаляем саму задачу
            $stmt_delete = $conn->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt_delete->bind_param("i", $task_id);

            if ($stmt_delete->execute()) {
                $_SESSION['message'] = "<p style='color: green;'>Задача из архива успешно удалена!</p>";
            } else {
                $_SESSION['message'] = "<p style='color: red;'>Ошибка при удалении задачи из архива: " . $stmt_delete->error . "</p>";
            }
            $stmt_delete->close();
        } else {
            $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для удаления этой задачи из архива.</p>";
        }
        header("Location: index.php?tab=archived-tasks");
        exit();
    }

    if (isset($_POST['clear_my_archive'])) {
        // Получаем файлы, прикрепленные к самим задачам
        $stmt_get_task_files = $conn->prepare("SELECT file_paths FROM tasks WHERE user_id = ? AND is_archived = 1");
        $stmt_get_task_files->bind_param("i", $current_user_id);
        $stmt_get_task_files->execute();
        $result_task_files = $stmt_get_task_files->get_result();
        $files_to_delete = [];
        while ($row = $result_task_files->fetch_assoc()) {
            if ($row['file_paths']) {
                $decoded_paths = json_decode($row['file_paths'], true);
                if (is_array($decoded_paths)) {
                    $files_to_delete = array_merge($files_to_delete, $decoded_paths);
                }
            }
        }
        $stmt_get_task_files->close();

        // --- НАЧАЛО ДОБАВЛЕННОГО КОДА: УДАЛЕНИЕ ФАЙЛОВ ИЗ СООБЩЕНИЙ ЧАТА ДЛЯ ВСЕГО АРХИВА ---
        // Получаем ID всех архивных задач текущего пользователя
        $stmt_get_archived_task_ids = $conn->prepare("SELECT id FROM tasks WHERE user_id = ? AND is_archived = 1");
        $stmt_get_archived_task_ids->bind_param("i", $current_user_id);
        $stmt_get_archived_task_ids->execute();
        $result_archived_task_ids = $stmt_get_archived_task_ids->get_result();
        $archived_task_ids = [];
        while ($row = $result_archived_task_ids->fetch_assoc()) {
            $archived_task_ids[] = $row['id'];
        }
        $stmt_get_archived_task_ids->close();

        if (!empty($archived_task_ids)) {
            // Создаем плейсхолдеры для IN-запроса
            $in_placeholders = implode(',', array_fill(0, count($archived_task_ids), '?'));
            $bind_types = str_repeat('i', count($archived_task_ids));

            $stmt_get_message_files_archive = $conn->prepare("SELECT file_path FROM task_messages WHERE task_id IN ($in_placeholders)");
            $stmt_get_message_files_archive->bind_param($bind_types, ...$archived_task_ids);
            $stmt_get_message_files_archive->execute();
            $result_message_files_archive = $stmt_get_message_files_archive->get_result();
            while ($row = $result_message_files_archive->fetch_assoc()) {
                if ($row['file_path']) {
                    $decoded_paths = json_decode($row['file_path'], true);
                    if (is_array($decoded_paths)) {
                        $files_to_delete = array_merge($files_to_delete, $decoded_paths);
                    }
                }
            }
            $stmt_get_message_files_archive->close();

            // Удаляем все сообщения, связанные с этими архивными задачами
            $stmt_delete_all_messages = $conn->prepare("DELETE FROM task_messages WHERE task_id IN ($in_placeholders)");
            $stmt_delete_all_messages->bind_param($bind_types, ...$archived_task_ids);
            $stmt_delete_all_messages->execute();
            $stmt_delete_all_messages->close();
        }
        // --- КОНЕЦ ДОБАВЛЕННОГО КОДА ---

        $stmt_clear_archive = $conn->prepare("DELETE FROM tasks WHERE user_id = ? AND is_archived = 1");
        $stmt_clear_archive->bind_param("i", $current_user_id);
        if ($stmt_clear_archive->execute()) {

            foreach ($files_to_delete as $path) {
                if (file_exists($path)) {
                    unlink($path);
                    error_log("Удален файл при очистке архива: " . $path);
                } else {
                    error_log("Файл не найден при очистке архива: " . $path);
                }
            }
            $_SESSION['message'] = "<p style='color: green;'>Ваш личный архив успешно очищен!</p>";
        } else {
            $_SESSION['message'] = "<p style='color: red;'>Ошибка при очистке вашего архива: " . $stmt_clear_archive->error . "</p>";
        }
        $stmt_clear_archive->close();
        header("Location: index.php?tab=archived-tasks");
        exit();
    }
}

// --- Функции для получения HTML содержимого вкладок ---

/**
 * Генерирует HTML для списка активных задач.
 * @param mysqli $conn Объект подключения к базе данных.
 * @param int $current_user_id ID текущего пользователя.
 * @param bool $current_user_is_admin Флаг, является ли текущий пользователь админом.
 * @param string|null $filter_department Фильтр по отделу.
 * @param string|null $filter Тип фильтра ('all_tasks' или 'my_tasks').
 * @return string HTML-строка со списком задач.
 */
function getActiveTasksHtml($conn, $current_user_id, $current_user_is_admin, $filter_department = null, $filter = null) {
    ob_start(); // Начинаем буферизацию вывода

    $status_mapping = [
        'На удержании' => 'on_hold',
        'Задача принята' => 'accepted',
        'Исполняется' => 'in_progress',
        'Выполнена' => 'completed'
    ];

    $where_clauses = ["t.is_archived = 0"];
    $bind_params = [];
    $bind_types = '';

    $select_fields = "t.id, t.task_subject, t.task_description, t.desired_due_date, t.admin_due_date, t.status, t.created_at, u.username, t.user_id, t.department, t.file_paths";
    $order_by = "ORDER BY t.created_at DESC";

    if ($filter_department && !empty($filter_department)) {
        $where_clauses[] = "t.department = ?";
        $bind_params[] = $filter_department;
        $bind_types .= 's';
    }

    if ($filter && $filter == 'my_tasks') {
        $where_clauses[] = "t.user_id = ?";
        $bind_params[] = $current_user_id;
        $bind_types .= 'i';
    }

    $sql = "SELECT $select_fields FROM tasks t LEFT JOIN users u ON t.user_id = u.id";

    if (!empty($where_clauses)) {
        $sql .= " WHERE " . implode(" AND ", $where_clauses);
    }
    $sql .= " " . $order_by;

    $stmt_select = $conn->prepare($sql);

    if (!empty($bind_params)) {
        $stmt_select->bind_param($bind_types, ...$bind_params);
    }
    $stmt_select->execute();
    $result = $stmt_select->get_result();

    // Получаем количество непрочитанных сообщений
    $unread_messages_count = [];
    if (!$current_user_is_admin) {
        $stmt_unread = $conn->prepare("
            SELECT tm.task_id, COUNT(tm.id) AS unread_count
            FROM task_messages tm
            JOIN users sender ON tm.sender_id = sender.id
            JOIN tasks t ON tm.task_id = t.id
            WHERE tm.is_read = 0
            AND sender.is_admin = 1
            AND t.user_id = ?
            AND t.is_archived = 0
            GROUP BY tm.task_id
        ");
        $stmt_unread->bind_param("i", $current_user_id);
    } else {
        $stmt_unread = $conn->prepare("
            SELECT tm.task_id, COUNT(tm.id) AS unread_count FROM task_messages tm
            JOIN users sender ON tm.sender_id = sender.id
            JOIN tasks t ON tm.task_id = t.id
            WHERE tm.is_read = 0
            AND sender.is_admin = 0
            AND t.is_archived = 0
            GROUP BY tm.task_id
        ");
    }

    $stmt_unread->execute();
    $result_unread = $stmt_unread->get_result();
    while ($unread_row = $result_unread->fetch_assoc()) {
        $unread_messages_count[$unread_row['task_id']] = $unread_row['unread_count'];
    }
    $stmt_unread->close();

    echo '<div class="filter-section">';
    echo '<form id="active-tasks-filter-form">'; // Добавляем ID для удобства в JS
    echo '<label for="filter_department">Показать задачи для отдела:</label>';
    echo '<select id="filter_department" name="filter_department" class="filter-select">';
    echo '<option value="">Все отделы</option>';
    echo '<option value="IT" ' . (($filter_department == 'IT') ? 'selected' : '') . '>IT</option>';
    echo '<option value="Офис-менеджер" ' . (($filter_department == 'Офис-менеджер') ? 'selected' : '') . '>Офис-менеджер</option>';
    echo '<option value="Маркетинговый отдел" ' . (($filter_department == 'Маркетинговый отдел') ? 'selected' : '') . '>Маркетинговый отдел</option>';
    echo '<option value="Отдел логистики" ' . (($filter_department == 'Отдел логистики') ? 'selected' : '') . '>Отдел логистики</option>';
    echo '<option value="Ремонтный отдел" ' . (($filter_department == 'Ремонтный отдел') ? 'selected' : '') . '>Ремонтный отдел</option>';
    echo '<option value="Бухгалтерия" ' . (($filter_department == 'Бухгалтерия') ? 'selected' : '') . '>Бухгалтерия</option>';
    echo '</select>';
    echo '<div class="filter-buttons">';
    $is_all_tasks_active = (!$filter || $filter == 'all_tasks');
    $is_my_tasks_active = ($filter == 'my_tasks');
    echo '<button type="button" class="filter-button ' . ($is_all_tasks_active ? 'active-filter-button' : '') . '" data-filter-value="all_tasks">Все задачи</button>';
    echo '<button type="button" class="filter-button secondary ' . ($is_my_tasks_active ? 'active-filter-button' : '') . '" data-filter-value="my_tasks">Мои задачи</button>';
    echo '<button type="button" class="filter-button secondary" data-filter-value="reset">Сбросить фильтры</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="tasks-list">';
    if ($result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $status_class = $status_mapping[$row["status"]] ?? 'unknown_status';

            $desired_due_date_formatted = $row["desired_due_date"] ? date('d-m-Y', strtotime($row["desired_due_date"])) : "Не указан";
            $admin_due_date_formatted = $row["admin_due_date"] ? date('d-m-Y', strtotime($row["admin_due_date"])) : "Ожидает назначения";
            $created_at_formatted = date('d-m-Y', strtotime($row["created_at"]));

            echo "<div class='table_zadacha'><div class='task-row'>";
            echo "<div class='task-cell date'><strong>Желаемый срок:</strong><br> " . htmlspecialchars($desired_due_date_formatted) . "</div>";
            echo "<div class='task-cell date'><strong>Срок исполнения:</strong><br>" . htmlspecialchars($admin_due_date_formatted) . "</div>";
            echo "<div class='task-cell'><strong>Статус:</strong><br><div class ='status status-" . $status_class . "'> ". htmlspecialchars($row["status"]) . "</div></div>";
            echo "<div class='task-cell department'><strong>Отдел:</strong><br> " . htmlspecialchars($row["department"] ?? "Не указан") . "</div>";
            echo "<div class='task-cell restaurant'><strong>Ресторан:</strong><br> " . htmlspecialchars($row["username"] ? $row["username"] : "Неизвестно") . "</div>";
            echo "<div class='task-cell date'><strong>Создано:</strong><br>" . htmlspecialchars($created_at_formatted) . "</div>";
            echo "</div>"; // Закрываем div.task-row с основными данными

            $can_view_details = ($row['user_id'] == $current_user_id) || $current_user_is_admin;

            if ($can_view_details) {
                echo "<div class='task-cell description'>
                    <strong>Тема:</strong><br>" . nl2br(htmlspecialchars($row["task_subject"])) . "<br><br>
                    <strong>Описание задачи:</strong><br>" . nl2br(htmlspecialchars($row["task_description"])) . " </div>";
                if (!empty($row['file_paths'])) {
                    $file_paths = json_decode($row['file_paths'], true);
                    if (is_array($file_paths) && !empty($file_paths)) {
                        echo "<div class='task-cell'><strong>Вложенные файлы:</strong><br><div class='task-files'>";
                        foreach ($file_paths as $index => $path) {
                            echo "<a href='" . htmlspecialchars($path) . "' target='_blank'>Файл " . ($index + 1) . "</a>";
                        }
                        echo "</div></div>";
                    }
                }
            } else {
                echo "<div class='task-cell description'><strong>Описание задачи:</strong><br><span style='color: #888;'>Приватная задача</span></div>";
            }

            echo "<div class='task-actions'>";
            if ($row['user_id'] == $current_user_id || $current_user_is_admin) {
                $task_id = htmlspecialchars($row['id']);
                $has_new_messages = isset($unread_messages_count[$task_id]) && $unread_messages_count[$task_id] > 0;
                echo "<form>";
                echo "<button type='button' class='chat-button' onclick=\"window.location.href='chat.php?task_id={$task_id}'\">";
                echo "Переписка";
                if ($has_new_messages) {
                    echo "<span class='new-message-indicator'></span>";
                }
                echo "</button>";
                echo "</form>";
            }
            if ($row['user_id'] == $current_user_id) {
                echo "<form action='edit_task.php' method='get'>";
                echo "<input type='hidden' name='id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' class='edit-button'>Изменить</button>";
                echo "</form>";
                echo "<form action='index.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите переместить эту задачу в архив?');\">";
                echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' name='archive_my_task' class='archive-button-user'>В архив</button>";
                echo "</form>";
                echo "<form action='delete_task.php' method='get' onsubmit=\"return confirm('Вы уверены, что хотите удалить эту задачу? (Это действие необратимо, если по задаче есть переписка, она также будет удалена!)');\">";
                echo "<input type='hidden' name='id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' class='delete-button'>Удалить</button>";
                echo "</form>";
            }
            echo "</div> </div>";
        }
    } else {
        echo "<div class='task-row'><div class='task-cell' style='width: 100%;'>Задач по выбранным фильтрам пока нет.</div></div>";
    }
    echo '</div>'; // Закрываем div.tasks-list

    $stmt_select->close();
    return ob_get_clean(); // Возвращаем содержимое буфера
}

/**
 * Генерирует HTML для списка архивных задач.
 * @param mysqli $conn Объект подключения к базе данных.
 * @param int $current_user_id ID текущего пользователя.
 * @param bool $current_user_is_admin Флаг, является ли текущий пользователь админом.
 * @param string|null $filter_archive_department Фильтр по отделу для архива.
 * @return string HTML-строка со списком архивных задач.
 */
function getArchivedTasksHtml($conn, $current_user_id, $current_user_is_admin, $filter_archive_department = null) {
    ob_start(); // Начинаем буферизацию вывода

    $status_mapping = [
        'На удержании' => 'on_hold',
        'Задача принята' => 'accepted',
        'Исполняется' => 'in_progress',
        'Выполнена' => 'completed'
    ];

    $archive_where_clauses = ["t.user_id = ?", "t.is_archived = 1"];
    $archive_bind_params = [$current_user_id];
    $archive_bind_types = 'i';

    if ($filter_archive_department && !empty($filter_archive_department)) {
        $archive_where_clauses[] = "t.department = ?";
        $archive_bind_params[] = $filter_archive_department;
        $archive_bind_types .= 's';
    }

    $sql_archive = "SELECT t.id, t.task_subject, t.task_description, t.desired_due_date, t.admin_due_date, t.status, t.created_at, u.username, t.user_id, t.department, t.file_paths FROM tasks t LEFT JOIN users u ON t.user_id = u.id WHERE " . implode(" AND ", $archive_where_clauses) . " ORDER BY t.created_at DESC";

    $stmt_archive_select = $conn->prepare($sql_archive);
    $stmt_archive_select->bind_param($archive_bind_types, ...$archive_bind_params);
    $stmt_archive_select->execute();
    $result_archive = $stmt_archive_select->get_result();

    // Получаем количество непрочитанных сообщений для архивных задач
    $unread_messages_count_archive = [];
    if (!$current_user_is_admin) {
        $stmt_unread_archive = $conn->prepare("
            SELECT tm.task_id, COUNT(tm.id) AS unread_count
            FROM task_messages tm
            JOIN users sender ON tm.sender_id = sender.id
            JOIN tasks t ON tm.task_id = t.id
            WHERE tm.is_read = 0
            AND sender.is_admin = 1
            AND t.user_id = ?
            AND t.is_archived = 1
            GROUP BY tm.task_id
        ");
        $stmt_unread_archive->bind_param("i", $current_user_id);
    } else {
         $stmt_unread_archive = $conn->prepare("
            SELECT tm.task_id, COUNT(tm.id) AS unread_count
            FROM task_messages tm
            JOIN users sender ON tm.sender_id = sender.id
            JOIN tasks t ON tm.task_id = t.id
            WHERE tm.is_read = 0
            AND sender.is_admin = 0
            AND t.is_archived = 1
            GROUP BY tm.task_id
        ");
    }
    $stmt_unread_archive->execute();
    $result_unread_archive = $stmt_unread_archive->get_result();
    while ($unread_row_archive = $result_unread_archive->fetch_assoc()) {
        $unread_messages_count_archive[$unread_row_archive['task_id']] = $unread_row_archive['unread_count'];
    }
    $stmt_unread_archive->close();

    echo '<div class="filter-section">';
    echo '<form id="archived-tasks-filter-form">'; // Добавляем ID для удобства в JS
    echo '<label for="filter_archive_department">Показать задачи для отдела:</label>';
    echo '<select id="filter_archive_department" name="filter_archive_department" class="filter-select">';
    echo '<option value="">Все отделы</option>';
    echo '<option value="IT" ' . (($filter_archive_department == 'IT') ? 'selected' : '') . '>IT</option>';
    echo '<option value="Офис-менеджер" ' . (($filter_archive_department == 'Офис-менеджер') ? 'selected' : '') . '>Офис-менеджер</option>';
    echo '<option value="Маркетинговый отдел" ' . (($filter_archive_department == 'Маркетинговый отдел') ? 'selected' : '') . '>Маркетинговый отдел</option>';
    echo '<option value="Отдел логистики" ' . (($filter_archive_department == 'Отдел логистики') ? 'selected' : '') . '>Отдел логистики</option>';
    echo '<option value="Ремонтный отдел" ' . (($filter_archive_department == 'Ремонтный отдел') ? 'selected' : '') . '>Ремонтный отдел</option>';
    echo '<option value="Бухгалтерия" ' . (($filter_archive_department == 'Бухгалтерия') ? 'selected' : '') . '>Бухгалтерия</option>';
    echo '</select>';
    echo '<div class="filter-buttons">';
    echo '<button type="button" class="filter-button secondary" data-filter-value="reset-archive">Сбросить фильтр отдела</button>';
    echo '</div>';
    echo '</form>';
    echo '</div>';

    echo '<div class="tasks-list">';
    if ($result_archive->num_rows > 0) {
        while($row = $result_archive->fetch_assoc()) {
            $status_class = $status_mapping[$row["status"]] ?? 'unknown_status';

            $desired_due_date_formatted = $row["desired_due_date"] ? date('d-m-Y', strtotime($row["desired_due_date"])) : "Не указан";
            $admin_due_date_formatted = $row["admin_due_date"] ? date('d-m-Y', strtotime($row["admin_due_date"])) : "Ожидает назначения";
            $created_at_formatted = date('d-m-Y', strtotime($row["created_at"]));

            echo "<div class='table_zadacha'><div class='task-row'>";
            echo "<div class='task-cell date'><strong>Желаемый срок:</strong><br> " . htmlspecialchars($desired_due_date_formatted) . "</div>";
            echo "<div class='task-cell date'><strong>Срок исполнения:</strong><br>" . htmlspecialchars($admin_due_date_formatted) . "</div>";
            echo "<div class='task-cell'><strong>Статус:</strong><br><div class ='status status-" . $status_class . "'> ". htmlspecialchars($row["status"]) . "</div></div>";
            echo "<div class='task-cell department'><strong>Отдел:</strong><br> " . htmlspecialchars($row["department"] ?? "Не указан") . "</div>";
            echo "<div class='task-cell restaurant'><strong>Ресторан:</strong><br> " . htmlspecialchars($row["username"] ? $row["username"] : "Неизвестно") . "</div>";
            echo "<div class='task-cell date'><strong>Создано:</strong><br>" . htmlspecialchars($created_at_formatted) . "</div>";
            echo "</div>"; // Закрываем div.task-row с основными данными

            echo "<div class='task-cell description'><strong>Описание задачи:</strong><br>" . nl2br(htmlspecialchars($row["task_description"])) . "</div>";
            if (!empty($row['file_paths'])) {
                $file_paths = json_decode($row['file_paths'], true);
                if (is_array($file_paths) && !empty($file_paths)) {
                    echo "<div class='task-cell'><strong>Вложенные файлы:</strong><br><div class='task-files'>";
                    foreach ($file_paths as $index => $path) {
                        echo "<a href='" . htmlspecialchars($path) . "' target='_blank'>Файл " . ($index + 1) . "</a>";
                    }
                    echo "</div></div>";
                }
            }

            echo "<div class='task-actions'>";
            $task_id_archive = htmlspecialchars($row['id']);
            $has_new_messages_archive = isset($unread_messages_count_archive[$task_id_archive]) && $unread_messages_count_archive[$task_id_archive] > 0;
            echo "<form>";
            echo "<button type='button' class='chat-button' onclick=\"window.location.href='chat.php?task_id={$task_id_archive}'\">";
            echo "Переписка";
            if ($has_new_messages_archive) {
                echo "<span class='new-message-indicator'></span>";
            }
            echo "</button>";
            echo "</form>";

            echo "<form action='index.php' method='post'>";
            echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
            echo "<button type='submit' name='restore_my_archived_task' class='restore-button-user'>Восстановить</button>";
            echo "</form>";

            echo "<form action='index.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите безвозвратно удалить эту задачу из архива?');\">";
            echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
            echo "<button type='submit' name='delete_my_archived_task' class='delete-archived-button-user'>Удалить</button>";
            echo "</form>";
            echo "</div> </div>";
        }
    } else {
        echo "<div class='task-row'><div class='task-cell' style='width: 100%;'>В вашем архиве пока нет задач по выбранным фильтрам.</div></div>";
    }
    echo '</div>'; // Закрываем div.tasks-list

    echo "<hr>";
    echo "<form action='index.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите полностью очистить свой личный архив? Это действие необратимо!');\">";
    echo "<button type='submit' name='clear_my_archive' class='clear-archive-button-user'>Очистить весь мой архив</button>";
    echo "</form>";

    $stmt_archive_select->close();
    return ob_get_clean(); // Возвращаем содержимое буфера
}

// Проверяем, является ли запрос AJAX-ом
$is_ajax_request = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($is_ajax_request) {
    // Если это AJAX-запрос, отдаем только HTML соответствующей вкладки
    $tab = $_GET['tab'] ?? 'active-tasks'; // Получаем нужную вкладку

    if ($tab === 'active-tasks') {
        $filter_department = $_GET['filter_department'] ?? null;
        $filter = $_GET['filter'] ?? null;
        echo getActiveTasksHtml($conn, $current_user_id, $current_user_is_admin, $filter_department, $filter);
    } elseif ($tab === 'archived-tasks') {
        $filter_archive_department = $_GET['filter_archive_department'] ?? null;
        echo getArchivedTasksHtml($conn, $current_user_id, $current_user_is_admin, $filter_archive_department);
    }
    // Для 'new-task' не требуется AJAX-загрузка, так как форма статична

    $conn->close();
    exit(); // Завершаем выполнение скрипта после отправки HTML
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Планер Задач</title>
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
            max-width: 1200px;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1, h2 {
            color: #0056b3;
            margin-top: 0;
            margin-bottom: 15px;
        }
        .table_zadacha {
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #f9f9f9;
            margin-bottom: 30px;
        }
        .welcome-section {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 10px;
        }
        .welcome-section p {
            margin: 0;
            font-size: 1.1em;
            font-weight: bold;
        }
        .logout-button {
            background-color: #dc3545;
            color: white;
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.9em;
            white-space: nowrap;
        }
        .logout-button:hover {
            background-color: #c82333;
        }
        form {
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            padding-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        textarea, input[type="date"], select, input[type="file"] {
            font-family: "Montserrat", sans-serif;
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button[type="submit"] {
            font-family: "Montserrat", sans-serif;
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
        }
        button[type="submit"]:hover {
            background-color: #0056b3;
        }
        .tasks-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .task-header, .task-row {
            display: flex;
            flex-wrap: wrap;
            padding: 10px;
            border-bottom: 1px solid #ddd;
            background-color: #f9f9f9;
            align-items: center;
            font-size: 13px;
        }
        .task-header {
            font-weight: bold;
            background-color: #f2f2f2;
            margin-bottom: 5px;
        }
        .task-cell {
            flex: 1;
            padding: 5px 10px;
        }
        .task-cell.description { flex: 2; padding: 16px; width: 95%;}
        .task-cell.status { min-width: 15%; }
        .task-cell.date { min-width: 15%;}
        .task-cell.restaurant { min-width: 15%; }
        .task-cell.department { min-width: 15%; }
        .task-files {
            margin-top: 10px;
        }
        .task-files a {
            display: block;
            background-color: #e6f7ff;
            padding: 5px 10px;
            border-radius: 5px;
            margin-bottom: 5px;
            text-decoration: none;
            color: #007bff;
            font-size: 0.9em;
        }
        .task-files a:hover {
            background-color: #cceeff;
        }

        .task-actions {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            margin-bottom: 10px;
            justify-content: flex-start;
            width: 97%;
            padding: 5px 10px;
            padding-top: 10px;
        }
        .task-actions form {
            flex: 1;
            margin: 0;
            display: flex;
        }
        .task-actions > * {
            flex-grow: 1;
            box-sizing: border-box;
        }

        .task-actions button {
            font-family: "Montserrat", sans-serif;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.95em;
            white-space: nowrap;
            text-align: center;
            display: flex;
            justify-content: center;
            align-items: center;
            box-sizing: border-box;
            color: white;
            width: 100%;
        }
        .task-actions button:hover {
            opacity: 0.9;
        }

        .edit-button {
            background-color: #ffc107;
            color: #333;
        }
        .edit-button:hover {
            background-color: #e0a800;
        }

        .delete-button {
            background-color: #dc3545;
        }
        .delete-button:hover {
            background-color: #c82333;
        }

        .chat-button {
            background-color: #6f42c1;
        }
        .chat-button:hover {
            background-color: #5a32a0;
        }

        .archive-button-user {
            background-color: #17a2b8;
        }
        .archive-button-user:hover {
            background-color: #138496;
        }
        .restore-button-user {
            background-color: #28a745;
        }
        .restore-button-user:hover {
            background-color: #218838;
        }
        .delete-archived-button-user, .clear-archive-button-user {
            background-color: #dc3545;
        }
        .delete-archived-button-user:hover, .clear-archive-button-user:hover {
            background-color: #c82333;
        }

        .status.status-on_hold { color: #dc3545; font-weight: bold;}
        .status.status-accepted { color: #ffc107; font-weight: bold;}
        .status.status-in_progress { color: #2637da; font-weight: bold;}
        .status.status-completed { color: #28a745; font-weight: bold;}

        .filter-section {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid #eee;
        }
        .filter-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .filter-button {
            background-color: #007bff;
            color: white;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.95em;
            white-space: nowrap;
        }
        .filter-button:hover {
            background-color: #0056b3;
        }
        .filter-button.secondary {
            background-color: #6c757d;
        }
        .filter-button.secondary:hover {
            background-color: #5a6268;
        }

        .filter-select {
            width: 200px;
        }

        .filter-button.active-filter-button {
            background-color: #e0e0e0;
            color: #333;
            box-shadow: inset 0 0 5px rgba(0,0,0,0.2);
        }
        .filter-button.active-filter-button:hover {
            background-color: #d0d0d0;
        }

        #selected-files-preview {
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

        .new-message-indicator {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 10px;
            height: 10px;
            background-color: red;
            border-radius: 50%;
            display: block;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.5);
        }
        .chat-button {
            position: relative;
        }

        .tabs-container {
            margin-top: 20px;
        }
        .tab-buttons {
            display: flex;
            border-bottom: 2px solid #ddd;
            margin-bottom: 20px;
        }
        .tab-button {
            background-color: #f2f2f2;
            border: 1px solid #ddd;
            border-bottom: none;
            padding: 10px 20px;
            cursor: pointer;
            font-weight: bold;
            border-top-left-radius: 8px;
            border-top-right-radius: 8px;
            margin-right: 5px;
            transition: background-color 0.3s ease;
        }
        .tab-button:hover {
            background-color: #e0e0e0;
        }
        .tab-button.active {
            background-color: #fff;
            border-bottom: 2px solid #fff;
            position: relative;
            z-index: 1;
        }
        .tab-content .tab-pane {
            display: none;
            padding: 20px 0;
            border-top: none;
        }
        .tab-content .tab-pane.active {
            display: block;
        }

        @media (max-width: 1084px) {
             .task-header, .task-row {
                font-size: 14px;
            }
            .task-cell {
                padding: 5px;
            }
        }

        @media (max-width: 768px) {
            .container {
                margin: 10px;
                padding: 15px;
            }
            .welcome-section {
                flex-direction: column;
                align-items: flex-start;
            }
            .welcome-section p, .welcome-section a {
                margin-bottom: 10px;
            }
            h1 {
                font-size: 1.5em;
            }
            h2 {
                font-size: 1.2em;
            }
            .logout-button {
                width: 100%;
                text-align: center;
                margin-top: 10px;
            }
            .task-header {
                display: none;
            }
            .task-row {
                flex-direction: column;
                align-items: flex-start;
                padding: 10px;
            }
            .task-cell {
                width: 100%;
                padding: 5px 0;
                border-bottom: 1px dashed #eee;
                flex: none;
                max-width: none;
                min-width: none;
            }
            .task-cell:last-child {
                border-bottom: none;
            }
            .task-actions {
                flex-direction: column;
                align-items: stretch;
                padding: 10px 0;
                border-top: none;
            }
            .edit-button, .delete-button, .chat-button, .archive-button-user, .restore-button-user, .delete-archived-button-user, .clear-archive-button-user {
                width: 100%;
                margin-bottom: 5px;
            }
            .filter-section .filter-buttons {
                flex-direction: column;
                gap: 5px;
            }
            .filter-section .filter-select {
                width: 100%;
            }
            .filter-section .filter-button {
                width: 100%;
                text-align: center;
            }
            .new-message-indicator {
                top: 5px;
                right: 5px;
            }
            .tab-buttons {
                flex-direction: column;
            }
            .tab-button {
                margin-right: 0;
                margin-bottom: 5px;
                border-bottom: 1px solid #ddd;
                border-radius: 4px;
            }
            .tab-button.active {
                border-bottom: 1px solid #ddd;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="welcome-section">
            <h1>GG - список задач!</h1>
            <p>Ваш ресторан: <?php echo htmlspecialchars($current_username); ?></p>
            <a href="?logout" class="logout-button">Выйти</a>
        </div>
        <p>Здесь вы можете оставить задачу для рекламного отдела, посмотреть список задач от других ресторанов, увидеть общий список задач и просмотреть их текущий статус.</p>

        <hr>

        <div class="tabs-container">
            <div class="tab-buttons">
                <button class="tab-button" data-tab="new-task">Отправить новую задачу</button>
                <button class="tab-button active" data-tab="active-tasks">Активные задачи</button>
                <button class="tab-button" data-tab="archived-tasks">Мой архив</button>
            </div>

            <div class="tab-content">
                <div id="new-task" class="tab-pane">
                    <h2>Отправить новую задачу</h2>
                    <?php echo $message; ?>

                    <form action="index.php" method="post" enctype="multipart/form-data">
                        <label for="task_subject">Тема задачи (до 160 символов):</label>
                        <input type="text" id="task_subject" name="task_subject" maxlength="160" required>
                        <label for="task_description">Описание задачи:</label>
                        <textarea id="task_description" name="task_description" rows="5" required></textarea>

                        <label for="desired_due_date">Желаемый срок выполнения (необязательно):</label>
                        <input type="date" id="desired_due_date" name="desired_due_date">

                        <label for="task_department">Отдел, которому адресована задача:</label>
                        <select id="task_department" name="task_department" required>
                            <option value="">Выберите отдел</option>
                            <option value="IT">IT</option>
                            <option value="Офис-менеджер">Офис-менеджер</option>
                            <option value="Маркетинговый отдел">Маркетинговый отдел</option>
                            <option value="Отдел логистики">Отдел логистики</option>
                            <option value="Ремонтный отдел">Ремонтный отдел</option>
                            <option value="Ремонтный отдел">Бухгалтерия</option>
                        </select>

                        <label for="task_files">Прикрепить файлы (фото или документ, до 5МБ каждый):</label>
                        <input type="file" id="task_files" name="task_files[]" multiple accept="image/*, .pdf, .doc, .docx, .xls, .xlsx">
                        <div id="selected-files-preview"></div>

                        <button type="submit" name="submit_new_task">Отправить задачу</button>
                    </form>
                </div>

                <div id="active-tasks" class="tab-pane active">
                    <h2>Активные задачи</h2>
                    <?php
                        // Инициализация активной вкладки при первой загрузке страницы
                        $active_filter_department = $_GET['filter_department'] ?? null;
                        $active_filter = $_GET['filter'] ?? null;
                        echo getActiveTasksHtml($conn, $current_user_id, $current_user_is_admin, $active_filter_department, $active_filter);
                    ?>
                </div>

                <div id="archived-tasks" class="tab-pane">
                    <h2>Мой архив задач</h2>
                    <?php
                        $archived_filter_department = $_GET['filter_archive_department'] ?? null;
                        echo getArchivedTasksHtml($conn, $current_user_id, $current_user_is_admin, $archived_filter_department);
                    ?>
                </div>
            </div>
        </div>
        <?php $conn->close(); ?>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const fileInput = document.getElementById('task_files');
            const selectedFilesPreview = document.getElementById('selected-files-preview');
            let filesArray = [];

            function renderSelectedFiles() {
                selectedFilesPreview.innerHTML = '';
                if (filesArray.length === 0) {
                    selectedFilesPreview.style.display = 'none';
                    return;
                }
                selectedFilesPreview.style.display = 'block';

                filesArray.forEach((file, index) => {
                    const fileItem = document.createElement('div');
                    fileItem.classList.add('file-item');
                    fileItem.innerHTML = `
                        <span>${file.name} (${(file.size / 1024 / 1024).toFixed(2)} МБ)</span>
                        <button type="button" class="remove-file-button" data-index="${index}">Удалить</button>
                    `;
                    selectedFilesPreview.appendChild(fileItem);
                });

                document.querySelectorAll('.remove-file-button').forEach(button => {
                    button.addEventListener('click', function() {
                        const indexToRemove = parseInt(this.dataset.index);
                        filesArray.splice(indexToRemove, 1);
                        updateFileInput();
                        renderSelectedFiles();
                    });
                });
            }

            function updateFileInput() {
                const dataTransfer = new DataTransfer();
                filesArray.forEach(file => dataTransfer.items.add(file));
                fileInput.files = dataTransfer.files;
            }

            fileInput.addEventListener('change', function() {
                for (let i = 0; i < fileInput.files.length; i++) {
                    filesArray.push(fileInput.files[i]);
                }
                renderSelectedFiles();
            });

            renderSelectedFiles(); // Вызываем при загрузке страницы для отображения уже выбранных файлов (если форма не очищается)

            const tabButtons = document.querySelectorAll('.tab-button');
            const tabPanes = document.querySelectorAll('.tab-pane');
            const activeTasksContainer = document.getElementById('active-tasks');
            const archivedTasksContainer = document.getElementById('archived-tasks');

            // Функция для загрузки содержимого вкладки по AJAX
            async function loadTabContent(tabName, params = {}) {
                const urlParams = new URLSearchParams(params);
                urlParams.set('tab', tabName); // Добавляем параметр вкладки
                urlParams.set('is_ajax', '1'); // Маркер AJAX запроса
                const url = `index.php?${urlParams.toString()}`;

                try {
                    const response = await fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest' // Отправляем заголовок для PHP-обработки
                        }
                    });
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const html = await response.text();
                    if (tabName === 'active-tasks') {
                        activeTasksContainer.innerHTML = '<h2>Активные задачи</h2>' + html;
                    } else if (tabName === 'archived-tasks') {
                        archivedTasksContainer.innerHTML = '<h2>Мой архив задач</h2>' + html;
                    }
                    // Обновляем обработчики событий для новых элементов
                    attachFilterListeners();
                } catch (error) {
                    console.error('Ошибка загрузки содержимого вкладки:', error);
                    // Возможно, показать сообщение об ошибке пользователю
                }
            }

            // Функция для прикрепления обработчиков событий к фильтрам
            function attachFilterListeners() {
                // Для активных задач
                const activeDepartmentSelect = document.querySelector('#active-tasks #filter_department');
                if (activeDepartmentSelect) {
                    activeDepartmentSelect.onchange = function() {
                        const filterDepartment = this.value;
                        const activeFilterButtons = document.querySelectorAll('#active-tasks .filter-buttons .filter-button');
                        let currentFilter = 'all_tasks'; // Дефолтный фильтр
                        activeFilterButtons.forEach(btn => {
                            if (btn.classList.contains('active-filter-button')) {
                                currentFilter = btn.dataset.filterValue;
                            }
                        });
                         if (currentFilter === 'reset') { // Если был сброс, то по умолчанию all_tasks
                            currentFilter = 'all_tasks';
                        }
                        loadTabContent('active-tasks', { filter_department: filterDepartment, filter: currentFilter });
                    };
                }

                document.querySelectorAll('#active-tasks .filter-buttons .filter-button').forEach(button => {
                    button.onclick = function() {
                        const filterValue = this.dataset.filterValue;
                        const filterDepartment = activeDepartmentSelect ? activeDepartmentSelect.value : '';

                        // Снимаем активность со всех кнопок и добавляем к текущей
                        document.querySelectorAll('#active-tasks .filter-buttons .filter-button').forEach(btn => btn.classList.remove('active-filter-button'));
                        if (filterValue !== 'reset') {
                           this.classList.add('active-filter-button');
                        } else {
                            // Если сброс, то сбрасываем и select
                            if (activeDepartmentSelect) activeDepartmentSelect.value = '';
                            // Активной становится кнопка "Все задачи"
                            document.querySelector('#active-tasks .filter-button[data-filter-value="all_tasks"]').classList.add('active-filter-button');
                        }

                        let params = {};
                        if (filterValue === 'reset') {
                            params = { filter_department: '', filter: 'all_tasks' };
                        } else {
                            params = { filter_department: filterDepartment, filter: filterValue };
                        }
                        loadTabContent('active-tasks', params);
                    };
                });


                // Для архивных задач
                const archivedDepartmentSelect = document.querySelector('#archived-tasks #filter_archive_department');
                if (archivedDepartmentSelect) {
                    archivedDepartmentSelect.onchange = function() {
                        loadTabContent('archived-tasks', { filter_archive_department: this.value });
                    };
                }

                document.querySelectorAll('#archived-tasks .filter-buttons .filter-button[data-filter-value="reset-archive"]').forEach(button => {
                    button.onclick = function() {
                        if (archivedDepartmentSelect) archivedDepartmentSelect.value = ''; // Сбрасываем select
                        loadTabContent('archived-tasks', { filter_archive_department: '' });
                    };
                });
            }

            // Инициализация вкладок при загрузке страницы
            function initializeTabs() {
                const urlParams = new URLSearchParams(window.location.search);
                const initialTab = urlParams.get('tab') || 'active-tasks';

                tabButtons.forEach(btn => {
                    btn.classList.remove('active');
                    if (btn.dataset.tab === initialTab) {
                        btn.classList.add('active');
                    }
                });
                tabPanes.forEach(pane => {
                    pane.classList.remove('active');
                    if (pane.id === initialTab) {
                        pane.classList.add('active');
                    }
                });

                // Если активная вкладка - "Новая задача", не загружаем ничего по AJAX
                // Если "Активные задачи" или "Мой архив", то они уже загружены PHP, но нужно прикрепить слушатели
                attachFilterListeners();
            }

            tabButtons.forEach(button => {
                button.addEventListener('click', () => {
                    tabButtons.forEach(btn => btn.classList.remove('active'));
                    tabPanes.forEach(pane => pane.classList.remove('active'));

                    const targetTab = button.dataset.tab;
                    document.getElementById(targetTab).classList.add('active');
                    button.classList.add('active');

                    // Обновляем URL без перезагрузки страницы
                    const url = new URL(window.location.href);
                    url.searchParams.set('tab', targetTab);
                    // Очищаем специфичные для вкладок параметры при переключении
                    if (targetTab !== 'active-tasks') {
                        url.searchParams.delete('filter');
                        url.searchParams.delete('filter_department');
                    }
                    if (targetTab !== 'archived-tasks') {
                        url.searchParams.delete('filter_archive_department');
                    }
                    history.pushState(null, '', url.toString());

                    // Если вкладка требует динамической загрузки, вызываем loadTabContent
                    if (targetTab === 'active-tasks' || targetTab === 'archived-tasks') {
                         // Загружаем контент только если он еще не был загружен или нужно обновить
                         // Сейчас мы загружаем его всегда, чтобы учесть сброс фильтров
                        let params = {};
                        if (targetTab === 'active-tasks') {
                            // При переключении на активные задачи, сбрасываем фильтры или используем дефолтные
                            params = { filter_department: '', filter: 'all_tasks' };
                        } else if (targetTab === 'archived-tasks') {
                            params = { filter_archive_department: '' };
                        }
                        loadTabContent(targetTab, params);
                    }
                });
            });

            // Инициализируем табы при загрузке страницы, чтобы применить корректные стили
            initializeTabs();
              // --- НОВЫЙ КОД ДЛЯ ОНЛАЙН УВЕДОМЛЕНИЙ ---
        const NEW_MESSAGE_INDICATOR_CLASS = 'new-message-indicator'; // Класс индикатора

        async function fetchUnreadMessages() {
            try {
                const response = await fetch('check_new_messages.php', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                if (!response.ok) {
                    throw new Error('HTTP error! status: ${response.status}');
                }
                const unreadCounts = await response.json(); // Парсим JSON ответ

                // Проходимся по всем кнопкам чата на странице
                document.querySelectorAll('.chat-button').forEach(button => {
                    const taskId = button.onclick.toString().match(/task_id=(\d+)/); // Извлекаем task_id из onclick
                    if (taskId && taskId[1]) {
                        const currentTaskId = taskId[1];
                        const indicator = button.querySelector('.' + NEW_MESSAGE_INDICATOR_CLASS);

                        if (unreadCounts[currentTaskId] && unreadCounts[currentTaskId] > 0) {
                            // Если есть непрочитанные сообщения для этой задачи, показываем индикатор
                            if (!indicator) {
                                const newIndicator = document.createElement('span');
                                newIndicator.classList.add(NEW_MESSAGE_INDICATOR_CLASS);
                                button.appendChild(newIndicator);
                            }
                        } else {
                            // Если нет непрочитанных сообщений, удаляем индикатор
                            if (indicator) {
                                indicator.remove();
                            }
                        }
                    }
                });
            } catch (error) {
                console.error('Ошибка при получении непрочитанных сообщений:', error);
            }
        }

        // Вызываем функцию при загрузке страницы
        fetchUnreadMessages();

        // Запускаем периодическую проверку каждые 10 секунд (10000 миллисекунд)
        // Вы можете настроить этот интервал по своему усмотрению.
        setInterval(fetchUnreadMessages, 10000);

        // --- КОНЕЦ НОВОГО КОДА ---
        });
    </script>
</body>
</html>

