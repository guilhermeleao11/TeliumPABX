<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Dominio\Permissoes;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Trava;

/**
 * "Aplicar configurações": recarrega os módulos do Asterisk pelo AMI.
 * Nada de sudo — a API só fala com o AMI em 127.0.0.1.
 */
final class Aplicador
{
    /**
     * O que recarregar, e como cada módulo chama a sua recarga.
     *
     * "pjsip reload" NÃO existe no Asterisk 22 — só "pjsip reload
     * qualify". Enquanto esteve nesta lista, toda aplicação falhava e,
     * pior, endpoint e tronco novos nunca chegavam ao Asterisk: o
     * console dizia que tinha aplicado e o ramal não existia.
     *
     * A lista cobre todos os arquivos que o gerador escreve. Faltando
     * um, a alteração fica no disco esperando alguém reiniciar.
     */
    private const RECARGAS = [
        'module reload res_pjsip.so'        => 'ramais e troncos',
        'dialplan reload'                   => 'dialplan',
        'module reload app_queue'           => 'filas',
        'voicemail reload'                  => 'correio de voz',
        'module reload app_confbridge.so'   => 'conferências',
        'module reload res_parking.so'      => 'estacionamento',
        'module reload res_musiconhold.so'  => 'música em espera',
        'module reload features'            => 'códigos de recurso',
        // O cdr_adaptive_odbc guarda o desenho da tabela de quando
        // subiu. Coluna nova criada por migração só passa a ser
        // gravada depois disto — sem recarregar, o dialplan escreve
        // em CDR(motivo) e o banco fica com NULL, sem erro nenhum.
        'module reload cdr_adaptive_odbc.so' => 'colunas do CDR',
        // Sem esta, mudar o endereço público no console reescrevia o
        // mapa de candidatos ICE e o Asterisk seguia com o antigo.
        'module reload res_rtp_asterisk.so' => 'candidatos ICE e portas de voz',
    ];

    /**
     * A lista de recargas, para a bateria de testes conferir que cada
     * comando existe nesta versão do Asterisk.
     *
     * @return array<string,string>
     */
    public static function comandosDeRecarga(): array
    {
        return self::RECARGAS;
    }

