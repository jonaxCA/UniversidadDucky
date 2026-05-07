<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me    = currentUser();
$db    = getDB();
$error = '';

$libroId = (int) ($_GET['id'] ?? 0);
if ($libroId <= 0) {
    header('Location: catalogSettings.php');
    exit;
}

// ── Cargar libro con relaciones ──────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT l.*, e.nombre AS editorial_nombre, c.nombre AS categoria_nombre
    FROM libros l
    LEFT JOIN editoriales e ON l.id_editorial = e.id_editorial
    LEFT JOIN categorias  c ON l.id_categoria = c.id_categoria
    WHERE l.id_libro = ?
");
$stmt->execute([$libroId]);
$libro = $stmt->fetch();

if (!$libro) {
    header('Location: catalogSettings.php');
    exit;
}

// ── Datos del primer ejemplar (ubicación) ────────────────────────────────────
$ejStmt = $db->prepare("
    SELECT biblioteca, ubicacion_pasillo_estante, precio_compra_usd
    FROM ejemplares
    WHERE id_libro = ? AND disponible != 'obsoleto'
    ORDER BY id_ejemplar
    LIMIT 1
");
$ejStmt->execute([$libroId]);
$primerejemplar = $ejStmt->fetch() ?: ['biblioteca' => '', 'ubicacion_pasillo_estante' => '', 'precio_compra_usd' => 0];

$currentUnits = countEjemplares($db, $libroId);

// Parsear ubicación: "Estoa - Level 3 - B4"
$partes   = array_map('trim', explode(' - ', $primerejemplar['ubicacion_pasillo_estante']));
$curBibl  = $primerejemplar['biblioteca'] ?: ($partes[0] ?? 'Estoa');
$curZone  = $partes[1] ?? 'Level 1';
$curShelf = $partes[2] ?? 'A1';

// ── Acción: Deshabilitar libro (POST con campo _action=disable) ─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'disable') {
    $db->prepare("UPDATE ejemplares SET disponible = 'obsoleto' WHERE id_libro = ?")
       ->execute([$libroId]);
    logAction($db, $me['id'], 'actualizar', 'libros', $libroId, ['accion' => 'deshabilitar'], 'catalogo');
    header('Location: catalogSettings.php?success=book_disabled');
    exit;
}

