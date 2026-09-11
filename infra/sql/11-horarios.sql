-- =========================================================
-- Telium PABX — grupos de horário e condições horárias
--
-- O grupo de horário guarda as faixas: hora, dia da semana, dia do mês
-- e mês. A condição horária pergunta "estamos dentro deste grupo?" e
-- manda a chamada para um destino ou para o outro.
--
-- As faixas deixaram de guardar o texto pronto do dialplan e passaram a
-- guardar início e fim de cada dimensão — é o que a tela mostra e o que
-- deixa o gerador montar o GotoIfTime sem ninguém decorar a sintaxe.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

ALTER TABLE grupos_horario
  ADD COLUMN IF NOT EXISTS descricao VARCHAR(240) NULL AFTER nome,
  ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

ALTER TABLE grupo_horario_faixas
  -- NULL em qualquer par significa "vale sempre" naquela dimensão.
  ADD COLUMN IF NOT EXISTS dia_semana_inicio TINYINT UNSIGNED NULL,   -- 0 = domingo
  ADD COLUMN IF NOT EXISTS dia_semana_fim    TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS dia_mes_inicio    TINYINT UNSIGNED NULL,   -- 1..31
  ADD COLUMN IF NOT EXISTS dia_mes_fim       TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS mes_inicio        TINYINT UNSIGNED NULL,   -- 1..12
  ADD COLUMN IF NOT EXISTS mes_fim           TINYINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS ordem             SMALLINT UNSIGNED NOT NULL DEFAULT 10;

-- A hora também pode ser "o dia inteiro".
ALTER TABLE grupo_horario_faixas
  MODIFY COLUMN hora_inicio TIME NULL,
  MODIFY COLUMN hora_fim    TIME NULL;

ALTER TABLE condicoes_horarias
  ADD COLUMN IF NOT EXISTS descricao VARCHAR(240) NULL AFTER nome,
  ADD COLUMN IF NOT EXISTS ativo     BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP;

-- Uma condição pode ficar sem destino escolhido enquanto está sendo montada.
ALTER TABLE condicoes_horarias
  MODIFY COLUMN destino_dentro_tipo  VARCHAR(24) NULL,
  MODIFY COLUMN destino_dentro_valor VARCHAR(80) NULL,
  MODIFY COLUMN destino_fora_tipo    VARCHAR(24) NULL,
  MODIFY COLUMN destino_fora_valor   VARCHAR(80) NULL;
