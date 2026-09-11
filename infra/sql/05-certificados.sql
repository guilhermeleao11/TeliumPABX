-- =========================================================
-- Telium PABX — certificados TLS
-- Um cofre de certificados enviados pelo console e a ligação
-- de cada serviço (web, Asterisk, Janus) com o par que ele usa.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS certificados (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(120) NOT NULL,
  base          VARCHAR(80)  NOT NULL UNIQUE,     -- nome dos arquivos no cofre, sem extensão
  descricao     VARCHAR(240) NULL,
  origem        ENUM('upload','autoassinado','csr') NOT NULL DEFAULT 'upload',
  estado        ENUM('aguardando_assinatura','pronto') NOT NULL DEFAULT 'pronto',
  cn            VARCHAR(160) NULL,                -- Common Name
  san           TEXT NULL,                        -- nomes alternativos, um por linha
  emissor       VARCHAR(240) NULL,
  serie         VARCHAR(80)  NULL,
  algoritmo     VARCHAR(40)  NULL,                -- RSA 2048, EC prime256v1…
  assinatura    VARCHAR(40)  NULL,                -- sha256WithRSAEncryption…
  impressao     VARCHAR(120) NULL,                -- SHA-256 fingerprint
  autoassinado  BOOLEAN NOT NULL DEFAULT 0,
  tem_cadeia    BOOLEAN NOT NULL DEFAULT 0,
  valido_de     DATETIME NULL,
  valido_ate    DATETIME NULL,
  enviado_por   INT UNSIGNED NULL,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (enviado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_cert_validade (valido_ate)
) ENGINE=InnoDB;

-- Uma linha por serviço. Existe sempre, mesmo sem certificado atribuído,
-- para a tela poder mostrar o que ainda está no autoassinado de fábrica.
CREATE TABLE IF NOT EXISTS certificado_servicos (
  servico        ENUM('web','asterisk','janus') PRIMARY KEY,
  certificado_id INT UNSIGNED NULL,
  estado         ENUM('fabrica','pendente','aplicado','falha') NOT NULL DEFAULT 'fabrica',
  aplicado_em    DATETIME NULL,
  saida          TEXT NULL,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (certificado_id) REFERENCES certificados(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO certificado_servicos (servico, estado) VALUES
 ('web', 'fabrica'), ('asterisk', 'fabrica'), ('janus', 'fabrica');
