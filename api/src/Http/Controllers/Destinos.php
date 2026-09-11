<?php
declare(strict_types=1);

namespace Telium\Http\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Telium\Suporte\Ami;
use Telium\Suporte\Bd;
use Telium\Suporte\Resposta;

/**
 * Apoio aos destinos personalizados.
 *
 * O CRUD em si é o genérico; o que falta é conferir se o contexto
 * apontado existe de fato no dialplan. Apontar para um contexto que
 * ninguém escreveu é o erro clássico aqui, e ele só apareceria na
 * primeira chamada perdida.
 */
final class Destinos
{
    /** GET /api/destinos/contextos */
    public function contextos(Request $req, Response $res): Response
    {
        $saida = Ami::tentarComando('dialplan show');

        if ($saida === null) {
            return Resposta::json($res, [
                'disponivel' => false,
                'contextos' => [],
                'detalhe' => 'Sem comunicação com o Asterisk agora — não dá para conferir os contextos.',
                'destinos' => $this->comEstado([], false),
            ]);
        }

        // "[ Context 'telium-custom' created by 'pbx_config' ]"
        preg_match_all("/\[\s*Context\s+'([^']+)'/", $saida, $m);
        $contextos = array_values(array_unique($m[1] ?? []));
        sort($contextos);

        return Resposta::json($res, [
            'disponivel' => true,
            'contextos' => $contextos,
            'destinos' => $this->comEstado($contextos, true),
        ]);
    }

    /**
     * Cada destino cadastrado com a informação de se o contexto existe.
     *
     * @param string[] $contextos
     */
    private function comEstado(array $contextos, bool $confiavel): array
    {
        $destinos = Bd::todos('SELECT id, nome, contexto, extensao, prioridade, ativo
                                 FROM destinos_personalizados ORDER BY nome');

        foreach ($destinos as &$d) {
            $d['contexto_existe'] = $confiavel ? in_array($d['contexto'], $contextos, true) : null;
        }

        return $destinos;
    }
}
