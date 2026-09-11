-- =========================================================
-- Telium PABX — anúncios
--
-- O arquivo enviado em Gravações do Sistema é matéria-prima. Quem os
-- módulos usam é o anúncio: uma gravação mais o que fazer com ela —
-- deixar pular, repetir, voltar para a URA, e para onde a chamada vai
-- depois de tocar.
--
-- Por isso nenhuma outra tabela guarda mais o nome de um arquivo de
-- áudio: todas passaram a apontar para um anúncio.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS anuncios (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome           VARCHAR(80)  NOT NULL,
  descricao      VARCHAR(240) NULL,
  audio_id       INT UNSIGNED NOT NULL,

  -- Tecla que repete o anúncio. NULL = não repete.
  repetir_tecla  VARCHAR(2)   NULL,
  -- Qualquer tecla interrompe e segue para o destino.
  permitir_pular BOOLEAN NOT NULL DEFAULT 0,
  -- Terminado o anúncio, volta para a URA de onde a chamada veio.
  retornar_ura   BOOLEAN NOT NULL DEFAULT 0,
  -- Toca sem atender o canal (não gera tarifação na operadora).
  nao_responder  BOOLEAN NOT NULL DEFAULT 0,

  destino_tipo   VARCHAR(24) NULL,
  destino_valor  VARCHAR(80) NULL,

  ativo          BOOLEAN NOT NULL DEFAULT 1,
  criado_em      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (audio_id) REFERENCES audios(id) ON DELETE RESTRICT,
  INDEX idx_anuncio_audio (audio_id)
) ENGINE=InnoDB;

-- ---------------------------------------------------------
-- Quem apontava para um arquivo passa a apontar para um anúncio.
-- As colunas antigas guardavam o nome do arquivo; as novas guardam o id
-- do anúncio, então são colunas novas mesmo — não dá para converter o
-- conteúdo sem inventar anúncio para arquivo que ninguém cadastrou.
-- ---------------------------------------------------------
ALTER TABLE ura
  ADD COLUMN IF NOT EXISTS anuncio_id INT UNSIGNED NULL AFTER audio;

ALTER TABLE filas
  ADD COLUMN IF NOT EXISTS anuncio_entrada_id   INT UNSIGNED NULL AFTER musica_espera,
  ADD COLUMN IF NOT EXISTS anuncio_agente_id    INT UNSIGNED NULL AFTER anuncio_entrada_id,
  ADD COLUMN IF NOT EXISTS anuncio_periodico_id INT UNSIGNED NULL AFTER anuncio_agente_id;

ALTER TABLE conferencias
  ADD COLUMN IF NOT EXISTS anuncio_entrada_id INT UNSIGNED NULL AFTER audio_entrada;

ALTER TABLE pesquisas
  ADD COLUMN IF NOT EXISTS anuncio_pergunta_id INT UNSIGNED NULL AFTER audio_pergunta,
  ADD COLUMN IF NOT EXISTS anuncio_obrigado_id INT UNSIGNED NULL AFTER audio_obrigado;

ALTER TABLE lista_negra
  ADD COLUMN IF NOT EXISTS anuncio_id INT UNSIGNED NULL AFTER audio_id;

-- ura.audio era obrigatória e guardava o nome do arquivo. Agora quem
-- manda é anuncio_id, então ela deixa de ser exigida — sem isso, criar
-- uma URA pelo console pediria um campo que a tela não mostra mais.
ALTER TABLE ura MODIFY COLUMN audio VARCHAR(120) NULL;

-- As colunas de arquivo saem de cena. Ficam no banco só para uma
-- instalação antiga não perder o que tinha configurado, mas o gerador
-- e a tela não olham mais para elas.
