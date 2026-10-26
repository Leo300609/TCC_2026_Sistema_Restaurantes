<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/bd.php';
require_once __DIR__ . '/../../backend/lib/web.php';
require_once __DIR__ . '/../../backend/modulos/categorias.php';

$ehGerente = $verFinanceiro;          // administrador ou gerente
$podeDisponibilidade = $verEstoque;   // gerente ou cozinha

// ---------- ações (POST) ----------
tratar_categorias('CARDAPIO', $ehGerente);

tratar_post(function () use ($ehGerente, $podeDisponibilidade) {
    $acao = entrada('acao');
    $id = entrada_int('id');

    if ($acao === 'salvar') {
        if (!$ehGerente) {
            throw new ErroNegocio('Somente administradores e gerentes podem editar o cardápio.');
        }
        chamar('sp_produto_salvar', [
            $id, 'CARDAPIO', entrada('nome'), entrada('descricao'), entrada_decimal('preco'), entrada_int('categoria_id'),
            entrada('status', 'ATIVO'), entrada('codigo_barras'), '', 'UN', 0, null, 0, (int) $_SESSION['usuario_id'],
        ]);
        return [$id ? 'Item atualizado.' : 'Item adicionado ao cardápio.', url(['editar' => null, 'novo' => null])];
    }
    if ($acao === 'disponibilidade') {
        if (!$ehGerente && !$podeDisponibilidade) {
            throw new ErroNegocio('Você não pode alterar a disponibilidade dos itens.');
        }
        $disp = entrada('disponivel') === '1' ? 1 : 0;
        chamar('sp_cardapio_disponibilidade', [$id, $disp]);
        return $disp ? 'Item disponível no cardápio.' : 'Item marcado como indisponível.';
    }
    if ($acao === 'excluir') {
        if (!$ehGerente) {
            throw new ErroNegocio('Somente administradores e gerentes podem excluir itens.');
        }
        $r = chamar_valor('sp_produto_excluir', [$id, 'CARDAPIO']);
        return [$r === 'INATIVADO' ? 'O item tem vendas ou movimentações no histórico, então foi marcado como indisponível em vez de excluído.' : 'Item excluído do cardápio.', url(['excluir' => null, 'editar' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
$busca = entrada('q');
$categoriaFiltro = entrada_int('categoria');
$itens = ler(fn() => chamar('sp_cardapio_listar', [$busca]));
$categorias = ler(fn() => chamar('sp_categoria_listar', ['CARDAPIO']));

$total = count($itens);
$visiveis = $categoriaFiltro ? array_values(array_filter($itens, static fn($i) => (int) $i['categoria_id'] === $categoriaFiltro)) : $itens;
$grupos = [];
foreach ($visiveis as $i) {
    $grupos[$i['categoria']][] = $i;
}
$porCategoria = [];
foreach ($itens as $i) {
    $porCategoria[(int) $i['categoria_id']] = ($porCategoria[(int) $i['categoria_id']] ?? 0) + 1;
}

$editarId = entrada_int('editar');
$formAberto = $ehGerente && (isset($_GET['novo']) || $editarId > 0);
$item = $editarId > 0 ? ler(fn() => chamar_um('sp_produto_obter', [$editarId]), null) : null;
$i = $item ?? ['id' => 0, 'nome' => '', 'descricao' => '', 'preco' => '', 'categoria_id' => 0, 'codigo_barras' => '', 'status' => 'ATIVO'];
$categoriasAtivas = array_values(array_filter($categorias, static fn($c) => $c['status'] === 'ATIVO' || (int) $c['id'] === (int) $i['categoria_id']));

$excluirId = entrada_int('excluir');
$alvoExcluir = $ehGerente && $excluirId > 0 ? ler(fn() => chamar_um('sp_produto_obter', [$excluirId]), null) : null;

$mostrarCategorias = $ehGerente && (isset($_GET['categorias']) || isset($_GET['cat']) || isset($_GET['cat_novo']) || isset($_GET['cat_excluir']));
$dadosCat = $mostrarCategorias ? dados_categorias('CARDAPIO') : null;
$rotuloCat = 'do cardápio';

$titulo = 'Cardápio';
$subtitulo = 'Pratos, bebidas e preços disponíveis para venda';
$paginaAtual = 'cardapios';
include __DIR__ . '/_partials/topo.php';
?>

        <form class="toolbar" method="get" action="cardapios.php">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar item do cardápio</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="<?= e($busca) ?>" placeholder="Buscar item do cardápio" autocomplete="off">
            </label>
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Categoria">
              <a class="filtro<?= !$categoriaFiltro ? ' ativo' : '' ?>" href="<?= e(url(['categoria' => null])) ?>">Todos <span><?= $total ?></span></a>
              <?php foreach ($categorias as $c): if (empty($porCategoria[(int) $c['id']])) continue; ?>
                <a class="filtro<?= $categoriaFiltro === (int) $c['id'] ? ' ativo' : '' ?>" href="<?= e(url(['categoria' => $c['id']])) ?>"><?= e($c['nome']) ?> <span><?= $porCategoria[(int) $c['id']] ?></span></a>
              <?php endforeach; ?>
            </div>
          </div>
          <?php if ($ehGerente): ?>
            <div class="toolbar-grupo">
              <a class="btn" href="<?= e(url(['categorias' => 1])) ?>#categorias"><i class="fa-solid fa-tags" aria-hidden="true"></i> Categorias</a>
              <a class="btn btn-primario" href="<?= e(url(['novo' => 1, 'editar' => null, 'excluir' => null])) ?>#formulario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo item</a>
            </div>
          <?php endif; ?>
        </form>

        <?php if ($alvoExcluir): ?>
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir item</h2>
            <p>Excluir <strong><?= e($alvoExcluir['nome']) ?></strong> do cardápio? Se já houver vendas com ele, será apenas marcado como indisponível.</p>
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

        <?php if ($formAberto && ($editarId === 0 || $item)): ?>
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form"><?= $i['id'] ? 'Editar item' : 'Novo item do cardápio' ?></h2></div>
            <?php if (!$categoriasAtivas): ?>
              <p class="vazio">Crie uma categoria antes de adicionar itens ao cardápio. <a href="<?= e(url(['categorias' => 1, 'cat_novo' => 1, 'novo' => null])) ?>#categorias">Criar categoria</a></p>
            <?php else: ?>
              <form method="post" action="<?= e(url()) ?>">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
                <div class="form-grid">
                  <div class="grupo-input largo">
                    <label for="i-nome">Nome</label>
                    <input type="text" id="i-nome" name="nome" class="input-dash" maxlength="200" required value="<?= e($i['nome']) ?>">
                  </div>
                  <div class="grupo-input">
                    <label for="i-cat">Categoria</label>
                    <select id="i-cat" name="categoria_id" class="input-dash" required>
                      <?php foreach ($categoriasAtivas as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"<?= (int) $c['id'] === (int) $i['categoria_id'] ? ' selected' : '' ?>><?= e($c['nome']) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="i-preco">Preço (R$)</label>
                    <input type="number" id="i-preco" name="preco" class="input-dash" min="0.01" step="0.01" inputmode="decimal" required value="<?= e($i['preco']) ?>">
                  </div>
                  <div class="grupo-input largo">
                    <label for="i-desc">Descrição</label>
                    <textarea id="i-desc" name="descricao" class="input-dash" maxlength="255" required><?= e($i['descricao']) ?></textarea>
                  </div>
                  <div class="grupo-input">
                    <label for="i-cod">Código de barras (opcional)</label>
                    <input type="text" id="i-cod" name="codigo_barras" class="input-dash" maxlength="14" inputmode="numeric" value="<?= e($i['codigo_barras']) ?>">
                  </div>
                  <div class="grupo-input">
                    <label for="i-status">Situação</label>
                    <select id="i-status" name="status" class="input-dash">
                      <option value="ATIVO"<?= $i['status'] === 'ATIVO' ? ' selected' : '' ?>>Disponível</option>
                      <option value="INATIVO"<?= $i['status'] === 'INATIVO' ? ' selected' : '' ?>>Indisponível</option>
                    </select>
                  </div>
                </div>
                <div class="form-acoes">
                  <button type="submit" class="btn btn-primario"><?= $i['id'] ? 'Salvar alterações' : 'Adicionar ao cardápio' ?></button>
                  <a class="btn btn-secundario" href="<?= e(url(['novo' => null, 'editar' => null])) ?>">Cancelar</a>
                </div>
              </form>
            <?php endif; ?>
          </section>
        <?php endif; ?>

        <div class="painel-card cardapio">
          <?php if (!$grupos): ?>
            <p class="vazio"><?= $busca ? 'Nenhum item encontrado. Tente outro termo de busca.' : ($ehGerente ? 'O cardápio ainda está vazio. Crie uma categoria e use "Novo item" para começar.' : 'O cardápio ainda está vazio. Peça a um gerente para cadastrar os itens.') ?></p>
          <?php endif; ?>
          <?php $n = 0; foreach ($grupos as $nomeCategoria => $lista): $n++; ?>
            <section class="categoria" aria-labelledby="cat-<?= $n ?>">
              <header class="categoria-topo">
                <h2 id="cat-<?= $n ?>"><?= e($nomeCategoria) ?></h2>
                <span class="painel-meta"><?= count($lista) ?> <?= count($lista) === 1 ? 'item' : 'itens' ?></span>
              </header>
              <ul class="itens-cardapio">
                <?php foreach ($lista as $it): $disp = $it['status'] === 'ATIVO'; ?>
                  <li class="item-cardapio<?= $disp ? '' : ' indisponivel' ?>">
                    <div class="item-linha">
                      <h3 class="item-nome"><?= e($it['nome']) ?></h3>
                      <span class="item-pontos" aria-hidden="true"></span>
                      <span class="item-preco"><?= moeda($it['preco']) ?></span>
                    </div>
                    <p class="item-descricao"><?= e($it['descricao']) ?></p>
                    <div class="item-acoes">
                      <span class="status <?= $disp ? 'ativo' : 'inativo' ?>"><?= $disp ? 'Disponível' : 'Indisponível' ?></span>
                      <?php if ($ehGerente || $podeDisponibilidade): ?>
                        <form class="em-linha" method="post" action="<?= e(url()) ?>">
                          <?= csrf_campo() ?>
                          <input type="hidden" name="acao" value="disponibilidade">
                          <input type="hidden" name="id" value="<?= (int) $it['id'] ?>">
                          <input type="hidden" name="disponivel" value="<?= $disp ? '0' : '1' ?>">
                          <button type="submit" class="btn btn-pequeno btn-fantasma"><?= $disp ? 'Marcar indisponível' : 'Marcar disponível' ?><span class="sr-only"> <?= e($it['nome']) ?></span></button>
                        </form>
                      <?php endif; ?>
                      <?php if ($ehGerente): ?>
                        <a class="btn btn-pequeno btn-fantasma" href="<?= e(url(['editar' => $it['id'], 'novo' => null, 'excluir' => null])) ?>#formulario"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> <?= e($it['nome']) ?></span></a>
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['excluir' => $it['id'], 'editar' => null, 'novo' => null])) ?>" aria-label="Excluir <?= e($it['nome']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>
                      <?php endif; ?>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            </section>
          <?php endforeach; ?>
        </div>

<?php include __DIR__ . '/_partials/rodape.php'; ?>
