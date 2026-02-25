# Sistema de Geocodificación IDECABA

Sistema de geocodificación de direcciones de CABA desarrollado en PHP nativo + PostgreSQL.
Consume la API pública de IDECABA para normalizar direcciones, obtener coordenadas GKBA,
datos útiles (comuna, barrio, CP, etc.) y transformar a WGS84.

---

## Requisitos

- XAMPP con PHP 8.x y extensión `pgsql` habilitada
- PostgreSQL local (puerto 5432)
- Acceso a la API de IDECABA (credenciales propias)

---

## Instalación

### 1. Clonar el repositorio
```bash
git clone https://github.com/gitmapa/geocod.git
cd geocod
```

### 2. Configurar credenciales

Copiá los archivos de ejemplo y completá con tus datos reales:
```bash
cp config/api_config.example.php config/api_config.php
cp config/db_config.example.php  config/db_config.php
```

Editá `config/api_config.php` con tu `client_id` y `client_secret` de IDECABA.
Editá `config/db_config.php` con los datos de tu PostgreSQL local.

> ⚠️ Estos archivos están en `.gitignore` y **nunca deben subirse al repositorio**.

### 3. Crear las tablas en PostgreSQL

Ejecutá los scripts SQL incluidos en la carpeta `sql/` (en orden):
```
sql/01_tablas_config.sql
sql/02_tablas_geo_config.sql
sql/03_tabla_geo_plantilla.sql
```

### 4. Verificar instalación

Abrí en el navegador:
```
http://localhost/geocod/selftest.php
```

Deberías ver confirmación de conexión a la base y respuesta de la API.

---

## Estructura del proyecto
```
geocod/
├── index.php                   # Interfaz principal (3 pestañas)
├── selftest.php                # Test de conexión y API
│
├── config/
│   ├── api_config.php          # Credenciales API (NO en repo)
│   ├── api_config.example.php  # Plantilla de ejemplo
│   ├── db_config.php           # Credenciales DB (NO en repo)
│   └── db_config.example.php   # Plantilla de ejemplo
│
└── lib/
    ├── api_idecaba.php         # Wrapper de llamadas a la API IDECABA
    ├── db.php                  # Conexión PostgreSQL y helpers
    ├── geocoder_engine.php     # Motor principal de geocodificación
    ├── parser_direcciones.php  # Parser de direcciones crudas
    ├── report_utils.php        # Render de reportes en la UI
    └── tables_config.php       # Abstracción de geocod.tablas_config
```

---

## Flujo de uso

1. **Cargar pedido**: la tabla del pedido debe estar en el esquema `geopedidos` con columnas `id_reg` y `direccion_raw`
2. **ABM → Crear tabla geo**: parsea las direcciones y genera `geopedidos.<pedido>_geo`
3. **ABM → Enviar a geocod**: copia la tabla a `geocod.<pedido>_geo` y la registra para procesamiento
4. **Geocodificar → Ejecutar**: llama a la API IDECABA y completa todos los campos de la tabla geo
5. **Resultado**: disponible en `geocod.<pedido>_geo`, unible con la tabla original por `id_reg = idgeo`

---

## Estados del proceso

| Estado | Descripción |
|---|---|
| `POBLADA` | Tabla geo creada y con datos, lista para enviar |
| `ENVIADA` | Copiada a esquema `geocod`, lista para geocodificar |
| `OK` | Geocodificación completada exitosamente |
| `ERROR_API_GEOCODER` | Falló la llamada al geocoder de IDECABA |
| `ERROR_API_WGS84` | Falló la transformación de coordenadas a WGS84 |
| `SIN_COORDENADAS_GKBA` | El geocoder no devolvió coordenadas |
| `BORRADA` | La tabla física fue eliminada |

---

## Versiones

| Versión | Descripción |
|---|---|
| v1.0 | Versión inicial |
| v1.1 | En desarrollo — mejoras de funcionamiento, descargas, refactor y comentarios |

---

## Notas de seguridad

- Las credenciales reales **nunca** se suben al repositorio
- Los archivos `config/*.php` (reales) están excluidos por `.gitignore`
- Las queries usan parámetros preparados para prevenir SQL injection