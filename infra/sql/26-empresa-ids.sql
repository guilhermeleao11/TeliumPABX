-- Identificadores do contrato nos dados da empresa.
--
-- A central é revendida: quem dá suporte precisa casar a instalação com
-- o contrato do cliente sem perguntar. Os dois números vêm do sistema de
-- quem vende — "service id" identifica o serviço contratado e "client
-- id" identifica o cliente — e ficam ao lado do CNPJ, na mesma tela.
--
-- Idempotente: rodar de novo não faz nada.

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa' AND COLUMN_NAME = 'service_id'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE empresa ADD COLUMN service_id VARCHAR(60) NULL AFTER cnpj',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa' AND COLUMN_NAME = 'client_id'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE empresa ADD COLUMN client_id VARCHAR(60) NULL AFTER service_id',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
