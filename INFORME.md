# INFORME — Parcial 2 práctico

## Despliegue multi-contenedor, orquestación, arquitectura y análisis del modelo OSI

| Elemento | Valor |
|---|---|
| Orquestador | Docker Compose v2 (`docker compose`) |
| Servicios | `nginx`, `joomla`, `database`, `jupyter`, `grafana` |
| Redes | `frontend_net` (172.28.1.0/24), `backend_net` (172.28.2.0/24) — driver `bridge` |
| Volúmenes | `joomla_data`, `db_data`, `grafana_data` + bind mounts de configuración |
| Punto de entrada único | `http://localhost:80` (Nginx) |
| Modo de despliegue | Zero-Touch: `cp .env.example .env && docker compose up -d` |

---

## Sección 1 — Topología y flujo de información

### 1.1 Diagrama de red

```
                           ┌──────────────────────────────┐
                           │     HOST (Docker Engine)      │
   Navegador / curl ──────►│  0.0.0.0:80  ──DNAT (iptables)│
                           └──────────────┬───────────────┘
                                          │ docker-proxy / regla DOCKER
                                          ▼
 ═══════════════════════ frontend_net  172.28.1.0/24  (bridge br-xxxx, gw 172.28.1.1) ═══════════════════════
        │                         │                           │                          │
 ┌──────┴───────┐        ┌────────┴────────┐         ┌────────┴────────┐        ┌────────┴────────┐
 │    nginx     │        │     joomla      │         │     jupyter     │        │     grafana     │
 │ 172.28.1.10  │──────► │ 172.28.1.20:80  │         │ 172.28.1.40:8888│        │ 172.28.1.50:3000│
 │ :80 (publ.)  │──────────────────────────────────► │                 │        │                 │
 │              │────────────────────────────────────────────────────────────────►│                 │
 └──────────────┘        │ 172.28.2.20     │         │ 172.28.2.40     │        │ 172.28.2.50     │
                         └────────┬────────┘         └────────┬────────┘        └────────┬────────┘
                                  │ TCP 5432                  │ TCP 5432                  │ TCP 5432
 ═══════════════════════ backend_net   172.28.2.0/24  (bridge br-yyyy, gw 172.28.2.1) ═══════════════════════
                                  │                           │                           │
                                  └───────────────┬───────────┴───────────────────────────┘
                                                  ▼
                                       ┌─────────────────────┐
                                       │      database       │
                                       │  PostgreSQL 16      │
                                       │  172.28.2.30:5432   │
                                       │  (SIN puertos host) │
                                       └─────────────────────┘
```

### 1.2 Tabla de direccionamiento

| Contenedor | Imagen | frontend_net | backend_net | Puerto interno | Publicado en host |
|---|---|---|---|---|---|
| nginx | `nginx:alpine` | 172.28.1.10 | — | 80/tcp | **80:80** |
| joomla | `joomla:latest` | 172.28.1.20 | 172.28.2.20 | 80/tcp | no |
| database | `postgres:16-alpine` | — | 172.28.2.30 | 5432/tcp | **no** |
| jupyter | `jupyter/minimal-notebook` | 172.28.1.40 | 172.28.2.40 | 8888/tcp | no |
| grafana | `grafana/grafana:latest` | 172.28.1.50 | 172.28.2.50 | 3000/tcp | no |

> **Decisión de diseño:** `jupyter` se conecta también a `backend_net`. Sin esa segunda interfaz el
> notebook `analisis_datos.ipynb` no podría alcanzar a `database`, que por requisito vive **solo**
> en `backend_net`. Nginx, en cambio, está únicamente en `frontend_net`: el proxy nunca puede
> hablar con la base de datos.

### 1.3 Flujos de información

| # | Flujo | Camino | Protocolos |
|---|---|---|---|
| F1 | Visita web a Joomla | Cliente → host:80 → nginx → joomla:80 → database:5432 | HTTP/1.1 → HTTP/1.1 (keep-alive upstream) → protocolo v3 de PostgreSQL |
| F2 | Notebook | Cliente → nginx `/jupyter/` → jupyter:8888 (REST + **WebSocket** al kernel) → database:5432 (psycopg2) | HTTP Upgrade → WS; PG wire |
| F3 | Dashboard | Cliente → nginx `/grafana/` → grafana:3000 → database:5432 (pool de conexiones del datasource) | HTTP/JSON; PG wire |
| F4 | Logs | Acciones de usuarios en Joomla → tabla `jos_action_logs` (+ `jos_users`) → consultadas por Grafana (panel "Logs de acciones") y por Jupyter | SQL |
| F5 | Healthchecks | Docker Engine ejecuta `pg_isready`, `curl`, `wget`, `python` dentro de cada contenedor; `depends_on: condition: service_healthy` ordena el arranque | local / loopback |

