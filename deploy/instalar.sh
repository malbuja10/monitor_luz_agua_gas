#!/usr/bin/env bash
# Instalación / actualización de la plataforma de medidores en Ubuntu (Apache2 + PHP + PostgreSQL).
# Se puede ejecutar varias veces: no borra datos ni cambia contraseñas existentes.
# Convive con otros sitios del servidor: se detiene (sin modificar nada) si el puerto, el archivo de
# Apache o el usuario/base de PostgreSQL ya existen y no son de esta plataforma.
#
# Uso (desde el clon del repositorio, p. ej. /var/www/medidores):
#   sudo bash deploy/instalar.sh [--receptores] [--puerto 8450] [--dominio dev.minkafab.com] [--sin-apt]
#
# El sitio se publica en un VirtualHost propio de Apache: https://dev.minkafab.com:8450/
# (sites-available/medidores.conf). Usa el certificado de Let's Encrypt del dominio si existe
# (o el indicado en CERT= y KEY=); si no hay certificado, queda en HTTP.
#
#   --puerto N     puerto del sitio (por defecto 8450)
#   --dominio X    nombre del servidor (por defecto dev.minkafab.com)
#   --receptores   reinicia en pm2 los receptores de energía y agua desde este repositorio, con el token
#                  de ingesta. Conserva carpeta de trabajo, argumentos (puerto, réplica a Zhongyi) y
#                  variables FORWARD_DOWNLINK / WATER_DOWNLINK_ID de los procesos actuales.
#   --sin-apt      no instala paquetes
#
# Variables opcionales: PM2_ENERGIA (zy-receiver), PM2_AGUA (zy-water-udp), DB_NOMBRE (monitor_medidores),
#                       DB_USUARIO (monitor_medidores), DIR_LOGS (/var/lib/medidores, solo si el proceso no existía),
#                       CERT y KEY (rutas del certificado TLS).
set -euo pipefail

DOMINIO="dev.minkafab.com"
PUERTO=8450
RECEPTORES=0; APT=1
while [ $# -gt 0 ]; do
  case "$1" in
    --dominio) DOMINIO="${2:?falta el dominio}"; shift 2 ;;
    --receptores) RECEPTORES=1; shift ;;
    --puerto) PUERTO="${2:?falta el puerto}"; shift 2 ;;
    --sin-apt) APT=0; shift ;;
    -h|--help) sed -n '2,23p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $1" >&2; exit 1 ;;
  esac
done

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PM2_ENERGIA="${PM2_ENERGIA:-zy-receiver}"
PM2_AGUA="${PM2_AGUA:-zy-water-udp}"
DB_NOMBRE="${DB_NOMBRE:-monitor_medidores}"
DB_USUARIO="${DB_USUARIO:-monitor_medidores}"
DIR_LOGS="${DIR_LOGS:-/var/lib/medidores}"
CONF="$APP/config/config.php"
CONF_APACHE=/etc/apache2/sites-available/medidores.conf
MARCA='# medidores-zhongyi'

paso() { printf '\n\033[1;34m== %s\033[0m\n' "$*"; }
ok()   { printf '   \033[32m✔\033[0m %s\n' "$*"; }
aviso(){ printf '   \033[33m!\033[0m %s\n' "$*"; }
fallo(){ printf '\n\033[31m✘ %s\033[0m\n' "$*" >&2; exit 1; }
recargar_apache() { systemctl reload apache2 2>/dev/null || apache2ctl graceful; }

[ "$(id -u)" -eq 0 ] || fallo "Ejecutar con sudo."
[ -f "$APP/public/index.php" ] && [ -f "$APP/sql/schema.sql" ] || fallo "No encuentro el sitio en $APP."
grep -qi ubuntu /etc/os-release || aviso "No parece Ubuntu; se continúa igual."
[[ "$PUERTO" =~ ^[0-9]+$ ]] && [ "$PUERTO" -ge 1024 ] && [ "$PUERTO" -le 65535 ] || fallo "Puerto inválido: $PUERTO"
CERT="${CERT:-/etc/letsencrypt/live/$DOMINIO/fullchain.pem}"
KEY="${KEY:-/etc/letsencrypt/live/$DOMINIO/privkey.pem}"
if [ -r "$CERT" ] && [ -r "$KEY" ]; then ESQUEMA=https; else ESQUEMA=http; fi
URL_SITIO="$ESQUEMA://$DOMINIO:$PUERTO/"
echo "Aplicación: $APP · Publicación: $URL_SITIO"

