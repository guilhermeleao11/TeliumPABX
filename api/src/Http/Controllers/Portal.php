<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Dominio\Auditoria;
use Telium\Suporte\Ambiente;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Portal do usuário: cada um cuida do próprio ramal.
 *
 * Nenhuma rota daqui aceita um identificador vindo do cliente. O ramal
 * sai sempre da sessão — é o que impede que trocar um número na barra
 * de endereço mostre o correio de voz do vizinho.
 */
final class Portal
{
    /** Campos que o dono do ramal pode mexer sem passar pelo administrador. */
    private const MEUS_CAMPOS = [
        'dnd'            => 'bool',
        'siga_me'        => 'numero',
        'siga_me_ativo'  => 'bool',
        'siga_me_modo'   => ['junto', 'depois'],
        'siga_me_confirmar' => 'bool',
        'chamada_espera' => 'bool',
        'tempo_toque'    => 'inteiro',
        'vm_email'       => 'bool',
        'email'          => 'email',
    ];

    // ---------------------------------------------------------------
    /** GET /api/me/ramal */
    public function ramal(Request $req, Response $res): Response
    {
        $r = $this->meuRamal($req);
        if ($r === null) {
            return $this->semRamal($res);
        }

        return Resposta::json($res, [
            'disponivel' => true,
            'ramal'      => $this->publico($r),
            'registro'   => $this->registro((string) $r['numero']),
        ]);
    }

    /** PUT /api/me/ramal — só o que é do próprio usuário. */
    public function salvarRamal(Request $req, Response $res): Response
    {
        $r = $this->meuRamal($req);
        if ($r === null) {
            return $this->semRamal($res);
        }

        $corpo = (array) $req->getParsedBody();
        $mudar = [];

        foreach (self::MEUS_CAMPOS as $campo => $tipo) {
            if (!array_key_exists($campo, $corpo)) {
                continue;
            }
            $valor = $corpo[$campo];

            if (is_array($tipo)) {
                if (!in_array((string) $valor, $tipo, true)) {
                    return Resposta::erro($res, "Valor não aceito em \"{$campo}\"", 422,
                                          ['campo' => $campo]);
                }
                $mudar[$campo] = (string) $valor;
                continue;
            }

            $mudar[$campo] = match ($tipo) {
                'bool'    => (int) (bool) $valor,
                'inteiro' => max(5, min(120, (int) $valor)),
                'numero'  => $this->soDiscavel((string) $valor),
                'email'   => $this->email($res, (string) $valor),
                default   => (string) $valor,
            };

            if ($mudar[$campo] instanceof Response) {
                return $mudar[$campo];
            }
        }

        if ($mudar === []) {
            return Resposta::erro($res, 'Nenhum campo válido enviado', 422);
        }

        $sets = implode(', ', array_map(static fn (string $c): string => "`{$c}` = ?", array_keys($mudar)));
        Bd::executar(
            "UPDATE ramais SET {$sets} WHERE id = ?",
            [...array_values($mudar), $r['id']]
        );

        // Siga-me e não perturbe valem em tempo de chamada pela base do
        // Asterisk. Escrever lá na hora evita o usuário ter de esperar
        // alguém aplicar a configuração para o desvio funcionar.
        $this->refletirNoAsterisk((string) $r['numero'], array_merge($r, $mudar));

        Bd::executar(
            "INSERT INTO sistema (chave, valor) VALUES ('config_pendente','1')
             ON DUPLICATE KEY UPDATE valor = '1'"
        );
        Auditoria::registrar($req->getAttribute('usuario'), 'editar', 'pcu.meuramal',
                             (string) $r['numero'], array_keys($mudar));

        $novo = Bd::um('SELECT * FROM ramais WHERE id = ?', [$r['id']]);

        return Resposta::json($res, [
            'disponivel' => true,
            'ramal'      => $this->publico((array) $novo),
            'registro'   => $this->registro((string) $r['numero']),
        ]);
    }

