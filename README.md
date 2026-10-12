# Sistema de Reservación de Espacios — Docker Compose con cinco servicios

Este sistema corresponde al dominio asignado al último dígito **8** (*Reservación de espacios*, entidad **Reservación**).
Permite crear, consultar, actualizar y eliminar reservaciones de espacios como auditorios, laboratorios y salas.
El backend guarda los datos en **MariaDB**, conserva en caché las consultas del listado en **Redis**, lleva un
contador de consultas y envía un correo de prueba a **Mailpit** cada vez que se crea una reservación.

**Mejora individual elegida:** *filtro de búsqueda*. El frontend tiene un campo de búsqueda que llama a
`GET /api/reservaciones?buscar=texto`. El backend busca ese texto en el espacio, el solicitante y el correo
dentro de MariaDB, y también guarda cada búsqueda en caché con su propia clave.

---

## 1. Arquitectura

| Servicio   | Tecnología                         | Responsabilidad                                   | Exposición            |
|------------|------------------------------------|---------------------------------------------------|-----------------------|
| `frontend` | Nginx 1.27 + HTML/CSS/JS           | Interfaz y proxy de `/api/` hacia `backend`       | Host **8081**         |
| `backend`  | PHP 8.3 + Apache                   | API REST, lógica, MariaDB, Redis y SMTP           | Solo red interna (80) |
| `db`       | MariaDB 11.4                       | Persistencia de reservaciones                     | Solo red interna (5432)|
| `redis`    | Redis 7.4                          | Caché (TTL 60 s) y contador `contador_consultas`  | Solo red interna (6379)|
| `mailpit`  | Mailpit v1.21.8                    | Captura de correos de prueba                      | Interfaz web **8025** |

```
Navegador
  └── http://localhost:8081
        └── frontend (Nginx)
              ├── archivos estáticos (index.html, app.js, styles.css)
              └── /api/ → backend:80
                           ├── db:5432        (MariaDB)
                           ├── redis:6379     (caché + contador)
                           └── mailpit:1025   (SMTP de pruebas)

Mailpit UI → http://localhost:8025
```

Los cinco servicios están en la red `app_network`. Solo `frontend` (8081) y `mailpit` (8025) publican puertos
en el host.

### Estructura

```
proyecto-chavez-sandra/
├── compose.yaml
├── .env.example          # plantilla sin contraseñas reales
├── .gitignore            # excluye .env
├── README.md
├── database/
│   └── init.sql          # tabla, PK, UNIQUE, CHECK y 3 registros iniciales
├── backend/
│   ├── Dockerfile        # php:8.3-apache + pdo_mysql + phpredis
│   └── src/
│       ├── index.php     # router y endpoints REST
│       └── lib/          # Database.php, Cache.php, Mailer.php (cliente SMTP)
└── frontend/
    ├── Dockerfile        # nginx:1.27-alpine
    ├── nginx.conf        # estáticos + proxy /api/ → backend
    └── src/              # index.html, app.js, styles.css
informe/
├── Reporte_Practica_Docker_Compose_Chavez_Hernandez_Sandra.pdf   # reporte con evidencias
├── reporte.html          # versión HTML del reporte
└── capturas/             # capturas de la interfaz, Mailpit y terminal
```

### Modelo de datos (`reservaciones`)

| Columna        | Tipo                                         | Restricción                          |
|----------------|----------------------------------------------|--------------------------------------|
| id             | INT UNSIGNED AUTO_INCREMENT                  | PRIMARY KEY                          |
| espacio        | VARCHAR(100)                                 | NOT NULL, CHECK ≥ 3 caracteres       |
| solicitante    | VARCHAR(100)                                 | NOT NULL, CHECK ≥ 3 caracteres       |
| correo         | VARCHAR(150)                                 | NOT NULL                             |
| fecha          | DATE                                         | NOT NULL                             |
| hora_inicio    | TIME                                         | NOT NULL                             |
| hora_fin       | TIME                                         | NOT NULL, CHECK `hora_fin > hora_inicio` |
| estado         | ENUM('pendiente','confirmada','cancelada')   | DEFAULT 'pendiente'                  |
| creado_en / actualizado_en | TIMESTAMP                        | automáticos                          |

