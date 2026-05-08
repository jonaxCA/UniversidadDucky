<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Ya autenticado → dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$db        = getDB();
$submitted = false;
$error     = '';
$devLink   = '';   // solo se rellena en entornos localhost

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        // Buscar usuario activo (no revelar si el email existe o no)
        $stmt = $db->prepare("
            SELECT id_usuario, nombre_completo
            FROM usuarios
            WHERE email = ? AND estado = 'activo'
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Eliminar tokens previos sin usar para este usuario
            $db->prepare("DELETE FROM password_resets WHERE id_usuario = ? AND used = 0")
               ->execute([$user['id_usuario']]);

            $token  = bin2hex(random_bytes(32));            // 64 chars hex
            $expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

            $db->prepare("
                INSERT INTO password_resets (id_usuario, token, expiry)
                VALUES (?, ?, ?)
            ")->execute([$user['id_usuario'], $token, $expiry]);

            // Construir URL de reset
            $base     = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                      . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $path     = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $resetUrl = $base . $path . '/resetPassword.php?token=' . $token;

            // Intentar envío de correo
            $subject = 'Password reset — Universidad Ducky';
            $body    = "Hello {$user['nombre_completo']},\r\n\r\n"
                     . "We received a request to reset the password for your account.\r\n"
                     . "Click the link below — it is valid for 1 hour:\r\n\r\n"
                     . $resetUrl . "\r\n\r\n"
                     . "If you did not request this, you can safely ignore this email.\r\n\r\n"
                     . "— Universidad Ducky · Sistema Bibliotecario ISO 9001:2015";
            $headers = implode("\r\n", [
                'From: no-reply@ducky.edu.mx',
                'Reply-To: no-reply@ducky.edu.mx',
                'X-Mailer: PHP/' . PHP_VERSION,
                'Content-Type: text/plain; charset=UTF-8',
            ]);

            @mail($email, $subject, $body, $headers);

            // ── Modo desarrollo: mostrar el link si estamos en localhost ──────
            $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
            if (
                str_starts_with($host, 'localhost') ||
                str_starts_with($host, '127.')      ||
                $host === '::1'
            ) {
                $devLink = $resetUrl;
            }
        }

        $submitted = true;   // siempre mostrar éxito (evitar enumeración de emails)
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #0f3524 0%, #1a5c3a 50%, #0f3524 100%);
            padding: 24px;
        }

        .reset-card {
            background: #fff;
            border-radius: 20px;
            box-shadow: 0 24px 60px rgba(0,0,0,.25);
            width: 100%;
            max-width: 440px;
            padding: 48px 44px 40px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 32px;
        }
        .brand-icon {
            width: 38px; height: 38px;
            background: #0f3524;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 16px;
        }
        .brand-text {
            font-size: 15px; font-weight: 700; color: #1e293b;
        }

        h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin-bottom: 6px; }
        .subtitle { font-size: 14px; color: #6b7280; margin-bottom: 28px; line-height: 1.5; }

        .input-group { margin-bottom: 20px; }
        .input-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .input-wrap {
            position: relative;
            display: flex; align-items: center;
        }
        .input-wrap i {
            position: absolute; left: 14px;
            color: #9ca3af; font-size: 14px;
        }
        .input-wrap input {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px; font-family: inherit;
            outline: none; transition: border-color .2s;
        }
        .input-wrap input:focus { border-color: #0f3524; }

        .btn-submit {
            width: 100%;
            padding: 13px;
            background: #0f3524;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px; font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }
        .btn-submit:hover { background: #1a5c3a; }

        .back-link {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            margin-top: 20px;
            font-size: 13px; font-weight: 600;
            color: #6b7280; text-decoration: none;
        }
        .back-link:hover { color: #0f3524; }

        /* Alertas */
        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 16px; border-radius: 10px;
            margin-bottom: 20px; font-size: 13px; line-height: 1.5;
        }
        .alert i { margin-top: 1px; flex-shrink: 0; }
        .alert-error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }

        /* Caja de link de dev */
        .dev-box {
            margin-top: 16px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 12px;
            color: #92400e;
        }
        .dev-box strong { display: block; margin-bottom: 6px; }
        .dev-box a {
            word-break: break-all;
            color: #1d4ed8;
            font-family: 'Courier New', monospace;
            font-size: 11px;
        }
    </style>
</head>
<body>

    <div class="reset-card">

        <div class="brand">
            <img src="images/duckyNav.jpeg" alt="Universidad Ducky" class="nav-logo">
        </div>

        <?php if ($submitted): ?>

            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div>
                    <strong>Check your inbox</strong>
                    If that email is registered, you'll receive a password reset link shortly.
                    The link expires in <strong>1 hour</strong>.
                </div>
            </div>

            <?php if ($devLink): ?>
                <div class="dev-box">
                    <strong>🛠 Dev mode — reset link (remove in production):</strong>
                    <a href="<?= e($devLink) ?>"><?= e($devLink) ?></a>
                </div>
            <?php endif; ?>

            <a href="index.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Login
            </a>

        <?php else: ?>

            <h1>Forgot your password?</h1>
            <p class="subtitle">Enter your account email and we'll send you a link to reset your password.</p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="input-group">
                    <label for="email">Email address</label>
                    <div class="input-wrap">
                        <i class="fa-regular fa-envelope"></i>
                        <input type="email" id="email" name="email" autofocus
                               placeholder="you@ducky.edu.mx"
                               value="<?= e($_POST['email'] ?? '') ?>">
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fa-solid fa-paper-plane"></i> Send Reset Link
                </button>
            </form>

            <a href="index.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Login
            </a>

        <?php endif; ?>

    </div>

</body>
</html>
