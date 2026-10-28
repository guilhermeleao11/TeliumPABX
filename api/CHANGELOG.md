# Histórico de versões — Telium PABX

A versão mais recente vem primeiro. Até a 1.1.0, o número mostrado no console não mudava a cada entrega (ficou em 1.0.1 o tempo todo); por isso as versões 1.0.0 a 1.0.7 foram numeradas depois, pela data de cada entrega. Numa central instalada antes da 1.1.0, o commit do git (`git log --oneline -1` em /opt/TeliumPABX) diz qual delas está rodando.

## 1.8.0 — 2026-10-27
Commits:
Monitoria pelo Zabbix instalada junto com a central.

### Novidades
- **zabbix-agent2 instalado pelo playbook** (pacote do Debian), apontado para os servidores Zabbix da Telium (198.18.22.114, 10.16.140.114, 200.155.185.231), com o nome da máquina (`hostname -a`, ou o `hostname`). Só na instalação nova (pergunta "Monitorar? [S/n]"). **A atualização não mexe em nada da monitoria**; numa central já instalada, só pedindo: `-e zabbix_configurar=true`.
- **Arquivos nos lugares de sempre**: scripts em `/Telium/zabbix/scripts` (`zbx_asterisk_ami.py`, `zbx_telium.sh`, `check_pacotes_updates.sh`) e os itens em `/etc/zabbix/zabbix_agent2.d/` (`asterisk_plugin.conf`, `telium.conf`, `debian_updates.conf`).
- **Itens**: os do Asterisk pelo AMI (`asterisk.*`); os do sistema Telium para o template PBX - TeliumPABX (`telium.servername`, `telium.versao`, `telium.saudavel`, `telium.ssl.*` com os dias até o certificado vencer, `telium.backup.*`); os pacotes com atualização (`pacotes.updates.count`); o MariaDB pelo plugin do agent2 (usuário `zbx_monitor`, sessão `Default`); e o status do nginx em `http://127.0.0.1:8080/nginx_status`.
- **Template "PBX - TeliumPABX"** para o Zabbix 7.4 (`infra/zabbix/PBX-TeliumPABX.yaml`): os itens do sistema Telium e os alertas (certificado vencendo ou vencido, backup falhou ou atrasado, envio remoto falhou, serviço do Telium parado, API com problema), com macros para os prazos.
- **Teste de SSH**: usuário `zabbix_test` com a chave do servidor Zabbix, que só responde `SSH_OK` (e o link em `/etc/ssh/keys` quando o sshd usa essa pasta).

### Segurança
- As senhas do usuário `zabbix` do AMI e do `zbx_monitor` do banco são as do Zabbix da Telium e ficam fora do Git: `infra/ansible/zabbix-senhas.yml` na máquina que roda o playbook (modelo `zabbix-senhas.yml.exemplo`); sem ele, a instalação para no começo. O script lê a do AMI de `/etc/zabbix/telium-ami.conf` (`root:zabbix 0640`).
- O usuário `zabbix` do AMI vai para o `manager_custom.conf`, que o playbook nunca reescreve; um `[zabbix]` já existente fica como está.
- O `zbx_monitor` (localhost, 127.0.0.1 e 198.18.22.114) lê só estatísticas e as colunas de estado do backup; a senha do FTP do destino remoto fica de fora. O banco continua escutando só em 127.0.0.1.
- A porta 10050 do agente é liberada no ufw só para os servidores Zabbix.
- O agente lê o log do Asterisk por uma permissão só dele (ACL), sem entrar no grupo asterisk, que abriria o `/etc/asterisk`.

### Correções (nos arquivos de monitoria de antes)
- `asterisk.log.errors` e `asterisk.log.warnings`: com zero linhas, o `grep -c || echo 0` devolvia "0" duas vezes e o Zabbix recusava; e o agente não tinha leitura da pasta do log. Agora contam no `messages`.
- `pacotes.updates.count`: o aviso do bash sobre o locale `en_US.UTF-8`, que o Debian não tem, ia junto do número.

## 1.7.1 — 2026-10-27
Commits: 6dda4bc
Migração do SNEP mantém a senha SIP curta.

### Mudanças
- **Migração do SNEP**: o ramal fica com a mesma senha SIP do SNEP mesmo com menos de 12 caracteres, e o telefone registra sem reconfigurar. A prévia mostra um aviso nesses ramais ("troque por uma forte quando puder"). Só o ramal sem senha no SNEP (ou com quebra de linha nela) ganha uma nova, que vem na lista de senhas novas.
- O ramal com senha curta é publicado no `pjsip.conf`; antes, abaixo de 8 caracteres ficava de fora. Fica de fora só o ramal sem senha. O cadastro continua exigindo 12 caracteres para senha digitada.

## 1.7.0 — 2026-10-26
Commits: 1089437
Instalação e atualização de fora da central, com perguntas; SIP só para redes conhecidas.

### Novidades
- **Instalar e atualizar de outra máquina**: da estação com o clone e o Ansible, `cd infra/ansible && ansible-playbook -i "200.170.198.176," TeliumPBX_instalacao.yml -u root -k`. É o mesmo `site.yml` de sempre, que continua rodando de dentro da central (`sudo ansible-playbook site.yml`). Atualizar várias de uma vez: `-i "IP1,IP2,"`.
- **A instalação pergunta tudo o que é do cliente**, uma vez: nome da central, nome do cliente, IP público (quando o servidor está atrás de NAT; sugere o IP usado no `-i`), o IP do áudio do softphone enquanto o nome não está no DNS, Janus, Asternic, SIP só para redes conhecidas (e as redes extras), syslog da Telium, ambiente de cliente ou de laboratório (com o cenário de teste) e a senha inicial do admin. Mostra o resumo e confirma antes de instalar.
- **Central já instalada**: mostra a versão instalada e a do clone e pergunta: 1 atualizar mantendo tudo (Enter), 2 limpar o banco (só depois de digitar o nome da central; pergunta também o cenário de teste), 3 cancelar. Limpar o banco e o cenário de teste valem só para aquela execução e nunca ficam guardados. Sem terminal, ou com `-e perguntar=false`, só atualiza.
- **Os ajustes de cada central ficam no próprio servidor**, em `/etc/telium-servidor.yml` (só root), e não mais no `servidor.yml` do clone: qualquer técnico, de qualquer máquina, atualiza sem perder nada. Na primeira execução da 1.7.0 o `servidor.yml` antigo, que fica no clone dentro da central, é levado para lá sozinho (o original vira `servidor.yml.migrado`); sem ele, o nome e o IP público vêm do `.env` da API. Atualizar não pergunta nada.
- **SIP só para redes conhecidas** (desligado por padrão; perguntado na instalação): SIP, áudio, fax T.38, TURN e Janus só respondem ao Brasil e à Telium (a mesma lista dos servidores da Telium), às redes internas, às redes extras, ao "Nunca bloquear", aos troncos e aos telefones que já registraram. Os robôs de fora nem chegam ao Asterisk. Fica numa tabela própria do nftables, à parte do ufw e do fail2ban, e por isso vale também nos servidores com o iptables antigo da Telium, cuja lista de países só aceitava e nunca descartava (com o ufw atrás, ela não segurava ninguém). Reaplicado a cada 5 minutos (tronco novo entra sozinho) e na hora em que o "Nunca bloquear" é salvo; com o banco fora do ar, fica como estava. Comando: `telium-firewall-sip aplicar | desligar | mostrar`.

### Segurança
- Rodando de fora, o playbook para se o clone da estação tiver `servidor.yml` ou `vault.yml` em `group_vars/all/`: valeriam para todas as centrais.
- O playbook não publica uma versão mais antiga que a instalada (clone da estação sem `git pull`). De propósito: `-e permitir_versao_antiga=true`.
- Os ajustes ficam fora de `/etc/telium`, que é do usuário da API: quem a controlasse poderia trocá-los por algo que o Ansible executaria como root.
- As senhas de serviço (banco, CDR, AMI, ARI, Asternic, TURN) são geradas e lidas no próprio servidor, no começo do playbook. Antes vinham de um `lookup` que roda na máquina de quem executa: de fora, ele trocaria as senhas da central no meio da atualização e daria a mesma senha a todas. Nada secreto sai da estação.
- O `servidor.yml` antigo é procurado em todos os clones do servidor (`/opt/TeliumPABX`, `/opt/telium-src`…). Com mais de um, o playbook para e pede `-e servidor_yml_antigo=<caminho>`, em vez de escolher.
- Uma central antiga, sem a marca da senha inicial, é reconhecida pela versão publicada: recebe o menu de atualização, nunca as perguntas de central nova (que poderiam carregar o cenário de teste num banco de produção).
- Várias centrais de uma vez só para atualizar: todas precisam estar instaladas, a resposta do menu vale para todas, e cancelar cancela todas.
- Valores com `{{ }}` em `/etc/telium-servidor.yml` param o playbook dizendo qual: lidos do servidor, eles iriam literais para a configuração.

