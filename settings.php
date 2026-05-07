<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireRole(['administrador']);

$me  = currentUser();
$db  = getDB();

$exito = '';
$error = '';

// ── POST: guardar configuración ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campos = [
        'nombre_institucion',
        'slogan_institucion',
        'email_contacto',
        'bibliotecas_disponibles',
        'dias_prestamo_alumno',
        'dias_prestamo_profesor',
        'dias_prestamo_staff',
        'monto_multa_dia',
        'max_renovaciones',
        'costo_perdida_multiplicador',
    ];

    $numericos = [
        'dias_prestamo_alumno', 'dias_prestamo_profesor',
        'dias_prestamo_staff', 'max_renovaciones',
        'costo_perdida_multiplicador',
    ];
    $decimales = ['monto_multa_dia'];

    try {
        $db->beginTransaction();
        foreach ($campos as $clave) {
            $valor = trim($_POST[$clave] ?? '');

            if (in_array($clave, $numericos, true)) {
                $valor = (string) max(1, (int) $valor);
            } elseif (in_array($clave, $decimales, true)) {
                $valor = number_format(max(0.01, (float) str_replace(',', '.', $valor)), 2, '.', '');
            }

            setConfig($db, $clave, $valor);
        }

        logAction($db, $me['id'], 'actualizar', 'configuracion', 0, [
            'campos_actualizados' => $campos,
        ], 'configuracion');

        $db->commit();
        $exito = 'Settings saved successfully.';
    } catch (PDOException $ex) {
        $db->rollBack();
        $error = 'Error saving settings: ' . $ex->getMessage();
    }
}

