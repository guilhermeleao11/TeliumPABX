-- Provisionamento do Linphone por QR Code.
--
-- O celular não tem MAC que a central conheça nem opção 66 de DHCP: quem
-- instala aponta a câmera para a tela e o aplicativo busca a própria
-- configuração num endereço que só vale por alguns minutos.
--
-- O QR carrega só esse endereço. A senha SIP não vai nele — um QR
-- fotografado por cima do ombro, ou esquecido num print de chamado,
-- seria a senha do ramal para quem o tivesse.
--
-- O token em si não é guardado: só o SHA-256 dele. Quem ler o banco não
-- consegue montar o endereço, e quando a senha foi digitada na tela (e
-- não veio do cadastro) ela fica cifrada com uma chave derivada do
-- próprio token, que também não está aqui.
--
-- Idempotente: rodar de novo não faz nada.

CREATE TABLE IF NOT EXISTS provisionamento_convite (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  token_hash     CHAR(64)     NOT NULL,
  ramal_id       INT UNSIGNED NOT NULL,
  -- Só quando a senha foi digitada na tela. Vazio, vale a do cadastro.
  -- Apagada no primeiro uso e na limpeza dos vencidos.
  senha_cifrada  VARBINARY(512) NULL,
  criado_por     INT UNSIGNED NULL,
  criado_em      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expira_em      DATETIME     NOT NULL,
  usado_em       DATETIME     NULL,
  usado_ip       VARCHAR(45)  NULL,
  UNIQUE KEY uq_prov_convite_token (token_hash),
  INDEX idx_prov_convite_expira (expira_em),
  FOREIGN KEY (ramal_id) REFERENCES ramais(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- O registro de pedidos passa a dizer também de qual ramal era o QR, e
-- por que um token foi recusado. É o que responde "o celular chegou a
-- ler o QR?" sem adivinhação.
ALTER TABLE provisionamento_log
  ADD COLUMN IF NOT EXISTS ramal VARCHAR(20) NULL AFTER mac;

ALTER TABLE provisionamento_log
  MODIFY resultado ENUM('entregue','mac_desconhecido','sem_ramal','segredo_errado','rede_negada',
                        'token_desconhecido','token_expirado','token_usado') NOT NULL;
