<?php
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

$db = getDB();
$me = currentUser();

$prestamoId = (int) ($_GET['id'] ?? 0);
$nuevo      = isset($_GET['nuevo']);

if ($prestamoId <= 0) {
    header('Location: transactions.php');
    exit;
}

$stmt = $db->prepare("
    SELECT
        p.*,
        u.nombre_completo, u.email, u.tipo AS usuario_tipo, u.matricula_empleado,
        e.codigo_inventario, e.biblioteca, e.ubicacion_pasillo_estante,
        l.titulo, l.autor, l.isbn, l.id_libro,
        au.nombre_completo AS autorizado_nombre,
        m.monto_total AS multa_monto, m.dias_retraso, m.estado_pago AS multa_pagada
    FROM  prestamos p
    JOIN  usuarios u   ON p.id_usuario   = u.id_usuario
    JOIN  ejemplares e ON p.id_ejemplar  = e.id_ejemplar
    JOIN  libros l     ON e.id_libro     = l.id_libro
    LEFT JOIN usuarios au ON p.autorizado_por = au.id_usuario
    LEFT JOIN multas m    ON m.id_prestamo    = p.id_prestamo
    WHERE p.id_prestamo = ?
");
$stmt->execute([$prestamoId]);
$p = $stmt->fetch();

if (!$p) {
    header('Location: transactions.php');
    exit;
}

// Solo el propio usuario o staff puede ver el recibo
$canView = in_array($me['tipo'], ['administrador', 'bibliotecario'])
        || (int)$me['id'] === (int)$p['id_usuario'];
if (!$canView) {
    header('Location: dashboard.php');
    exit;
}

$multaInfo = calcularMultaInfo($p['fecha_vencimiento']);
$esActivo  = $p['estado'] === 'activo' || $p['estado'] === 'vencido';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Receipt <?= e($p['folio_recibo']) ?> — Universidad Ducky</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:'Inter',sans-serif; background:#f1f5f9; color:#1e293b; min-height:100vh; padding:32px 20px; }

        .action-bar {
            max-width:700px; margin:0 auto 24px;
            display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;
        }
        .btn-back {
            display:inline-flex; align-items:center; gap:8px;
            padding:10px 18px; background:#fff; border:1px solid #e2e8f0;
            border-radius:8px; color:#374151; text-decoration:none; font-weight:600; font-size:14px;
        }
        .btn-back:hover { background:#f8fafc; }
        .btn-print {
            display:inline-flex; align-items:center; gap:8px;
            padding:10px 20px; background:#0f3524; color:#fff;
            border:none; border-radius:8px; font-weight:600; font-size:14px; cursor:pointer;
        }
        .btn-print:hover { background:#1a5c3a; }
        <?php if ($esActivo && in_array($me['tipo'], ['administrador','bibliotecario'])): ?>
        .btn-return {
            display:inline-flex; align-items:center; gap:8px;
            padding:10px 18px; background:#eff6ff; color:#1d4ed8;
            border:1px solid #bfdbfe; border-radius:8px;
            font-weight:600; font-size:14px; text-decoration:none;
        }
        .btn-return:hover { background:#dbeafe; }
        <?php endif; ?>

        /* Receipt card */
        .receipt-wrapper { max-width:700px; margin:0 auto; }
        .receipt {
            background:#fff; border:2px solid #e2e8f0;
            border-radius:16px; overflow:hidden;
            box-shadow:0 4px 20px rgba(0,0,0,.08);
        }

        /* Header */
        .receipt-header {
            background:#0f3524; color:#fff;
            padding:24px 32px;
        }
        .receipt-header .inst { font-size:20px; font-weight:700; letter-spacing:.3px; margin-bottom:4px; }
        .receipt-header .sub  { font-size:13px; opacity:.75; }
        .receipt-header .folio-row {
            margin-top:16px; display:flex; align-items:center; justify-content:space-between;
            background:rgba(255,255,255,.12); border-radius:8px; padding:10px 16px;
        }
        .receipt-header .folio-label { font-size:11px; text-transform:uppercase; letter-spacing:1px; opacity:.8; }
        .receipt-header .folio-value { font-family:'Courier New',monospace; font-size:18px; font-weight:700; }

        /* Status banner */
        .status-banner {
            padding:10px 32px; font-size:13px; font-weight:700;
            display:flex; align-items:center; gap:8px;
        }
        .status-active   { background:#dcfce7; color:#15803d; }
        .status-overdue  { background:#fef9c3; color:#92400e; }
        .status-returned { background:#f1f5f9; color:#475569; }
        .status-lost     { background:#fee2e2; color:#991b1b; }

        /* Body sections */
        .receipt-body { padding:28px 32px; }

        .section-label {
            font-size:10px; font-weight:700; letter-spacing:1.2px;
            text-transform:uppercase; color:#94a3b8; margin-bottom:12px;
            padding-bottom:8px; border-bottom:1px solid #f1f5f9;
        }

        .info-row { display:flex; justify-content:space-between; align-items:baseline;
                    padding:7px 0; border-bottom:1px dotted #f1f5f9; font-size:14px; }
        .info-row:last-child { border-bottom:none; }
        .info-row .lbl { color:#6b7280; }
        .info-row .val { font-weight:600; text-align:right; }

        .two-col { display:grid; grid-template-columns:1fr 1fr; gap:24px; }

        /* Fine notice */
        .fine-box {
            margin:20px 0; padding:16px 20px; border-radius:10px;
            display:flex; align-items:flex-start; gap:14px;
        }
        .fine-box-warn    { background:#fffbeb; border:1px solid #fde68a; }
        .fine-box-paid    { background:#f0fdf4; border:1px solid #bbf7d0; }
        .fine-box .fi-icon{ font-size:24px; flex-shrink:0; margin-top:2px; }
        .fine-box-warn .fi-icon { color:#b45309; }
        .fine-box-paid .fi-icon { color:#16a34a; }
        .fine-box .fi-title { font-weight:700; font-size:14px; margin-bottom:4px; }
        .fine-box-warn .fi-title { color:#92400e; }
        .fine-box-paid .fi-title { color:#15803d; }
        .fine-box .fi-sub { font-size:13px; color:#6b7280; }

        /* Signatures */
        .sig-grid { display:grid; grid-template-columns:1fr 1fr; gap:40px; margin-top:32px; }
        .sig-line { border-top:1px solid #64748b; padding-top:8px;
                    font-size:11px; color:#94a3b8; text-align:center; }

        /* Footer */
        .receipt-footer {
            background:#f8fafc; border-top:1px solid #e2e8f0;
            padding:12px 32px; font-size:11px; color:#94a3b8;
            display:flex; justify-content:space-between; flex-wrap:wrap; gap:8px;
        }

        /* Success flash */
        .new-banner {
            max-width:700px; margin:0 auto 20px;
            background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;
            padding:14px 20px; display:flex; align-items:center; gap:12px;
            font-size:14px; font-weight:600; color:#15803d;
        }

        @media print {
            body { background:#fff; padding:0; }
            .action-bar, .new-banner, .receipt-footer { display:none; }
            .receipt { border:1px solid #000; border-radius:0; box-shadow:none; }
            .receipt-header { background:#000 !important; -webkit-print-color-adjust:exact; }
        }
    </style>
</head>
<body>

    <!-- Barra de acciones (solo pantalla) -->
    <div class="action-bar">
        <a href="transactions.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> All Loans
        </a>
        <div style="display:flex;gap:10px;">
            <?php if ($esActivo && in_array($me['tipo'], ['administrador','bibliotecario'])): ?>
                <?php if ($p['renovaciones_conteo'] < 2): ?>
                    <a href="renovarPrestamo.php?id=<?= $prestamoId ?>" class="btn-return" style="background:#fffbeb;color:#b45309;border-color:#fde68a;">
                        <i class="fa-solid fa-rotate-right"></i> Renew
                    </a>
                <?php endif; ?>
                <a href="devolucion.php?prestamo_id=<?= $prestamoId ?>" class="btn-return">
                    <i class="fa-solid fa-rotate-left"></i> Process Return
                </a>
            <?php endif; ?>
            <button class="btn-print" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print
            </button>
        </div>
    </div>

    <?php if ($nuevo): ?>
    <div class="new-banner">
        <i class="fa-solid fa-circle-check" style="font-size:20px;"></i>
        Loan registered successfully! Folio: <strong><?= e($p['folio_recibo']) ?></strong>
    </div>
    <?php endif; ?>

    <div class="receipt-wrapper">
        <div class="receipt">

            <!-- Header -->
            <div class="receipt-header">
                <div class="inst"><i class="fa-solid fa-book-open-reader" style="margin-right:8px;"></i>Universidad Ducky</div>
                <div class="sub">Library Management System — Loan Receipt</div>
                <div class="folio-row">
                    <div>
                        <div class="folio-label">Folio</div>
                        <div class="folio-value"><?= e($p['folio_recibo']) ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="folio-label">Issue Date</div>
                        <div style="font-size:14px;font-weight:600;"><?= date('d M Y, H:i', strtotime($p['fecha_salida'])) ?></div>
                    </div>
                </div>
            </div>

            <!-- Status -->
            <?php
            $statusClass = match($p['estado']) {
                'activo'   => 'status-active',
                'vencido'  => 'status-overdue',
                'devuelto' => 'status-returned',
                'perdido'  => 'status-lost',
                default    => 'status-active',
            };
            $statusIcon = match($p['estado']) {
                'activo'   => 'fa-circle-check',
                'vencido'  => 'fa-triangle-exclamation',
                'devuelto' => 'fa-rotate-left',
                'perdido'  => 'fa-circle-xmark',
                default    => 'fa-circle-check',
            };
            ?>
            <div class="status-banner <?= $statusClass ?>">
                <i class="fa-solid <?= $statusIcon ?>"></i>
                <?= estadoPrestamoLabel($p['estado']) ?>
                <?php if ($p['estado'] === 'vencido'): ?>
                    — <?= $multaInfo['dias'] ?> day<?= $multaInfo['dias'] !== 1 ? 's' : '' ?> overdue
                <?php elseif ($p['estado'] === 'devuelto' && $p['fecha_devolucion']): ?>
                    — Returned <?= date('d M Y', strtotime($p['fecha_devolucion'])) ?>
                <?php endif; ?>
            </div>

            <div class="receipt-body">

                <!-- Fine notice -->
                <?php if ($p['multa_monto'] !== null && (float)$p['multa_monto'] > 0): ?>
                    <div class="fine-box <?= $p['multa_pagada'] ? 'fine-box-paid' : 'fine-box-warn' ?>">
                        <div class="fi-icon">
                            <i class="fa-solid <?= $p['multa_pagada'] ? 'fa-circle-check' : 'fa-circle-dollar-sign' ?>"></i>
                        </div>
                        <div>
                            <div class="fi-title">
                                Fine <?= $p['multa_pagada'] ? 'Paid' : 'Pending' ?>:
                                $<?= number_format((float)$p['multa_monto'], 2) ?> MXN
                            </div>
                            <div class="fi-sub">
                                <?= (int)$p['dias_retraso'] ?> day<?= (int)$p['dias_retraso'] !== 1 ? 's' : '' ?> overdue ×
                                $10.00 MXN/day
                                <?= $p['multa_pagada'] ? '— Cleared.' : '— Payable at Tesorería.' ?>
                            </div>
                        </div>
                    </div>
                <?php elseif ($p['estado'] === 'vencido'): ?>
                    <div class="fine-box fine-box-warn">
                        <div class="fi-icon"><i class="fa-solid fa-clock"></i></div>
                        <div>
                            <div class="fi-title">Overdue — Fine accruing: $<?= number_format($multaInfo['monto'], 2) ?> MXN</div>
                            <div class="fi-sub"><?= $multaInfo['dias'] ?> day<?= $multaInfo['dias'] !== 1 ? 's' : '' ?> × $10.00 MXN/day. Fine will be recorded on return.</div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="two-col">
                    <!-- Book info -->
                    <div>
                        <div class="section-label">Book</div>
                        <div class="info-row"><span class="lbl">Title</span>       <span class="val"><?= e($p['titulo']) ?></span></div>
                        <div class="info-row"><span class="lbl">Author</span>      <span class="val"><?= e($p['autor'] ?? '—') ?></span></div>
                        <?php if ($p['isbn']): ?>
                        <div class="info-row"><span class="lbl">ISBN</span>        <span class="val"><?= e($p['isbn']) ?></span></div>
                        <?php endif; ?>
                        <div class="info-row"><span class="lbl">Copy ID</span>     <span class="val" style="font-family:'Courier New',monospace;"><?= e($p['codigo_inventario']) ?></span></div>
                        <div class="info-row"><span class="lbl">Library</span>     <span class="val"><?= e($p['biblioteca'] ?? '—') ?></span></div>
                    </div>

                    <!-- Borrower info -->
                    <div>
                        <div class="section-label">Borrower</div>
                        <div class="info-row"><span class="lbl">Name</span>        <span class="val"><?= e($p['nombre_completo']) ?></span></div>
                        <div class="info-row"><span class="lbl">Email</span>       <span class="val"><?= e($p['email']) ?></span></div>
                        <div class="info-row"><span class="lbl">Role</span>        <span class="val"><?= tipoLabel($p['usuario_tipo']) ?></span></div>
                        <?php if ($p['matricula_empleado']): ?>
                        <div class="info-row"><span class="lbl">ID#</span>         <span class="val"><?= e($p['matricula_empleado']) ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <hr style="border:none;border-top:1px solid #e2e8f0;margin:20px 0;">

                <!-- Loan dates -->
                <div class="section-label">Loan Details</div>
                <div class="two-col">
                    <div>
                        <div class="info-row"><span class="lbl">Type</span>            <span class="val" style="text-transform:capitalize;"><?= e($p['tipo']) ?></span></div>
                        <div class="info-row"><span class="lbl">Issue Date</span>       <span class="val"><?= date('d M Y', strtotime($p['fecha_salida'])) ?></span></div>
                        <div class="info-row"><span class="lbl">Due Date</span>
                            <span class="val" style="color:<?= $p['estado'] === 'vencido' ? '#dc2626' : '#0f3524' ?>;">
                                <?= date('d M Y', strtotime($p['fecha_vencimiento'])) ?>
                            </span>
                        </div>
                        <?php if ($p['fecha_devolucion']): ?>
                        <div class="info-row"><span class="lbl">Returned</span>     <span class="val"><?= date('d M Y', strtotime($p['fecha_devolucion'])) ?></span></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="info-row"><span class="lbl">Renewals</span>         <span class="val"><?= (int)$p['renovaciones_conteo'] ?> / 2</span></div>
                        <div class="info-row"><span class="lbl">Authorized by</span>    <span class="val"><?= e($p['autorizado_nombre'] ?? '—') ?></span></div>
                        <?php if ($p['condicion_entrega']): ?>
                        <div class="info-row"><span class="lbl">Condition (out)</span> <span class="val"><?= e($p['condicion_entrega']) ?></span></div>
                        <?php endif; ?>
                        <?php if ($p['condicion_retorno']): ?>
                        <div class="info-row"><span class="lbl">Condition (in)</span>  <span class="val"><?= e($p['condicion_retorno']) ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Signatures (only on active loans for print) -->
                <?php if ($esActivo): ?>
                <div class="sig-grid">
                    <div class="sig-line">Librarian's signature</div>
                    <div class="sig-line">Borrower's signature</div>
                </div>
                <?php endif; ?>

            </div><!-- /receipt-body -->

            <div class="receipt-footer">
                <span>Universidad Ducky — Sistema Bibliotecario ISO 9001:2015</span>
                <span><?= e($p['folio_recibo']) ?> · Generated: <?= date('d/m/Y H:i') ?></span>
            </div>

        </div><!-- /receipt -->
    </div>

</body>
</html>
