# Parcial 2 práctico — Despliegue multi-contenedor y modelo OSI

Stack de 5 servicios orquestados con Docker Compose, detrás de un único proxy inverso Nginx:

| Servicio | Imagen | Ruta pública |
|---|---|---|
| nginx | `nginx:alpine` | puerto **80** (único expuesto) |
| joomla | `joomla:latest` | http://localhost/ |
| database | `postgres:16-alpine` | — (solo red interna `backend_net`) |
| jupyter | `jupyter/minimal-notebook` | http://localhost/jupyter/ |
| grafana | `grafana/grafana:latest` | http://localhost/grafana/ |

## Despliegue rápido (Zero-Touch)

Requisitos: Docker Engine / Docker Desktop con Compose v2 y el puerto 80 libre.

```bash
git clone <URL_DEL_REPO> parcial2
cd parcial2
cp .env.example .env
docker compose up -d
```

En Windows PowerShell el paso de copia es `Copy-Item .env.example .env`.

Espera de 1 a 3 minutos en el primer arranque (Joomla se instala solo y Jupyter instala sus librerías) y comprueba:

```bash
docker compose ps    # los 5 servicios en estado "healthy"
```

## Accesos

| Servicio | URL | Credenciales |
|---|---|---|
| Joomla (sitio) | http://localhost/ | — |
| Joomla (admin) | http://localhost/administrator | `admin` / `AdminParcial2026!` (ver `.env`) |
| JupyterLab | http://localhost/jupyter/ | sin token → abrir `work/analisis_datos.ipynb` |
| Grafana | http://localhost/grafana/ | anónimo (solo lectura); admin: `admin` / `admin` |

## Estructura

```
.
├── .env.example                 # variables (PostgreSQL, Joomla, Grafana)
├── docker-compose.yml           # 5 servicios, 2 redes bridge, healthchecks, volúmenes
├── INFORME.md                   # topología + análisis OSI + guía de verificación
├── nginx/default.conf           # proxy inverso (/, /jupyter/, /grafana/) + WebSockets
├── jupyter/notebooks/analisis_datos.ipynb   # SQLAlchemy/psycopg2 + pandas/matplotlib
└── grafana/provisioning/
    ├── datasources/datasource.yml           # PostgreSQL (database:5432) automático
    └── dashboards/{dashboard.yml, joomla_logs.json}  # dashboard con 8 paneles
```

## Comandos útiles

```bash
docker compose logs -f <servicio>   # ver logs
docker compose down                 # detener (conserva datos)
docker compose down -v              # detener y borrar volúmenes (reinstalación limpia)
```

El análisis completo de redes y del modelo OSI está en [INFORME.md](INFORME.md).
