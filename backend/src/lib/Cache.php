<?php
// Caché y contador en Redis usando el nombre de servicio "redis"

const CACHE_LISTA    = 'reservaciones:lista';
const CACHE_BUSQUEDA = 'reservaciones:buscar:';
const CONTADOR       = 'contador_consultas';

function redis_client(): Redis
{
    static $redis = null;
    if ($redis === null) {
        $redis = new Redis();
        $redis->connect(getenv('REDIS_HOST') ?: 'redis', (int) (getenv('REDIS_PORT') ?: 6379), 3.0);
        $password = getenv('REDIS_PASSWORD');
        if ($password !== false && $password !== '') {
            $redis->auth($password);
        }
    }
    return $redis;
}

function cache_ttl(): int
{
    return max(30, (int) (getenv('CACHE_TTL') ?: 60));
}

function cache_key(string $buscar): string
{
    return $buscar === '' ? CACHE_LISTA : CACHE_BUSQUEDA . md5(mb_strtolower($buscar));
}

// Elimina la lista general y todas las búsquedas guardadas
function cache_invalidar(): void
{
    $redis = redis_client();
    $claves = [CACHE_LISTA];
    $it = null;
    while (($lote = $redis->scan($it, CACHE_BUSQUEDA . '*', 100)) !== false) {
        $claves = array_merge($claves, $lote);
    }
    $redis->del($claves);
    error_log('[cache] INVALIDADA (' . count($claves) . ' claves)');
}
