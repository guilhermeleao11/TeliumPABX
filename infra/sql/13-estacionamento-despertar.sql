-- =========================================================
-- Telium PABX — estacionamento de chamadas e despertadores
--
-- Estacionamento é a configuração do res_parking: o número para onde se
-- transfere, a faixa de vagas, quanto tempo a chamada espera e o que
-- acontece quando ninguém a retoma.
--
-- Despertador é a chamada automática: o PABX liga para um ramal na hora
-- marcada e toca um anúncio.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS estacionamentos (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome             VARCHAR(60)  NOT NULL UNIQUE,   -- vira o nome do lote no Asterisk
  descricao        VARCHAR(240) NULL,
  padrao           BOOLEAN NOT NULL DEFAULT 0,     -- o lote que os códigos *85 e *86 usam
  numero_estacionar VARCHAR(20) NOT NULL,          -- para onde se transfere a chamada
  vaga_inicio      VARCHAR(20)  NOT NULL,
  vaga_fim         VARCHAR(20)  NOT NULL,
  tempo_segundos   SMALLINT UNSIGNED NOT NULL DEFAULT 45,
  musica_espera    VARCHAR(60)  NOT NULL DEFAULT 'default',
  -- Estourado o tempo: volta para quem estacionou, ou vai ao destino abaixo.
  volta_para_origem BOOLEAN NOT NULL DEFAULT 1,
  tempo_volta      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  destino_tipo     VARCHAR(24) NULL,
  destino_valor    VARCHAR(80) NULL,
  -- Tom curto avisando o número da vaga, para quem estacionou.
  avisar_vaga      BOOLEAN NOT NULL DEFAULT 1,
  primeira_vaga_livre BOOLEAN NOT NULL DEFAULT 1,  -- findslot: first ou next
  ativo            BOOLEAN NOT NULL DEFAULT 1,
  criado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO estacionamentos
  (id, nome, descricao, padrao, numero_estacionar, vaga_inicio, vaga_fim, tempo_segundos)
VALUES (1, 'default', 'Lote padrão, usado pelos códigos *85 e *86', 1, '70', '71', '80', 45);

-- ---------------------------------------------------------
-- Despertadores: o PABX liga e toca um anúncio na hora marcada.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS despertadores (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome         VARCHAR(80)  NOT NULL,
  ramal        VARCHAR(20)  NOT NULL,            -- para quem o PABX liga
  anuncio_id   INT UNSIGNED NULL,                -- o que toca quando atendem

  repeticao    ENUM('uma_vez','diario','dias_semana') NOT NULL DEFAULT 'uma_vez',
  quando       DATETIME NULL,                    -- uma_vez: data e hora exatas
  hora         TIME NULL,                        -- diario e dias_semana
  dias_semana  VARCHAR(20) NULL,                 -- '1,2,3,4,5' — 0 é domingo

  tentativas   TINYINT UNSIGNED NOT NULL DEFAULT 3,
  intervalo    SMALLINT UNSIGNED NOT NULL DEFAULT 120,  -- segundos entre tentativas

  ativo        BOOLEAN NOT NULL DEFAULT 1,
  ultimo_em    DATETIME NULL,
  ultimo_estado ENUM('atendido','nao_atendeu','falha') NULL,
  criado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (anuncio_id) REFERENCES anuncios(id) ON DELETE SET NULL,
  INDEX idx_desp_ativo (ativo, repeticao)
) ENGINE=InnoDB;
