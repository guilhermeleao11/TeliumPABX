-- O TURN entra na lista de serviços que recebem certificado.
--
-- O coturn escuta TLS na 5349 e lê /etc/coturn/turn.crt. O instalador
-- copia para lá o par do Asterisk NA INSTALAÇÃO, e nunca mais: quem
-- depois enviava um certificado de verdade pelo console via o nginx e o
-- SIP TLS trocarem, e o TURN continuava servindo o autoassinado de
-- fábrica para sempre.
--
-- Isso quebra exatamente quem mais precisa do TURN: o navegador recusa
-- "turns:" com certificado que não valida, fica sem candidato relay, e
-- em rede que bloqueia UDP a chamada conecta e ninguém ouve — com o
-- console jurando que o certificado foi aplicado.
--
-- Idempotente: rodar de novo não faz nada.

ALTER TABLE certificado_servicos
  MODIFY servico ENUM('web','asterisk','turn','janus') NOT NULL;

INSERT IGNORE INTO certificado_servicos (servico, estado) VALUES ('turn', 'fabrica');
