-- =========================================================
-- Telium PABX — salas de conferência
--
-- Uma linha por sala. O gerador transforma cada uma num par de perfis
-- do ConfBridge (a sala e o participante) e numa extensão do dialplan.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS conferencias (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  numero        VARCHAR(20)  NOT NULL UNIQUE,     -- o que se disca para entrar
  nome          VARCHAR(80)  NOT NULL,
  descricao     VARCHAR(240) NULL,

  -- PIN do participante e PIN de quem manda na sala. Vazio = sem PIN.
  pin           VARCHAR(16)  NULL,
  pin_admin     VARCHAR(16)  NULL,

  audio_entrada VARCHAR(160) NULL,                -- anúncio antes de entrar
  max_usuarios  SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- 0 = sem limite
  gravar        BOOLEAN NOT NULL DEFAULT 0,

  -- Comportamento dentro da sala
  -- announce_join_leave do ConfBridge: pede o nome ao entrar e o
  -- toca para a sala na entrada e na saída.
  anunciar_entrada_saida BOOLEAN NOT NULL DEFAULT 1,
  anunciar_quantidade    BOOLEAN NOT NULL DEFAULT 1,
  musica_sozinho         BOOLEAN NOT NULL DEFAULT 1,
  silenciar_ao_entrar    BOOLEAN NOT NULL DEFAULT 0,
  esperar_admin          BOOLEAN NOT NULL DEFAULT 0,

  ativo         BOOLEAN NOT NULL DEFAULT 1,
  criado_em     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;
