<?php
/** Painel de categorias (gerência). Espera $dadosCat = dados_categorias(...) e $rotuloCat. */
$ed = $dadosCat['editar'];
$abrirForm = $dadosCat['novo'] || $ed;
$c = $ed ?? ['id' => 0, 'nome' => '', 'descricao' => '', 'status' => 'ATIVO'];
?>
<section class="painel-card form-card" id="categorias" aria-labelledby="t-cats">
  <div class="painel-header">
    <h2 id="t-cats">Categorias <?= e($rotuloCat) ?></h2>
    <span class="toolbar-grupo" style="width:auto">
      <a class="btn btn-pequeno" href="<?= e(url(['cat_novo' => 1, 'cat' => null, 'cat_excluir' => null])) ?>#categorias"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova categoria</a>
      <a class="gaveta-fechar" href="<?= e(url(['categorias' => null, 'cat' => null, 'cat_novo' => null, 'cat_excluir' => null])) ?>" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
    </span>
  </div>
  <div class="gaveta-corpo">

  <?php if ($dadosCat['excluir']): $x = $dadosCat['excluir']; ?>
    <div class="confirmar-card">
      <p>Excluir a categoria <strong><?= e($x['nome']) ?></strong>? Só é possível se ela não tiver itens.</p>
      <form method="post" action="<?= e(url()) ?>">
        <?= csrf_campo() ?>
        <input type="hidden" name="acao" value="cat_excluir">
        <input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
        <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
        <a class="btn btn-secundario" href="<?= e(url(['cat_excluir' => null])) ?>#categorias">Cancelar</a>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($abrirForm): ?>
    <form method="post" action="<?= e(url()) ?>">
      <?= csrf_campo() ?>
      <input type="hidden" name="acao" value="cat_salvar">
      <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
      <div class="form-grid">
        <div class="grupo-input">
          <label for="cat-nome">Nome</label>
          <input type="text" id="cat-nome" name="nome" class="input-dash" maxlength="200" required value="<?= e($c['nome']) ?>">
        </div>
        <div class="grupo-input">
          <label for="cat-status">Situação</label>
          <select id="cat-status" name="status" class="input-dash">
            <option value="ATIVO"<?= $c['status'] === 'ATIVO' ? ' selected' : '' ?>>Ativa</option>
            <option value="INATIVO"<?= $c['status'] === 'INATIVO' ? ' selected' : '' ?>>Inativa</option>
          </select>
        </div>
        <div class="grupo-input largo">
          <label for="cat-desc">Descrição (opcional)</label>
          <input type="text" id="cat-desc" name="descricao" class="input-dash" maxlength="255" value="<?= e($c['descricao']) ?>">
        </div>
      </div>
      <div class="form-acoes">
        <button type="submit" class="btn btn-primario"><?= $c['id'] ? 'Salvar categoria' : 'Criar categoria' ?></button>
        <a class="btn btn-secundario" href="<?= e(url(['cat' => null, 'cat_novo' => null])) ?>">Cancelar</a>
      </div>
    </form>
  <?php endif; ?>

  <?php if (!$dadosCat['lista']): ?>
    <p class="vazio">Nenhuma categoria cadastrada.</p>
  <?php else: ?>
    <div class="tabela-container">
      <table class="tabela">
        <thead><tr><th scope="col">Categoria</th><th scope="col" class="num">Itens</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
        <tbody>
          <?php foreach ($dadosCat['lista'] as $cat): ?>
            <tr>
              <td><strong><?= e($cat['nome']) ?></strong></td>
              <td class="num"><?= (int) $cat['itens'] ?></td>
              <td><span class="status <?= $cat['status'] === 'ATIVO' ? 'ativo' : 'inativo' ?>"><?= $cat['status'] === 'ATIVO' ? 'Ativa' : 'Inativa' ?></span></td>
              <td class="acoes">
                <a class="btn btn-pequeno btn-fantasma" href="<?= e(url(['cat' => $cat['id'], 'cat_novo' => null, 'cat_excluir' => null])) ?>#categorias"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> <?= e($cat['nome']) ?></span></a>
                <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['cat_excluir' => $cat['id'], 'cat' => null, 'cat_novo' => null])) ?>#categorias" aria-label="Excluir <?= e($cat['nome']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  </div>
</section>
