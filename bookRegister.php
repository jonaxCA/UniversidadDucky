<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador', 'bibliotecario']);

$me    = currentUser();
$db    = getDB();
$error = '';
$old   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old = $_POST;

    $titulo      = trim($_POST['bookTitle']    ?? '');
    $autor       = trim($_POST['bookAuthor']   ?? '');
    $editorial   = trim($_POST['bookEditorial'] ?? '');
    $anio        = (int) ($_POST['bookYear']   ?? 0);
    $precio      = (float) str_replace(['$', ',', ' MXN'], '', $_POST['bookPrice'] ?? '0');
    $descripcion = trim($_POST['bookDescription'] ?? '');
    $isbn        = trim($_POST['bookISBN']     ?? '');
    $imagen      = trim($_POST['bookImageUrl'] ?? '');
    $categoria   = trim($_POST['bookGenre']    ?? '');
    $biblioteca  = $_POST['locLibrary']        ?? 'Estoa';
    $unidades    = max(1, (int) ($_POST['locUnits'] ?? 1));
    $zona        = $_POST['locZone']           ?? 'Level 1';
    $estante     = $_POST['locShelf']          ?? 'A1';

    $ubicacion = "$biblioteca - $zona - $estante";

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

    // Validaciones
    if (!$titulo || !$autor) {
        $error = $error ?: 'Título y autor son obligatorios.';
    } elseif ($isbn !== '') {
        $isbnClean = preg_replace('/[\s\-]/', '', $isbn);
        if (!preg_match('/^\d{9}[\dX]$|^\d{13}$/i', $isbnClean)) {
            $error = 'Invalid ISBN. Enter 10 digits (ISBN-10, last char may be X) or 13 digits (ISBN-13). Hyphens and spaces are allowed.';
        }
    }
    if (!$error) {
        try {
            // Verificar ISBN único
            if ($isbn !== '') {
                $chk = $db->prepare("SELECT id_libro FROM libros WHERE isbn = ? LIMIT 1");
                $chk->execute([$isbn]);
                if ($chk->fetch()) {
                    $error = 'Ya existe un libro con ese ISBN en el catálogo.';
                }
            }

            if (!$error) {
                // FK editorial y categoría (find or create)
                $idEditorial = findOrCreateEditorial($db, $editorial);
                $idCategoria = findOrCreateCategoria($db, $categoria);

                // Insertar libro
                $stmtLibro = $db->prepare("
                    INSERT INTO libros
                        (titulo, autor, isbn, anio_publicacion, descripcion,
                         precio_mxn, imagen_url, id_editorial, id_categoria)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtLibro->execute([
                    $titulo,
                    $autor,
                    $isbn   ?: null,
                    $anio   ?: null,
                    $descripcion ?: null,
                    $precio > 0 ? $precio : null,
                    $imagen ?: null,
                    $idEditorial,
                    $idCategoria,
                ]);
                $libroId = (int) $db->lastInsertId();

                // Crear N ejemplares
                crearEjemplares($db, $libroId, $unidades, $biblioteca, $ubicacion, $precio);

                // Generar ficha bibliográfica automáticamente
                $fichaData = [
                    'autor'           => $autor,
                    'titulo'          => $titulo,
                    'editorial_nombre'=> $editorial,
                    'anio_publicacion'=> $anio ?: '',
                    'isbn'            => $isbn,
                    'categoria_nombre'=> $categoria,
                ];
                $ficha = generarFichaBibliografica($fichaData);
                $db->prepare("UPDATE libros SET ficha_bibliografica = ? WHERE id_libro = ?")
                   ->execute([$ficha, $libroId]);

                logAction($db, $me['id'], 'crear', 'libros', $libroId, [
                    'titulo'    => $titulo,
                    'autor'     => $autor,
                    'unidades'  => $unidades,
                    'biblioteca'=> $biblioteca,
                ], 'catalogo');

                header('Location: catalogSettings.php?success=book_registered');
                exit;
            }
        } catch (PDOException $e) {
            $error = 'Error al registrar el libro: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register Book - Universidad Ducky</title>
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

    <main class="form-page-container">

        <div class="form-header-area">
            <a href="catalogSettings.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Go Back
            </a>
            <h1>Establish New Book</h1>
            <p>Register a new book for the library — copies will be created automatically.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <div class="form-card">
            <form method="POST" action="" enctype="multipart/form-data">

                <!-- Basic Information -->
                <div class="form-section">
                    <h3 class="section-title">Basic Information</h3>

                    <div class="input-grid full-width">
                        <div class="input-group">
                            <label for="bookTitle">Book's Title <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="bookTitle" name="bookTitle" class="base-input"
                                   placeholder="e.g. Introduction to Algorithms"
                                   value="<?= e($old['bookTitle'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="bookAuthor">Author <span style="color:#ef4444;">*</span></label>
                            <input type="text" id="bookAuthor" name="bookAuthor" class="base-input"
                                   placeholder="e.g. Thomas H. Cormen"
                                   value="<?= e($old['bookAuthor'] ?? '') ?>">
                        </div>
                        <div class="input-group">
                            <label for="bookEditorial">Publisher / Editorial</label>
                            <input type="text" id="bookEditorial" name="bookEditorial" class="base-input"
                                   placeholder="e.g. MIT Press"
                                   value="<?= e($old['bookEditorial'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="input-grid 2-cols">
                        <div class="input-group">
                            <label for="bookISBN">ISBN</label>
                            <input type="text" id="bookISBN" name="bookISBN" class="base-input"
                                   placeholder="e.g. 978-0262033848"
                                   maxlength="20"
                                   value="<?= e($old['bookISBN'] ?? '') ?>">
                            <small style="font-size:11px;color:#6b7280;margin-top:4px;display:block;">
                                ISBN-10 (10 digits) or ISBN-13 (13 digits). Hyphens are accepted.
                            </small>
                        </div>
                        <div class="input-group">
                            <label for="bookGenre">Genre / Category</label>
                            <input type="text" id="bookGenre" name="bookGenre" class="base-input"
                                   placeholder="e.g. Computer Science"
                                   value="<?= e($old['bookGenre'] ?? '') ?>">
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
                                       min="1800" max="<?= date('Y') ?>"
                                       placeholder="<?= date('Y') ?>"
                                       value="<?= e($old['bookYear'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <label for="bookPrice">Unit Price (MXN)</label>
                                <input type="text" id="bookPrice" name="bookPrice" class="base-input"
                                       placeholder="$349.99"
                                       value="<?= e($old['bookPrice'] ?? '') ?>">
                            </div>
                            <div class="input-group">
                                <label>Cover Image <span style="font-weight:400;color:#6b7280;">(optional)</span></label>
                                <div style="display:flex;flex-direction:column;gap:8px;">
                                    <label style="font-size:12px;font-weight:600;color:#374151;display:flex;align-items:center;gap:6px;
                                                  border:2px dashed #e2e8f0;border-radius:8px;padding:10px 14px;cursor:pointer;background:#f8fafc;"
                                           for="bookImageFile">
                                        <i class="fa-solid fa-upload" style="color:#0f3524;"></i>
                                        Upload file (JPEG / PNG / WebP, max 2 MB)
                                        <input type="file" id="bookImageFile" name="bookImageFile"
                                               accept="image/jpeg,image/png,image/webp,image/gif"
                                               style="display:none;">
                                    </label>
                                    <div style="font-size:11px;color:#94a3b8;text-align:center;">— or paste a URL —</div>
                                    <input type="url" id="bookImageUrl" name="bookImageUrl" class="base-input"
                                           placeholder="https://example.com/cover.jpg"
                                           value="<?= e($old['bookImageUrl'] ?? '') ?>">
                                    <small style="font-size:11px;color:#6b7280;">Uploaded file takes precedence over URL.</small>
                                </div>
                            </div>
                        </div>

                        <div class="details-right">
                            <div class="input-group h-100">
                                <label for="bookDescription">Description</label>
                                <textarea id="bookDescription" name="bookDescription"
                                          class="base-input textarea-input"
                                          placeholder="Brief description of the book..."><?= e($old['bookDescription'] ?? '') ?></textarea>
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
                                    <option value="Estoa" <?= ($old['locLibrary'] ?? '') === 'Estoa' ? 'selected' : '' ?>>Estoa</option>
                                    <option value="CCU"   <?= ($old['locLibrary'] ?? '') === 'CCU'   ? 'selected' : '' ?>>CCU</option>
                                </select>
                                <i class="fa-solid fa-chevron-down select-icon"></i>
                            </div>
                        </div>

                        <div class="input-group">
                            <label for="locUnits">Number of Copies</label>
                            <div class="select-wrapper">
                                <select id="locUnits" name="locUnits" class="base-input select-input">
                                    <?php for ($i = 1; $i <= 20; $i++): ?>
                                        <option value="<?= $i ?>" <?= (int)($old['locUnits'] ?? 1) === $i ? 'selected' : '' ?>>
                                            <?= $i ?>
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
                                        <option value="<?= $z ?>" <?= ($old['locZone'] ?? '') === $z ? 'selected' : '' ?>><?= $z ?></option>
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
                                        <option value="<?= $s ?>" <?= ($old['locShelf'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <i class="fa-solid fa-chevron-down select-icon"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="catalogSettings.php" class="btn-cancel">Cancel</a>
                    <button type="submit" class="btn-create">Register Book</button>
                </div>

            </form>
        </div>

        <p class="footer-note">
            La ficha bibliográfica se generará automáticamente al registrar el libro.
        </p>

    </main>

</body>
</html>
