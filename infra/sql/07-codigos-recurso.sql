-- =========================================================
-- Telium PABX — catálogo completo de códigos de recurso
--
-- Cada código tem uma chave fixa (o gerador sabe o que ela faz) e um
-- código discado que o administrador pode trocar. A coluna `tipo`
-- separa os dois lugares onde um código vive:
--
--   dialplan   → o usuário disca e cai num contexto (telium-recursos)
--   featuremap → o usuário aperta durante a chamada (features.conf)
--
-- São namespaces diferentes, então o mesmo código pode existir nos dois
-- (é o caso de ** no FreePBX: captura direcionada ao discar, desligar
-- durante a chamada). Por isso a unicidade é por (codigo, tipo).
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

ALTER TABLE codigos_recurso
  ADD COLUMN IF NOT EXISTS tipo ENUM('dialplan','featuremap') NOT NULL DEFAULT 'dialplan' AFTER codigo,
  ADD COLUMN IF NOT EXISTS argumento VARCHAR(40) NULL AFTER tipo,      -- o que se disca depois do código
  ADD COLUMN IF NOT EXISTS ordem SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER categoria;

-- A unicidade antiga (só o código) impedia ** em dois namespaces.
ALTER TABLE codigos_recurso DROP INDEX IF EXISTS uk_codigo;
ALTER TABLE codigos_recurso ADD UNIQUE KEY IF NOT EXISTS uk_codigo_tipo (codigo, tipo);

-- Discagem rápida da agenda: *0 seguido deste código chama o contato.
ALTER TABLE contatos
  ADD COLUMN IF NOT EXISTS discagem_rapida VARCHAR(8) NULL AFTER ramal_interno;
ALTER TABLE contatos ADD UNIQUE KEY IF NOT EXISTS uk_contato_rapida (discagem_rapida);

-- ---------------------------------------------------------
-- Realinhamento: a primeira versão do sistema pôs o estacionamento em
-- *70, que no catálogo completo é a chamada em espera. A troca só vale
-- se o código ainda for o padrão antigo — quem já customizou fica como
-- está. Precisa vir antes do catálogo, senão a unicidade por código
-- derruba a linha nova.
-- ---------------------------------------------------------
UPDATE codigos_recurso SET codigo = '*85' WHERE chave = 'estacionar' AND codigo = '*70';

-- ---------------------------------------------------------
-- Catálogo. A chave é a identidade; o código discado não é
-- sobrescrito, para não desfazer customizações.
-- ---------------------------------------------------------
INSERT INTO codigos_recurso (chave, nome, codigo, tipo, argumento, categoria, ordem, descricao) VALUES

-- ---- Allowlist -------------------------------------------------------
 ('allowlist_add',    'Adicionar número à allowlist',       '*38', 'dialplan', 'número',
  'allowlist', 10, 'Disque o código e o número. Ele passa a ser sempre atendido, mesmo que algum padrão da lista negra o alcance.'),
 ('allowlist_ultimo', 'Adicionar o último chamador à allowlist', '*39', 'dialplan', NULL,
  'allowlist', 20, 'Libera quem acabou de ligar para o seu ramal, sem precisar digitar o número.'),
 ('allowlist_del',    'Remover número da allowlist',        '*36', 'dialplan', 'número',
  'allowlist', 30, 'Tira o número da lista de exceções. Ele volta a ser avaliado pela lista negra.'),
 ('allowlist_pausa',  'Pausar ou retomar a allowlist',      '*37', 'dialplan', NULL,
  'allowlist', 40, 'Desliga a checagem de exceções no PABX inteiro. Disque de novo para religar.'),

-- ---- Lista negra -----------------------------------------------------
 ('listanegra_add',    'Adicionar número à lista negra',    '*30', 'dialplan', 'número',
  'listanegra', 10, 'Disque o código e o número. Chamadas dele passam a ser recusadas na entrada.'),
 ('listanegra_ultimo', 'Adicionar o último chamador à lista negra', '*32', 'dialplan', NULL,
  'listanegra', 20, 'Bloqueia quem acabou de ligar para o seu ramal, sem precisar digitar o número.'),
 ('listanegra_del',    'Remover número da lista negra',     '*31', 'dialplan', 'número',
  'listanegra', 30, 'Desbloqueia o número. Ele volta a ser atendido na próxima ligação.'),

