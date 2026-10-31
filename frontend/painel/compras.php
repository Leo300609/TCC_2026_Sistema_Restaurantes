<?php
require __DIR__ . '/_partials/auth.php';
exigir_permissao($perm['compras']);

// ---------- ações (POST) ----------
tratar_post(function () {
    $acao = entrada('acao');
    $uid = (int) $_SESSION['usuario_id'];

    if ($acao === 'salvar') {
        $produtos = (array) ($_POST['itens_produto'] ?? []);
        $qtds = (array) ($_POST['itens_qtd'] ?? []);
        $precos = (array) ($_POST['itens_preco'] ?? []);
        $itens = [];
        foreach ($produtos as $n => $produto) {
            if ((int) $produto > 0) {
                $preco = str_replace(',', '.', (string) ($precos[$n] ?? '0'));
                $itens[] = [(int) $produto, (int) ($qtds[$n] ?? 0), is_numeric($preco) ? (float) $preco : 0.0];
            }
        }
        if (!$itens) {
            throw new ErroNegocio('Adicione ao menos um item à compra.');
        }
        $vencimento = entrada('vencimento');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimento)) {
            throw new ErroNegocio('Informe o vencimento da primeira parcela.');
        }

        $id = transacao(function () use ($itens, $uid, $vencimento) {
            $id = (int) chamar_valor('sp_compra_criar', [entrada_int('fornecedor_id'), $uid, entrada('nf'), entrada('observacao')]);
            foreach ($itens as [$produto, $qtd, $preco]) {
                chamar('sp_compra_item_adicionar', [$id, $produto, $qtd, $preco]);
            }
            chamar('sp_compra_finalizar', [$id, entrada_decimal('desconto'), max(1, entrada_int('parcelas', 1)), $vencimento, isset($_POST['pago']) ? 1 : 0, $uid]);
            return $id;
        });
        return ["Compra #{$id} registrada. O estoque e o Fluxo de Caixa já foram atualizados.", url(['novo' => null, 'fornecedor' => null, 'ver' => $id])];
    }
    if ($acao === 'cancelar') {
        $id = entrada_int('id');
        chamar('sp_compra_cancelar', [$id, $uid]);
        return ["Compra #{$id} cancelada e estoque estornado.", url(['cancelar' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
const POR_PAGINA = 12;
[$de, $ate, $periodo] = periodo_filtro('90dias');
$busca = entrada('q');
$status = in_array(entrada('status'), ['FINALIZADA', 'CANCELADA'], true) ? entrada('status') : '';
$pagina = max(1, entrada_int('pagina', 1));

$resumo = ler(fn() => chamar_um('sp_compra_resumo', [$de, $ate]), null) ?? ['total_comprado' => 0, 'compras' => 0, 'a_pagar' => 0, 'vencido' => 0, 'principal_fornecedor' => null];
$contagem = ler(fn() => chamar_um('sp_compra_contagem', [$busca, $de, $ate]), ['todos' => 0, 'finalizadas' => 0, 'canceladas' => 0]);
$totalFiltro = (int) ($status === 'FINALIZADA' ? $contagem['finalizadas'] : ($status === 'CANCELADA' ? $contagem['canceladas'] : $contagem['todos']));
$paginasTotal = max(1, (int) ceil($totalFiltro / POR_PAGINA));
$pagina = min($pagina, $paginasTotal);
$compras = ler(fn() => chamar('sp_compra_listar', [$busca, $status, $de, $ate, POR_PAGINA, ($pagina - 1) * POR_PAGINA]));

// nova compra
$formAberto = isset($_GET['novo']);
$fornecedores = $formAberto ? ler(fn() => chamar('sp_fornecedor_opcoes')) : [];
$produtos = $formAberto ? ler(fn() => chamar('sp_compra_opcoes_produtos')) : [];
$fornecedorInicial = entrada_int('fornecedor');

// detalhe / cancelamento
$verId = entrada_int('ver');
$compra = $verId > 0 ? ler(fn() => chamar_um('sp_compra_obter', [$verId]), null) : null;
$itensCompra = $compra ? ler(fn() => chamar('sp_compra_itens', [$verId])) : [];
$parcelas = $compra ? ler(fn() => chamar('sp_compra_parcelas', [$verId])) : [];
$cancelarId = entrada_int('cancelar');
$alvoCancelar = $cancelarId > 0 ? ler(fn() => chamar_um('sp_compra_obter', [$cancelarId]), null) : null;

$situacoes = ['FINALIZADA' => ['finalizada', 'Recebida'], 'CANCELADA' => ['cancelada', 'Cancelada'], 'EM_ABERTO' => ['pendente', 'Em aberto']];
$situacoesParcela = ['PAGO' => ['pago', 'Paga'], 'PENDENTE' => ['pendente', 'A pagar'], 'ATRASADO' => ['atrasado', 'Vencida'], 'CANCELADO' => ['cancelado', 'Cancelada'], 'AGENDADO' => ['agendado', 'Agendada']];

$titulo = 'Compras';
$subtitulo = 'Entrada de mercadorias, contas a pagar e custo dos insumos';
$paginaAtual = 'compras';
include __DIR__ . '/_partials/topo.php';
?>

        <section class="grid-cards" aria-label="Resumo das compras">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-cart-flatbed" aria-hidden="true"></i></span> Comprado no período</span>
            <div class="card-valor"><?= moeda($resumo['total_comprado']) ?></div>
            <span class="card-detalhe"><?= (int) $resumo['compras'] ?> <?= (int) $resumo['compras'] === 1 ? 'compra recebida' : 'compras recebidas' ?></span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></span> Contas a pagar</span>
            <div class="card-valor"><?= moeda($resumo['a_pagar']) ?></div>
            <span class="card-detalhe">Parcelas de compras em aberto</span>
          </div>
          <div class="card-metrica alerta-box<?= (float) $resumo['vencido'] > 0 ? ' ativo-alerta' : '' ?>">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-calendar-xmark" aria-hidden="true"></i></span> Vencidas</span>
            <div class="card-valor"><?= moeda($resumo['vencido']) ?></div>
            <span class="card-detalhe <?= (float) $resumo['vencido'] > 0 ? 'alerta' : '' ?>"><?= (float) $resumo['vencido'] > 0 ? 'Pague no Fluxo de Caixa' : 'Nenhuma parcela vencida' ?></span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-truck-field" aria-hidden="true"></i></span> Principal fornecedor</span>
            <div class="card-valor" style="font-size:19px;white-space:normal"><?= e($resumo['principal_fornecedor'] ?: '—') ?></div>
            <span class="card-detalhe">Maior valor comprado no período</span>
          </div>
        </section>

        <form class="toolbar" method="get" action="compras.php">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar compra</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="<?= e($busca) ?>" placeholder="Fornecedor, nota fiscal ou nº da compra" autocomplete="off">
            </label>
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" name="periodo" class="input-dash" onchange="this.form.submit()">
                <?php foreach (array_diff_key(periodos_rotulos(), ['personalizado' => 1]) as $v => $r): ?>
                  <option value="<?= $v ?>"<?= $periodo === $v ? ' selected' : '' ?>><?= $r ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-secundario">Filtrar</button>
            <div class="filtros" role="group" aria-label="Situação">
              <?php foreach ([['', 'Todas', $contagem['todos']], ['FINALIZADA', 'Recebidas', $contagem['finalizadas']], ['CANCELADA', 'Canceladas', $contagem['canceladas']]] as [$v, $r, $q]): ?>
                <a class="filtro<?= $status === $v ? ' ativo' : '' ?>" href="<?= e(url(['status' => $v ?: null, 'pagina' => null])) ?>"><?= $r ?> <span><?= (int) $q ?></span></a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="toolbar-grupo">
            <a class="btn" href="fornecedores.php"><i class="fa-solid fa-truck-field" aria-hidden="true"></i> Fornecedores</a>
            <a class="btn btn-primario" href="<?= e(url(['novo' => 1, 'ver' => null])) ?>"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Registrar compra</a>
          </div>
        </form>

        <section class="painel-card painel-tabela" aria-label="Compras">
          <?php if (!$compras): ?>
            <p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-cart-flatbed" aria-hidden="true"></i></span>Nenhuma compra neste período. Use "Registrar compra" quando a mercadoria chegar.</p>
          <?php else: ?>
            <div class="tabela-container">
              <table class="tabela">
                <thead>
                  <tr>
                    <th scope="col">Compra</th>
                    <th scope="col">Fornecedor</th>
                    <th scope="col" class="num">Itens</th>
                    <th scope="col" class="num">Total</th>
                    <th scope="col">Pagamento</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($compras as $c): [$classe, $rotulo] = $situacoes[$c['status']] ?? ['', $c['status']]; ?>
                    <tr class="<?= $c['status'] === 'CANCELADA' ? 'linha-apagada' : '' ?>">
                      <td><span class="celula-dupla"><strong class="id-pedido">#<?= (int) $c['id'] ?></strong><small><?= e($c['data']) ?><?= $c['nf'] ? ' · ' . e($c['nf']) : '' ?></small></span></td>
                      <td><span class="celula-dupla"><strong><?= e($c['fornecedor']) ?></strong><small>por <?= e($c['responsavel']) ?></small></span></td>
                      <td class="num"><?= (int) $c['itens'] ?></td>
                      <td class="num"><strong><?= moeda($c['total']) ?></strong><?= (float) $c['desconto'] > 0 ? '<br><small class="muted">-' . moeda($c['desconto']) . '</small>' : '' ?></td>
                      <td>
                        <?php if ((int) $c['parcelas'] > 0): $pct = round((int) $c['parcelas_pagas'] / (int) $c['parcelas'] * 100); ?>
                          <div class="nivel" style="min-width:120px">
                            <span class="painel-meta"><?= (int) $c['parcelas_pagas'] ?>/<?= (int) $c['parcelas'] ?> <?= (int) $c['parcelas'] === 1 ? 'parcela paga' : 'parcelas pagas' ?></span>
                            <span class="nivel-barra"><span style="--nivel: <?= $pct ?>%"></span></span>
                          </div>
                        <?php else: ?>—<?php endif; ?>
                      </td>
                      <td><span class="status <?= $classe ?>"><?= $rotulo ?></span></td>
                      <td class="acoes">
                        <a class="btn btn-pequeno btn-fantasma" href="<?= e(url(['ver' => $c['id'], 'novo' => null])) ?>">Detalhes<span class="sr-only"> da compra <?= (int) $c['id'] ?></span></a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?= paginacao($pagina, $paginasTotal, $totalFiltro, POR_PAGINA) ?>
          <?php endif; ?>
        </section>

        <?php if ($compra): [$classe, $rotulo] = $situacoes[$compra['status']] ?? ['', $compra['status']]; ?>
          <section class="painel-card form-card" aria-labelledby="t-ver">
            <div class="painel-header">
              <h2 id="t-ver">Compra #<?= (int) $compra['id'] ?> <span class="status <?= $classe ?>"><?= $rotulo ?></span></h2>
              <a class="gaveta-fechar" href="<?= e(url(['ver' => null])) ?>" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <div class="gaveta-corpo">
              <div class="detalhe-grade">
                <div><small>Fornecedor</small><strong><?= e($compra['fornecedor']) ?></strong></div>
                <div><small>Data</small><strong><?= e($compra['data']) ?></strong></div>
                <div><small>Nota fiscal</small><strong><?= e($compra['nf'] ?: 'Não informada') ?></strong></div>
                <div><small>Registrada por</small><strong><?= e($compra['responsavel']) ?></strong></div>
                <?php if ($compra['observacao']): ?><div style="grid-column:1/-1"><small>Observação</small><strong><?= e($compra['observacao']) ?></strong></div><?php endif; ?>
              </div>
              <table class="tabela">
                <thead><tr><th scope="col">Item</th><th scope="col" class="num">Qtd.</th><th scope="col" class="num">Preço</th><th scope="col" class="num">Total</th></tr></thead>
                <tbody>
                  <?php foreach ($itensCompra as $it): ?>
                    <tr><td><?= e($it['nome']) ?></td><td class="num"><?= (int) $it['quantidade'] ?> <?= e($it['unidade']) ?></td><td class="num"><?= moeda($it['preco']) ?></td><td class="num"><?= moeda($it['total']) ?></td></tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <div class="resumo-compra">
                <div><span>Itens</span><span><?= moeda($compra['valor']) ?></span></div>
                <?php if ((float) $compra['desconto'] > 0): ?><div><span>Desconto</span><span>- <?= moeda($compra['desconto']) ?></span></div><?php endif; ?>
                <div class="total"><span>Total</span><span><?= moeda($compra['total']) ?></span></div>
              </div>
              <?php if ($parcelas): ?>
                <h3 class="subtitulo-form">Parcelas <a class="btn-link" href="fluxo_de_caixa.php?tipo=SAIDA&amp;periodo=ano">Pagar no Fluxo de Caixa</a></h3>
                <ul class="parcelas">
                  <?php foreach ($parcelas as $p): [$cp, $rp] = $situacoesParcela[$p['situacao']] ?? ['', $p['situacao']]; ?>
                    <li>
                      <span class="celula-dupla"><strong><?= (int) $p['numero'] ?>/<?= (int) $p['quantidade'] ?> · vence <?= data_br($p['vencimento']) ?></strong><small><?= $p['pagamento'] ? 'Paga em ' . data_br($p['pagamento']) : 'Em aberto' ?></small></span>
                      <span><span class="status <?= $cp ?>"><?= $rp ?></span> <strong style="margin-left:8px"><?= moeda($p['total']) ?></strong></span>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <?php if ($compra['status'] === 'FINALIZADA'): ?>
                <div class="form-acoes">
                  <a class="btn btn-perigo" href="<?= e(url(['cancelar' => $compra['id'], 'ver' => null])) ?>"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar compra</a>
                </div>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($alvoCancelar): ?>
          <section class="confirmar-card" aria-labelledby="t-cancelar">
            <h2 id="t-cancelar">Cancelar a compra #<?= (int) $alvoCancelar['id'] ?>?</h2>
            <p>As quantidades saem do estoque e as parcelas em aberto (<?= moeda($alvoCancelar['total']) ?> no total) são canceladas no Fluxo de Caixa.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="cancelar">
              <input type="hidden" name="id" value="<?= (int) $alvoCancelar['id'] ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar compra</button>
              <a class="btn btn-secundario" href="<?= e(url(['cancelar' => null])) ?>" data-fechar>Voltar</a>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($formAberto): ?>
          <section class="painel-card form-card larga" aria-labelledby="t-nova">
            <div class="painel-header">
              <h2 id="t-nova">Registrar compra</h2>
              <a class="gaveta-fechar" href="<?= e(url(['novo' => null, 'fornecedor' => null])) ?>" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <?php if (!$fornecedores): ?>
              <p class="vazio">Cadastre um fornecedor antes de registrar compras. <a href="fornecedores.php?novo=1">Cadastrar fornecedor</a></p>
            <?php else: ?>
              <form method="post" action="<?= e(url()) ?>" id="form-compra">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="salvar">
                <div class="form-grid tres">
                  <div class="grupo-input" style="grid-column: span 2">
                    <label for="c-fornecedor">Fornecedor</label>
                    <select id="c-fornecedor" name="fornecedor_id" class="input-dash" required>
                      <option value="">Escolha o fornecedor</option>
                      <?php foreach ($fornecedores as $fo): ?>
                        <option value="<?= (int) $fo['id'] ?>"<?= (int) $fo['id'] === $fornecedorInicial ? ' selected' : '' ?>><?= e($fo['nome']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="c-nf">Nota fiscal (opcional)</label>
                    <input type="text" id="c-nf" name="nf" class="input-dash" maxlength="30" placeholder="Ex.: NF-12345">
                  </div>
                </div>

                <h3 class="subtitulo-form">Itens recebidos <span class="painel-meta">Produto, quantidade e preço unitário</span></h3>
                <div class="itens-pedido">
                  <?php for ($n = 0; $n < 8; $n++): ?>
                    <div class="item-pedido compra">
                      <select name="itens_produto[]" class="input-dash" aria-label="Produto <?= $n + 1 ?>">
                        <option value="0">— Escolha um produto —</option>
                        <?php $grupo = null; foreach ($produtos as $p): ?>
                          <?php $rot = $p['tipo'] === 'INSUMO' ? 'Insumos · ' . $p['categoria'] : 'Revenda · ' . $p['categoria']; ?>
                          <?php if ($rot !== $grupo): ?><?= $grupo !== null ? '</optgroup>' : '' ?><optgroup label="<?= e($rot) ?>"><?php $grupo = $rot; endif; ?>
                          <option value="<?= (int) $p['id'] ?>" data-preco="<?= e($p['preco']) ?>"><?= e($p['nome']) ?> (<?= e($p['unidade']) ?> · estoque <?= (int) $p['estoque'] ?>)</option>
                        <?php endforeach; ?>
                        <?= $grupo !== null ? '</optgroup>' : '' ?>
                      </select>
                      <input type="number" name="itens_qtd[]" class="input-dash" min="1" step="1" placeholder="Qtd." aria-label="Quantidade do produto <?= $n + 1 ?>">
                      <div class="campo-dinheiro"><input type="number" name="itens_preco[]" class="input-dash" min="0.01" step="0.01" placeholder="0,00" aria-label="Preço unitário do produto <?= $n + 1 ?>"></div>
                    </div>
                  <?php endfor; ?>
                </div>
                <p class="painel-meta" style="margin-top:6px">Linhas sem produto são ignoradas. O preço sugerido é o último custo pago.</p>

                <h3 class="subtitulo-form">Pagamento</h3>
                <div class="form-grid tres">
                  <div class="grupo-input">
                    <label for="c-desconto">Desconto</label>
                    <div class="campo-dinheiro"><input type="number" id="c-desconto" name="desconto" class="input-dash" min="0" step="0.01" value="0"></div>
                  </div>
                  <div class="grupo-input">
                    <label for="c-parcelas">Parcelas</label>
                    <select id="c-parcelas" name="parcelas" class="input-dash">
                      <?php for ($p = 1; $p <= 12; $p++): ?><option value="<?= $p ?>"><?= $p === 1 ? 'À vista (1x)' : "{$p}x mensais" ?></option><?php endfor; ?>
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="c-venc">1º vencimento</label>
                    <input type="date" id="c-venc" name="vencimento" class="input-dash" required value="<?= date('Y-m-d', strtotime('+14 days')) ?>">
                  </div>
                  <label class="switch-rotulo" style="grid-column: 1 / -1">
                    <span class="switch"><input type="checkbox" name="pago" value="1" id="c-pago"><span class="switch-trilho" aria-hidden="true"></span></span>
                    <span>A primeira parcela já foi paga</span>
                  </label>
                  <div class="grupo-input largo" style="grid-column: 1 / -1">
                    <label for="c-obs">Observação (opcional)</label>
                    <input type="text" id="c-obs" name="observacao" class="input-dash" maxlength="255">
                  </div>
                </div>

                <div class="resumo-compra" aria-live="polite">
                  <div><span>Itens</span><span id="c-subtotal">R$ 0,00</span></div>
                  <div class="total"><span>Total</span><span id="c-total">R$ 0,00</span></div>
                  <div class="painel-meta" id="c-parcela-info"></div>
                </div>

                <div class="form-acoes">
                  <button type="submit" class="btn btn-primario"><i class="fa-solid fa-check" aria-hidden="true"></i> Registrar compra</button>
                  <a class="btn btn-secundario" href="<?= e(url(['novo' => null, 'fornecedor' => null])) ?>" data-fechar>Cancelar</a>
                </div>
              </form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

<?php
$rodapeExtra = <<<'HTML'
<script>
  (() => {
    const form = document.getElementById('form-compra');
    if (!form) return;
    const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    function calcular() {
      let soma = 0;
      form.querySelectorAll('.item-pedido').forEach((linha) => {
        const prod = Number(linha.querySelector('select').value);
        const q = Number(linha.querySelector('[name="itens_qtd[]"]').value) || 0;
        const p = Number(linha.querySelector('[name="itens_preco[]"]').value) || 0;
        if (prod) soma += q * p;
      });
      const desc = Math.min(Number(form.desconto.value) || 0, soma);
      const total = soma - desc;
      const n = Number(form.parcelas.value);
      document.getElementById('c-subtotal').textContent = moeda(soma);
      document.getElementById('c-total').textContent = moeda(total);
      document.getElementById('c-parcela-info').textContent = n > 1 && total ? `${n} parcelas de ${moeda(total / n)}` : '';
    }
    form.addEventListener('change', (e) => {
      // escolher o produto sugere o último custo e a quantidade 1
      if (e.target.matches('[name="itens_produto[]"]')) {
        const linha = e.target.closest('.item-pedido');
        const preco = linha.querySelector('[name="itens_preco[]"]');
        const qtd = linha.querySelector('[name="itens_qtd[]"]');
        const sugerido = e.target.selectedOptions[0]?.dataset.preco;
        if (sugerido && !preco.value) preco.value = Number(sugerido).toFixed(2);
        if (!qtd.value && e.target.value !== '0') qtd.value = 1;
        qtd.required = preco.required = e.target.value !== '0';
      }
      calcular();
    });
    form.addEventListener('input', calcular);
  })();
</script>
HTML;
include __DIR__ . '/_partials/rodape.php';
?>
