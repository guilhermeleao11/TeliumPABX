-- =========================================================
-- Telium PABX — Megafonia/Interfonia e DISA
--
-- Os dois já tinham item de menu e código de recurso; faltavam o
-- cadastro e o dialplan. São recursos que todo PABX de mercado tem, e
-- a ausência aparece na primeira demonstração.
-- =========================================================
SET NAMES utf8mb4;

-- Megafonia: um número que toca em vários aparelhos ao mesmo tempo,
-- com viva-voz aberto. É o "atenção, loja" do balcão.
CREATE TABLE IF NOT EXISTS grupos_paging (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero       VARCHAR(20)  NOT NULL,
  nome         VARCHAR(80)  NOT NULL,
  ramais       TEXT         NOT NULL,          -- números separados por hífen
  duplex       BOOLEAN      NOT NULL DEFAULT 0, -- 1 = todos falam; 0 = só quem chamou
  anuncio_id   INT UNSIGNED NULL,              -- toca antes de abrir o som
  forcar       BOOLEAN      NOT NULL DEFAULT 0, -- interrompe quem está em chamada
  duracao_max  SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  ativo        BOOLEAN      NOT NULL DEFAULT 1,
  criado_em    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_paging_numero (numero),
  KEY idx_paging_ativo (ativo)
) ENGINE=InnoDB;

-- DISA: ligar de fora, ouvir o tom da central e discar como se
-- estivesse na mesa. Poderoso e perigoso na mesma medida — por isso a
-- senha é obrigatória e o padrão é o mínimo de permissão.
CREATE TABLE IF NOT EXISTS disa (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome          VARCHAR(80)  NOT NULL,
  senha         VARCHAR(20)  NOT NULL,
  contexto      VARCHAR(40)  NOT NULL DEFAULT 'interno',
  cid_saida     VARCHAR(40)  NULL,
  tempo_digito  TINYINT UNSIGNED NOT NULL DEFAULT 10,
  tentativas    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  responder     BOOLEAN      NOT NULL DEFAULT 1,
  ativo         BOOLEAN      NOT NULL DEFAULT 1,
  criado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_disa_nome (nome)
) ENGINE=InnoDB;

-- Vínculo com o anúncio, quando a tabela já existir.
SET @existe := (
  SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
   WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'grupos_paging'
     AND CONSTRAINT_NAME = 'fk_paging_anuncio'
);
SET @sql := IF(@existe = 0,
  'ALTER TABLE grupos_paging
     ADD CONSTRAINT fk_paging_anuncio FOREIGN KEY (anuncio_id)
     REFERENCES anuncios (id) ON DELETE SET NULL',
  'DO 0');
PREPARE p FROM @sql; EXECUTE p; DEALLOCATE PREPARE p;

-- Os dois módulos entram no perfil de administrador.
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'apps.paging' FROM perfis WHERE chave = 'admin';
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'apps.disa' FROM perfis WHERE chave = 'admin';
