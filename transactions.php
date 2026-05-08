<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$me  = currentUser();
$db  = getDB();
$canManage = in_array($me['tipo'], ['administrador', 'bibliotecario']);

// Actualizar vencidos
$db->exec("UPDATE prestamos SET estado = 'vencido'
           WHERE estado = 'activo' AND fecha_vencimiento < NOW()");

// ── Filtros ───────────────────────────────────────────────────────────────────
$q          = trim($_GET['q']           ?? '');
$estado     =      $_GET['estado']      ?? '';
$tipo       =      $_GET['tipo']        ?? '';
// Only accept dates in YYYY-MM-DD format to prevent malformed values reaching SQL
$fechaDesde = preg_match('/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/', $_GET['fecha_desde'] ?? '') ? $_GET['fecha_desde'] : '';
$fechaHasta = preg_match('/^\d{4}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12]\d|3[01])$/', $_GET['fecha_hasta'] ?? '') ? $_GET['fecha_hasta'] : '';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 15;
$offset     = ($page - 1) * $perPage;

// Si el usuario no es staff, solo ve sus propios préstamos
$soloMios = !$canManage;

// ── Query ─────────────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($soloMios) {
    $where[]  = 'p.id_usuario = ?';
    $params[] = $me['id'];
}

if ($q !== '') {
    $like     = "%{$q}%";
    $where[]  = '(u.nombre_completo LIKE ? OR u.email LIKE ? OR l.titulo LIKE ? OR p.folio_recibo LIKE ? OR e.codigo_inventario LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
}
if ($estado !== '') {
    $where[]  = 'p.estado = ?';
    $params[] = $estado;
}
if ($tipo !== '') {
    $where[]  = 'p.tipo = ?';
    $params[] = $tipo;
}
if ($fechaDesde !== '') {
    $where[]  = 'DATE(p.fecha_salida) >= ?';
    $params[] = $fechaDesde;
}
if ($fechaHasta !== '') {
    $where[]  = 'DATE(p.fecha_salida) <= ?';
    $params[] = $fechaHasta;
}

$whereSQL = implode(' AND ', $where);

