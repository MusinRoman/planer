<?php
session_start();
require_once 'db_connect.php'; // Убедись, что путь к файлу корректен

// Если пользователь не авторизован, перенаправляем на страницу входа
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$current_user_id = $_SESSION['user_id'];

if (isset($_GET['id'])) {
    $task_id_to_delete = $_GET['id'];

    // Шаг 1: Получаем пути к файлам и user_id из архивной задачи
    $stmt_get_files = $conn->prepare("SELECT file_path, user_id FROM archive_tasks WHERE id = ?");
    $stmt_get_files->bind_param("i", $task_id_to_delete);
    $stmt_get_files->execute();
    $result_files = $stmt_get_files->get_result();
    $task_data = $result_files->fetch_assoc();
    $stmt_get_files->close();

    if ($task_data) {
        // Проверяем, что задача принадлежит текущему пользователю
        if ($task_data['user_id'] == $current_user_id) {
            $file_paths_json = $task_data['file_path'];

            // Шаг 2: Удаляем файлы из файловой системы
            if (!empty($file_paths_json)) {
                $file_paths = json_decode($file_paths_json, true);
                if (is_array($file_paths)) {
                    foreach ($file_paths as $path) {
                        // Убедитесь, что путь не содержит ".." для предотвращения Directory Traversal атак
                        if (strpos($path, '..') === false && file_exists($path)) {
                            if (unlink($path)) {
                                error_log("Файл успешно удален: " . $path);
                            } else {
                                error_log("Не удалось удалить файл: " . $path);
                            }
                        } else {
                            error_log("Попытка удалить некорректный путь к файлу или файл не существует: " . $path);
                        }
                    }
                }
            }

            // Шаг 3: Удаляем запись из базы данных
            $stmt_delete_archive_task = $conn->prepare("DELETE FROM archive_tasks WHERE id = ?");
            $stmt_delete_archive_task->bind_param("i", $task_id_to_delete);

            if ($stmt_delete_archive_task->execute()) {
                $_SESSION['message'] = "<p style='color: green;'>Задача и связанные файлы успешно удалены из архива!</p>";
            } else {
                $_SESSION['message'] = "<p style='color: red;'>Ошибка при удалении задачи из архива: " . $stmt_delete_archive_task->error . "</p>";
            }
            $stmt_delete_archive_task->close();
        } else {
            $_SESSION['message'] = "<p style='color: red;'>У вас нет прав для окончательного удаления этой задачи.</p>";
        }
    } else {
        $_SESSION['message'] = "<p style='color: red;'>Архивная задача для удаления не найдена.</p>";
    }
} else {
    $_SESSION['message'] = "<p style='color: red;'>ID задачи не указан.</p>";
}

$conn->close();
header("Location: index.php?filter_archive=true"); // Перенаправляем обратно в архивный список
exit();
?>