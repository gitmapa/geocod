-- =============================================================
-- sql/02_tablas_geo_config.sql
-- Tabla de tracking del ciclo de vida de cada tabla geo.
-- Registra el estado de cada pedido desde su creación
-- hasta que es geocodificado o borrado.
-- -------------------------------------------------------------
-- Versión : 1.0
-- =============================================================

CREATE TABLE IF NOT EXISTS geocod.tablas_geo_config
(
    id_tabla       integer   NOT NULL DEFAULT nextval('geocod.tablas_geo_config_id_tabla_seq'::regclass),
    nombre_pedido  text      NOT NULL,
    tabla_geo      text      NOT NULL,
    esquema_origen text      NOT NULL DEFAULT 'geopedidos'::text,
    fecha_creado   timestamp NOT NULL DEFAULT now(),
    estado         text      NOT NULL DEFAULT 'CREADA'::text,

    CONSTRAINT tablas_geo_config_pkey PRIMARY KEY (id_tabla)
);

COMMENT ON TABLE  geocod.tablas_geo_config                IS 'Tracking del ciclo de vida de cada tabla geo creada';
COMMENT ON COLUMN geocod.tablas_geo_config.nombre_pedido  IS 'Nombre de la tabla original en geopedidos';
COMMENT ON COLUMN geocod.tablas_geo_config.tabla_geo      IS 'Nombre de la tabla geo generada (<pedido>_geo)';
COMMENT ON COLUMN geocod.tablas_geo_config.esquema_origen IS 'Esquema donde vive la tabla original del pedido';
COMMENT ON COLUMN geocod.tablas_geo_config.fecha_creado   IS 'Fecha y hora de creación del registro';
COMMENT ON COLUMN geocod.tablas_geo_config.estado         IS 'Estado actual: CREADA, POBLADA, ENVIADA, BORRADA';