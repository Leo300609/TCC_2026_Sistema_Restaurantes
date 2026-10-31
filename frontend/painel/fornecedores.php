<?php
require __DIR__ . '/_partials/auth.php';
exigir_permissao($perm['compras']);

// ---------- ações (POST) ----------
tratar_post(function () {
    $acao = entrada('acao');
    $id = entrada_int('id');

    if ($acao === 'salvar') {
        chamar('sp_fornecedor_salvar', [
            $id, entrada('nome'), entrada('documento'), entrada('endereco'), entrada('endereco_numero'), entrada('telefone'),
            entrada('contato'), entrada('email'), entrada('status', 'ATIVO'), entrada('estado'), entrada('cidade'),
        ]);
        return [$id ? 'Fornecedor atualizado.' : 'Fornecedor cadastrado.', url(['editar' => null, 'novo' => null])];
    }
    if ($acao === 'status') {
        $novo = entrada('status') === 'INATIVO' ? 'INATIVO' : 'ATIVO';
        chamar('sp_fornecedor_status', [$id, $novo]);
        return $novo === 'ATIVO' ? 'Fornecedor reativado.' : 'Fornecedor inativado.';
    }
    if ($acao === 'excluir') {
        $r = chamar_valor('sp_fornecedor_excluir', [$id]);
        return [$r === 'INATIVADO' ? 'O fornecedor tem compras no histórico, então foi apenas inativado.' : 'Fornecedor excluído.', url(['excluir' => null, 'editar' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
const POR_PAGINA = 10;
$busca = entrada('q');
$status = in_array(entrada('status'), ['ATIVO', 'INATIVO'], true) ? entrada('status') : '';
$pagina = max(1, entrada_int('pagina', 1));

$contagem = ler(fn() => chamar_um('sp_fornecedor_contagem', [$busca]), ['todos' => 0, 'ativos' => 0, 'inativos' => 0]);
$totalFiltro = (int) ($status === 'ATIVO' ? $contagem['ativos'] : ($status === 'INATIVO' ? $contagem['inativos'] : $contagem['todos']));
$paginasTotal = max(1, (int) ceil($totalFiltro / POR_PAGINA));
$pagina = min($pagina, $paginasTotal);

$fornecedores = ler(fn() => chamar('sp_fornecedor_listar', [$busca, $status, POR_PAGINA, ($pagina - 1) * POR_PAGINA]));
$estados = ler(fn() => chamar('sp_estado_listar'));

$editar = entrada_int('editar');
$formAberto = isset($_GET['novo']) || $editar > 0;
$fornecedor = $editar > 0 ? ler(fn() => chamar_um('sp_fornecedor_obter', [$editar]), null) : null;
$f = $fornecedor ?? ['id' => 0, 'nome' => '', 'documento' => '', 'telefone' => '', 'endereco' => '', 'endereco_numero' => '', 'estado' => 'SP', 'cidade' => '', 'contato' => '', 'email' => '', 'status' => 'ATIVO'];
$produtosFornecedor = $fornecedor ? ler(fn() => chamar('sp_fornecedor_produtos', [$editar])) : [];

$excluirId = entrada_int('excluir');
$alvoExcluir = $excluirId > 0 ? ler(fn() => chamar_um('sp_fornecedor_obter', [$excluirId]), null) : null;

$titulo = 'Fornecedores';
$subtitulo = 'Quem abastece a cozinha e quanto você já comprou de cada um';
$paginaAtual = 'fornecedores';
include __DIR__ . '/_partials/topo.php';
?>

        <form class="toolbar" method="get" action="fornecedores.php">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar fornecedor</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="<?= e($busca) ?>" placeholder="Buscar por nome, contato, CNPJ ou telefone" autocomplete="off">
            </label>
            <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Situação">
              <?php foreach ([['', 'Todos', $contagem['todos']], ['ATIVO', 'Ativos', $contagem['ativos']], ['INATIVO', 'Inativos', $contagem['inativos']]] as [$v, $r, $q]): ?>
                <a class="filtro<?= $status === $v ? ' ativo' : '' ?>" href="<?= e(url(['status' => $v ?: null, 'pagina' => null])) ?>"><?= $r ?> <span><?= (int) $q ?></span></a>
              <?php endforeach; ?>
            </div>
          </div>
          <a class="btn btn-primario" href="<?= e(url(['novo' => 1, 'editar' => null])) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo fornecedor</a>
        </form>

        <section class="painel-card painel-tabela" aria-label="Lista de fornecedores">
          <?php if (!$fornecedores): ?>
            <p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-truck-field" aria-hidden="true"></i></span><?= $busca ? 'Nenhum fornecedor encontrado.' : 'Nenhum fornecedor cadastrado. Use "Novo fornecedor" para começar.' ?></p>
          <?php else: ?>
            <div class="tabela-container">
              <table class="tabela">
                <thead>
                  <tr>
                    <th scope="col">Fornecedor</th>
                    <th scope="col">Contato</th>
                    <th scope="col">Cidade</th>
                    <th scope="col" class="num">Produtos</th>
                    <th scope="col" class="num">Compras</th>
                    <th scope="col" class="num">Total comprado</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($fornecedores as $fo): $inativo = $fo['status'] === 'INATIVO'; ?>
                    <tr class="<?= $inativo ? 'linha-apagada' : '' ?>">
                      <td>
                        <div class="pessoa">
                          <span class="avatar <?= $corAvatar($fo['nome']) ?>" aria-hidden="true"><i class="fa-solid fa-truck" style="font-size:12px"></i></span>
                          <span class="celula-dupla"><strong><?= e($fo['nome']) ?></strong><small><?= strlen($fo['documento']) === 14 ? 'CNPJ' : 'CPF' ?> <?= e(documento_br($fo['documento'])) ?></small></span>
                        </div>
                      </td>
                      <td><span class="celula-dupla"><strong><?= e(telefone_br($fo['telefone'])) ?></strong><small><?= e($fo['contato'] ?: ($fo['email'] ?: '—')) ?></small></span></td>
                      <td><?= e($fo['cidade']) ?>, <?= e($fo['estado']) ?></td>
                      <td class="num"><?= (int) $fo['produtos'] ?></td>
                      <td class="num"><span class="celula-dupla"><strong><?= (int) $fo['compras'] ?></strong><small><?= $fo['ultima_compra'] ? 'última ' . e($fo['ultima_compra']) : 'nenhuma' ?></small></span></td>
                      <td class="num"><strong><?= moeda($fo['total_comprado']) ?></strong></td>
                      <td><span class="status <?= $inativo ? 'inativo' : 'ativo' ?>"><?= $inativo ? 'Inativo' : 'Ativo' ?></span></td>
                      <td class="acoes">
                        <?php if (!$inativo): ?><a class="btn btn-pequeno" href="compras.php?novo=1&amp;fornecedor=<?= (int) $fo['id'] ?>"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Comprar</a><?php endif; ?>
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['editar' => $fo['id'], 'novo' => null])) ?>" aria-label="Editar <?= e($fo['nome']) ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                        <form class="em-linha" method="post" action="<?= e(url()) ?>">
                          <?= csrf_campo() ?>
                          <input type="hidden" name="acao" value="status">
                          <input type="hidden" name="id" value="<?= (int) $fo['id'] ?>">
                          <input type="hidden" name="status" value="<?= $inativo ? 'ATIVO' : 'INATIVO' ?>">
                          <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="<?= $inativo ? 'Reativar' : 'Inativar' ?>" aria-label="<?= $inativo ? 'Reativar' : 'Inativar' ?> <?= e($fo['nome']) ?>"><i class="fa-solid <?= $inativo ? 'fa-rotate-left' : 'fa-ban' ?>" aria-hidden="true"></i></button>
                        </form>
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['excluir' => $fo['id']])) ?>" title="Excluir" aria-label="Excluir <?= e($fo['nome']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?= paginacao($pagina, $paginasTotal, $totalFiltro, POR_PAGINA) ?>
          <?php endif; ?>
        </section>

        <?php if ($alvoExcluir): ?>
          <section class="confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir fornecedor?</h2>
            <p>Excluir <strong><?= e($alvoExcluir['nome']) ?></strong>? Se houver compras ou produtos vinculados, ele será apenas inativado para preservar o histórico.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="<?= (int) $alvoExcluir['id'] ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="<?= e(url(['excluir' => null])) ?>" data-fechar>Cancelar</a>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($formAberto && ($editar === 0 || $fornecedor)): ?>
          <section class="painel-card form-card" aria-labelledby="t-form">
            <div class="painel-header">
              <h2 id="t-form"><?= $f['id'] ? 'Editar fornecedor' : 'Novo fornecedor' ?></h2>
              <a class="gaveta-fechar" href="<?= e(url(['novo' => null, 'editar' => null])) ?>" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="salvar">
              <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="f-nome">Razão social ou nome</label>
                  <input type="text" id="f-nome" name="nome" class="input-dash" maxlength="255" required value="<?= e($f['nome']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="f-doc">CNPJ ou CPF</label>
                  <input type="text" id="f-doc" name="documento" class="input-dash" inputmode="numeric" maxlength="18" required data-mascara="documento" value="<?= e(documento_br($f['documento'])) ?>">
                </div>
                <div class="grupo-input">
                  <label for="f-tel">Telefone</label>
                  <input type="tel" id="f-tel" name="telefone" class="input-dash" maxlength="16" required data-mascara="telefone" placeholder="(11) 3333-4444" value="<?= e(telefone_br($f['telefone'])) ?>">
                </div>
                <div class="grupo-input">
                  <label for="f-contato">Pessoa de contato (opcional)</label>
                  <input type="text" id="f-contato" name="contato" class="input-dash" maxlength="255" value="<?= e($f['contato']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="f-email">E-mail (opcional)</label>
                  <input type="email" id="f-email" name="email" class="input-dash" maxlength="255" value="<?= e($f['email']) ?>">
                </div>
                <div class="grupo-input largo">
                  <label for="f-end">Endereço (rua e bairro)</label>
                  <input type="text" id="f-end" name="endereco" class="input-dash" maxlength="255" required value="<?= e($f['endereco']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="f-num">Número</label>
                  <input type="text" id="f-num" name="endereco_numero" class="input-dash" maxlength="20" required value="<?= e($f['endereco_numero']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="f-uf">Estado</label>
                  <select id="f-uf" name="estado" class="input-dash" required>
                    <?php foreach ($estados as $uf): ?>
                      <option value="<?= e($uf['sigla']) ?>"<?= $uf['sigla'] === $f['estado'] ? ' selected' : '' ?>><?= e($uf['nome']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="f-cidade">Cidade</label>
                  <input type="text" id="f-cidade" name="cidade" class="input-dash" maxlength="200" required value="<?= e($f['cidade']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="f-status">Situação</label>
                  <select id="f-status" name="status" class="input-dash">
                    <option value="ATIVO"<?= $f['status'] === 'ATIVO' ? ' selected' : '' ?>>Ativo</option>
                    <option value="INATIVO"<?= $f['status'] === 'INATIVO' ? ' selected' : '' ?>>Inativo</option>
                  </select>
                </div>
              </div>

              <?php if ($produtosFornecedor): ?>
                <h3 class="subtitulo-form">Produtos e último preço pago</h3>
                <table class="tabela" style="font-size:13px">
                  <thead><tr><th scope="col">Produto</th><th scope="col" class="num">Preço</th></tr></thead>
                  <tbody>
                    <?php foreach ($produtosFornecedor as $p): ?>
                      <tr><td><?= e($p['nome']) ?> <small class="muted">(<?= e($p['unidade']) ?>)</small></td><td class="num"><?= moeda($p['preco']) ?></td></tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>

              <div class="form-acoes">
                <button type="submit" class="btn btn-primario"><?= $f['id'] ? 'Salvar alterações' : 'Cadastrar fornecedor' ?></button>
                <a class="btn btn-secundario" href="<?= e(url(['novo' => null, 'editar' => null])) ?>" data-fechar>Cancelar</a>
              </div>
            </form>
          </section>
        <?php endif; ?>

<?php include __DIR__ . '/_partials/rodape.php'; ?>
