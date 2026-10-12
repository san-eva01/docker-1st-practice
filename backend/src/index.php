<?php
// API REST del sistema de reservación de espacios.
// Nginx (frontend) reenvía /api/* hacia este servicio quitando el prefijo /api.
//
//   GET    /health                     estado de MariaDB y Redis
//   GET    /reservaciones?buscar=txt   listado (caché Redis + contador)
//   GET    /reservaciones/{id}         detalle
//   POST   /reservaciones              crear (envía correo a Mailpit)
//   PUT    /reservaciones/{id}         actualizar
//   DELETE /reservaciones/{id}         eliminar
//   GET    /estadisticas               contador y TTL de la caché

declare(strict_types=1);

require __DIR__ . '/lib/Database.php';
require __DIR__ . '/lib/Cache.php';
require __DIR__ . '/lib/Mailer.php';

const ESTADOS = ['pendiente', 'confirmada', 'cancelada'];
const CAMPOS  = ['espacio', 'solicitante', 'correo', 'fecha', 'hora_inicio', 'hora_fin', 'estado'];

header('Content-Type: application/json; charset=utf-8');

function responder(int $codigo, $datos = null): void
{
    http_response_code($codigo);
    if ($datos !== null) {
        echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
}

function error_http(int $codigo, string $mensaje, array $detalles = []): void
{
    $cuerpo = ['error' => $mensaje];
    if ($detalles) {
        $cuerpo['detalles'] = $detalles;
    }
    responder($codigo, $cuerpo);
}

function leer_json(): array
{
    $datos = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($datos)) {
        error_http(400, 'El cuerpo debe ser un objeto JSON válido');
    }
    return $datos;
}

// Valida los campos recibidos. Con $parcial=true (PUT) solo valida los presentes.
function validar(array $entrada, bool $parcial): array
{
    $datos = [];
    $errores = [];

    foreach (CAMPOS as $campo) {
        if (!array_key_exists($campo, $entrada)) {
            if (!$parcial && $campo !== 'estado') {
                $errores[$campo] = 'Campo obligatorio';
            }
            continue;
        }
        $datos[$campo] = is_string($entrada[$campo]) ? trim($entrada[$campo]) : $entrada[$campo];
    }

    foreach (['espacio', 'solicitante'] as $campo) {
        if (isset($datos[$campo])) {
            $largo = mb_strlen((string) $datos[$campo]);
            if ($largo < 3 || $largo > 100) {
                $errores[$campo] = 'Debe tener entre 3 y 100 caracteres';
            }
        }
    }
    if (isset($datos['correo']) && !filter_var($datos['correo'], FILTER_VALIDATE_EMAIL)) {
        $errores['correo'] = 'Correo electrónico no válido';
    }
    if (isset($datos['fecha'])) {
        $f = DateTime::createFromFormat('!Y-m-d', (string) $datos['fecha']);
        if (!$f || $f->format('Y-m-d') !== $datos['fecha']) {
            $errores['fecha'] = 'Formato esperado AAAA-MM-DD';
        }
    }
    foreach (['hora_inicio', 'hora_fin'] as $campo) {
        if (isset($datos[$campo])) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', (string) $datos[$campo])) {
                $errores[$campo] = 'Formato esperado HH:MM';
            } else {
                $datos[$campo] = substr($datos[$campo], 0, 5);
            }
        }
    }
    if (isset($datos['hora_inicio'], $datos['hora_fin']) && !isset($errores['hora_inicio'], $errores['hora_fin'])
        && $datos['hora_fin'] <= $datos['hora_inicio']) {
        $errores['hora_fin'] = 'Debe ser posterior a la hora de inicio';
    }
    if (isset($datos['estado']) && !in_array($datos['estado'], ESTADOS, true)) {
        $errores['estado'] = 'Valores permitidos: ' . implode(', ', ESTADOS);
    }
    if ($parcial && !$datos) {
        $errores['_'] = 'No se envió ningún campo para actualizar';
    }

    if ($errores) {
        error_http(422, 'Datos no válidos', $errores);
    }
    return $datos;
}

function buscar_por_id(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM reservaciones WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function es_duplicado(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? 0) === 1062;
}

// ---------- Endpoints ----------

function health(): void
{
    $estado = ['status' => 'ok', 'servicios' => []];
    try {
        db()->query('SELECT 1');
        $estado['servicios']['mariadb'] = 'ok';
    } catch (Throwable $e) {
        $estado['servicios']['mariadb'] = 'error: ' . $e->getMessage();
        $estado['status'] = 'error';
    }
    try {
        $estado['servicios']['redis'] = redis_client()->ping() ? 'ok' : 'error';
    } catch (Throwable $e) {
        $estado['servicios']['redis'] = 'error: ' . $e->getMessage();
        $estado['status'] = 'error';
    }
    responder($estado['status'] === 'ok' ? 200 : 503, $estado);
}

