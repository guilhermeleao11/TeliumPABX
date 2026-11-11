<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Mesa da Telefonista: todos os ramais da central, ao vivo.
 *
 * Quem atende a recepção precisa saber, antes de passar uma ligação, se
 * o ramal está livre, tocando, falando, em espera, fora do ar ou em não
 * perturbe. Tudo sai do Asterisk na hora (estado do aparelho, canais e a
 * base do não perturbe); o banco só dá o nome e o setor.
 */
final class Telefonista
{
    /** GET /api/telefonista/ramais */
    public function ramais(Request $req, Response $res): Response
    {
        try {
            $ami = Ami::compartilhada();
        } catch (\Throwable) {
            $ami = null;
        }
        $foto = self::foto($ami);
        $eu = trim((string) (((array) $req->getAttribute('usuario'))['ramal'] ?? ''));
        $meu = null;
        foreach ($foto['ramais'] as $r) {
            if ($r['numero'] === $eu) {
                $meu = $r;
            }
        }

        return Resposta::json($res, $foto + [
            'eu'         => $eu !== '' ? $eu : null,
            'em_chamada' => $meu !== null && $meu['transferivel'],
            // A captura direta (código + ramal): "Puxar" na tela disca isso
            // do aparelho da telefonista. Sem o código ativo, sem o botão.
            'captura'    => Bd::valor(
                "SELECT codigo FROM codigos_recurso WHERE chave = 'captura_dir' AND ativo = 1 LIMIT 1"
            ) ?: null,
        ]);
    }

    /**
     * A foto de todos os ramais. A mesma para a API e para o serviço de
     * tempo real (telium-cc), que a manda à tela a cada evento do Asterisk.
     *
     * @return array{ramais: list<array<string,mixed>>, asterisk: bool, asterisk_erro: ?string, agora: int}
     */
    public static function foto(?Ami $ami): array
    {
        $ramais = Bd::todos(
            'SELECT numero, nome, setor, dnd FROM ramais
              WHERE ativo = 1
           ORDER BY LENGTH(numero), numero'
        );

        $estados = [];
        $canais = [];
        $dnd = null;
        $erro = $ami === null ? 'sem conexão com o AMI' : null;
        if ($ami !== null) {
            try {
                foreach ($ami->lista(['Action' => 'DeviceStateList']) as $e) {
                    $estados[(string) ($e['Device'] ?? '')] = strtoupper((string) ($e['State'] ?? ''));
                }
                $canais = $ami->lista(['Action' => 'CoreShowChannels']);
                $dnd = self::dndDaBase($ami->comando('database show dnd'));
            } catch (\Throwable $e) {
                $erro = $e->getMessage();
            }
        }

        $porRamal = self::canaisPorRamal($canais);
        $nomes = array_column($ramais, 'nome', 'numero');
        // Quem está do outro lado da ponte, quando é um ramal: mais certo
        // que o número que o canal mostra (num click to call ou numa
        // captura, ele é o número discado, como "**7003").
        $pontas = self::ramalDoOutroLado($canais);

        $lista = [];
        foreach ($ramais as $r) {
            $numero = (string) $r['numero'];
            // A base do Asterisk é o que vale na hora da chamada (*78/*79
            // gravam nela e no banco); sem AMI, fica o banco.
            $emDnd = $dnd !== null ? isset($dnd[$numero]) : (int) $r['dnd'] === 1;
            $sit = self::situacao($estados["PJSIP/{$numero}"] ?? null, $porRamal[$numero] ?? [], $erro === null);
            $outro = $pontas[$sit['canal']] ?? null;
            if ($outro !== null) {
                $sit['outro_numero'] = $outro;
                $sit['outro_nome'] = isset($nomes[$outro]) ? (string) $nomes[$outro] : null;
            }
            unset($sit['canal']);
            $lista[] = [
                'numero' => $numero,
                'nome'   => (string) $r['nome'],
                'setor'  => (string) ($r['setor'] ?? ''),
                'dnd'    => $emDnd,
            ] + $sit + [
                // Numa ligação atendida, com alguém do outro lado: dá para
                // transferir a partir deste ramal.
                'transferivel' => self::canalParaTransferir($porRamal[$numero] ?? []) !== null,
            ];
        }

        return [
            'ramais'        => $lista,
            'asterisk'      => $erro === null,
            'asterisk_erro' => $erro,
            'agora'         => time(),
        ];
    }

