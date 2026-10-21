<?php
session_start();

/* ---------------- CONFIG ---------------- */
const DB_HOST = 'localhost';
const DB_NAME = 'social_events';
const DB_USER = 'root';
const DB_PASS = '';
const BASE_URL = ''; 

/* ---------------- DB CONNECT + AUTO-INSTALL ---------------- */
try {
    // Connect without db first so we can create it
    $pdo = new PDO("mysql:host=" . DB_HOST . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(100) NOT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        profile_picture VARCHAR(255) DEFAULT 'default.png',
        reset_token VARCHAR(64) DEFAULT NULL,
        reset_expires DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        event_date DATETIME NOT NULL,
        location VARCHAR(200) NOT NULL,
        image VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sender_id INT NOT NULL,
        receiver_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_conversation (sender_id, receiver_id)
    ) ENGINE=InnoDB");

    foreach (['uploads/profiles', 'uploads/events'] as $dir) {
        if (!is_dir(__DIR__ . '/' . $dir)) @mkdir(__DIR__ . '/' . $dir, 0775, true);
    }
} catch (PDOException $e) {
    die("DB error: " . htmlspecialchars($e->getMessage()));
}

/* ---------------- HELPERS ---------------- */
function e($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

function csrfToken() {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function verifyCsrf($t) {
    return !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $t ?? '');
}

function requireLogin() {
    if (empty($_SESSION['user_id'])) { header('Location: index.php?page=login'); exit; }
}

function redirect($p) { header("Location: index.php?page=$p"); exit; }

function flash($k, $v = null) {
    if ($v !== null) { $_SESSION['flash'][$k] = $v; return null; }
    if (!empty($_SESSION['flash'][$k])) {
        $x = $_SESSION['flash'][$k];
        unset($_SESSION['flash'][$k]);
        return $x;
    }
    return null;
}

function uploadImage(array $file, string $folder, string $prefix) {
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) return [false, 'Upload error.'];
    if ($file['size'] > 2 * 1024 * 1024) return [false, 'Max file size is 2MB.'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) return [false, 'Only JPG, PNG, GIF, WEBP allowed.'];
    $name = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $dest = __DIR__ . '/uploads/' . $folder . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return [false, 'Could not save file.'];
    return [true, $name];
}

function userById($pdo, $id) {
    $s = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $s->execute([(int)$id]);
    return $s->fetch();
}

/* ---------------- ROUTER ---------------- */
$page = $_GET['page'] ?? 'dashboard';

/* ===================== ACTIONS (POST / LOGOUT HANDLERS) ===================== */

// -------- LOGOUT --------
if ($page === 'logout') {
    $_SESSION = [];
    session_destroy();
    redirect('login');
}

// -------- REGISTER --------
if ($page === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!verifyCsrf($_POST['csrf'] ?? '')) $errors[] = 'Invalid session.';
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password  = $_POST['password'] ?? '';

    if (strlen($full_name) < 2) $errors[] = 'Full name must be at least 2 characters.';
    if (!$email) $errors[] = 'Valid email required.';
    if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($email) {
        $s = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $s->execute([$email]);
        if ($s->fetch()) $errors[] = 'Email already registered.';
    }
    $picName = 'default.png';
    if (!empty($_FILES['profile_picture']['name'])) {
        [$ok, $res] = uploadImage($_FILES['profile_picture'], 'profiles', 'user');
        if ($ok) $picName = $res; else $errors[] = $res;
    }
    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $s = $pdo->prepare("INSERT INTO users (full_name, email, password, profile_picture) VALUES (?,?,?,?)");
        $s->execute([$full_name, $email, $hash, $picName]);
        flash('success', 'Registration successful. Please log in.');
        redirect('login');
    }
    $_SESSION['register_errors'] = $errors;
    $_SESSION['register_old'] = ['full_name' => $full_name, 'email' => $_POST['email'] ?? ''];
    redirect('register');
}

