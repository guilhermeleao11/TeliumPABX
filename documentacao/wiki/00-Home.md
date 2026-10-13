# Bem-vindo ao Telium PABX

O **Telium PABX** é a central telefônica IP da Telium: ramais, troncos, filas, URA, call center, gravação, relatórios e softphone no navegador, tudo administrado por um console web.

Esta wiki reúne tudo sobre o produto: como instalar e atualizar, como usar cada tela, os comandos do servidor, o que vai no backup, como resolver problemas e como desenvolver.

> 📷 **Print:** tela inicial do console (Painel de Indicadores › Visão Geral).

---

## Comece por aqui

| Eu sou… | Leia primeiro |
|---|---|
| **Técnico de implantação** | [Instalação](01-Instalacao.md) → [Rede e portas](03-Requisitos-Rede-e-Portas.md) → [Conectividade](17-Conectividade.md) → [Aplicações](13-Aplicacoes-Filas-URA-e-Atendimento.md) |
| **Suporte** | [Solução de problemas](07-Solucao-de-Problemas.md) → [Comandos e serviços](05-Servidor-Comandos-e-Servicos.md) → [Diretórios e logs](06-Diretorios-Arquivos-e-Logs.md) |
| **Administrador da central (cliente)** | [Administrador](12-Administrador.md) → [Conectividade](17-Conectividade.md) → [Códigos de recurso](15-Codigos-de-Recurso.md) |
| **Supervisor de call center** | [Call Center](16-Call-Center.md) → [Relatórios](18-Relatorios.md) |
| **Usuário do ramal** | [Meu Painel (PCU)](21-Meu-Painel-PCU.md) → [Telefone no navegador](11-Telefone-WebRTC.md) → [Códigos de recurso](15-Codigos-de-Recurso.md) |
| **Desenvolvedor** | [Arquitetura](31-Arquitetura.md) → [Desenvolvimento](30-Desenvolvimento.md) → [Referência da API](32-API-Referencia.md) |

---

## O produto em um minuto

- **Uma central completa num servidor só.** Uma VM Debian 13 roda o Asterisk 22 (PJSIP), o banco MariaDB, o nginx com HTTPS, a API em PHP 8.4, o TURN para o softphone e o backup.
- **Tudo pelo console.** Nada de editar arquivo `.conf` à mão: a configuração do Asterisk é **gerada a partir do banco** quando se clica em **Aplicar configurações**.
- **Instalação e atualização por Ansible.** Um comando instala a central inteira; o mesmo comando atualiza sem perder dados.
- **Sem dado fictício.** A central nasce vazia, só com a empresa, os perfis e a conta `admin`. Tudo o mais é cadastrado.
- **Em português.** Console, mensagens e os áudios da central (correio de voz, fila, conferência, call center) são em português do Brasil.

### Componentes

| Componente | Versão |
|---|---|
| Telium PABX | 1.1.0 (a versão e o commit de cada central aparecem no rodapé do menu e em **Telium › Versões e Novidades**) |
| Debian | 13 (trixie); também roda em Ubuntu 24.04 |
| Asterisk | 22.11.0, compilado do fonte, com PJSIP |
| MariaDB | 12.3.3 |
| nginx | mainline 1.31.x |
| PHP | 8.4 + Slim 4 |
| coturn | TURN/STUN próprio para o softphone do navegador |
| Janus | 1.4.1, opcional e desligado de fábrica |

---

## O que a central faz