### Correções
- **verificar.yml**: a contagem de registros do banco dava erro de sintaxe no shell (`||` no começo da linha). O diagnóstico agora só lê: não migra nem cria os ajustes.

### Mudanças
- Não precisa mais de `-e compilar_asterisk=false -e compilar_janus=false`: o Asterisk e o Janus só são compilados quando a versão instalada não é a fixada (os parâmetros continuam aceitos).
- Para mudar um ajuste depois: edite `/etc/telium-servidor.yml` no servidor e rode o playbook.

## 1.6.1 — 2026-10-26
Commits: fb357b8
Firewall: bane os robôs que varrem a porta SIP.

### Novidades
- **fail2ban — varredura SIP** (cadeias `asterisk-varredura` e `asterisk-varredura-tcp`): o IP que pede 3 vezes em 1 hora um ramal que não existe ("No matching endpoint found", os `OPTIONS` de `sip:1@1` e os `INVITE` para números aleatórios) fica 24 horas sem a porta SIP. O Asterisk só registra isso no log comum, e as cadeias de antes, que leem o log de segurança, não viam esses pedidos.
- Não bane o IP de um tronco nem um IP com ramal registrado (um telefone de ramal apagado, no escritório, derrubaria os outros), nem a lista "Nunca bloquear". O filtro recusa aspa no From e no Call-ID, para ninguém fazer a central banir o IP da operadora escrevendo-o no pedido.

## 1.6.0 — 2026-10-26
Commits: 70baf14, 7a064c4
Feriados: cada um com o seu áudio.

### Novidades
- **Aplicações › Feriados**: cada feriado com o seu áudio e, se quiser, um destino depois dele (fila de plantão, caixa postal). No Natal toca o áudio do Natal, no Ano Novo o do Ano Novo, na Páscoa o da Páscoa — sem um grupo de horário e uma condição por feriado. A data é fixa (todo ano, com intervalo que pode virar o ano: 31/12 a 01/01), móvel (Carnaval, Quarta de Cinzas, Sexta-feira Santa, Páscoa, Corpus Christi, calculados pela Páscoa de cada ano) ou de um ano só (uma ponte), com horário opcional (véspera a partir das 12:00).
- **Carregar feriados nacionais**: cadastra de uma vez os que faltam, sem áudio, para escolher o de cada um. Só o dia do feriado (a véspera fica para cada empresa estender); Carnaval e Corpus Christi, ponto facultativo, entram desativados.
- Quem já ouviu o áudio do feriado e volta a uma condição (pela URA ou pela fila do destino dele) segue o horário: o áudio não toca em laço.
- **Condições horárias**: novo campo "Nos feriados, tocar o áudio do feriado" (ligado). A condição consulta os feriados depois do forçar e antes do horário; feriado sem áudio nem destino segue o "Fora do horário". O cartão mostra "Feriado: <nome>" no dia.
- Sem feriado cadastrado, nada muda nas condições que já existem. Quem já tem perfil com Condições Horárias ganha a tela de Feriados.

### Correções
- **Caixa postal: # ia para a transferência** em vez de encerrar o recado. Quem ligava de fora recebia a opção de transferir (`T` do Dial) e, quando a caixa postal atendia atravessando uma ponte (siga-me, desvio feito no próprio aparelho), o `##` da transferência cega era capturado. Agora só quem liga de um ramal pode transferir, e o ramal que atende continua podendo.
- **Siga-me**: a caixa postal do ramal de destino não atende mais dentro da perna do siga-me. No modo "junto", com o outro ramal desligado ou ocupado, ela atendia na hora e tirava a chamada do ramal que ainda tocava. O recado agora fica sempre na caixa do ramal dono do siga-me.
- **Testes**: o teste "o PIN não fica em claro no banco" falhava 1 vez em mil, quando o hash aleatório continha os dígitos do PIN.
- **Testes**: o teste da rede interna do provisionamento supunha as redes locais de fábrica e parava a atualização numa central com outra lista em **Redes locais** ("10.20.30.40 é da rede interna").

## 1.5.3 — 2026-10-21
Commits:
Ramais: exportar a planilha com as senhas.

### Novidades
- **Ramais › Exportar com senhas**: a planilha com a senha SIP e a do correio de voz de todos os ramais, para configurar os aparelhos (e que a importação por planilha aceita de volta). Pede a permissão de editar ramais — a mesma que mostra a senha na tela de credenciais —, confirma antes e fica na auditoria como "exportar com senhas". O **Exportar** de sempre continua sem senha.

## 1.5.2 — 2026-10-21
Commits: b04d2b5
Revisão da migração do SNEP e das rotas por grupo: a migração não deixa a central quebrada, e o banco anda junto com o código.

### Correções
- **Atualização**: "Unknown column 'grupo_saida'" ao gravar. Quem atualizava só a API (`--start-at-task "Publicar o código da API"`) ficava com código novo sobre banco velho. A etapa agora atualiza o banco logo depois de publicar o código; a conferência do esquema (tela de Saúde) conhece as colunas da 48; e o erro de coluna que falta diz como resolver (`php bin/telium migrar`).
- **Migração do SNEP — trava**: antes de gravar, roda a conferência do cadastro que a atualização roda; se a importação acrescentaria um erro, é cancelada sem gravar nada e mostra o motivo. Regra ou tecla de URA para ramal ou fila desativados fica para ser feita à mão.
- **Migração do SNEP**: regras com a mesma descrição viravam uma só (a segunda tomada como "já existe", ou a condição horária de um número usada em outro); agora levam o número da regra no nome. Regra com vários números e destino que depende do número (discar o próprio número, reescrita) mandava todos para o primeiro.
- **Migração do SNEP — áudios**: nome com espaço ou acento não era achado no pacote; a prévia passou a conferir se o arquivo é legível; importação desfeita apaga os áudios que converteu; agentes migrados não esperam o AMI.
- **Migração do SNEP — tempo**: a importação tem 15 minutos (o nginx cortava em 60 s e o PHP seguia gravando sem a tela saber, perdendo as senhas novas) e não para se o navegador desistir. Pedido sem "simular" válido é prévia, nunca importação.
- **Migração do SNEP — comando**: só pede ao mysqldump as tabelas que existem naquela versão do SNEP; acha o áudio que a regra cita com pasta (`custom/...`).
- **Migração do SNEP — tela**: prévias seguidas não travam mais o botão em "Conferindo…" nem mostram uma prévia velha.
- **Rotas de saída**: padrão só com rotas de grupo deixava sem saída quem não é ramal (desvio para número externo, DISA, siga-me); agora vai pela primeira do padrão. Rota de grupo com horário depois de uma do mesmo grupo sem horário é marcada como ignorada. O grupo de horário em uso por rota não pode ser apagado (chave estrangeira).
- **API**: num deadlock dentro de transação aninhada, o erro original não é mais escondido pelo SAVEPOINT que já não existe.

## 1.5.1 — 2026-10-21
Commits: f00c448
Na migração do SNEP, dá para escolher o que importar.

### Novidades
- **Migração do SNEP — escolher**: caixa por item (troncos, ramais, filas, agentes, regras de entrada e de saída) e por grupo. Só o marcado é importado; a prévia se refaz a cada escolha e mostra o que deixa de funcionar sem o item desmarcado.

## 1.5.0 — 2026-10-21
Commits: 4e11fb4
Rotas de saída por grupo de ramais e por horário, e a migração do SNEP com grupos, horários, datas e as filas dos agentes.

### Novidades
- **Rotas de saída — Só para**: uma rota pode valer só para alguns grupos de saída ou ramais (`NOC,Suporte,3602`). O ramal ganha **Grupos de saída** (aba Permissões). Com o mesmo padrão numa rota de grupo e numa geral, quem é do grupo sai pela dele e o resto pela geral.
- **Rotas de saída — Horário**: uma rota pode valer só num grupo de horário; fora dele, a chamada segue para a próxima rota do mesmo número.
- **Migração do SNEP — grupos**: o grupo do SNEP vira o setor do ramal, e as regras de saída de um grupo ou ramal viram rotas "Só para".
- **Migração do SNEP — horários**: as regras do mesmo número com horários (manhã, tarde, noite) viram condições horárias encadeadas na ordem do SNEP; faixa que vira a meia-noite vira duas; as datas da regra (feriados) viram faixas por dia e mês; regra de saída com horário vira rota com Horário.
- **Migração do SNEP — agentes**: o pacote leva, do histórico das filas dos últimos 90 dias, em que filas cada agente entrou, e o agente é criado nessas filas.
- **Migração do SNEP — prévia**: o que pede atenção vem primeiro em cada grupo; o que só vai ser criado vira um resumo com "ver todos".
### Correções
- **Migração do SNEP — ordem das regras**: o SNEP usa a regra de prioridade **maior** primeiro (confirmado no código dele); a migração usava a menor. Com duas regras para o mesmo número, ficava a errada.
- **Migração do SNEP**: os casos que ficam à mão (tronco sem host, gateway que registrava no SNEP, ExecuteApp, número interno que é ramal) dizem o que fazer e, no ExecuteApp, o que a regra fazia.