    // ---------------------------------------------------------------
    /** GET /api/me/chamadas — só as do meu ramal. */
    public function chamadas(Request $req, Response $res): Response
    {
        $r = $this->meuRamal($req);
        if ($r === null) {
            return $this->semRamal($res);
        }

        $n = (string) $r['numero'];
        $p = $req->getQueryParams();
        $limite = min(200, max(1, (int) ($p['limite'] ?? 50)));
        $pagina = max(1, (int) ($p['pagina'] ?? 1));

        $onde = ['(c.src = ? OR c.dst = ?)'];
        $args = [$n, $n];

        if (in_array($p['direcao'] ?? '', ['entrada', 'saida', 'interna'], true)) {
            $onde[] = 'c.direcao = ?';
            $args[] = $p['direcao'];
        }
        if (($p['de'] ?? '') !== '') {
            $onde[] = 'c.calldate >= ?';
            $args[] = $p['de'] . ' 00:00:00';
        }
        if (($p['ate'] ?? '') !== '') {
            $onde[] = 'c.calldate <= ?';
            $args[] = $p['ate'] . ' 23:59:59';
        }

        $where = implode(' AND ', $onde);
        $total = (int) Bd::valor("SELECT COUNT(*) FROM cdr c WHERE {$where}", $args);

        $linhas = Bd::todos(
            "SELECT c.calldate, c.src, c.dst, c.direcao, c.disposition,
                    c.duration, c.billsec, c.gravacao
               FROM cdr c WHERE {$where}
              ORDER BY c.calldate DESC
              LIMIT {$limite} OFFSET " . (($pagina - 1) * $limite),
            $args
        );

        foreach ($linhas as &$l) {
            // Quem discou define o sentido do ponto de vista do usuário.
            $l['sentido'] = ((string) $l['src'] === $n) ? 'feita' : 'recebida';
            $l['outro'] = ((string) $l['src'] === $n) ? $l['dst'] : $l['src'];
            $l['tem_gravacao'] = ($l['gravacao'] ?? '') !== '';
            unset($l['gravacao']);          // o caminho não vai para a tela
        }
        unset($l);

        return Resposta::json($res, [
            'disponivel' => true,
            'dados'  => $linhas,
            'total'  => $total,
            'pagina' => $pagina,
            'limite' => $limite,
            'ramal'  => $n,
        ]);
    }

    // ---------------------------------------------------------------
    /** GET /api/me/correiovoz */
    public function correioVoz(Request $req, Response $res): Response
    {
        $r = $this->meuRamal($req);
        if ($r === null) {
            return $this->semRamal($res);
        }
        if ((int) $r['voicemail'] !== 1) {
            return Resposta::json($res, [
                'disponivel' => true,
                'ativo' => false,
                'motivo' => 'O correio de voz não está ligado neste ramal. '
                          . 'Peça ao administrador para ativá-lo.',
                'pastas' => [],
            ]);
        }

        $base = $this->caixa((string) $r['numero']);
        $pastas = [];

        foreach (['INBOX' => 'Novas', 'Old' => 'Ouvidas', 'Urgent' => 'Urgentes'] as $dir => $rotulo) {
            $pastas[] = [
                'pasta'     => $dir,
                'rotulo'    => $rotulo,
                'mensagens' => $this->mensagens("{$base}/{$dir}"),
            ];
        }

        return Resposta::json($res, [
            'disponivel' => true,
            'ativo'  => true,
            'ramal'  => $r['numero'],
            'email'  => $r['email'],
            'copia_por_email' => (int) $r['vm_email'] === 1,
            'pastas' => $pastas,
        ]);
    }

    /** GET /api/me/correiovoz/audio?pasta=INBOX&id=msg0000 */
    public function audioCorreio(Request $req, Response $res): Response
    {
        $r = $this->meuRamal($req);
        if ($r === null) {
            return $this->semRamal($res);
        }

        $p = $req->getQueryParams();
        $arquivo = $this->arquivoDaMensagem(
            (string) $r['numero'],
            (string) ($p['pasta'] ?? ''),
            (string) ($p['id'] ?? '')
        );
        if ($arquivo === null) {
            return Resposta::erro($res, 'Mensagem não encontrada', 404);
        }

        $corpo = $res->getBody();
        $corpo->write((string) file_get_contents($arquivo));

        return $res->withBody($corpo)
            ->withHeader('Content-Type', 'audio/wav')
            ->withHeader('Content-Disposition',
                'inline; filename="' . basename($arquivo) . '"');
    }

