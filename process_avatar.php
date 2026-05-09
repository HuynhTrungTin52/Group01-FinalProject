<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'you not login yet!']);
    exit;
}

$user_id = $_SESSION['user_id'];
if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
    
    $uploadDir = 'uploads/avatars/';
    
    $fileExtension = pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION);
    $newFileName = 'avatar_' . $user_id . '_' . time() . '.' . $fileExtension;
    
    $destination = $uploadDir . $newFileName;

    if (move_uploaded_file($_FILES['avatar']['tmp_name'], $destination)) {

        try {
            $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE user_id = ?");
            $stmt->execute([$newFileName, $user_id]);
            if(isset($_SESSION['user'])) {
                $_SESSION['user']['avatar'] = $newFileName;
            }
            echo json_encode([
                'success' => true, 
                'new_path' => $destination 
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'error Database: ' . $e->getMessage()]);
        }

    } else {
        echo json_encode(['success' => false, 'error' => 'error saving file! Please make sure you have created the uploads/avatars/ directory']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Please select a valid image!']);
}
?>