## 1.4.1 — 2026-10-21
Commits: 9c028e1
O comando do SNEP vira dois: a senha e o pacote.

### Correções
- **Migração do SNEP**: colado inteiro, o comando que gera o pacote tinha o pedido de senha lendo a linha seguinte do próprio comando como se fosse a senha. Agora são dois comandos, cada um com o seu botão de copiar: (a) a senha do MySQL e (b) o pacote. O (b) roda numa subshell (não muda a pasta de quem colou) e, sem o (a), só avisa.

## 1.4.0 — 2026-10-21
Commits: 2d0dc7d
A migração do SNEP traz os agentes, os horários das regras e os áudios, a partir de um pacote gerado por um comando no próprio SNEP.

### Novidades
- **Migração do SNEP — pacote**: a tela mostra um comando para colar no SNEP que gera `snep-telium.tar.gz` com o banco sem CDR, os áudios usados, as músicas de espera e os `.conf`. Enviado o pacote, os áudios são convertidos e cadastrados em Áudios e ligados às URAs, anúncios e filas, e os contextos próprios que as regras usam aparecem com o texto, prontos para copiar.
- **Migração do SNEP — agentes**: os agentes do SNEP (login por código) viram agentes do Call Center, com a mesma matrícula e a senha como PIN; com fila fixa no SNEP, as filas viram filas de call center.
- **Migração do SNEP — horários**: duas regras para o mesmo número em horários diferentes viram condição horária com grupo de horário.
- **Migração do SNEP — desvios**: Reescrever + DiscarTronco numa regra de entrada vira destino "número externo"; ExecuteApp(Hangup) vira desligar; o número interno que levava a uma fila vira o número da fila.
### Mudanças
- **Migração do SNEP**: as regras de saída só de um grupo de ramais, os atalhos internos e os códigos de call center do SNEP (contextos ACD) aparecem como "não migra", com o motivo e o que fazer. Um padrão já usado por outra rota fica só na primeira. Rota com fixo e celular juntos fica com a classe local.
### Correções
- **Migração do SNEP**: a importação conferia só a permissão da tela de migração; agora exige poder criar em cada módulo que ela usa (o cadastro chamado por ela não passa pela checagem de cada tela).
- **Migração do SNEP**: arquivo acima do limite de envio dizia só "envie o arquivo"; agora diz o tamanho e o limite.
- **API**: grupos de horário, filas e agentes gravam numa transação que aceita estar dentro de outra (SAVEPOINT), como a prévia da migração precisa.

## 1.3.1 — 2026-10-21
Commits: ff3d4ee
A instalação parou de avisar que o AMI não autenticou quando ele autenticou.

### Correções
- **Instalação**: logo depois de "Authentication accepted", o playbook mostrava o diagnóstico do manager e "O AMI não autenticou". A segunda tentativa de login, pulada porque a primeira tinha passado, gravava o resultado por cima do primeiro. Agora ela grava num nome próprio, e só a falha de verdade aciona o diagnóstico e o aviso.

## 1.3.0 — 2026-10-21
Commits: b6d1d24
Migração do SNEP 3: troncos, ramais, filas e as regras de entrada e de saída, a partir do dump do banco do SNEP.

### Novidades
- **Telium › Migração do SNEP**: envie o dump do banco `snep` (`.sql`, `.sql.gz` ou `.sql.bz2`) e confira a prévia item por item antes de importar. Cada item passa pelo mesmo cadastro das telas, com as mesmas validações e auditoria; a prévia roda tudo e desfaz.
- Troncos SIP, ramais (com a senha SIP do SNEP, quando ela é forte), filas com os membros, regras de saída (padrões, prefixos e tronco) e regras de entrada, com as URAs, anúncios e contextos que elas usam.
- Os áudios que o SNEP usava são ligados quando já estão em Áudios com o mesmo nome; os que faltam aparecem numa lista. Os contextos do dialplan do SNEP viram destinos personalizados, com o aviso de copiá-los.
- O que já existe no Telium é aproveitado e não muda: rodar de novo só cria o que falta.
### Mudanças
- **PHP**: a extensão `bz2` passa a ser instalada (o dump do SNEP costuma vir em `.bz2`).
- **API**: as transações aninhadas viram SAVEPOINT (`Bd::transacao`), para a prévia da migração poder rodar o cadastro das telas e desfazer tudo.

## 1.2.8 — 2026-10-21
Commits: d88c645
A senha padrão do admin sai do repositório.

### Segurança
- **Senha do admin fora do Git**: a senha inicial do admin do console (e do Asternic) não fica mais no `group_vars/all/main.yml`. A instalação a pergunta (duas vezes, sem eco) e a guarda em `/etc/telium/credenciais/senha_admin_console` (só root); ou ela vem de `-e senha_padrao_console=...`, do `servidor.yml` ou do `vault.yml`. Só é pedida quando ainda vai ser usada: as atualizações de centrais já instaladas não perguntam nada.
- **Seed sem o hash da senha**: a conta `admin` nasce com um hash que não confere com senha nenhuma, até o playbook definir a senha. O hash da senha de fábrica saiu do `03-seed.sql`. Centrais já instaladas não mudam.
### Mudanças
- **Bancada de desenvolvimento**: o admin da bancada entra com `Bancada@2026`.

## 1.2.7 — 2026-10-21
Commits: c7b1693
O Asternic e o ionCube são instalados a partir dos arquivos do projeto.

### Mudanças
- **Asternic**: o pacote do Asternic e o carregador ionCube vêm de `infra/ansible/roles/asternic/files/`, sem depender dos sites dos fornecedores. Só sem o arquivo no projeto o playbook baixa da internet, e avisa no resumo. O repositório tem de ser privado.

## 1.2.6 — 2026-10-21
Commits: 2906b1a
Revisão da instalação do Asternic: tudo pelo playbook, e o admin do Asternic com a senha do admin do console.

### Mudanças
- **Asternic**: o usuário `admin` do Asternic entra com a mesma senha do admin do console (a senha padrão do console), gravada uma vez por central. A senha aleatória das versões anteriores (`/etc/telium/credenciais/asternic_admin_senha`) sai.
### Novidades
- **Asternic**: relatórios agendados e alertas por e-mail funcionam sem passo manual: o cron do `sched_check.php` é instalado, e a tela responde em `http://127.0.0.1/stats/` só para a própria máquina, que é por onde o agendador pede o relatório.
- **Asternic**: rotação semanal dos logs em `/var/log/asternic`.
### Correções
- **Asternic**: o playbook confere, depois do `update_db.php`, se as tabelas estão na versão instalada, e para com erro se não estiverem.
- **Asternic**: o usuário dos relatórios agendados (`restUser`) tem a senha alinhada ao `config.php` em toda execução.

## 1.2.5 — 2026-10-20
Commits: d337da0
O tempo real do Asternic conecta no AMI da central, e o serviço asterniclog tem o script que ele roda.

### Correções
- **Asternic**: o tempo real dizia "Could not connect to the Asterisk Manager Interface". Ele usa a tabela de servidores AMI do próprio Asternic (Setup › AMI Servers), que vinha com `localhost/admin/amp111`, e não o `config.php`. A linha da central passa a usar o usuário `asternic` do `manager.conf`, em `127.0.0.1`.
- **Asternic**: o serviço `asterniclog` reiniciava sem parar com "Unable to locate executable": a cópia dos scripts para `/usr/local/parselog` não deixava o `asterniclog` lá. A cópia agora é como a do instalador do fornecedor e confere o arquivo.

## 1.2.4 — 2026-10-20
Commits: 7f582e9
O login do Asternic aceita a senha, e os agentes e filas de antes da instalação aparecem nos relatórios.

