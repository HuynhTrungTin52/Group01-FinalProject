<?php
$front_path = '';
$back_path = '';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
require_once 'db.php';

    $uploadDir = 'uploads/';
    
    $errors = [];

    if (isset($_FILES['id_front']) && $_FILES['id_front']['error'] === UPLOAD_ERR_OK) {
        // Tạo tên file độc nhất để không bị trùng (ví dụ: 1684321_front_hinhanh.jpg)
        $frontName = time() . '_front_' . basename($_FILES['id_front']['name']);
        $frontDest = $uploadDir . $frontName;
        
        if (move_uploaded_file($_FILES['id_front']['tmp_name'], $frontDest)) {
            $front_path = $frontName;
        } else {
            $errors[] = 'Lỗi lưu ảnh mặt trước.';
        }
    } else {
        $errors[] = 'Vui lòng chọn ảnh mặt trước.';
    }

    if (isset($_FILES['id_back']) && $_FILES['id_back']['error'] === UPLOAD_ERR_OK) {
        $backName = time() . '_back_' . basename($_FILES['id_back']['name']);
        $backDest = $uploadDir . $backName;
        
        if (move_uploaded_file($_FILES['id_back']['tmp_name'], $backDest)) {
            $back_path = $backName;
        } else {
            $errors[] = 'Lỗi lưu ảnh mặt sau.';
        }
    } else {
        $errors[] = 'Vui lòng chọn ảnh mặt sau.';
    }

    if (!empty($errors)) {
        echo json_encode(['success' => false, 'error' => implode(' ', $errors)]);
        exit;
    }
$pdo->prepare("UPDATE users
               SET id_front_image = ?, id_back_image = ?, status = 'pending'
               WHERE user_id = ?")
    ->execute([$front_path, $back_path, $_SESSION['user_id']]);

echo json_encode(['success' => true]);