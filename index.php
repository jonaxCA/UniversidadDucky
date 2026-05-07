<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';

// Ya autenticado → directo a su home según rol
if (isLoggedIn()) {
    header('Location: ' . homePage());
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Por favor, ingresa tu usuario y contraseña.';
    } else {
        try {
            $db   = getDB();
            $stmt = $db->prepare(
                "SELECT * FROM usuarios WHERE email = ? AND estado = 'activo' LIMIT 1"
            );
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['contrasena'])) {
                setUserSession($user);
                header('Location: ' . homePage($user['tipo']));
                exit;
            } else {
                $error = 'Credenciales inválidas. Verifica tu usuario y contraseña.';
            }
        } catch (PDOException $e) {
            // [DEBUG] mostrar mensaje real — quitar en producción
            $error = 'Error de conexión a la base de datos: ' . $e->getMessage();
        }
    }
}

$bye = isset($_GET['bye']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:16px; font-size:14px; font-weight:500; }
        .alert-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .alert-success { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
    </style>
</head>
<body class="login-body">

    <main class="login-wrapper">
        <div class="login-card">

            <section class="login-left">
                <div class="large-logo-container">
                    <img src="images/duckyLogo.jpeg" alt="Logo Universidad Ducky" class="large-logo">
                </div>
                <div class="glass-panel">
                    <h2>Empowering Academic Excellence</h2>
                    <p>Secure access to Universidad Ducky's comprehensive library cataloguing and administrative tools. Built for speed, reliability, and precision.</p>
                </div>
            </section>

            <section class="login-right">
                <div class="brand-small">
                    <div class="brand-icon"><i class="fa-solid fa-book-open-reader"></i></div>
                    <span class="brand-text">Universidad Ducky</span>
                </div>

                <div class="login-header">
                    <h1>Admin Login</h1>
                    <p>Enter your credentials to access the system.</p>
                </div>

                <?php if ($bye): ?>
                    <div class="alert alert-success">
                        <i class="fa-solid fa-circle-check"></i> Sesión cerrada correctamente.
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-error">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="input-group">
                        <label for="username">Username</label>
                        <div class="input-wrapper">
                            <i class="fa-regular fa-user icon"></i>
                            <input type="email" id="username" name="username"
                                   placeholder="admin@ducky.edu"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="password">Password</label>
                        <div class="input-wrapper">
                            <i class="fa-solid fa-lock icon"></i>
                            <input type="password" id="password" name="password" placeholder="••••••••">
                        </div>
                    </div>

                    <div class="form-options">
                        <label class="checkbox-container">
                            <input type="checkbox" id="remember" name="remember">
                            <span class="checkmark"></span>
                            Remember me
                        </label>
                        <a href="forgotPassword.php" class="forgot-link">Forgot password?</a>
                    </div>

                    <button type="submit" class="btn-primary">
                        Login to Dashboard <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>

                <hr class="divider">

                <div class="system-admin">
                    <h3>SYSTEM ADMINISTRATION</h3>
                    <div class="admin-buttons">
                        <button class="btn-secondary"><i class="fa-solid fa-cloud-arrow-up"></i> Backup</button>
                        <button class="btn-secondary"><i class="fa-solid fa-clock-rotate-left"></i> Restore</button>
                    </div>
                </div>

                <div class="login-footer">
                    <p>ISO 9001:2015 Compliant System</p>
                </div>
            </section>

        </div>
    </main>

</body>
</html>