    /** DELETE /api/me/correiovoz — apaga uma mensagem da própria caixa. */
    public function apagarCorreio(Request $req, Response $res): Response
    {
        $r = $this->meuRamal($req);
        if ($r === null) {
            return $this->semRamal($res);
        }

        $p = $req->getQueryParams();
        $pasta = (string) ($p['pasta'] ?? '');
        $id = (string) ($p['id'] ?? '');
        $arquivo = $this->arquivoDaMensagem((string) $r['numero'], $pasta, $id);
        if ($arquivo === null) {
            return Resposta::erro($res, 'Mensagem não encontrada', 404);
        }

        // O app_voicemail guarda o áudio em vários formatos e um .txt
        // com os dados da chamada; tem de sair tudo junto.
        $semExtensao = preg_replace('/\.[^.]+$/', '', $arquivo) ?? $arquivo;
        foreach (glob($semExtensao . '.*') ?: [] as $f) {
            @unlink($f);
        }

        Auditoria::registrar($req->getAttribute('usuario'), 'excluir', 'pcu.correiovoz',
                             "{$r['numero']}/{$pasta}/{$id}");

        return Resposta::json($res, ['removido' => true]);
    }

    // ---------------------------------------------------------------
    private function meuRamal(Request $req): ?array
    {
        $eu = (array) $req->getAttribute('usuario');
        $numero = trim((string) ($eu['ramal'] ?? ''));
        if ($numero === '') {
            return null;
        }

        return Bd::um('SELECT * FROM ramais WHERE numero = ?', [$numero]);
    }

    /**
     * Conta sem ramal não é erro: é um estado normal do sistema —
     * o administrador, por exemplo, costuma não ter ramal.
     *
     * Por isso responde 200 com o motivo, e não 404: um 404 aqui
     * aparece como requisição falha no console do navegador e em
     * qualquer monitoramento, sugerindo defeito onde não há.
     */
    private function semRamal(Response $res): Response
    {
        return Resposta::json($res, [
            'disponivel' => false,
            'motivo' => 'A sua conta não está vinculada a nenhum ramal. '
                      . 'Peça ao administrador para fazer o vínculo em Gerenciador de Usuários.',
        ]);
    }

    /** O que o dono do ramal pode ver — sem senha SIP nem PIN. */
    private function publico(array $r): array
    {
        return [
            'numero'         => $r['numero'] ?? null,
            'nome'           => $r['nome'] ?? null,
            'setor'          => $r['setor'] ?? null,
            'email'          => $r['email'] ?? null,
            'dnd'            => (int) ($r['dnd'] ?? 0),
            'siga_me'        => $r['siga_me'] ?? null,
            'siga_me_ativo'  => (int) ($r['siga_me_ativo'] ?? 0),
            'siga_me_modo'   => $r['siga_me_modo'] ?? 'junto',
            'siga_me_confirmar' => (int) ($r['siga_me_confirmar'] ?? 0),
            'chamada_espera' => (int) ($r['chamada_espera'] ?? 1),
            'tempo_toque'    => (int) ($r['tempo_toque'] ?? 20),
            'voicemail'      => (int) ($r['voicemail'] ?? 0),
            'vm_email'       => (int) ($r['vm_email'] ?? 0),
            'gravar'         => $r['gravar'] ?? 'nao',
            'webrtc'         => (int) ($r['webrtc'] ?? 0),
        ];
    }

    /** O aparelho está registrado agora? Quem responde é o Asterisk. */
    private function registro(string $numero): array
    {
        $saida = Ami::tentarComando("pjsip show endpoint {$numero}");
        if ($saida === null) {
            return ['disponivel' => false, 'detalhe' => 'Sem comunicação com a central agora.'];
        }

        $contatos = [];
        foreach (explode("\n", $saida) as $linha) {
            // Contact:  1001/sip:1001@10.0.0.5:5060   abc123 Avail  12.345
            if (preg_match('/Contact:\s+\S+\/(\S+)\s+\S+\s+(\S+)\s+(\S+)/', $linha, $m) === 1) {
                $contatos[] = [
                    'uri'    => $m[1],
                    'estado' => $m[2],
                    'atraso' => is_numeric($m[3]) ? (float) $m[3] : null,
                ];
            }
        }

        return [
            'disponivel'  => true,
            'registrado'  => $contatos !== [],
            'dispositivos' => $contatos,
        ];
    }

