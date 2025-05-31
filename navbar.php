<?php
$current = basename($_SERVER['PHP_SELF']);
// Подключаем конфигурацию
require_once __DIR__.'/config.php';

// Проверяем авторизацию
$user_email = '';
if (isset($_SESSION['user_id'])) {
    $stmt = $conn->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->bind_param("i", $_SESSION['user_id']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $user_email = htmlspecialchars($user['email'] ?? '');
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stylish Navigation</title>
    <link href="https://fonts.googleapis.com/css2?family=Material+Icons+Outlined" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <!-- Убрали подключение navbar.css, так как он ранее не находился -->
</head>
<style>
    .itravel-navbar {
    z-index: 9999 !important;
    position: fixed !important;
    top: 20px !important;
    left: 0 !important;
    width: 100% !important;
    height: 60px !important; /* Убедитесь, что высота задана */
    display: flex !important; /* Гарантируем отображение */
    background: linear-gradient(180deg, rgba(20, 30, 40, 0.85), rgba(30, 40, 50, 0.75)) !important;
    backdrop-filter: blur(15px) !important;
    -webkit-backdrop-filter: blur(15px) !important;
    border-radius: 0 0 16px 16px !important;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2) !important;
    transition: all 0.3s ease !important;
    opacity: 1 !important; /* Убедитесь, что не скрыт */
}

    /* Эффект при скролле */
    :where(.itravel-navbar.itravel-scrolled) {
        background: rgba(20, 30, 40, 0.95) !important;
        height: 50px !important;
        border-radius: 0 0 12px 12px !important;
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3) !important;
    }

    /* Анимация появления */
    :where(.itravel-navbar.itravel-animate-on-load) {
        opacity: 0 !important;
        transform: translateY(-20px) !important;
        animation: slideIn 0.6s ease-out forwards !important;
    }

    @keyframes slideIn {
        to {
            opacity: 1 !important;
            transform: translateY(0) !important;
        }
    }

    /* Контейнер */
    :where(.itravel-nav-container) {
        all: initial !important;
        display: flex !important;
        align-items: center !important;
        justify-content: space-between !important;
        padding: 0 30px !important;
        width: 100% !important;
        max-width: 1400px !important;
        margin: 0 auto !important;
        height: 60px !important;
        position: relative !important;
        z-index: 1 !important;
        font-family: 'Roboto', sans-serif !important;
    }

    /* Логотип */
    :where(.itravel-brand-name) {
        all: initial !important;
        display: flex !important;
        align-items: center !important;
        font-family: 'Roboto', sans-serif !important;
        font-weight: 500 !important;
        color: #d0d0d0 !important;
        font-size: 22px !important;
        letter-spacing: 0.5px !important;
        transition: color 0.3s ease !important;
        position: relative !important;
    }

    :where(.itravel-brand-name:hover) {
        color: #4CAF50 !important;
    }

    :where(.itravel-brand-name::after) {
        content: '' !important;
        position: absolute !important;
        bottom: -2px !important;
        left: 0 !important;
        width: 100% !important;
        height: 2px !important;
        background: #4CAF50 !important;
        transform: scaleX(0) !important;
        transform-origin: bottom left !important;
        transition: transform 0.3s ease !important;
    }

    :where(.itravel-brand-name:hover::after) {
        transform: scaleX(1) !important;
    }

    :where(.itravel-brand-name .material-icons-outlined) {
        margin-right: 8px !important;
        font-size: 26px !important;
    }

    /* Ссылки навигации */
    :where(.itravel-nav-links) {
        all: initial !important;
        display: flex !important;
        gap: 20px !important;
        font-family: 'Roboto', sans-serif !important;
    }

    .itravel-nav-link {
    display: flex !important;
    align-items: center !important;
    color: #d0d0d0 !important;
    text-decoration: none !important;
    font-family: 'Roboto', sans-serif !important;
    font-weight: 400 !important;
    font-size: 15px !important;
    padding: 8px 12px !important;
    border-radius: 6px !important;
    position: relative !important;
    overflow: hidden !important;
    transition: color 0.3s ease, transform 0.2s ease !important;
    cursor: pointer !important; /* Добавляем курсор */
}

