<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once 'db.php';
$user_id = $_SESSION['user_id'];

// === PART 1: Check current verification status ===
$stmt = $pdo->prepare("SELECT status, id_status FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

// Prefer id_status, fallback to status
$current_state = strtolower(trim($row['id_status'] ?? $row['status'] ?? 'unverified'));

// Normalise state into 3 buckets: 'pending' | 'verified' | 'editable'
if (in_array($current_state, ['pending', 'verifying', 'submitted'], true)) {
    $view_mode = 'pending';
} elseif (in_array($current_state, ['verified', 'completed', 'active'], true)) {
    $view_mode = 'verified';
} else {
    // 'unverified' | 'waiting for updates' | anything else
    $view_mode = 'editable';
}
$user_id = $_SESSION['user_id'];
$errors  = [];
$success = false;

$stmt = $pdo->prepare("SELECT id, name, id_front_image, id_back_image, id_status FROM users WHERE id = ?");
try { $stmt->execute([$user_id]); $user = $stmt->fetch(PDO::FETCH_ASSOC); }
catch (Exception $e) {
    $stmt2 = $pdo->prepare("SELECT user_id, full_name FROM users WHERE user_id = ?");
    $stmt2->execute([$user_id]);
    $user = $stmt2->fetch(PDO::FETCH_ASSOC);
    $user['id_front_image'] = null; $user['id_back_image'] = null; $user['id_status'] = 'unverified';
}
if (!$user) { session_destroy(); header('Location: login.php'); exit; }

$upload_dir = __DIR__ . '/uploads/ids';
$public_dir = 'uploads/ids';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0775, true);

function save_upload($file, $user_id, $side, $upload_dir, $public_dir) {
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return [null, 'No file uploaded for ' . $side];
    if ($file['size'] > 5 * 1024 * 1024) return [null, ucfirst($side) . ' image exceeds 5MB.'];

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) return [null, ucfirst($side) . ' must be JPG, PNG, or WEBP.'];

    $ext   = $allowed[$mime];
    $fname = 'id_' . $user_id . '_' . $side . '_' . time() . '.' . $ext;
    $dest  = $upload_dir . '/' . $fname;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return [null, 'Failed to save ' . $side . ' image.'];
    return [$public_dir . '/' . $fname, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $side_posted = $_POST['side'] ?? 'both';
    $front_path = $user['id_front_image'] ?? null;
    $back_path  = $user['id_back_image']  ?? null;

    if ($side_posted === 'front' || $side_posted === 'both') {
        if (!empty($_FILES['id_front_image']['name'])) {
            [$p, $e] = save_upload($_FILES['id_front_image'], $user_id, 'front', $upload_dir, $public_dir);
            if ($e) $errors[] = $e; else $front_path = $p;
        } elseif ($side_posted === 'front') {
            $errors[] = 'Please capture or select the Front ID image.';
        }
    }
    if ($side_posted === 'back' || $side_posted === 'both') {
        if (!empty($_FILES['id_back_image']['name'])) {
            [$p, $e] = save_upload($_FILES['id_back_image'], $user_id, 'back', $upload_dir, $public_dir);
            if ($e) $errors[] = $e; else $back_path = $p;
        } elseif ($side_posted === 'back') {
            $errors[] = 'Please capture or select the Back ID image.';
        }
    }

    if ($side_posted === 'both') {
        if (!$front_path) $errors[] = 'Front ID image is required.';
        if (!$back_path)  $errors[] = 'Back ID image is required.';
    }

    if (empty($errors)) {
        try {
            $new_status = ($front_path && $back_path) ? 'pending' : ($user['id_status'] ?? 'unverified');
            $upd = $pdo->prepare("UPDATE users SET id_front_image = ?, id_back_image = ?, id_status = ? WHERE user_id = ?");
            $upd->execute([$front_path, $back_path, $new_status, $user_id]);
            $success = true;
            $user['id_front_image']  = $front_path;
            $user['id_back_image']   = $back_path;
            $user['id_status'] = $new_status;
        } catch (Exception $e) {
            $errors[] = 'Failed to save. Please ensure the database has id_front, id_back, id_status columns.';
        }
    }
}

