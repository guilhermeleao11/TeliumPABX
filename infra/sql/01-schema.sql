-- =========================================================
-- Telium PABX — schema da aplicação
-- MariaDB 12.3 · utf8mb4 · InnoDB
-- Idempotente: pode ser reaplicado sem perda de dados.
-- =========================================================
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- Identidade da central (linha única — PABX é single-tenant)
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS empresa (
  id                  TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  nome                VARCHAR(120)  NOT NULL,
  razao_social        VARCHAR(160)  NULL,
  cnpj                VARCHAR(20)   NULL,
  endereco            VARCHAR(200)  NULL,
  telefone            VARCHAR(30)   NULL,
  fuso                VARCHAR(60)   NOT NULL DEFAULT 'America/Sao_Paulo',
  idioma              VARCHAR(20)   NOT NULL DEFAULT 'pt_BR',
  plano               VARCHAR(40)   NULL,
  ramais_contratados  SMALLINT UNSIGNED NOT NULL DEFAULT 50,
  logo_path           VARCHAR(200)  NULL,
  tema_padrao         ENUM('claro','escuro','sistema') NOT NULL DEFAULT 'claro',
  atualizado_em       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT empresa_linha_unica CHECK (id = 1)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Perfis, permissões e usuários
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS perfis (
  id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave       VARCHAR(32)  NOT NULL UNIQUE,      -- admin | supervisor | operador | auditor
  nome        VARCHAR(60)  NOT NULL,
  descricao   VARCHAR(240) NULL,
  cor         VARCHAR(20)  NOT NULL DEFAULT 'brand',
  sistema     BOOLEAN NOT NULL DEFAULT 0,        -- perfis de sistema não podem ser excluídos
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Módulos liberados no menu. Aceita curinga: 'dash.*' ou '*'
CREATE TABLE IF NOT EXISTS perfil_modulos (
  perfil_id  SMALLINT UNSIGNED NOT NULL,
  modulo     VARCHAR(64) NOT NULL,
  PRIMARY KEY (perfil_id, modulo),
  FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Ações permitidas: criar | editar | excluir | exportar | reiniciar | permissoes
CREATE TABLE IF NOT EXISTS perfil_acoes (
  perfil_id  SMALLINT UNSIGNED NOT NULL,
  acao       VARCHAR(24) NOT NULL,
  PRIMARY KEY (perfil_id, acao),
  FOREIGN KEY (perfil_id) REFERENCES perfis(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS usuarios (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario        VARCHAR(64)  NOT NULL UNIQUE,
  nome           VARCHAR(120) NOT NULL,
  email          VARCHAR(160) NULL,
  senha_hash     VARCHAR(255) NOT NULL,          -- pbkdf2_sha256$iter$salt$hash
  perfil_id      SMALLINT UNSIGNED NOT NULL,
  ramal          VARCHAR(20)  NULL,
  setor          VARCHAR(80)  NULL,
  status         ENUM('ativo','inativo','bloqueado') NOT NULL DEFAULT 'ativo',
  totp_secret    VARCHAR(64)  NULL,
  totp_ativo     BOOLEAN NOT NULL DEFAULT 0,
  tentativas_login TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_ate  DATETIME NULL,
  ultimo_acesso  DATETIME NULL,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (perfil_id) REFERENCES perfis(id),
  INDEX idx_usuarios_ramal (ramal)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sessoes (
  id             CHAR(64) PRIMARY KEY,           -- sha256 do token
  usuario_id     INT UNSIGNED NOT NULL,
  ip             VARCHAR(45)  NULL,
  user_agent     VARCHAR(255) NULL,
  criada_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultima_atividade DATETIME NOT NULL,
  expira_em      DATETIME NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_sessoes_usuario (usuario_id),
  INDEX idx_sessoes_expira (expira_em)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Telefonia — origem da verdade dos .conf gerados
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS ramais (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero         VARCHAR(20)  NOT NULL UNIQUE,
  nome           VARCHAR(120) NOT NULL,
  setor          VARCHAR(80)  NULL,
  email          VARCHAR(160) NULL,
  tecnologia     ENUM('pjsip','dahdi') NOT NULL DEFAULT 'pjsip',
  senha_sip      VARCHAR(120) NOT NULL,
  contexto       VARCHAR(60)  NOT NULL DEFAULT 'interno',
  transporte     ENUM('udp','tcp','tls','wss') NOT NULL DEFAULT 'udp',
  codecs         VARCHAR(120) NOT NULL DEFAULT 'opus,alaw,ulaw,g722',
  max_contatos   TINYINT UNSIGNED NOT NULL DEFAULT 2,
  srtp           BOOLEAN NOT NULL DEFAULT 0,
  webrtc         BOOLEAN NOT NULL DEFAULT 0,
  callgroup      VARCHAR(40)  NULL,
  pickupgroup    VARCHAR(40)  NULL,
  redes_permitidas VARCHAR(255) NULL,
  voicemail      BOOLEAN NOT NULL DEFAULT 1,
  vm_senha       VARCHAR(20)  NULL,
  vm_email       BOOLEAN NOT NULL DEFAULT 1,
  vm_apagar      BOOLEAN NOT NULL DEFAULT 0,
  gravar         ENUM('nao','entrada','saida','ambas') NOT NULL DEFAULT 'nao',
  dnd            BOOLEAN NOT NULL DEFAULT 0,
  siga_me        VARCHAR(40)  NULL,
  tempo_toque    SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  pin_set_id     INT UNSIGNED NULL,
  perm_local     BOOLEAN NOT NULL DEFAULT 1,
  perm_celular   BOOLEAN NOT NULL DEFAULT 1,
  perm_ddd       BOOLEAN NOT NULL DEFAULT 1,
  perm_ddi       BOOLEAN NOT NULL DEFAULT 0,
  no_diretorio   BOOLEAN NOT NULL DEFAULT 1,
  ativo          BOOLEAN NOT NULL DEFAULT 1,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS troncos (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(80)  NOT NULL UNIQUE,
  tipo           ENUM('pjsip','dahdi') NOT NULL DEFAULT 'pjsip',
  host           VARCHAR(160) NULL,
  porta          SMALLINT UNSIGNED NOT NULL DEFAULT 5060,
  transporte     ENUM('udp','tcp','tls') NOT NULL DEFAULT 'udp',
  usuario        VARCHAR(80)  NULL,
  senha          VARCHAR(120) NULL,
  registrar      BOOLEAN NOT NULL DEFAULT 1,
  from_user      VARCHAR(80)  NULL,
  from_domain    VARCHAR(160) NULL,
  contexto_entrada VARCHAR(60) NOT NULL DEFAULT 'de-tronco',
  codecs         VARCHAR(120) NOT NULL DEFAULT 'alaw,ulaw,g729',
  canais_max     SMALLINT UNSIGNED NULL,
  cid_saida      VARCHAR(40)  NULL,
  ativo          BOOLEAN NOT NULL DEFAULT 1,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grupos_horario (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome   VARCHAR(80) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grupo_horario_faixas (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  grupo_id  INT UNSIGNED NOT NULL,
  dias      VARCHAR(40) NOT NULL DEFAULT 'mon-fri',   -- formato do dialplan
  dia_mes   VARCHAR(20) NOT NULL DEFAULT '*',
  mes       VARCHAR(20) NOT NULL DEFAULT '*',
  hora_inicio TIME NOT NULL,
  hora_fim    TIME NOT NULL,
  FOREIGN KEY (grupo_id) REFERENCES grupos_horario(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS condicoes_horarias (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome               VARCHAR(80) NOT NULL UNIQUE,
  grupo_horario_id   INT UNSIGNED NOT NULL,
  destino_dentro_tipo  VARCHAR(24) NOT NULL,
  destino_dentro_valor VARCHAR(80) NOT NULL,
  destino_fora_tipo    VARCHAR(24) NOT NULL,
  destino_fora_valor   VARCHAR(80) NOT NULL,
  FOREIGN KEY (grupo_horario_id) REFERENCES grupos_horario(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rotas_entrada (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  did            VARCHAR(40)  NOT NULL,
  descricao      VARCHAR(120) NULL,
  cid_origem     VARCHAR(40)  NOT NULL DEFAULT '',
  destino_tipo   VARCHAR(24)  NOT NULL,          -- ramal|fila|ura|condicao|voicemail|desligar|externo
  destino_valor  VARCHAR(80)  NOT NULL,
  gravar         BOOLEAN NOT NULL DEFAULT 0,
  ordem          SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  ativo          BOOLEAN NOT NULL DEFAULT 1,
  UNIQUE KEY uk_did_cid (did, cid_origem)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS rotas_saida (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome              VARCHAR(80) NOT NULL UNIQUE,
  ordem             SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  padrao            VARCHAR(80) NOT NULL,        -- padrão do dialplan: _9XXXXXXXX
  prefixo_remover   VARCHAR(12) NULL,
  prefixo_adicionar VARCHAR(12) NULL,
  tronco_id         INT UNSIGNED NOT NULL,
  tronco_falha_id   INT UNSIGNED NULL,
  pin_set_id        INT UNSIGNED NULL,
  classe            ENUM('local','celular','ddd','ddi','emergencia','especial') NOT NULL DEFAULT 'local',
  ativo             BOOLEAN NOT NULL DEFAULT 1,
  FOREIGN KEY (tronco_id) REFERENCES troncos(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS pin_sets (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome      VARCHAR(60) NOT NULL UNIQUE,
  pins      TEXT NOT NULL,
  no_cdr    BOOLEAN NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS filas (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero                VARCHAR(20) NOT NULL UNIQUE,
  nome                  VARCHAR(80) NOT NULL,
  estrategia            VARCHAR(24) NOT NULL DEFAULT 'ringall',
  timeout_agente        SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  retry                 SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  wrapuptime            SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  musica_espera         VARCHAR(60) NOT NULL DEFAULT 'default',
  anuncio_posicao       BOOLEAN NOT NULL DEFAULT 1,
  sla_segundos          SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  max_espera            SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  destino_estouro_tipo  VARCHAR(24) NULL,
  destino_estouro_valor VARCHAR(80) NULL,
  gravar                BOOLEAN NOT NULL DEFAULT 1,
  ativo                 BOOLEAN NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fila_agentes (
  fila_id    INT UNSIGNED NOT NULL,
  ramal_id   INT UNSIGNED NOT NULL,
  penalidade TINYINT UNSIGNED NOT NULL DEFAULT 0,
  tipo       ENUM('estatico','dinamico') NOT NULL DEFAULT 'estatico',
  PRIMARY KEY (fila_id, ramal_id),
  FOREIGN KEY (fila_id) REFERENCES filas(id) ON DELETE CASCADE,
  FOREIGN KEY (ramal_id) REFERENCES ramais(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS grupos_toque (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero         VARCHAR(20) NOT NULL UNIQUE,
  nome           VARCHAR(80) NOT NULL,
  estrategia     VARCHAR(24) NOT NULL DEFAULT 'ringall',
  ramais         VARCHAR(255) NOT NULL,          -- 1000-1010-2031
  tempo_toque    SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  destino_falha_tipo  VARCHAR(24) NULL,
  destino_falha_valor VARCHAR(80) NULL,
  ativo          BOOLEAN NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ura (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome                   VARCHAR(80) NOT NULL UNIQUE,
  audio                  VARCHAR(120) NOT NULL,
  timeout_digito         TINYINT UNSIGNED NOT NULL DEFAULT 8,
  tentativas             TINYINT UNSIGNED NOT NULL DEFAULT 3,
  discagem_direta        BOOLEAN NOT NULL DEFAULT 1,
  destino_timeout_tipo   VARCHAR(24) NULL,
  destino_timeout_valor  VARCHAR(80) NULL,
  destino_invalido_tipo  VARCHAR(24) NULL,
  destino_invalido_valor VARCHAR(80) NULL,
  ativo                  BOOLEAN NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS ura_opcoes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ura_id         INT UNSIGNED NOT NULL,
  tecla          VARCHAR(4)  NOT NULL,
  rotulo         VARCHAR(80) NOT NULL,
  destino_tipo   VARCHAR(24) NOT NULL,
  destino_valor  VARCHAR(80) NOT NULL,
  ordem          SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  UNIQUE KEY uk_ura_tecla (ura_id, tecla),
  FOREIGN KEY (ura_id) REFERENCES ura(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Operação
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS dispositivos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mac        VARCHAR(17) NOT NULL UNIQUE,
  modelo     VARCHAR(80) NULL,
  fabricante VARCHAR(40) NULL,
  ramal_id   INT UNSIGNED NULL,
  firmware   VARCHAR(40) NULL,
  ip         VARCHAR(45) NULL,
  estado     ENUM('pendente','provisionado','erro') NOT NULL DEFAULT 'pendente',
  visto_em   DATETIME NULL,
  FOREIGN KEY (ramal_id) REFERENCES ramais(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS contatos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,                  -- NULL = agenda corporativa
  nome       VARCHAR(120) NOT NULL,
  numero     VARCHAR(40)  NOT NULL,
  grupo      VARCHAR(60)  NULL,
  favorito   BOOLEAN NOT NULL DEFAULT 0,
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  INDEX idx_contatos_nome (nome)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gravacoes (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  uniqueid   VARCHAR(64) NOT NULL,
  arquivo    VARCHAR(255) NOT NULL,
  origem     VARCHAR(40) NULL,
  destino    VARCHAR(40) NULL,
  ramal      VARCHAR(20) NULL,
  fila       VARCHAR(20) NULL,
  agente     VARCHAR(120) NULL,
  inicio     DATETIME NOT NULL,
  duracao    INT UNSIGNED NOT NULL DEFAULT 0,
  tamanho    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tags       VARCHAR(160) NULL,
  INDEX idx_grav_uniqueid (uniqueid),
  INDEX idx_grav_inicio (inicio)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tarifas (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(60) NOT NULL,
  padrao        VARCHAR(60) NOT NULL,
  custo_minuto  DECIMAL(10,4) NOT NULL DEFAULT 0,
  taxa_fixa     DECIMAL(10,4) NOT NULL DEFAULT 0,
  incremento_seg SMALLINT UNSIGNED NOT NULL DEFAULT 6,
  ativo         BOOLEAN NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS integracoes (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(120) NOT NULL,
  tipo           ENUM('webhook','api_key','syslog','app') NOT NULL,
  url            VARCHAR(255) NULL,
  segredo        VARCHAR(120) NULL,
  eventos        VARCHAR(255) NULL,
  ativo          BOOLEAN NOT NULL DEFAULT 1,
  ultimo_disparo DATETIME NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS auditoria (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id   INT UNSIGNED NULL,
  usuario_nome VARCHAR(120) NULL,
  acao         VARCHAR(40)  NOT NULL,            -- criar|editar|excluir|aplicar|login|logout|exportar
  modulo       VARCHAR(64)  NOT NULL,
  objeto       VARCHAR(120) NULL,
  detalhe      JSON NULL,
  ip           VARCHAR(45)  NULL,
  criado_em    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_auditoria_data (criado_em),
  INDEX idx_auditoria_modulo (modulo)
) ENGINE=InnoDB;

-- Histórico do "Aplicar configurações": quais arquivos foram gerados e o retorno do reload
CREATE TABLE IF NOT EXISTS config_aplicacoes (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id  INT UNSIGNED NULL,
  arquivos    JSON NOT NULL,
  reloads     JSON NULL,
  sucesso     BOOLEAN NOT NULL DEFAULT 0,
  saida       TEXT NULL,
  criado_em   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Chave/valor de estado do sistema (ex.: config_pendente = 1)
CREATE TABLE IF NOT EXISTS sistema (
  chave      VARCHAR(60) PRIMARY KEY,
  valor      TEXT NULL,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
