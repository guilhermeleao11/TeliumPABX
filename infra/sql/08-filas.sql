-- =========================================================
-- Telium PABX — filas de atendimento completas
--
-- A fila ganha tudo o que o app_queue do Asterisk entrega e que faz
-- diferença no dia a dia: anúncio ao cliente, sussurro para o agente,
-- comportamento quando não há ninguém logado, peso entre filas e
-- destinos de failover separados por motivo.
--
-- Também entram a pesquisa de satisfação (o cliente vai para ela quando
-- o atendente desliga) e a marcação de fila de call center, cujos
-- agentes são atribuídos pelo módulo de call center, não aqui.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Pesquisa de satisfação
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS pesquisas (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(80)  NOT NULL,
  descricao      VARCHAR(240) NULL,
  audio_pergunta VARCHAR(160) NULL,      -- áudio do sistema; vazio = frase padrão
  audio_obrigado VARCHAR(160) NULL,
  nota_min       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  nota_max       TINYINT UNSIGNED NOT NULL DEFAULT 5,
  tentativas     TINYINT UNSIGNED NOT NULL DEFAULT 2,
  segundos       TINYINT UNSIGNED NOT NULL DEFAULT 8,   -- espera pelo dígito
  ativo          BOOLEAN NOT NULL DEFAULT 1,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pesquisa_respostas (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  pesquisa_id INT UNSIGNED NULL,
  fila        VARCHAR(20)  NULL,
  agente      VARCHAR(40)  NULL,
  origem      VARCHAR(40)  NULL,
  uniqueid    VARCHAR(64)  NULL,
  nota        TINYINT UNSIGNED NULL,     -- NULL = ouviu e desligou sem responder
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (pesquisa_id) REFERENCES pesquisas(id) ON DELETE SET NULL,
  INDEX idx_resp_fila (fila, criado_em),
  INDEX idx_resp_agente (agente, criado_em)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Fila
-- ---------------------------------------------------------
ALTER TABLE filas
  ADD COLUMN IF NOT EXISTS descricao VARCHAR(240) NULL AFTER nome,
  -- Fila de call center não recebe agente por aqui: quem atribui é o
  -- módulo de call center, na criação do agente.
  ADD COLUMN IF NOT EXISTS callcenter BOOLEAN NOT NULL DEFAULT 0 AFTER descricao,

  -- áudios
  ADD COLUMN IF NOT EXISTS audio_entrada   VARCHAR(160) NULL AFTER musica_espera,
  ADD COLUMN IF NOT EXISTS audio_agente    VARCHAR(160) NULL AFTER audio_entrada,
  ADD COLUMN IF NOT EXISTS audio_periodico VARCHAR(160) NULL AFTER audio_agente,
  ADD COLUMN IF NOT EXISTS periodico_segundos SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER audio_periodico,

  -- anúncios falados
  ADD COLUMN IF NOT EXISTS anuncio_espera     BOOLEAN NOT NULL DEFAULT 0 AFTER anuncio_posicao,
  ADD COLUMN IF NOT EXISTS anuncio_frequencia SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER anuncio_espera,

  -- comportamento
  ADD COLUMN IF NOT EXISTS peso          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS max_chamadas  SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = sem limite
  ADD COLUMN IF NOT EXISTS entrar_vazia  ENUM('sim','nao','estrito') NOT NULL DEFAULT 'sim',
  ADD COLUMN IF NOT EXISTS sair_vazia    ENUM('sim','nao','estrito') NOT NULL DEFAULT 'nao',
  ADD COLUMN IF NOT EXISTS tocar_ocupado BOOLEAN NOT NULL DEFAULT 0,             -- ringinuse
  ADD COLUMN IF NOT EXISTS pausa_automatica ENUM('nao','sim','todas') NOT NULL DEFAULT 'nao',
  ADD COLUMN IF NOT EXISTS atraso_atendimento SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS confirmar_atendimento BOOLEAN NOT NULL DEFAULT 0,

  -- failover por motivo
  ADD COLUMN IF NOT EXISTS destino_vazia_tipo  VARCHAR(24) NULL,
  ADD COLUMN IF NOT EXISTS destino_vazia_valor VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS destino_cheia_tipo  VARCHAR(24) NULL,
  ADD COLUMN IF NOT EXISTS destino_cheia_valor VARCHAR(80) NULL,

  ADD COLUMN IF NOT EXISTS pesquisa_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP;

-- A chave estrangeira só entra se ainda não existir.
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filas'
               AND CONSTRAINT_NAME = 'fk_fila_pesquisa');
SET @sql := IF(@fk = 0,
  'ALTER TABLE filas ADD CONSTRAINT fk_fila_pesquisa FOREIGN KEY (pesquisa_id) REFERENCES pesquisas(id) ON DELETE SET NULL',
  'DO 0');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

-- Agente da fila: motivo da pausa e se entrou pelo call center.
ALTER TABLE fila_agentes
  ADD COLUMN IF NOT EXISTS origem ENUM('manual','callcenter') NOT NULL DEFAULT 'manual',
  ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- ---------------------------------------------------------
-- Uma pesquisa pronta, desligada até alguém decidir usá-la.
-- ---------------------------------------------------------
INSERT IGNORE INTO pesquisas (id, nome, descricao, nota_min, nota_max, tentativas, segundos, ativo)
VALUES (1, 'Satisfação de 1 a 5',
        'Pergunta a nota logo depois que o atendente desliga. Sem áudio próprio, usa a contagem falada do Asterisk.',
        1, 5, 2, 8, 0);