// -------- LOGIN --------
if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    if (!verifyCsrf($_POST['csrf'] ?? '')) $errors[] = 'Invalid session.';
    $email    = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    if (!$email || !$password) {
        $errors[] = 'Email and password required.';
    } else {
        $s = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $s->execute([$email]);
        $u = $s->fetch();
        if ($u && password_verify($password, $u['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $u['id'];
            $_SESSION['user_name'] = $u['full_name'];
            redirect('dashboard');
        } else {
            $errors[] = 'Invalid credentials.';
        }
    }
    $_SESSION['login_errors'] = $errors;
    redirect('login');
}

// -------- FORGOT PASSWORD --------
if ($page === 'forgot' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    if ($email) {
        $s = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $s->execute([$email]);
        if ($u = $s->fetch()) {
            $token = bin2hex(random_bytes(32));
            $exp = date('Y-m-d H:i:s', time() + 3600);
            $pdo->prepare("UPDATE users SET reset_token=?, reset_expires=? WHERE id=?")
                ->execute([$token, $exp, $u['id']]);
            $link = (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') . $_SERVER['HTTP_HOST']
                    . dirname($_SERVER['PHP_SELF']) . "/index.php?page=reset&token=$token";
            flash('forgot_msg', "Reset link (demo): <a href=\"" . e($link) . "\">" . e($link) . "</a>");
        } else {
            flash('forgot_msg', 'If the email exists, a reset link was sent.');
        }
    }
    redirect('forgot');
}

// -------- RESET PASSWORD --------
if ($page === 'reset' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['token'] ?? '';
    $pw = $_POST['password'] ?? '';
    $s = $pdo->prepare("SELECT * FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $s->execute([$token]);
    $u = $s->fetch();
    if (!$u) {
        flash('reset_errors', ['Invalid or expired token.']);
    } elseif (strlen($pw) < 8) {
        flash('reset_errors', ['Password must be 8+ chars.']);
    } else {
        $pdo->prepare("UPDATE users SET password=?, reset_token=NULL, reset_expires=NULL WHERE id=?")
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        flash('success', 'Password updated. Please log in.');
        redirect('login');
    }
    header("Location: index.php?page=reset&token=" . urlencode($token));
    exit;
}

// -------- CREATE EVENT --------
if ($page === 'event_create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $errors = [];
    if (!verifyCsrf($_POST['csrf'] ?? '')) $errors[] = 'Invalid token.';
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $date  = $_POST['event_date'] ?? '';
    $loc   = trim($_POST['location'] ?? '');
    if (strlen($title) < 3) $errors[] = 'Title too short.';
    if (strlen($desc) < 5) $errors[] = 'Description too short.';
    if (!$date || strtotime($date) === false) $errors[] = 'Invalid date.';
    if (strlen($loc) < 2) $errors[] = 'Location required.';
    $img = null;
    if (!empty($_FILES['image']['name'])) {
        [$ok, $res] = uploadImage($_FILES['image'], 'events', 'event');
        if ($ok) $img = $res; else $errors[] = $res;
    }
    if (!$errors) {
        $pdo->prepare("INSERT INTO events (user_id, title, description, event_date, location, image) VALUES (?,?,?,?,?,?)")
            ->execute([$_SESSION['user_id'], $title, $desc, date('Y-m-d H:i:s', strtotime($date)), $loc, $img]);
        flash('success', 'Event created!');
        redirect('dashboard');
    }
    $_SESSION['event_errors'] = $errors;
    redirect('event_create');
}

// -------- EDIT EVENT --------
if ($page === 'event_edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $id = (int)($_GET['id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM events WHERE id = ? AND user_id = ?");
    $s->execute([$id, $_SESSION['user_id']]);
    $ev = $s->fetch();
    if (!$ev) redirect('dashboard');

    $errors = [];
    if (!verifyCsrf($_POST['csrf'] ?? '')) $errors[] = 'Invalid token.';
    $title = trim($_POST['title'] ?? '');
    $desc  = trim($_POST['description'] ?? '');
    $date  = $_POST['event_date'] ?? '';
    $loc   = trim($_POST['location'] ?? '');
    $img   = $ev['image'];
    if (strlen($title) < 3) $errors[] = 'Title too short.';
    if (!$date) $errors[] = 'Date required.';
    if (strlen($loc) < 2) $errors[] = 'Location required.';
    if (!empty($_FILES['image']['name'])) {
        [$ok, $res] = uploadImage($_FILES['image'], 'events', 'event');
        if ($ok) $img = $res; else $errors[] = $res;
    }
    if (!$errors) {
        $pdo->prepare("UPDATE events SET title=?, description=?, event_date=?, location=?, image=? WHERE id=? AND user_id=?")
            ->execute([$title, $desc, date('Y-m-d H:i:s', strtotime($date)), $loc, $img, $id, $_SESSION['user_id']]);
        flash('success', 'Event updated.');
        redirect('dashboard');
    }
    $_SESSION['event_errors'] = $errors;
    header("Location: index.php?page=event_edit&id=$id");
    exit;
}

// -------- DELETE EVENT --------
if ($page === 'event_delete') {
    requireLogin();
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare("DELETE FROM events WHERE id = ? AND user_id = ?")->execute([$id, $_SESSION['user_id']]);
    flash('success', 'Event deleted.');
    redirect('dashboard');
}

// -------- UPDATE PROFILE --------
if ($page === 'profile_edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    $errors = [];
    if (!verifyCsrf($_POST['csrf'] ?? '')) $errors[] = 'Invalid token.';
    $name = trim($_POST['full_name'] ?? '');
    if (strlen($name) < 2) $errors[] = 'Name too short.';
    $u = userById($pdo, $_SESSION['user_id']);
    $pic = $u['profile_picture'];
    if (!empty($_FILES['profile_picture']['name'])) {
        [$ok, $res] = uploadImage($_FILES['profile_picture'], 'profiles', 'user');
        if ($ok) $pic = $res; else $errors[] = $res;
    }
    if (!$errors) {
        $pdo->prepare("UPDATE users SET full_name=?, profile_picture=? WHERE id=?")
            ->execute([$name, $pic, $_SESSION['user_id']]);
        $_SESSION['user_name'] = $name;
        flash('success', 'Profile updated.');
        redirect('profile&id=' . $_SESSION['user_id']);
    }
    $_SESSION['profile_errors'] = $errors;
    redirect('profile_edit');
}

// -------- SEND MESSAGE --------
if ($page === 'send_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    requireLogin();
    if (!verifyCsrf($_POST['csrf'] ?? '')) redirect('messages');
    $receiver = (int)($_POST['receiver_id'] ?? 0);
    $msg = trim($_POST['message'] ?? '');
    $sender = $_SESSION['user_id'];
    $s = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $s->execute([$receiver]);
    if (!$s->fetch() || $receiver === $sender || $msg === '') redirect('messages');
    if (mb_strlen($msg) > 2000) $msg = mb_substr($msg, 0, 2000);
    $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?,?,?)")
        ->execute([$sender, $receiver, $msg]);
    header("Location: index.php?page=conversation&with=$receiver");
    exit;
}

/* ===================== AJAX SEARCH (JSON) ===================== */
if ($page === 'search' && !empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    if (empty($_SESSION['user_id'])) { echo json_encode([]); exit; }
    $q = trim($_GET['q'] ?? '');
    $out = [];
    if ($q !== '') {
        $like = "%$q%";
        $s = $pdo->prepare("SELECT id, full_name FROM users WHERE full_name LIKE ? LIMIT 5");
        $s->execute([$like]);
        foreach ($s->fetchAll() as $row)
            $out[] = ['label' => '👤 ' . $row['full_name'], 'url' => "index.php?page=profile&id={$row['id']}"];

        $s = $pdo->prepare("SELECT id, title FROM events WHERE title LIKE ? LIMIT 5");
        $s->execute([$like]);
        foreach ($s->fetchAll() as $row)
            $out[] = ['label' => '📅 ' . $row['title'], 'url' => "index.php?page=event_view&id={$row['id']}"];
    }
    echo json_encode($out);
    exit;
}

/* ===================== HTML RENDERING ===================== */
$logged = !empty($_SESSION['user_id']);
$currentUser = $logged ? userById($pdo, $_SESSION['user_id']) : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e(ucfirst(str_replace('_',' ', $page))) ?> — Social Events</title>
<style>
* { box-sizing: border-box; }
body { margin:0; font-family: system-ui, Segoe UI, Arial, sans-serif; background:#f4f6f9; color:#222; }
.navbar { display:flex; justify-content:space-between; align-items:center; background:#1e3a8a; color:#fff; padding:12px 24px; }
.navbar a { color:#fff; text-decoration:none; margin-left:16px; }
.navbar .brand { font-weight:700; font-size:1.2rem; }
.container { max-width:1000px; margin:24px auto; padding:0 16px; }
.card { background:#fff; border-radius:10px; padding:20px; margin-bottom:20px; box-shadow:0 2px 8px rgba(0,0,0,.06); }
.btn { display:inline-block; background:#1e3a8a; color:#fff; padding:9px 16px; border:none; border-radius:6px; cursor:pointer; text-decoration:none; font-size:.95rem; }
.btn.secondary { background:#6b7280; }
.btn.danger { background:#dc2626; }
input[type=text], input[type=email], input[type=password], input[type=datetime-local], textarea {
  width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:6px; margin:6px 0 14px; font-size:1rem; font-family:inherit;
}
label { font-weight:600; font-size:.9rem; }
.error { color:#dc2626; display:block; margin-top:-10px; margin-bottom:10px; font-size:.85rem; }
.alert { padding:10px 14px; border-radius:6px; margin-bottom:14px; }
.alert.success { background:#dcfce7; color:#166534; }
.avatar { width:44px; height:44px; border-radius:50%; object-fit:cover; background:#ddd; }
.avatar-lg { width:120px; height:120px; border-radius:50%; object-fit:cover; background:#ddd; }
.event-card { display:flex; gap:16px; border-bottom:1px solid #eee; padding:14px 0; }
.event-card img.event-thumb { width:120px; height:90px; object-fit:cover; border-radius:8px; }
.chat-box { max-height:450px; overflow-y:auto; padding:10px; background:#f9fafb; border-radius:8px; }
.bubble { padding:10px 14px; border-radius:14px; max-width:70%; margin:6px 0; word-wrap:break-word; }
.bubble.mine { background:#1e3a8a; color:#fff; margin-left:auto; }
.bubble.theirs { background:#e5e7eb; }
.search-item { padding:8px 0; border-bottom:1px solid #eee; }
.muted { color:#6b7280; font-size:.9rem; }
h1,h2,h3 { margin-top:0; }
</style>
</head>
<body>
<nav class="navbar">
    <a href="index.php?page=dashboard" class="brand">📅 SocialEvents</a>
    <div>
        <?php if ($logged): ?>
            <a href="index.php?page=dashboard">Dashboard</a>
            <a href="index.php?page=search">Search</a>
            <a href="index.php?page=messages">Messages</a>
            <a href="index.php?page=profile&id=<?= (int)$_SESSION['user_id'] ?>"><?= e($_SESSION['user_name']) ?></a>
            <a href="index.php?page=logout">Logout</a>
        <?php else: ?>
            <a href="index.php?page=login">Login</a>
            <a href="index.php?page=register">Register</a>
        <?php endif; ?>
    </div>
</nav>
<main class="container">
<?php if ($s = flash('success')): ?><div class="alert success"><?= e($s) ?></div><?php endif; ?>
<?php

/* ===================== PAGE VIEWS ===================== */

/* ---------- REGISTER ---------- */
if ($page === 'register'):
    $errors = $_SESSION['register_errors'] ?? []; unset($_SESSION['register_errors']);
    $old    = $_SESSION['register_old']    ?? ['full_name'=>'','email'=>'']; unset($_SESSION['register_old']);
?>
    <div class="card" style="max-width:520px;margin:auto;">
        <h2>Create Account</h2>
        <?php foreach ($errors as $er): ?><p class="error"><?= e($er) ?></p><?php endforeach; ?>
        <form method="post" action="index.php?page=register" enctype="multipart/form-data" data-validate>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <label>Full Name</label>
            <input type="text" name="full_name" value="<?= e($old['full_name']) ?>" required>
            <label>Email</label>
            <input type="email" name="email" value="<?= e($old['email']) ?>" required>
            <label>Password (min 8 chars)</label>
            <input type="password" name="password" data-min="8" required>
            <label>Profile Picture (optional, max 2MB)</label>
            <input type="file" name="profile_picture" accept="image/*" data-preview="preview-reg">
            <img id="preview-reg" src="" style="display:none;max-width:120px;border-radius:50%;margin:8px 0;">
            <button class="btn" type="submit">Register</button>
        </form>
    </div>

<?php /* ---------- LOGIN ---------- */
elseif ($page === 'login'):
    $errors = $_SESSION['login_errors'] ?? []; unset($_SESSION['login_errors']);
?>
    <div class="card" style="max-width:420px;margin:auto;">
        <h2>Login</h2>
        <?php foreach ($errors as $er): ?><p class="error"><?= e($er) ?></p><?php endforeach; ?>
        <form method="post" action="index.php?page=login" data-validate>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <label>Email</label><input type="email" name="email" required>
            <label>Password</label><input type="password" name="password" required>
            <button class="btn" type="submit">Login</button>
        </form>
        <p><a href="index.php?page=forgot">Forgot password?</a></p>
    </div>

<?php /* ---------- FORGOT ---------- */
elseif ($page === 'forgot'): ?>
    <div class="card" style="max-width:480px;margin:auto;">
        <h2>Forgot Password</h2>
        <?php if ($m = flash('forgot_msg')): ?><div class="alert success"><?= $m ?></div><?php endif; ?>
        <form method="post" action="index.php?page=forgot" data-validate>
            <label>Email</label><input type="email" name="email" required>
            <button class="btn" type="submit">Send Reset Link</button>
        </form>
    </div>

<?php /* ---------- RESET ---------- */
elseif ($page === 'reset'):
    $token = $_GET['token'] ?? '';
    $errors = $_SESSION['reset_errors'] ?? []; unset($_SESSION['reset_errors']);
    $s = $pdo->prepare("SELECT id FROM users WHERE reset_token=? AND reset_expires > NOW()");
    $s->execute([$token]);
    $valid = (bool)$s->fetch();
?>
    <div class="card" style="max-width:420px;margin:auto;">
        <h2>Reset Password</h2>
        <?php foreach ($errors as $er): ?><p class="error"><?= e($er) ?></p><?php endforeach; ?>
        <?php if (!$valid): ?>
            <p class="error">Invalid or expired token.</p>
        <?php else: ?>
            <form method="post" action="index.php?page=reset" data-validate>
                <input type="hidden" name="token" value="<?= e($token) ?>">
                <label>New Password</label><input type="password" name="password" data-min="8" required>
                <button class="btn" type="submit">Update Password</button>
            </form>
        <?php endif; ?>
    </div>

<?php /* ---------- DASHBOARD ---------- */
elseif ($page === 'dashboard'):
    requireLogin();
    $upcoming = $pdo->query("SELECT e.*, u.full_name FROM events e JOIN users u ON e.user_id=u.id
                             WHERE e.event_date >= NOW() ORDER BY e.event_date ASC")->fetchAll();
    $s = $pdo->prepare("SELECT * FROM events WHERE user_id=? ORDER BY event_date DESC");
    $s->execute([$_SESSION['user_id']]);
    $mine = $s->fetchAll();
?>
    <div class="card">
        <h2>Upcoming Events</h2>
        <?php if (!$upcoming): ?><p class="muted">No upcoming events.</p><?php endif; ?>
        <?php foreach ($upcoming as $ev): ?>
            <div class="event-card">
                <img class="event-thumb" src="uploads/events/<?= e($ev['image'] ?: 'placeholder.jpg') ?>"
                     onerror="this.src='https://via.placeholder.com/120x90?text=Event'">
                <div>
                    <h3><a href="index.php?page=event_view&id=<?= (int)$ev['id'] ?>"><?= e($ev['title']) ?></a></h3>
                    <p class="muted">📅 <?= e(date('D, M j Y g:i A', strtotime($ev['event_date']))) ?> · 📍 <?= e($ev['location']) ?></p>
                    <p class="muted">by <?= e($ev['full_name']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;">
            <h2>My Events</h2>
            <a class="btn" href="index.php?page=event_create">+ Create Event</a>
        </div>
        <?php if (!$mine): ?><p class="muted">No events created yet.</p><?php endif; ?>
        <?php foreach ($mine as $ev): ?>
            <div class="event-card">
                <div style="flex:1;">
                    <h3><?= e($ev['title']) ?></h3>
                    <p class="muted"><?= e(date('M j Y g:i A', strtotime($ev['event_date']))) ?> · <?= e($ev['location']) ?></p>
                    <p class="muted">Created: <?= e($ev['created_at']) ?> · Updated: <?= e($ev['updated_at']) ?></p>
                    <a class="btn secondary" href="index.php?page=event_edit&id=<?= (int)$ev['id'] ?>">Edit</a>
                    <a class="btn danger" href="index.php?page=event_delete&id=<?= (int)$ev['id'] ?>"
                       onclick="return confirm('Delete this event?')">Delete</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php /* ---------- EVENT CREATE ---------- */
elseif ($page === 'event_create'):
    requireLogin();
    $errors = $_SESSION['event_errors'] ?? []; unset($_SESSION['event_errors']);
?>
    <div class="card">
        <h2>Create Event</h2>
        <?php foreach ($errors as $er): ?><p class="error"><?= e($er) ?></p><?php endforeach; ?>
        <form method="post" action="index.php?page=event_create" enctype="multipart/form-data" data-validate>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <label>Title</label><input type="text" name="title" required>
            <label>Description</label><textarea name="description" rows="4" required></textarea>
            <label>Date &amp; Time</label><input type="datetime-local" name="event_date" required>
            <label>Location</label><input type="text" name="location" required>
            <label>Event Image (optional)</label>
            <input type="file" name="image" accept="image/*" data-preview="preview-ev">
            <img id="preview-ev" style="display:none;max-width:200px;border-radius:8px;margin:8px 0;">
            <button class="btn" type="submit">Create</button>
        </form>
    </div>

<?php /* ---------- EVENT EDIT ---------- */
elseif ($page === 'event_edit'):
    requireLogin();
    $id = (int)($_GET['id'] ?? 0);
    $s = $pdo->prepare("SELECT * FROM events WHERE id=? AND user_id=?");
    $s->execute([$id, $_SESSION['user_id']]);
    $ev = $s->fetch();
    if (!$ev) { echo "<p class='error'>Not found or not yours.</p>"; include __FILE__ . 'FOOTER'; exit; }
    $errors = $_SESSION['event_errors'] ?? []; unset($_SESSION['event_errors']);
?>
    <div class="card">
        <h2>Edit Event</h2>
        <?php foreach ($errors as $er): ?><p class="error"><?= e($er) ?></p><?php endforeach; ?>
        <form method="post" action="index.php?page=event_edit&id=<?= (int)$ev['id'] ?>" enctype="multipart/form-data" data-validate>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <label>Title</label><input type="text" name="title" value="<?= e($ev['title']) ?>" required>
            <label>Description</label><textarea name="description" rows="4" required><?= e($ev['description']) ?></textarea>
            <label>Date &amp; Time</label>
            <input type="datetime-local" name="event_date" value="<?= e(date('Y-m-d\TH:i', strtotime($ev['event_date']))) ?>" required>
            <label>Location</label><input type="text" name="location" value="<?= e($ev['location']) ?>" required>
            <label>Replace Image (optional)</label>
            <input type="file" name="image" accept="image/*" data-preview="preview-ee">
            <img id="preview-ee" src="uploads/events/<?= e($ev['image'] ?: 'placeholder.jpg') ?>"
                 onerror="this.style.display='none'" style="max-width:200px;border-radius:8px;margin:8px 0;">
            <button class="btn" type="submit">Update</button>
        </form>
    </div>

<?php /* ---------- EVENT VIEW ---------- */
elseif ($page === 'event_view'):
    requireLogin();
    $id = (int)($_GET['id'] ?? 0);
    $s = $pdo->prepare("SELECT e.*, u.full_name, u.id AS owner_id FROM events e JOIN users u ON e.user_id=u.id WHERE e.id=?");
    $s->execute([$id]);
    $ev = $s->fetch();
    if (!$ev) { echo "<div class='card'><p class='error'>Event not found.</p></div>"; }
    else {
?>
    <div class="card">
        <img src="uploads/events/<?= e($ev['image'] ?: 'placeholder.jpg') ?>"
             onerror="this.src='https://via.placeholder.com/800x300?text=Event'"
             style="width:100%;max-height:340px;object-fit:cover;border-radius:10px;">
        <h1><?= e($ev['title']) ?></h1>
        <p class="muted">📅 <?= e(date('D, M j Y g:i A', strtotime($ev['event_date']))) ?> · 📍 <?= e($ev['location']) ?></p>
        <p><?= nl2br(e($ev['description'])) ?></p>
        <p class="muted">Hosted by <a href="index.php?page=profile&id=<?= (int)$ev['owner_id'] ?>"><?= e($ev['full_name']) ?></a></p>
        <?php if ($ev['owner_id'] == $_SESSION['user_id']): ?>
            <a class="btn secondary" href="index.php?page=event_edit&id=<?= (int)$ev['id'] ?>">Edit</a>
        <?php else: ?>
            <a class="btn" href="index.php?page=conversation&with=<?= (int)$ev['owner_id'] ?>">Message Host</a>
        <?php endif; ?>
    </div>
<?php } ?>

<?php /* ---------- PROFILE ---------- */
elseif ($page === 'profile'):
    requireLogin();
    $id = (int)($_GET['id'] ?? $_SESSION['user_id']);
    $u = userById($pdo, $id);
    if (!$u) { echo "<div class='card'><p class='error'>User not found.</p></div>"; }
    else {
        $s = $pdo->prepare("SELECT * FROM events WHERE user_id=? ORDER BY event_date DESC");
        $s->execute([$id]);
        $events = $s->fetchAll();
?>
    <div class="card">
        <div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap;">
            <img class="avatar-lg" src="uploads/profiles/<?= e($u['profile_picture']) ?>"
                 onerror="this.src='https://via.placeholder.com/120'">
            <div>
                <h2><?= e($u['full_name']) ?></h2>
                <p class="muted">Joined <?= e(date('M Y', strtotime($u['created_at']))) ?></p>
                <?php if ($id == $_SESSION['user_id']): ?>
                    <a class="btn secondary" href="index.php?page=profile_edit">Edit Profile</a>
                <?php else: ?>
                    <a class="btn" href="index.php?page=conversation&with=<?= $id ?>">Send Message</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card">
        <h3><?= $id == $_SESSION['user_id'] ? 'My' : e($u['full_name']) . "'s" ?> Events</h3>
        <?php if (!$events): ?><p class="muted">No events yet.</p><?php endif; ?>
        <?php foreach ($events as $ev): ?>
            <div class="event-card">
                <div>
                    <a href="index.php?page=event_view&id=<?= (int)$ev['id'] ?>"><strong><?= e($ev['title']) ?></strong></a>
                    <p class="muted"><?= e(date('M j Y', strtotime($ev['event_date']))) ?> · <?= e($ev['location']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php } ?>

<?php /* ---------- PROFILE EDIT ---------- */
elseif ($page === 'profile_edit'):
    requireLogin();
    $u = $currentUser;
    $errors = $_SESSION['profile_errors'] ?? []; unset($_SESSION['profile_errors']);
?>
    <div class="card" style="max-width:520px;margin:auto;">
        <h2>Edit Profile</h2>
        <?php foreach ($errors as $er): ?><p class="error"><?= e($er) ?></p><?php endforeach; ?>
        <form method="post" action="index.php?page=profile_edit" enctype="multipart/form-data" data-validate>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <label>Full Name</label><input type="text" name="full_name" value="<?= e($u['full_name']) ?>" required>
            <label>Profile Picture</label>
            <input type="file" name="profile_picture" accept="image/*" data-preview="preview-pe">
            <img id="preview-pe" src="uploads/profiles/<?= e($u['profile_picture']) ?>"
                 onerror="this.src='https://via.placeholder.com/120'"
                 style="max-width:120px;border-radius:50%;display:block;margin:8px 0;">
            <button class="btn" type="submit">Save Changes</button>
        </form>
    </div>

<?php /* ---------- SEARCH ---------- */
elseif ($page === 'search'):
    requireLogin();
    $q = trim($_GET['q'] ?? '');
    $users = $events = [];
    if ($q !== '') {
        $like = "%$q%";
        $s = $pdo->prepare("SELECT id, full_name, profile_picture FROM users WHERE full_name LIKE ? LIMIT 20");
        $s->execute([$like]); $users = $s->fetchAll();
        $s = $pdo->prepare("SELECT id, title, event_date, location FROM events WHERE title LIKE ? OR location LIKE ? ORDER BY event_date ASC LIMIT 20");
        $s->execute([$like, $like]); $events = $s->fetchAll();
    }
?>
    <div class="card">
        <h2>Search</h2>
        <input type="text" id="live-search" placeholder="Search users and events..." value="<?= e($q) ?>">
        <div id="search-results"></div>
    </div>
    <?php if ($q !== ''): ?>
        <div class="card">
            <h3>Users</h3>
            <?php if (!$users): ?><p class="muted">No matching users.</p><?php endif; ?>
            <?php foreach ($users as $u): ?>
                <div class="event-card" style="align-items:center;">
                    <img class="avatar" src="uploads/profiles/<?= e($u['profile_picture']) ?>"
                         onerror="this.src='https://via.placeholder.com/44'">
                    <a href="index.php?page=profile&id=<?= (int)$u['id'] ?>"><?= e($u['full_name']) ?></a>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <h3>Events</h3>
            <?php if (!$events): ?><p class="muted">No matching events.</p><?php endif; ?>
            <?php foreach ($events as $ev): ?>
                <div class="event-card">
                    <div>
                        <a href="index.php?page=event_view&id=<?= (int)$ev['id'] ?>"><strong><?= e($ev['title']) ?></strong></a>
                        <p class="muted"><?= e(date('M j Y', strtotime($ev['event_date']))) ?> · <?= e($ev['location']) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php /* ---------- MESSAGES LIST ---------- */
elseif ($page === 'messages'):
    requireLogin();
    $uid = $_SESSION['user_id'];
    $s = $pdo->prepare("SELECT CASE WHEN sender_id = ? THEN receiver_id ELSE sender_id END AS partner_id,
                               MAX(created_at) AS last_time
                        FROM messages WHERE sender_id = ? OR receiver_id = ?
                        GROUP BY partner_id ORDER BY last_time DESC");
    $s->execute([$uid, $uid, $uid]);
    $rows = $s->fetchAll();
    $conversations = [];
    foreach ($rows as $r) {
        $u = userById($pdo, $r['partner_id']);
        if (!$u) continue;
        $m = $pdo->prepare("SELECT message FROM messages
                            WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
                            ORDER BY created_at DESC LIMIT 1");
        $m->execute([$uid, $r['partner_id'], $r['partner_id'], $uid]);
        $u['last_message'] = $m->fetchColumn();
        $u['last_time'] = $r['last_time'];
        $conversations[] = $u;
    }
?>
    <div class="card">
        <h2>Conversations</h2>
        <?php if (!$conversations): ?>
            <p class="muted">No messages yet. <a href="index.php?page=search">Find people</a>.</p>
        <?php endif; ?>
        <?php foreach ($conversations as $c): ?>
            <div class="event-card" style="align-items:center;">
                <img class="avatar" src="uploads/profiles/<?= e($c['profile_picture']) ?>"
                     onerror="this.src='https://via.placeholder.com/44'">
                <div style="flex:1;">
                    <a href="index.php?page=conversation&with=<?= (int)$c['id'] ?>"><strong><?= e($c['full_name']) ?></strong></a>
                    <p class="muted"><?= e(mb_strimwidth($c['last_message'], 0, 60, '…')) ?> · <?= e($c['last_time']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php /* ---------- CONVERSATION ---------- */
elseif ($page === 'conversation'):
    requireLogin();
    $uid = $_SESSION['user_id'];
    $other = (int)($_GET['with'] ?? 0);
    if ($other === $uid || $other <= 0) { echo "<div class='card'><p class='muted'>Invalid recipient.</p></div>"; }
    else {
        $partner = userById($pdo, $other);
        if (!$partner) { echo "<div class='card'><p class='error'>User not found.</p></div>"; }
        else {
            // Access control: only messages where user is a participant
            $m = $pdo->prepare("SELECT * FROM messages
                                WHERE (sender_id=? AND receiver_id=?) OR (sender_id=? AND receiver_id=?)
                                ORDER BY created_at ASC");
            $m->execute([$uid, $other, $other, $uid]);
            $messages = $m->fetchAll();
?>
    <div class="card">
        <h3>Conversation with <a href="index.php?page=profile&id=<?= (int)$partner['id'] ?>"><?= e($partner['full_name']) ?></a></h3>
        <div class="chat-box" id="chatbox">
            <?php foreach ($messages as $msg): ?>
                <div class="bubble <?= $msg['sender_id'] == $uid ? 'mine' : 'theirs' ?>">
                    <?= nl2br(e($msg['message'])) ?>
                    <div class="muted" style="font-size:.7rem;"><?= e($msg['created_at']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$messages): ?><p class="muted">No messages yet. Say hi!</p><?php endif; ?>
        </div>
        <form method="post" action="index.php?page=send_message" style="margin-top:14px;" data-validate>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <input type="hidden" name="receiver_id" value="<?= (int)$other ?>">
            <textarea name="message" rows="3" required placeholder="Type a message..."></textarea>
            <button class="btn" type="submit">Send</button>
        </form>
    </div>
    <script>
      const cb = document.getElementById('chatbox');
      cb.scrollTop = cb.scrollHeight;
    </script>
<?php } } ?>

<?php /* ---------- FALLBACK ---------- */
else: ?>
    <div class="card"><p class="muted">Page not found. <a href="index.php?page=dashboard">Go to dashboard</a></p></div>
<?php endif; ?>
</main>

<script>
/* ---------- CLIENT-SIDE VALIDATION ---------- */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-validate]').forEach(form => {
    form.addEventListener('submit', e => {
      let ok = true;
      form.querySelectorAll('[required]').forEach(field => {
        const errId = field.name + '-err';
        let err = document.getElementById(errId);
        if (!err) {
          err = document.createElement('small');
          err.id = errId; err.className = 'error';
          field.parentNode.appendChild(err);
        }
        err.textContent = '';
        const v = field.value.trim();
        if (!v) { err.textContent = 'This field is required.'; ok = false; }
        else if (field.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) {
          err.textContent = 'Invalid email format.'; ok = false;
        } else if (field.type === 'password' && field.dataset.min && v.length < +field.dataset.min) {
          err.textContent = `Minimum ${field.dataset.min} characters.`; ok = false;
        }
      });
      if (!ok) e.preventDefault();
    });
  });

  /* ---------- IMAGE PREVIEW ---------- */
  document.querySelectorAll('input[type=file][data-preview]').forEach(input => {
    input.addEventListener('change', () => {
      const target = document.getElementById(input.dataset.preview);
      if (!target || !input.files[0]) return;
      if (!input.files[0].type.startsWith('image/')) { alert('Please choose an image.'); input.value=''; return; }
      const r = new FileReader();
      r.onload = ev => { target.src = ev.target.result; target.style.display = 'block'; };
      r.readAsDataURL(input.files[0]);
    });
  });

  /* ---------- LIVE SEARCH ---------- */
  const searchInput = document.getElementById('live-search');
  if (searchInput) {
    const results = document.getElementById('search-results');
    let timer;
    searchInput.addEventListener('input', () => {
      clearTimeout(timer);
      const q = searchInput.value.trim();
      if (q.length < 2) { results.innerHTML = ''; return; }
      timer = setTimeout(() => {
        fetch('index.php?page=search&ajax=1&q=' + encodeURIComponent(q))
          .then(r => r.json())
          .then(data => {
            results.innerHTML = data.length
              ? data.map(i => `<div class="search-item"><a href="${i.url}">${i.label}</a></div>`).join('')
              : '<div class="search-item muted">No results</div>';
          })
          .catch(() => results.innerHTML = '<div class="search-item muted">Search error</div>');
      }, 300);
    });
  }
});
</script>
</body>
</html>