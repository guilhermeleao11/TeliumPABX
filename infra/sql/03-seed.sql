-- =========================================================
-- Telium PABX — dados iniciais
-- Espelha o protótipo do front-end. Senhas em PBKDF2-SHA256 (390.000 iterações).
--
-- Todas as contas nascem com a MESMA senha padrão de implantação:
--     T3l1um_@2024_@aD1m
--
-- Reaplicar este script NÃO sobrescreve senhas já trocadas (o ON DUPLICATE
-- KEY UPDATE abaixo não toca em senha_hash). Para trocar depois:
--     php bin/telium senha admin
-- =========================================================
SET NAMES utf8mb4;

-- ---------- empresa ----------
INSERT INTO empresa (id, nome, razao_social, cnpj, endereco, telefone, plano, ramais_contratados)
VALUES (1, 'Telium Networks', 'Telium Networks Telecomunicações Ltda.', '04.235.678/0001-90',
        'Av. Paulista, 1000 — São Paulo/SP', '11 3255-8800', 'Enterprise', 150)
ON DUPLICATE KEY UPDATE nome = VALUES(nome);

-- ---------- perfis ----------
INSERT INTO perfis (chave, nome, descricao, cor, sistema) VALUES
 ('admin','Administrador','Acesso irrestrito a todos os módulos, incluindo configuração do sistema.','brand',1),
 ('supervisor','Supervisor','Acompanha operação, filas e relatórios. Edita aplicações de atendimento.','info',1),
 ('operador','Operador','Atendente. Enxerga apenas o próprio painel e recursos pessoais.','ok',1),
 ('auditor','Auditoria','Somente leitura de relatórios, gravações e trilhas de auditoria.','warn',1)
ON DUPLICATE KEY UPDATE nome = VALUES(nome), descricao = VALUES(descricao);

-- ---------- módulos por perfil ----------
INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT id, '*' FROM perfis WHERE chave = 'admin';

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, m.modulo FROM perfis p JOIN (
  SELECT 'dash.*' AS modulo UNION ALL SELECT 'rel.*' UNION ALL SELECT 'pcu.*'
  UNION ALL SELECT 'apps.filas' UNION ALL SELECT 'apps.grupostoque' UNION ALL SELECT 'apps.ura'
  UNION ALL SELECT 'apps.conferencias' UNION ALL SELECT 'apps.gravacao' UNION ALL SELECT 'apps.anuncios'
  UNION ALL SELECT 'apps.condicoes' UNION ALL SELECT 'apps.grupohorario'
  UNION ALL SELECT 'conn.ramais' UNION ALL SELECT 'telium.painel' UNION ALL SELECT 'telium.tarifacao'
) m WHERE p.chave = 'supervisor';

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, m.modulo FROM perfis p JOIN (
  SELECT 'dash.visaogeral' AS modulo UNION ALL SELECT 'pcu.*'
  UNION ALL SELECT 'apps.sigame' UNION ALL SELECT 'apps.correiovoz'
) m WHERE p.chave = 'operador';

INSERT IGNORE INTO perfil_modulos (perfil_id, modulo)
SELECT p.id, m.modulo FROM perfis p JOIN (
  SELECT 'dash.visaogeral' AS modulo UNION ALL SELECT 'rel.*'
  UNION ALL SELECT 'admin.gravacoes' UNION ALL SELECT 'pcu.chamadas'
) m WHERE p.chave = 'auditor';

-- ---------- ações por perfil ----------
INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, a.acao FROM perfis p JOIN (
  SELECT 'criar' AS acao UNION ALL SELECT 'editar' UNION ALL SELECT 'excluir'
  UNION ALL SELECT 'exportar' UNION ALL SELECT 'reiniciar' UNION ALL SELECT 'permissoes'
) a WHERE p.chave = 'admin';

INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, a.acao FROM perfis p JOIN (
  SELECT 'criar' AS acao UNION ALL SELECT 'editar' UNION ALL SELECT 'exportar'
) a WHERE p.chave = 'supervisor';

INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, 'editar' FROM perfis p WHERE p.chave = 'operador';

INSERT IGNORE INTO perfil_acoes (perfil_id, acao)
SELECT p.id, 'exportar' FROM perfis p WHERE p.chave = 'auditor';

