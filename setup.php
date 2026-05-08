<?php
/**
 * setup.php — Inicialización del sistema (ejecutar UNA sola vez).
 * Accede a http://localhost/UniversidadDucky/setup.php
 * El archivo se bloquea automáticamente tras la primera ejecución exitosa.
 */

// ── Protección contra re-ejecución ───────────────────────────────────────────
$lockFile = __DIR__ . '/setup.lock';
if (file_exists($lockFile)) {
    http_response_code(403);
    $lockedOn = htmlspecialchars(trim(file_get_contents($lockFile)));
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">
          <title>Setup bloqueado — Universidad Ducky</title>
          <style>
            body{font-family:"Segoe UI",sans-serif;max-width:520px;margin:60px auto;padding:0 20px}
            .box{background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:28px;color:#991b1b}
            code{background:#fee2e2;padding:2px 6px;border-radius:4px;font-size:13px}
            a{color:#991b1b}
          </style></head><body>
          <div class="box">
            <h2>⚠️ Instalación ya completada</h2>
            <p>El sistema fue inicializado el <strong>' . $lockedOn . '</strong>.</p>
            <p>Para reinstalar desde cero, elimina <code>setup.lock</code> del servidor y vuelve a ejecutar este archivo.</p>
            <p><a href="index.php">← Ir al Login</a></p>
          </div></body></html>';
    exit;
}

// ── Credenciales de conexión (sin BD seleccionada aún) ──────────────────────
$host     = '127.0.0.1';
$port     = '3307';
$rootUser = 'root';
$rootPass = '';          // Cambia si tu root tiene contraseña

// ── Usuario administrador inicial ───────────────────────────────────────────
$adminEmail  = 'admin@ducky.edu';
$adminPass   = 'Admin1234!';
$adminNombre = 'Administrador Sistema';
$adminUser   = 'admin';

// ────────────────────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Permite personalizar credenciales desde el formulario.
    // root_pass: si el campo viene vacío, forzamos string vacío (XAMPP por defecto no tiene contraseña).
    $rootPass    = $_POST['root_pass'] !== '' ? $_POST['root_pass'] : '';
    $adminEmail  = trim($_POST['admin_email']  ?? $adminEmail);
    $adminPass   = $_POST['admin_pass']   ?? $adminPass;
    $adminNombre = trim($_POST['admin_nombre'] ?? $adminNombre);
}

$messages    = [];
$success     = false;
$includeSeed = !empty($_POST['include_seed']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // 1. Conectar a MariaDB sin seleccionar BD
        $pdo = new PDO(
            "mysql:host=$host;port=$port;charset=utf8mb4",
            $rootUser,
            $rootPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $messages[] = '✅ Conexión a MariaDB exitosa.';

        // 2. Ejecutar el esquema completo (crea BD, tablas, datos iniciales e índices)
        $schemaFile = __DIR__ . '/sql/universidad_ducky.sql';
        if (!file_exists($schemaFile)) {
            throw new RuntimeException("No se encontró sql/universidad_ducky.sql");
        }
        $sql = file_get_contents($schemaFile);
        // Eliminar comentarios de línea y dividir por ;
        $sql = preg_replace('/^--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            $pdo->exec($stmt);
        }
        $messages[] = '✅ Base de datos <code>universidad_ducky</code> y tablas creadas correctamente.';

        // 4. Insertar administrador inicial
        if (empty($adminEmail) || empty($adminPass)) {
            throw new RuntimeException("El email y contraseña del admin son obligatorios.");
        }
        $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO usuarios
                (nombre_completo, username, email, contrasena, tipo, estado)
            VALUES (?, ?, ?, ?, 'administrador', 'activo')
        ");
        $stmt->execute([$adminNombre, $adminUser, $adminEmail, $hash]);
        $messages[] = '✅ Usuario administrador creado.';

        // 5. Datos de prueba (seed) ────────────────────────────────────────────
        if ($includeSeed) {

            // ── Helpers: find-or-create editorial / categoría ─────────────────
            $fcEdit = function(string $nombre) use ($pdo): int {
                $s = $pdo->prepare("SELECT id_editorial FROM editoriales WHERE nombre = ? LIMIT 1");
                $s->execute([trim($nombre)]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) return (int)$row['id_editorial'];
                $pdo->prepare("INSERT INTO editoriales (nombre) VALUES (?)")->execute([trim($nombre)]);
                return (int)$pdo->lastInsertId();
            };

            $fcCat = function(string $nombre) use ($pdo): int {
                $s = $pdo->prepare("SELECT id_categoria FROM categorias WHERE nombre = ? LIMIT 1");
                $s->execute([trim($nombre)]);
                $row = $s->fetch(PDO::FETCH_ASSOC);
                if ($row) return (int)$row['id_categoria'];
                $pdo->prepare("INSERT INTO categorias (nombre) VALUES (?)")->execute([trim($nombre)]);
                return (int)$pdo->lastInsertId();
            };

            // ── 5a. Editoriales y Categorías base ─────────────────────────────
            foreach (['MIT Press', "O'Reilly Media", 'Pearson', 'McGraw-Hill',
                      'Fondo de Cultura Económica'] as $e) { $fcEdit($e); }

            foreach (['Ciencias de la Computación', 'Matemáticas', 'Física',
                      'Literatura', 'Administración'] as $c) { $fcCat($c); }

            // ── 5b. Libros de demo (8 títulos, 2 ejemplares c/u) ──────────────
            $demoBooks = [
                ['Introduction to Algorithms', 'Thomas H. Cormen',    '9780262033848', 2009, 1200.00, 'MIT Press',                   'Ciencias de la Computación'],
                ['Clean Code',                 'Robert C. Martin',    '9780132350884', 2008,  890.00, 'Pearson',                     'Ciencias de la Computación'],
                ['The Pragmatic Programmer',   'David Thomas',        '9780135957059', 2019,  950.00, "O'Reilly Media",              'Ciencias de la Computación'],
                ['Cálculo',                    'James Stewart',       '9786074818451', 2012,  780.00, 'McGraw-Hill',                 'Matemáticas'],
                ['Física Universitaria Vol. 1','Hugh D. Young',       '9786073218436', 2018,  850.00, 'Pearson',                     'Física'],
                ['Cien Años de Soledad',        'Gabriel García Márquez','9789500304139',1967, 280.00,'Fondo de Cultura Económica',  'Literatura'],
                ['El Principito',              'Antoine de Saint-Exupéry','9786071116789',1943,180.00,'Fondo de Cultura Económica',  'Literatura'],
                ['Fundamentos de Administración','Stephen P. Robbins','9786073228053', 2009,  620.00, 'Pearson',                     'Administración'],
            ];

            $insLibro    = $pdo->prepare("
                INSERT IGNORE INTO libros (titulo, autor, isbn, anio_publicacion, precio_mxn, id_editorial, id_categoria)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insEjemplar = $pdo->prepare("
                INSERT IGNORE INTO ejemplares
                    (id_libro, codigo_inventario, disponible, biblioteca, ubicacion_pasillo_estante, precio_compra_usd)
                VALUES (?, ?, 'disponible', 'Estoa', 'Estoa - Level 1 - A1', ?)
            ");
            $getLibByIsbn = $pdo->prepare("SELECT id_libro FROM libros WHERE isbn = ? LIMIT 1");

            foreach ($demoBooks as $b) {
                [$titulo, $autor, $isbn, $anio, $precio, $editorial, $categoria] = $b;
                $idEdit = $fcEdit($editorial);
                $idCat  = $fcCat($categoria);
                $insLibro->execute([$titulo, $autor, $isbn, $anio, $precio, $idEdit, $idCat]);
                $getLibByIsbn->execute([$isbn]);
                $libroId = (int)$getLibByIsbn->fetchColumn();
                if ($libroId) {
                    for ($c = 1; $c <= 2; $c++) {
                        $code = sprintf('EJ-%04d-%02d', $libroId, $c);
                        $insEjemplar->execute([$libroId, $code, $precio]);
                    }
                }
            }
            $messages[] = '✅ Seed: 8 libros de demo con 2 ejemplares cada uno.';

            // ── 5c. Usuarios demo (contraseña: Demo1234!) ─────────────────────
            $demoPass  = password_hash('Demo1234!', PASSWORD_BCRYPT, ['cost' => 12]);
            $demoUsers = [
                ['María López',          'bib1',  'biblioteca1@ducky.edu.mx', 'bibliotecario'],
                ['Carlos Ruiz',          'bib2',  'biblioteca2@ducky.edu.mx', 'bibliotecario'],
                ['Dr. Eduardo Méndez',   'prof1', 'prof1@ducky.edu.mx',       'profesor'],
                ['Dra. Ana Flores',      'prof2', 'prof2@ducky.edu.mx',       'profesor'],
                ['Pedro Gómez',          'alu1',  'alumno1@ducky.edu.mx',     'alumno'],
                ['Sofía Torres',         'alu2',  'alumno2@ducky.edu.mx',     'alumno'],
                ['Luis Hernández',       'alu3',  'alumno3@ducky.edu.mx',     'alumno'],
            ];
            $insUser = $pdo->prepare("
                INSERT IGNORE INTO usuarios (nombre_completo, username, email, contrasena, tipo, estado)
                VALUES (?, ?, ?, ?, ?, 'activo')
            ");
            foreach ($demoUsers as $u) {
                $insUser->execute([$u[0], $u[1], $u[2], $demoPass, $u[3]]);
            }
            $messages[] = '✅ Seed: 7 usuarios demo creados (contraseña: <code>Demo1234!</code>).';

            // ── 5d. Préstamos de demo ─────────────────────────────────────────
            $getUid = function(string $user) use ($pdo): int {
                $s = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE username = ? LIMIT 1");
                $s->execute([$user]);
                return (int)$s->fetchColumn();
            };
            $getLibId = function(string $isbn) use ($pdo): int {
                $s = $pdo->prepare("SELECT id_libro FROM libros WHERE isbn = ? LIMIT 1");
                $s->execute([$isbn]);
                return (int)$s->fetchColumn();
            };
            $getAvailEj = function(int $libroId) use ($pdo): int {
                $s = $pdo->prepare("SELECT id_ejemplar FROM ejemplares WHERE id_libro = ? AND disponible = 'disponible' LIMIT 1");
                $s->execute([$libroId]);
                return (int)$s->fetchColumn();
            };
            $setEjState = function(int $ejId, string $state) use ($pdo): void {
                $pdo->prepare("UPDATE ejemplares SET disponible = ? WHERE id_ejemplar = ?")
                    ->execute([$state, $ejId]);
            };
            $insLoan = $pdo->prepare("
                INSERT IGNORE INTO prestamos
                    (folio_recibo, id_usuario, id_ejemplar, fecha_salida, fecha_vencimiento, estado, tipo)
                VALUES (?, ?, ?, ?, ?, ?, 'externo')
            ");

            $loansMade = 0;
            $loanId3   = 0;

            // Loan 1: alumno activo — vence en 5 días
            $uid = $getUid('alu1');
            $lid = $getLibId('9780262033848');       // Introduction to Algorithms
            $eid = $getAvailEj($lid);
            if ($uid && $eid) {
                $insLoan->execute(['F-SEED-001', $uid, $eid, date('Y-m-d H:i:s', strtotime('-2 days')),
                    date('Y-m-d', strtotime('+5 days')), 'activo']);
                if ($insLoan->rowCount()) { $setEjState($eid, 'prestado'); $loansMade++; }
            }

            // Loan 2: profesor activo — vence en 10 días
            $uid = $getUid('prof1');
            $lid = $getLibId('9786074818451');       // Cálculo
            $eid = $getAvailEj($lid);
            if ($uid && $eid) {
                $insLoan->execute(['F-SEED-002', $uid, $eid, date('Y-m-d H:i:s', strtotime('-4 days')),
                    date('Y-m-d', strtotime('+10 days')), 'activo']);
                if ($insLoan->rowCount()) { $setEjState($eid, 'prestado'); $loansMade++; }
            }

            // Loan 3: alumno vencido — 3 días de retraso
            $uid = $getUid('alu2');
            $lid = $getLibId('9780132350884');       // Clean Code
            $eid = $getAvailEj($lid);
            if ($uid && $eid) {
                $insLoan->execute(['F-SEED-003', $uid, $eid, date('Y-m-d H:i:s', strtotime('-10 days')),
                    date('Y-m-d', strtotime('-3 days')), 'vencido']);
                if ($insLoan->rowCount()) {
                    $loanId3 = (int)$pdo->lastInsertId();
                    $setEjState($eid, 'prestado'); $loansMade++;
                }
            }

            // Loan 4: alumno — devuelto
            $uid = $getUid('alu3');
            $lid = $getLibId('9789500304139');       // Cien Años de Soledad
            $eid = $getAvailEj($lid);
            if ($uid && $eid) {
                $insLoan->execute(['F-SEED-004', $uid, $eid, date('Y-m-d H:i:s', strtotime('-15 days')),
                    date('Y-m-d', strtotime('-8 days')), 'devuelto']);
                if ($insLoan->rowCount()) { $loansMade++; }
            }

            if ($loansMade) {
                $messages[] = "✅ Seed: {$loansMade} préstamos de ejemplo (2 activos, 1 vencido, 1 devuelto).";
            }

            // ── 5e. Multa de demo sobre el préstamo vencido ───────────────────
            if ($loanId3) {
                $pdo->prepare("
                    INSERT IGNORE INTO multas
                        (id_prestamo, tipo_mora, dias_retraso, monto_total, estado_pago)
                    VALUES (?, 'retraso', 3, 30.00, 0)
                ")->execute([$loanId3]);
                $messages[] = '✅ Seed: multa pendiente de $30.00 MXN sobre el préstamo vencido.';
            }
        } // end seed

        $success = true;

        // Crear archivo de bloqueo para evitar re-ejecución
        file_put_contents($lockFile, date('Y-m-d H:i:s'));

    } catch (Throwable $e) {
        $messages[] = '❌ Error: ' . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — Universidad Ducky</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; max-width: 560px; margin: 60px auto; padding: 0 20px; }
        h1 { color: #1e293b; }
        .card { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 28px; }
        label { display: block; font-size: 14px; font-weight: 600; color: #374151; margin-bottom: 4px; }
        input[type=text], input[type=email], input[type=password] {
            width: 100%; padding: 10px 12px; border: 1px solid #d1d5db;
            border-radius: 8px; font-size: 14px; margin-bottom: 16px; box-sizing: border-box;
        }
        .seed-row {
            display: flex; align-items: flex-start; gap: 10px;
            background: #ecfdf5; border: 1px solid #a7f3d0;
            border-radius: 8px; padding: 14px 16px; margin-bottom: 20px;
        }
        .seed-row input[type=checkbox] { margin-top: 2px; width: 16px; height: 16px; flex-shrink: 0; }
        .seed-row .seed-text { font-size: 13px; color: #065f46; }
        .seed-row .seed-text strong { display: block; font-size: 14px; margin-bottom: 2px; }
        button { background: #0f3524; color: #fff; border: none; padding: 12px 24px;
                 border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; width: 100%; }
        button:hover { background: #1a5c3a; }
        .msg { padding: 10px 14px; border-radius: 8px; margin-bottom: 8px; font-size: 14px;
               background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .msg.error { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .warning { background: #fffbeb; color: #92400e; border: 1px solid #fde68a;
                   border-radius: 8px; padding: 12px 14px; font-size: 13px; margin-top: 20px; }
        a.btn-link { display: inline-block; margin-top: 16px; background: #16a34a; color: #fff;
                     padding: 11px 22px; border-radius: 8px; text-decoration: none; font-weight: 600; }
        hr { border: none; border-top: 1px solid #e5e7eb; margin: 24px 0; }
        code { background: #e0f2fe; color: #0369a1; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
    </style>
</head>
<body>
<h1>🦆 Universidad Ducky — Setup</h1>

<?php if ($success): ?>
    <div class="card">
        <?php foreach ($messages as $m): ?>
            <div class="msg"><?= $m ?></div>
        <?php endforeach; ?>
        <hr>
        <strong>Credenciales de acceso:</strong><br>
        Email: <code><?= htmlspecialchars($adminEmail) ?></code><br>
        Password: <code><?= htmlspecialchars($adminPass) ?></code>
        <?php if ($includeSeed): ?>
        <br><br>
        <strong>Usuarios demo (contraseña: <code>Demo1234!</code>):</strong><br>
        biblioteca1@ducky.edu.mx · biblioteca2@ducky.edu.mx (Bibliotecario)<br>
        prof1@ducky.edu.mx · prof2@ducky.edu.mx (Profesor)<br>
        alumno1@ducky.edu.mx · alumno2@ducky.edu.mx · alumno3@ducky.edu.mx (Alumno)
        <?php endif; ?>
        <br>
        <a class="btn-link" href="index.php">Ir al Login →</a>
        <div class="warning">
            🔒 <strong>setup.php</strong> ha sido bloqueado automáticamente y no puede volver a ejecutarse.<br><br>
            ⚠️ <strong>Cambia la contraseña</strong> del administrador en tu primer inicio de sesión desde <em>Mi Perfil</em>.<br><br>
            Para mayor seguridad en producción, elimina también <code>setup.php</code> del servidor.
        </div>
    </div>
<?php else: ?>
    <?php foreach ($messages as $m): ?>
        <div class="msg error"><?= $m ?></div>
    <?php endforeach; ?>

    <div class="card">
        <form method="POST">
            <h3 style="margin-top:0">Conexión a MariaDB</h3>
            <label>Contraseña de root (dejar vacío si no tiene)</label>
            <input type="password" name="root_pass" placeholder="(sin contraseña)">

            <hr>
            <h3>Administrador inicial</h3>
            <label>Nombre completo</label>
            <input type="text" name="admin_nombre" value="<?= htmlspecialchars($adminNombre) ?>">

            <label>Email</label>
            <input type="email" name="admin_email" value="<?= htmlspecialchars($adminEmail) ?>">

            <label>Contraseña</label>
            <input type="password" name="admin_pass" value="<?= htmlspecialchars($adminPass) ?>">

            <hr>
            <h3>Opciones de instalación</h3>
            <div class="seed-row">
                <input type="checkbox" name="include_seed" value="1" id="seedCheck"
                       <?= $includeSeed ? 'checked' : 'checked' ?>>
                <label for="seedCheck" class="seed-text" style="cursor:pointer;font-weight:400;">
                    <strong>Incluir datos de prueba (seed)</strong>
                    Inserta 8 libros de demo, 7 usuarios de ejemplo (bibliotecarios, profesores y alumnos),
                    4 préstamos de muestra y una multa pendiente. Ideal para explorar el sistema.
                    <br><small style="color:#047857;">Contraseña de todos los usuarios demo: <strong>Demo1234!</strong></small>
                </label>
            </div>

            <button type="submit">Inicializar sistema</button>
        </form>
    </div>
<?php endif; ?>
</body>
</html>
