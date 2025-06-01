<?php
ob_start(); // Включаем буферизацию вывода
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php_errors.log');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Конфигурация базы данных
$db_config = [
    'host' => 'db4free.net',
    'user' => 'myusername',
    'pass' => 'EVu-Nec-y2k-rC3',
    'name' => 'travel_agency'
];

// Логирование для отладки
function debug_log($message) {
    @file_put_contents(__DIR__ . '/book_tour_debug.log', date('[Y-m-d H:i:s] ') . $message . "\n", FILE_APPEND);
}

$conn = null;

try {
    debug_log("Начало обработки запроса");
    debug_log("Session ID: " . session_id());
    debug_log("Session user_id: " . (isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set'));

    if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
        throw new Exception('Требуется авторизация');
    }

    $raw_input = file_get_contents('php://input');
    debug_log("Raw input: " . $raw_input);

    $input = json_decode($raw_input, true);
    if ($input === null && $raw_input !== '') {
        debug_log("JSON decode error: " . json_last_error_msg());
        throw new Exception('Некорректные JSON-данные');
    }

    if (empty($raw_input)) {
        throw new Exception('Пустой запрос');
    }

    $required_fields = ['travel_id', 'name', 'phone', 'email', 'persons'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || ($field !== 'persons' && empty(trim($input[$field])))) {
            throw new Exception("Поле $field обязательно");
        }
    }

    $travel_id = (int)$input['travel_id'];
    $name = trim($input['name']);
    $phone = trim($input['phone']);
    $email = trim($input['email']);
    $persons = (int)$input['persons'];
    $price = isset($input['price']) ? (float)$input['price'] : null;
    $package_id = isset($input['package_id']) && $input['package_id'] !== null ? (int)$input['package_id'] : null;
    $user_id = (int)$_SESSION['user_id'];
    $status = 'pending'; // Статус "в обработке"

    debug_log("Полученные данные: travel_id=$travel_id, name=$name, phone=$phone, email=$email, persons=$persons, price=" . ($price ?? 'null') . ", package_id=" . ($package_id ?? 'null') . ", user_id=$user_id, status=$status");

    if ($travel_id <= 0) {
        throw new Exception('Некорректный ID тура');
    }

    if (!preg_match('/^\+?\d{10,15}$/', $phone)) {
        throw new Exception('Некорректный номер телефона');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Некорректный email');
    }

    if ($persons < 1 || $persons > 10) {
        throw new Exception('Количество человек должно быть от 1 до 10');
    }

    $conn = new mysqli($db_config['host'], $db_config['user'], $db_config['pass'], $db_config['name']);
    if ($conn->connect_error) {
        debug_log("Ошибка подключения к БД: " . $conn->connect_error);
        throw new Exception('Ошибка подключения к базе данных');
    }

    $stmt = $conn->prepare("SELECT price, start_date, status FROM travels WHERE id = ?");
    if (!$stmt) {
        debug_log("Ошибка подготовки запроса (travels): " . $conn->error);
        throw new Exception('Ошибка подготовки запроса');
    }
    $stmt->bind_param('i', $travel_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('Тур не существует');
    }
    $tour = $result->fetch_assoc();
    $stmt->close();

    if ($price === null || $price <= 0) {
        $tour_price = isset($tour['price']) ? (float)$tour['price'] : 0;
        try {
            $start_date = !empty($tour['start_date']) ? new DateTime($tour['start_date']) : null;
        } catch (Exception $e) {
            debug_log("Ошибка формата start_date: " . $e->getMessage() . ", start_date=" . ($tour['start_date'] ?? 'null'));
            $start_date = null;
        }
        $today = new DateTime();
        $is_promo = false;

        if ($start_date && isset($tour['status']) && $tour['status'] === 'active') {
            $interval = $today->diff($start_date);
            $days_until_start = $interval->days;
            $is_promo = $days_until_start <= 7 && !$interval->invert;
        }

        $price = $is_promo ? round($tour_price * 0.9) : $tour_price;
        debug_log("Цена не передана или некорректна, рассчитана: price=$price, is_promo=" . ($is_promo ? 'true' : 'false'));
    } else {
        debug_log("Передана цена: price=$price");
    }

    if ($package_id !== null) {
        $stmt = $conn->prepare("SELECT id FROM packages WHERE id = ?");
        if (!$stmt) {
            debug_log("Ошибка подготовки запроса (packages): " . $conn->error);
            throw new Exception('Ошибка подготовки запроса');
        }
        $stmt->bind_param('i', $package_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            throw new Exception('Пакет не существует');
        }
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    if (!$stmt) {
        debug_log("Ошибка подготовки запроса (users): " . $conn->error);
        throw new Exception('Ошибка подготовки запроса');
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        debug_log("Пользователь не существует: user_id=$user_id");
        throw new Exception('Пользователь не существует');
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT id FROM tour_bookings WHERE user_id = ? AND travel_id = ?");
    if (!$stmt) {
        debug_log("Ошибка подготовки запроса (tour_bookings check): " . $conn->error);
        throw new Exception('Ошибка подготовки запроса');
    }
    $stmt->bind_param('ii', $user_id, $travel_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        debug_log("Бронирование уже существует: user_id=$user_id, travel_id=$travel_id");
        throw new Exception('Вы уже забронировали этот тур');
    }
    $stmt->close();

    $stmt = $conn->prepare("
        INSERT INTO tour_bookings (travel_id, name, phone, email, package_id, user_id, persons, price, status, created_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    if (!$stmt) {
        debug_log("Ошибка подготовки INSERT: " . $conn->error);
        throw new Exception('Ошибка подготовки запроса');
    }

    $stmt->bind_param('isssiidds', $travel_id, $name, $phone, $email, $package_id, $user_id, $persons, $price, $status);
    if (!$stmt->execute()) {
        debug_log("Ошибка выполнения INSERT: " . $stmt->error);
        throw new Exception('Ошибка выполнения запроса: ' . $stmt->error);
    }

    debug_log("Бронирование создано: travel_id=$travel_id, price=$price, persons=$persons, user_id=$user_id, status=$status");
    $response = [
        'success' => true,
        'message' => 'Бронирование успешно создано',
    ];

} catch (Exception $e) {
    $error_message = $e->getMessage();
    $error_code = 400;
    $error_type = 'validation_error';

    if (stripos($error_message, 'авторизация') !== false) {
        $error_type = 'auth_error';
        $error_code = 403;
    } elseif (stripos($error_message, 'внутренняя') !== false) {
        $error_type = 'server_error';
        $error_code = 500;
    } elseif (stripos($error_message, 'существует') !== false) {
        $error_type = 'not_found';
        $error_code = 404;
    }

    debug_log("Ошибка [$error_type]: $error_message");

    http_response_code($error_code);
    $response = [
        'success' => false,
        'message' => $error_message,
        'type' => $error_type,
        'code' => $error_code
    ];
} catch (Throwable $t) {
    debug_log("Критическая ошибка: " . $t->getMessage() . " в файле " . $t->getFile() . " на строке " . $t->getLine());
    http_response_code(500);
    $response = [
        'success' => false,
        'message' => 'Внутренняя ошибка сервера',
        'type' => 'fatal_error',
        'code' => 500
    ];
} finally {
    if ($conn !== null) {
        $conn->close();
    }
    debug_log("Конец обработки запроса");
    // Очищаем буфер и отправляем только JSON
    ob_end_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
?>