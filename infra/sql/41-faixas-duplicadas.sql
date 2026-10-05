-- Faixas de horário repetidas e o "Comercial" de fábrica.
--
-- O seed inseria as faixas dos grupos "Comercial" e "24x7" com INSERT
-- IGNORE numa tabela sem chave para ele respeitar: cada execução do
-- playbook somava mais uma cópia. E o "mon-fri" do Comercial era
-- convertido para '*' pelo 27, ou seja, o expediente de fábrica valia
-- também no fim de semana.
--
-- 1. Uma única vez (marca em "sistema"): a faixa de fábrica do Comercial
--    que virou '*' volta a ser segunda a sexta. Só a que está exatamente
--    como o seed deixou: 08:00–18:00, todos os dias, sem outro filtro.
-- 2. Apaga as cópias exatas (todas as colunas iguais), ficando a mais
--    antiga. Duas faixas idênticas no mesmo grupo não mudam nada no
--    dialplan, então nenhuma configuração muda de sentido.

UPDATE grupo_horario_faixas f
  JOIN grupos_horario g ON g.id = f.grupo_id
   SET f.dias = 'mon,tue,wed,thu,fri'
 WHERE g.id = 1 AND g.nome = 'Comercial'
   AND f.dias = '*' AND f.dia_mes = '*' AND f.mes = '*'
   AND f.hora_inicio = '08:00:00' AND f.hora_fim = '18:00:00'
   AND f.dia_semana_inicio IS NULL AND f.dia_semana_fim IS NULL
   AND f.dia_mes_inicio IS NULL AND f.dia_mes_fim IS NULL
   AND f.mes_inicio IS NULL AND f.mes_fim IS NULL
   AND NOT EXISTS (SELECT 1 FROM sistema WHERE chave = 'faixa_comercial_corrigida');

DELETE f FROM grupo_horario_faixas f
  JOIN grupo_horario_faixas o
    ON o.id < f.id
   AND o.grupo_id = f.grupo_id
   AND o.dias <=> f.dias AND o.dia_mes <=> f.dia_mes AND o.mes <=> f.mes
   AND o.hora_inicio <=> f.hora_inicio AND o.hora_fim <=> f.hora_fim
   AND o.dia_semana_inicio <=> f.dia_semana_inicio AND o.dia_semana_fim <=> f.dia_semana_fim
   AND o.dia_mes_inicio <=> f.dia_mes_inicio AND o.dia_mes_fim <=> f.dia_mes_fim
   AND o.mes_inicio <=> f.mes_inicio AND o.mes_fim <=> f.mes_fim
   AND o.ordem <=> f.ordem;

INSERT IGNORE INTO sistema (chave, valor) VALUES ('faixa_comercial_corrigida', '1');
