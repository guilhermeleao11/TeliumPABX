-- A rota de entrada passa a fazer o que o nome diz.
--
-- Hoje ela casa o DID e aponta o destino, e só. Três coisas faltavam, e
-- duas delas derrubam a chamada em silêncio:
--
--  · Tronco que não entrega DID. Boa parte dos gateways FXO, dos E1 e de
--    vários SIP entrega a chamada na extensão "s". Não havia "s" em
--    lugar nenhum: a chamada morria sem tocar em ninguém e sem uma linha
--    de log que explicasse.
--
--  · Número em E.164. Operadora que entrega "+5511..." não casava nem com
--    a rede de segurança, porque o padrão dela começa em dígito.
--
--  · Quem ligou. A coluna cid_origem existia desde o começo e NUNCA foi
--    lida pelo gerador: dava para cadastrar e não fazia nada. É o recurso
--    que o FreePBX chama de CallerID match, e é o que permite mandar o
--    cliente grande direto para o gerente.
--
-- O resto são as opções que todo PABX de mercado tem na rota de entrada e
-- que aqui não existiam: prefixo no nome de quem liga, toque distintivo,
-- atender antes, pausa, barrar anônimo e música de espera própria.
--
-- Idempotente: rodar de novo não faz nada.

-- ---------- opções da rota ----------
ALTER TABLE rotas_entrada
  -- Vai na frente do nome de quem ligou: o atendente vê "SAC: João" e
  -- sabe por qual número a chamada entrou antes de atender.
  ADD COLUMN IF NOT EXISTS prefixo_cid VARCHAR(20) NULL AFTER cid_origem,
  -- Toque distintivo. O telefone toca diferente para este número — é o
  -- cabeçalho Alert-Info, que Grandstream, Yealink e Fanvil entendem.
  ADD COLUMN IF NOT EXISTS alertinfo VARCHAR(60) NULL AFTER prefixo_cid,
  -- Atender antes de encaminhar. Custa a chamada desde já, mas é o que
  -- alguns troncos exigem para entregar áudio.
  ADD COLUMN IF NOT EXISTS atender_antes TINYINT(1) NOT NULL DEFAULT 0 AFTER alertinfo,
  -- Tocar (180 Ringing) antes de atender: quem ligou ouve chamando em
  -- vez de silêncio enquanto a central decide.
  ADD COLUMN IF NOT EXISTS tocar_antes TINYINT(1) NOT NULL DEFAULT 0 AFTER atender_antes,
  -- Segundos de espera antes de encaminhar. Tronco que entrega o áudio
  -- com atraso corta o começo da saudação da URA sem isto.
  ADD COLUMN IF NOT EXISTS pausa_seg TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER tocar_antes,
  -- Recusa quem esconde o número. Devolve 486 em vez de atender.
  ADD COLUMN IF NOT EXISTS bloquear_anonimo TINYINT(1) NOT NULL DEFAULT 0 AFTER pausa_seg,
  -- Música de espera desta rota, quando ela for diferente da geral.
  ADD COLUMN IF NOT EXISTS musica_espera VARCHAR(60) NULL AFTER bloquear_anonimo;

ALTER TABLE rotas_entrada ADD INDEX IF NOT EXISTS idx_rotas_entrada_did (did, ordem, id);

-- ---------- normalização do DID, por tronco ----------
-- A operadora entrega o número no formato dela: umas mandam só o ramal
-- final, outras o nacional completo, outras em E.164 com "+55". Sem isto
-- é preciso cadastrar a rota exatamente com o que chega — e descobrir
-- qual é só olhando log.
ALTER TABLE troncos
  ADD COLUMN IF NOT EXISTS did_remover VARCHAR(12) NULL AFTER contexto_entrada,
  ADD COLUMN IF NOT EXISTS did_digitos TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER did_remover;

-- ---------- o número chamado vai para o CDR ----------
-- O "dst" guarda a extensão que o dialplan usou, que muda no caminho.
-- O DID é o número que a operadora entregou, já normalizado: é ele que
-- responde "por qual das nossas linhas essa chamada entrou".
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS did VARCHAR(40) NULL AFTER classe;
ALTER TABLE cdr ADD INDEX IF NOT EXISTS idx_cdr_did (did, calldate);
