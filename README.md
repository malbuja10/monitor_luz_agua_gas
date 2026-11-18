# Monitor de medidores — energía, agua y gas

Sitio web para ver lecturas, alarmas e historial de medidores Zhongyi / ZYsmart y enviarles comandos
(válvula, relé). Stack: **Ubuntu + Apache2 + PHP 8.1+ + PostgreSQL**, sin frameworks ni Composer.
Los receptores de tramas son scripts **Node.js** (sin dependencias npm) gestionados con `pm2`.

```
medidor ──UDP/TCP──► receivers/*.js ──HTTP local──► public/api/ingest.php ──► PostgreSQL ◄── sitio PHP
                         ▲                                  │
                         └──── comandos en cola ◄───────────┘ (se entregan cuando el medidor reporta)
```

## Estructura
| Ruta | Contenido |
|---|---|
| `public/` | DocumentRoot de Apache: páginas, `api/`, `assets/` (Chart.js 4.4.1 incluido en `assets/vendor`) |
| `src/` | `bootstrap.php` (config, BD, utilidades), `auth.php` (sesión, roles, CSRF), `layout.php`, `ingesta.php` |
| `sql/` | `schema.sql` (tablas), `seed.sql` (medidores de prueba) |
| `receivers/` | `zy-receiver.js` (energía, TCP), `zy-water-udp.js` (agua, UDP), `ingest-client.js` (cliente para enviar al sitio) |
| `scripts/crear_usuario.php` | crea o restablece usuarios desde la consola |
| `deploy/apache-monitor.conf` | VirtualHost de ejemplo |

## Instalación en dev.minkafab.com
Requisitos previos: un registro DNS para el sitio (por defecto `monitor.minkafab.com`) apuntando a 135.181.100.95,
y Node 18+ con pm2 (ya existentes en el servidor).
```bash
sudo git clone https://github.com/malbuja10/monitor_luz_agua_gas /var/www/monitor
cd /var/www/monitor
sudo bash deploy/instalar.sh --dominio monitor.minkafab.com --receptores
sudo bash deploy/instalar.sh --dominio monitor.minkafab.com --https     # cuando el DNS ya resuelva
```
`deploy/instalar.sh` se puede ejecutar todas las veces que haga falta (no borra datos ni cambia claves):
1. Instala los paquetes que falten (`apache2`, `postgresql`, `php-cli`, `php-pgsql`, `curl`) y verifica PHP ≥ 8.1.
2. Crea `config/config.php` con contraseña de BD y token de ingesta aleatorios (si ya existe, lo conserva).
3. Crea el rol y la base `monitor`, aplica `sql/schema.sql` y `sql/seed.sql`.
4. Si no hay usuarios, pide la contraseña del administrador `admin`.
5. Instala el VirtualHost (`deploy/apache-monitor.conf`), aborta si el dominio ya lo usa otro sitio, y recarga Apache.
6. Comprueba que `login.php` y `api/ingest.php` respondan.
7. Con `--receptores`: reinicia en pm2 `zy-receiver` y `zy-water-udp` desde `receivers/`, conservando su
   carpeta de trabajo, argumentos (puerto, réplica a Zhongyi) y `FORWARD_DOWNLINK`/`WATER_DOWNLINK_ID`, y añade
   el token de ingesta. Otros nombres de proceso: `PM2_ENERGIA=... PM2_AGUA=... sudo -E bash deploy/instalar.sh ...`

Actualizar: `cd /var/www/monitor && sudo git pull && sudo bash deploy/instalar.sh --dominio monitor.minkafab.com --receptores`

