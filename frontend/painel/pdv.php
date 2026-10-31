<?php
require __DIR__ . '/_partials/auth.php';
exigir_permissao($perm['pdv']);

// ---------- venda (POST) ----------
tratar_post(function () {
    if (entrada('acao') !== 'vender') {
        throw new ErroNegocio('Ação não reconhecida.');
    }
    $uid = (int) $_SESSION['usuario_id'];
    $produtos = (array) ($_POST['itens_produto'] ?? []);
    $qtds = (array) ($_POST['itens_qtd'] ?? []);
    $obs = (array) ($_POST['itens_obs'] ?? []);
    $itens = [];
    foreach ($produtos as $n => $produto) {
        if ((int) $produto > 0) {
            $itens[] = [(int) $produto, (int) ($qtds[$n] ?? 1), trim((string) ($obs[$n] ?? ''))];
        }
    }
    if (!$itens) {
        throw new ErroNegocio('Adicione ao menos um item à venda.');
    }

    $cobrarAgora = entrada('destino') === 'finalizar';
    $forma = entrada('forma_pagamento');
    $recebido = $forma === 'DINHEIRO' && entrada('valor_recebido') !== '' ? entrada_decimal('valor_recebido') : null;

    $id = transacao(function () use ($itens, $uid, $cobrarAgora, $recebido, $forma) {
        $id = (int) chamar_valor('sp_pedido_criar', [entrada('tipo_pedido'), entrada('mesa'), entrada_int('cliente_id'), $forma, entrada('observacao'), $uid]);
        foreach ($itens as [$produto, $qtd, $observacao]) {
            chamar('sp_pedido_item_adicionar', [$id, $produto, $qtd, $observacao]);
        }
        chamar('sp_pedido_totais', [$id, entrada_decimal('desconto')]);
        if ($cobrarAgora) {
            chamar('sp_pedido_finalizar', [$id, $recebido]);
        }
        return $id;
    });

    $_SESSION['pdv_ultima'] = ['id' => $id, 'cobrado' => $cobrarAgora];
    return [$cobrarAgora ? "Venda #{$id} concluída." : "Pedido #{$id} enviado para a cozinha.", 'pdv.php?venda=' . $id];
});

// ---------- dados (GET) ----------
$uid = (int) $_SESSION['usuario_id'];
$cfg = ler(fn() => chamar_um('sp_config_listar', [$uid]), ['aceita_delivery' => '1', 'pagamentos' => 'PIX,CREDITO,DEBITO,DINHEIRO']);
$pagamentos = array_values(array_filter(explode(',', (string) $cfg['pagamentos'])));
$formas = formas_pagamento();

$produtos = array_values(array_filter(ler(fn() => chamar('sp_cardapio_listar', [''])), static fn($p) => $p['status'] === 'ATIVO'));
$categorias = array_values(array_unique(array_column($produtos, 'categoria')));
$clientes = ler(fn() => chamar('sp_pedido_opcoes_clientes'));
$mesas = ler(fn() => chamar('sp_mesa_opcoes'));
$mesaInicial = entrada('mesa');

// resultado da última venda (vindo do redirecionamento)
$ultima = null;
if (entrada_int('venda') > 0 && (int) ($_SESSION['pdv_ultima']['id'] ?? 0) === entrada_int('venda')) {
    $ultima = ler(fn() => chamar_um('sp_pedido_comprovante', [entrada_int('venda')]), null);
}