# ---------------------------------------------------------------- paquetes
paso "Paquetes"
if [ "$APT" -eq 1 ]; then
  FALTAN=()
  for p in apache2 postgresql php-cli php-pgsql curl; do
    dpkg -s "$p" >/dev/null 2>&1 || FALTAN+=("$p")
  done
  if [ ${#FALTAN[@]} -gt 0 ]; then
    apt-get update -qq
    DEBIAN_FRONTEND=noninteractive apt-get install -y -qq "${FALTAN[@]}" >/dev/null
    ok "Instalado: ${FALTAN[*]}"
  else
    ok "apache2, postgresql, php-cli, php-pgsql y curl ya instalados"
  fi
fi
command -v php >/dev/null || fallo "Falta PHP."
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || fallo "Se necesita PHP 8.1 o superior (hay $(php -r 'echo PHP_VERSION;'))."
php -m | grep -qi '^pdo_pgsql$' || fallo "Falta la extensión pdo_pgsql (apt install php-pgsql)."
ok "PHP $(php -r 'echo PHP_VERSION;') con pdo_pgsql"

# PHP dentro de Apache: mod_php o PHP-FPM.
if apache2ctl -M 2>/dev/null | grep -qE 'php[0-9._]*_module|php_module'; then
  ok "Apache usa mod_php"
elif apache2ctl -M 2>/dev/null | grep -q proxy_fcgi_module; then
  ok "Apache usa PHP-FPM (proxy_fcgi)"
  systemctl restart "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm" 2>/dev/null || true
else
  [ "$APT" -eq 1 ] || fallo "Apache no tiene PHP configurado (instale libapache2-mod-php o PHP-FPM)."
  aviso "Apache no tenía PHP: se instala mod_php (cambia el MPM a prefork)."
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq libapache2-mod-php
  a2dismod -q mpm_event >/dev/null 2>&1 || true
  a2enmod -q mpm_prefork "php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" >/dev/null
  systemctl restart apache2 2>/dev/null || apache2ctl restart
fi

# ---------------------------------------------------------------- verificaciones de convivencia
# Nada se modifica hasta pasar estas comprobaciones.
paso "Convivencia con otros sitios"
CONF_NUEVA=0
[ -f "$CONF" ] || CONF_NUEVA=1
if [ -f "$CONF_APACHE" ] && ! grep -qF "$MARCA" "$CONF_APACHE"; then
  fallo "$CONF_APACHE existe y no es de esta plataforma. No se modifica."
fi
# El puerto no debe estar declarado en otra configuración de Apache ni usado por otro programa.
OCUPADO="$(grep -RlsE "^[[:space:]]*(Listen[[:space:]]+([^ ]+:)?$PUERTO([[:space:]]|$)|<VirtualHost[^>]*:$PUERTO[[:space:]>])" \
            /etc/apache2/ports.conf /etc/apache2/sites-enabled/ /etc/apache2/conf-enabled/ 2>/dev/null \
            | grep -v "/medidores.conf$" || true)"
[ -z "$OCUPADO" ] || fallo "El puerto $PUERTO ya lo usa otra configuración de Apache: $OCUPADO. Use --puerto con otro número."
if [ ! -e /etc/apache2/sites-enabled/medidores.conf ] && command -v ss >/dev/null \
   && ss -ltnH "sport = :$PUERTO" 2>/dev/null | grep -q .; then
  fallo "El puerto $PUERTO ya está en uso por otro programa: $(ss -ltnpH "sport = :$PUERTO" | head -1). Use --puerto."
fi
# Sin config.php, un usuario o base existentes solo se reutilizan si son restos de esta plataforma:
# la base pertenece a ese usuario y solo contiene tablas de este esquema, o el usuario no es dueño de ninguna base.
if [ "$CONF_NUEVA" -eq 1 ] && command -v psql >/dev/null; then
  systemctl start postgresql 2>/dev/null || service postgresql start >/dev/null 2>&1 || true
  pg() { sudo -u postgres psql -tAq "$@" 2>/dev/null; }
  TABLAS="'usuarios','medidores','lecturas','alarmas','comandos','auditoria'"
  ROL_EXISTE="$(pg -c "SELECT count(*) FROM pg_roles WHERE rolname = '$DB_USUARIO'")"
  BD_DUENO="$(pg -c "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '$DB_NOMBRE'")"
  if [ -n "$BD_DUENO" ]; then
    AJENAS="$(pg -d "$DB_NOMBRE" -c "SELECT count(*) FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema')
                                     AND NOT (schemaname = 'public' AND tablename IN ($TABLAS))")"
    if [ "$BD_DUENO" != "$DB_USUARIO" ] || [ "$AJENAS" != "0" ]; then
      fallo "Ya existe la base '$DB_NOMBRE' (dueño '$BD_DUENO', $AJENAS tabla(s) ajenas a esta plataforma). No se modifica. Use DB_NOMBRE=otro_nombre."
    fi
    aviso "La base '$DB_NOMBRE' es de una instalación anterior de esta plataforma: se reutiliza con sus datos."
  elif [ "$ROL_EXISTE" = "1" ]; then
    N_BD="$(pg -c "SELECT count(*) FROM pg_database WHERE datdba = (SELECT oid FROM pg_roles WHERE rolname = '$DB_USUARIO')")"
    [ "$N_BD" = "0" ] || fallo "El usuario de PostgreSQL '$DB_USUARIO' es dueño de otras bases. No se modifica. Use DB_USUARIO=otro_nombre."
  fi
  [ "$ROL_EXISTE" = "1" ] && aviso "El usuario '$DB_USUARIO' es de una instalación anterior sin config.php: se le asigna una contraseña nueva."
fi
ok "Puerto $PUERTO, archivo de Apache y base '$DB_NOMBRE' / usuario '$DB_USUARIO' libres o propios"

# ---------------------------------------------------------------- configuración
paso "Configuración ($CONF)"
if [ -f "$CONF" ]; then
  DB_CLAVE="$(php -r '$c = require $argv[1]; echo $c["db"]["clave"];' "$CONF")"
  TOKEN="$(php -r '$c = require $argv[1]; echo $c["ingest_token"];' "$CONF")"
  [ "$DB_CLAVE" != "CAMBIAR" ] && [ "$TOKEN" != "CAMBIAR" ] || fallo "config.php tiene valores CAMBIAR: complételo o bórrelo para regenerarlo."
  ok "Se conserva la configuración existente"
  DB_USUARIO="$(php -r '$c = require $argv[1]; echo $c["db"]["usuario"];' "$CONF")"
  DB_NOMBRE="$(php -r '$c = require $argv[1]; preg_match("/dbname=([^;]+)/", $c["db"]["dsn"], $m); echo $m[1] ?? "";' "$CONF")"
else
  DB_CLAVE="$(php -r 'echo bin2hex(random_bytes(18));')"
  TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
  sed -e "s|dbname=monitor_medidores'|dbname=$DB_NOMBRE'|" \
      -e "s|'usuario'  => 'monitor_medidores'|'usuario'  => '$DB_USUARIO'|" \
      -e "s|'clave'    => 'CAMBIAR'|'clave'    => '$DB_CLAVE'|" \
      -e "s|'ingest_token' => 'CAMBIAR'|'ingest_token' => '$TOKEN'|" \
      "$APP/config/config.example.php" > "$CONF"
  ok "Creado con contraseña de BD y token de ingesta aleatorios"
fi
chown root:www-data "$CONF"
chmod 640 "$CONF"
ok "Permisos root:www-data 640"

# ---------------------------------------------------------------- base de datos
paso "PostgreSQL"
systemctl start postgresql 2>/dev/null || service postgresql start >/dev/null 2>&1 || true
sudo -u postgres psql -q -v ON_ERROR_STOP=1 -v usr="$DB_USUARIO" -v pw="$DB_CLAVE" -v db="$DB_NOMBRE" <<'SQL'
SELECT format('CREATE ROLE %I LOGIN', :'usr') WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = :'usr') \gexec
SELECT format('ALTER ROLE %I PASSWORD %L', :'usr', :'pw') \gexec
SELECT format('CREATE DATABASE %I OWNER %I', :'db', :'usr') WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'db') \gexec
SQL
ok "Rol '$DB_USUARIO' y base '$DB_NOMBRE' listos"
export PGPASSWORD="$DB_CLAVE"
psql -q -h 127.0.0.1 -U "$DB_USUARIO" -d "$DB_NOMBRE" -v ON_ERROR_STOP=1 -f "$APP/sql/schema.sql" -f "$APP/sql/seed.sql" >/dev/null
unset PGPASSWORD
ok "Esquema aplicado y medidores de prueba cargados (sin duplicar)"