### Instalación manual (referencia)
Lo que hace el script, paso a paso, por si se prefiere a mano:
```bash
sudo apt install php php-pgsql libapache2-mod-php postgresql
sudo git clone https://github.com/malbuja10/monitor_luz_agua_gas /var/www/monitor
cd /var/www/monitor

# Base de datos
sudo -u postgres psql -c "CREATE USER monitor PASSWORD 'una-clave-fuerte'" -c "CREATE DATABASE monitor OWNER monitor"
PGPASSWORD='una-clave-fuerte' psql -h 127.0.0.1 -U monitor -d monitor -f sql/schema.sql -f sql/seed.sql

# Configuración (clave de BD + token de ingesta)
cp config/config.example.php config/config.php
php -r 'echo bin2hex(random_bytes(24)), "\n";'      # pegar como ingest_token
nano config/config.php
sudo chown root:www-data config/config.php && sudo chmod 640 config/config.php

# Primer administrador
php scripts/crear_usuario.php admin admin "Administrador"

# Apache
sudo cp deploy/apache-monitor.conf /etc/apache2/sites-available/monitor.conf   # ajustar ServerName
sudo a2enmod headers && sudo a2ensite monitor && sudo systemctl reload apache2
```

### Receptor de agua con ingesta
Reemplaza al `zy-water-udp.js` actual (mismo decodificador, mismo `.jsonl`, más ingesta y cola de comandos):
```bash
pm2 delete zy-water-udp
INGEST_TOKEN=<token> INGEST_URL=http://127.0.0.1/api/ingest.php INGEST_HOST=monitor.minkafab.com \
  pm2 start /var/www/monitor/receivers/zy-water-udp.js --name zy-water-udp -- 10091 /root/zy-water.jsonl
pm2 save
```
`INGEST_HOST` hace falta si Apache sirve el sitio con un VirtualHost por nombre. Con `WATER_DOWNLINK_ID=1` las
tramas de bajada llevan delante el ID de 8 B (pendiente confirmar con Zhongyi cuál formato acepta el medidor).

### Receptor de energía con ingesta
`receivers/zy-receiver.js` es el receptor de producción (login `ZY-IOT`, reporte big-endian, réplica a Zhongyi,
consola) más el envío al sitio. Sin `INGEST_TOKEN` funciona exactamente como antes.
```bash
pm2 delete zy-receiver
cd /root   # carpeta donde se escribe zy-raw.log
INGEST_TOKEN=<token> INGEST_URL=http://127.0.0.1/api/ingest.php INGEST_HOST=monitor.minkafab.com \
  pm2 start /var/www/monitor/receivers/zy-receiver.js --name zy-receiver -- 10091 udp:58.215.245.18:20131
pm2 save
```
Comandos web → tramas: conectar `0A010101`, cortar `0A010001`, consultar `0A040001`, ciclo `0A02<seg 4 B BE>01`.
Se envían cuando el medidor reporta (el 4G cae a ~60 s, así que no se puede esperar a una conexión permanente).
Ojo: con la réplica activa y `FORWARD_DOWNLINK` distinto de 0, Zhongyi también puede mandar comandos al medidor.

## Roles
- **lector**: panel, detalle, gráficos, alarmas, exportar CSV.
- **operador**: además envía comandos y reconoce alarmas.
- **admin**: además gestiona medidores y usuarios.

## API de ingesta (`POST /api/ingest.php`)
Solo desde `ingest_ips` (localhost) y con la cabecera `X-Ingest-Token`.
- `{"reporte": {...}}` → `{"ok":true,"medidor_id":3,"comandos":[{"id":1,"accion":"cortar","parametro":null}]}`
  Formato del reporte documentado en `src/ingesta.php`. Los medidores con ID desconocido se dan de alta solos.
- `{"enviados": [{"id":1,"fid":5,"trama_hex":"049905"} | {"id":1,"error":"..."}]}`

Un comando pasa por `pendiente` → `enviado` (cuando el medidor reporta) → `confirmado` (ACK con el mismo FID,
o el medidor reporta el estado de válvula/relé pedido) o `error`.

El historial guarda como máximo una lectura cada `intervalo_historico_seg` (300 s por defecto) por medidor,
salvo que cambie el acumulado, la válvula o las alarmas. El último dato siempre se ve en tiempo real.
