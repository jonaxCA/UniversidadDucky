<?php
/**
 * Chatbot endpoint — Ducky (asistente virtual de biblioteca).
 * Recibe POST JSON {"message": "..."} y devuelve JSON {"response": "..."}.
 * Patrón RAG: detecta intención → consulta BD → construye prompt → llama Ollama.
 */
require_once 'includes/db.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

// ── Configuración ────────────────────────────────────────────────────────────
const OLLAMA_URL    = 'http://localhost:11434/api/generate';
const OLLAMA_MODEL  = 'qwen2.5:7b';      // cambia a 'ducky' si creaste el Modelfile custom
const OLLAMA_TIMEOUT = 60;               // segundos (modelos grandes pueden tardar)

$db = getDB();
$me = currentUser();

// ── Leer body JSON ───────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
$msg  = trim($body['message'] ?? '');

if ($msg === '') {
    echo json_encode(['error' => 'Mensaje vacío.']);
    exit;
}
if (mb_strlen($msg) > 500) {
    echo json_encode(['error' => 'El mensaje es demasiado largo (máx. 500 caracteres).']);
    exit;
}

// ── Detectar intención por palabras clave ────────────────────────────────────
function detectIntent(string $msg): string
{
    $m = mb_strtolower($msg, 'UTF-8');

    // Saludo / smalltalk
    if (preg_match('/^\s*(hola|buenas|hey|hi|hello|qué onda|que onda|saludos)/u', $m)) {
        return 'greeting';
    }
    // Multas
    if (preg_match('/\b(multa|multas|adeudo|debo|deuda|pagar|fine|fines|pago)\b/u', $m)) {
        return 'fines';
    }
    // Mis préstamos
    if (preg_match('/\b(mis prestamos|mis préstamos|mis libros|qué tengo prestado|qué llevo|mi préstamo)\b/u', $m)) {
        return 'my_loans';
    }
    // Cómo solicitar préstamo
    if (preg_match('/\b(cómo (pido|solicito|saco)|how to borrow|llevarme|prestar|pedir prestado|reglas de préstamo)\b/u', $m)) {
        return 'loan_info';
    }
    // Horarios / ubicación
    if (preg_match('/\b(horario|hora|abre|cierra|abierto|cerrado|ubicación|donde está|dónde está|hours|location)\b/u', $m)) {
        return 'hours';
    }
    // Ayuda
    if (preg_match('/\b(ayuda|help|qué puedes|que puedes hacer)\b/u', $m)) {
        return 'help';
    }
    // Default → búsqueda de libros
    return 'book_search';
}

