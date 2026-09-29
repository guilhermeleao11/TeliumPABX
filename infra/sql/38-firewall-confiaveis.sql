-- Endereços que o firewall nunca bloqueia.
--
-- O fail2ban bane por 24 horas quem erra a senha cinco vezes. É o certo
-- para quem varre a central — e o errado para o IP do escritório, do
-- provedor de monitoramento ou do syslog central, que um ramal mal
-- configurado ou um teste derruba junto. Desbloquear resolvia até a
-- próxima vez.
--
-- A lista vira o "ignoreip" do fail2ban (jail.d/telium-confiaveis.local),
-- escrito pela ponte /usr/local/sbin/telium-fail2ban, que roda como root.
-- Aceita um IP (200.170.201.2) ou uma faixa (200.170.201.0/24).
--
-- Idempotente: rodar de novo não faz nada.

CREATE TABLE IF NOT EXISTS firewall_confiaveis (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  endereco    VARCHAR(50)  NOT NULL,
  descricao   VARCHAR(120) NULL,
  criado_por  INT UNSIGNED NULL,
  criado_em   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_firewall_confiavel (endereco)
) ENGINE=InnoDB;
