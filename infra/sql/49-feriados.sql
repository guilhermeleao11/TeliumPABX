-- Feriados: cada um com o seu áudio.
--
-- Antes, cada feriado era um grupo de horário e uma condição horária a
-- mais, encadeados na mão: Natal num, Ano Novo noutro, e a Páscoa
-- cadastrada de novo todo ano. Agora o feriado é um cadastro só (a data,
-- o áudio e, se quiser, para onde a chamada vai depois), e a condição
-- horária que "respeita feriados" consulta a lista antes do relógio.
--
-- A data é de um de três jeitos:
--   fixo   dia/mês que se repete todo ano (25/12, ou de 31/12 a 01/01)
--   data   um dia (ou alguns) de um ano só: a ponte de 2026
--   movel  calculado da Páscoa: Carnaval, Sexta-feira Santa, Páscoa,
--          Corpus Christi — sem digitar a data a cada ano
--
-- Feriado sem áudio e sem destino segue o "fora do horário" da condição.
--
-- Idempotente.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS feriados (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(80)  NOT NULL,
  tipo           ENUM('fixo','data','movel') NOT NULL DEFAULT 'fixo',
  dia_inicio     TINYINT UNSIGNED NULL,
  mes_inicio     TINYINT UNSIGNED NULL,
  dia_fim        TINYINT UNSIGNED NULL,
  mes_fim        TINYINT UNSIGNED NULL,
  data_inicio    DATE NULL,
  data_fim       DATE NULL,
  movel          VARCHAR(20)  NULL,
  -- Em branco: o dia inteiro.
  hora_inicio    TIME NULL,
  hora_fim       TIME NULL,
  audio_id       INT UNSIGNED NULL,
  -- Depois do áudio. Em branco: desliga (ou, sem áudio, o "fora" da condição).
  destino_tipo   VARCHAR(24)  NULL,
  destino_valor  VARCHAR(80)  NULL,
  ativo          BOOLEAN NOT NULL DEFAULT 1,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  -- O áudio em uso não some: apagado, o feriado ficava mudo sem aviso.
  CONSTRAINT fk_feriado_audio FOREIGN KEY (audio_id) REFERENCES audios (id) ON DELETE RESTRICT,
  INDEX idx_feriado_audio (audio_id)
) ENGINE=InnoDB;

-- A condição horária consulta os feriados antes das faixas do grupo.
-- Vale para as que já existem: sem feriado cadastrado, nada muda.
ALTER TABLE condicoes_horarias
  ADD COLUMN IF NOT EXISTS feriados BOOLEAN NOT NULL DEFAULT 1 AFTER destino_fora_valor;

-- A tela vai para quem já cuida das condições horárias. Só uma vez: se o
-- administrador tirar a tela de um perfil, a próxima atualização não a
-- devolve.
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT DISTINCT pm.perfil_id, 'apps.feriados' FROM perfil_modulos pm
 WHERE pm.modulo = 'apps.condicoes'
   AND NOT EXISTS (SELECT 1 FROM sistema WHERE chave = 'feriados_modulo_dado');

INSERT IGNORE INTO sistema (chave, valor) VALUES ('feriados_modulo_dado', '1');
