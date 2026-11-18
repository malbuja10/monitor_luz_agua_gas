#!/usr/bin/env bash
# Instalación / actualización del Monitor de medidores en Ubuntu (Apache2 + PHP + PostgreSQL).
# Se puede ejecutar varias veces: no borra datos ni cambia contraseñas existentes.
#
# Uso (desde el clon del repositorio, p. ej. /var/www/monitor):
#   sudo bash deploy/instalar.sh [--dominio monitor.minkafab.com] [--receptores] [--https] [--sin-apt]
#
#   --dominio X    ServerName del sitio (por defecto monitor.minkafab.com; el DNS debe apuntar al servidor)
#   --receptores   reinicia en pm2 los receptores de energía y agua desde este repositorio, con el token
#                  de ingesta. Conserva carpeta de trabajo, argumentos (puerto, réplica a Zhongyi) y
#                  variables FORWARD_DOWNLINK / WATER_DOWNLINK_ID de los procesos actuales.
#   --https        obtiene el certificado con certbot (requiere DNS ya apuntando al servidor)
#   --sin-apt      no instala paquetes
#
# Variables opcionales: PM2_ENERGIA (zy-receiver), PM2_AGUA (zy-water-udp), DB_NOMBRE (monitor),
#                       DB_USUARIO (monitor), DIR_LOGS (/var/lib/monitor, solo si el proceso no existía).
set -euo pipefail

DOMINIO="monitor.minkafab.com"
RECEPTORES=0; HTTPS=0; APT=1
while [ $# -gt 0 ]; do
  case "$1" in
    --dominio) DOMINIO="${2:?falta el dominio}"; shift 2 ;;
    --receptores) RECEPTORES=1; shift ;;
    --https) HTTPS=1; shift ;;
    --sin-apt) APT=0; shift ;;
    -h|--help) sed -n '2,19p' "$0"; exit 0 ;;
    *) echo "Opción desconocida: $1" >&2; exit 1 ;;
  esac
done

APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PM2_ENERGIA="${PM2_ENERGIA:-zy-receiver}"
PM2_AGUA="${PM2_AGUA:-zy-water-udp}"
DB_NOMBRE="${DB_NOMBRE:-monitor}"
DB_USUARIO="${DB_USUARIO:-monitor}"
DIR_LOGS="${DIR_LOGS:-/var/lib/monitor}"
CONF="$APP/config/config.php"
SITIO=/etc/apache2/sites-available/monitor.conf

paso() { printf '\n\033[1;34m== %s\033[0m\n' "$*"; }
ok()   { printf '   \033[32m✔\033[0m %s\n' "$*"; }
aviso(){ printf '   \033[33m!\033[0m %s\n' "$*"; }
fallo(){ printf '\n\033[31m✘ %s\033[0m\n' "$*" >&2; exit 1; }
recargar_apache() { systemctl reload apache2 2>/dev/null || apache2ctl graceful; }

[ "$(id -u)" -eq 0 ] || fallo "Ejecutar con sudo."
[ -f "$APP/public/index.php" ] && [ -f "$APP/sql/schema.sql" ] || fallo "No encuentro el sitio en $APP."
grep -qi ubuntu /etc/os-release || aviso "No parece Ubuntu; se continúa igual."
echo "Aplicación: $APP · Dominio: $DOMINIO"

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

# ---------------------------------------------------------------- configuración
paso "Configuración ($CONF)"
if [ -f "$CONF" ]; then
  DB_CLAVE="$(php -r '$c = require $argv[1]; echo $c["db"]["clave"];' "$CONF")"
  TOKEN="$(php -r '$c = require $argv[1]; echo $c["ingest_token"];' "$CONF")"
  [ "$DB_CLAVE" != "CAMBIAR" ] && [ "$TOKEN" != "CAMBIAR" ] || fallo "config.php tiene valores CAMBIAR: complételo o bórrelo para regenerarlo."
  ok "Se conserva la configuración existente"