.itravel-nav-link:hover {
    color: #ffffff !important;
    transform: translateY(-2px) !important;
    cursor: pointer !important; /* Убеждаемся, что hover тоже включает курсор */
}

    :where(.itravel-nav-link .material-icons-outlined) {
        margin-right: 6px !important;
        font-size: 20px !important;
    }

    /* Эффект волны при наведении */
    :where(.itravel-nav-link::before) {
        content: '' !important;
        position: absolute !important;
        top: 50% !important;
        left: 50% !important;
        width: 0 !important;
        height: 0 !important;
        background: rgba(76, 175, 80, 0.2) !important;
        border-radius: 50% !important;
        transform: translate(-50%, -50%) !important;
        transition: width 0.4s ease, height 0.4s ease !important;
    }

    :where(.itravel-nav-link:hover::before) {
        width: 200px !important;
        height: 200px !important;
    }

    :where(.itravel-nav-link:hover) {
        color: #ffffff !important;
        transform: translateY(-2px) !important;
    }

    :where(.itravel-nav-link.itravel-active) {
        color: #ffffff !important;
        background: rgba(76, 175, 80, 0.3) !important;
        position: relative !important;
    }

    /* Анимированная линия снизу */
    :where(.itravel-nav-link.itravel-active::after) {
        content: '' !important;
        position: absolute !important;
        bottom: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 2px !important;
        background: #4CAF50 !important;
        animation: underline 0.5s ease forwards !important;
    }

    @keyframes underline {
        from {
            width: 0 !important;
        }
        to {
            width: 100% !important;
        }
    }

    /* Эффект свечения */
    :where(.itravel-nav-link.itravel-active) {
        box-shadow: 0 0 8px rgba(76, 175, 80, 0.5) !important;
    }

    /* Анимация иконок */
    :where(.itravel-icon-pulse) {
        transition: transform 0.3s ease, color 0.3s ease !important;
    }

    :where(.itravel-nav-link:hover .itravel-icon-pulse),
    :where(.itravel-brand-name:hover .itravel-icon-pulse),
    :where(.itravel-login-link:hover .itravel-icon-pulse) {
        transform: scale(1.2) rotate(10deg) !important;
        color: #4CAF50 !important;
    }

    /* Email пользователя */
    :where(.itravel-user-email) {
        all: initial !important;
        color: #d0d0d0 !important;
        font-family: 'Roboto', sans-serif !important;
        font-weight: 300 !important;
        font-size: 14px !important;
        padding: 8px 12px !important;
        border-radius: 6px !important;
        transition: background-color 0.3s ease, color 0.3s ease !important;
    }

    :where(.itravel-user-email:hover) {
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: #ffffff !important;
    }

    /* Кнопка входа */
    :where(.itravel-login-link) {
        all: initial !important;
        display: flex !important;
        align-items: center !important;
        color: #4CAF50 !important;
        text-decoration: none !important;
        font-family: 'Roboto', sans-serif !important;
        font-weight: 400 !important;
        font-size: 15px !important;
        padding: 8px 12px !important;
        border-radius: 6px !important;
        position: relative !important;
        overflow: hidden !important;
        transition: color 0.3s ease !important;
    }

    :where(.itravel-login-link::before) {
        content: '' !important;
        position: absolute !important;
        top: 50% !important;
        left: 50% !important;
        width: 0 !important;
        height: 0 !important;
        background: rgba(76, 175, 80, 0.2) !important;
        border-radius: 50% !important;
        transform: translate(-50%, -50%) !important;
        transition: width 0.4s ease, height 0.4s ease !important;
    }

    :where(.itravel-login-link:hover::before) {
        width: 200px !important;
        height: 200px !important;
    }

    :where(.itravel-login-link:hover) {
        color: #ffffff !important;
    }

    :where(.itravel-login-link .material-icons-outlined) {
        margin-right: 6px !important;
        font-size: 20px !important;
    }

    /* Кнопка мобильного меню */
    :where(.itravel-mobile-menu-btn) {
        all: initial !important;
        display: none !important;
        background: none !important;
        border: none !important;
        color: #d0d0d0 !important;
        cursor: pointer !important;
        font-size: 24px !important;
        transition: color 0.3s ease !important;
    }

    :where(.itravel-mobile-menu-btn:hover) {
        color: #4CAF50 !important;
    }

    /* Адаптивность для мобильных устройств */
    @media (max-width: 768px) {
        :where(.itravel-mobile-menu-btn) {
            display: block !important;
        }

        :where(.itravel-nav-links) {
            display: none !important;
            flex-direction: column !important;
            position: absolute !important;
            top: 60px !important;
            left: 0 !important;
            width: 100% !important;
            background: rgba(20, 30, 40, 0.95) !important;
            backdrop-filter: blur(15px) !important;
            -webkit-backdrop-filter: blur(15px) !important;
            border-radius: 0 0 16px 16px !important;
            padding: 10px 0 !important;
            box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2) !important;
            transform: translateY(-20px) !important;
            opacity: 0 !important;
            transition: all 0.4s ease !important;
        }

        :where(.itravel-nav-links.itravel-active) {
            display: flex !important;
            transform: translateY(0) !important;
            opacity: 1 !important;
        }

        :where(.itravel-nav-link) {
            padding: 12px 20px !important;
            justify-content: center !important;
            font-size: 16px !important;
        }

        :where(.itravel-user-email),
        :where(.itravel-login-link) {
            padding: 12px 20px !important;
            justify-content: center !important;
            font-size: 15px !important;
        }
    }

    /* Переменные для тем */
    :root {
        --navbar-bg: linear-gradient(180deg, rgba(20, 30, 40, 0.85), rgba(30, 40, 50, 0.75)) !important;
        --navbar-bg-scrolled: rgba(20, 30, 40, 0.95) !important;
        --text-color: #d0d0d0 !important;
        --text-hover: #ffffff !important;
        --accent-color: #4CAF50 !important;
    }

    :where(.itravel-light-theme) {
        --navbar-bg: linear-gradient(180deg, rgba(200, 210, 220, 0.85), rgba(220, 230, 240, 0.75)) !important;
        --navbar-bg-scrolled: rgba(200, 210, 220, 0.95) !important;
        --text-color: #333333 !important;
        --text-hover: #000000 !important;
        --accent-color: #2196F3 !important;
    }

    /* Кнопка переключения темы */
    :where(.itravel-theme-toggle) {
        all: initial !important;
        background: none !important;
        border: none !important;
        color: #d0d0d0 !important;
        cursor: pointer !important;
        font-size: 24px !important;
        transition: transform 0.3s ease, color 0.3s ease !important;
    }

    :where(.itravel-theme-toggle:hover) {
        color: #4CAF50 !important;
        transform: rotate(180deg) !important;
    }

    /* Плавный переход для смены темы */
    :where(.itravel-navbar),
    :where(.itravel-nav-link),
    :where(.itravel-user-email),
    :where(.itravel-mobile-menu-btn),
    :where(.itravel-brand-name),
    :where(.itravel-login-link) {
        transition: background 0.5s ease, color 0.5s ease !important;
    }

    /* Фон с частицами */
    :where(.itravel-navbar-bg) {
        all: initial !important;
        position: absolute !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        z-index: -1 !important;
        opacity: 0.3 !important;
    }