    /**
     * PUT /api/telefonista/ramais/{numero}/dnd {ativo}
     *
     * Vale na hora, como o *78/*79 no aparelho: banco e base do Asterisk.
     */
    public function dnd(Request $req, Response $res, array $args): Response
    {
        $numero = (string) $args['numero'];
        $r = Bd::um('SELECT id, numero FROM ramais WHERE numero = ? AND ativo = 1', [$numero]);
        if ($r === null) {
            return Resposta::erro($res, "O ramal {$numero} não existe ou está inativo.", 404);
        }

        $ativo = (bool) (((array) $req->getParsedBody())['ativo'] ?? false);
        Bd::executar('UPDATE ramais SET dnd = ? WHERE id = ?', [(int) $ativo, $r['id']]);

        $aplicado = true;
        try {
            $resposta = Ami::compartilhada()->acao($ativo
                ? ['Action' => 'DBPut', 'Family' => 'dnd', 'Key' => $numero, 'Val' => '1']
                : ['Action' => 'DBDel', 'Family' => 'dnd', 'Key' => $numero]);
            // DBDel de chave que não existe responde Error: o resultado é o
            // mesmo que se pediu.
            $aplicado = $ativo ? str_contains($resposta, 'Success') : true;
        } catch (\Throwable) {
            $aplicado = false;
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'tel.mesa', $numero, ['dnd' => (int) $ativo]);

        return Resposta::json($res, [
            'numero'   => $numero,
            'dnd'      => $ativo,
            'aplicado' => $aplicado,
            'mensagem' => $aplicado
                ? ($ativo ? "Não perturbe ativado no ramal {$numero}." : "Não perturbe desativado no ramal {$numero}.")
                : 'Gravado, mas a central não respondeu: passa a valer quando a configuração for aplicada.',
        ]);
    }

    /**
     * POST /api/telefonista/transferir {destino}
     *
     * Passa a ligação em que a telefonista está para outro ramal, sem
     * consulta: o mesmo que o ## seguido do ramal. Quem é transferido é a
     * outra ponta; o aparelho dela desliga.
     */
    public function transferir(Request $req, Response $res): Response
    {
        $eu = trim((string) (((array) $req->getAttribute('usuario'))['ramal'] ?? ''));
        if ($eu === '') {
            return Resposta::erro($res, 'Sua conta não tem ramal vinculado, então não há chamada sua para transferir.', 422);
        }

        $destino = preg_replace('/[^0-9]/', '', (string) (((array) $req->getParsedBody())['destino'] ?? '')) ?? '';
        if ($destino === '' || Bd::valor('SELECT id FROM ramais WHERE numero = ? AND ativo = 1', [$destino]) === null) {
            return Resposta::erro($res, 'Escolha um ramal da central para transferir.', 422, ['campo' => 'destino']);
        }
        if ($destino === $eu) {
            return Resposta::erro($res, 'Não dá para transferir para o próprio ramal.', 422, ['campo' => 'destino']);
        }

        try {
            $ami = Ami::compartilhada();
            $canais = $ami->lista(['Action' => 'CoreShowChannels']);
        } catch (\Throwable $e) {
            return Resposta::erro($res, 'A central não respondeu: ' . $e->getMessage(), 502);
        }

        $meu = self::canalParaTransferir(self::canaisPorRamal($canais)[$eu] ?? []);
        if ($meu === null) {
            return Resposta::erro($res, "O ramal {$eu} não está em nenhuma ligação atendida agora.", 409);
        }

        // Ligação que veio de uma fila: a outra ponta é o Local do agente,
        // e o contexto de transferência dele religa o CDR antes de seguir
        // pelo contexto do ramal (o mesmo caminho da transferência pelo
        // botão do aparelho).
        $outro = self::outraPonta($canais, $meu);
        $contexto = $outro !== null && preg_match('#^Local/[^@]+@telium-agente-#', $outro) === 1
            ? 'telium-agente-transf'
            : self::contextoDoRamal($eu);

        $resposta = $ami->acao([
            'Action'  => 'BlindTransfer',
            'Channel' => $meu,
            'Context' => $contexto,
            'Exten'   => $destino,
        ]);
        if (!str_contains($resposta, 'Success')) {
            return Resposta::erro($res, 'A central recusou a transferência. Tente pelo aparelho (## e o ramal).', 409);
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'transferir', 'tel.mesa', $destino,
                             ['ramal' => $eu, 'destino' => $destino]);

        return Resposta::json($res, ['ok' => true, 'mensagem' => "Ligação transferida para o ramal {$destino}."]);
    }

