-- =========================================================
-- Telium PABX — agenda de contatos e destinos personalizados
--
-- A tabela contatos nasceu com o mínimo (nome e número). Aqui ela vira
-- uma agenda de verdade: empresa, cargo, endereço, e-mails, links e foto.
-- ALTER ... IF NOT EXISTS mantém o arquivo reaplicável.
-- =========================================================
SET NAMES utf8mb4;

ALTER TABLE contatos
  ADD COLUMN IF NOT EXISTS empresa       VARCHAR(120) NULL AFTER nome,
  ADD COLUMN IF NOT EXISTS cargo         VARCHAR(80)  NULL AFTER empresa,
  ADD COLUMN IF NOT EXISTS departamento  VARCHAR(80)  NULL AFTER cargo,
  ADD COLUMN IF NOT EXISTS celular       VARCHAR(40)  NULL AFTER numero,
  ADD COLUMN IF NOT EXISTS telefone      VARCHAR(40)  NULL AFTER celular,
  ADD COLUMN IF NOT EXISTS ramal_interno VARCHAR(20)  NULL AFTER telefone,
  ADD COLUMN IF NOT EXISTS email         VARCHAR(160) NULL AFTER ramal_interno,
  ADD COLUMN IF NOT EXISTS email_alt     VARCHAR(160) NULL AFTER email,
  ADD COLUMN IF NOT EXISTS site          VARCHAR(200) NULL AFTER email_alt,
  ADD COLUMN IF NOT EXISTS links         TEXT         NULL AFTER site,
  ADD COLUMN IF NOT EXISTS endereco      VARCHAR(200) NULL AFTER links,
  ADD COLUMN IF NOT EXISTS complemento   VARCHAR(80)  NULL AFTER endereco,
  ADD COLUMN IF NOT EXISTS bairro        VARCHAR(80)  NULL AFTER complemento,
  ADD COLUMN IF NOT EXISTS cidade        VARCHAR(80)  NULL AFTER bairro,
  ADD COLUMN IF NOT EXISTS uf            VARCHAR(2)   NULL AFTER cidade,
  ADD COLUMN IF NOT EXISTS cep           VARCHAR(12)  NULL AFTER uf,
  ADD COLUMN IF NOT EXISTS pais          VARCHAR(60)  NULL AFTER cep,
  ADD COLUMN IF NOT EXISTS aniversario   DATE         NULL AFTER pais,
  ADD COLUMN IF NOT EXISTS notas         TEXT         NULL AFTER aniversario,
  ADD COLUMN IF NOT EXISTS foto          VARCHAR(160) NULL AFTER notas,
  ADD COLUMN IF NOT EXISTS ativo         BOOLEAN NOT NULL DEFAULT 1 AFTER favorito,
  ADD COLUMN IF NOT EXISTS criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ADD COLUMN IF NOT EXISTS atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                                         ON UPDATE CURRENT_TIMESTAMP;

-- O número deixou de ser obrigatório: um contato pode ter só celular,
-- só e-mail ou só ramal. Quem exige ao menos um meio de contato é a API.
ALTER TABLE contatos MODIFY COLUMN numero VARCHAR(40) NULL;

-- Busca pelo nome da empresa é tão comum quanto pelo nome da pessoa.
ALTER TABLE contatos ADD INDEX IF NOT EXISTS idx_contatos_empresa (empresa);
ALTER TABLE contatos ADD INDEX IF NOT EXISTS idx_contatos_usuario (usuario_id);

-- ---------------------------------------------------------
-- Destinos personalizados: apontam para contextos que o
-- administrador escreveu em extensions_custom.conf.
-- ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS destinos_personalizados (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome       VARCHAR(80)  NOT NULL,
  descricao  VARCHAR(240) NULL,
  contexto   VARCHAR(80)  NOT NULL,               -- contexto do dialplan
  extensao   VARCHAR(40)  NOT NULL DEFAULT 's',
  prioridade VARCHAR(12)  NOT NULL DEFAULT '1',
  ativo      BOOLEAN NOT NULL DEFAULT 1,
  criado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_destino (contexto, extensao, prioridade)
) ENGINE=InnoDB;