Orden de arranque resultante (grafo de dependencias):

```
database (healthy) ──► joomla (auto-instalación, healthy) ──┐
                   ├─► jupyter (pip + servidor, healthy) ───┼──► nginx (healthy)
                   └─► grafana (provisioning, healthy) ─────┘
```

---

## Sección 2 — Análisis detallado del modelo OSI

### Capa 7 — Aplicación

**HTTP en Nginx (proxy inverso).** Nginx termina la conexión HTTP del cliente y abre una nueva
hacia el backend (`proxy_pass`). Para que el backend conozca el contexto original se inyectan:

| Cabecera | Valor | Propósito |
|---|---|---|
| `Host` | `$host` | Joomla/Grafana generan URLs absolutas y redirecciones con el host real (`localhost`), no con `joomla:80`. |
| `X-Real-IP` | `$remote_addr` | IP del cliente real (si no, el backend vería siempre 172.28.1.10). Joomla puede guardarla en `jos_action_logs.ip_address` si se activa *Registro de IP* en las opciones de "Registro de acciones de usuario" (por defecto viene desactivado y guarda `COM_ACTIONLOGS_DISABLED`). |
| `X-Forwarded-For` | `$proxy_add_x_forwarded_for` | Cadena de proxies atravesados (RFC 7239 de facto). |
| `X-Forwarded-Proto` | `$scheme` | Indica si el cliente usó `http` o `https`; evita bucles de redirección y contenido mixto. |

El enrutamiento se hace por **prefijo de URI** (`location /`, `/jupyter/`, `/grafana/`): es un
*virtual hosting por ruta*, puramente de Capa 7. Por eso Jupyter se lanza con
`--ServerApp.base_url=/jupyter/` y Grafana con `GF_SERVER_SERVE_FROM_SUB_PATH=true` +
`GF_SERVER_ROOT_URL=.../grafana/`: las aplicaciones deben saber que viven bajo un subpath.

**HTTP Upgrade / WebSockets en Jupyter.** La ejecución de celdas viaja por un WebSocket
(`/jupyter/api/kernels/<id>/channels`). El handshake (RFC 6455) es:

```
GET /jupyter/api/kernels/…/channels HTTP/1.1
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==
Sec-WebSocket-Version: 13

HTTP/1.1 101 Switching Protocols
Upgrade: websocket
Connection: Upgrade
Sec-WebSocket-Accept: s3pPLMBiTxaQ9kYGzzhZRbK+xOo=
```

`Upgrade` y `Connection` son cabeceras *hop-by-hop*: un proxy **no** las reenvía por defecto. Por
eso `nginx/default.conf` usa `proxy_http_version 1.1` y fija explícitamente
`proxy_set_header Upgrade $http_upgrade; proxy_set_header Connection "Upgrade";`, además de
`proxy_read_timeout 86400` para que el túnel no se cierre a los 60 s por inactividad.

**Protocolo cliente/servidor de PostgreSQL (Frontend/Backend Protocol v3).** Joomla (PDO pgsql),
Grafana (driver Go `pgx`) y Jupyter (`psycopg2`/libpq) hablan el mismo protocolo binario sobre TCP:

1. `StartupMessage` (versión 3.0, `user=joomlauser`, `database=joomladb`).
2. `AuthenticationSASL` → intercambio **SCRAM-SHA-256** (método por defecto en PG 16) → `AuthenticationOk`.
3. `ParameterStatus`, `BackendKeyData`, `ReadyForQuery`.
4. Consultas: *simple query* (`Q`) o *extended query* (`Parse`/`Bind`/`Execute`/`Sync`, usada por las sentencias preparadas de PDO).
5. Respuestas: `RowDescription`, `DataRow`…, `CommandComplete`, `ReadyForQuery`; cierre con `Terminate` (`X`).

El healthcheck `pg_isready -U $POSTGRES_USER -d $POSTGRES_DB` envía precisamente un intento de
conexión de este protocolo y devuelve 0 si el servidor acepta conexiones.

### Capa 6 y 5 — Presentación y Sesión (breve)

