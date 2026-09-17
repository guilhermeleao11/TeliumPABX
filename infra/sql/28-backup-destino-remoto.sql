-- Para onde o backup vai depois de pronto.
--
-- Backup guardado só na máquina que ele deveria salvar não é backup:
-- perdido o servidor, perde-se junto. Faltava qualquer caminho de saída
-- automático — e o download pelo console, que também faltava, só
-- resolve quando alguém lembra de clicar.
--
-- Uma linha só, como os outros ajustes gerais do sistema.
-- Idempotente: rodar de novo não faz nada.

CREATE TABLE IF NOT EXISTS backup_destino_remoto (
  id            TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  ativo         TINYINT(1)   NOT NULL DEFAULT 0,
  -- ftps é FTP com TLS explícito (AUTH TLS), que é o que se deve usar:
  -- o ftp puro manda usuário e senha em texto claro pela rede.
  tipo          ENUM('ftp','ftps','sftp') NOT NULL DEFAULT 'ftps',
  host          VARCHAR(160) NOT NULL DEFAULT '',
  porta         SMALLINT UNSIGNED NOT NULL DEFAULT 21,
  usuario       VARCHAR(120) NOT NULL DEFAULT '',
  senha         VARCHAR(160) NOT NULL DEFAULT '',
  caminho       VARCHAR(200) NOT NULL DEFAULT '/',
  passivo       TINYINT(1)   NOT NULL DEFAULT 1,
  -- Certificado autoassinado do outro lado: aceitar é escolha de quem
  -- configura, e fica registrada em vez de virar padrão escondido.
  aceitar_cert_invalido TINYINT(1) NOT NULL DEFAULT 0,
  ultimo_envio  DATETIME NULL,
  ultimo_estado ENUM('nunca','ok','falha') NOT NULL DEFAULT 'nunca',
  ultima_saida  TEXT NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO backup_destino_remoto (id) VALUES (1);
