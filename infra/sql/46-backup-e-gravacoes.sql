-- Backup que se exclui de verdade e gravações com prazo.
--
-- 1. Excluir um backup pelo console soltava só o registro: o arquivo é
--    do root e a API não escreve na pasta. Agora a API marca o backup
--    como "removendo" e o executor de backup, que roda como root, apaga
--    o arquivo e o registro.
--
-- 2. As gravações cresciam sem limite em /var/spool/asterisk/monitor —
--    a tela dizia que a retenção do backup limpava, mas ela só apaga os
--    arquivos de backup. Agora há um prazo, em dias, definido em
--    Aplicações › Gravação de Chamadas. Zero (o padrão) guarda para
--    sempre: nenhuma central perde gravação por causa da atualização.
--
-- Idempotente: rodar de novo não faz nada.
SET NAMES utf8mb4;

ALTER TABLE backups
  MODIFY estado ENUM('pendente','executando','concluido','falha','removendo') NOT NULL DEFAULT 'pendente';

INSERT IGNORE INTO sistema (chave, valor) VALUES ('gravacoes_reter_dias', '0');

-- 3. Conjuntos de PIN: quantos PINs cada um tem. Os PINs não voltam na
--    listagem (são senhas), e a coluna "Quantos PINs" mostrava sempre 0.
--    A contagem é guardada ao salvar; aqui, a das linhas que já existem.
ALTER TABLE pin_sets ADD COLUMN IF NOT EXISTS pins_qtd SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER pins;
UPDATE pin_sets
   SET pins_qtd = IF(TRIM(REGEXP_REPLACE(IFNULL(pins, ''), '[^0-9]+', ' ')) = '', 0,
         LENGTH(TRIM(REGEXP_REPLACE(pins, '[^0-9]+', ' ')))
         - LENGTH(REPLACE(TRIM(REGEXP_REPLACE(pins, '[^0-9]+', ' ')), ' ', '')) + 1)
 WHERE pins_qtd = 0;
