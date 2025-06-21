<?php
session_start();
require_once 'db_connect.php'; // Убедитесь, что этот файл содержит подключение к вашей базе данных

// Перенаправляем пользователя на страницу входа, если он не авторизован
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$message = ''; // Переменная для сообщений пользователю
$task = null; // Переменная для хранения данных задачи
$task_id = $_GET['id'] ?? null; // Получаем ID задачи из URL

// Проверяем, передан ли ID задачи и является ли он числом
// Если ID некорректный, перенаправляем на главную страницу
if (!$task_id || !is_numeric($task_id)) {
    header("Location: index.php");
    exit();
}

// Получаем данные задачи из базы данных, включая поле 'department'
$stmt = $conn->prepare("SELECT id, task_description, desired_due_date, user_id, department FROM tasks WHERE id = ?");
$stmt->bind_param("i", $task_id); // 'i' означает, что $task_id является целым числом
$stmt->execute();
$result = $stmt->get_result();

// Если задача найдена и принадлежит текущему пользователю
if ($result->num_rows === 1) {
    $task = $result->fetch_assoc(); // Извлекаем данные задачи как ассоциативный массив
    // Проверяем, что задача принадлежит текущему пользователю
    if ($task['user_id'] != $_SESSION['user_id']) {
        header("Location: index.php"); // Если не принадлежит, перенаправляем обратно
        exit();
    }
} else {
    // Если задача не найдена, перенаправляем обратно
    header("Location: index.php");
    exit();
}
$stmt->close(); // Закрываем подготовленное выражение

// Обработка отправки формы редактирования
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_description = $_POST['task_description']; // Новое описание задачи
    $new_desired_due_date = $_POST['desired_due_date']; // Новый желаемый срок выполнения
    $new_department = $_POST['department'] ?? 'Общий'; // Новый отдел, по умолчанию 'Общий'

    // Проверяем, что описание задачи не пустое
    if (!empty($new_description)) {
        // Подготавливаем запрос на обновление задачи, включая 'department'
        $update_stmt = $conn->prepare("UPDATE tasks SET task_description = ?, desired_due_date = ?, department = ? WHERE id = ? AND user_id = ?");
        // 'sssii' означает: три строки (string), два целых числа (integer)
        $update_stmt->bind_param("sssii", $new_description, $new_desired_due_date, $new_department, $task_id, $_SESSION['user_id']);

        // Выполняем запрос на обновление
        if ($update_stmt->execute()) {
            $message = "<p style='color: green;'>Задача успешно обновлена!</p>";
            // Обновляем локальные данные задачи для отображения на форме после успешного сохранения
            $task['task_description'] = $new_description;
            $task['desired_due_date'] = $new_desired_due_date;
            $task['department'] = $new_department;
        } else {
            $message = "<p style='color: red;'>Ошибка при обновлении задачи: " . $update_stmt->error . "</p>";
        }
        $update_stmt->close(); // Закрываем подготовленное выражение
    } else {
        $message = "<p style='color: red;'>Пожалуйста, введите описание задачи.</p>";
    }
}

// Список доступных отделов для выпадающего списка
// Измененный массив, содержащий только три отдела
$departments = [
    'IT',
    'Маркетинговый отдел',
    'Офис-менеджер'
];
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать Задачу</title>
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
            max-width: 800px;
            margin: 20px auto;
            background-color: #fff;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        h1 {
            color: #0056b3;
            margin-bottom: 20px;
        }
        form label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
        }
        /* Стили для текстовых полей, полей даты и выпадающих списков */
        form textarea,
        form input[type="date"],
        form select {
            font-family: "Montserrat", sans-serif;
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        form button[type="submit"] {
            font-family: "Montserrat", sans-serif;
            background-color: #007bff;
            color: white;
            padding: 14px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            margin-right: 10px;
        }
        form button[type="submit"]:hover {
            background-color: #0056b3;
        }
        .back-button {
            display: inline-block;
            background-color: #6c757d;
            color: white;
            padding: 10px 15px;
            border-radius: 4px;
            text-decoration: none;
            font-size: 16px;
            margin-top: 15px;
        }
        .back-button:hover {
            background-color: #5a6268;
        }
        p.message {
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Редактировать Задачу</h1>
        <?php echo $message; // Выводим сообщения об успехе или ошибке ?>

        <?php if ($task): // Если данные задачи успешно получены ?>
            <form action="edit_task.php?id=<?php echo htmlspecialchars($task_id); ?>" method="post">
                <label for="task_description">Описание задачи:</label>
                <textarea id="task_description" name="task_description" rows="5" required><?php echo htmlspecialchars($task['task_description']); ?></textarea>

                <label for="desired_due_date">Желаемый срок выполнения (необязательно):</label>
                <input type="date" id="desired_due_date" name="desired_due_date" value="<?php echo htmlspecialchars($task['desired_due_date']); ?>">

                <label for="department">Отдел:</label>
                <select id="department" name="department">
                    <?php foreach ($departments as $department_option): // Перебираем массив отделов для создания опций ?>
                        <option value="<?php echo htmlspecialchars($department_option); ?>"
                            <?php echo ($task['department'] == $department_option) ? 'selected' : ''; // Выбираем текущий отдел задачи ?>>
                            <?php echo htmlspecialchars($department_option); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">Сохранить изменения</button>
                <a href="index.php" class="back-button">Отмена / Назад</a>
            
            </form>
        <?php else: // Если задача не найдена или нет прав ?>
            <p>Задача не найдена или у вас нет прав для ее редактирования.</p>
            <a href="index.php" class="back-button">Вернуться к списку задач</a>
        <?php endif; ?>
    </div>
</body>
</html>
