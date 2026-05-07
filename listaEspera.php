<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$me        = currentUser();
$db        = getDB();
$uid       = $me['id'];
$canManage = in_array($me['tipo'], ['administrador', 'bibliotecario']);

$error = '';
$exito = '';

// ── POST: acciones ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action    =       $_POST['_action']   ?? '';
    $esperaId  = (int) ($_POST['espera_id'] ?? 0);
    $libroId   = (int) ($_POST['libro_id']  ?? 0);

    switch ($action) {

        // Cualquier usuario se apunta a la lista de espera
        case 'join':
            if ($libroId <= 0) { $error = 'Invalid book.'; break; }

            // Verificar que hay al menos 1 copia (aunque todas prestadas)
            $st = $db->prepare("SELECT COUNT(*) FROM ejemplares WHERE id_libro = ? AND disponible != 'obsoleto'");
            $st->execute([$libroId]);
            if ((int)$st->fetchColumn() === 0) { $error = 'This book has no copies in the system.'; break; }

            // Verificar que no está ya en la lista
            $chk = $db->prepare("SELECT id_espera FROM lista_espera WHERE id_libro = ? AND id_usuario = ? AND estado = 'esperando'");
            $chk->execute([$libroId, $uid]);
            if ($chk->fetch()) { $error = 'You are already on the waitlist for this book.'; break; }

            $db->prepare("
                INSERT INTO lista_espera (id_libro, id_usuario, estado)
                VALUES (?, ?, 'esperando')
                ON DUPLICATE KEY UPDATE estado = 'esperando', fecha_alta = NOW()
            ")->execute([$libroId, $uid]);

            logAction($db, $uid, 'crear', 'lista_espera', (int)$db->lastInsertId(), [
                'libro_id' => $libroId,
            ], 'lista_espera');

            $exito = 'You have been added to the waitlist. You will be notified when a copy is available.';
            break;

        // Cancelar propia posición
        case 'cancel_own':
            $db->prepare("
                UPDATE lista_espera SET estado = 'cancelado'
                WHERE id_espera = ? AND id_usuario = ?
            ")->execute([$esperaId, $uid]);
            $exito = 'Removed from waitlist.';
            break;

        // Staff: marcar como notificado
        case 'notify':
            if (!$canManage) { $error = 'Permission denied.'; break; }
            $db->prepare("
                UPDATE lista_espera SET estado = 'notificado', fecha_notif = NOW()
                WHERE id_espera = ?
            ")->execute([$esperaId]);
            logAction($db, $uid, 'actualizar', 'lista_espera', $esperaId, ['accion' => 'notificado'], 'lista_espera');
            $exito = 'User marked as notified.';
            break;

        // Staff: cancelar cualquier posición
        case 'cancel':
            if (!$canManage) { $error = 'Permission denied.'; break; }
            $db->prepare("UPDATE lista_espera SET estado = 'cancelado' WHERE id_espera = ?")->execute([$esperaId]);
            $exito = 'Entry cancelled.';
            break;
    }

    // Redirect to avoid re-POST
    $redir = 'listaEspera.php';
    if ($libroId) $redir .= "?libro_id={$libroId}";
    if ($exito)   $redir .= ($libroId ? '&' : '?') . 'msg=' . urlencode($exito);
    if ($error)   $redir .= ($libroId ? '&' : '?') . 'err=' . urlencode($error);
    header("Location: {$redir}");
    exit;
}

$exito = $_GET['msg'] ?? '';
$error = $_GET['err'] ?? '';

// ── Filtros ───────────────────────────────────────────────────────────────────
$libroId     = (int) ($_GET['libro_id'] ?? 0);
$verEstado   =       $_GET['estado']    ?? 'esperando';
$q           = trim( $_GET['q']         ?? '');

// Si el usuario no es staff, solo ve su propia lista
if (!$canManage) {
    // Vista personal: libros en los que el usuario espera
    $stMi = $db->prepare("
        SELECT  le.*, l.titulo, l.autor, l.id_libro,
                COALESCE(s.disponibles,0) AS copias_disponibles
        FROM    lista_espera le
        JOIN    libros l ON le.id_libro = l.id_libro
        LEFT JOIN (
            SELECT id_libro, SUM(disponible='disponible') AS disponibles
            FROM   ejemplares WHERE disponible != 'obsoleto' GROUP BY id_libro
        ) s ON s.id_libro = l.id_libro
        WHERE   le.id_usuario = ? AND le.estado IN ('esperando','notificado')
        ORDER   BY le.fecha_alta ASC
    ");
    $stMi->execute([$uid]);
    $miLista = $stMi->fetchAll();

    // Posición en cada lista
    $posiciones = [];
    foreach ($miLista as $item) {
        $stPos = $db->prepare("
            SELECT COUNT(*) FROM lista_espera
            WHERE id_libro = ? AND estado = 'esperando' AND fecha_alta <= ?
        ");
        $stPos->execute([$item['id_libro'], $item['fecha_alta']]);
        $posiciones[$item['id_espera']] = (int)$stPos->fetchColumn();
    }

} else {
    // Vista de staff: lista agrupada por libro o por libro específico
    $where  = ["le.estado = ?"];
    $params = [$verEstado];

    if ($libroId > 0) {
        $where[]  = 'le.id_libro = ?';
        $params[] = $libroId;
    }
    if ($q !== '') {
        $like     = "%{$q}%";
        $where[]  = '(u.nombre_completo LIKE ? OR u.email LIKE ? OR l.titulo LIKE ?)';
        array_push($params, $like, $like, $like);
    }

    $whereSQL = implode(' AND ', $where);

    $stStaff = $db->prepare("
        SELECT  le.*,
                l.titulo, l.autor, l.id_libro,
                u.nombre_completo, u.email, u.tipo AS usuario_tipo,
                COALESCE(s.disponibles,0) AS copias_disponibles,
                (SELECT COUNT(*) FROM lista_espera le2
                 WHERE le2.id_libro = le.id_libro AND le2.estado = 'esperando'
                   AND le2.fecha_alta <= le.fecha_alta) AS posicion
        FROM    lista_espera le
        JOIN    libros  l ON le.id_libro   = l.id_libro
        JOIN    usuarios u ON le.id_usuario = u.id_usuario
        LEFT JOIN (
            SELECT id_libro, SUM(disponible='disponible') AS disponibles
            FROM   ejemplares WHERE disponible != 'obsoleto' GROUP BY id_libro
        ) s ON s.id_libro = l.id_libro
        WHERE   {$whereSQL}
        ORDER   BY l.titulo ASC, le.fecha_alta ASC
        LIMIT   100
    ");
    $stStaff->execute($params);
    $listaStaff = $stStaff->fetchAll();

    // Stats
    $statsLE = $db->query("
        SELECT
            SUM(estado = 'esperando')   AS esperando,
            SUM(estado = 'notificado')  AS notificado,
            COUNT(DISTINCT id_libro)    AS libros_con_espera
        FROM lista_espera WHERE estado IN ('esperando','notificado')
    ")->fetch();

    // Si se filtra por libro, cargar datos del libro
    $libroFiltro = null;
    if ($libroId > 0) {
        $stL = $db->prepare("SELECT id_libro, titulo, autor FROM libros WHERE id_libro = ?");
        $stL->execute([$libroId]);
        $libroFiltro = $stL->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waitlist — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:flex-start; gap:10px; padding:13px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-ok      { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .alert-error   { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
        .alert-info    { background:#eff6ff; color:#1d4ed8; border:1px solid #bfdbfe; }

        .stats-strip { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:24px; }
        .stat-tile { background:#fff; border:1px solid #e2e8f0; border-radius:10px;
                     padding:16px 20px; display:flex; align-items:center; gap:14px; }
        .st-icon { width:42px; height:42px; border-radius:8px;
                   display:flex; align-items:center; justify-content:center; font-size:18px; flex-shrink:0; }
        .st-amber  { background:#fef9c3; color:#b45309; }
        .st-blue   { background:#dbeafe; color:#1d4ed8; }
        .st-green  { background:#dcfce7; color:#16a34a; }
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

        .table-wrap { background:#fff; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; }
        table.wt { width:100%; border-collapse:collapse; font-size:13px; }
        table.wt thead th { padding:10px 14px; background:#f8fafc; text-align:left;
            font-size:10px; font-weight:700; letter-spacing:.8px; text-transform:uppercase;
            color:#94a3b8; border-bottom:1px solid #e2e8f0; white-space:nowrap; }
        table.wt tbody tr { border-bottom:1px solid #f1f5f9; }
        table.wt tbody tr:last-child { border-bottom:none; }
        table.wt tbody tr:hover { background:#fafbfc; }
        table.wt td { padding:10px 14px; vertical-align:middle; }

        .pos-badge { display:inline-flex; align-items:center; justify-content:center;
                     width:28px; height:28px; border-radius:50%;
                     font-size:13px; font-weight:700; flex-shrink:0; }
        .pos-1 { background:#fef9c3; color:#b45309; }
        .pos-n { background:#f1f5f9; color:#475569; }

        .estado-pill { display:inline-block; padding:2px 9px; border-radius:10px;
                       font-size:11px; font-weight:700; }
        .ep-wait   { background:#fef9c3; color:#b45309; }
        .ep-notif  { background:#dbeafe; color:#1d4ed8; }

        .btn-sm { display:inline-flex; align-items:center; gap:5px;
                  padding:5px 10px; border-radius:6px; font-size:12px; font-weight:600;
                  border:1px solid; cursor:pointer; text-decoration:none; transition:.15s; }
        .btn-notify { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
        .btn-notify:hover { background:#dbeafe; }
        .btn-cancel-e { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
        .btn-cancel-e:hover { background:#fee2e2; }

        /* User view: book cards */
        .wait-cards { display:flex; flex-direction:column; gap:12px; }
        .wait-card {
            background:#fff; border:1px solid #e2e8f0; border-radius:10px;
            padding:16px 20px; display:flex; align-items:center; gap:16px; flex-wrap:wrap;
        }
        .wc-pos { flex-shrink:0; }
        .wc-info { flex:1; min-width:200px; }
        .wc-title { font-size:15px; font-weight:700; color:#1e293b; }
        .wc-sub   { font-size:13px; color:#6b7280; margin-top:3px; }
        .wc-actions { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

        .avail-pill { display:inline-flex; align-items:center; gap:5px;
                      padding:4px 10px; border-radius:20px; font-size:12px; font-weight:600; }
        .ap-avail { background:#dcfce7; color:#16a34a; border:1px solid #bbf7d0; }
        .ap-wait  { background:#fef9c3; color:#b45309; border:1px solid #fde68a; }

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

    <main style="max-width:1100px;margin:0 auto;padding:32px 20px;">

        <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:16px;margin-bottom:24px;">
            <div>
                <h1 style="font-size:24px;font-weight:700;color:#1e293b;margin-bottom:4px;">
                    <?= $canManage ? 'Waitlist Management' : 'My Waitlist' ?>
                </h1>
                <p style="font-size:14px;color:#6b7280;">
                    <?= $canManage
                        ? 'Users waiting for unavailable books. Notify them when a copy becomes available.'
                        : 'Books you are waiting for. You\'ll be notified when a copy is available.' ?>
                </p>
            </div>
            <?php if ($canManage && $libroId): ?>
                <a href="listaEspera.php" style="display:inline-flex;align-items:center;gap:8px;
                   padding:10px 16px;background:#fff;border:1px solid #e2e8f0;border-radius:8px;
                   color:#374151;font-weight:600;font-size:14px;text-decoration:none;">
                    <i class="fa-solid fa-list"></i> All Waitlists
                </a>
            <?php endif; ?>
        </div>

        <?php if ($exito): ?>
            <div class="alert alert-ok"><i class="fa-solid fa-circle-check" style="flex-shrink:0;"></i> <?= e($exito) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation" style="flex-shrink:0;"></i> <?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($canManage): ?>
        <!-- ── Vista de staff ──────────────────────────────────────────────── -->

        <!-- Stats -->
        <div class="stats-strip">
            <div class="stat-tile">
                <div class="st-icon st-amber"><i class="fa-solid fa-hourglass-half"></i></div>
                <div>
                    <div class="st-num"><?= (int)($statsLE['esperando'] ?? 0) ?></div>
                    <div class="st-lbl">Waiting</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-blue"><i class="fa-solid fa-bell"></i></div>
                <div>
                    <div class="st-num"><?= (int)($statsLE['notificado'] ?? 0) ?></div>
                    <div class="st-lbl">Notified</div>
                </div>
            </div>
            <div class="stat-tile">
                <div class="st-icon st-green"><i class="fa-solid fa-book"></i></div>
                <div>
                    <div class="st-num"><?= (int)($statsLE['libros_con_espera'] ?? 0) ?></div>
                    <div class="st-lbl">Books with Waitlist</div>
                </div>
            </div>
        </div>

        <?php if ($libroFiltro): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-book" style="flex-shrink:0;"></i>
                Showing waitlist for: <strong><?= e($libroFiltro['titulo']) ?></strong>
                <?php if ($libroFiltro['autor']): ?> — <?= e($libroFiltro['autor']) ?><?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <form method="GET" action="listaEspera.php" class="filter-bar">
            <?php if ($libroId): ?>
                <input type="hidden" name="libro_id" value="<?= $libroId ?>">
            <?php endif; ?>
            <div class="fb-group" style="flex:1;min-width:160px;">
                <div class="fb-lbl">Search</div>
                <input type="text" name="q" placeholder="User name, email, book title…" value="<?= e($q) ?>">
            </div>
            <div class="fb-group">
                <div class="fb-lbl">Status</div>
                <select name="estado">
                    <option value="esperando"  <?= $verEstado === 'esperando'  ? 'selected' : '' ?>>Waiting</option>
                    <option value="notificado" <?= $verEstado === 'notificado' ? 'selected' : '' ?>>Notified</option>
                    <option value="cancelado"  <?= $verEstado === 'cancelado'  ? 'selected' : '' ?>>Cancelled</option>
                    <option value="prestado"   <?= $verEstado === 'prestado'   ? 'selected' : '' ?>>Fulfilled</option>
                </select>
            </div>
            <button type="submit" class="btn-create" style="height:38px;padding:0 16px;">
                <i class="fa-solid fa-filter"></i> Filter
            </button>
        </form>

        <!-- Table -->
        <div class="table-wrap">
            <?php if (empty($listaStaff)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-inbox"></i>
                    No entries for the selected filters.
                </div>
            <?php else: ?>
            <table class="wt">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>User</th>
                        <th>Book</th>
                        <th>Since</th>
                        <th>Copies Available</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($listaStaff as $row): ?>
                    <tr>
                        <td>
                            <span class="pos-badge <?= $row['posicion'] === 1 ? 'pos-1' : 'pos-n' ?>">
                                <?= (int)$row['posicion'] ?>
                            </span>
                        </td>
                        <td>
                            <div style="font-weight:600;"><?= e($row['nombre_completo']) ?></div>
                            <div style="font-size:11px;color:#6b7280;"><?= e($row['email']) ?> · <?= tipoLabel($row['usuario_tipo']) ?></div>
                        </td>
                        <td>
                            <a href="bookInformation.php?id=<?= $row['id_libro'] ?>"
                               style="color:#1e293b;font-weight:600;text-decoration:none;">
                                <?= e(mb_strimwidth($row['titulo'],0,40,'…')) ?>
                            </a>
                        </td>
                        <td style="font-size:12px;color:#6b7280;white-space:nowrap;">
                            <?= date('d M Y', strtotime($row['fecha_alta'])) ?>
                        </td>
                        <td style="text-align:center;">
                            <?php if ($row['copias_disponibles'] > 0): ?>
                                <span class="avail-pill ap-avail">
                                    <i class="fa-solid fa-circle-check"></i>
                                    <?= (int)$row['copias_disponibles'] ?> available
                                </span>
                            <?php else: ?>
                                <span class="avail-pill ap-wait">
                                    <i class="fa-solid fa-clock"></i> None
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="estado-pill <?= $row['estado'] === 'notificado' ? 'ep-notif' : 'ep-wait' ?>">
                                <?= ucfirst($row['estado']) ?>
                            </span>
                            <?php if ($row['fecha_notif']): ?>
                                <div style="font-size:10px;color:#6b7280;margin-top:2px;">
                                    <?= date('d M', strtotime($row['fecha_notif'])) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                <?php if ($row['estado'] === 'esperando'): ?>
                                    <form method="POST" action="listaEspera.php" style="display:inline;">
                                        <input type="hidden" name="_action"   value="notify">
                                        <input type="hidden" name="espera_id" value="<?= $row['id_espera'] ?>">
                                        <input type="hidden" name="libro_id"  value="<?= $row['id_libro'] ?>">
                                        <button type="submit" class="btn-sm btn-notify">
                                            <i class="fa-solid fa-bell"></i> Notify
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if (in_array($row['estado'], ['esperando','notificado'])): ?>
                                    <?php if ($row['copias_disponibles'] > 0): ?>
                                        <a href="prestamo.php?libro_id=<?= $row['id_libro'] ?>&uid=<?= $row['id_usuario'] ?>"
                                           class="btn-sm" style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                                            <i class="fa-solid fa-book-bookmark"></i> Issue Loan
                                        </a>
                                    <?php endif; ?>
                                    <form method="POST" action="listaEspera.php" style="display:inline;">
                                        <input type="hidden" name="_action"   value="cancel">
                                        <input type="hidden" name="espera_id" value="<?= $row['id_espera'] ?>">
                                        <input type="hidden" name="libro_id"  value="<?= $row['id_libro'] ?>">
                                        <button type="submit" class="btn-sm btn-cancel-e"
                                                onclick="return confirm('Remove this entry from the waitlist?')">
                                            <i class="fa-solid fa-xmark"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <?php else: ?>
        <!-- ── Vista de usuario ───────────────────────────────────────────── -->

        <?php if (empty($miLista)): ?>
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:48px;text-align:center;">
                <i class="fa-solid fa-hourglass" style="font-size:36px;color:#cbd5e1;display:block;margin-bottom:12px;"></i>
                <p style="font-size:14px;color:#6b7280;margin-bottom:16px;">You are not on any waitlist.</p>
                <a href="catalogSettings.php" class="btn-create" style="display:inline-flex;gap:8px;">
                    <i class="fa-solid fa-search"></i> Browse Catalog
                </a>
            </div>
        <?php else: ?>
            <div class="wait-cards">
            <?php foreach ($miLista as $item):
                $pos    = $posiciones[$item['id_espera']] ?? '?';
                $avail  = (int)$item['copias_disponibles'] > 0;
            ?>
                <div class="wait-card">
                    <div class="wc-pos">
                        <span class="pos-badge <?= $pos === 1 ? 'pos-1' : 'pos-n' ?>"
                              title="Position in queue"><?= $pos ?></span>
                    </div>
                    <div class="wc-info">
                        <div class="wc-title">
                            <a href="bookInformation.php?id=<?= $item['id_libro'] ?>"
                               style="color:#1e293b;text-decoration:none;"><?= e($item['titulo']) ?></a>
                        </div>
                        <div class="wc-sub">
                            <?= e($item['autor'] ?? '—') ?>
                            &nbsp;·&nbsp; Position <strong><?= $pos ?></strong> in queue
                            &nbsp;·&nbsp; Joined <?= date('d M Y', strtotime($item['fecha_alta'])) ?>
                        </div>
                    </div>
                    <div class="wc-actions">
                        <?php if ($item['estado'] === 'notificado'): ?>
                            <span class="avail-pill ap-avail">
                                <i class="fa-solid fa-bell"></i> You've been notified! Visit the library.
                            </span>
                        <?php elseif ($avail): ?>
                            <span class="avail-pill ap-avail">
                                <i class="fa-solid fa-circle-check"></i> Copy available — contact the library
                            </span>
                        <?php else: ?>
                            <span class="avail-pill ap-wait">
                                <i class="fa-solid fa-clock"></i> Waiting
                            </span>
                        <?php endif; ?>
                        <form method="POST" action="listaEspera.php" style="display:inline;">
                            <input type="hidden" name="_action"   value="cancel_own">
                            <input type="hidden" name="espera_id" value="<?= $item['id_espera'] ?>">
                            <button type="submit" class="btn-sm btn-cancel-e"
                                    onclick="return confirm('Remove yourself from this waitlist?')">
                                <i class="fa-solid fa-xmark"></i> Leave Queue
                            </button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>

    </main>

</body>
</html>
