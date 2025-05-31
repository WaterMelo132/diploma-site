<?php
session_start();
require_once('navbar.php');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>О нас | iTravel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <!-- Иконки и шрифт -->
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons+Outlined" rel="stylesheet">
    


    <style>
        :root {
            --section-bg: #f9f9f9;
            --title-color: #111827;
            --text-color-dark: #4b5563;
            --highlight-color: #2563eb;
        }
        .navbar {

    position: fixed !important;
    top: 20px !important;
}

        body {
            background-color: var(--bg-color);
        }

        .hero {
            padding: 80px 20px;
            text-align: center;
            background: linear-gradient(to right,rgb(0, 81, 255),rgb(172, 198, 255));
            color: white;
        }

        .hero h1 {
            font-size: 3rem;
            margin-bottom: 10px;
        }

        .hero p {
            font-size: 1.2rem;
            max-width: 700px;
            margin: 0 auto;
        }

        .section {
            padding: 60px 20px;
            max-width: 1100px;
            margin: 0 auto;
        }

        .section-title {
            text-align: center;
            font-size: 2.5rem;
            color: var(--title-color);
            margin-bottom: 20px;
        }

        .section-description {
            text-align: center;
            font-size: 1.1rem;
            color: var(--text-color-dark);
            margin-bottom: 50px;
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
        }

        .feature-box {
            background: var(--section-bg);
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.04);
            text-align: center;
            transition: 0.3s;
        }

        .feature-box:hover {
            transform: translateY(-5px);
        }

        .feature-icon {
            font-size: 40px;
            color: var(--highlight-color);
            margin-bottom: 15px;
        }

        .feature-title {
            font-size: 1.2rem;
            font-weight: 500;
            margin-bottom: 10px;
            color: var(--title-color);
        }

        .feature-description {
            font-size: 1rem;
            color: var(--text-color-dark);
        }

        .footer {
            text-align: center;
            padding: 30px;
            background-color: #f1f5f9;
            color: #6b7280;
            font-size: 0.9rem;
        }

        @media (max-width: 600px) {
            .hero h1 {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>

    <div class="hero">
        <h1>О нас</h1>
        <p>iTravel — ваш надёжный партнёр в мире путешествий. Комфорт, безопасность и вдохновение — основа нашей работы.</p>
    </div>

    <div class="section">
        <h2 class="section-title">Почему выбирают нас</h2>
        <p class="section-description">Наша команда стремится предоставить лучший сервис каждому клиенту.</p>

        <div class="features">
            <div class="feature-box">
                <span class="material-icons-outlined feature-icon">public</span>
                <div class="feature-title">Путешествия по всему миру</div>
                <p class="feature-description">Доступ к более чем 100 направлениям по всему земному шару.</p>
            </div>

            <div class="feature-box">
                <span class="material-icons-outlined feature-icon">support_agent</span>
                <div class="feature-title">Круглосуточная поддержка</div>
                <p class="feature-description">Наша команда всегда рядом, даже когда вы в другой стране.</p>
            </div>

            <div class="feature-box">
                <span class="material-icons-outlined feature-icon">verified_user</span>
                <div class="feature-title">Надёжность и опыт</div>
                <p class="feature-description">Работаем с 2015 года, обслужили более 20,000 клиентов.</p>
            </div>
        </div>
    </div>

    <div class="footer">
        &copy; 2025 iTravel. Все права защищены.
    </div>

</body>
</html>
