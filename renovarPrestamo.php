<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me  = currentUser();
$db  = getDB();

// Leer límite de renovaciones desde configuración
$maxRenovaciones = (int) getConfig($db, 'max_renovaciones', 2);
$montoPorDia     = (float) getConfig($db, 'monto_multa_dia', 10.0);

// Actualizar vencidos
$db->exec("UPDATE prestamos SET estado = 'vencido'
           WHERE estado = 'activo' AND fecha_vencimiento < NOW()");

$prestamoId = (int) ($_GET['id'] ?? ($_POST['prestamo_id'] ?? 0));
$error      = '';

if ($prestamoId <= 0) {
    header('Location: transactions.php');
    exit;
}

// Cargar préstamo
$stmt = $db->prepare("
    SELECT p.*,
           u.nombre_completo, u.email, u.tipo AS usuario_tipo,
           e.codigo_inventario, e.biblioteca,
           l.titulo, l.autor
    FROM   prestamos p
    JOIN   usuarios  u ON p.id_usuario  = u.id_usuario
    JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN   libros    l ON e.id_libro    = l.id_libro
    WHERE  p.id_prestamo = ?
");
$stmt->execute([$prestamoId]);
$prestamo = $stmt->fetch();

if (!$prestamo) {
    header('Location: transactions.php');
    exit;
}

// Solo préstamos activos que no han alcanzado el límite de renovaciones
if (!in_array($prestamo['estado'], ['activo', 'vencido'])) {
    header("Location: reciboPrestamo.php?id={$prestamoId}");
    exit;
}
if ((int)$prestamo['renovaciones_conteo'] >= $maxRenovaciones) {
    header("Location: reciboPrestamo.php?id={$prestamoId}");
    exit;
}

// ── POST: ejecutar renovación ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $diasExtra = (int) ($_POST['dias'] ?? 7);
    if ($diasExtra < 1 || $diasExtra > 21) $diasExtra = 7;

    try {
        $nuevaFecha = date('Y-m-d H:i:s',
            strtotime($prestamo['fecha_vencimiento'] . " +{$diasExtra} days")
        );

        $db->prepare("
            UPDATE prestamos
            SET    fecha_vencimiento   = ?,
                   renovaciones_conteo = renovaciones_conteo + 1,
                   estado              = 'activo'
            WHERE  id_prestamo = ?
        ")->execute([$nuevaFecha, $prestamoId]);

        logAction($db, $me['id'], 'renovacion', 'prestamos', $prestamoId, [
            'dias_extra'   => $diasExtra,
            'nueva_fecha'  => $nuevaFecha,
            'renovacion_n' => (int)$prestamo['renovaciones_conteo'] + 1,
        ], 'prestamos');

        header("Location: reciboPrestamo.php?id={$prestamoId}");
        exit;
    } catch (PDOException $ex) {
        $error = 'Error al renovar el préstamo: ' . $ex->getMessage();
    }
}

$multaInfo        = calcularMultaInfo($prestamo['fecha_vencimiento'], $montoPorDia);
$renovacionesLeft = $maxRenovaciones - (int)$prestamo['renovaciones_conteo'];

// Calcular nueva fecha (preview con 7 días por defecto)
$nuevaFechaPreview = date('d M Y', strtotime($prestamo['fecha_vencimiento'] . ' +7 days'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Renew Loan — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .alert-warning { background:#fffbeb; color:#b45309; border:1px solid #fde68a; }

        .detail-card {
            background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px;
            padding:20px 24px; margin-bottom:24px;
        }
        .detail-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 24px; }
        .dt-lbl { font-size:10px; font-weight:700; letter-spacing:1px; text-transform:uppercase; color:#94a3b8; margin-bottom:3px; }
        .dt-val { font-size:14px; font-weight:600; color:#1e293b; }

        .timeline {
            display:flex; align-items:center; gap:0; margin:24px 0;
        }
        .tl-node {
            flex-shrink:0; width:40px; height:40px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:16px; border:2px solid;
        }
        .tl-old  { background:#fee2e2; border-color:#fca5a5; color:#dc2626; }
        .tl-new  { background:#dcfce7; border-color:#86efac; color:#16a34a; }
        .tl-line { flex:1; height:2px; background:#e2e8f0; }
        .tl-label { font-size:11px; color:#6b7280; margin-top:6px; text-align:center; }
        .tl-wrap { display:flex; flex-direction:column; align-items:center; }

        .days-radio-group { display:flex; gap:8px; flex-wrap:wrap; }
        .days-radio-group input[type=radio] { display:none; }
        .days-radio-group label {
            padding:8px 20px; border:1px solid #e2e8f0; border-radius:8px;
            font-size:14px; font-weight:600; cursor:pointer; transition:.15s; color:#374151;
        }
        .days-radio-group input:checked + label { background:#0f3524; color:#fff; border-color:#0f3524; }

        .renew-count-badge {
            display:inline-flex; align-items:center; gap:6px;
            background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe;
            padding:5px 12px; border-radius:20px; font-size:12px; font-weight:700;
        }
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
            <a href="reciboPrestamo.php?id=<?= $prestamoId ?>" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Receipt
            </a>
            <h1>Renew Loan</h1>
            <p>Extend the due date for this active loan.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($multaInfo['atrasado']): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                This loan is <strong><?= $multaInfo['dias'] ?> day<?= $multaInfo['dias'] !== 1 ? 's' : '' ?> overdue</strong>.
                A renewal will extend from the <em>original</em> due date — the accumulated fine will be recorded on return.
            </div>
        <?php endif; ?>

        <div class="form-card">
            <div class="form-section">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
                    <h3 class="section-title" style="margin-bottom:0;">Current Loan</h3>
                    <span class="renew-count-badge">
                        <i class="fa-solid fa-rotate-right"></i>
                        <?= (int)$prestamo['renovaciones_conteo'] ?> / <?= $maxRenovaciones ?> renewals used
                        &nbsp;·&nbsp; <?= $renovacionesLeft ?> remaining
                    </span>
                </div>

                <div class="detail-card">
                    <div class="detail-grid">
                        <div>
                            <div class="dt-lbl">Book</div>
                            <div class="dt-val"><?= e($prestamo['titulo']) ?></div>
                        </div>
                        <div>
                            <div class="dt-lbl">Borrower</div>
                            <div class="dt-val"><?= e($prestamo['nombre_completo']) ?></div>
                        </div>
                        <div>
                            <div class="dt-lbl">Folio</div>
                            <div class="dt-val" style="font-family:'Courier New',monospace;"><?= e($prestamo['folio_recibo']) ?></div>
                        </div>
                        <div>
                            <div class="dt-lbl">Copy</div>
                            <div class="dt-val" style="font-family:'Courier New',monospace;"><?= e($prestamo['codigo_inventario']) ?></div>
                        </div>
                    </div>

                    <!-- Timeline: current due → new due -->
                    <div class="timeline" style="margin-top:20px;">
                        <div class="tl-wrap">
                            <div class="tl-node tl-old"><i class="fa-solid fa-calendar-xmark"></i></div>
                            <div class="tl-label">Current due<br><strong style="color:#dc2626;"><?= date('d M Y', strtotime($prestamo['fecha_vencimiento'])) ?></strong></div>
                        </div>
                        <div class="tl-line"></div>
                        <div style="padding:0 12px;font-size:12px;color:#6b7280;text-align:center;flex-shrink:0;">
                            <i class="fa-solid fa-plus"></i><br>
                            <span id="days-label">7 days</span>
                        </div>
                        <div class="tl-line"></div>
                        <div class="tl-wrap">
                            <div class="tl-node tl-new"><i class="fa-solid fa-calendar-check"></i></div>
                            <div class="tl-label">New due<br><strong style="color:#16a34a;" id="new-date-preview"><?= $nuevaFechaPreview ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <h3 class="section-title">Extension Period</h3>

                <form method="POST" action="renovarPrestamo.php">
                    <input type="hidden" name="prestamo_id" value="<?= $prestamoId ?>">

                    <div class="input-group" style="margin-bottom:28px;">
                        <div class="days-radio-group">
                            <?php foreach ([3, 7, 14] as $d): ?>
                                <input type="radio" id="d<?= $d ?>" name="dias" value="<?= $d ?>"
                                       <?= $d === 7 ? 'checked' : '' ?>>
                                <label for="d<?= $d ?>"><?= $d ?> days</label>
                            <?php endforeach; ?>
                        </div>
                        <p style="font-size:12px;color:#6b7280;margin-top:10px;">
                            New due date will be:
                            <strong id="new-date-text"><?= $nuevaFechaPreview ?></strong>
                        </p>
                    </div>

                    <div class="form-actions">
                        <a href="reciboPrestamo.php?id=<?= $prestamoId ?>" class="btn-cancel">Cancel</a>
                        <button type="submit" class="btn-create">
                            <i class="fa-solid fa-rotate-right" style="margin-right:8px;"></i>
                            Confirm Renewal
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </main>

    <script>
    const baseDue = new Date('<?= date('Y-m-d', strtotime($prestamo['fecha_vencimiento'])) ?>');
    const opts    = { day:'2-digit', month:'short', year:'numeric' };

    function updatePreview() {
        const dias = parseInt(document.querySelector('input[name="dias"]:checked')?.value || 7);
        const nd   = new Date(baseDue);
        nd.setDate(nd.getDate() + dias);
        const str  = nd.toLocaleDateString('en-GB', opts);
        document.getElementById('new-date-preview').textContent = str;
        document.getElementById('new-date-text').textContent    = str;
        document.getElementById('days-label').textContent       = dias + ' day' + (dias !== 1 ? 's' : '');
    }
    document.querySelectorAll('input[name="dias"]').forEach(r => r.addEventListener('change', updatePreview));
    updatePreview();
    </script>
</body>
</html>
