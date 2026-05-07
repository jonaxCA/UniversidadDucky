-- ═══════════════════════════════════════════════════════════════════════════════
--  Universidad Ducky — Esquema completo MariaDB 10.6+
--  Archivo único. Incluye: BD, tablas, datos iniciales e índices.
--
--  Uso directo (CLI):
--      mysql -u root -p < sql/universidad_ducky.sql
--
--  Uso desde phpMyAdmin:
--      Importar este archivo con charset utf8mb4.
--
--  El archivo es idempotente: puede ejecutarse varias veces sin errores.
-- ═══════════════════════════════════════════════════════════════════════════════

CREATE DATABASE IF NOT EXISTS universidad_ducky
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE universidad_ducky;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ─────────────────────────────────────────────────────────────────────────────
--  TABLAS  (orden respeta dependencias de FK)
-- ─────────────────────────────────────────────────────────────────────────────

-- ── 1. Usuarios del sistema ───────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS usuarios (
  id_usuario         BIGINT       NOT NULL AUTO_INCREMENT,
  nombre_completo    VARCHAR(150) NOT NULL,
  username           VARCHAR(50)  UNIQUE,
  email              VARCHAR(150) NOT NULL UNIQUE,
  telefono           VARCHAR(20),
  contrasena         VARCHAR(255) NOT NULL,
  tipo               ENUM('administrador','bibliotecario','profesor','alumno') NOT NULL,
  estado             ENUM('activo','suspendido','bloqueado') NOT NULL DEFAULT 'activo',
  matricula_empleado VARCHAR(20)  UNIQUE,
  creado_en          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. Catálogo bibliográfico ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS editoriales (
  id_editorial BIGINT       NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(150) NOT NULL,
  pais         VARCHAR(100),
  PRIMARY KEY (id_editorial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
  id_categoria BIGINT       NOT NULL AUTO_INCREMENT,
  nombre       VARCHAR(100) NOT NULL,
  PRIMARY KEY (id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS libros (
  id_libro            BIGINT        NOT NULL AUTO_INCREMENT,
  titulo              VARCHAR(255)  NOT NULL,
  autor               VARCHAR(150),
  isbn                VARCHAR(20)   UNIQUE,
  anio_publicacion    YEAR,
  ficha_bibliografica TEXT,
  descripcion         TEXT,
  precio_mxn          DECIMAL(10,2),
  imagen_url          VARCHAR(500),
  id_editorial        BIGINT,
  id_categoria        BIGINT,
  PRIMARY KEY (id_libro),
  CONSTRAINT fk_libro_editorial FOREIGN KEY (id_editorial) REFERENCES editoriales (id_editorial),
  CONSTRAINT fk_libro_categoria FOREIGN KEY (id_categoria) REFERENCES categorias  (id_categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. Inventario físico ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ejemplares (
  id_ejemplar               BIGINT       NOT NULL AUTO_INCREMENT,
  id_libro                  BIGINT       NOT NULL,
  codigo_inventario         VARCHAR(50)  UNIQUE,
  biblioteca                VARCHAR(50),
  ubicacion_pasillo_estante VARCHAR(100),
  disponible                ENUM('disponible','prestado','reservado','dañado','perdido','obsoleto')
                            NOT NULL DEFAULT 'disponible',
  precio_compra_usd         DECIMAL(10,2),
  fecha_adquisicion         DATE,
  PRIMARY KEY (id_ejemplar),
  CONSTRAINT fk_ejemplar_libro FOREIGN KEY (id_libro) REFERENCES libros (id_libro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 4. Adquisiciones ──────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS compras_libros (
  id_compra    BIGINT        NOT NULL AUTO_INCREMENT,
  proveedor    VARCHAR(150),
  factura      VARCHAR(100),
  fecha_compra DATE,
  monto_total  DECIMAL(10,2),
  PRIMARY KEY (id_compra)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS compras_detalle (
  id_detalle          BIGINT        NOT NULL AUTO_INCREMENT,
  id_compra           BIGINT,
  id_ejemplar         BIGINT,
  precio_unitario_usd DECIMAL(10,2),
  PRIMARY KEY (id_detalle),
  CONSTRAINT fk_detalle_compra   FOREIGN KEY (id_compra)   REFERENCES compras_libros (id_compra),
  CONSTRAINT fk_detalle_ejemplar FOREIGN KEY (id_ejemplar) REFERENCES ejemplares     (id_ejemplar)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 5. Préstamos ──────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS prestamos (
  id_prestamo         BIGINT       NOT NULL AUTO_INCREMENT,
  id_usuario          BIGINT       NOT NULL,
  id_ejemplar         BIGINT       NOT NULL,
  tipo                ENUM('interno','externo') NOT NULL DEFAULT 'externo',
  estado              ENUM('activo','vencido','devuelto','perdido') NOT NULL DEFAULT 'activo',
  fecha_salida        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_vencimiento   DATETIME     NOT NULL,
  fecha_devolucion    DATETIME     NULL,
  renovaciones_conteo INT          NOT NULL DEFAULT 0,
  autorizado_por      BIGINT,
  condicion_entrega   VARCHAR(255),
  condicion_retorno   VARCHAR(255),
  folio_recibo        VARCHAR(50)  UNIQUE,
  PRIMARY KEY (id_prestamo),
  CONSTRAINT fk_prestamo_usuario    FOREIGN KEY (id_usuario)     REFERENCES usuarios  (id_usuario),
  CONSTRAINT fk_prestamo_ejemplar   FOREIGN KEY (id_ejemplar)    REFERENCES ejemplares(id_ejemplar),
  CONSTRAINT fk_prestamo_autorizado FOREIGN KEY (autorizado_por) REFERENCES usuarios  (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 6. Multas ─────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS multas (
  id_multa              BIGINT        NOT NULL AUTO_INCREMENT,
  id_prestamo           BIGINT        NOT NULL,
  tipo_mora             VARCHAR(50),
  dias_retraso          INT,
  monto_por_dia         DECIMAL(10,2) NOT NULL DEFAULT 10.00,
  monto_total           DECIMAL(10,2),
  estado_pago           TINYINT(1)    NOT NULL DEFAULT 0,
  comprobante_tesoreria VARCHAR(100),
  creado_en             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_multa),
  CONSTRAINT fk_multa_prestamo FOREIGN KEY (id_prestamo) REFERENCES prestamos (id_prestamo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 7. Bitácora ISO 9001 ──────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS bitacora_iso_9001 (
  id_log              BIGINT    NOT NULL AUTO_INCREMENT,
  id_usuario_actor    BIGINT,
  accion              ENUM('crear','actualizar','borrar','prestamo','devolucion',
                           'renovacion','pago_multa') NOT NULL,
  entidad_afectada    VARCHAR(50),
  id_entidad_afectada BIGINT,
  detalle_cambio      JSON,
  ip_usuario          VARCHAR(50),
  modulo              VARCHAR(50),
  creado_en           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_log),
  CONSTRAINT fk_bitacora_usuario FOREIGN KEY (id_usuario_actor) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 8. Integraciones externas (Fase 2 — pendiente implementación) ─────────────
--    Control Escolar y Recursos Humanos requieren API/webhook institucional.
CREATE TABLE IF NOT EXISTS integracion_escolar (
  id_escolar            BIGINT      NOT NULL AUTO_INCREMENT,
  id_usuario            BIGINT      NOT NULL UNIQUE,
  matricula_escolar     VARCHAR(20) UNIQUE,
  carrera               VARCHAR(100),
  situacion_academica   VARCHAR(50) COMMENT 'Regular, Baja, Egresado',
  periodo_activo        TINYINT(1)  NOT NULL DEFAULT 1,
  ultima_sincronizacion TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_escolar),
  CONSTRAINT fk_escolar_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS integracion_rh (
  id_rh                 BIGINT      NOT NULL AUTO_INCREMENT,
  id_usuario            BIGINT      NOT NULL UNIQUE,
  numero_empleado       VARCHAR(20) UNIQUE,
  departamento          VARCHAR(100),
  puesto                VARCHAR(100),
  estado_laboral        VARCHAR(50) COMMENT 'Activo, Incapacidad, Baja',
  ultima_sincronizacion TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_rh),
  CONSTRAINT fk_rh_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--    Tesorería: cola de notificaciones hacia sistema externo.
CREATE TABLE IF NOT EXISTS sincronizacion_tesoreria (
  id_sinc                   BIGINT        NOT NULL AUTO_INCREMENT,
  tipo_operacion            VARCHAR(50)   COMMENT 'prestamo, devolucion, multa',
  id_referencia_local       BIGINT        COMMENT 'ID de prestamo o multa',
  folio_transaccion_externo VARCHAR(50)   UNIQUE,
  monto_reportado           DECIMAL(10,2),
  estado_notificacion       VARCHAR(20)   NOT NULL DEFAULT 'enviado',
  fecha_comunicacion        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_sinc),
  CONSTRAINT fk_tesoreria_multa FOREIGN KEY (id_referencia_local) REFERENCES multas (id_multa)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 9. Lista de espera ────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS lista_espera (
  id_espera   BIGINT    NOT NULL AUTO_INCREMENT,
  id_libro    BIGINT    NOT NULL,
  id_usuario  BIGINT    NOT NULL,
  fecha_alta  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  fecha_notif DATETIME  NULL,
  estado      ENUM('esperando','notificado','prestado','cancelado') NOT NULL DEFAULT 'esperando',
  PRIMARY KEY (id_espera),
  UNIQUE KEY uq_libro_usuario (id_libro, id_usuario),
  CONSTRAINT fk_espera_libro   FOREIGN KEY (id_libro)   REFERENCES libros   (id_libro)   ON DELETE CASCADE,
  CONSTRAINT fk_espera_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 10. Configuración del sistema ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS configuracion (
  clave       VARCHAR(100) NOT NULL,
  valor       TEXT,
  descripcion VARCHAR(255),
  PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 11. Calendario institucional (Fase 2 — pendiente implementación) ──────────
--    Días festivos para cálculo de vencimientos. Requiere integración en PHP.
CREATE TABLE IF NOT EXISTS calendario_institucional (
  id_fecha            BIGINT      NOT NULL AUTO_INCREMENT,
  fecha               DATE        NOT NULL UNIQUE,
  es_laboral          TINYINT(1)  NOT NULL DEFAULT 1,
  descripcion_festivo VARCHAR(100),
  PRIMARY KEY (id_fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 12. Recuperación de contraseña ───────────────────────────────────────────
CREATE TABLE IF NOT EXISTS password_resets (
  id         BIGINT    NOT NULL AUTO_INCREMENT,
  id_usuario BIGINT    NOT NULL,
  token      CHAR(64)  NOT NULL,
  expiry     DATETIME  NOT NULL,
  used       TINYINT(1) NOT NULL DEFAULT 0,
  creado_en  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_token (token),
  CONSTRAINT fk_reset_usuario FOREIGN KEY (id_usuario)
    REFERENCES usuarios (id_usuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ─────────────────────────────────────────────────────────────────────────────
--  DATOS INICIALES
-- ─────────────────────────────────────────────────────────────────────────────

INSERT IGNORE INTO configuracion (clave, valor, descripcion) VALUES
  ('dias_prestamo_alumno',        '7',                                    'Días de préstamo para alumnos'),
  ('dias_prestamo_profesor',      '14',                                   'Días de préstamo para profesores'),
  ('dias_prestamo_staff',         '14',                                   'Días de préstamo para administradores y bibliotecarios'),
  ('monto_multa_dia',             '10.00',                                'Multa por día de retraso (MXN)'),
  ('max_renovaciones',            '2',                                    'Máximo de renovaciones por préstamo'),
  ('nombre_institucion',          'Universidad Ducky',                    'Nombre de la institución'),
  ('slogan_institucion',          'Sistema Bibliotecario ISO 9001:2015',  'Slogan / subtítulo'),
  ('email_contacto',              'biblioteca@ducky.edu.mx',              'Correo de contacto de la biblioteca'),
  ('bibliotecas_disponibles',     'Estoa,CCU',                            'Nombres de las bibliotecas (separados por coma)'),
  ('costo_perdida_multiplicador', '20',                                   'Multiplicador del precio de compra para costo de libro perdido');

-- ─────────────────────────────────────────────────────────────────────────────
--  ÍNDICES DE RENDIMIENTO
-- ─────────────────────────────────────────────────────────────────────────────

-- prestamos
CREATE INDEX IF NOT EXISTS idx_prestamos_usuario     ON prestamos (id_usuario);
CREATE INDEX IF NOT EXISTS idx_prestamos_ejemplar    ON prestamos (id_ejemplar);
CREATE INDEX IF NOT EXISTS idx_prestamos_estado      ON prestamos (estado);
CREATE INDEX IF NOT EXISTS idx_prestamos_vencimiento ON prestamos (estado, fecha_vencimiento);

-- multas
CREATE INDEX IF NOT EXISTS idx_multas_prestamo       ON multas (id_prestamo);
CREATE INDEX IF NOT EXISTS idx_multas_estado_pago    ON multas (estado_pago);

-- ejemplares
CREATE INDEX IF NOT EXISTS idx_ejemplares_libro_disp ON ejemplares (id_libro, disponible);

-- bitácora ISO 9001
CREATE INDEX IF NOT EXISTS idx_bitacora_entidad      ON bitacora_iso_9001 (entidad_afectada, id_entidad_afectada);
CREATE INDEX IF NOT EXISTS idx_bitacora_actor        ON bitacora_iso_9001 (id_usuario_actor);
CREATE INDEX IF NOT EXISTS idx_bitacora_modulo       ON bitacora_iso_9001 (modulo, creado_en);

-- lista de espera
CREATE INDEX IF NOT EXISTS idx_espera_libro_estado   ON lista_espera (id_libro, estado);
