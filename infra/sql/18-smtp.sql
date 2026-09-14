-- =========================================================
-- Telium PABX — envio de e-mail
--
-- O cadastro do ramal oferece "receber recado por e-mail" desde sempre,
-- o voicemail.conf aponta para /usr/sbin/sendmail — e nenhum servidor
-- de e-mail era instalado. Toda cópia de recado falhava em silêncio.
--
-- Uma linha só: a central manda e-mail por um relay, não por um servidor
-- próprio. Servidor de e-mail em PABX de cliente é caixa de entrada
-- bloqueada por reputação de IP em três dias.
-- =========================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS smtp (
  id          TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  ativo       BOOLEAN      NOT NULL DEFAULT 0,
  servidor    VARCHAR(160) NOT NULL DEFAULT '',
  porta       SMALLINT UNSIGNED NOT NULL DEFAULT 587,
  seguranca   ENUM('starttls','tls','nenhuma') NOT NULL DEFAULT 'starttls',
  usuario     VARCHAR(160) NOT NULL DEFAULT '',
  senha       VARCHAR(255) NOT NULL DEFAULT '',
  remetente   VARCHAR(160) NOT NULL DEFAULT '',
  nome_remetente VARCHAR(120) NOT NULL DEFAULT '',
  testado_em  DATETIME     NULL,
  teste_ok    BOOLEAN      NULL,
  teste_saida TEXT         NULL,
  atualizado_em TIMESTAMP  NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT ck_smtp_linha_unica CHECK (id = 1)
) ENGINE=InnoDB;

INSERT IGNORE INTO smtp (id) VALUES (1);

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'cfg.notificacoes' FROM perfis WHERE chave = 'admin';
