-- =========================================================
-- Telium PABX — siga-me e correio de voz por ramal
--
-- Os dois módulos são visões sobre a tabela de ramais: todo ramal tem
-- a sua caixa postal e pode ter um siga-me. O que faltava era poder
-- guardar o destino sem deixá-lo ligado, e os limites da caixa.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

ALTER TABLE ramais
  -- O destino fica guardado mesmo desligado: é o que o *23 religa.
  ADD COLUMN IF NOT EXISTS siga_me_ativo BOOLEAN NOT NULL DEFAULT 0 AFTER siga_me,
  ADD COLUMN IF NOT EXISTS siga_me_modo ENUM('junto','depois') NOT NULL DEFAULT 'junto' AFTER siga_me_ativo,
  ADD COLUMN IF NOT EXISTS siga_me_toque SMALLINT UNSIGNED NOT NULL DEFAULT 20 AFTER siga_me_modo,
  ADD COLUMN IF NOT EXISTS siga_me_confirmar BOOLEAN NOT NULL DEFAULT 0 AFTER siga_me_toque,

  ADD COLUMN IF NOT EXISTS vm_max_mensagens SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER vm_apagar,
  ADD COLUMN IF NOT EXISTS vm_max_segundos  SMALLINT UNSIGNED NOT NULL DEFAULT 180 AFTER vm_max_mensagens,
  ADD COLUMN IF NOT EXISTS vm_dizer_hora    BOOLEAN NOT NULL DEFAULT 1 AFTER vm_max_segundos,
  ADD COLUMN IF NOT EXISTS vm_dizer_origem  BOOLEAN NOT NULL DEFAULT 1 AFTER vm_dizer_hora;

-- Quem já tinha um destino de siga-me gravado continua com ele ligado:
-- antes desta coluna, ter destino era estar ativo.
UPDATE ramais SET siga_me_ativo = 1
 WHERE siga_me IS NOT NULL AND siga_me <> '' AND siga_me_ativo = 0;
