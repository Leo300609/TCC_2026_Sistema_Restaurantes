<?php
declare(strict_types=1);

/**
 * Base comum das exportações (PDF, Word, Excel e CSV): confere a permissão,
 * lê o período (?de=AAAA-MM-DD&ate=AAAA-MM-DD) e busca os dados nas procedures.
 */
require_once __DIR__ . '/../lib/sessao.php';
require_once __DIR__ . '/../lib/bd.php';
require_once __DIR__ . '/../lib/web.php';

iniciar_sessao();
$permExport = permissoes((string) ($_SESSION['usuario_nivel'] ?? ''), (string) ($_SESSION['usuario_cargo'] ?? ''));
if (!isset($_SESSION['usuario_id']) || !$permExport['relatorios']) {
    http_response_code(403);
    exit('Acesso negado: somente administradores e gerentes exportam relatórios.');
}

$dataValida = static fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1 && strtotime($d) !== false;
$de = (string) ($_GET['de'] ?? '');
$ate = (string) ($_GET['ate'] ?? '');
if (!$dataValida($de) || !$dataValida($ate)) {
    $de = date('Y-m-d', strtotime('-29 days'));
    $ate = date('Y-m-d');
}
if ($de > $ate) {
    [$de, $ate] = [$ate, $de];
}

try {
    $rel = [
        'de' => $de,
        'ate' => $ate,
        'empresa' => chamar_um('sp_empresa_obter') ?? ['nome_fantasia' => config('app_nome')],
        'resumo' => chamar_um('sp_rel_resumo', [$de, $ate]),
        'produtos' => chamar('sp_rel_produtos', [$de, $ate, 30]),
        'formas' => chamar('sp_rel_formas', [$de, $ate]),
        'tipos' => chamar('sp_rel_tipos', [$de, $ate]),
        'atendentes' => chamar('sp_rel_atendentes', [$de, $ate]),
        'vendas' => chamar('sp_rel_vendas_lista', [$de, $ate]),
        'gerado_por' => (string) ($_SESSION['usuario_nome'] ?? ''),
        'gerado_em' => date('d/m/Y H:i'),
    ];
} catch (Throwable $e) {
    error_log('[tcc] exportação: ' . $e->getMessage());
    http_response_code(500);
    exit('Não foi possível gerar o relatório. Confira se o banco está ligado e instalado.');
}

$r = $rel['resumo'];
$rel['ticket'] = (int) $r['pedidos'] ? (float) $r['faturamento'] / (int) $r['pedidos'] : 0.0;
$rel['lucro_bruto'] = (float) $r['faturamento'] - (float) $r['cmv'];
$rel['resultado'] = $rel['lucro_bruto'] - (float) $r['despesas_operacionais'];
$rel['nome_arquivo'] = 'relatorio_xtec_' . $de . '_a_' . $ate;

/** Linhas do quadro de indicadores, na mesma ordem em todos os formatos. */
function indicadores_relatorio(array $rel): array
{
    $r = $rel['resumo'];
    return [
        ['Faturamento', moeda($r['faturamento'])],
        ['Pedidos entregues', numero($r['pedidos'])],
        ['Ticket médio', moeda($rel['ticket'])],
        ['Itens vendidos', numero($r['itens'])],
        ['Descontos concedidos', moeda($r['descontos'])],
        ['Cancelamentos', numero($r['cancelados']) . ' (' . moeda($r['valor_cancelado']) . ')'],
        ['Custo dos insumos (estimado)', moeda($r['cmv'])],
        ['Lucro bruto', moeda($rel['lucro_bruto'])],
        ['Despesas operacionais pagas', moeda($r['despesas_operacionais'])],
        ['Resultado estimado', moeda($rel['resultado'])],
    ];
}

registrar_atividade('relatorios', 'exportar', 'Exportou relatório de ' . data_br($de) . ' a ' . data_br($ate) . ' (' . basename((string) $_SERVER['SCRIPT_NAME'], '.php') . ')');
