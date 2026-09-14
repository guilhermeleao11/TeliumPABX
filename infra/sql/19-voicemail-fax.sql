-- =========================================================
-- Telium PABX — parâmetros gerais do correio de voz e do fax
--
-- Estavam presos no template do Ansible: mudar o tamanho máximo de um
-- recado exigia editar arquivo no servidor e rodar o playbook. São
-- ajustes de operação, não de instalação.
-- =========================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS voicemail_geral (
  id             TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  max_mensagens  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  max_segundos   SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  min_segundos   TINYINT UNSIGNED  NOT NULL DEFAULT 2,
  max_tentativas TINYINT UNSIGNED  NOT NULL DEFAULT 3,
  formato        VARCHAR(40)  NOT NULL DEFAULT 'wav49|gsm|wav',
  anexar         BOOLEAN      NOT NULL DEFAULT 1,
  dizer_hora     BOOLEAN      NOT NULL DEFAULT 1,
  dizer_origem   BOOLEAN      NOT NULL DEFAULT 1,
  apagar_apos_email BOOLEAN   NOT NULL DEFAULT 0,
  assunto        VARCHAR(160) NOT NULL DEFAULT 'Nova mensagem de voz de ${VM_CALLERID}',
  corpo          TEXT         NULL,
  atualizado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT ck_vm_linha_unica CHECK (id = 1)
) ENGINE=InnoDB;

INSERT IGNORE INTO voicemail_geral (id) VALUES (1);

CREATE TABLE IF NOT EXISTS fax_geral (
  id            TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  ativo         BOOLEAN      NOT NULL DEFAULT 1,
  ecm           BOOLEAN      NOT NULL DEFAULT 1,
  cabecalho     VARCHAR(120) NOT NULL DEFAULT '',
  email_destino VARCHAR(160) NOT NULL DEFAULT '',
  minimo_bits   INT UNSIGNED NOT NULL DEFAULT 4800,
  maximo_bits   INT UNSIGNED NOT NULL DEFAULT 14400,
  atualizado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT ck_fax_linha_unica CHECK (id = 1)
) ENGINE=InnoDB;

INSERT IGNORE INTO fax_geral (id) VALUES (1);

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'cfg.correiovoz' FROM perfis WHERE chave = 'admin';
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'cfg.fax' FROM perfis WHERE chave = 'admin';