- **Presentación:** codificación UTF-8 (`client_encoding`), JSON en las APIs de Grafana/Jupyter,
  compresión `gzip` opcional; TLS no se usa dentro de la red interna (`sslmode=disable`), lo que es
  aceptable porque `backend_net` no es accesible desde el host.
- **Sesión:** cookies de sesión de Joomla y Grafana, sesiones de kernel de Jupyter, y la sesión
  autenticada de PostgreSQL que persiste mientras la conexión TCP siga abierta.

### Capa 4 — Transporte

| Puerto | Servicio | Socket de escucha | Quién se conecta |
|---|---|---|---|
| 80/tcp | nginx | `0.0.0.0:80` (publicado en el host) | Clientes externos |
| 80/tcp | joomla (Apache) | `172.28.1.20:80` | nginx |
| 8888/tcp | jupyter | `172.28.1.40:8888` | nginx |
| 3000/tcp | grafana | `172.28.1.50:3000` | nginx |
| 5432/tcp | database | `172.28.2.30:5432` | joomla, jupyter, grafana |

- **Multiplexación por puertos:** cada conexión se identifica por la 4-tupla
  `(IP origen, puerto origen efímero, IP destino, puerto destino)`. Nginx abre conexiones desde
  `172.28.1.10:3xxxx–6xxxx` (rango efímero de Linux 32768–60999) hacia tres destinos distintos sin
  colisión. Del mismo modo, `database:5432` atiende simultáneamente a tres clientes; PostgreSQL crea
  un proceso *backend* por conexión (se ven en `pg_stat_activity`).
- **Handshake y fiabilidad:** todas las comunicaciones son TCP (SYN, SYN-ACK, ACK; control de flujo
  con ventana deslizante; retransmisiones). No hay tráfico UDP de aplicación salvo el DNS interno.
- **Keep-alive HTTP (upstream):** los bloques `upstream { … keepalive 16; }` junto con
  `proxy_http_version 1.1` y `Connection ""` (para Joomla) permiten **reutilizar** conexiones TCP
  nginx→backend, evitando un handshake de 3 vías por cada petición.
- **TCP keep-alive (nivel de socket):** PostgreSQL usa `tcp_keepalives_idle/interval/count` (por
  defecto los del kernel: 7200 s) para detectar clientes caídos; los WebSockets de Jupyter se
  mantienen con *ping/pong* de aplicación y el `proxy_read_timeout` ampliado.
- **Connection pooling:** Grafana mantiene un pool por datasource (`maxOpenConns: 10`,
  `maxIdleConns: 5`, `connMaxLifetime: 14400` en `datasource.yml`); el notebook usa el pool de
  SQLAlchemy (`pool_size=5, pool_pre_ping=True`). El pooling amortiza el coste del handshake TCP +
  SCRAM y limita los procesos backend de PostgreSQL. Joomla (PHP) abre una conexión por petición.

### Capa 3 — Red

- **Direccionamiento IP:** cada red bridge recibe una subred /24 definida en `ipam` y su gateway
  `.1` (la IP del propio puente en el host). Los contenedores multi-red (`joomla`, `jupyter`,
  `grafana`) tienen **dos interfaces** (`eth0`, `eth1`) y por tanto dos IPs; la ruta por defecto
  apunta al gateway de una de ellas. Las IPs se fijaron con `ipv4_address` para que este informe sea
  reproducible.
- **Aislamiento `frontend_net` / `backend_net`:** son dos puentes Linux diferentes y Docker instala
  reglas (`DOCKER-ISOLATION-STAGE-1/2`, o en versiones recientes `DOCKER-FORWARD`/`DOCKER-INTERNAL`)
  que **descartan el reenvío entre puentes**. Consecuencias:
  - `nginx` (solo frontend) **no tiene ruta** a 172.28.2.0/24 ni puede resolver `database`.
  - `database` (solo backend) es inalcanzable desde el proxy y desde el host (no hay `ports:`).
  - Solo los contenedores que tienen interfaz en ambas redes actúan como frontera de aplicación;
    no reenvían paquetes IP (no son routers: `ip_forward` dentro del contenedor está desactivado).
- **DNS embebido de Docker (`127.0.0.11`):** en redes definidas por el usuario, el
  `/etc/resolv.conf` de cada contenedor apunta a `nameserver 127.0.0.11`. Docker intercepta esas
  consultas (reglas iptables en el *namespace* del contenedor que redirigen 53/udp-tcp a un puerto
  aleatorio del daemon) y responde con la IP del servicio **en la red compartida con quien
  pregunta**. Así `joomla` resuelve `database` → 172.28.2.30, mientras que `nginx` recibe
  `NXDOMAIN`. Las consultas externas se reenvían al DNS del host.
