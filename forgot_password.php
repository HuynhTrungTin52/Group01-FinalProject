<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
session_start();
require_once 'db.php';

$step = isset($_GET['step']) ? $_GET['step'] : 1;
$error = '';

// Step 1: Email input
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email_submit'])) {
    $email = trim($_POST['email']);
    
    // Check if email exists
    $stmt = $pdo->prepare("SELECT user_id, full_name FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Generate 6-digit OTP
        $otp = rand(100000, 999999);
        $_SESSION['reset_otp'] = $otp;
        $_SESSION['reset_email'] = $email;
        $_SESSION['otp_generated_at'] = time();
        
         // Nạp các file lõi PHPMailer
            require 'PHPMailer/src/Exception.php';
            require 'PHPMailer/src/PHPMailer.php';
            require 'PHPMailer/src/SMTP.php';

            $mail = new PHPMailer(true);

            try {
            // config GMAIL
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'justintraneg@gmail.com'; //your mail
                $mail->Password   = 'whel tikj tqkn hkme'; //apppassword
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;

            // receiver and sender
                $mail->setFrom('justintraneg@gmail.com', 'Justin E-Wallet');
                $mail->addAddress($email);                      //email user

            //email content
                $mail->isHTML(true);
                $mail->Subject = 'OTP Verification - E-Wallet';
                $mail->Body    = "
                    <div style='font-family: Arial, sans-serif; padding: 20px; background: #f4f4f4;'>
                        <div style='max-width: 500px; margin: auto; background: white; padding: 30px; border-radius: 10px; text-align: center;'>
                            <h2 style='color: #333;'>Reset Password</h2>
                            <p style='color: #666;'>Your OTP:</p>
                            <h1 style='color: #6f42c1; letter-spacing: 5px; border: 2px dashed #6f42c1; padding: 10px; display: inline-block;'>$otp</h1>
                            <p style='color: #999; font-size: 12px;'>This code will expire in 1 minute. Please do not share it with others.</p>
                        </div>
                    </div>
                ";

    // send the email
    $mail->send();
    
    // otp sent, redirect to OTP verification step
    header('Location: forgot_password.php?step=2');
    exit;

} catch (Exception $e) {
    // find a way to log this error in production instead of displaying it
    $error = "Hệ thống bận, không thể gửi mail lúc này. Chi tiết lỗi: {$mail->ErrorInfo}";
}
    }
}

// OTP verification
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp_submit'])) {
    $otp1 = $_POST['otp1'];
    $otp2 = $_POST['otp2'];
    $otp3 = $_POST['otp3'];
    $otp4 = $_POST['otp4'];
    $otp5 = $_POST['otp5'];
    $otp6 = $_POST['otp6'];
    $enteredOtp = $otp1 . $otp2 . $otp3 . $otp4 . $otp5 . $otp6;
    
    // Check OTP expiration (1 minute)
    if (!isset($_SESSION['otp_generated_at']) || (time() - $_SESSION['otp_generated_at']) > 60) {
        $error = 'OTP has expired. Please request a new one.';
    } elseif ($enteredOtp == $_SESSION['reset_otp']) {
        header('Location: forgot_password.php?step=3');
        exit;
    } else {
        $error = 'Invalid OTP. Please try again.';
    }
}

// Reset password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_submit'])) {
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    if (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters';
    } elseif (!preg_match('/[0-9]/', $newPassword) || !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
        $error = 'Password must contain at least one number and one special character';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
        
        if ($stmt->execute([$hashedPassword, $_SESSION['reset_email']])) {
            // Clear session
            unset($_SESSION['reset_otp']);
            unset($_SESSION['reset_email']);
            unset($_SESSION['otp_generated_at']);
            
            header('Location: forgot_password.php?step=4');
            exit;
        } else {
            $error = 'Failed to update password';
        }
    }
}

