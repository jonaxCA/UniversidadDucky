<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador']);

$db      = getDB();
$me      = currentUser();
$perPage = 10;
$page    = max(1, (int) ($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$filterRol = $_GET['rol'] ?? '';
$search    = trim($_GET['q'] ?? '');
$flash     = $_GET['success'] ?? '';
$flashErr  = $_GET['error'] ?? '';

// ── Construir WHERE ──────────────────────────────────────────────────────────
$where  = [];
$params = [];

$validRoles = ['administrador', 'bibliotecario', 'profesor', 'alumno'];
if ($filterRol && in_array($filterRol, $validRoles, true)) {
    $where[]  = 'tipo = ?';
    $params[] = $filterRol;
}
if ($search !== '') {
    $where[]  = '(nombre_completo LIKE ? OR email LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Conteo total ─────────────────────────────────────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM usuarios $whereSQL");
$countStmt->execute($params);
$totalCount = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Usuarios de la página actual ─────────────────────────────────────────────
$listStmt = $db->prepare(
    "SELECT * FROM usuarios $whereSQL ORDER BY creado_en DESC LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$users = $listStmt->fetchAll();

// ── Estadísticas de la biblioteca ────────────────────────────────────────────
$stats = $db->query("
    SELECT
        (SELECT COUNT(*) FROM libros)                                                   AS total_libros,
        (SELECT COUNT(*) FROM usuarios WHERE estado = 'activo')                         AS usuarios_activos,
        (SELECT COUNT(*) FROM prestamos WHERE estado = 'activo')                        AS prestamos_activos,
        (SELECT COUNT(*) FROM prestamos WHERE estado = 'vencido')                       AS prestamos_vencidos,
        (SELECT COALESCE(SUM(monto_total),0) FROM multas m
         JOIN prestamos p ON m.id_prestamo = p.id_prestamo WHERE m.estado_pago = 0)    AS multas_pendientes,
        (SELECT COUNT(*) FROM ejemplares WHERE disponible = 'disponible')               AS copias_disponibles
")->fetch();

// ── Helper: URL de paginación conservando filtros ────────────────────────────
function pageUrl(int $p): string
{
    $params = array_filter([
        'page' => $p,
        'rol'  => $_GET['rol']  ?? '',
        'q'    => $_GET['q']    ?? '',
    ]);
    return 'dashboard.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .alert-success { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .search-form   { display:contents; }
        .user-avatar-color-0 { background:#dbeafe; color:#1d4ed8; }
        .user-avatar-color-1 { background:#dcfce7; color:#15803d; }
        .user-avatar-color-2 { background:#fef3c7; color:#d97706; }
        .user-avatar-color-3 { background:#fce7f3; color:#db2777; }
        .user-avatar-color-4 { background:#ede9fe; color:#7c3aed; }
        .logout-link { color:inherit; text-decoration:none; font-size:13px;
                       opacity:.7; display:flex; align-items:center; gap:6px; }
        .logout-link:hover { opacity:1; }
        .stat-pill { display:flex; align-items:center; gap:12px; background:#fff;
                     border:1px solid #e5e7eb; border-radius:12px; padding:14px 16px; }
        .stat-pill-icon { width:40px; height:40px; border-radius:10px; display:flex;
                          align-items:center; justify-content:center; font-size:16px; flex-shrink:0; }
        .stat-pill-value { font-size:20px; font-weight:700; color:#111827; line-height:1.2; }
        .stat-pill-label { font-size:12px; color:#6b7280; margin-top:2px; }
        .status-dot.orange { background:#f97316; }   /* suspendido */
    </style>
</head>
<body class="dashboard-body">

    <header class="top-navbar">
        <div class="logo-area">
            <img src="images/duckyNav.jpeg" alt="Universidad Ducky" class="nav-logo">
        </div>
        <nav class="top-nav-links">
            <a href="dashboard.php" class="active">Dashboard</a>
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
            <a href="logout.php" class="logout-link" title="Cerrar sesión">
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
                <a href="dashboard.php" class="menu-item active">
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

            <?php if ($flash === 'user_created'): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check"></i> Usuario creado exitosamente.
                </div>
            <?php elseif ($flash === 'user_updated'): ?>
                <div class="alert alert-success">
                    <i class="fa-solid fa-circle-check"></i> Usuario actualizado correctamente.
                </div>
            <?php elseif ($flashErr === 'forbidden'): ?>
                <div class="alert alert-error">
                    <i class="fa-solid fa-circle-exclamation"></i> No tienes permisos para realizar esa acción.
                </div>
            <?php endif; ?>

            <!-- ── Stats strip ──────────────────────────────────────────────── -->
            <div class="stats-strip" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-bottom:24px;">
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#eff6ff;color:#2563eb;"><i class="fa-solid fa-book"></i></span>
                    <div>
                        <div class="stat-pill-value"><?= number_format((int)$stats['total_libros']) ?></div>
                        <div class="stat-pill-label">Total Books</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-users"></i></span>
                    <div>
                        <div class="stat-pill-value"><?= number_format((int)$stats['usuarios_activos']) ?></div>
                        <div class="stat-pill-label">Active Users</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#eff6ff;color:#2563eb;"><i class="fa-solid fa-book-open"></i></span>
                    <div>
                        <div class="stat-pill-value"><?= number_format((int)$stats['prestamos_activos']) ?></div>
                        <div class="stat-pill-label">Active Loans</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#fef2f2;color:#dc2626;"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    <div>
                        <div class="stat-pill-value"><?= number_format((int)$stats['prestamos_vencidos']) ?></div>
                        <div class="stat-pill-label">Overdue Loans</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#fffbeb;color:#b45309;"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div>
                        <div class="stat-pill-value">$<?= number_format((float)$stats['multas_pendientes'], 2) ?></div>
                        <div class="stat-pill-label">Pending Fines</div>
                    </div>
                </div>
                <div class="stat-pill">
                    <span class="stat-pill-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-layer-group"></i></span>
                    <div>
                        <div class="stat-pill-value"><?= number_format((int)$stats['copias_disponibles']) ?></div>
                        <div class="stat-pill-label">Copies on Shelf</div>
                    </div>
                </div>
            </div>

            <div class="content-header">
                <div>
                    <h1>User Management</h1>
                    <p>Manage system users, roles, and library permissions.</p>
                </div>
                <a href="addUsers.php" class="btn-primary btn-add">
                    <i class="fa-solid fa-user-plus"></i> Add New User
                </a>
            </div>

            <div class="table-card">
                <form method="GET" action="dashboard.php" class="search-form">
                    <div class="table-toolbar">
                        <div class="search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" name="q" placeholder="Filter users..."
                                   value="<?= e($search) ?>"
                                   onchange="this.form.submit()">
                        </div>
                        <div class="filter-pills">
                            <button type="submit" name="rol" value=""
                                class="pill <?= $filterRol === '' ? 'active' : '' ?>">
                                All Roles
                            </button>
                            <button type="submit" name="rol" value="administrador"
                                class="pill <?= $filterRol === 'administrador' ? 'active' : '' ?>">
                                Admin
                            </button>
                            <button type="submit" name="rol" value="bibliotecario"
                                class="pill <?= $filterRol === 'bibliotecario' ? 'active' : '' ?>">
                                Librarian
                            </button>
                            <button type="submit" name="rol" value="profesor"
                                class="pill <?= $filterRol === 'profesor' ? 'active' : '' ?>">
                                Professor
                            </button>
                            <button type="submit" name="rol" value="alumno"
                                class="pill <?= $filterRol === 'alumno' ? 'active' : '' ?>">
                                Student
                            </button>
                        </div>
                    </div>
                </form>

                <table class="data-table">
                    <thead>
                        <tr>
                            <th>NAME</th>
                            <th>ROLE</th>
                            <th>PERMISSIONS</th>
                            <th>ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="4" style="text-align:center;padding:40px;color:#6b7280;">
                                No se encontraron usuarios.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $i => $u):
                            $initials   = getInitials($u['nombre_completo']);
                            $badgeClass = tipoBadgeClass($u['tipo']);
                            $tipoLbl    = tipoLabel($u['tipo']);
                            $permsLbl   = permissionsLabel($u['tipo']);
                            $dotClass   = match($u['estado']) {
                                'activo'     => 'green',
                                'suspendido' => 'orange',
                                default      => 'red',
                            };
                            $colorIdx   = $i % 5;
                        ?>
                        <tr>
                            <td>
                                <div class="user-cell">
                                    <span class="status-dot <?= $dotClass ?>"></span>
                                    <div class="avatar-initials user-avatar-color-<?= $colorIdx ?>">
                                        <?= e($initials) ?>
                                    </div>
                                    <div class="user-info">
                                        <span class="user-name"><?= e($u['nombre_completo']) ?>
                                            <?php if ($u['estado'] !== 'activo'): ?>
                                                <span style="font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;
                                                    <?= $u['estado'] === 'suspendido' ? 'background:#ffedd5;color:#c2410c;' : 'background:#fee2e2;color:#991b1b;' ?>">
                                                    <?= strtoupper(e($u['estado'])) ?>
                                                </span>
                                            <?php endif; ?>
                                        </span>
                                        <span class="user-email"><?= e($u['email']) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td><span class="role-badge <?= $badgeClass ?>"><?= $tipoLbl ?></span></td>
                            <td><?= $permsLbl ?></td>
                            <td>
                                <a href="editUser.php?id=<?= (int) $u['id_usuario'] ?>" class="btn-edit">
                                    <i class="fa-solid fa-pen"></i> Edit User
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>

                <div class="table-footer">
                    <span class="showing-text">
                        Mostrando <?= $totalCount === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $perPage, $totalCount) ?>
                        de <?= $totalCount ?> usuarios
                    </span>
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="<?= pageUrl($page - 1) ?>" class="page-btn">
                                <i class="fa-solid fa-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <button class="page-btn" disabled><i class="fa-solid fa-chevron-left"></i></button>
                        <?php endif; ?>

                        <?php
                        $start = max(1, $page - 2);
                        $end   = min($totalPages, $page + 2);
                        for ($p = $start; $p <= $end; $p++): ?>
                            <a href="<?= pageUrl($p) ?>"
                               class="page-btn <?= $p === $page ? 'active' : '' ?>">
                                <?= $p ?>
                            </a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="<?= pageUrl($page + 1) ?>" class="page-btn">
                                <i class="fa-solid fa-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <button class="page-btn" disabled><i class="fa-solid fa-chevron-right"></i></button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

</body>
</html>
