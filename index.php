<?php
header('Permissions-Policy: interest-cohort=()');
session_start();
$navbar_path = $_SERVER['DOCUMENT_ROOT'] . '/travel/navbar.php';


if (file_exists($navbar_path)) {
    require_once($navbar_path);
} else {
    error_log('navbar.php не найден в ' . $navbar_path);
    echo '<p style="color: red; position: fixed; top: 10px; left: 10px; z-index: 10000;">Ошибка: navbar.php не найден</p>';
}

// Подключение к базе данных
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "travel_agency";
$conn = new mysqli($servername, $username, $password, $dbname);

// Проверяем соединение
if ($conn->connect_error) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Database connection failed']));
}

// Получаем все туры для слайд-шоу
$tours = [];
$sql = "SELECT * FROM travels";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $tours[] = $row;
}

$upcoming_tour = null;
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $sql = "
        SELECT t.*, tb.created_at AS booking_date
        FROM tour_bookings tb
        JOIN travels t ON tb.travel_id = t.id
        WHERE tb.user_id = ? AND t.start_date >= CURDATE()
        ORDER BY t.start_date ASC
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $upcoming_tour = $result->fetch_assoc();
    $stmt->close();
}

$conn->close();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500&display=swap" rel="stylesheet">
    <title>Travel Agency</title>
    <style>
       .nav-links {
        z-index: 1000 !important; /* Выше всех элементов */
       
    }
   
        body {
            font-family: 'Roboto', sans-serif;
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            color: white;
            margin: 0;
            padding: 0;
            height: 100vh;
            transition: background-image 1s ease-in-out;
        }
        body::before {
            content: "";
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to top, rgba(0,0,0,0.4), rgba(0,0,0,0));
            z-index: 1;
            pointer-events: none;
        }

        .tour-title-container {
            position: fixed;
            bottom: 200px;
            left: 50%;
            transform: translateX(-50%);
            text-align: center;
            z-index: 10;
            width: 100%;
        }

        .country-name {
            font-size: 1.2vw;
            font-weight: 400;
            letter-spacing: 8px;
            color: white;
            text-transform: uppercase;
            margin-bottom: 200px;
            opacity: 0.8;
            text-shadow: 0 2px 4px rgba(0,0,0,0.3);
        }

        .location-name {
            font-size: 5.5vw;
            font-weight: 700;
            letter-spacing: 2px;
            color: white;
            text-transform: uppercase;
            margin-bottom: 20px;
            text-shadow: 
                0 2px 10px rgba(0,0,0,0.5),
                0 4px 20px rgba(0,0,0,0.3);
            line-height: 1;
        }

        .discover-btn {
            background: transparent;
            color: white;
            border: 1px solid white;
            padding: 12px 40px;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 3px;
            text-transform: uppercase;
            cursor: pointer;
            transition: all 0.3s ease;
            border-radius: 10px;
            margin-top: 30px;
        }

        .discover-btn:hover {
            background-color: rgba(255, 255, 255, 0.2);
            transform: translateY(-2px);
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
            }
            to {
                opacity: 1;
            }
        }

        .fade-in {
            animation: fadeInUp 1s ease-out forwards;
        }

        @media (max-width: 768px) {
            .country-name {
                font-size: 14px;
                letter-spacing: 5px;
            }
            
            .location-name {
                font-size: 36px;
            }
            
            .discover-btn {
                padding: 10px 30px;
                font-size: 12px;
            }
        }

        .upcoming-trip-reminder {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 12px 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            z-index: 100;
        }

        .trip-icon {
            font-size: 24px;
            animation: float 3s ease-in-out infinite;
        }

        .trip-info {
            display: flex;
            flex-direction: column;
        }

        .trip-title {
            color: white;
            font-size: 12px;
            opacity: 0.8;
            margin-bottom: 4px;
        }

        .trip-countdown {
            color: white;
            font-weight: bold;
            font-size: 14px;
        }

        .trip-countdown span {
            color: #FFD700;
            font-weight: bolder;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
        }

        .scroll-down-indicator {
            position: fixed;
            bottom: 40px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100;
            text-align: center;
            color: white;
            opacity: 0.8;
            cursor: pointer;
            width: 160px;
            height: 160px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
        }

        .scroll-down-arrow {
            position: absolute;
            left: 50%;
            top: 80%;
            transform: translateX(-50%) rotate(45deg);
            width: 16px;
            height: 16px;
            border-right: 2px solid white;
            border-bottom: 2px solid white;
            animation: scroll-down-arrow 1.5s infinite ease-in-out;
            transition: transform 0.3s ease;
        }

        .scroll-down-indicator:hover .scroll-down-arrow {
            transform: translateX(-50%) translateY(-6px) rotate(45deg);
        }

        .scroll-down-horizontal-line {
            width: 400px;
            height: 2px;
            background: white;
            opacity: 0.4;
            border-radius: 1px;
            animation: scroll-down-arrow 1.5s infinite ease-in-out;
            transition: transform 0.3s ease;
            margin-top: 20px;
        }

        .scroll-down-indicator:hover .scroll-down-horizontal-line {
            transform: translateY(-5px);
        }

        .scroll-down-text {
            font-size: 12px;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-top: 10px;
            text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        }

        @media (max-width: 768px) {
            .scroll-down-indicator {
                bottom: 20px;
            }
            .scroll-down-line {
                height: 40px;
            }
            .scroll-down-arrow {
                width: 10px;
                height: 10px;
            }
            .scroll-down-horizontal-line {
                width: 60px;
            }
        }

        .background-blur {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: -1;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            backdrop-filter: blur(3px);
            -webkit-backdrop-filter: blur(3px);
            transition: background-image 1s ease-in-out;
        }

        .content-section {
            position: relative;
            min-height: 100vh;
            width: 100%;
            z-index: 2;
        }

        .info-section {
            position: relative;
            min-height: 100vh;
            background-color: #fff;
            color: #333;
            padding: 0px 20px;
            z-index: 3;
            box-shadow: 0 -10px 30px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
        }

        .info-content {
            max-width: 1000px;
            margin: 0 auto;
            text-align: center;
        }

        .section-title {
            font-size: 2.5rem;
            margin-bottom: 40px;
            color: #222;
            font-weight: 500;
        }

        .section-description {
            font-size: 1.1rem;
            line-height: 1.8;
            margin-bottom: 40px;
            color: #555;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            margin-top: 50px;
        }

        .feature-card {
            background: #f9f9f9;
            border-radius: 15px;
            padding: 30px;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .feature-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 30px rgba(0,0,0,0.1);
        }

        .feature-icon {
            font-size: 2.5rem;
            margin-bottom: 20px;
            color: #4a6fa5;
        }

        .feature-title {
            font-size: 1.3rem;
            margin-bottom: 15px;
            color: #333;
        }

        .feature-description {
            font-size: 0.95rem;
            color: #666;
            line-height: 1.6;
        }

        .tours-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 30px;
            width: 100%;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .tour-card {
            background: transparent;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            color: white;
            position: relative;
            border: none;
        }

        .tour-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: inherit;
            z-index: -1;
            border-radius: 15px;
            overflow: hidden;
        }

        .tour-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0,0,0,0.15);
        }

        .tour-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
        }

        .tour-content {
            padding: 20px;
        }

        .tour-title {
            font-size: 1.3rem;
            margin-bottom: 10px;
            color: #222;
            font-weight: 500;
        }

        .tour-description {
            font-size: 0.9rem;
            color: #666;
            margin-bottom: 15px;
            line-height: 1.5;
        }

        .tour-price {
            font-size: 1.2rem;
            font-weight: bold;
            color: #4a6fa5;
            margin-bottom: 15px;
        }

        .tour-details {
            display: flex;
            justify-content: space-between;
            font-size: 0.8rem;
            color: #888;
            margin-bottom: 15px;
        }

        .tour-button {
            display: inline-block;
            background: #4a6fa5;
            color: white;
            padding: 10px 20px;
            border-radius: 5px;
            text-decoration: none;
            font-weight: 500;
            transition: background 0.3s ease;
        }

        .tour-button:hover {
            background: #3a5a80;
        }

        .section-subtitle {
            font-size: 1.2rem;
            color: #555;
            margin-bottom: 40px;
            max-width: 700px;
            margin-left: auto;
            margin-right: auto;
        }

        @media (max-width: 768px) {
            .tours-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="background-blur"></div>
    <div class="content-section">
        <div class="tour-title-container" id="tourTitleContainer">
            <div class="country-name" id="countryName"></div>
            <div class="location-name" id="locationName"></div>
            <button class="discover-btn" id="discoverBtn">DISCOVER</button>
        </div>

        <?php if ($upcoming_tour): ?>
        <div class="upcoming-trip-reminder">
            <div class="trip-icon">
                <?php
                $transport_icon = '';
                switch ($upcoming_tour['transport_type_en']) {
                    case 'airplane':
                        $transport_icon = '✈️';
                        break;
                    case 'train':
                        $transport_icon = '🚆';
                        break;
                    case 'bus':
                        $transport_icon = '🚌';
                        break;
                    default:
                        $transport_icon = '✈️';
                }
                echo $transport_icon;
                ?>
            </div>
            <div class="trip-info">
                <div class="trip-title"><?php echo htmlspecialchars($upcoming_tour['title']); ?></div>
                <div class="trip-countdown" id="countdown">
                    <span class="days">0</span> дней 
                    <span class="hours">0</span> часов
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="scroll-down-indicator" title="Прокрутить вниз" id="scrollDownIndicator">
            <div class="scroll-down-line">
                <div class="scroll-down-arrow"></div>
            </div>
            <div class="scroll-down-horizontal-line"></div>
        </div>
    </div>

    <div class="info-section">
        <div class="info-content">
            <h2 class="section-title">Наши популярные туры</h2>
            <p class="section-subtitle">Откройте для себя лучшие направления этого сезона</p>
            
            <div class="tours-container">
                <?php foreach ($tours as $tour): ?>
                <div class="tour-card">
                    <img src="<?php echo htmlspecialchars($tour['image']); ?>" alt="<?php echo htmlspecialchars($tour['title']); ?>" class="tour-image">
                    <div class="tour-content">
                        <h3 class="tour-title"><?php echo htmlspecialchars($tour['title']); ?></h3>
                        <div class="tour-price">от <?php echo htmlspecialchars($tour['price'] ?? '0'); ?> ₽</div>
                        <a href="tours.php?id=<?php echo $tour['id']; ?>" class="tour-button">Подробнее</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <script>
        const tours = <?php echo json_encode($tours); ?>;
        let currentTourIndex = 0;
        let currentTourId = tours.length > 0 ? tours[0].id : null;

        function randomBounceAnimation() {
            const scrollDown = document.getElementById('scrollDownIndicator');
            const randomInterval = Math.floor(Math.random() * 10000) + 5000;
            
            setTimeout(() => {
                scrollDown.classList.add('scroll-down-bounce');
                
                setTimeout(() => {
                    scrollDown.classList.remove('scroll-down-bounce');
                }, 1500);
                
                randomBounceAnimation();
            }, randomInterval);
        }

        if (tours.length > 0) {
            randomBounceAnimation();
        }

        document.getElementById('scrollDownIndicator').addEventListener('click', function() {
            const infoSection = document.querySelector('.info-section');
            infoSection.scrollIntoView({ behavior: 'smooth' });
        });

        function changeBackground() {
            if (tours.length === 0) return;
            
            const tour = tours[currentTourIndex];
            document.body.style.backgroundImage = `url('${tour.image}')`;
            
            const container = document.getElementById('tourTitleContainer');
            const countryElement = document.getElementById('countryName');
            const locationElement = document.getElementById('locationName');
            
            const titleParts = tour.title.split('|');
            const country = titleParts.length > 1 ? titleParts[0].trim() : '';
            const location = titleParts.length > 1 ? titleParts[1].trim() : tour.title;
            
            countryElement.textContent = country;
            locationElement.textContent = location;
            
            currentTourId = tour.id;
            
            container.classList.add('fade-in');
            
            setTimeout(() => {
                container.classList.remove('fade-in');
            }, 800);
            
            currentTourIndex = (currentTourIndex + 1) % tours.length;
        }

        if (tours.length > 0) {
            changeBackground();
        }

        setInterval(changeBackground, 10000);

        document.getElementById('discoverBtn').addEventListener('click', function() {
            if (currentTourId) {
                window.location.href = `tours.php?id=${currentTourId}`;
            }
        });

        <?php if ($upcoming_tour): ?>
        function updateCountdown() {
            const startDate = new Date('<?php echo $upcoming_tour['start_date']; ?>');
            const now = new Date();
            const diff = startDate - now;

            if (diff <= 0) {
                document.getElementById('countdown').innerHTML = 'Тур начался!';
                return;
            }

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));

            document.getElementById('countdown').innerHTML = `
                <span class="days">${days}</span> дней 
                <span class="hours">${hours}</span> часов
            `;
        }

        updateCountdown();
        setInterval(updateCountdown, 3600000);
        <?php endif; ?>

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in');
                }
            });
        }, { threshold: 0.1 });
        
        document.querySelectorAll('.tour-card').forEach(card => {
            observer.observe(card);
        });
        document.addEventListener('DOMContentLoaded', function() {
    const navbar = document.querySelector('.nav-links');
    if (navbar) {
        console.log('Navbar найден в DOM:', navbar);
        navbar.addEventListener('click', function(e) {
            console.log('Клик по navbar:', e.target);
            if (e.target.tagName === 'A') {
                console.log('Переход по ссылке:', e.target.href);
            }
        });
    } else {
        console.error('Navbar не найден в DOM');
        const navs = document.querySelectorAll('nav');
        console.log('Все nav-элементы:', navs);
        navs.forEach((nav, index) => {
            console.log(`Nav ${index}:`, nav.className, nav.outerHTML);
        });
    }
});
    </script>
</body>
</html>