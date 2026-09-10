-- =========================================================
-- Telium PABX — CDR e CEL
-- Colunas no formato esperado por cdr_adaptive_odbc e cel_odbc
-- (o Asterisk grava direto aqui via unixODBC).
-- =========================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS cdr (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  calldate     DATETIME     NOT NULL DEFAULT '1970-01-02 00:00:00',
  clid         VARCHAR(80)  NOT NULL DEFAULT '',
  src          VARCHAR(80)  NOT NULL DEFAULT '',
  dst          VARCHAR(80)  NOT NULL DEFAULT '',
  dcontext     VARCHAR(80)  NOT NULL DEFAULT '',
  channel      VARCHAR(80)  NOT NULL DEFAULT '',
  dstchannel   VARCHAR(80)  NOT NULL DEFAULT '',
  lastapp      VARCHAR(80)  NOT NULL DEFAULT '',
  lastdata     VARCHAR(80)  NOT NULL DEFAULT '',
  duration     INT UNSIGNED NOT NULL DEFAULT 0,
  billsec      INT UNSIGNED NOT NULL DEFAULT 0,
  disposition  VARCHAR(45)  NOT NULL DEFAULT '',
  amaflags     INT UNSIGNED NOT NULL DEFAULT 0,
  accountcode  VARCHAR(20)  NOT NULL DEFAULT '',
  uniqueid     VARCHAR(64)  NOT NULL DEFAULT '',
  linkedid     VARCHAR(64)  NOT NULL DEFAULT '',
  peeraccount  VARCHAR(20)  NOT NULL DEFAULT '',
  userfield    VARCHAR(255) NOT NULL DEFAULT '',
  sequence     INT UNSIGNED NOT NULL DEFAULT 0,
  -- colunas próprias do Telium (preenchidas pelo dialplan via CDR(...))
  direcao      ENUM('entrada','saida','interna') NULL,
  tronco       VARCHAR(80)  NULL,
  fila         VARCHAR(20)  NULL,
  gravacao     VARCHAR(255) NULL,
  custo        DECIMAL(10,4) NULL,
  INDEX idx_cdr_calldate (calldate),
  INDEX idx_cdr_src (src),
  INDEX idx_cdr_dst (dst),
  INDEX idx_cdr_uniqueid (uniqueid),
  INDEX idx_cdr_disposition (disposition)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS cel (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  eventtype    VARCHAR(30)  NOT NULL,
  eventtime    DATETIME(6)  NOT NULL,
  cid_name     VARCHAR(80)  NOT NULL DEFAULT '',
  cid_num      VARCHAR(80)  NOT NULL DEFAULT '',
  cid_ani      VARCHAR(80)  NOT NULL DEFAULT '',
  cid_rdnis    VARCHAR(80)  NOT NULL DEFAULT '',
  cid_dnid     VARCHAR(80)  NOT NULL DEFAULT '',
  exten        VARCHAR(80)  NOT NULL DEFAULT '',
  context      VARCHAR(80)  NOT NULL DEFAULT '',
  channame     VARCHAR(80)  NOT NULL DEFAULT '',
  appname      VARCHAR(80)  NOT NULL DEFAULT '',
  appdata      VARCHAR(512) NOT NULL DEFAULT '',
  amaflags     INT UNSIGNED NOT NULL DEFAULT 0,
  accountcode  VARCHAR(20)  NOT NULL DEFAULT '',
  uniqueid     VARCHAR(64)  NOT NULL DEFAULT '',
  linkedid     VARCHAR(64)  NOT NULL DEFAULT '',
  peer         VARCHAR(80)  NOT NULL DEFAULT '',
  userdeftype  VARCHAR(255) NOT NULL DEFAULT '',
  extra        VARCHAR(512) NOT NULL DEFAULT '',
  INDEX idx_cel_uniqueid (uniqueid),
  INDEX idx_cel_linkedid (linkedid),
  INDEX idx_cel_eventtime (eventtime)
) ENGINE=InnoDB;

-- Fila de eventos para o barramento de tempo real (consumida pelo daemon PHP)
CREATE TABLE IF NOT EXISTS eventos (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo       VARCHAR(40) NOT NULL,
  payload    JSON NOT NULL,
  criado_em  TIMESTAMP(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  INDEX idx_eventos_criado (criado_em)
) ENGINE=InnoDB;