| Área | Recursos | Página |
|---|---|---|
| Painel de Indicadores | Visão geral, wallboard em tempo real com Modo TV, estatísticas do servidor e do Asterisk | [10](10-Painel-de-Indicadores.md) |
| Telefone | Softphone no navegador (WebRTC) | [11](11-Telefone-WebRTC.md) |
| Administrador | Usuários, perfis e permissões, backup, certificados, lista negra, allowlist, CLI do Asterisk, contatos, destinos personalizados, códigos de recurso, gravações do sistema, auditoria | [12](12-Administrador.md) |
| Aplicações: atendimento | Filas, pesquisa de satisfação, grupos de toque, URA, conferências, gravação de chamadas, anúncios, condições e grupos de horário | [13](13-Aplicacoes-Filas-URA-e-Atendimento.md) |
| Aplicações: recursos do ramal | Siga-me, correio de voz, caixas postais, DISA, estacionamento, megafonia e interfonia, despertador | [14](14-Aplicacoes-Recursos-do-Ramal.md) |
| Códigos de recurso | Todos os códigos discados no telefone (`*xx`) | [15](15-Codigos-de-Recurso.md) |
| Call Center | Agentes, pausas, tabulação, retorno, monitor ao vivo com escuta, relatórios | [16](16-Call-Center.md) |
| Conectividade | Ramais, troncos, rotas de entrada e saída, DIDs, firewall, provisionamento de telefones, rede, WebRTC | [17](17-Conectividade.md) |
| Relatórios | CDR com gravações e exportação em CSV, filas, ramais, troncos, CEL, agentes | [18](18-Relatorios.md) |
| Configurações | Arquivos e aplicação, SIP, correio de voz, música de espera, PINs, fax, e-mail, tarifas, dados da empresa | [19](19-Configuracoes.md) |
| Telium | Tarifação, integrações e API (click to call e webhooks), suporte, versões e novidades | [20](20-Telium-Tarifacao-Integracoes-Suporte.md) |
| Meu Painel (PCU) | O painel do usuário: meu ramal, minhas chamadas, correio de voz, siga-me, contatos, perfil e segurança | [21](21-Meu-Painel-PCU.md) |

---

## Índice completo

### Implantação e operação

| # | Página | Conteúdo |
|---|---|---|
| 01 | [Instalação](01-Instalacao.md) | Servidor novo, do zero ao primeiro atendimento |
| 02 | [Atualização](02-Atualizacao.md) | Atualizar sem perder dados, voltar atrás, notas das versões (a 1.1.0 é a mais recente) |
| 03 | [Requisitos de rede e portas](03-Requisitos-Rede-e-Portas.md) | Portas, NAT, firewall do cliente e da operadora |
| 04 | [Backup e restauração](04-Backup-e-Restauracao.md) | Backup do console, destino remoto, restauração, o que levar no backup da empresa |
| 05 | [Comandos e serviços do servidor](05-Servidor-Comandos-e-Servicos.md) | CLI `telium`, serviços e timers, comandos do Asterisk e do banco |
| 06 | [Diretórios, arquivos e logs](06-Diretorios-Arquivos-e-Logs.md) | Onde fica cada coisa na central |
| 07 | [Solução de problemas](07-Solucao-de-Problemas.md) | Por sintoma: causa, verificação e solução |
| 08 | [Segurança](08-Seguranca.md) | Firewall, fail2ban, senhas, 2FA, permissões, boas práticas |

### Manual das telas

| # | Página |
|---|---|
| 10 | [Painel de Indicadores](10-Painel-de-Indicadores.md) |
| 11 | [Telefone (WebRTC)](11-Telefone-WebRTC.md) |
| 12 | [Administrador](12-Administrador.md) |
| 13 | [Aplicações: filas, URA e atendimento](13-Aplicacoes-Filas-URA-e-Atendimento.md) |
| 14 | [Aplicações: recursos do ramal](14-Aplicacoes-Recursos-do-Ramal.md) |
| 15 | [Códigos de recurso](15-Codigos-de-Recurso.md) |
| 16 | [Call Center](16-Call-Center.md) |
| 17 | [Conectividade](17-Conectividade.md) |
| 18 | [Relatórios](18-Relatorios.md) |
| 19 | [Configurações](19-Configuracoes.md) |
| 20 | [Telium: tarifação, integrações e suporte](20-Telium-Tarifacao-Integracoes-Suporte.md) |
| 21 | [Meu Painel (PCU)](21-Meu-Painel-PCU.md) |

### Desenvolvimento

