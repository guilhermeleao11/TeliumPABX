-- =========================================================
-- Telium PABX — vínculos que faltavam
--
-- Sem estas chaves, apagar um cadastro deixava outro apontando para o
-- vazio. O caso que dói: excluir o tronco usado como reserva de uma rota
-- de saída passava sem reclamar, e a rota continuava na tela mostrando
-- um failover que o dialplan não escrevia mais.
-- =========================================================
SET NAMES utf8mb4;

-- Limpa o que já ficou pendurado antes de exigir a integridade.
UPDATE rotas_saida r
   LEFT JOIN troncos t ON t.id = r.tronco_falha_id
   SET r.tronco_falha_id = NULL
 WHERE r.tronco_falha_id IS NOT NULL AND t.id IS NULL;

UPDATE rotas_saida r
   LEFT JOIN pin_sets p ON p.id = r.pin_set_id
   SET r.pin_set_id = NULL
 WHERE r.pin_set_id IS NOT NULL AND p.id IS NULL;

UPDATE ramais x
   LEFT JOIN pin_sets p ON p.id = x.pin_set_id
   SET x.pin_set_id = NULL
 WHERE x.pin_set_id IS NOT NULL AND p.id IS NULL;

-- Tronco de reserva: RESTRICT, igual ao principal. Perder o failover
-- sem avisar é pior do que ser obrigado a editar a rota antes.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rotas_saida'
     AND CONSTRAINT_NAME = 'fk_rota_tronco_falha'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE rotas_saida
     ADD CONSTRAINT fk_rota_tronco_falha FOREIGN KEY (tronco_falha_id)
     REFERENCES troncos (id) ON DELETE RESTRICT',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- Conjunto de PIN: apagar o conjunto libera a rota em vez de deixá-la
-- pedindo uma senha que não existe mais.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rotas_saida'
     AND CONSTRAINT_NAME = 'fk_rota_pin_set'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE rotas_saida
     ADD CONSTRAINT fk_rota_pin_set FOREIGN KEY (pin_set_id)
     REFERENCES pin_sets (id) ON DELETE SET NULL',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'ramais'
     AND CONSTRAINT_NAME = 'fk_ramal_pin_set'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE ramais
     ADD CONSTRAINT fk_ramal_pin_set FOREIGN KEY (pin_set_id)
     REFERENCES pin_sets (id) ON DELETE SET NULL',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
