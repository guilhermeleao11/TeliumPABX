-- Integração com o Kommo (CRM).
--
-- Três coisas:
--   clique para ligar  o app instalado no Kommo manda o telefone do
--                      contato e quem clicou; a central toca o ramal
--                      dessa pessoa e, quando ele atende, disca o cliente
--   registro           cada chamada de entrada e de saída vai para o
--                      histórico do contato no Kommo, com o resultado
--   gravação           o registro leva o link da gravação, que o Kommo
--                      toca no próprio cartão
--
-- A central fala com o Kommo por um token de longo prazo, gerado na
-- integração privada do Kommo. Quem é quem fica aqui: cada usuário do
-- Kommo ganha um ramal.
--
-- Idempotente.
SET NAMES utf8mb4;

-- Uma linha só (id = 1).
CREATE TABLE IF NOT EXISTS kommo_config (
  id                  TINYINT UNSIGNED PRIMARY KEY,
  ativo               BOOLEAN      NOT NULL DEFAULT 0,
  -- "empresa" em empresa.kommo.com
  subdominio          VARCHAR(80)  NULL,
  token               TEXT         NULL,
  -- Como o navegador de quem usa o Kommo chega à central: é o começo do
  -- link das gravações. Vazio: https:// e o nome da central.
  url_publica         VARCHAR(255) NULL,
  -- O DDD da central: número local de 8 ou 9 dígitos ganha este DDD
  -- antes de ir para o Kommo procurar o contato.
  ddd                 CHAR(2)      NULL,
  -- Como o ramal disca o número que vem do Kommo (+55 11 99999-0000):
  -- sem o +55; com o prefixo de linha externa, se a central usa um; e,
  -- marcado, sem o DDD quando ele é o da central.
  prefixo             VARCHAR(4)   NULL,
  local_sem_ddd       BOOLEAN      NOT NULL DEFAULT 0,
  registrar_entrada   BOOLEAN      NOT NULL DEFAULT 1,
  registrar_saida     BOOLEAN      NOT NULL DEFAULT 1,
  -- Ligação de número que não é contato no Kommo vira um lead de entrada
  -- (a lista "Entrada" do funil), para ninguém perder o cliente novo.
  criar_lead          BOOLEAN      NOT NULL DEFAULT 1,
  -- A chave de click to call que o app do Kommo usa (Integrações / API).
  chave_id            INT UNSIGNED NULL,
  -- Assina os links das gravações: sem ele, quem adivinhasse o id de uma
  -- chamada ouviria a gravação.
  gravacao_segredo    CHAR(64)     NULL,
  -- Só as chamadas a partir daqui: ligar a integração não despeja o
  -- histórico inteiro da central no Kommo.
  desde               DATETIME     NULL,
  ultimo_envio        DATETIME     NULL,
  ultimo_estado       VARCHAR(255) NULL,
  atualizado_em       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_kommo_chave FOREIGN KEY (chave_id) REFERENCES click2call_chaves (id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO kommo_config (id) VALUES (1);

-- Os usuários do Kommo, lidos da API dele, e o ramal de cada um.
CREATE TABLE IF NOT EXISTS kommo_usuarios (
  kommo_id     INT UNSIGNED PRIMARY KEY,
  nome         VARCHAR(120) NOT NULL,
  email        VARCHAR(160) NULL,
  ramal        VARCHAR(20)  NULL,
  -- Saiu do Kommo: fica para o histórico, mas não liga nem registra.
  ativo        BOOLEAN      NOT NULL DEFAULT 1,
  atualizado_em TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_kommo_usu_ramal (ramal)
) ENGINE=InnoDB;

-- Cada chamada levada (ou não) ao Kommo. A chave é o linkedid do CDR: uma
-- ligação com fila e transferência tem várias linhas no CDR e é um
-- registro só no Kommo.
CREATE TABLE IF NOT EXISTS kommo_chamadas (
  linkedid      VARCHAR(64)  PRIMARY KEY,
  inicio        DATETIME     NOT NULL,
  direcao       ENUM('entrada','saida') NOT NULL,
  telefone      VARCHAR(40)  NOT NULL,
  ramal         VARCHAR(20)  NULL,
  kommo_usuario INT UNSIGNED NULL,
  duracao       INT UNSIGNED NOT NULL DEFAULT 0,
  atendida      BOOLEAN      NOT NULL DEFAULT 0,
  gravacao      VARCHAR(255) NULL,
  -- ok: no histórico do contato; lead: virou lead de entrada; sem_contato:
  -- número que não é contato (e lead desligado); erro: tenta de novo.
  estado        ENUM('ok','lead','sem_contato','erro') NOT NULL,
  detalhe       VARCHAR(255) NULL,
  tentativas    TINYINT UNSIGNED NOT NULL DEFAULT 1,
  enviado_em    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_kommo_cham_estado (estado, enviado_em),
  INDEX idx_kommo_cham_inicio (inicio)
) ENGINE=InnoDB;

-- O pedido de click to call que veio do app do Kommo aparece como tal.
ALTER TABLE click2call_pedidos MODIFY origem ENUM('api','console','kommo') NOT NULL DEFAULT 'api';
