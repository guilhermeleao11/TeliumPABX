-- Call center: agente sem conta no console, um código por pausa e uma
-- faixa só para os códigos do agente.
--
-- 1. O agente deixa de precisar de usuário. Quem só atende pelo
--    telefone (ou softphone) entra com matrícula e PIN e nunca abre o
--    console: o nome passa a ser do agente, e a conta do console vira
--    opcional — com ela, a pessoa ganha o painel "Meu Atendimento".
--    Apagar a conta não apaga mais o agente.
--
-- 2. Cada pausa tem o seu código (Almoço *14, Banheiro *15…): discou,
--    pausou, sem número de motivo depois. O antigo "*42 + motivo" sai.
--    Os motivos da central (Pós-atendimento, Não atendeu) não têm código.
--
-- 3. Os códigos do agente vão para a faixa *1x, que ninguém usa: na *4x
--    eles dividiam espaço com eco (*43), filas (*45-*47) e diretório (*411).
--    Só muda quem ainda está no código de fábrica.
--
-- Idempotente: rodar de novo não faz nada.
SET NAMES utf8mb4;

-- ---------------------------------------------------------
-- 1. Agente com nome próprio, conta do console opcional
-- ---------------------------------------------------------
ALTER TABLE cc_agentes ADD COLUMN IF NOT EXISTS nome VARCHAR(80) NULL AFTER id;

UPDATE cc_agentes a JOIN usuarios u ON u.id = a.usuario_id
   SET a.nome = u.nome
 WHERE a.nome IS NULL OR a.nome = '';
UPDATE cc_agentes SET nome = CONCAT('Agente ', matricula) WHERE nome IS NULL OR nome = '';

ALTER TABLE cc_agentes MODIFY nome VARCHAR(80) NOT NULL;

-- A chave estrangeira apagava o agente junto com a conta (CASCADE). Sai,
-- a coluna aceita vazio, e volta com SET NULL.
SET @fk := (
  SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cc_agentes'
     AND REFERENCED_TABLE_NAME = 'usuarios' AND DELETE_RULE <> 'SET NULL'
   LIMIT 1
);
SET @sql := IF(@fk IS NULL, 'DO 0', CONCAT('ALTER TABLE cc_agentes DROP FOREIGN KEY `', @fk, '`'));
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

ALTER TABLE cc_agentes MODIFY usuario_id INT UNSIGNED NULL;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cc_agentes'
     AND REFERENCED_TABLE_NAME = 'usuarios'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE cc_agentes
     ADD CONSTRAINT fk_cc_agente_usuario FOREIGN KEY (usuario_id)
     REFERENCES usuarios (id) ON DELETE SET NULL',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- ---------------------------------------------------------
-- 2. Um código por pausa
-- ---------------------------------------------------------
ALTER TABLE cc_pausas_motivos MODIFY codigo VARCHAR(8) NULL;

-- Os da central não se discam.
UPDATE cc_pausas_motivos SET codigo = NULL WHERE sistema = 1;

-- Os números antigos viram códigos: 1 a 6 (os de fábrica) vão para
-- *14…*19; os demais, para *10 e dois dígitos (*1007), todos do mesmo
-- tamanho para nenhum ser o começo de outro. O IGNORE pula quem bateria
-- num código já tomado — esse fica sem código, e se pausa pelo painel
-- até o administrador dar um.
UPDATE IGNORE cc_pausas_motivos SET codigo = CONCAT('*', 13 + CAST(codigo AS UNSIGNED))
 WHERE codigo REGEXP '^[1-6]$';
UPDATE IGNORE cc_pausas_motivos SET codigo = CONCAT('*10', LPAD(codigo, 2, '0'))
 WHERE codigo REGEXP '^[0-9]{1,2}$';
UPDATE cc_pausas_motivos SET codigo = NULL WHERE codigo REGEXP '^[0-9]+$';

DELETE FROM codigos_recurso WHERE chave = 'cc_pausa';

-- ---------------------------------------------------------
-- 3. Faixa *1x para o agente
-- ---------------------------------------------------------
UPDATE IGNORE codigos_recurso SET codigo = '*11' WHERE chave = 'cc_login'  AND codigo = '*40';
UPDATE IGNORE codigos_recurso SET codigo = '*12' WHERE chave = 'cc_logout' AND codigo = '*44';
UPDATE IGNORE codigos_recurso SET codigo = '*13' WHERE chave = 'cc_volta'  AND codigo = '*49';
