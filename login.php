<?php
// Начинаем сессию PHP. Это должно быть самой первой строкой в файле!
session_start();

require_once 'db_connect.php'; // Подключаем файл с подключением к БД

$message = ''; // Переменная для сообщений пользователю

// Список предопределенных имен пользователей (должен быть тем же, что и в register.php)
$allowed_usernames = [
    'Подворье', 'Замок БИП', 'Певческая Башня', 'Uno Cafe', 'Эрмитажная кухня',
    'SV', 'Ялта', 'Уно Березка', 'Адмиралтейство', 'Pizza Uno Momento',
    'Oscar Catering', 'Пивная кружка Пушкин', 'Пивная кружка Колпино', 'Борщ',
    'Столовая', 'Парная баня', 'Кондитерский цех', 'Колхоз', 'Школа Горчакова'
];

// Если пользователь уже авторизован, перенаправляем его
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['is_admin']) {
        header("Location: admin.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Получаем имя пользователя из выпадающего списка
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Простая валидация
    // Добавлена проверка на то, что выбранное имя пользователя действительно из разрешенного списка
    if (empty($username) || !in_array($username, $allowed_usernames)) {
        $message = "<p style='color: red;'>Пожалуйста, выберите действительное имя пользователя из списка.</p>";
    } elseif (empty($password)) {
        $message = "<p style='color: red;'>Пожалуйста, введите пароль.</p>";
    } else {
        // Подготавливаем запрос для получения данных пользователя
        $stmt = $conn->prepare("SELECT id, username, password_hash, is_admin, is_confirmed FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            // Проверяем введенный пароль с хешированным паролем из БД
            if (password_verify($password, $user['password_hash'])) {
                // Проверяем, подтвержден ли пользователь
                if (!$user['is_confirmed']) {
                    $message = "<p style='color: red;'>Ваша учетная запись ожидает подтверждения администратором.</p>";
                } else {
                    // Пароль верный и пользователь подтвержден, создаем сессию
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = (bool)$user['is_admin'];

                    // Перенаправляем пользователя в зависимости от его роли
                    if ($_SESSION['is_admin']) {
                        header("Location: admin.php");
                    } else {
                        header("Location: index.php");
                    }
                    exit(); // Важно завершить выполнение скрипта после редиректа
                }
            } else {
                $message = "<p style='color: red;'>Неправильный пароль для ресторана</p>";
            }
        } else {
            // Если пользователя с таким именем нет
            $message = "<p style='color: red;'>Ресторан еще не зарегистророван в системе.</p>";
        }
        $stmt->close();
    }
}
$conn->close();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход - Планер Задач</title>
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
        /* Стилизуем и select, и input[type="password"] */
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
        .register-link {
            margin-top: 20px;
            display: block;
        }
        .register-link a {
            color: #007bff;
            text-decoration: none;
        }
        .register-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Вход</h1>
        <?php echo $message; ?>
        <form action="login.php" method="post">
            <label for="username">Ресторан:</label>
            <select id="username" name="username" required>
                <option value="">-- Выбрать ресторан --</option>
                <?php foreach ($allowed_usernames as $name): ?>
                    <option value="<?php echo htmlspecialchars($name); ?>"><?php echo htmlspecialchars($name); ?></option>
                <?php endforeach; ?>
            </select>

            <label for="password">Пароль:</label>
            <input type="password" id="password" name="password" required>

            <button type="submit">Войти</button>
        </form>
        <span class="register-link">Нет аккаунта? <a href="register.php">Зарегистрироваться</a></span>
    </div>
</body>
</html>