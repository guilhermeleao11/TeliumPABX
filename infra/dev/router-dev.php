<?php
/**
 * Roteador do servidor embutido do PHP, só para a bancada de
 * desenvolvimento. Em produção quem faz este papel é o nginx.
 *
 *   /api/...  → api/public/index.php
 *   o resto   → arquivos estáticos do console
 */
declare(strict_types=1);

$raiz = '/w';                                    // o repositório, montado no contêiner
$caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($caminho, '/api')) {
    $_SERVER['SCRIPT_NAME'] = '/api/index.php';
    require $raiz . '/api/public/index.php';

    return true;
}

// Fotos de contato são gravadas fora do repositório.
if (str_starts_with($caminho, '/uploads/contatos/')) {
    $arquivo = '/fotos/' . basename($caminho);
    if (is_file($arquivo)) {
        header('Content-Type: ' . (mime_content_type($arquivo) ?: 'application/octet-stream'));
        readfile($arquivo);

        return true;
    }
    http_response_code(404);

    return true;
}

$arquivo = $raiz . $caminho;
if ($caminho !== '/' && is_file($arquivo)) {
    return false;                                // o servidor embutido serve sozinho
}

readfile($raiz . '/index.html');

return true;