-- ---- Desvio de chamadas ---------------------------------------------
 ('cf_todas_on',      'Ativar desvio de todas as chamadas',   '*72', 'dialplan', 'destino',
  'desvio', 10, 'Disque o código e o destino. Toda chamada para o seu ramal vai direto para lá.'),
 ('cf_todas_off',     'Desativar desvio de todas as chamadas','*73', 'dialplan', NULL,
  'desvio', 20, 'Cancela o desvio de todas as chamadas.'),
 ('cf_todas_pedir',   'Ativar desvio de todas perguntando o destino', '*74', 'dialplan', NULL,
  'desvio', 30, 'O PABX atende e pede o destino, em vez de você digitá-lo junto com o código.'),
 ('cf_todas_alterna', 'Alternar desvio de todas as chamadas', '*75', 'dialplan', NULL,
  'desvio', 40, 'Liga se estiver desligado, desliga se estiver ligado, mantendo o último destino.'),
 ('cf_ocupado_on',    'Ativar desvio quando ocupado',         '*90', 'dialplan', 'destino',
  'desvio', 50, 'Só desvia quando o seu ramal já está em outra chamada.'),
 ('cf_ocupado_off',   'Desativar desvio quando ocupado',      '*91', 'dialplan', NULL,
  'desvio', 60, 'Cancela o desvio por ocupado.'),
 ('cf_ocupado_pedir', 'Ativar desvio quando ocupado perguntando o destino', '*92', 'dialplan', NULL,
  'desvio', 70, 'O PABX atende e pede o destino.'),
 ('cf_ocupado_alterna','Alternar desvio quando ocupado',      '*93', 'dialplan', NULL,
  'desvio', 80, 'Liga ou desliga o desvio por ocupado, mantendo o último destino.'),
 ('cf_naoatende_on',  'Ativar desvio quando não atende',      '*52', 'dialplan', 'destino',
  'desvio', 90, 'Desvia quando o ramal toca e ninguém atende, ou quando está indisponível.'),
 ('cf_naoatende_off', 'Desativar desvio quando não atende',   '*53', 'dialplan', NULL,
  'desvio', 100, 'Cancela o desvio por não atender.'),
 ('cf_naoatende_pedir','Ativar desvio quando não atende perguntando o destino', '*54', 'dialplan', NULL,
  'desvio', 110, 'O PABX atende e pede o destino.'),
 ('cf_naoatende_alterna','Alternar desvio quando não atende', '*55', 'dialplan', NULL,
  'desvio', 120, 'Liga ou desliga o desvio por não atender, mantendo o último destino.'),

-- ---- Chamada em espera ----------------------------------------------
 ('cw_on',      'Ativar chamada em espera',   '*70', 'dialplan', NULL,
  'chamadaespera', 10, 'Uma segunda chamada passa a avisar com um bipe em vez de ouvir ocupado.'),
 ('cw_off',     'Desativar chamada em espera','*71', 'dialplan', NULL,
  'chamadaespera', 20, 'Quem ligar enquanto você fala ouve ocupado (ou cai no desvio por ocupado).'),
 ('cw_alterna', 'Alternar chamada em espera', '*77', 'dialplan', NULL,
  'chamadaespera', 30, 'Liga ou desliga a chamada em espera, e informa como ficou.'),

-- ---- Conferências ----------------------------------------------------
 ('conf_estado', 'Estado de uma conferência', '*82', 'dialplan', 'sala',
  'conferencia', 10, 'Disque o código e o número da sala: o PABX fala quantas pessoas estão nela.'),

-- ---- Agenda ----------------------------------------------------------
 ('agenda_rapida', 'Discagem rápida da agenda', '*0', 'dialplan', 'código do contato',
  'agenda', 10, 'Disque o código e o número curto do contato. A agenda resolve o telefone e liga.'),

