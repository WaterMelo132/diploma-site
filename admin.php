<?php
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once('config.php');

// Обработка ошибок базы данных
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Кэширование статистики (временное, хранится 5 минут)
$cache_file = 'cache/admin_stats.json';
$cache_time = 300; // 5 минут

function getCachedData($cache_file, $cache_time) {
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
        return json_decode(file_get_contents($cache_file), true);
    }
    return false;
}

function saveCacheData($cache_file, $data) {
    $cache_dir = dirname($cache_file);
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    if (!is_writable($cache_dir)) {
        error_log("Cache directory ($cache_dir) is not writable.");
        return false;
    }
    return file_put_contents($cache_file, json_encode($data));
}

// Маппинг типов транспорта
$transport_mapping = [
    'автобус' => 'bus',
    'поезд' => 'train',
    'самолет' => 'airplane'
];

try {
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        die("Ошибка: Пользователь не найден.");
    }

    $error = isset($_GET['error']) ? urldecode($_GET['error']) : '';
    $success = isset($_GET['success']) ? urldecode($_GET['success']) : '';

    // Обработка AJAX-запросов для пакетов
    if (isset($_GET['action']) && $_GET['action'] === 'get_tour_packages' && isset($_GET['id'])) {
        header('Content-Type: application/json');
        $tour_id = (int)$_GET['id'];
        $stmt = $conn->prepare("SELECT package_id FROM tour_packages WHERE tour_id = ?");
        $stmt->bind_param('i', $tour_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $package_ids = [];
        while ($row = $result->fetch_assoc()) {
            $package_ids[] = $row['package_id'];
        }
        echo json_encode(['success' => true, 'package_ids' => $package_ids]);
        exit;
    }

    // Обработка добавления тура
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_tour'])) {
        $title = trim($_POST['title'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $image = trim($_POST['image'] ?? '');
        $images = trim($_POST['images'] ?? '');
        $transport_type = trim($_POST['transport_type'] ?? '');
        $transport_details = trim($_POST['transport_details'] ?? '');
        $package_ids = isset($_POST['package_ids']) ? array_map('intval', $_POST['package_ids']) : [];

        $transport_type_en = $transport_mapping[$transport_type] ?? 'unknown';

        if (empty($title) || empty($destination) || $price <= 0 || empty($start_date) || empty($end_date) || empty($status) || empty($transport_type)) {
            $error = 'Все обязательные поля для тура должны быть заполнены.';
        } elseif (!in_array($status, ['active', 'inactive'])) {
            $error = 'Неверный статус тура.';
        } elseif (strtotime($start_date) === false || strtotime($end_date) === false) {
            $error = 'Неверный формат даты.';
        } elseif (strtotime($start_date) > strtotime($end_date)) {
            $error = 'Дата начала не может быть позже даты окончания.';
        } elseif (!array_key_exists($transport_type, $transport_mapping)) {
            $error = 'Неверный тип транспорта.';
        } else {
            if (!empty($image) && !filter_var($image, FILTER_VALIDATE_URL)) {
                $error = 'Неверный формат URL для основного фото.';
            } else {
                $images_array = array_filter(array_map('trim', explode(',', $images)));
                foreach ($images_array as $photo) {
                    if (!empty($photo) && !filter_var($photo, FILTER_VALIDATE_URL)) {
                        $error = 'Неверный формат URL для дополнительного фото: ' . htmlspecialchars($photo);
                        break;
                    }
                }
                if (empty($error)) {
                    $images_string = implode(',', $images_array);
                    $stmt = $conn->prepare("INSERT INTO travels (title, destination, price, description, start_date, end_date, status, image, images, transport_type, transport_type_en, transport_details) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                   $stmt->bind_param('ssdsssssssss', $title, $destination, $price, $description, $start_date, $end_date, $status, $image, $images_string, $transport_type, $transport_type_en, $transport_details);
                    $stmt->execute();
                    $tour_id = $conn->insert_id;
                    $stmt->close();

                    if (!empty($package_ids)) {
                        $stmt = $conn->prepare("INSERT INTO tour_packages (tour_id, package_id) VALUES (?, ?)");
                        foreach ($package_ids as $package_id) {
                            $stmt->bind_param('ii', $tour_id, $package_id);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }
                    $success = 'Тур успешно добавлен.';
                    header('Location: admin.php?success=' . urlencode($success));
                    exit;
                }
            }
        }
    }

    // Обработка редактирования тура
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_tour'])) {
        $tour_id = (int)($_POST['tour_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $status = trim($_POST['status'] ?? '');
        $image = trim($_POST['image'] ?? '');
        $images = trim($_POST['images'] ?? '');
        $transport_type = trim($_POST['transport_type'] ?? '');
        $transport_details = trim($_POST['transport_details'] ?? '');
        $package_ids = isset($_POST['package_ids']) ? array_map('intval', $_POST['package_ids']) : [];

        $transport_type_en = $transport_mapping[$transport_type] ?? 'unknown';

        if (empty($title) || empty($destination) || $price <= 0 || empty($start_date) || empty($end_date) || empty($status) || empty($transport_type)) {
            $error = 'Все обязательные поля для тура должны быть заполнены.';
        } elseif (!in_array($status, ['active', 'inactive'])) {
            $error = 'Неверный статус тура.';
        } elseif (strtotime($start_date) === false || strtotime($end_date) === false) {
            $error = 'Неверный формат даты.';
        } elseif (strtotime($start_date) > strtotime($end_date)) {
            $error = 'Дата начала не может быть позже даты окончания.';
        } elseif (!array_key_exists($transport_type, $transport_mapping)) {
            $error = 'Неверный тип транспорта.';
        } else {
            if (!empty($image) && !filter_var($image, FILTER_VALIDATE_URL)) {
                $error = 'Неверный формат URL для основного фото.';
            } else {
                $images_array = array_filter(array_map('trim', explode(',', $images)));
                foreach ($images_array as $photo) {
                    if (!empty($photo) && !filter_var($photo, FILTER_VALIDATE_URL)) {
                        $error = 'Неверный формат URL для дополнительного фото: ' . htmlspecialchars($photo);
                        break;
                    }
                }
                if (empty($error)) {
                    $images_string = implode(',', $images_array);
                    $stmt = $conn->prepare("UPDATE travels SET title = ?, destination = ?, price = ?, description = ?, start_date = ?, end_date = ?, status = ?, image = ?, images = ?, transport_type = ?, transport_type_en = ?, transport_details = ? WHERE id = ?");
                    $stmt->bind_param('ssdsssssssssi', $title, $destination, $price, $description, $start_date, $end_date, $status, $image, $images_string, $transport_type, $transport_type_en, $transport_details, $tour_id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM tour_packages WHERE tour_id = ?");
                    $stmt->bind_param('i', $tour_id);
                    $stmt->execute();
                    $stmt->close();

                    if (!empty($package_ids)) {
                        $stmt = $conn->prepare("INSERT INTO tour_packages (tour_id, package_id) VALUES (?, ?)");
                        foreach ($package_ids as $package_id) {
                            $stmt->bind_param('ii', $tour_id, $package_id);
                            $stmt->execute();
                        }
                        $stmt->close();
                    }
                    $success = 'Тур успешно обновлен.';
                    header('Location: admin.php?success=' . urlencode($success));
                    exit;
                }
            }
        }
    }

    // Обработка удаления тура
    if (isset($_GET['delete_tour']) && isset($_GET['tour_id'])) {
        $tour_id = (int)$_GET['tour_id'];
        $stmt = $conn->prepare("SELECT COUNT(*) as booking_count FROM tour_bookings WHERE travel_id = ?");
        $stmt->bind_param('i', $tour_id);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        $booking_count = $result['booking_count'];
        $stmt->close();

        if ($booking_count > 0) {
            $error_message = "Нельзя удалить тур: на него зарегистрировано $booking_count бронирований.";
            header('Location: admin.php?error=' . urlencode($error_message));
            exit;
        } else {
            $stmt = $conn->prepare("DELETE FROM tour_packages WHERE tour_id = ?");
            $stmt->bind_param('i', $tour_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM travels WHERE id = ?");
            $stmt->bind_param('i', $tour_id);
            $stmt->execute();
            $stmt->close();
            header('Location: admin.php?success=' . urlencode('Тур успешно удален.'));
            exit;
        }
    }

    // Обработка редактирования пользователя
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $role = trim($_POST['role'] ?? '');

        if (empty($username) || empty($email) || empty($role)) {
            $error = 'Все поля обязательны для пользователя.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Неверный формат email.';
        } elseif (!in_array($role, ['admin', 'user'])) {
            $error = 'Неверная роль пользователя.';
        } else {
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param('si', $email, $user_id);
            $stmt->execute();
            if ($stmt->get_result()->fetch_assoc()) {
                $error = 'Этот email уже используется.';
            } else {
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
                $stmt->bind_param('sssi', $username, $email, $role, $user_id);
                $stmt->execute();
                $success = 'Пользователь успешно обновлен.';
                header('Location: admin.php?success=' . urlencode($success));
                exit;
            }
            $stmt->close();
        }
    }

    // Обработка редактирования бронирования
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_booking'])) {
        $booking_id = (int)($_POST['booking_id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $persons = (int)($_POST['persons'] ?? 0);
        $package_id = (int)($_POST['package_id'] ?? 0);

        if (empty($status) || $persons <= 0 || $package_id <= 0) {
            $error = 'Все поля обязательны, и количество человек должно быть больше 0.';
        } elseif (!in_array($status, ['pending', 'confirmed', 'cancelled'])) {
            $error = 'Неверный статус бронирования.';
        } else {
            $stmt = $conn->prepare("SELECT price FROM packages WHERE id = ?");
            $stmt->bind_param('i', $package_id);
            $stmt->execute();
            $package_result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$package_result) {
                $error = 'Выбранный пакет не существует.';
            } else {
                $package_price = is_numeric($package_result['price']) ? floatval($package_result['price']) : 0;

                $stmt = $conn->prepare("SELECT travel_id FROM tour_bookings WHERE id = ?");
                $stmt->bind_param('i', $booking_id);
                $stmt->execute();
                $booking_result = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$booking_result) {
                    $error = 'Бронирование не найдено.';
                } else {
                    $travel_id = (int)$booking_result['travel_id'];

                    $stmt = $conn->prepare("SELECT price AS tour_price FROM travels WHERE id = ?");
                    $stmt->bind_param('i', $travel_id);
                    $stmt->execute();
                    $travel_result = $stmt->get_result()->fetch_assoc();
                    $stmt->close();

                    $tour_price = is_numeric($travel_result['tour_price'] ?? 0) ? floatval($travel_result['tour_price']) : 0;

                    $price = ($package_price * $persons) + ($tour_price * $persons);

                    $stmt = $conn->prepare("UPDATE tour_bookings SET status = ?, persons = ?, package_id = ?, price = ? WHERE id = ?");
                    $stmt->bind_param('siidi', $status, $persons, $package_id, $price, $booking_id);
                    $stmt->execute();
                    $stmt->close();

                    $success = 'Бронирование успешно обновлено.';
                    header('Location: admin.php?success=' . urlencode($success));
                    exit;
                }
            }
        }
    }

    // Обработка AJAX-запросов для пакетов и услуг
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        $data = json_decode(file_get_contents('php://input'), true);
        if (isset($data['action'])) {
            header('Content-Type: application/json');
            $response = ['success' => false, 'message' => ''];

            try {
                if ($data['action'] === 'save_package') {
                    $name = $conn->real_escape_string($data['name']);
                    $price = floatval($data['price']);
                    $description = $conn->real_escape_string($data['description'] ?? '');
                    $services = array_map('intval', $data['services']);
                    if (empty($name) || $price <= 0 || empty($services)) {
                        throw new Exception('Все поля обязательны, и цена должна быть больше 0.');
                    }
                    $stmt = $conn->prepare("INSERT INTO packages (name, price, description) VALUES (?, ?, ?)");
                    $stmt->bind_param('sds', $name, $price, $description);
                    $stmt->execute();
                    $package_id = $conn->insert_id;
                    $stmt->close();

                    $stmt = $conn->prepare("INSERT INTO package_services (package_id, service_id) VALUES (?, ?)");
                    foreach ($services as $service_id) {
                        $stmt->bind_param('ii', $package_id, $service_id);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $response['success'] = true;
                    $response['message'] = 'Пакет успешно создан';
                } elseif ($data['action'] === 'update_package') {
                    $id = (int)$data['id'];
                    $name = $conn->real_escape_string($data['name']);
                    $price = floatval($data['price']);
                    $description = $conn->real_escape_string($data['description'] ?? '');
                    $services = array_map('intval', $data['services']);
                    if (empty($name) || $price <= 0 || empty($services)) {
                        throw new Exception('Все поля обязательны, и цена должна быть больше 0.');
                    }
                    $stmt = $conn->prepare("UPDATE packages SET name = ?, price = ?, description = ? WHERE id = ?");
                    $stmt->bind_param('sdsi', $name, $price, $description, $id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM package_services WHERE package_id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("INSERT INTO package_services (package_id, service_id) VALUES (?, ?)");
                    foreach ($services as $service_id) {
                        $stmt->bind_param('ii', $id, $service_id);
                        $stmt->execute();
                    }
                    $stmt->close();
                    $response['success'] = true;
                    $response['message'] = 'Пакет успешно обновлен';
                } elseif ($data['action'] === 'delete_package') {
                    $id = (int)$data['id'];
                    $stmt = $conn->prepare("DELETE FROM package_services WHERE package_id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM tour_packages WHERE package_id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM packages WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                    $response['success'] = true;
                    $response['message'] = 'Пакет успешно удален';
                } elseif ($data['action'] === 'save_service') {
                    $name = $conn->real_escape_string($data['name']);
                    $description = $conn->real_escape_string($data['description'] ?? '');
                    if (empty($name)) {
                        throw new Exception('Название услуги обязательно.');
                    }
                    $stmt = $conn->prepare("INSERT INTO services (name, description) VALUES (?, ?)");
                    $stmt->bind_param('ss', $name, $description);
                    $stmt->execute();
                    $stmt->close();
                    $response['success'] = true;
                    $response['message'] = 'Услуга успешно создана';
                } elseif ($data['action'] === 'update_service') {
                    $id = (int)$data['id'];
                    $name = $conn->real_escape_string($data['name']);
                    $description = $conn->real_escape_string($data['description'] ?? '');
                    if (empty($name)) {
                        throw new Exception('Название услуги обязательно.');
                    }
                    $stmt = $conn->prepare("UPDATE services SET name = ?, description = ? WHERE id = ?");
                    $stmt->bind_param('ssi', $name, $description, $id);
                    $stmt->execute();
                    $stmt->close();
                    $response['success'] = true;
                    $response['message'] = 'Услуга успешно обновлена';
                } elseif ($data['action'] === 'delete_service') {
                    $id = (int)$data['id'];
                    $stmt = $conn->prepare("DELETE FROM package_services WHERE service_id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();

                    $stmt = $conn->prepare("DELETE FROM services WHERE id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $stmt->close();
                    $response['success'] = true;
                    $response['message'] = 'Услуга успешно удалена';
                } elseif ($data['action'] === 'get_package_services') {
                    $id = (int)$data['id'];
                    $stmt = $conn->prepare("SELECT service_id FROM package_services WHERE package_id = ?");
                    $stmt->bind_param('i', $id);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    $services = [];
                    while ($row = $result->fetch_assoc()) {
                        $services[] = $row['service_id'];
                    }
                    $stmt->close();
                    $response['success'] = true;
                    $response['services'] = $services;
                }
            } catch (Exception $e) {
                $response['message'] = 'Ошибка: ' . $e->getMessage();
            }
            echo json_encode($response);
            exit;
        }
    }

    // Обработка отмены бронирования
    if (isset($_GET['cancel']) && isset($_GET['booking_id'])) {
        $booking_id = (int)$_GET['booking_id'];
        $stmt = $conn->prepare("DELETE FROM tour_bookings WHERE id = ?");
        $stmt->bind_param('i', $booking_id);
        $stmt->execute();
        $stmt->close();
        header('Location: admin.php?success=' . urlencode('Бронирование успешно отменено.'));
        exit;
    }

// Статистика
$stats = getCachedData($cache_file, $cache_time);
if (!$stats) {
    $stmt = $conn->prepare("SELECT 
        (SELECT COUNT(*) FROM users) as user_count,
        (SELECT COUNT(*) FROM travels WHERE status = 'active') as tour_count,
        (SELECT COUNT(*) FROM tour_bookings) as booking_count,
        (SELECT COUNT(*) FROM tour_bookings WHERE status = 'confirmed') as confirmed_count");
    $stmt->execute();
    $stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Новый код для статистики бронирований
    $stmt = $conn->prepare("
        SELECT 
            SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
        FROM tour_bookings
    ");
    $stmt->execute();
    $booking_stats = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // Рассчитываем проценты
    $total_bookings = ($booking_stats['confirmed'] + $booking_stats['pending'] + $booking_stats['cancelled']) ?: 1; // Избегаем деления на 0
    $confirmed_percent = round(($booking_stats['confirmed'] / $total_bookings) * 100, 1);
    $pending_percent = round(($booking_stats['pending'] / $total_bookings) * 100, 1);
    $cancelled_percent = round(($booking_stats['cancelled'] / $total_bookings) * 100, 1);

    // Добавляем в массив $stats
    $stats['confirmed_percent'] = $confirmed_percent;
    $stats['pending_percent'] = $pending_percent;
    $stats['cancelled_percent'] = $cancelled_percent;

    saveCacheData($cache_file, $stats);
}


// Популярность пакетов услуг
$stmt = $conn->prepare("
    SELECT p.name, COUNT(tb.id) as booking_count
    FROM tour_bookings tb
    JOIN packages p ON tb.package_id = p.id
    GROUP BY p.id
    ORDER BY booking_count DESC
    LIMIT 4
");
$stmt->execute();
$result = $stmt->get_result();
$package_stats = [];
$package_labels = [];
while ($row = $result->fetch_assoc()) {
    $package_stats[] = $row['booking_count'];
    $package_labels[] = $row['name'];
}
$stmt->close();

// Заполняем нули, если пакетов меньше 4
while (count($package_stats) < 4) {
    $package_stats[] = 0;
    $package_labels[] = 'Без пакета ' . (count($package_labels) + 1);
}

// Сохраняем в кэш
$stats['package_stats'] = $package_stats;
$stats['package_labels'] = $package_labels;



// Динамика бронирований за последние 7 дней
$stmt = $conn->prepare("
    SELECT DATE(created_at) as day, COUNT(*) as booking_count
    FROM tour_bookings
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(created_at)
    ORDER BY day ASC
");
$stmt->execute();
$result = $stmt->get_result();
$booking_trend = [];
$labels = [];

// Инициализируем последние 7 дней
$today = new DateTime();
$days_ago = [];
for ($i = 6; $i >= 0; $i--) {
    $date = (clone $today)->modify("-$i day");
    $days_ago[$date->format('Y-m-d')] = 0;
    $labels[] = $date->format('d.m');
}

while ($row = $result->fetch_assoc()) {
    $days_ago[$row['day']] = $row['booking_count'];
}

$booking_trend = array_values($days_ago);
$stmt->close();

// Сохраняем в кэш
$stats['booking_trend'] = $booking_trend;
$stats['booking_labels'] = $labels;




    // Пагинация и выборка данных
    $items_per_page = 10;
    $user_page = max(1, (int)($_GET['user_page'] ?? 1));
    $user_offset = ($user_page - 1) * $items_per_page;
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM users");
    $stmt->execute();
    $total_users = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $stmt = $conn->prepare("SELECT id, username, email, role FROM users LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $items_per_page, $user_offset);
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $tour_page = max(1, (int)($_GET['tour_page'] ?? 1));
    $tour_offset = ($tour_page - 1) * $items_per_page;
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM travels");
    $stmt->execute();
    $total_tours = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $stmt = $conn->prepare("SELECT id, title, destination, price, description, start_date, end_date, status, image, images, transport_type, transport_type_en, transport_details FROM travels LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $items_per_page, $tour_offset);
    $stmt->execute();
    $tours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $booking_page = max(1, (int)($_GET['booking_page'] ?? 1));
    $booking_offset = ($booking_page - 1) * $items_per_page;
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tour_bookings");
    $stmt->execute();
    $total_bookings = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();
    $stmt = $conn->prepare("SELECT tb.id, u.username, tb.phone, t.title, tb.status, tb.created_at, tb.persons, p.name AS package_name, tb.price, tb.package_id 
                           FROM tour_bookings tb 
                           LEFT JOIN users u ON tb.user_id = u.id 
                           LEFT JOIN travels t ON tb.travel_id = t.id 
                           LEFT JOIN packages p ON tb.package_id = p.id 
                           LIMIT ? OFFSET ?");
    $stmt->bind_param('ii', $items_per_page, $booking_offset);
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT p.id, p.name, p.price, p.description, GROUP_CONCAT(s.name SEPARATOR ', ') as service_names 
                            FROM packages p 
                            LEFT JOIN package_services ps ON p.id = ps.package_id 
                            LEFT JOIN services s ON ps.service_id = s.id 
                            GROUP BY p.id");
    $stmt->execute();
    $packages_with_services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, name FROM packages");
    $stmt->execute();
    $packages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT id, name, description FROM services");
    $stmt->execute();
    $services = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

} catch (mysqli_sql_exception $e) {
    error_log("Ошибка базы данных: " . $e->getMessage() . " (Код: " . $e->getCode() . ")", 3, 'error.log');
    die("Произошла ошибка базы данных: " . htmlspecialchars($e->getMessage()) . ". Пожалуйста, попробуйте позже.");
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель | iTravel</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&family=Roboto+Mono:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.0.0/dist/chartjs-plugin-datalabels.min.js"></script>

    <style>
    :root {
        --primary: #0EA5E9;
        --secondary: #1E3A8A;
        --background: #F9FAFB;
        --white: #FFFFFF;
        --dark-text: #1E293B;
        --gray: #6B7280;
        --shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        --border: #E5E7EB;
        --error: #FEE2E2;
        --error-text: #B91C1C;
        --success: #D1FAE5;
        --success-text: #065F46;
    }

    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Poppins', sans-serif;
        background: var(--background);
        color: var(--dark-text);
        line-height: 1.6;
        overflow-x: hidden;
    }

    .container {
        display: flex;
        max-width: 1600px;
        margin: 0 auto;
        padding: 20px;
        min-height: 100vh;
        gap: 20px;
    }

    .sidebar {
        width: 280px;
        background: var(--white);
        padding: 20px;
        border-radius: 12px;
        box-shadow: var(--shadow);
        position: sticky;
        top: 20px;
        height: calc(100vh - 40px);
        overflow-y: auto;
        flex-shrink: 0;
    }

    .sidebar .logo {
        font-size: 26px;
        font-weight: 700;
        color: var(--primary);
        margin-bottom: 30px;
        text-align: center;
        letter-spacing: 1px;
    }

    .menu a {
        display: flex;
        align-items: center;
        padding: 12px 16px;
        color: var(--dark-text);
        text-decoration: none;
        margin-bottom: 8px;
        border-radius: 8px;
        transition: background 0.3s ease, color 0.3s ease, transform 0.2s ease;
    }

    .menu a i {
        margin-right: 12px;
        font-size: 18px;
        color: var(--gray);
    }

    .menu a:hover, .menu a.active {
        background: var(--primary);
        color: var(--white);
        transform: translateX(5px);
    }

    .menu a:hover i, .menu a.active i {
        color: var(--white);
    }

    .main-content {
        flex: 1;
        padding: 20px;
        background: var(--background);
        border-radius: 12px;
    }

    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        background: var(--white);
        padding: 15px 20px;
        border-radius: 12px;
        box-shadow: var(--shadow);
    }

    .header-title {
        font-size: 26px;
        font-weight: 600;
        color: var(--dark-text);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .header-title i {
        color: var(--primary);
    }

    .profile {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .profile img {
        width: 44px;
        height: 44px;
        border-radius: 50%;
        border: 2px solid var(--primary);
        transition: transform 0.3s ease;
    }

    .profile img:hover {
        transform: scale(1.1);
    }

    .profile-info h1 {
        font-size: 16px;
        font-weight: 600;
        color: var(--dark-text);
    }

    .profile-info p {
        font-size: 12px;
        color: var(--gray);
    }

    .section {
        background: var(--white);
        border-radius: 12px;
        box-shadow: var(--shadow);
        padding: 20px;
        margin-bottom: 20px;
        transition: transform 0.3s ease;
    }

    .section:hover {
        transform: translateY(-3px);
    }

    .section-title {
        font-size: 20px;
        font-weight: 600;
        margin-bottom: 15px;
        color: var(--dark-text);
        border-left: 4px solid var(--primary);
        padding-left: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }

    .section-title i {
        color: var(--primary);
    }

    .section-content {
        max-height: 0;
        overflow: hidden;
        transition: max-height 0.5s ease-in-out;
    }

    .section-content.active {
        max-height: 2000px;
        transition: max-height 0.5s ease-in-out;
    }

    .dashboard-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 20px;
    }

    .dashboard-card {
        background: var(--white);
        padding: 20px;
        border-radius: 12px;
        text-align: center;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border: 1px solid var(--border);
    }

    .dashboard-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 6px 16px rgba(0, 0, 0, 0.12);
    }

    .dashboard-card h3 {
        font-size: 14px;
        font-weight: 500;
        margin-bottom: 10px;
        color: var(--gray);
        text-transform: uppercase;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .dashboard-card h3 i {
        color: var(--primary);
    }

    .dashboard-card p {
        font-size: 28px;
        font-weight: 700;
        color: var(--primary);
    }

    .chart-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .chart-container {
        background: var(--white);
        padding: 20px;
        border-radius: 12px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .chart-container:hover {
        transform: scale(1.02);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    }

    .chart-title {
        font-size: 18px;
        font-weight: 600;
        color: var(--dark-text);
        margin-bottom: 15px;
        text-align: center;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .chart-title i {
        color: var(--primary);
    }

    .bottom-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .top-products, .service-level-container {
        background: var(--white);
        padding: 20px;
        border-radius: 12px;
        box-shadow: var(--shadow);
        border: 1px solid var(--border);
        transition: transform 0.3s ease, box-shadow 0.3s ease;
    }

    .service-level-container:hover {
        transform: scale(1.02);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
    }

    .top-products table, .table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }

    .top-products th, .top-products td, .table th, .table td {
        padding: 12px;
        text-align: left;
        color: var(--dark-text);
        border-bottom: 1px solid var(--border);
    }

    .top-products th, .table th {
        font-weight: 600;
        color: var(--white);
        background: var(--primary);
    }

    .top-products td, .table td {
        font-size: 14px;
    }

    .table tr:hover {
        background: var(--background);
    }

    .btn {
        padding: 8px 12px;
        border-radius: 8px;
        font-size: 13px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        transition: background 0.3s ease, transform 0.2s ease;
    }

    .btn-edit {
        background: var(--primary);
        color: var(--white);
        margin-right: 8px;
    }

    .btn-edit:hover {
        background: #0284C7;
        transform: translateY(-2px);
    }

    .btn-delete {
        background: #EF4444;
        color: var(--white);
    }

    .btn-delete:hover {
        background: #DC2626;
        transform: translateY(-2px);
    }

    .btn-cancel {
        background: #EF4444;
        color: var(--white);
    }

    .btn-cancel:hover {
        background: #DC2626;
        transform: translateY(-2px);
    }

    .btn-save {
        background: var(--primary);
        color: var(--white);
        padding: 10px 18px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        transition: background 0.3s ease, transform 0.2s ease;
        margin-bottom: 10px;
    }

    .btn-save:hover {
        background: #0284C7;
        transform: translateY(-2px);
    }

    .pagination {
        margin-top: 20px;
        display: flex;
        justify-content: center;
        gap: 10px;
    }

    .pagination a {
        padding: 10px 16px;
        background: var(--white);
        color: var(--dark-text);
        text-decoration: none;
        border-radius: 8px;
        border: 1px solid var(--border);
        transition: background 0.3s ease, color 0.3s ease, transform 0.2s ease;
    }

    .pagination a:hover {
        background: var(--primary);
        color: var(--white);
        transform: translateY(-2px);
    }

    .pagination a.active {
        background: var(--primary);
        color: var(--white);
        border-color: var(--primary);
    }

    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 1000;
        justify-content: center;
        align-items: center;
        animation: fadeIn 0.3s ease;
    }

    .modal-content {
        background: var(--white);
        padding: 20px;
        border-radius: 12px;
        width: 90%;
        max-width: 550px;
        max-height: 80vh;
        overflow-y: auto;
        scroll-behavior: smooth;
        box-shadow: var(--shadow);
        position: relative;
        color: var(--dark-text);
        animation: slideIn 0.3s ease;
    }

    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }

    @keyframes slideIn {
        from { transform: translateY(-20px); opacity: 0; }
        to { transform: translateY(0); opacity: 1; }
    }

    .modal-content h2 {
        font-size: 20px;
        margin-bottom: 20px;
        color: var(--dark-text);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .modal-content h2 i {
        color: var(--primary);
    }

    .close {
        position: absolute;
        top: 12px;
        right: 16px;
        font-size: 24px;
        cursor: pointer;
        color: var(--gray);
        transition: color 0.3s ease;
    }

    .close:hover {
        color: var(--primary);
    }

    .form-group {
        margin-bottom: 15px;
    }

    .form-group label {
        display: block;
        font-weight: 500;
        margin-bottom: 6px;
        color: var(--dark-text);
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .form-group label i {
        color: var(--primary);
    }

    .form-group input, .form-group select {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 14px;
        background: var(--white);
        color: var(--dark-text);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
    }

    .form-group input:focus, .form-group select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
    }

    .form-group textarea {
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        font-size: 14px;
        background: var(--white);
        color: var(--dark-text);
        transition: border-color 0.3s ease, box-shadow 0.3s ease;
        resize: vertical;
    }

    .form-group textarea:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
    }

    .error {
        background: var(--error);
        color: var(--error-text);
        padding: 10px;
        margin-bottom: 20px;
        border-radius: 8px;
        text-align: center;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .error i {
        color: var(--error-text);
    }

    .success {
        background: var(--success);
        color: var(--success-text);
        padding: 10px;
        margin-bottom: 20px;
        border-radius: 8px;
        text-align: center;
        font-size: 14px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .success i {
        color: var(--success-text);
    }

    .form-group select[multiple] {
        height: 150px;
        width: 100%;
        padding: 10px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: var(--white);
        color: var(--dark-text);
        transition: border-color 0.3s ease;
    }

    .form-group select[multiple]:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.1);
    }

  @media (max-width: 1024px) {
    .container {
        flex-direction: column;
        padding: 15px;
    }
    .sidebar {
        width: 100%;
        margin-bottom: 20px;
    }
    .main-content {
        flex: 1;
    }
}

    @media (max-width: 768px) {
        .dashboard-grid {
            grid-template-columns: 1fr;
        }

        .chart-row, .bottom-row {
            grid-template-columns: 1fr;
        }

        .header-title {
            font-size: 22px;
        }

        .section-title {
            font-size: 18px;
        }

        .table th, .table td {
            padding: 8px;
            font-size: 13px;
        }

        .btn {
            padding: 6px 10px;
            font-size: 12px;
        }

        .pagination a {
            padding: 8px 12px;
            font-size: 12px;
        }
    }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="logo">iTravel</div>
            <nav class="menu">
                <a href="#overview" class="active"><i class="fas fa-tachometer-alt"></i> Обзор</a>
                <a href="#users"><i class="fas fa-users-cog"></i> Пользователи</a>
                <a href="#tours"><i class="fas fa-plane"></i> Туры</a>
                <a href="#bookings"><i class="fas fa-suitcase"></i> Бронирования</a>
                <a href="#packages"><i class="fas fa-box-open"></i> Пакеты услуг</a>
                <a href="#services"><i class="fas fa-concierge-bell"></i> Услуги</a>
                <a href="profile.php"><i class="fas fa-user"></i> Профиль</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Выйти</a>
            </nav>
        </div>

        <div class="main-content">
            <div class="header">
                <div class="header-title"><i class="fas fa-cog"></i> Админ-панель</div>
                <div class="profile">
                    <div class="profile-info">
                        <h1><?= htmlspecialchars($user['username'] ?? 'Админ') ?></h1>
                        <p>Администратор</p>
                    </div>
                
                </div>
            </div>

            <?php if ($error): ?>
                <div class="error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="section" id="overview">
                <div class="section-title"><i class="fas fa-chart-line"></i> Статистика за сегодня</div>
                <div class="section-content active">
                    <div class="dashboard-grid">
                        <div class="dashboard-card">
                            <h3><i class="fas fa-suitcase"></i> Все бронирования</h3>
                            <p><?= $stats['booking_count'] ?></p>
                        </div>
                        <div class="dashboard-card">
                            <h3><i class="fas fa-plane"></i> Активные туры</h3>
                            <p><?= $stats['tour_count'] ?></p>
                        </div>
                        <div class="dashboard-card">
                            <h3><i class="fas fa-check"></i> Подтвержденные</h3>
                            <p><?= $stats['confirmed_count'] ?></p>
                        </div>
                    </div>
                    <div class="chart-row">
                        <div class="chart-container">
                            <div class="chart-title"><i class="fas fa-smile"></i> Статистика заявок</div>
                            <canvas id="satisfactionChart"></canvas>
                        </div>
                        <div class="chart-container">
                            <div class="chart-title"><i class="fas fa-chart-line"></i> Динамика бронирований</div>
                            <canvas id="salesChart"></canvas>
                        </div>
                    </div>
                    <div class="bottom-row">
                        <div class="top-products">
                            <div class="section-title"><i class="fas fa-star"></i> Популярные туры</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Название</th>
                                        <th>Направление</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($tours)): ?>
                                        <tr><td colspan="3">Нет туров</td></tr>
                                    <?php else: ?>
                                        <?php $counter = 1; ?>
                                        <?php foreach (array_slice($tours, 0, 4) as $tour): ?>
                                            <tr>
                                                <td><?= $counter++ ?></td>
                                                <td><?= htmlspecialchars($tour['title'] ?? '') ?></td>
                                                <td><?= htmlspecialchars($tour['destination'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                      <div class="service-level-container">
    <div class="section-title"><i class="fas fa-box-open"></i> Популярность пакетов услуг</div>
    <canvas id="serviceLevelChart"></canvas>
</div>
                    </div>
                </div>
            </div>

            <div class="section" id="users">
                <div class="section-title"><i class="fas fa-users"></i> Пользователи</div>
                <div class="section-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Имя</th>
                                <th>Email</th>
                                <th>Роль</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($users)): ?>
                                <tr><td colspan="5" class="no-data">Нет пользователей в базе данных.</td></tr>
                            <?php else: ?>
                                <?php foreach ($users as $user_item): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($user_item['id'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($user_item['username'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($user_item['email'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($user_item['role'] ?? '') ?></td>
                                        <td>
                                            <button class="btn btn-edit" onclick="openUserModal(<?= $user_item['id'] ?>, '<?= htmlspecialchars($user_item['username']) ?>', '<?= htmlspecialchars($user_item['email']) ?>', '<?= htmlspecialchars($user_item['role']) ?>')"><i class="fas fa-edit"></i> Редактировать</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= ceil($total_users / $items_per_page); $i++): ?>
                            <a href="?user_page=<?= $i ?>" class="<?= $i == $user_page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="section" id="tours">
                <div class="section-title"><i class="fas fa-plane"></i> Туры</div>
                <div class="section-content">
                    <button class="btn btn-save" onclick="openAddTourModal()"><i class="fas fa-plus"></i> Добавить тур</button>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Направление</th>
                                <th>Цена</th>
                                <th>Описание</th>
                                <th>Дата начала</th>
                                <th>Дата окончания</th>
                                <th>Статус</th>
                                <th>Транспорт</th>
                                <th>Транспорт (EN)</th>
                                <th>Детали транспорта</th>
                                <th>Пакеты</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($tours)): ?>
                                <tr><td colspan="13" class="no-data">Нет туров в базе данных.</td></tr>
                            <?php else: ?>
                                <?php foreach ($tours as $tour): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($tour['id'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($tour['title'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($tour['destination'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($tour['price'] ?? '0') ?> ₽</td>
                                        <td><?= htmlspecialchars($tour['description'] ?? '-') ?></td>
                                        <td><?= !empty($tour['start_date']) ? date('d.m.Y', strtotime($tour['start_date'])) : '-' ?></td>
                                        <td><?= !empty($tour['end_date']) ? date('d.m.Y', strtotime($tour['end_date'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($tour['status'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($tour['transport_type'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($tour['transport_type_en'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($tour['transport_details'] ?? '-') ?></td>
                                        <td>
                                            <?php
                                            $stmt = $conn->prepare("SELECT p.name FROM tour_packages tp JOIN packages p ON tp.package_id = p.id WHERE tp.tour_id = ?");
                                            $stmt->bind_param('i', $tour['id']);
                                            $stmt->execute();
                                            $result = $stmt->get_result();
                                            $package_names = [];
                                            while ($row = $result->fetch_assoc()) {
                                                $package_names[] = htmlspecialchars($row['name']);
                                            }
                                            echo implode(', ', $package_names) ?: 'Без пакетов';
                                            $stmt->close();
                                            ?>
                                        </td>
                                        <td>
                                            <button class="btn btn-edit" onclick="openTourModal(<?= $tour['id'] ?>, '<?= htmlspecialchars($tour['title']) ?>', '<?= htmlspecialchars($tour['destination']) ?>', <?= $tour['price'] ?? 0 ?>, '<?= htmlspecialchars($tour['description'] ?? '') ?>', '<?= htmlspecialchars($tour['start_date']) ?>', '<?= htmlspecialchars($tour['end_date']) ?>', '<?= htmlspecialchars($tour['status']) ?>', '<?= htmlspecialchars($tour['image'] ?? '') ?>', '<?= htmlspecialchars($tour['images'] ?? '') ?>', '<?= htmlspecialchars($tour['transport_type'] ?? '') ?>', '<?= htmlspecialchars($tour['transport_details'] ?? '') ?>')"><i class="fas fa-edit"></i> Редактировать</button>
                                            <a href="?delete_tour=1&tour_id=<?= urlencode($tour['id'] ?? '') ?>" class="btn btn-delete" onclick="return confirm('Вы уверены, что хотите удалить тур?');"><i class="fas fa-trash"></i> Удалить</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= ceil($total_tours / $items_per_page); $i++): ?>
                            <a href="?tour_page=<?= $i ?>" class="<?= $i == $tour_page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="section" id="bookings">
                <div class="section-title"><i class="fas fa-suitcase"></i> Бронирования</div>
                <div class="section-content">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Пользователь</th>
                                <th>Телефон</th>
                                <th>Тур</th>
                                <th>Статус</th>
                                <th>Дата бронирования</th>
                                <th>Человек</th>
                                <th>Пакет</th>
                                <th>Цена</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                                <tr><td colspan="10" class="no-data">Нет бронирований в базе данных.</td></tr>
                            <?php else: ?>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($booking['id'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($booking['username'] ?? 'Неизвестно') ?></td>
                                        <td><?= htmlspecialchars($booking['phone'] ?? 'Не указан') ?></td>
                                        <td><?= htmlspecialchars($booking['title'] ?? 'Неизвестно') ?></td>
                                        <td><?= htmlspecialchars($booking['status'] ?? '') ?></td>
                                        <td><?= !empty($booking['created_at']) ? date('d.m.Y H:i', strtotime($booking['created_at'])) : '-' ?></td>
                                        <td><?= htmlspecialchars($booking['persons'] ?? 0) ?></td>
                                        <td><?= htmlspecialchars($booking['package_name'] ?? 'Без пакета') ?></td>
                                        <td><?= htmlspecialchars($booking['price'] ?? '0') ?> ₽</td>
                                        <td>
                                            <button class="btn btn-edit" onclick="openBookingModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['status']) ?>', <?= $booking['persons'] ?? 0 ?>, <?= $booking['package_id'] ?? 0 ?>)"><i class="fas fa-edit"></i> Редактировать</button>
                                            <a href="?cancel=1&booking_id=<?= urlencode($booking['id'] ?? '') ?>" class="btn btn-cancel" onclick="return confirm('Вы уверены, что хотите отменить бронирование?');"><i class="fas fa-times"></i> Отменить</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= ceil($total_bookings / $items_per_page); $i++): ?>
                            <a href="?booking_page=<?= $i ?>" class="<?= $i == $booking_page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    </div>
                </div>
            </div>

            <div class="section" id="packages">
                <div class="section-title"><i class="fas fa-box-open"></i> Пакеты услуг</div>
                <div class="section-content">
                    <div class="form-group">
                        <label for="package_name"><i class="fas fa-tag"></i> Название пакета</label>
                        <input type="text" id="package_name" name="package_name" required>
                    </div>
                    <div class="form-group">
                        <label for="package_price"><i class="fas fa-money-bill"></i> Цена</label>
                        <input type="number" id="package_price" name="package_price" step="0.01" min="0" required>
                    </div>
                    <div class="form-group">
                        <label for="package_description"><i class="fas fa-info-circle"></i> Описание</label>
                        <textarea id="package_description" name="package_description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <label for="package_services"><i class="fas fa-list"></i> Услуги</label>
                        <select id="package_services" name="package_services" multiple required>
                            <?php foreach ($services as $service): ?>
                                <option value="<?= htmlspecialchars($service['id']) ?>"><?= htmlspecialchars($service['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-save" onclick="savePackage()"><i class="fas fa-save"></i> Создать пакет</button>
                    </div>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Цена</th>
                                <th>Описание</th>
                                <th>Услуги</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($packages_with_services)): ?>
                                <tr><td colspan="6" class="no-data">Нет пакетов в базе данных.</td></tr>
                            <?php else: ?>
                                <?php foreach ($packages_with_services as $package): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($package['id'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($package['name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($package['price'] ?? '') ?> ₽</td>
                                        <td><?= htmlspecialchars($package['description'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($package['service_names'] ?? 'Нет услуг') ?></td>
                                        <td>
                                            <button class="btn btn-edit" onclick="editPackage(<?= $package['id'] ?>, '<?= htmlspecialchars($package['name']) ?>', <?= $package['price'] ?>, '<?= htmlspecialchars($package['description'] ?? '') ?>')"><i class="fas fa-edit"></i> Редактировать</button>
                                            <button class="btn btn-delete" onclick="deletePackage(<?= $package['id'] ?>)"><i class="fas fa-trash"></i> Удалить</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="section" id="services">
                <div class="section-title"><i class="fas fa-concierge-bell"></i> Услуги</div>
                <div class="section-content">
                    <div class="form-group">
                        <label for="service_name"><i class="fas fa-tag"></i> Название услуги</label>
                        <input type="text" id="service_name" name="service_name" required>
                    </div>
                    <div class="form-group">
                        <label for="service_description"><i class="fas fa-info-circle"></i> Описание</label>
                        <textarea id="service_description" name="service_description" rows="3"></textarea>
                    </div>
                    <div class="form-group">
                        <button type="button" class="btn btn-save" onclick="saveService()"><i class="fas fa-save"></i> Создать услугу</button>
                    </div>
                    <table class="table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Название</th>
                                <th>Описание</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($services)): ?>
                                <tr><td colspan="4" class="no-data">Нет услуг в базе данных.</td></tr>
                            <?php else: ?>
                                <?php foreach ($services as $service): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($service['id'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($service['name'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($service['description'] ?? '') ?></td>
                                        <td>
                                            <button class="btn btn-edit" data-id="<?= htmlspecialchars($service['id'] ?? '') ?>" data-name="<?= htmlspecialchars($service['name'] ?? '') ?>" data-description="<?= htmlspecialchars($service['description'] ?? '') ?>" onclick="editService(this)"><i class="fas fa-edit"></i> Редактировать</button>
                                            <button class="btn btn-delete" onclick="deleteService(<?= $service['id'] ?>)"><i class="fas fa-trash"></i> Удалить</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="userModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeModal('userModal')">×</span>
                    <h2><i class="fas fa-user-edit"></i> Редактировать пользователя</h2>
                    <form method="POST">
                        <input type="hidden" name="edit_user" value="1">
                        <input type="hidden" id="user_id" name="user_id">
                        <div class="form-group">
                            <label for="user_username"><i class="fas fa-user"></i> Имя пользователя</label>
                            <input type="text" id="user_username" name="username" required>
                        </div>
                        <div class="form-group">
                            <label for="user_email"><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" id="user_email" name="email" required>
                        </div>
                        <div class="form-group">
                            <label for="user_role"><i class="fas fa-shield-alt"></i> Роль</label>
                            <select id="user_role" name="role" required>
                                <option value="admin">Администратор</option>
                                <option value="user">Пользователь</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="addTourModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeModal('addTourModal')">×</span>
                    <h2><i class="fas fa-plane"></i> Добавить тур</h2>
                    <form method="POST">
                        <input type="hidden" name="add_tour" value="1">
                        <div class="form-group">
                            <label for="add_tour_title"><i class="fas fa-tag"></i> Название</label>
                            <input type="text" id="add_tour_title" name="title" required>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_destination"><i class="fas fa-map-marker-alt"></i> Направление</label>
                            <input type="text" id="add_tour_destination" name="destination" required>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_price"><i class="fas fa-money-bill"></i> Цена</label>
                            <input type="number" id="add_tour_price" name="price" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_description"><i class="fas fa-info-circle"></i> Описание</label>
                            <textarea id="add_tour_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_start_date"><i class="fas fa-calendar-alt"></i> Дата начала</label>
                            <input type="date" id="add_tour_start_date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_end_date"><i class="fas fa-calendar-alt"></i> Дата окончания</label>
                            <input type="date" id="add_tour_end_date" name="end_date" required>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_status"><i class="fas fa-toggle-on"></i> Статус</label>
                            <select id="add_tour_status" name="status" required>
                                <option value="active">Активен</option>
                                <option value="inactive">Неактивен</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_transport_type"><i class="fas fa-bus"></i> Тип транспорта</label>
                            <select id="add_tour_transport_type" name="transport_type" required>
                                <option value="автобус">Автобус</option>
                                <option value="поезд">Поезд</option>
                                <option value="самолет">Самолет</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_transport_details"><i class="fas fa-info-circle"></i> Детали транспорта</label>
                            <textarea id="add_tour_transport_details" name="transport_details" rows="3" placeholder="Введите детали (например, время отправления, номер рейса)"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_package_ids"><i class="fas fa-box"></i> Пакеты услуг</label>
                            <select id="add_tour_package_ids" name="package_ids[]" multiple>
                                <?php foreach ($packages as $package): ?>
                                    <option value="<?= $package['id'] ?>"><?= htmlspecialchars($package['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="add_tour_image"><i class="fas fa-image"></i> URL основного фото</label>
                            <input type="url" id="add_tour_image" name="image">
                        </div>
                        <div class="form-group">
                            <label for="add_tour_images"><i class="fas fa-images"></i> URL дополнительных фото (через запятую)</label>
                            <input type="text" id="add_tour_images" name="images">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="tourModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeModal('tourModal')">×</span>
                    <h2><i class="fas fa-plane"></i> Редактировать тур</h2>
                    <form method="POST">
                        <input type="hidden" name="edit_tour" value="1">
                        <input type="hidden" id="tour_id" name="tour_id">
                        <div class="form-group">
                            <label for="tour_title"><i class="fas fa-tag"></i> Название</label>
                            <input type="text" id="tour_title" name="title" required>
                        </div>
                        <div class="form-group">
                            <label for="tour_destination"><i class="fas fa-map-marker-alt"></i> Направление</label>
                            <input type="text" id="tour_destination" name="destination" required>
                        </div>
                        <div class="form-group">
                            <label for="tour_price"><i class="fas fa-money-bill"></i> Цена</label>
                            <input type="number" id="tour_price" name="price" step="0.01" min="0" required>
                        </div>
                        <div class="form-group">
                            <label for="tour_description"><i class="fas fa-info-circle"></i> Описание</label>
                            <textarea id="tour_description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="tour_start_date"><i class="fas fa-calendar-alt"></i> Дата начала</label>
                            <input type="date" id="tour_start_date" name="start_date" required>
                        </div>
                        <div class="form-group">
                            <label for="tour_end_date"><i class="fas fa-calendar-alt"></i> Дата окончания</label>
                            <input type="date" id="tour_end_date" name="end_date" required>
                        </div>
                        <div class="form-group">
                            <label for="tour_status"><i class="fas fa-toggle-on"></i> Статус</label>
                            <select id="tour_status" name="status" required>
                                <option value="active">Активен</option>
                                <option value="inactive">Неактивен</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tour_transport_type"><i class="fas fa-bus"></i> Тип транспорта</label>
                            <select id="tour_transport_type" name="transport_type" required>
                                <option value="автобус">Автобус</option>
                                <option value="поезд">Поезд</option>
                                <option value="самолет">Самолет</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tour_transport_details"><i class="fas fa-info-circle"></i> Детали транспорта</label>
                            <textarea id="tour_transport_details" name="transport_details" rows="3" placeholder="Введите детали (например, время отправления, номер рейса)"></textarea>
                        </div>
                        <div class="form-group">
                            <label for="tour_package_ids"><i class="fas fa-box"></i> Пакеты услуг</label>
                            <select id="tour_package_ids" name="package_ids[]" multiple>
                                <?php foreach ($packages as $package): ?>
                                    <option value="<?= htmlspecialchars($package['id']) ?>"><?= htmlspecialchars($package['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="tour_image"><i class="fas fa-image"></i> URL основного фото</label>
                            <input type="url" id="tour_image" name="image">
                        </div>
                        <div class="form-group">
                            <label for="tour_images"><i class="fas fa-images"></i> URL дополнительных фото (через запятую)</label>
                            <input type="text" id="tour_images" name="images">
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="bookingModal" class="modal">
                <div class="modal-content">
                    <span class="close" onclick="closeModal('bookingModal')">×</span>
                    <h2><i class="fas fa-suitcase"></i> Редактировать бронирование</h2>
                    <form method="POST">
                        <input type="hidden" name="edit_booking" value="1">
                        <input type="hidden" id="booking_id" name="booking_id">
                        <div class="form-group">
                            <label for="booking_status"><i class="fas fa-toggle-on"></i> Статус</label>
                            <select id="booking_status" name="status" required>
                                <option value="pending">Ожидает</option>
                                <option value="confirmed">Подтверждено</option>
                                <option value="cancelled">Отменено</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="booking_persons"><i class="fas fa-users"></i> Количество человек</label>
                            <input type="number" id="booking_persons" name="persons" min="1" required>
                        </div>
                        <div class="form-group">
                            <label for="booking_package_id"><i class="fas fa-box"></i> Пакет услуг</label>
                            <select id="booking_package_id" name="package_id" required>
                                <?php foreach ($packages as $package): ?>
                                    <option value="<?= htmlspecialchars($package['id']) ?>"><?= htmlspecialchars($package['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>
            <script>
    const packagePopularityData = {
        labels: <?= json_encode($stats['package_labels']) ?>,
        data: <?= json_encode($stats['package_stats']) ?>
    };
</script>
           <script>
    const salesData = {
        labels: <?= json_encode($stats['booking_labels']) ?>,
        data: <?= json_encode($stats['booking_trend']) ?>
    };
</script>
<script>
    const satisfactionData = {
        satisfied: <?= json_encode($stats['confirmed_percent']) ?>,
        neutral: <?= json_encode($stats['pending_percent']) ?>,
        dissatisfied: <?= json_encode($stats['cancelled_percent']) ?>
    };
</script>
            <script>
                document.addEventListener('DOMContentLoaded', function() {
                    // Сворачивание/разворачивание секций
                    document.querySelectorAll('.section-title').forEach(title => {
                        title.addEventListener('click', function() {
                            const content = this.nextElementSibling;
                            content.classList.toggle('active');
                        });
                    });

                    // Закрытие модальных окон
                    document.querySelectorAll('.modal').forEach(modal => {
                        const closeBtn = modal.querySelector('.close');
                        closeBtn.addEventListener('click', () => closeModal(modal.id));
                        window.addEventListener('click', (event) => {
                            if (event.target === modal) closeModal(modal.id);
                        });
                    });

                    // Открытие модального окна пользователя
                    window.openUserModal = function(id, username, email, role) {
                        document.getElementById('user_id').value = id;
                        document.getElementById('user_username').value = username;
                        document.getElementById('user_email').value = email;
                        document.getElementById('user_role').value = role;
                        document.getElementById('userModal').style.display = 'flex';
                    };

                    // Открытие модального окна добавления тура
                    window.openAddTourModal = function() {
                        document.getElementById('addTourModal').style.display = 'flex';
                        document.getElementById('add_tour_title').value = '';
                        document.getElementById('add_tour_destination').value = '';
                        document.getElementById('add_tour_price').value = '';
                        document.getElementById('add_tour_description').value = '';
                        document.getElementById('add_tour_start_date').value = '';
                        document.getElementById('add_tour_end_date').value = '';
                        document.getElementById('add_tour_status').value = 'active';
                        document.getElementById('add_tour_transport_type').value = 'автобус';
                        document.getElementById('add_tour_transport_details').value = '';
                        document.getElementById('add_tour_image').value = '';
                        document.getElementById('add_tour_images').value = '';
                        const packageSelect = document.getElementById('add_tour_package_ids');
                        for (let option of packageSelect.options) {
                            option.selected = false;
                        }
                    };

                    // Открытие модального окна редактирования тура
                    window.openTourModal = function(id, title, destination, price, description, start_date, end_date, status, image, images, transport_type, transport_details) {
                        document.getElementById('tour_id').value = id;
                        document.getElementById('tour_title').value = title;
                        document.getElementById('tour_destination').value = destination;
                        document.getElementById('tour_price').value = price;
                        document.getElementById('tour_description').value = description;
                        document.getElementById('tour_start_date').value = start_date;
                        document.getElementById('tour_end_date').value = end_date;
                        document.getElementById('tour_status').value = status;
                        document.getElementById('tour_transport_type').value = transport_type;
                        document.getElementById('tour_transport_details').value = transport_details;
                        document.getElementById('tour_image').value = image;
                        document.getElementById('tour_images').value = images;

                        const packageSelect = document.getElementById('tour_package_ids');
                        for (let option of packageSelect.options) {
                            option.selected = false;
                        }

                        fetch(`admin.php?action=get_tour_packages&id=${id}`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    data.package_ids.forEach(packageId => {
                                        const option = packageSelect.querySelector(`option[value="${packageId}"]`);
                                        if (option) option.selected = true;
                                    });
                                }
                                document.getElementById('tourModal').style.display = 'flex';
                            })
                            .catch(error => console.error('Ошибка загрузки пакетов:', error));
                    };

                    // Открытие модального окна редактирования бронирования
                    window.openBookingModal = function(id, status, persons, package_id) {
                        document.getElementById('booking_id').value = id;
                        document.getElementById('booking_status').value = status;
                        document.getElementById('booking_persons').value = persons;
                        document.getElementById('booking_package_id').value = package_id;
                        document.getElementById('bookingModal').style.display = 'flex';
                    };

                    // Закрытие модального окна
                    window.closeModal = function(modalId) {
                        document.getElementById(modalId).style.display = 'none';
                    };

                    // Добавление тура "Летние чудеса Архыза" при загрузке страницы, если он еще не существует
                    fetch('admin.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'check_tour',
                            title: 'Летние чудеса Архыза'
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (!data.exists) {
                            const tourData = {
                                action: 'add_tour',
                                title: 'Летние чудеса Архыза',
                                destination: 'Архыз, Карачаево-Черкесия',
                                price: 35000,
                                description: 'Незабываемое путешествие в горы Архыза с посещением древних храмов, озер и водопадов.',
                                start_date: '2025-07-01',
                                end_date: '2025-07-07',
                                status: 'active',
                                image: 'https://example.com/images/arkhyz_main.jpg',
                                images: 'https://example.com/images/arkhyz_1.jpg,https://example.com/images/arkhyz_2.jpg',
                                transport_type: 'автобус',
                                transport_details: 'Отправление в 08:00, прибытие в 18:00',
                                package_ids: [1, 2] // Пример: базовый и премиум пакеты
                            };

                            fetch('admin.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(tourData)
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.success) {
                                    console.log('Тур "Летние чудеса Архыза" успешно добавлен.');
                                }
                            })
                            .catch(error => console.error('Ошибка добавления тура:', error));
                        }
                    })
                    .catch(error => console.error('Ошибка проверки тура:', error));

                    // Инициализация графиков
                    Chart.register(ChartDataLabels);

                  new Chart(document.getElementById('satisfactionChart'), {
    type: 'doughnut',
    data: {
        labels: ['Подтверждено', 'Ожидает', 'Отменено'],
        datasets: [{
            data: [satisfactionData.satisfied, satisfactionData.neutral, satisfactionData.dissatisfied],
            backgroundColor: ['#0EA5E9', '#FBBF24', '#EF4444'],
            borderWidth: 1,
            borderColor: '#FFFFFF'
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: { position: 'top' },
            datalabels: {
                color: '#FFFFFF',
                formatter: (value) => value + '%'
            }
        }
    }
});

                  new Chart(document.getElementById('salesChart'), {
    type: 'line',
    data: {
        labels: salesData.labels,
        datasets: [{
            label: 'Бронирования',
            data: salesData.data,
            borderColor: '#0EA5E9',
            backgroundColor: 'rgba(14, 165, 233, 0.2)',
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true }
        },
        plugins: {
            legend: { display: true },
            datalabels: { display: false }
        }
    }
});
                 new Chart(document.getElementById('serviceLevelChart'), {
    type: 'bar',
    data: {
        labels: packagePopularityData.labels,
        datasets: [{
            label: 'Бронирования',
            data: packagePopularityData.data,
            backgroundColor: '#0EA5E9',
            borderColor: '#0284C7',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        scales: {
            y: { beginAtZero: true }
        },
        plugins: {
            legend: { display: false },
            datalabels: {
                anchor: 'end',
                align: 'top',
                formatter: (value) => value
            }
        }
    }
});

                    // Создание пакета услуг
                    window.savePackage = function() {
                        const name = document.getElementById('package_name').value;
                        const price = document.getElementById('package_price').value;
                        const description = document.getElementById('package_description').value;
                        const services = Array.from(document.getElementById('package_services').selectedOptions).map(option => option.value);

                        fetch('admin.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'save_package', name, price, description, services })
                        })
                        .then(response => response.json())
                        .then(data => {
                            alert(data.message);
                            if (data.success) location.reload();
                        })
                        .catch(error => console.error('Ошибка:', error));
                    };

                    // Редактирование пакета услуг
                    window.editPackage = function(id, name, price, description) {
                        document.getElementById('package_name').value = name;
                        document.getElementById('package_price').value = price;
                        document.getElementById('package_description').value = description;

                        fetch('admin.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'get_package_services', id })
                        })
                        .then(response => response.json())
                        .then(data => {
                            const serviceSelect = document.getElementById('package_services');
                            for (let option of serviceSelect.options) {
                                option.selected = data.services.includes(parseInt(option.value));
                            }

                            const saveButton = document.querySelector('button[onclick="savePackage()"]');
                            saveButton.textContent = 'Обновить пакет';
                            saveButton.setAttribute('onclick', `updatePackage(${id})`);
                        })
                        .catch(error => console.error('Ошибка загрузки услуг:', error));
                    };

                    // Обновление пакета услуг
                    window.updatePackage = function(id) {
                        const name = document.getElementById('package_name').value;
                        const price = document.getElementById('package_price').value;
                        const description = document.getElementById('package_description').value;
                        const services = Array.from(document.getElementById('package_services').selectedOptions).map(option => option.value);

                        fetch('admin.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'update_package', id, name, price, description, services })
                        })
                        .then(response => response.json())
                        .then(data => {
                            alert(data.message);
                            if (data.success) location.reload();
                        })
                        .catch(error => console.error('Ошибка:', error));
                    };

                    // Удаление пакета услуг
                    window.deletePackage = function(id) {
                        if (confirm('Вы уверены, что хотите удалить этот пакет?')) {
                            fetch('admin.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'delete_package', id })
                            })
                            .then(response => response.json())
                            .then(data => {
                                alert(data.message);
                                if (data.success) location.reload();
                            })
                            .catch(error => console.error('Ошибка:', error));
                        }
                    };

                    // Создание услуги
                    window.saveService = function() {
                        const name = document.getElementById('service_name').value;
                        const description = document.getElementById('service_description').value;

                        fetch('admin.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'save_service', name, description })
                        })
                        .then(response => response.json())
                        .then(data => {
                            alert(data.message);
                            if (data.success) location.reload();
                        })
                        .catch(error => console.error('Ошибка:', error));
                    };

                    // Редактирование услуги
                    window.editService = function(button) {
                        const id = button.getAttribute('data-id');
                        const name = button.getAttribute('data-name');
                        const description = button.getAttribute('data-description');

                        document.getElementById('service_name').value = name;
                        document.getElementById('service_description').value = description;

                        const saveButton = document.querySelector('button[onclick="saveService()"]');
                        saveButton.textContent = 'Обновить услугу';
                        saveButton.setAttribute('onclick', `updateService(${id})`);
                    };

                    // Обновление услуги
                    window.updateService = function(id) {
                        const name = document.getElementById('service_name').value;
                        const description = document.getElementById('service_description').value;

                        fetch('admin.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'update_service', id, name, description })
                        })
                        .then(response => response.json())
                        .then(data => {
                            alert(data.message);
                            if (data.success) location.reload();
                        })
                        .catch(error => console.error('Ошибка:', error));
                    };

                    // Удаление услуги
                    window.deleteService = function(id) {
                        if (confirm('Вы уверены, что хотите удалить эту услугу?')) {
                            fetch('admin.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ action: 'delete_service', id })
                            })
                            .then(response => response.json())
                            .then(data => {
                                alert(data.message);
                                if (data.success) location.reload();
                            })
                            .catch(error => console.error('Ошибка:', error));
                        }
                    };
                });
            </script>
        </div>
    </div>
</body>
</html>