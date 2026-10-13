-- =========================================================
-- Telium PABX — limpeza da base operacional
--
-- DESTRUTIVO. Apaga ramais, troncos, filas, URAs, rotas, CDR,
-- gravações, contatos, auditoria, call center (agentes, atendimentos,
-- retornos, tabulações), caixas postais, chaves de click to call e
-- todos os usuários exceto o admin.
--
-- Preserva: empresa, perfis e suas permissões, conta admin, os grupos
-- de horário padrão, os códigos de recurso, os motivos de pausa, a
-- pesquisa padrão, os estacionamentos, a biblioteca de áudios e os
-- endereços confiáveis do firewall (quem administra não perde o acesso).
--
-- Apaga só o banco: os arquivos de gravação, recados e backups no
-- disco ficam onde estão.
--
--     sudo ansible-playbook site.yml -e limpar_banco=true
-- =========================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------- telefonia ----------
DELETE FROM rota_saida_padroes;
DELETE FROM fila_agentes;
DELETE FROM ura_opcoes;
DELETE FROM ura;
DELETE FROM rotas_entrada;
DELETE FROM rotas_saida;
DELETE FROM grupos_toque;
DELETE FROM filas;
DELETE FROM condicoes_horarias;
DELETE FROM pin_sets;
DELETE FROM dispositivos;
DELETE FROM ramais;
DELETE FROM troncos;
DELETE FROM tarifas;
DELETE FROM anuncios;
DELETE FROM caixas_postais;
DELETE FROM conferencias;
DELETE FROM despertadores;
DELETE FROM destinos_personalizados;
DELETE FROM disa;
DELETE FROM grupos_paging;
DELETE FROM lista_negra;
DELETE FROM lista_permitida;
DELETE FROM provisionamento_convite;
DELETE FROM provisionamento_log;

-- ---------- call center ----------
DELETE FROM cc_agente_filas;
DELETE FROM cc_atendimentos;
DELETE FROM cc_retornos;
DELETE FROM cc_agentes;
DELETE FROM cc_tabulacoes;
TRUNCATE TABLE queue_log;
DELETE FROM pesquisa_respostas;

-- ---------- integrações ----------
DELETE FROM click2call_pedidos;
DELETE FROM click2call_chaves;

-- ---------- operação ----------
TRUNCATE TABLE cdr;
TRUNCATE TABLE cel;
TRUNCATE TABLE eventos;
DELETE FROM gravacoes;
DELETE FROM contatos;
DELETE FROM integracoes;
DELETE FROM auditoria;
DELETE FROM config_aplicacoes;
DELETE FROM webrtc_chamadas;
DELETE FROM sessoes;

-- ---------- contas: mantém apenas o admin ----------
DELETE FROM usuarios WHERE usuario <> 'admin';

SET FOREIGN_KEY_CHECKS = 1;

-- ---------- recomeçar a numeração ----------
ALTER TABLE ramais         AUTO_INCREMENT = 1;
ALTER TABLE troncos        AUTO_INCREMENT = 1;
ALTER TABLE filas          AUTO_INCREMENT = 1;
ALTER TABLE ura            AUTO_INCREMENT = 1;
ALTER TABLE ura_opcoes     AUTO_INCREMENT = 1;
ALTER TABLE rotas_entrada  AUTO_INCREMENT = 1;
ALTER TABLE rotas_saida    AUTO_INCREMENT = 1;
ALTER TABLE grupos_toque   AUTO_INCREMENT = 1;
ALTER TABLE dispositivos   AUTO_INCREMENT = 1;
ALTER TABLE contatos       AUTO_INCREMENT = 1;
ALTER TABLE gravacoes      AUTO_INCREMENT = 1;
ALTER TABLE tarifas        AUTO_INCREMENT = 1;
ALTER TABLE integracoes    AUTO_INCREMENT = 1;
ALTER TABLE cc_agentes     AUTO_INCREMENT = 1;
ALTER TABLE caixas_postais AUTO_INCREMENT = 1;

-- a configuração do Asterisk precisa ser regerada depois disso
UPDATE sistema SET valor = '1' WHERE chave = 'config_pendente';
