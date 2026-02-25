-- =============================================================
-- sql/03_tabla_geo_plantilla.sql
-- Plantilla base para todas las tablas _geo del sistema.
-- Cada pedido geocodificable genera una tabla con esta estructura
-- usando CREATE TABLE <nueva> (LIKE geocod.tabla_geo_plantilla INCLUDING ALL).
-- -------------------------------------------------------------
-- Versión : 1.0
-- =============================================================

-- Secuencia para el idgeo autoincrementable
CREATE SEQUENCE IF NOT EXISTS geocod.tabla_geo_plantilla_idgeo_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;

CREATE TABLE IF NOT EXISTS geocod.tabla_geo_plantilla
(
    -- ----------------------------------------------------------
    -- Identificación y datos originales del pedido
    -- ----------------------------------------------------------
    idgeo            integer NOT NULL DEFAULT nextval('geocod.tabla_geo_plantilla_idgeo_seq'::regclass),
    pedido           text,                      -- Nombre de la tabla pedido de origen
    calle_original   text,                      -- Calle parseada de la dirección cruda
    altura_original  text,                      -- Altura parseada de la dirección cruda

    -- ----------------------------------------------------------
    -- Resultados del geocoder IDECABA
    -- ----------------------------------------------------------
    direccion_normalizada    text,              -- Dirección normalizada por la API
    tipo_direccion           text,              -- Tipo de dirección (ej: ALTURA, ESQUINA)
    tipo_catastro            text,              -- Tipo catastral
    cod_calle_1              text,              -- Código de calle principal
    cod_calle_2              text,              -- Código de calle secundaria (en esquinas)
    nombre_calle_1           text,              -- Nombre normalizado calle principal
    nombre_calle_2           text,              -- Nombre normalizado calle secundaria
    altura_normalizada       text,              -- Altura normalizada por la API
    coordenada_x_gkba        numeric,           -- Coordenada X en sistema GKBA
    coordenada_y_gkba        numeric,           -- Coordenada Y en sistema GKBA
    metodo_geocodificacion   text,              -- Método usado por el geocoder
    smp                      text,              -- Sección / Manzana / Parcela catastral

    -- ----------------------------------------------------------
    -- Datos catastrales del geocoder
    -- ----------------------------------------------------------
    barrio_inf       text,                      -- Barrio informado por el geocoder
    sector_inf       text,                      -- Sector catastral
    manzana_inf      text,                      -- Manzana catastral
    parcela_inf      text,                      -- Parcela catastral

    -- ----------------------------------------------------------
    -- Resultados de datos útiles IDECABA
    -- ----------------------------------------------------------
    comuna                   text,              -- Comuna de CABA
    barrio                   text,              -- Barrio (datos útiles)
    distrito_escolar         text,              -- Distrito escolar
    comisaria                text,              -- Comisaría
    comisaria_vecinal        text,              -- Comisaría vecinal
    area_hospitalaria        text,              -- Área hospitalaria
    region_sanitaria         text,              -- Región sanitaria
    seccion                  text,              -- Sección electoral
    codigo_postal            text,              -- Código postal numérico
    codigo_postal_argentino  text,              -- Código postal argentino (CPA)

    -- ----------------------------------------------------------
    -- Coordenadas WGS84 (resultado de transformación)
    -- ----------------------------------------------------------
    latitud_wgs84    numeric,                   -- Latitud en sistema WGS84
    longitud_wgs84   numeric,                   -- Longitud en sistema WGS84

    -- ----------------------------------------------------------
    -- Control del proceso de geocodificación
    -- ----------------------------------------------------------
    estado_proceso   text,                      -- Estado: OK, ERROR_API_GEOCODER, etc.
    mensaje_error    text,                      -- Detalle del error si corresponde
    fecha_procesado  timestamp,                 -- Fecha y hora del último procesamiento

    CONSTRAINT tabla_geo_plantilla_pkey PRIMARY KEY (idgeo)
);

COMMENT ON TABLE geocod.tabla_geo_plantilla IS 'Plantilla base para todas las tablas _geo. No se usa directamente, se copia con LIKE INCLUDING ALL';