    /** @return array{sucesso:bool, etapas:array<string,string>, saida:string} */
    public function aplicar(?int $usuarioId = null, array $arquivos = []): array
    {
        $etapas = [];
        $saida = '';
        $sucesso = true;

        // Duas aplicações ao mesmo tempo se atropelam: cada uma limpa a
        // família inteira na base do Asterisk antes de regravá-la, e a
        // limpeza de uma apagava o que a outra tinha acabado de escrever.
        $trava = Trava::tentar('telium_aplicar', 20);
        if ($trava === null) {
            return [
                'sucesso' => false,
                'etapas'  => ['aplicação em curso' => 'falhou'],
                'saida'   => 'Outra aplicação de configuração está em andamento. '
                           . 'Espere ela terminar e tente de novo.',
            ];
        }

        try {
            $ami = Ami::compartilhada();

            // A base interna do Asterisk é o que vale em tempo de chamada
            // para número exato, porque é lá que os códigos *30 e *38
            // escrevem. Aqui ela é reposta a partir do banco, que é o que
            // o console edita — do contrário as duas fontes divergiriam.
            $etapas['listas na base do Asterisk'] = $this->sincronizarListas($ami) ? 'ok' : 'falhou';

            foreach (self::RECARGAS as $comando => $descricao) {
                $resposta = $ami->comando($comando);
                $ok = $this->recarregou($resposta);
                $etapas[$descricao] = $ok ? 'ok' : 'falhou';
                $saida .= "\$ {$comando}\n" . trim($resposta) . "\n\n";
                $sucesso = $sucesso && $ok;
            }

            // O reload responde "ok" mesmo quando o PJSIP descartou um objeto
            // por causa de uma opção inválida: o ramal some da central e o
            // console dizia que estava tudo aplicado. Aqui se confere o que
            // de fato carregou.
            // O reload do PJSIP responde antes de terminar de carregar: logo
            // depois de criar muitos ramais de uma vez, os últimos ainda não
            // aparecem. Espera um pouco antes de acusar, sem passar de ~4 s.
            $faltando = $this->endpointsQueNaoCarregaram($ami);
            for ($tentativa = 0; $faltando !== [] && $tentativa < 5; $tentativa++) {
                usleep(800_000);
                $faltando = $this->endpointsQueNaoCarregaram($ami);
            }
            if ($faltando !== []) {
                $etapas['ramais e troncos carregados'] = 'falhou';
                $saida .= 'O Asterisk não carregou: ' . implode(', ', array_slice($faltando, 0, 20))
                        . (count($faltando) > 20 ? '…' : '')
                        . ". Veja o log do Asterisk (pjsip) para saber qual opção ele recusou.\n\n";
                $sucesso = false;
            }
        } catch (\Throwable $e) {
            // Sem esta etapa, a tela mostraria "recusou parte da recarga"
            // com a lista vazia, sem dizer que o problema foi a conexão.
            $etapas['conexão com o Asterisk'] = 'falhou';
            $sucesso = false;
            $saida .= 'ERRO: ' . $e->getMessage() . "\n";
        }

        Bd::executar(
            'INSERT INTO config_aplicacoes (usuario_id, arquivos, reloads, sucesso, saida)
             VALUES (?, ?, ?, ?, ?)',
            [
                $usuarioId,
                json_encode($arquivos, JSON_UNESCAPED_UNICODE),
                json_encode($etapas, JSON_UNESCAPED_UNICODE),
                $sucesso ? 1 : 0,
                mb_substr($saida, 0, 60000),
            ]
        );

        if ($sucesso) {
            Bd::executar(
                "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','0')
                 ON DUPLICATE KEY UPDATE valor = '0'"
            );
        }

        return ['sucesso' => $sucesso, 'etapas' => $etapas, 'saida' => $saida];
    }

    /**
     * Apaga uma família inteira da base do Asterisk.
     *
     * Família vazia responde "Database entry not found", que é um erro
     * do protocolo e um não-evento para nós: numa instalação sem lista
     * negra, sem siga-me e sem não perturbe — ou seja, a maioria —
     * todas as famílias estão vazias, e a etapa inteira era dada como
     * falha. Era esse o 500 de "aplicar" numa central recém-instalada.
     */
    private function limpouFamilia(Ami $ami, string $familia): bool
    {
        $resposta = $ami->acao(['Action' => 'DBDelTree', 'Family' => $familia]);
        if ($this->deuCerto($resposta)) {
            return true;
        }

        $texto = strtolower($resposta);

        return str_contains($texto, 'not found')
            || str_contains($texto, 'not exist')
            || str_contains($texto, 'no such');
    }

    /**
     * Ramais e troncos ativos que não aparecem no "pjsip show endpoints".
     *
     * @return string[]
     */
    private function endpointsQueNaoCarregaram(Ami $ami): array
    {
        $resposta = $ami->comando('pjsip show endpoints');
        // Pelo AMI cada linha vem como "Output:  Endpoint:  1001/1001 ...";
        // o cabeçalho da tabela ("<Endpoint/CID...>") fica de fora pelo "<".
        preg_match_all('/Endpoint:\s+([^\s\/<]+)\//', $resposta, $m);
        $carregados = array_flip($m[1]);
        if ($carregados === []) {
            return [];      // sem resposta legível não dá para afirmar nada
        }

        $esperados = array_column(Bd::todos('SELECT numero FROM ramais WHERE ativo = 1'), 'numero');
        foreach (Bd::todos("SELECT nome FROM troncos WHERE ativo = 1 AND tipo = 'pjsip'") as $t) {
            $esperados[] = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $t['nome']) ?? (string) $t['nome'];
        }

