-- =========================================================
-- Telium PABX — cenário para testar as funções
--
-- NÃO é dado de demonstração e NÃO entra em instalação de cliente: a
-- base nasce vazia de propósito. Isto existe para um ambiente de teste,
-- onde exercitar cada módulo exigiria vinte cadastros à mão antes da
-- primeira ligação.
--
--     sudo ansible-playbook site.yml -e cenario_teste=true
--
-- Ou, na própria VM:
--
--     sudo mariadb telium < /opt/telium/sql/opcional/cenario-teste.sql
--
-- Para tirar tudo depois, limpar.sql apaga a base operacional inteira.
--
-- Reaplicar é seguro: tudo é INSERT ... ON DUPLICATE KEY, e nada aqui
-- toca em empresa, perfis ou usuários.
--
-- Senha SIP de todos os ramais: T3st3_R4mal_@2024
-- =========================================================
SET NAMES utf8mb4;

-- ---------- ramais ----------
-- 1001 e 1002 são o par de sempre: ramal chama ramal, transfere,
-- estaciona, entra em conferência.
-- 1003 é WebRTC — as quatro opções que o navegador exige vão
-- explícitas aqui, porque quem as liga junto é o formulário do console,
-- não o banco.
-- 1004 existe para provar o bloqueio: só liga para local.
INSERT INTO ramais
  (numero, nome, setor, senha_sip, contexto, transporte, codecs, webrtc,
   dtls, ice, avpf, rtcp_mux, srtp, voicemail, vm_senha,
   perm_local, perm_celular, perm_ddd, perm_ddi, tempo_toque, ativo)
VALUES
  ('1001', 'Teste 1001 — mesa',     'Testes', 'T3st3_R4mal_@2024', 'interno', 'udp', 'alaw,ulaw', 0,
   0, 0, 0, 0, 0, 1, '1001', 1, 1, 1, 0, 25, 1),
  ('1002', 'Teste 1002 — mesa',     'Testes', 'T3st3_R4mal_@2024', 'interno', 'udp', 'alaw,ulaw', 0,
   0, 0, 0, 0, 0, 1, '1002', 1, 1, 1, 0, 25, 1),
  ('1003', 'Teste 1003 — navegador','Testes', 'T3st3_R4mal_@2024', 'interno', 'wss', 'opus,alaw,ulaw', 1,
   1, 1, 1, 1, 1, 1, '1003', 1, 1, 1, 0, 25, 1),
  ('1004', 'Teste 1004 — só local', 'Testes', 'T3st3_R4mal_@2024', 'interno', 'udp', 'alaw,ulaw', 0,
   0, 0, 0, 0, 0, 0, NULL, 1, 0, 0, 0, 25, 1)
-- Número que já existe fica como está: num servidor que já tem
-- ramal 1001 de verdade, este script não pode renomeá-lo.
ON DUPLICATE KEY UPDATE numero = numero;

-- ---------- grupo de toque ----------
INSERT INTO grupos_toque (numero, nome, estrategia, ramais, tempo_toque,
                          destino_falha_tipo, destino_falha_valor, ativo)
VALUES ('2000', 'Todos os testes', 'ringall', '1001-1002', 20, 'ramal', '1001', 1)
ON DUPLICATE KEY UPDATE numero = numero;

-- ---------- fila ----------
INSERT INTO filas (numero, nome, descricao, estrategia, timeout_agente, retry,
                   wrapuptime, musica_espera, sla_segundos, max_espera,
                   destino_estouro_tipo, destino_estouro_valor, gravar,
                   entrar_vazia, sair_vazia, ativo)
VALUES ('3000', 'Atendimento (teste)', 'Fila do cenário de teste', 'rrmemory',
        20, 5, 5, 'default', 20, 120, 'ramal', '1001', 1, 'sim', 'sim', 1)
-- Número que já existe fica como está: num servidor que já tem
-- ramal 1001 de verdade, este script não pode renomeá-lo.
ON DUPLICATE KEY UPDATE numero = numero;

INSERT INTO fila_agentes (fila_id, ramal_id, penalidade, tipo, origem)
SELECT f.id, r.id, 0, 'estatico', 'manual'
  FROM filas f JOIN ramais r ON r.numero IN ('1001', '1002')
 WHERE f.numero = '3000'
ON DUPLICATE KEY UPDATE fila_id = fila_id;

-- ---------- conferência ----------
INSERT INTO conferencias (numero, nome, descricao, pin, pin_admin, max_usuarios,
                          gravar, anunciar_entrada_saida, musica_sozinho, ativo)
VALUES ('4000', 'Sala de teste', 'Conferência do cenário de teste',
        '1234', '4321', 10, 1, 1, 1, 1)
-- Número que já existe fica como está: num servidor que já tem
-- ramal 1001 de verdade, este script não pode renomeá-lo.
ON DUPLICATE KEY UPDATE numero = numero;

-- ---------- URA ----------
-- O áudio é um som que vem com o Asterisk, para a URA falar sem
-- ninguém precisar gravar nada antes.
INSERT INTO ura (nome, audio, timeout_digito, tentativas, discagem_direta,
                 destino_timeout_tipo, destino_timeout_valor,
                 destino_invalido_tipo, destino_invalido_valor, ativo)