- **NAT e iptables:**
  - **DNAT (entrada):** `-A DOCKER ! -i br-xxxx -p tcp --dport 80 -j DNAT --to-destination 172.28.1.10:80`
    convierte `host:80` en `nginx:80` (complementado por `docker-proxy` para tráfico desde loopback).
  - **SNAT/MASQUERADE (salida):** `-A POSTROUTING -s 172.28.0.0/… ! -o br-xxxx -j MASQUERADE` permite
    que `jupyter` descargue paquetes `pip` y que las imágenes salgan a Internet con la IP del host.
  - En Docker Desktop (Windows/macOS) todo esto ocurre dentro de la VM Linux (WSL2/LinuxKit) y el
    puerto se expone al sistema anfitrión a través del *vpnkit/port-forwarder*.

### Capa 2 — Enlace de datos

- **Puentes `br-*`:** cada red de usuario crea un *Linux bridge* llamado `br-<12 primeros hex del
  ID de red>` (p. ej. `br-3f2a9c1d7e44`) que se comporta como un **switch virtual** con tabla de
  aprendizaje de MAC (FDB).
- **Pares `veth*`:** por cada interfaz de contenedor Docker crea un par *virtual ethernet*: un
  extremo (`eth0`/`eth1`) dentro del *network namespace* del contenedor y el otro (`vethXXXXXXX`)
  enchufado al puente en el namespace del host. `joomla` tiene dos pares: uno en el puente de
  frontend y otro en el de backend.
- **Direcciones MAC:** Docker genera MACs localmente administradas (prefijo `02:42:` en versiones
  clásicas; aleatorias en Engine ≥ 26) a partir de la IP o al azar.
- **ARP interno:** antes de que `joomla` envíe el primer segmento TCP a 172.28.2.30, emite un
  `ARP who-has 172.28.2.30 tell 172.28.2.20` por `eth1`; el puente lo inunda a todos sus puertos
  `veth`, `database` responde con su MAC y ambos lo guardan en su caché ARP (`ip neigh`). El puente
  aprende las MAC en su FDB (`bridge fdb show`). Las tramas Ethernet II (MTU 1500) encapsulan los
  paquetes IP de la Capa 3.

### Capa 1 — Física (breve)

No existe medio físico entre contenedores: las "tramas" se copian en memoria del kernel entre
namespaces. Solo el tráfico que sale del host (descarga de imágenes, `pip`) atraviesa la NIC real.

### Resumen por capas

| Capa OSI | Elementos del proyecto |
|---|---|
| 7 Aplicación | HTTP/1.1, cabeceras `Host`/`X-Real-IP`/`X-Forwarded-*`, WebSocket (Upgrade), protocolo PG v3 + SCRAM, API REST de Grafana/Jupyter |
| 6 Presentación | UTF-8, JSON, gzip |
| 5 Sesión | Cookies Joomla/Grafana, sesiones de kernel, sesión PG |
| 4 Transporte | TCP 80/8888/3000/5432, puertos efímeros, keep-alive, pools de conexión |
| 3 Red | Subredes 172.28.1.0/24 y 172.28.2.0/24, DNS 127.0.0.11, DNAT/MASQUERADE, aislamiento iptables |
| 2 Enlace | `br-*`, `veth*`, MAC, ARP, FDB |
| 1 Física | Memoria del kernel / NIC del host |

---

## Sección 3 — Guía de verificación y demostración paso a paso

> Comandos para bash/PowerShell con Docker Desktop o Docker Engine.

### Paso 1 — Despliegue desatendido

```bash
git clone <URL_DEL_REPO> parcial2 && cd parcial2
cp .env.example .env
docker compose up -d
docker compose ps        # los 5 servicios deben aparecer "running (healthy)"
```

La primera vez Joomla tarda ~1–2 min (instalación automática contra PostgreSQL) y Jupyter
~1 min (instalación de `psycopg2-binary`, `pandas`, `matplotlib`, `sqlalchemy`).

### Paso 2 — Acceso a los servicios (todo por el puerto 80)

| URL | Resultado esperado |
|---|---|
| http://localhost/ | Sitio Joomla "Parcial2 Joomla" ya instalado |
| http://localhost/administrator | Login (usuario `admin`, contraseña `JOOMLA_ADMIN_PASSWORD` del `.env`) |
| http://localhost/jupyter/ | JupyterLab sin token; abrir `analisis_datos.ipynb` → *Run All* |
| http://localhost/grafana/ | Dashboard "Joomla - Actividad y Logs" sin login (anónimo Viewer) |

