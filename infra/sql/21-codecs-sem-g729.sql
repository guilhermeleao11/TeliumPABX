-- ------------------------------------------------------------------
-- Tira o g729 dos troncos que nasceram com ele no padrão.
--
-- O Asterisk NÃO transcodifica g729: a tabela de tradução nem lista o
-- codec, e o suporte de fábrica é só passagem. Com g729 no tronco e um
-- ramal em opus (navegador) ou alaw (aparelho), a operadora atende e o
-- Asterisk derruba na hora:
--
--   set_format: Unable to find a codec translation path: (g729) -> (opus)
--   dial_exec_full: Had to drop call because I couldn't make ... compatible
--
-- Quem tiver um g729 licenciado e a central inteira em g729 pode pôr de
-- volta pela tela do tronco; a conferência do cadastro avisa quando a
-- combinação não fecha.
-- ------------------------------------------------------------------

UPDATE troncos
   SET codecs = TRIM(BOTH ',' FROM REPLACE(REPLACE(CONCAT(',', codecs, ','), ',g729,', ','), ',,', ','))
 WHERE codecs LIKE '%g729%';

UPDATE ramais
   SET codecs = TRIM(BOTH ',' FROM REPLACE(REPLACE(CONCAT(',', codecs, ','), ',g729,', ','), ',,', ','))
 WHERE codecs LIKE '%g729%';

-- E o padrão da coluna, para o próximo cadastro não nascer com o problema.
ALTER TABLE troncos MODIFY COLUMN codecs VARCHAR(120) NOT NULL DEFAULT 'alaw,ulaw';
ALTER TABLE ramais  MODIFY COLUMN codecs VARCHAR(120) NOT NULL DEFAULT 'alaw,ulaw';
