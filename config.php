<?php
$host = "db4free.net";
$user = "myusername"; // Стандартный пользователь MySQL в XAMPP
$pass = "EVu-Nec-y2k-rC3"; // Пароль
$db = "travel_agency";

$conn = new mysqli($host, $user, $pass, $db);

// Проверка подключения
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}


?>