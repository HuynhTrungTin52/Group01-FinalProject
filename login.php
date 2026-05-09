<?php
session_start();
require_once 'db.php';

$error = '';
$lockMessage = '';
$countdown = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identifier = trim($_POST['identifier']); // email or phone
    $password = trim($_POST['password']);
    
    // Find user by email or phone
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? OR phone = ?");
    $stmt->execute([$identifier, $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user) {
        // Check if permanently locked
        if ($user['is_permanently_locked']) {
            header('Location: login.php?error=permanent_lock');
            exit;
        }
        
        // Check temporary lock
        if ($user['temp_lock_until'] && strtotime($user['temp_lock_until']) > time()) {
            $remaining = strtotime($user['temp_lock_until']) - time();
            header('Location: login.php?error=temp_lock&countdown=' . $remaining);
            exit;
        }
        
        // Verify password
        if ($user && (password_verify($password, $user['password']) || $password === $user['password'])){
            // Reset failed attempts
            $stmt = $pdo->prepare("UPDATE users SET failed_attempts = 0, temp_lock_until = NULL WHERE user_id = ?");
            $stmt->execute([$user['user_id']]);
            
            // Set session
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['email'] = $user['email'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role']; 

            if ($user['is_first_login']) {
                header('Location: change_first_password.php');
            } else {

                if ($user['role'] === 'admin') {
                    header('Location: admin_dashboard.php'); 
                } else {
                    header('Location: dashboard_user.php');
                }
            }
            exit;
        } else {
            // Increment failed attempts
            $failedAttempts = $user['failed_attempts'] + 1;
            
            if ($failedAttempts >= 6) {
                // Permanent lock
                $stmt = $pdo->prepare("UPDATE users SET failed_attempts = ?, is_permanently_locked = 1 WHERE user_id = ?");
                $stmt->execute([$failedAttempts, $user['user_id']]);
                header('Location: login.php?error=permanent_lock');
                exit;
            } elseif ($failedAttempts >= 3) {
                // Temporary lock for 1 minute
                $lockUntil = date('Y-m-d H:i:s', time() + 60);
                $stmt = $pdo->prepare("UPDATE users SET failed_attempts = ?, temp_lock_until = ? WHERE user_id = ?");
                $stmt->execute([$failedAttempts, $lockUntil, $user['user_id']]);
                header('Location: login.php?error=temp_lock&countdown=60');
                exit;
            } else {
                // Just increment
                $stmt = $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE user_id = ?");
                $stmt->execute([$failedAttempts, $user['user_id']]);
                $error = 'You have entered the wrong password. Attempts: ' . $failedAttempts . '/6';
            }
        }
    } else {
        $error = 'Invalid email/phone or password';
    }
}

// Handle error messages from redirects
if (isset($_GET['error'])) {
    if ($_GET['error'] === 'permanent_lock') {
        $lockMessage = 'permanent';
    } elseif ($_GET['error'] === 'temp_lock') {
        $lockMessage = 'temporary';
        $countdown = isset($_GET['countdown']) ? intval($_GET['countdown']) : 60;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - E-Wallet</title>
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
        .login-container {
            max-width: 450px;
            width: 100%;
            padding: 40px;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .login-title {
            font-size: 2.5rem;
            font-weight: bold;
            text-align: center;
            margin-bottom: 10px;
        }
        .login-subtitle {
            text-align: center;
            color: #666;
            margin-bottom: 30px;
            font-size: 0.95rem;
        }
        .form-control {
            border-radius: 10px;
            padding: 12px 15px 12px 45px;
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
        .form-check {
            margin-bottom: 15px;
        }
        .forgot-link {
            float: right;
            color: #7E6FB0;
            text-decoration: none;
        }
        .signup-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        .signup-link a {
            color: #7E6FB0;
            text-decoration: none;
            font-weight: 600;
        }
        .error-message {
            color: #dc3545;
            text-align: center;
            margin-bottom: 15px;
            font-size: 0.9rem;
        }
        .lock-container {
            text-align: center;
        }
        .lock-icon {
            width: 150px;
            height: 150px;
            background: #7E6FB0;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 30px auto;
        }
        .lock-icon i {
            font-size: 80px;
            color: white;
        }
        .countdown {
            font-size: 1.5rem;
            font-weight: bold;
            color: #7E6FB0;
        }
    </style>
</head>
<body>
    <?php if ($lockMessage === 'permanent'): ?>
    <div class="login-container lock-container">
        <h1 class="login-title">Account Locked</h1>
        <p class="login-subtitle">Your account is permanently locked</p>
        <div class="lock-icon">
            <i class="fas fa-exclamation"></i>
        </div>
        <p style="text-align: center; margin-bottom: 20px;">Please contact support for assistance.</p>
        <button type="button" class="btn btn-primary" onclick="window.location.href='login.php'">Contact Support</button>
    </div>
    <?php elseif ($lockMessage === 'temporary'): ?>
    <div class="login-container lock-container">
        <h1 class="login-title">Account Locked</h1>
        <p class="login-subtitle">Your account is temporarily locked due to multiple failed log in attempts.</p>
        <div class="lock-icon">
            <i class="fas fa-exclamation"></i>
        </div>
        <p style="text-align: center;">Please try again after 60 seconds.</p>
        <p style="text-align: center;">Try again time in: <span class="countdown" id="countdown"><?php echo $countdown; ?></span></p>
        <button type="button" class="btn btn-primary" id="backToLoginBtn" disabled>Back to log in</button>
    </div>
    <script>
        let countdown = <?php echo $countdown; ?>;
        const countdownEl = document.getElementById('countdown');
        const backBtn = document.getElementById('backToLoginBtn');
        
        const timer = setInterval(() => {
            countdown--;
            countdownEl.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                backBtn.disabled = false;
                backBtn.onclick = function() {
                    window.location.href = 'login.php';
                };
            }
        }, 1000);
    </script>
    <?php else: ?>
    <div class="login-container">
        <h1 class="login-title">LOG IN</h1>
        <p class="login-subtitle">Enter your email and password to securely access your account and manage your services.</p>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="login.php">
            <div class="input-group-icon">
                <i class="fas fa-envelope"></i>
                <input type="text" class="form-control" name="identifier" placeholder="Phone number/ Email Address" required>
            </div>
            
            <div class="input-group-icon">
                <i class="fas fa-lock"></i>
                <input type="password" class="form-control" name="password" placeholder="Password" required>
            </div>
            
            <div class="d-flex justify-content-between align-items-center">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label" for="remember">
                        Remember me
                    </label>
                </div>
                <a href="forgot_password.php" class="forgot-link">Forgot Password</a>
            </div>
            
            <button type="submit" class="btn btn-primary">LOG IN</button>
        </form>
        
        <div class="signup-link">
            Don't have an account? <a href="register.php">Sign Up here</a>
        </div>
    </div>
    <?php endif; ?>
</body>
</html>