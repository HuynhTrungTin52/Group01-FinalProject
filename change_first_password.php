<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'];
    $confirmPassword = $_POST['confirm_password'];
    
    // Validation
    if (strlen($newPassword) < 8) {
        $error = 'Password must be at least 8 characters';
    } elseif (!preg_match('/[0-9]/', $newPassword) || !preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
        $error = 'Password must contain at least one number and one special character';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'Passwords do not match';
    } else {
        // Update password
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ?, is_first_login = 0 WHERE user_id = ?");
        
        if ($stmt->execute([$hashedPassword, $_SESSION['user_id']])) {
            $success = true;
        } else {
            $error = 'Failed to update password';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - E-Wallet</title>
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
        .password-container {
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
        .error-message {
            color: #dc3545;
            text-align: center;
            margin-bottom: 15px;
            font-size: 0.9rem;
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
        
    </style>
</head>
<body>
    <?php if ($success): ?>
    <div class="success-popup">
        <h3>Thanks! Your password has been successfully updated.</h3>
        <p>You've successfully created a new password</p>
        <p style="font-size: 0.9rem; color: #666;">Click below to login</p>
        <button type="button" class="btn btn-primary" onclick="window.location.href='dashboard_user.php'">Go to Dashboard</button>
    </div>
    <?php else: ?>
    <div class="password-container">
        <h1 class="page-title">Reset Password</h1>
        <p class="page-subtitle">Please create a new password for your account</p>
        
        <?php if ($error): ?>
            <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <style>
    :root{
    --bg-page:#E8F4F8;
    --primary:#9B95D6;
    --primary-hover:#857FCB;
    --primary-dark:#3D3D8C;
    --info-bg:#DCDFF7;
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#E5E7EB;
    --danger:#DC2626;
    --success:#16A34A;
}
*{font-family:'Mulish',sans-serif;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:28px 18px 40px;}
.page-title{font-weight:900;font-size:32px;letter-spacing:-1px;margin-bottom:2px;}
.page-sub{color:var(--text-muted);font-size:13px;margin-bottom:20px;}
.form-card{background:#fff;border-radius:22px;padding:22px 20px;box-shadow:0 4px 20px rgba(0,0,0,.05);}
.form-label{font-weight:600;font-size:13px;color:#1F1F2E;margin-bottom:6px;}
.input-wrap{position:relative;margin-bottom:14px;}
.input-wrap .form-control{border:1.5px solid var(--border);border-radius:12px;padding:12px 44px 12px 44px;font-size:14px;font-weight:500;height:48px;}
.input-wrap .form-control:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(155,149,214,.2);}
.input-wrap .icon-left{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:16px;pointer-events:none;}
.input-wrap .eye-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:16px;cursor:pointer;background:transparent;border:none;padding:0;}
.req-box{background:var(--info-bg);border-radius:12px;padding:14px 16px;color:var(--primary-dark);font-size:12.5px;margin-top:8px;}
.req-box .rb-title{display:flex;align-items:center;gap:6px;font-weight:700;margin-bottom:6px;font-size:13px;}
.req-box ol{padding-left:18px;margin:0;}
.req-box li{margin-bottom:2px;}
.req-box li.met{color:var(--success);}
.btn-row{display:flex;gap:10px;margin-top:18px;}
.btn-row .btn{flex:1;height:48px;border-radius:14px;font-weight:700;font-size:15px;}
.btn-cancel{background:#fff;border:1.5px solid var(--border);color:#1F1F2E;}
.btn-primary-c{background:var(--primary);color:#fff;border:none;}
.btn-primary-c:hover{background:var(--primary-hover);color:#fff;}
.alert-list{margin-bottom:14px;border-radius:12px;font-size:13px;}
</style>
        <form method="POST" action="change_first_password.php">
           <div class="input-wrap">
    <i class="fas fa-lock icon-left"></i>
    <input type="password" class="form-control" name="new_password" id="newPw" placeholder="New password" required>
    <button type="button" class="eye-toggle" data-target="newPw"><i class="fas fa-eye-slash"></i></button>
</div>

<div class="input-wrap">
    <i class="fas fa-lock icon-left"></i>
    <input type="password" class="form-control" name="confirm_password" id="confirmPw" placeholder="Confirm password" required>
    <button type="button" class="eye-toggle" data-target="confirmPw"><i class="fas fa-eye-slash"></i></button>
</div>
            
            <div class="req-box" data-testid="password-requirements">
            <div class="rb-title"><i class="bi bi-clipboard-check"></i> Password requirement:</div>
            <ol>
                <li id="req-length">At least 6 characters</li>
                <li id="req-mix">Should include uppercase, lowercase, numbers and special characters</li>
                <li id="req-weak">Do not use easy-to-guess passwords</li>
            </ol>
        </div>
            
            <button type="submit" class="btn btn-primary">CONTINUE</button>
        </form>
    </div>
    <?php endif; ?>
    <script>
// show password eye Font Awesome
document.querySelectorAll('.eye-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.dataset.target);
        const ic = btn.querySelector('i');
        
        if (input.type === 'password') { 
            input.type = 'text'; 
            ic.className = 'fas fa-eye'; //show
        } else { 
            input.type = 'password'; 
            ic.className = 'fas fa-eye-slash'; // hide
        }
    });
});

// check password requirements
const newPw = document.getElementById('newPw');
const weak = ['password','12345678','qwerty','abc123','admin123','letmein','111111','123456789'];

if (newPw) {
    newPw.addEventListener('input', () => {
        const v = newPw.value;
        const hasLen = v.length >= 6;
        const hasMix = /[A-Z]/.test(v) && /[a-z]/.test(v) && /\d/.test(v) && /[^A-Za-z0-9]/.test(v);
        const notWeak = v.length > 0 && !weak.includes(v.toLowerCase());
        
        document.getElementById('req-length').classList.toggle('met', hasLen);
        document.getElementById('req-mix').classList.toggle('met', hasMix);
        document.getElementById('req-weak').classList.toggle('met', notWeak);
    });
}
</script>
</body>
</html>