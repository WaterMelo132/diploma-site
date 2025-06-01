<?php
// /travel/php/chat.php
session_start();
require_once __DIR__ . '/config.php';
require_once('navbar.php'); // Подключает config.php, где есть $conn

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Включение отладки
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Получение данных пользователя
$user_id = $_SESSION['user_id'];
$stmt = $conn->prepare("SELECT email, role FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
if (!$result->num_rows) {
    error_log("Ошибка: Пользователь с ID $user_id не найден");
    header("Location: login.php");
    exit();
}
$user = $result->fetch_assoc();
$user_email = htmlspecialchars($user['email']);
$user_role = $user['role'];

// Обработка отправки сообщения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['message'])) {
    $message = trim($_POST['message']);
    $recipient_id = ($user_role === 'admin') ? (int)$_POST['recipient_id'] : getAdminId($conn);
    
    if (!empty($message) && $recipient_id) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE id = ?");
        $stmt->bind_param("i", $recipient_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows === 0) {
            error_log("Ошибка: Получатель с ID $recipient_id не существует");
            exit("Ошибка: Получатель не существует");
        }
        
        $stmt = $conn->prepare("INSERT INTO chat_messages (sender_id, recipient_id, message) VALUES (?, ?, ?)");
        $stmt->bind_param("iis", $user_id, $recipient_id, $message);
        if ($stmt->execute()) {
            error_log("Сообщение успешно сохранено: sender_id=$user_id, recipient_id=$recipient_id, message=$message");
        } else {
            error_log("Ошибка сохранения сообщения: " . $stmt->error);
            exit("Ошибка сохранения сообщения: " . $stmt->error);
        }
        $stmt->close();
    } else {
        error_log("Ошибка: Пустое сообщение или неверный recipient_id ($recipient_id)");
        exit("Ошибка: Пустое сообщение или неверный получатель");
    }
    header("Location: chat.php" . ($user_role === 'admin' && $recipient_id ? "?user_id=$recipient_id" : ""));
    exit();
}

// Функция для получения ID админа
function getAdminId($conn) {
    $stmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $admin = $result->fetch_assoc();
    if (!$admin) {
        error_log("Ошибка: Админ не найден в базе данных");
        return null;
    }
    return $admin['id'];
}

