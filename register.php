<?php
session_start();
require_once 'db.php';

// Handle AJAX requests for saving camera captures
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] === 'save_id_image') {
        $imageData = $_POST['image_data'];
        $imageType = $_POST['image_type']; // 'front' or 'back'
        
        // Remove data URL prefix
        $imageData = str_replace('data:image/png;base64,', '', $imageData);
        $imageData = str_replace(' ', '+', $imageData);
        $data = base64_decode($imageData);
        
        // Generate unique filename
        $filename = 'id_' . $imageType . '_' . uniqid() . '.png';
        $filepath = 'uploads/' . $filename;
        
        // Create uploads directory if not exists
        if (!file_exists('uploads')) {
            mkdir('uploads', 0777, true);
        }
        
        // Save image
        if (file_put_contents($filepath, $data)) {
            echo json_encode(['success' => true, 'filename' => $filename]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save image']);
        }
        exit;
    }
    
    if ($_POST['ajax_action'] === 'register_user') {
        $phone = trim($_POST['phone']);
        $email = trim($_POST['email']);
        $fullName = trim($_POST['full_name']);
        $dob = $_POST['dob'];
        $address = trim($_POST['address']);
        $idFront = $_POST['id_front'];
        $idBack = $_POST['id_back'];
        
        // Validate unique email/phone
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE email = ? OR phone = ?");
        $stmt->execute([$email, $phone]);
        if ($stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Email or phone already exists']);
            exit;
        }
        
        // Generate random 6-character password
        $randomPassword = substr(str_shuffle('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789'), 0, 6);
        $hashedPassword = password_hash($randomPassword, PASSWORD_DEFAULT);
        
        // Insert user
        $stmt = $pdo->prepare("INSERT INTO users (phone, email, full_name, dob, address, id_front_image, id_back_image, password, is_first_login, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 'pending')");
        

    if ($stmt->execute([$phone, $email, $fullName, $dob, $address, $idFront, $idBack, $hashedPassword])) {
        
        $mailSent = false;

        // mailer
        require_once 'PHPMailer/src/Exception.php';
        require_once 'PHPMailer/src/PHPMailer.php';
        require_once 'PHPMailer/src/SMTP.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // config maain mail
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'justintraneg@gmail.com';     // your mail
            $mail->Password   = 'whel tikj tqkn hkme';            // app password (not your regular email password)
            $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port       = 465;

            // info email + recipient
            $mail->setFrom('justintraneg@gmail.com', 'Justin E-Wallet');
            $mail->addAddress($email, $fullName); // email user

            // email content
            $mail->isHTML(true);
            $mail->Subject = 'Your Temporary Password - Justin E-Wallet';
            $mail->Body    = "
                <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
                    <h2 style='color: #6f42c1;'>Welcome to Justin E-Wallet!</h2>
                    <p>Hi <b>$fullName</b>,</p>
                    <p>Your account has been successfully registered. Please use the temporary password below to login:</p>
                    <h1 style='background-color: #f3f0ff; padding: 15px; border: 1px dashed #6f42c1; display: inline-block; letter-spacing: 2px;'>$randomPassword</h1>
                    <p style='color: #d9534f; font-weight: bold;'>For your security, please change this password immediately after your first login.</p>
                </div>
            ";

            $mail->send();
            $mailSent = true;

        } catch (\PHPMailer\PHPMailer\Exception $e) {
        }

        // give response
        echo json_encode([
            'success' => true, 
            'message' => 'Registration successful! Please check your email for the temporary password.'
        ]);
        exit;

    } else {
        echo json_encode(['success' => false, 'error' => 'Registration failed, please try again.']);
        exit;
    }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - E-Wallet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #E6E6FA 0%, #D4C5F9 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .register-container {
            max-width: 500px;
            margin: 30px auto;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .step-title {
            font-size: 2rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
            color: #333;
        }
        .step-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            margin-bottom: 15px;
        }
        .btn-primary {
            background: #7E6FB0;
            border: none;
            border-radius: 25px;
            padding: 12px 40px;
            font-weight: 600;
            width: 100%;
            margin-top: 10px;
        }
        .btn-primary:hover {
            background: #6C5FA7;
        }
        .camera-container {
            text-align: center;
        }
        .camera-preview {
            width: 100%;
            max-width: 400px;
            border-radius: 15px;
            margin: 20px auto;
            display: block;
        }
        .character-image {
            width: 100%;
            max-width: 300px;
            margin: 20px auto;
            display: block;
        }
        .step-hidden {
            display: none;
        }
        .success-popup {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 50px rgba(0,0,0,0.3);
            z-index: 1000;
            max-width: 400px;
            text-align: center;
        }
        .success-popup h3 {
            color: #7E6FB0;
            margin-bottom: 20px;
        }
        .password-display {
            font-size: 1.5rem;
            font-weight: bold;
            color: #7E6FB0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin: 20px 0;
        }
    </style>