    /**
     * Reflete na base do Asterisk o que vale em tempo de chamada.
     *
     * O sub-ramal consulta estas chaves a cada ligação. Sem escrever
     * aqui, ligar o siga-me no portal só valeria depois de alguém
     * aplicar a configuração — que é justamente o que o portal existe
     * para evitar.
     */
    private function refletirNoAsterisk(string $numero, array $r): void
    {
        try {
            $ami = Ami::compartilhada();
        } catch (\Throwable) {
            return;     // sem central no ar, o banco já guardou; aplica depois
        }

        $ligado = static fn (string $familia, bool $tem, string $valor = '1'): array => $tem
            ? ['Action' => 'DBPut', 'Family' => $familia, 'Key' => $valor === '' ? '1' : $valor]
            : ['Action' => 'DBDel', 'Family' => $familia];

        $sigaMe = (int) ($r['siga_me_ativo'] ?? 0) === 1 ? trim((string) ($r['siga_me'] ?? '')) : '';

        $acoes = [
            ['dnd', (int) ($r['dnd'] ?? 0) === 1, '1'],
            ['cw', (int) ($r['chamada_espera'] ?? 1) === 1, '1'],
            ['sigame', $sigaMe !== '', $sigaMe],
            ['sigame-modo', $sigaMe !== '', (string) ($r['siga_me_modo'] ?? 'junto')],
        ];

        foreach ($acoes as [$familia, $tem, $valor]) {
            $ami->acao($tem
                ? ['Action' => 'DBPut', 'Family' => $familia, 'Key' => $numero, 'Val' => $valor]
                : ['Action' => 'DBDel', 'Family' => $familia, 'Key' => $numero]);
        }
    }

    // ---------------------------------------------------------------
    private function caixa(string $numero): string
    {
        $base = rtrim((string) Ambiente::get('VOICEMAIL_DIR', '/var/spool/asterisk/voicemail'), '/');

        return "{$base}/telium/{$numero}";
    }

    /**
     * As mensagens de uma pasta, lidas do .txt que o app_voicemail
     * escreve ao lado do áudio.
     *
     * @return list<array<string,mixed>>
     */
    private function mensagens(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $lista = [];
        foreach (glob("{$dir}/msg*.txt") ?: [] as $txt) {
            $dados = @parse_ini_file($txt) ?: [];
            $id = basename($txt, '.txt');
            $audio = $this->audioDaMensagem($dir, $id);

            $lista[] = [
                'id'       => $id,
                'origem'   => $dados['callerid'] ?? '',
                'quando'   => isset($dados['origtime'])
                    ? date('Y-m-d H:i:s', (int) $dados['origtime'])
                    : null,
                'duracao'  => (int) ($dados['duration'] ?? 0),
                'tem_audio' => $audio !== null,
            ];
        }

        // Mais nova primeiro: é a que interessa.
        usort($lista, static fn (array $a, array $b): int => strcmp((string) $b['quando'], (string) $a['quando']));

        return $lista;
    }

    private function audioDaMensagem(string $dir, string $id): ?string
    {
        foreach (['wav', 'WAV', 'gsm', 'ulaw', 'alaw'] as $ext) {
            if (is_file("{$dir}/{$id}.{$ext}")) {
                return "{$dir}/{$id}.{$ext}";
            }
        }

        return null;
    }

    /**
     * Do par (pasta, id) para um arquivo dentro da própria caixa.
     *
     * Os dois vêm do cliente, então nenhum entra no caminho sem passar
     * por uma lista fechada — é o que impede "../../" virar leitura de
     * qualquer arquivo do servidor.
     */
    private function arquivoDaMensagem(string $numero, string $pasta, string $id): ?string
    {
        if (!in_array($pasta, ['INBOX', 'Old', 'Urgent'], true)) {
            return null;
        }
        if (preg_match('/^msg[0-9]{1,6}$/', $id) !== 1) {
            return null;
        }

        return $this->audioDaMensagem($this->caixa($numero) . '/' . $pasta, $id);
    }

    private function soDiscavel(string $texto): string
    {
        return (string) preg_replace('/[^0-9*#+]/', '', $texto);
    }

    private function email(Response $res, string $valor): mixed
    {
        if ($valor !== '' && !filter_var($valor, FILTER_VALIDATE_EMAIL)) {
            return Resposta::erro($res, 'E-mail em formato inválido', 422, ['campo' => 'email']);
        }

        return $valor;
    }
}
