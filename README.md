# UEICEE · MAPA · GEOCOD

Sistema de geocodificación de direcciones de CABA desarrollado en PHP nativo + PostgreSQL.
Consume la API pública de IDECABA para normalizar direcciones, obtener coordenadas GKBA,
datos útiles (comuna, barrio, CP, etc.) y transformar a WGS84.
Los resultados se exportan en formato Excel (.xlsx) con coordenadas correctamente formateadas.

---

## Requisitos

- XAMPP con PHP 8.x y extensiones `pgsql` y `curl` habilitadas
- PostgreSQL local (puerto 5432)
- Composer (para instalar PhpSpreadsheet)
- Acceso a la API de IDECABA (credenciales propias)

---

## Instalación

### 1. Clonar el repositorio

```bash
git clone https://github.com/gitmapa/geocod.git
cd geocod
```

### 2. Instalar dependencias PHP

```bash
composer install
```

Esto genera la carpeta `vendor/` con PhpSpreadsheet.

### 3. Configurar credenciales

Copiá los archivos de ejemplo y completá con tus datos reales:

```bash
cp config/api_config.example.php config/api_config.php
cp config/db_config.example.php  config/db_config.php
```

Editá `config/api_config.php` con tu `client_id` y `client_secret` de IDECABA.
Editá `config/db_config.php` con los datos de tu PostgreSQL local.

> ⚠️ Estos archivos están en `.gitignore` y **nunca deben subirse al repositorio**.

### 4. Crear las tablas en PostgreSQL

Ejecutá los scripts SQL incluidos en la carpeta `sql/` en este orden:

```
sql/00_esquemas.sql
sql/01_tablas_config.sql
sql/02_tablas_geo_config.sql
sql/03_tabla_geo_plantilla.sql
```

Si estás migrando desde v1.0, ejecutar también:

```
sql/04_migracion_v1_1.sql
```

### 5. Verificar instalación

Abrí en el navegador:

```
http://localhost/geocod/selftest.php
```

Deberías ver todos los chequeos en verde: extensiones PHP, PhpSpreadsheet, conexión a la base, tablas de configuración y API de IDECABA.

---

## Estructura del proyecto

```
geocod/
├── index.php                    # Interfaz principal (4 pestañas)
├── descargar.php                # Endpoint de descarga Excel (.xlsx)
├── selftest.php                 # Diagnóstico del sistema
├── composer.json                # Dependencias PHP
│
├── config/
│   ├── api_config.php           # Credenciales API (NO en repo)
│   ├── api_config.example.php   # Plantilla de ejemplo
│   ├── db_config.php            # Credenciales DB (NO en repo)
│   └── db_config.example.php    # Plantilla de ejemplo
│
├── lib/
│   ├── api_idecaba.php          # Wrapper de llamadas a la API IDECABA
│   ├── db.php                   # Conexión PostgreSQL y helpers
│   ├── geocoder_engine.php      # Motor principal de geocodificación
│   ├── parser_direcciones.php   # Parser de direcciones crudas
│   └── tables_config.php        # Abstracción de geocod.tablas_config
│
├── sql/
│   ├── 00_esquemas.sql          # Creación de esquemas geocod y geopedidos
│   ├── 01_tablas_config.sql     # Tabla de configuración de tablas geocodificables
│   ├── 02_tablas_geo_config.sql # Tabla de tracking del ciclo de vida
│   ├── 03_tabla_geo_plantilla.sql # Plantilla base para tablas _geo
│   └── 04_migracion_v1_1.sql   # Migración desde v1.0
│
└── vendor/                      # Dependencias Composer (NO en repo)
```

---

## Flujo de uso

1. **ABM → Subir listado**: cargá un CSV con columnas `id_reg` y `direccion_raw`
2. **ABM → Crear tabla geo**: parsea las direcciones en calle + altura
3. **ABM → Enviar a geocod**: copia la tabla al esquema `geocod` y la registra
4. **Geocodificar → Ejecutar**: llama a la API IDECABA (geocoder + datos útiles + WGS84)
5. **Descargas**: descargá el resultado en Excel, con fuente original o joineado con datos geo

---

## Estados del proceso

| Estado | Descripción |
|---|---|
| `POBLADA` | Tabla geo creada, lista para enviar a geocod |
| `ENVIADA` | Copiada a geocod, lista para geocodificar |
| `GEOCODIFICADA` | Procesada por el motor — disponible en Descargas |
| `BORRADA` | La tabla física fue eliminada |

---

## Versiones

| Versión | Descripción |
|---|---|
| v1.0 | Versión inicial |
| v1.1 | Mejoras de UI, carga de CSV, descargas Excel, chequeo de API, refactor |

---

## Notas de seguridad

- Las credenciales reales **nunca** se suben al repositorio
- Los archivos `config/*.php` (reales) están excluidos por `.gitignore`
- Las queries usan parámetros preparados para prevenir SQL injection
- Los nombres de tabla se validan con regex antes de interpolarse en queries

---

## Para desarrolladores

Stack: PHP 8.x nativo · PostgreSQL · PhpSpreadsheet · API IDECABA (datos abiertos GCBA) · Bootstrap 5

Repo: [github.com/gitmapa/geocod](https://github.com/gitmapa/geocod)