paso "Usuarios"
N_USR="$(php -r 'require $argv[1]; echo q("SELECT count(*) FROM usuarios")->fetchColumn();' "$APP/src/bootstrap.php")"
if [ "$N_USR" -eq 0 ]; then
  if [ -t 0 ]; then
    echo "   No hay usuarios. Se crea el administrador 'admin':"
    php "$APP/scripts/crear_usuario.php" admin admin "Administrador"
  else
    aviso "No hay usuarios. Cree el primero con: sudo php $APP/scripts/crear_usuario.php admin admin"
  fi
else
  ok "$N_USR usuario(s) existentes"
fi

# ---------------------------------------------------------------- Apache
paso "Apache ($CONF_APACHE)"
SED=(-e "s|8450|$PUERTO|g" -e "s|ServerName dev.minkafab.com|ServerName $DOMINIO|" -e "s|/var/www/medidores|$APP|g")
if [ "$ESQUEMA" = https ]; then
  SED+=(-e "s|^\([[:space:]]*\)#SSL |\1|" -e "s|__CERT__|$CERT|" -e "s|__KEY__|$KEY|")
  a2enmod -q ssl >/dev/null
  ok "HTTPS con $CERT"
else
  SED+=(-e "/#SSL /d")
  aviso "Sin certificado en $CERT: el sitio queda en HTTP. Para HTTPS indique CERT=... KEY=... y vuelva a ejecutar."
