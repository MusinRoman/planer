<?php
session_start();
require_once 'db_connect.php';

// Проверяем, авторизован ли пользователь
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Проверяем, передан ли ID задачи и является ли он числом
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $task_id = $_GET['id'];
    $current_user_id = $_SESSION['user_id'];

    // Начинаем транзакцию для обеспечения атомарности операции
    $conn->begin_transaction();

    try {
        // 1. Получаем пути к файлам задачи и user_id задачи перед удалением
        // Этот запрос найдет задачу по ID, независимо от ее статуса (активная/архивная)
        $stmt_get_task_files = $conn->prepare("SELECT file_paths, user_id FROM tasks WHERE id = ?");
        $stmt_get_task_files->bind_param("i", $task_id);
        $stmt_get_task_files->execute();
        $result_get_task_files = $stmt_get_task_files->get_result();
        $task_data = $result_get_task_files->fetch_assoc();
        $stmt_get_task_files->close();

        // Проверяем, найдена ли задача
        if ($task_data) {
            // Проверяем, что задача принадлежит текущему пользователю
            if ($task_data['user_id'] == $current_user_id) {
                $all_files_to_delete = [];

                // Добавляем файлы, прикрепленные к самой задаче
                $task_file_paths_json = $task_data['file_paths'];
                if ($task_file_paths_json) {
                    $task_files = json_decode($task_file_paths_json, true);
                    if (is_array($task_files)) {
                        $all_files_to_delete = array_merge($all_files_to_delete, $task_files);
                    }
                }

                // 2. Получаем пути к файлам из сообщений чата, связанных с этой задачей
                // Этот запрос также работает независимо от статуса задачи (активная/архивная),
                // так как он ищет по task_id
                $stmt_get_chat_files = $conn->prepare("SELECT file_path FROM task_messages WHERE task_id = ? AND file_path IS NOT NULL AND file_path != ''");
                $stmt_get_chat_files->bind_param("i", $task_id);
                $stmt_get_chat_files->execute();
                $result_get_chat_files = $stmt_get_chat_files->get_result();

                while ($chat_file_data = $result_get_chat_files->fetch_assoc()) {
                    $chat_file_path_json = $chat_file_data['file_path'];
                    if ($chat_file_path_json) {
                        $decoded_paths = json_decode($chat_file_path_json, true);
                        if (is_array($decoded_paths)) {
                            $all_files_to_delete = array_merge($all_files_to_delete, $decoded_paths);
                        } else {
                            $all_files_to_delete[] = $chat_file_path_json;
                        }
                    }
                }
                $stmt_get_chat_files->close();

                // 3. Удаляем связанные сообщения чата
                $stmt_delete_chat = $conn->prepare("DELETE FROM task_messages WHERE task_id = ?");
                $stmt_delete_chat->bind_param("i", $task_id);
                $stmt_delete_chat->execute();
                $stmt_delete_chat->close();

                // 4. Удаляем саму задачу (активную или архивную)
                $stmt_delete_task = $conn->prepare("DELETE FROM tasks WHERE id = ?");
                $stmt_delete_task->bind_param("i", $task_id);

                if ($stmt_delete_task->execute()) {
                    // 5. Если задача успешно удалена, удаляем связанные файлы с диска
                    foreach (array_unique($all_files_to_delete) as $file_path) {
                        if (file_exists($file_path)) {
                            if (!unlink($file_path)) {
                                error_log("Ошибка при удалении файла: " . $file_path);
                            }
                        } else {
                            error_log("Файл не найден (уже удален или не существует) при удалении задачи " . $task_id . ": " . $file_path);
                        }
                    }
                    $_SESSION['message'] = "<p style='color: green;'>Задача, связанные чаты и файлы успешно удалены!</p>";
                    $conn->commit(); // Подтверждаем транзакцию
                } else {
                    $_SESSION['message'] = "<p style='color: red;'>Ошибка при удалении задачи из базы данных: " . $stmt_delete_task->error . "</p>";
                    $conn->rollback(); // Откатываем транзакцию при ошибке
                }
                $stmt_delete_task->close();
            } else {
                $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для удаления этой задачи.</p>";
                $conn->rollback(); // Откатываем транзакцию
            }
        } else {
            $_SESSION['message'] = "<p style='color: red;'>Задача не найдена.</p>";
            $conn->rollback(); // Откатываем транзакцию
        }
    } catch (Exception $e) {
        $_SESSION['message'] = "<p style='color: red;'>Произошла критическая ошибка при удалении задачи: " . $e->getMessage() . "</p>";
        $conn->rollback(); // Откатываем транзакцию при исключении
    }
} else {
    $_SESSION['message'] = "<p style='color: red;'>Неверный идентификатор задачи.</p>";
}

$conn->close();
header("Location: index.php");
exit();
?>