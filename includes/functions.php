<?php
/** Escapa output para HTML (shorthand). */
function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

/** Etiqueta legible del tipo de usuario. */
function tipoLabel(string $tipo): string
{
    return match ($tipo) {
        'administrador' => 'Admin',
        'bibliotecario' => 'Librarian',
        'profesor'      => 'Professor',
        'alumno'        => 'Student',
        default         => ucfirst($tipo),
    };
}

/** Clase CSS del badge de rol. */
function tipoBadgeClass(string $tipo): string
{
    return match ($tipo) {
        'administrador' => 'badge-admin',
        'bibliotecario' => 'badge-librarian',
        'profesor'      => 'badge-professor',
        'alumno'        => 'badge-student',
        default         => 'badge-student',
    };
}

/** Texto de permisos para la tabla. */
function permissionsLabel(string $tipo): string
{
    return match ($tipo) {
        'administrador' => 'All Access',
        'bibliotecario' => 'Manage Catalog',
        'profesor'      => 'Borrow, Reserve',
        'alumno'        => 'Borrow',
        default         => '—',
    };
}

/** Iniciales a partir del nombre completo (máximo 2). */
function getInitials(string $name): string
{
    $words    = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach (array_slice($words, 0, 2) as $word) {
        $initials .= mb_strtoupper(mb_substr($word, 0, 1));
    }
    return $initials;
}

// ── Helpers de libros ────────────────────────────────────────────────────────

/** Etiqueta de disponibilidad y clase CSS para un libro dado sus conteos. */
function disponibilidadInfo(int $disponibles, int $total): array
{
    if ($total === 0)        return ['label' => 'Sin ejemplares', 'class' => 'status-none',      'dot' => 'gray'];
    if ($disponibles > 0)   return ['label' => 'Available',      'class' => 'available',         'dot' => 'green'];
    return                         ['label' => 'Borrowed',       'class' => 'borrowed',          'dot' => 'red'];
}

/** Genera o busca una editorial; devuelve su id. */
function findOrCreateEditorial(PDO $db, string $nombre): ?int
{
    $nombre = trim($nombre);
    if ($nombre === '') return null;
    $stmt = $db->prepare("SELECT id_editorial FROM editoriales WHERE nombre = ? LIMIT 1");
    $stmt->execute([$nombre]);
    $row = $stmt->fetch();
    if ($row) return (int) $row['id_editorial'];
    $db->prepare("INSERT INTO editoriales (nombre) VALUES (?)")->execute([$nombre]);
    return (int) $db->lastInsertId();
}

/** Genera o busca una categoría; devuelve su id. */
function findOrCreateCategoria(PDO $db, string $nombre): ?int
{
    $nombre = trim($nombre);
    if ($nombre === '') return null;
    $stmt = $db->prepare("SELECT id_categoria FROM categorias WHERE nombre = ? LIMIT 1");
    $stmt->execute([$nombre]);
    $row = $stmt->fetch();
    if ($row) return (int) $row['id_categoria'];
    $db->prepare("INSERT INTO categorias (nombre) VALUES (?)")->execute([$nombre]);
    return (int) $db->lastInsertId();
}

/** Cuenta ejemplares activos (no obsoletos) de un libro. */
function countEjemplares(PDO $db, int $libroId): int
{
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM ejemplares WHERE id_libro = ? AND disponible != 'obsoleto'"
    );
    $stmt->execute([$libroId]);
    return (int) $stmt->fetchColumn();
}