// Count total
$cntStmt = $db->prepare("
    SELECT COUNT(*)
    FROM   prestamos p
    JOIN   usuarios  u  ON p.id_usuario  = u.id_usuario
    JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN   libros    l  ON e.id_libro    = l.id_libro
    WHERE  {$whereSQL}
");
$cntStmt->execute($params);
$totalRows = (int) $cntStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Fetch page
$dataParams   = array_merge($params, [$perPage, $offset]);
$dataStmt     = $db->prepare("
    SELECT  p.id_prestamo, p.folio_recibo, p.estado, p.tipo,
            p.fecha_salida, p.fecha_vencimiento, p.fecha_devolucion,
            p.renovaciones_conteo,
            u.nombre_completo, u.email, u.tipo AS usuario_tipo, u.id_usuario,
            e.codigo_inventario, e.biblioteca,
            l.titulo, l.autor, l.id_libro,
            m.monto_total AS multa_monto, m.estado_pago AS multa_pagada
    FROM    prestamos p
    JOIN    usuarios  u  ON p.id_usuario  = u.id_usuario
    JOIN    ejemplares e ON p.id_ejemplar = e.id_ejemplar
    JOIN    libros    l  ON e.id_libro    = l.id_libro
    LEFT JOIN multas  m  ON m.id_prestamo = p.id_prestamo
    WHERE   {$whereSQL}
    ORDER   BY p.fecha_salida DESC
    LIMIT   ? OFFSET ?
");
$dataStmt->execute($dataParams);
$prestamos = $dataStmt->fetchAll();

// ── Stats para el banner ──────────────────────────────────────────────────────
$statsBase  = $soloMios ? "WHERE p.id_usuario = {$me['id']}" : '';
$statsRow   = $db->query("
    SELECT
        SUM(p.estado = 'activo')   AS activos,
        SUM(p.estado = 'vencido')  AS vencidos,
        SUM(p.estado = 'devuelto' AND DATE(p.fecha_devolucion) = CURDATE()) AS hoy_devueltos
    FROM prestamos p {$statsBase}
")->fetch();

// Flash messages
$successMsg = match ($_GET['success'] ?? '') {
    'loan_registered' => 'Loan registered successfully.',
    default            => '',
};

// ── CSV export (must run before any HTML output) ──────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $expStmt = $db->prepare("
        SELECT  p.folio_recibo, u.nombre_completo, u.email, u.tipo AS usuario_tipo,
                l.titulo, l.autor, e.codigo_inventario, e.biblioteca,
                p.fecha_salida, p.fecha_vencimiento, p.fecha_devolucion,
                p.estado, p.tipo, p.renovaciones_conteo,
                m.monto_total AS multa_monto, m.estado_pago AS multa_pagada
        FROM    prestamos p
        JOIN    usuarios  u  ON p.id_usuario  = u.id_usuario
        JOIN    ejemplares e ON p.id_ejemplar = e.id_ejemplar
        JOIN    libros    l  ON e.id_libro    = l.id_libro
        LEFT JOIN multas  m  ON m.id_prestamo = p.id_prestamo
        WHERE   {$whereSQL}
        ORDER   BY p.fecha_salida DESC
    ");
    $expStmt->execute($params);
    $expRows = [];
    foreach ($expStmt->fetchAll() as $r) {
        $expRows[] = [
            $r['folio_recibo'],
            $r['nombre_completo'],
            $r['email'],
            tipoLabel($r['usuario_tipo']),
            $r['titulo'],
            $r['autor'] ?? '',
            $r['codigo_inventario'],
            $r['biblioteca'] ?? '',
            substr($r['fecha_salida'],    0, 10),
            substr($r['fecha_vencimiento'],0, 10),
            $r['fecha_devolucion'] ? substr($r['fecha_devolucion'], 0, 10) : '',
            estadoPrestamoLabel($r['estado']),
            ucfirst($r['tipo']),
            (int)$r['renovaciones_conteo'],
            $r['multa_monto'] !== null ? number_format((float)$r['multa_monto'], 2) : '',
            $r['multa_monto'] !== null ? ($r['multa_pagada'] ? 'Paid' : 'Pending') : '',
        ];
    }
    csvDownload(
        ['Folio','Borrower','Email','Role','Book','Author','Inventory Code','Library',
         'Issued','Due Date','Returned','Status','Type','Renewals','Fine (MXN)','Fine Status'],
        $expRows,
        'loans-' . date('Y-m-d') . '.csv'
    );
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Transactions — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        /* Stats strip */
        .stats-strip {
            display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px;
        }
        .stat-tile {
            background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:16px 20px; display:flex; align-items:center; gap:14px;
        }
        .stat-tile .st-icon {
            width:42px; height:42px; border-radius:8px;
            display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0;
        }
        .st-green { background:#dcfce7; color:#15803d; }
        .st-amber { background:#fef9c3; color:#b45309; }
        .st-blue  { background:#dbeafe; color:#1d4ed8; }
        .stat-tile .st-num { font-size:24px; font-weight:700; color:#1e293b; line-height:1; }
        .stat-tile .st-lbl { font-size:12px; color:#6b7280; margin-top:3px; }

        /* Filter bar */
        .filter-bar {
            background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:16px 20px; margin-bottom:20px;
            display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;
        }
        .filter-bar .fb-group { display:flex; flex-direction:column; gap:4px; }
        .filter-bar .fb-lbl { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#94a3b8; }
        .filter-bar input, .filter-bar select {
            padding:8px 12px; border:1px solid #e2e8f0; border-radius:7px;
            font-size:13px; font-family:inherit; color:#374151; background:#f8fafc;
        }
        .filter-bar input:focus, .filter-bar select:focus { outline:none; border-color:#0f3524; }
        .filter-bar .fb-search { flex:1; min-width:200px; }

        /* Table */
        .loans-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; }
        .loans-table { width:100%; border-collapse:collapse; font-size:13px; }
        .loans-table thead th {
            padding:11px 14px; background:#f8fafc; text-align:left;
            font-size:10px; font-weight:700; letter-spacing:.8px; text-transform:uppercase;
            color:#94a3b8; border-bottom:1px solid #e2e8f0; white-space:nowrap;
        }
        .loans-table tbody tr { border-bottom:1px solid #f1f5f9; transition:.15s; }
        .loans-table tbody tr:last-child { border-bottom:none; }
        .loans-table tbody tr:hover { background:#fafbfc; }
        .loans-table td { padding:12px 14px; vertical-align:middle; }

        /* Loan state badges */
        .lb { display:inline-flex; align-items:center; gap:5px;
              padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; white-space:nowrap; }
        .lb-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
        .loan-active   { background:#dcfce7; color:#15803d; }
        .loan-active   .lb-dot { background:#16a34a; }
        .loan-overdue  { background:#fef9c3; color:#92400e; }
        .loan-overdue  .lb-dot { background:#b45309; }
        .loan-returned { background:#f1f5f9; color:#475569; }
        .loan-returned .lb-dot { background:#64748b; }
        .loan-lost     { background:#fee2e2; color:#991b1b; }
        .loan-lost     .lb-dot { background:#dc2626; }

        /* Fine indicator */
        .fine-pill { display:inline-flex; align-items:center; gap:5px;
                     padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; }
        .fine-pending { background:#fef9c3; color:#92400e; }
        .fine-paid    { background:#dcfce7; color:#15803d; }

        /* Actions */
        .actions-cell { display:flex; gap:6px; flex-wrap:nowrap; }
        .act-btn {
            display:inline-flex; align-items:center; gap:5px;
            padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600;
            text-decoration:none; border:1px solid; white-space:nowrap; transition:.15s;
        }
        .act-receipt { background:#fff;    color:#374151; border-color:#e2e8f0; }
        .act-receipt:hover { background:#f1f5f9; }
        .act-return  { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .act-return:hover  { background:#dbeafe; }
        .act-renew   { background:#fffbeb; color:#b45309; border-color:#fde68a; }
        .act-renew:hover   { background:#fef3c7; }

        /* Empty state */
        .empty-state { padding:48px; text-align:center; color:#6b7280; }
        .empty-state i { font-size:36px; color:#cbd5e1; margin-bottom:12px; display:block; }

        /* Pagination */
        .pagination { display:flex; align-items:center; justify-content:center; gap:6px;
                      padding:16px; border-top:1px solid #f1f5f9; }
        .pg-btn { display:inline-flex; align-items:center; justify-content:center;
                  width:34px; height:34px; border-radius:6px; font-size:13px; font-weight:600;
                  text-decoration:none; border:1px solid #e2e8f0; color:#374151; transition:.15s; }
        .pg-btn:hover { background:#f1f5f9; }
        .pg-btn.active { background:#0f3524; color:#fff; border-color:#0f3524; }
        .pg-btn.disabled { opacity:.4; pointer-events:none; }

        /* Flash */
        .flash { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500;
                 background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }

        @media(max-width:768px){
            .stats-strip { grid-template-columns:1fr; }
            .loans-table-wrap { overflow-x:auto; }
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

    <main style="max-width:1200px;margin:0 auto;padding:32px 20px;">

        <!-- Page header -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:28px;">
            <div>
                <h1 style="font-size:24px;font-weight:700;color:#1e293b;margin-bottom:4px;">Loan Transactions</h1>
                <p style="font-size:14px;color:#6b7280;">
                    <?= $soloMios ? 'Your loan history.' : 'All library loans — active, overdue and returned.' ?>
                </p>
            </div>
            <?php if ($canManage): ?>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <?php
                $expQs = http_build_query(array_filter([
                    'q'=>$q,'estado'=>$estado,'tipo'=>$tipo,
                    'fecha_desde'=>$fechaDesde,'fecha_hasta'=>$fechaHasta,'export'=>'csv',
                ]));
                ?>
                <a href="transactions.php?<?= $expQs ?>"
                   style="display:inline-flex;align-items:center;gap:8px;
                          padding:10px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;
                          color:#374151;text-decoration:none;font-weight:600;font-size:14px;"
                   title="Export current filter to CSV">
                    <i class="fa-solid fa-file-csv"></i> Export CSV
                </a>
                <a href="devolucion.php" style="display:inline-flex;align-items:center;gap:8px;
                   padding:10px 18px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;
                   color:#374151;text-decoration:none;font-weight:600;font-size:14px;">
                    <i class="fa-solid fa-rotate-left"></i> Process Return
                </a>
                <a href="prestamo.php" style="display:inline-flex;align-items:center;gap:8px;
                   padding:10px 20px;background:#0f3524;color:#fff;
                   border:none;border-radius:8px;font-weight:600;font-size:14px;text-decoration:none;">
                    <i class="fa-solid fa-book-bookmark"></i> New Loan
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($successMsg): ?>
            <div class="flash"><i class="fa-solid fa-circle-check"></i> <?= e($successMsg) ?></div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-strip">
            <div class="stat-tile">
                <div class="st-icon st-green"><i class="fa-solid fa-book-bookmark"></i></div>
                <div>
                    <div class="st-num"><?= (int)($statsRow['activos'] ?? 0) ?></div>
                    <div class="st-lbl">Active Loans</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-amber"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div>
                    <div class="st-num"><?= (int)($statsRow['vencidos'] ?? 0) ?></div>
                    <div class="st-lbl">Overdue</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-blue"><i class="fa-solid fa-rotate-left"></i></div>
                <div>
                    <div class="st-num"><?= (int)($statsRow['hoy_devueltos'] ?? 0) ?></div>
                    <div class="st-lbl">Returned Today</div>
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="GET" action="transactions.php" class="filter-bar">
            <div class="fb-group fb-search">
                <div class="fb-lbl">Search</div>
                <input type="text" name="q" placeholder="Folio, user, book title…" value="<?= e($q) ?>">
            </div>
            <div class="fb-group">
                <div class="fb-lbl">Status</div>
                <select name="estado">
                    <option value="">All statuses</option>
                    <?php foreach (['activo'=>'Active','vencido'=>'Overdue','devuelto'=>'Returned','perdido'=>'Lost'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= $estado === $v ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fb-group">
                <div class="fb-lbl">Type</div>
                <select name="tipo">
                    <option value="">All types</option>
                    <option value="externo" <?= $tipo === 'externo' ? 'selected' : '' ?>>External</option>
                    <option value="interno" <?= $tipo === 'interno' ? 'selected' : '' ?>>Internal</option>
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
            <?php if ($q || $estado || $tipo || $fechaDesde || $fechaHasta): ?>
                <a href="transactions.php" style="display:inline-flex;align-items:center;gap:6px;
                   height:38px;padding:0 14px;border:1px solid #e2e8f0;border-radius:7px;
                   font-size:13px;color:#6b7280;text-decoration:none;">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Table -->
        <div class="loans-table-wrap">
            <?php if (empty($prestamos)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    No loans found<?= ($q || $estado || $tipo) ? ' for the current filters.' : ' yet.' ?>
                </div>
            <?php else: ?>
            <table class="loans-table">
                <thead>
                    <tr>
                        <th>Folio</th>
                        <?php if (!$soloMios): ?><th>Borrower</th><?php endif; ?>
                        <th>Book</th>
                        <th>Issued</th>
                        <th>Due</th>
                        <th>Status</th>
                        <th>Fine</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($prestamos as $row):
                    $mulInfo  = calcularMultaInfo($row['fecha_vencimiento']);
                    $isActive = in_array($row['estado'], ['activo', 'vencido']);
                    $canRenew = $isActive && (int)$row['renovaciones_conteo'] < 2;
                ?>
                    <tr>
                        <!-- Folio -->
                        <td>
                            <a href="reciboPrestamo.php?id=<?= $row['id_prestamo'] ?>"
                               style="font-family:'Courier New',monospace;font-size:12px;font-weight:700;
                                      color:#0f3524;text-decoration:none;">
                                <?= e($row['folio_recibo']) ?>
                            </a>
                            <div style="font-size:11px;color:#94a3b8;margin-top:2px;">
                                <?= ucfirst($row['tipo']) ?>
                                <?php if ($row['renovaciones_conteo'] > 0): ?>
                                    · <?= (int)$row['renovaciones_conteo'] ?>× renewed
                                <?php endif; ?>
                            </div>
                        </td>

                        <?php if (!$soloMios): ?>
                        <!-- Borrower -->
                        <td>
                            <div style="font-weight:600;font-size:13px;"><?= e($row['nombre_completo']) ?></div>
                            <div style="font-size:11px;color:#6b7280;"><?= e($row['email']) ?> · <?= tipoLabel($row['usuario_tipo']) ?></div>
                        </td>
                        <?php endif; ?>

                        <!-- Book -->
                        <td>
                            <a href="bookInformation.php?id=<?= $row['id_libro'] ?>"
                               style="font-weight:600;color:#1e293b;text-decoration:none;font-size:13px;">
                                <?= e(mb_strimwidth($row['titulo'], 0, 45, '…')) ?>
                            </a>
                            <div style="font-size:11px;color:#6b7280;font-family:'Courier New',monospace;">
                                <?= e($row['codigo_inventario']) ?>
                            </div>
                        </td>

                        <!-- Issued -->
                        <td style="font-size:12px;white-space:nowrap;">
                            <?= date('d M Y', strtotime($row['fecha_salida'])) ?>
                        </td>

                        <!-- Due -->
                        <td style="font-size:12px;white-space:nowrap;
                                   color:<?= $mulInfo['atrasado'] && $isActive ? '#dc2626' : '#374151' ?>;
                                   font-weight:<?= $mulInfo['atrasado'] && $isActive ? '700' : '400' ?>;">
                            <?= date('d M Y', strtotime($row['fecha_vencimiento'])) ?>
                            <?php if ($mulInfo['atrasado'] && $isActive): ?>
                                <div style="font-size:10px;color:#dc2626;">+<?= $mulInfo['dias'] ?>d late</div>
                            <?php elseif ($row['fecha_devolucion']): ?>
                                <div style="font-size:10px;color:#6b7280;">
                                    Ret. <?= date('d M Y', strtotime($row['fecha_devolucion'])) ?>
                                </div>
                            <?php endif; ?>
                        </td>

                        <!-- Status badge -->
                        <td>
                            <span class="lb <?= estadoPrestamoBadge($row['estado']) ?>">
                                <span class="lb-dot"></span>
                                <?= estadoPrestamoLabel($row['estado']) ?>
                            </span>
                        </td>

                        <!-- Fine -->
                        <td>
                            <?php if ($row['multa_monto'] !== null && (float)$row['multa_monto'] > 0): ?>
                                <span class="fine-pill <?= $row['multa_pagada'] ? 'fine-paid' : 'fine-pending' ?>">
                                    <?= $row['multa_pagada'] ? '✓' : '$' ?>
                                    <?= number_format((float)$row['multa_monto'], 0) ?> MXN
                                </span>
                            <?php elseif ($mulInfo['atrasado'] && $isActive): ?>
                                <span class="fine-pill fine-pending">
                                    ~$<?= number_format($mulInfo['monto'], 0) ?> MXN
                                </span>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Actions -->
                        <td>
                            <div class="actions-cell">
                                <a href="reciboPrestamo.php?id=<?= $row['id_prestamo'] ?>" class="act-btn act-receipt" title="Receipt">
                                    <i class="fa-solid fa-receipt"></i>
                                </a>
                                <?php if ($isActive && $canManage): ?>
                                    <?php if ($canRenew): ?>
                                        <a href="renovarPrestamo.php?id=<?= $row['id_prestamo'] ?>" class="act-btn act-renew" title="Renew">
                                            <i class="fa-solid fa-rotate-right"></i>
                                        </a>
                                    <?php endif; ?>
                                    <a href="devolucion.php?prestamo_id=<?= $row['id_prestamo'] ?>" class="act-btn act-return" title="Return">
                                        <i class="fa-solid fa-rotate-left"></i>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $qp = http_build_query(array_filter([
                    'q'          => $q,
                    'estado'     => $estado,
                    'tipo'       => $tipo,
                    'fecha_desde'=> $fechaDesde,
                    'fecha_hasta'=> $fechaHasta,
                ]));
                $qp = $qp ? "&{$qp}" : '';
                ?>
                <a href="?page=<?= max(1,$page-1) ?><?= $qp ?>"
                   class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-chevron-left" style="font-size:11px;"></i>
                </a>
                <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                    <a href="?page=<?= $i ?><?= $qp ?>"
                       class="pg-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <a href="?page=<?= min($totalPages,$page+1) ?><?= $qp ?>"
                   class="pg-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-chevron-right" style="font-size:11px;"></i>
                </a>
            </div>
            <div style="padding:0 16px 12px;text-align:center;font-size:12px;color:#94a3b8;">
                Showing <?= ($offset+1) ?>–<?= min($offset+$perPage,$totalRows) ?> of <?= $totalRows ?> loans
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

    </main>

    <footer class="simple-footer">
        <p>&copy; 2026 Universidad Ducky — Library Administrative System</p>
    </footer>

</body>
</html>
