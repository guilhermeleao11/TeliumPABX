-- Código de facilidade de cada condição horária.
--
-- Cada condição tem o seu código no telefone: o prefixo de "condicao_alterna"
-- (de fábrica *27) mais o número da condição — *271, *272… — e uma tecla
-- BLF que fica acesa enquanto a condição está forçada. É o que se usa para
-- fechar a entrada na mão (um feriado, uma reunião geral) sem abrir o
-- console.
--
--   codigo_acao   o que o código faz: fechar (padrão) manda para o destino
--                 de fora do horário mesmo dentro dele; abrir, o contrário;
--                 inverter faz o oposto do que o relógio diz agora, como o
--                 FreePBX. Discar de novo volta a seguir o relógio.
--   codigo_pin    se preenchido, o telefone pede este PIN antes.
--
-- Idempotente: rodar de novo não faz nada.

ALTER TABLE condicoes_horarias
  ADD COLUMN IF NOT EXISTS codigo_acao ENUM('fechar','abrir','inverter') NOT NULL DEFAULT 'fechar',
  ADD COLUMN IF NOT EXISTS codigo_pin  VARCHAR(10) NULL;

UPDATE codigos_recurso
   SET nome = 'Condição horária (prefixo)',
       descricao = CONCAT('Cada condição horária tem o seu código: este prefixo mais o número da condição ',
                          '(ex.: *271). Discar força a condição; discar de novo volta a seguir o horário. ',
                          'O código de cada uma aparece em Aplicações › Condições Horárias.')
 WHERE chave = 'condicao_alterna';