### Paso 3 — Verificar Capa 7

```bash
# Cabeceras y enrutamiento por ruta
curl -sI http://localhost/            | head -5
curl -sI http://localhost/grafana/api/health
curl -s  http://localhost/jupyter/api # {"version": "..."}

# Handshake WebSocket a través del proxy (espera "101 Switching Protocols")
curl -si -N http://localhost/jupyter/api/events/subscribe \
  -H "Connection: Upgrade" -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" -H "Sec-WebSocket-Key: SGVsbG9QYXJjaWFsMg==" --max-time 3 | head -3

# Protocolo PostgreSQL
docker compose exec database pg_isready -U joomlauser -d joomladb
docker compose exec database psql -U joomlauser -d joomladb -c "\dt jos_*" | head
```

### Paso 4 — Verificar Capa 4

```bash
# Conexiones TCP abiertas contra PostgreSQL (procesos backend y sus clientes)
docker compose exec database psql -U joomlauser -d joomladb \
  -c "SELECT client_addr, client_port, application_name, state FROM pg_stat_activity WHERE datname='joomladb';"

# Sockets en escucha / establecidos
docker compose exec database netstat -tn 2>/dev/null || docker compose exec database ss -tn
docker compose exec nginx netstat -tn          # conexiones keep-alive hacia :80, :8888, :3000

# Solo el puerto 80 está publicado en el host
docker compose port nginx 80                   # 0.0.0.0:80
docker compose port database 5432              # (vacío / error: no publicado)
```

### Paso 5 — Verificar Capa 3 (IP, DNS, aislamiento, NAT)

```bash
docker network inspect parcial2_frontend_net --format '{{range .Containers}}{{.Name}} {{.IPv4Address}}{{"\n"}}{{end}}'
docker network inspect parcial2_backend_net  --format '{{range .Containers}}{{.Name}} {{.IPv4Address}}{{"\n"}}{{end}}'

# DNS embebido
docker compose exec nginx cat /etc/resolv.conf          # nameserver 127.0.0.11
docker compose exec joomla getent hosts database        # 172.28.2.30 database
docker compose exec nginx nslookup database 127.0.0.11  # NXDOMAIN -> aislamiento

# Aislamiento: nginx NO alcanza a la base de datos
docker compose exec nginx sh -c "nc -zv -w 3 172.28.2.30 5432 || echo 'BLOQUEADO (esperado)'"
# Joomla SÍ (está en ambas redes)
docker compose exec joomla bash -c "echo > /dev/tcp/database/5432 && echo 'ALCANZABLE'"

# Rutas e interfaces de un contenedor multi-red
docker compose exec grafana ip -4 addr
docker compose exec grafana ip route

# NAT (en Linux nativo; en Docker Desktop dentro de la VM)
sudo iptables -t nat -L DOCKER -n -v | grep ':80'
sudo iptables -t nat -L POSTROUTING -n -v | grep MASQUERADE
```

### Paso 6 — Verificar Capa 2 (veth, bridges, ARP)

```bash
# En el host Linux (o en la VM de Docker Desktop:
#   docker run -it --rm --privileged --pid=host --net=host alpine nsenter -t 1 -m -u -n -i sh)
ip link show type bridge                 # br-xxxx y br-yyyy
ip link show type veth                   # vethXXXX@ifN, uno por interfaz de contenedor
bridge link                              # qué veth está en qué br-*
bridge fdb show br br-<id> | grep -v permanent

# Caché ARP dentro de un contenedor tras generar tráfico
docker compose exec joomla cat /proc/net/arp
docker compose exec grafana cat /proc/net/arp
```

### Paso 7 — Persistencia y Zero-Touch

```bash
docker compose down             # elimina contenedores y redes, conserva volúmenes
docker compose up -d            # Joomla, BD y Grafana conservan sus datos
docker volume ls | grep parcial2

docker compose down -v          # reinicio limpio total (borra volúmenes)
docker compose up -d            # vuelve a instalarse todo sin intervención
```

### Paso 8 — Logs y diagnóstico

```bash
docker compose logs -f nginx      # access log: IP cliente, ruta, código, upstream
docker compose logs joomla | grep -i install
docker compose logs grafana | grep -i provision
docker inspect --format '{{json .State.Health}}' database
```
