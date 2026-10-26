<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/bd.php';
require_once __DIR__ . '/../../backend/lib/web.php';

// ---------- ações (POST) ----------
tratar_post(function () use ($verFinanceiro) {
    $acao = entrada('acao');
    $id = entrada_int('id');

    if ($acao === 'salvar') {
        chamar('sp_cliente_salvar', [
            $id, entrada('nome'), entrada('documento'), entrada('endereco'), entrada('endereco_numero'),
            entrada('telefone'), entrada('contato'), entrada('status', 'ATIVO'), entrada('estado'), entrada('cidade'),
        ]);
        return $id ? 'Cliente atualizado.' : 'Cliente cadastrado.';
    }
    if ($acao === 'status') {
        $novo = entrada('status') === 'INATIVO' ? 'INATIVO' : 'ATIVO';
        chamar('sp_cliente_status', [$id, $novo]);
        return $novo === 'ATIVO' ? 'Cliente reativado.' : 'Cliente inativado.';
    }
    if ($acao === 'excluir') {
        if (!$verFinanceiro) {
            throw new ErroNegocio('Somente administradores e gerentes podem excluir clientes.');
        }
        $r = chamar_valor('sp_cliente_excluir', [$id]);
        return [$r === 'INATIVADO' ? 'O cliente tem pedidos, então foi apenas inativado para preservar o histórico.' : 'Cliente excluído.', url(['excluir' => null, 'editar' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
const POR_PAGINA = 10;
$busca = entrada('q');
$status = in_array(entrada('status'), ['ATIVO', 'INATIVO'], true) ? entrada('status') : '';
$pagina = max(1, entrada_int('pagina', 1));

$contagem = ler(fn() => chamar_um('sp_cliente_contagem', [$busca]), ['todos' => 0, 'ativos' => 0, 'inativos' => 0]);
$totalFiltro = (int) ($status === 'ATIVO' ? $contagem['ativos'] : ($status === 'INATIVO' ? $contagem['inativos'] : $contagem['todos']));
$paginasTotal = max(1, (int) ceil($totalFiltro / POR_PAGINA));
$pagina = min($pagina, $paginasTotal);

$clientes = ler(fn() => chamar('sp_cliente_listar', [$busca, $status, POR_PAGINA, ($pagina - 1) * POR_PAGINA]));
$estados = ler(fn() => chamar('sp_estado_listar'));

$editar = entrada_int('editar');
$formAberto = isset($_GET['novo']) || $editar > 0;
$cliente = $editar > 0 ? ler(fn() => chamar_um('sp_cliente_obter', [$editar]), null) : null;
$c = $cliente ?? ['id' => 0, 'nome' => '', 'documento' => '', 'telefone' => '', 'endereco' => '', 'endereco_numero' => '', 'estado' => 'SP', 'cidade' => '', 'contato' => '', 'status' => 'ATIVO'];

$excluirId = entrada_int('excluir');
$alvoExcluir = $excluirId > 0 ? ler(fn() => chamar_um('sp_cliente_obter', [$excluirId]), null) : null;

$titulo = 'Clientes';
$subtitulo = 'Quem compra com você e quanto já consumiu';
$paginaAtual = 'clientes';
include __DIR__ . '/_partials/topo.php';

$filtros = [['', 'Todos', $contagem['todos']], ['ATIVO', 'Ativos', $contagem['ativos']], ['INATIVO', 'Inativos', $contagem['inativos']]];
?>

        <form class="toolbar" method="get" action="clientes.php">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar cliente</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="<?= e($busca) ?>" placeholder="Buscar por nome, CPF/CNPJ ou telefone" autocomplete="off">
            </label>
            <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Situação do cliente">
              <?php foreach ($filtros as [$valor, $rotulo, $qtd]): ?>
                <a class="filtro<?= $status === $valor ? ' ativo' : '' ?>" href="<?= e(url(['status' => $valor ?: null, 'pagina' => null, 'editar' => null, 'novo' => null, 'excluir' => null])) ?>"><?= $rotulo ?> <span><?= (int) $qtd ?></span></a>
              <?php endforeach; ?>
            </div>
          </div>
          <a class="btn btn-primario" href="<?= e(url(['novo' => 1, 'editar' => null, 'excluir' => null])) ?>#formulario"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Novo cliente</a>
        </form>

        <?php if ($alvoExcluir): ?>
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir cliente</h2>
            <p>Excluir <strong><?= e($alvoExcluir['nome']) ?></strong>? Se ele já tiver pedidos, será apenas inativado para preservar o histórico.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="<?= (int) $alvoExcluir['id'] ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="<?= e(url(['excluir' => null])) ?>">Cancelar</a>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($formAberto && ($editar === 0 || $cliente)): ?>
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form"><?= $c['id'] ? 'Editar cliente' : 'Novo cliente' ?></h2></div>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="salvar">
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="c-nome">Nome ou razão social</label>
                  <input type="text" id="c-nome" name="nome" class="input-dash" maxlength="255" required value="<?= e($c['nome']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="c-doc">CPF ou CNPJ</label>
                  <input type="text" id="c-doc" name="documento" class="input-dash" inputmode="numeric" maxlength="18" required value="<?= e($c['documento']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="c-tel">Telefone</label>
                  <input type="tel" id="c-tel" name="telefone" class="input-dash" maxlength="16" placeholder="(11) 98765-4321" required value="<?= e($c['telefone']) ?>">
                </div>
                <div class="grupo-input largo">
                  <label for="c-end">Endereço (rua e bairro)</label>
                  <input type="text" id="c-end" name="endereco" class="input-dash" maxlength="400" required value="<?= e($c['endereco']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="c-num">Número</label>
                  <input type="text" id="c-num" name="endereco_numero" class="input-dash" maxlength="20" required value="<?= e($c['endereco_numero']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="c-uf">Estado</label>
                  <select id="c-uf" name="estado" class="input-dash" required>
                    <?php foreach ($estados as $uf): ?>
                      <option value="<?= e($uf['sigla']) ?>"<?= $uf['sigla'] === $c['estado'] ? ' selected' : '' ?>><?= e($uf['nome']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="grupo-input largo">
                  <label for="c-cidade">Cidade</label>
                  <input type="text" id="c-cidade" name="cidade" class="input-dash" maxlength="200" required value="<?= e($c['cidade']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="c-contato">Contato (opcional)</label>
                  <input type="text" id="c-contato" name="contato" class="input-dash" maxlength="255" placeholder="E-mail ou nome do responsável" value="<?= e($c['contato']) ?>">
                </div>
                <div class="grupo-input">
                  <label for="c-status">Situação</label>
                  <select id="c-status" name="status" class="input-dash">
                    <option value="ATIVO"<?= $c['status'] === 'ATIVO' ? ' selected' : '' ?>>Ativo</option>
                    <option value="INATIVO"<?= $c['status'] === 'INATIVO' ? ' selected' : '' ?>>Inativo</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario"><?= $c['id'] ? 'Salvar alterações' : 'Cadastrar cliente' ?></button>
                <a class="btn btn-secundario" href="<?= e(url(['novo' => null, 'editar' => null])) ?>">Cancelar</a>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <section class="painel-card painel-tabela" aria-label="Lista de clientes">
          <?php if (!$clientes): ?>
            <p class="vazio">Nenhum cliente encontrado.</p>
          <?php else: ?>
            <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de clientes, role para os lados se necessário">
              <table class="tabela">
                <thead>
                  <tr>
                    <th scope="col">Cliente</th>
                    <th scope="col">Telefone</th>
                    <th scope="col">Cidade</th>
                    <th scope="col" class="num">Pedidos</th>
                    <th scope="col" class="num">Total gasto</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($clientes as $cl): $inativo = $cl['status'] === 'INATIVO'; ?>
                    <tr>
                      <td>
                        <div class="pessoa">
                          <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($cl['nome'], 0, 1))) ?></span>
                          <span class="celula-dupla"><strong><?= e($cl['nome']) ?></strong><small><?= strlen($cl['documento']) === 14 ? 'CNPJ' : 'CPF' ?> <?= e($cl['documento']) ?></small></span>
                        </div>
                      </td>
                      <td><?= e($cl['telefone']) ?></td>
                      <td><?= e($cl['cidade']) ?>, <?= e($cl['estado']) ?></td>
                      <td class="num"><?= (int) $cl['pedidos'] ?></td>
                      <td class="num"><?= moeda($cl['total_gasto']) ?></td>
                      <td><span class="status <?= $inativo ? 'inativo' : 'ativo' ?>"><?= $inativo ? 'Inativo' : 'Ativo' ?></span></td>
                      <td class="acoes">
                        <a class="btn btn-pequeno btn-fantasma" href="<?= e(url(['editar' => $cl['id'], 'novo' => null, 'excluir' => null])) ?>#formulario"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> <?= e($cl['nome']) ?></span></a>
                        <form class="em-linha" method="post" action="<?= e(url()) ?>">
                          <?= csrf_campo() ?>
                          <input type="hidden" name="acao" value="status">
                          <input type="hidden" name="id" value="<?= (int) $cl['id'] ?>">
                          <input type="hidden" name="status" value="<?= $inativo ? 'ATIVO' : 'INATIVO' ?>">
                          <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="<?= $inativo ? 'Reativar' : 'Inativar' ?> cliente" aria-label="<?= $inativo ? 'Reativar' : 'Inativar' ?> <?= e($cl['nome']) ?>"><i class="fa-solid <?= $inativo ? 'fa-rotate-left' : 'fa-user-slash' ?>" aria-hidden="true"></i></button>
                        </form>
                        <?php if ($verFinanceiro): ?>
                          <a class="btn btn-pequeno btn-fantasma btn-icone" href="<?= e(url(['excluir' => $cl['id'], 'editar' => null, 'novo' => null])) ?>" title="Excluir cliente" aria-label="Excluir <?= e($cl['nome']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>
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

<?php include __DIR__ . '/_partials/rodape.php'; ?>
