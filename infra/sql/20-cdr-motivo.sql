-- ------------------------------------------------------------------
-- Por que a chamada não completou.
--
-- "Nenhum tronco atendeu" era tudo o que sobrava de uma ligação que não
-- foi. A operadora diz o motivo no SIP — 404 para número que ela não
-- conhece, 403 para número não liberado, 503 para tronco sem capacidade
-- — e isso ficava só no log do Asterisk, que ninguém lê a tempo.
--
-- A coluna é preenchida pelo dialplan (sub-motivo-falha) via
-- CDR(motivo), e o cdr_adaptive_odbc grava em coluna de mesmo nome.
-- Chamada atendida fica com NULL.
-- ------------------------------------------------------------------

ALTER TABLE cdr ADD COLUMN IF NOT EXISTS motivo VARCHAR(120) NULL AFTER tronco;
