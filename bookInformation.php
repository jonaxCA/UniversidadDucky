<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$db = getDB();
$me = currentUser();

$libroId = (int) ($_GET['id'] ?? 0);
if ($libroId <= 0) {
    header('Location: catalogSettings.php');
    exit;
}

// ── Datos del libro + editorial + categoría + stats de ejemplares ────────────
$stmt = $db->prepare("
    SELECT
        l.*,
        e.nombre  AS editorial_nombre,
        c.nombre  AS categoria_nombre,
        COALESCE(s.total,       0) AS total_copias,
        COALESCE(s.disponibles, 0) AS copias_disponibles,
        COALESCE(s.prestadas,   0) AS copias_prestadas,
        s.biblioteca,
        s.ubicacion
    FROM libros l
    LEFT JOIN editoriales e ON l.id_editorial = e.id_editorial
    LEFT JOIN categorias  c ON l.id_categoria = c.id_categoria
    LEFT JOIN (
        SELECT
            id_libro,
            COUNT(*)                             AS total,
            SUM(disponible = 'disponible')       AS disponibles,
            SUM(disponible = 'prestado')         AS prestadas,
            MIN(biblioteca)                      AS biblioteca,
            MIN(ubicacion_pasillo_estante)       AS ubicacion
        FROM ejemplares
        WHERE disponible != 'obsoleto'
        GROUP BY id_libro
    ) s ON l.id_libro = s.id_libro
    WHERE l.id_libro = ?
");
$stmt->execute([$libroId]);
$libro = $stmt->fetch();

if (!$libro) {
    header('Location: catalogSettings.php');
    exit;
}

// ── Total de préstamos históricos ────────────────────────────────────────────
$totalPrestamos = (int) $db->prepare("
    SELECT COUNT(*) FROM prestamos p
    JOIN ejemplares ej ON p.id_ejemplar = ej.id_ejemplar
    WHERE ej.id_libro = ?
")->execute([$libroId]) ? $db->query("
    SELECT COUNT(*) FROM prestamos p
    JOIN ejemplares ej ON p.id_ejemplar = ej.id_ejemplar
    WHERE ej.id_libro = $libroId
")->fetchColumn() : 0;

// ── Info de disponibilidad ───────────────────────────────────────────────────
$dispInfo = disponibilidadInfo((int)$libro['copias_disponibles'], (int)$libro['total_copias']);

$canEdit  = in_array($me['tipo'], ['administrador', 'bibliotecario'], true);
$imgSrc   = $libro['imagen_url']
    ?: 'https://placehold.co/200x280/e2e8f0/475569?text=' . urlencode(mb_substr($libro['titulo'], 0, 12));

// Parsear ubicación: "Estoa - Level 3 - B4"
$ubicPartes = array_map('trim', explode(' - ', $libro['ubicacion'] ?? ''));
$ubicTexto  = $libro['ubicacion']
    ? ($libro['biblioteca'] ?? $ubicPartes[0] ?? '') . ' — ' . implode(', ', array_slice($ubicPartes, 1))
    : '—';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($libro['titulo']) ?> - Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .action-bar { display:flex; gap:10px; margin-top:16px; flex-wrap:wrap; }
        .btn-ficha  { display:inline-flex; align-items:center; gap:8px;
                      padding:10px 18px; background:#eff6ff; color:#1d4ed8;
                      border:1px solid #bfdbfe; border-radius:8px;
                      font-weight:600; font-size:14px; text-decoration:none; transition:.2s; }
        .btn-ficha:hover { background:#dbeafe; }
        .info-grid-extra { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:16px; }
        @media(max-width:600px){ .info-grid-extra { grid-template-columns:1fr; } }
    </style>
</head>
<body class="dashboard-body">

    <header class="top-navbar">
        <div class="logo-area">
            <img src="images/duckyNav.jpeg" alt="Universidad Ducky" class="nav-logo">
        </div>
        <nav class="top-nav-links">
            <?php if (in_array($me['tipo'], ['administrador','bibliotecario'], true)): ?>
                <a href="dashboard.php">Dashboard</a>
            <?php endif; ?>
            <a href="catalogSettings.php" class="active">Catalog</a>
            <?php if (in_array($me['tipo'], ['administrador','bibliotecario'], true)): ?>
                <a href="transactions.php">Loans</a>
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

    <main class="book-details-page">

        <div class="breadcrumb-nav">
            <a href="catalogSettings.php" class="back-link">
                <div class="back-icon-circle"><i class="fa-solid fa-arrow-left"></i></div>
                Go Back
            </a>
            <span class="separator">/</span>
            <span class="current-page">Book Details</span>
        </div>

        <div class="book-details-card">

            <div class="book-cover-section">
                <img src="<?= e($imgSrc) ?>"
                     alt="<?= e($libro['titulo']) ?>"
                     class="large-book-cover"
                     onerror="this.src='https://placehold.co/200x280/e2e8f0/475569?text=No+Cover'">
            </div>

            <div class="book-info-section">

                <div class="info-header">
                    <span class="book-badge <?= $dispInfo['class'] ?>">
                        <span class="dot"></span> <?= $dispInfo['label'] ?>
                    </span>
                    <?php if ($canEdit): ?>
                        <a href="bookManagement.php?id=<?= $libroId ?>" class="btn-edit-icon" title="Editar">
                            <i class="fa-solid fa-pen"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <h1 class="book-main-title"><?= e($libro['titulo']) ?></h1>

                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">ISBN</span>
                        <span class="info-value"><?= e($libro['isbn'] ?? '—') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">GENRE</span>
                        <span class="info-value"><?= e($libro['categoria_nombre'] ?? '—') ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">LOCATION</span>
                        <span class="info-value">
                            <i class="fa-solid fa-location-dot location-icon"></i> <?= e($ubicTexto) ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">AUTHOR</span>
                        <span class="info-value"><?= e($libro['autor'] ?? '—') ?></span>
                    </div>
                    <?php if ($libro['editorial_nombre']): ?>
                    <div class="info-item">
                        <span class="info-label">EDITORIAL</span>
                        <span class="info-value"><?= e($libro['editorial_nombre']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($libro['anio_publicacion']): ?>
                    <div class="info-item">
                        <span class="info-label">YEAR</span>
                        <span class="info-value"><?= e($libro['anio_publicacion']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($libro['descripcion'] || $libro['ficha_bibliografica']): ?>
                <div class="description-section">
                    <span class="info-label">DESCRIPTION</span>
                    <p class="description-text">
                        <?= e($libro['descripcion'] ?? $libro['ficha_bibliografica'] ?? '') ?>
                    </p>
                </div>
                <?php endif; ?>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-title">COPIES OWNED</span>
                            <span class="stat-number"><?= (int)$libro['total_copias'] ?></span>
                        </div>
                        <div class="progress-bar-bg">
                            <?php $pct = $libro['total_copias'] > 0
                                ? round(($libro['copias_disponibles'] / $libro['total_copias']) * 100) : 0; ?>
                            <div class="progress-bar-fill" style="width:<?= $pct ?>%;"></div>
                        </div>
                        <span class="stat-footer-text">
                            <?= (int)$libro['copias_disponibles'] ?> cop<?= $libro['copias_disponibles'] == 1 ? 'ia' : 'ias' ?> en estante
                        </span>
                    </div>

                    <div class="stat-card">
                        <div class="stat-header">
                            <span class="stat-title">TOTAL LOANS</span>
                            <span class="stat-number"><?= $totalPrestamos ?></span>
                        </div>
                        <div class="progress-bar-bg">
                            <div class="progress-bar-fill" style="width:<?= min(100, $totalPrestamos * 2) ?>%;"></div>
                        </div>
                        <span class="stat-footer-text">
                            <?= $totalPrestamos > 50 ? 'High demand item' : ($totalPrestamos > 0 ? 'Regular demand' : 'Never borrowed') ?>
                        </span>
                    </div>
                </div>

                <!-- Acciones -->
                <div class="action-bar">
                    <?php if ($canEdit && (int)$libro['copias_disponibles'] > 0): ?>
                        <a href="prestamo.php?libro_id=<?= $libroId ?>" class="btn-ficha"
                           style="background:#f0fdf4;color:#15803d;border-color:#bbf7d0;">
                            <i class="fa-solid fa-book-bookmark"></i> Register Loan
                        </a>
                    <?php elseif ((int)$libro['total_copias'] > 0): ?>
                        <a href="listaEspera.php?libro_id=<?= $libroId ?>" class="btn-ficha"
                           style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                            <i class="fa-solid fa-hourglass-half"></i>
                            <?= $canEdit ? 'View Waitlist' : 'Join Waitlist' ?>
                        </a>
                    <?php endif; ?>
                    <a href="fichaLibro.php?id=<?= $libroId ?>" class="btn-ficha" target="_blank">
                        <i class="fa-solid fa-paste"></i> Generate Bibliographic Card
                    </a>
                </div>

            </div>
        </div>

    </main>

    <footer class="simple-footer">
        <p>&copy; 2026 Universidad Ducky - Library Administrative System</p>
    </footer>


    <?php include __DIR__ . '/includes/chatbot_widget.php'; ?>
</body>
</html>
