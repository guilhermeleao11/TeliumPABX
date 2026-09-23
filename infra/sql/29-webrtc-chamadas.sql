-- O que aconteceu com o áudio de cada chamada do navegador.
--
-- Hoje, quando um cliente diz "a chamada conectou e não ouvi nada", a
-- única forma de saber por quê é pedir que ele rode sngrep, ou abrir o
-- console do navegador dele. Nenhuma das duas acontece.
--
-- O navegador, porém, SABE: ele tem o estado do ICE, por qual caminho o
-- áudio fechou (rede local, endereço público ou TURN) e quantos pacotes
-- entraram e saíram. Cada chamada do softphone passa a deixar esse
-- resumo aqui quando termina — alguns bytes — e o suporte lê depois,
-- sem depender de reproduzir o problema.
--
-- Não guarda áudio nem conteúdo: só números sobre o transporte.
-- Idempotente: rodar de novo não faz nada.

CREATE TABLE IF NOT EXISTS webrtc_chamadas (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ramal         VARCHAR(20)  NOT NULL,
  usuario_id    INT UNSIGNED NULL,
  direcao       ENUM('entrada','saida') NOT NULL DEFAULT 'saida',
  numero        VARCHAR(40)  NULL,
  inicio        DATETIME     NOT NULL,
  duracao       INT UNSIGNED NOT NULL DEFAULT 0,

  -- Como o áudio fechou: host = rede local, srflx = endereço público
  -- descoberto por STUN, relay = passou pelo TURN. Vazio quer dizer que
  -- não fechou de jeito nenhum.
  caminho_local  VARCHAR(12) NULL,
  caminho_remoto VARCHAR(12) NULL,
  ice_estado     VARCHAR(16) NULL,

  pacotes_entrada  INT UNSIGNED NOT NULL DEFAULT 0,
  pacotes_saida    INT UNSIGNED NOT NULL DEFAULT 0,
  perdidos         INT UNSIGNED NOT NULL DEFAULT 0,
  jitter_ms        SMALLINT UNSIGNED NULL,
  rtt_ms           SMALLINT UNSIGNED NULL,
  codec            VARCHAR(20) NULL,

  -- O veredito, calculado no servidor para a tela e o suporte lerem a
  -- mesma frase: ok | mudo | so_ouviu | so_falou | nao_fechou
  veredito      ENUM('ok','instavel','so_ouviu','so_falou','mudo','nao_fechou') NOT NULL DEFAULT 'ok',
  motivo_fim    VARCHAR(80) NULL,
  navegador     VARCHAR(120) NULL,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_webrtc_ramal (ramal, inicio),
  INDEX idx_webrtc_veredito (veredito, inicio),
  INDEX idx_webrtc_inicio (inicio)
) ENGINE=InnoDB;
