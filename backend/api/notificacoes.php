<?php
declare(strict_types=1);

/** Notificações do usuário logado (pedidos atrasados, estoque, validade e contas). */
require __DIR__ . '/_api.php';

try {
    $itens = chamar('sp_notificacoes', [(int) $_SESSION['usuario_id'], $perm['estoque'] ? 1 : 0, $perm['financeiro'] ? 1 : 0]);
    json_saida([
        'total' => count($itens),
        'itens' => array_map(static fn($n) => [
            'tipo' => $n['tipo'],
            'nivel' => $n['nivel'],
            'titulo' => $n['titulo'],
            'detalhe' => $n['detalhe'],
            'link' => $n['link'],
        ], array_slice($itens, 0, 25)),
    ]);
} catch (Throwable $e) {
    error_log('[tcc] notificacoes: ' . $e->getMessage());
    json_saida(['erro' => 'Não foi possível carregar as notificações.', 'total' => 0, 'itens' => []], 500);
}
