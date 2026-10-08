-- Caixas postais sem ramal.
--
-- Até aqui toda caixa era de um ramal. Mas há recado que não é de
-- ninguém em particular: o cliente que liga fora do horário, o SAC sem
-- atendente. A caixa avulsa tem número, nome e senha próprios, entra no
-- seletor de destino (URA, condição horária, rota de entrada) e tem um
-- código de facilidade para ouvir: no dia seguinte, disca-se o código e
-- a central diz quem ligou, quando, e toca o recado.
--
-- O número mora no mesmo contexto do correio de voz dos ramais
-- ([telium] do voicemail.conf), por isso não pode ser número de ramal.
--
-- Idempotente: rodar de novo não faz nada.
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS caixas_postais (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero            VARCHAR(20)  NOT NULL,
  nome              VARCHAR(80)  NOT NULL,
  -- O app_voicemail lê a senha em claro do voicemail.conf, como a do ramal.
  senha             VARCHAR(12)  NULL,
  -- O código que abre a caixa para ouvir (*981). Vazio: só pelo *98.
  codigo            VARCHAR(8)   NULL,
  -- Com o código, pedir a senha ou entrar direto. Direto é para a caixa
  -- que qualquer ramal da empresa pode ouvir.
  pedir_senha       BOOLEAN      NOT NULL DEFAULT 1,
  -- A saudação: um anúncio, ou vazio para a do próprio correio — que se
  -- grava pelo menu da caixa (opção 0).
  anuncio_id        INT UNSIGNED NULL,
  email             VARCHAR(160) NULL,
  -- Mandar cópia do recado para o e-mail, e apagar da caixa depois.
  vm_email          BOOLEAN      NOT NULL DEFAULT 0,
  vm_apagar         BOOLEAN      NOT NULL DEFAULT 0,
  vm_max_mensagens  SMALLINT UNSIGNED NOT NULL DEFAULT 100,
  vm_max_segundos   SMALLINT UNSIGNED NOT NULL DEFAULT 180,
  ativo             BOOLEAN      NOT NULL DEFAULT 1,
  criado_em         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_caixa_numero (numero),
  UNIQUE KEY uq_caixa_codigo (codigo),
  CONSTRAINT fk_caixa_anuncio FOREIGN KEY (anuncio_id) REFERENCES anuncios (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- O supervisor cuida dos anúncios e das URAs; cuida também das caixas.
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, 'apps.caixaspostais' FROM perfis WHERE chave = 'supervisor';