### Correções
- **Asternic**: o login respondia "Bad username or password" com a senha certa. As tabelas não estavam na versão instalada (faltavam colunas de `users`, como `auth_type` e `active`), porque o `update_db.php` do fornecedor nunca rodava: na linha de comando o ionCube não era a primeira extensão do PHP ("The Loader must appear as the first entry"), e o erro era ignorado. Agora ele roda com as configurações do PHP-FPM, e um erro nele para o playbook.
### Novidades
- **Asternic**: na primeira execução, o histórico do `queue_log` que a central já tinha no banco (anterior ao arquivo que o Asternic lê) é carregado no `qstats`. Sem isso, o Asternic só conhecia os agentes e as filas com evento depois da instalação.

## 1.2.3 — 2026-10-20
Commits: 28d95d4
O login do Asternic, agora de fato.

### Correções
- **Asternic**: a pasta de sessão da 1.2.2 ficava dentro de `/var/lib/php`, que o usuário do Asternic não atravessa — a sessão continuava sem ser gravada. Ela passa para `/var/lib/asternic/sessoes`.
- **Asternic**: instala a extensão LDAP do PHP, que ele procura ao fazer login.

## 1.2.2 — 2026-10-20
Commits: fc27f77
O login do Asternic volta a funcionar.

### Correções
- **Asternic**: com a senha certa, a tela voltava para o login. O PHP do Asternic roda com usuário próprio e não conseguia gravar a sessão na pasta do console; agora ele tem pasta de sessão (`/var/lib/asternic/sessoes`) e de log (`/var/log/asternic/php.log`) próprias.
- **Asternic**: a senha inicial do admin é gravada depois da atualização das tabelas do fornecedor.
### Segurança
- **Asternic**: os usuários de exemplo `user` e `supervisor`, que vêm com a senha igual ao nome, ganham uma senha que ninguém conhece.

## 1.2.1 — 2026-10-20
Commits: 4202af9
A instalação do Asternic não para mais ao ligar o serviço asterniclog.

### Correções
- **Asternic**: a instalação parava em "Ligar o asterniclog" (`Unable to start service asterniclog`). O serviço sai com erro se o `/var/log/asterisk/queue_log` ainda não existe, e o Asterisk só passava a gravá-lo no fim do playbook. Agora o papel faz o Asterisk reler o `logger.conf` e cria o arquivo antes de ligar o serviço.

## 1.2.0 — 2026-10-20
Commits: 88a42fa
O Asternic Call Center Stats PRO entra no console, em Call Center › Stats, para as centrais com licença.

### Novidades
- **Call Center › Stats**: o Asternic Call Center Stats PRO dentro do console, em `/stats/`. Opcional, por central: `instalar_asternic: true` no `servidor.yml`, com a licença comprada para aquele servidor. O pacote oficial é baixado na instalação; nada do Asternic vai para o repositório.
- **Asternic integrado ao nginx**: PHP-FPM próprio com usuário próprio, banco `qstats` com senha aleatória e sem SUPER, senha inicial aleatória do admin, usuário do AMI que só lê e mexe em fila e agente, e o `/stats/` aberto só para quem está logado no console com a permissão.
- **Backup**: o banco do Asternic entra no backup do console (`asternic.sql`) e volta na restauração.
### Mudanças
- **Asterisk**: com o Asternic instalado, o `queue_log` é gravado também em `/var/log/asterisk/queue_log`; os relatórios do Telium continuam lendo o do banco.
### Segurança
- **Asternic**: quem tinha rodado o `make install` do fornecedor perde o SUPER do usuário `qstatsUser`, que ganha senha nova.

## 1.1.4 — 2026-10-13
Commits: f200da3
O bloco do Telium no backup da empresa só acrescenta o que falta, sem repetir linha.

### Mudanças
- **Backup da empresa**: o bloco em `/root/.backup_telium` traz só os diretórios que o arquivo ainda não leva. Uma linha que já existe fora do bloco, ou um diretório coberto por outro acima dela (`I /var/lib` cobre `/var/lib/asterisk`), não se repete; com tudo já no arquivo, o bloco some.
- **Backup da empresa**: `/Telium`, a pasta da empresa nos servidores, entra na lista do essencial.

## 1.1.3 — 2026-10-13
Commits: 3b87084
O backup da empresa leva tudo o que a central precisa para voltar só copiando os arquivos, e o dump do banco passa a existir sempre.

### Mudanças
- **Backup da empresa**: o bloco em `/root/.backup_telium` passa a levar `/etc`, `/home`, `/root`, `/opt`, `/usr/local/sbin`, `/var/Backup`, `/var/spool/asterisk` e `/var/lib/asterisk`. O `/var/lib/mysql` cru fica de fora de propósito: o banco vai no dump.
- **Backup**: sem nenhuma rotina automática ligada, a atualização liga a rotina diária de fábrica, que gera o dump do banco em `/var/Backup/pabx-telium`. Quem já tem uma rotina fica com ela; `backup_ligar_rotina: false` no `servidor.yml` desliga.

## 1.1.2 — 2026-10-13
Commits: 6e920ee
O essencial da central entra sozinho na lista do backup da empresa.

### Novidades
- **Backup da empresa**: o playbook mantém em `/root/.backup_telium` um bloco marcado "Telium PABX" com o essencial da central (dump do banco em `/var/Backup/pabx-telium`, `/etc/telium`, `/etc/asterisk`, `/etc/ssl/telium`, gravações e recados em `/var/spool/asterisk`, áudios, base interna do Asterisk e o `servidor.yml`), sem tocar nas outras linhas. Sem o arquivo, não faz nada; outro caminho vai em `backup_empresa_lista` no `servidor.yml`.

## 1.1.1 — 2026-10-13
Commits: 749e0a8, 63d1f8d
Os backups da central passam para /var/Backup, junto do backup da empresa, e o playbook volta a rodar no Ansible novo.

### Mudanças
- **Backup**: os arquivos ficam em `/var/Backup/pabx-telium` (B maiúsculo), dentro do diretório que o backup da empresa já leva. A atualização move os backups que estavam em `/var/backup/pabx-telium` e apaga a pasta antiga se ela ficar vazia; o histórico do console continua apontando para eles.
- **Backup**: se o `/var/Backup` for fechado só para o root, o usuário da API ganha apenas a permissão de atravessá-lo (ACL), sem listar nem ler o que mais houver ali.
### Correções
- **Instalação e atualização**: o playbook parava no resumo inicial no ansible-core 2.19 ou mais novo (`'NoneType' object has no attribute 'group'`), antes de mexer em qualquer coisa.

## 1.1.0 — 2026-10-13
Commits: e1ae8c7
Primeira versão numerada na entrega: backup e restauração completos, prazo para as gravações, fax por e-mail, webhooks, alertas por e-mail, o histórico de versões no console e mais de trinta correções de telefonia e de tela.

