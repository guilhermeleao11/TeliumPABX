<?php
declare(strict_types=1);

namespace Telium\Dominio;

use Telium\Suporte\Bd;

/**
 * As contas de acesso ao console — e o que não se pode fazer com elas.
 *
 * O cadastro de usuários passava pelo CRUD genérico, que confere campo
 * por campo e não enxerga o resto do sistema. Em produção isso significa
 * que quatro operações rotineiras terminavam num console sem dono:
 *
 *   · excluir a conta que administra (inclusive a própria, com um clique);
 *   · marcar essa conta como inativa;
 *   · movê-la para um perfil que não administra nada;
 *   · tirar "Usuários" da matriz do único perfil que o tinha.
 *
 * Nenhuma das quatro dá erro. A tela responde "Usuário atualizado", a
 * pessoa sai, e na volta ninguém entra: a recuperação é ir ao banco pelo
 * terminal do servidor. Numa central revendida, isso é uma visita.
 *
 * Aqui mora a resposta para "pode?". Quem grava é o CRUD; quem diz não
 * é esta classe.
 */
final class Usuarios
{
    /** Os três estados que a coluna aceita. */
    public const ESTADOS = ['ativo', 'inativo', 'bloqueado'];

    /** O módulo que abre o gerenciador de contas. */
    private const MODULO = 'admin.usuarios';

    /**
     * A conferência que o CRUD genérico chama antes de gravar ou apagar.
     *
     * @param  array<string,mixed> $dados campos enviados
     * @param  array<string,mixed> $atual linha como está hoje (vazia na criação)
     * @param  array<string,mixed> $eu    quem está pedindo
     * @return array{mensagem:string, campo?:string, codigo?:int}|null
     */
    public static function conferir(string $acao, array $dados, array $atual, array $eu): ?array
    {
        if ($acao === 'excluir') {
            return self::podeExcluir($atual, $eu);
        }

        $campo = self::conferirCampos($dados, $atual);
        if ($campo !== null) {
            return $campo;
        }

        return $acao === 'editar' ? self::podeEditar($dados, $atual, $eu) : null;
    }

    // ------------------------------------------------------------------
    // Os campos
    // ------------------------------------------------------------------

    /**
     * O que vale para uma conta, venha ela da criação ou da edição.
     *
     * A criação já conferia o formato do login; a edição, não — e é a
     * mesma coluna. Um PUT com usuario vazio ou com espaço no meio
     * gravava sem reclamar e a conta parava de conseguir entrar, porque
     * o login recusa os dois na porta.
     *
     * @param  array<string,mixed> $dados
     * @param  array<string,mixed> $atual
     * @return array{mensagem:string, campo?:string, codigo?:int}|null
     */
    public static function conferirCampos(array $dados, array $atual = []): ?array
    {
        if (array_key_exists('usuario', $dados)) {
            $login = trim((string) $dados['usuario']);
            if (!self::loginValido($login)) {
                return [
                    'mensagem' => 'O usuário de login aceita letras, números, ponto, hífen e '
                                . 'sublinhado (3 a 64 caracteres).',
                    'campo' => 'usuario',
                    'codigo' => 422,
                ];
            }
        }

        if (array_key_exists('email', $dados)) {
            $email = trim((string) ($dados['email'] ?? ''));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['mensagem' => 'E-mail em formato inválido.', 'campo' => 'email', 'codigo' => 422];
            }
        }

        if (array_key_exists('status', $dados)
            && !in_array((string) $dados['status'], self::ESTADOS, true)) {
            return [
                'mensagem' => 'Estado inválido. Use ativo, inativo ou bloqueado.',
                'campo' => 'status',
                'codigo' => 422,
            ];
        }

        if (array_key_exists('perfil_id', $dados)) {
            $perfil = (int) $dados['perfil_id'];
            if ($perfil <= 0 || (int) Bd::valor('SELECT COUNT(*) FROM perfis WHERE id = ?', [$perfil]) === 0) {
                return [
                    'mensagem' => 'Escolha um perfil de acesso válido.',
                    'campo' => 'perfil_id',
                    'codigo' => 422,
                ];
            }
        }

