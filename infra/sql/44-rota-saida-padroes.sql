-- Rota de saída com mais de um padrão de discagem.
--
-- A rota guardava um padrão só. O celular de São Paulo se disca de dois
-- jeitos — 9XXXXXXXX e 09XXXXXXXX — e eram precisas duas rotas iguais em
-- tudo, menos no padrão, para a mesma coisa: "Celular 11" com o mesmo
-- tronco, a mesma classe e o mesmo PIN, editadas em dobro.
--
-- Os padrões passam para uma tabela própria, e com eles os prefixos: é
-- cada FORMA de discar que sabe o que tirar e o que pôr. O 09XXXXXXXX
-- tira o 0 e o 9XXXXXXXX não tira nada, e os dois saem iguais para a
-- operadora. Prefixo na rota inteira não daria conta disso.
--
-- O padrão e os prefixos de cada rota existente viram o primeiro padrão
-- dela, do jeito que estavam; depois as colunas antigas ficam vazias,
-- para não haver duas verdades. As colunas continuam na tabela (apagar
-- é sem volta), mas nada mais as lê.
--
-- Idempotente: rodar de novo não copia nada duas vezes.

CREATE TABLE IF NOT EXISTS rota_saida_padroes (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rota_id           INT UNSIGNED NOT NULL,
  padrao            VARCHAR(80) NOT NULL,        -- padrão do dialplan: _9XXXXXXXX
  prefixo_remover   VARCHAR(12) NULL,
  prefixo_adicionar VARCHAR(12) NULL,
  ordem             SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  -- O mesmo padrão duas vezes na mesma rota gerava a mesma extensão
  -- duas vezes. Entre rotas diferentes a conferência é que avisa: a
  -- ordem decide qual vale, e isso precisa ser dito, não recusado.
  UNIQUE KEY uk_rota_padrao (rota_id, padrao),
  KEY ix_padrao (padrao),
  -- Apagar a rota leva os padrões dela junto.
  CONSTRAINT fk_padrao_rota_saida FOREIGN KEY (rota_id)
    REFERENCES rotas_saida (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- A coluna antiga deixa de ser obrigatória: rota nova nasce sem ela.
ALTER TABLE rotas_saida MODIFY COLUMN padrao VARCHAR(80) NULL;

-- Cada rota que ainda não tem padrão na tabela nova recebe o seu.
INSERT INTO rota_saida_padroes (rota_id, padrao, prefixo_remover, prefixo_adicionar, ordem)
SELECT r.id, TRIM(r.padrao), NULLIF(r.prefixo_remover, ''), NULLIF(r.prefixo_adicionar, ''), 10
  FROM rotas_saida r
 WHERE r.padrao IS NOT NULL AND TRIM(r.padrao) <> ''
   AND NOT EXISTS (SELECT 1 FROM rota_saida_padroes p WHERE p.rota_id = r.id);

-- E só então a rota esquece o padrão: o que foi copiado mora lá agora.
UPDATE rotas_saida r
   SET r.padrao = NULL, r.prefixo_remover = NULL, r.prefixo_adicionar = NULL
 WHERE r.padrao IS NOT NULL
   AND EXISTS (SELECT 1 FROM rota_saida_padroes p WHERE p.rota_id = r.id);
