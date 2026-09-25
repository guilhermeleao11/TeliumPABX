-- Tarifação: de tela vazia para conta que fecha.
--
-- A tela de Tarifação somava cdr.custo desde sempre — e NADA no sistema
-- escrevia essa coluna. O resultado era R$ 0,00 para todo mês, sem erro
-- nenhum: o pior jeito de um módulo não existir.
--
-- O que faltava não era a matemática, era a classificação. E ela já
-- existe: a rota de saída diz se a chamada é local, celular, DDD, DDI,
-- emergência ou especial, e o dialplan já carrega isso no canal em
-- TELIUM_CLASSE. Só não chegava ao CDR.
--
-- Aqui: a coluna no CDR, a tabela de tarifas por classe, e as linhas de
-- partida com preço ZERO — de propósito. Preço inventado numa fatura é
-- pior do que preço em branco; a tela avisa enquanto estiverem zerados.
--
-- Idempotente: rodar de novo não faz nada.

-- ---------- a classe da chamada chega ao CDR ----------
-- Preenchida pelo dialplan com Set(CDR(classe)=...) e gravada pelo
-- cdr_adaptive_odbc, que casa por nome de coluna.
ALTER TABLE cdr ADD COLUMN IF NOT EXISTS classe VARCHAR(20) NULL AFTER tronco;
ALTER TABLE cdr ADD INDEX IF NOT EXISTS idx_cdr_custo (calldate, custo);

-- ---------- a tabela de tarifas ----------
ALTER TABLE tarifas
  ADD COLUMN IF NOT EXISTS classe ENUM('local','celular','ddd','ddi','emergencia','especial','qualquer')
      NOT NULL DEFAULT 'qualquer' AFTER nome;

-- Mínimo cobrado na primeira fração. No Brasil o padrão é 30 segundos,
-- e depois de 6 em 6: uma ligação de 5 s custa 30 s, uma de 31 s custa
-- 36 s. Com primeiro=0 e incremento=60 vira minuto cheio; com
-- primeiro=0 e incremento=1, por segundo.
ALTER TABLE tarifas
  ADD COLUMN IF NOT EXISTS primeiro_incremento_seg SMALLINT UNSIGNED NOT NULL DEFAULT 30
      AFTER incremento_seg;

-- Mais específica primeiro: uma tarifa com padrão vence a da classe
-- inteira, e a ordem desempata o resto.
ALTER TABLE tarifas
  ADD COLUMN IF NOT EXISTS ordem SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER primeiro_incremento_seg;

ALTER TABLE tarifas MODIFY padrao VARCHAR(60) NULL;
ALTER TABLE tarifas ADD INDEX IF NOT EXISTS idx_tarifas_escolha (ativo, ordem, id);

-- ---------- linhas de partida ----------
-- Uma por classe, com preço zero. O revendedor digita os valores da
-- operadora; até lá a tela diz, com todas as letras, que estão zerados.
INSERT INTO tarifas (nome, classe, padrao, custo_minuto, taxa_fixa, incremento_seg,
                     primeiro_incremento_seg, ordem, ativo)
SELECT * FROM (
  SELECT 'Emergência'        AS nome, 'emergencia' AS classe, NULL AS padrao,
         0.0000 AS custo_minuto, 0.0000 AS taxa_fixa, 6 AS incremento_seg,
         0 AS primeiro_incremento_seg, 10 AS ordem, 1 AS ativo
  UNION ALL SELECT '0800 e gratuitos', 'qualquer', '_0800.',  0.0000, 0.0000, 6,  0, 20, 1
  UNION ALL SELECT 'Fixo local',       'local',    NULL,      0.0000, 0.0000, 6, 30, 30, 1
  UNION ALL SELECT 'Celular',          'celular',  NULL,      0.0000, 0.0000, 6, 30, 40, 1
  UNION ALL SELECT 'Interurbano (DDD)','ddd',      NULL,      0.0000, 0.0000, 6, 30, 50, 1
  UNION ALL SELECT 'Internacional',    'ddi',      NULL,      0.0000, 0.0000, 6, 30, 60, 1
  UNION ALL SELECT 'Especiais',        'especial', NULL,      0.0000, 0.0000, 6, 30, 70, 1
) novas
WHERE NOT EXISTS (SELECT 1 FROM tarifas);