### Novidades
- **Versões e Novidades** (menu Telium): a versão e o commit desta central e o que mudou em cada versão. O número aparece com o commit também no rodapé do menu e no Suporte.
- **Gravação de Chamadas**: prazo de guarda em dias; uma vez por dia, as gravações mais antigas que o prazo saem do disco. O padrão é zero, que guarda para sempre.
- **Fax**: o fax recebido vai por e-mail em PDF; ECM, cabeçalho e velocidades da tela passam a valer; com a recepção ligada, os troncos aceitam T.38.
- **Webhooks** (Integrações / API): avisos `chamada.tocando`, `chamada.atendida` e `chamada.encerrada` em JSON para o CRM, assinados com HMAC, com botão de teste e o resultado do último envio.
- **Notificações e E-mail**: alertas de backup com falha ou atrasado, certificado perto de vencer e disco quase cheio, para os e-mails escolhidos; cada aviso sai uma vez por dia.
- **CDR**: exportação em CSV com o filtro da tela.
- **Grupos de Toque**: "Um de cada vez, na ordem da lista" e destino para quando ninguém atende.
- **Despertador**: novas tentativas quando ninguém atende (configuráveis) e o resultado de cada despertar na lista.
- **Produtividade de Agentes**: período de hoje, 7, 30 ou 90 dias, e as colunas Não atendeu, Em pausa e Satisfação.
- **Provisionamento**: os últimos pedidos dos telefones com o motivo de cada recusa.
- **Arquivos personalizados**: todo arquivo do Asterisk gerado pelo console tem o seu `_custom.conf`, que o console nunca sobrescreve; o AMI e o ARI ganham `manager_custom.conf` e `ari_custom.conf`, fora da pasta editável pelo navegador.
- **Terminal**: `telium desligar-2fa <usuario>` para o administrador que perdeu o celular, e `telium alertas`.
### Correções
- **Backup**: Baixar, Enviar ao destino remoto e Excluir voltam a funcionar; a pasta passa a ser lida pela API e escrita só pelo root.
- **Backup**: passa a levar os recados do correio de voz e das caixas postais, os fax, a base interna do Asterisk e o certificado do console.
- **Restauração**: volta tudo o que está no arquivo, preserva as senhas deste servidor e o histórico de backups, para e religa o call center e refaz a configuração a partir do banco restaurado.
- **Backup**: a retenção conta por rotina; um backup manual não apaga mais os arquivos de uma rotina.
- **Grupos de Toque**: quem está em não perturbe ou com desvio de todas as chamadas não toca mais.
- **Filas**: o failover escolhe URA, anúncio e destino personalizado pelo nome, no mesmo seletor das outras telas; antes pedia um número que era o id interno.
- **Despertador**: o anúncio escolhido volta a tocar, e o `*68` só aceita hora válida, com os avisos certos.
- **Correio de voz**: "apagar depois de mandar" não apaga mais o recado de quem não tem e-mail.
- **Siga-me**: ligado pelo `*21`, usa o modo do cadastro (junto ou depois), e não sempre "junto".
- **URA**: a discagem direta alcança ramais de qualquer tamanho.
- **Tarifação**: "Recalcular o mês" refaz o mês inteiro com os preços de agora.
- **Lista Negra**: o contador de bloqueios e a data do último passam a contar.
- **Conjuntos de PIN**: a coluna "Quantos PINs" mostra a quantidade certa.
- **DIDs**: mostra o nome do destino, e o aviso de duplicado compara só os dígitos.
- **Visão Geral**: "Entrada não atendida" conta só as chamadas de fora; antes misturava chamadas entre ramais.
- **Ocupação de Troncos**: congestionada, não atendida e falha em colunas separadas; a conta da linha fecha.
- **Música em espera** e **Dados da Empresa** acendem a faixa "Alterações ainda não aplicadas".
- **Perfis e Permissões**: salvar a matriz mantém os curingas de grupo (`rel.*`), e os módulos de versões futuras chegam aos perfis.
- **Conferências**: tela e API aceitam o mesmo número de sala e PIN, e a sala que espera o administrador exige PIN de administrador.
- **Códigos de recurso**: a API confere o formato, e religar um código de fábrica (`*8`, `*55`, `*77`) não é mais recusado.
- **TURN**: o endereço público gravado no console chega ao coturn.
- **Instalação**: o softphone do navegador funciona antes do primeiro "Aplicar"; o firewall só abre as portas do Janus com o Janus instalado.
- **Click to call**: os pedidos de uma chave excluída não contam mais para o bloqueio por IP; a tela lista todos os códigos de resposta.
- **Telas**: títulos iguais aos do menu, rótulos certos em Grupos de Horário e Anúncios, filtro completo de ações na Auditoria, e os códigos certos em Meu Siga-me (`*21`/`*22`) e na megafonia (`*80`).
### Mudanças
- **Lista Negra e Allowlist pelo telefone** (`*30` a `*39`): só o ramal de um usuário com a tela da lista correspondente pode usar os códigos.
- **`*45`**: só entra na fila quem está na lista de agentes dela como "Entra por código".
- **`limpar_banco`** apaga também call center, caixas postais, chaves de click to call e os demais cadastros novos.
- **Fax**: com a recepção desligada em Configurações › Fax, o código de receber fax avisa que o serviço não está disponível.
### Segurança
- **Conta admin**: não pode ser excluída, renomeada, desativada nem tirada do perfil Administrador — nem pela tela, nem pela API. É a conta de recuperação da central; senha, nome e e-mail continuam editáveis.
- **Perfil Administrador**: as permissões dele ficam travadas na matriz, com acesso a tudo.
- **Fax**: o número de quem envia entra no nome do arquivo só com dígitos.
- **AMI e ARI**: usuários extras ficam em arquivos fora da pasta que o console edita.

## 1.0.7 — 2026-10-08
Commits: 13cc43f, 5f063cc, ae4f854
Call center sem conta do console e com um código por pausa, caixas postais sem ramal, rotas de saída com vários padrões, click to call para outros sistemas e a wiki completa.

### Novidades
- **Call center**: o agente tem nome próprio e a conta do console passa a ser opcional; quem só atende pelo telefone ou softphone entra com matrícula e PIN.
- **Call center**: cada motivo de pausa tem o seu código no telefone (por exemplo, Almoço `*14`).
- **Monitor ao Vivo**: filtro de filas.
- **Caixas postais**: caixas sem ramal, com destino próprio e código para ouvir os recados.
- **Rotas de saída**: uma rota aceita vários padrões, cada um com o seu tirar e pôr prefixo.
- **Rotas de entrada** e demais telas mostram o nome do destino, e não só o tipo e o número.
- **Auditoria**: filtros e páginas de 30 registros.
- **Telefone** (navegador): visor dos dígitos DTMF digitados.
- **Integrações / API**: click to call para CRM e ERP em `/api/click2call`; a central toca o ramal e, atendido, disca o cliente pelo contexto do próprio ramal.
- **Integrações / API**: chaves por sistema, presas a ramais e IPs, com atendimento automático opcional, teste pela tela e registro dos pedidos; o "discar" do Meu Ramal passa a usar o mesmo caminho.
- **Documentação**: wiki completa em 24 páginas (instalação, atualização, rede e portas, backup, comandos do servidor, logs, solução de problemas, segurança, manual de cada tela, códigos de recurso, arquitetura e referência da API).
### Mudanças
- **Call center**: os códigos do agente passam para a faixa `*1x` (`*11` entrar, `*12` sair, `*13` voltar), fora do `*4x` de eco, filas e diretório; deixa de existir o `*42` seguido do motivo.
- **Códigos de recurso**: `*45` e `*46` não mexem mais em fila de call center.
- **Códigos de recurso**, pausas e caixas postais: a central recusa código igual a outro ou que seja o começo de outro.
- **Anúncios**: anúncio sem destino depois de tocar desliga a chamada, e a tela passa a dizer isso.

## 1.0.6 — 2026-10-05
Commits: dd7ed8d, 8b781b5, 9efae47, b2d36c0, bb94ae4, 923a02e, ff8bb9a, a043bd4, 2044fa5, 17baa86
A central passa a falar português, as rotas ficam mais fáceis de escrever e testar, cada condição horária ganha código com tecla BLF e a senha do admin deixa de voltar à de fábrica.

### Novidades
- **Rotas**: o padrão aceita o jeito que a pessoa escreve ("x.", "0xx xxxx-xxxx", minúsculas, espaços); a central acerta o formato ao gravar e ao gerar, e rotas antigas escritas assim passam a funcionar.
- **Rotas de entrada**: "x." no número chamado vale como qualquer número, e "x." na origem como qualquer origem.
- **Rotas de saída**: modelos prontos (tudo, emergência, fixo, celular, DDD, internacional, 0800) e teste do padrão no formulário, mostrando o que a operadora recebe.
- **Rotas**: testadores "Para onde vai um número?" (saída) e "Uma chamada para o número X, vinda de Y, vai para onde?" (entrada), com a mesma precedência da central.
- **Condições horárias**: cada condição ganha o seu código no telefone (`*271`, `*272`...); discar força a condição e discar de novo volta a seguir o relógio.
- **Condições horárias**: a ação do código é escolhida na condição (fechar, abrir ou inverter), com PIN opcional; o card mostra o código.
- **Condições horárias**: tecla BLF acesa enquanto a condição está forçada, pelo telefone ou pelo console.
- **Idioma**: os avisos do sistema (correio de voz, conferência, fila, números, "ativado", "número inválido") passam a ser em português do Brasil, com o pacote de áudios dentro do próprio projeto.
- **Servidor**: rotação diária dos logs do Asterisk (inclusive o usado pelo fail2ban) e semanal dos logs da API e do e-mail.
- **Firewall**: abre a faixa usada pelo fax T.38.
### Correções
- **Idioma**: números e datas em português saem completos (1250, 100 e a hora com minutos no correio de voz).
- **Condições horárias**: o código alternava entre três estados com o mesmo aviso; agora liga e desliga com avisos distintos.
- **Atualização**: deixava de religar o siga-me que o usuário tinha desligado.
- **Atualização**: o seed duplicava as faixas de horário e o horário Comercial de fábrica passava a valer também no fim de semana; a migração limpa as cópias e corrige a faixa.
- **Atualização**: o nome da empresa definido no console não volta mais ao de fábrica.
- **Pesquisa de satisfação**: nota fora da escala não se perde mais, e a migração não aborta a atualização por causa dela.
- **Pesquisa de satisfação**: a tela é dada aos perfis uma vez só (retirá-la depois fica valendo); respostas antigas não duplicam o atendente; escala antiga com mínimo igual ao máximo não dá mais erro.
- **Instalação**: o nome da central passado por `-e` também vira minúsculo.
- **Conectividade > WebRTC**: o diagnóstico do TURN consulta o DNS, e não o /etc/hosts da central, e deixa de acusar "endereço de rede interna" à toa.
### Mudanças
- **Idioma**: o idioma padrão da central passa a ser pt_BR.
- **Repositório**: o projeto passa para o GitLab da Telium; a documentação explica como apontar uma central já instalada para o repositório novo.
### Segurança
- **Usuários**: a senha do admin não volta mais à de fábrica a cada atualização; é definida uma única vez, a troca feita pelo console é preservada e a recuperação passa a ser pelo terminal.
- **Login**: o bloqueio por senha errada vale só para a origem que errou, e bloqueio vencido zera a contagem; antes, errar a senha do admin de tempos em tempos deixava o dono de fora para sempre.