| # | Página | Conteúdo |
|---|---|---|
| 30 | [Desenvolvimento](30-Desenvolvimento.md) | Ambiente, migrações, testes, como criar uma tela, fluxo git |
| 31 | [Arquitetura](31-Arquitetura.md) | Componentes, fluxo do "Aplicar configurações", estrutura do código |
| 32 | [Referência da API](32-API-Referencia.md) | Autenticação, formato das respostas, todas as rotas |

---

## Informações rápidas

| O quê | Onde / como |
|---|---|
| Repositório | `gitlab.telium.com.br/Voip/teliumpabx` |
| Endereço do console | `https://<nome ou IP do servidor>/` |
| Primeiro acesso | Usuário `admin`, com a senha inicial informada na [Instalação](01-Instalacao.md). **Troque no primeiro acesso** |
| Perdeu a senha do admin | `sudo -u teliumpbx php /opt/telium/api/bin/telium senha admin` |
| Perdeu o celular do 2FA (admin) | `sudo -u teliumpbx php /opt/telium/api/bin/telium desligar-2fa admin` |
| Atualizar a central | `cd /opt/TeliumPABX && sudo git pull && cd infra/ansible && sudo ansible-playbook site.yml -e compilar_asterisk=false -e compilar_janus=false` |
| Qual versão está rodando, e o que mudou em cada uma | Console: **Telium › [Versões e Novidades](20-Telium-Tarifacao-Integracoes-Suporte.md)**; o histórico também está em `api/CHANGELOG.md` e nas [notas das versões](02-Atualizacao.md#10-notas-das-versões) |
| Conferir se está tudo de pé | `sudo -u teliumpbx php /opt/telium/api/bin/telium testar` |
| Saúde da API | `https://<central>/api/health` |
| Backups do console | `/var/Backup/pabx-telium` |
| Gravações | `/var/spool/asterisk/monitor` |
| Senhas de serviço | `/etc/telium/credenciais` (só root) |
| Banco de dados | MariaDB, base `telium_pabx` |

> **Nunca rode `-e limpar_banco=true` nem `-e cenario_teste=true` numa central de cliente.** O primeiro apaga a operação inteira (ramais, filas, rotas, histórico); o segundo cria cadastros de teste.

---

## Conceitos que aparecem em toda a wiki

| Termo | Significado |
|---|---|
| **Aplicar configurações** | Salvar no console grava no banco; a central só passa a usar a mudança depois de **Aplicar configurações** (faixa amarela no topo). Ela gera os arquivos do Asterisk e recarrega sem derrubar chamadas |
| **Destino** | Para onde uma chamada vai: ramal, fila, URA, grupo de toque, anúncio, correio de voz, caixa postal, condição horária, conferência, desligar… Quase toda tela que encaminha chamadas usa o mesmo seletor de destinos |
| **Código de recurso** | Um código discado no telefone (`*xx`) que liga uma função: siga-me, correio de voz, pausa do call center… Nenhum código pode ser igual a outro nem começar outro |
| **Perfil** | O conjunto de telas e ações que um usuário do console pode acessar |
| **Ramal** | A linha interna (telefone de mesa, softphone ou navegador) |
| **Tronco** | A ligação com a operadora |
| **DID** | O número externo que chega pela operadora |
| **CDR** | O registro de cada chamada |

---

## Outros documentos do repositório

| Documento | Para quê |
|---|---|
| `docs/CADERNO-DE-TESTE.md` | Caderno de aceitação: o que testar antes de entregar a central a um cliente |
| `docs/TESTE-FUNCIONAL.md` | Passeio pelas telas, módulo a módulo |
| `docs/PENDENCIAS.md` | O que ainda falta e as limitações conhecidas |
| `docs/BACKEND.md` | Notas de arquitetura do backend |
| `api/CHANGELOG.md` | Histórico de versões, o mesmo que o console mostra em **Telium › Versões e Novidades** |
| `infra/README.md` | Referência técnica do provisionamento |
