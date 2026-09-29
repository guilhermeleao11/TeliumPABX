-- O QR do Linphone passa a levar o endereço pelo qual o console foi
-- aberto, e não o nome da instalação.
--
-- Central instalada sem nome próprio chama-se "pabx.telium.local", que
-- nenhum celular resolve, e tem certificado autoassinado, que o Linphone
-- recusa. O endereço que o administrador digitou no navegador é o único
-- que sabidamente chega à central; é ele que vai no QR e no servidor SIP
-- da conta, e é por isso que precisa ficar guardado com o convite: quem
-- busca a configuração é o celular, que não sabe de onde veio o QR.
--
-- Idempotente: rodar de novo não faz nada.

ALTER TABLE provisionamento_convite
  ADD COLUMN IF NOT EXISTS servidor VARCHAR(255) NULL AFTER senha_cifrada,
  -- "https" só quando o certificado da central é de confiança; senão
  -- "http". Um convite https não é entregue por http.
  ADD COLUMN IF NOT EXISTS esquema  VARCHAR(5) NOT NULL DEFAULT 'https' AFTER servidor;
