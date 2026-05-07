<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me  = currentUser();
$db  = getDB();

$error = '';
$exito = '';

// ── POST: registrar nueva orden de compra ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion    =         $_POST['_action']   ?? '';
    $proveedor = trim(   $_POST['proveedor'] ?? '');
    $factura   = trim(   $_POST['factura']   ?? '');
    $fecha     =         $_POST['fecha']     ?? date('Y-m-d');
    $monto     = (float) str_replace(',', '.', $_POST['monto'] ?? '0');

    if ($accion === 'nueva_compra') {
        if (empty($proveedor) || empty($factura)) {
            $error = 'Supplier and invoice number are required.';
        } elseif ($monto <= 0) {
            $error = 'Total amount must be greater than zero.';
        } else {
            try {
                $db->prepare("
                    INSERT INTO compras_libros (proveedor, factura, fecha_compra, monto_total)
                    VALUES (?, ?, ?, ?)
                ")->execute([$proveedor, $factura, $fecha, $monto]);

                $compraId = (int) $db->lastInsertId();
                logAction($db, $me['id'], 'crear', 'compras_libros', $compraId, [
                    'proveedor' => $proveedor,
                    'factura'   => $factura,
                    'monto'     => $monto,
                ], 'adquisiciones');

                $exito = "Purchase order #{$compraId} registered successfully.";
            } catch (PDOException $ex) {
                $error = 'Error: ' . $ex->getMessage();
            }
        }
    }
}

// ── Parámetros ────────────────────────────────────────────────────────────────
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;
$q       = trim($_GET['q'] ?? '');

// ── Conteo y lista de órdenes ─────────────────────────────────────────────────
$whereSQL = '';
$params   = [];
if ($q !== '') {
    $whereSQL = 'WHERE proveedor LIKE ? OR factura LIKE ?';
    $params   = ["%{$q}%", "%{$q}%"];
}

$totalCount = (int) $db->prepare("SELECT COUNT(*) FROM compras_libros {$whereSQL}")
    ->execute($params) ? $db->prepare("SELECT COUNT(*) FROM compras_libros {$whereSQL}")
    ->execute($params) ?: 0 : 0;

