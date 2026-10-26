<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/bd.php';
require_once __DIR__ . '/../../backend/lib/web.php';
require_once __DIR__ . '/../../backend/modulos/categorias.php';

if (!$verEstoque) {
    header('Location: dashboard.php');
    exit;
}
$ehGerente = $verFinanceiro;

// ---------- ações (POST) ----------
tratar_categorias('INSUMO', $ehGerente);

tratar_post(function () use ($ehGerente) {
    $acao = entrada('acao');
    $id = entrada_int('id');
    $uid = (int) $_SESSION['usuario_id'];

    if ($acao === 'salvar') {
        $validade = entrada('validade') ?: null;
        chamar('sp_produto_salvar', [
            $id, 'ESTOQUE', entrada('nome'), entrada('descricao'), entrada_decimal('preco'), entrada_int('categoria_id'),
            entrada('status', 'ATIVO'), entrada('codigo_barras'), entrada('ncm'), entrada('unidade'),
            entrada_int('estoque_minimo'), $validade, entrada_int('estoque'), $uid,
        ]);
        return [$id ? 'Item atualizado.' : 'Item cadastrado no estoque.', url(['editar' => null, 'novo' => null])];
    }
    if ($acao === 'movimentar') {
        $tipo = entrada('tipo') === 'SAIDA' ? 'SAIDA' : 'ENTRADA';
        $novo = chamar_valor('sp_estoque_movimentar', [entrada_int('produto_id'), $tipo, entrada_int('quantidade'), entrada('observacao'), $uid]);
        return [($tipo === 'ENTRADA' ? 'Entrada registrada' : 'Saída registrada') . '. Estoque atual: ' . (int) $novo . '.', url(['mov' => null, 'produto' => null])];
    }
    if ($acao === 'excluir') {
        if (!$ehGerente) {
            throw new ErroNegocio('Somente administradores e gerentes podem excluir itens.');
        }
        $r = chamar_valor('sp_produto_excluir', [$id, 'ESTOQUE']);
        return [$r === 'INATIVADO' ? 'O item tem vendas ou movimentações no histórico, então foi inativado em vez de excluído.' : 'Item excluído do estoque.', url(['excluir' => null, 'editar' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
const POR_PAGINA = 12;
$uid = (int) $_SESSION['usuario_id'];
$cfg = ler(fn() => chamar_um('sp_config_listar', [$uid]), ['dias_validade' => 3]);
$dias = min(60, max(0, (int) $cfg['dias_validade']));

$busca = entrada('q');
$situacao = in_array(entrada('situacao'), ['CRITICO', 'VENCENDO', 'OK'], true) ? entrada('situacao') : 'TODOS';
$categoriaFiltro = entrada_int('categoria');
$pagina = max(1, entrada_int('pagina', 1));

$resumo = ler(fn() => chamar_um('sp_estoque_resumo', [$dias]), ['itens' => 0, 'categorias' => 0, 'abaixo_minimo' => 0, 'vencendo' => 0, 'valor_estoque' => 0]);
$contagem = ler(fn() => chamar_um('sp_estoque_contagem', [$busca, $categoriaFiltro, $dias]), ['todos' => 0, 'critico' => 0, 'vencendo' => 0, 'ok' => 0]);
$chaveSit = ['TODOS' => 'todos', 'CRITICO' => 'critico', 'VENCENDO' => 'vencendo', 'OK' => 'ok'][$situacao];
$totalFiltro = (int) $contagem[$chaveSit];
$paginasTotal = max(1, (int) ceil($totalFiltro / POR_PAGINA));
$pagina = min($pagina, $paginasTotal);

$itens = ler(fn() => chamar('sp_estoque_listar', [$busca, $situacao, $categoriaFiltro, $dias, POR_PAGINA, ($pagina - 1) * POR_PAGINA]));
$movimentos = ler(fn() => chamar('sp_estoque_movimentos_hoje'));
$categoriasTodas = ler(fn() => chamar('sp_categoria_listar', ['']));
$categoriasAtivas = array_values(array_filter($categoriasTodas, static fn($c) => $c['status'] === 'ATIVO'));

// formulários
$editarId = entrada_int('editar');
$formAberto = isset($_GET['novo']) || $editarId > 0;
$item = $editarId > 0 ? ler(fn() => chamar_um('sp_produto_obter', [$editarId]), null) : null;
$i = $item ?? ['id' => 0, 'nome' => '', 'descricao' => '', 'preco' => '0', 'categoria_id' => 0, 'codigo_barras' => '', 'ncm' => '', 'unidade' => 'UN', 'estoque_minimo' => 0, 'estoque' => 0, 'validade' => '', 'status' => 'ATIVO'];

$mov = in_array(entrada('mov'), ['ENTRADA', 'SAIDA'], true) ? entrada('mov') : '';
$produtosMov = $mov ? ler(fn() => chamar('sp_estoque_produtos_ativos')) : [];
$produtoSel = entrada_int('produto');

$excluirId = entrada_int('excluir');
$alvoExcluir = $ehGerente && $excluirId > 0 ? ler(fn() => chamar_um('sp_produto_obter', [$excluirId]), null) : null;

$mostrarCategorias = $ehGerente && (isset($_GET['categorias']) || isset($_GET['cat']) || isset($_GET['cat_novo']) || isset($_GET['cat_excluir']));
$dadosCat = $mostrarCategorias ? dados_categorias('INSUMO') : null;
$rotuloCat = 'de insumos';

$situacoes = [
    'OK' => ['ok', 'Em dia'],
    'CRITICO' => ['critico', 'Abaixo do mínimo'],
    'VENCIDO' => ['critico', 'Vencido'],
    'VENCENDO' => ['baixo', 'Vence em breve'],
    'INATIVO' => ['inativo', 'Inativo'],
];

$titulo = 'Estoque';
$subtitulo = 'Insumos, validade e níveis mínimos';
$paginaAtual = 'estoque';
include __DIR__ . '/_partials/topo.php';

$filtrosSit = [['TODOS', 'Todos', $contagem['todos']], ['CRITICO', 'Críticos', $contagem['critico']], ['VENCENDO', 'Vencendo', $contagem['vencendo']], ['OK', 'Em dia', $contagem['ok']]];
?>

        <section class="grid-cards" aria-label="Resumo do estoque">
          <div class="card-metrica">
            <span class="card-titulo">Itens ativos</span>
            <div class="card-valor"><?= (int) $resumo['itens'] ?></div>
            <span class="card-detalhe neutro"><?= (int) $resumo['categorias'] ?> categorias</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Abaixo do mínimo</span>
            <div class="card-valor"><?= (int) $resumo['abaixo_minimo'] ?></div>
            <span class="card-detalhe alerta">Repor com urgência</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Vencimento próximo</span>
            <div class="card-valor"><?= (int) $resumo['vencendo'] ?></div>
            <span class="card-detalhe alerta">Em até <?= $dias ?> dias · use primeiro</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Valor em estoque</span>
            <div class="card-valor"><?= moeda($resumo['valor_estoque']) ?></div>
            <span class="card-detalhe neutro">quantidade × preço unitário</span>
          </div>
        </section>

        <form class="toolbar" method="get" action="estoque.php">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar produto</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="<?= e($busca) ?>" placeholder="Buscar por nome ou código de barras" autocomplete="off">
            </label>
            <div class="grupo-input seletor">
              <label class="sr-only" for="categoria">Categoria</label>
              <select id="categoria" class="input-dash" name="categoria">
                <option value="0">Todas as categorias</option>
                <?php foreach ($categoriasTodas as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"<?= $categoriaFiltro === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <?php if ($situacao !== 'TODOS'): ?><input type="hidden" name="situacao" value="<?= e($situacao) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-secundario">Filtrar</button>
            <div class="filtros" role="group" aria-label="Situação do estoque">
              <?php foreach ($filtrosSit as [$valor, $rotulo, $qtd]): ?>
                <a class="filtro<?= $situacao === $valor ? ' ativo' : '' ?>" href="<?= e(url(['situacao' => $valor === 'TODOS' ? null : $valor, 'pagina' => null])) ?>"><?= $rotulo ?> <span><?= (int) $qtd ?></span></a>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="toolbar-grupo">
            <?php if ($ehGerente): ?><a class="btn" href="<?= e(url(['categorias' => 1])) ?>#categorias"><i class="fa-solid fa-tags" aria-hidden="true"></i> Categorias</a><?php endif; ?>
            <a class="btn" href="<?= e(url(['mov' => 'SAIDA', 'novo' => null, 'editar' => null])) ?>#movimento"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Registrar saída</a>
            <a class="btn" href="<?= e(url(['mov' => 'ENTRADA', 'novo' => null, 'editar' => null])) ?>#movimento"><i class="fa-solid fa-download" aria-hidden="true"></i> Registrar entrada</a>
            <a class="btn btn-primario" href="<?= e(url(['novo' => 1, 'editar' => null, 'mov' => null])) ?>#formulario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo item</a>
          </div>
        </form>

        <?php if ($alvoExcluir): ?>
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir item</h2>
            <p>Excluir <strong><?= e($alvoExcluir['nome']) ?></strong> do estoque? Se houver vendas, compras ou movimentações, ele será apenas inativado.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="<?= (int) $alvoExcluir['id'] ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="<?= e(url(['excluir' => null])) ?>">Cancelar</a>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($dadosCat) { include __DIR__ . '/_partials/categorias.php'; } ?>

        <?php if ($mov): ?>
          <section class="painel-card form-card" id="movimento" aria-labelledby="t-mov">
            <div class="painel-header"><h2 id="t-mov"><?= $mov === 'ENTRADA' ? 'Registrar entrada' : 'Registrar saída' ?></h2></div>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="movimentar">
              <input type="hidden" name="tipo" value="<?= e($mov) ?>">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="m-produto">Produto</label>
                  <select id="m-produto" name="produto_id" class="input-dash" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($produtosMov as $p): ?>
                      <option value="<?= (int) $p['id'] ?>"<?= $produtoSel === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['nome']) ?> (<?= (int) $p['estoque'] ?> <?= e($p['unidade']) ?>)</option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="m-qtd">Quantidade</label>
                  <input type="number" id="m-qtd" name="quantidade" class="input-dash" min="1" step="1" inputmode="numeric" required>
                </div>
                <div class="grupo-input">
                  <label for="m-obs">Observação (opcional)</label>
                  <input type="text" id="m-obs" name="observacao" class="input-dash" maxlength="255" placeholder="<?= $mov === 'ENTRADA' ? 'Ex.: Compra #214' : 'Ex.: Produção do jantar' ?>">
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario"><?= $mov === 'ENTRADA' ? 'Registrar entrada' : 'Registrar saída' ?></button>
                <a class="btn btn-secundario" href="<?= e(url(['mov' => null, 'produto' => null])) ?>">Cancelar</a>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($formAberto && ($editarId === 0 || $item)): ?>
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form"><?= $i['id'] ? 'Editar item do estoque' : 'Novo item do estoque' ?></h2></div>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="salvar">
              <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="e-nome">Nome</label>
                  <input type="text" id="e-nome" name="nome" class="input-dash" maxlength="200" required value="<?= e($i['nome']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="e-cat">Categoria</label>
                  <select id="e-cat" name="categoria_id" class="input-dash" required>
                    <?php foreach (['CARDAPIO' => 'Itens do cardápio', 'INSUMO' => 'Insumos e materiais'] as $tipoCat => $rotuloGrupo): ?>
                      <?php $opcoesTipo = array_filter($categoriasAtivas, static fn($c) => $c['tipo'] === $tipoCat || (int) $c['id'] === (int) $i['categoria_id'] && $c['tipo'] === $tipoCat); ?>
                      <?php if ($opcoesTipo): ?>
                        <optgroup label="<?= e($rotuloGrupo) ?>">
                          <?php foreach ($opcoesTipo as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $i['categoria_id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option>
                          <?php endforeach; ?>
                        </optgroup>
                      <?php endif; ?>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="e-un">Unidade de medida</label>
                  <input type="text" id="e-un" name="unidade" class="input-dash" maxlength="6" placeholder="UN, KG, L..." required value="<?= e($i['unidade']) ?>">
                </div>
                <?php if (!$i['id']): ?>
                  <div class="grupo-input">
                    <label for="e-est">Quantidade inicial</label>
                    <input type="number" id="e-est" name="estoque" class="input-dash" min="0" step="1" value="0">
                  </div>
                <?php endif; ?>
                <div class="grupo-input">
                  <label for="e-min">Estoque mínimo</label>
                  <input type="number" id="e-min" name="estoque_minimo" class="input-dash" min="0" step="1" required value="<?= (int) $i['estoque_minimo'] ?>">
                </div>
                <div class="grupo-input">
                  <label for="e-val">Validade (opcional)</label>
                  <input type="date" id="e-val" name="validade" class="input-dash" value="<?= e($i['validade']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="e-preco">Preço unitário (R$)</label>
                  <input type="number" id="e-preco" name="preco" class="input-dash" min="0" step="0.01" inputmode="decimal" required value="<?= e($i['preco']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="e-cod">Código de barras (opcional)</label>
                  <input type="text" id="e-cod" name="codigo_barras" class="input-dash" maxlength="14" inputmode="numeric" value="<?= e($i['codigo_barras']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="e-ncm">NCM (opcional)</label>
                  <input type="text" id="e-ncm" name="ncm" class="input-dash" maxlength="8" inputmode="numeric" value="<?= e($i['ncm']) ?>">
                </div>
                <div class="grupo-input largo">
                  <label for="e-desc">Descrição</label>
                  <textarea id="e-desc" name="descricao" class="input-dash" maxlength="255" required><?= e($i['descricao']) ?></textarea>
                </div>
                <div class="grupo-input">
                  <label for="e-status">Situação</label>
                  <select id="e-status" name="status" class="input-dash">
                    <option value="ATIVO"<?= $i['status'] === 'ATIVO' ? ' selected' : '' ?>>Ativo</option>
                    <option value="INATIVO"<?= $i['status'] === 'INATIVO' ? ' selected' : '' ?>>Inativo</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario"><?= $i['id'] ? 'Salvar alterações' : 'Cadastrar item' ?></button>
                <a class="btn btn-secundario" href="<?= e(url(['novo' => null, 'editar' => null])) ?>">Cancelar</a>
                <?php if ($i['id'] && $ehGerente): ?>
                  <a class="btn btn-perigo" href="<?= e(url(['excluir' => $i['id'], 'editar' => null])) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</a>
                <?php endif; ?>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <div class="estoque-grid">
          <section class="painel-card painel-tabela" aria-label="Produtos em estoque">
            <?php if (!$itens): ?>
              <p class="vazio"><?= ($busca || $situacao !== 'TODOS' || $categoriaFiltro) ? 'Nenhum item encontrado. Tente outra busca ou outro filtro.' : 'Nenhum item no estoque. Use "Novo item" para cadastrar o primeiro.' ?></p>
            <?php else: ?>
              <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de produtos, role para os lados se necessário">
                <table class="tabela">
                  <thead>
                    <tr>
                      <th scope="col">Produto</th>
                      <th scope="col">Em estoque</th>
                      <th scope="col" class="num">Mínimo</th>
                      <th scope="col">Validade</th>
                      <th scope="col">Situação</th>
                      <th scope="col"><span class="sr-only">Ações</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($itens as $it): [$classe, $texto] = $situacoes[$it['situacao']] ?? $situacoes['OK']; $critico = in_array($it['situacao'], ['CRITICO', 'VENCIDO'], true); ?>
                      <tr>
                        <td><span class="celula-dupla"><strong><?= e($it['nome']) ?></strong><small><?= e($it['categoria']) ?></small></span></td>
                        <td>
                          <div class="nivel<?= $critico ? ' critico' : '' ?>" style="--nivel: <?= (int) $it['nivel'] ?>%">
                            <span class="nivel-valor"><?= (int) $it['estoque'] ?> <?= e($it['unidade']) ?></span>
                            <span class="nivel-barra" aria-hidden="true"><span></span></span>
                          </div>
                        </td>
                        <td class="num"><?= (int) $it['estoque_minimo'] ?> <?= e($it['unidade']) ?></td>
                        <td class="data"><?= $it['validade'] ? data_br($it['validade']) : 'Sem validade' ?></td>
                        <td><span class="status <?= $classe ?>"><?= e($texto) ?></span></td>
                        <td class="acoes">
                          <?php if ($it['status'] === 'ATIVO'): ?>
                            <a class="btn btn-pequeno<?= $critico ? '' : ' btn-fantasma' ?>" href="<?= e(url(['mov' => 'ENTRADA', 'produto' => $it['id'], 'novo' => null, 'editar' => null])) ?>#movimento">Repor<span class="sr-only"> <?= e($it['nome']) ?></span></a>
                          <?php endif; ?>
                          <a class="btn btn-pequeno btn-fantasma" href="<?= e(url(['editar' => $it['id'], 'novo' => null, 'mov' => null])) ?>#formulario"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> <?= e($it['nome']) ?></span></a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?= paginacao($pagina, $paginasTotal, $totalFiltro, POR_PAGINA) ?>
            <?php endif; ?>
          </section>

          <section class="painel-card">
            <div class="painel-header"><h2>Movimentações de hoje</h2></div>
            <ul class="movimentos">
              <?php if (!$movimentos): ?>
                <li class="movimento"><span class="celula-dupla"><strong>Sem movimentações hoje</strong><small>Entradas e saídas aparecem aqui.</small></span></li>
              <?php endif; ?>
              <?php foreach ($movimentos as $m): ?>
                <li class="movimento">
                  <span class="movimento-qtd <?= $m['tipo'] === 'ENTRADA' ? 'entrada' : 'saida' ?>"><?= $m['tipo'] === 'ENTRADA' ? '+' : '−' ?><?= (int) $m['quantidade'] ?> <?= e($m['unidade']) ?></span>
                  <span class="celula-dupla"><strong><?= e($m['produto']) ?></strong><small><?= e($m['usuario']) ?> · <?= e($m['hora']) ?><?= $m['observacao'] ? ' · ' . e($m['observacao']) : '' ?></small></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        </div>

<?php include __DIR__ . '/_partials/rodape.php'; ?>
