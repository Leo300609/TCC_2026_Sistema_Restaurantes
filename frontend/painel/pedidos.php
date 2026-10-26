<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/bd.php';
require_once __DIR__ . '/../../backend/lib/web.php';

$ehGerente = $verFinanceiro; // administrador ou gerente: exclui e estorna

/** Lê as linhas de itens do formulário (arrays itens_produto[], itens_qtd[], itens_obs[]). */
function itens_do_formulario(): array
{
    $produtos = $_POST['itens_produto'] ?? [];
    $qtds = $_POST['itens_qtd'] ?? [];
    $obs = $_POST['itens_obs'] ?? [];
    $itens = [];
    foreach ((array) $produtos as $n => $produto) {
        $produto = (int) $produto;
        if ($produto <= 0) {
            continue; // linha em branco
        }
        $itens[] = [$produto, (int) ($qtds[$n] ?? 1), trim((string) ($obs[$n] ?? ''))];
    }
    return $itens;
}

// ---------- ações (POST) ----------
tratar_post(function () use ($ehGerente) {
    $acao = entrada('acao');
    $id = entrada_int('id');
    $uid = (int) $_SESSION['usuario_id'];

    if ($acao === 'salvar') {
        $itens = itens_do_formulario();
        if (!$itens) {
            throw new ErroNegocio('Adicione ao menos um item ao pedido.');
        }
        $tipo = entrada('tipo_pedido');
        $desconto = entrada_decimal('desconto');

        // cabeçalho, itens e totais: tudo ou nada
        $numero = transacao(function () use ($id, $tipo, $itens, $desconto, $uid) {
            if ($id) {
                chamar('sp_pedido_atualizar', [$id, $tipo, entrada('mesa'), entrada_int('cliente_id'), entrada('forma_pagamento'), entrada('observacao')]);
                chamar('sp_pedido_item_limpar', [$id]);
                $numero = $id;
            } else {
                $numero = (int) chamar_valor('sp_pedido_criar', [$tipo, entrada('mesa'), entrada_int('cliente_id'), entrada('forma_pagamento'), entrada('observacao'), $uid]);
            }
            foreach ($itens as [$produto, $qtd, $obs]) {
                chamar('sp_pedido_item_adicionar', [$numero, $produto, $qtd, $obs]);
            }
            chamar('sp_pedido_totais', [$numero, $desconto]);
            return $numero;
        });
        return [$id ? "Pedido #{$numero} atualizado." : "Pedido #{$numero} criado.", url(['novo' => null, 'editar' => null])];
    }
    if ($acao === 'etapa') {
        $destino = entrada('etapa');
        chamar('sp_pedido_etapa', [$id, $destino]);
        return $destino === 'ENTREGUE' ? "Pedido #{$id} entregue e registrado no Fluxo de Caixa." : "Pedido #{$id} atualizado.";
    }
    if ($acao === 'cancelar') {
        chamar('sp_pedido_cancelar', [$id, $ehGerente ? 1 : 0]);
        return [$ehGerente && entrada('etapa') === 'ENTREGUE' ? "Pedido #{$id} cancelado e estornado." : "Pedido #{$id} cancelado.", url(['cancelar' => null, 'editar' => null, 'detalhes' => null])];
    }
    if ($acao === 'excluir') {
        if (!$ehGerente) {
            throw new ErroNegocio('Somente administradores e gerentes podem excluir pedidos.');
        }
        chamar('sp_pedido_excluir', [$id]);
        return ["Pedido #{$id} excluído.", url(['excluir' => null, 'editar' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
$uid = (int) $_SESSION['usuario_id'];
$cfg = ler(fn() => chamar_um('sp_config_listar', [$uid]), ['tempo_preparo' => 20, 'aceita_delivery' => '1', 'pagamentos' => 'PIX,CREDITO,DEBITO,DINHEIRO']);
$limitePreparo = max(1, (int) $cfg['tempo_preparo']);
$aceitaDelivery = $cfg['aceita_delivery'] === '1';
$pagamentosAceitos = array_values(array_filter(explode(',', (string) $cfg['pagamentos'])));

$busca = entrada('q');
$tipoFiltro = in_array(entrada('tipo'), ['SALAO', 'BALCAO', 'DELIVERY'], true) ? entrada('tipo') : '';

$todos = ler(fn() => chamar('sp_pedido_quadro'));
$itensPorPedido = [];
foreach (ler(fn() => chamar('sp_pedido_quadro_itens')) as $it) {
    $itensPorPedido[(int) $it['venda_id']][] = $it;
}

$termo = mb_strtolower(ltrim($busca, '#'));
$base = array_values(array_filter($todos, static function ($p) use ($termo) {
    return $termo === '' || str_contains((string) $p['id'], $termo)
        || str_contains(mb_strtolower((string) $p['cliente']), $termo) || str_contains(mb_strtolower((string) $p['mesa']), $termo);
}));
$contaTipo = static fn(string $t) => count(array_filter($base, static fn($p) => $t === '' || $p['tipo'] === $t));
$lista = array_values(array_filter($base, static fn($p) => $tipoFiltro === '' || $p['tipo'] === $tipoFiltro));

$colunas = [
    ['NOVO', 'Novos'], ['PREPARO', 'Em preparo'], ['PRONTO', 'Pronto / a caminho'], ['ENTREGUE', 'Entregues'],
];
$anterior = ['PREPARO' => 'NOVO', 'PRONTO' => 'PREPARO'];
$proxima = ['NOVO' => ['PREPARO', 'Aceitar', true], 'PREPARO' => ['PRONTO', 'Marcar pronto', false], 'PRONTO' => ['ENTREGUE', 'Confirmar entrega', false]];
$formas = ['PIX' => 'Pix', 'CREDITO' => 'Crédito', 'DEBITO' => 'Débito', 'DINHEIRO' => 'Dinheiro', 'BOLETO' => 'Boleto'];

$tempo = static fn(int $min) => $min >= 60 ? intdiv($min, 60) . 'h ' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) . 'min' : $min . ' min';
$origem = static function (array $p): string {
    $cli = $p['cliente'] ? ' · ' . e($p['cliente']) : '';
    return match ($p['tipo']) {
        'SALAO' => '<i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Mesa ' . e($p['mesa']) . $cli,
        'BALCAO' => '<i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão' . $cli,
        default => '<i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · ' . e($p['cliente'] ?: 'sem cliente'),
    };
};

// formulário (novo / editar)
$editarId = entrada_int('editar');
$formAberto = isset($_GET['novo']) || $editarId > 0;
$pedido = null;
$itensPedido = [];
if ($editarId > 0) {
    $pedido = ler(fn() => chamar_um('sp_pedido_obter', [$editarId]), null);
    if ($pedido && in_array($pedido['etapa'], ['NOVO', 'PREPARO', 'PRONTO'], true)) {
        $itensPedido = ler(fn() => chamar('sp_pedido_itens', [$editarId]));
    } else {
        $pedido = null;
        flash('erro', 'Esse pedido não existe ou não pode mais ser editado.');
    }
}
$p = $pedido ?? ['id' => 0, 'tipo' => 'SALAO', 'mesa' => '', 'cliente_id' => 0, 'forma_pagamento' => $pagamentosAceitos[0] ?? 'PIX', 'desconto' => 0, 'observacao' => ''];
$produtos = $formAberto ? ler(fn() => chamar('sp_pedido_opcoes_produtos')) : [];
$clientes = $formAberto ? ler(fn() => chamar('sp_pedido_opcoes_clientes')) : [];
$formasForm = array_values(array_unique(array_merge($pagamentosAceitos, [$p['forma_pagamento']])));
$linhasItens = max(6, count($itensPedido) + 3);

$detalheId = entrada_int('detalhes');
$detalhe = $detalheId > 0 ? ler(fn() => chamar_um('sp_pedido_obter', [$detalheId]), null) : null;
$itensDetalhe = $detalhe ? ler(fn() => chamar('sp_pedido_itens', [$detalheId])) : [];

$cancelarId = entrada_int('cancelar');
$alvoCancelar = $cancelarId > 0 ? ler(fn() => chamar_um('sp_pedido_obter', [$cancelarId]), null) : null;
$excluirId = entrada_int('excluir');
$alvoExcluir = $ehGerente && $excluirId > 0 ? ler(fn() => chamar_um('sp_pedido_obter', [$excluirId]), null) : null;

$titulo = 'Pedidos';
$subtitulo = 'Comandas do dia, da cozinha até a entrega';
$paginaAtual = 'pedidos';
include __DIR__ . '/_partials/topo.php';
?>

        <form class="toolbar" method="get" action="pedidos.php">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar pedido</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="<?= e($busca) ?>" placeholder="Buscar por número, mesa ou cliente" autocomplete="off">
            </label>
            <?php if ($tipoFiltro): ?><input type="hidden" name="tipo" value="<?= e($tipoFiltro) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Tipo de pedido">
              <?php foreach ([['', 'Todos'], ['SALAO', 'Salão'], ['BALCAO', 'Balcão'], ['DELIVERY', 'Delivery']] as [$valor, $rotulo]): ?>
                <a class="filtro<?= $tipoFiltro === $valor ? ' ativo' : '' ?>" href="<?= e(url(['tipo' => $valor ?: null])) ?>"><?= $rotulo ?> <span><?= $contaTipo($valor) ?></span></a>
              <?php endforeach; ?>
            </div>
          </div>
          <a class="btn btn-primario" href="<?= e(url(['novo' => 1, 'editar' => null, 'detalhes' => null])) ?>#formulario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo pedido</a>
        </form>

        <?php foreach ([['cancelar', $alvoCancelar, 'Cancelar pedido', 'Cancelar', 'fa-ban'], ['excluir', $alvoExcluir, 'Excluir pedido', 'Excluir', 'fa-trash']] as [$param, $alvo, $tituloConf, $botao, $icone]): ?>
          <?php if ($alvo): ?>
            <section class="painel-card confirmar-card" aria-labelledby="t-<?= $param ?>">
              <h2 id="t-<?= $param ?>"><?= $tituloConf ?></h2>
              <p>
                <?php if ($param === 'cancelar'): ?>
                  Cancelar o pedido <strong>#<?= (int) $alvo['id'] ?></strong>?<?= $alvo['etapa'] === 'ENTREGUE' ? ' Como ele já foi entregue, o valor será estornado do Fluxo de Caixa.' : '' ?>
                <?php else: ?>
                  Excluir definitivamente o pedido <strong>#<?= (int) $alvo['id'] ?></strong>? Essa ação não pode ser desfeita.
                <?php endif; ?>
              </p>
              <form method="post" action="<?= e(url()) ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="<?= $param ?>">
                <input type="hidden" name="id" value="<?= (int) $alvo['id'] ?>">
                <input type="hidden" name="etapa" value="<?= e($alvo['etapa']) ?>">
                <button type="submit" class="btn btn-perigo"><i class="fa-solid <?= $icone ?>" aria-hidden="true"></i> <?= $botao ?></button>
                <a class="btn btn-secundario" href="<?= e(url([$param => null])) ?>">Voltar</a>
              </form>
            </section>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if ($detalhe): ?>
          <section class="painel-card form-card" aria-labelledby="t-det">
            <div class="painel-header"><h2 id="t-det">Pedido #<?= (int) $detalhe['id'] ?></h2><a class="btn btn-pequeno btn-secundario" href="<?= e(url(['detalhes' => null])) ?>">Fechar</a></div>
            <div style="padding: 0 20px 20px">
              <p class="comanda-origem"><?= $origem($detalhe) ?></p>
              <ul class="comanda-itens">
                <?php foreach ($itensDetalhe as $it): ?>
                  <li><span class="qtd"><?= (int) $it['quantidade'] ?>x</span> <?= e($it['nome']) ?> <span class="item-valor"><?= moeda($it['quantidade'] * $it['preco']) ?></span><?= $it['observacao'] ? ' <em>' . e($it['observacao']) . '</em>' : '' ?></li>
                <?php endforeach; ?>
              </ul>
              <?php if ($detalhe['observacao']): ?><p class="modal-texto">Observação: <?= e($detalhe['observacao']) ?></p><?php endif; ?>
              <p class="modal-texto">Atendido por <?= e($detalhe['atendente']) ?> · <?= e($formas[$detalhe['forma_pagamento']] ?? $detalhe['forma_pagamento']) ?><?= (float) $detalhe['desconto'] > 0 ? ' · desconto de ' . moeda($detalhe['desconto']) : '' ?></p>
              <p class="total-pedido"><span>Total</span><strong><?= moeda($detalhe['total']) ?></strong></p>
              <?php if ($ehGerente && $detalhe['etapa'] !== 'CANCELADO'): ?>
                <div class="form-acoes"><a class="btn btn-perigo" href="<?= e(url(['cancelar' => $detalhe['id'], 'detalhes' => null])) ?>"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar pedido (estornar)</a></div>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($formAberto && ($editarId === 0 || $pedido)): ?>
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form"><?= $p['id'] ? 'Editar pedido #' . (int) $p['id'] : 'Novo pedido' ?></h2></div>
            <?php if (!$produtos): ?>
              <p class="vazio">Não há itens disponíveis no cardápio. Cadastre itens na tela de Cardápio antes de criar pedidos.</p>
            <?php else: ?>
              <form method="post" action="<?= e(url()) ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">

                <fieldset class="segmentado segmentado-3">
                  <legend class="sr-only">Tipo do pedido</legend>
                  <label><input type="radio" name="tipo_pedido" value="SALAO"<?= $p['tipo'] === 'SALAO' ? ' checked' : '' ?>><span><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Salão</span></label>
                  <label><input type="radio" name="tipo_pedido" value="BALCAO"<?= $p['tipo'] === 'BALCAO' ? ' checked' : '' ?>><span><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão</span></label>
                  <?php $deliveryOk = $aceitaDelivery || $p['tipo'] === 'DELIVERY'; ?>
                  <label><input type="radio" name="tipo_pedido" value="DELIVERY"<?= $p['tipo'] === 'DELIVERY' ? ' checked' : '' ?><?= $deliveryOk ? '' : ' disabled' ?>><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery<?= $deliveryOk ? '' : ' (pausado)' ?></span></label>
                </fieldset>

                <div class="form-grid">
                  <div class="grupo-input">
                    <label for="p-mesa">Mesa (só no salão)</label>
                    <input type="text" id="p-mesa" name="mesa" class="input-dash" maxlength="20" placeholder="Ex.: 4" value="<?= e($p['mesa']) ?>">
                  </div>
                  <div class="grupo-input">
                    <label for="p-cliente">Cliente (obrigatório no delivery)</label>
                    <select id="p-cliente" name="cliente_id" class="input-dash">
                      <option value="0">Sem cliente identificado</option>
                      <?php foreach ($clientes as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $p['cliente_id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>

                <h3 class="subtitulo-form">Itens do pedido</h3>
                <div class="itens-pedido">
                  <?php for ($n = 0; $n < $linhasItens; $n++): $atual = $itensPedido[$n] ?? null; ?>
                    <div class="item-pedido">
                      <select name="itens_produto[]" class="input-dash" aria-label="Item <?= $n + 1 ?>">
                        <option value="0">— Escolha um item do cardápio —</option>
                        <?php $catAnterior = null; foreach ($produtos as $pr): ?>
                          <?php if ($pr['categoria'] !== $catAnterior): ?>
                            <?= $catAnterior !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($pr['categoria']) ?>">
                            <?php $catAnterior = $pr['categoria']; ?>
                          <?php endif; ?>
                          <option value="<?= (int) $pr['id'] ?>"<?= $atual && (int) $atual['produto_id'] === (int) $pr['id'] ? ' selected' : '' ?>><?= e($pr['nome']) ?> — <?= moeda($pr['preco']) ?></option>
                        <?php endforeach; ?>
                        <?= $catAnterior !== null ? '</optgroup>' : '' ?>
                      </select>
                      <input type="number" name="itens_qtd[]" class="input-dash" min="1" max="999" step="1" value="<?= $atual ? (int) $atual['quantidade'] : 1 ?>" aria-label="Quantidade do item <?= $n + 1 ?>">
                      <input type="text" name="itens_obs[]" class="input-dash" maxlength="255" placeholder="Observação (ex.: sem sal)" value="<?= e($atual['observacao'] ?? '') ?>" aria-label="Observação do item <?= $n + 1 ?>">
                    </div>
                  <?php endfor; ?>
                </div>
                <p class="painel-meta">Linhas em branco são ignoradas. O total é calculado ao salvar.</p>

                <div class="form-grid">
                  <div class="grupo-input">
                    <label for="p-forma">Forma de pagamento</label>
                    <select id="p-forma" name="forma_pagamento" class="input-dash" required>
                      <?php foreach ($formasForm as $f): ?>
                        <option value="<?= e($f) ?>"<?= $f === $p['forma_pagamento'] ? ' selected' : '' ?>><?= e($formas[$f] ?? $f) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="p-desc">Desconto (R$)</label>
                    <input type="number" id="p-desc" name="desconto" class="input-dash" min="0" step="0.01" inputmode="decimal" value="<?= e($p['desconto'] ?: 0) ?>">
                  </div>
                  <div class="grupo-input largo">
                    <label for="p-obs">Observação do pedido (opcional)</label>
                    <input type="text" id="p-obs" name="observacao" class="input-dash" maxlength="255" value="<?= e($p['observacao']) ?>">
                  </div>
                </div>

                <div class="form-acoes">
                  <button type="submit" class="btn btn-primario"><?= $p['id'] ? 'Salvar alterações' : 'Criar pedido' ?></button>
                  <a class="btn btn-secundario" href="<?= e(url(['novo' => null, 'editar' => null])) ?>">Cancelar</a>
                  <?php if ($p['id']): ?>
                    <a class="btn btn-fantasma" href="<?= e(url(['cancelar' => $p['id'], 'editar' => null])) ?>"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar pedido</a>
                    <?php if ($ehGerente): ?><a class="btn btn-perigo" href="<?= e(url(['excluir' => $p['id'], 'editar' => null])) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</a><?php endif; ?>
                  <?php endif; ?>
                </div>
              </form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <div class="quadro">
          <?php foreach ($colunas as $n => [$etapa, $rotuloColuna]):
            $doGrupo = array_values(array_filter($lista, static fn($x) => $x['etapa'] === $etapa));
            if ($etapa === 'ENTREGUE') { $doGrupo = array_reverse($doGrupo); }
          ?>
            <section class="coluna" aria-labelledby="col-<?= $n ?>">
              <header class="coluna-topo">
                <h2 id="col-<?= $n ?>"><?= $rotuloColuna ?></h2>
                <span class="contagem"><?= count($doGrupo) ?></span>
              </header>
              <?php if (!$doGrupo): ?><p class="coluna-vazia">Nenhum pedido</p><?php endif; ?>
              <?php foreach ($doGrupo as $pd):
                $entregue = $pd['etapa'] === 'ENTREGUE';
                $atrasado = in_array($pd['etapa'], ['NOVO', 'PREPARO'], true) && (int) $pd['minutos'] > $limitePreparo;
                $itensCard = $itensPorPedido[(int) $pd['id']] ?? [];
              ?>
                <article class="comanda<?= $entregue ? ' concluida' : '' ?>">
                  <header class="comanda-topo">
                    <strong class="comanda-numero">#<?= (int) $pd['id'] ?></strong>
                    <?php if ($entregue): ?>
                      <span class="status entregue">Entregue</span>
                    <?php elseif ($pd['etapa'] === 'PRONTO'): ?>
                      <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> <?= $pd['tipo'] === 'DELIVERY' ? 'saiu' : 'pronto' ?> há <?= $tempo((int) $pd['minutos_etapa']) ?></span>
                    <?php else: ?>
                      <span class="comanda-tempo<?= $atrasado ? ' atrasado' : '' ?>"><i class="fa-regular fa-clock" aria-hidden="true"></i> há <?= $tempo((int) $pd['minutos']) ?></span>
                    <?php endif; ?>
                  </header>
                  <p class="comanda-origem"><?= $origem($pd) ?></p>
                  <ul class="comanda-itens">
                    <?php foreach ($itensCard as $it): ?>
                      <li><span class="qtd"><?= (int) $it['quantidade'] ?>x</span> <?= e($it['nome']) ?><?= $it['observacao'] ? ' <em>' . e($it['observacao']) . '</em>' : '' ?></li>
                    <?php endforeach; ?>
                  </ul>
                  <?php if ($pd['observacao']): ?><p class="comanda-obs"><i class="fa-regular fa-note-sticky" aria-hidden="true"></i> <?= e($pd['observacao']) ?></p><?php endif; ?>
                  <footer class="comanda-rodape">
                    <div class="comanda-total">
                      <strong><?= moeda($pd['total']) ?></strong>
                      <small><?= e($formas[$pd['forma_pagamento']] ?? $pd['forma_pagamento']) ?></small>
                    </div>
                    <div class="comanda-acoes">
                      <?php if ($entregue): ?>
                        <span class="comanda-hora"><?= e($pd['hora_etapa']) ?></span>
                        <a class="btn btn-pequeno btn-fantasma" href="<?= e(url(['detalhes' => $pd['id'], 'editar' => null, 'novo' => null])) ?>">Detalhes<span class="sr-only"> do pedido <?= (int) $pd['id'] ?></span></a>
                      <?php else: ?>
                        <?php if (isset($anterior[$pd['etapa']])): ?>
                          <form class="em-linha" method="post" action="<?= e(url()) ?>">
                            <?= csrf_campo() ?>
                            <input type="hidden" name="acao" value="etapa">
                            <input type="hidden" name="id" value="<?= (int) $pd['id'] ?>">
                            <input type="hidden" name="etapa" value="<?= $anterior[$pd['etapa']] ?>">
                            <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="Voltar etapa" aria-label="Voltar o pedido <?= (int) $pd['id'] ?> para a etapa anterior"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
                          </form>
                        <?php endif; ?>
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['editar' => $pd['id'], 'novo' => null, 'detalhes' => null])) ?>#formulario" title="Editar pedido" aria-label="Editar pedido <?= (int) $pd['id'] ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                        <?php [$destino, $rotuloBotao, $destaque] = $proxima[$pd['etapa']]; ?>
                        <form class="em-linha" method="post" action="<?= e(url()) ?>">
                          <?= csrf_campo() ?>
                          <input type="hidden" name="acao" value="etapa">
                          <input type="hidden" name="id" value="<?= (int) $pd['id'] ?>">
                          <input type="hidden" name="etapa" value="<?= $destino ?>">
                          <button type="submit" class="btn btn-pequeno<?= $destaque ? ' btn-primario' : '' ?>"><?= $rotuloBotao ?><span class="sr-only"> pedido <?= (int) $pd['id'] ?></span></button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </footer>
                </article>
              <?php endforeach; ?>
            </section>
          <?php endforeach; ?>
        </div>

<?php include __DIR__ . '/_partials/rodape.php'; ?>