        return self::conferirRamal($dados, $atual);
    }

    /** Três a sessenta e quatro, sem espaço e sem acento. */
    public static function loginValido(string $login): bool
    {
        return preg_match('/^[a-z0-9._-]{3,64}$/i', $login) === 1;
    }

    /**
     * O ramal vinculado existe, e é só desta pessoa?
     *
     * O portal do usuário resolve tudo por esta coluna: as chamadas que
     * ele vê, o correio de voz que ele ouve, o ramal que o discador faz
     * tocar. Duas contas com o mesmo ramal é uma pessoa ouvindo o recado
     * da outra — e ninguém descobre isso por acaso. Um ramal que não
     * existe é mais simples e igualmente calado: o portal abre vazio e o
     * discador diz que o telefone não está registrado.
     *
     * @param  array<string,mixed> $dados
     * @param  array<string,mixed> $atual
     * @return array{mensagem:string, campo?:string, codigo?:int}|null
     */
    private static function conferirRamal(array $dados, array $atual): ?array
    {
        if (!array_key_exists('ramal', $dados)) {
            return null;
        }

        $ramal = trim((string) ($dados['ramal'] ?? ''));
        if ($ramal === '') {
            return null;                       // conta sem ramal é normal
        }

        if ((int) Bd::valor('SELECT COUNT(*) FROM ramais WHERE numero = ?', [$ramal]) === 0) {
            return [
                'mensagem' => "O ramal {$ramal} não está cadastrado. Cadastre-o em Conectividade > "
                            . 'Ramais antes de vincular, ou deixe o campo em branco.',
                'campo' => 'ramal',
                'codigo' => 422,
            ];
        }

        $dono = Bd::um(
            'SELECT nome, usuario FROM usuarios WHERE ramal = ? AND id <> ? LIMIT 1',
            [$ramal, (int) ($atual['id'] ?? 0)]
        );
        if ($dono !== null) {
            return [
                'mensagem' => "O ramal {$ramal} já está vinculado a {$dono['nome']} ({$dono['usuario']}). "
                            . 'Duas contas no mesmo ramal veem as chamadas e ouvem o correio de voz '
                            . 'uma da outra.',
                'campo' => 'ramal',
                'codigo' => 409,
            ];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Quem administra
    // ------------------------------------------------------------------

    /**
     * O perfil abre o gerenciador de contas E pode gravar nele?
     *
     * Enxergar a tela sem poder editar não conserta nada: quem ficasse
     * só com isso veria a lista de usuários e não conseguiria reativar
     * ninguém. Para efeito de "ainda há quem administre", só conta o
     * perfil que faz as duas coisas.
     */
    public static function perfilAdministra(int $perfilId): bool
    {
        // Duas consultas por perfil, e a contagem percorre TODAS as
        // contas ativas: numa central com trezentos usuários no mesmo
        // perfil seriam seiscentas consultas para salvar um setor.
        // Perfis são poucos e não mudam no meio de uma requisição.
        static $lembrado = [];

        if (!array_key_exists($perfilId, $lembrado)) {
            $p = Permissoes::doPerfil($perfilId);
            $lembrado[$perfilId] = Permissoes::podeModulo($p['allow'], self::MODULO)
                                && Permissoes::podeAcao($p['caps'], 'editar');
        }

        return $lembrado[$perfilId];
    }

    /**
     * Quantas contas ATIVAS ainda administrariam, tirando uma da conta.
     *
     * @param int $exceto id que não deve ser contado (0 = conta todas)
     */
    public static function administradores(int $exceto = 0): int
    {
        $quantos = 0;
        foreach (Bd::todos('SELECT id, perfil_id FROM usuarios WHERE status = ?', ['ativo']) as $u) {
            if ((int) $u['id'] === $exceto) {
                continue;
            }
            if (self::perfilAdministra((int) $u['perfil_id'])) {
                $quantos++;
            }
        }

        return $quantos;
    }

    /**
     * A mudança tira a última pessoa capaz de administrar?
     *
     * @param  array<string,mixed> $dados
     * @param  array<string,mixed> $atual
     * @param  array<string,mixed> $eu
     * @return array{mensagem:string, campo?:string, codigo?:int}|null
     */
    private static function podeEditar(array $dados, array $atual, array $eu): ?array
    {
        $id = (int) ($atual['id'] ?? 0);
        $souEu = $id > 0 && $id === (int) ($eu['id'] ?? 0);

        $novoStatus = (string) ($dados['status'] ?? $atual['status'] ?? 'ativo');
        $novoPerfil = (int) ($dados['perfil_id'] ?? $atual['perfil_id'] ?? 0);

        // A própria conta é caso à parte, e não por cerimônia: quem se
        // rebaixa ou se desliga perde o acesso NA HORA, e a tela que
        // desfaria isso é justamente a que ele acabou de fechar para si.
        // Outro administrador faz em dois cliques; sozinho, é banco de
        // dados pelo terminal do servidor.
        if ($souEu && $novoPerfil !== (int) $atual['perfil_id']) {
            return [
                'mensagem' => 'Você não pode trocar o perfil da sua própria conta. '
                            . 'Peça a outro administrador — é o que impede alguém de se '
                            . 'trancar para fora sem perceber.',
                'campo' => 'perfil_id',
            ];
        }
        if ($souEu && $novoStatus !== 'ativo') {
            return [
                'mensagem' => 'Você não pode desativar nem bloquear a sua própria conta.',
                'campo' => 'status',
            ];
        }

        $eraAdmin = (string) ($atual['status'] ?? '') === 'ativo'
                 && self::perfilAdministra((int) ($atual['perfil_id'] ?? 0));
        $seraAdmin = $novoStatus === 'ativo' && self::perfilAdministra($novoPerfil);

        if ($eraAdmin && !$seraAdmin && self::administradores($id) === 0) {
            return [
                'mensagem' => sprintf(
                    '%s é a última conta capaz de administrar usuários. %s deixaria o console '
                    . 'sem ninguém para criar contas, redefinir senha ou reativar acesso — e '
                    . 'voltar atrás só pelo banco de dados, no terminal do servidor.',
                    (string) ($atual['nome'] ?? $atual['usuario'] ?? 'Esta conta'),
                    $novoStatus !== 'ativo' ? 'Desativá-la' : 'Movê-la para um perfil sem esse acesso'
                ),
                'campo' => $novoStatus !== 'ativo' ? 'status' : 'perfil_id',
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed> $atual
     * @param  array<string,mixed> $eu
     * @return array{mensagem:string, campo?:string, codigo?:int}|null
     */
    private static function podeExcluir(array $atual, array $eu): ?array
    {
        $id = (int) ($atual['id'] ?? 0);

        if ($id > 0 && $id === (int) ($eu['id'] ?? 0)) {
            return ['mensagem' => 'Você não pode excluir a sua própria conta.'];
        }

        $eraAdmin = (string) ($atual['status'] ?? '') === 'ativo'
                 && self::perfilAdministra((int) ($atual['perfil_id'] ?? 0));

        if ($eraAdmin && self::administradores($id) === 0) {
            return [
                'mensagem' => sprintf(
                    '%s é a última conta capaz de administrar usuários. Excluí-la deixaria o '
                    . 'console sem ninguém para criar contas ou redefinir senha, e voltar atrás '
                    . 'só pelo banco de dados, no terminal do servidor.',
                    (string) ($atual['nome'] ?? $atual['usuario'] ?? 'Esta conta')
                ),
            ];
        }

        return null;
    }

    /**
     * A matriz nova do perfil deixa alguém administrando?
     *
     * O mesmo buraco pela outra porta: em vez de mexer na conta, tira-se
     * "Usuários" (ou a ação de editar) do perfil em que ela está. O
     * efeito é idêntico e a tela é outra, então a conferência precisa
     * existir nas duas.
     *
     * @param  list<string> $allow módulos que ficariam
     * @param  list<string> $caps  ações que ficariam
     */
    public static function matrizDeixaAdministrador(int $perfilId, array $allow, array $caps): bool
    {
        if (Permissoes::podeModulo($allow, self::MODULO) && Permissoes::podeAcao($caps, 'editar')) {
            return true;           // o próprio perfil continua administrando
        }

        // Alguma conta ativa FORA deste perfil ainda administra?
        foreach (Bd::todos('SELECT perfil_id FROM usuarios WHERE status = ?', ['ativo']) as $u) {
            if ((int) $u['perfil_id'] !== $perfilId && self::perfilAdministra((int) $u['perfil_id'])) {
                return true;
            }
        }

        // Nenhuma conta ativa neste perfil? Então tirar o acesso dele não
        // tranca ninguém para fora — é um perfil vazio.
        return (int) Bd::valor(
            'SELECT COUNT(*) FROM usuarios WHERE perfil_id = ? AND status = ?',
            [$perfilId, 'ativo']
        ) === 0;
    }
}
