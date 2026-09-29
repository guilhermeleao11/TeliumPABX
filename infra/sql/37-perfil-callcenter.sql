-- Perfil "Call Center": o atendente que só atende.
--
-- Ele abre um painel e nada mais — "Meu Atendimento": entra e sai do
-- atendimento, pausa, vê quem está ligando e qualifica (tabula) cada
-- chamada. Não vê a central, os colegas, os relatórios nem o cadastro
-- de ninguém. O "Operador" continua existindo para quem precisa do Meu
-- Painel (siga-me, correio de voz, agenda) além do atendimento.
--
-- Nenhuma ação (criar, editar, excluir) é dada: as rotas do painel do
-- agente exigem só o módulo, e só alcançam o próprio agente — resolvido
-- pela sessão, não por parâmetro.
--
-- A pessoa ainda precisa ser cadastrada em Call Center → Agentes, com as
-- filas dela; sem isso o painel diz que a conta não é de agente.
--
-- Idempotente: rodar de novo não faz nada.

INSERT INTO perfis (chave, nome, descricao, cor, sistema) VALUES
 ('callcenter', 'Call Center',
  'Atendente de call center. Só o painel Meu Atendimento: entrar, pausar, ver quem ligou e qualificar a chamada.',
  'info', 1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao);

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'cc.agente' FROM perfis WHERE chave = 'callcenter';
