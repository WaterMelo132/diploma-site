<?php
session_start();
header('Content-Type: application/json');

// Проверка CSRF токена
if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || 
    empty($_POST['csrf_token']) || 
    $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF token validation failed']);
    exit;
}

require_once($_SERVER['DOCUMENT_ROOT'].'/travel/php/config.php');

// Получаем данные из POST (теперь они приходят как JSON)
$input = json_decode(file_get_contents('php://input'), true);
$booking_id = isset($input['booking_id']) ? (int)$input['booking_id'] : 0;
$user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

if (!$booking_id || !$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

try {
    // Проверяем, существует ли бронь и принадлежит ли пользователю
    $stmt = $conn->prepare("SELECT id FROM tour_bookings WHERE id = ? AND user_id = ? AND status != 'cancelled'");
    $stmt->bind_param("ii", $booking_id, $user_id);
    $stmt->execute();
    $stmt->store_result();
    
    if ($stmt->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found or already cancelled']);
        exit;
    }
    
    // Обновляем статус брони
    $stmt = $conn->prepare("UPDATE tour_bookings SET status = 'cancelled' WHERE id = ?");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    
    if ($stmt->affected_rows === 0) {
        throw new Exception("Failed to update booking");
    }
    
    echo json_encode(['success' => true, 'message' => 'Booking cancelled successfully']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}