-- ---------- usuários ----------
INSERT INTO usuarios (usuario, nome, email, senha_hash, perfil_id, ramal, setor, status)
SELECT v.usuario, v.nome, v.email, 'pbkdf2_sha256$390000$YOaItVoHDd2/hpoLNDFOcQ==$ad2bQSeyuzfdiIVwHPDZuvTYZ/FJMr8E4Yqu/vW0LJ4=', p.id, v.ramal, v.setor, v.status
FROM (
  SELECT 'admin' usuario,'Guilherme Leão' nome,'grp-voip@telium.com.br' email,
         'admin' perfil,'1000' ramal,'TI / VoIP' setor,'ativo' status
  UNION ALL SELECT 'supervisor','Marina Duarte','marina@telium.com.br','supervisor','1010','Atendimento','ativo'
  UNION ALL SELECT 'operador','Rafael Santos','rafael@telium.com.br','operador','2031','Suporte N1','ativo'
  UNION ALL SELECT 'auditor','Carla Nogueira','carla@telium.com.br','auditor','1500','Compliance','ativo'
  UNION ALL SELECT 'jmartins','João Martins','joao@telium.com.br','operador','2032','Suporte N1','ativo'
  UNION ALL SELECT 'pcosta','Patrícia Costa','patricia@telium.com.br','operador','2033','Comercial','inativo'
  UNION ALL SELECT 'lferreira','Lucas Ferreira','lucas@telium.com.br','supervisor','1011','Comercial','ativo'
  UNION ALL SELECT 'abraga','Ana Braga','ana@telium.com.br','operador','2034','Financeiro','bloqueado'
) v JOIN perfis p ON p.chave = v.perfil
ON DUPLICATE KEY UPDATE nome = VALUES(nome), email = VALUES(email);

-- ---------- ramais ----------
-- Os ramais falam SIP puro com o Asterisk: quem conversa WebRTC com o
-- navegador é o Janus (plugin SIP). Por isso webrtc=0 e transporte=udp.
INSERT INTO ramais (numero, nome, setor, email, senha_sip, gravar, voicemail, webrtc, transporte)
VALUES
 ('1000','Guilherme Leão','TI / VoIP','grp-voip@telium.com.br', SUBSTRING(SHA2(CONCAT(UUID(), '1000', RAND()), 256), 1, 24), 'ambas', 1, 0, 'udp'),
 ('1010','Marina Duarte','Atendimento','marina@telium.com.br',  SUBSTRING(SHA2(CONCAT(UUID(), '1010', RAND()), 256), 1, 24), 'ambas', 1, 0, 'udp'),
 ('1011','Lucas Ferreira','Comercial','lucas@telium.com.br',    SUBSTRING(SHA2(CONCAT(UUID(), '1011', RAND()), 256), 1, 24), 'nao',   1, 0, 'udp'),
 ('1500','Carla Nogueira','Compliance','carla@telium.com.br',   SUBSTRING(SHA2(CONCAT(UUID(), '1500', RAND()), 256), 1, 24), 'ambas', 1, 0, 'udp'),
 ('2031','Rafael Santos','Suporte N1','rafael@telium.com.br',   SUBSTRING(SHA2(CONCAT(UUID(), '2031', RAND()), 256), 1, 24), 'ambas', 0, 0, 'udp'),
 ('2032','João Martins','Suporte N1','joao@telium.com.br',      SUBSTRING(SHA2(CONCAT(UUID(), '2032', RAND()), 256), 1, 24), 'ambas', 1, 0, 'udp'),
 ('2033','Patrícia Costa','Comercial','patricia@telium.com.br', SUBSTRING(SHA2(CONCAT(UUID(), '2033', RAND()), 256), 1, 24), 'nao',   1, 0, 'udp'),
 ('2034','Ana Braga','Financeiro','ana@telium.com.br',          SUBSTRING(SHA2(CONCAT(UUID(), '2034', RAND()), 256), 1, 24), 'ambas', 1, 0, 'udp'),
 ('3001','Recepção','Recepção', NULL,                           SUBSTRING(SHA2(CONCAT(UUID(), '3001', RAND()), 256), 1, 24), 'nao',   0, 0, 'udp'),
 ('3002','Sala de Reunião','Corporativo', NULL,                 SUBSTRING(SHA2(CONCAT(UUID(), '3002', RAND()), 256), 1, 24), 'nao',   0, 0, 'udp')