## 1.0.5 — 2026-09-30
Commits: f64ed82, 4c13681, 798b546, 496f4de, 006dd43, d7e4cfd, fcf322a, 5e92474, 7b8a9ba, 7569212, 3f4aa70, 201b052, 57a369f, d22ee7e, 0214351, a2a26e3, 43e7d6f, 53b3c9f, 5f390c2, 2001660, 2114426, 907849f
Chega o módulo de call center, a pesquisa de satisfação vira módulo próprio, ramais podem ser importados por planilha, o Linphone se configura por QR Code e duas revisões gerais corrigem falhas de segurança e de telefonia antes da produção.

### Novidades
- **Call center**: o agente é uma pessoa com matrícula e PIN que entra no ramal em que está, pelo console ou pelo telefone (`*40` entrar, `*42` pausa com motivo, `*49` voltar, `*44` sair), com áudios do call center em português.
- **Call center**: serviço de tempo real que registra cada atendimento, põe em pós-atendimento quem precisa tabular e disca os retornos pedidos na espera (agente primeiro, cliente depois); ampliação de habilidade abre os níveis conforme a espera cresce.
- **Meu Atendimento**: painel do agente para entrar, pausar com motivo e limite, ver quem está na linha (com nome da agenda), tabular e ver os números do dia.
- **Monitor ao Vivo**: painel do supervisor com quem espera, SLA do dia e cada agente, e as ações de pausar, tirar da pausa, tirar do atendimento, escutar, sussurrar e intervir.
- **Call center**: cadastro de agentes, motivos de pausa, tabulações, lista de retornos e troca dos códigos do telefone; a fila ganha a aba "Call center", e a gaveta de agentes da fila de call center lista as pessoas com nível.
- **Perfis**: novo perfil "Call Center", que só alcança o painel do agente.
- **Relatórios de fila e de agente**: passam a sair do registro das filas, com SLA dentro da meta, TME, TMA, abandono, tempo logado e em pausa, ocupação e nota da pesquisa, e exportação em CSV.
- **Pesquisa de Satisfação**: módulo próprio com mensagens, escala de 0 a 10 em qualquer sentido, ligação à fila, à URA e à rota de entrada, e aba de resultados com índice, por fila, por atendente, dia a dia e CSV.
- **Telefone**: o softphone do navegador vira uma tela própria, com discador, transferências, volume e escolha de microfone e alto-falante, não perturbe, contatos e chamadas da sessão; chamada entrando em outra tela aparece como aviso no topo.
- **Ramais**: modelo CSV, exportação e importação em lote, com prévia, senha forte gerada para ramal novo e aviso quando passa do número de ramais contratados.
- **Provisionamento**: QR Code para o Linphone do celular, com endereço de uso único válido por dez minutos; funciona também em central sem nome próprio.
- **Firewall**: lista "nunca bloquear" (IP ou faixa), que solta na hora quem estava banido.
- **Usuários**: o administrador desbloqueia conta travada por senha errada e desliga a verificação em dois passos de quem perdeu o celular.
- **Servidor**: os logs da central também vão para o syslog central do grupo VoIP (desligável no servidor.yml).
- **Instalação**: pergunta o nome do servidor enquanto ele for o de fábrica, e o nome novo refaz o certificado de fábrica.
- **Documentação**: pasta documentacao com instalação, atualização, backup e problemas comuns, e caderno de aceite para decidir se a central sobe.
### Correções
- **Áudios**: a central podia ser instalada muda (URA calada, correio de voz desligando); agora a instalação confere os áudios e, faltando, baixa o pacote oficial, com opção para o pacote em português.
- **Usuários**: não é mais possível deixar o console sem administrador (excluir, desativar ou rebaixar a última conta que administra); a edição confere login, e-mail e perfil, e um ramal não pode ficar vinculado a duas contas.
- **Certificados**: "Aplicar nos serviços" não enviava nada ao servidor; certificado no Janus não aparece mais como falha quando o Janus nem está instalado; SIP TLS não diz mais "aplicado" sem estar.
- **Chamadas entrantes**: mostravam o número do tronco no lugar de quem ligou (CDR, call center e pesquisa); o tronco passa a confiar na identificação da operadora.
- **Telefonia**: pesquisa de satisfação que nunca tocava, áudio enviado pelo console que não tocava, estacionamento, `*45` que não deslogava, padrão `X.`, DID com "+55", megafonia sem atendimento automático, não perturbe e chamada em espera apagados no "aplicar" e regra de gravação externa ignorada.
- **Relatórios**: o CID de saída trocava a origem no CDR e chamadas sumiam dos relatórios; relatório por ramal com data dava erro.
- **Call center**: correções de retornos (repetidos, presos ou religados com o cliente na linha), pausas, tabulação e relatório de quem estava logado desde a véspera.
- **Console**: botões que travavam sem mensagem, painel e wallboard que não liam o Asterisk, resultado do "Aplicar configurações" que sumia em um segundo, "hoje" calculado em UTC e falha do aplicar mostrada como "Erro 500".
- **Console**: depois de uma atualização, o navegador seguia com a tela antiga por até 7 dias; agora HTML, JS e CSS são conferidos a cada acesso.
- **Instalação e atualização**: não dependem mais de mariadb.org ou nginx.org responderem; a primeira passada do playbook não deixa mais o Asterisk sem dialplan; reiniciar o MariaDB não derruba mais o Asterisk.
### Mudanças
- **Telefone**: o softphone deixa de ser um botão flutuante em todas as telas e só aparece para quem tem o módulo "Telefone"; sem ele, a senha SIP não vai ao navegador e o usuário disca pelo telefone de mesa.
- **Instalação**: os ajustes de cada servidor passam a ficar em group_vars/all/servidor.yml, fora do Git, para o git pull não conflitar.
- **Ramais**: a restrição de rede passa a ser por ramal, e não global (a global barrava a operadora e o WebRTC).
- **Códigos de recurso**: `*555` passa a ser só para supervisor; grupo de toque só aceita ramais; DISA não disca internacional e é desativada se a senha for fraca.
### Segurança
- **Provisionamento**: o endereço do aparelho podia ser falsificado por cabeçalho para levar a senha SIP de fora da rede interna.
- **Cadastros**: número de fila, grupo ou sala com caracteres especiais, ou nomes com `${SHELL()}`, podiam executar comando no servidor; a API recusa esses textos e a função de shell do Asterisk foi desligada.
- **Siga-me, desvio e transferência às cegas**: passam a respeitar as permissões de discagem do ramal dono, fechando um caminho de fraude.
- **Console**: XSS na busca Ctrl+K e nos títulos de gavetas e modais; CSV com fórmula no nome de agente ou fila.
- **Escuta**: escutar o ramal 100 podia entrar na chamada do 1001.
- **Call center**: o painel ao vivo confere a sessão a cada 15 s; o PIN do telefone ganha limite de tentativas.
- **Perfis**: quem tem "Usuários" ou "Permissões" não consegue dar a outro mais do que tem.
- **Ramais**: a senha SIP não aparece mais no console do Asterisk pela web, não pode ser apagada pela API, e listagens por referência não entregam mais siga-me, e-mail e DID.

## 1.0.4 — 2026-09-25
Commits: 14b2a5b, ac4d0b0, ff7bec8, f17c7cb, 91dcdd4, 13d1266
Tarifação e provisionamento passam a funcionar, a rota de entrada ganha normalização e regras por origem, o telefone do navegador nasce desligado e o aplicador de certificados e o e-mail são corrigidos.

