<?php

session_start();

if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Проверка отмены бронирования должна быть до вывода
if (isset($_GET['cancel']) && isset($_GET['booking_id'])) {
    $user_id = (int)$_SESSION['user_id'];
    $booking_id = (int)$_GET['booking_id'];
    require_once('config.php'); // Подключаем конфигурацию для $conn
    $stmt = $conn->prepare("DELETE FROM tour_bookings WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $booking_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header('Location: profile.php');
    exit;
}

require_once('navbar.php'); // Подключаем после проверки отмены

$user_id = (int)$_SESSION['user_id'];

// Handle settings form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    $new_username = trim($_POST['username']);
    $new_email = trim($_POST['email']);
    $new_password = trim($_POST['password']);
    
    try {
        if (empty($new_username) || empty($new_email)) {
            throw new Exception("Имя пользователя и email обязательны.");
        }
        
        $update_query = "UPDATE users SET username = ?, email = ?";
        $params = [$new_username, $new_email];
        $types = 'ss';
        
        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_query .= ", password = ?";
            $params[] = $hashed_password;
            $types .= 's';
        }
        
        $update_query .= " WHERE id = ?";
        $params[] = $user_id;
        $types .= 'i';
        
        $stmt = $conn->prepare($update_query);
        if ($stmt === false) {
            throw new Exception("Ошибка подготовки запроса: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $stmt->close();
        
        $_SESSION['username'] = $new_username;
        $user['username'] = $new_username;
        $user['email'] = $new_email;
        
        $success_message = "Настройки успешно обновлены!";
    } catch (Exception $e) {
        $error_message = "Ошибка при обновлении настроек: " . $e->getMessage();
    }
}

if (isset($_GET['cancel']) && isset($_GET['booking_id'])) {
    $booking_id = (int)$_GET['booking_id'];
    $stmt = $conn->prepare("DELETE FROM tour_bookings WHERE id = ? AND user_id = ?");
    $stmt->bind_param('ii', $booking_id, $user_id);
    $stmt->execute();
    $stmt->close();
    header('Location: profile.php');
    exit;
}

$status_filter = $_GET['status'] ?? '';
$sort_by = $_GET['sort'] ?? 'created_at';
$sort_order = ($_GET['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';

$valid_sort_columns = ['created_at', 'start_date', 'price'];
$sort_by = in_array($sort_by, $valid_sort_columns) ? $sort_by : 'created_at';

try {
    $query = "
    SELECT tb.*, tb.status AS booking_status, t.title, t.destination, t.start_date, t.end_date, t.status AS tour_status, t.image AS image_url, 
           p.name AS package_name, p.price AS package_price
    FROM tour_bookings tb
    LEFT JOIN travels t ON tb.travel_id = t.id
    LEFT JOIN packages p ON tb.package_id = p.id
    WHERE tb.user_id = ?
";
if ($status_filter) {
    $query .= " AND t.status = ?";
}
$query .= " ORDER BY " . ($sort_by === 'created_at' ? "tb.$sort_by" : ($sort_by === 'price' ? "COALESCE(p.price * tb.persons, tb.price * tb.persons)" : "t.$sort_by")) . " $sort_order";

    error_log("SQL Query: $query, user_id: $user_id, status_filter: $status_filter");

    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        throw new Exception("Ошибка подготовки запроса: " . $conn->error);
    }

    if ($status_filter) {
        $stmt->bind_param('is', $user_id, $status_filter);
    } else {
        $stmt->bind_param('i', $user_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $bookings_raw = $result->fetch_all(MYSQLI_ASSOC);

    error_log("Bookings raw count: " . count($bookings_raw));
    error_log("Bookings raw: " . print_r($bookings_raw, true));

    $stmt->close();

   $bookings = [];
foreach ($bookings_raw as $booking) {
    $travel_id = $booking['travel_id'];
    if ($travel_id && !isset($bookings[$travel_id])) {
        $bookings[$travel_id] = [
            'title' => $booking['title'] ?? 'Без названия',
            'destination' => $booking['destination'] ?? 'Не указано',
            'start_date' => $booking['start_date'] ?? date('Y-m-d'),
            'end_date' => $booking['end_date'] ?? date('Y-m-d'),
            'tour_status' => $booking['tour_status'] ?? 'inactive', // Статус тура
            'image_url' => $booking['image_url'] ?? "https://via.placeholder.com/100",
            'reservations' => [],
            'total_tour_price' => 0
        ];
    }
    if ($travel_id) {
        $package_id = $booking['package_id'] ?? null;
        $package_price = floatval($booking['package_price'] ?? 0);
        $persons = intval($booking['persons'] ?? 1);
        $tb_price = floatval($booking['price'] ?? 0);

        if ($package_id && $package_price > 0) {
            $total_price = $package_price * $persons;
            $price_source = "package.price * persons";
        } else {
            $total_price = $tb_price * $persons;
            $price_source = "tb.price * persons";
        }
        if ($total_price < 0) {
            $total_price = 0;
            error_log("Warning: Negative total_price for booking ID {$booking['id']}");
        }
        if ($persons <= 0) {
            error_log("Warning: Invalid persons count ($persons) for booking ID {$booking['id']}");
            $persons = 1;
        }

        error_log("Booking ID {$booking['id']}: package_id=$package_id, package_price=$package_price, persons=$persons, tb_price=$tb_price, total_price=$total_price, source=$price_source");

        $booking['total_price'] = $total_price;
        $booking['booking_status'] = $booking['booking_status'] ?? 'pending'; // Статус бронирования
        $bookings[$travel_id]['reservations'][] = $booking;
        $bookings[$travel_id]['total_tour_price'] += $total_price;
    }
}

    error_log("Bookings final count: " . count($bookings));
} catch (Exception $e) {
    error_log("Error in query: " . $e->getMessage());
    $bookings = [];
}
// Получаем роль пользователя
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT role FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user_role = $stmt->get_result()->fetch_assoc()['role'] ?? 'user'; // По умолчанию 'user', если роли нет
$_SESSION['role'] = $user_role; // Сохраняем роль в сессии
$stmt->close();

$stmt = $conn->prepare("SELECT MIN(created_at) AS registration_date FROM tour_bookings WHERE user_id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$result = $stmt->get_result();
$registration = $result->fetch_assoc();
$registration_date = $registration['registration_date'] ? $registration['registration_date'] : date('Y-m-d');
$stmt->close();

$stmt = $conn->prepare("SELECT username, email FROM users WHERE id = ?");
$stmt->bind_param('i', $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc() ?: ['username' => 'Не указано', 'email' => 'Не указано'];
$user['created_at'] = $registration_date;
$_SESSION['registration_date'] = $registration_date;
$stmt->close();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мой профиль | iTravel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        /* Ваш CSS код остается без изменений */
        :root {
            --primary:rgb(0, 102, 210);
            --secondary: rgb(0, 221, 255);
            --white: #FFFFFF;
            --light-bg: #F7F9FC;
            --dark-text: #2D3748;
            --gray: #A0AEC0;
            --shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            --gradient: linear-gradient(135deg,rgb(97, 255, 247), #4A90E2);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: url('https://images.unsplash.com/photo-1507525428034-b723cf961d3e?ixlib=rb-4.0.3&auto=format&fit=crop&w=1950&q=80') no-repeat center center fixed;
            background-size: cover;
            color: var(--dark-text);
            line-height: 1.6;
            overflow-x: hidden;
        }

        .container {
            display: flex;
            max-width: 1300px;
            margin: 0 auto;
            padding: 30px;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: var(--white);
            padding: 30px;
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-right: 30px;
            animation: slideIn 0.5s ease-out;
        }

        @keyframes slideIn {
            from { transform: translateX(-100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .profile-avatar {
            text-align: center;
            margin-bottom: 30px;
        }

        .avatar-img {
            width: 120px;
            height: 120px;
            border-radius: 50%;
            border: 4px solid var(--primary);
            margin-bottom: 15px;
            transition: transform 0.3s ease;
        }

        .avatar-img:hover {
            transform: scale(1.1);
        }

        .profile-avatar h1 {
            font-size: 24px;
            font-weight: 600;
            color: var(--dark-text);
            margin-bottom: 5px;
        }

        .profile-avatar p {
            font-size: 14px;
            color: var(--gray);
        }

        .stats {
            display: flex;
            justify-content: space-around;
            margin-top: 15px;
            gap: 10px;
        }

        .stat-item {
            background: var(--light-bg);
            padding: 12px;
            border-radius: 10px;
            color: var(--secondary);
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s ease;
        }

        .stat-item:hover {
            background: var(--secondary);
            color: var(--white);
        }

        .stat-item span {
            font-weight: bold;
            margin-right: 5px;
        }

        .menu a {
            display: flex;
            align-items: center;
            padding: 15px;
            color: var(--dark-text);
            text-decoration: none;
            margin-bottom: 10px;
            border-radius: 10px;
            transition: background 0.3s ease, color 0.3s ease;
            cursor: pointer;
        }

        .menu a i {
            margin-right: 15px;
            font-size: 18px;
        }

        .menu a:hover, .menu a.active {
            background: var(--primary);
            color: var(--white);
        }

        .main-content {
            flex: 1;
            background: rgba(255, 255, 255, 0.95);
            padding: 30px;
            border-radius: 20px;
            box-shadow: var(--shadow);
            animation: fadeIn 0.7s ease-out;
            display: none;
        }

        .main-content.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .profile-header {
            text-align: center;
            margin-bottom: 40px;
        }

        .profile-header p {
            font-size: 16px;
            color: var(--gray);
            max-width: 600px;
            margin: 0 auto;
        }

        .section {
            background: var(--white);
            border-radius: 15px;
            box-shadow: var(--shadow);
            padding: 25px;
            margin-bottom: 30px;
            transition: transform 0.3s ease;
        }

        .section:hover {
            transform: translateY(-5px);
        }

        .section-title {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
            display: flex;
            align-items: center;
            color: var(--dark-text);
        }

        .section-title i {
            margin-right: 10px;
            color: var(--primary);
            font-size: 24px;
        }

        .user-details {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .detail-item {
            display: flex;
            align-items: center;
            background: var(--light-bg);
            padding: 15px;
            border-radius: 10px;
            transition: background 0.3s ease;
        }

        .detail-item:hover {
            background: #E6F0FA;
        }

        .detail-icon {
            width: 45px;
            height: 45px;
            background: var(--primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--white);
            font-size: 18px;
        }

        .detail-content h3 {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .detail-content p {
            font-weight: 600;
            color: var(--dark-text);
        }

        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 30px;
            align-items: center;
            background: var(--light-bg);
            padding: 15px;
            border-radius: 10px;
        }

        .filter-label {
            font-size: 14px;
            color: var(--gray);
            margin-right: 10px;
        }

        .select {
            padding: 10px 15px;
            border: none;
            border-radius: 8px;
            background: var(--white);
            font-size: 14px;
            color: var(--dark-text);
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .select:hover, .select:focus {
            outline: none;
            background: var(--secondary);
            color: var(--white);
        }

        .booking-card {
            border-radius: 15px;
            overflow: hidden;
            box-shadow: var(--shadow);
            margin-bottom: 30px;
            background: var(--white);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            cursor: pointer;
        }

        .booking-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.15);
        }

        .booking-header {
            display: flex;
            align-items: center;
            padding: 20px;
            background: var(--gradient);
            color: var(--white);
        }

        .booking-image {
            width: 100px;
            height: 80px;
            border-radius: 10px;
            object-fit: cover;
            margin-right: 20px;
        }

        .booking-title {
            flex: 1;
        }

        .booking-title h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .booking-title p {
            font-size: 14px;
            display: flex;
            align-items: center;
            opacity: 0.9;
        }

        .booking-title p i {
            margin-right: 8px;
        }

        .booking-status {
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            background: rgba(255, 255, 255, 0.2);
        }

        .status-active { background: #34D399; }
        .status-upcoming { background: #FBBF24; }
        .status-inactive { background: #EF4444; }

        .booking-dates {
            display: flex;
            align-items: center;
            font-size: 14px;
            margin-top: 8px;
            opacity: 0.9;
        }

        .booking-dates i {
            margin-right: 8px;
        }

        .booking-body {
            padding: 20px;
        }

        .reservation-item {
            display: flex;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #EDF2F7;
        }

        .reservation-item:last-child {
            border-bottom: none;
        }

        .reservation-detail h4 {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .reservation-detail p {
            font-weight: 600;
            color: var(--dark-text);
        }

        .reservation-price {
            text-align: right;
        }

        .reservation-price p:first-child {
            font-size: 14px;
            color: var(--gray);
        }

        .reservation-price p:last-child {
            font-weight: 700;
            color: var(--primary);
            font-size: 18px;
        }

        .booking-actions {
            display: flex;
            justify-content: flex-end;
            padding-top: 15px;
            border-top: 1px solid #EDF2F7;
            margin-top: 15px;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 14px;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            transition: all 0.3s ease;
        }

        .btn i {
            margin-right: 8px;
        }

        .btn-danger {
            background: #EF4444;
            color: var(--white);
        }

        .btn-danger:hover {
            background: #DC2626;
            transform: scale(1.05);
        }

        .btn-primary {
            background: var(--primary);
            color: var(--white);
        }

        .btn-primary:hover {
            background: var(--secondary);
            transform: scale(1.05);
        }

        .no-bookings {
            text-align: center;
            padding: 50px;
            color: var(--gray);
        }

        .no-bookings i {
            font-size: 60px;
            margin-bottom: 20px;
            color: var(--primary);
            animation: bounce 1.5s infinite;
        }

        @keyframes bounce {
            0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
            40% { transform: translateY(-15px); }
            60% { transform: translateY(-7px); }
        }

        .no-bookings h3 {
            margin-bottom: 15px;
            font-weight: 600;
            color: var(--dark-text);
        }

        .ticket-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.8);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.3s ease-out;
            overflow-y: auto;
            padding: 20px;
        }

        .ticket-container {
            position: relative;
            width: 90%;
            max-width: 950px;
            animation: slideUp 0.4s ease-out;
        }

        @keyframes slideUp {
            from { transform: translateY(50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .ticket {
            background: url('https://www.transparenttextures.com/patterns/paper-fibers.png'), linear-gradient(135deg, #ffffff, #f0f0f0);
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.3);
            position: relative;
            border: 2px solid rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: row;
            font-family: 'Roboto Mono', monospace;
            background-clip: padding-box;
        }

        .ticket::before,
        .ticket::after {
            content: '';
            position: absolute;
            width: 30px;
            height: 30px;
            background: rgba(0, 0, 0, 0.8);
            border-radius: 50%;
            z-index: 1;
        }

        .ticket::before {
            top: 50%;
            left: -15px;
            transform: translateY(-50%);
        }

        .ticket::after {
            top: 50%;
            right: -15px;
            transform: translateY(-50%);
        }

        .ticket-main {
            flex: 3;
            padding: 25px;
            position: relative;
            background: var(--white);
        }

        .ticket-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 15px;
            border-bottom: 2px dashed rgba(0, 0, 0, 0.2);
            margin-bottom: 20px;
        }

        .ticket-header .logo {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 20px;
            font-weight: 700;
            color: var(--primary);
        }

        .ticket-header .logo i {
            color: var(--secondary);
        }

        .ticket-header .ticket-id {
            font-size: 14px;
            color: var(--gray);
            font-weight: 600;
        }

        .ticket-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .ticket-info-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .ticket-info-item i {
            font-size: 16px;
            color: var(--primary);
            margin-top: 2px;
        }

        .ticket-info-item div h3 {
            font-size: 12px;
            color: var(--gray);
            margin-bottom: 5px;
            text-transform: uppercase;
        }

        .ticket-info-item div p {
            font-weight: 600;
            font-size: 14px;
            color: var(--dark-text);
        }

        .ticket-footer {
            padding-top: 15px;
            border-top: 2px dashed rgba(0, 0, 0, 0.2);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
            color: var(--gray);
        }

        .ticket-footer .contact-info {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .ticket-footer .download-btn {
            padding: 8px 15px;
            background: var(--primary);
            color: var(--white);
            border: none;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.3s ease;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .ticket-footer .download-btn:hover {
            background: var(--secondary);
        }

        .ticket-side {
            flex: 1;
            background: var(--gradient);
            padding: 20px;
            position: relative;
            color: var(--white);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 15px;
        }

        .ticket-side::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: repeating-linear-gradient(
                to bottom,
                transparent,
                transparent 5px,
                rgba(255, 255, 255, 0.2) 5px,
                rgba(255, 255, 255, 0.2) 10px
            );
        }

        .ticket-side .qr-code {
            width: 100px;
            height: 100px;
            background: var(--white);
            padding: 5px;
            border-radius: 5px;
        }

        .ticket-side .qr-code img {
            width: 100%;
            height: 100%;
        }

        .ticket-side .barcode {
            font-family: 'Roboto Mono', monospace;
            font-size: 14px;
            letter-spacing: 2px;
            background: rgba(255, 255, 255, 0.1);
            padding: 5px 10px;
            border-radius: 5px;
            transform: rotate(-90deg);
            white-space: nowrap;
            margin-top: 20px;
        }

        .ticket-side .issue-date {
            font-size: 12px;
            opacity: 0.8;
            text-align: center;
        }

        .ticket-close {
            position: absolute;
            top: 15px;
            right: 15px;
            background: rgba(0, 0, 0, 0.2);
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            color: var(--white);
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .ticket-close:hover {
            background: rgba(0, 0, 0, 0.3);
            transform: rotate(90deg);
        }

        .calendar-container {
    padding: 20px;
    background: var(--light-bg);
    border-radius: 15px;
    margin-bottom: 20px;
    position: relative;
}

.calendar-wrapper {
    max-width: 100%;
    overflow-x: auto;
}

.calendar-container .flatpickr-calendar {
    background: var(--white);
    box-shadow: var(--shadow);
    border-radius: 15px;
    width: 100%;
    max-width: 900px; /* Увеличенный размер календаря */
    margin: 0 auto;
    font-family: 'Poppins', sans-serif;
}

.calendar-container .flatpickr-days {
    width: 100%;
}

.calendar-container .flatpickr-day {
    height: 50px; /* Увеличенная высота дней */
    line-height: 50px;
    font-size: 16px;
    transition: background 0.3s ease, color 0.3s ease;
    border: 1px solid rgba(0, 0, 0, 0.1);
}

.calendar-container .flatpickr-day:hover {
    background: var(--light-bg);
    cursor: pointer;
}

.calendar-container .flatpickr-day.selected {
    background: var(--primary);
    border-color: var(--primary);
    color: var(--white);
}

.calendar-container .flatpickr-day.today {
    background: rgba(74, 144, 226, 0.2);
    font-weight: 600;
}

.calendar-container .flatpickr-day.hasEvent {
    position: relative;
}

.calendar-container .flatpickr-day.hasEvent::after {
    content: attr(data-event-count);
    position: absolute;
    top: 2px;
    right: 2px;
    width: 18px;
    height: 18px;
    background: var(--primary);
    color: var(--white);
    border-radius: 50%;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.calendar-container .flatpickr-day.pending::after {
    background: #FBBF24; /* Желтый для "В обработке" */
}

.calendar-container .flatpickr-day.confirmed::after {
    background: #34D399; /* Зеленый для "Подтверждено" */
}

.calendar-container .flatpickr-day.cancelled::after {
    background: #EF4444; /* Красный для "Отменено" */
}

.calendar-events {
    margin-top: 20px;
    padding: 15px;
    background: var(--white);
    border-radius: 10px;
    box-shadow: var(--shadow);
    max-height: 300px;
    overflow-y: auto;
}

.calendar-events h4 {
    margin-bottom: 15px;
    font-size: 18px;
    color: var(--dark-text);
}

.calendar-events .event-item {
    padding: 10px;
    margin-bottom: 10px;
    background: var(--light-bg);
    border-radius: 8px;
    transition: transform 0.3s ease;
}

.calendar-events .event-item:hover {
    transform: translateX(5px);
}

.calendar-events .event-item p {
    font-size: 14px;
    color: var(--gray);
    margin: 5px 0;
}

.calendar-events .event-item strong {
    color: var(--dark-text);
}

        .settings-form {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .form-group input {
            padding: 10px;
            border: 1px solid var(--gray);
            border-radius: 8px;
            font-size: 14px;
            color: var(--dark-text);
            background: var(--white);
            transition: border-color 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .form-message {
            margin-top: 10px;
            padding: 10px;
            border-radius: 8px;
            font-size: 14px;
        }

        .form-message.success {
            background: #34D399;
            color: var(--white);
        }

        .form-message.error {
            background: #EF4444;
            color: var(--white);
        }

        .ticket-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .ticket-item {
            background: var(--white);
            padding: 15px;
            border-radius: 10px;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease;
        }

        .ticket-item:hover {
            transform: translateY(-5px);
        }

        .ticket-item h4 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .ticket-item p {
            font-size: 14px;
            color: var(--gray);
            margin-bottom: 5px;
        }

        .ticket-item .ticket {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 10px;
        }

        @media (max-width: 768px) {
            .container {
                flex-direction: column;
                padding: 15px;
            }

            .sidebar {
                width: 100%;
                margin-right: 0;
                margin-bottom: 20px;
            }

            .ticket {
                flex-direction: column;
            }

            .ticket-main {
                padding: 20px;
            }

            .ticket-side {
                padding: 15px;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
            }

            .ticket-side .barcode {
                transform: none;
                margin-top: 0;
            }

            .ticket-side .qr-code {
                width: 80px;
                height: 80px;
            }

            .ticket-info {
                grid-template-columns: 1fr;
            }

            .ticket-footer {
                flex-direction: column;
                gap: 10px;
                text-align: center;
            }

            .user-details {
                grid-template-columns: 1fr;
            }

            .settings-form {
                grid-template-columns: 1fr;
            }
        }
        .form-message.warning {
    background: #FBBF24;
    color: #fff;
    padding: 10px;
    border-radius: 8px;
    font-size: 14px;
    margin-bottom: 20px;
}
.welcome-banner {
    background: var(--gradient);
    color: var(--white);
    padding: 30px;
    border-radius: 15px;
    text-align: center;
    margin-bottom: 30px;
    animation: gradientShift 5s infinite;
}

@keyframes gradientShift {
    0% { background: linear-gradient(135deg,rgb(0, 226, 247), #4A90E2); }
    50% { background: linear-gradient(135deg, #4A90E2,rgb(0, 226, 247)); }
    100% { background: linear-gradient(135deg, rgb(0, 226, 247), #4A90E2); }
}

.welcome-banner h1 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 10px;
}

.welcome-banner p {
    font-size: 16px;
    opacity: 0.9;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.stat-card {
    background: var(--light-bg);
    padding: 20px;
    border-radius: 10px;
    text-align: center;
    transition: transform 0.3s ease, background 0.3s ease;
}

.stat-card:hover {
    transform: translateY(-5px);
    background: var(--secondary);
    color: var(--white);
}

.stat-card i {
    font-size: 30px;
    color: var(--primary);
    margin-bottom: 10px;
}

.stat-card h3 {
    font-size: 14px;
    margin-bottom: 10px;
    color: inherit;
}

.stat-card p {
    font-size: 24px;
    font-weight: 700;
    color: inherit;
}

.recommendations-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.recommendation-card {
    background: var(--white);
    border-radius: 15px;
    overflow: hidden;
    box-shadow: var(--shadow);
    transition: transform 0.3s ease;
}

.recommendation-card:hover {
    transform: translateY(-5px);
}

.recommendation-card img {
    width: 100%;
    height: 150px;
    object-fit: cover;
}

.recommendation-card h3 {
    font-size: 16px;
    font-weight: 600;
    margin: 15px;
}

.recommendation-card p {
    font-size: 14px;
    color: var(--gray);
    margin: 0 15px 15px;
}

.recommendation-card .btn {
    margin: 0 15px 15px;
    width: calc(100% - 30px);
    text-align: center;
    justify-content: center;
}

.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.action-btn {
    padding: 15px;
    background: var(--primary);
    color: var(--white);
    border: none;
    border-radius: 10px;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    transition: transform 0.3s ease, background 0.3s ease;
}

.action-btn:hover {
    transform: scale(1.05);
    background: var(--secondary);
}

    </style>
</head>
<body>
    <div class="container">
        <!-- Sidebar -->
        <div class="sidebar">
    <div class="profile-avatar">
        <h1>Привет, <?= htmlspecialchars($user['username']) ?>!</h1>
        <p><?= htmlspecialchars($user['email']) ?></p>
       
    </div>
    
    <nav class="menu">
        <a onclick="showSection('glavn'); event.stopPropagation();" id="glavn-btn"><i class="fas fa-home"></i> Главная</a>
        <a onclick="showSection('kalendar'); event.stopPropagation();" id="kalendar-btn"><i class="fas fa-calendar"></i> Календарь</a>
        <a onclick="showSection('nastroiki'); event.stopPropagation();" id="nastro-btn"><i class="fas fa-cog"></i> Настройки</a>
       
        <a onclick="showSection('bilety'); event.stopPropagation();" id="bilety-btn"><i class="fas fa-ticket-alt"></i> Билеты</a>
        <a onclick="showSection('bronirovaniya'); event.stopPropagation();" id="bronirovaniya-btn"><i class="fas fa-suitcase"></i> Бронирования</a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="admin.php" target="_blank"><i class="fas fa-shield-alt"></i> Админ-панель</a>
        <?php endif; ?>
        <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Выйти</a>
    </nav>
</div>

        <div class="main-content active" id="glavn">
    <div class="welcome-banner">
        <h1>Добро пожаловать, <?= htmlspecialchars($user['username']) ?>!</h1>
        <p>Сегодня <?= date('d.m.Y') ?>. Давайте спланируем ваше следующее путешествие!</p>
    </div>

    <div class="section">
                <h2 class="section-title"><i class="fas fa-user-circle"></i> Личная информация</h2>
                <div class="user-details">
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-envelope"></i></div>
                        <div class="detail-content">
                            <h3>Email</h3>
                            <p><?= htmlspecialchars($user['email']) ?></p>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-phone"></i></div>
                        <div class="detail-content">
                            <h3>Телефон</h3>
                            <p>Не указан</p>
                        </div>
                    </div>
                    
                    <div class="detail-item">
                        <div class="detail-icon"><i class="fas fa-calendar-alt"></i></div>
                        <div class="detail-content">
                            <h3>Зарегистрирован</h3>
                            <p><?= date('d.m.Y', strtotime($user['created_at'])) ?></p>
                        </div>
                    </div>
                </div>
            </div>

    <div class="section">
        <h2 class="section-title"><i class="fas fa-chart-line"></i> Ваша статистика</h2>
        <div class="stats-grid">
            <div class="stat-card">
                <i class="fas fa-suitcase"></i>
                <h3>Всего бронирований</h3>
                <p><?= count($bookings) ?></p>
            </div>
            <div class="stat-card">
                <i class="fas fa-ticket-alt"></i>
                <h3>Подтвержденные билеты</h3>
                <p>
                    <?php
                    $confirmed_count = 0;
                    foreach ($bookings as $tour) {
                        foreach ($tour['reservations'] as $reservation) {
                            if (($reservation['booking_status'] ?? 'pending') === 'confirmed') {
                                $confirmed_count++;
                            }
                        }
                    }
                    echo $confirmed_count;
                    ?>
                </p>
            </div>
            <div class="stat-card">
                <i class="fas fa-hourglass-half"></i>
                <h3>Ожидающие заявки</h3>
                <p>
                    <?php
                    $pending_count = 0;
                    foreach ($bookings as $tour) {
                        foreach ($tour['reservations'] as $reservation) {
                            if (($reservation['booking_status'] ?? 'pending') === 'pending') {
                                $pending_count++;
                            }
                        }
                    }
                    echo $pending_count;
                    ?>
                </p>
            </div>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title"><i class="fas fa-star"></i> Рекомендованные туры</h2>
        <div class="recommendations-grid">
            <div class="recommendation-card">
                <img src="https://images.unsplash.com/photo-1495567720989-cebdb147afc5?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=60" alt="Тур">
                <h3>Пляжный отдых в Турции</h3>
                <p>Насладитесь солнцем и морем на лучших пляжах Антальи!</p>
                <a href="tours.php" class="btn btn-primary"><i class="fas fa-search"></i> Подробнее</a>
            </div>
            <div class="recommendation-card">
                <img src="https://images.unsplash.com/photo-1507525428034-b723cf961d3e?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=60" alt="Тур">
                <h3>Горнолыжный курорт в Альпах</h3>
                <p>Испытайте адреналин на склонах Швейцарии!</p>
                <a href="tours.php" class="btn btn-primary"><i class="fas fa-search"></i> Подробнее</a>
            </div>
            <div class="recommendation-card">
                <img src="https://images.unsplash.com/photo-1530785602389-07594b6c3df9?ixlib=rb-4.0.3&auto=format&fit=crop&w=500&q=60" alt="Тур">
                <h3>Культурный тур по Италии</h3>
                <p>Исследуйте искусство и историю Рима и Флоренции!</p>
                <a href="tours.php" class="btn btn-primary"><i class="fas fa-search"></i> Подробнее</a>
            </div>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title"><i class="fas fa-rocket"></i> Быстрые действия</h2>
        <div class="actions-grid">
            <button class="action-btn" onclick="window.location.href='tours.php'">
                <i class="fas fa-search"></i> Найти тур
            </button>
            <button class="action-btn" onclick="showSection('bilety')">
                <i class="fas fa-ticket-alt"></i> Мои билеты
            </button>
            <button class="action-btn" onclick="showSection('bronirovaniya')">
                <i class="fas fa-suitcase"></i> Мои бронирования
            </button>
        </div>
    </div>
</div>

        <div class="main-content" id="kalendar">
    <div class="section">
        <h2 class="section-title"><i class="fas fa-calendar-alt"></i> Календарь бронирований</h2>
        <div class="calendar-container">
            <div class="calendar-wrapper">
                <input id="calendar" type="text" readonly>
                <div id="calendar-events" class="calendar-events"></div>
            </div>
        </div>
    </div>
</div>

        <div class="main-content" id="nastroiki">
            <div class="section">
                <h2 class="section-title"><i class="fas fa-cog"></i> Настройки</h2>
                <?php if (isset($success_message)): ?>
                    <div class="form-message success"><?= htmlspecialchars($success_message) ?></div>
                <?php elseif (isset($error_message)): ?>
                    <div class="form-message error"><?= htmlspecialchars($error_message) ?></div>
                <?php endif; ?>
                
                <form class="settings-form" method="POST" action="">
                    <div class="form-group">
                        <label for="username">Имя пользователя</label>
                        <input type="text" id="username" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Новый пароль (оставьте пустым, чтобы не менять)</label>
                        <input type="password" id="password" name="password" placeholder="Введите новый пароль">
                    </div>
                    
                    <button type="submit" name="update_settings" class="btn btn-primary">
                        <i class="fas fa-save"></i> Сохранить изменения
                    </button>
                </form>
            </div>
        </div>

        <div class="main-content" id="profil">
            
        </div>

       <div class="main-content" id="bilety">
    <div class="section">
        <h2 class="section-title"><i class="fas fa-ticket-alt"></i> Мои билеты</h2>
        <?php
        // Подсчитываем неподтвержденные заявки
        $pending_count = 0;
        foreach ($bookings as $tour) {
            foreach ($tour['reservations'] as $reservation) {
                if (($reservation['booking_status'] ?? 'pending') === 'pending') {
                    $pending_count++;
                }
            }
        }
        if ($pending_count > 0): ?>
            <div class="form-message warning" style="background: #FBBF24; color: #fff; margin-bottom: 20px;">
                У вас есть <?php echo $pending_count; ?> неподтвержденных заявок. Пожалуйста, дождитесь подтверждения для получения билетов.
            </div>
        <?php endif; ?>
        <div class="ticket-list">
            <?php
            $confirmed_bookings = false;
            foreach ($bookings as $travel_id => $tour):
                foreach ($tour['reservations'] as $reservation):
                    if (($reservation['booking_status'] ?? 'pending') !== 'confirmed') {
                        continue; // Пропускаем неподтвержденные брони
                    }
                    $confirmed_bookings = true;
                    $bookingData = [
                        'id' => $reservation['id'] ?? 0,
                        'title' => htmlspecialchars($tour['title'] ?? 'Без названия', ENT_QUOTES, 'UTF-8'),
                        'destination' => htmlspecialchars($tour['destination'] ?? 'Не указано', ENT_QUOTES, 'UTF-8'),
                        'start_date' => $tour['start_date'] ?? date('Y-m-d'),
                        'end_date' => $tour['end_date'] ?? date('Y-m-d'),
                        'booking_status' => $reservation['booking_status'] ?? 'pending', // Используем booking_status
                        'package_name' => htmlspecialchars($reservation['package_name'] ?? 'Без пакета', ENT_QUOTES, 'UTF-8'),
                        'persons' => $reservation['persons'] ?? 1,
                        'created_at' => $reservation['created_at'] ?? date('Y-m-d H:i:s'),
                        'total_price' => $reservation['total_price'] ?? 0,
                        'username' => htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8'),
                        'image_url' => $tour['image_url'] ?? 'https://via.placeholder.com/100'
                        
                    ];
                    ?>
                    <div class="ticket-item">
                        <div class="ticket-content" onclick='openTicket(<?= json_encode($bookingData, JSON_HEX_QUOT | JSON_HEX_APOS) ?>)'>
                            <h4><?= htmlspecialchars($tour['title'] ?? 'Без названия') ?> (Билет #<?= $reservation['id'] ?>)</h4>
                            <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($tour['destination'] ?? 'Не указано') ?></p>
                            <p><i class="far fa-calendar-alt"></i> <?= date('d.m.Y', strtotime($tour['start_date'])) ?> - <?= date('d.m.Y', strtotime($tour['end_date'])) ?></p>
                            <p><i class="fas fa-ruble-sign"></i> <?= number_format($reservation['total_price'], 2, ',', ' ') ?> ₽</p>
                        </div>
                        <div class="ticket-actions">
                            <?php if (($reservation['booking_status'] ?? 'pending') === 'confirmed'): ?>
                                <a href="?cancel=1&booking_id=<?= $reservation['id'] ?>" class="btn btn-danger" onclick="return confirm('Вы точно уверены, что хотите отменить билет?');">
                                    <i class="fas fa-times"></i> Отказаться
                                </a>
                               
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <?php if (!$confirmed_bookings): ?>
                <div class="no-bookings">
                    <i class="fas fa-ticket-alt"></i>
                    <h3>У вас пока нет подтвержденных билетов</h3>
                    <p>Забронируйте тур и дождитесь подтверждения, чтобы получить билет!</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
              

        <div class="main-content" id="bronirovaniya">
            <div class="section">
                <h2 class="section-title"><i class="fas fa-suitcase"></i> Мои бронирования</h2>
                <div class="filters">
                    <span class="filter-label">Фильтр:</span>
                    <select class="select" onchange="window.location.href='?status=' + this.value">
                        <option value="">Все статусы</option>
                        <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Подтверждено</option>
                        <option value="upcoming" <?= $status_filter === 'upcoming' ? 'selected' : '' ?>>Ожидает</option>
                        <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Отменено</option>
                    </select>
                    
                    <span class="filter-label">Сортировка:</span>
                    <select class="select" onchange="window.location.href='?sort=' + this.value + '&order=<?= $sort_order ?>'">
                        <option value="created_at" <?= $sort_by === 'created_at' ? 'selected' : '' ?>>Дата бронирования</option>
                        <option value="start_date" <?= $sort_by === 'start_date' ? 'selected' : '' ?>>Дата начала</option>
                        <option value="price" <?= $sort_by === 'price' ? 'selected' : '' ?>>Стоимость</option>
                    </select>
                    
                    <select class="select" onchange="window.location.href='?sort=<?= $sort_by ?>&order=' + this.value">
                        <option value="desc" <?= $sort_order === 'DESC' ? 'selected' : '' ?>>По убыванию</option>
                        <option value="asc" <?= $sort_order === 'ASC' ? 'selected' : '' ?>>По возрастанию</option>
                    </select>
                </div>

                <?php if (empty($bookings)): ?>
                    <div class="no-bookings">
                        <i class="fas fa-suitcase-rolling"></i>
                        <h3>У вас пока нет бронирований</h3>
                        <p>Начните планировать ваше следующее приключение!</p>
                        <a href="tours.php" class="btn btn-primary">
                            <i class="fas fa-search"></i> Найти туры
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($bookings as $travel_id => $tour): ?>
                        <div class="booking-card">
                            <div class="booking-header">
                                <img src="<?= htmlspecialchars($tour['image_url']) ?>" alt="Тур" class="booking-image">
                                <div class="booking-title">
                                    <h3><?= htmlspecialchars($tour['title'] ?? 'Без названия') ?></h3>
                                    <p><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($tour['destination'] ?? 'Не указано') ?></p>
                                    <div class="booking-dates">
                                        <span><?= date('d.m.Y', strtotime($tour['start_date'])) ?></span> - 
                                        <span><?= date('d.m.Y', strtotime($tour['end_date'])) ?></span>
                                    </div>
                                    <p><i class="fas fa-money"></i> Общая стоимость: <?= number_format($tour['total_tour_price'], 2, ',', ' ') ?> ₽</p>
                                </div>
                               <span class="booking-status status-<?= strtolower($reservation['booking_status'] ?? 'pending') ?>">
    <?= $reservation['booking_status'] === 'pending' ? 'В обработке' : 
        ($reservation['booking_status'] === 'confirmed' ? 'Подтверждено' : 'Отменено') ?>
</span>
                            </div>
                            
                            <div class="booking-body">
                                <?php foreach ($tour['reservations'] as $reservation): ?>
                                    <div class="reservation-item">
                                        <div class="reservation-detail">
                                            <h4>Пакет</h4>
                                            <p><?= htmlspecialchars($reservation['package_name'] ?? 'Без пакета') ?></p>
                                        </div>
                                        
                                        <div class="reservation-detail">
                                            <h4>Количество человек</h4>
                                            <p><?= htmlspecialchars($reservation['persons'] ?? 1) ?></p>
                                        </div>
                                        
                                        <div class="reservation-detail">
                                            <h4>Дата бронирования</h4>
                                            <p><?= date('d.m.Y H:i', strtotime($reservation['created_at'])) ?></p>
                                        </div>
                                        
                                        <div class="reservation-price">
                                            <p>Стоимость</p>
                                            <p><?= number_format($reservation['total_price'], 2, ',', ' ') ?> ₽</p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="booking-actions">
                                <?php foreach ($tour['reservations'] as $reservation): ?>
                                    <a href="?cancel=1&booking_id=<?= $reservation['id'] ?>" class="btn btn-danger" onclick="return confirm('Вы точно уверены, что хотите отменить бронирование?');">
                                        <i class="fas fa-times"></i> Отменить
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Ticket Modal -->
        <div id="ticketModal" class="ticket-modal">
            <div class="ticket-container">
                <div id="ticketContent" class="ticket"></div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    <script>
        const bookings = <?php echo json_encode(array_values($bookings), JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function showSection(sectionId) {
            console.log(`Attempting to show section: ${sectionId}`);
            document.querySelectorAll('.main-content').forEach(section => {
                section.classList.remove('active');
            });
            document.querySelectorAll('.menu a').forEach(link => {
                link.classList.remove('active');
            });
            
            const section = document.getElementById(sectionId);
            if (section) {
                console.log(`Section ${sectionId} found, adding active class`);
                section.classList.add('active');
            } else {
                console.error(`Section with ID ${sectionId} not found`);
            }
            
            const button = document.getElementById(`${sectionId}-btn`);
            if (button) {
                console.log(`Button ${sectionId}-btn found, adding active class`);
                button.classList.add('active');
            } else {
                console.error(`Button with ID ${sectionId}-btn not found`);
            }
        }

        function openTicket(booking) {
    try {
        console.log("Booking data:", booking);
        const modal = document.getElementById('ticketModal');
        const ticket = document.getElementById('ticketContent');
        
        if (!booking || !booking.id || !booking.title) {
            throw new Error("Недостаточно данных для бронирования: id=" + (booking?.id ?? 'missing') + ", title=" + (booking?.title ?? 'missing'));
        }

        ticket.innerHTML = `
            <button class="ticket-close" onclick="closeTicket()">×</button>
            <div class="ticket-main">
                <div style="text-align: center; margin-bottom: 20px;">
                    <img src="${booking.image_url || 'https://via.placeholder.com/100'}" alt="Тур" style="width: 100px; height: auto; border-radius: 10px; object-fit: cover;">
                </div>
                <div class="ticket-header">
                    <div class="logo">
                        <i class="fas fa-plane"></i> iTravel
                    </div>
                    <div class="ticket-id">
                        Билет #${booking.id}
                    </div>
                </div>
                <div class="ticket-info">
                <div class="ticket-info-item">
            <i class="fas fa-user"></i>
            <div>
                <h3>Клиент</h3>
                <p>${booking.username}</p>
            </div>
        </div>
                    <div class="ticket-info-item">
                        <i class="fas fa-map-marker-alt"></i>
                        <div>
                            <h3>Направление</h3>
                            <p>${booking.destination}</p>
                        </div>
                    </div>
                    <div class="ticket-info-item">
                        <i class="far fa-calendar-alt"></i>
                        <div>
                            <h3>Даты поездки</h3>
                            <p>${new Date(booking.start_date).toLocaleDateString('ru-RU')} - ${new Date(booking.end_date).toLocaleDateString('ru-RU')}</p>
                        </div>
                    </div>
                    <div class="ticket-info-item">
                        <i class="fas fa-box"></i>
                        <div>
                            <h3>Пакет</h3>
                            <p>${booking.package_name || 'Без пакета'}</p>
                        </div>
                    </div>
                    <div class="ticket-info-item">
                        <i class="fas fa-users"></i>
                        <div>
                            <h3>Количество человек</h3>
                            <p>${booking.persons}</p>
                        </div>
                    </div>
                    <div class="ticket-info-item">
                        <i class="fas fa-check-circle"></i>
                        <div>
                            <h3>Статус</h3>
                            <p>${booking.booking_status === 'pending' ? 'В обработке' : 
                                (booking.booking_status === 'confirmed' ? 'Подтверждено' : 'Отменено')}</p>
                        </div>
                    </div>
                    <div class="ticket-info-item">
                        <i class="fas fa-ruble-sign"></i>
                        <div>
                            <h3>Стоимость</h3>
                            <p>${new Intl.NumberFormat('ru-RU').format(booking.total_price)} ₽</p>
                        </div>
                    </div>
                </div>
                <div class="ticket-footer">
                    <div class="contact-info">
                        <p><i class="fas fa-envelope"></i> support@example.com</p>
                        <p><i class="fas fa-phone"></i> +7 (495) 123-45-67</p>
                    </div>
                    <button class="download-btn" onclick="downloadTicket()">
                        <i class="fas fa-download"></i> Скачать билет
                    </button>
                </div>
            </div>
            <div class="ticket-side">
                <div class="qr-code">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=${encodeURIComponent(`Бронирование #${booking.id}\nТур: ${booking.title}\nКлиент: ${user.username}\nДаты: ${booking.start_date} - ${booking.end_date}\nСтатус: ${booking.booking_status === 'pending' ? 'В обработке' : (booking.booking_status === 'confirmed' ? 'Подтверждено' : 'Отменено')}\nСтоимость: ${new Intl.NumberFormat('ru-RU').format(booking.total_price)} ₽\nДата выдачи: ${new Date().toLocaleString('ru-RU')}`)}" alt="QR code">
                </div>
                <div class="barcode">
                    <svg id="barcode-${booking.id}"></svg>
                </div>
                <div class="issue-date">
                    Выдан: ${new Date().toLocaleString('ru-RU')}
                </div>
            </div>
        `;
        
        JsBarcode(`#barcode-${booking.id}`, String(booking.id).padStart(10, '0'), {
            format: "CODE128",
            width: 2,
            height: 40,
            displayValue: false,
            background: "transparent",
            lineColor: "#ffffff"
        });

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
    } catch (error) {
        console.error("Ошибка при открытии билета:", error.message);
        alert("Ошибка при открытии билета. Проверьте консоль для подробностей.");
    }
}

        function closeTicket() {
            document.getElementById('ticketModal').style.display = 'none';
            document.body.style.overflow = 'auto';
        }

        function downloadTicket() {
            try {
                html2canvas(document.getElementById('ticketContent'), { scale: 2 }).then(canvas => {
                    const link = document.createElement('a');
                    link.download = `itravel-ticket-${new Date().toLocaleDateString('ru-RU')}.png`;
                    link.href = canvas.toDataURL('image/png');
                    link.click();
                }).catch(error => {
                    console.error("Ошибка при скачивании билета:", error);
                    alert("Не удалось скачать билет.");
                });
            } catch (error) {
                console.error("Ошибка при скачивании билета:", error);
                alert("Не удалось скачать билет.");
            }
        }

      flatpickr("#calendar", {
    inline: true,
    dateFormat: "Y-m-d",
    locale: "ru",
    onChange: function(selectedDates, dateStr, instance) {
        displayEvents(dateStr);
    },
    onReady: function(selectedDates, dateStr, instance) {
        const days = instance.days;
        bookings.forEach(tour => {
            tour.reservations.forEach(reservation => {
                const startDate = new Date(tour.start_date).toISOString().split('T')[0];
                const endDate = new Date(tour.end_date).toISOString().split('T')[0];
                days.forEach(day => {
                    const dayDate = day.dateObj.toISOString().split('T')[0];
                    if (dayDate >= startDate && dayDate <= endDate) {
                        day.classList.add('hasEvent');
                        day.classList.add(reservation.booking_status.toLowerCase());
                        const eventCount = days.filter(d => {
                            const dDate = d.dateObj.toISOString().split('T')[0];
                            return dDate >= startDate && dDate <= endDate;
                        }).length;
                        day.setAttribute('data-event-count', eventCount);
                    }
                });
                instance.setDate([tour.start_date, tour.end_date], false);
            });
        });
        displayEvents(dateStr);
    }
});

       function displayEvents(date) {
    const eventsContainer = document.getElementById('calendar-events');
    let html = '<h4>События на ' + new Date(date).toLocaleDateString('ru-RU') + '</h4>';
    let hasEvents = false;

    bookings.forEach(tour => {
        tour.reservations.forEach(reservation => {
            const startDate = new Date(tour.start_date).toISOString().split('T')[0];
            const endDate = new Date(tour.end_date).toISOString().split('T')[0];
            if (date >= startDate && date <= endDate) {
                hasEvents = true;
                html += `
                    <div class="event-item" onclick="openTicket(${JSON.stringify({
                        id: reservation.id,
                        title: tour.title,
                        destination: tour.destination,
                        start_date: tour.start_date,
                        end_date: tour.end_date,
                        booking_status: reservation.booking_status,
                        package_name: reservation.package_name,
                        persons: reservation.persons,
                        total_price: reservation.total_price,
                        image_url: tour.image_url
                    }).replace(/'/g, "\\'")})" style="cursor: pointer;">
                        <p><strong>${tour.title}</strong></p>
                        <p><i class="fas fa-map-marker-alt"></i> ${tour.destination}</p>
                        <p><i class="far fa-calendar-alt"></i> ${new Date(tour.start_date).toLocaleDateString('ru-RU')} - ${new Date(tour.end_date).toLocaleDateString('ru-RU')}</p>
                        <p><i class="fas fa-box"></i> ${reservation.package_name || 'Без пакета'}</p>
                        <p><i class="fas fa-users"></i> ${reservation.persons} чел.</p>
                        <p><i class="fas fa-ruble-sign"></i> ${new Intl.NumberFormat('ru-RU').format(reservation.total_price)} ₽</p>
                        <p><i class="fas fa-info-circle"></i> Статус: ${reservation.booking_status === 'pending' ? 'В обработке' : (reservation.booking_status === 'confirmed' ? 'Подтверждено' : 'Отменено')}</p>
                    </div>
                `;
            }
        });
    });

    if (!hasEvents) {
        html += '<p>Нет событий на эту дату.</p>';
    }

    eventsContainer.innerHTML = html;
}

        window.onclick = function(event) {
            if (event.target == document.getElementById('ticketModal')) {
                closeTicket();
            }
        }

        const user = {
            username: '<?php echo htmlspecialchars($user['username']); ?>',
            email: '<?php echo htmlspecialchars($user['email']); ?>'
        };

        // Показываем секцию 'glavn' по умолчанию
        showSection('glavn');
    </script>
</body>
</html>