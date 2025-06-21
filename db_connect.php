<?php

$servername = "localhost"; 
$username = "vh159080_planer";   
$password = "balalaika13$";         
$dbname = "vh159080_planer"; 

// Создаем подключение
$conn = new mysqli($servername, $username, $password, $dbname);

// Проверяем соединение
if ($conn->connect_error) {
    die("Ошибка подключения к базе данных: " . $conn->connect_error);
}

// Устанавливаем кодировку для корректного отображения русских символов
$conn->set_charset("utf8mb4");

?>