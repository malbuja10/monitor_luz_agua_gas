-- Esquema PostgreSQL — Plataforma de medidores (energía, agua, gas)
-- Uso: psql -h 127.0.0.1 -U medidores -d medidores -f sql/schema.sql

BEGIN;

CREATE TABLE IF NOT EXISTS usuarios (
  id            serial PRIMARY KEY,
  usuario       varchar(50)  NOT NULL UNIQUE,
  nombre        varchar(120) NOT NULL DEFAULT '',
  clave_hash    varchar(255) NOT NULL,
  rol           varchar(10)  NOT NULL DEFAULT 'lector'
                CHECK (rol IN ('admin', 'operador', 'lector')),
  activo        boolean      NOT NULL DEFAULT true,
  ultimo_acceso timestamptz,
  creado        timestamptz  NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS medidores (
  id              serial PRIMARY KEY,
  tipo            varchar(10)  NOT NULL CHECK (tipo IN ('energia', 'agua', 'gas')),
  numero          varchar(32)  NOT NULL UNIQUE,      -- nº de serie impreso en el medidor
  dispositivo_id  varchar(32)  UNIQUE,               -- ID en tramas: IMEI, ID 8 B, DevEUI
  nombre          varchar(120) NOT NULL DEFAULT '',
  ubicacion       varchar(200) NOT NULL DEFAULT '',
  comunicacion    varchar(30)  NOT NULL DEFAULT '',  -- WiFi, 4G, LoRaWAN...
  modelo          varchar(60)  NOT NULL DEFAULT '',
  tiene_corte     boolean      NOT NULL DEFAULT false, -- válvula o relé controlable
  activo          boolean      NOT NULL DEFAULT true,
  ultima_conexion timestamptz,
  ultima_ip       varchar(64),                       -- ip:puerto del último paquete
  ultimo_dato     jsonb,                             -- última lectura decodificada completa
  creado          timestamptz  NOT NULL DEFAULT now()
);

-- Lecturas históricas. 'valor' es el acumulado del totalizador
-- (kWh para energía, m³ para agua y gas). El resto va en 'datos'.
CREATE TABLE IF NOT EXISTS lecturas (
  id          bigserial PRIMARY KEY,
  medidor_id  integer      NOT NULL REFERENCES medidores(id) ON DELETE CASCADE,
  ts          timestamptz  NOT NULL DEFAULT now(),
  valor       numeric(14,3),
  corte       varchar(12),          -- abierta/cerrada (válvula) o con_servicio/cortado (relé)
  bateria_v   numeric(5,2),
  rssi        smallint,
  datos       jsonb        NOT NULL DEFAULT '{}'::jsonb,
  raw         text
);
CREATE INDEX IF NOT EXISTS lecturas_medidor_ts ON lecturas (medidor_id, ts DESC);

-- Alarmas: se abre una fila cuando el código aparece y se cierra cuando desaparece.
CREATE TABLE IF NOT EXISTS alarmas (
  id              bigserial PRIMARY KEY,
  medidor_id      integer     NOT NULL REFERENCES medidores(id) ON DELETE CASCADE,
  codigo          varchar(40) NOT NULL,
  inicio          timestamptz NOT NULL DEFAULT now(),
  fin             timestamptz,
  reconocida_por  integer REFERENCES usuarios(id) ON DELETE SET NULL,
  reconocida_en   timestamptz
);
CREATE INDEX IF NOT EXISTS alarmas_abiertas ON alarmas (medidor_id) WHERE fin IS NULL;
CREATE INDEX IF NOT EXISTS alarmas_inicio ON alarmas (inicio DESC);

-- Cola de comandos de bajada. El receptor los recoge cuando el medidor reporta.
CREATE TABLE IF NOT EXISTS comandos (
  id          bigserial PRIMARY KEY,
  medidor_id  integer     NOT NULL REFERENCES medidores(id) ON DELETE CASCADE,
  accion      varchar(20) NOT NULL,         -- abrir, cerrar, consultar, ciclo
  parametro   varchar(40),
  estado      varchar(12) NOT NULL DEFAULT 'pendiente'
              CHECK (estado IN ('pendiente', 'enviado', 'confirmado', 'error', 'cancelado')),
  fid         smallint,
  trama_hex   varchar(200),
  detalle     text,
  creado_por  integer REFERENCES usuarios(id) ON DELETE SET NULL,
  creado      timestamptz NOT NULL DEFAULT now(),
  enviado     timestamptz,
  confirmado  timestamptz
);
CREATE INDEX IF NOT EXISTS comandos_pendientes ON comandos (medidor_id) WHERE estado = 'pendiente';

-- Auditoría de acciones de usuarios (comandos, altas, cambios).
CREATE TABLE IF NOT EXISTS auditoria (
  id          bigserial PRIMARY KEY,
  usuario_id  integer REFERENCES usuarios(id) ON DELETE SET NULL,
  accion      varchar(60) NOT NULL,
  detalle     text,
  ip          varchar(64),
  ts          timestamptz NOT NULL DEFAULT now()
);

COMMIT;
