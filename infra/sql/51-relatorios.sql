-- =========================================================
-- Telium PABX — relatórios por ligação (1.10.0)
--
-- Os painéis passaram a contar ligações, não trechos do CDR: os trechos
-- da mesma ligação se juntam pelo linkedid. O índice deixa a junção (e a
-- busca dos trechos de uma ligação) rápida em central com CDR grande.
--
-- Idempotente: roda em toda atualização sem mexer em dado nenhum. Em CDR
-- muito grande a criação leva alguns minutos na primeira vez; o InnoDB
-- cria o índice sem travar a gravação de chamadas.
-- =========================================================

CREATE INDEX IF NOT EXISTS idx_cdr_linkedid ON cdr (linkedid);