// Recount correctly
$cnt = $db->prepare("SELECT COUNT(*) FROM compras_libros {$whereSQL}");
$cnt->execute($params);
$totalCount = (int) $cnt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stOrders = $db->prepare("
    SELECT cl.*,
           COUNT(cd.id_detalle) AS copias_compradas
    FROM   compras_libros cl
    LEFT JOIN compras_detalle cd ON cl.id_compra = cd.id_compra
    {$whereSQL}
    GROUP BY cl.id_compra
    ORDER BY cl.fecha_compra DESC, cl.id_compra DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$stOrders->execute($params);
$orders = $stOrders->fetchAll();

// ── Detalle de una orden seleccionada ─────────────────────────────────────────
$detalleCompraId = (int) ($_GET['compra_id'] ?? 0);
$detalleItems    = [];
$detalleCompra   = null;
if ($detalleCompraId > 0) {
    $stC = $db->prepare("SELECT * FROM compras_libros WHERE id_compra = ?");
    $stC->execute([$detalleCompraId]);
    $detalleCompra = $stC->fetch();

    if ($detalleCompra) {
        $stDet = $db->prepare("
            SELECT cd.*, e.codigo_inventario, e.disponible,
                   l.titulo, l.autor, l.isbn
            FROM   compras_detalle cd
            JOIN   ejemplares e ON cd.id_ejemplar = e.id_ejemplar
            JOIN   libros     l ON e.id_libro     = l.id_libro
            WHERE  cd.id_compra = ?
            ORDER  BY l.titulo ASC
        ");
        $stDet->execute([$detalleCompraId]);
        $detalleItems = $stDet->fetchAll();
    }
}

// ── Stats globales ────────────────────────────────────────────────────────────
$stats = $db->query("
    SELECT
        COUNT(DISTINCT cl.id_compra)         AS total_ordenes,
        COALESCE(SUM(cl.monto_total), 0)     AS gasto_total,
        COUNT(cd.id_detalle)                 AS total_copias,
        COUNT(DISTINCT cd.id_ejemplar)       AS ejemplares_registrados
    FROM compras_libros cl
    LEFT JOIN compras_detalle cd ON cl.id_compra = cd.id_compra
")->fetch();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acquisitions — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-ok    { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

        .stat-pill { display:flex; align-items:center; gap:12px; background:#fff;
                     border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; }
        .stat-pill-icon { width:40px; height:40px; border-radius:10px; display:flex;
                          align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .stat-pill-value { font-size:20px; font-weight:700; color:#111827; line-height:1.2; }
        .stat-pill-label { font-size:12px; color:#6b7280; margin-top:2px; }

        .detail-panel { background:#fff; border:1px solid #e2e8f0; border-radius:12px;
                        overflow:hidden; margin-bottom:24px; }
        .dp-header { padding:16px 20px; background:#f8fafc; border-bottom:1px solid #e2e8f0;
                     display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; }
        .dp-title  { font-weight:700; font-size:15px; color:#1e293b; }
        .dp-meta   { font-size:12px; color:#6b7280; margin-top:3px; }

        .order-row { cursor:pointer; }
        .order-row:hover td { background:#f8fafc; }
        .order-row.selected td { background:#eff6ff; }

        .pg-btn { display:inline-flex; align-items:center; justify-content:center;
                  width:32px; height:32px; border-radius:6px; border:1px solid #e2e8f0;
                  font-size:12px; color:#374151; text-decoration:none; transition:.15s; }
        .pg-btn:hover { background:#f1f5f9; }
        .pg-btn.active { background:#0f3524; color:#fff; border-color:#0f3524; }
        .pg-btn.disabled { opacity:.4; pointer-events:none; }
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

    <div class="dashboard-layout">
        <aside class="sidebar">
            <div class="sidebar-header">
                <div class="sidebar-icon"><i class="fa-solid fa-shield-halved"></i></div>
                <div class="sidebar-titles">
                    <h4>Uni Ducky Admin</h4>
                    <p>Library System</p>
                </div>
            </div>
            <nav class="sidebar-menu">
                <a href="dashboard.php" class="menu-item">
                    <i class="fa-solid fa-users"></i> User Management
                </a>
                <a href="catalogSettings.php" class="menu-item">
                    <i class="fa-solid fa-book"></i> Books
                </a>
                <a href="transactions.php" class="menu-item">
                    <i class="fa-solid fa-file-invoice"></i> Loans
                </a>
                <?php if (in_array($me['tipo'], ['administrador','bibliotecario'], true)): ?>
                <a href="multas.php" class="menu-item">
                    <i class="fa-solid fa-triangle-exclamation"></i> Fines
                </a>
                <?php endif; ?>
                <a href="adquisiciones.php" class="menu-item active">
                    <i class="fa-solid fa-cart-shopping"></i> Acquisitions
                </a>
                <?php if ($me['tipo'] === 'administrador'): ?>
                <a href="reportes.php" class="menu-item">
                    <i class="fa-solid fa-chart-bar"></i> Reports
                </a>
                <a href="bitacora.php" class="menu-item">
                    <i class="fa-solid fa-scroll"></i> Audit Log
                </a>
                <?php endif; ?>
                <div class="menu-divider"></div>
                <?php if ($me['tipo'] === 'administrador'): ?>
                <a href="settings.php" class="menu-item">
                    <i class="fa-solid fa-gear"></i> Settings
                </a>
                <?php endif; ?>
                <a href="perfilUsuario.php" class="menu-item">
                    <i class="fa-solid fa-circle-user"></i> My Profile
                </a>
            </nav>
        </aside>

        <main class="main-content">

            <?php if ($exito): ?>
                <div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i> <?= e($exito) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?></div>
            <?php endif; ?>

            <div class="content-header">
                <div>
                    <h1>Acquisitions</h1>
                    <p>Purchase orders and book acquisition history.</p>
                </div>
                <button class="btn-primary btn-add" onclick="document.getElementById('formNueva').style.display='block';this.style.display='none'">
                    <i class="fa-solid fa-plus"></i> New Purchase Order
                </button>
            </div>

            <!-- ── Formulario nueva compra ─────────────────────────────────── -->
            <div id="formNueva" class="table-card" style="display:none;margin-bottom:20px;padding:20px 24px;">
                <h3 style="font-size:15px;font-weight:700;margin:0 0 16px;">New Purchase Order</h3>
                <form method="POST" action="adquisiciones.php">
                    <input type="hidden" name="_action" value="nueva_compra">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:12px;align-items:end;">
                        <div class="input-group" style="margin-bottom:0;">
                            <label>Supplier / Vendor</label>
                            <input type="text" name="proveedor" class="base-input" placeholder="e.g. Editorial Porrúa" required>
                        </div>
                        <div class="input-group" style="margin-bottom:0;">
                            <label>Invoice #</label>
                            <input type="text" name="factura" class="base-input" placeholder="e.g. FAC-2026-001" required>
                        </div>
                        <div class="input-group" style="margin-bottom:0;">
                            <label>Purchase Date</label>
                            <input type="date" name="fecha" class="base-input" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="input-group" style="margin-bottom:0;">
                            <label>Total (USD)</label>
                            <input type="number" name="monto" class="base-input" step="0.01" min="0.01" placeholder="0.00" required style="width:110px;">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;margin-top:14px;justify-content:flex-end;">
                        <button type="button" class="btn-cancel"
                                onclick="document.getElementById('formNueva').style.display='none';document.querySelector('.btn-primary.btn-add').style.display=''">
                            Cancel
                        </button>
                        <button type="submit" class="btn-create">
                            <i class="fa-solid fa-floppy-disk" style="margin-right:6px;"></i>Save Order
                        </button>
                    </div>
                </form>
            </div>

            <!-- ── Stats ──────────────────────────────────────────────────────── -->
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#eff6ff;color:#2563eb;"><i class="fa-solid fa-file-invoice-dollar"></i></span>
                    <div>
                        <div class="stat-pill-value"><?= number_format((int)$stats['total_ordenes']) ?></div>
                        <div class="stat-pill-label">Purchase Orders</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#fef9c3;color:#a16207;"><i class="fa-solid fa-dollar-sign"></i></span>
                    <div>
                        <div class="stat-pill-value">$<?= number_format((float)$stats['gasto_total'], 2) ?></div>
                        <div class="stat-pill-label">Total Spent (USD)</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-layer-group"></i></span>
                    <div>
                        <div class="stat-pill-value"><?= number_format((int)$stats['total_copias']) ?></div>
                        <div class="stat-pill-label">Copies Logged</div>
                    </div>
                </div>
            </div>

            <!-- ── Detalle de la orden seleccionada ──────────────────────────── -->
            <?php if ($detalleCompra): ?>
            <div class="detail-panel">
                <div class="dp-header">
                    <div>
                        <div class="dp-title">
                            <i class="fa-solid fa-file-invoice-dollar" style="margin-right:8px;color:#2563eb;"></i>
                            Order #<?= $detalleCompra['id_compra'] ?> — <?= e($detalleCompra['proveedor']) ?>
                        </div>
                        <div class="dp-meta">
                            Invoice: <?= e($detalleCompra['factura']) ?>
                            · Date: <?= $detalleCompra['fecha_compra'] ? date('d M Y', strtotime($detalleCompra['fecha_compra'])) : '—' ?>
                            · Total: <strong>$<?= number_format((float)$detalleCompra['monto_total'], 2) ?> USD</strong>
                        </div>
                    </div>
                    <a href="adquisiciones.php" class="btn-cancel" style="padding:6px 14px;font-size:12px;">
                        <i class="fa-solid fa-xmark"></i> Close
                    </a>
                </div>
                <?php if ($detalleItems): ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>BOOK</th>
                            <th>ISBN</th>
                            <th>COPY ID</th>
                            <th>STATUS</th>
                            <th style="text-align:right;">UNIT COST (USD)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($detalleItems as $di): ?>
                        <tr>
                            <td>
                                <div style="font-weight:600;font-size:13px;"><?= e($di['titulo']) ?></div>
                                <div style="font-size:12px;color:#6b7280;"><?= e($di['autor'] ?? '—') ?></div>
                            </td>
                            <td style="font-family:'Courier New',monospace;font-size:12px;"><?= e($di['isbn'] ?? '—') ?></td>
                            <td style="font-family:'Courier New',monospace;font-size:12px;"><?= e($di['codigo_inventario']) ?></td>
                            <td>
                                <span style="padding:2px 8px;border-radius:12px;font-size:11px;font-weight:700;
                                    background:<?= $di['disponible'] === 'disponible' ? '#dcfce7' : '#fee2e2' ?>;
                                    color:<?= $di['disponible'] === 'disponible' ? '#15803d' : '#dc2626' ?>;">
                                    <?= ucfirst(e($di['disponible'])) ?>
                                </span>
                            </td>
                            <td style="text-align:right;font-weight:600;">
                                $<?= $di['precio_unitario_usd'] ? number_format((float)$di['precio_unitario_usd'],2) : '—' ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <div style="padding:24px;text-align:center;color:#6b7280;font-size:14px;">
                        <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
                        No copy details linked to this order yet.
                        Copy detail records are created automatically when books are registered via
                        <a href="bookRegister.php">Book Register</a>.
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── Tabla de órdenes ───────────────────────────────────────────── -->
            <div class="table-card">
                <div class="table-toolbar">
                    <form method="GET" action="adquisiciones.php" style="display:contents;">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" name="q" placeholder="Search supplier or invoice…"
                                   value="<?= e($q) ?>" onchange="this.form.submit()">
                        </div>
                    </form>
                    <span style="font-size:13px;color:#6b7280;">
                        <?= $totalCount ?> order<?= $totalCount !== 1 ? 's' : '' ?>
                    </span>
                </div>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>SUPPLIER</th>
                            <th>INVOICE</th>
                            <th>DATE</th>
                            <th>COPIES</th>
                            <th style="text-align:right;">TOTAL (USD)</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($orders)): ?>
                        <tr><td colspan="7" style="text-align:center;padding:40px;color:#6b7280;">
                            No purchase orders found.
                        </td></tr>
                    <?php else: ?>
                        <?php foreach ($orders as $o): ?>
                        <tr class="order-row <?= $o['id_compra'] === $detalleCompraId ? 'selected' : '' ?>">
                            <td style="font-family:'Courier New',monospace;font-size:12px;color:#6b7280;">
                                #<?= $o['id_compra'] ?>
                            </td>
                            <td style="font-weight:600;"><?= e($o['proveedor'] ?? '—') ?></td>
                            <td style="font-family:'Courier New',monospace;font-size:12px;"><?= e($o['factura'] ?? '—') ?></td>
                            <td><?= $o['fecha_compra'] ? date('d M Y', strtotime($o['fecha_compra'])) : '—' ?></td>
                            <td>
                                <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;">
                                    <i class="fa-solid fa-layer-group" style="color:#94a3b8;"></i>
                                    <?= (int)$o['copias_compradas'] ?>
                                </span>
                            </td>
                            <td style="text-align:right;font-weight:700;">
                                $<?= $o['monto_total'] ? number_format((float)$o['monto_total'], 2) : '—' ?>
                            </td>
                            <td>
                                <a href="adquisiciones.php?compra_id=<?= $o['id_compra'] ?><?= $q ? '&q='.urlencode($q) : '' ?>"
                                   class="btn-edit" style="font-size:12px;padding:5px 10px;">
                                    <i class="fa-solid fa-eye"></i> Details
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <?php if ($totalPages > 1): ?>
                <div class="table-footer">
                    <span class="showing-text">
                        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalCount) ?> of <?= $totalCount ?>
                    </span>
                    <div style="display:flex;gap:4px;">
                        <a href="?page=<?= max(1,$page-1) ?><?= $q ? '&q='.urlencode($q) : '' ?>"
                           class="pg-btn <?= $page <= 1 ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-left" style="font-size:10px;"></i>
                        </a>
                        <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                            <a href="?page=<?= $p ?><?= $q ? '&q='.urlencode($q) : '' ?>"
                               class="pg-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                        <?php endfor; ?>
                        <a href="?page=<?= min($totalPages,$page+1) ?><?= $q ? '&q='.urlencode($q) : '' ?>"
                           class="pg-btn <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <i class="fa-solid fa-chevron-right" style="font-size:10px;"></i>
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>

        </main>
    </div>

</body>
</html>
