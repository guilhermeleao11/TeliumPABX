-- Click to call para outros sistemas.
--
-- Um CRM, um ERP ou uma página manda à central o ramal e o número do
-- cliente; a central toca o ramal e, quando ele atende, disca o cliente.
-- Quem pede se identifica com uma chave de API criada em Telium ›
-- Integrações / API.
--
-- A chave é mostrada uma vez, na criação; o banco guarda o hash dela e os
-- quatro últimos caracteres, para a tela mostrar qual é qual. Cada chave
-- pode ficar presa a alguns ramais e a alguns endereços de origem.
--
-- Cada pedido, atendido ou recusado, fica registrado: é o que se olha
-- quando o sistema do outro lado diz que "clicou e nada aconteceu".
--
-- Idempotente: rodar de novo não faz nada.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS click2call_chaves (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(80)  NOT NULL,
  token_hash    CHAR(64)     NOT NULL,
  token_fim     CHAR(4)      NOT NULL,
  -- Os ramais que esta chave pode fazer tocar. Vazio: qualquer um.
  ramais        VARCHAR(1000) NULL,
  -- De onde ela pode ser usada (IP ou faixa, 200.1.2.3 ou 10.0.0.0/8). Vazio: de qualquer lugar.
  ips           VARCHAR(1000) NULL,
  -- Pedir ao telefone que atenda sozinho, em viva-voz.
  atender_auto  BOOLEAN      NOT NULL DEFAULT 0,
  ativo         BOOLEAN      NOT NULL DEFAULT 1,
  ultimo_uso    DATETIME     NULL,
  criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_c2c_token (token_hash)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS click2call_pedidos (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  chave_id    INT UNSIGNED NULL,
  -- api: outro sistema; console: o botão "Testar" da tela.
  origem      ENUM('api','console') NOT NULL DEFAULT 'api',
  ramal       VARCHAR(20)  NULL,
  numero      VARCHAR(40)  NULL,
  ok          BOOLEAN      NOT NULL DEFAULT 0,
  resultado   VARCHAR(255) NOT NULL,
  uniqueid    VARCHAR(40)  NULL,
  ip          VARCHAR(45)  NULL,
  criado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_c2c_ped_chave (chave_id, criado_em),
  INDEX idx_c2c_ped_ip (ip, criado_em),
  CONSTRAINT fk_c2c_ped_chave FOREIGN KEY (chave_id) REFERENCES click2call_chaves (id) ON DELETE SET NULL
) ENGINE=InnoDB;
