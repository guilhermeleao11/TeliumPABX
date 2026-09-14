<?php
declare(strict_types=1);

namespace Telium\Gerador;

use Telium\Suporte\Ambiente;
use Telium\Suporte\Bd;

/**
 * Classes de música em espera.
 *
 * O cadastro da fila oferecia como música em espera qualquer áudio da
 * categoria "espera", mas o musiconhold.conf só tinha a classe
 * [default]: a fila apontava para uma classe inexistente e o cliente
 * esperava em silêncio, sem nenhum erro no console.
 *
 * O Asterisk toca uma classe a partir de um diretório, não de um
 * arquivo. Então cada áudio de espera ganha um diretório próprio, com o
 * arquivo dentro — é o que permite trocar a música de uma fila sem
 * mexer na de outra.
 */
final class GeradorMusica
{
    /** @return array<string,string> */
    public function gerar(): array
    {
        $b = (new Bloco())
            ->comentario('Gerado pelo Telium PABX — NÃO EDITE À MÃO')
            ->comentario('Gerado em ' . date('d/m/Y H:i:s'))
            ->branco()
            ->comentario('O [default] fica no musiconhold.conf estático, que inclui este arquivo');

        $audios = Bd::todos(
            "SELECT arquivo, nome FROM audios WHERE categoria = 'espera' ORDER BY nome"
        );

        if ($audios === []) {
            $b->branco()->comentario('nenhum áudio na categoria "espera"');

            return ['musicaespera.conf' => $b->texto()];
        }

        foreach ($audios as $a) {
            $classe = $this->classe((string) $a['arquivo']);
            $dir = $this->diretorioDaClasse($classe);
            $this->prepararDiretorio($classe, (string) $a['arquivo']);

            $b->branco()
              ->comentario("{$a['nome']}")
              ->crua("[{$classe}]")
              ->crua('mode = files')
              ->crua("directory = {$dir}")
              ->crua('sort = alpha');
        }

        return ['musicaespera.conf' => $b->texto()];
    }

    /** Nome da classe: o mesmo do arquivo, que é o que a fila guarda. */
    private function classe(string $arquivo): string
    {
        return preg_replace('/[^A-Za-z0-9_-]/', '-', $arquivo) ?? $arquivo;
    }

    private function raiz(): string
    {
        return rtrim((string) Ambiente::get('AUDIOS_DIR', '/var/lib/asterisk/sounds/telium'), '/')
             . '/moh';
    }

    private function diretorioDaClasse(string $classe): string
    {
        return $this->raiz() . '/' . $classe;
    }

    /**
     * Garante o diretório da classe com o áudio dentro.
     *
     * É o único gerador que mexe em arquivo fora do destino dos .conf, e
     * de propósito: só aqui se sabe qual áudio pertence a qual classe, e
     * a classe sem diretório é exatamente o silêncio que se quer evitar.
     */
    private function prepararDiretorio(string $classe, string $arquivo): void
    {
        $dir = $this->diretorioDaClasse($classe);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $origem = rtrim((string) Ambiente::get('AUDIOS_DIR', '/var/lib/asterisk/sounds/telium'), '/');
        foreach (['wav', 'gsm', 'sln', 'ulaw', 'alaw'] as $ext) {
            $de = "{$origem}/{$arquivo}.{$ext}";
            $para = "{$dir}/{$arquivo}.{$ext}";
            if (!is_file($de)) {
                continue;
            }
            // Copia só quando mudou: aplicar configuração não precisa
            // reescrever dezenas de megabytes de áudio a cada clique.
            if (is_file($para) && filemtime($para) >= filemtime($de)) {
                continue;
            }
            @copy($de, $para);
            @chmod($para, 0664);
        }
    }
}
