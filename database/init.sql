-- Sistema de reservación de espacios
-- Se ejecuta solo la primera vez que se crea el volumen de datos.

CREATE TABLE IF NOT EXISTS reservaciones (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    espacio      VARCHAR(100) NOT NULL,
    solicitante  VARCHAR(100) NOT NULL,
    correo       VARCHAR(150) NOT NULL,
    fecha        DATE         NOT NULL,
    hora_inicio  TIME         NOT NULL,
    hora_fin     TIME         NOT NULL,
    estado       ENUM('pendiente', 'confirmada', 'cancelada') NOT NULL DEFAULT 'pendiente',
    creado_en    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT pk_reservaciones PRIMARY KEY (id),
    CONSTRAINT uq_espacio_horario UNIQUE (espacio, fecha, hora_inicio),
    CONSTRAINT chk_horario CHECK (hora_fin > hora_inicio),
    CONSTRAINT chk_espacio CHECK (CHAR_LENGTH(TRIM(espacio)) >= 3),
    CONSTRAINT chk_solicitante CHECK (CHAR_LENGTH(TRIM(solicitante)) >= 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reservaciones (espacio, solicitante, correo, fecha, hora_inicio, hora_fin, estado) VALUES
    ('Laboratorio de Cómputo 1', 'Ana López',     'ana.lopez@practica.local',     '2026-10-15', '08:00', '10:00', 'confirmada'),
    ('Auditorio Principal',      'Carlos Pérez',  'carlos.perez@practica.local',  '2026-10-16', '12:00', '14:00', 'pendiente'),
    ('Sala de Juntas A',         'María García',  'maria.garcia@practica.local',  '2026-10-17', '16:00', '17:30', 'cancelada');
