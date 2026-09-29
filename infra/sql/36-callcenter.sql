-- =========================================================
-- Telium PABX — módulo de call center
--
-- A fila marcada como "call center" dizia que os agentes vinham de um
-- módulo que não existia, e ficava sem ninguém. Aqui ele passa a
-- existir.
--
-- O agente é uma PESSOA, não uma mesa: uma conta do console com
-- matrícula e PIN, que entra no ramal em que estiver sentada — pelo
-- navegador ou por código no telefone. As filas e o nível de habilidade
-- em cada uma vêm do cadastro dela, e o relatório é por pessoa.
--
-- Quem está logado, pausado ou falando é o Asterisk que sabe: não há
-- tabela de estado para ficar presa. O histórico é o queue_log do
-- próprio app_queue, gravado aqui por ODBC — entrada, pausa com motivo,
-- espera, atendimento e abandono, com o tempo de cada um.
--
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Agentes
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS cc_agentes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id    INT UNSIGNED NOT NULL,
  -- Só dígitos: é o que se digita no teclado do telefone (*40).
  matricula     VARCHAR(10)  NOT NULL,
  -- PIN do telefone. Nunca guardado em claro: SHA-256 de sal + PIN,
  -- calculado pela API.
  pin_sal       CHAR(32)     NULL,
  pin_hash      CHAR(64)     NULL,
  -- Sugestão do ramal na hora de entrar; quem manda é onde ele está.
  ramal_padrao  VARCHAR(20)  NULL,
  ativo         BOOLEAN      NOT NULL DEFAULT 1,
  criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cc_agente_usuario (usuario_id),
  UNIQUE KEY uq_cc_agente_matricula (matricula),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- As filas do agente e o nível de habilidade em cada uma. É a
-- penalidade do app_queue: 0 é quem recebe primeiro; 1, 2, 3… só
-- recebem quando os de nível menor estão todos ocupados — ou quando a
-- regra de ampliação da fila alcança o nível deles.
CREATE TABLE IF NOT EXISTS cc_agente_filas (
  agente_id   INT UNSIGNED NOT NULL,
  fila_id     INT UNSIGNED NOT NULL,
  penalidade  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (agente_id, fila_id),
  FOREIGN KEY (agente_id) REFERENCES cc_agentes(id) ON DELETE CASCADE,
  FOREIGN KEY (fila_id)   REFERENCES filas(id)      ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Motivos de pausa
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS cc_pausas_motivos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome            VARCHAR(40)  NOT NULL,
  -- O número do motivo no telefone: *42 seguido dele.
  codigo          TINYINT UNSIGNED NOT NULL,
  -- Passou disto, o supervisor vê a pausa em vermelho. Vazio: sem limite.
  limite_minutos  SMALLINT UNSIGNED NULL,
  -- Treinamento e reunião são trabalho; almoço não. O relatório separa.
  produtiva       BOOLEAN NOT NULL DEFAULT 0,
  -- Os do sistema são postos pela central, não escolhidos pelo agente.
  sistema         BOOLEAN NOT NULL DEFAULT 0,
  ordem           SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  ativo           BOOLEAN NOT NULL DEFAULT 1,
  UNIQUE KEY uq_cc_motivo_nome (nome),
  UNIQUE KEY uq_cc_motivo_codigo (codigo)
) ENGINE=InnoDB;

INSERT IGNORE INTO cc_pausas_motivos (nome, codigo, limite_minutos, produtiva, sistema, ordem) VALUES
  ('Almoço',          1, 60,   0, 0, 10),
  ('Banheiro',        2, 10,   0, 0, 20),
  ('Café',            3, 15,   0, 0, 30),
  ('Treinamento',     4, NULL, 1, 0, 40),
  ('Reunião',         5, NULL, 1, 0, 50),
  ('Feedback',        6, 30,   1, 0, 60),
  -- A central pausa sozinha nestes dois casos, e o nome é o que o
  -- Asterisk escreve no queue_log — não mude sem mudar o código.
  ('Pós-atendimento', 90, 5,   1, 1, 900),
  ('Não atendeu',     91, NULL, 0, 1, 910);

-- ---------------------------------------------------------
-- Tabulação: o que foi resolvido em cada atendimento
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS cc_tabulacoes (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome      VARCHAR(80)  NOT NULL,
  grupo     VARCHAR(40)  NULL,
  -- Vazio vale para todas as filas.
  fila_id   INT UNSIGNED NULL,
  ordem     SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  ativo     BOOLEAN NOT NULL DEFAULT 1,
  FOREIGN KEY (fila_id) REFERENCES filas(id) ON DELETE CASCADE,
  INDEX idx_cc_tab_fila (fila_id)
) ENGINE=InnoDB;

