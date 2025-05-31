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
    <link href="/travel/css/navbar.css" rel="stylesheet">
</head>
<style>
    /* Основные стили для навбара */
.navbar {
    z-index: 1000 !important;
    position:  !important;
    top: 0 !important;
    left: -20px !important;
    width: 100% !important;
    display: flex !important;
    background: var(--navbar-bg) !important;
    backdrop-filter: blur(15px) !important;
    -webkit-backdrop-filter: blur(15px) !important;
    border-radius: 0 0 16px 16px !important;
    box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2) !important;
    transition: all 0.3s ease !important;
}

/* Эффект при скролле */
.navbar.scrolled {
    background: var(--navbar-bg-scrolled) !important;
    height: 50px !important;
    border-radius: 0 0 12px 12px !important;
    box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3) !important;
}

/* Анимация появления */
.navbar.animate-on-load {
    opacity: 0;
    transform: translateY(-20px);
    animation: slideIn 0.6s ease-out forwards;
}

@keyframes slideIn {
    to {
        opacity: 1;

    }
}

/* Контейнер */
.nav-container {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 30px;
    width: 100%;
    max-width: 1400px;
    margin: 0 auto;
    height: 60px;
    position: relative;
    z-index: 1;
}

/* Логотип */
.brand-name {
    display: flex;
    align-items: center;
    font-family: 'Roboto', sans-serif;
    font-weight: 500;
    color: var(--text-color);
    font-size: 22px;
    letter-spacing: 0.5px;
    transition: color 0.3s ease;
}

.brand-name:hover {
    color: var(--accent-color);
}

.brand-name::after {
    content: '';
    position: absolute;
    bottom: -2px;
    left: 0;
    width: 100%;
    height: 2px;
    background: var(--accent-color);
    transform: scaleX(0);
    transform-origin: bottom left;
    transition: transform 0.3s ease;
}

.brand-name:hover::after {
    transform: scaleX(1);
}

.brand-name .material-icons-outlined {
    margin-right: 8px;
    font-size: 26px;
}

/* Ссылки навигации */
.nav-links {
    display: flex;
    gap: 20px;
}

.nav-link {
    display: flex;
    align-items: center;
    color: var(--text-color);
    text-decoration: none;
    font-family: 'Roboto', sans-serif;
    font-weight: 400;
    font-size: 15px;
    padding: 8px 12px;
    border-radius: 6px;
    position: relative;
    overflow: hidden;
    transition: color 0.3s ease, transform 0.2s ease;
}

.nav-link .material-icons-outlined {
    margin-right: 6px;
    font-size: 20px;
}

/* Эффект волны при наведении */
.nav-link::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(76, 175, 80, 0.2);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.4s ease, height 0.4s ease;
}

.nav-link:hover::before {
    width: 200px;
    height: 200px;
}

.nav-link:hover {
    color: var(--text-hover);
    transform: translateY(-2px);
}

.nav-link.active {
    color: var(--text-hover);
    background: rgba(76, 175, 80, 0.3);
    position: relative;
}

/* Анимированная линия снизу */
.nav-link.active::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 2px;
    background: var(--accent-color);
    animation: underline 0.5s ease forwards;
}

@keyframes underline {
    from {
        width: 0;
    }
    to {
        width: 100%;
    }
}

/* Эффект свечения */
.nav-link.active {
    box-shadow: 0 0 8px rgba(76, 175, 80, 0.5);
}

/* Анимация иконок */
.icon-pulse {
    transition: transform 0.3s ease, color 0.3s ease;
}

.nav-link:hover .icon-pulse,
.brand-name:hover .icon-pulse,
.login-link:hover .icon-pulse {
    transform: scale(1.2) rotate(10deg);
    color: var(--accent-color);
}

/* Email пользователя */
.user-email {
    color: var(--text-color);
    font-family: 'Roboto', sans-serif;
    font-weight: 300;
    font-size: 14px;
    padding: 8px 12px;
    border-radius: 6px;
    transition: background-color 0.3s ease, color 0.3s ease;
}

.user-email:hover {
    background-color: rgba(255, 255, 255, 0.1);
    color: var(--text-hover);
}

/* Кнопка входа */
.login-link {
    display: flex;
    align-items: center;
    color: var(--accent-color);
    text-decoration: none;
    font-family: 'Roboto', sans-serif;
    font-weight: 400;
    font-size: 15px;
    padding: 8px 12px;
    border-radius: 6px;
    position: relative;
    overflow: hidden;
    transition: color 0.3s ease;
}

.login-link::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    width: 0;
    height: 0;
    background: rgba(76, 175, 80, 0.2);
    border-radius: 50%;
    transform: translate(-50%, -50%);
    transition: width 0.4s ease, height 0.4s ease;
}

.login-link:hover::before {
    width: 200px;
    height: 200px;
}

.login-link:hover {
    color: var(--text-hover);
}

.login-link .material-icons-outlined {
    margin-right: 6px;
    font-size: 20px;
}

/* Кнопка мобильного меню */
.mobile-menu-btn {
    display: none;
    background: none;
    border: none;
    color: var(--text-color);
    cursor: pointer;
    font-size: 24px;
    transition: color 0.3s ease;
}

.mobile-menu-btn:hover {
    color: var(--accent-color);
}