</style>
<body>
<header class="itravel-navbar itravel-animate-on-load">
    <div class="itravel-nav-container">
        <div class="itravel-brand-name">
            <span class="material-icons-outlined itravel-icon-pulse">travel_explore</span>
            <span>iTravel</span>
        </div>
        
        <button class="itravel-mobile-menu-btn">
            <span class="material-icons-outlined itravel-icon-pulse">menu</span>
        </button>
        
        <nav class="itravel-nav-links">
            <a href="index.php" class="itravel-nav-link <?= $current == 'index.php' ? 'itravel-active' : '' ?>">
                <span class="material-icons-outlined itravel-icon-pulse">home</span>
                <span>Главная</span>
            </a>
            <a href="about.php" class="itravel-nav-link <?= $current == 'about.php' ? 'itravel-active' : '' ?>">
                <span class="material-icons-outlined itravel-icon-pulse">info</span>
                <span>О нас</span>
            </a>
            <a href="tours.php" class="itravel-nav-link <?= $current == 'tours.php' ? 'itravel-active' : '' ?>">
                <span class="material-icons-outlined itravel-icon-pulse">map</span>
                <span>Туры</span>
            </a>
            <a href="contact.php" class="itravel-nav-link <?= $current == 'contact.php' ? 'itravel-active' : '' ?>">
                <span class="material-icons-outlined itravel-icon-pulse">mail</span>
                <span>Контакты</span>
            </a>
            <a href="profile.php" class="itravel-nav-link <?= $current == 'profile.php' ? 'itravel-active' : '' ?>">
                <span class="material-icons-outlined itravel-icon-pulse">person</span>
                <span>Профиль</span>
            </a>
            <a href="chat.php" class="itravel-nav-link <?= $current == 'chat.php' ? 'itravel-active' : '' ?>">
                <span class="material-icons-outlined itravel-icon-pulse">chat</span>
                <span>Чат</span>
            </a>
        </nav>
        
        <?php if(isset($_SESSION['user_id'])): ?>
            <div class="itravel-user-email">
                <?= htmlspecialchars($user_email) ?>
            </div>
        <?php else: ?>
            <a href="/travel/login.php" class="itravel-login-link">
                <span class="material-icons-outlined itravel-icon-pulse">login</span>
                <span>Войти</span>
            </a>
        <?php endif; ?>
        <button class="itravel-theme-toggle">
            <span class="material-icons-outlined">brightness_6</span>
        </button>
    </div>
    <canvas class="itravel-navbar-bg"></canvas>
