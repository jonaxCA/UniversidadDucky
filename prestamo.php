<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me  = currentUser();
$db  = getDB();

// Marcar como vencidos los préstamos activos que ya pasaron su fecha
$db->exec("UPDATE prestamos SET estado = 'vencido'
           WHERE estado = 'activo' AND fecha_vencimiento < NOW()");

$error = '';

// ── POST: crear préstamo ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $libroId   = (int)   ($_POST['libro_id']   ?? 0);
    $usuarioId = (int)   ($_POST['usuario_id'] ?? 0);
    $tipo      = in_array($_POST['tipo'] ?? '', ['externo','interno']) ? $_POST['tipo'] : 'externo';
    $diasRaw   = (int)   ($_POST['dias']        ?? 7);
    $dias      = in_array($diasRaw, [3, 7, 14, 21], true) ? $diasRaw : 7;   // whitelist
    $condicion = trim(    $_POST['condicion']   ?? '');

    if (!$libroId || !$usuarioId) {
        $error = 'Debes seleccionar un libro y un usuario.';
    } else {
        // Validaciones previas fuera de la transacción
        $usrStmt = $db->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
        $usrStmt->execute([$usuarioId]);
        $usuario = $usrStmt->fetch();

        if (!$usuario) {
            $error = 'User not found in the system.';
        } elseif ($usuario['estado'] === 'suspendido') {
            $error = 'This account is currently <strong>suspended</strong>. The user cannot borrow new items until the suspension is lifted. They may still return books.';
        } elseif ($usuario['estado'] !== 'activo') {
            $error = 'This account is <strong>blocked</strong>. Please contact the administration office to resolve this before issuing loans.';
        } else {
            // Multas pendientes
            $multaChk = $db->prepare("
                SELECT COUNT(*) FROM multas m
                JOIN prestamos p ON m.id_prestamo = p.id_prestamo
                WHERE p.id_usuario = ? AND m.estado_pago = 0
            ");
            $multaChk->execute([$usuarioId]);
            if ((int) $multaChk->fetchColumn() > 0) {
                $error = 'El usuario tiene multas sin pagar. Debe liquidarlas antes de obtener un préstamo.';
            } else {
                    // Verificar préstamo duplicado del mismo título
                    $dupChk = $db->prepare("
                        SELECT COUNT(*) FROM prestamos p
                        JOIN ejemplares ej ON p.id_ejemplar = ej.id_ejemplar
                        WHERE p.id_usuario = ?
                          AND ej.id_libro  = ?
                          AND p.estado IN ('activo','vencido')
                    ");
                    $dupChk->execute([$usuarioId, $libroId]);
                    if ((int)$dupChk->fetchColumn() > 0) {
                        $error = 'This user already has an active or overdue loan for this title. They must return or renew it before borrowing another copy.';
                    } else {
                try {
                    $db->beginTransaction();

                    // SELECT FOR UPDATE: bloquea el ejemplar elegido dentro de la transacción
                    // para evitar que dos solicitudes simultáneas asignen el mismo ejemplar.
                    $ejStmt = $db->prepare(
                        "SELECT id_ejemplar FROM ejemplares
                         WHERE id_libro = ? AND disponible = 'disponible'
                         LIMIT 1 FOR UPDATE"
                    );
                    $ejStmt->execute([$libroId]);
                    $ejemplar = $ejStmt->fetch();

                    if (!$ejemplar) {
                        $db->rollBack();
                        $error = 'No hay ejemplares disponibles para este libro en este momento.';
                    } else {
                        $vencimiento = date('Y-m-d H:i:s', strtotime("+{$dias} days"));

                        $ins = $db->prepare("
                            INSERT INTO prestamos
                                (id_usuario, id_ejemplar, tipo, estado,
                                 fecha_salida, fecha_vencimiento,
                                 autorizado_por, condicion_entrega)
                            VALUES (?, ?, ?, 'activo', NOW(), ?, ?, ?)
                        ");
                        $ins->execute([
                            $usuarioId, $ejemplar['id_ejemplar'],
                            $tipo, $vencimiento,
                            $me['id'], $condicion ?: null,
                        ]);
                        $prestamoId = (int) $db->lastInsertId();

                        $folio = generarFolio($prestamoId);
                        $db->prepare("UPDATE prestamos SET folio_recibo = ? WHERE id_prestamo = ?")
                           ->execute([$folio, $prestamoId]);

                        $db->prepare("UPDATE ejemplares SET disponible = 'prestado' WHERE id_ejemplar = ?")
                           ->execute([$ejemplar['id_ejemplar']]);

                        logAction($db, $me['id'], 'prestamo', 'prestamos', $prestamoId, [
                            'folio'      => $folio,
                            'libro_id'   => $libroId,
                            'usuario_id' => $usuarioId,
                            'tipo'       => $tipo,
                            'dias'       => $dias,
                            'vencimiento'=> $vencimiento,
                        ], 'prestamos');

                        // Cerrar entrada en lista de espera si el usuario estaba en cola para este libro
                        $db->prepare("
                            UPDATE lista_espera
                            SET    estado = 'prestado',
                                   fecha_notif = COALESCE(fecha_notif, NOW())
                            WHERE  id_libro   = ?
                              AND  id_usuario = ?
                              AND  estado IN ('esperando', 'notificado')
                        ")->execute([$libroId, $usuarioId]);

                        $db->commit();
                        header("Location: reciboPrestamo.php?id={$prestamoId}&nuevo=1");
                        exit;
                    }
                } catch (PDOException $ex) {
                    if ($db->inTransaction()) $db->rollBack();
                    $error = 'Error al registrar el préstamo: ' . $ex->getMessage();
                }
                    } // end: no duplicate loan
            }
        }
    }
    // Repoblar libro / usuario para re-mostrar el form
    $libroId   = (int) ($_POST['libro_id']   ?? 0);
    $usuarioId = (int) ($_POST['usuario_id'] ?? 0);
} else {
    $libroId   = (int) ($_GET['libro_id'] ?? 0);
    $usuarioId = (int) ($_GET['uid']      ?? 0);
}

// ── Búsquedas GET ─────────────────────────────────────────────────────────────
$buscarLibro   = trim($_GET['buscar_libro']    ?? '');
$buscarUsuario = trim($_GET['buscar_usuario']  ?? '');

$libro   = null;
$usuario = null;
$librosResultado   = [];
$usuariosResultado = [];
$usuarioAlerta     = '';    // '', 'ok', 'fines', 'suspended'

if ($libroId > 0) {
    $st = $db->prepare("
        SELECT l.*, e.nombre AS editorial_nombre, c.nombre AS categoria_nombre,
               COALESCE(s.disponibles, 0) AS copias_disponibles,
               COALESCE(s.total,       0) AS total_copias
        FROM libros l
        LEFT JOIN editoriales e ON l.id_editorial = e.id_editorial
        LEFT JOIN categorias  c ON l.id_categoria = c.id_categoria
        LEFT JOIN (
            SELECT id_libro,
                   COUNT(*)                       AS total,
                   SUM(disponible = 'disponible') AS disponibles
            FROM   ejemplares WHERE disponible != 'obsoleto' GROUP BY id_libro
        ) s ON l.id_libro = s.id_libro
        WHERE l.id_libro = ?
    ");
    $st->execute([$libroId]);
    $libro = $st->fetch();
}

if ($buscarLibro !== '') {
    $q = "%{$buscarLibro}%";
    $st = $db->prepare("
        SELECT l.id_libro, l.titulo, l.autor, l.isbn,
               COALESCE(s.disponibles, 0) AS copias_disponibles
        FROM   libros l
        LEFT JOIN (
            SELECT id_libro, SUM(disponible = 'disponible') AS disponibles
            FROM   ejemplares WHERE disponible != 'obsoleto' GROUP BY id_libro
        ) s ON l.id_libro = s.id_libro
        WHERE (l.titulo LIKE ? OR l.autor LIKE ? OR l.isbn LIKE ?)
        LIMIT 8
    ");
    $st->execute([$q, $q, $q]);
    $librosResultado = $st->fetchAll();
}

if ($usuarioId > 0) {
    $st = $db->prepare("SELECT * FROM usuarios WHERE id_usuario = ?");
    $st->execute([$usuarioId]);
    $usuario = $st->fetch();

    if ($usuario) {
        if ($usuario['estado'] !== 'activo') {
            $usuarioAlerta = 'suspended';
        } else {
            $mc = $db->prepare("
                SELECT COUNT(*) FROM multas m
                JOIN prestamos p ON m.id_prestamo = p.id_prestamo
                WHERE p.id_usuario = ? AND m.estado_pago = 0
            ");
            $mc->execute([$usuarioId]);
            $usuarioAlerta = (int)$mc->fetchColumn() > 0 ? 'fines' : 'ok';
        }
    }
}

if ($buscarUsuario !== '') {
    $q = "%{$buscarUsuario}%";
    $st = $db->prepare("
        SELECT id_usuario, nombre_completo, email, username, tipo, estado
        FROM   usuarios
        WHERE  (nombre_completo LIKE ? OR email LIKE ? OR username LIKE ?)
          AND  estado = 'activo'
        LIMIT  8
    ");
    $st->execute([$q, $q, $q]);
    $usuariosResultado = $st->fetchAll();
}

$diasDefault = $usuario ? diasPrestamoDB($db, $usuario['tipo']) : 7;

// URL base para conservar selecciones en los form action
$baseUrl = 'prestamo.php' .
    ($libroId   ? "?libro_id={$libroId}"  : '') .
    ($libroId && $usuarioId ? "&uid={$usuarioId}" : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Loan — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:flex-start; gap:10px; padding:14px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .alert-ok      { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .alert-warning { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }
        .alert-danger  { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

        /* Steps */
        .steps-bar { display:flex; gap:0; margin-bottom:28px; }
        .step { flex:1; padding:12px 16px; background:#f1f5f9; font-size:13px; font-weight:600;
                color:#94a3b8; border:1px solid #e2e8f0; text-align:center; }
        .step:first-child { border-radius:8px 0 0 8px; }
        .step:last-child  { border-radius:0 8px 8px 0; }
        .step.done   { background:#dcfce7; color:#16a34a; border-color:#bbf7d0; }
        .step.active { background:#0f3524; color:#fff;    border-color:#0f3524; }
        .step .step-num { display:inline-flex; align-items:center; justify-content:center;
                          width:20px; height:20px; border-radius:50%; background:rgba(255,255,255,.2);
                          font-size:11px; margin-right:6px; }
        .step.done   .step-num { background:rgba(22,163,74,.2); }
        .step.active .step-num { background:rgba(255,255,255,.25); }

        /* Search results */
        .search-results { margin-top:8px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
        .search-result-item {
            display:flex; align-items:center; justify-content:space-between;
            padding:12px 16px; border-bottom:1px solid #f1f5f9;
            text-decoration:none; color:inherit; transition:.15s;
        }
        .search-result-item:last-child { border-bottom:none; }
        .search-result-item:hover { background:#f8fafc; }
        .sri-info  { font-size:14px; font-weight:600; color:#1e293b; }
        .sri-meta  { font-size:12px; color:#6b7280; margin-top:2px; }
        .sri-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:12px; }
        .sri-avail   { background:#dcfce7; color:#16a34a; }
        .sri-unavail { background:#fee2e2; color:#dc2626; }

        /* Selected card */
        .selected-card {
            background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
            padding:16px 20px; display:flex; align-items:center; gap:16px;
        }
        .selected-card .sc-icon {
            width:44px; height:44px; border-radius:8px; background:#0f3524;
            display:flex; align-items:center; justify-content:center; color:#fff; flex-shrink:0;
        }
        .selected-card .sc-title  { font-size:15px; font-weight:700; color:#1e293b; }
        .selected-card .sc-sub    { font-size:12px; color:#6b7280; margin-top:3px; }
        .selected-card .sc-change {
            margin-left:auto; font-size:12px; font-weight:600; color:#6b7280;
            text-decoration:none; padding:6px 12px; border:1px solid #e2e8f0;
            border-radius:6px; white-space:nowrap;
        }
        .selected-card .sc-change:hover { background:#f1f5f9; }

        /* User status badges */
        .user-status-ok      { background:#f0fdf4; border:1px solid #bbf7d0; color:#16a34a; }
        .user-status-fines   { background:#fffbeb; border:1px solid #fde68a; color:#b45309; }
        .user-status-suspend { background:#fef2f2; border:1px solid #fecaca; color:#dc2626; }

        /* Loan config grid */
        .loan-config-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media(max-width:600px) { .loan-config-grid { grid-template-columns:1fr; } }

        /* Days selector */
        .days-radio-group { display:flex; gap:8px; flex-wrap:wrap; }
        .days-radio-group input[type=radio] { display:none; }
        .days-radio-group label {
            padding:8px 16px; border:1px solid #e2e8f0; border-radius:8px;
            font-size:13px; font-weight:600; cursor:pointer; transition:.15s;
            color:#374151;
        }
        .days-radio-group input:checked + label {
            background:#0f3524; color:#fff; border-color:#0f3524;
        }
        .days-radio-group label:hover { background:#f1f5f9; }
        .days-radio-group input:checked + label:hover { background:#1a5c3a; }

        /* tipo selector */
        .tipo-radio-group { display:flex; gap:8px; }
        .tipo-radio-group input[type=radio] { display:none; }
        .tipo-radio-group label {
            flex:1; padding:10px; border:1px solid #e2e8f0; border-radius:8px;
            font-size:13px; font-weight:600; cursor:pointer; text-align:center; transition:.15s;
        }
        .tipo-radio-group input:checked + label { background:#0f3524; color:#fff; border-color:#0f3524; }

        .no-results { padding:20px; text-align:center; color:#6b7280; font-size:14px; }
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
            <a href="transactions.php" class="active">Loans</a>
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

    <main class="form-page-container">

        <div class="form-header-area">
            <a href="transactions.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Loans
            </a>
            <h1>Register New Loan</h1>
            <p>Select a book and a borrower to issue a new loan.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation" style="margin-top:2px;flex-shrink:0;"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Steps indicator -->
        <div class="steps-bar">
            <div class="step <?= $libro ? 'done' : 'active' ?>">
                <span class="step-num"><?= $libro ? '<i class="fa-solid fa-check" style="font-size:9px;"></i>' : '1' ?></span>
                Select Book
            </div>
            <div class="step <?= (!$libro) ? '' : ($usuario ? 'done' : 'active') ?>">
                <span class="step-num"><?= ($usuario && $libro) ? '<i class="fa-solid fa-check" style="font-size:9px;"></i>' : '2' ?></span>
                Select Borrower
            </div>
            <div class="step <?= ($libro && $usuario) ? 'active' : '' ?>">
                <span class="step-num">3</span>
                Confirm Loan
            </div>
        </div>

        <div class="form-card">

            <!-- ── STEP 1: Book ───────────────────────────────────────────── -->
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fa-solid fa-book" style="margin-right:8px;color:#0f3524;"></i>
                    Book
                </h3>

                <?php if ($libro): ?>
                    <!-- Libro ya seleccionado -->
                    <div class="selected-card">
                        <div class="sc-icon"><i class="fa-solid fa-book"></i></div>
                        <div>
                            <div class="sc-title"><?= e($libro['titulo']) ?></div>
                            <div class="sc-sub">
                                <?= e($libro['autor'] ?? '—') ?>
                                <?php if ($libro['isbn']): ?>&nbsp;·&nbsp;ISBN <?= e($libro['isbn']) ?><?php endif; ?>
                                &nbsp;·&nbsp;
                                <?php if ($libro['copias_disponibles'] > 0): ?>
                                    <span style="color:#16a34a;font-weight:700;"><?= (int)$libro['copias_disponibles'] ?> available</span>
                                <?php else: ?>
                                    <span style="color:#dc2626;font-weight:700;">No copies available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="prestamo.php<?= $usuarioId ? "?uid={$usuarioId}" : '' ?>" class="sc-change">
                            <i class="fa-solid fa-rotate"></i> Change
                        </a>
                    </div>
                    <?php if ((int)$libro['copias_disponibles'] === 0): ?>
                        <div class="alert alert-warning" style="margin-top:12px;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            No available copies right now. You can register the loan and the system will queue it.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Búsqueda de libro -->
                    <form method="GET" action="prestamo.php" style="display:flex;gap:8px;">
                        <?php if ($usuarioId): ?>
                            <input type="hidden" name="uid" value="<?= $usuarioId ?>">
                        <?php endif; ?>
                        <input type="text" name="buscar_libro" class="base-input"
                               placeholder="Search by title, author or ISBN…"
                               value="<?= e($buscarLibro) ?>" style="flex:1;">
                        <button type="submit" class="btn-create" style="white-space:nowrap;">
                            <i class="fa-solid fa-search"></i> Search
                        </button>
                    </form>

                    <?php if ($buscarLibro !== '' && empty($librosResultado)): ?>
                        <div class="no-results"><i class="fa-solid fa-face-frown" style="margin-right:6px;"></i>No books found for "<?= e($buscarLibro) ?>"</div>
                    <?php endif; ?>

                    <?php if ($librosResultado): ?>
                        <div class="search-results" style="margin-top:12px;">
                            <?php foreach ($librosResultado as $lr): ?>
                                <a href="prestamo.php?libro_id=<?= $lr['id_libro'] ?><?= $usuarioId ? "&uid={$usuarioId}" : '' ?>"
                                   class="search-result-item">
                                    <div>
                                        <div class="sri-info"><?= e($lr['titulo']) ?></div>
                                        <div class="sri-meta"><?= e($lr['autor'] ?? '—') ?>
                                            <?php if ($lr['isbn']): ?>· <?= e($lr['isbn']) ?><?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="sri-badge <?= (int)$lr['copias_disponibles'] > 0 ? 'sri-avail' : 'sri-unavail' ?>">
                                        <?= (int)$lr['copias_disponibles'] ?> avail.
                                    </span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- ── STEP 2: Borrower ───────────────────────────────────────── -->
            <?php if ($libro): ?>
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fa-solid fa-user" style="margin-right:8px;color:#0f3524;"></i>
                    Borrower
                </h3>

                <?php if ($usuario): ?>
                    <!-- Usuario ya seleccionado -->
                    <div class="selected-card <?=
                        $usuarioAlerta === 'ok'       ? 'user-status-ok'      :
                        ($usuarioAlerta === 'fines'   ? 'user-status-fines'   :
                        ($usuarioAlerta === 'suspended'? 'user-status-suspend' : '')) ?>">
                        <div class="sc-icon" style="background:<?= $usuarioAlerta === 'ok' ? '#16a34a' : ($usuarioAlerta === 'fines' ? '#b45309' : '#dc2626') ?>;">
                            <i class="fa-solid fa-<?= $usuarioAlerta === 'ok' ? 'user-check' : 'user-xmark' ?>"></i>
                        </div>
                        <div>
                            <div class="sc-title"><?= e($usuario['nombre_completo']) ?></div>
                            <div class="sc-sub">
                                <?= e($usuario['email']) ?>
                                &nbsp;·&nbsp; <?= tipoLabel($usuario['tipo']) ?>
                                <?php if ($usuarioAlerta === 'fines'): ?>
                                    &nbsp;·&nbsp; <span style="color:#b45309;font-weight:700;">⚠ Pending fines</span>
                                <?php elseif ($usuarioAlerta === 'suspended'): ?>
                                    &nbsp;·&nbsp; <span style="color:#dc2626;font-weight:700;">✗ Suspended/Blocked</span>
                                <?php else: ?>
                                    &nbsp;·&nbsp; <span style="color:#16a34a;font-weight:700;">✓ Eligible</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <a href="prestamo.php?libro_id=<?= $libroId ?>" class="sc-change">
                            <i class="fa-solid fa-rotate"></i> Change
                        </a>
                    </div>
                    <?php if ($usuarioAlerta === 'fines'): ?>
                        <div class="alert alert-warning" style="margin-top:12px;">
                            <i class="fa-solid fa-triangle-exclamation"></i>
                            This user has unpaid fines. Loans cannot be issued until they are paid.
                        </div>
                    <?php elseif ($usuarioAlerta === 'suspended'): ?>
                        <div class="alert alert-danger" style="margin-top:12px;">
                            <i class="fa-solid fa-ban"></i>
                            This user's account is <?= e($usuario['estado']) ?>. Loans cannot be issued.
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <!-- Búsqueda de usuario -->
                    <form method="GET" action="prestamo.php" style="display:flex;gap:8px;">
                        <input type="hidden" name="libro_id" value="<?= $libroId ?>">
                        <input type="text" name="buscar_usuario" class="base-input"
                               placeholder="Search by name, email or username…"
                               value="<?= e($buscarUsuario) ?>" style="flex:1;">
                        <button type="submit" class="btn-create" style="white-space:nowrap;">
                            <i class="fa-solid fa-search"></i> Search
                        </button>
                    </form>

                    <?php if ($buscarUsuario !== '' && empty($usuariosResultado)): ?>
                        <div class="no-results"><i class="fa-solid fa-face-frown" style="margin-right:6px;"></i>No active users found for "<?= e($buscarUsuario) ?>"</div>
                    <?php endif; ?>

                    <?php if ($usuariosResultado): ?>
                        <div class="search-results" style="margin-top:12px;">
                            <?php foreach ($usuariosResultado as $ur): ?>
                                <a href="prestamo.php?libro_id=<?= $libroId ?>&uid=<?= $ur['id_usuario'] ?>"
                                   class="search-result-item">
                                    <div>
                                        <div class="sri-info"><?= e($ur['nombre_completo']) ?></div>
                                        <div class="sri-meta"><?= e($ur['email']) ?> · <?= tipoLabel($ur['tipo']) ?></div>
                                    </div>
                                    <span class="sri-badge sri-avail"><?= tipoLabel($ur['tipo']) ?></span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; /* libro */ ?>

            <!-- ── STEP 3: Loan config + confirm ─────────────────────────── -->
            <?php if ($libro && $usuario && $usuarioAlerta === 'ok'): ?>
            <div class="form-section">
                <h3 class="section-title">
                    <i class="fa-solid fa-clipboard-check" style="margin-right:8px;color:#0f3524;"></i>
                    Loan Configuration
                </h3>

                <form method="POST" action="prestamo.php">
                    <input type="hidden" name="libro_id"   value="<?= $libroId ?>">
                    <input type="hidden" name="usuario_id" value="<?= $usuarioId ?>">

                    <div class="loan-config-grid">

                        <!-- Tipo de préstamo -->
                        <div class="input-group">
                            <label>Loan Type</label>
                            <div class="tipo-radio-group">
                                <input type="radio" id="tipo_externo" name="tipo" value="externo" checked>
                                <label for="tipo_externo"><i class="fa-solid fa-house" style="margin-right:6px;"></i>External (Take home)</label>
                                <input type="radio" id="tipo_interno" name="tipo" value="interno">
                                <label for="tipo_interno"><i class="fa-solid fa-building" style="margin-right:6px;"></i>Internal (Library only)</label>
                            </div>
                        </div>

                        <!-- Días de préstamo -->
                        <div class="input-group">
                            <label>Loan Duration</label>
                            <div class="days-radio-group">
                                <?php foreach ([3, 7, 14, 21] as $d): ?>
                                    <input type="radio" id="dias_<?= $d ?>" name="dias" value="<?= $d ?>"
                                           <?= $d === $diasDefault ? 'checked' : '' ?>>
                                    <label for="dias_<?= $d ?>"><?= $d ?> days</label>
                                <?php endforeach; ?>
                            </div>
                            <p style="font-size:12px;color:#6b7280;margin-top:6px;">
                                Default for <?= tipoLabel($usuario['tipo']) ?>: <?= $diasDefault ?> days.
                                Due: <strong id="due-date-preview"></strong>
                            </p>
                        </div>

                        <!-- Condición de entrega -->
                        <div class="input-group" style="grid-column:span 2;">
                            <label for="condicion">Book Condition on Delivery <span style="color:#6b7280;font-weight:400;">(optional)</span></label>
                            <input type="text" id="condicion" name="condicion" class="base-input"
                                   placeholder="e.g. Good condition, minor spine wear…">
                        </div>
                    </div>

                    <!-- Resumen -->
                    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:16px 20px;margin-top:8px;margin-bottom:20px;">
                        <div style="font-size:13px;font-weight:700;color:#15803d;margin-bottom:10px;">
                            <i class="fa-solid fa-circle-check" style="margin-right:6px;"></i>Loan Summary
                        </div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;color:#166534;">
                            <div><strong>Book:</strong> <?= e($libro['titulo']) ?></div>
                            <div><strong>Borrower:</strong> <?= e($usuario['nombre_completo']) ?></div>
                            <div><strong>Issued by:</strong> <?= e($me['nombre']) ?></div>
                            <div><strong>Fine rate:</strong> $<?= number_format((float)getConfig($db,'monto_multa_dia',10),2) ?> MXN / day overdue</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <a href="prestamo.php?libro_id=<?= $libroId ?>" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-create">
                            <i class="fa-solid fa-book-bookmark" style="margin-right:8px;"></i>Register Loan
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

        </div><!-- /form-card -->
    </main>

    <script>
    // Actualizar preview de fecha de vencimiento
    function updateDueDate() {
        const dias = document.querySelector('input[name="dias"]:checked')?.value ?? 7;
        const d    = new Date();
        d.setDate(d.getDate() + parseInt(dias));
        const opts = { day: '2-digit', month: 'short', year: 'numeric' };
        const el   = document.getElementById('due-date-preview');
        if (el) el.textContent = d.toLocaleDateString('en-GB', opts);
    }
    document.querySelectorAll('input[name="dias"]').forEach(r => r.addEventListener('change', updateDueDate));
    updateDueDate();
    </script>
</body>
</html>
