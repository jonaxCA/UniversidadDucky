<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me = currentUser();
$db = getDB();

// ── Parámetros ────────────────────────────────────────────────────────────────
$mesActual = date('Y-m');
$mes       = $_GET['mes'] ?? $mesActual;   // YYYY-MM
$venceDias = (int) ($_GET['vence_dias'] ?? 3);

// ── Reporte 1: Usuarios con multas pendientes (Servicios Escolares) ───────────
$r1 = $db->query("
    SELECT  u.id_usuario, u.nombre_completo, u.email, u.tipo,
            COUNT(m.id_multa)        AS total_multas,
            SUM(m.monto_total)       AS monto_total
    FROM    multas m
    JOIN    prestamos p ON m.id_prestamo = p.id_prestamo
    JOIN    usuarios  u ON p.id_usuario  = u.id_usuario
    WHERE   m.estado_pago = 0
    GROUP   BY u.id_usuario
    ORDER   BY monto_total DESC
    LIMIT   50
")->fetchAll();

// ── Reporte 2: Multas por mes (Tesorería) ─────────────────────────────────────
$r2 = $db->query("
    SELECT  DATE_FORMAT(m.creado_en, '%Y-%m') AS mes,
            COUNT(*)                           AS cantidad,
            SUM(m.monto_total)                 AS monto_generado,
            SUM(CASE WHEN m.estado_pago=1 THEN m.monto_total ELSE 0 END) AS monto_cobrado,
            SUM(CASE WHEN m.estado_pago=0 THEN m.monto_total ELSE 0 END) AS monto_pendiente
    FROM    multas m
    GROUP   BY DATE_FORMAT(m.creado_en, '%Y-%m')
    ORDER   BY mes DESC
    LIMIT   24
")->fetchAll();

// ── Reporte 3: Top 10 libros más prestados ────────────────────────────────────
$r3 = $db->query("
    SELECT  l.id_libro, l.titulo, l.autor,
            c.nombre AS categoria,
            COUNT(p.id_prestamo) AS total_prestamos,
            SUM(p.estado IN ('activo','vencido')) AS prestamos_activos
    FROM    libros l
    JOIN    ejemplares e  ON e.id_libro    = l.id_libro
    JOIN    prestamos  p  ON p.id_ejemplar = e.id_ejemplar
    LEFT JOIN categorias c ON c.id_categoria = l.id_categoria
    GROUP   BY l.id_libro
    ORDER   BY total_prestamos DESC
    LIMIT   10
")->fetchAll();

// ── Reporte 4: Préstamos que vencen pronto ────────────────────────────────────
$r4 = $db->prepare("
    SELECT  p.id_prestamo, p.folio_recibo, p.fecha_vencimiento,
            u.nombre_completo, u.email, u.tipo AS usuario_tipo,
            l.titulo, l.id_libro,
            DATEDIFF(p.fecha_vencimiento, NOW()) AS dias_restantes
    FROM    prestamos p
    JOIN    usuarios  u  ON p.id_usuario  = u.id_usuario
    JOIN    ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN    libros    l  ON e.id_libro    = l.id_libro
    WHERE   p.estado = 'activo'
      AND   p.fecha_vencimiento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)
    ORDER   BY p.fecha_vencimiento ASC
    LIMIT   50
");
$r4->execute([$venceDias]);
$vencenProximos = $r4->fetchAll();

// ── CSV exports (must run before HTML output) ─────────────────────────────────
switch ($_GET['export'] ?? '') {
    case 'r1':
        $expRows = [];
        foreach ($r1 as $row) {
            $expRows[] = [
                $row['nombre_completo'], $row['email'], tipoLabel($row['tipo']),
                (int)$row['total_multas'],
                number_format((float)$row['monto_total'], 2),
            ];
        }
        csvDownload(
            ['Name','Email','Role','Pending Fines','Total Owed (MXN)'],
            $expRows, 'pending-fines-' . date('Y-m-d') . '.csv'
        );
        break;
    case 'r2':
        $expRows = [];
        foreach ($r2 as $row) {
            $expRows[] = [
                $row['mes'], (int)$row['cantidad'],
                number_format((float)$row['monto_generado'],  2),
                number_format((float)$row['monto_cobrado'],   2),
                number_format((float)$row['monto_pendiente'], 2),
            ];
        }
        csvDownload(
            ['Month','Count','Generated (MXN)','Collected (MXN)','Pending (MXN)'],
            $expRows, 'fines-monthly-' . date('Y-m-d') . '.csv'
        );
        break;
    case 'r3':
        $expRows = [];
        foreach ($r3 as $i => $row) {
            $expRows[] = [
                $i + 1, $row['titulo'], $row['autor'] ?? '',
                $row['categoria'] ?? '',
                (int)$row['total_prestamos'],
                (int)($row['prestamos_activos'] ?? 0),
            ];
        }
        csvDownload(
            ['Rank','Title','Author','Category','Total Loans','Currently Out'],
            $expRows, 'top-books-' . date('Y-m-d') . '.csv'
        );
        break;
}

// ── Reporte 5: Préstamos vencidos sin multa (inconsistencias) ─────────────────
$r5 = $db->query("
    SELECT  p.id_prestamo, p.folio_recibo, p.fecha_vencimiento,
            u.nombre_completo,
            l.titulo,
            DATEDIFF(NOW(), p.fecha_vencimiento) AS dias_retraso
    FROM    prestamos p
    JOIN    usuarios  u  ON p.id_usuario  = u.id_usuario
    JOIN    ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN    libros    l  ON e.id_libro    = l.id_libro
    LEFT JOIN multas  m  ON m.id_prestamo = p.id_prestamo
    WHERE   p.estado = 'vencido'
      AND   m.id_multa IS NULL
    ORDER   BY dias_retraso DESC
    LIMIT   30
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .reports-grid { display:grid; grid-template-columns:1fr 1fr; gap:24px; }
        @media(max-width:900px){ .reports-grid { grid-template-columns:1fr; } }
        .report-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; overflow:hidden; }
        .report-card.full-width { grid-column:span 2; }
        @media(max-width:900px){ .report-card.full-width { grid-column:span 1; } }

        .rc-header {
            padding:14px 20px; border-bottom:1px solid #f1f5f9;
            display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;
        }
        .rc-header .rc-title { font-size:15px; font-weight:700; color:#1e293b; display:flex; align-items:center; gap:10px; }
        .rc-header .rc-icon { width:32px; height:32px; border-radius:7px;
                               display:flex; align-items:center; justify-content:center; font-size:14px; flex-shrink:0; }
        .ri-red    { background:#fee2e2; color:#dc2626; }
        .ri-blue   { background:#dbeafe; color:#1d4ed8; }
        .ri-green  { background:#dcfce7; color:#16a34a; }
        .ri-amber  { background:#fef9c3; color:#b45309; }
        .ri-purple { background:#ede9fe; color:#7c3aed; }

        .rc-body { padding:0; max-height:360px; overflow-y:auto; }

        table.rt { width:100%; border-collapse:collapse; font-size:13px; }
        table.rt thead th {
            position:sticky; top:0; padding:9px 14px; background:#f8fafc;
            text-align:left; font-size:10px; font-weight:700; letter-spacing:.8px;
            text-transform:uppercase; color:#94a3b8; border-bottom:1px solid #e2e8f0; }
        table.rt tbody tr { border-bottom:1px solid #f1f5f9; }
        table.rt tbody tr:last-child { border-bottom:none; }
        table.rt tbody tr:hover { background:#fafbfc; }
        table.rt td { padding:10px 14px; vertical-align:middle; }

        .rc-footer { padding:10px 16px; border-top:1px solid #f1f5f9; text-align:right; }
        .btn-print-report {
            display:inline-flex; align-items:center; gap:6px;
            padding:6px 14px; border:1px solid #e2e8f0; border-radius:7px;
            font-size:12px; font-weight:600; color:#374151; background:#fff; cursor:pointer;
            text-decoration:none;
        }
        .btn-print-report:hover { background:#f8fafc; }

        .empty-mini { padding:24px; text-align:center; color:#94a3b8; font-size:13px; }
        .empty-mini i { display:block; font-size:28px; color:#e2e8f0; margin-bottom:8px; }

        .badge-tipo { display:inline-block; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:700; }
        .bt-alumno   { background:#ede9fe; color:#7c3aed; }
        .bt-profesor { background:#dbeafe; color:#1d4ed8; }
        .bt-admin    { background:#dcfce7; color:#15803d; }

        .dias-bar { display:inline-flex; align-items:center; gap:8px; }
        .dias-bar input[type=number] {
            width:60px; padding:5px 8px; border:1px solid #e2e8f0; border-radius:6px;
            font-size:13px; font-family:inherit; }

        @media print {
            body { background:#fff; }
            header.top-navbar, .action-bar, .rc-footer, .dias-bar { display:none !important; }
            .report-card { break-inside:avoid; border:1px solid #ccc; margin-bottom:24px; }
            .rc-body { max-height:none; overflow:visible; }
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

    <main style="max-width:1200px;margin:0 auto;padding:32px 20px;">

        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:28px;">
            <div>
                <h1 style="font-size:24px;font-weight:700;color:#1e293b;margin-bottom:4px;">Reports</h1>
                <p style="font-size:14px;color:#6b7280;">Operational reports for Servicios Escolares, Tesorería and library management.</p>
            </div>
            <button onclick="window.print()" class="btn-print-report" style="padding:10px 18px;font-size:14px;">
                <i class="fa-solid fa-print"></i> Print All Reports
            </button>
        </div>

        <div class="reports-grid">

            <!-- ── R1: Usuarios con multas pendientes ──────────────────────── -->
            <div class="report-card">
                <div class="rc-header">
                    <div class="rc-title">
                        <div class="rc-icon ri-red"><i class="fa-solid fa-circle-exclamation"></i></div>
                        Users with Pending Fines
                    </div>
                    <span style="font-size:12px;color:#6b7280;">For Servicios Escolares</span>
                </div>
                <div class="rc-body">
                    <?php if (empty($r1)): ?>
                        <div class="empty-mini"><i class="fa-solid fa-face-smile"></i>No pending fines!</div>
                    <?php else: ?>
                    <table class="rt">
                        <thead><tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Fines</th>
                            <th>Total Owed</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($r1 as $row): ?>
                            <tr>
                                <td>
                                    <div style="font-weight:600;"><?= e($row['nombre_completo']) ?></div>
                                    <div style="font-size:11px;color:#6b7280;"><?= e($row['email']) ?></div>
                                </td>
                                <td>
                                    <span class="badge-tipo bt-<?= $row['tipo'] === 'administrador' ? 'admin' : ($row['tipo'] === 'profesor' ? 'profesor' : 'alumno') ?>">
                                        <?= tipoLabel($row['tipo']) ?>
                                    </span>
                                </td>
                                <td style="text-align:center;font-weight:600;"><?= (int)$row['total_multas'] ?></td>
                                <td style="font-weight:700;color:#dc2626;">
                                    $<?= number_format((float)$row['monto_total'],2) ?> <span style="font-size:10px;font-weight:400;color:#94a3b8;">MXN</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <div class="rc-footer" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <a href="reportes.php?export=r1" class="btn-print-report">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </a>
                    <a href="multas.php?estado_pago=0" class="btn-print-report">
                        <i class="fa-solid fa-arrow-right"></i> Manage Fines
                    </a>
                </div>
            </div>

            <!-- ── R2: Multas por mes (Tesorería) ─────────────────────────── -->
            <div class="report-card">
                <div class="rc-header">
                    <div class="rc-title">
                        <div class="rc-icon ri-blue"><i class="fa-solid fa-chart-bar"></i></div>
                        Fines by Month
                    </div>
                    <span style="font-size:12px;color:#6b7280;">For Tesorería</span>
                </div>
                <div class="rc-body">
                    <?php if (empty($r2)): ?>
                        <div class="empty-mini"><i class="fa-solid fa-chart-bar"></i>No fine data yet.</div>
                    <?php else: ?>
                    <table class="rt">
                        <thead><tr>
                            <th>Month</th>
                            <th>Count</th>
                            <th>Generated</th>
                            <th>Collected</th>
                            <th>Pending</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($r2 as $row): ?>
                            <tr>
                                <td style="font-weight:600;"><?= e($row['mes']) ?></td>
                                <td style="text-align:center;"><?= (int)$row['cantidad'] ?></td>
                                <td>$<?= number_format((float)$row['monto_generado'],2) ?></td>
                                <td style="color:#16a34a;font-weight:600;">$<?= number_format((float)$row['monto_cobrado'],2) ?></td>
                                <td style="color:<?= (float)$row['monto_pendiente'] > 0 ? '#dc2626' : '#94a3b8' ?>;font-weight:600;">
                                    $<?= number_format((float)$row['monto_pendiente'],2) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <div class="rc-footer" style="display:flex;align-items:center;justify-content:space-between;gap:8px;">
                    <a href="reportes.php?export=r2" class="btn-print-report">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </a>
                    <button onclick="window.print()" class="btn-print-report">
                        <i class="fa-solid fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- ── R3: Top 10 libros ──────────────────────────────────────── -->
            <div class="report-card">
                <div class="rc-header">
                    <div class="rc-title">
                        <div class="rc-icon ri-green"><i class="fa-solid fa-trophy"></i></div>
                        Top 10 Most Borrowed Books
                    </div>
                </div>
                <div class="rc-body">
                    <?php if (empty($r3)): ?>
                        <div class="empty-mini"><i class="fa-solid fa-book"></i>No loan data yet.</div>
                    <?php else: ?>
                    <table class="rt">
                        <thead><tr>
                            <th>#</th>
                            <th>Book</th>
                            <th>Category</th>
                            <th>Total Loans</th>
                            <th>Active</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($r3 as $i => $row): ?>
                            <tr>
                                <td style="font-weight:700;color:<?= $i === 0 ? '#b45309' : ($i === 1 ? '#6b7280' : ($i === 2 ? '#92400e' : '#94a3b8')) ?>;font-size:14px;">
                                    <?= $i+1 ?>
                                </td>
                                <td>
                                    <a href="bookInformation.php?id=<?= $row['id_libro'] ?>"
                                       style="color:#1e293b;font-weight:600;text-decoration:none;">
                                        <?= e(mb_strimwidth($row['titulo'],0,38,'…')) ?>
                                    </a>
                                    <div style="font-size:11px;color:#6b7280;"><?= e($row['autor'] ?? '—') ?></div>
                                </td>
                                <td style="font-size:12px;color:#6b7280;"><?= e($row['categoria'] ?? '—') ?></td>
                                <td style="text-align:center;font-weight:700;font-size:15px;"><?= (int)$row['total_prestamos'] ?></td>
                                <td style="text-align:center;">
                                    <?php if ($row['prestamos_activos'] > 0): ?>
                                        <span style="background:#dcfce7;color:#16a34a;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">
                                            <?= (int)$row['prestamos_activos'] ?> out
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#94a3b8;font-size:12px;">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
                <div class="rc-footer">
                    <a href="reportes.php?export=r3" class="btn-print-report">
                        <i class="fa-solid fa-file-csv"></i> Export CSV
                    </a>
                </div>
            </div>

            <!-- ── R4: Vencen pronto ───────────────────────────────────────── -->
            <div class="report-card">
                <div class="rc-header">
                    <div class="rc-title">
                        <div class="rc-icon ri-amber"><i class="fa-solid fa-hourglass-half"></i></div>
                        Loans Due Soon
                    </div>
                    <form method="GET" action="reportes.php" style="display:inline-flex;align-items:center;gap:6px;">
                        <div class="dias-bar">
                            <span style="font-size:12px;color:#6b7280;">Next</span>
                            <input type="number" name="vence_dias" value="<?= $venceDias ?>" min="1" max="30">
                            <span style="font-size:12px;color:#6b7280;">days</span>
                            <button type="submit" class="btn-print-report" style="padding:5px 10px;">Go</button>
                        </div>
                    </form>
                </div>
                <div class="rc-body">
                    <?php if (empty($vencenProximos)): ?>
                        <div class="empty-mini">
                            <i class="fa-solid fa-calendar-check"></i>
                            No loans due in the next <?= $venceDias ?> day<?= $venceDias !== 1 ? 's' : '' ?>.
                        </div>
                    <?php else: ?>
                    <table class="rt">
                        <thead><tr>
                            <th>Folio</th>
                            <th>Borrower</th>
                            <th>Book</th>
                            <th>Due</th>
                            <th>Days Left</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($vencenProximos as $row): ?>
                            <tr>
                                <td style="font-family:'Courier New',monospace;font-size:11px;color:#0f3524;font-weight:700;">
                                    <a href="reciboPrestamo.php?id=<?= $row['id_prestamo'] ?>" style="color:inherit;text-decoration:none;">
                                        <?= e($row['folio_recibo']) ?>
                                    </a>
                                </td>
                                <td>
                                    <div style="font-weight:600;font-size:13px;"><?= e($row['nombre_completo']) ?></div>
                                    <div style="font-size:11px;color:#6b7280;"><?= tipoLabel($row['usuario_tipo']) ?></div>
                                </td>
                                <td style="font-size:12px;"><?= e(mb_strimwidth($row['titulo'],0,30,'…')) ?></td>
                                <td style="font-size:12px;white-space:nowrap;">
                                    <?= date('d M Y', strtotime($row['fecha_vencimiento'])) ?>
                                </td>
                                <td style="text-align:center;">
                                    <span style="background:<?= $row['dias_restantes'] <= 1 ? '#fee2e2' : '#fef9c3' ?>;
                                                 color:<?= $row['dias_restantes'] <= 1 ? '#dc2626' : '#b45309' ?>;
                                                 padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;">
                                        <?= $row['dias_restantes'] === 0 ? 'Today' : (int)$row['dias_restantes'].'d' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── R5: Vencidos sin multa ─────────────────────────────────── -->
            <?php if (!empty($r5)): ?>
            <div class="report-card full-width">
                <div class="rc-header">
                    <div class="rc-title">
                        <div class="rc-icon ri-purple"><i class="fa-solid fa-triangle-exclamation"></i></div>
                        Overdue Loans Without Fine Record
                        <span style="background:#fee2e2;color:#dc2626;padding:3px 10px;border-radius:12px;font-size:11px;">
                            <?= count($r5) ?> inconsistencies
                        </span>
                    </div>
                    <span style="font-size:12px;color:#6b7280;">These overdue loans need a return processed</span>
                </div>
                <div class="rc-body" style="max-height:280px;">
                    <table class="rt">
                        <thead><tr>
                            <th>Folio</th>
                            <th>Borrower</th>
                            <th>Book</th>
                            <th>Due Date</th>
                            <th>Days Late</th>
                            <th>Action</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($r5 as $row): ?>
                            <tr>
                                <td style="font-family:'Courier New',monospace;font-size:11px;color:#0f3524;font-weight:700;">
                                    <?= e($row['folio_recibo']) ?>
                                </td>
                                <td style="font-weight:600;"><?= e($row['nombre_completo']) ?></td>
                                <td style="font-size:12px;"><?= e(mb_strimwidth($row['titulo'],0,35,'…')) ?></td>
                                <td style="font-size:12px;"><?= date('d M Y', strtotime($row['fecha_vencimiento'])) ?></td>
                                <td style="text-align:center;">
                                    <span style="background:#fee2e2;color:#dc2626;padding:2px 8px;border-radius:10px;font-size:12px;font-weight:700;">
                                        +<?= (int)$row['dias_retraso'] ?>d
                                    </span>
                                </td>
                                <td>
                                    <a href="devolucion.php?prestamo_id=<?= $row['id_prestamo'] ?>"
                                       style="font-size:12px;font-weight:600;color:#1d4ed8;text-decoration:none;">
                                        <i class="fa-solid fa-rotate-left"></i> Process Return
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        </div><!-- /reports-grid -->
    </main>

</body>
</html>