La restricción `UNIQUE (espacio, fecha, hora_inicio)` impide reservar dos veces el mismo espacio a la misma hora
de inicio. Si ocurre, la API responde con **409 Conflict**.

### API REST (a través de `http://localhost:8081/api`)

| Método | Ruta                          | Descripción                                   | Códigos              |
|--------|-------------------------------|-----------------------------------------------|----------------------|
| GET    | `/health`                     | Verifica la conexión con MariaDB y Redis      | 200 / 503            |
| GET    | `/reservaciones[?buscar=txt]` | Listado (caché Redis + contador)              | 200 / 422            |
| GET    | `/reservaciones/{id}`         | Detalle                                       | 200 / 404            |
| POST   | `/reservaciones`              | Crea, invalida la caché y envía el correo     | 201 / 400 / 409 / 422|
| PUT    | `/reservaciones/{id}`         | Actualiza (parcial, p. ej. solo `estado`)     | 200 / 404 / 409 / 422|
| DELETE | `/reservaciones/{id}`         | Elimina y luego invalida la caché             | 204 / 404            |
| GET    | `/estadisticas`               | Contador y TTL restante de la caché           | 200                  |

La respuesta del listado incluye `"fuente": "redis" | "mariadb"`, la cabecera `X-Cache: HIT | MISS` y el valor
actual de `contador_consultas`. El backend también escribe `[cache] HIT/MISS/INVALIDADA` y `[mail]` en sus logs.

---

## 2. Requisitos

- Docker Desktop o Docker Engine con Docker Compose v2
- Al menos 4 GB de RAM libres
- Navegador, `curl` y Git

## 3. Ejecución desde cero

```bash
git clone <url-del-repositorio> proyecto-chavez-sandra
cd proyecto-chavez-sandra
cp .env.example .env        # en Windows PowerShell: Copy-Item .env.example .env
# editar .env y poner contraseñas propias
docker compose config
docker compose up -d --build
docker compose ps
```

- Aplicación: **http://localhost:8081**
- Mailpit: **http://localhost:8025**
- Salud: `curl http://localhost:8081/api/health`

> Si el puerto 8081 ya está ocupado por otro proyecto, puedes cambiarlo sin modificar el compose:
> agrega `FRONTEND_PORT=8082` al archivo `.env`.

Para detener el proyecto: `docker compose down`. Para borrar también los datos: `docker compose down -v`.

---

## 4. Pruebas obligatorias

| Prueba         | Procedimiento                                                                                       | Resultado esperado |
|----------------|-----------------------------------------------------------------------------------------------------|--------------------|
| Validación YAML| `docker compose config`                                                                             | Sin errores        |
| Estado         | `docker compose ps`                                                                                 | 5 servicios; db, redis, backend y mailpit `(healthy)` |
| CRUD           | Crear, editar (botón *Editar* o selector de estado) y eliminar (con confirmación) desde la interfaz  | Cambios visibles y persistentes |
| Persistencia   | `docker compose rm -sf db` y luego `docker compose up -d db`                                        | Los registros permanecen |
| Caché          | `curl -i http://localhost:8081/api/reservaciones` dos veces                                         | 1.ª `X-Cache: MISS`, 2.ª `X-Cache: HIT` (`"fuente":"redis"`) |
| TTL            | `docker compose exec redis redis-cli TTL reservaciones:lista`                                       | Valor entre 1 y 60 |
| Invalidación   | Crear un registro y consultar                                                                       | El dato nuevo aparece; log `[cache] INVALIDADA` |
| Contador       | Consultar el listado varias veces y `redis-cli GET contador_consultas`                              | El valor aumenta |
| Correo         | Crear un registro y abrir http://localhost:8025                                                     | Asunto `Reservación #N: <espacio>` |
| Aislamiento    | `docker compose ps`                                                                                 | db y redis sin `0.0.0.0:` en PORTS |
| Recuperación   | `docker compose stop backend` (la API responde 502) y luego `docker compose start backend`          | La aplicación vuelve a funcionar |

