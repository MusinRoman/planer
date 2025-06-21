<?php
session_start();
require_once 'db_connect.php';

// Проверка аутентификации
if (!isset($_SESSION['user_id'])) {
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

$archive_where_clauses = ["t.user_id = ?", "t.is_archived = 1"];
$archive_bind_params = [$current_user_id];
$archive_bind_types = 'i';

if (isset($_GET['filter_archive_department']) && !empty($_GET['filter_archive_department'])) {
    $archive_where_clauses[] = "t.department = ?";
    $archive_bind_params[] = $_GET['filter_archive_department'];
    $archive_bind_types .= 's';
}
$sql_archive = "SELECT t.id, t.task_description, t.desired_due_date, t.admin_due_date, t.status, t.created_at, u.username, t.user_id, t.department, t.file_paths FROM tasks t LEFT JOIN users u ON t.user_id = u.id WHERE " . implode(" AND ", $archive_where_clauses) . " ORDER BY t.created_at DESC";

$stmt_archive_select = $conn->prepare($sql_archive);
$stmt_archive_select->bind_param($archive_bind_types, ...$archive_bind_params);
$stmt_archive_select->execute();
$result_archive = $stmt_archive_select->get_result();

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
?>

<div class="filter-section">
    <form id="filter-form" action="" method="get">
        <label for="filter_archive_department">Показать задачи для отдела:</label>
        <select id="filter_archive_department" name="filter_archive_department" class="filter-select">
            <option value="">Все отделы</option>
            <option value="IT" <?php echo (isset($_GET['filter_archive_department']) && $_GET['filter_archive_department'] == 'IT') ? 'selected' : ''; ?>>IT</option>
            <option value="Офис-менеджер" <?php echo (isset($_GET['filter_archive_department']) && $_GET['filter_archive_department'] == 'Офис-менеджер') ? 'selected' : ''; ?>>Офис-менеджер</option>
            <option value="Маркетинговый отдел" <?php echo (isset($_GET['filter_archive_department']) && $_GET['filter_archive_department'] == 'Маркетинговый отдел') ? 'selected' : ''; ?>>Маркетинговый отдел</option>
        </select>
        <div class="filter-buttons">
            <button type="submit" name="clear_archive_department_filter" value="1" class="filter-button secondary">Сбросить фильтр отдела</button>
        </div>
    </form>
</div>
<div class="tasks-list">
    <?php
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
            ?>
            <button type='button' class='chat-button' onclick="window.location.href='chat.php?task_id=<?php echo $task_id_archive; ?>'">
                Переписка
                <?php if ($has_new_messages_archive): ?>
                    <span class="new-message-indicator"></span>
                <?php endif; ?>
            </button>
            <?php
            echo "<form action='index.php' method='post'>";
            echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
            echo "<button type='submit' name='restore_my_archived_task' class='restore-button-user'>Восстановить</button>";
            echo "</form>";

            echo "<form action='index.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите безвозвратно удалить эту задачу из архива?');\">";
            echo "<input type='hidden' name='task_id' value='" . htmlspecialchars($row['id']) . "'>";
            echo "<button type='submit' name='delete_my_archived_task' class='delete-archived-button-user'>Удалить</button>";
            echo "</form>";
            echo "</div> </div>"; // Закрываем div.task-actions и div.table_zadacha
        }
    } else {
        echo "<div class='task-row'><div class='task-cell' style='width: 100%;'>В вашем архиве пока нет задач по выбранным фильтрам.</div></div>";
    }
    $stmt_archive_select->close();
    ?>
</div>
<?php
// Кнопка очистки всего архива пользователя
echo "<hr>";
echo "<form action='index.php' method='post' onsubmit=\"return confirm('Вы уверены, что хотите полностью очистить свой личный архив? Это действие необратимо!');\">";
echo "<button type='submit' name='clear_my_archive' class='clear-archive-button-user'>Очистить весь мой архив</button>";
echo "</form>";
?>

<?php $conn->close(); ?>