-- Um atendimento por agente e chamada. Nasce quando o agente atende
-- (o serviço de tempo real escreve), e é o que o painel do agente
-- mostra para tabular.
CREATE TABLE IF NOT EXISTS cc_atendimentos (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  callid        VARCHAR(80)  NOT NULL,
  fila          VARCHAR(20)  NOT NULL,
  agente_id     INT UNSIGNED NULL,
  numero        VARCHAR(40)  NULL,
  nome          VARCHAR(80)  NULL,
  espera_seg    INT UNSIGNED NOT NULL DEFAULT 0,
  atendido_em   DATETIME     NOT NULL,
  encerrado_em  DATETIME     NULL,
  tabulacao_id  INT UNSIGNED NULL,
  observacao    VARCHAR(1000) NULL,
  tabulado_em   DATETIME     NULL,
  UNIQUE KEY uq_cc_atend (callid, agente_id),
  INDEX idx_cc_atend_agente (agente_id, atendido_em),
  INDEX idx_cc_atend_fila (fila, atendido_em),
  FOREIGN KEY (agente_id)    REFERENCES cc_agentes(id)    ON DELETE SET NULL,
  FOREIGN KEY (tabulacao_id) REFERENCES cc_tabulacoes(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Retorno (callback): quem cansou de esperar aperta uma tecla, desliga
-- e é chamado de volta quando houver agente livre.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS cc_retornos (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  fila          VARCHAR(20)  NOT NULL,
  numero        VARCHAR(40)  NOT NULL,
  nome          VARCHAR(80)  NULL,
  callid        VARCHAR(80)  NULL,
  pedido_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  estado        ENUM('pendente','discando','concluido','falhou','cancelado') NOT NULL DEFAULT 'pendente',
  tentativas    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  proxima_em    DATETIME     NULL,
  agente        VARCHAR(80)  NULL,
  resultado     VARCHAR(160) NULL,
  concluido_em  DATETIME     NULL,
  INDEX idx_cc_ret_estado (estado, proxima_em),
  INDEX idx_cc_ret_fila (fila, pedido_em)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- A fila de call center ganha o que só faz sentido com agente de verdade
-- ---------------------------------------------------------
ALTER TABLE filas
  -- Depois de cada atendimento o agente fica pausado em "Pós-atendimento"
  -- até dizer o que foi resolvido.
  ADD COLUMN IF NOT EXISTS tabulacao_obrigatoria BOOLEAN NOT NULL DEFAULT 0 AFTER callcenter,
  -- Tecla que o cliente aperta na espera para pedir retorno. Vazio: não oferece.
  ADD COLUMN IF NOT EXISTS retorno_tecla CHAR(1) NULL AFTER tabulacao_obrigatoria,
  ADD COLUMN IF NOT EXISTS retorno_anuncio_id INT UNSIGNED NULL AFTER retorno_tecla,
  ADD COLUMN IF NOT EXISTS retorno_tentativas TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER retorno_anuncio_id,
  -- Ampliação de habilidade: a cada N segundos de espera, a fila passa a
  -- oferecer a chamada a um nível a mais. Zero desliga.
  ADD COLUMN IF NOT EXISTS ampliar_segundos SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER retorno_tentativas,
  ADD COLUMN IF NOT EXISTS ampliar_ate TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER ampliar_segundos;

-- ---------------------------------------------------------
-- queue_log: o histórico do app_queue, gravado pelo Asterisk por ODBC
-- (extconfig.conf). As colunas são as que ele espera, com os nomes dele.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS queue_log (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  time       DATETIME(6)  NULL,
  callid     VARCHAR(80)  NOT NULL DEFAULT '',
  queuename  VARCHAR(40)  NOT NULL DEFAULT '',
  agent      VARCHAR(80)  NOT NULL DEFAULT '',
  event      VARCHAR(32)  NOT NULL DEFAULT '',
  data1      VARCHAR(100) NOT NULL DEFAULT '',
  data2      VARCHAR(100) NOT NULL DEFAULT '',
  data3      VARCHAR(100) NOT NULL DEFAULT '',
  data4      VARCHAR(100) NOT NULL DEFAULT '',
  data5      VARCHAR(100) NOT NULL DEFAULT '',
  INDEX idx_ql_time (time),
  INDEX idx_ql_fila (queuename, time),
  INDEX idx_ql_agente (agent, time),
  INDEX idx_ql_callid (callid)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Permissões: o supervisor vê e comanda tudo; o atendente, o próprio
-- painel. O administrador já tem "*".
-- ---------------------------------------------------------
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'cc.*' FROM perfis WHERE chave = 'supervisor';

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'cc.agente' FROM perfis WHERE chave = 'operador';

-- ---------------------------------------------------------
-- Códigos de agente no telefone. Quem decide é o serviço de tempo real,
-- pela mesma classe que o console usa; o dialplan só pergunta.
-- ---------------------------------------------------------
INSERT INTO codigos_recurso (chave, nome, codigo, tipo, argumento, categoria, ordem, descricao) VALUES
 ('cc_login',  'Call center: entrar',            '*40', 'dialplan', NULL,
  'filas', 40, 'Pede o código do agente — a matrícula, ou matrícula * PIN para quem tem PIN — e o põe nas filas dele, neste ramal.'),
 ('cc_pausa',  'Call center: pausar com motivo', '*42', 'dialplan', 'motivo',
  'filas', 41, 'Disque o código e o número do motivo (1 almoço, 2 banheiro…). Vale para todas as filas.'),
 ('cc_volta',  'Call center: voltar da pausa',   '*49', 'dialplan', NULL,
  'filas', 42, 'Encerra a pausa: o agente volta a receber chamadas.'),
 ('cc_logout', 'Call center: sair',              '*44', 'dialplan', NULL,
  'filas', 43, 'Tira o agente logado neste ramal de todas as filas.')
ON DUPLICATE KEY UPDATE
  nome = VALUES(nome), descricao = VALUES(descricao), categoria = VALUES(categoria),
  ordem = VALUES(ordem), tipo = VALUES(tipo), argumento = VALUES(argumento);

-- O segredo que o dialplan leva ao serviço (127.0.0.1:8095/interno).
-- Qualquer processo da máquina alcança 127.0.0.1; o segredo é o que
-- separa o dialplan de um script qualquer pausando agentes.
INSERT IGNORE INTO sistema (chave, valor)
VALUES ('cc_segredo_interno', REPLACE(UUID(), '-', ''));
