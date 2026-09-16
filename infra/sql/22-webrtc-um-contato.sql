-- ------------------------------------------------------------------
-- Ramal WebRTC aceita um registro só.
--
-- O endpoint carrega DTLS, ICE e o transporte wss para TODO contato
-- registrado nele. Com duas vagas, um softphone comum se registra ao
-- lado do navegador — e aí:
--
--   * discar o ramal tenta sair pelo wss e não alcança o contato UDP
--     (CHANUNAVAIL na hora, sem tocar);
--   * a chamada que o softphone origina conecta, o ICE nunca fecha e
--     todo envio de RTP sai com zero byte — conecta e fica muda.
--
-- Uma vaga fecha a porta para esse engano. Quem precisa dos dois usa
-- dois ramais e o Siga-me no modo "junto".
-- ------------------------------------------------------------------

UPDATE ramais SET max_contatos = 1 WHERE webrtc = 1 AND max_contatos <> 1;
