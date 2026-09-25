-- Provisionamento: do cadastro que não faz nada para o telefone que se
-- configura sozinho.
--
-- A tela de aparelhos existia e guardava MAC, modelo e ramal — e nada
-- lia isso. Instalar trinta ramais num cliente seguia sendo configurar
-- trinta aparelhos à mão, um por um, digitando senha SIP em teclado de
-- telefone.
--
-- O que falta é o outro lado: um endereço que o aparelho busca ao ligar
-- e recebe a própria configuração. Ele não tem como fazer login, então o
-- que protege é o conjunto: um segredo no caminho, o MAC precisar estar
-- cadastrado, e a rede de origem ser conferida.
--
-- Idempotente: rodar de novo não troca o segredo já sorteado.

-- Segredo do caminho de provisionamento. Vai na URL que se configura no
-- aparelho (ou na opção 66 do DHCP), e é o que impede alguém de baixar
-- a senha SIP de um ramal só por adivinhar o MAC.
INSERT IGNORE INTO sistema (chave, valor)
VALUES ('prov_segredo', REPLACE(UUID(), '-', ''));

-- Só da rede interna, por padrão. Aparelho que se provisiona pela
-- internet é a mesma senha SIP viajando para quem pedir.
INSERT IGNORE INTO sistema (chave, valor) VALUES ('prov_so_rede_local', '1');

ALTER TABLE dispositivos
  ADD COLUMN IF NOT EXISTS observacao VARCHAR(160) NULL AFTER estado,
  ADD COLUMN IF NOT EXISTS ultimo_agente VARCHAR(160) NULL AFTER visto_em,
  ADD COLUMN IF NOT EXISTS vezes INT UNSIGNED NOT NULL DEFAULT 0 AFTER ultimo_agente;

-- Quem pediu o quê, inclusive quem pediu e não devia. É o registro que
-- responde "o telefone da sala 3 buscou a configuração?" sem adivinhação.
CREATE TABLE IF NOT EXISTS provisionamento_log (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  quando     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mac        VARCHAR(17)  NULL,
  ip         VARCHAR(45)  NULL,
  agente     VARCHAR(160) NULL,
  resultado  ENUM('entregue','mac_desconhecido','sem_ramal','segredo_errado','rede_negada') NOT NULL,
  INDEX idx_prov_log_quando (quando),
  INDEX idx_prov_log_mac (mac, quando)
) ENGINE=InnoDB;