/* Адаптивность для мобильных устройств */
@media (max-width: 768px) {
    .mobile-menu-btn {
        display: block;
    }

    .nav-links {
        display: none;
        flex-direction: column;
        position: absolute;
        top: 60px;
        left: 0;
        width: 100%;
        background: var(--navbar-bg-scrolled) !important;
        backdrop-filter: blur(15px) !important;
        -webkit-backdrop-filter: blur(15px) !important;
        border-radius: 0 0 16px 16px !important;
        padding: 10px 0;
        box-shadow: 0 3px 8px rgba(0, 0, 0, 0.2);
        transform: translateY(-20px);
        opacity: 0;
        transition: all 0.4s ease;
    }

    .nav-links.active {
        display: flex;
        transform: translateY(0);
        opacity: 1;
    }

    .nav-link {
        padding: 12px 20px;
        justify-content: center;
        font-size: 16px;
    }

    .user-email,
    .login-link {
        padding: 12px 20px;
        justify-content: center;
        font-size: 15px;
    }
}

/* Переменные для тем */
:root {
    --navbar-bg: linear-gradient(180deg, rgba(20, 30, 40, 0.85), rgba(30, 40, 50, 0.75));
    --navbar-bg-scrolled: rgba(20, 30, 40, 0.95);
    --text-color: #d0d0d0;
    --text-hover: #ffffff;
    --accent-color: #4CAF50;
}

.light-theme {
    --navbar-bg: linear-gradient(180deg, rgba(200, 210, 220, 0.85), rgba(220, 230, 240, 0.75));
    --navbar-bg-scrolled: rgba(200, 210, 220, 0.95);
    --text-color: #333333;
    --text-hover: #000000;
    --accent-color: #2196F3;
}

/* Кнопка переключения темы */
.theme-toggle {
    background: none;
    border: none;
    color: var(--text-color);
    cursor: pointer;
    font-size: 24px;
    transition: transform 0.3s ease, color 0.3s ease;
}

.theme-toggle:hover {
    color: var(--accent-color);
    transform: rotate(180deg);
}

/* Плавный переход для смены темы */
.navbar,
.nav-link,
.user-email,
.mobile-menu-btn,
.brand-name,
.login-link {
    transition: background 0.5s ease, color 0.5s ease !important;
}

/* Фон с частицами */
.navbar-bg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
    opacity: 0.3;
}
    </style>
<body>
<header class="navbar animate-on-load">
    <div class="nav-container">
        <div class="brand-name">
            <span class="material-icons-outlined icon-pulse">travel_explore</span>
            <span>iTravel</span>
        </div>
        
        <button class="mobile-menu-btn">
            <span class="material-icons-outlined icon-pulse">menu</span>
        </button>
        
       <nav class="nav-links">
    <a href="index.php" class="nav-link <?= $current == 'index.php' ? 'active' : '' ?>">
        <span class="material-icons-outlined icon-pulse">home</span>
        <span>Главная</span>
    </a>
    <a href="about.php" class="nav-link <?= $current == 'about.php' ? 'active' : '' ?>">
        <span class="material-icons-outlined icon-pulse">info</span>
        <span>О нас</span>
    </a>
    <a href="tours.php" class="nav-link <?= $current == 'tours.php' ? 'active' : '' ?>">
        <span class="material-icons-outlined icon-pulse">map</span>
        <span>Туры</span>
    </a>
    <a href="contact.php" class="nav-link <?= $current == 'contact.php' ? 'active' : '' ?>">
        <span class="material-icons-outlined icon-pulse">mail</span>
        <span>Контакты</span>
    </a>
    <a href="profile.php" class="nav-link <?= $current == 'profile.php' ? 'active' : '' ?>">
        <span class="material-icons-outlined icon-pulse">person</span>
        <span>Профиль</span>
    </a>
    <a href="chat.php" class="nav-link <?= $current == 'chat.php' ? 'active' : '' ?>">
        <span class="material-icons-outlined icon-pulse">chat</span>
        <span>Чат</span>
    </a>

</nav>
        
        <?php if(isset($_SESSION['user_id'])): ?>
            <div class="user-email">
                <?= htmlspecialchars($user_email) ?>
            </div>
        <?php else: ?>
            <a href="/travel/login.php" class="login-link">
                <span class="material-icons-outlined icon-pulse">login</span>
                <span>Войти</span>
            </a>
        <?php endif; ?>
        <button class="theme-toggle">
            <span class="material-icons-outlined">brightness_6</span>
        </button>
    </div>
    <canvas class="navbar-bg"></canvas> <!-- Фон с частицами -->
</header>

<script>
    
    // Управление скроллом
window.addEventListener('scroll', () => {
    const navbar = document.querySelector('.navbar');
    if (window.scrollY > 50) {
        navbar.classList.add('scrolled');
    } else {
        navbar.classList.remove('scrolled');
    }
});

// Управление мобильным меню
const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
const navLinks = document.querySelector('.nav-links');

mobileMenuBtn.addEventListener('click', () => {
    navLinks.classList.toggle('active');
});

// Переключение темы
const themeToggle = document.querySelector('.theme-toggle');
themeToggle.addEventListener('click', () => {
    document.body.classList.toggle('light-theme');
    localStorage.setItem('theme', document.body.classList.contains('light-theme') ? 'light' : 'dark');
});

// Загрузка сохраненной темы
if (localStorage.getItem('theme') === 'light') {
    document.body.classList.add('light-theme');
}

// Анимация частиц
const canvas = document.querySelector('.navbar-bg');
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