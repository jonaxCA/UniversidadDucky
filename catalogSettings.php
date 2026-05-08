<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$db       = getDB();
$me       = currentUser();
$flash    = $_GET['success'] ?? '';
$flashErr = ($_GET['error'] ?? '') === 'forbidden'
    ? "You don't have permission to access that page."
    : '';

// ── Parámetros de búsqueda y filtros ────────────────────────────────────────
$q          = trim($_GET['q']          ?? '');
$filtAutor  = trim($_GET['autor']      ?? '');
$filtGenero = (int) ($_GET['genero']   ?? 0);
$filtBiblioteca = trim($_GET['biblioteca'] ?? '');
$page       = max(1, (int) ($_GET['page'] ?? 1));
$perPage    = 12;

// ── Listas para dropdowns de filtros ────────────────────────────────────────
$autoresRaw = $db->query(
    "SELECT DISTINCT autor FROM libros WHERE autor IS NOT NULL ORDER BY autor"
)->fetchAll(PDO::FETCH_COLUMN);

$categoriasRaw = $db->query(
    "SELECT id_categoria, nombre FROM categorias ORDER BY nombre"
)->fetchAll();

// ── Construir WHERE ──────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = '(l.titulo LIKE ? OR l.autor LIKE ? OR l.isbn LIKE ?)';
    $params[] = "%$q%"; $params[] = "%$q%"; $params[] = "%$q%";
}
if ($filtAutor !== '') {
    $where[]  = 'l.autor = ?';
    $params[] = $filtAutor;
}
if ($filtGenero > 0) {
    $where[]  = 'l.id_categoria = ?';
    $params[] = $filtGenero;
}
if ($filtBiblioteca !== '') {
    $where[]  = 'EXISTS (SELECT 1 FROM ejemplares ex WHERE ex.id_libro = l.id_libro AND ex.biblioteca = ? AND ex.disponible != \'obsoleto\')';
    $params[] = $filtBiblioteca;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Total de resultados ──────────────────────────────────────────────────────
$totalStmt = $db->prepare("SELECT COUNT(DISTINCT l.id_libro) FROM libros l $whereSQL");
$totalStmt->execute($params);
$totalCount = (int) $totalStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalCount / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// ── Query principal con stats de ejemplares ──────────────────────────────────
$booksSQL = "
    SELECT
        l.id_libro, l.titulo, l.autor, l.isbn, l.anio_publicacion, l.imagen_url,
        e.nombre  AS editorial,
        c.nombre  AS categoria,
        COALESCE(s.total,       0) AS total_copias,
        COALESCE(s.disponibles, 0) AS copias_disponibles,
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
            MIN(biblioteca)                      AS biblioteca,
            MIN(ubicacion_pasillo_estante)       AS ubicacion
        FROM ejemplares
        WHERE disponible != 'obsoleto'
        GROUP BY id_libro
    ) s ON l.id_libro = s.id_libro
    $whereSQL
    ORDER BY l.titulo
    LIMIT $perPage OFFSET $offset
";
$booksStmt = $db->prepare($booksSQL);
$booksStmt->execute($params);
$books = $booksStmt->fetchAll();

// ── Helper URL preservando filtros ───────────────────────────────────────────
function catalogUrl(array $override = []): string
{
    $base = array_filter(array_merge([
        'q'          => $_GET['q']          ?? '',
        'autor'      => $_GET['autor']      ?? '',
        'genero'     => $_GET['genero']     ?? '',
        'biblioteca' => $_GET['biblioteca'] ?? '',
        'page'       => $_GET['page']       ?? '',
    ], $override));
    return 'catalogSettings.php' . ($base ? '?' . http_build_query($base) : '');
}

