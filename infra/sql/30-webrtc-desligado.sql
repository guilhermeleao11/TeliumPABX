-- O softphone do navegador nasce desligado.
--
-- Ele depende de coisas que estão fora da central — o NAT da rede de
-- quem usa, um TURN alcançável, o certificado aceito pelo navegador — e
-- cada uma delas falha do mesmo jeito: a chamada conecta e ninguém ouve.
-- Entregar isso ligado por padrão transforma a primeira impressão do
-- produto num problema de rede do cliente.
--
-- Desligado, o discador do console continua servindo: ele liga pelo
-- telefone de mesa da pessoa, pela própria central, que é o caminho que
-- não depende de nada disso.
--
-- Quem quiser ligar, liga em Conectividade > WebRTC / Softphone.
-- Idempotente: rodar de novo não muda o que já foi escolhido.

INSERT IGNORE INTO sistema (chave, valor) VALUES ('webrtc_ativo', '0');