        return array_values(array_filter(
            array_map('strval', $esperados),
            static fn (string $nome): bool => !isset($carregados[$nome])
        ));
    }

    /** A ação do AMI respondeu sucesso? */
    private function deuCerto(string $resposta): bool
    {
        return preg_match('/^Response:\s*Success/mi', $resposta) === 1;
    }

    /**
     * A recarga deu certo?
     *
     * A procura por "error" em qualquer lugar da resposta dava falso
     * positivo: basta o nome de um ramal ou de uma fila conter a
     * palavra para a etapa ser dada como falha. O que importa é a
     * linha de resposta do AMI e a recusa do próprio CLI.
     */
    /** Só para a bateria de testes alcançar a regra sem subir Asterisk. */
    public static function recargaDeuCerto(string $resposta): bool
    {
        return (new self())->recarregou($resposta);
    }

    private function recarregou(string $resposta): bool
    {
        if (preg_match('/^Response:\s*Error/mi', $resposta) === 1) {
            return false;
        }

        $texto = strtolower($resposta);

        return !str_contains($texto, 'no such command')
            // "No such module" é a resposta quando o módulo não está
            // carregado. Passava por sucesso porque a frase é outra —
            // então uma central sem o res_pjsip carregado aplicava
            // "com sucesso" e ficava sem ramal nenhum.
            && !str_contains($texto, 'no such module')
            && !str_contains($texto, 'does not support reload')
            && !str_contains($texto, 'failed to reload');
    }

    /**
     * Repõe as famílias listanegra/ e allowlist/ na base do Asterisk.
     *
     * Só números exatos: padrões (_1199X.) não cabem numa chave de banco
     * e continuam morando no dialplan gerado, que a sub-rotina consulta
     * logo depois.
     */
    private function sincronizarListas(Ami $ami): bool
    {
        $tabelas = [
            'listanegra' => 'SELECT numero FROM lista_negra WHERE ativo = 1',
            'allowlist'  => 'SELECT numero FROM lista_permitida WHERE ativo = 1',
        ];

        $ok = true;

        // Siga-me guarda o destino, não um "1": vai à parte.
        $ok = $this->sincronizarSigaMe($ami) && $ok;

        // Não perturbe: o console e o código *76 gravam no banco (o código
        // pelo func_odbc TELIUM_DND), e a base é reposta a partir dele —
        // senão o ramal aparece livre numa tela e ocupado na outra.
        $tabelas['dnd'] = 'SELECT numero FROM ramais WHERE ativo = 1 AND dnd = 1';

        // Chamada em espera. Sem a chave, o sub-ramal dá ocupado na
        // segunda chamada — é a opção do cadastro que decide.
        $tabelas['cw'] = 'SELECT numero FROM ramais WHERE ativo = 1 AND chamada_espera = 1';

        // Interfonia e rastreio guardam a NEGATIVA: a maioria dos ramais
        // permite, e gravar só a exceção deixa a base pequena.
        $tabelas['interfonia-nao'] =
            "SELECT numero FROM ramais WHERE ativo = 1 AND interfonia = 'negar'";
        $tabelas['rastreio-nao'] =
            'SELECT numero FROM ramais WHERE ativo = 1 AND rastreio_chamada = 0';

        // Atendimento automático em chamada interna. Guarda a exceção,
        // que é quem tem o recurso ligado.
        $tabelas['autoatende'] =
            'SELECT numero FROM ramais WHERE ativo = 1 AND auto_resposta = 1';

        // Ditado: os códigos *34 e *35 só valem para quem tem no cadastro.
        $tabelas['ditado'] = 'SELECT numero FROM ramais WHERE ativo = 1 AND ditado = 1';

        // Permissão de discagem de cada ramal. O canal que sai por siga-me,
        // desvio ou transferência às cegas não é o do ramal (é um Local ou
        // o do tronco), não carrega o TELIUM_PERM do endpoint, e sem isto
        // saía com tudo liberado: um ramal só-local punha o siga-me num
        // número internacional e ligava para si mesmo.
        if (!$this->limpouFamilia($ami, 'perm')) {
            $ok = false;
        }
        foreach (Bd::todos('SELECT * FROM ramais WHERE ativo = 1') as $r) {
            $resposta = $ami->acao([
                'Action' => 'DBPut',
                'Family' => 'perm',
                'Key'    => (string) $r['numero'],
                'Val'    => GeradorPjsip::permissoes($r),
            ]);
            if (!$this->deuCerto($resposta)) {
                $ok = false;
            }
        }

        // Quem pode escutar (*555): o ramal de um usuário ativo com a
        // permissão de supervisor do call center.
        if (!$this->limpouFamilia($ami, 'escuta')) {
            $ok = false;
        }
        $modulosDoPerfil = [];
        foreach (Bd::todos('SELECT perfil_id, modulo FROM perfil_modulos') as $m) {
            $modulosDoPerfil[(int) $m['perfil_id']][] = (string) $m['modulo'];
        }
        foreach (Bd::todos(
            "SELECT ramal, perfil_id FROM usuarios
              WHERE status = 'ativo' AND ramal IS NOT NULL AND ramal <> ''"
        ) as $u) {
            if (!Permissoes::podeModulo($modulosDoPerfil[(int) $u['perfil_id']] ?? [], 'cc.supervisor')) {
                continue;
            }
            $resposta = $ami->acao([
                'Action' => 'DBPut', 'Family' => 'escuta', 'Key' => (string) $u['ramal'], 'Val' => '1',
            ]);
            if (!$this->deuCerto($resposta)) {
                $ok = false;
            }
        }

        foreach ($tabelas as $familia => $sql) {
            if (!$this->limpouFamilia($ami, $familia)) {
                $ok = false;
            }

            foreach (Bd::todos($sql) as $linha) {
                $numero = (string) $linha['numero'];
                if ($numero === '' || preg_match('/[_.\[\]XZN!]/', $numero) === 1) {
                    continue;   // é padrão de dialplan, não número
                }

                $resposta = $ami->acao([
                    'Action' => 'DBPut',
                    'Family' => $familia,
                    'Key'    => $numero,
                    'Val'    => '1',
                ]);
                if (!$this->deuCerto($resposta)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }

    /**
     * A família sigame é a que o sub-ramal consulta a cada chamada. Ela
     * é reposta a partir do banco, senão um destino trocado no console
     * ficaria só na tela.
     */
    private function sincronizarSigaMe(Ami $ami): bool
    {
        $ok = true;
        foreach (['sigame', 'sigame-modo', 'sigame-toque'] as $familia) {
            if (!$this->limpouFamilia($ami, $familia)) {
                $ok = false;
            }
        }

        $ligados = Bd::todos(
            "SELECT numero, siga_me, siga_me_modo, toque_sigame FROM ramais
              WHERE ativo = 1 AND siga_me_ativo = 1 AND siga_me IS NOT NULL AND siga_me <> ''"
        );

        foreach ($ligados as $r) {
            $chaves = [['sigame', $r['siga_me']], ['sigame-modo', $r['siga_me_modo']]];
            // O celular do siga-me costuma precisar de mais tempo de toque
            // que o ramal de mesa.
            if ((int) ($r['toque_sigame'] ?? 0) > 0) {
                $chaves[] = ['sigame-toque', (int) $r['toque_sigame']];
            }

            foreach ($chaves as [$fam, $val]) {
                $resposta = $ami->acao([
                    'Action' => 'DBPut', 'Family' => $fam,
                    'Key' => (string) $r['numero'], 'Val' => (string) $val,
                ]);
                if (!$this->deuCerto($resposta)) {
                    $ok = false;
                }
            }
        }

        return $ok;
    }
}