$front_exists = !empty($user['id_front_image']);
$back_exists  = !empty($user['id_back_image']);
$current_status = $user['id_status'] ?? 'unverified';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Update ID - E-Wallet</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Mulish:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
    --bg-page:#E8F4F8;
    --primary:#9B95D6;
    --primary-hover:#857FCB;
    --primary-dark:#3D3D8C;
    --primary-darker:#2D2D7C;
    --info-bg:#DCDFF7;
    --text-dark:#1F1F2E;
    --text-muted:#6B7280;
    --border:#E5E7EB;
    --danger:#DC2626;
    --success:#16A34A;
    --pending:#D97706;
}
*{font-family:'Mulish',sans-serif;}
html,body{background:var(--bg-page);color:var(--text-dark);margin:0;}
.app-container{max-width:430px;margin:0 auto;min-height:100vh;background:var(--bg-page);padding:24px 18px 40px;}
.page-title{font-weight:900;font-size:30px;letter-spacing:-1px;margin-bottom:2px;}
.page-sub{color:var(--text-muted);font-size:13px;margin-bottom:16px;}

.status-pill{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border-radius:999px;font-size:12px;font-weight:700;margin-bottom:14px;}
.status-pill.verified{background:#D4F5DD;color:var(--success);}
.status-pill.pending{background:#FFF1C9;color:var(--pending);}
.status-pill.unverified{background:#FEE2E2;color:var(--danger);}

.side-label{font-size:13px;font-weight:600;color:#1F1F2E;margin-bottom:8px;margin-top:10px;}
.preview-box{background:#D7DDE3;border-radius:14px;aspect-ratio:16/10;display:flex;align-items:center;justify-content:center;overflow:hidden;position:relative;margin-bottom:10px;cursor:pointer;border:2px dashed transparent;transition:all .15s ease;}
.preview-box:hover{border-color:var(--primary);}
.preview-box img{width:100%;height:100%;object-fit:cover;}
.preview-box .placeholder{text-align:center;color:#6B7280;}
.preview-box .placeholder i{font-size:36px;display:block;margin-bottom:4px;opacity:.55;}
.preview-box .placeholder .label{font-size:12px;font-weight:600;}

.update-title{text-align:center;font-weight:900;font-size:20px;margin-top:18px;margin-bottom:4px;}
.update-warn{text-align:center;color:var(--danger);font-size:12.5px;margin-bottom:14px;font-weight:600;}

.upload-row{display:flex;align-items:center;justify-content:space-between;background:#EEE8EA;border-radius:12px;padding:12px 14px;margin-bottom:10px;cursor:pointer;border:none;width:100%;text-align:left;color:var(--text-dark);transition:background .15s ease;}
.upload-row:hover{background:#E2DCDE;}
.upload-row .lr-title{font-weight:700;font-size:14px;}
.upload-row .lr-arrow{color:#1F1F2E;font-size:18px;}

.note-box{background:var(--info-bg);border-radius:12px;padding:14px 16px;color:var(--primary-dark);font-size:12.5px;margin-top:16px;}
.note-box .nb-title{display:flex;align-items:center;gap:6px;font-weight:700;margin-bottom:6px;font-size:13px;}
.note-box ol{padding-left:18px;margin:0;}

.btn-row{display:flex;gap:10px;margin-top:18px;}
.btn-row .btn{flex:1;height:48px;border-radius:14px;font-weight:700;font-size:15px;}
.btn-cancel{background:#fff;border:1.5px solid var(--border);color:#1F1F2E;}
.btn-primary-c{background:var(--primary);color:#fff;border:none;}
.btn-primary-c:hover{background:var(--primary-hover);color:#fff;}
.btn-primary-c:disabled{opacity:.55;}
.alert-list{margin-bottom:14px;border-radius:12px;font-size:13px;}

.cam-overlay{position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:1100;display:none;flex-direction:column;align-items:center;justify-content:center;padding:20px;}
.cam-overlay.show{display:flex;}
.cam-title{color:#fff;font-weight:900;font-size:22px;margin-bottom:8px;}
.cam-sub{color:#E5E7EB;font-size:13px;text-align:center;margin-bottom:14px;}
#camVideo,#camCanvas{width:100%;max-width:390px;border-radius:14px;background:#000;aspect-ratio:16/10;object-fit:cover;}
.cam-actions{display:flex;gap:10px;margin-top:16px;width:100%;max-width:390px;}
.cam-actions .btn{flex:1;height:48px;border-radius:14px;font-weight:700;}
.cam-close{position:absolute;top:16px;right:16px;color:#fff;font-size:28px;cursor:pointer;background:transparent;border:none;}
.file-input{display:none;}
.or-divider{text-align:center;color:#E5E7EB;font-size:12px;margin:12px 0;}
/* ============ Success Screen (Pending Verification) ============ */
.success-screen-wrap{
    min-height: 60vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #F0F8FB;            /* very light blue/gray */
    border-radius: 18px;
    padding: 24px 16px;
    margin-top: 12px;
}
.success-card{
    width: 100%;
    max-width: 380px;
    background: linear-gradient(135deg, #A8E063 0%, #56AB2F 100%);
    border-radius: 18px;
    border: none;
    padding: 32px 26px 28px;
    text-align: center;
    box-shadow: 0 10px 30px rgba(86, 171, 47, 0.25);
}
.success-title{
    color: #111;
    font-weight: 900;
    font-size: 22px;
    margin: 0 0 12px;
    letter-spacing: -0.3px;
}
.success-sub{
    color: rgba(0, 0, 0, 0.72);
    font-size: 13.5px;
    line-height: 1.55;
    margin: 0 0 22px;
    font-weight: 500;
}
.btn-back-home{
    display: inline-block;
    background: linear-gradient(180deg, #5a5a5a 0%, #2a2a2a 50%, #4a4a4a 100%);
    color: #fff;
    border: none;
    padding: 12px 42px;
    font-weight: 800;
    font-size: 15px;
    text-decoration: none;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25), inset 0 1px 0 rgba(255, 255, 255, 0.15);
    transition: transform .15s ease, box-shadow .15s ease;
}
.btn-back-home:hover{
    color: #fff;
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.32), inset 0 1px 0 rgba(255, 255, 255, 0.18);
}
.btn-back-home:active{ transform: translateY(0); }

/* ============ Verified Banner ============ */
.verified-banner{
    background: #fff;
    border-radius: 18px;
    padding: 28px 22px;
    text-align: center;
    box-shadow: 0 4px 18px rgba(0,0,0,.05);
    margin-top: 10px;
}
.verified-icon{
    width: 70px; height: 70px;
    margin: 0 auto 14px;
    border-radius: 50%;
    background: #D4F5DD;
    color: #16A34A;
    display: flex; align-items: center; justify-content: center;
    font-size: 36px;
}
</style>
</head>
<body>
   
<!-- ============ PENDING STATE ============ -->
<div id="successScreen" class="success-screen-wrap"
     style="display: <?= $view_mode === 'pending' ? 'flex' : 'none' ?>;">
    <div class="success-card" data-testid="id-success-card">
    <h4 class="success-title">Identity Card Update Successful!</h4>
    <p class="success-sub">
        Your Identity ID information has been submitted. The system is verifying.
        We will notify you once the verification is complete.
    </p>
    <a href="dashboard_user.php" class="btn-back-home rounded-pill" data-testid="btn-back-home">
        Back to Home
    </a>
</div>
</div>
 <div id="uploadFormContainer" style="display: <?= $view_mode === 'editable' ? 'block' : 'none' ?>;">
<!-- ============ VERIFIED STATE ============ -->
<?php if ($view_mode === 'verified'): ?>
<div class="verified-banner" data-testid="verified-banner">
    <div class="verified-icon"><i class="bi bi-shield-check-fill"></i></div>
    <h4 class="fw-bold mb-1">Account Already Verified</h4>
    <p class="text-muted mb-3">Your identity has been confirmed. No further action is required.</p>
    <a href="dashboard.php" class="btn btn-dark rounded-pill px-4">Back to Home</a>
</div>
<?php endif; ?>

<!-- ============ EDITABLE STATE — your existing form ============ -->

    <!--
      ↓↓↓ KEEP YOUR EXISTING <form method="POST" enctype="multipart/form-data" id="idForm"> ↓↓↓
      ↓↓↓ Don't change anything inside the form                                              ↓↓↓
    -->


<div class="app-container" data-testid="update-id-page">
    <h1 class="page-title" data-testid="update-id-title">Update ID</h1>
    <div class="page-sub">Upload both sides of your ID to verify your account</div>

    <?php
    $sp_class = $current_status === 'verified' ? 'verified' : ($current_status === 'pending' ? 'pending' : 'unverified');
    $sp_label = ucfirst($current_status);
    ?>
    <span class="status-pill <?= $sp_class ?>" data-testid="id_status"><i class="bi bi-shield-check"></i> <?= $sp_label ?></span>

    <?php if ($success): ?>
    <div class="alert alert-success alert-list" data-testid="success-alert">
        <i class="bi bi-check-circle-fill me-1"></i> ID images updated. Status: <?= htmlspecialchars($current_status) ?>.
    </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger alert-list" data-testid="error-alert">
        <ul class="mb-0 ps-3">
            <?php foreach($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <form method="POST" action="update_id.php" enctype="multipart/form-data" id="idForm" data-testid="update-id-form">
        <input type="hidden" name="side" value="both">

        <div class="side-label">Front Side ID</div>
        <div class="preview-box" id="frontPreview" data-testid="front-preview" onclick="openCapture('front')">
            <?php if ($front_exists): ?>
                <img id="frontImg" src="uploads/<?= htmlspecialchars($user['id_front_image']) ?>" alt="Front ID">
            <?php else: ?>
                <div class="placeholder" id="frontPlaceholder">
                    <i class="bi bi-camera"></i>
                    <div class="label">Tap to capture Front ID</div>
                </div>
                <img id="frontImg" style="display:none" alt="Front ID">
            <?php endif; ?>
        </div>

        <div class="side-label">Back Side ID</div>
        <div class="preview-box" id="backPreview" data-testid="back-preview" onclick="openCapture('back')">
            <?php if ($back_exists): ?>
                <img id="backImg" src="uploads/<?= htmlspecialchars($user['id_back_image']) ?>" alt="Back ID">
            <?php else: ?>
                <div class="placeholder" id="backPlaceholder">
                    <i class="bi bi-camera"></i>
                    <div class="label">Tap to capture Back ID</div>
                </div>
                <img id="backImg" style="display:none" alt="Back ID">
            <?php endif; ?>
        </div>

        <div class="update-title">Update ID Information</div>
        <div class="update-warn" data-testid="update-warn">Please upload your ID again as requested by administrator.</div>

        <button type="button" class="upload-row" onclick="openCapture('front')" data-testid="btn-upload-front">
            <span class="lr-title">Upload Front ID</span>
            <i class="bi bi-chevron-right lr-arrow"></i>
        </button>
        <button type="button" class="upload-row" onclick="openCapture('back')" data-testid="btn-upload-back">
            <span class="lr-title">Upload Back ID</span>
            <i class="bi bi-chevron-right lr-arrow"></i>
        </button>

        <div class="note-box">
            <div class="nb-title"><i class="bi bi-clipboard-check"></i> Notice:</div>
            <ol>
                <li>Make sure the image is clear.</li>
                <li>All information must be visible.</li>
                <li>No blur or glare.</li>
            </ol>
        </div>

        <input type="file" name="id_front" id="fileFront" class="file-input" accept="image/jpeg,image/png,image/webp">
        <input type="file" name="id_back"  id="fileBack"  class="file-input" accept="image/jpeg,image/png,image/webp">

        <div class="btn-row">
            <a href="profile.php" class="btn btn-cancel" data-testid="btn-cancel">Cancel</a>
            <button type="submit" class="btn btn-primary-c" id="btnSubmit" data-testid="btn-submit">UPDATE</button>
        </div>
    </form>
</div>

<div class="cam-overlay" id="camOverlay" data-testid="camera-modal">
    <button class="cam-close" onclick="closeCapture()" data-testid="camera-close"><i class="bi bi-x-lg"></i></button>
    <div class="cam-title" id="camTitle">Take a Photo - ID Front</div>
    <div class="cam-sub">Place ID on a plain dark surface and make sure all four corners are visible.</div>
    <video id="camVideo" autoplay playsinline></video>
    <canvas id="camCanvas" style="display:none"></canvas>
    <div class="cam-actions">
        <button type="button" class="btn btn-cancel" onclick="closeCapture()" data-testid="camera-cancel">Cancel</button>
        <button type="button" class="btn btn-primary-c" id="camShoot" onclick="shoot()" data-testid="camera-capture">Capture</button>
    </div>
    <div class="or-divider">or</div>
    <button type="button" class="btn btn-cancel" style="width:100%;max-width:390px;" onclick="fallbackFile()" data-testid="camera-fallback">
        <i class="bi bi-upload me-1"></i> Choose file instead
    </button>
</div>
</div>
<script>
let activeSide = null;
let mediaStream = null;
const video   = document.getElementById('camVideo');
const canvas  = document.getElementById('camCanvas');
const overlay = document.getElementById('camOverlay');

async function openCapture(side) {
    activeSide = side;
    document.getElementById('camTitle').textContent = 'Take a Photo - ID ' + (side === 'front' ? 'Front' : 'Back');
    overlay.classList.add('show');
    try {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) throw new Error('no-camera');
        mediaStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: { ideal: 'environment' } },
            audio: false
        });
        video.srcObject = mediaStream;
        video.style.display = 'block';
        canvas.style.display = 'none';
    } catch (e) {
        overlay.classList.remove('show');
        fallbackFile();
    }
}
function closeCapture() {
    overlay.classList.remove('show');
    if (mediaStream) { mediaStream.getTracks().forEach(t=>t.stop()); mediaStream = null; }
    video.srcObject = null;
}
function shoot() {
    const w = video.videoWidth || 1280;
    const h = video.videoHeight || 720;
    canvas.width = w; canvas.height = h;
    canvas.getContext('2d').drawImage(video, 0, 0, w, h);
    canvas.toBlob((blob) => {
        if (!blob) return;
        const file = new File([blob], 'id_' + activeSide + '_' + Date.now() + '.jpg', { type: 'image/jpeg' });
        assignFile(file);
        closeCapture();
    }, 'image/jpeg', 0.92);
}
function fallbackFile() {
    const inp = activeSide === 'front' ? document.getElementById('fileFront') : document.getElementById('fileBack');
    inp.onchange = () => { if (inp.files[0]) { assignFile(inp.files[0]); closeCapture(); } };
    inp.click();
}
function assignFile(file) {
    const dt = new DataTransfer();
    dt.items.add(file);
    const inp = activeSide === 'front' ? document.getElementById('fileFront') : document.getElementById('fileBack');
    inp.files = dt.files;

    const reader = new FileReader();
    reader.onload = (e) => {
        const img = document.getElementById(activeSide === 'front' ? 'frontImg' : 'backImg');
        const ph  = document.getElementById(activeSide === 'front' ? 'frontPlaceholder' : 'backPlaceholder');
        img.src = e.target.result;
        img.style.display = 'block';
        if (ph) ph.style.display = 'none';
    };
    reader.readAsDataURL(file);
}
// === Part 2: AJAX submission via fetch ===
document.getElementById('idForm').addEventListener('submit', async (e) => {
    e.preventDefault();

    const form    = e.currentTarget;
    const fd      = new FormData(form);
    const submitBtn = document.getElementById('btnSubmit');
    const origText  = submitBtn.textContent;

    // Light client validation (kept lenient to preserve your existing logic)
    const front = document.getElementById('fileFront').files[0];
    const back  = document.getElementById('fileBack').files[0];
    if (!front && !document.getElementById('frontImg')?.src) {
        alert('Please capture or select the Front ID image.');
        return;
    }
    if (!back && !document.getElementById('backImg')?.src) {
        alert('Please capture or select the Back ID image.');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';

    try {
        const resp = await fetch('process_update_id.php', { method: 'POST', body: fd });
        const data = await resp.json();

        if (data.success === true) {
            // Hide form, show success screen — no page reload
            document.getElementById('uploadFormContainer').style.display = 'none';
            const ok = document.getElementById('successScreen');
            ok.style.display = 'flex';
            ok.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            alert(data.error || 'Failed to update ID. Please try again.');
        }
    } catch (err) {
        alert('Network error. Please check your connection and try again.');
    } finally {
        submitBtn.disabled = false;
        submitBtn.textContent = origText;
    }
});
</script>
</body>
</html>