$canEdit = in_array($me['tipo'], ['administrador', 'bibliotecario'], true);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catalog Management - Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-success { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .filter-form   { display:contents; }
        .no-results    { text-align:center; padding:60px 20px; color:#6b7280; }
        .no-results i  { font-size:48px; margin-bottom:16px; display:block; opacity:.3; }
        /* Paginación del catálogo */
        .catalog-pagination { display:flex; justify-content:center; align-items:center;
                              gap:8px; margin-top:28px; flex-wrap:wrap; }
        .catalog-pagination a, .catalog-pagination span {
            padding:8px 14px; border-radius:8px; font-size:14px; font-weight:500;
            border:1px solid #e5e7eb; text-decoration:none; color:#374151; }
        .catalog-pagination a:hover { background:#f3f4f6; }
        .catalog-pagination .active-page { background:#0f3524; color:#fff; border-color:#0f3524; }
        .catalog-pagination .disabled   { opacity:.4; pointer-events:none; }
        /* select filtros */
        .filter-select { padding:8px 12px; border:1px solid #d1d5db; border-radius:8px;
                         font-size:13px; background:#fff; color:#374151; cursor:pointer; }
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

    <main class="catalog-container">

        <?php if ($flashErr): ?>
            <div class="alert alert-error" style="margin-bottom:0;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:12px 16px;border-radius:8px;display:flex;align-items:center;gap:10px;font-weight:500;">
                <i class="fa-solid fa-circle-exclamation"></i> <?= e($flashErr) ?>
            </div>
        <?php endif; ?>

        <?php if ($flash === 'book_registered'): ?>
            <div class="alert alert-success" style="margin-bottom:0;">
                <i class="fa-solid fa-circle-check"></i> Libro registrado correctamente.
            </div>
        <?php elseif ($flash === 'book_updated'): ?>
            <div class="alert alert-success" style="margin-bottom:0;">
                <i class="fa-solid fa-circle-check"></i> Libro actualizado correctamente.
            </div>
        <?php elseif ($flash === 'book_disabled'): ?>
            <div class="alert alert-success" style="margin-bottom:0;">
                <i class="fa-solid fa-circle-check"></i> Libro deshabilitado del catálogo.
            </div>
        <?php endif; ?>

        <div class="catalog-header">
            <h1>Catalog Management</h1>
            <p>Advanced Book Search and Administration — <?= $totalCount ?> libro<?= $totalCount !== 1 ? 's' : '' ?> encontrado<?= $totalCount !== 1 ? 's' : '' ?></p>
        </div>

        <div class="catalog-layout">
            <div class="catalog-main-content">

                <!-- Search + Filters -->
                <form method="GET" action="catalogSettings.php" class="filter-form">
                    <div class="search-card">
                        <div class="search-input-wrapper">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="text" name="q" id="bookSearchInput"
                                   placeholder="Search by Title, Author, or ISBN"
                                   autocomplete="off"
                                   value="<?= e($q) ?>">
                            <button type="submit" class="btn-search">Search</button>
                        </div>

                        <div class="search-filters" style="display:flex;">
                            <div class="filter-group">
                                <!-- Author -->
                                <select name="autor" class="filter-select" onchange="this.form.submit()">
                                    <option value="">Author</option>
                                    <?php foreach ($autoresRaw as $a): ?>
                                        <option value="<?= e($a) ?>" <?= $filtAutor === $a ? 'selected' : '' ?>>
                                            <?= e($a) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- Genre -->
                                <select name="genero" class="filter-select" onchange="this.form.submit()">
                                    <option value="">Genre</option>
                                    <?php foreach ($categoriasRaw as $cat): ?>
                                        <option value="<?= (int)$cat['id_categoria'] ?>"
                                            <?= $filtGenero === (int)$cat['id_categoria'] ? 'selected' : '' ?>>
                                            <?= e($cat['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>

                                <!-- Location -->
                                <select name="biblioteca" class="filter-select" onchange="this.form.submit()">
                                    <option value="">Location</option>
                                    <option value="Estoa" <?= $filtBiblioteca === 'Estoa' ? 'selected' : '' ?>>Estoa</option>
                                    <option value="CCU"   <?= $filtBiblioteca === 'CCU'   ? 'selected' : '' ?>>CCU</option>
                                </select>
                            </div>

                            <?php if ($q || $filtAutor || $filtGenero || $filtBiblioteca): ?>
                                <a href="catalogSettings.php" class="clear-filters-btn">
                                    <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
                                </a>
                            <?php else: ?>
                                <button type="button" class="clear-filters-btn" disabled style="opacity:.4;">
                                    <i class="fa-solid fa-filter-circle-xmark"></i> Clear Filters
                                </button>
                            <?php endif; ?>
                        </div>

                        <?php if ($q || $filtAutor || $filtGenero || $filtBiblioteca): ?>
                            <div class="search-results-text" style="display:block;">
                                <span><?= $totalCount ?> Result<?= $totalCount !== 1 ? 's' : '' ?></span>
                                <?php
                                $disponiblesTotal = array_sum(array_column($books, 'copias_disponibles'));
                                ?>
                                - <span><?= $disponiblesTotal ?> Available</span>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Books Grid -->
                <div class="books-grid" id="booksGrid">
                    <?php if (empty($books)): ?>
                        <div class="no-results" style="grid-column:1/-1;">
                            <i class="fa-solid fa-book-open"></i>
                            <p>No se encontraron libros con esos criterios.</p>
                            <?php if ($canEdit): ?>
                                <a href="bookRegister.php" class="btn-search" style="display:inline-block;margin-top:12px;">
                                    <i class="fa-solid fa-plus"></i> Registrar primer libro
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($books as $b):
                            $dispInfo = disponibilidadInfo((int)$b['copias_disponibles'], (int)$b['total_copias']);
                            $imgSrc   = $b['imagen_url'] ?: 'https://placehold.co/120x160/e2e8f0/475569?text=' . urlencode(mb_substr($b['titulo'], 0, 10));
                        ?>
                        <a href="bookInformation.php?id=<?= (int)$b['id_libro'] ?>"
                           class="book-card"
                           data-title="<?= e(strtolower($b['titulo'])) ?>"
                           data-author="<?= e(strtolower($b['autor'] ?? '')) ?>"
                           data-isbn="<?= e($b['isbn'] ?? '') ?>">
                            <img src="<?= e($imgSrc) ?>" alt="<?= e($b['titulo']) ?>" class="book-cover"
                                 onerror="this.src='https://placehold.co/120x160/e2e8f0/475569?text=No+Cover'">
                            <div class="book-info">
                                <div class="book-title-row">
                                    <h3 class="book-title" title="<?= e($b['titulo']) ?>">
                                        <?= e(mb_strlen($b['titulo']) > 22 ? mb_substr($b['titulo'], 0, 22) . '…' : $b['titulo']) ?>
                                    </h3>
                                    <span class="book-badge <?= $dispInfo['class'] ?>">
                                        <span class="dot"></span> <?= $dispInfo['label'] ?>
                                    </span>
                                </div>
                                <p class="book-author"><?= e($b['autor'] ?? '—') ?></p>
                                <div class="book-meta">
                                    <?php if ($b['isbn']): ?>
                                        <span><i class="fa-solid fa-barcode"></i> <?= e($b['isbn']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($b['biblioteca']): ?>
                                        <span><i class="fa-solid fa-location-dot"></i> <?= e($b['biblioteca']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Paginación -->
                <?php if ($totalPages > 1): ?>
                <div class="catalog-pagination">
                    <a href="<?= catalogUrl(['page' => $page - 1]) ?>"
                       class="<?= $page <= 1 ? 'disabled' : '' ?>">
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                        <a href="<?= catalogUrl(['page' => $p]) ?>"
                           class="<?= $p === $page ? 'active-page' : '' ?>">
                            <?= $p ?>
                        </a>
                    <?php endfor; ?>
                    <a href="<?= catalogUrl(['page' => $page + 1]) ?>"
                       class="<?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                </div>
                <?php endif; ?>

            </div><!-- /catalog-main-content -->

            <aside class="catalog-sidebar">
                <div class="quick-actions-card">
                    <h3>Quick Actions</h3>
                    <p class="subtitle">Common administrative tasks</p>

                    <?php if ($canEdit): ?>
                    <a href="bookRegister.php" class="action-item" style="color:inherit;">
                        <div class="action-icon" style="color:#10b981;background:#ecfdf5;">
                            <i class="fa-solid fa-square-plus"></i>
                        </div>
                        <div class="action-text">
                            <h4>New Acquisition Request</h4>
                            <p>Request new materials</p>
                        </div>
                    </a>
                    <?php endif; ?>

                    <a href="catalogSettings.php?genero=" class="action-item" style="color:inherit;">
                        <div class="action-icon" style="color:#10b981;background:#ecfdf5;">
                            <i class="fa-solid fa-paste"></i>
                        </div>
                        <div class="action-text">
                            <h4>Generate Bibliographic Card</h4>
                            <p>Search a book first, then click its details</p>
                        </div>
                    </a>

                    <?php if (in_array($me['tipo'], ['administrador'], true)): ?>
                    <a href="addUsers.php" class="action-item" style="color:inherit;">
                        <div class="action-icon" style="color:#10b981;background:#ecfdf5;">
                            <i class="fa-solid fa-user-plus"></i>
                        </div>
                        <div class="action-text">
                            <h4>Register User</h4>
                            <p>Add new library patron</p>
                        </div>
                    </a>
                    <?php endif; ?>
                </div>

                <div class="system-status-card">
                    <i class="fa-solid fa-circle-info status-icon"></i>
                    <div>
                        <h4>Catalog Stats</h4>
                        <p>
                            <?= $totalCount ?> libro<?= $totalCount !== 1 ? 's' : '' ?> en catálogo.<br>
                            <?php
                            $totalDisp = $db->query("SELECT SUM(disponible='disponible') FROM ejemplares WHERE disponible != 'obsoleto'")->fetchColumn();
                            $totalCopias = $db->query("SELECT COUNT(*) FROM ejemplares WHERE disponible != 'obsoleto'")->fetchColumn();
                            ?>
                            <?= (int)$totalDisp ?> / <?= (int)$totalCopias ?> ejemplares disponibles.
                        </p>
                    </div>
                </div>
            </aside>
        </div><!-- /catalog-layout -->
    </main>

    <script>
    // Búsqueda cliente (filtra las tarjetas ya cargadas en tiempo real)
    (function () {
        const input = document.getElementById('bookSearchInput');
        if (!input) return;
        input.addEventListener('input', () => {
            const q = input.value.toLowerCase().trim();
            document.querySelectorAll('.book-card').forEach(card => {
                const match = !q ||
                    card.dataset.title.includes(q) ||
                    card.dataset.author.includes(q) ||
                    (card.dataset.isbn || '').includes(q);
                card.style.display = match ? '' : 'none';
            });
        });
    })();
    </script>

    <?php include __DIR__ . '/includes/chatbot_widget.php'; ?>
</body>
</html>
