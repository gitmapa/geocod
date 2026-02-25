-- =============================================================
-- sql/00_esquemas.sql
-- Creación de los esquemas base del sistema de geocodificación.
-- Debe ejecutarse PRIMERO, antes que cualquier otro script SQL.
-- -------------------------------------------------------------
-- Esquemas creados:
--   - geocod    : tablas de configuración, plantilla y resultados
--   - geopedidos: tablas de pedidos originales con direcciones crudas
-- -------------------------------------------------------------
-- Versión : 1.0
-- =============================================================

-- Esquema principal del sistema de geocodificación
CREATE SCHEMA IF NOT EXISTS geocod;

COMMENT ON SCHEMA geocod     IS 'Esquema principal del sistema: configuración, plantilla y tablas geocodificadas';

-- Esquema donde se alojan los pedidos originales con direcciones crudas
CREATE SCHEMA IF NOT EXISTS geopedidos;

COMMENT ON SCHEMA geopedidos IS 'Esquema de pedidos: tablas originales con direcciones crudas y sus tablas _geo intermedias';
```