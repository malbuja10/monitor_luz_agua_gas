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
| `deploy/instalar.sh`, `deploy/apache-medidores.conf` | instalador y VirtualHost (puerto 8450) |

## Instalación en dev.minkafab.com
El sitio se publica en **https://dev.minkafab.com:8450/**, en un VirtualHost propio de Apache
(`sites-available/medidores.conf`), separado de los demás sitios del servidor (p. ej. el monitoreo de
dispositivos en el puerto 8445). Usa el certificado de Let's Encrypt de dev.minkafab.com si existe.
Requisitos previos: Node 18+ con pm2 (ya existentes) y el puerto 8450/tcp abierto en el firewall
(el script lo abre en `ufw`; si hay firewall de Hetzner, abrirlo allí también).
```bash
sudo git clone https://github.com/malbuja10/monitor_luz_agua_gas /var/www/medidores
cd /var/www/medidores
sudo bash deploy/instalar.sh --receptores
```
`deploy/instalar.sh` se puede ejecutar todas las veces que haga falta (no borra datos ni cambia claves).
**Antes de modificar nada** comprueba que no choca con otros sitios y se detiene si:
el puerto ya está en otra configuración de Apache o en uso por otro programa; `sites-available/medidores.conf`
existe y no es de esta plataforma; o (en una instalación nueva) ya existen el usuario o la base `medidores` en PostgreSQL.

Luego:
1. Instala los paquetes que falten (`apache2`, `postgresql`, `php-cli`, `php-pgsql`, `curl`) y verifica PHP ≥ 8.1.
2. Crea `config/config.php` con contraseña de BD y token de ingesta aleatorios (si ya existe, lo conserva).
3. Crea el usuario y la base `medidores`, aplica `sql/schema.sql` y `sql/seed.sql`.
4. Si no hay usuarios, pide la contraseña del administrador `admin`.
5. Instala el VirtualHost en el puerto 8450 (HTTPS si encuentra el certificado), abre el puerto en `ufw` y
   reinicia Apache. Si `configtest` falla, deshabilita el sitio para no afectar a los demás.
6. Comprueba que `api/ingest.php` acepte el token y que `login.php` responda.
7. Con `--receptores`: reinicia en pm2 `zy-receiver` y `zy-water-udp` desde `receivers/`, conservando su
   carpeta de trabajo, argumentos (puerto, réplica a Zhongyi) y `FORWARD_DOWNLINK`/`WATER_DOWNLINK_ID`, y añade
   el token y la URL de ingesta (`https://127.0.0.1:8450/api/ingest.php` con `INGEST_HOST=dev.minkafab.com`,
   que valida el certificado contra el dominio). Si la ingesta no responde, no los toca.
   Otros nombres de proceso: `PM2_ENERGIA=... PM2_AGUA=... sudo -E bash deploy/instalar.sh ...`

Opciones: `--puerto` (8450), `--dominio` (dev.minkafab.com), `--sin-apt`; variables `CERT=` y `KEY=` para otro
certificado, `DB_NOMBRE=` y `DB_USUARIO=` para otra base.

Actualizar: `cd /var/www/medidores && sudo git pull && sudo bash deploy/instalar.sh --receptores`

### Instalación manual (referencia)
Lo que hace el script, paso a paso, por si se prefiere a mano:
```bash
sudo apt install php php-pgsql libapache2-mod-php postgresql
sudo git clone https://github.com/malbuja10/monitor_luz_agua_gas /var/www/medidores
cd /var/www/medidores

# Base de datos
sudo -u postgres psql -c "CREATE USER medidores PASSWORD 'una-clave-fuerte'" -c "CREATE DATABASE medidores OWNER medidores"
PGPASSWORD='una-clave-fuerte' psql -h 127.0.0.1 -U medidores -d medidores -f sql/schema.sql -f sql/seed.sql

# Configuración (clave de BD + token de ingesta)
cp config/config.example.php config/config.php
php -r 'echo bin2hex(random_bytes(24)), "\n";'      # pegar como ingest_token
nano config/config.php
sudo chown root:www-data config/config.php && sudo chmod 640 config/config.php

# Primer administrador
php scripts/crear_usuario.php admin admin "Administrador"

# Apache
# copiar y editar (puerto, certificado: descomentar líneas #SSL y poner rutas)
sudo cp deploy/apache-medidores.conf /etc/apache2/sites-available/medidores.conf
sudo a2enmod ssl headers && sudo a2ensite medidores && sudo systemctl restart apache2
```

### Receptor de agua con ingesta
Reemplaza al `zy-water-udp.js` actual (mismo decodificador, mismo `.jsonl`, más ingesta y cola de comandos):
```bash
pm2 delete zy-water-udp
INGEST_TOKEN=<token> INGEST_URL=https://127.0.0.1:8450/api/ingest.php INGEST_HOST=dev.minkafab.com \
  pm2 start /var/www/medidores/receivers/zy-water-udp.js --name zy-water-udp -- 10091 /root/zy-water.jsonl
pm2 save
```
Con `WATER_DOWNLINK_ID=1` las
tramas de bajada llevan delante el ID de 8 B (pendiente confirmar con Zhongyi cuál formato acepta el medidor).

### Receptor de energía con ingesta
`receivers/zy-receiver.js` es el receptor de producción (login `ZY-IOT`, reporte big-endian, réplica a Zhongyi,
consola) más el envío al sitio. Sin `INGEST_TOKEN` funciona exactamente como antes.
```bash
pm2 delete zy-receiver
cd /root   # carpeta donde se escribe zy-raw.log
INGEST_TOKEN=<token> INGEST_URL=https://127.0.0.1:8450/api/ingest.php INGEST_HOST=dev.minkafab.com \
  pm2 start /var/www/medidores/receivers/zy-receiver.js --name zy-receiver -- 10091 udp:58.215.245.18:20131
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
