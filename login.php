<?php
include 'config.php';
session_start();

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $sql = "SELECT id, password FROM users WHERE username = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows > 0) {
        $stmt->bind_result($id, $hashed_password);
        $stmt->fetch();
        if (password_verify($password, $hashed_password)) {
            $_SESSION['user_id'] = $id; // Сохраняем ID пользователя
            header("Location: profile.php");
            exit();
        } else {
            $message = "Неверный пароль!";
        }
    } else {
        $message = "Пользователь не найден!";
    }
    $stmt->close();
    $conn->close();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Voyger - Login</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Arial', sans-serif;
        }
        
        body {
            display: flex;
            min-height: 100vh;
            overflow: hidden;
            background-color: white;
        }
        
        .left-panel {
            width: 50%;
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center; /* Добавлено для центрирования по горизонтали */
            background-color: #ffffff;
        }
        
        .left-panel-content {
            width: 100%;
            max-width: 400px; /* Ограничиваем ширину контента */
        }
        
        .right-panel {
            width: 80%;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: white;
            position: relative;
        }
        
        .image-frame {
            position: relative;
            width: 90%;
            height: 90%;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.2);
        }
        
        .slides-container {
            position: relative;
            width: 100%;
            height: 100%;
        }
        
        .slide {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            opacity: 0;
            transition: opacity 1s ease-in-out;
        }
        
        .slide.active {
            opacity: 1;
        }
        
        .image-caption {
            position: absolute;
            bottom: 30px;
            left: 30px;
            color: white;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
            z-index: 2;
        }
        
        .location-title {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .location-subtitle {
            font-size: 1.2rem;
            margin-bottom: 15px;
        }
        
        .distance {
            font-size: 1rem;
            background-color: rgba(255, 255, 255, 0.2);
            padding: 5px 10px;
            border-radius: 20px;
            display: inline-block;
        }
        
        .brand-bottom {
            position: absolute;
            bottom: 30px;
            right: 30px;
            color: white;
            font-size: 1.2rem;
            font-weight: bold;
            font-style: italic;
            text-shadow: 0 2px 5px rgba(0, 0, 0, 0.5);
            z-index: 2;
        }
        
        .logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: #006800;
            margin-bottom: 10px;
            text-align: center; /* Центрируем логотип */
        }

        
        .form-group {
            margin-bottom: 20px;
            width: 100%; /* Занимает всю доступную ширину */
        }
        
        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ecf0f1;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus {
            border-color: #3498db;
            outline: none;
        }
        
        .btn {
            background-color: #006800;
            color: white;
            border: none;
            padding: 14px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 1rem;
            font-weight: bold;
            width: 100%;
            transition: background-color 0.3s;
            margin-top: 10px;
        }
        
        .btn:hover {
            background-color: #004D00;
        }
        
        .login-link {
            margin-top: 20px;
            color: #7f8c8d;
            text-align: center; /* Центрируем ссылку */
        }
        
        .login-link a {
            color: #006800;
            text-decoration: none;
            font-weight: bold;
        }
        
        .login-link a:hover {
            text-decoration: underline;
        }
        

        
        .message {
            color: #e74c3c;
            margin-bottom: 20px;
            font-weight: bold;
            text-align: center; /* Центрируем сообщения об ошибках */
        }
        .podlogo {
            text-align: center; /* Центрируем сообщения об ошибках */
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 20px;
            font-size: 2.5rem;
        }
        
        form {
            width: 100%; /* Форма занимает всю доступную ширину */
        }
    </style>
</head>
<body>
    <div class="left-panel">
        <div class="left-panel-content">
            <div class="logo">iTravel</div>
            <div class="podlogo">Начни свое незабываемое путешествие</div>

            
            <?php if (!empty($message)) echo "<p class='message'>$message</p>"; ?>
            
            <form action="" method="POST">
                <div class="form-group">
                    <input type="text" name="username" placeholder="Имя" required>
                </div>
                <div class="form-group">
                    <input type="email" name="email" placeholder="Email" required>
                </div>
                <div class="form-group">
                    <input type="password" name="password" placeholder="Пароль" required>
                </div>
                <button type="submit" class="btn">Войти</button>
            </form>
            
            <div class="login-link">
                Нет учетной записи? <a href="register.php">Регистрация</a>
            </div>

        </div>
    </div>
    
    <div class="right-panel">
        <div class="image-frame">
            <div class="slides-container">
                <?php
                include 'config.php';
                
                $sql = "SELECT id, title, description, price, destination, image FROM travels";
                $result = $conn->query($sql);
                
                if ($result->num_rows > 0) {
                    $first = true;
                    while($row = $result->fetch_assoc()) {
                        $activeClass = $first ? 'active' : '';
                        echo '<img src="'.$row["image"].'" alt="'.$row["title"].'" class="slide '.$activeClass.'" 
                              data-title="'.$row["title"].'" 
                              data-destination="'.$row["destination"].'" 
                              data-price="'.number_format($row["price"], 0, ',', ' ').' ₽">';
                        $first = false;
                    }
                } else {
                    echo '<img src="/default-travel.jpg" alt="Default Travel" class="slide active">';
                }
                $conn->close();
                ?>
            </div>
            
            <div class="image-caption">
                <div class="location-title" id="slide-title">Загрузка...</div>
                <div class="location-subtitle" id="slide-destination"></div>
                <div class="distance" id="slide-price"></div>
            </div>

        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const slides = document.querySelectorAll('.slide');
            let currentSlide = 0;
            
            function showSlide(n) {
                slides.forEach(slide => slide.classList.remove('active'));
                slides[n].classList.add('active');
                
                document.getElementById('slide-title').textContent = slides[n].dataset.title;
                document.getElementById('slide-destination').textContent = slides[n].dataset.destination;
                document.getElementById('slide-price').textContent = 'от ' + slides[n].dataset.price;
            }
            
            function nextSlide() {
                currentSlide = (currentSlide + 1) % slides.length;
                showSlide(currentSlide);
            }
            
            if (slides.length > 0) {
                showSlide(currentSlide);
                setInterval(nextSlide, 7000);
            }
        });
    </script>
</body>
</html>