$titulo = 'PDV';
$subtitulo = 'Frente de caixa: monte a comanda e cobre em segundos';
$paginaAtual = 'pdv';
include __DIR__ . '/_partials/topo.php';
?>

        <?php if ($ultima): $troco = $ultima['valor_recebido'] !== null ? (float) $ultima['valor_recebido'] - (float) $ultima['total'] : 0; ?>
          <div class="aviso aviso-ok" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span>
              <strong><?= $ultima['etapa'] === 'ENTREGUE' ? 'Venda' : 'Pedido' ?> #<?= (int) $ultima['id'] ?></strong> · <?= moeda($ultima['total']) ?> no <?= e($formas[$ultima['forma_pagamento']][0] ?? $ultima['forma_pagamento']) ?>
              <?php if ($troco > 0): ?> · <strong>Troco: <?= moeda($troco) ?></strong><?php endif; ?>
              <?= $ultima['etapa'] === 'ENTREGUE' ? '' : ' · aguardando na cozinha' ?>
            </span>
            <a class="btn btn-pequeno" style="margin-left:auto" href="comprovante.php?id=<?= (int) $ultima['id'] ?>" target="_blank" rel="noopener"><i class="fa-solid fa-print" aria-hidden="true"></i> Comprovante</a>
          </div>
        <?php endif; ?>

        <?php if (!$produtos): ?>
          <section class="painel-card"><p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-utensils" aria-hidden="true"></i></span>O cardápio não tem itens disponíveis. <a href="cardapios.php">Cadastre os itens no Cardápio</a> para começar a vender.</p></section>
        <?php else: ?>
        <form class="pdv" id="pdv" method="post" action="pdv.php" data-limpar="<?= $ultima ? '1' : '0' ?>">
          <?= csrf_campo() ?>
          <input type="hidden" name="acao" value="vender">

          <div class="pdv-catalogo">
            <div class="pdv-busca">
              <label class="busca">
                <span class="sr-only">Buscar item</span>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                <input type="search" id="pdv-busca" class="input-dash" placeholder="Buscar item ou código de barras (F2)" autocomplete="off">
              </label>
            </div>
            <div class="filtros" role="group" aria-label="Categorias">
              <button type="button" class="filtro ativo" data-categoria="">Todos <span><?= count($produtos) ?></span></button>
              <?php foreach ($categorias as $c): ?>
                <button type="button" class="filtro" data-categoria="<?= e($c) ?>"><?= e($c) ?> <span><?= count(array_filter($produtos, static fn($p) => $p['categoria'] === $c)) ?></span></button>
              <?php endforeach; ?>
            </div>
            <div class="pdv-produtos" id="pdv-produtos">
              <?php foreach ($produtos as $p):
                $prod = $p['produziveis'];
                $esgotado = $prod !== null && (int) $prod <= 0; ?>
                <button type="button" class="pdv-produto" data-id="<?= (int) $p['id'] ?>" data-nome="<?= e($p['nome']) ?>" data-preco="<?= e($p['preco']) ?>"
                        data-categoria="<?= e($p['categoria']) ?>" data-codigo="<?= e($p['codigo_barras']) ?>" data-limite="<?= $prod === null ? '' : (int) $prod ?>"<?= $esgotado ? ' disabled' : '' ?>>
                  <span class="cat"><?= e($p['categoria']) ?></span>
                  <strong><?= e($p['nome']) ?></strong>
                  <?php if ($esgotado): ?><span class="estoque-aviso">Sem estoque</span><?php elseif ($prod !== null && (int) $prod <= 10): ?><span class="estoque-aviso">Restam <?= (int) $prod ?></span><?php endif; ?>
                  <span class="preco"><?= moeda($p['preco']) ?></span>
                </button>
              <?php endforeach; ?>
            </div>
            <p class="vazio" id="pdv-nada" hidden>Nenhum item encontrado.</p>
          </div>

          <aside class="pdv-carrinho" aria-label="Comanda atual">
            <fieldset class="segmentado segmentado-3">
              <legend class="sr-only">Tipo do pedido</legend>
              <label><input type="radio" name="tipo_pedido" value="BALCAO"<?= $mesaInicial === '' ? ' checked' : '' ?>><span><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão</span></label>
              <label><input type="radio" name="tipo_pedido" value="SALAO"<?= $mesaInicial !== '' ? ' checked' : '' ?>><span><i class="fa-solid fa-chair" aria-hidden="true"></i> Mesa</span></label>
              <label><input type="radio" name="tipo_pedido" value="DELIVERY"<?= $cfg['aceita_delivery'] === '1' ? '' : ' disabled' ?>><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery</span></label>
            </fieldset>

            <div class="comanda">
              <div class="comanda-topo">
                <span class="comanda-numero">Comanda</span>
                <span class="comanda-tempo" id="pdv-relogio"><?= date('H:i') ?></span>
              </div>

              <div class="linha-dupla" id="pdv-destino">
                <div class="grupo-input" data-mostrar="SALAO">
                  <label class="sr-only" for="pdv-mesa">Mesa</label>
                  <select id="pdv-mesa" name="mesa" class="input-dash">
                    <option value="">Escolha a mesa</option>
                    <?php foreach ($mesas as $m): ?>
                      <option value="<?= e($m['numero']) ?>"<?= $m['numero'] === $mesaInicial ? ' selected' : '' ?>>Mesa <?= e($m['numero']) ?> · <?= e($m['area']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="grupo-input" style="grid-column: span 2" data-mostrar="BALCAO DELIVERY SALAO">
                  <label class="sr-only" for="pdv-cliente">Cliente</label>
                  <select id="pdv-cliente" name="cliente_id" class="input-dash">
                    <option value="0">Cliente não identificado</option>
                    <?php foreach ($clientes as $c): ?>
                      <option value="<?= (int) $c['id'] ?>"><?= e($c['nome']) ?> · <?= e(telefone_br($c['telefone'])) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <ul class="carrinho-itens" id="carrinho" aria-live="polite">
                <li class="carrinho-vazio">Toque nos itens ao lado para montar a comanda.</li>
              </ul>
              <div id="carrinho-campos"></div>

              <div class="carrinho-totais">
                <div><span>Itens</span><span id="t-subtotal">R$ 0,00</span></div>
                <div>
                  <label for="pdv-desconto">Desconto</label>
                  <input type="number" id="pdv-desconto" name="desconto" min="0" step="0.01" value="0" inputmode="decimal"
                         style="width:90px;height:26px;padding:0 6px;border:1px solid #e3dcc9;border-radius:6px;background:#fffefa;color:#26221b;text-align:right">
                </div>
                <div class="total"><span>Total</span><span id="t-total">R$ 0,00</span></div>
                <div class="troco" id="linha-troco" hidden><span>Troco</span><span id="t-troco">R$ 0,00</span></div>
              </div>
            </div>

            <div class="pdv-pagamento">
              <div class="pdv-formas" role="radiogroup" aria-label="Forma de pagamento">
                <?php foreach ($pagamentos as $i => $f): ?>
                  <label><input type="radio" name="forma_pagamento" value="<?= e($f) ?>"<?= $i === 0 ? ' checked' : '' ?>><span><i class="<?= $formas[$f][1] ?? 'fa-solid fa-money-bill' ?>" aria-hidden="true"></i> <?= e($formas[$f][0] ?? $f) ?></span></label>
                <?php endforeach; ?>
              </div>
              <div class="grupo-input" id="campo-recebido" hidden>
                <label for="pdv-recebido">Valor recebido em dinheiro</label>
                <div class="campo-dinheiro"><input type="number" id="pdv-recebido" name="valor_recebido" class="input-dash" min="0" step="0.01" inputmode="decimal" placeholder="0,00"></div>
              </div>
              <input type="text" name="observacao" class="input-dash" maxlength="255" placeholder="Observação do pedido (opcional)">
              <div class="pdv-acoes">
                <button type="submit" name="destino" value="cozinha" class="btn btn-grande" id="btn-cozinha" disabled><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> Enviar à cozinha</button>
                <button type="submit" name="destino" value="finalizar" class="btn btn-grande btn-primario" id="btn-cobrar" disabled><i class="fa-solid fa-check" aria-hidden="true"></i> Cobrar agora</button>
              </div>
              <button type="button" class="btn btn-fantasma btn-pequeno" id="btn-limpar"><i class="fa-solid fa-eraser" aria-hidden="true"></i> Limpar comanda</button>
            </div>
          </aside>
        </form>
        <?php endif; ?>

<?php
$rodapeExtra = '<script src="../js/painel/pdv.js?v=' . e(config('app_versao')) . '" defer></script>';
include __DIR__ . '/_partials/rodape.php';
?>
