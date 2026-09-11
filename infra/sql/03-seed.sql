-- =========================================================
-- Telium PABX — dados essenciais
--
-- Só o que a central precisa para funcionar: identidade da empresa,
-- perfis de acesso com suas permissões e a conta de administrador.
-- NENHUM ramal, tronco, fila ou rota fictícia.
--
-- Senha do admin: T3l1um_@2024_@aD1m
-- Reaplicar este script restaura essa senha e desbloqueia a conta.
--
-- A base nasce vazia de dados operacionais: ramais, troncos, filas,
-- rotas e URAs são cadastrados pelo console, e o CDR é preenchido pelo
-- próprio Asterisk conforme as chamadas acontecem.
-- =========================================================
SET NAMES utf8mb4;

-- ---------- empresa ----------
INSERT INTO empresa (id, nome, razao_social, plano, ramais_contratados)
VALUES (1, 'Telium Networks', 'Telium Networks Telecomunicações Ltda.', 'Enterprise', 150)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- ---------- perfis ----------
INSERT INTO perfis (chave, nome, descricao, cor, sistema) VALUES
 ('admin','Administrador','Acesso irrestrito a todos os módulos, incluindo configuração do sistema.','brand',1),
 ('supervisor','Supervisor','Acompanha operação, filas e relatórios. Edita aplicações de atendimento.','info',1),
 ('operador','Operador','Atendente. Enxerga apenas o próprio painel e recursos pessoais.','ok',1),
 ('auditor','Auditoria','Somente leitura de relatórios, gravações e trilhas de auditoria.','warn',1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao);

-- ---------- módulos por perfil ----------
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, '*' FROM perfis WHERE chave = 'admin';

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, m.modulo FROM perfis p JOIN (
  SELECT 'dash.*' AS modulo UNION ALL SELECT 'rel.*' UNION ALL SELECT 'pcu.*'
  UNION ALL SELECT 'apps.filas' UNION ALL SELECT 'apps.grupostoque' UNION ALL SELECT 'apps.ura'
  UNION ALL SELECT 'apps.conferencias' UNION ALL SELECT 'apps.gravacao' UNION ALL SELECT 'apps.anuncios'
  UNION ALL SELECT 'apps.condicoes' UNION ALL SELECT 'apps.grupohorario'
  UNION ALL SELECT 'conn.ramais' UNION ALL SELECT 'telium.painel' UNION ALL SELECT 'telium.tarifacao'
) m WHERE p.chave = 'supervisor';

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, m.modulo FROM perfis p JOIN (
  SELECT 'dash.visaogeral' AS modulo UNION ALL SELECT 'pcu.*'
  UNION ALL SELECT 'apps.sigame' UNION ALL SELECT 'apps.correiovoz'
) m WHERE p.chave = 'operador';

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, m.modulo FROM perfis p JOIN (
  SELECT 'dash.visaogeral' AS modulo UNION ALL SELECT 'rel.*'
  UNION ALL SELECT 'apps.gravacao' UNION ALL SELECT 'pcu.chamadas'
) m WHERE p.chave = 'auditor';

-- ---------- ações por perfil ----------
INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, a.acao FROM perfis p JOIN (
  SELECT 'criar' AS acao UNION ALL SELECT 'editar' UNION ALL SELECT 'excluir'
  UNION ALL SELECT 'exportar' UNION ALL SELECT 'reiniciar' UNION ALL SELECT 'permissoes'
) a WHERE p.chave = 'admin';

INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, a.acao FROM perfis p JOIN (
  SELECT 'criar' AS acao UNION ALL SELECT 'editar' UNION ALL SELECT 'exportar'
) a WHERE p.chave = 'supervisor';

-- O operador só alcança dash.visaogeral, pcu.* e os recursos pessoais.
-- Dentro disso ele precisa poder criar e apagar — a agenda pessoal e o
-- siga-me são dele. O que limita o alcance é a lista de módulos, não a ação.
INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, a.acao FROM perfis p JOIN (
  SELECT 'criar' AS acao UNION ALL SELECT 'editar' UNION ALL SELECT 'excluir'
) a WHERE p.chave = 'operador';

INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, 'exportar' FROM perfis p WHERE p.chave = 'auditor';

-- ---------- conta de administrador ----------
-- A senha do admin é REIMPOSTA a cada provisionamento, de propósito: é a
-- garantia de que sempre existe uma forma conhecida de entrar na central.
-- Consequência: se você trocar a senha do admin pelo console, a próxima
-- execução do playbook devolve o padrão. Para uma conta com senha própria,
-- crie um segundo usuário administrador pelo console.
--
-- INSERT direto (sem SELECT) para não haver ambiguidade de coluna com
-- a tabela perfis no ON DUPLICATE KEY UPDATE.
INSERT INTO usuarios (usuario, nome, email, senha_hash, perfil_id, setor, status)
VALUES ('admin', 'Administrador', NULL, 'pbkdf2_sha256$390000$YOaItVoHDd2/hpoLNDFOcQ==$ad2bQSeyuzfdiIVwHPDZuvTYZ/FJMr8E4Yqu/vW0LJ4=',
        (SELECT id FROM perfis WHERE chave = 'admin'), 'TI', 'ativo')
ON DUPLICATE KEY UPDATE
  nome             = VALUES(nome),
  senha_hash       = VALUES(senha_hash),
  perfil_id        = VALUES(perfil_id),
  status           = 'ativo',
  tentativas_login = 0,
  bloqueado_ate    = NULL;

-- ---------- grupos de horário padrão ----------
-- Genéricos e necessários para montar condições horárias.
INSERT IGNORE INTO grupos_horario (id, nome) VALUES (1,'Comercial'), (2,'24x7');
INSERT IGNORE INTO grupo_horario_faixas (grupo_id, dias, hora_inicio, hora_fim)
VALUES (1,'mon-fri','08:00:00','18:00:00'), (2,'mon-sun','00:00:00','23:59:59');

-- ---------- estado ----------
INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1'), ('schema_versao','1')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);