// Получение сообщений
$messages = [];
$selected_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($user_role === 'admin' && $selected_user_id) {
    $stmt = $conn->prepare("SELECT cm.*, u.email AS sender_email 
                           FROM chat_messages cm 
                           JOIN users u ON cm.sender_id = u.id 
                           WHERE cm.sender_id = ? OR cm.recipient_id = ? 
                           ORDER BY cm.created_at ASC");
    $stmt->bind_param("ii", $selected_user_id, $selected_user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    error_log("Админ: Загружено " . count($messages) . " сообщений для user_id=$selected_user_id");
} elseif ($user_role !== 'admin') {
    $stmt = $conn->prepare("SELECT cm.*, u.email AS sender_email 
                           FROM chat_messages cm 
                           JOIN users u ON cm.sender_id = u.id 
                           JOIN users admins ON admins.id = cm.sender_id OR admins.id = cm.recipient_id 
                           WHERE (cm.sender_id = ? OR cm.recipient_id = ?) 
                           AND admins.role = 'admin' 
                           ORDER BY cm.created_at ASC");
    $stmt->bind_param("ii", $user_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $messages = $result->fetch_all(MYSQLI_ASSOC);
    error_log("Пользователь: Загружено " . count($messages) . " сообщений для user_id=$user_id");
}

// Получение списка пользователей для админа
$users = [];
if ($user_role === 'admin') {
    $stmt = $conn->prepare("SELECT id, email FROM users WHERE role = 'user'");
    $stmt->execute();
    $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Чат</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Outlined" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        :root {
            --bg-color: #f8fafc;
            --chat-bg: #ffffff;
            --text-color: #1e293b;
            --sent-bg: #4f46e5;
            --received-bg: #e5e7eb;
            --accent-color: #4f46e5;
            --border-color: #e5e7eb;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            --hover-bg: #f1f5f9;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        [data-theme="dark"] {
            --bg-color: #1e293b;
            --chat-bg: #2d3748;
            --text-color: #e5e7eb;
            --sent-bg: #6366f1;
            --received-bg: #4b5563;
            --accent-color: #6366f1;
            --border-color: #4b5563;
            --shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
            --hover-bg: #374151;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            line-height: 1.6;
            background: linear-gradient(rgba(0, 0, 0, 0.3), rgba(0, 0, 0, 0.3)),
                        url('https://images.unsplash.com/photo-1506929562872-bb421503ef21?ixlib=rb-1.2.1&auto=format&fit=crop&w=1350&q=80');
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: white;
            min-height: 100vh;
        }

        .chat-container {
            margin-top: 10px;
            max-width: 1000px;
            margin-left: auto;
            margin-right: auto;
            padding: 24px;
            background: var(--chat-bg);
            border-radius: 16px;
            box-shadow: var(--shadow);
            animation: slideIn 0.5s ease-out;
        }

        .chat-header {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #AB4CFE;
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border-color);
        }

        .chat-header h2 {
            margin: 0;
            font-size: 1.75rem;
            font-weight: 600;
            color: #AB4CFE;
        }

        .user-select select {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            background: var(--chat-bg);
            color: black;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
        }

        .user-select select:focus {
            outline: none;
            border-color: var(--accent-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .chat-messages {
            height: 550px;
            overflow-y: auto;
            padding: 16px;
            background: var(--chat-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            margin-bottom: 24px;
            scroll-behavior: smooth;
        }

        .chat-messages::-webkit-scrollbar {
            width: 8px;
        }

        .chat-messages::-webkit-scrollbar-track {
            background: var(--bg-color);
        }

        .chat-messages::-webkit-scrollbar-thumb {
            background: var(--accent-color);
            border-radius: 4px;
        }

        .message {
            display: flex;
            margin: 12px 0;
            animation: messageFadeIn 0.4s ease-out;
        }

        .message.sent {
            justify-content: flex-end;
        }

        .message.received {
            justify-content: flex-start;
        }

        .message-content {
            max-width: 70%;
            padding: 12px 16px;
            border-radius: 16px;
            background: var(--received-bg);
            color:rgb(0, 0, 0);
            position: relative;
            box-shadow: var(--shadow);
            transition: transform 0.2s ease;
        }

        .message-content:hover {
            transform: translateY(-2px);
        }

        .message.sent .message-content {
            background: var(--sent-bg);
            color: #ffffff;
            border-bottom-right-radius: 4px;
        }

        .message.received .message-content {
            border-bottom-left-radius: 4px;
        }

        .message .sender {
            font-size: 0.9rem;
            font-weight: 500;
            margin-bottom: 4px;
            color: var(--text-color);
            opacity: 0.8;
        }

        .message .content {
            font-size: 1rem;
            line-height: 1.5;
        }

        .message .timestamp {
            font-size: 0.8rem;
            color: var(--text-color);
            opacity: 0.6;
            margin-top: 4px;
            text-align: right;
        }

        .message-form {
            display: flex;
            gap: 12px;
            background: var(--chat-bg);
            padding: 12px;
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
        }

        .message-form input[type="text"] {
            flex-grow: 1;
            padding: 12px 16px;
            border: none;
            border-radius: 8px;
            background: var(--bg-color);
            color: var(--text-color);
            font-size: 1rem;
            resize: none;
            min-height: 48px;
            max-height: 120px;
            overflow-y: auto;
            transition: var(--transition);
        }

        .message-form input[type="text"]:focus {
            outline: none;
            background: var(--chat-bg);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .message-form button {
            padding: 12px 24px;
            background: var(--accent-color);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 500;
            font-size: 1rem;
            transition: var(--transition);
        }

        .message-form button:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }

        .message-form button:active {
            transform: translateY(0);
        }

        .no-user-selected {
            text-align: center;
            color: var(--text-color);
            padding: 24px;
            font-size: 1.1rem;
            opacity: 0.7;
            animation: fadeIn 0.5s ease-out;
        }

        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1001;
        }

        .theme-toggle button {
            padding: 12px;
            background: var(--accent-color);
            color: #ffffff;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            box-shadow: var(--shadow);
            transition: var(--transition);
        }

        .theme-toggle button:hover {
            background: #4338ca;
            transform: rotate(15deg);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes messageFadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @media (max-width: 600px) {
            .chat-container {
                margin-top: 60px;
                padding: 16px;
                border-radius: 12px;
            }

            .chat-messages {
                height: 450px;
            }

            .message-content {
                max-width: 85%;
            }

            .message-form {
                flex-direction: column;
                gap: 8px;
            }

            .message-form input[type="text"] {
                width: 100%;
            }

            .message-form button {
                width: 100%;
                padding: 12px;
            }

            .chat-header h2 {
                font-size: 1.5rem;
            }

            .theme-toggle {
                top: 12px;
                right: 12px;
            }
        }
    </style>
</head>
<body>

    
    <div class="chat-container">
        <div class="chat-header">
            <span class="material-icons-outlined">chat</span>
            <h2>Чат с администратором</h2>
        </div>
        
        <?php if ($user_role === 'admin'): ?>
            <div class="user-select">
                <select onchange="location.href='chat.php?user_id='+this.value">
                    <option value="">Выберите пользователя</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= $u['id'] ?>" <?= $selected_user_id == $u['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($u['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
        
        <div class="chat-messages" id="chat-messages">
            <?php if ($user_role === 'admin' && !$selected_user_id): ?>
                <div class="no-user-selected">Выберите пользователя для просмотра сообщений</div>
            <?php elseif (empty($messages) && $user_role !== 'admin'): ?>
                <div class="no-user-selected">Нет сообщений в чате</div>
            <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                    <div class="message <?= $msg['sender_id'] == $user_id ? 'sent' : 'received' ?>">
                        <div class="message-content">
                            <div class="sender"><?= htmlspecialchars($msg['sender_email']) ?></div>
                            <div class="content"><?= htmlspecialchars($msg['message']) ?></div>
                            <div class="timestamp"><?= $msg['created_at'] ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($user_role !== 'admin' || $selected_user_id): ?>
            <form class="message-form" id="message-form" method="POST">
                <input type="text" name="message" placeholder="Введите сообщение..." required>
                <?php if ($user_role === 'admin' && $selected_user_id): ?>
                    <input type="hidden" name="recipient_id" value="<?= $selected_user_id ?>">
                    <?php error_log("recipient_id в форме: $selected_user_id"); ?>
                <?php endif; ?>
                <button type="submit">Отправить</button>
            </form>
        <?php endif; ?>
    </div>


    <script>
        // Прокрутка к последнему сообщению
        const chatMessages = document.getElementById('chat-messages');
        chatMessages.scrollTop = chatMessages.scrollHeight;

        // Обработка отправки формы через AJAX
        document.getElementById('message-form')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('chat.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(() => {
                window.location.href = 'chat.php<?= $user_role === 'admin' && $selected_user_id ? "?user_id=" . $selected_user_id : "" ?>';
            })
            .catch(error => console.error('Ошибка AJAX:', error));
        });

        // Обновление сообщений каждые 5 секунд
        setInterval(() => {
            fetch('chat.php<?= $user_role === 'admin' && $selected_user_id ? "?user_id=" . $selected_user_id : "" ?>')
                .then(response => response.text())
                .then(() => {
                    window.location.reload();
                })
                .catch(error => console.error('Ошибка поллинга:', error));
        }, 20000);

     

     
    </script>
</body>
</html>