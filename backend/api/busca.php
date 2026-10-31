<?php
declare(strict_types=1);

/** Busca global da paleta de comandos (Ctrl + K): clientes, produtos, pedidos e fornecedores. */
require __DIR__ . '/_api.php';

$termo = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
if (mb_strlen($termo) < 2) {
    json_saida(['itens' => []]);
}

try {
    $itens = [];
    foreach (chamar('sp_busca_global', [$termo, $perm['financeiro'] ? 1 : 0]) as $r) {
        $link = match ($r['tipo']) {
            'CLIENTE' => 'clientes.php?editar=' . (int) $r['id'],
            'PEDIDO' => in_array($r['extra'], ['NOVO', 'PREPARO', 'PRONTO'], true)
                ? 'pedidos.php?editar=' . (int) $r['id'] . '#formulario'
                : 'pedidos.php?detalhes=' . (int) $r['id'],
            'FORNECEDOR' => 'fornecedores.php?editar=' . (int) $r['id'],
            default => $r['extra'] === 'INSUMO'
                ? ($perm['estoque'] ? 'estoque.php?editar=' . (int) $r['id'] : 'estoque.php')
                : 'cardapios.php?q=' . rawurlencode($r['titulo']),
        };
        $itens[] = ['tipo' => $r['tipo'], 'titulo' => $r['titulo'], 'detalhe' => $r['detalhe'], 'link' => $link];
    }
    json_saida(['itens' => $itens]);
} catch (Throwable $e) {
    error_log('[tcc] busca: ' . $e->getMessage());
    json_saida(['erro' => 'Não foi possível buscar agora.', 'itens' => []], 500);
}