-- ---- Núcleo ----------------------------------------------------------
 ('core_linha',     'Tomar linha (tom de discar)', '*99', 'dialplan', NULL,
  'core', 10, 'O PABX atende, dá tom de discar e você disca como se estivesse no seu ramal.'),
 ('chanspy',        'Escuta de chamadas',          '*555','dialplan', 'ramal',
  'core', 20, 'Disque o código e o ramal para ouvir a chamada dele. Fica registrado na auditoria do Asterisk.'),
 ('captura',        'Capturar chamada do grupo',   '*8',  'dialplan', NULL,
  'core', 30, 'Atende uma chamada que está tocando em outro ramal do mesmo grupo de captura.'),
 ('captura_dir',    'Capturar um ramal específico','**',  'dialplan', 'ramal',
  'core', 40, 'Disque o código e o ramal para atender a chamada que está tocando nele.'),
 ('simular_entrada','Simular chamada de entrada',  '*7777','dialplan','DID',
  'core', 50, 'Disque o código e o DID: a chamada segue exatamente a rota de entrada daquele número. Serve para testar URA e horários sem ligar de fora.'),
 ('desconectar',    'Desligar durante a chamada',  '*0',  'featuremap', NULL,
  'core', 60, 'Apertado durante a conversa, encerra a chamada dos dois lados.'),
 ('gravar_alterna', 'Gravar esta chamada',         '*1',  'featuremap', NULL,
  'core', 70, 'Apertado durante a conversa, começa ou para a gravação.'),
 ('atxfer',         'Transferência com consulta',  '*2',  'featuremap', NULL,
  'core', 80, 'Apertado durante a conversa, põe o outro lado em espera e pede o destino para você falar antes de passar.'),
 ('blindxfer',      'Transferência cega',          '##',  'featuremap', NULL,
  'core', 90, 'Apertado durante a conversa, pede o destino e passa a chamada na hora.'),

-- ---- Ditado ----------------------------------------------------------
 ('ditado_gravar', 'Gravar um ditado',            '*34', 'dialplan', NULL,
  'ditado', 10, 'Grava um áudio no serviço de ditado do Asterisk, para ouvir ou baixar depois.'),
 ('ditado_email',  'Ditado enviado por e-mail',   '*35', 'dialplan', NULL,
  'ditado', 20, 'Grava e deixa o áudio na sua caixa postal, que o envia para o seu e-mail se o correio de voz estiver com envio ligado.'),

-- ---- Não perturbe ----------------------------------------------------
 ('dnd_ligar',    'Ativar não perturbe',    '*78', 'dialplan', NULL,
  'naoperturbe', 10, 'O ramal para de tocar; quem ligar cai no correio de voz ou ouve ocupado.'),
 ('dnd_desligar', 'Desativar não perturbe', '*79', 'dialplan', NULL,
  'naoperturbe', 20, 'Volta a receber chamadas.'),
 ('dnd_alterna',  'Alternar não perturbe',  '*76', 'dialplan', NULL,
  'naoperturbe', 30, 'Liga ou desliga o não perturbe, e informa como ficou.'),

-- ---- Fax -------------------------------------------------------------
 ('fax_receber', 'Receber fax neste ramal', '*666', 'dialplan', NULL,
  'fax', 10, 'Atende a chamada em modo fax e guarda o documento recebido. Depende do módulo de fax do Asterisk (res_fax).'),

-- ---- Siga-me ---------------------------------------------------------
 ('sigame_ligar',    'Ativar siga-me',    '*21', 'dialplan', 'destino',
  'sigame', 10, 'Disque o código e o destino: o ramal toca junto com ele.'),
 ('sigame_desligar', 'Desativar siga-me', '*22', 'dialplan', NULL,
  'sigame', 20, 'Cancela o siga-me.'),
 ('sigame_alterna',  'Alternar siga-me',  '*23', 'dialplan', NULL,
  'sigame', 30, 'Liga ou desliga o siga-me, mantendo o último destino.'),

-- ---- Despertador -----------------------------------------------------
 ('despertar_marcar',   'Marcar despertador', '*68', 'dialplan', NULL,
  'despertar', 10, 'O PABX pede a hora em quatro dígitos (por exemplo 0730) e liga para o ramal nesse horário.'),
 ('despertar_cancelar', 'Cancelar despertador','*67', 'dialplan', NULL,
  'despertar', 20, 'Apaga o despertador marcado para o seu ramal.'),

-- ---- Serviços de informação -----------------------------------------
 ('eco',       'Teste de eco',            '*43', 'dialplan', NULL,
  'informacoes', 10, 'Devolve o seu próprio áudio, para conferir microfone, alto-falante e atraso.'),
 ('hora',      'Hora certa',              '*60', 'dialplan', NULL,
  'informacoes', 20, 'Fala a hora do servidor.'),
 ('meu_ramal', 'Falar o número do ramal', '*65', 'dialplan', NULL,
  'informacoes', 30, 'Fala o número do ramal de onde você está ligando.'),
 ('rastrear',  'Rastrear a última chamada','*69','dialplan', NULL,
  'informacoes', 40, 'Fala o número de quem ligou por último para o seu ramal.'),
 ('diretorio', 'Diretório por nome',      '*411','dialplan', NULL,
  'informacoes', 50, 'Procura um ramal soletrando as primeiras letras do nome.'),