function listar(): void
{
    $buscar = trim((string) ($_GET['buscar'] ?? ''));
    if (mb_strlen($buscar) > 100) {
        error_http(422, 'El texto de búsqueda no debe superar 100 caracteres');
    }
    $redis = redis_client();
    $consultas = $redis->incr(CONTADOR);
    $clave = cache_key($buscar);

    $cache = $redis->get($clave);
    if ($cache !== false) {
        error_log("[cache] HIT $clave (TTL restante {$redis->ttl($clave)}s)");
        header('X-Cache: HIT');
        $registros = json_decode($cache, true);
        $fuente = 'redis';
    } else {
        error_log("[cache] MISS $clave -> consulta a MariaDB");
        header('X-Cache: MISS');
        if ($buscar === '') {
            $st = db()->query('SELECT * FROM reservaciones ORDER BY fecha, hora_inicio');
        } else {
            $st = db()->prepare(
                'SELECT * FROM reservaciones
                 WHERE espacio LIKE :q1 OR solicitante LIKE :q2 OR correo LIKE :q3
                 ORDER BY fecha, hora_inicio'
            );
            $patron = '%' . addcslashes($buscar, '%_\\') . '%';
            $st->execute([':q1' => $patron, ':q2' => $patron, ':q3' => $patron]);
        }
        $registros = $st->fetchAll();
        $redis->setex($clave, cache_ttl(), json_encode($registros, JSON_UNESCAPED_UNICODE));
        $fuente = 'mariadb';
    }

    responder(200, [
        'fuente'             => $fuente,
        'buscar'             => $buscar,
        'contador_consultas' => $consultas,
        'total'              => count($registros),
        'datos'              => $registros,
    ]);
}

function crear(): void
{
    $datos = validar(leer_json(), false);
    $datos['estado'] ??= 'pendiente';
    try {
        $st = db()->prepare(
            'INSERT INTO reservaciones (espacio, solicitante, correo, fecha, hora_inicio, hora_fin, estado)
             VALUES (:espacio, :solicitante, :correo, :fecha, :hora_inicio, :hora_fin, :estado)'
        );
        $st->execute($datos);
    } catch (PDOException $e) {
        if (es_duplicado($e)) {
            error_http(409, 'Ese espacio ya está reservado en la misma fecha y hora de inicio');
        }
        throw $e;
    }
    $reservacion = buscar_por_id((int) db()->lastInsertId());
    cache_invalidar();

    $correo = 'enviado';
    try {
        notificar_reservacion($reservacion);
    } catch (Throwable $e) {
        error_log('[mail] ERROR: ' . $e->getMessage());
        $correo = 'no enviado';
    }
    responder(201, ['mensaje' => 'Reservación creada', 'correo' => $correo, 'datos' => $reservacion]);
}

function actualizar(int $id): void
{
    if (!buscar_por_id($id)) {
        error_http(404, "No existe la reservación $id");
    }
    $datos = validar(leer_json(), true);

    // Si solo se cambia una de las horas, validar contra la hora guardada
    $actual = buscar_por_id($id);
    $inicio = $datos['hora_inicio'] ?? substr($actual['hora_inicio'], 0, 5);
    $fin    = $datos['hora_fin'] ?? substr($actual['hora_fin'], 0, 5);
    if ($fin <= $inicio) {
        error_http(422, 'Datos no válidos', ['hora_fin' => 'Debe ser posterior a la hora de inicio']);
    }

    $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($datos)));
    try {
        $st = db()->prepare("UPDATE reservaciones SET $sets WHERE id = :id");
        $st->execute($datos + ['id' => $id]);
    } catch (PDOException $e) {
        if (es_duplicado($e)) {
            error_http(409, 'Ese espacio ya está reservado en la misma fecha y hora de inicio');
        }
        throw $e;
    }
    cache_invalidar();
    responder(200, ['mensaje' => 'Reservación actualizada', 'datos' => buscar_por_id($id)]);
}

function eliminar(int $id): void
{
    $st = db()->prepare('DELETE FROM reservaciones WHERE id = ?');
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        error_http(404, "No existe la reservación $id");
    }
    cache_invalidar();
    responder(204);
}

function estadisticas(): void
{
    $redis = redis_client();
    responder(200, [
        'contador_consultas' => (int) ($redis->get(CONTADOR) ?: 0),
        'cache_lista_ttl'    => $redis->ttl(CACHE_LISTA),
        'cache_ttl_config'   => cache_ttl(),
    ]);
}

// ---------- Router ----------

$metodo = $_SERVER['REQUEST_METHOD'];
$ruta   = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/', '/') ?: '/';

try {
    if ($ruta === '/health' && $metodo === 'GET') {
        health();
    } elseif ($ruta === '/estadisticas' && $metodo === 'GET') {
        estadisticas();
    } elseif ($ruta === '/reservaciones') {
        match ($metodo) {
            'GET'   => listar(),
            'POST'  => crear(),
            default => error_http(405, 'Método no permitido'),
        };
    } elseif (preg_match('#^/reservaciones/(\d+)$#', $ruta, $m)) {
        $id = (int) $m[1];
        match ($metodo) {
            'GET'    => ($r = buscar_por_id($id)) ? responder(200, $r) : error_http(404, "No existe la reservación $id"),
            'PUT'    => actualizar($id),
            'DELETE' => eliminar($id),
            default  => error_http(405, 'Método no permitido'),
        };
    } else {
        error_http(404, 'Ruta no encontrada');
    }
} catch (PDOException $e) {
    error_log('[db] ' . $e->getMessage());
    error_http(503, 'Error de base de datos');
} catch (RedisException $e) {
    error_log('[redis] ' . $e->getMessage());
    error_http(503, 'Error de caché');
} catch (Throwable $e) {
    error_log('[api] ' . $e->getMessage());
    error_http(500, 'Error interno del servidor');
}
