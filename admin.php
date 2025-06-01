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

try {
    // Получение данных текущего пользователя
    $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt->bind_param('i', $_SESSION['user_id']);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        die("Ошибка: Пользователь не найден.");
    }

    $error = '';
    $success = '';

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
            // Проверка, не занят ли email
            $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->bind_param('si', $email, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result->fetch_assoc()) {
                $error = 'Этот email уже используется.';
            } else {
                // Обновление пользователя
                $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, role = ? WHERE id = ?");
                $stmt->bind_param('sssi', $username, $email, $role, $user_id);
                $stmt->execute();
                $success = 'Пользователь успешно обновлен.';
            }
            $stmt->close();
        }
    }

    // Обработка редактирования тура
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_tour'])) {
        $tour_id = (int)($_POST['tour_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $destination = trim($_POST['destination'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = trim($_POST['end_date'] ?? '');
        $status = trim($_POST['status'] ?? '');

        if (empty($title) || empty($destination) || empty($start_date) || empty($end_date) || empty($status)) {
            $error = 'Все поля обязательны для тура.';
        } elseif (!in_array($status, ['active', 'inactive'])) {
            $error = 'Неверный статус тура.';
        } elseif (strtotime($start_date) === false || strtotime($end_date) === false) {
            $error = 'Неверный формат даты.';
        } elseif (strtotime($start_date) > strtotime($end_date)) {
            $error = 'Дата начала не может быть позже даты окончания.';
        } else {
            $stmt = $conn->prepare("UPDATE travels SET title = ?, destination = ?, start_date = ?, end_date = ?, status = ? WHERE id = ?");
            $stmt->bind_param('sssssi', $title, $destination, $start_date, $end_date, $status, $tour_id);
            $stmt->execute();
            $stmt->close();
            $success = 'Тур успешно обновлен.';
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
            $stmt = $conn->prepare("SELECT id FROM packages WHERE id = ?");
            $stmt->bind_param('i', $package_id);
            $stmt->execute();
            $result = $stmt->get_result();
            if (!$result->fetch_assoc()) {
                $error = 'Выбранный пакет не существует.';
            } else {
                $stmt = $conn->prepare("UPDATE tour_bookings SET status = ?, persons = ?, package_id = ? WHERE id = ?");
                $stmt->bind_param('siii', $status, $persons, $package_id, $booking_id);
                $stmt->execute();
                $success = 'Бронирование успешно обновлено.';
            }
            $stmt->close();
        }
    }

    // Подсчет статистики
    $stmt = $conn->prepare("SELECT COUNT(*) as user_count FROM users");
    $stmt->execute();
    $user_count = $stmt->get_result()->fetch_assoc()['user_count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as tour_count FROM travels WHERE status = 'active'");
    $stmt->execute();
    $tour_count = $stmt->get_result()->fetch_assoc()['tour_count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as booking_count FROM tour_bookings");
    $stmt->execute();
    $booking_count = $stmt->get_result()->fetch_assoc()['booking_count'];
    $stmt->close();

    $stmt = $conn->prepare("SELECT COUNT(*) as confirmed_count FROM tour_bookings WHERE status = 'confirmed'");
    $stmt->execute();
    $confirmed_count = $stmt->get_result()->fetch_assoc()['confirmed_count'];
    $stmt->close();

    // Получение списка пользователей
    $stmt = $conn->prepare("SELECT id, username, email, role FROM users");
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Получение списка туров
    $stmt = $conn->prepare("SELECT id, title, destination, start_date, end_date, status FROM travels");
    $stmt->execute();
    $tours = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Получение списка бронирований
    $stmt = $conn->prepare("SELECT tb.id, u.username, tb.phone, t.title, tb.status, tb.created_at, tb.persons, p.name AS package_name, tb.price, tb.package_id 
                            FROM tour_bookings tb 
                            LEFT JOIN users u ON tb.user_id = u.id 
                            LEFT JOIN travels t ON tb.travel_id = t.id 
                            LEFT JOIN packages p ON tb.package_id = p.id");
    $stmt->execute();
    $bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Получение списка пакетов для формы бронирования
    $stmt = $conn->prepare("SELECT id, name FROM packages");
    $stmt->execute();
    $packages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Обработка отмены бронирования
    if (isset($_GET['cancel']) && isset($_GET['booking_id'])) {
        $booking_id = (int)$_GET['booking_id'];
        $stmt = $conn->prepare("DELETE FROM tour_bookings WHERE id = ?");
        $stmt->bind_param('i', $booking_id);
        $stmt->execute();
        $stmt->close();
        header('Location: admin.php');
        exit;
    }
} catch (mysqli_sql_exception $e) {
    error_log("Ошибка базы данных: " . $e->getMessage() . " (Код: " . $e->getCode() . ")", 3, 'C:/xampp/htdocs/travel/error.log');
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
    <style>
        :root {
            --primary: rgb(0, 68, 215);
            --secondary: #4A90E2;
            --white: #FFFFFF;
            --light-bg: #F7F9FC;
            --dark-text: #2D3748;
            --gray: #A0AEC0;
            --shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
            --gradient: linear-gradient(135deg, #FF6F61, #4A90E2);
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
        }

        .profile-avatar {
            text-align: center;
            margin-bottom: 30px;
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
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
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

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }

        .dashboard-card {
            background: var(--light-bg);
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            transition: transform 0.3s ease, background 0.3s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-5px);
            background: var(--secondary);
            color: var(--white);
        }

        .dashboard-card i {
            font-size: 30px;
            color: var(--primary);
            margin-bottom: 10px;
        }

        .dashboard-card h3 {
            font-size: 14px;
            margin-bottom: 10px;
            color: inherit;
        }

        .dashboard-card p {
            font-size: 24px;
            font-weight: 700;
            color: inherit;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th, .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid var(--light-bg);
        }

        .table th {
            background: var(--primary);
            color: var(--white);
        }

        .table tr:hover {
            background: var(--light-bg);
        }

        .table .btn {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 12px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .btn-edit {
            background: #34D399;
            color: var(--white);
        }

        .btn-edit:hover {
            background: #2DD4BF;
        }

        .btn-cancel {
            background: #EF4444;
            color: var(--white);
        }

        .btn-cancel:hover {
            background: #DC2626;
        }

        .no-data {
            text-align: center;
            color: var(--gray);
            padding: 20px;
        }

        /* Стили для модальных окон */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background: var(--white);
            padding: 20px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            box-shadow: var(--shadow);
            position: relative;
        }

        .modal-content h2 {
            font-size: 20px;
            margin-bottom: 20px;
            color: var(--dark-text);
        }

        .close {
            position: absolute;
            top: 10px;
            right: 15px;
            font-size: 24px;
            cursor: pointer;
            color: var(--gray);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 5px;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid var(--gray);
            border-radius: 5px;
            font-size: 14px;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn-save {
            background: var(--primary);
            color: var(--white);
            padding: 10px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .btn-save:hover {
            background: #0033A0;
        }

        .error, .success {
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 5px;
            text-align: center;
        }

        .error {
            background: #FEE2E2;
            color: #B91C1C;
        }

        .success {
            background: #D1FAE5;
            color: #065F46;
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

            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .table {
                font-size: 14px;
            }

            .modal-content {
                width: 95%;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="profile-avatar">
                <h1>Привет, <?= htmlspecialchars($user['username'] ?? 'Админ') ?>!</h1>
                <p>Администратор</p>
                <div class="stats">
                    <div class="stat-item"><span><?= $booking_count ?></span> Бронирования</div>
                </div>
            </div>
            <nav class="menu">
                <a href="admin.php" class="active"><i class="fas fa-tachometer-alt"></i> Обзор</a>
                <a href="admin_users.php"><i class="fas fa-users-cog"></i> Пользователи</a>
                <a href="admin_tours.php"><i class="fas fa-plane"></i> Туры</a>
                <a href="profile.php"><i class="fas fa-user"></i> Профиль</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> Выйти</a>
            </nav>
        </div>

        <div class="main-content">
            <h1 class="section-title"><i class="fas fa-tachometer-alt"></i> Обзор админ-панели</h1>
            <p>Добро пожаловать, <?= htmlspecialchars($user['username'] ?? 'Админ') ?>! Сегодня <?= date('d.m.Y H:i') ?> CEST.</p>

            <?php if ($error): ?>
                <div class="error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="success"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>

            <div class="section">
                <h2 class="section-title"><i class="fas fa-chart-pie"></i> Статистика</h2>
                <div class="dashboard-grid">
                    <div class="dashboard-card">
                        <i class="fas fa-users"></i>
                        <h3>Пользователи</h3>
                        <p><?= $user_count ?></p>
                    </div>
                    <div class="dashboard-card">
                        <i class="fas fa-plane"></i>
                        <h3>Активные туры</h3>
                        <p><?= $tour_count ?></p>
                    </div>
                    <div class="dashboard-card">
                        <i class="fas fa-suitcase"></i>
                        <h3>Все бронирования</h3>
                        <p><?= $booking_count ?></p>
                    </div>
                    <div class="dashboard-card">
                        <i class="fas fa-ticket-alt"></i>
                        <h3>Подтвержденные</h3>
                        <p><?= $confirmed_count ?></p>
                    </div>
                </div>
            </div>

            <div class="section">
                <h2 class="section-title"><i class="fas fa-users"></i> Пользователи</h2>
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
                                <?php if (!is_array($user_item)): ?>
                                    <tr><td colspan="5" class="no-data">Ошибка: Неверный формат данных пользователя: <?= htmlspecialchars($user_item) ?></td></tr>
                                <?php else: ?>
                                    <tr>
                                        <td><?= htmlspecialchars($user_item['id'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($user_item['username'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($user_item['email'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($user_item['role'] ?? '') ?></td>
                                        <td>
                                            <button class="btn btn-edit" onclick="openUserModal(<?= $user_item['id'] ?>, '<?= htmlspecialchars($user_item['username']) ?>', '<?= htmlspecialchars($user_item['email']) ?>', '<?= htmlspecialchars($user_item['role']) ?>')"><i class="fas fa-edit"></i> Редактировать</button>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <h2 class="section-title"><i class="fas fa-plane"></i> Туры</h2>
                <table class="table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Название</th>
                            <th>Направление</th>
                            <th>Дата начала</th>
                            <th>Дата окончания</th>
                            <th>Статус</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($tours)): ?>
                            <tr><td colspan="7" class="no-data">Нет туров в базе данных.</td></tr>
                        <?php else: ?>
                            <?php foreach ($tours as $tour): ?>
                                <tr>
                                    <td><?= htmlspecialchars($tour['id'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($tour['title'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($tour['destination'] ?? '') ?></td>
                                    <td><?= !empty($tour['start_date']) ? date('d.m.Y', strtotime($tour['start_date'])) : '-' ?></td>
                                    <td><?= !empty($tour['end_date']) ? date('d.m.Y', strtotime($tour['end_date'])) : '-' ?></td>
                                    <td><?= htmlspecialchars($tour['status'] ?? '') ?></td>
                                    <td>
                                        <button class="btn btn-edit" onclick="openTourModal(<?= $tour['id'] ?>, '<?= htmlspecialchars($tour['title']) ?>', '<?= htmlspecialchars($tour['destination']) ?>', '<?= htmlspecialchars($tour['start_date']) ?>', '<?= htmlspecialchars($tour['end_date']) ?>', '<?= htmlspecialchars($tour['status']) ?>')"><i class="fas fa-edit"></i> Редактировать</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="section">
                <h2 class="section-title"><i class="fas fa-suitcase"></i> Бронирования</h2>
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
                                    <td><?= number_format($booking['price'] ?? 0, 2, ',', ' ') ?> ₽</td>
                                    <td>
                                        <button class="btn btn-edit" onclick="openBookingModal(<?= $booking['id'] ?>, '<?= htmlspecialchars($booking['status']) ?>', <?= $booking['persons'] ?? 0 ?>, <?= $booking['package_id'] ?? 0 ?>)"><i class="fas fa-edit"></i> Редактировать</button>
                                        <a href="?cancel=1&booking_id=<?= urlencode($booking['id'] ?? '') ?>" class="btn btn-cancel" onclick="return confirm('Вы уверены, что хотите отменить бронирование?');"><i class="fas fa-times"></i> Отменить</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Модальное окно для редактирования пользователя -->
    <div id="userModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('userModal')">×</span>
            <h2>Редактировать пользователя</h2>
            <form method="POST">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" id="user_id" name="user_id">
                <div class="form-group">
                    <label for="user_username">Имя пользователя</label>
                    <input type="text" id="user_username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="user_email">Email</label>
                    <input type="email" id="user_email" name="email" required>
                </div>
                <div class="form-group">
                    <label for="user_role">Роль</label>
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

    <!-- Модальное окно для редактирования тура -->
    <div id="tourModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('tourModal')">×</span>
            <h2>Редактировать тур</h2>
            <form method="POST">
                <input type="hidden" name="edit_tour" value="1">
                <input type="hidden" id="tour_id" name="tour_id">
                <div class="form-group">
                    <label for="tour_title">Название</label>
                    <input type="text" id="tour_title" name="title" required>
                </div>
                <div class="form-group">
                    <label for="tour_destination">Направление</label>
                    <input type="text" id="tour_destination" name="destination" required>
                </div>
                <div class="form-group">
                    <label for="tour_start_date">Дата начала</label>
                    <input type="date" id="tour_start_date" name="start_date" required>
                </div>
                <div class="form-group">
                    <label for="tour_end_date">Дата окончания</label>
                    <input type="date" id="tour_end_date" name="end_date" required>
                </div>
                <div class="form-group">
                    <label for="tour_status">Статус</label>
                    <select id="tour_status" name="status" required>
                        <option value="active">Активен</option>
                        <option value="inactive">Неактивен</option>
                    </select>
                </div>
                <div class="form-group">
                    <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Сохранить</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Модальное окно для редактирования бронирования -->
    <div id="bookingModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('bookingModal')">×</span>
            <h2>Редактировать бронирование</h2>
            <form method="POST">
                <input type="hidden" name="edit_booking" value="1">
                <input type="hidden" id="booking_id" name="booking_id">
                <div class="form-group">
                    <label for="booking_status">Статус</label>
                    <select id="booking_status" name="status" required>
                        <option value="pending">Ожидает</option>
                        <option value="confirmed">Подтверждено</option>
                        <option value="cancelled">Отменено</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="booking_persons">Количество человек</label>
                    <input type="number" id="booking_persons" name="persons" min="1" required>
                </div>
                <div class="form-group">
                    <label for="booking_package_id">Пакет</label>
                    <select id="booking_package_id" name="package_id" required>
                        <?php foreach ($packages as $package): ?>
                            <option value="<?= $package['id'] ?>"><?= htmlspecialchars($package['name']) ?></option>
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
        function openUserModal(id, username, email, role) {
            document.getElementById('user_id').value = id;
            document.getElementById('user_username').value = username;
            document-ship.$email;
            document.getElementById('user_role').value = role;
            document.getElementById('userModal').style.display = 'flex';
        }

        function openTourModal(id, title, destination, start_date, end_date, status) {
            document.getElementById('tour_id').value = id;
            document.getElementById('tour_title').value = title;
            document.getElementById('tour_destination').value = destination;
            document.getElementById('tour_start_date').value = start_date;
            document.getElementById('tour_end_date').value = end_date;
            document.getElementById('tour_status').value = status;
            document.getElementById('tourModal').style.display = 'flex';
        }

        function openBookingModal(id, status, persons, package_id) {
            document.getElementById('booking_id').value = id;
            document.getElementById('booking_status').value = status;
            document.getElementById('booking_persons').value = persons;
            document.getElementById('booking_package_id').value = package_id;
            document.getElementById('bookingModal').style.display = 'flex';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Закрытие модального окна при клике вне формы
        window.onclick = function(event) {
            const modals = document.getElementsByClassName('modal');
            for (let modal of modals) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            }
        }
    </script>
</body>
</html>