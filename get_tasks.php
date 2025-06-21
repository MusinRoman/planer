<?php
session_start();
require_once 'db_connect.php';

// Проверяем авторизацию и админские права
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    // В случае AJAX-запроса не делаем редирект, а отдаем пустой ответ или ошибку
    header("HTTP/1.1 401 Unauthorized");
    exit("Unauthorized access.");
}

$admin_department = $_SESSION['department'] ?? null;

// Если отдел администратора не установлен в сессии, получаем его из БД
if ($admin_department === null) {
    $stmt_department = $conn->prepare("SELECT department FROM users WHERE id = ?");
    $stmt_department->bind_param("i", $_SESSION['user_id']);
    $stmt_department->execute();
    $result_department = $stmt_department->get_result();
    if ($row_department = $result_department->fetch_assoc()) {
        $_SESSION['department'] = $row_department['department'];
        $admin_department = $row_department['department'];
    } else {
        // Если не удалось получить отдел, то что-то не так с сессией или БД
        header("HTTP/1.1 500 Internal Server Error");
        exit("Department information missing.");
    }
    $stmt_department->close();
}

$contentType = $_GET['content_type'] ?? '';

switch ($contentType) {
    case 'active_tasks':
        // Выбираем задачи только для отдела текущего администратора
        $sql = "SELECT t.*, u.username FROM tasks t JOIN users u ON t.user_id = u.id WHERE t.department = ? ORDER BY t.created_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $admin_department);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo "<div class='list-container tasks'>";
            echo "<div class='list-header'>";
            echo "<div class='list-cell display'>ID</div>";
            echo "<div class='list-cell description'>Описание задачи</div>";
            echo "<div class='list-cell date'>Срок от пользователя</div>";
            echo "<div class='list-cell date'>Срок от администратора</div>";
            echo "<div class='list-cell status'>Статус</div>";
            echo "<div class='list-cell restaurant display'>Отправитель</div>";
            echo "<div class='list-cell display'>Создана</div>";
            echo "<div class='list-cell actions'>Действия</div>";
            echo "</div>"; // Закрываем list-header

            while ($row = $result->fetch_assoc()) {
                echo "<div class='list-row table-list-row' data-task-id='" . htmlspecialchars($row['id']) . "'>";
                echo "<div class='list-cell display'><strong>ID:</strong> " . htmlspecialchars($row['id']) . "</div>";
                echo "<div class='list-cell description'><strong>Описание:</strong> " . htmlspecialchars($row['task_description']) . "</div>";
                echo "<div class='list-cell date'><strong>Срок пользователя:</strong> " . htmlspecialchars($row['desired_due_date']) . "</div>";
                echo "<div class='list-cell date'><strong>Срок администратора:</strong> " . htmlspecialchars($row['admin_due_date'] ?? 'Не установлен') . "</div>";
                echo "<div class='list-cell status status-" . str_replace(' ', '_', strtolower(htmlspecialchars($row['status']))) . "'><strong>Статус:</strong> " . htmlspecialchars($row['status']) . "</div>";
                echo "<div class='list-cell restaurant display'><strong>Отправитель:</strong> " . htmlspecialchars($row['username']) . "</div>";
                echo "<div class='list-cell display'><strong>Создана:</strong> " . htmlspecialchars($row['created_at']) . "</div>";
                echo "<div class='list-cell actions'>";
                echo "<form class='ajax-form' action='admin.php' method='post'>";
                echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<select name='new_status'>";
                echo "<option value='Задача принята'" . ($row['status'] == 'Задача принята' ? ' selected' : '') . ">Задача принята</option>";
                echo "<option value='Исполняется'" . ($row['status'] == 'Исполняется' ? ' selected' : '') . ">Исполняется</option>";
                echo "<option value='На удержании'" . ($row['status'] == 'На удержании' ? ' selected' : '') . ">На удержании</option>";
                echo "<option value='Выполнена'" . ($row['status'] == 'Выполнена' ? ' selected' : '') . ">Выполнена</option>";
                echo "</select>";
                echo "<input type='text' name='new_admin_due_date' class='datepicker' placeholder='Срок от админа' value='" . htmlspecialchars($row['admin_due_date'] ?? '') . "'>";
                echo "<button type='submit' name='update_task'>Обновить</button>";
                echo "</form>";
                echo "<form class='ajax-form' action='admin.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите архивировать эту задачу?');\">";
                echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' name='archive_task' class='archive-button'>В архив</button>";
                echo "</form>";
                echo "</div>"; // Закрываем list-cell actions
                echo "</div>"; // Закрываем list-row
            }
            echo "</div>"; // Закрываем list-container
        } else {
            echo "<p>Активных задач пока нет.</p>";
        }
        $stmt->close();
        break;

    case 'unconfirmed_users':
        // Выбираем неподтвержденных пользователей только из отдела текущего администратора
        $sql = "SELECT id, username, created_at FROM users WHERE is_confirmed = FALSE AND is_admin = FALSE AND department = ? ORDER BY created_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $admin_department);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo "<div class='list-container users'>";
            echo "<div class='list-header'>";
            echo "<div class='list-cell id'>ID</div>";
            echo "<div class='list-cell username'>Имя пользователя</div>";
            echo "<div class='list-cell created_at'>Дата регистрации</div>";
            echo "<div class='list-cell actions'>Действия</div>";
            echo "</div>"; // Закрываем list-header

            while ($row = $result->fetch_assoc()) {
                echo "<div class='list-row table-list-row' data-task-id='" . htmlspecialchars($row['id']) . "'>"; // Используем data-task-id для общности
                echo "<div class='list-cell id'><strong>ID:</strong> " . htmlspecialchars($row['id']) . "</div>";
                echo "<div class='list-cell username'><strong>Имя:</strong> " . htmlspecialchars($row['username']) . "</div>";
                echo "<div class='list-cell created_at'><strong>Зарегистрирован:</strong> " . htmlspecialchars($row['created_at']) . "</div>";
                echo "<div class='list-cell actions'>";
                echo "<form class='ajax-form' action='admin.php' method='post'>";
                echo "<input type='hidden' name='user_id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' name='confirm_user'>Подтвердить</button>";
                echo "</form>";
                echo "<form class='ajax-form' action='admin.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите удалить этого пользователя?');\">";
                echo "<input type='hidden' name='user_id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' name='delete_unconfirmed_user' class='archive-button'>Удалить</button>";
                echo "</form>";
                echo "</div>"; // Закрываем list-cell actions
                echo "</div>"; // Закрываем list-row
            }
            echo "</div>"; // Закрываем list-container
        } else {
            echo "<p>Новых регистраций нет.</p>";
        }
        $stmt->close();
        break;

    case 'archive_tasks':
        // Выбираем архивные задачи только для отдела текущего администратора
        $sql = "SELECT at.*, u.username FROM archive_tasks at JOIN users u ON at.user_id = u.id WHERE at.department = ? ORDER BY at.archived_at DESC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $admin_department);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            echo "<div class='list-container archive'>";
            echo "<div class='list-header'>";
            echo "<div class='list-cell id display'>ID</div>";
            echo "<div class='list-cell description'>Описание задачи</div>";
            echo "<div class='list-cell date'>Срок от пользователя</div>";
            echo "<div class='list-cell date'>Срок от администратора</div>";
            echo "<div class='list-cell status'>Статус</div>";
            echo "<div class='list-cell sender display'>Отправитель</div>";
            echo "<div class='list-cell created_at display'>Создана</div>";
            echo "<div class='list-cell archived_at display'>Архивирована</div>";
            echo "<div class='list-cell actions'>Действия</div>";
            echo "</div>"; // Закрываем list-header

            while ($row = $result->fetch_assoc()) {
                echo "<div class='list-row table-list-row' data-task-id='" . htmlspecialchars($row['id']) . "'>";
                echo "<div class='list-cell id display'><strong>ID:</strong> " . htmlspecialchars($row['id']) . "</div>";
                echo "<div class='list-cell description'><strong>Описание:</strong> " . htmlspecialchars($row['task_description']) . "</div>";
                echo "<div class='list-cell date'><strong>Срок пользователя:</strong> " . htmlspecialchars($row['desired_due_date']) . "</div>";
                echo "<div class='list-cell date'><strong>Срок администратора:</strong> " . htmlspecialchars($row['admin_due_date'] ?? 'Не установлен') . "</div>";
                echo "<div class='list-cell status status-" . str_replace(' ', '_', strtolower(htmlspecialchars($row['status']))) . "'><strong>Статус:</strong> " . htmlspecialchars($row['status']) . "</div>";
                echo "<div class='list-cell sender display'><strong>Отправитель:</strong> " . htmlspecialchars($row['username']) . "</div>";
                echo "<div class='list-cell created_at display'><strong>Создана:</strong> " . htmlspecialchars($row['created_at']) . "</div>";
                echo "<div class='list-cell archived_at display'><strong>Архивирована:</strong> " . htmlspecialchars($row['archived_at']) . "</div>";
                echo "<div class='list-cell actions'>";
                echo "<form class='ajax-form' action='admin.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите навсегда удалить эту задачу из архива?');\">";
                echo "<input type='hidden' name='archived_task_id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' name='delete_archived_task' class='delete-archived-button'>Удалить из архива</button>";
                echo "</form>";
                echo "</div>"; // Закрываем list-cell actions
                echo "</div>"; // Закрываем list-row
            }
            echo "</div>"; // Закрываем list-container
        } else {
            echo "<p>Архивных задач пока нет.</p>";
        }
        $stmt->close();
        break;

    default:
        // Если content_type не указан или некорректен
        header("HTTP/1.1 400 Bad Request");
        echo "Invalid content type specified.";
        break;
}

$conn->close();
?>