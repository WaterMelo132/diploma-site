<?php
ob_start();
session_start();
require_once __DIR__ . '/config.php';
require_once 'navbar.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Session expired']);
        exit;
    }
    header("Location: login.php");
    exit;
}

// Включение отладки в dev-среде
$is_dev = in_array($_SERVER['SERVER_NAME'], ['localhost', '127.0.0.1']);
if ($is_dev) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}

// Получение данных пользователя
$user_id = (int)$_SESSION['user_id'];
$stmt = $conn->prepare("SELECT email, username, role FROM users WHERE id = ?");
if (!$stmt) {
    error_log("Prepare failed for user query: " . $conn->error);
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Database error']);
        exit;
    }
    header("Location: login.php");
    exit;
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result->num_rows) {
    error_log("User ID $user_id not found");
    if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'User not found']);
        exit;
    }
    header("Location: login.php");
    exit;
}
$user = $result->fetch_assoc();
$user_username = htmlspecialchars($user['username'] ?? $user['email']);
$user_role = $user['role'];
$stmt->close();

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['message'])) {
        $message = trim($_POST['message']);
        $recipient_id = $user_role === 'admin' ? (int)$_POST['recipient_id'] : getAdminId($conn);

        if ($message && $recipient_id) {
            $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->bind_param("i", $recipient_id);
            $stmt->execute();
            if (!$stmt->get_result()->num_rows) {
                error_log("Recipient ID $recipient_id does not exist");
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Recipient not found']);
                exit;
            }
            $stmt->close();

            $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, recipient_id, message) VALUES (?, ?, ?)");
            if (!$stmt) {
                error_log("Prepare failed for message insert: " . $conn->error);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Database error']);
                exit;
            }
            $stmt->bind_param("iis", $user_id, $recipient_id, $message);
            if ($stmt->execute()) {
                $update_stmt = $conn->prepare("UPDATE users SET last_activity = NOW() WHERE id = ?");
                $update_stmt->bind_param("i", $user_id);
                $update_stmt->execute();
                $update_stmt->close();

                error_log("Message saved: sender_id=$user_id, recipient_id=$recipient_id");
                if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest') {
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'message' => [
                            'id' => $stmt->insert_id,
                            'sender_id' => $user_id,
                            'message' => htmlspecialchars($message),
                            'created_at' => date('H:i')
                        ]
                    ]);
                    exit;
                }
            } else {
                error_log("Message save error: " . $stmt->error);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Message save failed']);
                exit;
            }
            $stmt->close();
        } else {
            error_log("Empty message or invalid recipient_id ($recipient_id)");
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Empty message or invalid recipient']);
            exit;
        }
    }

    if (isset($_FILES['file_upload'])) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 'text/plain'];
        $max_size = 5 * 1024 * 1024;

        if (in_array($_FILES['file_upload']['type'], $allowed_types) && $_FILES['file_upload']['size'] <= $max_size) {
            $file_ext = pathinfo($_FILES['file_upload']['name'], PATHINFO_EXTENSION);
            $file_name = uniqid('file_') . '.' . $file_ext;
            $file_path = 'Uploads/chat_files/' . $file_name;

            if (!is_dir('Uploads/chat_files')) {
                mkdir('Uploads/chat_files', 0755, true);
            }

            if (move_uploaded_file($_FILES['file_upload']['tmp_name'], $file_path)) {
                $recipient_id = $user_role === 'admin' ? (int)$_POST['recipient_id'] : getAdminId($conn);
                $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, recipient_id, message, attachment) VALUES (?, ?, ?, ?)");
                if (!$stmt) {
                    error_log("Prepare failed for file insert: " . $conn->error);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'Database error']);
                    exit;
                }
                $message = "File attached: " . $_FILES['file_upload']['name'];
                $stmt->bind_param("iiss", $user_id, $recipient_id, $message, $file_path);
                if ($stmt->execute()) {
                    $stmt->close();
                    header('Content-Type: application/json');
                    echo json_encode([
                        'success' => true,
                        'file_url' => $file_path,
                        'file_name' => $_FILES['file_upload']['name']
                    ]);
                    exit;
                } else {
                    error_log("File save error: " . $stmt->error);
                    header('Content-Type: application/json');
                    echo json_encode(['error' => 'File save failed']);
                    exit;
                }
            } else {
                error_log("File upload failed for: " . $_FILES['file_upload']['name']);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'File upload failed']);
                exit;
            }
        } else {
            error_log("Invalid file type or size for: " . $_FILES['file_upload']['name']);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid file type or size']);
            exit;
        }
    }
}

