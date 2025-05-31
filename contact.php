<?php
session_start();
require_once('navbar.php');

// Обработка формы обратной связи
$contact_success = false;
$contact_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_contact'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $message = trim($_POST['message'] ?? '');

    if ($name && $email && $message && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // Здесь можно добавить отправку email или сохранение в базу данных
        $contact_success = true;
    } else {
        $contact_error = 'Пожалуйста, заполните все поля корректно.';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Контакты | Travel Agency</title>
    <meta name="description" content="Свяжитесь с Travel Agency: телефон, email, адрес и форма обратной связи. Мы готовы помочь вам с вашим путешествием!">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
         .navbar {

    position: fixed !important;
    top: 20px !important;
}
        body {
            font-family: 'Roboto', sans-serif;
            margin: 0;
            padding: 0;
            color: #2d3748;
            background-color: #f9fafb;
            line-height: 1.7;
        }

        .hero-section {
            padding: 80px 20px;
            text-align: center;
            background: linear-gradient(135deg, #e6f0fa 0%, #f7fafc 100%);
        }

        .hero-title {
            font-size: 2.5rem;
            font-weight: 400;
            color: #1a202c;
            margin-bottom: 20px;
            letter-spacing: 0.5px;
        }

        .hero-subtitle {
            font-size: 1.1rem;
            font-weight: 300;
            color: #4a5568;
            max-width: 600px;
            margin: 0 auto;
        }

        .divider {
            width: 60px;
            height: 2px;
            background: #3b82f6;
            margin: 30px auto;
            opacity: 0.7;
        }

        .section {
            padding: 80px 20px;
            text-align: center;
            max-width: 900px;
            margin: 0 auto;
        }

        .section-title {
            font-size: 1.8rem;
            font-weight: 400;
            color: #1a202c;
            margin-bottom: 25px;
            letter-spacing: 0.3px;
        }

        .contact-info {
            display: flex;
            justify-content: center;
            gap: 40px;
            flex-wrap: wrap;
            margin-bottom: 40px;
        }

        .contact-item {
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.95rem;
            color: #4a5568;
            transition: color 0.3s ease;
        }

        .contact-icon {
            font-size: 1.2rem;
            color: #3b82f6;
            transition: transform 0.3s ease;
        }

        .contact-item:hover .contact-icon {
            transform: translateY(-2px);
        }

        .contact-item a {
            color: #3b82f6;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .contact-item a:hover {
            color: #2563eb;
        }

        .map-container {
            max-width: 900px;
            margin: 40px auto 0;
            height: 300px;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .contact-form {
            max-width: 500px;
            margin: 40px auto 0;
            text-align: left;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.9rem;
            color: #2d3748;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 5px;
            font-size: 0.95rem;
            color: #2d3748;
            background: #fff;
            transition: border-color 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #3b82f6;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-submit {
            background: #3b82f6;
            color: #fff;
            border: none;
            padding: 12px 30px;
            border-radius: 5px;
            font-size: 0.95rem;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .form-submit:hover {
            background: #2563eb;
        }

        .form-message {
            margin-top: 15px;
            font-size: 0.9rem;
            text-align: center;
        }

        .form-message.success {
            color: #10b981;
        }

        .form-message.error {
            color: #ef4444;
        }

        .cta-section {
            background: #fff;
            padding: 60px 20px;
        }

        .cta-button {
            display: inline-block;
            background: #3b82f6;
            color: #fff;
            padding: 12px 30px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 1rem;
            font-weight: 400;
            transition: background 0.3s ease;
        }

        .cta-button:hover {
            background: #2563eb;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .fade-in {
            opacity: 0;
            animation: fadeIn 0.8s ease-out forwards;
        }

        .fade-in-delay-1 { animation-delay: 0.2s; }
        .fade-in-delay-2 { animation-delay: 0.4s; }

        @media (max-width: 768px) {
            .hero-section {
                padding: 50px 20px;
            }

            .hero-title {
                font-size: 2rem;
            }

            .hero-subtitle {
                font-size: 1rem;
            }

            .section {
                padding: 50px 20px;
            }

            .section-title {
                font-size: 1.5rem;
            }

            .contact-info {
                gap: 20px;
                flex-direction: column;
            }

            .map-container {
                height: 200px;
            }
        }
    </style>
</head>
<body>
    <div class="hero-section">
        <div>
            <h1 class="hero-title fade-in">Контакты</h1>
            <div class="divider fade-in fade-in-delay-1"></div>
            <p class="hero-subtitle fade-in fade-in-delay-2">
                Мы всегда готовы помочь вам спланировать идеальное путешествие. Свяжитесь с нами любым удобным способом!
            </p>
        </div>
    </div>

    <div class="section">
        <h2 class="section-title fade-in">Наши контакты</h2>
        <div class="contact-info fade-in fade-in-delay-1">
            <div class="contact-item">
                <i class="fas fa-phone contact-icon"></i>
                <span>+7 (495) 123-45-67</span>
            </div>
            <div class="contact-item">
                <i class="fas fa-envelope contact-icon"></i>
                <a href="mailto:info@travelagency.ru">info@travelagency.ru</a>
            </div>
            <div class="contact-item">
                <i class="fas fa-map-marker-alt contact-icon"></i>
                <span>Москва, ул. Путешествий, д. 1</span>
            </div>
        </div>

        <div class="map-container fade-in fade-in-delay-2">
            <iframe
                src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d2245.227602714025!2d37.617348416094!3d55.755826980557!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x46b54a5d77b1d3b7%3A0x3f1e8d7b6e7e5f0d!2z0JzQvtGB0LrQstCw!5e0!3m2!1sru!2sru!4v1697041234567!5m2!1sru!2sru"
                width="100%"
                height="100%"
                style="border:0;"
                allowfullscreen=""
                loading="lazy"
                referrerpolicy="no-referrer-when-downgrade">
            </iframe>
        </div>

        
    </div>

    <div class="section cta-section">
        <h2 class="section-title fade-in">Готовы к путешествию?</h2>
        <a href="/travel/index.php" class="cta-button fade-in fade-in-delay-1">Посмотреть наши туры</a>
    </div>

    <script>
        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                }
            });
        }, { threshold: 0.1 });

        document.querySelectorAll('.fade-in').forEach(element => {
            observer.observe(element);
        });
      
    </script>
</body>
</html>