// ── Guardar cambios (POST normal) ────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titulo      = trim($_POST['bookTitle']       ?? '');
    $autor       = trim($_POST['bookAuthor']      ?? '');
    $editorialTxt= trim($_POST['bookEditorial']   ?? '');
    $anio        = (int) ($_POST['bookYear']      ?? 0);
    $precio      = (float) str_replace(['$', ',', ' MXN'], '', $_POST['bookPrice'] ?? '0');
    $descripcion = trim($_POST['bookDescription'] ?? '');
    $isbn        = trim($_POST['bookISBN']        ?? '');
    $imagen      = trim($_POST['bookImageUrl']    ?? '');
    $categoriaTxt= trim($_POST['bookGenre']       ?? '');
    $biblioteca  = $_POST['locLibrary']           ?? 'Estoa';
    // Handle optional cover image upload (takes precedence over URL when provided)
    if (isset($_FILES['bookImageFile']) && $_FILES['bookImageFile']['error'] === UPLOAD_ERR_OK) {
        $file    = $_FILES['bookImageFile'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $mime    = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowed, true)) {
            $error = 'Cover image must be a JPEG, PNG, WebP, or GIF file.';
        } elseif ($file['size'] > 2 * 1024 * 1024) {
            $error = 'Cover image must be smaller than 2 MB.';
        } else {
            $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            $dir    = __DIR__ . '/uploads/covers/';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $fname  = 'book_' . uniqid('', true) . '.' . $extMap[$mime];
            if (move_uploaded_file($file['tmp_name'], $dir . $fname)) {
                $imagen = 'uploads/covers/' . $fname;
            } else {
                $error = 'Could not save the uploaded image. Check directory permissions.';
            }
        }
    }
    $newUnits    = max(0, (int) ($_POST['locUnits'] ?? $currentUnits));
    $zona        = $_POST['locZone']              ?? $curZone;
    $estante     = $_POST['locShelf']             ?? $curShelf;
    $ubicacion   = "$biblioteca - $zona - $estante";

    $isbnError = '';
    if ($isbn !== '') {
        $isbnClean = preg_replace('/[\s\-]/', '', $isbn);
        if (!preg_match('/^\d{9}[\dX]$|^\d{13}$/i', $isbnClean)) {
            $isbnError = 'Invalid ISBN. Enter 10 digits (ISBN-10, last char may be X) or 13 digits (ISBN-13). Hyphens and spaces are allowed.';
        }
    }

    if (!$titulo || !$autor) {
        $error = 'Título y autor son obligatorios.';
    } elseif ($isbnError) {
        $error = $isbnError;
    } else {
        try {
            // ISBN único (excluyendo el actual)
            if ($isbn !== '') {
                $chk = $db->prepare("SELECT id_libro FROM libros WHERE isbn = ? AND id_libro != ? LIMIT 1");
                $chk->execute([$isbn, $libroId]);
                if ($chk->fetch()) { $error = 'Otro libro ya tiene ese ISBN.'; }
            }

            if (!$error) {
                $idEditorial = findOrCreateEditorial($db, $editorialTxt);
                $idCategoria = findOrCreateCategoria($db, $categoriaTxt);

                // Actualizar libro
                $db->prepare("
                    UPDATE libros
                    SET titulo = ?, autor = ?, isbn = ?, anio_publicacion = ?,
                        descripcion = ?, precio_mxn = ?, imagen_url = ?,
                        id_editorial = ?, id_categoria = ?
                    WHERE id_libro = ?
                ")->execute([
                    $titulo, $autor, $isbn ?: null, $anio ?: null,
                    $descripcion ?: null, $precio > 0 ? $precio : null,
                    $imagen ?: null, $idEditorial, $idCategoria, $libroId,
                ]);

                // Actualizar ubicación en todos los ejemplares activos
                $db->prepare("
                    UPDATE ejemplares
                    SET biblioteca = ?, ubicacion_pasillo_estante = ?
                    WHERE id_libro = ? AND disponible != 'obsoleto'
                ")->execute([$biblioteca, $ubicacion, $libroId]);

                // Ajustar cantidad de ejemplares
                if ($newUnits > $currentUnits) {
                    crearEjemplares($db, $libroId, $newUnits - $currentUnits, $biblioteca, $ubicacion, $precio, $currentUnits);
                } elseif ($newUnits < $currentUnits) {
                    // Marcar los últimos N como obsoletos
                    $toObsolete = $currentUnits - $newUnits;
                    $ids = $db->prepare("
                        SELECT id_ejemplar FROM ejemplares
                        WHERE id_libro = ? AND disponible = 'disponible'
                        ORDER BY id_ejemplar DESC
                        LIMIT $toObsolete
                    ");
                    $ids->execute([$libroId]);
                    $idList = $ids->fetchAll(PDO::FETCH_COLUMN);
                    if ($idList) {
                        $ph = implode(',', array_fill(0, count($idList), '?'));
                        $db->prepare("UPDATE ejemplares SET disponible = 'obsoleto' WHERE id_ejemplar IN ($ph)")
                           ->execute($idList);
                    }
                }

                // Regenerar ficha bibliográfica
                $fichaData = [
                    'autor' => $autor, 'titulo' => $titulo,
                    'editorial_nombre' => $editorialTxt,
                    'anio_publicacion' => $anio ?: '',
                    'isbn' => $isbn, 'categoria_nombre' => $categoriaTxt,
                ];
                $ficha = generarFichaBibliografica($fichaData);
                $db->prepare("UPDATE libros SET ficha_bibliografica = ? WHERE id_libro = ?")
                   ->execute([$ficha, $libroId]);

                logAction($db, $me['id'], 'actualizar', 'libros', $libroId, [
                    'antes'  => ['titulo' => $libro['titulo']],
                    'despues'=> ['titulo' => $titulo, 'unidades' => $newUnits],
                ], 'catalogo');

                header("Location: catalogSettings.php?success=book_updated");
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Error al guardar: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Management - Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:12px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }
    </style>
</head>
<body class="dashboard-body">

    <header class="top-navbar">
        <div class="logo-area">
            <h2 class="text-logo"><i class="fa-solid fa-book-open-reader"></i> DUCKY <span>UNIVERSIDAD</span></h2>
        </div>
        <nav class="top-nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="catalogSettings.php" class="active">Catalog</a>
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

    <main class="form-page-container edit-user-page">

        <div class="form-header-area">
            <a href="bookInformation.php?id=<?= $libroId ?>" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Go Back
            </a>
            <h1>Book Information</h1>
            <p>Make changes on registered books</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" id="bookManagementForm" enctype="multipart/form-data">
                <input type="hidden" name="_action" value="save">

                <!-- Basic Information -->
                <div class="form-section">
                    <h3 class="section-title">Basic Information</h3>
                    <div class="input-grid full-width">
                        <div class="input-group">
                            <label for="bookTitle">Book's Title</label>
                            <input type="text" id="bookTitle" name="bookTitle" class="base-input"
                                   value="<?= e($libro['titulo']) ?>">
                        </div>
                    </div>
                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="bookAuthor">Author</label>
                            <input type="text" id="bookAuthor" name="bookAuthor" class="base-input"
                                   value="<?= e($libro['autor'] ?? '') ?>">
                        </div>
                        <div class="input-group">
                            <label for="bookEditorial">Editorial / Publisher</label>
                            <input type="text" id="bookEditorial" name="bookEditorial" class="base-input"
                                   value="<?= e($libro['editorial_nombre'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="bookISBN">ISBN</label>
                            <input type="text" id="bookISBN" name="bookISBN" class="base-input"
                                   maxlength="20"
                                   value="<?= e($libro['isbn'] ?? '') ?>">
                            <small style="font-size:11px;color:#6b7280;margin-top:4px;display:block;">
                                ISBN-10 (10 digits) or ISBN-13 (13 digits). Hyphens are accepted.
                            </small>
                        </div>
                        <div class="input-group">
                            <label for="bookGenre">Genre / Category</label>
                            <input type="text" id="bookGenre" name="bookGenre" class="base-input"
                                   value="<?= e($libro['categoria_nombre'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <!-- Book Details -->
                <div class="form-section">
                    <h3 class="section-title">Book's Details</h3>
                    <div class="details-split-grid">
                        <div class="details-left">
                            <div class="input-group">
                                <label for="bookYear">Year</label>
                                <input type="number" id="bookYear" name="bookYear" class="base-input"
                                       value="<?= e($libro['anio_publicacion'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <label for="bookPrice">Unit Price (MXN)</label>
                                <input type="text" id="bookPrice" name="bookPrice" class="base-input"
                                       value="<?= $libro['precio_mxn'] ? '$' . number_format((float)$libro['precio_mxn'], 2) : '' ?>">
                            </div>
                            <div class="input-group">
                                <label>Cover Image</label>
                                <div style="display:flex;flex-direction:column;gap:8px;">
                                    <label style="font-size:12px;font-weight:600;color:#374151;display:flex;align-items:center;gap:6px;
                                                  border:2px dashed #e2e8f0;border-radius:8px;padding:10px 14px;cursor:pointer;background:#f8fafc;"
                                           for="bookImageFile">
                                        <i class="fa-solid fa-upload" style="color:#0f3524;"></i>
                                        Upload new file (JPEG / PNG / WebP, max 2 MB)
                                        <input type="file" id="bookImageFile" name="bookImageFile"
                                               accept="image/jpeg,image/png,image/webp,image/gif"
                                               style="display:none;">
                                    </label>
                                    <div style="font-size:11px;color:#94a3b8;text-align:center;">— or edit URL directly —</div>
                                    <input type="url" id="bookImageUrl" name="bookImageUrl" class="base-input"
                                           placeholder="https://example.com/cover.jpg"
                                           value="<?= e($libro['imagen_url'] ?? '') ?>">
                                    <small style="font-size:11px;color:#6b7280;">Uploaded file takes precedence over URL.</small>
                                </div>
                            </div>
                        </div>
                        <div class="details-right">
                            <div class="input-group h-100">
                                <label for="bookDescription">Description</label>
                                <textarea id="bookDescription" name="bookDescription"
                                          class="base-input textarea-input"><?= e($libro['descripcion'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Location -->
                <div class="form-section">
                    <h3 class="section-title">Book's Location</h3>
                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="locLibrary">Library</label>
                            <div class="select-wrapper">
                                <select id="locLibrary" name="locLibrary" class="base-input select-input">
                                    <option value="Estoa" <?= $curBibl === 'Estoa' ? 'selected' : '' ?>>Estoa</option>
                                    <option value="CCU"   <?= $curBibl === 'CCU'   ? 'selected' : '' ?>>CCU</option>
                                </select>
                                <i class="fa-solid fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="locUnits">Available Copies</label>
                            <div class="select-wrapper">
                                <select id="locUnits" name="locUnits" class="base-input select-input">
                                    <?php for ($i = 0; $i <= 20; $i++): ?>
                                        <option value="<?= $i ?>" <?= $currentUnits === $i ? 'selected' : '' ?>>
                                            <?= $i ?> <?= $i === $currentUnits ? '(actual)' : '' ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="locZone">Library Zone</label>
                            <div class="select-wrapper">
                                <select id="locZone" name="locZone" class="base-input select-input">
                                    <?php foreach (['Level 1','Level 2','Level 3'] as $z): ?>
                                        <option value="<?= $z ?>" <?= $curZone === $z ? 'selected' : '' ?>><?= $z ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="locShelf">Shelf</label>
                            <div class="select-wrapper">
                                <select id="locShelf" name="locShelf" class="base-input select-input">
                                    <?php foreach (['A1','A2','B1','B2','B3','B4','B5','C1','C2'] as $s): ?>
                                        <option value="<?= $s ?>" <?= $curShelf === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions book-actions">
                    <a href="catalogSettings.php" class="btn-cancel">Cancel</a>
                    <div class="right-actions">
                        <button type="button" class="btn-danger" id="btnDisableBook">
                            <i class="fa-solid fa-ban"></i> Disable Book
                        </button>
                        <button type="submit" class="btn-save">Save Changes</button>
                    </div>
                </div>

            </form>
        </div>

        <p class="footer-note">
            Los cambios quedan registrados en la bitácora ISO 9001 del sistema.
        </p>
    </main>

    <!-- Modal de confirmación para deshabilitar -->
    <div id="disableModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.5);
         z-index:9999; align-items:center; justify-content:center;">
        <div style="background:#fff; border-radius:16px; padding:32px; max-width:420px;
                    width:90%; box-shadow:0 20px 60px rgba(0,0,0,.3);">
            <h3 style="margin:0 0 12px; color:#dc2626;">
                <i class="fa-solid fa-triangle-exclamation"></i> Deshabilitar libro
            </h3>
            <p style="color:#374151; margin:0 0 24px;">
                ¿Seguro que deseas deshabilitar <strong>"<?= e($libro['titulo']) ?>"</strong>?<br>
                Todos los ejemplares quedarán fuera de circulación.
            </p>
            <div style="display:flex; gap:12px; justify-content:flex-end;">
                <button id="cancelDisable" style="padding:10px 20px; border:1px solid #d1d5db;
                        border-radius:8px; background:#fff; cursor:pointer; font-weight:600;">
                    Cancelar
                </button>
                <form method="POST" action="" style="display:inline;">
                    <input type="hidden" name="_action" value="disable">
                    <button type="submit" style="padding:10px 20px; background:#dc2626; color:#fff;
                            border:none; border-radius:8px; cursor:pointer; font-weight:600;">
                        Deshabilitar
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
    const modal        = document.getElementById('disableModal');
    const btnDisable   = document.getElementById('btnDisableBook');
    const btnCancel    = document.getElementById('cancelDisable');
    const bookForm     = document.getElementById('bookManagementForm');

    btnDisable.addEventListener('click', () => {
        modal.style.display = 'flex';
    });
    btnCancel.addEventListener('click', () => {
        modal.style.display = 'none';
    });
    modal.addEventListener('click', e => {
        if (e.target === modal) modal.style.display = 'none';
    });
    </script>
</body>
</html>
