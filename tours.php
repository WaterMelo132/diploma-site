<?php
header('Permissions-Policy: interest-cohort=()');
session_start();
$show_success = false;
require_once('navbar.php');

// Проверяем соединение
if (!isset($conn) || $conn->connect_error) {
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Database connection failed']));
}

try {
    // Увеличиваем лимит GROUP_CONCAT
    $conn->query("SET SESSION group_concat_max_len = 1000000;");

    $query = "
        SELECT 
            t.id, 
            COALESCE(t.title, 'Untitled Tour') as title, 
            COALESCE(t.destination, 'Unknown Destination') as destination, 
            COALESCE(t.price, 0) as price, 
            COALESCE(t.status, 'inactive') as status, 
            COALESCE(t.image, '/travel/images/placeholder.jpg') as image, 
            COALESCE(t.images, '/travel/images/placeholder.jpg') as images, 
            COALESCE(t.description, '') as description, 
            t.start_date, 
            t.end_date, 
            COALESCE(t.transport_type, '') as transport_type, 
            COALESCE(t.transport_type_en, '') as transport_type_en, 
            COALESCE(t.transport_details, '') as transport_details,
            GROUP_CONCAT(DISTINCT p.id ORDER BY p.id SEPARATOR ',') as package_ids,
            GROUP_CONCAT(DISTINCT COALESCE(p.name, 'Без названия') ORDER BY p.id SEPARATOR ',') as package_names,
            GROUP_CONCAT(DISTINCT COALESCE(p.description, '') ORDER BY p.id SEPARATOR ',') as package_descriptions,
            GROUP_CONCAT(DISTINCT COALESCE(p.price, 0) ORDER BY p.id SEPARATOR ',') as package_prices,
            GROUP_CONCAT(
                (SELECT GROUP_CONCAT(s.id ORDER BY s.id SEPARATOR ';')
                 FROM package_services ps
                 JOIN services s ON ps.service_id = s.id
                 WHERE ps.package_id = p.id)
                ORDER BY p.id SEPARATOR ','
            ) as service_ids,
            GROUP_CONCAT(
                (SELECT GROUP_CONCAT(COALESCE(s.name, 'Без названия') ORDER BY s.id SEPARATOR ';')
                 FROM package_services ps
                 JOIN services s ON ps.service_id = s.id
                 WHERE ps.package_id = p.id)
                ORDER BY p.id SEPARATOR ','
            ) as service_names
        FROM travels t
        LEFT JOIN tour_packages tp ON t.id = tp.tour_id
        LEFT JOIN packages p ON tp.package_id = p.id
        GROUP BY t.id
    ";
    $stmt = $conn->prepare($query);

    if (!$stmt) {
        throw new Exception('Ошибка подготовки запроса: ' . $conn->error);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    $tours = $result->fetch_all(MYSQLI_ASSOC);

    foreach ($tours as $index => $tour) {
        if ($tour['package_ids']) {
            $package_ids = array_filter(explode(',', $tour['package_ids']));
            $package_names = array_filter(explode(',', $tour['package_names']));
            $package_descriptions = array_filter(explode(',', $tour['package_descriptions']));
            $package_prices = array_filter(explode(',', $tour['package_prices']));
            $service_ids_raw = $tour['service_ids'] ? explode(',', $tour['service_ids']) : [];
            $service_names_raw = $tour['service_names'] ? explode(',', $tour['service_names']) : [];

            $service_ids = array_map(function($ids) { return $ids ? array_filter(explode(';', $ids)) : []; }, $service_ids_raw);
            $service_names = array_map(function($names) { return $names ? array_filter(explode(';', $names)) : []; }, $service_names_raw);

            $tours[$index]['packages'] = [];
            $count = min(count($package_ids), count($package_names), count($package_descriptions), count($package_prices));
            for ($i = 0; $i < $count; $i++) {
                if (!empty($package_ids[$i]) && !empty($package_names[$i])) {
                    $services = [];
                    if (isset($service_ids[$i]) && isset($service_names[$i])) {
                        $current_service_ids = $service_ids[$i] ?? [];
                        $current_service_names = $service_names[$i] ?? [];
                        $service_count = min(count($current_service_ids), count($current_service_names));
                        for ($j = 0; $j < $service_count; $j++) {
                            if (!empty($current_service_ids[$j]) && !empty($current_service_names[$j])) {
                                $services[] = [
                                    'id' => $current_service_ids[$j],
                                    'name' => $current_service_names[$j]
                                ];
                            }
                        }
                    }
                    $tours[$index]['packages'][] = [
                        'id' => $package_ids[$i],
                        'name' => $package_names[$i],
                        'description' => $package_descriptions[$i] ?? '',
                        'price' => $package_prices[$i] ?? 0,
                        'services' => $services
                    ];
                }
            }
        } else {
            $tours[$index]['packages'] = [];
        }

        // Определяем акцию
        $start_date = $tour['start_date'] ? new DateTime($tour['start_date']) : null;
        $today = new DateTime();
        $is_promo = false;
        $days_until_start = null;

        if ($start_date && $tour['status'] === 'active') {
            $interval = $today->diff($start_date);
            $days_until_start = $interval->days;
            $is_promo = $days_until_start <= 7 && !$interval->invert;
        }

        $tours[$index]['is_promo'] = $is_promo;
        $tours[$index]['original_price'] = (float)$tour['price'];
        $tours[$index]['discount_price'] = $is_promo ? round($tour['price'] * 0.9) : (float)$tour['price'];

        unset($tours[$index]['package_ids'], $tours[$index]['package_names'], 
              $tours[$index]['package_descriptions'], $tours[$index]['package_prices'],
              $tours[$index]['service_ids'], $tours[$index]['service_names']);
    }

    $destinations = array_unique(array_column($tours, 'destination'));
    sort($destinations);

    $stmt->close();

} catch (Exception $e) {
    error_log($e->getMessage());
    echo '<div class="container"><div class="error-message" style="color: red; text-align: center; padding: 2rem;">Ошибка загрузки туров. Пожалуйста, попробуйте позже.</div></div>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Список туров | Travel Agency</title>
    <meta name="description" content="Выберите лучшие туры для вашего путешествия">

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.3/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.3/dist/leaflet.js"></script>
    <script src="https://unpkg.com/imask@7.6.1/dist/imask.min.js"></script>
    <style>
        :root {
            --primary-color: #2563eb;
            --primary-light: #dbeafe;
            --success-color: #16a34a;
            --warning-color: #ea580c;
            --error-color: #dc2626;
            --text-secondary: #64748b;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
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
         .navbar {

    position: fixed !important;
    top: 20px !important;
}
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .page-title {
            text-align: center;
            margin-bottom: 2rem;
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            text-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
        
        .filters-container {
            background: rgba(255, 255, 255, 0.9);
            backdrop-filter: blur(10px);
            border-radius: 1rem;
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border: 1px solid var(--border-color);
            animation: fadeIn 0.5s ease-out;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }
        
        .filter-group {
            margin-bottom: 0;
        }
        
        .filter-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: black;
            font-size: 0.875rem;
        }
        
        .filter-select, 
        .filter-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid var(--border-color);
            border-radius: 0.5rem;
            font-size: 0.9375rem;
            background-color: white;
            transition: var(--transition);
        }
        
        .filter-select:focus, 
        .filter-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .price-range-container {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .price-range-input {
            flex: 1;
        }
        
        .price-value {
            min-width: 60px;
            text-align: center;
            font-weight: 500;
            color: black;
        }
        
        .reset-filters {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            background-color: var(--primary-light);
            color: var(--primary-color);
            border: none;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            margin-top: 1rem;
        }
        
        .reset-filters:hover {
            background-color: #bfdbfe;
        }
        
        .tour-cards-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .tour-card {
            background: var(--card-bg);
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: var(--transition);
            cursor: pointer;
            border: 1px solid var(--border-color);
            color: black;
            opacity: 0;
            transform: translateY(20px);
            animation: fadeInUp 0.5s ease-out forwards;
        }
        
        .tour-card:hover {
            transform: translateY(-5px) !important;
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
            border-color: #cbd5e1;
        }
        
        .tour-image {
            width: 100%;
            height: 200px;
            object-fit: cover;
            border-bottom: 1px solid var(--border-color);
            transition: var(--transition);
        }
        
        .tour-card:hover .tour-image {
            transform: scale(1.03);
        }
        
        .tour-info {
            padding: 1.25rem;
        }
        
        .tour-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: black;
        }
        
        .tour-destination {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .tour-price {
            font-size: 1.125rem;
            font-weight: 700;
            color: black;
        }
        
        .tour-status {
            display: inline-block;
            margin-top: 0.75rem;
            padding: 0.25rem 0.625rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 500;
        }
        
        .status-active {
            background-color: #dcfce7;
            color: var(--success-color);
        }
        
        .status-inactive {
            background-color: #fee2e2;
            color: var(--error-color);
        }
        
        .status-upcoming {
            background-color: #ffedd5;
            color: var(--warning-color);
        }
        
        .tour-packages {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        
        .modal {
            position: fixed;
            z-index: 1000;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.6);
            backdrop-filter: blur(8px);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease;
            overflow-y: auto;
        }
        
        body.modal-open {
            overflow: hidden;
            position: fixed;
            width: 100%;
        }
        
        .modal.show {
            opacity: 1;
            visibility: visible;
        }
        
        .modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 1.5rem;
            width: 70%;
            max-width: 1500px;
            box-shadow: 0 15px 60px rgba(0,0,0,0.25);
            position: relative;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }
        
        .modal.show .modal-content {
            transform: scale(1);
        }
        
        .close {
            position: absolute;
            top: 0.25rem;
            right: 1.25rem;
            font-size: 1.8rem;
            font-weight: 300;
            color: #94a3b8;
            cursor: pointer;
            transition: color 0.2s;
        }
        
        .close:hover {
            color: black;
        }
        
        .modal-description strong {
            color: #1e293b;
        }
        
        .modal-title {
            font-size: 2rem;
            font-weight: 700;
            color: #1e293b;
            line-height: 1.2;
        }
        
        .modal-destination {
            font-size: 1rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .modal-description {
            line-height: 1.7;
            color: #4b5563;
            padding: 1rem 0;
            max-height: 300px;
            overflow-y: auto;
        }
        
        .modal-description h3 {
            font-size: 1.3rem;
            color: #1e293b;
            margin: 1rem 0;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .modal-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1.25rem;
            border-top: 1px solid var(--border-color);
        }
        
        .modal-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: black;
        }
        
        .modal-status {
            display: inline-block;
            padding: 0.375rem 0.75rem;
            border-radius: 0.375rem;
            font-weight: 500;
            font-size: 0.875rem;
            margin-top: 0.5rem;
        }
        
        .modal-button {
            display: inline-block;
            padding: 0.75rem 1.5rem;
            background-color: var(--primary-color);
            color: white;
            text-decoration: none;
            border-radius: 0.5rem;
            font-weight: 600;
            transition: var(--transition);
            border: none;
            cursor: pointer;
            font-size: 0.9375rem;
        }
        
        .modal-button:hover {
            background-color: #1d4ed8;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .modal-button:disabled {
            background-color: #94a3b8;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        @keyframes fadeInUp {
            from { 
                opacity: 0;
                transform: translateY(20px);
            }
            to { 
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .card-delay-1 { animation-delay: 0.1s; }
        .card-delay-2 { animation-delay: 0.2s; }
        .card-delay-3 { animation-delay: 0.3s; }
        .card-delay-4 { animation-delay: 0.4s; }
        .card-delay-5 { animation-delay: 0.5s; }
        
        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 3rem;
            background: rgba(255,255,255,0.9);
            border-radius: 1rem;
            animation: fadeIn 0.5s ease-out;
        }
        
        .no-results-icon {
            font-size: 3rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }
        
        .no-results-text {
            color: var(--text-color);
            font-size: 1.125rem;
        }
        
        @media (max-width: 768px) {
            .container {
                padding: 1.5rem 1rem;
            }
            
            .modal-content {
                padding: 1.5rem;
                width: 95%;
            }
            
            .tour-cards-container {
                grid-template-columns: 1fr;
            }
            
            .filters-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .modal-footer {
                flex-direction: column;
                gap: 1rem;
                align-items: flex-start;
            }
            
            .page-title {
                font-size: 2rem;
            }
        }
        
        .modal-gallery {
            position: relative;
            width: 100%;
            height: 830px;
            overflow: hidden;
            border-radius: 1rem;
            margin-bottom: 2rem;
            background: #f1f5f9;
        }
        
        .modal-gallery-inner {
            display: flex;
            transition: transform 0.4s ease;
            height: 100%;
        }
        
        .modal-gallery img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            flex-shrink: 0;
            border-radius: 1rem;
        }
        
        .gallery-prev,
        .gallery-next {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(255, 255, 255, 0.9);
            color: #1e293b;
            border: none;
            padding: 0.75rem;
            cursor: pointer;
            font-size: 1.5rem;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            transition: all 0.2s;
        }
        
        .gallery-prev:hover,
        .gallery-next:hover {
            background: #fff;
            transform: translateY(-50%) scale(1.1);
        }
        
        .gallery-prev {
            left: 15px;
        }
        
        .gallery-next {
            right: 15px;
        }
        
        .gallery-disabled {
            opacity: 0.4;
            cursor: not-allowed;
            transform: translateY(-50%);
        }
        
        .gallery-indicators {
            position: absolute;
            bottom: 15px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 10px;
        }
        
        .gallery-indicator {
            width: 12px;
            height: 12px;
            background: rgba(255, 255, 255, 0.7);
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .gallery-indicator.active {
            background: #2563eb;
            transform: scale(1.2);
        }
        
        .booking-form {
            display: none;
            padding: 1.5rem;
            background: #f8fafc;
            border-radius: 0.75rem;
            margin-top: 1.5rem;
            border: 1px solid #e2e8f0;
        }
        
        .booking-form.show {
            display: block;
            animation: fadeIn 0.3s ease-out;
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #1e293b;
        }
        
        .form-input {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .btn {
            padding: 0.75rem 1.5rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            border: none;
        }
        
        .btn-primary {
            background-color: #2563eb;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #1d4ed8;
        }
        
        .btn-secondary {
            background-color: #e2e8f0;
            color: #1e293b;
        }
        
        .btn-secondary:hover {
            background-color: #cbd5e1;
        }
        
        .success-message {
            display: none;
            padding: 1.5rem;
            background: #dcfce7;
            color: #166534;
            border-radius: 0.75rem;
            margin-top: 1.5rem;
            text-align: center;
            animation: fadeIn 0.3s ease-out;
        }
        
        .success-message.show {
            display: block;
        }
        
        .success-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
            color: #16a34a;
        }
        
        .modal-dates {
            font-size: 1rem;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
        }
        
        .booking-form label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4b5563;
        }
        
        .booking-form input[type="checkbox"],
        .booking-form input[type="radio"] {
            margin-right: 0.5rem;
        }
        
        @keyframes trainMove {
            0%, 100% { transform: translateX(-2px); }
            50% { transform: translateX(3px); }
        }
        
        @keyframes busTilt {
            0%, 100% { transform: rotate(0deg); }
            25% { transform: rotate(-10deg); }
            75% { transform: rotate(10deg); }
        }
        
        @keyframes airplaneSway {
            0%, 100% { transform: rotate(0deg) translateY(0); }
            50% { transform: rotate(2deg) translateY(-5px); }
        }
        
        @keyframes transportGlow {
            0%, 100% { text-shadow: 0 0 5px currentColor; }
            50% { text-shadow: 0 0 15px currentColor; }
        }
        
        .transport-icon-train {
            color: #10b981;
            animation: trainMove 2s ease-in-out infinite, transportGlow 2.2s ease-in-out infinite;
        }
        
        .transport-icon-bus {
            color: #f59e0b;
            animation: busTilt 2.5s ease-in-out infinite, transportGlow 1.8s ease-in-out infinite;
        }
        
        .transport-icon-airplane {
            color: #3b82f6;
            animation: airplaneSway 2.3s ease-in-out infinite, transportGlow 2s ease-in-out infinite;
        }
        
        .transport-icon-train:hover,
        .transport-icon-bus:hover,
        .transport-icon-airplane:hover {
            animation-play-state: paused;
            transform: translateY(-4px) scale(1.1);
            filter: drop-shadow(0 8px 20px rgba(37, 99, 235, 0.6));
        }
        
        .package-cards-container {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 5rem;
            margin-top: 1rem;
        }
        
        .package-card {
            background: var(--card-bg);
            border-radius: 0.5rem;
            border: 1px solid var(--border-color);
            padding: 1rem;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
            color: #1e293b;
        }
        
        .package-card:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .package-card input[type="radio"] {
            position: absolute;
            top: 1rem;
            right: 1rem;
        }
        
        .package-card label {
            display: block;
            cursor: pointer;
        }
        
        .package-title {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .package-price {
            font-size: 0.875rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .package-description {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        
        .package-services {
            font-size: 0.875rem;
            color: #4b5563;
        }
        
        .package-services ul {
            list-style-type: disc;
            margin-left: 1.5rem;
        }
        
        .package-services li {
            margin-bottom: 0.25rem;
        }
        
        .package-card.selected {
            border-color: var(--success-color);
            background-color: #f0fdf4;
        }
        
        .promo-badge {
            position: absolute;
            top: 10px;
            right: 10px;
            background-color: #ff4d4f;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            z-index: 1;
        }
        
        .tour-card.promo {
            border: 2px solid #ff4d4f;
            background: #fff1f2;
        }
        
        .original-price {
            text-decoration: line-through;
            color: #94a3b8;
            font-size: 0.875rem;
            margin-right: 0.5rem;
        }
        
        .discount-price {
            color: #dc2626;
            font-weight: 700;
        }
        
        .promo-timer {
            font-size: 1rem;
            color: #dc2626;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            font-weight: 500;
            background: #fff1f2;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            animation: pulse 2s infinite;
        }
        
        .promo-timer i {
            color: #ff4d4f;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
        .modal-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding-top: 1.25rem;
    border-top: 1px solid var(--border-color);
}

.booking-section {
    display: flex;
    gap: 1.5rem;
    width: 100%;
}

.form-disclaimer {
    flex: 0 0 30%;
    background: #e6f0fa;
    border-radius: 0.75rem;
    padding: 1rem;
    border-left: 4px solid var(--primary-color);
    color: #1e293b;
    font-size: 0.875rem;
    line-height: 1.5;
    animation: fadeIn 0.3s ease-out;
}

.form-disclaimer i {
    color: var(--primary-color);
    font-size: 1.25rem;
    margin-right: 0.75rem;
    flex-shrink: 0;
}

.form-disclaimer p {
    margin: 0;
}

.form-disclaimer strong {
    font-weight: 600;
}

.form-disclaimer a {
    color: var(--primary-color);
    text-decoration: none;
    font-weight: 600;
    transition: color 0.3s ease;
}

.form-disclaimer a:hover {
    color: #1d4ed8;
}

.booking-form {
    flex: 1;
    display: none;
    padding: 1.5rem;
    background: #f8fafc;
    border-radius: 0.75rem;
    margin-top: 0;
    border: 1px solid #e2e8f0;
}

.booking-form.show {
    display: block;
    animation: fadeIn 0.3s ease-out;
}

@media (max-width: 768px) {
    .booking-section {
        flex-direction: column;
    }

    .form-disclaimer {
        flex: 0 0 100%;
        margin-bottom: 1.5rem;
    }

    .booking-form {
        padding: 1rem;
    }
}
        

    </style>
</head>
<body>
<div class="container">
    <h1 class="page-title">Наши туры</h1>

    <?php if ($show_success): ?>
        <div class="success-message show">
            <div class="success-icon"><i class="fas fa-check-circle"></i></div>
            <p>Новый тур успешно добавлен!</p>
        </div>
    <?php endif; ?>

    <div class="filters-container">
        <div class="filters-grid">
            <div class="filter-group">
                <label for="searchInput" class="filter-label">Поиск</label>
                <input type="text" id="searchInput" class="filter-input" placeholder="Название или направление..." aria-label="Поиск туров">
            </div>

            <div class="filter-group">
                <label for="destinationFilter" class="filter-label">Направление</label>
                <select id="destinationFilter" class="filter-select">
                    <option value="">Все направления</option>
                    <?php foreach ($destinations as $destination): ?>
                        <option value="<?= htmlspecialchars($destination) ?>"><?= htmlspecialchars($destination) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label for="statusFilter" class="filter-label">Статус</label>
                <select id="statusFilter" class="filter-select">
                    <option value="">Все статусы</option>
                    <option value="active">Доступен</option>
                    <option value="upcoming">Скоро</option>
                    <option value="inactive">Недоступен</option>
                </select>
            </div>

            <div class="filter-group">
                <label class="filter-label">Цена, руб.</label>
                <div class="price-range-container">
                    <input type="range" id="priceFilter" class="price-range-input filter-input" min="0" max="<?= max(array_column($tours, 'discount_price')) ?>" value="<?= max(array_column($tours, 'discount_price')) ?>">
                    <span id="priceValue" class="price-value">до <?= number_format(max(array_column($tours, 'discount_price')), 0, ',', ' ') ?></span>
                </div>
            </div>
        </div>

        <button id="resetFilters" class="reset-filters">
            <i class="fas fa-redo"></i>
            Сбросить фильтры
        </button>
    </div>

    <div id="tourCardsContainer" class="tour-cards-container">
        <?php foreach ($tours as $index => $tour): ?>
            <div class="tour-card <?php echo $tour['is_promo'] ? 'promo' : ''; ?> card-delay-<?= ($index % 5) + 1 ?>" 
                 data-id="<?= $tour['id'] ?>" 
                 data-destination="<?= htmlspecialchars($tour['destination']) ?>" 
                 data-status="<?= htmlspecialchars($tour['status']) ?>" 
                 data-price="<?= $tour['discount_price'] ?>">
                <?php if ($tour['is_promo']): ?>
                    <span class="promo-badge">Акция!</span>
                <?php endif; ?>
                <img 
                    src="<?= htmlspecialchars($tour['image']) ?>" 
                    alt="<?= htmlspecialchars($tour['title']) ?>" 
                    class="tour-image"
                    loading="lazy"
                    onerror="this.src='/travel/images/placeholder.jpg'"
                >
                <div class="tour-info">
                    <h3 class="tour-title"><?= htmlspecialchars($tour['title']) ?></h3>
                    <div class="tour-destination">
                        <i class="fas fa-map-marker-alt"></i>
                        <?= htmlspecialchars($tour['destination']) ?>
                    </div>
                    <div class="tour-price">
                        <?php if ($tour['is_promo']): ?>
                            <span class="original-price"><?= number_format($tour['original_price'], 0, ',', ' ') ?> руб.</span>
                            <span class="discount-price"><?= number_format($tour['discount_price'], 0, ',', ' ') ?> руб.</span>
                        <?php else: ?>
                            <?= number_format($tour['discount_price'], 0, ',', ' ') ?> руб.
                        <?php endif; ?>
                    </div>
                    <div class="tour-status status-<?= htmlspecialchars($tour['status']) ?>">
                        <?= ($tour['status'] === 'active' ? 'Доступен' : ($tour['status'] === 'upcoming' ? 'Скоро' : 'Недоступен')) ?>
                    </div>
                    <?php if (!empty($tour['packages'])): ?>
                        <div class="tour-packages">
                            <strong>Пакеты:</strong> <?= implode(', ', array_map('htmlspecialchars', array_column($tour['packages'], 'name'))) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div id="tourModal" class="modal" aria-hidden="true" role="dialog">
        <div class="modal-content">
            <span class="close" aria-label="Закрыть">×</span>
            <div class="modal-gallery">
                <div class="modal-gallery-inner" id="modalGallery"></div>
                <button class="gallery-prev" onclick="moveGallery(-1)">❮</button>
                <button class="gallery-next" onclick="moveGallery(1)">❯</button>
                <div class="gallery-indicators" id="galleryIndicators"></div>
            </div>

            <div class="modal-header">
                <h2 id="modalTitle" class="modal-title"></h2>
                <div id="modalDestination" class="modal-destination">
                    <i class="fas fa-map-marker-alt"></i>
                    <span id="destinationText"></span>
                    <div class="tour-transport" id="transportInfo"></div>
                </div>

                <div id="modalDates" class="modal-dates">
                    <i class="fas fa-calendar-alt"></i>
                    <span id="datesText"></span>
                </div>

                <div id="promoTimer" class="promo-timer" style="display: none;">
                    <i class="fas fa-clock"></i>
                    <span>Акция заканчивается через: </span>
                    <span id="timerText"></span>
                </div>
            </div>

            <div id="modalDescription" class="modal-description">
                <h3>Описание</h3>
                <div id="tourDescription"></div>
                <h3>Выберите пакет услуг:</h3>
                <div id="tourPackages" class="package-cards-container"></div>
            </div>

            <div class="modal-footer">
                
                <div>
                    <div id="modalPrice" class="modal-price"></div>
                    <div id="modalStatus" class="modal-status"></div>
                </div>
                <div id="bookingForm" class="booking-form">
                    
                    <div class="form-group">
                        <label for="bookingName" class="form-label">Имя:</label>
                        <input type="text" id="bookingName" class="form-input" placeholder="Ваше имя" required>
                    </div>
                    <div class="form-group">
                        <label for="bookingPersons" class="form-label">Количество человек:</label>
                        <input type="number" id="bookingPersons" class="form-input" min="1" max="10" value="1" required>
                    </div>
                    <div class="form-group">
                        <label for="bookingPhone" class="form-label">Телефон:</label>
                        <input type="tel" id="bookingPhone" class="form-input" placeholder="+7 (___) ___-__-__" required>
                    </div>
                    <div class="form-group">
                        <label for="bookingEmail" class="form-label">Email:</label>
                        <input type="email" id="bookingEmail" class="form-input" placeholder="example@email.com" required>
                    </div>
                    <div class="form-actions">
                        <button id="confirmBooking" class="btn btn-primary">Подтвердить</button>
                        <button id="cancelBooking" class="btn btn-secondary">Отмена</button>
                    </div>
                      <div class="form-disclaimer">
                    <i class="fas fa-info-circle"></i>
                    <p><strong>Важно</strong>: Указанные имя, фамилия и номер телефона будут проверяться при посадке на транспорт или регистрации на тур. Вводите актуальные данные, соответствующие вашим документам. Для уточнений свяжитесь со службой поддержки: <a href="tel:+74951234567">+7 (495) 123-4567</a> или <a href="mailto:support@itravel.com">support@itravel.com</a>.</p>
                </div>
                </div>
                
                <div id="successMessage" class="success-message">
                    <div class="success-icon"><i class="fas fa-check-circle"></i></div>
                    <p id="successText">Тур успешно забронирован! Мы свяжемся с вами для подтверждения.</p>
                </div>
                <button id="modalButton" class="modal-button">Забронировать</button>
            </div>
          
        </div>
        
    </div>
</div>




<script>
    const transportMap = {
        'train': { icon: 'train', label: 'Поезд', class: 'transport-icon-train', color: '#10b981' },
        'airplane': { icon: 'plane', label: 'Самолет', class: 'transport-icon-airplane', color: '#3b82f6' },
        'bus': { icon: 'bus-alt', label: 'Автобус', class: 'transport-icon-bus', color: '#f59e0b' }
    };

    const toursData = <?= json_encode($tours, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    let currentModalTourId = null;
    let currentSlide = 0;

    const searchInput = document.getElementById('searchInput');
    const destinationFilter = document.getElementById('destinationFilter');
    const statusFilter = document.getElementById('statusFilter');
    const priceFilter = document.getElementById('priceFilter');
    const priceValue = document.getElementById('priceValue');
    const resetFiltersBtn = document.getElementById('resetFilters');
    const tourCardsContainer = document.getElementById('tourCardsContainer');
    const modal = document.getElementById('tourModal');
    const closeModalBtn = document.querySelector('.close');
    const bookingForm = document.getElementById('bookingForm');
    const modalButton = document.getElementById('modalButton');
    const confirmBookingBtn = document.getElementById('confirmBooking');
    const cancelBookingBtn = document.getElementById('cancelBooking');
    const bookingName = document.getElementById('bookingName');
    const bookingPersons = document.getElementById('bookingPersons');
    const bookingPhone = document.getElementById('bookingPhone');
    const bookingEmail = document.getElementById('bookingEmail');
    const successMessage = document.getElementById('successMessage');
    const successText = document.getElementById('successText');
    const transportInfo = document.getElementById('transportInfo');

    document.addEventListener('DOMContentLoaded', () => {
        const tourCards = document.querySelectorAll('.tour-card');
        tourCards.forEach(card => {
            card.addEventListener('click', () => {
                const tourId = card.getAttribute('data-id');
                openModal(tourId);
            });
        });

        searchInput.addEventListener('input', filterTours);
        destinationFilter.addEventListener('change', filterTours);
        statusFilter.addEventListener('change', filterTours);
        priceFilter.addEventListener('input', updatePriceValue);
        priceFilter.addEventListener('change', filterTours);
        resetFiltersBtn.addEventListener('click', resetFilters);
        closeModalBtn.addEventListener('click', closeModal);
        window.addEventListener('click', (e) => {
            if (e.target === modal) closeModal();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
        });

        updatePriceValue();

        if (window.location.search.includes('refresh=true')) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });

    function updatePriceValue() {
        priceValue.textContent = `до ${Number(priceFilter.value).toLocaleString('ru-RU')}`;
    }

    function resetFilters() {
        searchInput.value = '';
        destinationFilter.value = '';
        statusFilter.value = '';
        priceFilter.value = priceFilter.max;
        updatePriceValue();
        filterTours();

        resetFiltersBtn.innerHTML = '<i class="fas fa-check"></i> Фильтры сброшены';
        setTimeout(() => {
            resetFiltersBtn.innerHTML = '<i class="fas fa-redo"></i> Сбросить фильтры';
        }, 2000);
    }

    function filterTours() {
        const searchTerm = searchInput.value.trim().toLowerCase();
        const destination = destinationFilter.value;
        const status = statusFilter.value;
        const maxPrice = Number(priceFilter.value);

        const filteredTours = toursData.filter(tour => {
            const matchesSearch = !searchTerm || 
                (tour.title && tour.title.toLowerCase().includes(searchTerm)) || 
                (tour.destination && tour.destination.toLowerCase().includes(searchTerm));
            const matchesDestination = !destination || tour.destination === destination;
            const matchesStatus = !status || tour.status === status;
            const matchesPrice = tour.discount_price <= maxPrice;

            return matchesSearch && matchesDestination && matchesStatus && matchesPrice;
        });

        renderTours(filteredTours);
    }

    function renderTours(tours) {
        if (!tours.length) {
            tourCardsContainer.innerHTML = `
                <div class="no-results">
                    <div class="no-results-icon">
                        <i class="fas fa-search"></i>
                    </div>
                    <p class="no-results-text">Ничего не найдено. Попробуйте изменить параметры поиска.</p>
                </div>
            `;
            return;
        }

        tourCardsContainer.innerHTML = tours.map((tour, index) => `
            <div class="tour-card ${tour.is_promo ? 'promo' : ''} card-delay-${(index % 5) + 1}" 
                 data-id="${tour.id}" 
                 data-destination="${escapeHtml(tour.destination)}" 
                 data-status="${escapeHtml(tour.status)}" 
                 data-price="${tour.discount_price}">
                ${tour.is_promo ? '<span class="promo-badge">Акция!</span>' : ''}
                <img 
                    src="${escapeHtml(tour.image)}" 
                    alt="${escapeHtml(tour.title)}" 
                    class="tour-image"
                    loading="lazy"
                    onerror="this.src='/travel/images/placeholder.jpg'"
                >
                <div class="tour-info">
                    <h3 class="tour-title">${escapeHtml(tour.title)}</h3>
                    <div class="tour-destination">
                        <i class="fas fa-map-marker-alt"></i>
                        ${escapeHtml(tour.destination)}
                    </div>
                    <div class="tour-price">
                        ${tour.is_promo ? `
                            <span class="original-price">${Number(tour.original_price).toLocaleString('ru-RU')} руб.</span>
                            <span class="discount-price">${Number(tour.discount_price).toLocaleString('ru-RU')} руб.</span>
                        ` : `
                            ${Number(tour.discount_price).toLocaleString('ru-RU')} руб.
                        `}
                    </div>
                    <div class="tour-status status-${escapeHtml(tour.status)}">
                        ${getStatusText(tour.status)}
                    </div>
                    ${tour.packages && tour.packages.length > 0 ? `
                        <div class="tour-packages">
                            <strong>Пакеты:</strong> ${tour.packages.map(pkg => escapeHtml(pkg.name)).join(', ')}
                        </div>
                    ` : ''}
                </div>
            </div>
        `).join('');

        const tourCards = document.querySelectorAll('.tour-card');
        tourCards.forEach(card => {
            card.addEventListener('click', () => {
                const tourId = card.getAttribute('data-id');
                openModal(tourId);
            });
        });
    }

    function startPromoTimer(startDate, timerElement) {
        const updateTimer = () => {
            const now = new Date();
            const start = new Date(startDate);
            const diff = start - now;

            if (diff <= 0) {
                timerElement.textContent = 'Акция завершена!';
                return;
            }

            const days = Math.floor(diff / (1000 * 60 * 60 * 24));
            const hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((diff % (1000 * 60)) / 1000);

            timerElement.textContent = `${days} д. ${hours} ч. ${minutes} мин. ${seconds} сек.`;
        };

        updateTimer();
        return setInterval(updateTimer, 1000);
    }

    function stopPromoTimer(timerInterval) {
        if (timerInterval) {
            clearInterval(timerInterval);
        }
    }

    let timerInterval = null;

    function openModal(tourId) {
        const tour = toursData.find(t => t.id == tourId);
        if (!tour) return;

        document.body.classList.add('modal-open');

        const images = tour.images && typeof tour.images === 'string' && tour.images.trim()
            ? tour.images.split(',').map(img => img.trim()).filter(img => img)
            : [tour.image && typeof tour.image === 'string' ? tour.image : '/travel/images/placeholder.jpg'];
        const cleanedImages = images.map(img => img.replace(/\s/g, ''));

        const galleryInner = document.getElementById('modalGallery');
        galleryInner.innerHTML = cleanedImages.map(img => `
            <img src="${escapeHtml(img)}" alt="${escapeHtml(tour.title || 'Tour Image')}" loading="lazy" onerror="this.src='/travel/images/placeholder.jpg'">
        `).join('');

        currentSlide = 0;
        updateGallery();

        document.getElementById('modalTitle').textContent = tour.title || 'Untitled Tour';
        document.getElementById('destinationText').textContent = tour.destination || 'Unknown Destination';

        const transport = transportMap[tour.transport_type_en] || { 
            icon: 'question', 
            label: tour.transport_type_en || 'Другой',
            class: '',
            color: '#64748b'
        };
        

        transportInfo.innerHTML = `
            <i class="fas fa-${transport.icon} ${tour.status === 'active' ? 'transport-icon-active ' + transport.class : ''}" 
               style="${tour.status === 'active' ? 'color: ' + transport.color : ''}"></i>
            <span>${escapeHtml(transport.label)}</span>
            ${tour.transport_details ? `<div class="transport-details">${escapeHtml(tour.transport_details)}</div>` : ''}
        `;

       const datesElement = document.getElementById('datesText');
if (tour.start_date && tour.end_date) {
    const startDate = new Date(tour.start_date).toLocaleDateString('ru-RU');
    const endDate = new Date(tour.end_date).toLocaleDateString('ru-RU');
    datesElement.textContent = `${startDate} — ${endDate}`;
} else if (tour.status === 'inactive') {
    datesElement.textContent = 'Даты недоступны';
} else {
    datesElement.textContent = 'Даты не указаны';
}

        const promoTimer = document.getElementById('promoTimer');
        const timerText = document.getElementById('timerText');
        stopPromoTimer(timerInterval);
        if (tour.is_promo && tour.start_date) {
            promoTimer.style.display = 'flex';
            timerInterval = startPromoTimer(tour.start_date, timerText);
        } else {
            promoTimer.style.display = 'none';
        }

        const descriptionElement = document.getElementById('tourDescription');
        descriptionElement.innerHTML = formatDescription(tour.description || 'Описание временно недоступно.');

        const packagesElement = document.getElementById('tourPackages');
        if (tour.packages && tour.packages.length > 0) {
            packagesElement.innerHTML = tour.packages.map((pkg, index) => `
                <div class="package-card ${index === 0 ? 'selected' : ''}">
                    <input type="radio" name="package" id="package-${pkg.id}" value="${pkg.id}" ${index === 0 ? 'checked' : ''}>
                    <label for="package-${pkg.id}">
                        <div class="package-title">${escapeHtml(pkg.name || 'Без названия')}</div>
                        ${pkg.price ? `<div class="package-price">${Number(pkg.price).toLocaleString('ru-RU')} рублей</div>` : ''}
                        ${pkg.description ? `<div class="package-description">${escapeHtml(pkg.description)}</div>` : ''}
                        ${pkg.services && pkg.services.length > 0 ? `
                            <div class="package-services">
                                <strong>Услуги:</strong>
                                <ul>
                                    ${pkg.services.map(service => `<li>${escapeHtml(service.name || 'Без названия')}</li>`).join('')}
                                </ul>
                            </div>
                        ` : '<div class="package-services"><strong>Услуги:</strong><ul><li>Услуги отсутствуют</li></ul></div>'}
                    </label>
                </div>
            `).join('');
        } else {
            packagesElement.innerHTML = '<p>Пакеты услуг не доступны.</p>';
        }

        const packageRadios = document.querySelectorAll('input[name="package"]');
        packageRadios.forEach(radio => {
            radio.addEventListener('change', (e) => {
                const cards = document.querySelectorAll('.package-card');
                cards.forEach(card => card.classList.remove('selected'));
                e.target.closest('.package-card').classList.add('selected');
            });
        });

        const modalPrice = document.getElementById('modalPrice');
        modalPrice.innerHTML = tour.is_promo
            ? `
                <span class="original-price">${Number(tour.original_price).toLocaleString('ru-RU')} руб.</span>
                <span class="discount-price">${Number(tour.discount_price).toLocaleString('ru-RU')} руб.</span>
            `
            : `${Number(tour.discount_price).toLocaleString('ru-RU')} руб.`;

        const statusElement = document.getElementById('modalStatus');
        statusElement.className = 'modal-status';
        if (tour.status === 'active') {
            statusElement.textContent = '✔ Доступен';
            statusElement.classList.add('status-active');
            modalButton.removeAttribute('disabled');
            modalButton.style.display = 'block';
            bookingForm.classList.remove('show');
            successMessage.classList.remove('show');
        } else if (tour.status === 'upcoming') {
            statusElement.textContent = '🕒 Скоро';
            statusElement.classList.add('status-upcoming');
            modalButton.setAttribute('disabled', 'true');
            modalButton.style.display = 'block';
            bookingForm.classList.remove('show');
            successMessage.classList.remove('show');
        } else {
            statusElement.textContent = '🚫 Недоступен';
            statusElement.classList.add('status-inactive');
            modalButton.setAttribute('disabled', 'true');
            modalButton.style.display = 'block';
            bookingForm.classList.remove('show');
            successMessage.classList.remove('show');
        }

        currentModalTourId = tourId;
        modal.classList.add('show');
        modal.scrollTop = 0;

        modalButton.onclick = () => {
            modalButton.style.display = 'none';
            bookingForm.classList.add('show');
            successMessage.classList.remove('show');
        };

        cancelBookingBtn.onclick = () => {
            bookingForm.classList.remove('show');
            modalButton.style.display = 'block';
            successMessage.classList.remove('show');
            bookingName.value = '';
            bookingPhone.value = '';
            bookingEmail.value = '';
        };

        confirmBookingBtn.onclick = () => {
            const name = bookingName.value.trim();
            const phone = bookingPhone.value.trim();
            const email = bookingEmail.value.trim();
            const persons = parseInt(bookingPersons.value.trim());
            const selectedPackage = document.querySelector('input[name="package"]:checked');

            if (!name || !phone || !email || !persons) {
                alert('Пожалуйста, заполните все поля.');
                return;
            }

            if (!selectedPackage && tour.packages && tour.packages.length > 0) {
                alert('Пожалуйста, выберите пакет услуг.');
                return;
            }

            if (!/^\+?\d{10,15}$/.test(phone)) {
                alert('Пожалуйста, введите корректный номер телефона.');
                return;
            }

            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                alert('Пожалуйста, введите корректный email.');
                return;
            }

            if (persons < 1 || persons > 10) {
                alert('Количество человек должно быть от 1 до 10.');
                return;
            }

            const packageId = selectedPackage ? selectedPackage.value : null;
            const packageName = selectedPackage ? tour.packages.find(pkg => pkg.id == packageId).name : 'Без пакета';

            bookTour(tourId, name, phone, email, packageId, packageName, persons);
        };
    }

  function bookTour(tourId, name, phone, email, packageId, packageName, persons) {
    const tour = toursData.find(t => t.id == tourId);
    if (!tour) {
        alert('Тур не найден!');
        return;
    }

    // Добавляем user_id из сессии, если он доступен
    const userId = <?php echo isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 'null'; ?>;
    if (!userId) {
        alert('Пользователь не авторизован. Пожалуйста, войдите в систему.');
        return;
    }

    // Находим выбранный пакет
    const selectedPackage = packageId ? tour.packages.find(pkg => pkg.id == packageId) : null;
    // Рассчитываем общую цену: базовая цена тура + цена пакета (если выбран)
    const packagePrice = selectedPackage && selectedPackage.price ? Number(selectedPackage.price) : 0;
    const tourPrice = Number(tour.discount_price || tour.price || 0);
    const totalPrice = (tourPrice + packagePrice) * persons;

    const payload = {
        travel_id: tourId,
        name: name,
        phone: phone,
        email: email,
        package_id: packageId,
        user_id: userId,
        persons: persons,
        price: totalPrice // Передаем общую цену
    };

    fetch('/book_tour.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(response => {
        if (!response.ok) {
            return response.text().then(text => {
                throw new Error(`HTTP error! Status: ${response.status}, Response: ${text}`);
            });
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            bookingForm.classList.remove('show');
            successMessage.classList.add('show');
            successText.textContent = `Тур успешно забронирован для ${persons} человек по цене ${Number(totalPrice).toLocaleString('ru-RU')} руб.! Мы свяжемся с вами для подтверждения.`;
            modalButton.style.display = 'none';
        } else {
            alert(data.message || 'Ошибка бронирования. Попробуйте снова.');
        }
    })
    .catch(error => {
        alert('Произошла ошибка при бронировании: ' + error.message);
    });
}

    function formatDescription(description) {
        if (!description.trim()) {
            return '<p>Описание временно недоступно.</p>';
        }

        const paragraphs = description.split(/\n\s*\n/).filter(p => p.trim());
        let formatted = '';

        paragraphs.forEach(paragraph => {
            paragraph = paragraph.trim();
            if (paragraph.match(/^(Особенности|Включено|Не включено|Важно):/i)) {
                const [title, ...items] = paragraph.split('\n');
                formatted += `<h3>${escapeHtml(title)}</h3>`;
                if (items.length > 0) {
                    formatted += '<ul>';
                    items.forEach(item => {
                        if (item.trim()) {
                            formatted += `<li>${escapeHtml(item.trim())}</li>`;
                        }
                    });
                    formatted += '</ul>';
                }
            } else {
                formatted += `<p>${escapeHtml(paragraph)}</p>`;
            }
        });

        return formatted;
    }

    function closeModal() {
        document.body.classList.remove('modal-open');
        modal.classList.remove('show');
        bookingForm.classList.remove('show');
        successMessage.classList.remove('show');
        modalButton.style.display = 'block';
        bookingName.value = '';
        bookingPhone.value = '';
        bookingEmail.value = '';
        stopPromoTimer(timerInterval);
    }

    function updateGallery() {
        const galleryInner = document.getElementById('modalGallery');
        const images = galleryInner.querySelectorAll('img');
        if (!images.length) return;
        galleryInner.style.transform = `translateX(-${currentSlide * 100}%)`;
        const prevBtn = document.querySelector('.gallery-prev');
        const nextBtn = document.querySelector('.gallery-next');
        prevBtn.classList.toggle('gallery-disabled', currentSlide === 0);
        nextBtn.classList.toggle('gallery-disabled', currentSlide === images.length - 1);

        const indicatorsContainer = document.getElementById('galleryIndicators');
        indicatorsContainer.innerHTML = Array.from(images).map((_, index) => `
            <div class="gallery-indicator ${index === currentSlide ? 'active' : ''}" onclick="moveGalleryTo(${index})"></div>
        `).join('');
    }

    function moveGalleryTo(index) {
        currentSlide = index;
        updateGallery();
    }

    function moveGallery(direction) {
        const galleryInner = document.getElementById('modalGallery');
        const images = galleryInner.querySelectorAll('img');
        currentSlide = Math.min(Math.max(currentSlide + direction, 0), images.length - 1);
        updateGallery();
    }

    function escapeHtml(unsafe) {
        if (unsafe == null) return '';
        return unsafe
            .toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function getStatusText(status) {
        switch (status) {
            case 'active': return 'Доступен';
            case 'upcoming': return 'Скоро';
            default: return 'Недоступен';
        }
    }

    document.addEventListener('mousemove', (e) => {
        const transportIcons = document.querySelectorAll('.transport-icon-active');
        transportIcons.forEach(icon => {
            const rect = icon.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;

            icon.style.setProperty('--mouse-x', `${x}px`);
            icon.style.setProperty('--mouse-y', `${y}px`);
        });
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
            const hiddenNav = document.querySelector('.nav-links[style*="display: none"]');
            if (hiddenNav) {
                console.log('Nav-links скрыт из-за стилей:', hiddenNav);
            }
        }
    });
    document.addEventListener('DOMContentLoaded', () => {
    const phoneInput = document.getElementById('bookingPhone');
    if (phoneInput) {
        const phoneMask = IMask(phoneInput, {
            mask: '+{7} (000) 000-00-00',
            lazy: false,
            placeholderChar: '_'
        });
    }
});
</script>
</body>
</html>




 