// Cargar valores actuales
$cfg = [];
foreach ($db->query("SELECT clave, valor FROM configuracion") as $r) {
    $cfg[$r['clave']] = $r['valor'];
}
$g = fn(string $k, $d = '') => $cfg[$k] ?? $d;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Settings — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <style>
        .alert { display:flex; align-items:center; gap:10px; padding:13px 16px;
                 border-radius:8px; margin-bottom:20px; font-size:14px; font-weight:500; }
        .alert-ok    { background:#f0fdf4; color:#16a34a; border:1px solid #bbf7d0; }
        .alert-error { background:#fef2f2; color:#dc2626; border:1px solid #fecaca; }

        .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media(max-width:640px){ .settings-grid { grid-template-columns:1fr; } }

        .setting-hint { font-size:12px; color:#94a3b8; margin-top:4px; }

        .section-icon { width:36px; height:36px; border-radius:8px; background:#ecfdf5;
                        display:inline-flex; align-items:center; justify-content:center;
                        color:#0f3524; font-size:15px; margin-right:10px; vertical-align:middle; }

        .preview-pill {
            display:inline-flex; align-items:center; gap:6px;
            background:#f0fdf4; border:1px solid #bbf7d0; color:#15803d;
            padding:4px 12px; border-radius:20px; font-size:12px; font-weight:600;
            margin-left:8px;
        }

        .input-with-suffix { position:relative; }
        .input-with-suffix input { padding-right:60px; }
        .input-suffix {
            position:absolute; right:12px; top:50%; transform:translateY(-50%);
            font-size:12px; font-weight:600; color:#94a3b8; pointer-events:none;
        }
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
                <a href="settings.php" class="active">Settings</a>
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
            <a href="dashboard.php" class="back-link">
                <i class="fa-solid fa-arrow-left"></i> Dashboard
            </a>
            <h1>System Settings</h1>
            <p>Configure loan policies, fine amounts, and institution information.</p>
        </div>

        <?php if ($exito): ?>
            <div class="alert alert-ok">
                <i class="fa-solid fa-circle-check"></i> <?= e($exito) ?>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i> <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="settings.php">

            <!-- ── Institución ──────────────────────────────────────────────── -->
            <div class="form-card" style="margin-bottom:20px;">
                <div class="form-section">
                    <h3 class="section-title">
                        <span class="section-icon"><i class="fa-solid fa-university"></i></span>
                        Institution
                    </h3>
                    <div class="settings-grid">
                        <div class="input-group">
                            <label for="nombre_institucion">Institution Name</label>
                            <input type="text" id="nombre_institucion" name="nombre_institucion"
                                   class="base-input" value="<?= e($g('nombre_institucion','Universidad Ducky')) ?>">
                        </div>
                        <div class="input-group">
                            <label for="email_contacto">Contact Email</label>
                            <input type="email" id="email_contacto" name="email_contacto"
                                   class="base-input" value="<?= e($g('email_contacto')) ?>">
                        </div>
                        <div class="input-group" style="grid-column:span 2;">
                            <label for="slogan_institucion">Tagline / Slogan</label>
                            <input type="text" id="slogan_institucion" name="slogan_institucion"
                                   class="base-input" value="<?= e($g('slogan_institucion')) ?>">
                        </div>
                        <div class="input-group" style="grid-column:span 2;">
                            <label for="bibliotecas_disponibles">Available Libraries</label>
                            <input type="text" id="bibliotecas_disponibles" name="bibliotecas_disponibles"
                                   class="base-input" value="<?= e($g('bibliotecas_disponibles','Estoa,CCU')) ?>">
                            <p class="setting-hint">Comma-separated names, e.g. <code>Estoa,CCU,Anexo</code>. Used in registration forms.</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Política de préstamos ────────────────────────────────────── -->
            <div class="form-card" style="margin-bottom:20px;">
                <div class="form-section">
                    <h3 class="section-title">
                        <span class="section-icon"><i class="fa-solid fa-book-bookmark"></i></span>
                        Loan Policy
                    </h3>
                    <div class="settings-grid">
                        <div class="input-group">
                            <label for="dias_prestamo_alumno">
                                Loan Days — Student
                                <span class="preview-pill" id="prev_alumno"><?= (int)$g('dias_prestamo_alumno',7) ?> days</span>
                            </label>
                            <div class="input-with-suffix">
                                <input type="number" id="dias_prestamo_alumno" name="dias_prestamo_alumno"
                                       class="base-input" min="1" max="60"
                                       value="<?= (int)$g('dias_prestamo_alumno',7) ?>"
                                       oninput="document.getElementById('prev_alumno').textContent=this.value+' days'">
                                <span class="input-suffix">days</span>
                            </div>
                        </div>
                        <div class="input-group">
                            <label for="dias_prestamo_profesor">
                                Loan Days — Professor
                                <span class="preview-pill" id="prev_prof"><?= (int)$g('dias_prestamo_profesor',14) ?> days</span>
                            </label>
                            <div class="input-with-suffix">
                                <input type="number" id="dias_prestamo_profesor" name="dias_prestamo_profesor"
                                       class="base-input" min="1" max="60"
                                       value="<?= (int)$g('dias_prestamo_profesor',14) ?>"
                                       oninput="document.getElementById('prev_prof').textContent=this.value+' days'">
                                <span class="input-suffix">days</span>
                            </div>
                        </div>
                        <div class="input-group">
                            <label for="dias_prestamo_staff">
                                Loan Days — Staff / Admin
                                <span class="preview-pill" id="prev_staff"><?= (int)$g('dias_prestamo_staff',14) ?> days</span>
                            </label>
                            <div class="input-with-suffix">
                                <input type="number" id="dias_prestamo_staff" name="dias_prestamo_staff"
                                       class="base-input" min="1" max="60"
                                       value="<?= (int)$g('dias_prestamo_staff',14) ?>"
                                       oninput="document.getElementById('prev_staff').textContent=this.value+' days'">
                                <span class="input-suffix">days</span>
                            </div>
                        </div>
                        <div class="input-group">
                            <label for="max_renovaciones">
                                Max Renewals per Loan
                                <span class="preview-pill" id="prev_renov"><?= (int)$g('max_renovaciones',2) ?></span>
                            </label>
                            <div class="input-with-suffix">
                                <input type="number" id="max_renovaciones" name="max_renovaciones"
                                       class="base-input" min="0" max="10"
                                       value="<?= (int)$g('max_renovaciones',2) ?>"
                                       oninput="document.getElementById('prev_renov').textContent=this.value">
                                <span class="input-suffix">max</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Política de multas ───────────────────────────────────────── -->
            <div class="form-card" style="margin-bottom:28px;">
                <div class="form-section">
                    <h3 class="section-title">
                        <span class="section-icon"><i class="fa-solid fa-circle-dollar-sign"></i></span>
                        Fine Policy
                    </h3>
                    <div class="settings-grid">
                        <div class="input-group">
                            <label for="monto_multa_dia">
                                Fine per Overdue Day
                                <span class="preview-pill" id="prev_multa">$<?= number_format((float)$g('monto_multa_dia',10),2) ?> MXN</span>
                            </label>
                            <div class="input-with-suffix">
                                <input type="number" id="monto_multa_dia" name="monto_multa_dia"
                                       class="base-input" min="0.01" step="0.50"
                                       value="<?= number_format((float)$g('monto_multa_dia',10),2,'.','') ?>"
                                       oninput="document.getElementById('prev_multa').textContent='$'+parseFloat(this.value||0).toFixed(2)+' MXN'">
                                <span class="input-suffix">MXN</span>
                            </div>
                            <p class="setting-hint">Applied per calendar day after the due date.</p>
                        </div>
                        <div class="input-group">
                            <label for="costo_perdida_multiplicador">
                                Lost Book Cost Multiplier
                                <span class="preview-pill" id="prev_perdida"><?= (int)$g('costo_perdida_multiplicador',20) ?>×</span>
                            </label>
                            <div class="input-with-suffix">
                                <input type="number" id="costo_perdida_multiplicador" name="costo_perdida_multiplicador"
                                       class="base-input" min="1" max="100"
                                       value="<?= (int)$g('costo_perdida_multiplicador',20) ?>"
                                       oninput="document.getElementById('prev_perdida').textContent=this.value+'×'">
                                <span class="input-suffix">× cost</span>
                            </div>
                            <p class="setting-hint">Multiplied by the book's purchase price (USD) to estimate replacement fine. Default: 20×.</p>
                        </div>
                        <div class="input-group" style="grid-column:span 2;">
                            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:14px 16px;font-size:13px;color:#92400e;">
                                <i class="fa-solid fa-triangle-exclamation" style="margin-right:6px;"></i>
                                <strong>Changing the fine rate</strong> only affects <em>new</em> fines generated from this point forward.
                                Existing unpaid fines keep their original amount.
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <a href="dashboard.php" class="btn-cancel">Discard Changes</a>
                <button type="submit" class="btn-create">
                    <i class="fa-solid fa-floppy-disk" style="margin-right:8px;"></i>
                    Save Settings
                </button>
            </div>

        </form>

    </main>

</body>
</html>