// Resend OTP
if (isset($_GET['resend']) && $_GET['resend'] === '1' && isset($_SESSION['reset_email'])) {
    $otp = rand(100000, 999999);
    $_SESSION['reset_otp'] = $otp;
    $_SESSION['otp_generated_at'] = time();
    
    $subject = "Password Reset OTP";
    $message = "Hello,\n\nYour new OTP for password reset is: $otp\n\nThis code will expire in 1 minute.\n\nThank you!";
    $headers = "From: noreply@ewallet.com";
    
    mail($_SESSION['reset_email'], $subject, $message, $headers);
    
    header('Location: forgot_password.php?step=2');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - E-Wallet</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #E6E6FA 0%, #D4C5F9 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .forgot-container {
            max-width: 450px;
            width: 100%;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .page-title {
            font-size: 2rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }
        .page-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px;
            border: 1px solid #ddd;
            margin-bottom: 15px;
        }
        .input-group-icon {
            position: relative;
        }
        .input-group-icon i {
            position: absolute;
            left: 15px;
            top: 15px;
            color: #666;
        }
        .btn-primary {
            background: #7E6FB0;
            border: none;
            border-radius: 25px;
            padding: 12px;
            font-weight: 600;
            width: 100%;
            margin-top: 10px;
        }
        .btn-primary:hover {
            background: #6C5FA7;
        }
        .error-message {
            color: #dc3545;
            text-align: center;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        .otp-inputs {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 30px 0;
        }
        .otp-input {
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 1.5rem;
            border: 2px solid #ddd;
            border-radius: 10px;
        }
        .resend-link {
            text-align: center;
            margin-top: 15px;
            font-size: 0.9rem;
            color: #666;
        }
        .resend-link a {
            color: #7E6FB0;
            text-decoration: none;
            font-weight: 600;
        }
        .validation-rules {
            font-size: 0.85rem;
            color: #666;
            margin-top: 20px;
            padding-left: 20px;
        }
        .validation-rules li {
            margin-bottom: 5px;
        }
        .success-box {
            background: #f8f9fa;
            padding: 30px;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 20px;
        }
        .success-box h4 {
            color: #7E6FB0;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <div class="forgot-container">
        <?php if ($step == 1): ?>
            <h1 class="page-title">Forget Password</h1>
            <p class="page-subtitle">Select which methods you'd like to reset</p>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="forgot_password.php">
                <label for="email" style="font-weight: 600; margin-bottom: 10px;">Email Address</label>
                <input type="email" class="form-control" name="email" placeholder="Enter your email" required>
                <button type="submit" name="email_submit" class="btn btn-primary">CONTINUE</button>
            </form>
            
        <?php elseif ($step == 2): ?>
            <h1 class="page-title">Enter OTP</h1>
            <p class="page-subtitle">A magic code to sign in was sent to<br><?php echo htmlspecialchars($_SESSION['reset_email'] ?? ''); ?></p>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="forgot_password.php?step=2" id="otpForm">
                <div class="otp-inputs">
                    <input type="text" class="otp-input" name="otp1" maxlength="1" required>
                    <input type="text" class="otp-input" name="otp2" maxlength="1" required>
                    <input type="text" class="otp-input" name="otp3" maxlength="1" required>
                    <input type="text" class="otp-input" name="otp4" maxlength="1" required>
                    <input type="text" class="otp-input" name="otp5" maxlength="1" required>
                    <input type="text" class="otp-input" name="otp6" maxlength="1" required>
                </div>
                <button type="submit" name="otp_submit" class="btn btn-primary">CONTINUE</button>
            </form>
            
            <div class="resend-link">
                Didn't receive the code? <a href="forgot_password.php?resend=1&step=2">Request a new one</a><br>
                <div id="timerText">Resend OTP in <span id="countdown">1:00</span></div>
            </div>
            
            <script>
                // Auto-focus next input
                const inputs = document.querySelectorAll('.otp-input');
                inputs.forEach((input, index) => {
                    input.addEventListener('input', function() {
                        if (this.value.length === 1 && index < inputs.length - 1) {
                            inputs[index + 1].focus();
                        }
                    });
                    input.addEventListener('keydown', function(e) {
                        if (e.key === 'Backspace' && this.value === '' && index > 0) {
                            inputs[index - 1].focus();
                        }
                    });
                });
            </script>
            
        <?php elseif ($step == 3): ?>
            <h1 class="page-title">Reset Password</h1>
            <p class="page-subtitle">Create a new password for your account</p>
            
            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="forgot_password.php?step=3">
                <label for="new_password" style="font-weight: 600; margin-bottom: 10px;">New password</label>
                <div class="input-group-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" class="form-control" name="new_password" placeholder="Enter new password" required style="padding-left: 45px;">
                </div>
                
                <label for="confirm_password" style="font-weight: 600; margin-bottom: 10px;">Confirm password</label>
                <div class="input-group-icon">
                    <i class="fas fa-lock"></i>
                    <input type="password" class="form-control" name="confirm_password" placeholder="Confirm password" required style="padding-left: 45px;">
                </div>
                
                <ul class="validation-rules">
                    <li>1. Must be at least 8 characters</li>
                    <li>2. Must contain one number and one special character</li>
                </ul>
                
                <button type="submit" name="reset_submit" class="btn btn-primary">CONTINUE</button>
            </form>
            
        <?php elseif ($step == 4): ?>
            <div class="success-box">
                <h4>Thanks! your password has been successfully updated.</h4>
                <p>You've successfully created a new password</p>
                <p style="font-size: 0.9rem; color: #666;">Click below to login</p>
            </div>
            <button type="button" class="btn btn-primary" onclick="window.location.href='login.php'">Back to log in</button>
        <?php endif; ?>
    </div>
    <script>
    let timeLeft = 60; // 60 seconds countdown
    const countdownEl = document.getElementById('countdown');
    const resendLink = document.getElementById('resendLink');
    const timerText = document.getElementById('timerText');

    const timerId = setInterval(() => {
        timeLeft--;
        // calculate minutes and seconds
        let seconds = timeLeft < 10 ? '0' + timeLeft : timeLeft;
        countdownEl.innerText = '0:' + seconds;

        // wwhen time is up, disable timer and enable resend link
        if (timeLeft <= 0) {
            clearInterval(timerId); // stop timer
            timerText.style.display = 'none'; // hide timer text
            
            // unlock resend link
            resendLink.style.pointerEvents = 'auto'; 
            resendLink.style.color = '#6f42c1'; // 
            resendLink.style.fontWeight = 'bold';
        }
    }, 1000); // 1000ms = 1 second
</script>
</body>
</html>