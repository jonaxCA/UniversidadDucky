<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador']);

$me     = currentUser();
$error  = '';
$old    = [];  // valores previos para repoblar el form tras error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $nombre   = trim($_POST['fullName']    ?? '');
    $email    = trim($_POST['email']       ?? '');
    $telefono = trim($_POST['phone']       ?? '');
    $username = trim($_POST['newUsername'] ?? '');
    $password = $_POST['newPassword']      ?? '';
    $role     = $_POST['userRole']         ?? '';

    $validRoles = ['alumno', 'bibliotecario', 'administrador', 'profesor'];

    // ── Validaciones ─────────────────────────────────────────────────────────
    if (!$nombre || !$email || !$password) {
        $error = 'Nombre, email y contraseña son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no tiene un formato válido.';
    } elseif (!in_array($role, $validRoles, true)) {
        $error = 'Selecciona un rol válido.';
    } elseif (strlen($password) < 8) {
        $error = 'La contraseña debe tener mínimo 8 caracteres.';
    } else {
        try {
            $db = getDB();

            // Verificar email único
            $check = $db->prepare("SELECT id_usuario FROM usuarios WHERE email = ? LIMIT 1");
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'Ya existe un usuario registrado con ese correo electrónico.';
            }

            // Verificar username único (si se proporcionó)
            if (!$error && $username !== '') {
                $checkU = $db->prepare("SELECT id_usuario FROM usuarios WHERE username = ? LIMIT 1");
                $checkU->execute([$username]);
                if ($checkU->fetch()) {
                    $error = 'Ese nombre de usuario ya está en uso.';
                }
            }

            if (!$error) {
                $hash  = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $stmt  = $db->prepare("
                    INSERT INTO usuarios
                        (nombre_completo, username, email, telefono, contrasena, tipo)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $nombre,
                    $username ?: null,
                    $email,
                    $telefono ?: null,
                    $hash,
                    $role,
                ]);
                $newId = (int) $db->lastInsertId();

                logAction($db, $me['id'], 'crear', 'usuarios', $newId, [
                    'nombre' => $nombre,
                    'email'  => $email,
                    'tipo'   => $role,
                ], 'usuarios');

                header('Location: dashboard.php?success=user_created');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Error al guardar el usuario. Intenta de nuevo.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add User - Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
    </style>
</head>
<body class="dashboard-body">

    <header class="top-navbar">
        <div class="logo-area">
            <img src="images/duckyNav.jpeg" alt="Universidad Ducky" class="nav-logo">
        </div>
        <nav class="top-nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="catalogSettings.php">Catalog</a>
            <a href="transactions.php">Loans</a>
            <?php if (in_array($me['tipo'], ['administrador','bibliotecario'], true)): ?>
                <a href="multas.php">Fines</a>
                <a href="dashboard.php" class="active">Users</a>
            <?php endif; ?>
            <?php if ($me['tipo'] === 'administrador'): ?>
                <a href="settings.php">Settings</a>
            <?php endif; ?>
        </nav>
        <div class="user-profile" style="display:flex;align-items:center;gap:12px;">
            <a href="perfilUsuario.php" title="My Profile">
                <img src="https://ui-avatars.com/api/?name=<?= urlencode($me['nombre']) ?>&background=random"
                     alt="Profile" class="avatar-img">
            </a>
            <a href="logout.php" style="color:inherit;opacity:.6;font-size:18px;" title="Salir">
                <i class="fa-solid fa-right-from-bracket"></i>
            </a>
        </div>
    </header>

    <main class="form-page-container">

        <div class="form-header-area">
            <a href="dashboard.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to User List</a>
            <h1>Create New User</h1>
            <p>Register a new staff member or student to the library system.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="">

                <div class="form-section">
                    <h3 class="section-title"><i class="fa-regular fa-user"></i> Personal Information</h3>
                    <div class="input-grid full-width">
                        <div class="input-group">
                            <label for="fullName">Full Name</label>
                            <input type="text" id="fullName" name="fullName" class="base-input"
                                   placeholder="e.g. John Doe"
                                   value="<?= e($old['fullName'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" class="base-input"
                                   placeholder="john.doe@university.edu"
                                   value="<?= e($old['email'] ?? '') ?>">
                        </div>
                        <div class="input-group">
                            <label for="phone">Phone Number</label>
                            <input type="text" id="phone" name="phone" class="base-input"
                                   placeholder="+52 81 1234 5678"
                                   value="<?= e($old['phone'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="section-title"><i class="fa-solid fa-lock"></i> Account Details</h3>
                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="newUsername">Username <span style="font-weight:400;color:#6b7280;">(opcional)</span></label>
                            <input type="text" id="newUsername" name="newUsername" class="base-input"
                                   placeholder="jdoe_admin"
                                   value="<?= e($old['newUsername'] ?? '') ?>">
                        </div>
                        <div class="input-group">
                            <label for="newPassword">Password <span style="font-weight:400;color:#6b7280;">(mín. 8 chars)</span></label>
                            <input type="password" id="newPassword" name="newPassword" class="base-input"
                                   placeholder="••••••••">
                        </div>
                    </div>
                </div>

                <div class="role-permission-grid">

                    <div class="roles-column">
                        <h3 class="section-title"><i class="fa-solid fa-id-badge"></i> Role Assignment</h3>

                        <?php
                        $selectedRole = $old['userRole'] ?? 'alumno';
                        $roles = [
                            'alumno'        => ['Student',   'Standard library access & borrowing'],
                            'bibliotecario' => ['Librarian', 'Catalog management & user assistance'],
                            'profesor'      => ['Professor', 'Extended borrowing & reservation rights'],
                            'administrador' => ['System Admin', 'Full control over users & configuration'],
                        ];
                        foreach ($roles as $val => [$label, $desc]):
                        ?>
                        <label class="role-card">
                            <input type="radio" name="userRole" value="<?= $val ?>"
                                   <?= $selectedRole === $val ? 'checked' : '' ?>>
                            <div class="role-content">
                                <span class="role-custom-radio"></span>
                                <div>
                                    <h4><?= $label ?></h4>
                                    <p><?= $desc ?></p>
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="permissions-column">
                        <h3 class="section-title"><i class="fa-regular fa-circle-check"></i> Permissions</h3>

                        <label class="permission-item" id="permCatalogWrap">
                            <input type="checkbox" id="permCatalog">
                            <div class="perm-content">
                                <h4>Manage Catalog</h4>
                                <p>Add, edit, or remove library assets.</p>
                            </div>
                        </label>

                        <label class="permission-item" id="permUsersWrap">
                            <input type="checkbox" id="permUsers">
                            <div class="perm-content">
                                <h4>Manage Users</h4>
                                <p>Invite new members and edit roles.</p>
                            </div>
                        </label>

                        <label class="permission-item" id="permReportsWrap">
                            <input type="checkbox" id="permReports">
                            <div class="perm-content">
                                <h4>View Reports</h4>
                                <p>Access analytics and borrowing history data.</p>
                            </div>
                        </label>
                    </div>

                </div>

                <div class="form-actions">
                    <a href="dashboard.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-create">Create User</button>
                </div>

            </form>
        </div>

        <p class="footer-note">El usuario podrá iniciar sesión inmediatamente después de crearse.</p>

    </main>

    <script>
    (function () {
        const roleRadios     = document.querySelectorAll('input[name="userRole"]');
        const permCatalogWrap = document.getElementById('permCatalogWrap');
        const permUsersWrap   = document.getElementById('permUsersWrap');
        const permReportsWrap = document.getElementById('permReportsWrap');
        const chkCatalog      = document.getElementById('permCatalog');
        const chkUsers        = document.getElementById('permUsers');
        const chkReports      = document.getElementById('permReports');

        function updatePerms(role) {
            [chkCatalog, chkUsers, chkReports].forEach(c => { c.disabled = false; c.checked = false; });
            [permCatalogWrap, permUsersWrap, permReportsWrap].forEach(w => w.classList.remove('disabled'));

            if (role === 'alumno') {
                [chkCatalog, chkUsers, chkReports].forEach(c => { c.disabled = true; });
                [permCatalogWrap, permUsersWrap, permReportsWrap].forEach(w => w.classList.add('disabled'));
            } else if (role === 'bibliotecario') {
                chkCatalog.checked = true;
                chkUsers.disabled  = true;
                permUsersWrap.classList.add('disabled');
            } else if (role === 'profesor') {
                chkReports.checked = true;
                chkCatalog.disabled = true;
                chkUsers.disabled   = true;
                permCatalogWrap.classList.add('disabled');
                permUsersWrap.classList.add('disabled');
            } else if (role === 'administrador') {
                chkCatalog.checked = true;
                chkUsers.checked   = true;
                chkReports.checked = true;
            }
        }

        roleRadios.forEach(r => r.addEventListener('change', e => updatePerms(e.target.value)));
        const checked = document.querySelector('input[name="userRole"]:checked');
        if (checked) updatePerms(checked.value);
    })();
    </script>
</body>
</html>
