-- Rota de saída por grupo de ramais e por horário.
--
-- A rota valia para todos os ramais: quem discava 9XXXXXXXX saía pela
-- mesma operadora, fosse do NOC, do suporte ou da filial de Porto Alegre.
-- O SNEP fazia isso por grupo, e a migração deixava essas regras de fora.
--
-- O ramal ganha grupos de saída (uma lista curta: "NOC" ou "NOC,VoIP"),
-- e a rota pode valer só para alguns grupos ou ramais ("NOC,3602").
-- Rota sem lista vale para todos, como sempre. Com o mesmo padrão numa
-- rota de grupo e numa geral, o ramal do grupo sai pela dele e o resto
-- pela geral.
--
-- E a rota pode valer só num horário (um grupo de horário): fora dele, a
-- chamada segue para a próxima rota do mesmo número — o "comercial sai
-- pela operadora A, a noite pela B" que o SNEP fazia com a validade da
-- regra.
--
-- Idempotente.
SET NAMES utf8mb4;

ALTER TABLE ramais
  ADD COLUMN IF NOT EXISTS grupo_saida VARCHAR(255) NULL AFTER pin_set_id;
ALTER TABLE ramais MODIFY grupo_saida VARCHAR(255) NULL;

ALTER TABLE rotas_saida
  ADD COLUMN IF NOT EXISTS grupos VARCHAR(255) NULL AFTER pin_set_id,
  ADD COLUMN IF NOT EXISTS grupo_horario_id INT UNSIGNED NULL AFTER grupos;

-- O grupo de horário em uso pela rota não pode sumir: apagado, a rota
-- passava a não valer nunca, sem aviso. Como na condição horária, a
-- chave impede apagar o grupo enquanto alguma rota o usa.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rotas_saida'
     AND REFERENCED_TABLE_NAME = 'grupos_horario'
);
-- Rota apontando para grupo que já não existe (de antes da chave): solta.
UPDATE rotas_saida SET grupo_horario_id = NULL
 WHERE grupo_horario_id IS NOT NULL
   AND grupo_horario_id NOT IN (SELECT id FROM grupos_horario);
SET @sql := IF(@existe = 0,
  'ALTER TABLE rotas_saida ADD CONSTRAINT fk_rota_saida_horario FOREIGN KEY (grupo_horario_id) REFERENCES grupos_horario (id)',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