fi
sed "${SED[@]}" "$APP/deploy/apache-medidores.conf" > "$CONF_APACHE"
a2enmod -q headers >/dev/null
a2ensite -q medidores >/dev/null
if ! apache2ctl configtest 2>&1 | grep -q 'Syntax OK'; then
  apache2ctl configtest || true
  a2dissite -q medidores >/dev/null
  fallo "Error en la configuración de Apache: se deshabilitó el sitio medidores para no afectar a los demás."
fi
# Un Listen nuevo requiere reinicio completo (graceful no abre puertos nuevos de forma fiable).
if ss -ltnH "sport = :$PUERTO" 2>/dev/null | grep -q .; then recargar_apache
else systemctl restart apache2 2>/dev/null || apache2ctl restart; fi
ok "Sitio habilitado en el puerto $PUERTO"

if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow "$PUERTO/tcp" >/dev/null && ok "Firewall (ufw): puerto $PUERTO/tcp abierto"
else
  aviso "ufw no está activo: si hay otro firewall (p. ej. el de Hetzner), abra el puerto $PUERTO/tcp."
fi

# ---------------------------------------------------------------- comprobaciones
paso "Comprobaciones"
# Los receptores llegan por 127.0.0.1. Con HTTPS, la cabecera Host (INGEST_HOST) hace que Node
# valide el certificado contra el dominio aunque conecte a 127.0.0.1.
CURL_LOCAL=(--resolve "$DOMINIO:$PUERTO:127.0.0.1")
if [ "$ESQUEMA" = https ]; then
  INGEST_URL="https://127.0.0.1:$PUERTO/api/ingest.php"; INGEST_HOST="$DOMINIO"
else
  INGEST_URL="http://127.0.0.1:$PUERTO/api/ingest.php"; INGEST_HOST=""
fi
# Apache puede tardar unos segundos en abrir el puerto tras el reinicio.
for _ in 1 2 3 4 5 6 7 8 9 10; do
  RESP="$(curl -s --noproxy "*" --max-time 8 "${CURL_LOCAL[@]}" -H "X-Ingest-Token: $TOKEN" -d '{}' "$ESQUEMA://$DOMINIO:$PUERTO/api/ingest.php" || true)"
  [ "$RESP" = '{"ok":true}' ] && break
  sleep 1
