<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador']);

$me    = currentUser();
$db    = getDB();
$error = '';

// ── Cargar usuario a editar ──────────────────────────────────────────────────
$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$userStmt = $db->prepare("SELECT * FROM usuarios WHERE id_usuario = ? LIMIT 1");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    header('Location: dashboard.php?error=not_found');
    exit;
}

// ── Manejar POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre   = trim($_POST['fullName']    ?? '');
    $email    = trim($_POST['email']       ?? '');
    $telefono = trim($_POST['phone']       ?? '');
    $username = trim($_POST['newUsername'] ?? '');
    $password = $_POST['newPassword']      ?? '';
    $role     = $_POST['userRole']         ?? '';
    $estado   = $_POST['userStatus']       ?? 'activo';

    $validRoles   = ['alumno', 'bibliotecario', 'administrador', 'profesor'];
    $validEstados = ['activo', 'suspendido', 'bloqueado'];

    if (!$nombre || !$email) {
        $error = 'Nombre y email son obligatorios.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'El email no tiene un formato válido.';
    } elseif (!in_array($role, $validRoles, true)) {
        $error = 'Selecciona un rol válido.';
    } elseif (!in_array($estado, $validEstados, true)) {
        $error = 'Estado de cuenta inválido.';
    } elseif ($password !== '' && strlen($password) < 8) {
        $error = 'La contraseña debe tener mínimo 8 caracteres.';
    } else {
        try {
            // Verificar email único (excluyendo el usuario actual)
            $checkEmail = $db->prepare(
                "SELECT id_usuario FROM usuarios WHERE email = ? AND id_usuario != ? LIMIT 1"
            );
            $checkEmail->execute([$email, $userId]);
            if ($checkEmail->fetch()) {
                $error = 'Ese correo ya está en uso por otro usuario.';
            }

            // Verificar username único (si se proporcionó)
            if (!$error && $username !== '') {
                $checkU = $db->prepare(
                    "SELECT id_usuario FROM usuarios WHERE username = ? AND id_usuario != ? LIMIT 1"
                );
                $checkU->execute([$username, $userId]);
                if ($checkU->fetch()) {
                    $error = 'Ese nombre de usuario ya está en uso.';
                }
            }

            if (!$error) {
                $before = [
                    'nombre'   => $user['nombre_completo'],
                    'email'    => $user['email'],
                    'tipo'     => $user['tipo'],
                    'estado'   => $user['estado'],
                ];

                if ($password !== '') {
                    // Actualizar con nueva contraseña
                    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                    $stmt = $db->prepare("
                        UPDATE usuarios
                        SET nombre_completo = ?, username = ?, email = ?,
                            telefono = ?, contrasena = ?, tipo = ?, estado = ?
                        WHERE id_usuario = ?
                    ");
                    $stmt->execute([
                        $nombre,
                        $username ?: null,
                        $email,
                        $telefono ?: null,
                        $hash,
                        $role,
                        $estado,
                        $userId,
                    ]);
                } else {
                    // Sin cambio de contraseña
                    $stmt = $db->prepare("
                        UPDATE usuarios
                        SET nombre_completo = ?, username = ?, email = ?,
                            telefono = ?, tipo = ?, estado = ?
                        WHERE id_usuario = ?
                    ");
                    $stmt->execute([
                        $nombre,
                        $username ?: null,
                        $email,
                        $telefono ?: null,
                        $role,
                        $estado,
                        $userId,
                    ]);
                }

                logAction($db, $me['id'], 'actualizar', 'usuarios', $userId, [
                    'antes'  => $before,
                    'despues' => ['nombre' => $nombre, 'email' => $email,
                                  'tipo' => $role, 'estado' => $estado],
                ], 'usuarios');

                header('Location: dashboard.php?success=user_updated');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Error al actualizar el usuario. Intenta de nuevo.';
        }
    }

    // Si hubo error, actualizar $user con los valores del POST para repoblar el form
    $user = array_merge($user, [
        'nombre_completo' => $nombre,
        'email'           => $email,
        'telefono'        => $telefono,
        'username'        => $username,
        'tipo'            => $role,
        'estado'          => $estado,
    ]);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit User - Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .user-id-badge { display:inline-block; background:#ede9fe; color:#7c3aed;
                         font-size:12px; font-weight:600; padding:3px 10px;
                         border-radius:20px; margin-left:10px; vertical-align:middle; }
        .status-section { margin-top: 28px; }
        .status-options { display:flex; gap:16px; margin-top:12px; flex-wrap:wrap; }
        .status-card { display:flex; align-items:center; gap:10px; padding:14px 20px;
                       border:2px solid #e5e7eb; border-radius:10px; cursor:pointer;
                       transition: border-color .2s; flex:1; min-width:140px; }
        .status-card:has(input:checked) { border-color:#4f46e5; background:#f5f3ff; }
        .status-card input { accent-color:#4f46e5; width:16px; height:16px; }
        .status-dot-inline { width:10px; height:10px; border-radius:50%; display:inline-block; }
        .dot-activo     { background:#22c55e; }
        .dot-suspendido { background:#f59e0b; }
        .dot-bloqueado  { background:#ef4444; }
        .btn-danger { background:#fef2f2; color:#dc2626; border:1px solid #fecaca;
                      padding:10px 20px; border-radius:8px; font-weight:600;
                      cursor:pointer; font-size:14px; transition:.2s; }
        .btn-danger:hover { background:#fee2e2; }
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
            <h1>
                Edit User
                <span class="user-id-badge">#USR-<?= str_pad($userId, 4, '0', STR_PAD_LEFT) ?></span>
            </h1>
            <p>Modifica los datos de <strong><?= e($user['nombre_completo']) ?></strong>.</p>
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
                                   value="<?= e($user['nombre_completo']) ?>">
                        </div>
                    </div>
                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="email">Email Address</label>
                            <input type="email" id="email" name="email" class="base-input"
                                   value="<?= e($user['email']) ?>">
                        </div>
                        <div class="input-group">
                            <label for="phone">Phone Number</label>
                            <input type="text" id="phone" name="phone" class="base-input"
                                   value="<?= e($user['telefono'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3 class="section-title"><i class="fa-solid fa-lock"></i> Account Details</h3>
                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="newUsername">Username</label>
                            <input type="text" id="newUsername" name="newUsername" class="base-input"
                                   value="<?= e($user['username'] ?? '') ?>">
                        </div>
                        <div class="input-group">
                            <label for="newPassword">
                                New Password
                                <span style="font-weight:400;color:#6b7280;">(dejar vacío para no cambiar)</span>
                            </label>
                            <input type="password" id="newPassword" name="newPassword"
                                   class="base-input" placeholder="••••••••">
                        </div>
                    </div>
                </div>

                <div class="role-permission-grid">

                    <div class="roles-column">
                        <h3 class="section-title"><i class="fa-solid fa-id-badge"></i> Role Assignment</h3>

                        <?php
                        $roles = [
                            'alumno'        => ['Student',      'Standard library access & borrowing'],
                            'bibliotecario' => ['Librarian',    'Catalog management & user assistance'],
                            'profesor'      => ['Professor',    'Extended borrowing & reservation rights'],
                            'administrador' => ['System Admin', 'Full control over users & configuration'],
                        ];
                        foreach ($roles as $val => [$label, $desc]):
                        ?>
                        <label class="role-card">
                            <input type="radio" name="userRole" value="<?= $val ?>"
                                   <?= $user['tipo'] === $val ? 'checked' : '' ?>>
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

                <!-- Account Status -->
                <div class="status-section">
                    <h3 class="section-title"><i class="fa-solid fa-circle-half-stroke"></i> Account Status</h3>
                    <div class="status-options">
                        <?php
                        $statuses = [
                            'activo'     => ['Active',     'dot-activo',     'El usuario puede iniciar sesión normalmente.'],
                            'suspendido' => ['Suspended',  'dot-suspendido', 'El usuario no puede solicitar préstamos.'],
                            'bloqueado'  => ['Blocked',    'dot-bloqueado',  'El usuario no puede iniciar sesión.'],
                        ];
                        foreach ($statuses as $val => [$label, $dotClass, $desc]):
                        ?>
                        <label class="status-card">
                            <input type="radio" name="userStatus" value="<?= $val ?>"
                                   <?= $user['estado'] === $val ? 'checked' : '' ?>>
                            <span class="status-dot-inline <?= $dotClass ?>"></span>
                            <div>
                                <strong><?= $label ?></strong>
                                <div style="font-size:12px;color:#6b7280;"><?= $desc ?></div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions" style="margin-top:32px;">
                    <a href="dashboard.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-create">Save Changes</button>
                </div>

            </form>
        </div>

        <p class="footer-note">
            Registrado el <?= date('d/m/Y', strtotime($user['creado_en'])) ?>.
            Los cambios quedan registrados en la bitácora ISO 9001.
        </p>

    </main>

    <script>
    (function () {
        const roleRadios      = document.querySelectorAll('input[name="userRole"]');
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
                chkReports.checked  = true;
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
