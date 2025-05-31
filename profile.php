<?php
// Включим вывод ошибок для отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once('navbar.php'); // Подключает config.php, где есть $conn

// Убедимся, что сессия стартована
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
    // Инициализация CSRF-токена, если его нет
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

include($_SERVER['DOCUMENT_ROOT'].'/travel/config.php');

// Получаем данные пользователя
$username = "Гость";
$email = "Не указан";
$user_id = null;
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT username, email, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->bind_result($username, $email, $role);
    $stmt->fetch();
    $stmt->close();
}

// Обработка AJAX-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
    strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json');
    
    try {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        
        // Обработка JSON-запросов
        if (isset($contentType) && stripos($contentType, 'application/json') !== false) {
            $input = json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
            
            if ($input['action'] === 'logout') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token'])) {
                    session_unset();
                    session_destroy();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Вы успешно вышли из системы'
                    ], JSON_UNESCAPED_UNICODE);
                    exit;
                }
            }

            if ($input['action'] === 'update_profile') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($user_id)) {
                    $new_username = trim($input['username'] ?? '');
                    $new_email = trim($input['email'] ?? '');
                    $new_password = $input['password'] ?? '';

                    if (!empty($new_username) && strlen($new_username) >= 3 && !empty($new_email) && filter_var($new_email, FILTER_VALIDATE_EMAIL) && (empty($new_password) || strlen($new_password) >= 6)) {
                        $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
                        $stmt->bind_param("si", $new_username, $user_id);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        if ($result->num_rows === 0) {
                            $stmt->close();
                            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
                            $stmt->bind_param("si", $new_email, $user_id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            if ($result->num_rows === 0) {
                                $stmt->close();
                                if (!empty($new_password)) {
                                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ? WHERE id = ?");
                                    $stmt->bind_param("sssi", $new_username, $new_email, $hashed_password, $user_id);
                                } else {
                                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ? WHERE id = ?");
                                    $stmt->bind_param("ssi", $new_username, $new_email, $user_id);
                                }
                                if ($stmt->execute()) {
                                    $stmt->close();
                                    $_SESSION['username'] = $new_username;
                                    echo json_encode([
                                        'success' => true,
                                        'message' => 'Profile updated successfully',
                                        'user' => [
                                            'username' => $new_username,
                                            'email' => $new_email
                                        ]
                                    ], JSON_UNESCAPED_UNICODE);
                                    exit;
                                }
                            }
                            $stmt->close();
                        }
                        $stmt->close();
                    }
                }
            }

            if ($input['action'] === 'cancel_booking') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($input['booking_id'])) {
                    $booking_id = (int)$input['booking_id'];
                    $stmt = $conn->prepare("SELECT user_id FROM tour_bookings WHERE id = ?");
                    $stmt->bind_param("i", $booking_id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    if ($result->num_rows > 0) {
                        if ($role === 'admin') {
                            $stmt = $conn->prepare("DELETE FROM tour_bookings WHERE id = ?");
                            $stmt->bind_param("i", $booking_id);
                        } else {
                            $user_id = $_SESSION['user_id'];
                            $stmt = $conn->prepare("DELETE FROM tour_bookings WHERE id = ? AND user_id = ?");
                            $stmt->bind_param("ii", $booking_id, $user_id);
                        }
                        if ($stmt->execute() && ($stmt->affected_rows > 0 || $role === 'admin')) {
                            $stmt->close();
                            echo json_encode(['success' => true, 'message' => 'Booking deleted successfully']);
                            exit;
                        }
                        $stmt->close();
                    }
                }
            }

            if ($input['action'] === 'add_service' && $role === 'admin') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($input['name'])) {
                    $name = $input['name'];
                    $description = $input['description'] ?? '';
                    $price = $input['price'] ?? 0;
                    $stmt = $conn->prepare("INSERT INTO services (name, description, price) VALUES (?, ?, ?)");
                    $stmt->bind_param("ssd", $name, $description, $price);
                    if ($stmt->execute()) {
                        $newServiceId = $conn->insert_id;
                        $stmt->close();
                        echo json_encode([
                            'success' => true,
                            'message' => 'Service added successfully',
                            'service' => [
                                'id' => $newServiceId,
                                'name' => $name,
                                'description' => $description,
                                'price' => $price
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $stmt->close();
                }
            }

            if ($input['action'] === 'get_tour' && $role === 'admin') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($input['id']) && (int)$input['id'] > 0) {
                    $id = (int)$input['id'];
                    $stmt = $conn->prepare("
                        SELECT id, title, destination, price, status, description, start_date, end_date, transport_type, transport_type_en, image, images
                        FROM travels 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("i", $id);
                    if ($stmt->execute()) {
                        $result = $stmt->get_result();
                        if ($result->num_rows > 0) {
                            $tour = $result->fetch_assoc();
                            $stmt->close();
                            $stmt = $conn->prepare("SELECT package_id FROM tour_packages WHERE tour_id = ?");
                            $stmt->bind_param("i", $id);
                            $stmt->execute();
                            $result = $stmt->get_result();
                            $packages = [];
                            while ($row = $result->fetch_assoc()) {
                                $packages[] = (string)$row['package_id'];
                            }
                            $stmt->close();
                            $tour['packages'] = $packages;
                            echo json_encode([
                                'success' => true,
                                'tour' => $tour
                            ], JSON_UNESCAPED_UNICODE);
                            exit;
                        }
                        $stmt->close();
                    }
                }
            }

            if ($input['action'] === 'edit_tour' && $role === 'admin') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($input['id']) && !empty($input['title']) && !empty($input['destination']) && !empty($input['price']) && !empty($input['transport_type'])) {
                    $id = $input['id'];
                    $title = $input['title'];
                    $destination = $input['destination'];
                    $price = $input['price'];
                    $status = $input['status'] ?? 'inactive';
                    $start_date = $input['start_date'] ?? null;
                    $end_date = $input['end_date'] ?? null;
                    $transport_type = $input['transport_type'];
                    $transport_type_en = $input['transport_type_en'] ?? '';
                    $stmt = $conn->prepare("
                        UPDATE travels 
                        SET title = ?, destination = ?, price = ?, status = ?, start_date = ?, end_date = ?, transport_type = ?, transport_type_en = ? 
                        WHERE id = ?
                    ");
                    $stmt->bind_param("ssdsssssi", $title, $destination, $price, $status, $start_date, $end_date, $transport_type, $transport_type_en, $id);
                    if ($stmt->execute()) {
                        $stmt->close();
                        echo json_encode([
                            'success' => true,
                            'message' => 'Tour updated successfully',
                            'tour' => [
                                'id' => $id,
                                'title' => $title,
                                'destination' => $destination,
                                'price' => $price,
                                'status' => $status,
                                'start_date' => $start_date,
                                'end_date' => $end_date,
                                'transport_type' => $transport_type,
                                'transport_type_en' => $transport_type_en
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $stmt->close();
                }
            }

            if ($input['action'] === 'delete_tour' && $role === 'admin') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($input['id']) && (int)$input['id'] > 0) {
                    $id = (int)$input['id'];
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM tour_bookings WHERE travel_id = ?");
                    $stmt->bind_param("i", $id);
                    $stmt->execute();
                    $stmt->bind_result($booking_count);
                    $stmt->fetch();
                    $stmt->close();
                    if ($booking_count === 0) {
                        $conn->begin_transaction();
                        $stmt = $conn->prepare("DELETE FROM tour_packages WHERE tour_id = ?");
                        $stmt->bind_param("i", $id);
                        if ($stmt->execute()) {
                            $stmt->close();
                            $stmt = $conn->prepare("DELETE FROM travels WHERE id = ?");
                            $stmt->bind_param("i", $id);
                            if ($stmt->execute() && $stmt->affected_rows > 0) {
                                $stmt->close();
                                $conn->commit();
                                echo json_encode([
                                    'success' => true,
                                    'message' => 'Тур успешно удален'
                                ], JSON_UNESCAPED_UNICODE);
                                exit;
                            }
                            $stmt->close();
                        }
                        $conn->rollback();
                    }
                }
            }

            if ($input['action'] === 'add_package' && $role === 'admin') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($input['name'])) {
                    $name = $input['name'];
                    $description = $input['description'] ?? '';
                    $price = $input['price'] ?? 0;
                    $services = isset($input['services']) ? (array)$input['services'] : [];
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("INSERT INTO packages (name, description, price) VALUES (?, ?, ?)");
                    $stmt->bind_param("sss", $name, $description, $price);
                    if ($stmt->execute()) {
                        $newPackageId = $conn->insert_id;
                        $stmt->close();
                        if (!empty($services)) {
                            $stmt = $conn->prepare("INSERT INTO package_services (package_id, service_id) VALUES (?, ?)");
                            foreach ($services as $service_id) {
                                $service_id = (int)$service_id;
                                if ($service_id > 0) {
                                    $stmt->bind_param("ii", $newPackageId, $service_id);
                                    $stmt->execute();
                                }
                            }
                            $stmt->close();
                        }
                        $conn->commit();
                        echo json_encode([
                            'success' => true,
                            'message' => 'Package added successfully',
                            'package' => [
                                'id' => $newPackageId,
                                'name' => $name,
                                'description' => $description,
                                'price' => $price,
                                'services' => $services
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $stmt->close();
                    $conn->rollback();
                }
            }

            if ($input['action'] === 'update_booking_status' && $role === 'admin') {
                if (!empty($input['csrf_token']) && hash_equals($_SESSION['csrf_token'], $input['csrf_token']) && !empty($input['booking_id']) && !empty($input['status']) && in_array($input['status'], ['pending', 'confirmed', 'cancelled'])) {
                    $booking_id = $input['booking_id'];
                    $status = $input['status'];
                    $stmt = $conn->prepare("UPDATE tour_bookings SET status = ? WHERE id = ?");
                    $stmt->bind_param("si", $status, $booking_id);
                    if ($stmt->execute()) {
                        $stmt->close();
                        echo json_encode([
                            'success' => true,
                            'message' => 'Booking status updated successfully',
                            'booking' => [
                                'id' => $booking_id,
                                'status' => $status
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $stmt->close();
                }
            }
        }

        if (isset($_POST['action']) && $_POST['action'] === 'add_tour' && $role === 'admin') {
            if (!empty($_POST['csrf_token']) && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
                $title = $_POST['title'] ?? '';
                $destination = $_POST['destination'] ?? '';
                $price = (float)($_POST['price'] ?? 0);
                $status = $_POST['status'] ?? 'inactive';
                $description = $_POST['description'] ?? '';
                $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
                $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
                $transport_type = $_POST['transport_type'] ?? '';
                $transport_type = mb_strtolower($transport_type, 'UTF-8');
                $transport_type_en = $_POST['transport_type_en'] ?? '';
                $packages = !empty($_POST['packages']) ? json_decode($_POST['packages'], true) : [];
                
                if (!empty($title) && !empty($destination) && $price > 0 && !empty($transport_type)) {
                    $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/travel/uploads/tours/';
                    $relativeDir = '/travel/uploads/tours/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $imagePath = '';
                    if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                        $image = $_FILES['image'];
                        $imageName = uniqid('tour_') . '_' . basename($image['name']);
                        $imagePath = $relativeDir . $imageName;
                        $imageFullPath = $uploadDir . $imageName;
                        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                        if (in_array($image['type'], $allowedTypes) && $image['size'] <= 5 * 1024 * 1024) {
                            move_uploaded_file($image['tmp_name'], $imageFullPath);
                        }
                    }
                    $imagesPaths = [];
                    if (!empty($_FILES['images']['name'][0])) {
                        foreach ($_FILES['images']['name'] as $key => $name) {
                            if ($_FILES['images']['error'][$key] === UPLOAD_ERR_OK) {
                                $imageName = uniqid('tour_extra_') . '_' . basename($name);
                                $imageFullPath = $uploadDir . $imageName;
                                $imageRelativePath = $relativeDir . $imageName;
                                if (in_array($_FILES['images']['type'][$key], $allowedTypes) && $_FILES['images']['size'][$key] <= 5 * 1024 * 1024) {
                                    move_uploaded_file($_FILES['images']['tmp_name'][$key], $imageFullPath);
                                    $imagesPaths[] = $imageRelativePath;
                                }
                            }
                        }
                    }
                    $images = implode(',', $imagesPaths);
                    $conn->begin_transaction();
                    $stmt = $conn->prepare("
                        INSERT INTO travels (title, destination, price, status, image, images, description, start_date, end_date, transport_type, transport_type_en) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("ssdssssssss", $title, $destination, $price, $status, $imagePath, $images, $description, $start_date, $end_date, $transport_type, $transport_type_en);
                    if ($stmt->execute()) {
                        $newTourId = $conn->insert_id;
                        $stmt->close();
                        if (!empty($packages)) {
                            $stmt = $conn->prepare("INSERT INTO tour_packages (tour_id, package_id) VALUES (?, ?)");
                            foreach ($packages as $package_id) {
                                $package_id = (int)$package_id;
                                if ($package_id > 0) {
                                    $stmt->bind_param("ii", $newTourId, $package_id);
                                    $stmt->execute();
                                }
                            }
                            $stmt->close();
                        }
                        $conn->commit();
                        echo json_encode([
                            'success' => true,
                            'message' => 'Tour added successfully',
                            'tour' => [
                                'id' => $newTourId,
                                'title' => $title,
                                'destination' => $destination,
                                'price' => $price,
                                'status' => $status,
                                'image' => $imagePath,
                                'images' => $images,
                                'start_date' => $start_date,
                                'end_date' => $end_date,
                                'transport_type' => $transport_type,
                                'transport_type_en' => $transport_type_en,
                                'packages' => $packages
                            ]
                        ], JSON_UNESCAPED_UNICODE);
                        exit;
                    }
                    $stmt->close();
                    $conn->rollback();
                }
            }
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollback();
        }
        // Пустой муляж вместо сообщения об ошибке
    }
}



function formatStatus($status) {
    switch ($status) {
        case 'confirmed': return 'Подтверждено';
        case 'pending': return 'Ожидание';
        case 'cancelled': return 'Отменено';
        default: return htmlspecialchars($status) ?: 'Неизвестно';
    }
}

function getBookedTours($conn, $user_id) {
    $tours = [];
    if ($user_id) {
        $query = "
            SELECT 
                t.id as travel_id, 
                t.title, 
                t.description, 
                t.start_date, 
                t.end_date, 
                t.image, 
                tb.id as booking_id, 
                tb.created_at as booking_date, 
                tb.status,
                tb.package_id as selected_package_id,
                tb.persons,
                tb.price as booking_price,
                p.name as package_name,
                p.price as package_price,
                GROUP_CONCAT(DISTINCT s.name SEPARATOR ', ') as selected_services
            FROM travels t
            JOIN tour_bookings tb ON t.id = tb.travel_id
            LEFT JOIN packages p ON tb.package_id = p.package_id
            LEFT JOIN package_services ps ON p.package_id = ps.package_id
            LEFT JOIN services s ON ps.service_id = s.id
            WHERE tb.user_id = ?
            GROUP BY tb.id, t.id, t.title, t.description, t.start_date, t.end_date, t.image, tb.created_at, tb.status, tb.package_id, p.name, p.price, tb.persons, tb.price
            ORDER BY tb.created_at DESC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $package_price = 0;
                if (!empty($row['package_price']) && $row['package_price'] !== 'Входит в стоимость тура') {
                    $package_price = is_numeric($row['package_price']) ? (float)$row['package_price'] : 0;
                }
                $row['total_price'] = ((float)$row['booking_price'] + $package_price) * (int)$row['persons'];
                $tours[] = $row;
            }
            $stmt->close();
        }
    }
    return $tours;
}

function getFavoriteTravels($conn, $user_id) {
    $favorites = [];
    if ($user_id) {
        $query = "
            SELECT t.id, t.title, t.description, 
                   t.start_date, t.end_date, t.image, t.price
            FROM travels t
            JOIN bookmarks b ON t.id = b.travel_id
            WHERE b.user_id = ?
            ORDER BY b.created_at DESC
        ";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $favorites[] = $row;
        }
        $stmt->close();
    }
    return $favorites;
}

$bookedTours = getBookedTours($conn, $user_id);
$favoriteTravels = getFavoriteTravels($conn, $user_id);

$packages = [];
$stmt = $conn->prepare("SELECT package_id, name FROM packages ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $packages[] = $row;
}
$stmt->close();

$services = [];
$stmt = $conn->prepare("SELECT id, name FROM services ORDER BY name");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $services[] = $row;
}
$stmt->close();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мой профиль | Travel Agency</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css' rel='stylesheet' />

    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <meta http-equiv="Permissions-Policy" content="interest-cohort=()">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <style>
        :root {
    --primary-color: #3498db;
    --secondary-color: #2c3e50;
    --accent-color: #3498db;
    --light-gray: #f8f9fa;
    --medium-gray: #e9ecef;
    --dark-gray: #6c757d;
    --white: #ffffff;
    --black: #212529;
}
.avatar {
    transition: all 0.3s ease;
    border: 3px solid #fff;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.avatar:hover {
    transform: rotate(5deg) scale(1.1);
    box-shadow: 0 10px 25px rgba(0,0,0,0.2);
}
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    min-height: 100vh;
    background: 
        linear-gradient(rgba(0, 0, 0, 0.3), 
        rgba(0, 0, 0, 0.3)),
        url('https://images.unsplash.com/photo-1506929562872-bb421503ef21?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    margin: 0;
    padding: 0;
    color: var(--black);
}

.dashboard {
    display: grid;
    grid-template-columns: 250px 1fr;
    gap: 20px;
    max-width: 1200px;
    margin: 20px auto;
    padding: 0 20px;
}

.sidebar {
    background-color: var(--white);
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.main-content {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.content-section {
    display: none;
    background-color: var(--white);
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.content-section.active {
    display: block;
}

.welcome-section {
    background-color: var(--white);
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.progress-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.card {
    background-color: var(--white);
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

h1 {
    font-size: 24px;
    margin: 0 0 10px 0;
    color: var(--secondary-color);
}

h2 {
    font-size: 18px;
    margin: 0 0 15px 0;
    color: var(--secondary-color);
}

h3 {
    font-size: 16px;
    margin: 20px 0 10px 0;
    color: var(--secondary-color);
}

.user-info {
    text-align: center;
    margin-bottom: 20px;
}

.avatar {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    object-fit: cover;
    margin-bottom: 10px;
    border: 3px solid var(--primary-color);
}

.username {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 5px;
}

.user-email {
    color: var(--dark-gray);
    font-size: 14px;
}

.menu-item {
    display: flex;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid var(--medium-gray);
    cursor: pointer;
    color: var(--secondary-color);
}

.menu-item:last-child {
    border-bottom: none;
}

.menu-item:hover, .menu-item.active {
    color: var(--primary-color);
}

.menu-icon {
    margin-right: 10px;
    font-size: 16px;
    width: 20px;
    text-align: center;
}

/* Стили для блока моих записей */
#bookings .card, #favorites .card {
    background-color: #fff;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 15px rgba(0,0,0,0.1);
}

.trip-card {
    display: flex;
    margin-bottom: 20px;
    padding: 15px;
    border-radius: 8px;
    background-color: #f8f9fa;
    transition: all 0.3s ease;
    border: 1px solid #e0e0e0;
}

.trip-card:hover {
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transform: translateY(-2px);
    border-color: #3498db;
}

.trip-image {
    width: 120px;
    height: 90px;
    border-radius: 6px;
    object-fit: cover;
    margin-right: 20px;
    border: 1px solid #ddd;
}

.trip-info {
    flex: 1;
    min-width: 0;
}

.trip-title {
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.trip-description {
    font-size: 14px;
    color: #555;
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.trip-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
    margin-top: 10px;
}

.trip-date, .appointment-date {
    display: flex;
    align-items: center;
    font-size: 13px;
    color: #666;
}

.trip-date i, .appointment-date i {
    margin-right: 5px;
    color: #3498db;
}

.status-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
    margin-left: 10px;
}

.status-pending {
    background-color: #FFC107;
    color: #000;
}

.status-confirmed {
    background-color: #4CAF50;
    color: white;
}

.status-cancelled {
    background-color: #F44336;
    color: white;
}

.trip-actions {
    display: flex;
    align-items: center;
    margin-left: 15px;
}


.cancel-btn {
    background-color: #e74c3c;
    color: white;
    border: none;
    padding: 6px 12px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    transition: background 0.2s;
}

.cancel-btn:hover {
    background-color: #c0392b;
}

.no-bookings, .no-favorites {
    text-align: center;
    padding: 40px 20px;
    color: #7f8c8d;
}

.no-bookings i, .no-favorites i {
    font-size: 50px;
    margin-bottom: 15px;
    color: #bdc3c7;
}

.no-bookings p, .no-favorites p {
    font-size: 16px;
    margin-top: 10px;
}

.price-tag {
    background-color: #3498db;
    color: white;
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 14px;
    font-weight: bold;
}

@media (max-width: 768px) {
    .dashboard {
        grid-template-columns: 1fr;
    }
    
    .trip-card {
        flex-direction: column;
    }
    
    .trip-image {
        width: 100%;
        height: 150px;
        margin-right: 0;
        margin-bottom: 15px;
    }
    
    .trip-actions {
        margin-left: 0;
        margin-top: 10px;
        justify-content: flex-end;
    }
}
/* Стили для календаря */
#calendar-container {
max-width: 100%;
margin: 0 auto;
font-family: 'Segoe UI', sans-serif;
}

.fc .fc-toolbar-title {
font-size: 1.2em;
color: #2c3e50;
font-weight: 600;
}
/* Стиль для однодневных событий */
.fc-event-single-day {
width: calc(100% - 6px) !important;
margin: 3px !important;
}

/* Убираем стрелку продолжительности */
.fc-event-single-day:after {
display: none !important;
}

.fc .fc-button {
background-color: #3498db;
border: none;
transition: all 0.3s;
padding: 6px 12px;
}

.fc .fc-button:hover {
background-color: #2980b9;
transform: translateY(-1px);
}

.fc .fc-button-primary:not(:disabled).fc-button-active {
background-color: #2c3e50;
}

.fc .fc-daygrid-day-number {
color: #2c3e50;
font-weight: bold;
}

.fc .fc-day-today {
background-color: rgba(52, 152, 219, 0.1) !important;
}

.fc-event {
cursor: pointer;
font-size: 12px;
border-radius: 4px;
border: none;
padding: 2px 4px;
transition: all 0.2s;
}

.fc-event:hover {
transform: translateY(-1px);
box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}

.fc-event.confirmed {
background-color: #4CAF50;
}

.fc-event.pending {
background-color: #FFC107;
color: #000;
}

.fc-event.cancelled {
background-color: #F44336;
}

.fc-daygrid-event-dot {
display: none;
}

@media (max-width: 768px) {
.fc .fc-toolbar {
    flex-direction: column;
    gap: 10px;
}

.fc .fc-toolbar-chunk {
    display: flex;
    justify-content: center;
    width: 100%;
}

.fc .fc-button {
    padding: 4px 8px;
    font-size: 0.8em;
}

.fc .fc-toolbar-title {
    font-size: 1em;
    text-align: center;
}
}


/* Обновленные стили для статистики */
.user-stats {
    background: #ffffff;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
}

.user-stats h3 {
    margin-top: 0;
    color: #2d3748;
    font-size: 1.4rem;
    padding-bottom: 15px;
    border-bottom: 1px solid #edf2f7;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.stat-card {
    background: #ffffff;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
    position: relative;
    overflow: hidden;
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
    border-color: #cbd5e0;
}

.stat-card i {
    font-size: 28px;
    color: #4c51bf;
    margin-bottom: 15px;
    background: #f0f1ff;
    width: 60px;
    height: 60px;
    line-height: 60px;
    border-radius: 50%;
    display: inline-block;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #2d3748;
    margin: 10px 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.stat-label {
    font-size: 15px;
    color: #718096;
    font-weight: 500;
}


/* Обновленные стили для достижений */
.achievements-section {
    background: #ffffff;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
}

.achievements-section h3 {
    margin-top: 0;
    color: #2d3748;
    font-size: 1.4rem;
    padding-bottom: 15px;
    border-bottom: 1px solid #edf2f7;
}

.achievements-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.achievement {
    display: flex;
    align-items: center;
    padding: 20px;
    border-radius: 10px;
    background: #ffffff;
    transition: all 0.3s ease;
    border: 1px solid #e2e8f0;
    position: relative;
    overflow: hidden;
}

.achievement.unlocked {
    background: linear-gradient(135deg, #f0f4ff 0%, #e0e7ff 100%);
    border-left: 4px solid #4c51bf;
}

.achievement.locked {
    background: #f8fafc;
    filter: grayscale(30%);
    opacity: 0.9;
}

.achievement-icon {
    width: 60px;
    height: 60px;
    background: #4c51bf;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-right: 20px;
    color: white;
    font-size: 24px;
    flex-shrink: 0;
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

.achievement.locked .achievement-icon {
    background: #a0aec0;
}

.achievement-info {
    flex-grow: 1;
}

.achievement-info h4 {
    margin: 0 0 5px 0;
    color: #2d3748;
    font-size: 1.1rem;
}

.achievement-info p {
    margin: 0;
    font-size: 14px;
    color: #4a5568;
}

.achievement-date, .achievement-badge {
    font-size: 13px;
    color: #4c51bf;
    margin-top: 8px;
    display: block;
    font-weight: 500;
}

.achievement-progress {
    font-size: 13px;
    color: #718096;
    margin-top: 8px;
    display: block;
}

/* Анимации */
@keyframes unlockAchievement {
    0% { transform: scale(1); box-shadow: 0 0 0 rgba(76, 81, 191, 0); }
    50% { transform: scale(1.05); box-shadow: 0 0 20px rgba(76, 81, 191, 0.3); }
    100% { transform: scale(1); box-shadow: 0 0 0 rgba(76, 81, 191, 0); }
}

.achievement.unlocked {
    animation: unlockAchievement 0.6s ease;
}
.progress-bar {
    height: 5px;
    background: #e9ecef;
    border-radius: 3px;
    margin-top: 8px;
}
.progress {
    height: 100%;
    background: #4e73df;
    border-radius: 3px;
}
.menu-icon {
    transition: all 0.3s ease;
}

.menu-item:hover .menu-icon {
    transform: translateX(5px);
    color: #4e73df;
}
.travel-animation {
    position: relative;
    height: 200px;
    overflow: hidden;
    margin: 20px 0;
}

.travel-hero {
    position: relative;
    height: 250px;
    margin: 30px 0;
    overflow: hidden;
    border-radius: 12px;
    background: linear-gradient(135deg, #f5f7fa 0%, #e4e8f0 100%);
}






/* admin */


    /* Общие стили для админ-панели */
    .admin-card {
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        padding: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .admin-form, .admin-table-container {
        display: none; /* Скрываем блоки по умолчанию */
        padding: 3rem;
        background: #ffffff;
        border-radius: 0.5rem;
        border: 1px solid #e2e8f0;
        margin-top: 0.5rem;
        animation: slideDown 0.3s ease-out forwards;
    }
    
    .admin-form.show, .admin-table-container.show {
        display: block; /* Показываем при активации */
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    .admin-section {
        margin-bottom: 2rem;
    }

    .admin-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1rem;
    }

    .admin-header h3 {
        font-size: 1.25rem;
        font-weight: 600;
        color: #333;
        margin: 0;
    }

    .toggle-form-btn {
        background: none;
        border: none;
        cursor: pointer;
        color: #666;
        transition: transform 0.3s ease;
    }

    .toggle-form-btn.active i {
        transform: rotate(180deg);
    }

    /* Фильтры */
    .admin-filters {
        display: flex;
        gap: 1.5rem;
        margin-bottom: 1.5rem;
    }

    .filter-group {
        flex: 1;
    }

    .filter-group label {
        display: block;
        font-size: 0.9rem;
        font-weight: 500;
        color: #555;
        margin-bottom: 0.5rem;
    }

    .filter-group input,
    .filter-group select {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        font-size: 0.95rem;
        color: #333;
        background: #f9f9f9;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .filter-group input:focus,
    .filter-group select:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        outline: none;
        background: #fff;
    }

    /* Стили формы */
    .admin-form {
        display: none;
        transition: all 0.3s ease;
    }

    .form-row {
        display: flex;
        gap: 1.5rem;
        margin-bottom: 1rem;
    }

    .form-group {
        flex: 1;
        min-width: 0;
    }

    .form-group label {
        display: block;
        font-size: 0.9rem;
        font-weight: 500;
        color: #555;
        margin-bottom: 0.5rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 0.75rem;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        font-size: 0.95rem;
        color: #333;
        background: #f9f9f9;
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        outline: none;
        background: #fff;
    }

    .form-group textarea {
        min-height: 100px;
        resize: vertical;
    }

    /* Стили таблицы */
    .admin-table table {
        width: 100%;
        border-collapse: collapse;
        font-size: 0.95rem;
    }

    .admin-table th,
    .admin-table td {
        padding: 0.75rem 1rem;
        text-align: left;
        border-bottom: 1px solid #e0e0e0;
    }

    .admin-table th {
        background: #f5f5f5;
        font-weight: 600;
        color: #333;
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
    }

    .admin-table td {
        color: #555;
    }

    .admin-table tr {
        transition: background 0.2s ease;
    }

    .admin-table tr:hover {
        background: #f9f9f9;
    }

    /* Статус */
    .status-badge {
        display: inline-block;
        padding: 0.25rem 0.75rem;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 500;
    }

    .status-active {
        background: #e6f4ea;
        color: #2e7d32;
    }

    .status-upcoming {
        background: #fff3e0;
        color: #f57c00;
    }

    .status-inactive {
        background: #ffebee;
        color: #d32f2f;
    }

    /* Пагинация */
    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 1rem;
        margin-top: 1.5rem;
    }

    .page-info {
        font-size: 0.95rem;
        color: #555;
    }

    .btn-prev,
    .btn-next {
        background: #f5f5f5;
        color: #333;
    }

    .btn-prev:hover,
    .btn-next:hover {
        background: #e0e0e0;
    }

    .btn-prev:disabled,
    .btn-next:disabled {
        background: #f0f0f0;
        color: #aaa;
        cursor: not-allowed;
    }

    /* Кнопки */
    .btn {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem 1rem;
        border: none;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.3s ease, transform 0.1s ease;
    }

    .btn-primary {
        background: #007bff;
        color: #fff;
    }

    .btn-primary:hover {
        background: #0056b3;
        transform: translateY(-1px);
    }

    .btn-edit {
        background: #28a745;
        color: #fff;
    }

    .btn-edit:hover {
        background: #218838;
        transform: translateY(-1px);
    }

    .btn-delete {
        background: #dc3545;
        color: #fff;
    }

    .btn-delete:hover {
        background: #c82333;
        transform: translateY(-1px);
    }

    .btn:active {
        transform: translateY(0);
    }





  /* Стили для меток статуса */
.status-badge {
    display: inline-block;
    padding: 6px 12px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 500;
    text-transform: capitalize;
    cursor: default;

}

.status-badge.clickable {
    cursor: pointer;
}



/* Цвета для статусов */
.status-pending {
    background-color: #ffeeba;
    color: #856404;
}

.status-confirmed {
    background-color: #d4edda;
    color: #155724;
}

.status-cancelled {
    background-color: #f8d7da;
    color: #721c24;
}

/* Стили для кнопки удаления */
.btn-delete {
    background: none;
    border: none;
    color: #7e7e7e;
    font-size: 16px;
    padding: 8px;
    cursor: pointer;

}


/* Общие стили для админ-панели */
.admin-card {
    background: var(--white);
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    padding: 1.5rem;
    margin-bottom: 1.5rem;
}

.admin-section {
    margin-bottom: 1.5rem;
}

.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid var(--medium-gray);
}

.admin-header h3 {
    font-size: 1.2rem;
    font-weight: 600;
    color: var(--secondary-color);
    margin: 0;
}

.toggle-form-btn {
    background: none;
    border: none;
    color: var(--dark-gray);
    font-size: 1rem;
    cursor: pointer;
    transition: transform 0.3s ease, color 0.2s ease;
}

.toggle-form-btn:hover {
    color: var(--primary-color);
}

.toggle-form-btn.active {
    transform: rotate(180deg);
}

/* Фильтры */
.admin-filters {
    display: flex;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1rem;
}

.filter-group {
    flex: 1;
    min-width: 200px;
}

.filter-group label {
    display: block;
    font-size: 0.85rem;
    font-weight: 500;
    color: var(--dark-gray);
    margin-bottom: 0.25rem;
}

.filter-group input,
.filter-group select {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid var(--medium-gray);
    border-radius: 6px;
    font-size: 0.9rem;
    color: var(--black);
    background: var(--white);
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.filter-group input:focus,
.filter-group select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

/* Стили таблицы */
.admin-table table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    background: var(--white);
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.admin-table th,
.admin-table td {
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.9rem;
    color: var(--black);
}

.admin-table th {
    background: var(--light-gray);
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    color: var(--dark-gray);
}

.admin-table td {
    border-bottom: 1px solid var(--medium-gray);
}

.admin-table tr:last-child td {
    border-bottom: none;
}

.admin-table tr {
    transition: background 0.2s ease;
}

.admin-table tr:hover {
    background: rgba(52, 152, 219, 0.05);
}





/* Стили для select элемента изменения статуса */
.status-select {
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    background-color: var(--white);
    border: 1px solid var(--medium-gray);
    border-radius: 6px;
    padding: 8px 24px 8px 12px;
    font-size: 0.9rem;
    color: var(--black);
    width: 140px;
    cursor: pointer;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%236c757d' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
}

/* Ховер эффект */
.status-select:hover {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

/* Фокус */
.status-select:focus {
    outline: none;
    border-color: var(--primary-color);
    box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.2);
}

/* Разные цвета для статусов в выпадающем списке */
.status-select option[value="pending"] {
    background-color: var(--white);
    color: var(--black);
}

.status-select option[value="confirmed"] {
    background-color: var(--white);
    color: var(--black);
}

.status-select option[value="cancelled"] {
    background-color: var(--white);
    color: var(--black);
}

/* Анимация при выборе статуса */
@keyframes statusChange {
    0% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.03);
    }
    100% {
        transform: scale(1);
    }
}

.status-select.changed {
    animation: statusChange 0.3s ease;
}

/* Стили для бейджей статуса в таблице */
.status-badge {
    padding: 5px 10px;
    border-radius: 12px;
    font-size: 0.85rem;
    font-weight: 500;
    display: inline-block;
    transition: background-color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
}

/* Стили для кликабельных бейджей */
.status-badge.clickable {
    cursor: pointer;
}

.status-badge.clickable:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.status-pending {
    background-color: rgba(255, 193, 7, 0.15); /* Легкий желтый фон */
    color: #856404;
}

.status-confirmed {
    background-color: rgba(76, 175, 80, 0.15); /* Легкий зеленый фон */
    color: #155724;
}

.status-cancelled {
    background-color: rgba(244, 67, 54, 0.15); /* Легкий красный фон */
    color: #721c24;
}

/* Мелкие улучшения для таблицы */
.admin-table td {
    padding: 0.75rem 1rem;
    vertical-align: middle;
}

.admin-table td select {
    margin: 0;
}

/* Адаптивность */
@media (max-width: 768px) {
    .status-select {
        width: 120px;
        padding: 6px 20px 6px 10px;
        font-size: 0.85rem;
    }

    .status-badge {
        padding: 4px 8px;
        font-size: 0.8rem;
    }
}


/* Общие стили для секции bookings */
#bookings {
    padding: 24px;
    background: #ffffff;
}

/* Контейнер для карточек */
#bookings .card {
    display: grid;
    gap: 16px;
    max-width: 1000px;
    margin: 0 auto;
}

/* Заголовок секции */
#bookings h2 {
    font-size: 1.75rem;
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 24px;
    text-align: left;
}

/* Стили для trip-card */
.trip-card {
    display: grid;
    grid-template-columns: 120px 1fr auto;
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    overflow: hidden;
    transition: border-color 0.2s ease;
    animation: slideIn 0.4s ease forwards;
}

.trip-card:hover {
    border-color: #007bff;
}

/* Изображение тура */
.trip-image {
    width: 120px;
    height: 100px;
    object-fit: cover;
}

/* Информация о туре */
.trip-info {
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 8px;
}

/* Заголовок тура */
.trip-title {
    font-size: 1.25rem;
    font-weight: 500;
    color: #1a1a1a;
    margin: 0;
    line-height: 1.4;
}

/* Описание тура */
.trip-description {
    font-size: 0.875rem;
    color: #666;
    line-height: 1.5;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Мета-информация */
.trip-meta {
    display: flex;
    flex-direction: column;
    gap: 6px;
    font-size: 0.8125rem;
    color: #4a4a4a;
}

/* Даты и цена */
.trip-date, .appointment-date, .total-price {
    display: flex;
    align-items: center;
    gap: 6px;
}

.trip-date i, .appointment-date i, .total-price i {
    color: #007bff;
    font-size: 0.875rem;
}

/* Статус */
.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    line-height: 1;
}

.status-pending {
    background: #e6f0fa;
    color: #004085;
}

.status-confirmed {
    background: #e6f4ea;
    color: #155724;
}

.status-cancelled {
    background: #f8e9e9;
    color: #721c24;
}

/* Действия */
.trip-actions {
    padding: 16px;
    display: flex;
    align-items: center;
}

/* Кнопка отмены */
.cancel-btn {
    background: transparent;
    color: #dc3545;
    border: 1px solid #dc3545;
    padding: 6px 12px;
    border-radius: 6px;
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease;
    display: flex;
    align-items: center;
    gap: 6px;
}

.cancel-btn:hover {
    background: #dc3545;
    color: #ffffff;
}

.cancel-btn:disabled {
    color: #6c757d;
    border-color: #6c757d;
    cursor: not-allowed;
}

.cancel-btn.loading {
    opacity: 0.6;
}

/* Пустое состояние */
.no-bookings {
    text-align: center;
    padding: 48px;
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    max-width: 400px;
    margin: 0 auto;
}

.no-bookings i {
    font-size: 2rem;
    color: #6c757d;
    margin-bottom: 12px;
}

.no-bookings p {
    font-size: 1rem;
    color: #4a4a4a;
    margin: 0 0 16px;
}

.no-bookings .btn-primary {
    background: #007bff;
    color: #ffffff;
    border: none;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    text-decoration: none;
    transition: background 0.2s ease;
}

.no-bookings .btn-primary:hover {
    background: #0056b3;
}

/* Анимация появления */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Адаптивность */
@media (max-width: 768px) {
    .trip-card {
        grid-template-columns: 1fr;
        grid-template-rows: auto 1fr auto;
    }

    .trip-image {
        width: 100%;
        height: 160px;
    }

    .trip-info {
        padding: 12px;
    }

    .trip-actions {
        padding: 12px;
        justify-content: flex-start;
    }

    .trip-title {
        font-size: 1.125rem;
    }

    .trip-description {
        font-size: 0.8125rem;
    }
}

@media (max-width: 576px) {
    #bookings {
        padding: 16px;
    }

    #bookings h2 {
        font-size: 1.5rem;
    }

    .trip-meta {
        font-size: 0.75rem;
    }

    .cancel-btn {
        width: 100%;
        justify-content: center;
    }
}


/* Общие стили для секции settings */
#settings {
    padding: 24px;
    background: #ffffff;
}

/* Контейнер для формы */
#settings .card {
    max-width: 600px;
    margin: 0 auto;
    background: #ffffff;
    border: 1px solid #e5e5e5;
    border-radius: 8px;
    padding: 24px;
    animation: slideIn 0.4s ease forwards;
}