function getAdminId($conn) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    if (!$stmt) {
        error_log("Prepare failed for getAdminId: " . $conn->error);
        return null;
    }
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$result) {
        error_log("Admin not found in database");
        return null;
    }
    return $result['id'];
}

// Получение сообщений
$messages = [];
$selected_user_id = (int)($_GET['user_id'] ?? 0);

if ($user_role === 'admin' && $selected_user_id) {
    $update_stmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ?");
    if (!$update_stmt) {
        error_log("Prepare failed for update read status: " . $conn->error);
    } else {
        $update_stmt->bind_param("ii", $selected_user_id, $user_id);
        $update_stmt->execute();
        $update_stmt->close();
    }

    $stmt = $conn->prepare("SELECT cm.*, u.username 
                           FROM chat_messages cm 
                           JOIN users u ON cm.sender_id = u.id 
                           WHERE (cm.sender_id = ? AND cm.recipient_id = ?) 
                           OR (cm.sender_id = ? AND cm.recipient_id = ?)
                           ORDER BY cm.created_at ASC");
    if (!$stmt) {
        error_log("Prepare failed for messages query: " . $conn->error);
    } else {
        $stmt->bind_param("iiii", $selected_user_id, $user_id, $user_id, $selected_user_id);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        error_log("Admin: Loaded " . count($messages) . " messages for user_id=$selected_user_id");
    }
} elseif ($user_role !== 'admin') {
    $admin_id = getAdminId($conn);
    if ($admin_id) {
        $update_stmt = $conn->prepare("UPDATE chat_messages SET is_read = 1 WHERE sender_id = ? AND recipient_id = ?");
        if (!$update_stmt) {
            error_log("Prepare failed for update read status: " . $conn->error);
        } else {
            $update_stmt->bind_param("ii", $admin_id, $user_id);
            $update_stmt->execute();
            $update_stmt->close();
        }

        $stmt = $conn->prepare("SELECT cm.*, u.username 
                               FROM chat_messages cm 
                               JOIN users u ON cm.sender_id = u.id 
                               WHERE (cm.sender_id = ? AND cm.recipient_id = ?) 
                               OR (cm.sender_id = ? AND cm.recipient_id = ?)
                               ORDER BY cm.created_at ASC");
        if (!$stmt) {
            error_log("Prepare failed for messages query: " . $conn->error);
        } else {
            $stmt->bind_param("iiii", $user_id, $admin_id, $admin_id, $user_id);
            $stmt->execute();
            $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            error_log("User: Loaded " . count($messages) . " messages for user_id=$user_id");
        }
    }
}

// Получение списка пользователей
$users = [];
if ($user_role === 'admin') {
    $stmt = $conn->prepare("SELECT u.id, u.username, u.last_activity, 
                           (SELECT COUNT(*) FROM chat_messages WHERE sender_id = u.id AND recipient_id = ? AND is_read = 0) as unread_count
                           FROM users u 
                           WHERE u.id != ? 
                           ORDER BY u.last_activity DESC");
    if (!$stmt) {
        error_log("Prepare failed for users query: " . $conn->error);
    } else {
        $stmt->bind_param("ii", $user_id, $user_id);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
} else {
    $stmt = $conn->prepare("SELECT u.id, u.username, u.last_activity,
                           (SELECT COUNT(*) FROM chat_messages WHERE sender_id = u.id AND recipient_id = ? AND is_read = 0) as unread_count
                           FROM users u 
                           WHERE u.role = 'admin'");
    if (!$stmt) {
        error_log("Prepare failed for users query: " . $conn->error);
    } else {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }
}

// AJAX-запрос для сообщений
if (isset($_GET['ajax']) && $_GET['ajax'] === 'messages') {
    error_log("AJAX request for messages, user_id: " . ($_GET['user_id'] ?? 'none'));
    ob_clean(); // Clear any previous output
    header('Content-Type: application/json');

    // Validate user_id
    $selected_user_id = (int)($_GET['user_id'] ?? 0);
    if (!$selected_user_id) {
        echo json_encode(['error' => 'Invalid user ID']);
        exit;
    }

    // Verify recipient exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
    if (!$stmt) {
        error_log("Prepare failed for user check: " . $conn->error);
        echo json_encode(['error' => 'Database error']);
        exit;
    }
    $stmt->bind_param("i", $selected_user_id);
    $stmt->execute();
    if (!$stmt->get_result()->num_rows) {
        $stmt->close();
        echo json_encode(['error' => 'Recipient not found']);
        exit;
    }
    $stmt->close();

    // Return messages
    echo json_encode([
        'messages' => array_map(function($msg) use ($user_id) {
            $msg['is_sent'] = $msg['sender_id'] == $user_id;
            $msg['time'] = date('H:i', strtotime($msg['created_at']));
            return $msg;
        }, $messages)
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $user_role === 'admin' ? 'Админ-чат' : 'Чат поддержки' ?></title>
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #6200EA;
            --primary-dark: #3700B3;
            --light-bg: #FFFFFF;
            --lighter-bg: #F5F5F5;
            --dark-text: #212121;
            --darker-text: #757575;
            --border-color: #E0E0E0;
            --success-color: #4CAF50;
            --online-status: #4CAF50;
            --offline-status: #F44336;
            --away-status: #FFC107;
            --transition: all 0.3s ease;
            --shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

       body {
    font-family: 'Roboto', sans-serif;
    background-image: url('https://images.unsplash.com/photo-1507525428034-b723cf961d3e');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
    color: var(--dark-text);
    height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    overflow: hidden;
}

        .app-container {
            display: flex;
            height: 80vh;
            width: 80%;
            max-width: 1000px;
            background-color: var(--light-bg);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--shadow);
            position: relative;
        }

        .loading-screen {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: var(--light-bg);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .loading-screen.active {
            opacity: 1;
            visibility: visible;
        }

        .loader {
            border: 4px solid var(--lighter-bg);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            margin-top: 10px;
            font-size: 14px;
            color: var(--dark-text);
        }

        .sidebar {
            width: 250px;
            background-color: var(--lighter-bg);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
        }

        .sidebar-header {
            padding: 10px;
            background-color: var(--light-bg);
            border-bottom: 1px solid var(--border-color);
        }

        .user-name {
            font-weight: 500;
            font-size: 14px;
        }

        .user-status {
            font-size: 10px;
            color: var(--darker-text);
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background-color: var(--online-status);
        }

        .sidebar-search {
            padding: 8px;
            border-bottom: 1px solid var(--border-color);
        }

        .search-input {
            width: 100%;
            padding: 6px 10px;
            background-color: var(--light-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--dark-text);
            font-size: 12px;
        }

        .search-input:focus {
            outline: none;
            background-color: var(--lighter-bg);
            border-color: var(--primary-color);
        }

        .conversation-list {
            flex-grow: 1;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) var(--lighter-bg);
        }

        .conversation-list::-webkit-scrollbar {
            width: 5px;
        }

        .conversation-list::-webkit-scrollbar-track {
            background: var(--lighter-bg);
        }

        .conversation-list::-webkit-scrollbar-thumb {
            background-color: var(--primary-color);
            border-radius: 3px;
        }

        .conversation-item {
            padding: 8px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
        }

        .conversation-item:hover {
            background-color: var(--light-bg);
        }

        .conversation-item.active {
            background-color: var(--lighter-bg);
        }

        .conversation-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }

        .conversation-name {
            font-weight: 500;
            font-size: 13px;
        }

        .conversation-time {
            font-size: 10px;
            color: var(--darker-text);
        }

        .conversation-preview {
            font-size: 11px;
            color: var(--darker-text);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .unread-badge {
            float: right;
            background-color: var(--primary-color);
            color: white;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
        }

        .chat-area {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            background-color: var(--light-bg);
        }

        .chat-header {
            padding: 8px 10px;
            background-color: var(--lighter-bg);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
        }

        .chat-back-btn {
            display: none;
            background: none;
            border: none;
            color: var(--dark-text);
            cursor: pointer;
            font-size: 18px;
        }

        .chat-user-info {
            flex-grow: 1;
        }

        .chat-user-name {
            font-weight: 500;
            font-size: 14px;
        }

        .chat-user-status {
            font-size: 10px;
            color: var(--darker-text);
            display: flex;
            align-items: center;
            gap: 3px;
        }

        .chat-messages {
            flex-grow: 1;
            padding: 10px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: var(--primary-color) var(--light-bg);
        }

        .chat-messages::-webkit-scrollbar {
            width: 5px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: var(--light-bg);
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background-color: var(--primary-color);
            border-radius: 3px;
        }

        .message-container {
            display: flex;
            margin-bottom: 8px;
        }

        .message-container.sent {
            justify-content: flex-end;
        }

        .message-container.received {
            justify-content: flex-start;
        }

        .message-content {
            max-width: 70%;
        }

        .message-bubble {
            padding: 8px 12px;
            border-radius: 12px;
            word-wrap: break-word;
            box-shadow: var(--shadow);
        }

        .message-container.sent .message-bubble {
            background-color: var(--primary-color);
            color: white;
            border-bottom-right-radius: 4px;
        }

        .message-container.received .message-bubble {
            background-color: var(--lighter-bg);
            color: var(--dark-text);
            border-bottom-left-radius: 4px;
        }

        .message-time {
            font-size: 10px;
            color: var(--darker-text);
            margin-top: 2px;
            text-align: right;
        }

        .message-status.read {
            color: var(--success-color);
        }

        .message-file {
            padding: 6px;
            background-color: rgba(0, 0, 0, 0.05);
            border-radius: 5px;
            margin-top: 2px;
        }

        .file-link {
            display: flex;
            align-items: center;
            gap: 4px;
            color: inherit;
            text-decoration: none;
        }

        .file-name {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            font-size: 12px;
        }

        .file-size {
            font-size: 10px;
            color: var(--darker-text);
        }

        .no-messages {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            color: var(--darker-text);
            text-align: center;
        }

        .chat-input-container {
            padding: 8px;
            background-color: var(--lighter-bg);
            border-top: 1px solid var(--border-color);
        }

        .message-form {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .message-input {
            flex-grow: 1;
            padding: 8px 12px;
            background-color: var(--light-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            color: var(--dark-text);
            font-size: 13px;
            resize: none;
            max-height: 80px;
        }

        .message-input:focus {
            outline: none;
            background-color: var(--lighter-bg);
            border-color: var(--primary-color);
        }

        .send-btn {
            background-color: var(--primary-color);
            border: none;
            border-radius: 50%;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: white;
        }

        .send-btn:hover {
            background-color: var(--primary-dark);
        }

        .send-btn:disabled {
            background-color: var(--darker-text);
            cursor: not-allowed;
        }

        .file-input {
            display: none;
        }

        @media (max-width: 768px) {
            .sidebar {
                position: absolute;
                left: 0;
                top: 0;
                bottom: 0;
                z-index: 100;
                width: 100%;
                transform: translateX(-100%);
                transition: var(--transition);
            }

            .sidebar.active {
                transform: translateX(0);
            }

            .chat-back-btn {
                display: block;
            }

            .chat-area {
                width: 100%;
            }

            .app-container {
                width: 100%;
                height: 100vh;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <div class="app-container">
        <div class="loading-screen" id="loading-screen">
            <div class="loader"></div>
            <div class="loading-text">Загрузка...</div>
        </div>
        <div class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <div class="user-name"><?= $user_username ?></div>
                <div class="user-status">
                    <span class="status-dot"></span>
                    <span>Online</span>
                </div>
            </div>
            <div class="sidebar-search">
                <input type="text" class="search-input" placeholder="Поиск...">
            </div>
            <div class="conversation-list">
                <?php if (empty($users)): ?>
                    <div style="padding: 10px; text-align: center; color: var(--darker-text);">Нет чатов</div>
                <?php else: ?>
                    <?php foreach ($users as $u): 
                        $last_message = getLastMessage($conn, $user_id, $u['id']);
                        $unread_count = $u['unread_count'] ?? 0;
                    ?>
                        <div class="conversation-item <?= $selected_user_id == $u['id'] ? 'active' : '' ?>" 
                             data-user-id="<?= $u['id'] ?>"
                             onclick="loadChat(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username'] ?? $u['email']) ?>')">
                            <div class="conversation-header">
                                <div class="conversation-name"><?= htmlspecialchars($u['username'] ?? $u['email']) ?></div>
                                <div class="conversation-time"><?= $last_message ? formatTime($last_message['created_at']) : '' ?></div>
                            </div>
                            <div class="conversation-preview">
                                <?php if ($last_message): ?>
                                    <?= $last_message['sender_id'] == $user_id ? '<i class="material-icons">done_all</i>' : '' ?>
                                    <?= htmlspecialchars(mb_strlen($last_message['message']) > 30 ? mb_substr($last_message['message'], 0, 30) . '...' : $last_message['message']) ?>
                                <?php else: ?>
                                    Нет сообщений
                                <?php endif; ?>
                                <?php if ($unread_count > 0): ?>
                                    <div class="unread-badge"><?= $unread_count ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="chat-area">
            <?php if ($user_role === 'admin' && !$selected_user_id): ?>
                <div class="no-messages">
                    <h3>Выберите чат</h3>
                    <p>Выберите пользователя слева</p>
                </div>
            <?php else: ?>
                <div class="chat-header">
                    <button class="chat-back-btn" onclick="toggleSidebar()">
                        <i class="material-icons">menu</i>
                    </button>
                    <div class="chat-user-info">
                        <?php
                        $chat_user = null;
                        if ($user_role === 'admin' && $selected_user_id) {
                            $stmt = $conn->prepare("SELECT username, last_activity FROM users WHERE id = ?");
                            $stmt->bind_param("i", $selected_user_id);
                            $stmt->execute();
                            $chat_user = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                        } elseif ($user_role !== 'admin') {
                            $stmt = $conn->prepare("SELECT username, last_activity FROM users WHERE role = 'admin' LIMIT 1");
                            $stmt->execute();
                            $chat_user = $stmt->get_result()->fetch_assoc();
                            $stmt->close();
                        }
                        $chat_status = getUserStatus($chat_user['last_activity'] ?? null);
                        ?>
                        <div class="chat-user-name"><?= htmlspecialchars($chat_user['username'] ?? $user['email'] ?? 'Пользователь') ?></div>
                        <div class="chat-user-status">
                            <span class="status-dot" style="background-color: <?= $chat_status['color'] ?>;"></span>
                            <span><?= $chat_status['text'] ?></span>
                        </div>
                    </div>
                </div>
                <div class="chat-messages" id="chat-messages">
                    <?php foreach ($messages as $msg): 
                        $is_sent = $msg['sender_id'] == $user_id;
                        $status_icon = $is_sent && isset($msg['is_read']) && $msg['is_read'] ? 'done_all' : 'done';
                    ?>
                        <div class="message-container <?= $is_sent ? 'sent' : 'received' ?>">
                            <div class="message-content">
                                <div class="message-bubble">
                                    <?= htmlspecialchars($msg['message']) ?>
                                    <?php if (isset($msg['attachment'])): ?>
                                        <a href="<?= $msg['attachment'] ?>" target="_blank" class="message-file file-link">
                                            <div class="file-name"><?= basename($msg['attachment']) ?></div>
                                            <div class="file-size"><?= round(filesize($msg['attachment']) / 1024, 1) ?> KB</div>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="message-time">
                                    <?= date('H:i', strtotime($msg['created_at'])) ?>
                                    <?php if ($is_sent): ?>
                                        <i class="material-icons message-status <?= $msg['is_read'] ? 'read' : '' ?>" style="font-size: 12px; vertical-align: middle;">
                                            <?= $status_icon ?>
                                        </i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="chat-input-container">
                    <form class="message-form" id="message-form" method="POST" enctype="multipart/form-data">
                        <label class="tool-btn">
                            <i class="material-icons">attach_file</i>
                            <input type="file" name="file_upload" class="file-input" id="file-input">
                        </label>
                        <textarea class="message-input" name="message" placeholder="Сообщение..."></textarea>
                        <button type="submit" class="send-btn" id="send-btn">
                            <i class="material-icons">send</i>
                        </button>
                        <input type="hidden" name="recipient_id" value="<?= $selected_user_id ?: getAdminId($conn) ?>">
                    </form>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    let currentUserId = <?= $selected_user_id ?: ($user_role === 'admin' ? 'null' : getAdminId($conn)) ?>;
    let isAdmin = <?= $user_role === 'admin' ? 'true' : 'false' ?>;

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
    }

    function showLoadingScreen() {
        document.getElementById('loading-screen').classList.add('active');
    }

    function loadChat(userId, userName) {
        showLoadingScreen();
        window.location.href = `chat.php?user_id=${userId}`;
    }

    function scrollToBottom() {
        const chatMessages = document.getElementById('chat-messages');
        if (chatMessages) {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
    }

    function fetchMessages(userId) {
        if (!userId) return;
        
        fetch(`chat.php?ajax=messages&user_id=${userId}&_=${Date.now()}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                const chatMessages = document.getElementById('chat-messages');
                if (!chatMessages) return;
                
                if (data.error) {
                    console.error('Server error:', data.error);
                    return;
                }

                if (data.messages.length === 0) {
                    chatMessages.innerHTML = '<div class="no-messages"><h3>Нет сообщений</h3><p>Начните общение</p></div>';
                } else {
                    chatMessages.innerHTML = data.messages.map(msg => `
                        <div class="message-container ${msg.is_sent ? 'sent' : 'received'}">
                            <div class="message-content">
                                <div class="message-bubble">
                                    ${msg.message}
                                    ${msg.attachment ? `<a href="${msg.attachment}" target="_blank" class="message-file file-link">
                                        <div class="file-name">${msg.attachment.split('/').pop()}</div>
                                        <div class="file-size">${Math.round(msg.attachment_size / 1024) || 'N/A'} KB</div>
                                    </a>` : ''}
                                </div>
                                <div class="message-time">
                                    ${msg.time}
                                    ${msg.is_sent ? `<i class="material-icons message-status ${msg.is_read ? 'read' : ''}" style="font-size: 12px; vertical-align: middle;">
                                        ${msg.is_read ? 'done_all' : 'done'}
                                    </i>` : ''}
                                </div>
                            </div>
                        </div>
                    `).join('');
                }
                scrollToBottom();
            })
            .catch(error => {
                console.error('Ошибка загрузки сообщений:', error);
            });
    }

    // Обработчик отправки сообщения
    document.getElementById('message-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const messageInput = this.querySelector('.message-input');
        const sendBtn = document.getElementById('send-btn');
        
        if (!messageInput.value.trim() && !formData.get('file_upload').name) return;
        
        sendBtn.disabled = true;
        
        fetch('chat.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                messageInput.value = '';
                messageInput.style.height = 'auto';
                
                if (data.file_url) {
                    const chatMessages = document.getElementById('chat-messages');
                    if (chatMessages.querySelector('.no-messages')) {
                        chatMessages.innerHTML = '';
                    }
                    
                    chatMessages.insertAdjacentHTML('beforeend', `
                        <div class="message-container sent">
                            <div class="message-content">
                                <div class="message-bubble">
                                    <a href="${data.file_url}" target="_blank" class="message-file file-link">
                                        <div class="file-name">${data.file_name}</div>
                                    </a>
                                </div>
                                <div class="message-time">
                                    ${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}
                                    <i class="material-icons message-status" style="font-size: 12px; vertical-align: middle;">done</i>
                                </div>
                            </div>
                        </div>
                    `);
                }
                
                fetchMessages(currentUserId);
            } else {
                console.error('Ошибка отправки:', data.error);
            }
        })
        .catch(error => {
            console.error('Ошибка:', error);
        })
        .finally(() => {
            sendBtn.disabled = false;
        });
    });

    // Обработчик загрузки файла
    document.getElementById('file-input')?.addEventListener('change', function() {
        if (this.files.length > 0) {
            document.getElementById('message-form').dispatchEvent(new Event('submit'));
        }
    });

    // Автоматическое увеличение высоты textarea
    document.querySelector('.message-input')?.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = (this.scrollHeight) + 'px';
    });

    // Инициализация при загрузке
    document.addEventListener('DOMContentLoaded', function() {
        if (currentUserId) {
            fetchMessages(currentUserId);
        }
        scrollToBottom();
        document.getElementById('loading-screen').classList.remove('active');
    });

    // Периодическое обновление сообщений
    setInterval(() => {
        if (currentUserId) {
            fetchMessages(currentUserId);
        }
    }, 5000);
</script>
</body>
</html>

<?php
function getLastMessage($conn, $user_id, $other_user_id) {
    $stmt = $conn->prepare("SELECT * FROM chat_messages 
                           WHERE (sender_id = ? AND recipient_id = ?) 
                           OR (sender_id = ? AND recipient_id = ?) 
                           ORDER BY created_at DESC LIMIT 1");
    if (!$stmt) {
        error_log("Prepare failed for getLastMessage: " . $conn->error);
        return null;
    }
    $stmt->bind_param("iiii", $user_id, $other_user_id, $other_user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result;
}

function getUserStatus($last_activity) {
    if (!$last_activity) return ['text' => 'Offline', 'color' => 'var(--offline-status)'];
    $diff = time() - strtotime($last_activity);
    if ($diff < 300) return ['text' => 'Online', 'color' => 'var(--online-status)'];
    if ($diff < 1800) return ['text' => 'Away', 'color' => 'var(--away-status)'];
    return ['text' => 'Offline', 'color' => 'var(--offline-status)'];
}

function formatTime($datetime) {
    $time = strtotime($datetime);
    $diff = time() - $time;
    if ($diff < 86400) return date('H:i', $time);
    if ($diff < 172800) return 'Yesterday';
    if ($diff < 604800) return date('D', $time);
    return date('d.m.Y', $time);
}

ob_end_flush();
?>