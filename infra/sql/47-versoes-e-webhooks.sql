-- Telium › Versões e Novidades: a versão desta central e o que mudou em
-- cada uma. Todo perfil ganha a tela: quem abre um chamado precisa dizer
-- o que está rodando, e quem usa o console quer saber o que há de novo.
--
-- Só uma vez: se o administrador tirar a tela de um perfil, a próxima
-- atualização não a devolve.
SET NAMES utf8mb4;

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, 'telium.versoes' FROM perfis p
 WHERE NOT EXISTS (SELECT 1 FROM sistema WHERE chave = 'versoes_modulo_dado');

INSERT IGNORE INTO sistema (chave, valor) VALUES ('versoes_modulo_dado', '1');

-- Webhooks: o resultado do último envio, para a tela mostrar se chegou.
ALTER TABLE integracoes ADD COLUMN IF NOT EXISTS ultimo_status VARCHAR(120) NULL AFTER ultimo_disparo;
ALTER TABLE integracoes MODIFY tipo ENUM('webhook','api_key','syslog','app') NOT NULL DEFAULT 'webhook';

-- Alertas por e-mail: para quem vão os avisos do sistema (backup que
-- falhou, certificado perto de vencer, disco quase cheio).
ALTER TABLE smtp ADD COLUMN IF NOT EXISTS alertas_para VARCHAR(255) NOT NULL DEFAULT '' AFTER nome_remetente;