    // ------------------------------------------------------------------

    /**
     * O estado de um ramal a partir do estado do aparelho e dos canais dele.
     *
     * @param list<array<string,string>> $canais
     * @return array{estado:string, direcao:?string, outro_numero:?string, outro_nome:?string, duracao:int, canal:string}
     */
    public static function situacao(?string $estadoDispositivo, array $canais, bool $comAsterisk = true): array
    {
        $vazio = ['direcao' => null, 'outro_numero' => null, 'outro_nome' => null, 'duracao' => 0, 'canal' => ''];
        if (!$comAsterisk) {
            return ['estado' => 'desconhecido'] + $vazio;
        }

        // Falando vence tocando: com dois aparelhos, um atende e o outro
        // ainda pode aparecer tocando por um instante.
        $prioridade = ['Up' => 3, 'Ring' => 2, 'Ringing' => 1];
        usort($canais, static fn ($a, $b) =>
            ($prioridade[$b['ChannelStateDesc'] ?? ''] ?? 0) <=> ($prioridade[$a['ChannelStateDesc'] ?? ''] ?? 0));
        $c = $canais[0] ?? null;
        // Canal ainda em Down é aparelho sendo chamado (antes do 180): até
        // ele dizer que tocou, vale o estado do aparelho, e não "em chamada".
        if ($c !== null && !isset($prioridade[$c['ChannelStateDesc'] ?? ''])) {
            $c = null;
        }

        if ($c === null) {
            $estado = match ($estadoDispositivo) {
                'UNAVAILABLE', 'INVALID', null => 'offline',
                'RINGING'                      => 'tocando',
                'INUSE', 'BUSY', 'RINGINUSE'   => 'emchamada',
                'ONHOLD'                       => 'espera',
                default                        => 'disponivel',
            };
            return ['estado' => $estado] + $vazio;
        }

        $saida = ($c['Uniqueid'] ?? '') !== '' && ($c['Uniqueid'] ?? '') === ($c['Linkedid'] ?? '');
        // Discando e esperando o outro lado atender: o canal está em Ring
        // (ou Up, quando a ligação nasceu de um click to call) rodando o
        // Dial, ainda sem ponte com ninguém.
        $discando = ($c['Application'] ?? '') === 'Dial' && ($c['BridgeId'] ?? '') === '';
        $estado = match (true) {
            ($c['ChannelStateDesc'] ?? '') === 'Ringing' => 'tocando',
            ($c['ChannelStateDesc'] ?? '') === 'Ring', $discando => 'chamando',
            $estadoDispositivo === 'ONHOLD' => 'espera',
            default => 'emchamada',
        };

        $limpo = static fn (?string $v): ?string =>
            $v === null || $v === '' || $v === '<unknown>' ? null : $v;
        $outroNum = $limpo($c['ConnectedLineNum'] ?? null);
        $outroNome = $limpo($c['ConnectedLineName'] ?? null);
        if ($outroNum === null && $saida) {
            // Discando para fora, antes de atender: o número é o discado.
            $outroNum = $limpo($c['Exten'] ?? null);
            if ($outroNum === 's') {
                $outroNum = null;
            }
        }
        if ($outroNome === $outroNum) {
            $outroNome = null;
        }

        return [
            'estado'       => $estado,
            'direcao'      => $saida ? 'saida' : 'entrada',
            'outro_numero' => $outroNum,
            'outro_nome'   => $outroNome,
            'duracao'      => self::segundos((string) ($c['Duration'] ?? '')),
            'canal'        => (string) ($c['Channel'] ?? ''),
        ];
    }

