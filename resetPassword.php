<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// Ya autenticado → dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit;
}

$db    = getDB();
$token = trim($_GET['token'] ?? '');

// ── Validar token ──────────────────────────────────────────────────────────
$resetRow = null;
$tokenError = '';

if ($token === '' || strlen($token) !== 64 || !ctype_xdigit($token)) {
    $tokenError = 'The reset link is invalid or malformed.';
} else {
    $stmt = $db->prepare("
        SELECT r.id, r.id_usuario, r.expiry, r.used, u.email, u.nombre_completo
        FROM   password_resets r
        JOIN   usuarios u ON u.id_usuario = r.id_usuario
        WHERE  r.token = ?
        LIMIT  1
    ");
    $stmt->execute([$token]);
    $resetRow = $stmt->fetch();

    if (!$resetRow) {
        $tokenError = 'This reset link does not exist or has already been used.';
    } elseif ($resetRow['used']) {
        $tokenError = 'This reset link has already been used. Please request a new one.';
    } elseif (strtotime($resetRow['expiry']) < time()) {
        $tokenError = 'This reset link has expired (valid for 1 hour). Please request a new one.';
    }
}

// ── Procesar nueva contraseña ──────────────────────────────────────────────
$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$tokenError && $resetRow) {
    $newPass    = $_POST['password']         ?? '';
    $confirmPass= $_POST['password_confirm'] ?? '';

    if (strlen($newPass) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $newPass)) {
        $error = 'Password must contain at least one uppercase letter.';
    } elseif (!preg_match('/[0-9]/', $newPass)) {
        $error = 'Password must contain at least one number.';
    } elseif ($newPass !== $confirmPass) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);

            // Actualizar contraseña y marcar token como usado en una transacción
            $db->beginTransaction();

            $db->prepare("UPDATE usuarios SET contrasena = ? WHERE id_usuario = ?")
               ->execute([$hash, $resetRow['id_usuario']]);

            $db->prepare("UPDATE password_resets SET used = 1 WHERE id = ?")
               ->execute([$resetRow['id']]);

            // Invalidar todos los demás tokens pendientes del mismo usuario
            $db->prepare("DELETE FROM password_resets WHERE id_usuario = ? AND used = 0")
               ->execute([$resetRow['id_usuario']]);

            $db->commit();

            logAction($db, $resetRow['id_usuario'], 'actualizar', 'usuarios',
                $resetRow['id_usuario'], ['accion' => 'password_reset_via_email'], 'seguridad');

            $success = true;

        } catch (PDOException $e) {
            $db->rollBack();
            $error = 'An error occurred. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password — Universidad Ducky</title>
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
            display: flex; align-items: center; gap: 10px;
            margin-bottom: 32px;
        }
        .brand-icon {
            width: 38px; height: 38px; background: #0f3524;
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 16px;
        }
        .brand-text { font-size: 15px; font-weight: 700; color: #1e293b; }

        h1 { font-size: 22px; font-weight: 700; color: #1e293b; margin-bottom: 6px; }
        .subtitle { font-size: 14px; color: #6b7280; margin-bottom: 28px; line-height: 1.5; }

        .input-group { margin-bottom: 18px; }
        .input-group label { display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px; }
        .input-wrap { position: relative; display: flex; align-items: center; }
        .input-wrap i.icon {
            position: absolute; left: 14px;
            color: #9ca3af; font-size: 14px; pointer-events: none;
        }
        .input-wrap input {
            width: 100%;
            padding: 11px 42px 11px 40px;
            border: 1.5px solid #e2e8f0;
            border-radius: 10px;
            font-size: 14px; font-family: inherit;
            outline: none; transition: border-color .2s;
        }
        .input-wrap input:focus { border-color: #0f3524; }
        .toggle-pw {
            position: absolute; right: 12px;
            background: none; border: none; cursor: pointer;
            color: #9ca3af; font-size: 14px; padding: 0;
        }
        .toggle-pw:hover { color: #374151; }

        /* Indicador de fortaleza */
        .strength-bar {
            height: 4px; border-radius: 4px; background: #e2e8f0;
            margin-top: 8px; overflow: hidden;
        }
        .strength-fill {
            height: 100%; border-radius: 4px; width: 0;
            transition: width .3s, background .3s;
        }
        .strength-hint { font-size: 11px; color: #6b7280; margin-top: 4px; }

        .requirements {
            font-size: 11px; color: #6b7280; line-height: 1.8;
            margin-top: 6px; padding-left: 4px;
        }
        .req-item { display: flex; align-items: center; gap: 6px; }
        .req-item i { font-size: 10px; }
        .req-ok  { color: #16a34a; }
        .req-bad { color: #9ca3af; }

        .btn-submit {
            width: 100%; padding: 13px;
            background: #0f3524; color: #fff;
            border: none; border-radius: 10px;
            font-size: 15px; font-weight: 600;
            cursor: pointer; transition: background .2s;
            display: flex; align-items: center; justify-content: center; gap: 8px;
            margin-top: 8px;
        }
        .btn-submit:hover { background: #1a5c3a; }
        .btn-submit:disabled { background: #94a3b8; cursor: not-allowed; }

        .back-link {
            display: flex; align-items: center; justify-content: center; gap: 6px;
            margin-top: 20px; font-size: 13px; font-weight: 600;
            color: #6b7280; text-decoration: none;
        }
        .back-link:hover { color: #0f3524; }

        .alert {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 16px; border-radius: 10px;
            margin-bottom: 20px; font-size: 13px; line-height: 1.5;
        }
        .alert i { margin-top: 1px; flex-shrink: 0; }
        .alert-error   { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
        .alert-success { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
    </style>
</head>
<body>

    <div class="reset-card">

        <div class="brand">
            <img src="images/duckyNav.jpeg" alt="Universidad Ducky" class="nav-logo">
        </div>

        <?php if ($tokenError): ?>

            <!-- Token inválido / expirado -->
            <h1>Invalid Link</h1>
            <div class="alert alert-warning" style="margin-top:8px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= e($tokenError) ?>
            </div>
            <a href="forgotPassword.php" class="btn-submit" style="text-decoration:none;">
                <i class="fa-solid fa-rotate-right"></i> Request a New Link
            </a>
            <a href="index.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Login
            </a>

        <?php elseif ($success): ?>

            <!-- Contraseña cambiada con éxito -->
            <div style="text-align:center;padding:8px 0 20px;">
                <div style="width:64px;height:64px;background:#dcfce7;border-radius:50%;
                             display:flex;align-items:center;justify-content:center;
                             margin:0 auto 16px;font-size:28px;color:#16a34a;">
                    <i class="fa-solid fa-circle-check"></i>
                </div>
                <h1 style="margin-bottom:6px;">Password Updated!</h1>
                <p class="subtitle" style="margin-bottom:0;">
                    Your password has been changed successfully.<br>
                    You can now log in with your new credentials.
                </p>
            </div>
            <a href="index.php" class="btn-submit" style="text-decoration:none;">
                <i class="fa-solid fa-right-to-bracket"></i> Go to Login
            </a>

        <?php else: ?>

            <!-- Formulario de nueva contraseña -->
            <h1>Set New Password</h1>
            <p class="subtitle">
                For <strong><?= e($resetRow['email']) ?></strong>.<br>
                Choose a strong password for your account.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="resetPassword.php?token=<?= urlencode($token) ?>" id="resetForm">

                <div class="input-group">
                    <label for="password">New Password</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock icon"></i>
                        <input type="password" id="password" name="password"
                               placeholder="Min. 8 characters" autocomplete="new-password">
                        <button type="button" class="toggle-pw" data-target="password" tabindex="-1">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <div class="strength-hint" id="strengthHint"></div>
                    <div class="requirements" id="reqList">
                        <div class="req-item" id="req-len">
                            <i class="fa-solid fa-circle req-bad"></i> At least 8 characters
                        </div>
                        <div class="req-item" id="req-upper">
                            <i class="fa-solid fa-circle req-bad"></i> One uppercase letter
                        </div>
                        <div class="req-item" id="req-num">
                            <i class="fa-solid fa-circle req-bad"></i> One number
                        </div>
                    </div>
                </div>

                <div class="input-group">
                    <label for="password_confirm">Confirm Password</label>
                    <div class="input-wrap">
                        <i class="fa-solid fa-lock icon"></i>
                        <input type="password" id="password_confirm" name="password_confirm"
                               placeholder="Repeat your password" autocomplete="new-password">
                        <button type="button" class="toggle-pw" data-target="password_confirm" tabindex="-1">
                            <i class="fa-regular fa-eye"></i>
                        </button>
                    </div>
                    <div class="requirements" id="matchHint" style="display:none;">
                        <div class="req-item" id="req-match">
                            <i class="fa-solid fa-circle req-bad"></i> Passwords match
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn" disabled>
                    <i class="fa-solid fa-key"></i> Update Password
                </button>
            </form>

            <a href="index.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Back to Login
            </a>

        <?php endif; ?>

    </div>

    <script>
    // ── Toggle mostrar/ocultar contraseña ──────────────────────────────────────
    document.querySelectorAll('.toggle-pw').forEach(btn => {
        btn.addEventListener('click', () => {
            const inp = document.getElementById(btn.dataset.target);
            const ico = btn.querySelector('i');
            if (inp.type === 'password') {
                inp.type = 'text';
                ico.className = 'fa-regular fa-eye-slash';
            } else {
                inp.type = 'password';
                ico.className = 'fa-regular fa-eye';
            }
        });
    });

    // ── Indicador de fortaleza y validación en vivo ───────────────────────────
    const pwInput   = document.getElementById('password');
    const cfInput   = document.getElementById('password_confirm');
    const fill      = document.getElementById('strengthFill');
    const hint      = document.getElementById('strengthHint');
    const submitBtn = document.getElementById('submitBtn');

    const reqLen   = document.getElementById('req-len');
    const reqUpper = document.getElementById('req-upper');
    const reqNum   = document.getElementById('req-num');
    const reqMatch = document.getElementById('req-match');
    const matchHint= document.getElementById('matchHint');

    function setReq(el, ok) {
        el.querySelector('i').className = ok
            ? 'fa-solid fa-circle-check req-ok'
            : 'fa-solid fa-circle req-bad';
        el.style.color = ok ? '#16a34a' : '#6b7280';
    }

    function evaluate() {
        const pw  = pwInput.value;
        const cf  = cfInput.value;
        const len   = pw.length >= 8;
        const upper = /[A-Z]/.test(pw);
        const num   = /[0-9]/.test(pw);
        const match = pw !== '' && pw === cf;

        setReq(reqLen,   len);
        setReq(reqUpper, upper);
        setReq(reqNum,   num);

        if (cf.length > 0) {
            matchHint.style.display = 'block';
            setReq(reqMatch, match);
        }

        // Barra de fortaleza (0–4)
        const score = [len, upper, num, pw.length >= 12].filter(Boolean).length;
        const pct   = score * 25;
        const colors = ['', '#ef4444', '#f97316', '#eab308', '#16a34a'];
        const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        fill.style.width      = pct + '%';
        fill.style.background = colors[score] || '#e2e8f0';
        hint.textContent      = pw.length > 0 ? labels[score] : '';
        hint.style.color      = colors[score] || '#6b7280';

        submitBtn.disabled = !(len && upper && num && match);
    }

    if (pwInput) {
        pwInput.addEventListener('input', evaluate);
        cfInput.addEventListener('input', evaluate);
    }
    </script>

</body>
</html>