ON DUPLICATE KEY UPDATE nome = VALUES(nome), setor = VALUES(setor);

-- ---------- troncos ----------
INSERT INTO troncos (nome, tipo, host, usuario, senha, registrar, canais_max, contexto_entrada)
VALUES
 ('SIP-Vivo-Principal','pjsip','sip.vivo.com.br','telium01','TROQUE', 1, 60,'de-tronco'),
 ('SIP-Claro-Backup','pjsip','sip.claro.com.br','telium02','TROQUE', 1, 30,'de-tronco'),
 ('SIP-Lab-Interno','pjsip','127.0.0.1','lab','TROQUE', 0, 10,'de-tronco')
ON DUPLICATE KEY UPDATE host = VALUES(host);

-- ---------- horários e rotas ----------
INSERT IGNORE INTO grupos_horario (id, nome) VALUES (1,'Comercial'), (2,'24x7');
INSERT IGNORE INTO grupo_horario_faixas (grupo_id, dias, hora_inicio, hora_fim)
VALUES (1,'mon-fri','08:00:00','18:00:00'), (2,'mon-sun','00:00:00','23:59:59');

INSERT IGNORE INTO filas (numero, nome, estrategia, sla_segundos, gravar) VALUES
 ('600','Suporte Técnico','ringall',20,1),
 ('601','Comercial','leastrecent',20,1),
 ('602','Financeiro','linear',30,1),
 ('603','Retenção','fewestcalls',20,1);

INSERT IGNORE INTO ura (id, nome, audio, timeout_digito, tentativas,
                        destino_timeout_tipo, destino_timeout_valor,
                        destino_invalido_tipo, destino_invalido_valor)
VALUES (1,'URA Principal','ura-principal',8,3,'ramal','3001','ura','1');

INSERT IGNORE INTO ura_opcoes (ura_id, tecla, rotulo, destino_tipo, destino_valor, ordem) VALUES
 (1,'1','Suporte Técnico','fila','600',10),
 (1,'2','Comercial','fila','601',20),
 (1,'3','Financeiro','fila','602',30),
 (1,'4','Falar com atendente','ramal','3001',40),
 (1,'9','Repetir menu','ura','1',50),
 (1,'0','Diretório por nome','app','diretorio',60);

INSERT IGNORE INTO rotas_entrada (did, descricao, destino_tipo, destino_valor, ordem, gravar) VALUES
 ('1133255880','Comercial 0800','ura','1',10,1),
 ('1133255881','Suporte direto','fila','600',20,1),
 ('_X.','Catch-all','ramal','3001',99,0);

INSERT IGNORE INTO rotas_saida (nome, ordem, padrao, prefixo_remover, tronco_id, classe)
SELECT v.nome, v.ordem, v.padrao, v.prefixo, t.id, v.classe FROM (
  SELECT 'Emergência' nome, 0 ordem, '_1XX' padrao, NULL prefixo, 'emergencia' classe, 'SIP-Vivo-Principal' tronco
  UNION ALL SELECT 'Local / Fixo', 10, '_0[2-5]XXXXXXX', '0', 'local', 'SIP-Vivo-Principal'
  UNION ALL SELECT 'Celular', 20, '_09XXXXXXXX', '0', 'celular', 'SIP-Vivo-Principal'
  UNION ALL SELECT 'DDD Nacional', 30, '_0XXXXXXXXXX.', NULL, 'ddd', 'SIP-Claro-Backup'
  UNION ALL SELECT 'Internacional', 40, '_00.', NULL, 'ddi', 'SIP-Claro-Backup'
) v JOIN troncos t ON t.nome = v.tronco;

-- ---------- tarifas ----------
INSERT IGNORE INTO tarifas (nome, padrao, custo_minuto) VALUES
 ('Fixo local','_0[2-5]XXXXXXX',0.1200),
 ('Celular','_09XXXXXXXX',0.2500),
 ('DDD nacional','_0XXXXXXXXXX.',0.3300),
 ('Internacional','_00.',0.8000),
 ('Gratuito','_0800XXXXXXX',0.0000);

-- ---------- estado ----------
INSERT INTO sistema (chave, valor) VALUES ('config_pendente','0'), ('schema_versao','1')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);
