-- =========================================================
-- Telium PABX — endurecimento do login
--
-- 1. Passo do TOTP já usado, para o mesmo código não valer duas vezes.
-- 2. Índice que torna barata a contagem de falhas por IP, que é o que
--    segura a varredura de senha contra vários usuários de uma vez.
-- =========================================================
SET NAMES utf8mb4;

-- Último passo de 30 s aceito na verificação em dois passos. Sem isto,
-- um código interceptado valia de novo enquanto o passo não virasse.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios'
     AND COLUMN_NAME = 'totp_ultimo_passo'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE usuarios ADD COLUMN totp_ultimo_passo BIGINT UNSIGNED NULL AFTER totp_ativo',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- A auditoria já registra cada login_falha com IP e horário; só faltava
-- o índice para perguntar "quantas falhas deste IP nos últimos minutos?"
-- sem varrer a tabela inteira.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auditoria'
     AND INDEX_NAME = 'idx_auditoria_ip_acao'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE auditoria ADD INDEX idx_auditoria_ip_acao (ip, acao, criado_em)',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- Sessões expiradas eram ignoradas na consulta, mas ficavam na tabela
-- para sempre. O índice deixa a limpeza barata.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.STATISTICS
   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sessoes'
     AND INDEX_NAME = 'idx_sessoes_expira'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE sessoes ADD INDEX idx_sessoes_expira (expira_em)',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;