</header>

<script>
// Управление скроллом
window.addEventListener('scroll', () => {
    const navbar = document.querySelector('.itravel-navbar'); // Используем правильный класс
    if (navbar && window.scrollY > 50) {
        navbar.classList.add('itravel-scrolled');
    } else if (navbar) {
        navbar.classList.remove('itravel-scrolled');
    }
});

// Управление мобильным меню
const mobileMenuBtn = document.querySelector('.itravel-mobile-menu-btn');
const navLinks = document.querySelector('.itravel-nav-links');
if (mobileMenuBtn && navLinks) {
    mobileMenuBtn.addEventListener('click', () => {
        navLinks.classList.toggle('itravel-active');
    });
}



// Переключение темы
const themeToggle = document.querySelector('.itravel-theme-toggle');
themeToggle.addEventListener('click', () => {
    document.body.classList.toggle('itravel-light-theme');
    localStorage.setItem('theme', document.body.classList.contains('itravel-light-theme') ? 'light' : 'dark');
});

// Загрузка сохраненной темы
if (localStorage.getItem('theme') === 'light') {
    document.body.classList.add('itravel-light-theme');
}

// Анимация частиц
const canvas = document.querySelector('.itravel-navbar-bg');
const ctx = canvas.getContext('2d');

canvas.width = window.innerWidth;
canvas.height = 60; // Высота панели

const particles = [];
const particleCount = 20;

class Particle {
    constructor() {
        this.x = Math.random() * canvas.width;
        this.y = Math.random() * canvas.height;
        this.size = Math.random() * 2 + 1;
        this.speedX = Math.random() * 0.5 - 0.25;
        this.speedY = Math.random() * 0.5 - 0.25;
    }

    update() {
        this.x += this.speedX;
        this.y += this.speedY;

        if (this.x < 0 || this.x > canvas.width) this.speedX *= -1;
        if (this.y < 0 || this.y > canvas.height) this.speedY *= -1;
    }

    draw() {
        ctx.fillStyle = 'rgba(255, 255, 255, 0.8)';
        ctx.beginPath();
        ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
        ctx.fill();
    }
}

for (let i = 0; i < particleCount; i++) {
    particles.push(new Particle());
}

function animateParticles() {
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    particles.forEach(particle => {
        particle.update();
        particle.draw();
    });
    requestAnimationFrame(animateParticles);
}

animateParticles();

// Адаптация при изменении размера окна
window.addEventListener('resize', () => {
    canvas.width = window.innerWidth;
    canvas.height = 60;
});
</script>
</body>
</html>