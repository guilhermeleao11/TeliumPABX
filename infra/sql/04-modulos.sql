-- =========================================================
-- Telium PABX — módulos operacionais
-- Backup, áudios do sistema, códigos de recurso e lista negra.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Backup: rotinas agendadas e histórico de execuções
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS backup_rotinas (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome             VARCHAR(80)  NOT NULL,
  periodicidade    ENUM('diaria','semanal','mensal','manual') NOT NULL DEFAULT 'diaria',
  hora             TIME         NOT NULL DEFAULT '03:00:00',
  dia_semana       TINYINT UNSIGNED NULL,          -- 0=domingo … 6=sábado
  dia_mes          TINYINT UNSIGNED NULL,          -- 1..28
  inclui_banco     BOOLEAN NOT NULL DEFAULT 1,
  inclui_config    BOOLEAN NOT NULL DEFAULT 1,     -- /etc/asterisk e /etc/telium
  inclui_audios    BOOLEAN NOT NULL DEFAULT 1,     -- sons e anúncios
  inclui_gravacoes BOOLEAN NOT NULL DEFAULT 0,     -- pesado: chamadas gravadas
  retencao         TINYINT UNSIGNED NOT NULL DEFAULT 5,
  ativo            BOOLEAN NOT NULL DEFAULT 1,
  ultimo_em        DATETIME NULL,
  criado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS backups (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rotina_id        INT UNSIGNED NULL,
  arquivo          VARCHAR(255) NULL,
  tamanho          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  origem           ENUM('manual','agendado') NOT NULL DEFAULT 'manual',
  estado           ENUM('pendente','executando','concluido','falha') NOT NULL DEFAULT 'pendente',
  inclui_banco     BOOLEAN NOT NULL DEFAULT 1,
  inclui_config    BOOLEAN NOT NULL DEFAULT 1,
  inclui_audios    BOOLEAN NOT NULL DEFAULT 1,
  inclui_gravacoes BOOLEAN NOT NULL DEFAULT 0,
  retencao         TINYINT UNSIGNED NOT NULL DEFAULT 5,
  usuario_id       INT UNSIGNED NULL,
  iniciado_em      DATETIME NULL,
  concluido_em     DATETIME NULL,
  saida            TEXT NULL,
  criado_em        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (rotina_id) REFERENCES backup_rotinas(id) ON DELETE SET NULL,
  INDEX idx_backups_estado (estado),
  INDEX idx_backups_criado (criado_em)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Áudios do sistema: anúncios, saudações de URA, música em espera
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS audios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(120) NOT NULL,
  arquivo       VARCHAR(160) NOT NULL UNIQUE,      -- nome base, sem extensão
  descricao     VARCHAR(240) NULL,
  categoria     ENUM('ura','anuncio','espera','fila','sistema') NOT NULL DEFAULT 'anuncio',
  duracao       INT UNSIGNED NOT NULL DEFAULT 0,   -- segundos
  tamanho       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  formatos      VARCHAR(80)  NOT NULL DEFAULT 'wav',
  enviado_por   INT UNSIGNED NULL,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (enviado_por) REFERENCES usuarios(id) ON DELETE SET NULL,
  INDEX idx_audios_categoria (categoria)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Códigos de recurso: *8 captura, *43 eco, e assim por diante
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS codigos_recurso (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave      VARCHAR(40)  NOT NULL UNIQUE,         -- identificador interno, fixo
  nome       VARCHAR(80)  NOT NULL,
  codigo     VARCHAR(16)  NOT NULL,                -- o que o usuário disca
  descricao  VARCHAR(240) NULL,
  categoria  VARCHAR(40)  NOT NULL DEFAULT 'geral',
  ativo      BOOLEAN NOT NULL DEFAULT 1,
  UNIQUE KEY uk_codigo (codigo)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Lista negra e allowlist de chamadas entrantes
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS lista_negra (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero      VARCHAR(40)  NOT NULL UNIQUE,        -- número ou padrão do dialplan
  descricao   VARCHAR(240) NULL,
  tratamento  ENUM('desligar','ocupado','anuncio','silencio') NOT NULL DEFAULT 'desligar',
  audio_id    INT UNSIGNED NULL,
  bloqueios   INT UNSIGNED NOT NULL DEFAULT 0,
  ultimo_em   DATETIME NULL,
  ativo       BOOLEAN NOT NULL DEFAULT 1,
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (audio_id) REFERENCES audios(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lista_permitida (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero      VARCHAR(40)  NOT NULL UNIQUE,
  descricao   VARCHAR(240) NULL,
  ativo       BOOLEAN NOT NULL DEFAULT 1,
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Códigos padrão. São os mesmos que o dialplan já usava fixos;
-- a diferença é que agora podem ser trocados pelo console.
-- ---------------------------------------------------------
INSERT IGNORE INTO codigos_recurso (chave, nome, codigo, descricao, categoria) VALUES
 ('eco',          'Teste de eco',             '*43', 'Devolve o próprio áudio, para testar o aparelho.', 'diagnostico'),
 ('hora',         'Hora certa',               '*60', 'Fala a hora do servidor.', 'diagnostico'),
 ('meu_ramal',    'Informar meu ramal',       '*65', 'Fala o número do ramal que ligou.', 'diagnostico'),
 ('vm_proprio',   'Meu correio de voz',       '*97', 'Entra na caixa postal do próprio ramal.', 'correio'),
 ('vm_outro',     'Correio de voz de outro',  '*98', 'Pede o número da caixa postal.', 'correio'),
 ('captura',      'Capturar chamada do grupo','*8',  'Atende uma chamada que toca em outro ramal do grupo.', 'atendimento'),
 ('captura_dir',  'Capturar ramal específico','**',  'Seguido do ramal: atende a chamada dele.', 'atendimento'),
 ('diretorio',    'Diretório por nome',       '*411','Procura um ramal soletrando o nome.', 'atendimento'),
 ('dnd_ligar',    'Ativar não perturbe',      '*78', 'Manda as chamadas direto ao correio.', 'desvio'),
 ('dnd_desligar', 'Desativar não perturbe',   '*79', 'Volta a receber chamadas.', 'desvio'),
 ('sigame_ligar', 'Ativar siga-me',           '*21', 'Seguido do destino: desvia as chamadas.', 'desvio'),
 ('sigame_desligar','Desativar siga-me',      '*22', 'Cancela o desvio.', 'desvio'),
 ('gravar_alterna','Gravar esta chamada',     '*1',  'Liga ou desliga a gravação durante a chamada.', 'gravacao'),
 ('estacionar',   'Estacionar chamada',       '*70', 'Coloca a chamada em uma vaga de espera.', 'atendimento');

-- rotina de backup padrão, desligada até alguém decidir
INSERT IGNORE INTO backup_rotinas (id, nome, periodicidade, hora, inclui_banco, inclui_config,
                                   inclui_audios, inclui_gravacoes, retencao, ativo)
VALUES (1, 'Backup diário', 'diaria', '03:00:00', 1, 1, 1, 0, 5, 0);
