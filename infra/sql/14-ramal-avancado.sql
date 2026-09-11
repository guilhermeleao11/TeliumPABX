-- =========================================================
-- Telium PABX — ramal completo
--
-- O ramal ganha o que o PJSIP do Asterisk 22 entrega e que muda
-- comportamento de verdade: sinalização, mídia, MWI, temporizadores,
-- DTLS e as opções de discagem e gravação por sentido de chamada.
--
-- Cada coluna aqui vira uma linha do endpoint, do aor ou do auth. O que
-- o Asterisk não tem, ou que não muda nada na prática, ficou de fora.
-- Idempotente: pode ser reaplicado.
-- =========================================================
SET NAMES utf8mb4;

ALTER TABLE ramais
  -- ---------- identificação ----------
  ADD COLUMN IF NOT EXISTS pin              VARCHAR(20)  NULL AFTER senha_sip,
  ADD COLUMN IF NOT EXISTS accountcode      VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS alias_sip        VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS cid_pseudo       VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS did              VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS did_descricao    VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS cid_entrada      VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS contexto_custom  VARCHAR(60)  NULL,

  -- ---------- sinalização ----------
  ADD COLUMN IF NOT EXISTS dtmf_modo        ENUM('rfc4733','inband','info','auto','auto_info')
                                            NOT NULL DEFAULT 'rfc4733',
  ADD COLUMN IF NOT EXISTS trust_rpid       BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS envia_rpid       BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS envia_pai        BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS send_connected   BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS user_eq_phone    BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS qualify_freq     SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  ADD COLUMN IF NOT EXISTS timers_sessao    ENUM('nao','sim','obrigatorio') NOT NULL DEFAULT 'sim',
  ADD COLUMN IF NOT EXISTS timers_expira    SMALLINT UNSIGNED NOT NULL DEFAULT 1800,
  ADD COLUMN IF NOT EXISTS expira_max       SMALLINT UNSIGNED NOT NULL DEFAULT 3600,
  ADD COLUMN IF NOT EXISTS expira_min       SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  ADD COLUMN IF NOT EXISTS proxy_saida      VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS contexto_mensagens VARCHAR(60) NULL,

  -- ---------- mídia ----------
  ADD COLUMN IF NOT EXISTS codecs_negados   VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS direct_media     BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS media_address    VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS rtp_simetrico    BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS reescrever_contato BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS forcar_rport     BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS usar_transporte_recebido BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rtp_timeout      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rtp_timeout_hold SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS max_audio        TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS max_video        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS srtp_oportunista BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS remove_existing  BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS refer_blind_progress BOOLEAN NOT NULL DEFAULT 1,

  -- ---------- WebRTC e DTLS ----------
  ADD COLUMN IF NOT EXISTS avpf             BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ice              BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS rtcp_mux         BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS dtls             BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS dtls_verificar   ENUM('no','fingerprint','certificate','yes')
                                            NOT NULL DEFAULT 'fingerprint',
  ADD COLUMN IF NOT EXISTS dtls_setup       ENUM('active','passive','actpass') NOT NULL DEFAULT 'actpass',
  ADD COLUMN IF NOT EXISTS dtls_rekey       SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  -- ---------- MWI ----------
  ADD COLUMN IF NOT EXISTS mwi_tipo         ENUM('auto','solicitado','nao_solicitado')
                                            NOT NULL DEFAULT 'auto',
  ADD COLUMN IF NOT EXISTS mwi_agregado     BOOLEAN NOT NULL DEFAULT 0,

  -- ---------- discagem e atendimento ----------
  ADD COLUMN IF NOT EXISTS opcoes_dial      VARCHAR(40)  NULL,
  ADD COLUMN IF NOT EXISTS toque_sigame     SMALLINT UNSIGNED NOT NULL DEFAULT 20,
  ADD COLUMN IF NOT EXISTS max_saidas       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS chamada_espera   BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS tom_espera       BOOLEAN NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS rastreio_chamada BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS auto_resposta    BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS interfonia       ENUM('permitir','negar') NOT NULL DEFAULT 'permitir',
  ADD COLUMN IF NOT EXISTS estado_em_fila   BOOLEAN NOT NULL DEFAULT 1,

  -- ---------- gravação por sentido ----------
  ADD COLUMN IF NOT EXISTS grav_ext_entrada ENUM('forcar','sim','indiferente','nao','nunca')
                                            NOT NULL DEFAULT 'indiferente',
  ADD COLUMN IF NOT EXISTS grav_ext_saida   ENUM('forcar','sim','indiferente','nao','nunca')
                                            NOT NULL DEFAULT 'indiferente',
  ADD COLUMN IF NOT EXISTS grav_int_entrada ENUM('forcar','sim','indiferente','nao','nunca')
                                            NOT NULL DEFAULT 'indiferente',
  ADD COLUMN IF NOT EXISTS grav_int_saida   ENUM('forcar','sim','indiferente','nao','nunca')
                                            NOT NULL DEFAULT 'indiferente',
  ADD COLUMN IF NOT EXISTS grav_sob_demanda ENUM('desabilitado','ativar','sobrepor')
                                            NOT NULL DEFAULT 'ativar',
  ADD COLUMN IF NOT EXISTS grav_prioridade  TINYINT UNSIGNED NOT NULL DEFAULT 10,

  -- ---------- ditado ----------
  ADD COLUMN IF NOT EXISTS ditado           BOOLEAN NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS ditado_formato   ENUM('wav','gsm','ogg') NOT NULL DEFAULT 'wav',
  ADD COLUMN IF NOT EXISTS ditado_email     VARCHAR(160) NULL,
  ADD COLUMN IF NOT EXISTS ditado_remetente VARCHAR(160) NULL;

-- Índice do alias, que é usado para casar chamada entrante.
ALTER TABLE ramais ADD INDEX IF NOT EXISTS idx_ramal_alias (alias_sip);
ALTER TABLE ramais ADD INDEX IF NOT EXISTS idx_ramal_did (did);

-- Quem já usava a coluna "gravar" continua com o mesmo comportamento
-- nas quatro novas, para nada mudar de sentido sem alguém pedir.
UPDATE ramais SET grav_ext_entrada = 'sim', grav_int_entrada = 'sim'
 WHERE gravar IN ('entrada','ambas') AND grav_ext_entrada = 'indiferente';
UPDATE ramais SET grav_ext_saida = 'sim', grav_int_saida = 'sim'
 WHERE gravar IN ('saida','ambas') AND grav_ext_saida = 'indiferente';
