<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me  = currentUser();
$db  = getDB();

// Leer tasas desde configuración (en lugar de valores hardcodeados)
$montoPorDia = (float) getConfig($db, 'monto_multa_dia',            10.0);
$multPerdida = (int)   getConfig($db, 'costo_perdida_multiplicador', 20);

// Marcar préstamos vencidos automáticamente
$db->exec("UPDATE prestamos SET estado = 'vencido'
           WHERE estado = 'activo' AND fecha_vencimiento < NOW()");

$error  = '';
$exito  = '';

// ── POST: procesar devolución ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $prestamoId    = (int)   ($_POST['prestamo_id']    ?? 0);
    $condicion     = trim(    $_POST['condicion']       ?? '');
    $estadoEjemplar=          $_POST['estado_ejemplar'] ?? 'disponible';  // disponible|dañado|perdido
    $esPerdido     = $estadoEjemplar === 'perdido';

    if ($prestamoId <= 0) {
        $error = 'Préstamo no válido.';
    } else {
        // Cargar préstamo
        $stP = $db->prepare("
            SELECT p.*, e.id_ejemplar, l.titulo
            FROM   prestamos p
            JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
            JOIN   libros     l ON e.id_libro    = l.id_libro
            WHERE  p.id_prestamo = ?
              AND  p.estado IN ('activo','vencido')
        ");
        $stP->execute([$prestamoId]);
        $prestamo = $stP->fetch();

        if (!$prestamo) {
            $error = 'El préstamo no existe o ya fue procesado.';
        } else {
            try {
                $db->beginTransaction();

                $ahora        = date('Y-m-d H:i:s');
                $multaInfo    = calcularMultaInfo($prestamo['fecha_vencimiento'], $montoPorDia);
                $estadoPrest  = $esPerdido ? 'perdido' : 'devuelto';

                // Actualizar préstamo
                $db->prepare("
                    UPDATE prestamos
                    SET    estado            = ?,
                           fecha_devolucion  = ?,
                           condicion_retorno = ?
                    WHERE  id_prestamo = ?
                ")->execute([$estadoPrest, $ahora, $condicion ?: null, $prestamoId]);

                // Actualizar ejemplar
                $db->prepare("
                    UPDATE ejemplares SET disponible = ? WHERE id_ejemplar = ?
                ")->execute([$estadoEjemplar, $prestamo['id_ejemplar']]);

                // Crear multa si corresponde
                $multaId = null;
                if ($multaInfo['atrasado'] || $esPerdido) {
                    $montoExtra = $esPerdido ? ($prestamo['precio_compra_usd'] ?? 0) * $multPerdida : 0;
                    $montoTotal = $multaInfo['monto'] + $montoExtra;
                    $tipoMora   = $esPerdido ? 'perdida' : 'retraso';

                    $ins = $db->prepare("
                        INSERT INTO multas
                            (id_prestamo, dias_retraso, monto_por_dia, monto_total, tipo_mora, estado_pago)
                        VALUES (?, ?, ?, ?, ?, 0)
                    ");
                    $ins->execute([$prestamoId, $multaInfo['dias'], $montoPorDia, $montoTotal, $tipoMora]);
                    $multaId = (int) $db->lastInsertId();
                }

                logAction($db, $me['id'], 'devolucion', 'prestamos', $prestamoId, [
                    'estado'         => $estadoPrest,
                    'condicion'      => $condicion,
                    'dias_retraso'   => $multaInfo['dias'],
                    'multa_monto'    => $multaInfo['monto'],
                    'estado_ejemplar'=> $estadoEjemplar,
                ], 'prestamos');

                $db->commit();

                $msgExtra = $multaId
                    ? " Se generó una multa de <strong>$" . number_format($multaInfo['monto'] + ($esPerdido ? 0 : 0), 2) . " MXN</strong>."
                    : '';
                $exito = 'Devolución registrada correctamente.' . $msgExtra;

                // Redirigir a recibo con flag de devuelto
                header("Location: reciboPrestamo.php?id={$prestamoId}");
                exit;
            } catch (PDOException $ex) {
                $db->rollBack();
                $error = 'Error al procesar la devolución: ' . $ex->getMessage();
            }
        }
    }
}

// ── Búsqueda ──────────────────────────────────────────────────────────────────
$prestamoId  = (int)  ($_GET['prestamo_id'] ?? 0);
$buscar      = trim(   $_GET['buscar']      ?? '');

$prestamo        = null;
$resultadosBuscar= [];

if ($prestamoId > 0) {
    $st = $db->prepare("
        SELECT p.*,
               u.nombre_completo, u.email, u.tipo AS usuario_tipo,
               e.codigo_inventario, e.biblioteca, e.ubicacion_pasillo_estante,
               l.titulo, l.autor, l.isbn, l.id_libro
        FROM   prestamos p
        JOIN   usuarios  u ON p.id_usuario  = u.id_usuario
        JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
        JOIN   libros    l ON e.id_libro    = l.id_libro
        WHERE  p.id_prestamo = ?
          AND  p.estado IN ('activo','vencido')
    ");
    $st->execute([$prestamoId]);
    $prestamo = $st->fetch();
}

if ($buscar !== '') {
    $q = "%{$buscar}%";
    $st = $db->prepare("
        SELECT p.id_prestamo, p.folio_recibo, p.estado,
               p.fecha_salida, p.fecha_vencimiento,
               u.nombre_completo, u.email,
               l.titulo,
               e.codigo_inventario
        FROM   prestamos p
        JOIN   usuarios  u ON p.id_usuario  = u.id_usuario
        JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
        JOIN   libros    l ON e.id_libro    = l.id_libro
        WHERE  p.estado IN ('activo','vencido')
          AND  (p.folio_recibo      LIKE ?
             OR u.nombre_completo   LIKE ?
             OR u.email             LIKE ?
             OR e.codigo_inventario LIKE ?
             OR l.titulo            LIKE ?)
        ORDER  BY p.fecha_salida DESC
        LIMIT  10
    ");
    $st->execute([$q, $q, $q, $q, $q]);
    $resultadosBuscar = $st->fetchAll();
}

$multaInfo = $prestamo ? calcularMultaInfo($prestamo['fecha_vencimiento'], $montoPorDia) : null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Return — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:flex-start; gap:10px; padding:14px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .alert-warning { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }

        /* Search results */
        .search-results { margin-top:10px; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
        .sri { display:flex; align-items:center; justify-content:space-between; gap:12px;
               padding:12px 16px; border-bottom:1px solid #f1f5f9;
               text-decoration:none; color:inherit; transition:.15s; }
        .sri:last-child { border-bottom:none; }
        .sri:hover { background:#f8fafc; }
        .sri-title  { font-size:14px; font-weight:600; color:#1e293b; }
        .sri-meta   { font-size:12px; color:#6b7280; margin-top:2px; }
        .badge-overdue  { background:#fef9c3; color:#92400e; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }
        .badge-active-l { background:#dcfce7; color:#15803d; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }

        /* Return card */
        .return-card {
            background:#fff; border:1px solid #e2e8f0; border-radius:12px;
            overflow:hidden; margin-bottom:24px;
        }
        .return-card-header {
            padding:16px 24px; background:#f8fafc;
            border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between;
            align-items:center; gap:12px;
        }
        .return-card-body { padding:20px 24px; }

        .info-grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:10px 24px; }
        .ig-item { }
        .ig-lbl { font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#94a3b8; margin-bottom:3px; }
        .ig-val { font-size:14px; font-weight:600; color:#1e293b; }

        /* Fine preview box */
        .fine-preview {
            margin:16px 0; padding:16px 20px; border-radius:10px;
            display:flex; align-items:flex-start; gap:14px;
        }
        .fp-warn { background:#fffbeb; border:1px solid #fde68a; }
        .fp-ok   { background:#f0fdf4; border:1px solid #bbf7d0; }
        .fp-icon { font-size:22px; flex-shrink:0; }
        .fp-warn .fp-icon { color:#b45309; }
        .fp-ok   .fp-icon { color:#16a34a; }
        .fp-title { font-weight:700; font-size:14px; margin-bottom:4px; }
        .fp-warn  .fp-title { color:#92400e; }
        .fp-ok    .fp-title { color:#15803d; }
        .fp-sub   { font-size:13px; color:#6b7280; }

        /* Condition cards */
        .cond-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px; }
        .cond-radio input[type=radio] { display:none; }
        .cond-radio label {
            display:block; padding:12px; border:2px solid #e2e8f0; border-radius:8px;
            cursor:pointer; text-align:center; transition:.15s; font-size:13px;
        }
        .cond-radio label .ci { font-size:20px; display:block; margin-bottom:4px; }
        .cond-radio input:checked + label { border-color:#0f3524; background:#f0fdf4; }
        .cond-radio.danger input:checked + label { border-color:#dc2626; background:#fef2f2; }
        .no-results { padding:20px; text-align:center; color:#6b7280; font-size:14px; }

        /* Days overdue pill */
        .overdue-pill {
            display:inline-flex; align-items:center; gap:6px;
            background:#fef9c3; color:#92400e; border:1px solid #fde68a;
            padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700;
        }
        .ontime-pill {
            display:inline-flex; align-items:center; gap:6px;
            background:#dcfce7; color:#15803d; border:1px solid #bbf7d0;
            padding:4px 12px; border-radius:20px; font-size:12px; font-weight:700;
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
            <h1>Process Return</h1>
            <p>Search by folio, user, book title or inventory code.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;margin-top:2px;"></i>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- Búsqueda -->
        <div class="form-card" style="margin-bottom:20px;">
            <form method="GET" action="devolucion.php" style="display:flex;gap:8px;">
                <input type="text" name="buscar" class="base-input"
                       placeholder="Folio, borrower name, email, book title or inventory code…"
                       value="<?= e($buscar) ?>" style="flex:1;">
                <button type="submit" class="btn-create" style="white-space:nowrap;">
                    <i class="fa-solid fa-search"></i> Search
                </button>
            </form>

            <?php if ($buscar !== '' && empty($resultadosBuscar) && !$prestamo): ?>
                <div class="no-results" style="margin-top:12px;">
                    <i class="fa-solid fa-face-frown" style="margin-right:6px;"></i>
                    No active loans found for "<?= e($buscar) ?>"
                </div>
            <?php endif; ?>

            <?php if ($resultadosBuscar): ?>
                <div class="search-results" style="margin-top:12px;">
                    <?php foreach ($resultadosBuscar as $r): ?>
                        <?php $mulInfo = calcularMultaInfo($r['fecha_vencimiento'], $montoPorDia); ?>
                        <a href="devolucion.php?prestamo_id=<?= $r['id_prestamo'] ?>" class="sri">
                            <div>
                                <div class="sri-title"><?= e($r['titulo']) ?></div>
                                <div class="sri-meta">
                                    <?= e($r['nombre_completo']) ?> · <?= e($r['folio_recibo']) ?>
                                    · Due: <?= date('d M Y', strtotime($r['fecha_vencimiento'])) ?>
                                </div>
                            </div>
                            <?php if ($mulInfo['atrasado']): ?>
                                <span class="badge-overdue">⚠ <?= $mulInfo['dias'] ?>d overdue</span>
                            <?php else: ?>
                                <span class="badge-active-l">On time</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Formulario de devolución -->
        <?php if ($prestamo): ?>
        <div class="form-card">
            <div class="form-section">
                <h3 class="section-title">Loan Details</h3>

                <div class="return-card">
                    <div class="return-card-header">
                        <div>
                            <div style="font-family:'Courier New',monospace;font-size:16px;font-weight:700;color:#0f3524;">
                                <?= e($prestamo['folio_recibo']) ?>
                            </div>
                            <div style="font-size:12px;color:#6b7280;margin-top:2px;">
                                Issued: <?= date('d M Y', strtotime($prestamo['fecha_salida'])) ?>
                            </div>
                        </div>
                        <?php if ($multaInfo['atrasado']): ?>
                            <span class="overdue-pill">
                                <i class="fa-solid fa-triangle-exclamation"></i>
                                <?= $multaInfo['dias'] ?> day<?= $multaInfo['dias'] !== 1 ? 's' : '' ?> overdue
                            </span>
                        <?php else: ?>
                            <?php $diasRestantes = (int) (new DateTime($prestamo['fecha_vencimiento']))->diff(new DateTime('today'))->days; ?>
                            <span class="ontime-pill">
                                <i class="fa-solid fa-circle-check"></i>
                                On time
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="return-card-body">
                        <div class="info-grid-2">
                            <div class="ig-item">
                                <div class="ig-lbl">Book</div>
                                <div class="ig-val"><?= e($prestamo['titulo']) ?></div>
                            </div>
                            <div class="ig-item">
                                <div class="ig-lbl">Borrower</div>
                                <div class="ig-val"><?= e($prestamo['nombre_completo']) ?></div>
                            </div>
                            <div class="ig-item">
                                <div class="ig-lbl">Copy ID</div>
                                <div class="ig-val" style="font-family:'Courier New',monospace;"><?= e($prestamo['codigo_inventario']) ?></div>
                            </div>
                            <div class="ig-item">
                                <div class="ig-lbl">Due Date</div>
                                <div class="ig-val" style="color:<?= $multaInfo['atrasado'] ? '#dc2626' : '#0f3524' ?>;">
                                    <?= date('d M Y', strtotime($prestamo['fecha_vencimiento'])) ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Fine preview -->
                <?php if ($multaInfo['atrasado']): ?>
                    <div class="fine-preview fp-warn">
                        <div class="fp-icon"><i class="fa-solid fa-circle-dollar-sign"></i></div>
                        <div>
                            <div class="fp-title">Fine will be generated: $<?= number_format($multaInfo['monto'], 2) ?> MXN</div>
                            <div class="fp-sub">
                                <?= $multaInfo['dias'] ?> day<?= $multaInfo['dias'] !== 1 ? 's' : '' ?> overdue ×
                                $<?= number_format($montoPorDia, 2) ?> MXN/day. Payable at Tesorería.
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="fine-preview fp-ok">
                        <div class="fp-icon"><i class="fa-solid fa-circle-check"></i></div>
                        <div>
                            <div class="fp-title">No fine — returned on time</div>
                            <div class="fp-sub">The book is being returned before its due date.</div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-section">
                <h3 class="section-title">Return Details</h3>

                <form method="POST" action="devolucion.php">
                    <input type="hidden" name="prestamo_id" value="<?= (int)$prestamo['id_prestamo'] ?>">

                    <!-- Estado del ejemplar al retorno -->
                    <div class="input-group" style="margin-bottom:20px;">
                        <label>Book Condition on Return</label>
                        <div class="cond-grid">
                            <div class="cond-radio">
                                <input type="radio" id="cond_ok" name="estado_ejemplar" value="disponible" checked>
                                <label for="cond_ok">
                                    <span class="ci">✅</span>
                                    Good / Normal wear
                                </label>
                            </div>
                            <div class="cond-radio danger">
                                <input type="radio" id="cond_dano" name="estado_ejemplar" value="dañado">
                                <label for="cond_dano">
                                    <span class="ci">⚠️</span>
                                    Damaged
                                </label>
                            </div>
                            <div class="cond-radio danger">
                                <input type="radio" id="cond_perd" name="estado_ejemplar" value="perdido">
                                <label for="cond_perd">
                                    <span class="ci">❌</span>
                                    Lost / Not returned
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Notas de condición -->
                    <div class="input-group" style="margin-bottom:24px;">
                        <label for="condicion">Condition Notes <span style="color:#6b7280;font-weight:400;">(optional)</span></label>
                        <input type="text" id="condicion" name="condicion" class="base-input"
                               placeholder="e.g. Spine cracked, pages yellowed…">
                    </div>

                    <div class="form-actions">
                        <a href="reciboPrestamo.php?id=<?= $prestamo['id_prestamo'] ?>" class="btn-cancel">View Receipt</a>
                        <button type="submit" id="btnProcessReturn" class="btn-create"
                                data-titulo="<?= e($prestamo['titulo']) ?>"
                                data-fine="<?= $multaInfo['atrasado']
                                    ? 'A fine of $' . number_format($multaInfo['monto'], 2) . ' MXN will be generated.'
                                    : 'No fine will be applied.' ?>">
                            <i class="fa-solid fa-rotate-left" style="margin-right:8px;"></i>
                            Process Return
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </main>

    <script>
    const btnReturn = document.getElementById('btnProcessReturn');
    if (btnReturn) {
        btnReturn.addEventListener('click', function(e) {
            const msg = 'Confirm return of "' + this.dataset.titulo + '"?\n' + this.dataset.fine;
            if (!confirm(msg)) e.preventDefault();
        });
    }
    </script>
</body>
</html>