/** Crea N ejemplares para un libro dado. */
function crearEjemplares(
    PDO    $db,
    int    $libroId,
    int    $cantidad,
    string $biblioteca,
    string $ubicacion,
    float  $precio = 0.0,
    int    $startSeq = 0
): void {
    $stmt = $db->prepare("
        INSERT INTO ejemplares
            (id_libro, codigo_inventario, biblioteca, ubicacion_pasillo_estante,
             disponible, precio_compra_usd, fecha_adquisicion)
        VALUES (?, ?, ?, ?, 'disponible', ?, CURDATE())
    ");
    for ($i = 1; $i <= $cantidad; $i++) {
        $seq    = $startSeq + $i;
        $codInv = sprintf('DUCK-%04d-%03d', $libroId, $seq);
        $stmt->execute([$libroId, $codInv, $biblioteca, $ubicacion, $precio]);
    }
}

/**
 * Returns a simplified Dewey Decimal number based on category/title keywords.
 * Returns '000' (Generalidades) when no category matches.
 */
function deweyCode(string $categoria, string $titulo = ''): string
{
    $hay = mb_strtolower($categoria . ' ' . $titulo);
    $map = [
        // 000 – Informática / Computación
        'algoritmo'            => '005.1',  'algorithm'             => '005.1',
        'programac'            => '005.1',  'software'              => '005.3',
        'base de datos'        => '005.74', 'database'              => '005.74',
        'computaci'            => '004',    'cómputo'               => '004',
        'computer'             => '004',    'red '                  => '004.6',
        'network'              => '004.6',  'inteligencia artificial'=> '006.3',
        'artificial intellig'  => '006.3',  'seguridad informática'  => '005.8',
        // 100 – Filosofía / Psicología
        'filosofía'            => '100',    'philosophy'            => '100',
        'psicología'           => '150',    'psychology'            => '150',
        // 200 – Religión
        'religión'             => '200',    'religion'              => '200',
        'teología'             => '230',
        // 300 – Ciencias sociales
        'economía'             => '330',    'economics'             => '330',
        'finanzas'             => '332',    'finance'               => '332',
        'contabilidad'         => '657',    'accounting'            => '657',
        'administrac'          => '658',    'management'            => '658',
        'business'             => '650',    'negocio'               => '650',
        'marketing'            => '658.8',  'derecho'               => '340',
        'law'                  => '340',    'educación'             => '370',
        'education'            => '370',    'política'              => '320',
        'political'            => '320',    'social'                => '300',
        // 400 – Lingüística
        'lingüística'          => '410',    'language'              => '400',
        'idioma'               => '400',
        // 500 – Ciencias naturales
        'matemátic'            => '510',    'math'                  => '510',
        'cálculo'              => '515',    'calculus'              => '515',
        'álgebra'              => '512',    'algebra'               => '512',
        'estadística'          => '519',    'statistics'            => '519',
        'probabilidad'         => '519',    'física'                => '530',
        'physics'              => '530',    'química'               => '540',
        'chemistry'            => '540',    'biología'              => '570',
        'biology'              => '570',    'ciencia'               => '500',
        'science'              => '500',
        // 600 – Tecnología / Ingeniería
        'ingeniería'           => '620',    'engineering'           => '620',
        'eléctric'             => '621.3',  'electric'              => '621.3',
        'electrónic'           => '621.38', 'electronic'            => '621.38',
        'mecánic'              => '620.1',  'mechanic'              => '620.1',
        'civil'                => '624',    'arquitectura'          => '720',
        'architecture'         => '720',    'medicina'              => '610',
        'medicine'             => '610',    'salud'                 => '613',
        'health'               => '613',
        // 700 – Artes
        'diseño'               => '745.4',  'design'                => '745.4',
        'música'               => '780',    'music'                 => '780',
        'arte'                 => '700',    'art'                   => '700',
        // 800 – Literatura
        'literatura'           => '800',    'literature'            => '800',
        // 900 – Historia / Geografía
        'historia'             => '900',    'history'               => '900',
        'geografía'            => '910',    'geography'             => '910',
        'biografía'            => '920',    'biography'             => '920',
    ];
    foreach ($map as $kw => $code) {
        if (str_contains($hay, $kw)) {
            return $code;
        }
    }
    return '000';
}

/**
 * Returns a simplified Cutter author code: first letter of last name
 * followed by a 3-digit number derived from the 2nd and 3rd letters.
 * Example: "Cormen" → C382, "Knuth" → K359
 */
function cutterCode(string $autor): string
{
    $autor = trim($autor);
    if ($autor === '') return 'A000';

    $partes   = preg_split('/\s+/', $autor);
    $apellido = strtolower(end($partes) ?: 'a');

    // Normalize common Spanish accents
    $apellido = strtr($apellido, [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
        'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ì' => 'i',
    ]);
    $apellido = preg_replace('/[^a-z]/', 'a', $apellido);

    $letra = strtoupper($apellido[0] ?? 'A');
    $c1    = isset($apellido[1]) ? max(1, min(26, ord($apellido[1]) - 96)) : 1;
    $c2    = isset($apellido[2]) ? max(1, min(26, ord($apellido[2]) - 96)) : 1;

    return $letra . str_pad((string)(($c1 - 1) * 26 + $c2), 3, '0', STR_PAD_LEFT);
}

/** Genera la ficha bibliográfica en texto (formato ISBD simplificado + clasificación). */
function generarFichaBibliografica(array $libro): string
{
    $autor     = $libro['autor']            ?? '';
    $titulo    = $libro['titulo']           ?? '';
    $editorial = $libro['editorial_nombre'] ?? '';
    $anio      = (string) ($libro['anio_publicacion'] ?? '');
    $isbn      = $libro['isbn']             ?? '';
    $cat       = $libro['categoria_nombre'] ?? '';

    $partes   = preg_split('/\s+/', trim($autor));
    $apellido = count($partes) > 1
        ? strtoupper(array_pop($partes)) . ', ' . implode(' ', $partes)
        : strtoupper($autor);

    // Número topográfico
    $dewey  = deweyCode($cat, $titulo);
    $cutter = cutterCode($autor);
    $yr     = $anio ? substr($anio, -2) : '';

    $ficha  = "      {$dewey}\n";
    $ficha .= "      {$cutter}" . ($yr ? " {$yr}" : '') . "\n\n";
    $ficha .= "{$apellido}.\n";
    $ficha .= "    {$titulo}";
    if ($autor) $ficha .= " / {$autor}";
    $ficha .= ". --\n";
    $ficha .= "    " . implode(', ', array_filter([$editorial, $anio])) . ".\n\n";
    if ($isbn) $ficha .= "    ISBN: {$isbn}\n";
    if ($cat)  $ficha .= "    1. {$cat}. I. t.\n";

    return $ficha;
}

// ── Helpers de préstamos ─────────────────────────────────────────────────────

/** Genera el folio del recibo: PREST-YYYY-NNNNN */
function generarFolio(int $id): string
{
    return 'PREST-' . date('Y') . '-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

/** Días de préstamo por defecto según tipo de usuario. */
function diasPrestamoPorTipo(string $tipo): int
{
    return match ($tipo) {
        'alumno'                    => 7,
        'profesor', 'administrador',
        'bibliotecario'             => 14,
        default                     => 7,
    };
}

/** Calcula días de retraso y monto de multa respecto a hoy. */
function calcularMultaInfo(string $fechaVencimiento, float $montoPorDia = 10.0): array
{
    $hoy  = new DateTime('today');
    $venc = new DateTime(explode(' ', $fechaVencimiento)[0]); // solo fecha
    if ($hoy > $venc) {
        $dias  = (int) $hoy->diff($venc)->days;
        return ['atrasado' => true,  'dias' => $dias, 'monto' => $dias * $montoPorDia];
    }
    return ['atrasado' => false, 'dias' => 0, 'monto' => 0.0];
}

/** Etiqueta legible del estado de préstamo. */
function estadoPrestamoLabel(string $estado): string
{
    return match ($estado) {
        'activo'   => 'Active',
        'vencido'  => 'Overdue',
        'devuelto' => 'Returned',
        'perdido'  => 'Lost',
        default    => ucfirst($estado),
    };
}

/** Clase CSS del badge de estado de préstamo. */
function estadoPrestamoBadge(string $estado): string
{
    return match ($estado) {
        'activo'   => 'loan-active',
        'vencido'  => 'loan-overdue',
        'devuelto' => 'loan-returned',
        'perdido'  => 'loan-lost',
        default    => 'loan-active',
    };
}

// ── Helpers de configuración ─────────────────────────────────────────────────

/**
 * Lee un valor de la tabla configuracion (con caché por request).
 * Retorna $default si la clave no existe.
 */
function getConfig(PDO $db, string $key, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        // Carga toda la tabla de una vez
        $cache = [];
        foreach ($db->query("SELECT clave, valor FROM configuracion") as $row) {
            $cache[$row['clave']] = $row['valor'];
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** Actualiza (o inserta) un valor de configuración. */
function setConfig(PDO $db, string $key, string $value): void
{
    $db->prepare("
        INSERT INTO configuracion (clave, valor)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE valor = VALUES(valor)
    ")->execute([$key, $value]);
}

/** Días de préstamo leyendo de configuracion si está disponible. */
function diasPrestamoDB(PDO $db, string $tipo): int
{
    return match ($tipo) {
        'alumno'        => (int) getConfig($db, 'dias_prestamo_alumno',   7),
        'profesor'      => (int) getConfig($db, 'dias_prestamo_profesor', 14),
        default         => (int) getConfig($db, 'dias_prestamo_staff',    14),
    };
}

// ── Exportación ──────────────────────────────────────────────────────────────

/**
 * Streams a CSV file to the browser and exits.
 * Must be called before any HTML output.
 *
 * @param string[] $headers  Column header labels
 * @param array[]  $rows     Each element is an ordered array of cell values
 * @param string   $filename Suggested download filename (e.g. 'loans-2026-05.csv')
 */
function csvDownload(array $headers, array $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    $fp = fopen('php://output', 'w');
    fputs($fp, "\xEF\xBB\xBF");  // UTF-8 BOM — Excel opens without encoding issues
    fputcsv($fp, $headers);
    foreach ($rows as $row) {
        fputcsv($fp, $row);
    }
    fclose($fp);
    exit;
}

// ── Bitácora ──────────────────────────────────────────────────────────────────

/** Graba una acción en la bitácora ISO 9001. */
function logAction(
    PDO    $db,
    int    $actorId,
    string $accion,
    string $entidad,
    int    $entidadId,
    array  $detalle = [],
    string $modulo  = ''
): void {
    $stmt = $db->prepare("
        INSERT INTO bitacora_iso_9001
            (id_usuario_actor, accion, entidad_afectada, id_entidad_afectada,
             detalle_cambio, ip_usuario, modulo)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $actorId,
        $accion,
        $entidad,
        $entidadId,
        json_encode($detalle, JSON_UNESCAPED_UNICODE),
        $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        $modulo,
    ]);
}
