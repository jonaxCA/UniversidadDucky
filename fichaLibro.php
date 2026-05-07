<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$db = getDB();

$libroId = (int) ($_GET['id'] ?? 0);
if ($libroId <= 0) {
    header('Location: catalogSettings.php');
    exit;
}

$stmt = $db->prepare("
    SELECT
        l.*,
        e.nombre  AS editorial_nombre,
        c.nombre  AS categoria_nombre,
        COALESCE(s.total,       0) AS total_copias,
        COALESCE(s.disponibles, 0) AS copias_disponibles,
        s.biblioteca,
        s.ubicacion
    FROM libros l
    LEFT JOIN editoriales e ON l.id_editorial = e.id_editorial
    LEFT JOIN categorias  c ON l.id_categoria = c.id_categoria
    LEFT JOIN (
        SELECT id_libro,
               COUNT(*) AS total,
               SUM(disponible = 'disponible') AS disponibles,
               MIN(biblioteca) AS biblioteca,
               MIN(ubicacion_pasillo_estante) AS ubicacion
        FROM   ejemplares
        WHERE  disponible != 'obsoleto'
        GROUP  BY id_libro
    ) s ON l.id_libro = s.id_libro
    WHERE l.id_libro = ?
");
$stmt->execute([$libroId]);
$libro = $stmt->fetch();

if (!$libro) {
    header('Location: catalogSettings.php');
    exit;
}

// Si la ficha no se generó aún, generarla ahora y guardar
if (empty($libro['ficha_bibliografica'])) {
    $ficha = generarFichaBibliografica($libro);
    $db->prepare("UPDATE libros SET ficha_bibliografica = ? WHERE id_libro = ?")
       ->execute([$ficha, $libroId]);
    $libro['ficha_bibliografica'] = $ficha;
}

// Datos para la tarjeta
$partes    = array_map('trim', explode(' - ', $libro['ubicacion'] ?? ''));
$ubicTexto = implode(' — ', array_filter([$libro['biblioteca'] ?? '', implode(', ', array_slice($partes, 1))]));

// Apellido, Nombre del autor (formato bibliográfico)
$palabras  = preg_split('/\s+/', trim($libro['autor'] ?? ''));
$apellido  = count($palabras) > 1 ? strtoupper(array_pop($palabras)) . ', ' . implode(' ', $palabras) : strtoupper($libro['autor'] ?? '');

// Número topográfico: clasificación Dewey + código Cutter + año
$dewey      = deweyCode($libro['categoria_nombre'] ?? '', $libro['titulo']);
$cutter     = cutterCode($libro['autor'] ?? '');
$anio2      = $libro['anio_publicacion'] ? substr((string)$libro['anio_publicacion'], -2) : '';
$callNumber = $dewey . ' / ' . $cutter . ($anio2 ? " {$anio2}" : '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha Bibliográfica — <?= e($libro['titulo']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            color: #1e293b;
            min-height: 100vh;
            padding: 32px 20px;
        }

        /* ── Barra de acciones (solo pantalla) ─────────────────────────────── */
        .action-bar {
            max-width: 760px;
            margin: 0 auto 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 18px; background: #fff;
            border: 1px solid #e2e8f0; border-radius: 8px;
            color: #374151; text-decoration: none; font-weight: 600; font-size: 14px;
        }
        .btn-back:hover { background: #f8fafc; }
        .btn-print {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 10px 20px; background: #0f3524; color: #fff;
            border: none; border-radius: 8px;
            font-weight: 600; font-size: 14px; cursor: pointer; transition: .2s;
        }
        .btn-print:hover { background: #1a5c3a; }

        /* ── Tarjeta bibliográfica ────────────────────────────────────────── */
        .card-wrapper {
            max-width: 760px;
            margin: 0 auto;
        }

        .ficha-card {
            background: #fff;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,.08);
        }

        /* Header */
        .ficha-header {
            background: #0f3524;
            color: #fff;
            padding: 20px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }
        .ficha-header h2 { font-size: 16px; font-weight: 700; letter-spacing: .5px; }
        .ficha-header .ficha-isbn {
            font-family: 'Courier New', monospace;
            font-size: 13px;
            background: rgba(255,255,255,.15);
            padding: 4px 12px;
            border-radius: 20px;
            white-space: nowrap;
        }

        /* Cuerpo */
        .ficha-body { padding: 28px; }

        /* Texto principal de la ficha */
        .ficha-texto {
            font-family: 'Courier New', Courier, monospace;
            font-size: 14px;
            line-height: 1.8;
            color: #1e293b;
            white-space: pre-wrap;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 20px 24px;
            margin-bottom: 24px;
        }

        /* Grid de datos ─────────────── */
        .datos-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .dato-item { }
        .dato-label {
            font-size: 10px; font-weight: 700; letter-spacing: 1px;
            text-transform: uppercase; color: #94a3b8; margin-bottom: 4px;
        }
        .dato-value { font-size: 14px; font-weight: 600; color: #1e293b; }

        /* Separador */
        hr.ficha-sep { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }

        /* Stats de inventario */
        .inventario-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .inv-stat {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }
        .inv-stat .num  { font-size: 28px; font-weight: 700; color: #0f3524; line-height: 1; }
        .inv-stat .lbl  { font-size: 11px; color: #6b7280; margin-top: 6px; text-transform: uppercase; letter-spacing: .5px; }

        /* Footer de la tarjeta */
        .ficha-footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 14px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            color: #6b7280;
            flex-wrap: wrap;
            gap: 8px;
        }
        .ficha-footer strong { color: #374151; }

        /* Ubicación badge */
        .ubicacion-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: #ecfdf5; color: #065f46;
            border: 1px solid #a7f3d0;
            padding: 6px 14px; border-radius: 20px;
            font-size: 13px; font-weight: 600;
        }

        /* ── Print ─────────────────────────────────────────────────────────── */
        @media print {
            body { background: #fff; padding: 0; }
            .action-bar, .ficha-footer { display: none; }
            .ficha-card {
                border: 1px solid #000;
                border-radius: 0;
                box-shadow: none;
                max-width: 100%;
            }
            .ficha-header { background: #000 !important; -webkit-print-color-adjust: exact; }
            .inv-stat { border: 1px solid #ccc; }
        }
    </style>
</head>
<body>

    <!-- Barra de acciones -->
    <div class="action-bar">
        <a href="bookInformation.php?id=<?= $libroId ?>" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Volver al libro
        </a>
        <button class="btn-print" onclick="window.print()">
            <i class="fa-solid fa-print"></i> Imprimir Ficha
        </button>
    </div>

    <!-- Tarjeta principal -->
    <div class="card-wrapper">
        <div class="ficha-card">

            <div class="ficha-header">
                <h2><i class="fa-solid fa-paste" style="margin-right:8px;"></i>Ficha Bibliográfica</h2>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                    <?php if ($libro['isbn']): ?>
                        <span class="ficha-isbn">ISBN: <?= e($libro['isbn']) ?></span>
                    <?php endif; ?>
                    <span style="font-family:'Courier New',monospace;font-size:12px;
                                 background:rgba(255,255,255,.15);padding:3px 12px;
                                 border-radius:12px;letter-spacing:.5px;"
                          title="Número topográfico: Dewey / Cutter año">
                        <?= e($callNumber) ?>
                    </span>
                </div>
            </div>

            <div class="ficha-body">

                <!-- Texto formal de la ficha -->
                <div class="ficha-texto"><?= e($libro['ficha_bibliografica']) ?></div>

                <!-- Grid de datos del libro -->
                <div class="datos-grid">
                    <!-- Número topográfico — clasificación estándar para estantería -->
                    <div class="dato-item" style="grid-column:span 2;">
                        <div class="dato-label">Número topográfico (Clasificación)</div>
                        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin-top:4px;">
                            <div style="background:#ecfdf5;border:2px solid #a7f3d0;border-radius:8px;
                                        padding:8px 18px;font-family:'Courier New',monospace;line-height:1.3;
                                        text-align:center;">
                                <div style="font-size:20px;font-weight:700;color:#0f3524;"><?= e($dewey) ?></div>
                                <div style="font-size:15px;font-weight:700;color:#1e293b;">
                                    <?= e($cutter) ?><?= $anio2 ? " {$anio2}" : '' ?>
                                </div>
                            </div>
                            <div style="font-size:12px;color:#6b7280;line-height:1.7;">
                                <div><strong>Clasificación Dewey:</strong> <?= e($dewey) ?>
                                    <?php if ($dewey === '000'): ?>
                                        <span style="color:#b45309;"> — Generalidades (sin categoría asignada)</span>
                                    <?php endif; ?>
                                </div>
                                <div><strong>Código Cutter:</strong> <?= e($cutter) ?></div>
                                <div><strong>Número topográfico:</strong> <?= e($callNumber) ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="dato-item">
                        <div class="dato-label">Autor</div>
                        <div class="dato-value"><?= e($libro['autor'] ?? '—') ?></div>
                    </div>
                    <div class="dato-item">
                        <div class="dato-label">Título</div>
                        <div class="dato-value"><?= e($libro['titulo']) ?></div>
                    </div>
                    <?php if ($libro['editorial_nombre']): ?>
                    <div class="dato-item">
                        <div class="dato-label">Editorial</div>
                        <div class="dato-value"><?= e($libro['editorial_nombre']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($libro['anio_publicacion']): ?>
                    <div class="dato-item">
                        <div class="dato-label">Año</div>
                        <div class="dato-value"><?= e($libro['anio_publicacion']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($libro['categoria_nombre']): ?>
                    <div class="dato-item">
                        <div class="dato-label">Categoría</div>
                        <div class="dato-value"><?= e($libro['categoria_nombre']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($libro['precio_mxn']): ?>
                    <div class="dato-item">
                        <div class="dato-label">Precio de adquisición</div>
                        <div class="dato-value">$<?= number_format((float)$libro['precio_mxn'], 2) ?> MXN</div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Ubicación -->
                <?php if ($ubicTexto): ?>
                <div style="margin-bottom:20px;">
                    <div class="dato-label" style="margin-bottom:8px;">Ubicación en biblioteca</div>
                    <span class="ubicacion-badge">
                        <i class="fa-solid fa-location-dot"></i>
                        <?= e($ubicTexto) ?>
                    </span>
                </div>
                <?php endif; ?>

                <hr class="ficha-sep">

                <!-- Inventario -->
                <div class="inventario-grid">
                    <div class="inv-stat">
                        <div class="num"><?= (int)$libro['total_copias'] ?></div>
                        <div class="lbl">Total de ejemplares</div>
                    </div>
                    <div class="inv-stat">
                        <div class="num" style="color:#16a34a;"><?= (int)$libro['copias_disponibles'] ?></div>
                        <div class="lbl">Disponibles</div>
                    </div>
                    <div class="inv-stat">
                        <div class="num" style="color:#dc2626;"><?= (int)$libro['total_copias'] - (int)$libro['copias_disponibles'] ?></div>
                        <div class="lbl">En préstamo</div>
                    </div>
                </div>

            </div><!-- /ficha-body -->

            <div class="ficha-footer">
                <span>Universidad Ducky — Sistema Bibliotecario ISO 9001:2015</span>
                <strong>Generado: <?= date('d/m/Y H:i') ?></strong>
            </div>

        </div><!-- /ficha-card -->
    </div>

</body>
</html>
