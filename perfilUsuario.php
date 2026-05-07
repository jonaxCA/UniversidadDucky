<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$me   = currentUser();
$db   = getDB();
$uid  = $me['id'];

$error = '';
$exito = '';

// Cargar datos completos del usuario
$stmt = $db->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
$stmt->execute([$uid]);
$usuario = $stmt->fetch();

if (!$usuario) {
    destroySession();
    header('Location: index.php');
    exit;
}

// ── POST: actualizar perfil ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['_action'] ?? 'update_profile';

    if ($action === 'update_profile') {
        $nombre   = trim($_POST['nombre']   ?? '');
        $email    = trim($_POST['email']    ?? '');
        $telefono = trim($_POST['telefono'] ?? '');

        if (!$nombre || !$email) {
            $error = 'Name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            // Verificar email único (excepto el propio)
            $chk = $db->prepare("SELECT id_usuario FROM usuarios WHERE email = ? AND id_usuario != ?");
            $chk->execute([$email, $uid]);
            if ($chk->fetch()) {
                $error = 'That email is already used by another account.';
            } else {
                $db->prepare("
                    UPDATE usuarios
                    SET nombre_completo = ?, email = ?, telefono = ?
                    WHERE id_usuario = ?
                ")->execute([$nombre, $email, $telefono ?: null, $uid]);

                // Actualizar sesión
                $_SESSION['user']['nombre'] = $nombre;
                $_SESSION['user']['email']  = $email;

                logAction($db, $uid, 'actualizar', 'usuarios', $uid, [
                    'campos' => ['nombre_completo', 'email', 'telefono'],
                ], 'perfil');

                $exito = 'Profile updated successfully.';
                $usuario['nombre_completo'] = $nombre;
                $usuario['email']           = $email;
                $usuario['telefono']        = $telefono;
            }
        }

    } elseif ($action === 'change_password') {
        $actual  = $_POST['password_actual']   ?? '';
        $nueva   = $_POST['password_nueva']    ?? '';
        $confirm = $_POST['password_confirm']  ?? '';

        if (!$actual || !$nueva || !$confirm) {
            $error = 'All password fields are required.';
        } elseif (!password_verify($actual, $usuario['contrasena'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($nueva) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($nueva !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            $hash = password_hash($nueva, PASSWORD_BCRYPT, ['cost' => 12]);
            $db->prepare("UPDATE usuarios SET contrasena = ? WHERE id_usuario = ?")
               ->execute([$hash, $uid]);

            logAction($db, $uid, 'actualizar', 'usuarios', $uid, [
                'campo' => 'contrasena',
            ], 'perfil');

            $exito = 'Password changed successfully.';
        }
    }
}

// ── Préstamos activos del usuario ─────────────────────────────────────────────
$prestamosActivos = $db->prepare("
    SELECT p.id_prestamo, p.folio_recibo, p.estado,
           p.fecha_salida, p.fecha_vencimiento,
           l.titulo, l.id_libro,
           e.codigo_inventario
    FROM   prestamos p
    JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN   libros    l ON e.id_libro    = l.id_libro
    WHERE  p.id_usuario = ?
      AND  p.estado IN ('activo','vencido')
    ORDER  BY p.fecha_vencimiento ASC
    LIMIT  10
");
$prestamosActivos->execute([$uid]);
$activos = $prestamosActivos->fetchAll();

// ── Multas pendientes ─────────────────────────────────────────────────────────
$multasPend = $db->prepare("
    SELECT m.id_multa, m.monto_total, m.tipo_mora, m.creado_en,
           p.folio_recibo, l.titulo
    FROM   multas m
    JOIN   prestamos p ON m.id_prestamo = p.id_prestamo
    JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN   libros    l ON e.id_libro    = l.id_libro
    WHERE  p.id_usuario = ? AND m.estado_pago = 0
    ORDER  BY m.creado_en DESC
");
$multasPend->execute([$uid]);
$pendientes = $multasPend->fetchAll();

// ── Historial reciente (últimos 5 préstamos) ──────────────────────────────────
$historial = $db->prepare("
    SELECT p.id_prestamo, p.folio_recibo, p.estado,
           p.fecha_salida, p.fecha_devolucion,
           l.titulo, l.id_libro
    FROM   prestamos p
    JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN   libros    l ON e.id_libro    = l.id_libro
    WHERE  p.id_usuario = ?
    ORDER  BY p.fecha_salida DESC
    LIMIT  5
");
$historial->execute([$uid]);
$recientes = $historial->fetchAll();

$stm = $db->prepare("
    SELECT COALESCE(SUM(m.monto_total),0)
    FROM multas m
    JOIN prestamos p ON m.id_prestamo = p.id_prestamo
    WHERE p.id_usuario = ? AND m.estado_pago = 0
");
$stm->execute([$uid]);
$totalMultasPend = (float) $stm->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-ok    { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

        .profile-layout { display:grid; grid-template-columns:280px 1fr; gap:24px; }
        @media(max-width:768px){ .profile-layout { grid-template-columns:1fr; } }

        /* Sidebar */
        .profile-sidebar { display:flex; flex-direction:column; gap:16px; }
        .profile-card {
            background:#fff; border:1px solid #e2e8f0; border-radius:12px;
            overflow:hidden;
        }
        .avatar-section {
            background:#0f3524; padding:28px 20px; text-align:center;
        }
        .avatar-circle {
            width:72px; height:72px; border-radius:50%;
            background:rgba(255,255,255,.2); display:inline-flex;
            align-items:center; justify-content:center;
            font-size:28px; font-weight:700; color:#fff; margin-bottom:12px;
        }
        .avatar-name { color:#fff; font-size:16px; font-weight:700; }
        .avatar-role { color:rgba(255,255,255,.7); font-size:12px; margin-top:4px; }

        .profile-meta { padding:16px 20px; }
        .meta-item { display:flex; align-items:center; gap:10px; padding:8px 0;
                     border-bottom:1px solid #f1f5f9; font-size:13px; }
        .meta-item:last-child { border-bottom:none; }
        .meta-item i { width:16px; color:#6b7280; font-size:13px; }
        .meta-item span { color:#374151; }

        .stats-mini { padding:16px 20px; border-top:1px solid #f1f5f9; }
        .sm-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .sm-item { text-align:center; }
        .sm-num  { font-size:20px; font-weight:700; color:#1e293b; }
        .sm-lbl  { font-size:11px; color:#6b7280; margin-top:2px; }

        /* Main content */
        .profile-main { display:flex; flex-direction:column; gap:20px; }
        .section-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .sc-header { padding:14px 20px; border-bottom:1px solid #f1f5f9;
                     display:flex; align-items:center; gap:10px; }
        .sc-header h3 { font-size:15px; font-weight:700; color:#1e293b; }
        .sc-icon { width:30px; height:30px; border-radius:7px;
                   display:flex; align-items:center; justify-content:center; font-size:13px; }
        .sci-green  { background:#dcfce7; color:#16a34a; }
        .sci-blue   { background:#dbeafe; color:#1d4ed8; }
        .sci-red    { background:#fee2e2; color:#dc2626; }
        .sci-slate  { background:#f1f5f9; color:#475569; }

        .sc-body { padding:20px; }

        /* Forms inside cards */
        .form-2col { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
        @media(max-width:640px){ .form-2col { grid-template-columns:1fr; } }

        /* Mini table */
        table.mini-t { width:100%; border-collapse:collapse; font-size:13px; }
        table.mini-t thead th { padding:8px 12px; background:#f8fafc; text-align:left;
            font-size:10px; font-weight:700; letter-spacing:.8px; text-transform:uppercase;
            color:#94a3b8; border-bottom:1px solid #e2e8f0; }
        table.mini-t tbody tr { border-bottom:1px solid #f1f5f9; }
        table.mini-t tbody tr:last-child { border-bottom:none; }
        table.mini-t tbody tr:hover { background:#fafbfc; }
        table.mini-t td { padding:9px 12px; vertical-align:middle; }

        .lb { display:inline-flex; align-items:center; gap:5px;
              padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
        .lb-dot { width:6px; height:6px; border-radius:50%; }
        .loan-active  { background:#dcfce7; color:#15803d; }
        .loan-active .lb-dot { background:#16a34a; }
        .loan-overdue { background:#fef9c3; color:#92400e; }
        .loan-overdue .lb-dot { background:#b45309; }
        .loan-returned{ background:#f1f5f9; color:#475569; }
        .loan-returned .lb-dot { background:#64748b; }

        .empty-mini { padding:20px; text-align:center; color:#94a3b8; font-size:13px; }

        /* Fine total banner */
        .fine-banner {
            background:#fef2f2; border:1px solid #fecaca; border-radius:8px;
            padding:12px 16px; display:flex; align-items:center; gap:12px;
            margin-bottom:16px; font-size:14px; font-weight:600; color:#dc2626;
        }
    </style>
</head>
<body class="dashboard-body">

    <header class="top-navbar">
        <div class="logo-area">
            <h2 class="text-logo"><i class="fa-solid fa-book-open-reader"></i> DUCKY <span>UNIVERSIDAD</span></h2>
        </div>
        <nav class="top-nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="catalogSettings.php">Catalog</a>
            <a href="transactions.php">Loans</a>
            <?php if (in_array($me['tipo'], ['administrador','bibliotecario'], true)): ?>
                <a href="multas.php">Fines</a>
                <a href="dashboard.php">Users</a>
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

    <main style="max-width:1100px;margin:0 auto;padding:32px 20px;">

        <div style="margin-bottom:24px;">
            <h1 style="font-size:24px;font-weight:700;color:#1e293b;margin-bottom:4px;">My Profile</h1>
            <p style="font-size:14px;color:#6b7280;">Manage your personal information and password.</p>
        </div>

        <?php if ($exito): ?>
            <div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= e($exito) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <div class="profile-layout">

            <!-- ── Sidebar ─────────────────────────────────────────────────── -->
            <div class="profile-sidebar">
                <div class="profile-card">
                    <div class="avatar-section">
                        <div class="avatar-circle"><?= e(getInitials($usuario['nombre_completo'])) ?></div>
                        <div class="avatar-name"><?= e($usuario['nombre_completo']) ?></div>
                        <div class="avatar-role"><?= tipoLabel($usuario['tipo']) ?></div>
                    </div>
                    <div class="profile-meta">
                        <div class="meta-item">
                            <i class="fa-solid fa-envelope"></i>
                            <span><?= e($usuario['email']) ?></span>
                        </div>
                        <?php if ($usuario['telefono']): ?>
                        <div class="meta-item">
                            <i class="fa-solid fa-phone"></i>
                            <span><?= e($usuario['telefono']) ?></span>
                        </div>
                        <?php endif; ?>
                        <div class="meta-item">
                            <i class="fa-solid fa-calendar"></i>
                            <span>Since <?= date('M Y', strtotime($usuario['creado_en'])) ?></span>
                        </div>
                        <div class="meta-item">
                            <i class="fa-solid fa-circle" style="color:<?= $usuario['estado'] === 'activo' ? '#16a34a' : '#dc2626' ?>;font-size:9px;"></i>
                            <span style="text-transform:capitalize;"><?= e($usuario['estado']) ?></span>
                        </div>
                    </div>
                    <?php
                    $stTot = $db->prepare("SELECT COUNT(*) FROM prestamos WHERE id_usuario = ?");
                    $stTot->execute([$uid]);
                    $totalPrest = (int)$stTot->fetchColumn();
                    ?>
                    <div class="stats-mini">
                        <div class="sm-grid">
                            <div class="sm-item">
                                <div class="sm-num"><?= $totalPrest ?></div>
                                <div class="sm-lbl">Total Loans</div>
                            </div>
                            <div class="sm-item">
                                <div class="sm-num" style="color:<?= $totalMultasPend > 0 ? '#dc2626' : '#16a34a' ?>;">
                                    <?= count($pendientes) ?>
                                </div>
                                <div class="sm-lbl">Pending Fines</div>
                            </div>
                        </div>
                    </div>
                </div>

                <a href="transactions.php" style="display:flex;align-items:center;justify-content:center;gap:8px;
                   padding:12px;background:#fff;border:1px solid #e2e8f0;border-radius:10px;
                   color:#374151;text-decoration:none;font-weight:600;font-size:14px;transition:.15s;"
                   onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">
                    <i class="fa-solid fa-clock-rotate-left"></i> View Full Loan History
                </a>
            </div>

            <!-- ── Main ───────────────────────────────────────────────────── -->
            <div class="profile-main">

                <!-- Edit profile -->
                <div class="section-card">
                    <div class="sc-header">
                        <div class="sc-icon sci-blue"><i class="fa-solid fa-user-pen"></i></div>
                        <h3>Personal Information</h3>
                    </div>
                    <div class="sc-body">
                        <form method="POST" action="perfilUsuario.php">
                            <input type="hidden" name="_action" value="update_profile">
                            <div class="form-2col">
                                <div class="input-group" style="grid-column:span 2;">
                                    <label for="nombre">Full Name <span style="color:#ef4444;">*</span></label>
                                    <input type="text" id="nombre" name="nombre" class="base-input"
                                           value="<?= e($usuario['nombre_completo']) ?>" required>
                                </div>
                                <div class="input-group">
                                    <label for="email">Email <span style="color:#ef4444;">*</span></label>
                                    <input type="email" id="email" name="email" class="base-input"
                                           value="<?= e($usuario['email']) ?>" required>
                                </div>
                                <div class="input-group">
                                    <label for="telefono">Phone</label>
                                    <input type="text" id="telefono" name="telefono" class="base-input"
                                           value="<?= e($usuario['telefono'] ?? '') ?>"
                                           placeholder="+52 55 0000 0000">
                                </div>
                            </div>
                            <div style="margin-top:16px;display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn-create">
                                    <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i>Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Change password -->
                <div class="section-card">
                    <div class="sc-header">
                        <div class="sc-icon sci-slate"><i class="fa-solid fa-key"></i></div>
                        <h3>Change Password</h3>
                    </div>
                    <div class="sc-body">
                        <form method="POST" action="perfilUsuario.php">
                            <input type="hidden" name="_action" value="change_password">
                            <div class="form-2col">
                                <div class="input-group" style="grid-column:span 2;">
                                    <label for="password_actual">Current Password</label>
                                    <input type="password" id="password_actual" name="password_actual"
                                           class="base-input" autocomplete="current-password">
                                </div>
                                <div class="input-group">
                                    <label for="password_nueva">New Password</label>
                                    <input type="password" id="password_nueva" name="password_nueva"
                                           class="base-input" autocomplete="new-password"
                                           placeholder="Min. 8 characters">
                                </div>
                                <div class="input-group">
                                    <label for="password_confirm">Confirm New Password</label>
                                    <input type="password" id="password_confirm" name="password_confirm"
                                           class="base-input" autocomplete="new-password">
                                </div>
                            </div>
                            <div style="margin-top:16px;display:flex;justify-content:flex-end;">
                                <button type="submit" class="btn-create">
                                    <i class="fa-solid fa-lock" style="margin-right:6px;"></i>Update Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Pending fines — always visible so all users know their fine status -->
                <div class="section-card">
                    <div class="sc-header">
                        <div class="sc-icon <?= empty($pendientes) ? 'sci-green' : 'sci-red' ?>">
                            <i class="fa-solid fa-<?= empty($pendientes) ? 'circle-check' : 'circle-dollar-sign' ?>"></i>
                        </div>
                        <h3>Fines<?= !empty($pendientes) ? ' (' . count($pendientes) . ' pending)' : '' ?></h3>
                    </div>
                    <div class="sc-body" style="padding:0;">
                        <?php if (empty($pendientes)): ?>
                            <div style="display:flex;align-items:center;gap:12px;padding:20px;
                                        background:#f0fdf4;margin:16px;border-radius:8px;
                                        border:1px solid #bbf7d0;color:#16a34a;font-size:14px;font-weight:600;">
                                <i class="fa-solid fa-circle-check" style="font-size:18px;"></i>
                                No pending fines — your account is in good standing.
                            </div>
                        <?php else: ?>
                            <div class="fine-banner" style="margin:16px 16px 0;">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                Total pending: <strong>$<?= number_format($totalMultasPend, 2) ?> MXN</strong>
                                &nbsp;— Payable at Tesorería with your loan receipt number.
                            </div>
                            <table class="mini-t" style="margin-top:12px;">
                                <thead><tr>
                                    <th>Folio</th><th>Book</th><th>Type</th><th>Amount</th><th>Date</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($pendientes as $mf): ?>
                                    <tr>
                                        <td style="font-family:'Courier New',monospace;font-size:11px;color:#0f3524;font-weight:700;">
                                            <?= e($mf['folio_recibo']) ?>
                                        </td>
                                        <td style="font-size:13px;"><?= e(mb_strimwidth($mf['titulo'],0,35,'…')) ?></td>
                                        <td style="font-size:12px;text-transform:capitalize;color:#6b7280;"><?= e($mf['tipo_mora'] ?? '—') ?></td>
                                        <td style="font-weight:700;color:#dc2626;">$<?= number_format((float)$mf['monto_total'],2) ?></td>
                                        <td style="font-size:12px;color:#6b7280;"><?= date('d M Y', strtotime($mf['creado_en'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Active loans -->
                <div class="section-card">
                    <div class="sc-header">
                        <div class="sc-icon sci-green"><i class="fa-solid fa-book-bookmark"></i></div>
                        <h3>Active Loans<?= count($activos) ? ' ('.count($activos).')' : '' ?></h3>
                    </div>
                    <div style="padding:0;">
                        <?php if (empty($activos)): ?>
                            <div class="empty-mini">No active loans.</div>
                        <?php else: ?>
                        <table class="mini-t">
                            <thead><tr><th>Folio</th><th>Book</th><th>Due</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($activos as $pRow):
                                $mul = calcularMultaInfo($pRow['fecha_vencimiento']);
                            ?>
                                <tr>
                                    <td>
                                        <a href="reciboPrestamo.php?id=<?= $pRow['id_prestamo'] ?>"
                                           style="font-family:'Courier New',monospace;font-size:11px;color:#0f3524;font-weight:700;text-decoration:none;">
                                            <?= e($pRow['folio_recibo']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="bookInformation.php?id=<?= $pRow['id_libro'] ?>"
                                           style="color:#1e293b;font-weight:600;font-size:13px;text-decoration:none;">
                                            <?= e(mb_strimwidth($pRow['titulo'],0,35,'…')) ?>
                                        </a>
                                    </td>
                                    <td style="font-size:12px;white-space:nowrap;
                                               color:<?= $mul['atrasado'] ? '#dc2626' : '#374151' ?>;
                                               font-weight:<?= $mul['atrasado'] ? '700' : '400' ?>;">
                                        <?= date('d M Y', strtotime($pRow['fecha_vencimiento'])) ?>
                                        <?php if ($mul['atrasado']): ?>
                                            <div style="font-size:10px;">+<?= $mul['dias'] ?>d overdue</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="lb <?= estadoPrestamoBadge($pRow['estado']) ?>">
                                            <span class="lb-dot"></span>
                                            <?= estadoPrestamoLabel($pRow['estado']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent history -->
                <?php if (!empty($recientes)): ?>
                <div class="section-card">
                    <div class="sc-header">
                        <div class="sc-icon sci-slate"><i class="fa-solid fa-clock-rotate-left"></i></div>
                        <h3>Recent Loan History</h3>
                    </div>
                    <div style="padding:0;">
                        <table class="mini-t">
                            <thead><tr><th>Folio</th><th>Book</th><th>Issued</th><th>Status</th></tr></thead>
                            <tbody>
                            <?php foreach ($recientes as $hr): ?>
                                <tr>
                                    <td>
                                        <a href="reciboPrestamo.php?id=<?= $hr['id_prestamo'] ?>"
                                           style="font-family:'Courier New',monospace;font-size:11px;color:#0f3524;font-weight:700;text-decoration:none;">
                                            <?= e($hr['folio_recibo']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <a href="bookInformation.php?id=<?= $hr['id_libro'] ?>"
                                           style="color:#1e293b;font-weight:600;font-size:13px;text-decoration:none;">
                                            <?= e(mb_strimwidth($hr['titulo'],0,35,'…')) ?>
                                        </a>
                                    </td>
                                    <td style="font-size:12px;color:#6b7280;white-space:nowrap;">
                                        <?= date('d M Y', strtotime($hr['fecha_salida'])) ?>
                                    </td>
                                    <td>
                                        <span class="lb <?= estadoPrestamoBadge($hr['estado']) ?>">
                                            <span class="lb-dot"></span>
                                            <?= estadoPrestamoLabel($hr['estado']) ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- /profile-main -->
        </div><!-- /profile-layout -->
    </main>

</body>
</html>