### Novidades
- **Tarifação**: o custo de cada chamada passa a ser calculado pela classe da rota (local, celular, DDD, DDI), em frações como a operadora cobra; as tarifas nascem zeradas e a tela explica por que um total sai zero.
- **Provisionamento**: telefones Grandstream, Yealink e Fanvil buscam a própria configuração na central, protegida por segredo, MAC cadastrado e faixa de rede.
- **Conectividade > WebRTC**: cada chamada do navegador deixa um resumo dizendo se teve áudio ("ouviu, mas não falou", "a mídia não fechou"...), e a tela abre com uma linha só dizendo se o softphone vai funcionar.
- **Troncos**: cada tronco normaliza o número que chega (tira um prefixo e fica com os últimos N dígitos) antes de procurar a rota.
- **Rotas de entrada**: as regras por número de origem (CallerID) passam a valer, com origem exata vencendo padrão.
- **Rotas de entrada**: prefixo no nome de quem liga, toque distintivo, música de espera própria, sinalizar chamando, atender antes, pausa antes de encaminhar e recusar número oculto com ocupado, sem atender.
- **CDR**: passa a guardar o DID pelo qual a chamada entrou.
### Correções
- **Certificados**: aplicar não fazia nada e o console dizia que tinha feito; voltar ao certificado de fábrica nunca funcionava; agora o console espera o resultado e mostra o estado real de cada serviço.
- **E-mail**: relay sem autenticação não funcionava, e o recado do correio de voz saía com um remetente que Gmail e Microsoft 365 recusam; o teste do console agora confere a configuração publicada.
- **Rotas de entrada**: tronco que não manda número e operadora que entrega em formato "+55..." recebiam 404 e a chamada morria antes de tocar.
- **Discador**: avisa quando o telefone de quem clicou não está registrado, em vez de dizer que ele vai tocar.
### Mudanças
- **Telefone do navegador**: passa a nascer desligado; o discador do console liga pelo telefone de mesa (a central chama o ramal e, atendido, completa a chamada). Ligar de novo é um clique em Conectividade > WebRTC.
### Segurança
- **Telefone do navegador**: com a função desligada, a senha SIP deixa de sair do servidor.

## 1.0.3 — 2026-09-17
Commits: 9064969, 893ec84, 93970cd, 26ed0da, 3297c5a, c99e06a, 863138d, b463b6b, 683f014, 1672ab3, 3efcbf7, a5ad2ff, 8968f74, 5512750, 1176388, d3afa52, 30d50e2, a1e246e, bad0453, 520c2b0
Entrega do POC: o WebRTC passa a ter áudio, o g729 sai do padrão dos troncos, o correio de voz deixa de falhar por permissão e entram backup remoto, desbanir no firewall e novos dias no grupo de horário.

### Novidades
- **Conectividade > WebRTC**: botão "Testar agora", que diz no navegador de quem olha se há caminho de áudio (rede local, STUN, TURN).
- **Conectividade > WebRTC**: avisa quando um softphone comum está registrado num ramal marcado como WebRTC, e sugere dois ramais com o mesmo número pelo siga-me no modo "junto".
- **Conectividade > Rede**: avisa quando a central está atrás de NAT sem endereço público configurado, inclusive em CGNAT.
- **Telefone do navegador**: toque de verdade na chamada entrante, transferência com consulta e o registro cai quando a aba fecha.
- **Backup**: baixar pelo console e enviar automaticamente por FTP, FTPS ou SFTP depois de cada backup.
- **Firewall**: a tela mostra quem está banido e permite desbanir.
- **Grupos de horário**: opções "todos os dias", "segunda a sexta", "sábado e domingo" e dias avulsos.
- **Estatísticas do Sistema**: uso de CPU calculado como no top, com atualização a cada 5 s.
- **Gravações, recados e anúncios**: tocam em fluxo e permitem arrastar a barra do player (no Safari passam a tocar).
- **Empresa**: campos Service ID e Client ID.
### Correções
- **WebRTC**: a chamada conectava sem áudio e o ramal do navegador não recebia chamadas, por duas falhas de configuração da central; o áudio da chamada recebida também não era ligado no navegador.
- **WebRTC**: o STUN do navegador volta a ser um servidor público; o endereço público passa a ser anunciado nos candidatos ICE atrás de NAT; o console corrige sozinho o endereço do TURN quando o endereço deixado pelo instalador termina em .local.
- **Troncos**: o padrão "alaw,ulaw,g729" derrubava a chamada já atendida pela operadora quando ela preferia g729; a migração tira o g729 de troncos e ramais, e o aplicar avisa sobre codec sem tradução.
- **Correio de voz**: a chamada caía ao deixar recado por falta de permissão nas pastas; o mesmo valia para ditado e fax.
- **Áudios**: três avisos pedidos pelo dialplan não existiam (inclusive o de chamada bloqueada, que saía em silêncio); todos os áudios usados estão agora no pacote básico.
- **Música em espera**: a fila podia tocar silêncio quando o pacote de música não entrava na compilação; agora é conferido e baixado.
- **Grupos de horário**: lista de dias mal separada fazia a condição não valer em dia nenhum.
- **Certificados**: o certificado enviado pelo console agora chega também ao TURN, e "aplicar" confere se o programa realmente rodou.
- **Diagnóstico**: o Janus, que não é instalado por padrão, deixa de aparecer como falha.
### Mudanças
- **Idioma**: a central fala inglês por decisão (o Asterisk não publica áudios em português); antes a instalação fingia instalar português. Saudação, URA e música em espera continuam sendo as gravações do cliente.
- **Ramais**: ramal marcado como WebRTC passa a aceitar um único registro.
- **Firewall**: a faixa de RTP passa para 10000 a 20000.
### Segurança
- **Perfis**: a auditoria passa a ter permissão própria; supervisor e auditor não leem mais a trilha de quem mexeu na central.
- **Perfis**: o Operador deixa de acessar as telas administrativas de correio de voz e siga-me e fica só com o Portal do Usuário.

## 1.0.2 — 2026-09-14
Commits: 58f6991, b295322, dcc24d5, ad88cbc, 85e2bd5, b261d73, 8d4ff14, 70ba815, 5c1729d, d00acf8, 22e17f1, e903025, 946edd9, d1ca730, 34d1c78, 6575717, 5255121, 12f9bcd, d8bbf1f, ad74786, 3a65d5f, ee35cdf, 912b5eb, 9848f70, ad5cc40, b4bde5e, 9f9cbe7, bc08ec7, a6a4a46, 1dbb671, 5e7f61c, 4fce6ac, bc699f6, a7349ee, 4a60328
Todos os itens do menu ganham tela: Portal do Usuário, verificação em dois passos, TURN próprio, megafonia, DISA, relatórios e diagnósticos, e o botão "aplicar" passa a funcionar de fato.