VALUES ('URA de teste', 'demo-congrats', 5, 3, 1, 'fila', '3000', 'fila', '3000', 1)
ON DUPLICATE KEY UPDATE nome = nome;

INSERT INTO ura_opcoes (ura_id, tecla, rotulo, destino_tipo, destino_valor, ordem)
SELECT u.id, v.tecla, v.rotulo, v.tipo, v.valor, v.ordem
  FROM ura u
  JOIN (SELECT '1' tecla, 'Atendimento'  rotulo, 'fila'        tipo, '3000' valor, 1 ordem
        UNION ALL SELECT '2', 'Ramal 1001',      'ramal',            '1001', 2
        UNION ALL SELECT '3', 'Sala de reunião', 'conferencia',      '4000', 3
        UNION ALL SELECT '9', 'Desligar',        'desligar',         '',     4) v
 WHERE u.nome = 'URA de teste'
   AND NOT EXISTS (SELECT 1 FROM ura_opcoes o WHERE o.ura_id = u.id AND o.tecla = v.tecla);

-- ---------- horário e condição ----------
INSERT INTO grupos_horario (nome, descricao)
SELECT 'Comercial (teste)', 'Segunda a sexta, 8h às 18h'
 WHERE NOT EXISTS (SELECT 1 FROM grupos_horario WHERE nome = 'Comercial (teste)');

INSERT INTO grupo_horario_faixas (grupo_id, hora_inicio, hora_fim,
                                  dia_semana_inicio, dia_semana_fim, ordem)
SELECT g.id, '08:00:00', '18:00:00', 1, 5, 1
  FROM grupos_horario g
 WHERE g.nome = 'Comercial (teste)'
   AND NOT EXISTS (SELECT 1 FROM grupo_horario_faixas f WHERE f.grupo_id = g.id);

INSERT INTO condicoes_horarias (nome, descricao, grupo_horario_id,
                                destino_dentro_tipo, destino_dentro_valor,
                                destino_fora_tipo, destino_fora_valor, ativo)
SELECT 'Expediente (teste)', 'Dentro do horário vai para a fila; fora, para o correio de voz',
       g.id, 'fila', '3000', 'voicemail', '1001', 1
  FROM grupos_horario g
 WHERE g.nome = 'Comercial (teste)'
   AND NOT EXISTS (SELECT 1 FROM condicoes_horarias WHERE nome = 'Expediente (teste)');

-- ---------- conjunto de PIN ----------
INSERT INTO pin_sets (nome, pins, no_cdr)
SELECT 'Diretoria (teste)', '4721\n8890', 0
 WHERE NOT EXISTS (SELECT 1 FROM pin_sets WHERE nome = 'Diretoria (teste)');

-- ---------- listas ----------
INSERT INTO lista_negra (numero, descricao)
SELECT '1140041111', 'Número de teste da lista negra'
 WHERE NOT EXISTS (SELECT 1 FROM lista_negra WHERE numero = '1140041111');

INSERT INTO lista_permitida (numero, descricao)
SELECT '1140042222', 'Número de teste da allowlist'
 WHERE NOT EXISTS (SELECT 1 FROM lista_permitida WHERE numero = '1140042222');

-- ---------- agenda e destino personalizado ----------
INSERT INTO contatos (nome, numero, empresa)
SELECT 'Contato de teste', '1140043333', 'Telium'
 WHERE NOT EXISTS (SELECT 1 FROM contatos WHERE numero = '1140043333');

INSERT INTO destinos_personalizados (nome, descricao, contexto, extensao, prioridade, ativo)
SELECT 'Hora certa (teste)', 'Destino personalizado do cenário', 'telium-recursos', '*60', 1, 1
 WHERE NOT EXISTS (SELECT 1 FROM destinos_personalizados WHERE nome = 'Hora certa (teste)');

-- ---------- rotas ----------
-- A rota de entrada manda o DID para a URA; a de saída só nasce se já
-- houver tronco cadastrado, porque sem tronco ela não teria para onde
-- apontar.
INSERT INTO rotas_entrada (did, descricao, destino_tipo, destino_valor, gravar, ordem, ativo)
SELECT '1140041000', 'DID de teste', 'ura', u.id, 1, 10, 1
  FROM ura u
 WHERE u.nome = 'URA de teste'
   AND NOT EXISTS (SELECT 1 FROM rotas_entrada WHERE did = '1140041000');

INSERT INTO rotas_saida (nome, ordem, padrao, prefixo_remover, tronco_id, classe, ativo)
SELECT 'Local (teste)', 10, '_0XXXXXXXXXX', '0', t.id, 'local', 1
  FROM troncos t
 WHERE t.ativo = 1 AND t.tipo = 'pjsip'
   AND NOT EXISTS (SELECT 1 FROM rotas_saida WHERE nome = 'Local (teste)')
 ORDER BY t.id LIMIT 1;

INSERT INTO rotas_saida (nome, ordem, padrao, prefixo_remover, tronco_id, classe, ativo)
SELECT 'Celular (teste)', 20, '_0XX9XXXXXXXX', '0', t.id, 'celular', 1
  FROM troncos t
 WHERE t.ativo = 1 AND t.tipo = 'pjsip'
   AND NOT EXISTS (SELECT 1 FROM rotas_saida WHERE nome = 'Celular (teste)')
 ORDER BY t.id LIMIT 1;
