<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador']);

$me = currentUser();
$db = getDB();

// ── Filtros ───────────────────────────────────────────────────────────────────
$q          = trim($_GET['q']          ?? '');
$modulo     =      $_GET['modulo']     ?? '';
$accion     =      $_GET['accion']     ?? '';
$actor      =      $_GET['actor']      ?? '';
$fechaDesde =      $_GET['fecha_desde']?? '';
$fechaHasta =      $_GET['fecha_hasta']?? '';
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 20;
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $like     = "%{$q}%";
    $where[]  = '(u.nombre_completo LIKE ? OR b.entidad_afectada LIKE ? OR b.modulo LIKE ?)';
    array_push($params, $like, $like, $like);
}
if ($modulo !== '') { $where[] = 'b.modulo = ?';           $params[] = $modulo; }
if ($accion !== '') { $where[] = 'b.accion = ?';           $params[] = $accion; }
if ($actor  !== '') { $where[] = 'b.id_usuario_actor = ?'; $params[] = (int)$actor; }
if ($fechaDesde !== '') { $where[] = 'DATE(b.creado_en) >= ?'; $params[] = $fechaDesde; }
if ($fechaHasta !== '') { $where[] = 'DATE(b.creado_en) <= ?'; $params[] = $fechaHasta; }

$whereSQL = implode(' AND ', $where);