/* Заголовок секции */
#settings h2 {
    font-size: 1.75rem;
    font-weight: 600;
    color: #1a1a1a;
    margin-bottom: 24px;
    text-align: left;
}

/* Форма профиля */
.profile-form {
    display: flex;
    flex-direction: column;
    gap: 16px;
}

/* Группа полей */
.form-group {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-group label {
    font-size: 0.875rem;
    font-weight: 500;
    color: #1a1a1a;
}

/* Поля ввода */
.form-group input[type="text"],
.form-group input[type="email"],
.form-group input[type="password"] {
    padding: 10px;
    border: 1px solid #e5e5e5;
    border-radius: 6px;
    font-size: 0.875rem;
    color: #1a1a1a;
    background: #fafafa;
    transition: border-color 0.2s ease;
}

.form-group input:focus {
    outline: none;
    border-color: #007bff;
    background: #ffffff;
}

/* Аватар */
.avatar-group {
    align-items: center;
    gap: 12px;
}

.avatar-preview {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    overflow: hidden;
    border: 1px solid #e5e5e5;
}

.avatar-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.form-group input[type="file"] {
    display: none;
}

#upload-avatar-btn {
    background: #f8f9fa;
    color: #007bff;
    border: 1px solid #e5e5e5;
    padding: 8px 16px;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s ease, color 0.2s ease;
}

