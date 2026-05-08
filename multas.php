<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me  = currentUser();
$db  = getDB();

$error = '';
$exito = '';

// ── POST: marcar multa como pagada ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $multaId      = (int)  ($_POST['multa_id']     ?? 0);
    $comprobante  = trim(   $_POST['comprobante']   ?? '');

    if ($multaId <= 0) {
        $error = 'ID de multa no válido.';
    } else {
        $chk = $db->prepare("SELECT id_multa, estado_pago FROM multas WHERE id_multa = ?");
        $chk->execute([$multaId]);
        $multa = $chk->fetch();

        if (!$multa) {
            $error = 'Multa no encontrada.';
        } elseif ($multa['estado_pago']) {
            $error = 'Esta multa ya fue marcada como pagada.';
        } else {
            $db->prepare("
                UPDATE multas
                SET    estado_pago           = 1,
                       comprobante_tesoreria = ?
                WHERE  id_multa = ?
            ")->execute([$comprobante ?: null, $multaId]);

            logAction($db, $me['id'], 'pago_multa', 'multas', $multaId, [
                'comprobante' => $comprobante,
            ], 'multas');

            header('Location: multas.php?success=paid');
            exit;
        }
    }
}

$successMsg = ($_GET['success'] ?? '') === 'paid'
    ? 'Fine marked as paid successfully.' : '';

// ── Filtros ───────────────────────────────────────────────────────────────────
$q          = trim($_GET['q']           ?? '');
$estadoPago =      $_GET['estado_pago'] ?? '';   // '' | '0' | '1'
// Only accept dates in YYYY-MM-DD format to prevent malformed values reaching SQL
$fechaDesde = preg_match('/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/', $_GET['fecha_desde'] ?? '') ? $_GET['fecha_desde'] : '';
$fechaHasta = preg_match('/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/', $_GET['fecha_hasta'] ?? '') ? $_GET['fecha_hasta'] : '';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $like     = "%{$q}%";
    $where[]  = '(u.nombre_completo LIKE ? OR u.email LIKE ? OR l.titulo LIKE ? OR p.folio_recibo LIKE ?)';
    array_push($params, $like, $like, $like, $like);
}
if ($estadoPago !== '') {
    $where[]  = 'm.estado_pago = ?';
    $params[] = (int) $estadoPago;
}
if ($fechaDesde !== '') {
    $where[]  = 'DATE(m.creado_en) >= ?';
    $params[] = $fechaDesde;
}
if ($fechaHasta !== '') {
    $where[]  = 'DATE(m.creado_en) <= ?';
    $params[] = $fechaHasta;
}

$whereSQL = implode(' AND ', $where);

