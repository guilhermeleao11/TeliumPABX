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
ON DUPLICATE KEY UPDATE id = id;  -- o nome que o cliente puser no console fica

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
  UNION ALL SELECT 'conn.ramais' UNION ALL SELECT 'telium.tarifacao'
) m WHERE p.chave = 'supervisor';

-- Nada de "apps.*" aqui: apps.correiovoz e apps.sigame são as telas de
-- ADMINISTRAÇÃO desses serviços e listam todos os ramais da central, com
-- senha de caixa postal e destino de desvio editáveis. O que o operador
-- precisa é "pcu.*" — Meu Painel —, que resolve o ramal pela sessão e
-- por isso só alcança o dele.
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, m.modulo FROM perfis p JOIN (
  SELECT 'dash.visaogeral' AS modulo UNION ALL SELECT 'pcu.*'
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
-- A conta nasce com a senha de fábrica, e ela NÃO é reimposta depois: a
-- senha de fábrica está no repositório, e reimpô-la a cada atualização
-- deixava qualquer central em produção aberta a quem a conhecesse — e
-- desfazia em silêncio a troca que o cliente tinha feito. A senha inicial
-- de cada servidor é definida uma única vez pelo playbook (papel api), e
-- quem perder a senha recupera pelo terminal: php bin/telium senha admin.
-- Cada execução ainda destrava a conta e a mantém administradora, para
-- nunca ficar sem quem administre.
--
-- INSERT direto (sem SELECT) para não haver ambiguidade de coluna com
-- a tabela perfis no ON DUPLICATE KEY UPDATE.
INSERT INTO usuarios (usuario, nome, email, senha_hash, perfil_id, setor, status)
VALUES ('admin', 'Administrador', NULL, 'pbkdf2_sha256$390000$YOaItVoHDd2/hpoLNDFOcQ==$ad2bQSeyuzfdiIVwHPDZuvTYZ/FJMr8E4Yqu/vW0LJ4=',
        (SELECT id FROM perfis WHERE chave = 'admin'), 'TI', 'ativo')
ON DUPLICATE KEY UPDATE
  nome             = VALUES(nome),
  perfil_id        = VALUES(perfil_id),
  status           = 'ativo',
  tentativas_login = 0,
  bloqueado_ate    = NULL;

-- ---------- grupos de horário padrão ----------
-- Genéricos e necessários para montar condições horárias.
INSERT IGNORE INTO grupos_horario (id, nome) VALUES (1,'Comercial'), (2,'24x7');
-- A faixa só entra no grupo que ainda não tem nenhuma. A tabela não tem
-- chave que o INSERT IGNORE pudesse usar, e cada execução do playbook
-- somava mais uma cópia (o 41 limpa as que já se acumularam).
INSERT INTO grupo_horario_faixas (grupo_id, dias, hora_inicio, hora_fim)
SELECT f.grupo_id, f.dias, f.hora_inicio, f.hora_fim
  FROM (SELECT 1 AS grupo_id, 'mon,tue,wed,thu,fri' AS dias, '08:00:00' AS hora_inicio, '18:00:00' AS hora_fim
        UNION ALL SELECT 2, '*', '00:00:00', '23:59:59') f
 WHERE NOT EXISTS (SELECT 1 FROM grupo_horario_faixas g WHERE g.grupo_id = f.grupo_id);

-- ---------- estado ----------
INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1'), ('schema_versao','1')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);
