-- =============================================================
-- sql/04_migracion_v1_1.sql
-- Migración de base de datos para la versión 1.1
-- -------------------------------------------------------------
-- Cambios:
--   - Agrega el estado 'GEOCODIFICADA' como valor válido en
--     geocod.tablas_geo_config. En versiones anteriores el
--     estado máximo era 'ENVIADA'; ahora una tabla procesada
--     por el motor pasa a 'GEOCODIFICADA' y deja de aparecer
--     en el selector de Geocodificar.
-- -------------------------------------------------------------
-- Este script es idempotente: puede ejecutarse más de una vez
-- sin causar errores.
-- -------------------------------------------------------------
-- Versión : 1.1
-- =============================================================

-- No hay cambios de estructura de tabla necesarios ya que el
-- campo 'estado' es TEXT y acepta cualquier valor.
-- Este script actualiza registros existentes que quedaron en
-- estado intermedio y agrega un comentario actualizado.

-- Actualizar tablas que ya fueron procesadas por el motor
-- (tienen filas con estado_proceso = 'OK') pero cuyo registro
-- en tablas_geo_config todavía dice 'ENVIADA'.
-- Esto es útil para sincronizar instalaciones que venían de v1.0.
UPDATE geocod.tablas_geo_config tgc
SET estado = 'GEOCODIFICADA'
WHERE tgc.estado = 'ENVIADA'
  AND EXISTS (
      SELECT 1
      FROM geocod.tablas_config tc
      WHERE tc.nombre_config = tgc.tabla_geo
  )
  AND EXISTS (
      -- Verificar que la tabla geo tenga al menos una fila procesada
      SELECT 1
      FROM information_schema.tables t
      WHERE t.table_schema = 'geocod'
        AND t.table_name   = tgc.tabla_geo
  );

-- Comentario actualizado en la columna estado
COMMENT ON COLUMN geocod.tablas_geo_config.estado IS
    'Estado del ciclo de vida: CREADA, POBLADA, ENVIADA, GEOCODIFICADA, BORRADA';