// Stats
$stats = $db->query("
    SELECT
        SUM(m.estado_pago = 0)                                     AS pendientes,
        SUM(m.estado_pago = 0) * 0 + SUM(CASE WHEN m.estado_pago=0 THEN m.monto_total ELSE 0 END) AS monto_pendiente,
        SUM(m.estado_pago = 1 AND DATE(m.creado_en) = CURDATE())   AS pagadas_hoy,
        COUNT(*)                                                    AS total
    FROM multas m
")->fetch();

// Count
$cntStmt = $db->prepare("
    SELECT COUNT(*)
    FROM   multas m
    JOIN   prestamos p ON m.id_prestamo = p.id_prestamo
    JOIN   usuarios  u ON p.id_usuario  = u.id_usuario
    JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN   libros    l ON e.id_libro    = l.id_libro
    WHERE  {$whereSQL}
");
$cntStmt->execute($params);
$totalRows  = (int) $cntStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Data
$dataParams = array_merge($params, [$perPage, $offset]);
$dataStmt   = $db->prepare("
    SELECT  m.id_multa, m.dias_retraso, m.monto_total, m.tipo_mora,
            m.estado_pago, m.comprobante_tesoreria, m.creado_en,
            p.folio_recibo, p.id_prestamo, p.fecha_vencimiento, p.fecha_devolucion,
            u.nombre_completo, u.email, u.tipo AS usuario_tipo,
            l.titulo, l.id_libro
    FROM    multas m
    JOIN    prestamos p ON m.id_prestamo = p.id_prestamo
    JOIN    usuarios  u ON p.id_usuario  = u.id_usuario
    JOIN    ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN    libros    l ON e.id_libro    = l.id_libro
    WHERE   {$whereSQL}
    ORDER   BY m.estado_pago ASC, m.creado_en DESC
    LIMIT   ? OFFSET ?
");
$dataStmt->execute($dataParams);
$multas = $dataStmt->fetchAll();

// ── CSV export ────────────────────────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $expStmt = $db->prepare("
        SELECT  m.id_multa, m.dias_retraso, m.monto_total, m.tipo_mora,
                m.estado_pago, m.comprobante_tesoreria, m.creado_en,
                p.folio_recibo, p.fecha_vencimiento, p.fecha_devolucion,
                u.nombre_completo, u.email, u.tipo AS usuario_tipo,
                l.titulo
        FROM    multas m
        JOIN    prestamos p ON m.id_prestamo = p.id_prestamo
        JOIN    usuarios  u ON p.id_usuario  = u.id_usuario
        JOIN    ejemplares e ON p.id_ejemplar = e.id_ejemplar
        JOIN    libros    l ON e.id_libro    = l.id_libro
        WHERE   {$whereSQL}
        ORDER   BY m.estado_pago ASC, m.creado_en DESC
    ");
    $expStmt->execute($params);
    $expRows = [];
    foreach ($expStmt->fetchAll() as $r) {
        $expRows[] = [
            '#' . $r['id_multa'],
            $r['nombre_completo'],
            $r['email'],
            tipoLabel($r['usuario_tipo']),
            $r['titulo'],
            $r['folio_recibo'],
            ucfirst($r['tipo_mora'] ?? 'retraso'),
            (int)$r['dias_retraso'],
            number_format((float)$r['monto_total'], 2),
            $r['estado_pago'] ? 'Paid' : 'Pending',
            $r['comprobante_tesoreria'] ?? '',
            substr($r['creado_en'], 0, 10),
        ];
    }
    csvDownload(
        ['ID','Borrower','Email','Role','Book','Loan Folio','Type','Days Late',
         'Amount (MXN)','Status','Voucher #','Date'],
        $expRows,
        'fines-' . date('Y-m-d') . '.csv'
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fines Management — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-ok    { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

        .stats-strip {
            display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px;
        }
        .stat-tile {
            background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:16px 20px; display:flex; align-items:center; gap:14px;
        }
        .st-icon { width:42px; height:42px; border-radius:8px;
                   display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .st-red    { background:#fee2e2; color:#dc2626; }
        .st-green  { background:#dcfce7; color:#16a34a; }
        .st-amber  { background:#fef9c3; color:#b45309; }
        .st-num { font-size:22px; font-weight:700; color:#1e293b; line-height:1; }
        .st-lbl { font-size:12px; color:#6b7280; margin-top:3px; }

        .filter-bar {
            background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:14px 18px; margin-bottom:20px;
            display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;
        }
        .fb-group { display:flex; flex-direction:column; gap:4px; }
        .fb-lbl   { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#94a3b8; }
        .filter-bar input, .filter-bar select {
            padding:8px 12px; border:1px solid #e2e8f0; border-radius:7px;
            font-size:13px; font-family:inherit; color:#374151; background:#f8fafc;
        }

        .table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; }
        table.mt { width:100%; border-collapse:collapse; font-size:13px; }
        table.mt thead th {
            padding:11px 14px; background:#f8fafc; text-align:left;
            font-size:10px; font-weight:700; letter-spacing:.8px; text-transform:uppercase;
            color:#94a3b8; border-bottom:1px solid #e2e8f0; white-space:nowrap;
        }
        table.mt tbody tr { border-bottom:1px solid #f1f5f9; transition:.15s; }
        table.mt tbody tr:last-child { border-bottom:none; }
        table.mt tbody tr:hover { background:#fafbfc; }
        table.mt td { padding:12px 14px; vertical-align:middle; }

        .fine-pill { display:inline-flex; align-items:center; gap:5px;
                     padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }
        .fp-pend { background:#fef9c3; color:#92400e; }
        .fp-paid { background:#dcfce7; color:#15803d; }

        .tipo-pill { display:inline-block; padding:2px 8px; border-radius:8px; font-size:11px; font-weight:700; }
        .tp-retraso { background:#eff6ff; color:#1d4ed8; }
        .tp-perdida { background:#fee2e2; color:#dc2626; }
        .tp-danio   { background:#fef9c3; color:#b45309; }

        /* Modal */
        .modal-backdrop {
            position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:100;
            display:none; align-items:center; justify-content:center;
        }
        .modal-backdrop.open { display:flex; }
        .modal {
            background:#fff; border-radius:12px; width:440px; max-width:95vw;
            box-shadow:0 20px 60px rgba(0,0,0,.25); overflow:hidden;
        }
        .modal-header {
            padding:18px 24px; background:#0f3524; color:#fff;
            display:flex; align-items:center; justify-content:space-between;
        }
        .modal-header h4 { font-size:15px; font-weight:700; }
        .modal-header button { background:none; border:none; color:#fff; font-size:18px; cursor:pointer; opacity:.7; }
        .modal-body  { padding:20px 24px; }
        .modal-footer{ padding:16px 24px; border-top:1px solid #f1f5f9; display:flex; justify-content:flex-end; gap:10px; }

        .pagination { display:flex; align-items:center; justify-content:center; gap:6px;
                      padding:16px; border-top:1px solid #f1f5f9; }
        .pg-btn { display:inline-flex; align-items:center; justify-content:center;
                  width:34px; height:34px; border-radius:6px; font-size:13px; font-weight:600;
                  text-decoration:none; border:1px solid #e2e8f0; color:#374151; transition:.15s; }
        .pg-btn:hover  { background:#f1f5f9; }
        .pg-btn.active { background:#0f3524; color:#fff; border-color:#0f3524; }
        .pg-btn.disabled { opacity:.4; pointer-events:none; }

        .empty-state { padding:48px; text-align:center; color:#6b7280; }
        .empty-state i { font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block; }

        @media(max-width:768px){ .stats-strip { grid-template-columns:1fr; } }
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
                <a href="multas.php" class="active">Fines</a>
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
                <h1 style="font-size:24px;font-weight:700;color:#1e293b;margin-bottom:4px;">Fines Management</h1>
                <p style="font-size:14px;color:#6b7280;">Track and collect overdue fines. Mark payments received from Tesorería.</p>
            </div>
            <div style="display:flex;gap:10px;">
                <?php
                $expQs = http_build_query(array_filter([
                    'q'=>$q,'estado_pago'=>$estadoPago,
                    'fecha_desde'=>$fechaDesde,'fecha_hasta'=>$fechaHasta,'export'=>'csv',
                ]));
                ?>
                <a href="multas.php?<?= $expQs ?>"
                   style="display:inline-flex;align-items:center;gap:8px;
                          padding:10px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;
                          color:#374151;font-weight:600;font-size:14px;text-decoration:none;"
                   title="Export current filter to CSV">
                    <i class="fa-solid fa-file-csv"></i> Export CSV
                </a>
                <a href="reportes.php" style="display:inline-flex;align-items:center;gap:8px;
                   padding:10px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;
                   color:#374151;font-weight:600;font-size:14px;text-decoration:none;">
                    <i class="fa-solid fa-chart-bar"></i> Reports
                </a>
            </div>
        </div>

        <?php if ($successMsg): ?>
            <div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= e($successMsg) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-strip">
            <div class="stat-tile">
                <div class="st-icon st-red"><i class="fa-solid fa-circle-dollar-sign"></i></div>
                <div>
                    <div class="st-num">$<?= number_format((float)($stats['monto_pendiente'] ?? 0), 2) ?></div>
                    <div class="st-lbl">Total Pending (MXN)</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-amber"><i class="fa-solid fa-clock"></i></div>
                <div>
                    <div class="st-num"><?= (int)($stats['pendientes'] ?? 0) ?></div>
                    <div class="st-lbl">Unpaid Fines</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-green"><i class="fa-solid fa-check-circle"></i></div>
                <div>
                    <div class="st-num"><?= (int)($stats['pagadas_hoy'] ?? 0) ?></div>
                    <div class="st-lbl">Paid Today</div>
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="GET" action="multas.php" class="filter-bar">
            <div class="fb-group" style="flex:1;min-width:180px;">
                <div class="fb-lbl">Search</div>
                <input type="text" name="q" placeholder="User, book title or folio…" value="<?= e($q) ?>">
            </div>
            <div class="fb-group">
                <div class="fb-lbl">Status</div>
                <select name="estado_pago">
                    <option value="">All</option>
                    <option value="0" <?= $estadoPago === '0' ? 'selected' : '' ?>>Pending</option>
                    <option value="1" <?= $estadoPago === '1' ? 'selected' : '' ?>>Paid</option>
                </select>
            </div>
            <div class="fb-group">
                <div class="fb-lbl">From</div>
                <input type="date" name="fecha_desde" value="<?= e($fechaDesde) ?>">
            </div>
            <div class="fb-group">
                <div class="fb-lbl">To</div>
                <input type="date" name="fecha_hasta" value="<?= e($fechaHasta) ?>">
            </div>
            <button type="submit" class="btn-create" style="height:38px;padding:0 16px;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
            <?php if ($q || $estadoPago !== '' || $fechaDesde || $fechaHasta): ?>
                <a href="multas.php" style="display:inline-flex;align-items:center;gap:6px;
                   height:38px;padding:0 14px;border:1px solid #e2e8f0;border-radius:7px;
                   font-size:13px;color:#6b7280;text-decoration:none;">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="table-wrap">
            <?php if (empty($multas)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-face-smile"></i>
                    No fines found<?= ($q || $estadoPago !== '') ? ' for the current filters.' : '!' ?>
                </div>
            <?php else: ?>
            <table class="mt">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Borrower</th>
                        <th>Book</th>
                        <th>Folio</th>
                        <th>Type</th>
                        <th>Days Late</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($multas as $m): ?>
                    <tr>
                        <td style="font-size:12px;color:#94a3b8;font-family:'Courier New',monospace;">#<?= $m['id_multa'] ?></td>
                        <td>
                            <div style="font-weight:600;"><?= e($m['nombre_completo']) ?></div>
                            <div style="font-size:11px;color:#6b7280;"><?= e($m['email']) ?></div>
                        </td>
                        <td>
                            <a href="bookInformation.php?id=<?= $m['id_libro'] ?>"
                               style="color:#1e293b;font-weight:600;text-decoration:none;font-size:13px;">
                                <?= e(mb_strimwidth($m['titulo'], 0, 40, '…')) ?>
                            </a>
                        </td>
                        <td>
                            <a href="reciboPrestamo.php?id=<?= $m['id_prestamo'] ?>"
                               style="font-family:'Courier New',monospace;font-size:11px;color:#0f3524;font-weight:700;text-decoration:none;">
                                <?= e($m['folio_recibo']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="tipo-pill tp-<?= $m['tipo_mora'] === 'perdida' ? 'perdida' : ($m['tipo_mora'] === 'daño' ? 'danio' : 'retraso') ?>">
                                <?= ucfirst($m['tipo_mora'] ?? 'retraso') ?>
                            </span>
                        </td>
                        <td style="text-align:center;font-weight:600;color:<?= (int)$m['dias_retraso'] > 0 ? '#dc2626' : '#6b7280' ?>">
                            <?= (int)$m['dias_retraso'] ?>d
                        </td>
                        <td style="font-weight:700;font-size:14px;">
                            $<?= number_format((float)$m['monto_total'], 2) ?> <span style="font-size:10px;font-weight:400;color:#94a3b8;">MXN</span>
                        </td>
                        <td>
                            <span class="fine-pill <?= $m['estado_pago'] ? 'fp-paid' : 'fp-pend' ?>">
                                <?= $m['estado_pago'] ? '✓ Paid' : '⏳ Pending' ?>
                            </span>
                            <?php if ($m['comprobante_tesoreria']): ?>
                                <div style="font-size:10px;color:#6b7280;margin-top:3px;font-family:'Courier New',monospace;">
                                    <?= e($m['comprobante_tesoreria']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px;white-space:nowrap;">
                            <?= date('d M Y', strtotime($m['creado_en'])) ?>
                        </td>
                        <td>
                            <?php if (!$m['estado_pago']): ?>
                                <button class="btn-create" style="padding:6px 14px;font-size:12px;"
                                        data-id="<?= (int)$m['id_multa'] ?>"
                                        data-name="<?= e($m['nombre_completo']) ?>"
                                        data-amount="<?= number_format((float)$m['monto_total'],2) ?>"
                                        onclick="openPayModal(this.dataset.id, this.dataset.name, parseFloat(this.dataset.amount))">
                                    <i class="fa-solid fa-check"></i> Mark Paid
                                </button>
                            <?php else: ?>
                                <span style="font-size:12px;color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $qp = http_build_query(array_filter(['q'=>$q,'estado_pago'=>$estadoPago,'fecha_desde'=>$fechaDesde,'fecha_hasta'=>$fechaHasta]));
                $qp = $qp ? "&{$qp}" : '';
                ?>
                <a href="?page=<?= max(1,$page-1) ?><?= $qp ?>" class="pg-btn <?= $page<=1?'disabled':'' ?>">
                    <i class="fa-solid fa-chevron-left" style="font-size:11px;"></i>
                </a>
                <?php for ($i=max(1,$page-2); $i<=min($totalPages,$page+2); $i++): ?>
                    <a href="?page=<?= $i ?><?= $qp ?>" class="pg-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($totalPages,$page+1) ?><?= $qp ?>" class="pg-btn <?= $page>=$totalPages?'disabled':'' ?>">
                    <i class="fa-solid fa-chevron-right" style="font-size:11px;"></i>
                </a>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

    </main>

    <!-- Modal: marcar como pagada -->
    <div class="modal-backdrop" id="payModal">
        <div class="modal">
            <div class="modal-header">
                <h4><i class="fa-solid fa-circle-dollar-sign" style="margin-right:8px;"></i>Mark Fine as Paid</h4>
                <button onclick="closePayModal()">&times;</button>
            </div>
            <form method="POST" action="multas.php">
                <input type="hidden" name="multa_id" id="modal_multa_id">
                <div class="modal-body">
                    <p style="font-size:14px;margin-bottom:16px;">
                        Confirm payment received from <strong id="modal_nombre"></strong>
                        — Amount: <strong id="modal_monto" style="color:#dc2626;"></strong> MXN
                    </p>
                    <div class="input-group">
                        <label for="comprobante">Receipt / Voucher # <span style="color:#6b7280;font-weight:400;">(optional)</span></label>
                        <input type="text" id="comprobante" name="comprobante" class="base-input"
                               placeholder="e.g. TES-2026-001234">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closePayModal()">Cancel</button>
                    <button type="submit" class="btn-create">
                        <i class="fa-solid fa-check" style="margin-right:6px;"></i>Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
    function openPayModal(id, nombre, monto) {
        document.getElementById('modal_multa_id').value = id;
        document.getElementById('modal_nombre').textContent = nombre;
        document.getElementById('modal_monto').textContent  = '$' + monto.toFixed(2);
        document.getElementById('comprobante').value = '';
        document.getElementById('payModal').classList.add('open');
    }
    function closePayModal() {
        document.getElementById('payModal').classList.remove('open');
    }
    document.getElementById('payModal').addEventListener('click', function(e){
        if (e.target === this) closePayModal();
    });
    </script>

</body>
</html>
