-- =============================================================
-- sql/01_tablas_config.sql
-- Tabla de configuración de tablas geocodificables.
-- Registra qué tablas están disponibles para el motor de geocod.
-- -------------------------------------------------------------
-- Versión : 1.0
-- =============================================================

CREATE TABLE IF NOT EXISTS geocod.tablas_config
(
    id_tabla_config integer      NOT NULL DEFAULT nextval('geocod.tablas_config_id_tabla_config_seq'::regclass),
    nombre_config   text         NOT NULL,
    esquema         text         NOT NULL,
    tabla           text         NOT NULL,
    descripcion     text,

    CONSTRAINT tablas_config_pkey             PRIMARY KEY (id_tabla_config),
    CONSTRAINT tablas_config_nombre_config_key UNIQUE      (nombre_config)
);

COMMENT ON TABLE  geocod.tablas_config                IS 'Registro de tablas habilitadas para geocodificación';
COMMENT ON COLUMN geocod.tablas_config.nombre_config  IS 'Nombre lógico único que identifica la tabla en el sistema';
COMMENT ON COLUMN geocod.tablas_config.esquema        IS 'Esquema PostgreSQL donde vive la tabla física';
COMMENT ON COLUMN geocod.tablas_config.tabla          IS 'Nombre físico de la tabla dentro del esquema';
COMMENT ON COLUMN geocod.tablas_config.descripcion    IS 'Descripción libre del pedido o propósito de la tabla';