-- ---- Chamadas perdidas ----------------------------------------------
 ('perdidas_on',      'Ativar aviso de chamada perdida',   '*58', 'dialplan', NULL,
  'perdidas', 10, 'O PABX passa a avisar por e-mail quando alguém liga para o seu ramal e você não atende.'),
 ('perdidas_off',     'Desativar aviso de chamada perdida','*59', 'dialplan', NULL,
  'perdidas', 20, 'Para de enviar os avisos.'),
 ('perdidas_alterna', 'Alternar aviso de chamada perdida', '*57', 'dialplan', NULL,
  'perdidas', 30, 'Liga ou desliga o aviso, e informa como ficou.'),

-- ---- Interfonia e megafonia -----------------------------------------
 ('interfonia_prefixo',  'Chamar em interfonia', '*80', 'dialplan', 'ramal',
  'interfonia', 10, 'Disque o código e o ramal: o aparelho dele atende sozinho em viva-voz.'),
 ('interfonia_permitir', 'Aceitar interfonia neste ramal', '*87', 'dialplan', NULL,
  'interfonia', 20, 'Volta a aceitar que outros ramais falem no seu viva-voz.'),
 ('interfonia_negar',    'Recusar interfonia neste ramal', '*88', 'dialplan', NULL,
  'interfonia', 30, 'Passa a recusar interfonia; quem tentar ouve o seu ramal tocando normalmente.'),

-- ---- Estacionamento --------------------------------------------------
 ('estacionar',         'Estacionar a chamada',            '*85', 'dialplan', NULL,
  'estacionamento', 10, 'Põe a chamada em uma vaga de espera e fala o número da vaga para você anunciar.'),
 ('estacionar_captura', 'Retomar chamada estacionada',     '*86', 'dialplan', 'vaga',
  'estacionamento', 20, 'Disque o código e o número da vaga anunciada para assumir a chamada.'),

-- ---- Filas -----------------------------------------------------------
 ('fila_login',    'Entrar ou sair de uma fila',        '*45', 'dialplan', 'fila',
  'filas', 10, 'Disque o código e a fila: se você já é membro dinâmico, sai; se não é, entra.'),
 ('fila_pausa',    'Pausar ou despausar numa fila',     '*46', 'dialplan', 'fila',
  'filas', 20, 'Disque o código e a fila para se pausar, e de novo para voltar a receber.'),
 ('fila_contagem', 'Ouvir quantas chamadas estão na fila','*47','dialplan', 'fila',
  'filas', 30, 'Fala quantas chamadas estão esperando na fila informada.'),

-- ---- Condições horárias ---------------------------------------------
 ('condicao_alterna', 'Forçar uma condição horária', '*27', 'dialplan', 'número da condição',
  'condicoes', 10, 'Disque o código e o número da condição para alternar entre seguir o horário, forçar aberto e forçar fechado.'),

-- ---- Correio de voz --------------------------------------------------
 ('vm_proprio', 'Ouvir o meu correio de voz',  '*97', 'dialplan', NULL,
  'correio', 10, 'Entra direto na sua caixa postal, sem pedir o número dela.'),
 ('vm_outro',   'Ouvir outro correio de voz',  '*98', 'dialplan', NULL,
  'correio', 20, 'Pede o número da caixa postal e a senha.'),
 ('vm_direto',  'Deixar recado sem tocar o ramal', '*95', 'dialplan', 'ramal',
  'correio', 30, 'Disque o código e o ramal para cair direto na caixa postal dele, sem fazer o telefone tocar.')

ON DUPLICATE KEY UPDATE
  nome = VALUES(nome), descricao = VALUES(descricao), categoria = VALUES(categoria),
  ordem = VALUES(ordem), tipo = VALUES(tipo), argumento = VALUES(argumento);

-- Códigos da primeira versão que mudaram de lugar ou de nome de chave.
UPDATE codigos_recurso SET categoria = 'estacionamento' WHERE chave = 'estacionar' AND categoria <> 'estacionamento';
DELETE FROM codigos_recurso WHERE chave IN ('vm_proprio_antigo');
