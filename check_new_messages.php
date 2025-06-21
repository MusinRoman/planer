<?php
session_start(); // Начинаем сессию
require_once 'db_connect.php'; // Подключаем файл с подключением к БД

header('Content-Type: application/json'); // Указываем, что ответ будет в формате JSON

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Пользователь не авторизован.']);
    exit();
}

$current_user_id = $_SESSION['user_id'];
$current_user_is_admin = false;

// Проверяем, является ли пользователь админом (дублируем логику из index.php для независимости)
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

$unread_messages_data = [];

// Логика получения количества непрочитанных сообщений, аналогичная index.php
if (!$current_user_is_admin) {
    // Для обычных пользователей: сообщения от админов
    $stmt_unread = $conn->prepare("
        SELECT tm.task_id, COUNT(tm.id) AS unread_count
        FROM task_messages tm
        JOIN users sender ON tm.sender_id = sender.id
        JOIN tasks t ON tm.task_id = t.id
        WHERE tm.is_read = 0
        AND sender.is_admin = 1
        AND t.user_id = ?
        GROUP BY tm.task_id
    ");
    $stmt_unread->bind_param("i", $current_user_id);
} else {
    // Для админов: сообщения от обычных пользователей
    $stmt_unread = $conn->prepare("
        SELECT tm.task_id, COUNT(tm.id) AS unread_count
        FROM task_messages tm
        JOIN users sender ON tm.sender_id = sender.id
        JOIN tasks t ON tm.task_id = t.id
        WHERE tm.is_read = 0
        AND sender.is_admin = 0
        GROUP BY tm.task_id
    ");
}

$stmt_unread->execute();
$result_unread = $stmt_unread->get_result();
while ($unread_row = $result_unread->fetch_assoc()) {
    $unread_messages_data[(string)$unread_row['task_id']] = $unread_row['unread_count'];
}
$stmt_unread->close();
$conn->close();

echo json_encode($unread_messages_data); // Отправляем данные в формате JSON
?>