### Novidades
- **Portal do Usuário**: Meu Ramal, Meu Siga-me, Minhas Chamadas, Meu Correio de Voz e Meu Perfil e Segurança; o que o usuário muda vale na hora.
- **Verificação em dois passos**: ativação por QR Code, desativação pedindo a senha e campo do código na tela de entrada.
- **TURN**: servidor TURN próprio na central (UDP e TCP 3478, TLS 5349), com credenciais temporárias, para o áudio do navegador atravessar NAT.
- **Ramais**: o DID do ramal cria a rota de entrada sozinho, com CID de entrada; atendimento automático em chamada interna; interfonia, rastreio da última chamada e ditado passam a depender do cadastro.
- **Conjuntos de PIN**: tela de cadastro e seletor na rota de saída, com opção de levar o PIN ao CDR.
- **Música em espera**: tela para enviar, ouvir e ver quais filas usam cada música.
- **Megafonia e Interfonia**: um número abre o viva-voz de vários aparelhos.
- **DISA**: ligar de fora e discar pela central, com senha obrigatória e contexto restrito por padrão.
- **Relatórios**: por ramal, por tronco (completamento e duração média) e eventos da chamada.
- **E-mail**: configuração do servidor de envio no console, com botão de teste.
- **Diagnóstico**: telas de DIDs, Rede, WebRTC, Firewall e SIP/PJSIP, só de leitura.
- **Configurações**: correio de voz e fax configuráveis no console; tela de Suporte; tela Arquivos e Aplicação (quais arquivos a central gera e quais podem ser editados à mão).
- **Conectividade > Rede**: endereço público e faixas locais no console, com botão que descobre o IP público.
- **Troncos**: coluna "Na central" dizendo se o tronco está no ar, recusado pela operadora, sem resposta ou não aplicado.
- **Relatório de chamadas**: mostra o motivo dado pela operadora quando a chamada não completa, e ganha o botão de retornar a ligação.
- **Rotas de entrada**: seletor único de destino com treze grupos, incluindo conferência, DISA, megafonia e número externo.
- **Click-to-call**: o botão "Ligar" toca primeiro o ramal de quem clicou e, atendido, disca o destino.
- **Instalação**: cenário de teste opcional (`-e cenario_teste=true`), que nunca entra em instalação de cliente.
### Correções
- **Aplicar configurações**: sempre falhava em instalação limpa, e ramais e troncos novos nunca chegavam ao Asterisk; respostas do Asterisk também podiam sair trocadas.
- **Rotas de saída**: rota com PIN derrubava toda chamada.
- **Filas**: escolher música em espera fazia o cliente esperar em silêncio.
- **Correio de voz**: o recado por e-mail nunca saía da central.
- **Troncos**: editar o tronco apagava a senha da operadora; campo secreto em branco agora significa "não mexer", com caixa para remover de propósito.
- **Troncos**: tipo "dahdi" e número no campo "From domain" passam a ser recusados com o motivo.
- **Troncos**: avisa quando a central não carregou a autenticação do tronco, em vez de culpar a operadora.
- **Chamadas**: até nove segundos parados antes de discar, por consulta STUN da central.
- **Permissões**: 19 telas não funcionavam para quem não era administrador.
- **Lista negra**: o anúncio escolhido era descartado.
- **Rotas de entrada**: destino "ocupado" atendia e cobrava a chamada; agora devolve ocupado sem atender.
- **Console**: o aviso de configuração pendente aparece a cada alteração, sem recarregar; duas rotas de saída com o mesmo padrão geram aviso.
- **Instalação**: correções no nginx e no dialplan que paravam o playbook, e o playbook conserta sozinho uma central que ficou sem dialplan; instalação sem áudio agora para em vez de chegar ao cliente.
### Mudanças
- **Menu**: Texto em Voz, Painel Telium e Licenciamento saem do menu, por decisão de produto.
- **Ramais**: o campo de prioridade da regra de gravação sai do formulário; "nunca" de qualquer lado proíbe, "forçar" obriga, e só então vale a preferência de cada um.
- **Ramais**: marcar WebRTC no cadastro passa a ligar também as quatro opções que dependem dele.
### Segurança
- **Cadastros**: senha da DISA, PINs de rota, PIN do ramal e PIN da sala de conferência não voltam mais em listagens.

## 1.0.1 — 2026-09-11
Commits: 4120cb4, 0ed7488, 5e1de82, bb55bdd, 027a2de, fc3caba, b8f5e1d, a9e2334, aabacd7, 85352da, 2d93180, 3d657f9, 385a727
Primeira versão de teste: chamada básica corrigida de ponta a ponta, auditoria de segurança com três falhas críticas fechadas e bateria de testes automáticos.

### Novidades
- **Versão**: o número da versão passa a aparecer na tela de entrada, no rodapé do menu, no /api/health e na CLI.
- **Testes**: bateria `php bin/telium testar`, executada no fim de cada provisionamento.
- **Aplicar configurações**: a barra lista destinos quebrados (rota, URA ou fila apontando para cadastro apagado ou inativo, fila sem agente, rota com tronco desativado).
- **Ramais**: passam a valer o limite de chamadas simultâneas de saída, as opções do Dial, o estado do ramal nas filas e o tempo de toque do siga-me.
### Correções
- **Rotas de saída**: a operadora recebia o número interno do ramal; prefixo "0" a remover era ignorado.
- **Chamadas**: gravação interna, siga-me, fila como destino de rota e DID sem rota cadastrada passam a funcionar como prometido; número interno inexistente toca mensagem.
- **Filas**: salvar agentes descartava a lista sem avisar.
- **Troncos**: o tronco de reserva podia ser excluído deixando a rota sem failover.
- **Telefone do navegador**: endereço montado pela origem da página, servidores STUN/TURN entregues ao navegador, segunda discagem durante chamada recusada e aviso de "Conectado, sem áudio".
- **Aplicar configurações**: duas aplicações ao mesmo tempo apagavam o que a outra escrevia.
- **Servidor**: limite de carga do Asterisk ajustado ao número de núcleos; mudanças de asterisk.conf e modules.conf passam a valer com reinício quando não houver chamada.
### Segurança
- **Cadastros**: nomes com quebra de linha permitiam executar comando no servidor (crítico).
- **Asterisk**: campos do cadastro permitiam injetar ações no AMI (crítico).
- **Login**: a verificação em dois passos aceitava qualquer código (crítico); agora o código é conferido de verdade.
- **Login**: limite de tentativas por origem e no nginx, mesmo tempo de resposta para usuário existente e inexistente, e jail do fail2ban para o login do console.
- **Web**: cabeçalhos de segurança, cifras TLS mais fortes, limpeza de sessões vencidas e escape de apóstrofe no console.
- **/api/health**: sem sessão, responde só nome, versão e estado, sem detalhes técnicos da máquina.

## 1.0.0 — 2026-09-10
Commits: c3e75ad, fecf89d, a0af756, af9edfe, 4b0f33c, 858a1ba, 165ca8f, 70c053c, 9c0ce2a, 7d60d51, 256e0a2, 93629e0, 034ab07, 8086dbd, 62058ff, 28347d1, 654b12a, c4252f3, 38c8a1d, c1cbd9f, a4885e4, a3e96c7, a27f876, 147fb69, 254b5d7, 16ec1c7, 04bdc87, 294ea2a, e8d09c8, 014ba97, 1ca3566, d9fa7bd, 56c9e7c, c33cb23, ec8a3ad, bf1a297
Primeira entrega: console web, instalação automatizada em Debian 13 e os módulos principais de telefonia.

### Novidades
- **Instalação**: Ansible em Debian 13 com Asterisk 22, MariaDB, nginx, PHP 8.4, ufw e fail2ban; senhas de serviço geradas por instalação e scripts de diagnóstico.
- **Console**: identidade Telium, menu recolhível, tema claro e escuro, busca Ctrl+K e wallboard com modo TV.
- **Usuários e perfis**: perfis admin, supervisor, operador e auditoria, perfis sob medida com matriz de ações e política de senha (8 caracteres, número e símbolo).
- **Configuração**: a central é gerada a partir do banco e aplicada pelo console; a base nasce sem nenhum dado fictício; `php bin/telium migrar` atualiza o banco e o /api/health aponta o que falta.
- **Console do Asterisk**: CLI pela web, com comandos que alteram estado exigindo permissão e tudo registrado na auditoria.
- **Ramais**: cadastro completo em abas, gerador de senha SIP, gravação por sentido e interruptor único de WebRTC.
- **Telefone do navegador**: softphone falando direto com o Asterisk por WebSocket seguro.
- **Filas**: anúncios, sussurro, confirmação de atendimento, pesquisa de satisfação e destinos distintos para tempo esgotado, fila sem agente e fila cheia.
- **URA**: entradas na mesma tela e seletor único de destino.
- **Aplicações**: conferências com PIN e gravação, anúncios, condições e grupos de horário (com `*27`), siga-me, correio de voz, estacionamento, chamadas de despertar e destinos personalizados.
- **Códigos de recurso**: catálogo de 64 códigos em 19 categorias, editáveis pelo console.
- **Lista negra e allowlist**: bloqueio por número ou padrão, com a allowlist tendo precedência.
- **Gravação de Chamadas**: lista, player, download e zip, sem exclusão pela web.
- **Gravações do sistema**: envio de áudio convertido para o formato do Asterisk.
- **Agenda de contatos**: agenda da empresa e pessoal, com discagem rápida.
- **Backup e restauração**: escolha do conteúdo, agendamento e retenção.
- **Certificados TLS**: envio, autoassinado e CSR, por serviço, com retorno automático se o novo falhar; SIP TLS na porta 5061.
- **Telefonia**: tons de discar, ocupado e chamando no padrão brasileiro.
### Correções
- **Ramais**: nenhum ramal carregava no Asterisk por uma opção com nome errado.
- **Console**: o painel levava cerca de 15 segundos para abrir por lentidão na conexão com o Asterisk.
- **Console do Asterisk**: de 35 erros por recarga para zero; estacionamento e conferências não subiam numa instalação nova.
### Mudanças
- **Usuários**: a senha do admin é reposta à de fábrica a cada execução do playbook; quem quiser senha própria cria um segundo usuário administrador.
- **Menu**: saem Administradores, Admin Módulos, Idiomas de Som, Config Edit, Atualizações, Diretório, Chamada de Retorno, Controle de Fluxo, Definir ID do Chamador e Relatórios > Gravações.
### Segurança
- **Ramais**: senha SIP com no mínimo 12 caracteres e validação dos campos do cadastro.
- **Gravações**: download protegido contra acesso a arquivos fora da pasta de gravações.
