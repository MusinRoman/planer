<?php
session_start();
require_once 'db_connect.php';

// Проверка аутентификации
if (!isset($_SESSION['user_id'])) {
    // В случае AJAX запроса, не делаем header("Location: ...")
    // А возвращаем сообщение об ошибке или пустой ответ
    echo "<p style='color: red;'>Ошибка: Сессия не активна. Пожалуйста, войдите снова.</p>";
    exit();
}

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

$status_mapping = [
    'На удержании' => 'on_hold',
    'Задача принята' => 'accepted',
    'Исполняется' => 'in_progress',
    'Выполнена' => 'completed'
];

$where_clauses = ["t.is_archived = 0"];
$bind_params = [];
$bind_types = '';

$select_fields = "t.id, t.task_description, t.desired_due_date, t.admin_due_date, t.status, t.created_at, u.username, t.user_id, t.department, t.file_paths";
$order_by = "ORDER BY t.created_at DESC";

if (isset($_GET['filter_department']) && !empty($_GET['filter_department'])) {
    $where_clauses[] = "t.department = ?";
    $bind_params[] = $_GET['filter_department'];
    $bind_types .= 's';
}

if (isset($_GET['filter']) && $_GET['filter'] == 'my_tasks') {
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
?>

<div class="filter-section">
    <form id="filter-form" action="" method="get">
        <label for="filter_department">Показать задачи для отдела:</label>
        <select id="filter_department" name="filter_department" class="filter-select">
            <option value="">Все отделы</option>
            <option value="IT" <?php echo (isset($_GET['filter_department']) && $_GET['filter_department'] == 'IT') ? 'selected' : ''; ?>>IT</option>
            <option value="Офис-менеджер" <?php echo (isset($_GET['filter_department']) && $_GET['filter_department'] == 'Офис-менеджер') ? 'selected' : ''; ?>>Офис-менеджер</option>
            <option value="Маркетинговый отдел" <?php echo (isset($_GET['filter_department']) && $_GET['filter_department'] == 'Маркетинговый отдел') ? 'selected' : ''; ?>>Маркетинговый отдел</option>
        </select>
        <div class="filter-buttons">
            <?php
            $is_all_tasks_active = (!isset($_GET['filter']) || $_GET['filter'] == 'all_tasks');
            $is_my_tasks_active = (isset($_GET['filter']) && $_GET['filter'] == 'my_tasks');
            ?>
            <button type="submit" name="filter" value="all_tasks" class="filter-button <?php echo $is_all_tasks_active ? 'active-filter-button' : ''; ?>">Все задачи</button>
            <button type="submit" name="filter" value="my_tasks" class="filter-button secondary <?php echo $is_my_tasks_active ? 'active-filter-button' : ''; ?>">Мои задачи</button>
            <button type="submit" name="clear_filters" value="1" class="filter-button secondary">Сбросить фильтры</button>
        </div>
    </form>
</div>

<div class="tasks-list">
    <?php
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
            } else {
                echo "<div class='task-cell description'><strong>Описание задачи:</strong><br><span style='color: #888;'>Приватная задача</span></div>";
            }

            echo "<div class='task-actions'>";
            if ($row['user_id'] == $current_user_id || $current_user_is_admin) {
            echo "<form>"; // Убрал position: relative, т.к. стиль уже в .chat-button
            $task_id = htmlspecialchars($row['id']);
            $has_new_messages = isset($unread_messages_count[$task_id]) && $unread_messages_count[$task_id] > 0;
            ?>
            <button type='button' class='chat-button' onclick="window.location.href='chat.php?task_id=<?php echo $task_id; ?>'">
                Переписка
                <?php if ($has_new_messages): ?>
                    <span class="new-message-indicator"></span>
                <?php endif; ?>
            </button>
            <?php
            echo "</form>";
            }
            if ($row['user_id'] == $current_user_id) {
                echo "<form action='edit_task.php' method='get'>"; // Отправляем GET-запрос на edit_task.php
                echo "<input type='hidden' name='id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' class='edit-button'>Изменить</button>";
                echo "</form>";
                echo "<form action='index.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите переместить эту задачу в архив?');\">";
                echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
                echo "<button type='submit' name='archive_my_task' class='archive-button-user'>В архив</small></button>";
                echo "</form>";
                echo "<form action='delete_task.php' method='get' onsubmit=\"return confirm('Вы уверены, что хотите удалить эту задачу? (Это действие необратимо, если по задаче есть переписка, она также будет удалена!)');\">";
                echo "<input type='hidden' name='id' value='" . htmlspecialchars($row['id']) . "'>"; // Убеждаемся, что id передается корректно
                echo "<button type='submit' class='delete-button'>Удалить</button>";
                echo "</form>";
            }
            echo "</div> </div>"; // Закрываем div.task-actions и div.table_zadacha
        }
    } else {
        echo "<div class='task-row'><div class='task-cell' style='width: 100%;'>Задач по выбранным фильтрам пока нет.</div></div> ";
    }
    $stmt_select->close();
    ?>
</div>

<?php $conn->close(); ?>