#upload-avatar-btn:hover {
    background: #007bff;
    color: #ffffff;
}

/* Кнопка сохранения */
.btn-primary {
    background: #007bff;
    color: #ffffff;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s ease;
    align-self: flex-start;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-primary:disabled {
    background: #6c757d;
    cursor: not-allowed;
}

.btn-primary.loading {
    opacity: 0.6;
}

/* Анимация появления */
@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Адаптивность */
@media (max-width: 576px) {
    #settings {
        padding: 16px;
    }

    #settings h2 {
        font-size: 1.5rem;
    }

    #settings .card {
        padding: 16px;
    }

    .btn-primary {
        width: 100%;
        text-align: center;
    }

    .avatar-preview {
        width: 60px;
        height: 60px;
    }
}
        .dashboard-content {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .dashboard-stats {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .stat-card {
            display: flex;
            align-items: center;
            background-color: #fff;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            flex: 1;
            min-width: 200px;
            transition: transform 0.2s ease;
        }
        .stat-card:hover {
            transform: translateY(-3px);
        }
        .stat-icon {
            font-size: 24px;
            color: #3498db;
            margin-right: 15px;
        }
        .stat-info h3 {
            margin: 0;
            font-size: 24px;
            color: #2c3e50;
        }
        .stat-info p {
            margin: 5px 0 0;
            font-size: 14px;
            color: #7f8c8d;
        }
        .dashboard-info {
            background-color: #fff;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }
        .dashboard-info p {
            margin: 0;
            font-size: 16px;
            color: #34495e;
        }
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            overflow-y: auto;
            justify-content: center;
            align-items: center;
        }
        .modal-content {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.2);
            width: 90%;
            max-width: 600px;
            margin: 20px auto;
            padding: 20px;
            position: relative;
            animation: fadeIn 0.3s ease-in-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 24px;
            color: #34495e;
            cursor: pointer;
            transition: color 0.2s ease;
        }
        .modal-close:hover {
            color: #e74c3c;
        }
        .modal-content h3 {
            margin: 0 0 20px;
            font-size: 24px;
            color: #2c3e50;
        }
        .modal-content .admin-form {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .modal-content .form-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }
        .modal-content .form-group {
            flex: 1;
            min-width: 200px;
        }
        .modal-content .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
            color: #34495e;
        }
        .modal-content .form-group input,
        .modal-content .form-group select,
        .modal-content .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .modal-content .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        .modal-content .form-group select[multiple] {
            height: 100px;
        }
        .modal-content .form-group input[type="file"] {
            padding: 5px;
        }
        .modal-content .btn-primary {
            background-color: #3498db;
            color: #fff;
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            transition: background-color 0.2s ease;
        }
        .modal-content .btn-primary:hover {
            background-color: #2980b9;
        }
        @media (max-width: 600px) {
            .modal-content {
                width: 95%;
                padding: 15px;
            }
            .modal-content .form-row {
                flex-direction: column;
                gap: 10px;
            }
            .modal-content .form-group {
                min-width: 100%;
            }
            .modal-close {
                font-size: 20px;
                top: 8px;
                right: 10px;
            }
        }
         .navbar {

    position: fixed !important;
    top: 20px !important;
}
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <h1>iTravel</h1>
            <div class="user-info">
                <img src="/travel/images/user.png" alt="Аватар" class="avatar">
                <div class="username">Привет, <?php echo htmlspecialchars($username); ?>!</div>
                <div class="user-email"><?php echo htmlspecialchars($email); ?></div>
            </div>
            <div class="info-section">
                <h3>Общая информация</h3>
                <div class="menu-item active" data-section="dashboard">
                    <i class="fas fa-home menu-icon"></i>
                    <span>Главная</span>
                </div>
                <div class="menu-item" data-section="bookings">
                    <i class="fas fa-ticket-alt menu-icon"></i>
                    <span>Мои записи</span>
                </div>
                <div class="menu-item" data-section="calendar">
                    <i class="far fa-calendar-alt menu-icon"></i>
                    <span>Календарь</span>
                </div>
            </div>
            <div class="info-section">
                <h3>Настройки</h3>
                <div class="menu-item" data-section="settings">
                    <i class="fas fa-cog menu-icon"></i>
                    <span>Профиль</span>
                </div>
                <?php if ($role === 'admin'): ?>
                <div class="menu-item" data-section="admin-panel">
                    <i class="fas fa-user-shield menu-icon"></i>
                    <span>Админ-панель</span>
                </div>
                <?php endif; ?>
                <div class="menu-item logout-btn" data-action="logout">
                    <i class="fas fa-sign-out-alt menu-icon"></i>
                    <span>Выйти</span>
                </div>
            </div>
        </div>
        <div class="main-content">
            <div id="dashboard" class="content-section active">
                <h2>Добро пожаловать, <?php echo htmlspecialchars($username); ?>!</h2>
                <div class="dashboard-content">
                    <div class="dashboard-stats">
                        <div class="stat-card">
                            <i class="fas fa-ticket-alt stat-icon"></i>
                            <div class="stat-info">
                                <h3><?php echo count($bookedTours); ?></h3>
                                <p>Ваши бронирования</p>
                            </div>
                        </div>
                        <div class="stat-card">
                            <i class="fas fa-heart stat-icon"></i>
                            <div class="stat-info">
                                <h3><?php echo count($favoriteTravels); ?></h3>
                                <p>Избранные туры</p>
                            </div>
                        </div>
                    </div>
                    <div class="dashboard-info card">
                        <p>Ваш профиль — это место, где вы можете управлять своими путешествиями. Ознакомьтесь с вашими бронированиями, добавьте туры в избранное или обновите данные профиля в разделе настроек.</p>
                    </div>
                </div>
            </div>
            <div id="bookings" class="content-section">
                <h2>Мои записи на туры</h2>
                <div class="card">
                    <?php if (empty($bookedTours)): ?>
                        <div class="no-bookings">
                            <i class="fas fa-calendar-times"></i>
                            <p>Вы пока не записаны ни на один тур</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($bookedTours as $tour): ?>
                            <div class="trip-card">
                                <img src="<?php echo !empty($tour['image']) ? htmlspecialchars($tour['image']) : '/travel/uploads/default.jpg'; ?>" 
                                     alt="<?php echo htmlspecialchars($tour['title']); ?>" 
                                     class="trip-image">
                                <div class="trip-info">
                                    <div class="trip-title"><?php echo htmlspecialchars($tour['title']); ?></div>
                                    <?php if (!empty($tour['description'])): ?>
                                    <div class="trip-description">
                                        <?php echo htmlspecialchars($tour['description']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="trip-meta">
                                        <div class="trip-date">
                                            <i class="far fa-calendar-alt"></i>
                                            <?php if (!empty($tour['start_date']) && !empty($tour['end_date'])): ?>
                                                <?php echo date('d.m.Y', strtotime($tour['start_date'])) . ' - ' . date('d.m.Y', strtotime($tour['end_date'])); ?>
                                            <?php else: ?>
                                                Даты не указаны
                                            <?php endif; ?>
                                        </div>
                                        <div class="appointment-date">
                                            <i class="far fa-clock"></i>
                                            <?php echo !empty($tour['booking_date']) ? date('d.m.Y H:i', strtotime($tour['booking_date'])) : 'Дата не указана'; ?>
                                            <span class="status-badge status-<?php echo htmlspecialchars($tour['status']); ?>">
                                                <?php echo formatStatus($tour['status']); ?>
                                            </span>
                                        </div>
                                        <div class="total-price">
                                            <i class="fas fa-ruble-sign"></i>
                                            Общая стоимость: <?php echo number_format($tour['total_price'], 2, ',', ' '); ?> RUB
                                            <?php if (!empty($tour['package_name'])): ?>
                                                (Тур: <?php echo number_format($tour['booking_price'] * $tour['persons'], 2, ',', ' '); ?> RUB
                                                <?php if ($tour['package_price'] !== 'Входит в стоимость тура'): ?>
                                                    + Пакет: <?php echo number_format((is_numeric($tour['package_price']) ? (float)$tour['package_price'] : 0) * $tour['persons'], 2, ',', ' '); ?> RUB
                                                <?php endif; ?>
                                                для <?php echo htmlspecialchars($tour['persons']); ?> чел.)
                                            <?php else: ?>
                                                (Тур: <?php echo number_format($tour['booking_price'] * $tour['persons'], 2, ',', ' '); ?> RUB для <?php echo htmlspecialchars($tour['persons']); ?> чел.)
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($tour['package_name'])): ?>
                                        <div class="trip-package">
                                            <i class="fas fa-box"></i>
                                            Выбранный пакет: <?php echo htmlspecialchars($tour['package_name']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty($tour['selected_services'])): ?>
                                        <div class="trip-services">
                                            <i class="fas fa-concierge-bell"></i>
                                            Услуги в пакете: <?php echo htmlspecialchars($tour['selected_services']); ?>
                                        </div>
                                        <?php endif; ?>
                                        <div class="trip-persons">
                                            <i class="fas fa-users"></i>
                                            Количество человек: <?php echo htmlspecialchars($tour['persons']); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($tour['status'] === 'pending'): ?>
                                <div class="trip-actions">
                                    <button class="cancel-btn" data-booking-id="<?php echo $tour['booking_id']; ?>">
                                        <i class="fas fa-times"></i> Отменить
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div id="favorites" class="content-section">
                <h2>Избранные туры</h2>
                <div class="card">
                    <?php if (empty($favoriteTravels)): ?>
                        <div class="no-favorites">
                            <i class="fas fa-heart-broken"></i>
                            <p>У вас пока нет избранных туров</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($favoriteTravels as $travel): ?>
                            <div class="trip-card">
                                <img src="<?php echo !empty($travel['image']) ? htmlspecialchars($travel['image']) : '/travel/uploads/tours/default.jpg'; ?>" 
                                     alt="<?php echo htmlspecialchars($travel['title']); ?>" 
                                     class="trip-image">
                                <div class="trip-info">
                                    <div class="trip-title"><?php echo htmlspecialchars($travel['title']); ?></div>
                                    <?php if (!empty($travel['description'])): ?>
                                    <div class="trip-description">
                                        <?php echo htmlspecialchars($travel['description']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="trip-meta">
                                        <div class="price-tag">
                                            <?php echo htmlspecialchars($travel['price']); ?> RUB
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div id="calendar" class="content-section">
                <h2>Календарь путешествий</h2>
                <div class="card">
                    <div id="calendar-container"></div>
                </div>
            </div>
            <div id="settings" class="content-section">
                <h2>Настройки профиля</h2>
                <div class="card">
                    <p>Здесь вы можете обновить информацию о профиле.</p>
                    <form id="update-profile-form" class="settings-form" method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                        <div class="form-group">
                            <label for="update_username">Имя пользователя</label>
                            <input type="text" id="update_username" name="update_username" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="update_email">Email</label>
                            <input type="email" id="update_email" name="update_email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="update_password">Новый пароль (оставьте пустым, чтобы не менять)</label>
                            <input type="password" id="update_password" name="update_password">
                        </div>
                        <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                    </form>
                </div>
            </div>
            <?php if ($role === 'admin'): ?>
            <div id="admin-panel" class="content-section">
                <h2>Админ-панель</h2>
                <div class="admin-card">
                    <div class="admin-section">
                        <div class="admin-header">
                            <h3>Добавить новую услугу</h3>
                            <button class="toggle-form-btn" data-target="add-service-form">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <form id="add-service-form" class="admin-form" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="service_name">Название услуги</label>
                                    <input type="text" id="service_name" name="service_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="service_price">Цена (RUB)</label>
                                    <input type="number" id="service_price" name="service_price" step="0.01">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="service_description">Описание</label>
                                <textarea id="service_description" name="service_description"></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Добавить услугу</button>
                        </form>
                    </div>
                    <div class="admin-section">
                        <div class="admin-header">
                            <h3>Добавить новый тур</h3>
                            <button class="toggle-form-btn" data-target="add-tour-form">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <form id="add-tour-form" class="admin-form" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="title">Название</label>
                                    <input type="text" id="title" name="title" required>
                                </div>
                                <div class="form-group">
                                    <label for="destination">Направление</label>
                                    <input type="text" id="destination" name="destination" required>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="price">Цена (RUB)</label>
                                    <input type="number" id="price" name="price" step="0.01" required>
                                </div>
                                <div class="form-group">
                                    <label for="status">Статус</label>
                                    <select id="status" name="status" required>
                                        <option value="active">Доступен</option>
                                        <option value="upcoming">Скоро</option>
                                        <option value="inactive">Недоступен</option>
                                    </select>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="start_date">Дата начала</label>
                                    <input type="date" id="start_date" name="start_date">
                                </div>
                                <div class="form-group">
                                    <label for="end_date">Дата окончания</label>
                                    <input type="date" id="end_date" name="end_date">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="transport_type">Тип транспорта</label>
                                    <select id="transport_type" name="transport_type" required>
                                        <option value="Самолет" data-en="airplane">Самолет</option>
                                        <option value="Поезд" data-en="train">Поезд</option>
                                        <option value="Автобус" data-en="bus">Автобус</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="image">Основное изображение</label>
                                    <input type="file" id="image" name="image" accept="image/*">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="images">Дополнительные изображения</label>
                                    <input type="file" id="images" name="images[]" multiple accept="image/*">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="description">Описание</label>
                                <textarea id="description" name="description"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="packages">Пакеты услуг</label>
                                <select id="packages" name="packages[]" multiple>
                                    <?php foreach ($packages as $package): ?>
                                        <option value="<?php echo htmlspecialchars($package['package_id']); ?>">
                                            <?php echo htmlspecialchars($package['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Добавить тур</button>
                        </form>
                    </div>
                    <div id="edit-tour-modal" class="modal" style="display: none;">
                        <div class="modal-content">
                            <span class="modal-close">×</span>
                            <h3>Редактировать тур</h3>
                            <form id="edit-tour-form" class="admin-form" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="tour_id" id="edit_tour_id">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit_title">Название</label>
                                        <input type="text" id="edit_title" name="title" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="edit_destination">Направление</label>
                                        <input type="text" id="edit_destination" name="destination" required>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit_price">Цена (RUB)</label>
                                        <input type="number" id="edit_price" name="price" step="0.01" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="edit_status">Статус</label>
                                        <select id="edit_status" name="status" required>
                                            <option value="active">Доступен</option>
                                            <option value="upcoming">Скоро</option>
                                            <option value="inactive">Недоступен</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="edit_start_date">Дата начала</label>
                                        <input type="date" id="edit_start_date" name="start_date">
                                    </div>
                                    <div class="form-group">
                                        <label for="edit_end_date">Дата окончания</label>
                                        <input type="date" id="edit_end_date" name="end_date">
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="edit_description">Описание</label>
                                    <textarea id="edit_description" name="description"></textarea>
                                </div>
                                <div class="form-group">
                                    <label for="edit_transport_type">Тип транспорта</label>
                                    <select id="edit_transport_type" name="transport_type" required>
                                        <option value="Самолет" data-en="airplane">Самолет</option>
                                        <option value="Поезд" data-en="train">Поезд</option>
                                        <option value="Автобус" data-en="bus">Автобус</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="edit_image">Основное изображение</label>
                                    <input type="file" id="edit_image" name="image" accept="image/*">
                                </div>
                                <div class="form-group">
                                    <label for="edit_images">Дополнительные изображения</label>
                                    <input type="file" id="edit_images" name="images[]" multiple accept="image/*">
                                </div>
                                <div class="form-group">
                                    <label for="edit_packages">Пакеты услуг</label>
                                    <select id="edit_packages" name="packages[]" multiple>
                                        <?php foreach ($packages as $package): ?>
                                            <option value="<?php echo htmlspecialchars($package['package_id']); ?>">
                                                <?php echo htmlspecialchars($package['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary">Сохранить изменения</button>
                            </form>
                        </div>
                    </div>
                    <div class="admin-section">
                        <div class="admin-header">
                            <h3>Добавить новый пакет услуг</h3>
                            <button class="toggle-form-btn" data-target="add-package-form">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <form id="add-package-form" class="admin-form" method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="package_name">Название пакета</label>
                                    <input type="text" id="package_name" name="package_name" required>
                                </div>
                                <div class="form-group">
                                    <label for="package_price">Цена (RUB)</label>
                                    <input type="text" id="package_price" name="package_price">
                                </div>
                            </div>
                            <div class="form-group">
                                <label for="package_description">Описание</label>
                                <textarea id="package_description" name="package_description"></textarea>
                            </div>
                            <div class="form-group">
                                <label for="package_services">Услуги в пакете</label>
                                <select id="package_services" name="package_services[]" multiple>
                                    <?php foreach ($services as $service): ?>
                                        <option value="<?php echo htmlspecialchars($service['id']); ?>">
                                            <?php echo htmlspecialchars($service['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-primary">Добавить пакет</button>
                        </form>
                    </div>
                    <div class="admin-section">
                        <div class="admin-header">
                            <h3>Список туров</h3>
                            <button class="toggle-form-btn" data-target="tours-table-container">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div id="tours-table-container" class="admin-table-container">
                            <div class="admin-filters">
                                <div class="form-group">
                                    <label for="search-tour">Поиск:</label>
                                    <input type="text" id="search-tour" placeholder="Название или направление">
                                </div>
                                <div class="form-group">
                                    <label for="filter-status">Статус:</label>
                                    <select id="filter-status">
                                        <option value="">Все</option>
                                        <option value="active">Доступен</option>
                                        <option value="upcoming">Скоро</option>
                                        <option value="inactive">Недоступен</option>
                                    </select>
                                </div>
                            </div>
                            <div class="admin-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Название</th>
                                            <th>Направление</th>
                                            <th>Цена</th>
                                            <th>Дата начала</th>
                                            <th>Дата окончания</th>
                                            <th>Статус</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tours-table-body">
                                        <?php
                                        $query = "SELECT id, title, destination, price, start_date, end_date, status FROM travels ORDER BY id DESC";
                                        $result = $conn->query($query);
                                        while ($tour = $result->fetch_assoc()):
                                        ?>
                                            <tr data-id="<?php echo $tour['id']; ?>">
                                                <td><?php echo htmlspecialchars($tour['id']); ?></td>
                                                <td><?php echo htmlspecialchars($tour['title']); ?></td>
                                                <td><?php echo htmlspecialchars($tour['destination']); ?></td>
                                                <td><?php echo number_format($tour['price'], 2, ',', ' '); ?> RUB</td>
                                                <td><?php echo $tour['start_date'] ? htmlspecialchars(date('d.m.Y', strtotime($tour['start_date']))) : '-'; ?></td>
                                                <td><?php echo $tour['end_date'] ? htmlspecialchars(date('d.m.Y', strtotime($tour['end_date']))) : '-'; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo htmlspecialchars($tour['status']); ?>">
                                                        <?php echo htmlspecialchars(ucfirst($tour['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button class="btn btn-edit" data-id="<?php echo htmlspecialchars($tour['id']); ?>">
                                                        <i class="fas fa-edit"></i> Редактировать
                                                    </button>
                                                    <button class="btn btn-delete" data-id="<?php echo htmlspecialchars($tour['id']); ?>">
                                                        <i class="fas fa-trash"></i> Удалить
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="pagination">
                                <button class="btn btn-prev" disabled>Назад</button>
                                <span class="page-info">Страница <span id="current-page">1</span> из <span id="total-pages">1</span></span>
                                <button class="btn btn-next">Вперёд</button>
                            </div>
                        </div>
                    </div>
                    <div class="admin-section">
                        <div class="admin-header">
                            <h3>Записи пользователей на туры</h3>
                            <button class="toggle-form-btn" data-target="bookings-table-container">
                                <i class="fas fa-chevron-down"></i>
                            </button>
                        </div>
                        <div id="bookings-table-container" class="admin-table-container">
                            <div class="admin-filters">
                                <div class="form-group">
                                    <label for="search-booking">Поиск:</label>
                                    <input type="text" id="search-booking" placeholder="Название тура или имя пользователя">
                                </div>
                                <div class="form-group">
                                    <label for="filter-booking-status">Статус:</label>
                                    <select id="filter-booking-status">
                                        <option value="">Все</option>
                                        <option value="pending">Ожидание</option>
                                        <option value="confirmed">Подтверждено</option>
                                        <option value="cancelled">Отменено</option>
                                    </select>
                                </div>
                            </div>
                            <div class="admin-table">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>ID записи</th>
                                            <th>Название тура</th>
                                            <th>Пользователь</th>
                                            <th>Дата бронирования</th>
                                            <th>Статус</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody id="bookings-table-body">
                                        <?php
                                        $query = "
                                            SELECT tb.id, t.title, u.username, tb.created_at as booking_date, tb.status 
                                            FROM tour_bookings tb
                                            JOIN travels t ON tb.travel_id = t.id
                                            JOIN users u ON tb.user_id = u.id
                                            ORDER BY tb.created_at DESC
                                        ";
                                        $result = $conn->query($query);
                                        while ($booking = $result->fetch_assoc()):
                                        ?>
                                            <tr data-id="<?php echo $booking['id']; ?>">
                                                <td><?php echo htmlspecialchars($booking['id']); ?></td>
                                                <td><?php echo htmlspecialchars($booking['title']); ?></td>
                                                <td><?php echo htmlspecialchars($booking['username']); ?></td>
                                                <td><?php echo $booking['booking_date'] ? htmlspecialchars(date('d.m.Y H:i', strtotime($booking['booking_date']))) : '-'; ?></td>
                                                <td>
                                                    <span class="status-badge status-<?php echo htmlspecialchars($booking['status']); ?>">
                                                        <?php echo htmlspecialchars(formatStatus($booking['status'])); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <select class="status-select" data-id="<?php echo $booking['id']; ?>">
                                                        <option value="pending" <?php echo $booking['status'] === 'pending' ? 'selected' : ''; ?>>Ожидание</option>
                                                        <option value="confirmed" <?php echo $booking['status'] === 'confirmed' ? 'selected' : ''; ?>>Подтверждено</option>
                                                        <option value="cancelled" <?php echo $booking['status'] === 'cancelled' ? 'selected' : ''; ?>>Отменено</option>
                                                    </select>
                                                    <button class="btn btn-delete" data-id="<?php echo $booking['id']; ?>">
                                                        <i class="fas fa-trash"></i> Удалить
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="pagination">
                                <button class="btn btn-prev" disabled>Назад</button>
                                <span class="page-info">Страница <span id="current-booking-page">1</span> из <span id="total-booking-pages">1</span></span>
                                <button class="btn btn-next">Вперёд</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js'></script>
    <script src='https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/locales/ru.min.js'></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
document.addEventListener('DOMContentLoaded', function() {
    function showTourDetails(info) {
        const event = info.event;
        const statusText = {
            'confirmed': '✅ Подтверждено',
            'pending': '🕒 Ожидание',
            'cancelled': '❌ Отменено'
        };
        const startDate = event.start ? new Date(event.start) : null;
        const endDate = event.end ? new Date(event.end) : null;
        if (endDate) endDate.setDate(endDate.getDate() - 1);
        let dateText = 'Даты не указаны';
        if (startDate && endDate) {
            dateText = `${startDate.toLocaleDateString('ru-RU')} — ${endDate.toLocaleDateString('ru-RU')}`;
        }
        Swal.fire({
            title: event.title || 'Тур без названия',
            html: `
                <div style="text-align: left;">
                    <p><b>Статус:</b> ${statusText[event.extendedProps.status] || 'Неизвестно'}</p>
                    <p><b>Даты:</b> ${dateText}</p>
                    <p>${event.extendedProps.description || 'Описание отсутствует'}</p>
                    ${event.extendedProps.image ? 
                        `<img src="${event.extendedProps.image}" 
                              style="max-width: 100%; border-radius: 8px; margin-top: 10px; max-height: 200px; object-fit: cover;">` 
                        : ''}
                </div>
            `,
            confirmButtonText: 'Закрыть'
        });
    }

    // Обработка кнопки выхода
    const logoutBtn = document.querySelector('.logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', function() {
            Swal.fire({
                title: 'Вы уверены?',
                text: 'Вы хотите выйти из системы?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Да, выйти',
                cancelButtonText: 'Нет'
            }).then(result => {
                if (result.isConfirmed) {
                    fetch('', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: JSON.stringify({
                            action: 'logout',
                            csrf_token: document.querySelector('meta[name="csrf-token"]').content
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('Успех!', data.message, 'success').then(() => {
                                window.location.href = '/travel/login.php';
                            });
                        }
                    })
                    .catch(() => {});
                }
            });
        });
    }

    // Обработка меню
    const menuItems = document.querySelectorAll('.menu-item');
    menuItems.forEach(item => {
        item.addEventListener('click', function() {
            menuItems.forEach(i => i.classList.remove('active'));
            this.classList.add('active');
            document.querySelectorAll('.content-section').forEach(section => {
                section.classList.remove('active');
            });
            const sectionId = this.getAttribute('data-section');
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.classList.add('active');
                if (sectionId === 'calendar') {
                    initCalendar();
                }
            }
        });
    });

    // Обработка формы обновления профиля
    const updateProfileForm = document.getElementById('update-profile-form');
    if (updateProfileForm) {
        updateProfileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const data = {
                action: 'update_profile',
                csrf_token: formData.get('csrf_token'),
                username: formData.get('update_username'),
                email: formData.get('update_email'),
                password: formData.get('update_password')
            };
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Успех!', data.message, 'success').then(() => {
                        document.querySelector('.username').textContent = `Привет, ${data.user.username}!`;
                        document.querySelector('.user-email').textContent = data.user.email;
                    });
                }
            })
            .catch(() => {});
        });
    }

    // Обработка кнопок отмены бронирований
    const cancelButtons = document.querySelectorAll('.cancel-btn');
    cancelButtons.forEach(button => {
        button.addEventListener('click', function() {
            const bookingId = this.getAttribute('data-booking-id');
            if (bookingId) {
                Swal.fire({
                    title: 'Вы уверены?',
                    text: 'Вы хотите отменить это бронирование?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Да, отменить',
                    cancelButtonText: 'Нет'
                }).then(result => {
                    if (result.isConfirmed) {
                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                action: 'cancel_booking',
                                booking_id: bookingId,
                                csrf_token: document.querySelector('meta[name="csrf-token"]').content
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire('Успех!', data.message, 'success').then(() => {
                                    const tripCard = button.closest('.trip-card');
                                    if (tripCard) tripCard.remove();
                                });
                            }
                        })
                        .catch(() => {});
                    }
                });
            }
        });
    });

    // Инициализация календаря
    function initCalendar() {
        const calendarEl = document.getElementById('calendar-container');
        if (!calendarEl) return;
        const bookedTours = <?php echo json_encode($bookedTours); ?> || [];
        const calendarEvents = bookedTours.map(tour => {
            let startDate = tour.start_date ? new Date(tour.start_date) : null;
            if (!startDate || isNaN(startDate)) return null;
            return {
                title: tour.title || 'Тур без названия',
                start: startDate,
                end: tour.end_date ? new Date(tour.end_date) : null,
                className: tour.status || 'pending',
                allDay: true,
                extendedProps: {
                    description: tour.description || '',
                    status: tour.status || 'pending',
                    image: tour.image || '',
                    booking_date: tour.booking_date || ''
                }
            };
        }).filter(event => event !== null);
        const calendar = new FullCalendar.Calendar(calendarEl, {
            locale: 'ru',
            initialView: 'dayGridMonth',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay'
            },
            events: calendarEvents,
            eventClick: showTourDetails,
            firstDay: 1,
            navLinks: true,
            nowIndicator: true
        });
        calendar.render();
        window.calendar = calendar;
        setTimeout(() => calendar.updateSize(), 300);
    }

    // Обработка формы добавления услуги
    const addServiceForm = document.getElementById('add-service-form');
    if (addServiceForm) {
        addServiceForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const data = {
                action: 'add_service',
                csrf_token: formData.get('csrf_token'),
                name: formData.get('service_name'),
                description: formData.get('service_description'),
                price: parseFloat(formData.get('service_price')) || 0
            };
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Успех!', data.message, 'success').then(() => {
                        this.reset();
                    });
                }
            })
            .catch(() => {});
        });
    }

    // Обработка формы добавления пакета услуг
    const addPackageForm = document.getElementById('add-package-form');
    if (addPackageForm) {
        addPackageForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const services = Array.from(this.querySelector('#package_services').selectedOptions).map(option => option.value);
            const data = {
                action: 'add_package',
                csrf_token: formData.get('csrf_token'),
                name: formData.get('package_name'),
                description: formData.get('package_description'),
                price: formData.get('package_price') || 'Входит в стоимость тура',
                services: services
            };
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Успех!', data.message, 'success').then(() => {
                        this.reset();
                        const packageSelects = document.querySelectorAll('#packages, #edit_packages');
                        packageSelects.forEach(select => {
                            const option = new Option(data.package.name, data.package.id);
                            select.appendChild(option);
                        });
                    });
                }
            })
            .catch(() => {});
        });
    }

    // Обработка формы добавления тура
    const addTourForm = document.getElementById('add-tour-form');
    if (addTourForm) {
        addTourForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Обработка...';
            submitBtn.disabled = true;
            const formData = new FormData(this);
            const packages = Array.from(this.querySelector('#packages').selectedOptions).map(option => option.value);
            formData.append('packages', JSON.stringify(packages));
            formData.append('action', 'add_tour');
            formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
            const selectedTransport = this.querySelector('#transport_type');
            if (selectedTransport) {
                const selectedOption = selectedTransport.options[selectedTransport.selectedIndex];
                if (selectedOption) {
                    formData.append('transport_type_en', selectedOption.dataset.en || '');
                }
            }
            fetch('', {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Успех!', data.message, 'success').then(() => {
                        this.reset();
                        window.location.reload();
                    });
                }
            })
            .catch(() => {})
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    }

    // Обработка формы редактирования тура
    const editTourForm = document.getElementById('edit-tour-form');
    if (editTourForm) {
        editTourForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            const data = {
                action: 'edit_tour',
                csrf_token: formData.get('csrf_token'),
                id: formData.get('tour_id'),
                title: formData.get('title'),
                destination: formData.get('destination'),
                price: parseFloat(formData.get('price')) || 0,
                status: formData.get('status'),
                description: formData.get('description'),
                start_date: formData.get('start_date') || null,
                end_date: formData.get('end_date') || null,
                transport_type: formData.get('transport_type'),
                transport_type_en: document.querySelector('#edit_transport_type option:checked').dataset.en || ''
            };
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('Успех!', data.message, 'success').then(() => {
                        const toursTableBody = document.getElementById('tours-table-body');
                        if (toursTableBody) {
                            const row = toursTableBody.querySelector(`tr[data-id="${data.tour.id}"]`);
                            if (row) {
                                row.innerHTML = `
                                    <td>${data.tour.id}</td>
                                                                        <td>${data.tour.id}</td>
                                    <td>${data.tour.title}</td>
                                    <td>${data.tour.destination}</td>
                                    <td>${parseFloat(data.tour.price).toLocaleString('ru-RU', { minimumFractionDigits: 2 })} RUB</td>
                                    <td>${data.tour.start_date ? new Date(data.tour.start_date).toLocaleDateString('ru-RU') : '-'}</td>
                                    <td>${data.tour.end_date ? new Date(data.tour.end_date).toLocaleDateString('ru-RU') : '-'}</td>
                                    <td><span class="status-badge status-${data.tour.status}">${data.tour.status.charAt(0).toUpperCase() + data.tour.status.slice(1)}</span></td>
                                    <td>
                                        <button class="btn btn-edit" data-id="${data.tour.id}">
                                            <i class="fas fa-edit"></i> Редактировать
                                        </button>
                                        <button class="btn btn-delete" data-id="${data.tour.id}">
                                            <i class="fas fa-trash"></i> Удалить
                                        </button>
                                    </td>
                                `;
                            }
                        }
                        const modal = document.getElementById('edit-tour-modal');
                        if (modal) {
                            modal.style.display = 'none';
                        }
                    });
                }
            })
            .catch(() => {}); // Пустой муляж вместо сообщения об ошибке
        });
    }

    // Обработка кнопок редактирования тура
    document.addEventListener('click', function(e) {
        if (e.target.closest('.btn-edit')) {
            const button = e.target.closest('.btn-edit');
            const tourId = button.getAttribute('data-id');
            if (tourId) {
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'get_tour',
                        id: tourId,
                        csrf_token: document.querySelector('meta[name="csrf-token"]').content
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.tour) {
                        const tour = data.tour;
                        document.getElementById('edit_tour_id').value = tour.id;
                        document.getElementById('edit_title').value = tour.title || '';
                        document.getElementById('edit_destination').value = tour.destination || '';
                        document.getElementById('edit_price').value = tour.price || 0;
                        document.getElementById('edit_status').value = tour.status || 'inactive';
                        document.getElementById('edit_description').value = tour.description || '';
                        document.getElementById('edit_start_date').value = tour.start_date || '';
                        document.getElementById('edit_end_date').value = tour.end_date || '';
                        document.getElementById('edit_transport_type').value = tour.transport_type || '';

                        // Обновление множественного выбора пакетов
                        const packageSelect = document.getElementById('edit_packages');
                        if (packageSelect) {
                            Array.from(packageSelect.options).forEach(option => {
                                option.selected = tour.packages.includes(option.value);
                            });
                        }

                        const modal = document.getElementById('edit-tour-modal');
                        if (modal) {
                            modal.style.display = 'flex';
                        }
                    }
                })
                .catch(() => {}); // Пустой муляж вместо сообщения об ошибке
            }
        }
    });

    // Обработка кнопок удаления тура
    document.addEventListener('click', function(e) {
        if (e.target.closest('.btn-delete')) {
            const button = e.target.closest('.btn-delete');
            const tourId = button.getAttribute('data-id');
            if (tourId) {
                Swal.fire({
                    title: 'Вы уверены?',
                    text: 'Вы хотите удалить этот тур?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Да, удалить',
                    cancelButtonText: 'Нет'
                }).then(result => {
                    if (result.isConfirmed) {
                        fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: JSON.stringify({
                                    action: 'delete_tour',
                                    id: tourId,
                                    csrf_token: document.querySelector('meta[name="csrf-token"]').content
                                })
                            })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                Swal.fire('Успех!', data.message, 'success').then(() => {
                                    const row = button.closest('tr');
                                    if (row) {
                                        row.remove();
                                    }
                                });
                            }
                        })
                        .catch(() => {}); // Пустой муляж вместо сообщения об ошибке
                    }
                });
            }
        }
    });

    // Закрытие модального окна
    const modalClose = document.querySelector('.modal-close');
    if (modalClose) {
        modalClose.addEventListener('click', function() {
            const modal = document.getElementById('edit-tour-modal');
            if (modal) {
                modal.style.display = 'none';
            }
        });
    }

    // Обработка изменения статуса бронирования
    const statusSelects = document.querySelectorAll('.status-select');
    statusSelects.forEach(select => {
        select.addEventListener('change', function() {
            const bookingId = this.getAttribute('data-id');
            const newStatus = this.value;
            if (bookingId && newStatus) {
                fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        action: 'update_booking_status',
                        booking_id: bookingId,
                        status: newStatus,
                        csrf_token: document.querySelector('meta[name="csrf-token"]').content
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        Swal.fire('Успех!', data.message, 'success').then(() => {
                            const statusBadge = this.closest('tr').querySelector('.status-badge');
                            if (statusBadge) {
                                statusBadge.className = `status-badge status-${newStatus}`;
                                statusBadge.textContent = {
                                    pending: 'Ожидание',
                                    confirmed: 'Подтверждено',
                                    cancelled: 'Отменено'
                                }[newStatus] || newStatus;
                            }
                        });
                    }
                })
                .catch(() => {}); // Пустой муляж вместо сообщения об ошибке
            }
        });
    });

    // Обработка фильтров и пагинации таблицы туров
    const searchTourInput = document.getElementById('search-tour');
    const filterStatusSelect = document.getElementById('filter-status');
    let currentPage = 1;

    function updateToursTable() {
        const search = searchTourInput ? searchTourInput.value.toLowerCase() : '';
        const status = filterStatusSelect ? filterStatusSelect.value : '';
        const rows = document.querySelectorAll('#tours-table-body tr');
        let visibleRows = 0;

        rows.forEach(row => {
            const title = row.children[1].textContent.toLowerCase();
            const destination = row.children[2].textContent.toLowerCase();
            const rowStatus = row.children[6].querySelector('.status-badge').className.includes(status) || !status;
            const matchesSearch = title.includes(search) || destination.includes(search);
            row.style.display = (matchesSearch && rowStatus) ? '' : 'none';
            if (matchesSearch && rowStatus) visibleRows++;
        });

        const totalPages = Math.ceil(visibleRows / 10);
        document.getElementById('total-pages').textContent = totalPages || 1;
        document.getElementById('current-page').textContent = currentPage;
        document.querySelector('.btn-prev').disabled = currentPage === 1;
        document.querySelector('.btn-next').disabled = currentPage >= totalPages;
    }

    if (searchTourInput) {
        searchTourInput.addEventListener('input', updateToursTable);
    }
    if (filterStatusSelect) {
        filterStatusSelect.addEventListener('change', updateToursTable);
    }

    document.querySelector('.btn-prev').addEventListener('click', () => {
        if (currentPage > 1) {
            currentPage--;
            updateToursTable();
        }
    });

    document.querySelector('.btn-next').addEventListener('click', () => {
        const totalPages = parseInt(document.getElementById('total-pages').textContent);
        if (currentPage < totalPages) {
            currentPage++;
            updateToursTable();
        }
    });

    // Обработка сворачивания/разворачивания форм
    const toggleButtons = document.querySelectorAll('.toggle-form-btn');
    toggleButtons.forEach(button => {
        button.addEventListener('click', function() {
            const targetId = this.getAttribute('data-target');
            const target = document.getElementById(targetId);
            if (target) {
                target.style.display = target.style.display === 'none' || !target.style.display ? 'block' : 'none';
                const icon = this.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-chevron-down');
                    icon.classList.toggle('fa-chevron-up');
                }
            }
        });
    });
});
</script>
</body>
</html>
</xaiArtifact>