> En `docker compose ps`, la columna PORTS de `db` muestra `3306/tcp`. Ese dato solo es el puerto declarado en la
> imagen de MariaDB; **no está publicado** porque no aparece `0.0.0.0:`. En este proyecto MariaDB escucha en 5432
> por la opción `--port=5432`.

Prueba de la mejora (búsqueda):

```bash
curl "http://localhost:8081/api/reservaciones?buscar=auditorio"
```

### Comandos de evidencia

```bash
docker compose config
docker compose ps
docker compose images
docker compose exec db sh -c 'mariadb -P5432 -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE" -e "SELECT * FROM reservaciones"'
docker compose exec redis redis-cli KEYS "*"
docker compose exec redis redis-cli GET contador_consultas
docker compose exec redis redis-cli TTL reservaciones:lista
docker compose logs backend
docker network inspect reservaciones_app_network
docker volume inspect reservaciones_MariaDB_data
```

`compose.yaml` define `name: reservaciones`, por eso la red y los volúmenes se llaman `reservaciones_*` sin
importar el nombre de la carpeta. El contenedor de Redis recibe `REDISCLI_AUTH`, así que `redis-cli` se
autentica solo y no hay que escribir la contraseña en la terminal.

---

## 5. Decisiones técnicas y notas

- **Versión y puerto de MariaDB.** La guía menciona `MariaDB:16-alpine`, el puerto 5432 y la ruta
  `/var/lib/MariaDB/data`. Esos valores corresponden a PostgreSQL: la imagen `mariadb:16` no existe. Por eso se usa
  `mariadb:11.4` (versión LTS). Para cumplir con `DB_PORT=5432`, MariaDB se inicia con `--port=5432`. El volumen
  nombrado `MariaDB_data` se monta en `/var/lib/mysql`, que es el directorio real de datos de MariaDB.
- **Health checks.** `db` ejecuta un `SELECT 1` por TCP con el usuario de la aplicación. Ese comando solo tiene
  éxito cuando `init.sql` ya terminó, porque durante la inicialización MariaDB no acepta conexiones de red.
  `redis` ejecuta `redis-cli ping`. El backend tiene su propio health check (`/health`), y el frontend espera
  a que el backend esté *healthy*.
- **Proxy Nginx resistente a reinicios.** `nginx.conf` usa el DNS interno de Docker (`resolver 127.0.0.11`) y una
  variable en `proxy_pass`. Así Nginx vuelve a resolver `backend` aunque el contenedor cambie de IP al reiniciarse.
- **Seguridad.** Las contraseñas están solo en `.env`, que está en `.gitignore`. Redis pide contraseña
  (`--requirepass`). No se usa `privileged`, no se monta el socket de Docker y no se usa `container_name`.
  El directorio `backend/src/lib` no se puede abrir por HTTP (responde 403).
- **Redis.** Tiene persistencia AOF (`--appendonly yes`) en el volumen `redis_data`.
- **Mailpit.** Es solo una herramienta de desarrollo: captura los correos SMTP y los muestra en su interfaz web,
  pero **no entrega correo real** a ningún destinatario.

---

## 6. Resolución de nombres (db, redis, mailpit, backend)

Docker Compose crea la red de usuario `reservaciones_app_network` y conecta ahí a los cinco contenedores. Cada red
de usuario tiene un servidor DNS interno en `127.0.0.11`, y ese servidor registra el **nombre de cada servicio**
como alias de la IP de su contenedor.

- El backend se conecta a `db:5432`, `redis:6379` y `mailpit:1025`. El DNS interno convierte cada nombre en la
  IP actual del contenedor correspondiente (por ejemplo, 172.20.0.3).
- Nginx reenvía `/api/` a `http://backend:80` y consulta el mismo DNS.
- Si un contenedor se recrea y cambia de IP, el nombre se mantiene. Por eso nunca se usan IP fijas ni `localhost`.
  Dentro de un contenedor, `localhost` apunta a ese mismo contenedor.

Para comprobarlo: `docker compose exec backend getent hosts db redis mailpit`.