    /**
     * Para cada canal de aparelho em ponte com outro aparelho, o ramal do
     * outro lado.
     *
     * @param list<array<string,string>> $canais
     * @return array<string,string> canal => ramal
     */
    public static function ramalDoOutroLado(array $canais): array
    {
        $porPonte = [];
        foreach ($canais as $c) {
            if (($c['BridgeId'] ?? '') !== '') {
                $porPonte[$c['BridgeId']][] = (string) ($c['Channel'] ?? '');
            }
        }
        $saida = [];
        foreach ($porPonte as $membros) {
            if (count($membros) !== 2) {
                continue;       // conferência: cada um fica com o que o canal diz
            }
            [$a, $b] = $membros;
            foreach ([[$a, $b], [$b, $a]] as [$eu, $outro]) {
                if (preg_match('#^PJSIP/([^-/]+)-[0-9a-f]+$#', $outro, $m) === 1) {
                    $saida[$eu] = $m[1];
                }
            }
        }

        return $saida;
    }

    /**
     * Os canais de aparelho (PJSIP/<ramal>-xxxx) agrupados por ramal.
     *
     * @param list<array<string,string>> $canais
     * @return array<string, list<array<string,string>>>
     */
    public static function canaisPorRamal(array $canais): array
    {
        $por = [];
        foreach ($canais as $c) {
            if (preg_match('#^PJSIP/([^-/]+)-[0-9a-f]+$#', (string) ($c['Channel'] ?? ''), $m) === 1) {
                $por[$m[1]][] = $c;
            }
        }

        return $por;
    }

    /** @return array<string,true>|null ramais em não perturbe na base do Asterisk */
    public static function dndDaBase(string $saida): ?array
    {
        if (stripos($saida, 'Success') === false && !str_contains($saida, 'results found')) {
            return null;
        }
        $lista = [];
        if (preg_match_all('#/dnd/([^\s:]+)\s*:#', $saida, $m) > 0) {
            foreach ($m[1] as $numero) {
                $lista[$numero] = true;
            }
        }

        return $lista;
    }

    /** @param list<array<string,string>> $canais */
    private static function canalParaTransferir(array $canais): ?string
    {
        foreach ($canais as $c) {
            if (($c['ChannelStateDesc'] ?? '') === 'Up' && ($c['BridgeId'] ?? '') !== '') {
                return (string) $c['Channel'];
            }
        }

        return null;
    }

    /** @param list<array<string,string>> $canais */
    private static function outraPonta(array $canais, string $meu): ?string
    {
        $ponte = '';
        foreach ($canais as $c) {
            if (($c['Channel'] ?? '') === $meu) {
                $ponte = (string) ($c['BridgeId'] ?? '');
            }
        }
        if ($ponte === '') {
            return null;
        }
        foreach ($canais as $c) {
            if (($c['BridgeId'] ?? '') === $ponte && ($c['Channel'] ?? '') !== $meu) {
                return (string) $c['Channel'];
            }
        }

        return null;
    }

    private static function contextoDoRamal(string $numero): string
    {
        $r = Bd::um('SELECT contexto, contexto_custom FROM ramais WHERE numero = ?', [$numero]);
        $ctx = (string) (($r['contexto_custom'] ?? '') ?: ($r['contexto'] ?? ''));

        return $ctx !== '' ? $ctx : 'interno';
    }

    private static function segundos(string $hms): int
    {
        if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $hms, $m) === 1) {
            return (int) $m[1] * 3600 + (int) $m[2] * 60 + (int) $m[3];
        }

        return is_numeric($hms) ? (int) $hms : 0;
    }
}