else
  DB_CLAVE="$(php -r 'echo bin2hex(random_bytes(18));')"
  TOKEN="$(php -r 'echo bin2hex(random_bytes(24));')"
  sed -e "s|'dsn'      => 'pgsql:host=127.0.0.1;port=5432;dbname=monitor'|'dsn'      => 'pgsql:host=127.0.0.1;port=5432;dbname=$DB_NOMBRE'|" \
      -e "s|'usuario'  => 'monitor'|'usuario'  => '$DB_USUARIO'|" \
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
paso "Apache ($SITIO)"
if apache2ctl -S 2>/dev/null | grep -E "(namevhost|alias) $DOMINIO( |$)" | grep -vq "sites-enabled/monitor.conf"; then
  fallo "El dominio $DOMINIO ya lo usa otro sitio de Apache. Use --dominio con otro nombre (p. ej. monitor.minkafab.com)."
fi
sed -e "s|ServerName monitor.minkafab.com|ServerName $DOMINIO|" \
    -e "s|/var/www/monitor|$APP|g" \
    "$APP/deploy/apache-monitor.conf" > "$SITIO.nuevo"
if [ -f "$SITIO" ] && grep -q 'SSLCertificateFile\|Include /etc/letsencrypt' "$SITIO"; then
  rm -f "$SITIO.nuevo"   # certbot lo modificó; no se sobrescribe
  ok "Sitio existente con HTTPS: se conserva"
else
  mv "$SITIO.nuevo" "$SITIO"
fi
a2enmod -q headers >/dev/null
a2ensite -q monitor >/dev/null
apache2ctl configtest 2>&1 | grep -q 'Syntax OK' || { apache2ctl configtest; fallo "Error en la configuración de Apache."; }
recargar_apache
ok "Sitio habilitado y Apache recargado"

if [ "$HTTPS" -eq 1 ]; then
  paso "HTTPS"
  if command -v certbot >/dev/null; then
    certbot --apache -d "$DOMINIO" --non-interactive --agree-tos --redirect --register-unsafely-without-email \
      && ok "Certificado instalado" || aviso "certbot falló (¿el DNS de $DOMINIO apunta a este servidor?)"
  else
    aviso "certbot no está instalado: sudo apt install certbot python3-certbot-apache"
  fi
fi

# ---------------------------------------------------------------- comprobaciones
paso "Comprobaciones"
COD="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $DOMINIO" http://127.0.0.1/login.php || true)"
[ "$COD" = "200" ] && ok "login.php responde 200" || aviso "login.php respondió $COD (revise /var/log/apache2/monitor_error.log)"
RESP="$(curl -s -H "Host: $DOMINIO" -H "X-Ingest-Token: $TOKEN" -d '{}' http://127.0.0.1/api/ingest.php || true)"
[ "$RESP" = '{"ok":true}' ] && ok "api/ingest.php acepta el token" || aviso "api/ingest.php respondió: $RESP"

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
  env INGEST_TOKEN="$TOKEN" INGEST_URL="http://127.0.0.1/api/ingest.php" INGEST_HOST="$DOMINIO" \
      ${fwd:+FORWARD_DOWNLINK="$fwd"} ${wid:+WATER_DOWNLINK_ID="$wid"} \
      pm2 start "$APP/receivers/$script" --name "$nombre" --cwd "$cwd" -- "${ARGS[@]}" >/dev/null
  ok "$nombre: $script ${ARGS[*]} (carpeta $cwd)"
}

if [ "$RECEPTORES" -eq 1 ]; then
  paso "Receptores pm2"
  command -v node >/dev/null && command -v pm2 >/dev/null || fallo "Faltan node o pm2."
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
if [ "$HTTPS" -eq 1 ]; then echo "   Sitio: https://$DOMINIO/"; else
  echo "   Sitio: http://$DOMINIO/   (HTTPS: sudo bash deploy/instalar.sh --dominio $DOMINIO --https)"; fi
echo "   Para actualizar: cd $APP && sudo git pull && sudo bash deploy/instalar.sh --dominio $DOMINIO"
