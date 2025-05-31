<?php
$host = "localhost";
$user = "root"; // Стандартный пользователь MySQL в XAMPP
$pass = ""; // Пароль пустой по умолчанию
$db = "travel_agency";

$conn = new mysqli($host, $user, $pass, $db);

// Проверка подключения
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}
?>
