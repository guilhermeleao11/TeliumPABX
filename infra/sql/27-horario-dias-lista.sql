-- Dias da semana viram lista nas faixas de horário.
--
-- A faixa guardava "dia da semana início" e "dia da semana fim", que só
-- sabe dizer intervalo contínuo. "Sábado e domingo" não é contínuo —
-- a semana começa no domingo — e "segunda, quarta e sexta" também não.
-- O GotoIfTime do Asterisk aceita lista ("sat,sun") desde sempre; era o
-- cadastro que não deixava.
--
-- A coluna "dias" já existia, sobrando da primeira versão, com '*' em
-- todas as linhas. Aqui ela recebe o que o par início/fim dizia, para
-- as faixas antigas continuarem valendo exatamente o mesmo.
--
-- Idempotente: só mexe em quem ainda está com '*' e tem o par preenchido.

UPDATE grupo_horario_faixas
   SET dias = (
     SELECT GROUP_CONCAT(d.sigla ORDER BY d.n SEPARATOR ',')
       FROM (SELECT 0 AS n, 'sun' AS sigla UNION ALL SELECT 1,'mon' UNION ALL
             SELECT 2,'tue' UNION ALL SELECT 3,'wed' UNION ALL SELECT 4,'thu' UNION ALL
             SELECT 5,'fri' UNION ALL SELECT 6,'sat') d
      WHERE (dia_semana_fim IS NULL AND d.n = dia_semana_inicio)
         OR (dia_semana_fim IS NOT NULL AND dia_semana_inicio <= dia_semana_fim
             AND d.n BETWEEN dia_semana_inicio AND dia_semana_fim)
         -- Faixa que dá a volta na semana, tipo sexta a segunda.
         OR (dia_semana_fim IS NOT NULL AND dia_semana_inicio > dia_semana_fim
             AND (d.n >= dia_semana_inicio OR d.n <= dia_semana_fim))
   )
 WHERE dia_semana_inicio IS NOT NULL
   AND (dias IS NULL OR dias = '' OR dias = '*' OR dias IN ('mon-fri', 'mon-sun', 'sun-sat'));

-- Sem o par para copiar: "a semana inteira", escrito de qualquer jeito,
-- vira '*'; o resto que não seja lista fica '*' também, porque é o que
-- a faixa já fazia na prática.
UPDATE grupo_horario_faixas
   SET dias = '*'
 WHERE dias IS NULL OR dias = '' OR dias IN ('mon-sun', 'sun-sat')
    OR (dias = 'mon-fri' AND dia_semana_inicio IS NULL);