// ── Recuperar contexto desde la BD según la intención (RAG) ─────────────────
function gatherContext(PDO $db, string $intent, string $msg, array $me): string
{
    switch ($intent) {

        case 'fines': {
            $q = $db->prepare("
                SELECT m.monto_total, m.dias_retraso, m.tipo_mora, l.titulo
                FROM   multas m
                JOIN   prestamos p ON m.id_prestamo = p.id_prestamo
                JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
                JOIN   libros    l ON e.id_libro    = l.id_libro
                WHERE  p.id_usuario = ? AND m.estado_pago = 0
                ORDER  BY m.creado_en DESC
            ");
            $q->execute([$me['id']]);
            $rows = $q->fetchAll();
            if (!$rows) {
                return "El usuario {$me['nombre']} NO tiene multas pendientes. Su cuenta está al corriente.";
            }
            $total = 0;
            $detalle = [];
            foreach ($rows as $r) {
                $total += (float)$r['monto_total'];
                $detalle[] = sprintf(
                    '- $%.2f MXN por %s en "%s" (%d días)',
                    $r['monto_total'], $r['tipo_mora'] ?? 'retraso',
                    $r['titulo'], (int)$r['dias_retraso']
                );
            }
            return "MULTAS PENDIENTES de {$me['nombre']}:\n"
                . implode("\n", $detalle)
                . sprintf("\nTOTAL ADEUDADO: $%.2f MXN", $total);
        }

        case 'my_loans': {
            $q = $db->prepare("
                SELECT l.titulo, l.autor, p.fecha_vencimiento, p.estado, p.folio_recibo
                FROM   prestamos p
                JOIN   ejemplares e ON p.id_ejemplar = e.id_ejemplar
                JOIN   libros    l ON e.id_libro    = l.id_libro
                WHERE  p.id_usuario = ? AND p.estado IN ('activo','vencido')
                ORDER  BY p.fecha_vencimiento ASC
            ");
            $q->execute([$me['id']]);
            $rows = $q->fetchAll();
            if (!$rows) {
                return "{$me['nombre']} no tiene préstamos activos en este momento.";
            }
            $detalle = [];
            foreach ($rows as $r) {
                $detalle[] = sprintf('- "%s" por %s, vence el %s [%s]',
                    $r['titulo'], $r['autor'] ?? 's/a',
                    date('d M Y', strtotime($r['fecha_vencimiento'])),
                    $r['estado']);
            }
            return "PRÉSTAMOS ACTIVOS de {$me['nombre']}:\n" . implode("\n", $detalle);
        }

        case 'loan_info': {
            $diasAlumno   = getConfig($db, 'dias_prestamo_alumno',   7);
            $diasProfesor = getConfig($db, 'dias_prestamo_profesor', 14);
            $maxRen       = getConfig($db, 'max_renovaciones',       2);
            $multaDia     = getConfig($db, 'monto_multa_dia',        10);
            return "REGLAS DE PRÉSTAMO de Universidad Ducky:\n"
                . "- Alumnos: {$diasAlumno} días por libro\n"
                . "- Profesores: {$diasProfesor} días por libro\n"
                . "- Renovaciones máximas: {$maxRen} por préstamo\n"
                . "- Multa por retraso: \$$multaDia MXN por día\n"
                . "- Para solicitar un préstamo, el alumno acude a la biblioteca con su credencial.";
        }

        case 'hours': {
            return "HORARIOS Y UBICACIONES de Universidad Ducky:\n"
                . "- Biblioteca Estoa: Lunes a Viernes 8:00–20:00, Sábados 9:00–14:00\n"
                . "- Biblioteca CCU: Lunes a Viernes 9:00–19:00\n"
                . "- Ambas cerradas en domingos y días festivos institucionales.";
        }

        case 'book_search': {
            // Extraer palabras significativas (>= 3 chars, sin stopwords)
            $stop = ['libro','libros','tienen','tiene','hay','del','para','con',
                     'sobre','quiero','busco','pueden','algun','algún','autor',
                     'titulo','título','que','los','las','una','uno','una','book','books'];
            $tokens = preg_split('/\s+/', mb_strtolower($msg, 'UTF-8'));
            $tokens = array_filter($tokens, fn($t) => mb_strlen($t) >= 3 && !in_array($t, $stop, true));

            if (empty($tokens)) {
                return "No se identificaron palabras clave en la consulta. Sugiere al usuario buscar por título o autor específico.";
            }

            $like   = '%' . implode('%', array_slice($tokens, 0, 5)) . '%';
            $stmt   = $db->prepare("
                SELECT l.id_libro, l.titulo, l.autor, l.isbn, l.anio_publicacion,
                       c.nombre AS categoria,
                       COALESCE(s.disponibles, 0) AS disponibles,
                       COALESCE(s.total, 0)       AS total,
                       s.biblioteca, s.ubicacion
                FROM libros l
                LEFT JOIN categorias c ON l.id_categoria = c.id_categoria
                LEFT JOIN (
                    SELECT id_libro,
                           COUNT(*) AS total,
                           SUM(disponible='disponible') AS disponibles,
                           MIN(biblioteca) AS biblioteca,
                           MIN(ubicacion_pasillo_estante) AS ubicacion
                    FROM ejemplares
                    WHERE disponible != 'obsoleto'
                    GROUP BY id_libro
                ) s ON l.id_libro = s.id_libro
                WHERE l.titulo LIKE ? OR l.autor LIKE ? OR l.isbn LIKE ?
                ORDER BY (s.disponibles > 0) DESC, l.titulo
                LIMIT 5
            ");
            $stmt->execute([$like, $like, $like]);
            $rows = $stmt->fetchAll();

            if (!$rows) {
                // Intentar con cada token por separado
                $orParts  = [];
                $orParams = [];
                foreach (array_slice($tokens, 0, 4) as $t) {
                    $orParts[]  = '(l.titulo LIKE ? OR l.autor LIKE ?)';
                    $orParams[] = "%$t%"; $orParams[] = "%$t%";
                }
                $sql = "SELECT l.id_libro, l.titulo, l.autor, l.isbn,
                               COALESCE(SUM(e.disponible='disponible'),0) AS disponibles,
                               COALESCE(COUNT(e.id_ejemplar),0) AS total
                        FROM libros l
                        LEFT JOIN ejemplares e ON e.id_libro = l.id_libro AND e.disponible != 'obsoleto'
                        WHERE " . implode(' OR ', $orParts) . "
                        GROUP BY l.id_libro
                        LIMIT 5";
                $stmt = $db->prepare($sql);
                $stmt->execute($orParams);
                $rows = $stmt->fetchAll();
            }

            if (!$rows) {
                return "NO SE ENCONTRARON LIBROS que coincidan con la consulta del usuario. "
                     . "Sugiere alternativas o buscar con otras palabras.";
            }

            $detalle = [];
            foreach ($rows as $r) {
                $disp = (int)$r['disponibles'];
                $total = (int)$r['total'];
                $estado = $disp > 0 ? "{$disp} disponible(s) de {$total}" : "TODOS PRESTADOS ({$total} ejemplares)";
                $loc  = !empty($r['biblioteca']) ? " en {$r['biblioteca']}" . (!empty($r['ubicacion']) ? " ({$r['ubicacion']})" : "") : "";
                $detalle[] = sprintf('- "%s" por %s%s. %s%s.',
                    $r['titulo'], $r['autor'] ?? 'autor desconocido',
                    $r['anio_publicacion'] ? " ({$r['anio_publicacion']})" : "",
                    $estado, $loc);
            }
            return "LIBROS ENCONTRADOS EN EL CATÁLOGO:\n" . implode("\n", $detalle);
        }

        case 'help': {
            return "CAPACIDADES DEL ASISTENTE: buscar libros por título/autor, ver disponibilidad y ubicación, "
                 . "consultar multas pendientes, ver préstamos activos del usuario, dar horarios y reglas de préstamo.";
        }

        case 'greeting':
        default:
            return "El usuario {$me['nombre']} (rol: {$me['tipo']}) inició la conversación. "
                 . "Responde con un saludo breve y pregunta en qué puedes ayudarlo.";
    }
}

// ── Construir prompt para el LLM ─────────────────────────────────────────────
function buildPrompt(string $userMsg, string $context, array $me): string
{
    $system = <<<SYS
Eres "Ducky", el asistente virtual de la biblioteca de Universidad Ducky.
Reglas:
- Responde SIEMPRE en español, breve (máximo 3 oraciones), amable y profesional.
- Solo respondes sobre: catálogo de libros, disponibilidad, préstamos, multas, horarios.
- Si la pregunta es ajena a la biblioteca, redirige amablemente al usuario.
- Usa el CONTEXTO recuperado como única fuente de verdad. NO inventes libros, montos ni fechas.
- Si el contexto dice "NO SE ENCONTRARON", informa que no se encontró y ofrece sugerencias.
- No saludes al usuario por su nombre completo cada vez.
SYS;

    $user = "Usuario actual: {$me['nombre']} (rol: {$me['tipo']})\n\n"
          . "CONTEXTO RECUPERADO DE LA BASE DE DATOS:\n{$context}\n\n"
          . "PREGUNTA DEL USUARIO:\n{$userMsg}";

    // Formato Qwen / Llama (system + user)
    return $system . "\n\n" . $user;
}

// ── Llamar a Ollama ──────────────────────────────────────────────────────────
function callOllama(string $prompt): array
{
    $payload = json_encode([
        'model'   => OLLAMA_MODEL,
        'prompt'  => $prompt,
        'stream'  => false,
        'options' => [
            'temperature' => 0.4,
            'num_predict' => 256,
            'num_ctx'     => 4096,
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(OLLAMA_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => OLLAMA_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        return [
            'ok'    => false,
            'error' => $err ?: "Ollama respondió HTTP {$code}",
        ];
    }
    $data = json_decode($raw, true);
    return [
        'ok'       => true,
        'response' => trim($data['response'] ?? ''),
    ];
}

// ── Pipeline principal ───────────────────────────────────────────────────────
$intent  = detectIntent($msg);
$context = gatherContext($db, $intent, $msg, $me);
$prompt  = buildPrompt($msg, $context, $me);
$result  = callOllama($prompt);

if (!$result['ok']) {
    echo json_encode([
        'response' => "Lo siento, el asistente no está disponible en este momento. "
                    . "Verifica que Ollama esté corriendo (http://localhost:11434).",
        'intent'   => $intent,
        'offline'  => true,
        'debug'    => $result['error'] ?? null,
    ]);
    exit;
}

echo json_encode([
    'response' => $result['response'],
    'intent'   => $intent,
]);
