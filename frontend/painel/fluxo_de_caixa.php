<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/bd.php';
require_once __DIR__ . '/../../backend/lib/web.php';

if (!$verFinanceiro) {
    header('Location: dashboard.php');
    exit;
}

// ---------- ações (POST) ----------
tratar_post(function () {
    $acao = entrada('acao');
    $id = entrada_int('id');

    if ($acao === 'salvar') {
        chamar('sp_fluxo_salvar', [
            $id, entrada('tipo'), entrada('descricao'), entrada('categoria'), entrada_decimal('valor'),
            entrada('data_movimentacao') ?: null, entrada('status', 'PENDENTE'), (int) $_SESSION['usuario_id'],
        ]);
        return [$id ? 'Lançamento atualizado.' : 'Lançamento registrado.', url(['editar' => null, 'cancelar' => null, 'excluir' => null])];
    }
    if ($acao === 'status') {
        $novo = entrada('status');
        chamar('sp_fluxo_status', [$id, entrada('tipo'), entrada('origem'), $novo]);
        return [$novo === 'PAGO' ? 'Lançamento marcado como pago.' : ($novo === 'CANCELADO' ? 'Lançamento cancelado.' : 'Pagamento desfeito.'), url(['cancelar' => null, 'cancelar_tipo' => null, 'cancelar_origem' => null])];
    }
    if ($acao === 'excluir') {
        chamar('sp_fluxo_excluir', [$id]);
        return ['Lançamento excluído.', url(['excluir' => null, 'editar' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
const POR_PAGINA = 10;
$periodo = in_array(entrada('periodo'), ['mes', 'passado', '30dias', 'ano'], true) ? entrada('periodo') : 'mes';
[$de, $ate] = match ($periodo) {
    'passado' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '30dias' => [date('Y-m-d', strtotime('-30 days')), date('Y-m-d')],
    'ano' => [date('Y-01-01'), date('Y-12-31')],
    default => [date('Y-m-01'), date('Y-m-t')],
};
$tipo = in_array(entrada('tipo'), ['ENTRADA', 'SAIDA'], true) ? entrada('tipo') : 'TODOS';
$situacao = in_array(entrada('situacao'), ['PENDENTE', 'PAGO', 'ATRASADO', 'CANCELADO'], true) ? entrada('situacao') : 'TODAS';
$pagina = max(1, entrada_int('pagina', 1));

$resumo = ler(fn() => chamar_um('sp_fluxo_resumo', [$de, $ate]), ['entradas_pagas' => 0, 'saidas_pagas' => 0, 'a_receber' => 0, 'a_pagar' => 0, 'atrasado_valor' => 0, 'atrasado_qtd' => 0]);
$contagem = ler(fn() => chamar_um('sp_fluxo_contagem', [$de, $ate, $situacao]), ['todos' => 0, 'entradas' => 0, 'saidas' => 0]);
$totalFiltro = (int) ($tipo === 'ENTRADA' ? $contagem['entradas'] : ($tipo === 'SAIDA' ? $contagem['saidas'] : $contagem['todos']));
$paginasTotal = max(1, (int) ceil($totalFiltro / POR_PAGINA));
$pagina = min($pagina, $paginasTotal);
$lancamentos = ler(fn() => chamar('sp_fluxo_listar', [$de, $ate, $tipo, $situacao, POR_PAGINA, ($pagina - 1) * POR_PAGINA]));

$semanas = ler(fn() => chamar('sp_fluxo_semanas'));
usort($semanas, static fn($a, $b) => (int) $b['semana'] <=> (int) $a['semana']);
$maior = 1.0;
foreach ($semanas as $s) {
    $maior = max($maior, (float) $s['entrada'], (float) $s['saida']);
}

$saldo = (float) $resumo['entradas_pagas'] - (float) $resumo['saidas_pagas'];

$editarId = entrada_int('editar');
$edit = $editarId > 0 ? ler(fn() => chamar_um('sp_fluxo_obter', [$editarId]), null) : null;
$f = $edit ?? ['id' => 0, 'tipo' => 'ENTRADA', 'descricao' => '', 'categoria' => '', 'valor' => '', 'data_movimentacao' => date('Y-m-d'), 'status' => 'PENDENTE'];

$cancelarId = entrada_int('cancelar');
$excluirId = entrada_int('excluir');

$situacoes = [
    'PENDENTE' => ['pendente', 'Pendente'], 'PAGO' => ['pago', 'Pago'], 'ATRASADO' => ['atrasado', 'Atrasado'],
    'CANCELADO' => ['cancelado', 'Cancelado'], 'AGENDADO' => ['agendado', 'Agendado'], 'NEGOCIANDO' => ['pendente', 'Negociando'],
];
$origens = ['MANUAL' => 'Manual', 'VENDA' => 'Venda', 'COMPRA' => 'Compra'];

$titulo = 'Fluxo de Caixa';
$subtitulo = 'Entradas, saídas e contas em aberto';
$paginaAtual = 'fluxo_de_caixa';
include __DIR__ . '/_partials/topo.php';
?>

        <section class="grid-cards" aria-label="Resumo do período">
          <div class="card-metrica">
            <span class="card-titulo">Saldo do período</span>
            <div class="card-valor"><?= moeda($saldo) ?></div>
            <span class="card-detalhe <?= $saldo >= 0 ? 'positivo' : 'alerta' ?>"><?= $saldo >= 0 ? 'Entradas pagas menos saídas pagas' : 'Saídas acima das entradas' ?></span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Entradas pagas</span>
            <div class="card-valor"><?= moeda($resumo['entradas_pagas']) ?></div>
            <span class="card-detalhe neutro"><?= moeda($resumo['a_receber']) ?> a receber</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Saídas pagas</span>
            <div class="card-valor"><?= moeda($resumo['saidas_pagas']) ?></div>
            <span class="card-detalhe neutro"><?= moeda($resumo['a_pagar']) ?> a pagar</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Em atraso</span>
            <div class="card-valor"><?= moeda($resumo['atrasado_valor']) ?></div>
            <span class="card-detalhe alerta"><?= (int) $resumo['atrasado_qtd'] ?> <?= (int) $resumo['atrasado_qtd'] === 1 ? 'lançamento' : 'lançamentos' ?></span>
          </div>
        </section>

        <form class="toolbar" method="get" action="fluxo_de_caixa.php">
          <div class="toolbar-grupo">
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" class="input-dash" name="periodo">
                <?php foreach (['mes' => 'Este mês', 'passado' => 'Mês passado', '30dias' => 'Últimos 30 dias', 'ano' => 'Este ano'] as $v => $r): ?>
                  <option value="<?= $v ?>"<?= $periodo === $v ? ' selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="grupo-input seletor">
              <label class="sr-only" for="situacao">Situação</label>
              <select id="situacao" class="input-dash" name="situacao">
                <?php foreach (['TODAS' => 'Todas as situações', 'PENDENTE' => 'Pendente', 'PAGO' => 'Pago', 'ATRASADO' => 'Atrasado', 'CANCELADO' => 'Cancelado'] as $v => $r): ?>
                  <option value="<?= $v ?>"<?= $situacao === $v ? ' selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if ($tipo !== 'TODOS'): ?><input type="hidden" name="tipo" value="<?= e($tipo) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-secundario">Filtrar</button>
          </div>
          <div class="toolbar-grupo">
            <div class="filtros" role="group" aria-label="Tipo de lançamento">
              <?php foreach ([['TODOS', 'Todos', $contagem['todos']], ['ENTRADA', 'Entradas', $contagem['entradas']], ['SAIDA', 'Saídas', $contagem['saidas']]] as [$v, $r, $q]): ?>
                <a class="filtro<?= $tipo === $v ? ' ativo' : '' ?>" href="<?= e(url(['tipo' => $v === 'TODOS' ? null : $v, 'pagina' => null])) ?>"><?= $r ?> <span><?= (int) $q ?></span></a>
              <?php endforeach; ?>
            </div>
          </div>
        </form>

        <?php if ($cancelarId): ?>
          <section class="painel-card confirmar-card" aria-labelledby="t-cancelar">
            <h2 id="t-cancelar">Cancelar lançamento</h2>
            <p>Cancelar este lançamento? Um lançamento cancelado não pode ser reaberto.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="status">
              <input type="hidden" name="status" value="CANCELADO">
              <input type="hidden" name="id" value="<?= $cancelarId ?>">
              <input type="hidden" name="tipo" value="<?= e(entrada('cancelar_tipo')) ?>">
              <input type="hidden" name="origem" value="<?= e(entrada('cancelar_origem')) ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar lançamento</button>
              <a class="btn btn-secundario" href="<?= e(url(['cancelar' => null, 'cancelar_tipo' => null, 'cancelar_origem' => null])) ?>">Voltar</a>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($excluirId): ?>
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir lançamento</h2>
            <p>Excluir definitivamente este lançamento manual? Essa ação não pode ser desfeita.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="<?= $excluirId ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="<?= e(url(['excluir' => null])) ?>">Voltar</a>
            </form>
          </section>
        <?php endif; ?>

        <div class="fluxo-grid">

          <section class="painel-card painel-tabela" aria-label="Lançamentos">
            <?php if (!$lancamentos): ?>
              <p class="vazio">Nenhum lançamento neste filtro. Registre um lançamento manual ou mude o período.</p>
            <?php else: ?>
              <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de lançamentos, role para os lados se necessário">
                <table class="tabela">
                  <thead>
                    <tr>
                      <th scope="col">Vencimento</th>
                      <th scope="col">Lançamento</th>
                      <th scope="col" class="num">Valor</th>
                      <th scope="col">Situação</th>
                      <th scope="col"><span class="sr-only">Ações</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($lancamentos as $l):
                      [$classe, $rotulo] = $situacoes[$l['situacao']] ?? ['pendente', $l['situacao']];
                      $manual = $l['origem'] === 'MANUAL';
                      $cancelado = $l['status'] === 'CANCELADO';
                      $detalhe = $manual ? $l['categoria'] : ($l['origem'] === 'VENDA' ? 'Pedido #' . $l['referencia'] : 'Compra #' . $l['referencia']);
                      $dataTxt = date('Y', strtotime($l['vencimento'])) === date('Y') ? date('d/m', strtotime($l['vencimento'])) : date('d/m/y', strtotime($l['vencimento']));
                    ?>
                      <tr>
                        <td class="data"><?= $dataTxt ?></td>
                        <td><span class="celula-dupla"><strong><?= e($l['contraparte']) ?></strong><small><span class="origem"><?= e($origens[$l['origem']] ?? $l['origem']) ?></span> <?= e($detalhe) ?></small></span></td>
                        <td class="num <?= $l['tipo'] === 'ENTRADA' ? 'entrada' : 'saida' ?>"><?= $l['tipo'] === 'ENTRADA' ? '+' : '−' ?> <?= moeda($l['total']) ?></td>
                        <td><span class="status <?= $classe ?>"><?= e($rotulo) ?></span></td>
                        <td class="acoes">
                          <?php if ($manual && !$cancelado): ?>
                            <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['editar' => $l['id']])) ?>#novo-lancamento" aria-label="Editar <?= e($l['contraparte']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                          <?php endif; ?>
                          <?php if (!$cancelado): ?>
                            <form class="em-linha" method="post" action="<?= e(url()) ?>">
                              <?= csrf_campo() ?>
                              <input type="hidden" name="acao" value="status">
                              <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                              <input type="hidden" name="tipo" value="<?= e($l['tipo']) ?>">
                              <input type="hidden" name="origem" value="<?= e($l['origem']) ?>">
                              <?php if ($l['status'] === 'PAGO'): ?>
                                <input type="hidden" name="status" value="PENDENTE">
                                <button type="submit" class="btn btn-pequeno btn-fantasma">Desfazer<span class="sr-only"> pagamento: <?= e($l['contraparte']) ?></span></button>
                              <?php else: ?>
                                <input type="hidden" name="status" value="PAGO">
                                <button type="submit" class="btn btn-pequeno">Marcar como pago<span class="sr-only">: <?= e($l['contraparte']) ?></span></button>
                              <?php endif; ?>
                            </form>
                            <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['cancelar' => $l['id'], 'cancelar_tipo' => $l['tipo'], 'cancelar_origem' => $l['origem'], 'excluir' => null])) ?>" title="Cancelar lançamento" aria-label="Cancelar <?= e($l['contraparte']) ?>"><i class="fa-solid fa-ban" aria-hidden="true"></i></a>
                          <?php endif; ?>
                          <?php if ($manual): ?>
                            <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['excluir' => $l['id'], 'cancelar' => null])) ?>" title="Excluir lançamento" aria-label="Excluir <?= e($l['contraparte']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?= paginacao($pagina, $paginasTotal, $totalFiltro, POR_PAGINA) ?>
            <?php endif; ?>
          </section>

          <div class="fluxo-lateral">

            <section class="painel-card" id="novo-lancamento" aria-labelledby="titulo-novo-lancamento">
              <div class="painel-header">
                <h2 id="titulo-novo-lancamento"><?= $f['id'] ? 'Editar lançamento' : 'Novo lançamento' ?></h2>
              </div>
              <form class="form-lancamento" method="post" action="<?= e(url()) ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                <fieldset class="segmentado">
                  <legend class="sr-only">Tipo</legend>
                  <label>
                    <input type="radio" name="tipo" value="ENTRADA"<?= $f['tipo'] === 'ENTRADA' ? ' checked' : '' ?>>
                    <span><i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Entrada</span>
                  </label>
                  <label>
                    <input type="radio" name="tipo" value="SAIDA"<?= $f['tipo'] === 'SAIDA' ? ' checked' : '' ?>>
                    <span><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Saída</span>
                  </label>
                </fieldset>

                <div class="grupo-input">
                  <label for="descricao">Descrição</label>
                  <input type="text" id="descricao" name="descricao" class="input-dash" maxlength="255" placeholder="Ex.: Conta de energia" required value="<?= e($f['descricao']) ?>">
                </div>

                <div class="grupo-input">
                  <label for="categoria">Categoria</label>
                  <input type="text" id="categoria" name="categoria" class="input-dash" maxlength="100" list="categorias-caixa" placeholder="Ex.: Despesas fixas" required value="<?= e($f['categoria']) ?>">
                  <datalist id="categorias-caixa">
                    <option value="Despesas fixas">
                    <option value="Receitas extras">
                    <option value="Impostos">
                    <option value="Manutenção">
                  </datalist>
                </div>

                <div class="linha-dupla">
                  <div class="grupo-input">
                    <label for="valor">Valor (R$)</label>
                    <input type="number" id="valor" name="valor" class="input-dash" min="0.01" step="0.01" inputmode="decimal" placeholder="0,00" required value="<?= e($f['valor']) ?>">
                  </div>
                  <div class="grupo-input">
                    <label for="data_movimentacao">Data</label>
                    <input type="date" id="data_movimentacao" name="data_movimentacao" class="input-dash" required value="<?= e($f['data_movimentacao']) ?>">
                  </div>
                </div>

                <div class="grupo-input">
                  <label for="status">Situação</label>
                  <select id="status" name="status" class="input-dash">
                    <option value="PENDENTE"<?= $f['status'] === 'PENDENTE' ? ' selected' : '' ?>>Pendente</option>
                    <option value="PAGO"<?= $f['status'] === 'PAGO' ? ' selected' : '' ?>>Pago</option>
                  </select>
                </div>

                <button type="submit" class="btn btn-primario"><i class="fa-solid <?= $f['id'] ? 'fa-floppy-disk' : 'fa-plus' ?>" aria-hidden="true"></i> <?= $f['id'] ? 'Salvar alterações' : 'Registrar lançamento' ?></button>
                <?php if ($f['id']): ?><a class="btn btn-secundario" href="<?= e(url(['editar' => null])) ?>">Cancelar edição</a><?php endif; ?>
              </form>
            </section>

            <section class="painel-card" aria-labelledby="titulo-semanas">
              <div class="painel-header">
                <h2 id="titulo-semanas">Entradas e saídas</h2>
                <span class="painel-meta">Últimas 4 semanas</span>
              </div>
              <div class="barras" role="img" aria-label="Entradas e saídas das últimas quatro semanas. <?php foreach ($semanas as $s) { echo e('Semana de ' . $s['rotulo'] . ': entradas ' . moeda($s['entrada']) . ', saídas ' . moeda($s['saida']) . '. '); } ?>">
                <?php foreach ($semanas as $s): ?>
                  <div class="barras-semana">
                    <div class="barras-par">
                      <span class="barra entrada" style="--h: <?= (int) round((float) $s['entrada'] / $maior * 100) ?>%" title="Entradas <?= e(moeda($s['entrada'])) ?>"></span>
                      <span class="barra saida" style="--h: <?= (int) round((float) $s['saida'] / $maior * 100) ?>%" title="Saídas <?= e(moeda($s['saida'])) ?>"></span>
                    </div>
                    <span class="barras-rotulo"><?= e($s['rotulo']) ?></span>
                  </div>
                <?php endforeach; ?>
              </div>
              <ul class="legenda">
                <li><span class="ponto entrada" aria-hidden="true"></span> Entradas</li>
                <li><span class="ponto saida" aria-hidden="true"></span> Saídas</li>
              </ul>
            </section>

          </div>
        </div>

<?php include __DIR__ . '/_partials/rodape.php'; ?>
