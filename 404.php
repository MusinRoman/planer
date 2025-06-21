<?php
// Устанавливаем заголовок HTTP-ответа, чтобы браузер знал, что это ошибка 404
header("HTTP/1.0 404 Not Found");
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Страница не найдена - Ошибка 404</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat+Alternates&family=Montserrat:wght@500;600;700&display=swap');
        body {
            font-family: "Montserrat", sans-serif;
            margin: 0;
            background-color: #f4f4f4;
            color: #333;
            line-height: 1.6;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            text-align: center;
            padding: 20px;
            box-sizing: border-box;
        }
        .error-container {
            max-width: 600px;
            background-color: #fff;
            padding: 40px;
            border-radius: 8px;
            box-shadow: 0 0 15px rgba(0, 0, 0, 0.1);
        }
        h1 {
            font-size: 5em;
            color: #dc3545; /* Красный цвет для ошибки */
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.1);
        }
        h2 {
            font-size: 1.8em;
            color: #0056b3;
            margin-bottom: 20px;
        }
        p {
            font-size: 1.1em;
            margin-bottom: 25px;
        }
        .home-button {
            display: inline-block;
            background-color: #007bff;
            color: white;
            padding: 12px 25px;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            font-size: 1.1em;
            transition: background-color 0.3s ease;
        }
        .home-button:hover {
            background-color: #0056b3;
        }
        @media (max-width: 600px) {
            h1 {
                font-size: 3.5em;
            }
            h2 {
                font-size: 1.4em;
            }
            .error-container {
                padding: 25px;
            }
            .home-button {
                padding: 10px 20px;
                font-size: 1em;
            }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <h1>404</h1>
        <h2>Упс! Кажется, эта страница заблудилась.</h2>
        <p>Мы не можем найти страницу, которую вы ищете. Возможно, она была перемещена или удалена.</p>
        <p>Пожалуйста, проверьте URL или вернитесь на главную страницу.</p>
        <a href="/" class="home-button">Вернуться на главную</a>
    </div>
</body>
</html>