// Stats
$statsRow = $db->query("
    SELECT
        COUNT(*) AS total_hoy
    FROM bitacora_iso_9001
    WHERE DATE(creado_en) = CURDATE()
")->fetch();

$actoresHoy = (int) $db->query("
    SELECT COUNT(DISTINCT id_usuario_actor)
    FROM bitacora_iso_9001
    WHERE DATE(creado_en) = CURDATE()
")->fetchColumn();

// Count
$cntStmt = $db->prepare("
    SELECT COUNT(*)
    FROM   bitacora_iso_9001 b
    LEFT JOIN usuarios u ON b.id_usuario_actor = u.id_usuario
    WHERE  {$whereSQL}
");
$cntStmt->execute($params);
$totalRows  = (int) $cntStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Data
$dataParams = array_merge($params, [$perPage, $offset]);
$dataStmt   = $db->prepare("
    SELECT  b.*,
            u.nombre_completo AS actor_nombre,
            u.tipo            AS actor_tipo
    FROM    bitacora_iso_9001 b
    LEFT JOIN usuarios u ON b.id_usuario_actor = u.id_usuario
    WHERE   {$whereSQL}
    ORDER   BY b.creado_en DESC
    LIMIT   ? OFFSET ?
");
$dataStmt->execute($dataParams);
$logs = $dataStmt->fetchAll();

// Módulos únicos para el filtro
$modulos = $db->query("SELECT DISTINCT modulo FROM bitacora_iso_9001 WHERE modulo != '' ORDER BY modulo")->fetchAll(PDO::FETCH_COLUMN);

// Actores para el filtro
$actores = $db->query("
    SELECT DISTINCT u.id_usuario, u.nombre_completo
    FROM bitacora_iso_9001 b
    JOIN usuarios u ON b.id_usuario_actor = u.id_usuario
    ORDER BY u.nombre_completo
")->fetchAll();

$accionColors = [
    'crear'       => ['bg'=>'#dcfce7','txt'=>'#15803d'],
    'actualizar'  => ['bg'=>'#dbeafe','txt'=>'#1d4ed8'],
    'borrar'      => ['bg'=>'#fee2e2','txt'=>'#dc2626'],
    'prestamo'    => ['bg'=>'#f0fdf4','txt'=>'#15803d'],
    'devolucion'  => ['bg'=>'#eff6ff','txt'=>'#1d4ed8'],
    'renovacion'  => ['bg'=>'#fef9c3','txt'=>'#92400e'],
    'pago_multa'  => ['bg'=>'#dcfce7','txt'=>'#15803d'],
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .stats-strip { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
        .stat-tile { background:#fff; border:1px solid #e2e8f0; border-radius:10px;
                     padding:16px 20px; display:flex; align-items:center; gap:14px; }
        .st-icon { width:42px; height:42px; border-radius:8px;
                   display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .st-teal  { background:#ccfbf1; color:#0f766e; }
        .st-blue  { background:#dbeafe; color:#1d4ed8; }
        .st-slate { background:#f1f5f9; color:#475569; }
        .st-num { font-size:22px; font-weight:700; color:#1e293b; line-height:1; }
        .st-lbl { font-size:12px; color:#6b7280; margin-top:3px; }

        .filter-bar { background:#fff; border:1px solid #e2e8f0; border-radius:10px;
                      padding:14px 18px; margin-bottom:20px;
                      display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; }
        .fb-group { display:flex; flex-direction:column; gap:4px; }
        .fb-lbl   { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.8px; color:#94a3b8; }
        .filter-bar input, .filter-bar select {
            padding:8px 12px; border:1px solid #e2e8f0; border-radius:7px;
            font-size:13px; font-family:inherit; color:#374151; background:#f8fafc; }

        .log-table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; }
        table.lt { width:100%; border-collapse:collapse; font-size:13px; }
        table.lt thead th {
            padding:10px 14px; background:#f8fafc; text-align:left;
            font-size:10px; font-weight:700; letter-spacing:.8px; text-transform:uppercase;
            color:#94a3b8; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
        table.lt tbody tr { border-bottom:1px solid #f1f5f9; }
        table.lt tbody tr:last-child { border-bottom:none; }
        table.lt tbody tr:hover { background:#fafbfc; }
        table.lt td { padding:10px 14px; vertical-align:top; }

        .accion-badge { display:inline-block; padding:2px 9px; border-radius:10px;
                        font-size:11px; font-weight:700; text-transform:capitalize; }

        /* JSON detail toggle */
        .detail-toggle { font-size:11px; color:#6b7280; cursor:pointer; border:none;
                         background:none; font-family:inherit; padding:2px 6px;
                         border:1px solid #e2e8f0; border-radius:4px; margin-top:4px; }
        .detail-toggle:hover { background:#f1f5f9; }
        .json-pre { font-family:'Courier New',monospace; font-size:11px; color:#374151;
                    background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px;
                    padding:10px 12px; margin-top:6px; white-space:pre-wrap; word-break:break-all;
                    display:none; max-width:340px; }
        .json-pre.open { display:block; }

        .pagination { display:flex; align-items:center; justify-content:center; gap:6px;
                      padding:16px; border-top:1px solid #f1f5f9; }
        .pg-btn { display:inline-flex; align-items:center; justify-content:center;
                  width:34px; height:34px; border-radius:6px; font-size:13px; font-weight:600;
                  text-decoration:none; border:1px solid #e2e8f0; color:#374151; }
        .pg-btn:hover { background:#f1f5f9; }
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

        <div style="margin-bottom:28px;">
            <h1 style="font-size:24px;font-weight:700;color:#1e293b;margin-bottom:4px;">
                <i class="fa-solid fa-shield-halved" style="color:#0f3524;margin-right:10px;"></i>ISO 9001 Audit Log
            </h1>
            <p style="font-size:14px;color:#6b7280;">Complete record of all system actions. Read-only.</p>
        </div>

        <!-- Stats -->
        <div class="stats-strip">
            <div class="stat-tile">
                <div class="st-icon st-teal"><i class="fa-solid fa-list-check"></i></div>
                <div>
                    <div class="st-num"><?= (int)($statsRow['total_hoy'] ?? 0) ?></div>
                    <div class="st-lbl">Events Today</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-blue"><i class="fa-solid fa-users"></i></div>
                <div>
                    <div class="st-num"><?= $actoresHoy ?></div>
                    <div class="st-lbl">Active Users Today</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-slate"><i class="fa-solid fa-database"></i></div>
                <div>
                    <div class="st-num"><?= number_format($totalRows) ?></div>
                    <div class="st-lbl">Total Log Entries</div>
                </div>
            </div>
        </div>

        <!-- Filter bar -->
        <form method="GET" action="bitacora.php" class="filter-bar">
            <div class="fb-group" style="flex:1;min-width:160px;">
                <div class="fb-lbl">Search</div>
                <input type="text" name="q" placeholder="Actor name, module…" value="<?= e($q) ?>">
            </div>
            <div class="fb-group">
                <div class="fb-lbl">Module</div>
                <select name="modulo">
                    <option value="">All modules</option>
                    <?php foreach ($modulos as $mod): ?>
                        <option value="<?= e($mod) ?>" <?= $modulo === $mod ? 'selected' : '' ?>><?= e(ucfirst($mod)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fb-group">
                <div class="fb-lbl">Action</div>
                <select name="accion">
                    <option value="">All actions</option>
                    <?php foreach (array_keys($accionColors) as $ac): ?>
                        <option value="<?= $ac ?>" <?= $accion === $ac ? 'selected' : '' ?>><?= ucfirst($ac) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fb-group">
                <div class="fb-lbl">User</div>
                <select name="actor">
                    <option value="">All users</option>
                    <?php foreach ($actores as $act): ?>
                        <option value="<?= $act['id_usuario'] ?>" <?= (string)$actor === (string)$act['id_usuario'] ? 'selected' : '' ?>>
                            <?= e($act['nombre_completo']) ?>
                        </option>
                    <?php endforeach; ?>
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
            <?php if ($q || $modulo || $accion || $actor || $fechaDesde || $fechaHasta): ?>
                <a href="bitacora.php" style="display:inline-flex;align-items:center;gap:6px;
                   height:38px;padding:0 14px;border:1px solid #e2e8f0;border-radius:7px;
                   font-size:13px;color:#6b7280;text-decoration:none;">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
            <?php endif; ?>
        </form>

        <!-- Log table -->
        <div class="log-table-wrap">
            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    No log entries found for the current filters.
                </div>
            <?php else: ?>
            <table class="lt">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Module</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>IP</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($logs as $idx => $log):
                    $col = $accionColors[$log['accion']] ?? ['bg'=>'#f1f5f9','txt'=>'#475569'];
                ?>
                    <tr>
                        <td style="white-space:nowrap;">
                            <div style="font-size:12px;font-weight:600;"><?= date('d M Y', strtotime($log['creado_en'])) ?></div>
                            <div style="font-size:11px;color:#6b7280;"><?= date('H:i:s', strtotime($log['creado_en'])) ?></div>
                        </td>
                        <td>
                            <?php if ($log['actor_nombre']): ?>
                                <div style="font-weight:600;font-size:13px;"><?= e($log['actor_nombre']) ?></div>
                                <div style="font-size:11px;color:#6b7280;"><?= tipoLabel($log['actor_tipo'] ?? '') ?></div>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">System</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span style="font-size:12px;font-weight:600;color:#374151;text-transform:capitalize;">
                                <?= e($log['modulo'] ?: '—') ?>
                            </span>
                        </td>
                        <td>
                            <span class="accion-badge" style="background:<?= $col['bg'] ?>;color:<?= $col['txt'] ?>;">
                                <?= $log['accion'] ?>
                            </span>
                        </td>
                        <td style="font-size:12px;">
                            <span style="font-family:'Courier New',monospace;color:#374151;">
                                <?= e($log['entidad_afectada'] ?? '—') ?>
                                <?php if ($log['id_entidad_afectada']): ?>
                                    <span style="color:#94a3b8;"> #<?= $log['id_entidad_afectada'] ?></span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:#6b7280;font-family:'Courier New',monospace;">
                            <?= e($log['ip_usuario'] ?? '—') ?>
                        </td>
                        <td>
                            <?php if ($log['detalle_cambio'] && $log['detalle_cambio'] !== 'null'): ?>
                                <?php $json = json_decode($log['detalle_cambio'], true); ?>
                                <button class="detail-toggle" onclick="toggleJson(<?= $idx ?>)">
                                    <i class="fa-solid fa-code" style="font-size:10px;"></i> Details
                                </button>
                                <pre class="json-pre" id="json-<?= $idx ?>"><?= e(json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:12px;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php
                $qp = http_build_query(array_filter(['q'=>$q,'modulo'=>$modulo,'accion'=>$accion,'actor'=>$actor,'fecha_desde'=>$fechaDesde,'fecha_hasta'=>$fechaHasta]));
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
            <div style="padding:0 16px 12px;text-align:center;font-size:12px;color:#94a3b8;">
                Showing <?= ($offset+1) ?>–<?= min($offset+$perPage,$totalRows) ?> of <?= number_format($totalRows) ?> entries
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

    </main>

    <script>
    function toggleJson(idx) {
        const el  = document.getElementById('json-' + idx);
        const btn = el.previousElementSibling;
        el.classList.toggle('open');
        btn.innerHTML = el.classList.contains('open')
            ? '<i class="fa-solid fa-chevron-up" style="font-size:10px;"></i> Hide'
            : '<i class="fa-solid fa-code" style="font-size:10px;"></i> Details';
    }
    </script>

</body>
</html>
