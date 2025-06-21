<?php
require_once 'db_connect.php'; // Подключаем файл с подключением к БД

$message = ''; // Переменная для сообщений пользователю

// Список предопределенных имен пользователей
$allowed_usernames = [
    'Подворье', 'Замок БИП', 'Певческая Башня', 'Uno Cafe', 'Эрмитажная кухня',
    'SV', 'Ялта', 'Уно Березка', 'Адмиралтейство', 'Pizza Uno Momento',
    'Oscar Catering', 'Пивная кружка Пушкин', 'Пивная кружка Колпино', 'Борщ',
    'Столовая', 'Парная баня', 'Кондитерский цех', 'Колхоз', 'Школа Горчакова'
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Получаем имя пользователя из выпадающего списка
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    // Простая валидация
    // Проверяем, что выбранное имя пользователя действительно из разрешенного списка
    if (empty($username) || !in_array($username, $allowed_usernames)) {
        $message = "<p style='color: red;'>Пожалуйста, выберите действительное имя пользователя из списка.</p>";
    } elseif (empty($password) || empty($confirm_password)) {
        $message = "<p style='color: red;'>Пожалуйста, заполните все поля пароля.</p>";
    } elseif ($password !== $confirm_password) {
        $message = "<p style='color: red;'>Пароли не совпадают.</p>";
    } elseif (strlen($password) < 6) {
        $message = "<p style='color: red;'>Пароль должен быть не менее 6 символов.</p>";
    } else {
        // Хешируем пароль
        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // Проверяем, существует ли пользователь с таким именем
        $stmt_check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $stmt_check->bind_param("s", $username);
        $stmt_check->execute();
        $stmt_check->store_result();

        if ($stmt_check->num_rows > 0) {
            $message = "<p style='color: red;'>Пользователь с именем '" . htmlspecialchars($username) . "' уже зарегистрирован. Пожалуйста, выберите другое имя.</p>";
        } else {
            // Вставляем нового пользователя
            // Добавляем is_confirmed = FALSE по умолчанию
            $stmt_insert = $conn->prepare("INSERT INTO users (username, password_hash, is_confirmed) VALUES (?, ?, FALSE)");
            $stmt_insert->bind_param("ss", $username, $password_hash);

            if ($stmt_insert->execute()) {
                $message = "<p style='color: green;'>Регистрация прошла успешно! Дождитесь подтверждения от администратора.</p>"; // Изменяем сообщение
            } else {
                $message = "<p style='color: red;'>Ошибка при регистрации: " . $stmt_insert->error . "</p>";
            }
            $stmt_insert->close();
        }
        $stmt_check->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Регистрация - Планер Задач</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f4f4f4;
            color: #333;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .container {
            max-width: 400px;
            background-color: #fff;
            padding: 20px 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }
        h1 {
            color: #0056b3;
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            text-align: left;
        }
        select,
        input[type="password"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-sizing: border-box;
        }
        button {
            background-color: #007bff;
            color: white;
            padding: 10px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            width: 100%;
        }
        button:hover {
            background-color: #0056b3;
        }
        .message {
            margin-top: 15px;
            font-size: 0.9em;
        }
        .login-link {
            margin-top: 20px;
            display: block;
        }
        .login-link a {
            color: #007bff;
            text-decoration: none;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Регистрация</h1>
        <?php echo $message; ?>
        <form action="register.php" method="post">
            <label for="username">Имя пользователя:</label>
            <select id="username" name="username" required>
                <option value="">-- Выберите имя --</option>
                <?php foreach ($allowed_usernames as $name): ?>
                    <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="password">Пароль:</label>
            <input type="password" id="password" name="password" required>

            <label for="confirm_password">Повторите пароль:</label>
            <input type="password" id="confirm_password" name="confirm_password" required>

            <button type="submit">Зарегистрироваться</button>
        </form>
        <span class="login-link">Уже есть аккаунт? <a href="login.php">Войти</a></span>
    </div>
</body>
</html>