done
if [ "$RESP" = '{"ok":true}' ]; then
  ok "api/ingest.php acepta el token"
else
  aviso "api/ingest.php respondió: ${RESP:-sin respuesta}. Revise /var/log/apache2/medidores_error.log"
  INGEST_URL=""
fi
COD="$(curl -s --noproxy "*" --max-time 8 "${CURL_LOCAL[@]}" -o /dev/null -w '%{http_code}' "$ESQUEMA://$DOMINIO:$PUERTO/login.php" || true)"
[ "$COD" = "200" ] && ok "${URL_SITIO}login.php responde 200 (desde el servidor)" \
  || aviso "login.php respondió $COD. Revise /var/log/apache2/medidores_error.log"

# ---------------------------------------------------------------- receptores (pm2)
# Devuelve "cwd␞args (separados por \x1f)␞FORWARD_DOWNLINK␞WATER_DOWNLINK_ID" del proceso pm2 (␞ = \x1e).
info_pm2() {
  pm2 jlist 2>/dev/null | node -e '
    let s = ""; process.stdin.on("data", d => s += d).on("end", () => {
      let l = []; try { l = JSON.parse(s); } catch {}
      const p = l.find(x => x.name === process.argv[1]); if (!p) return;
      const e = p.pm2_env, a = Array.isArray(e.args) ? e.args : (e.args ? String(e.args).split(" ") : []);
      console.log([e.pm_cwd, a.join("\x1f"), e.FORWARD_DOWNLINK ?? "", e.WATER_DOWNLINK_ID ?? ""].join("\x1e"));
    });' "$1"
}
reiniciar() { # nombre script args_por_defecto...
  local nombre="$1" script="$2"; shift 2
  local info cwd args fwd wid
  info="$(info_pm2 "$nombre")"
  if [ -n "$info" ]; then
    IFS=$'\x1e' read -r cwd args fwd wid <<<"$info"
    IFS=$'\x1f' read -r -a ARGS <<<"$args"
    [ ${#ARGS[@]} -gt 0 ] && [ -n "${ARGS[0]}" ] || ARGS=("$@")
    pm2 delete "$nombre" >/dev/null
  else
    cwd="$DIR_LOGS"; ARGS=("$@"); fwd=""; wid=""
    mkdir -p "$cwd"
  fi
  env INGEST_TOKEN="$TOKEN" INGEST_URL="$INGEST_URL" ${INGEST_HOST:+INGEST_HOST="$INGEST_HOST"} \
      ${fwd:+FORWARD_DOWNLINK="$fwd"} ${wid:+WATER_DOWNLINK_ID="$wid"} \
      pm2 start "$APP/receivers/$script" --name "$nombre" --cwd "$cwd" -- "${ARGS[@]}" >/dev/null
  ok "$nombre: $script ${ARGS[*]} (carpeta $cwd)"
}

if [ "$RECEPTORES" -eq 1 ]; then
  paso "Receptores pm2"
  command -v node >/dev/null && command -v pm2 >/dev/null || fallo "Faltan node o pm2."
  [ -n "$INGEST_URL" ] || fallo "No se reinician los receptores: el endpoint de ingesta no responde (ver avisos)."
  node -e 'process.exit(+process.versions.node.split(".")[0] >= 18 ? 0 : 1)' || fallo "Se necesita Node 18 o superior."
  reiniciar "$PM2_ENERGIA" zy-receiver.js 10091
  reiniciar "$PM2_AGUA" zy-water-udp.js 10091 zy-water.jsonl
  pm2 save >/dev/null
  sleep 2
  pm2 ls
else
  paso "Receptores"
  echo "   No se tocaron. Para conectarlos al sitio vuelva a ejecutar con --receptores."
fi

paso "Listo"
echo "   Sitio: $URL_SITIO   (si no abre desde fuera, revise el firewall del puerto $PUERTO)"
echo "   Para actualizar: cd $APP && sudo git pull && sudo bash deploy/instalar.sh --receptores"