</head>
<body>
    <div class="register-container" id="step1">
        <h1 class="step-title">SIGN UP</h1>
        <p class="step-subtitle">Create your account</p>
        <form id="registerForm">
            <input type="tel" class="form-control" id="phone" placeholder="Phone Number" required>
            <input type="email" class="form-control" id="email" placeholder="Email" required>
            <input type="text" class="form-control" id="full_name" placeholder="Full Name" required>
            <input type="date" class="form-control" id="dob" placeholder="Date of Birth" required>
            <textarea class="form-control" id="address" placeholder="Address" rows="3" required></textarea>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" id="terms" required>
                <label class="form-check-label" for="terms">
                    I agree to Terms & Conditions
                </label>
            </div>
            <button type="button" class="btn btn-primary" onclick="proceedToIdCapture()">Register</button>
            <div class="text-center mt-3" style="font-size: 14px;">
                <span style="color: #6c757d;">You have an account? </span>
                    <a href="login.php" style="color: #7d6b9d; font-weight: bold; text-decoration: none;">Login</a>
            </div>
        </form>
    </div>

    <div class="register-container step-hidden" id="step2">
        <h1 class="step-title">Front CCCD</h1>
        <p class="step-subtitle">Take a Photo - ID Front</p>
        <div class="camera-container">
            <video id="videoFront" class="camera-preview" autoplay></video>
            <canvas id="canvasFront" class="camera-preview" style="display:none;"></canvas>
            <button type="button" class="btn btn-primary" onclick="captureImage('front')">Capture</button>
            <button type="button" class="btn btn-primary" id="btnFrontContinue" style="display:none;" onclick="proceedToBackId()">CONTINUE</button>
        </div>
    </div>

    <div class="register-container step-hidden" id="step3">
        <h1 class="step-title">Back CCCD</h1>
        <p class="step-subtitle">Take a Photo - ID Back</p>
        <div class="camera-container">
            <video id="videoBack" class="camera-preview" autoplay></video>
            <canvas id="canvasBack" class="camera-preview" style="display:none;"></canvas>
            <button type="button" class="btn btn-primary" onclick="captureImage('back')">Capture</button>
            <button type="button" class="btn btn-primary" id="btnBackContinue" style="display:none;" onclick="submitRegistration()">CONTINUE</button>
        </div>
    </div>

    <div class="success-popup step-hidden" id="successPopup">
        <h3>Thanks! Your account has been successfully created.</h3>
        <p>Please check your inbox, a code is sent on your email as well as on your registered phone number.</p>
        <div class="password-display" id="displayPassword"></div>
        <p style="font-size: 0.9rem; color: #666;">Please save the password and login to your account.</p>
        <button type="button" class="btn btn-primary" onclick="window.location.href='login.php'">CONTINUE</button>
    </div>

    <script>
        let formData = {};
        let idFrontFilename = '';
        let idBackFilename = '';
        let streamFront = null;
        let streamBack = null;

        function proceedToIdCapture() {
            // Validate form
            if (!document.getElementById('registerForm').checkValidity()) {
                document.getElementById('registerForm').reportValidity();
                return;
            }

            // Store form data
            formData = {
                phone: document.getElementById('phone').value,
                email: document.getElementById('email').value,
                full_name: document.getElementById('full_name').value,
                dob: document.getElementById('dob').value,
                address: document.getElementById('address').value
            };

            // Show front ID capture
            document.getElementById('step1').classList.add('step-hidden');
            document.getElementById('step2').classList.remove('step-hidden');
            startCamera('front');
        }

        function startCamera(type) {
            const video = type === 'front' ? document.getElementById('videoFront') : document.getElementById('videoBack');
            
            navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } })
                .then(function(stream) {
                    if (type === 'front') {
                        streamFront = stream;
                    } else {
                        streamBack = stream;
                    }
                    video.srcObject = stream;
                })
                .catch(function(err) {
                    alert('Camera access denied: ' + err.message);
                });
        }

        function captureImage(type) {
            const video = type === 'front' ? document.getElementById('videoFront') : document.getElementById('videoBack');
            const canvas = type === 'front' ? document.getElementById('canvasFront') : document.getElementById('canvasBack');
            const context = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0);
            
            const imageData = canvas.toDataURL('image/png');
            
            // Stop camera
            const stream = type === 'front' ? streamFront : streamBack;
            stream.getTracks().forEach(track => track.stop());
            
            // Show canvas, hide video
            video.style.display = 'none';
            canvas.style.display = 'block';
            
            // Show continue button
            if (type === 'front') {
                document.getElementById('btnFrontContinue').style.display = 'block';
            } else {
                document.getElementById('btnBackContinue').style.display = 'block';
            }
            
            // Save image to server
            fetch('register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'ajax_action=save_id_image&image_data=' + encodeURIComponent(imageData) + '&image_type=' + type
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    if (type === 'front') {
                        idFrontFilename = data.filename;
                    } else {
                        idBackFilename = data.filename;
                    }
                } else {
                    alert('Failed to save image: ' + data.error);
                }
            });
        }

        function proceedToBackId() {
            document.getElementById('step2').classList.add('step-hidden');
            document.getElementById('step3').classList.remove('step-hidden');
            startCamera('back');
        }

        function submitRegistration() {
            // Submit complete registration
            const btn = document.getElementById('btnBackContinue');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Processing...';
            const registrationData = {
                ...formData,
                id_front: idFrontFilename,
                id_back: idBackFilename,
                ajax_action: 'register_user'
            };

            fetch('register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams(registrationData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('step3').classList.add('step-hidden');
                    document.getElementById('displayPassword').textContent = data.password;
                    document.getElementById('successPopup').classList.remove('step-hidden');
                } else {
                    alert('Registration failed: ' + data.error);
                }
            });
        }
    </script>
</body>
</html>