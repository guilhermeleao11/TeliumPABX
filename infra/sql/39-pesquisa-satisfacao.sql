-- Pesquisa de satisfação como módulo próprio.
--
-- A pesquisa já existia presa à tela de filas: uma pergunta, nota de um
-- dígito só (a nota 10 era impossível) e nenhum registro de quem
-- desligou sem responder. Agora:
--
--   * três mensagens: saudação, a pergunta com as notas e o agradecimento
--     (e, opcional, a de tecla inválida);
--   * escala de 0 a 10, no sentido que o cliente escolher — há empresa
--     em que 10 é "muito satisfeito" e há a que usa 10 para "muito
--     insatisfeito";
--   * um número para o atendente transferir o cliente para a pesquisa;
--   * cada resposta guarda a escala com que foi dada e um índice de 0 a
--     100 já no sentido certo, para o relatório comparar pesquisas de
--     escalas diferentes e continuar certo depois de alguém mudar a escala.
--
-- Idempotente: rodar de novo não faz nada.

ALTER TABLE pesquisas
  ADD COLUMN IF NOT EXISTS anuncio_saudacao_id INT UNSIGNED NULL AFTER descricao,
  ADD COLUMN IF NOT EXISTS anuncio_invalida_id INT UNSIGNED NULL AFTER anuncio_obrigado_id,
  -- maior_melhor: a nota mais alta é a melhor. menor_melhor: o contrário.
  ADD COLUMN IF NOT EXISTS sentido ENUM('maior_melhor','menor_melhor') NOT NULL DEFAULT 'maior_melhor' AFTER nota_max,
  -- Discável: o atendente transfere o cliente para cá no fim da conversa.
  ADD COLUMN IF NOT EXISTS numero VARCHAR(10) NULL AFTER nome;

-- Número de transferência é único entre as pesquisas.
SET @tem := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'pesquisas' AND index_name = 'uq_pesquisa_numero');
SET @sql := IF(@tem = 0, 'ALTER TABLE pesquisas ADD UNIQUE KEY uq_pesquisa_numero (numero)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE pesquisa_respostas
  -- O ramal de quem atendeu (a interface da fila, sem o "PJSIP/").
  ADD COLUMN IF NOT EXISTS ramal VARCHAR(20) NULL AFTER agente,
  -- respondida · sem_resposta (não digitou) · invalida (errou todas as
  -- tentativas) · desligou (saiu no meio da pesquisa).
  ADD COLUMN IF NOT EXISTS status ENUM('respondida','sem_resposta','invalida','desligou')
      NOT NULL DEFAULT 'respondida' AFTER uniqueid,
  -- A escala vigente quando o cliente respondeu.
  ADD COLUMN IF NOT EXISTS nota_min TINYINT UNSIGNED NULL AFTER nota,
  ADD COLUMN IF NOT EXISTS nota_max TINYINT UNSIGNED NULL AFTER nota_min,
  ADD COLUMN IF NOT EXISTS sentido ENUM('maior_melhor','menor_melhor') NULL AFTER nota_max,
  -- 0 = pior, 100 = melhor, qualquer que seja a escala e o sentido.
  ADD COLUMN IF NOT EXISTS satisfacao DECIMAL(5,2) NULL AFTER sentido;

SET @tem := (SELECT COUNT(*) FROM information_schema.statistics
              WHERE table_schema = DATABASE() AND table_name = 'pesquisa_respostas' AND index_name = 'idx_resp_pesquisa_data');
SET @sql := IF(@tem = 0, 'ALTER TABLE pesquisa_respostas ADD KEY idx_resp_pesquisa_data (pesquisa_id, criado_em)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- As respostas antigas ganham status, escala e índice.
UPDATE pesquisa_respostas SET status = 'sem_resposta' WHERE nota IS NULL AND status = 'respondida';
UPDATE pesquisa_respostas r JOIN pesquisas p ON p.id = r.pesquisa_id
   SET r.nota_min = p.nota_min, r.nota_max = p.nota_max, r.sentido = p.sentido
 WHERE r.nota_min IS NULL;
UPDATE pesquisa_respostas
   SET satisfacao = ROUND(100 * IF(sentido = 'menor_melhor', nota_max - nota, nota - nota_min)
                              / NULLIF(nota_max - nota_min, 0), 2)
 WHERE satisfacao IS NULL AND nota IS NOT NULL AND nota_min IS NOT NULL;
UPDATE pesquisa_respostas SET ramal = SUBSTRING_INDEX(SUBSTRING_INDEX(agente, '/', -1), '@', 1)
 WHERE ramal IS NULL AND agente LIKE 'PJSIP/%';

-- A tela própria. Quem cuidava das filas cuidava das pesquisas.
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
  SELECT perfil_id, 'apps.pesquisas' FROM perfil_modulos WHERE modulo IN ('apps.filas', 'apps.*');
