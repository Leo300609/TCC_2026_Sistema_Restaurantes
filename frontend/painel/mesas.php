<?php
require __DIR__ . '/_partials/auth.php';
exigir_permissao($perm['mesas']);
$ehGerente = $perm['gerente'];

// ---------- ações (POST) ----------
tratar_post(function () use ($ehGerente) {
    $acao = entrada('acao');
    $numero = entrada('numero');

    if ($acao === 'fechar_conta') {
        $forma = entrada('forma_pagamento');
        $recebido = $forma === 'DINHEIRO' && entrada('valor_recebido') !== '' ? entrada_decimal('valor_recebido') : null;
        $r = chamar_um('sp_mesa_fechar_conta', [$numero, $forma, $recebido]);
        $troco = (float) ($r['troco'] ?? 0);
        return ["Conta da mesa {$numero} fechada: " . moeda($r['total']) . ($troco > 0 ? ' · troco de ' . moeda($troco) : '') . '.', url(['mesa' => null])];
    }
    if ($acao === 'transferir') {
        $destino = entrada('destino');
        chamar('sp_mesa_transferir', [$numero, $destino]);
        return ["Pedidos da mesa {$numero} transferidos para a mesa {$destino}.", url(['mesa' => $destino])];
    }
    if (!$ehGerente) {
        throw new ErroNegocio('Somente administradores e gerentes podem alterar as mesas.');
    }
    if ($acao === 'salvar_mesa') {
        $id = entrada_int('id');
        chamar('sp_mesa_salvar', [$id, $numero, entrada_int('lugares', 4), entrada('area'), entrada('status', 'ATIVO')]);
        return [$id ? "Mesa {$numero} atualizada." : "Mesa {$numero} cadastrada.", url(['editar' => null, 'nova' => null])];
    }
    if ($acao === 'excluir_mesa') {
        chamar('sp_mesa_excluir', [entrada_int('id')]);
        return ['Mesa excluída.', url(['editar' => null, 'excluir' => null])];
    }
    throw new ErroNegocio('Ação não reconhecida.');
});

// ---------- dados (GET) ----------
$uid = (int) $_SESSION['usuario_id'];
$cfg = ler(fn() => chamar_um('sp_config_listar', [$uid]), ['pagamentos' => 'PIX,CREDITO,DEBITO,DINHEIRO']);
$pagamentos = array_values(array_filter(explode(',', (string) $cfg['pagamentos'])));
$formas = formas_pagamento();

$mesas = ler(fn() => chamar('sp_mesa_listar'));
$porArea = [];
foreach ($mesas as $m) {
  $porArea[$m['area']][] = $m;
}
$ativas = array_filter($mesas, static fn($m) => $m['status'] === 'ATIVO');
$contar = static fn(string $s) => count(array_filter($ativas, static fn($m) => $m['situacao'] === $s));
$livres = $contar('LIVRE');
$ocupadas = $contar('OCUPADA');
$naConta = $contar('CONTA');
$lugaresOcupados = array_sum(array_map(static fn($m) => $m['situacao'] !== 'LIVRE' ? (int) $m['lugares'] : 0, $ativas));
$lugaresTotal = array_sum(array_map(static fn($m) => (int) $m['lugares'], $ativas)) ?: 1;
$totalAberto = array_sum(array_map(static fn($m) => (float) $m['total'], $ativas));

$gerenciar = $ehGerente && isset($_GET['gerenciar']);

// comanda de uma mesa
$numeroAberto = entrada('mesa');
$mesaAberta = null;
foreach ($mesas as $m) {
  if ($m['numero'] === $numeroAberto) {
    $mesaAberta = $m;
  }
}
$pedidosMesa = $mesaAberta ? ler(fn() => chamar('sp_mesa_pedidos', [$numeroAberto])) : [];
$comanda = $mesaAberta ? ler(fn() => chamar('sp_mesa_comanda', [$numeroAberto])) : [];

// cadastro de mesa (gerência)
$editarId = entrada_int('editar');
$formMesa = $ehGerente && (isset($_GET['nova']) || $editarId > 0);
$mesaEdit = $editarId > 0 ? ler(fn() => chamar_um('sp_mesa_obter', [$editarId]), null) : null;
$me = $mesaEdit ?? ['id' => 0, 'numero' => '', 'lugares' => 4, 'area' => 'Salão', 'status' => 'ATIVO'];
$excluirId = entrada_int('excluir');
$alvoExcluir = $ehGerente && $excluirId > 0 ? ler(fn() => chamar_um('sp_mesa_obter', [$excluirId]), null) : null;
$areas = array_keys($porArea) ?: ['Salão'];

$rotulos = ['LIVRE' => ['livre', 'Livre'], 'OCUPADA' => ['ocupada', 'Ocupada'], 'CONTA' => ['conta', 'Aguardando conta'], 'INATIVA' => ['inativa', 'Desativada']];
$etapas = ['NOVO' => ['novo', 'Novo'], 'PREPARO' => ['em-preparo', 'Em preparo'], 'PRONTO' => ['pronto', 'Servido']];
$tempo = static fn(?int $min) => $min === null ? '' : ($min >= 60 ? intdiv($min, 60) . 'h' . str_pad((string) ($min % 60), 2, '0', STR_PAD_LEFT) : $min . ' min');

$titulo = 'Mesas';
$subtitulo = 'Mapa do salão em tempo real';
$paginaAtual = 'mesas';
$classeCorpo = '';
include __DIR__ . '/_partials/topo.php';
?>
<script>document.body.dataset.atualizar = '<?= $mesaAberta || $formMesa || $alvoExcluir ? '0' : '30' ?>';</script>

        <div class="mesas-resumo">
          <div><span class="status livre">Livres</span> <strong><?= $livres ?></strong></div>
          <div><span class="status ocupada">Ocupadas</span> <strong><?= $ocupadas ?></strong></div>
          <div><span class="status conta">Aguardando conta</span> <strong><?= $naConta ?></strong></div>
          <div class="ocupacao">
            <span class="card-detalhe" style="justify-content:space-between"><span><?= $lugaresOcupados ?> de <?= $lugaresTotal ?> lugares em uso</span><strong style="font-size:13px;color:var(--tinta)"><?= round($lugaresOcupados / $lugaresTotal * 100) ?>%</strong></span>
            <span class="progresso azul"><span style="--nivel: <?= round($lugaresOcupados / $lugaresTotal * 100) ?>%"></span></span>
          </div>
          <?php if ($perm['financeiro']): ?><div><span class="muted">Em aberto no salão</span> <strong><?= moeda($totalAberto) ?></strong></div><?php endif; ?>
        </div>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <span class="painel-meta">Toque numa mesa livre para abrir a comanda no PDV. A tela se atualiza sozinha a cada 30 segundos.</span>
          </div>
          <?php if ($ehGerente): ?>
            <div class="toolbar-grupo">
              <?php if ($gerenciar): ?>
                <a class="btn" href="<?= e(url(['gerenciar' => null])) ?>"><i class="fa-solid fa-check" aria-hidden="true"></i> Concluir edição</a>
                <a class="btn btn-primario" href="<?= e(url(['nova' => 1])) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova mesa</a>
              <?php else: ?>
                <a class="btn" href="<?= e(url(['gerenciar' => 1])) ?>"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i> Gerenciar mesas</a>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <?php if (!$mesas): ?>
          <section class="painel-card"><p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-chair" aria-hidden="true"></i></span>Nenhuma mesa cadastrada.<?php if ($ehGerente): ?> <a href="<?= e(url(['nova' => 1, 'gerenciar' => 1])) ?>">Cadastrar a primeira mesa</a><?php endif; ?></p></section>
        <?php endif; ?>

        <?php foreach ($porArea as $area => $lista): ?>
          <h2 class="area-titulo"><?= e($area) ?> <span class="painel-meta"><?= count($lista) ?> <?= count($lista) === 1 ? 'mesa' : 'mesas' ?></span></h2>
          <div class="mesas-grid">
            <?php foreach ($lista as $m):
              [$classe, $rotulo] = $rotulos[$m['situacao']];
              $link = $gerenciar ? url(['editar' => $m['id']])
                : ($m['situacao'] === 'LIVRE' ? 'pdv.php?mesa=' . rawurlencode($m['numero']) : ($m['situacao'] === 'INATIVA' ? '#' : url(['mesa' => $m['numero']])));
            ?>
              <a class="mesa <?= $classe ?>" href="<?= e($link) ?>" aria-label="Mesa <?= e($m['numero']) ?>, <?= $rotulo ?><?= (int) $m['pedidos'] ? ', ' . moeda($m['total']) : '' ?>">
                <div class="mesa-topo">
                  <span class="mesa-numero"><small>Mesa</small><?= e($m['numero']) ?></span>
                  <span class="mesa-lugares"><i class="fa-solid fa-user" aria-hidden="true"></i> <?= (int) $m['lugares'] ?></span>
                </div>
                <span class="status <?= $classe ?>"><?= $rotulo ?></span>
                <?php if ($gerenciar): ?>
                  <span class="mesa-info"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar mesa</span>
                <?php elseif ((int) $m['pedidos'] > 0): ?>
                  <span class="mesa-info">
                    <strong><?= moeda($m['total']) ?></strong>
                    <?= (int) $m['pedidos'] ?> <?= (int) $m['pedidos'] === 1 ? 'pedido' : 'pedidos' ?> · há <?= $tempo((int) $m['minutos']) ?>
                    <?php if ((int) $m['na_cozinha']): ?><br><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> <?= (int) $m['na_cozinha'] ?> na cozinha<?php endif; ?>
                  </span>
                <?php elseif ($m['situacao'] === 'LIVRE'): ?>
                  <span class="mesa-info muted"><i class="fa-solid fa-plus" aria-hidden="true"></i> Abrir comanda</span>
                <?php endif; ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($mesaAberta):
          $totalMesa = (float) $mesaAberta['total'];
          $livresDestino = array_filter($ativas, static fn($m) => $m['situacao'] === 'LIVRE'); ?>
          <section class="painel-card form-card" aria-labelledby="t-mesa">
            <div class="painel-header">
              <h2 id="t-mesa">Mesa <?= e($mesaAberta['numero']) ?> <span class="status <?= $rotulos[$mesaAberta['situacao']][0] ?>"><?= $rotulos[$mesaAberta['situacao']][1] ?></span></h2>
              <a class="gaveta-fechar" href="<?= e(url(['mesa' => null])) ?>" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <div class="gaveta-corpo">
              <?php if (!$pedidosMesa): ?>
                <p class="vazio">Esta mesa não tem pedidos em aberto.</p>
              <?php else: ?>
                <div class="comanda">
                  <div class="comanda-topo">
                    <span class="comanda-numero">Mesa <?= e($mesaAberta['numero']) ?></span>
                    <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> aberta há <?= $tempo((int) $mesaAberta['minutos']) ?></span>
                  </div>
                  <ul class="comanda-itens">
                    <?php foreach ($comanda as $it): ?>
                      <li><span class="qtd"><?= (int) $it['quantidade'] ?>x</span> <?= e($it['nome']) ?> <span class="item-valor"><?= moeda($it['total']) ?></span></li>
                    <?php endforeach; ?>
                  </ul>
                  <div class="comanda-rodape">
                    <div class="comanda-total"><small>Total da mesa</small><strong style="font-size:20px"><?= moeda($totalMesa) ?></strong></div>
                    <a class="btn btn-pequeno" href="pdv.php?mesa=<?= rawurlencode($mesaAberta['numero']) ?>"><i class="fa-solid fa-plus" aria-hidden="true"></i> Adicionar itens</a>
                  </div>
                </div>

                <h3 class="subtitulo-form">Pedidos da mesa</h3>
                <ul class="lista-linhas" style="margin:0 -20px">
                  <?php foreach ($pedidosMesa as $pd): [$cl, $rt] = $etapas[$pd['etapa']] ?? ['', $pd['etapa']]; ?>
                    <li>
                      <span class="celula-dupla" style="flex:1"><strong>#<?= (int) $pd['id'] ?> · <?= e($pd['hora']) ?></strong><small><?= e($pd['atendente']) ?></small></span>
                      <span class="status <?= $cl ?>"><?= $rt ?></span>
                      <strong><?= moeda($pd['total']) ?></strong>
                      <a class="btn btn-pequeno btn-fantasma btn-icone" href="pedidos.php?editar=<?= (int) $pd['id'] ?>#formulario" aria-label="Editar pedido <?= (int) $pd['id'] ?>"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                    </li>
                  <?php endforeach; ?>
                </ul>

                <h3 class="subtitulo-form">Fechar conta</h3>
                <?php if ((int) $mesaAberta['na_cozinha'] > 0): ?>
                  <p class="painel-nota" style="margin:0 0 12px">Ainda há <?= (int) $mesaAberta['na_cozinha'] ?> pedido(s) na cozinha. A conta pode ser fechada quando todos estiverem servidos.</p>
                <?php endif; ?>
                <form method="post" action="<?= e(url()) ?>" id="form-conta">
                  <?= csrf_campo() ?>
                  <input type="hidden" name="acao" value="fechar_conta">
                  <input type="hidden" name="numero" value="<?= e($mesaAberta['numero']) ?>">
                  <div class="pdv-formas" role="radiogroup" aria-label="Forma de pagamento">
                    <?php foreach ($pagamentos as $i => $f): ?>
                      <label><input type="radio" name="forma_pagamento" value="<?= e($f) ?>"<?= $i === 0 ? ' checked' : '' ?>><span><i class="<?= $formas[$f][1] ?? '' ?>" aria-hidden="true"></i> <?= e($formas[$f][0] ?? $f) ?></span></label>
                    <?php endforeach; ?>
                  </div>
                  <div class="form-grid" style="margin-top:12px">
                    <div class="grupo-input">
                      <label for="m-recebido">Valor recebido (dinheiro)</label>
                      <div class="campo-dinheiro"><input type="number" id="m-recebido" name="valor_recebido" class="input-dash" min="0" step="0.01" placeholder="<?= number_format($totalMesa, 2, ',', '') ?>"></div>
                    </div>
                    <div class="grupo-input">
                      <span class="rotulo">Dividir a conta</span>
                      <select class="input-dash" id="m-dividir" aria-label="Dividir a conta por pessoas">
                        <?php for ($n = 1; $n <= max(2, (int) $mesaAberta['lugares']); $n++): ?>
                          <option value="<?= $n ?>"><?= $n === 1 ? 'Sem divisão' : "{$n} pessoas · " . moeda($totalMesa / $n) . ' cada' ?></option>
                        <?php endfor; ?>
                      </select>
                    </div>
                  </div>
                  <div class="form-acoes">
                    <button type="submit" class="btn btn-primario btn-grande"<?= (int) $mesaAberta['na_cozinha'] > 0 ? ' disabled' : '' ?>><i class="fa-solid fa-check" aria-hidden="true"></i> Fechar conta de <?= moeda($totalMesa) ?></button>
                  </div>
                </form>

                <?php if ($livresDestino): ?>
                  <h3 class="subtitulo-form">Trocar de mesa</h3>
                  <form method="post" action="<?= e(url()) ?>" class="toolbar-grupo">
                    <?= csrf_campo() ?>
                    <input type="hidden" name="acao" value="transferir">
                    <input type="hidden" name="numero" value="<?= e($mesaAberta['numero']) ?>">
                    <label class="sr-only" for="m-destino">Mesa de destino</label>
                    <select id="m-destino" name="destino" class="input-dash" style="flex:1" required>
                      <?php foreach ($livresDestino as $l): ?><option value="<?= e($l['numero']) ?>">Mesa <?= e($l['numero']) ?> · <?= e($l['area']) ?> (<?= (int) $l['lugares'] ?> lugares)</option><?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn"><i class="fa-solid fa-right-left" aria-hidden="true"></i> Transferir</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </section>
        <?php endif; ?>

        <?php if ($formMesa && ($editarId === 0 || $mesaEdit)): ?>
          <section class="painel-card form-card estreita" aria-labelledby="t-form-mesa">
            <div class="painel-header">
              <h2 id="t-form-mesa"><?= $me['id'] ? 'Editar mesa ' . e($me['numero']) : 'Nova mesa' ?></h2>
              <a class="gaveta-fechar" href="<?= e(url(['editar' => null, 'nova' => null])) ?>" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="salvar_mesa">
              <input type="hidden" name="id" value="<?= (int) $me['id'] ?>">
              <div class="form-grid">
                <div class="grupo-input">
                  <label for="me-numero">Número ou nome</label>
                  <input type="text" id="me-numero" name="numero" class="input-dash" maxlength="20" required value="<?= e($me['numero']) ?>" placeholder="Ex.: 13">
                </div>
                <div class="grupo-input">
                  <label for="me-lugares">Lugares</label>
                  <input type="number" id="me-lugares" name="lugares" class="input-dash" min="1" max="30" required value="<?= (int) $me['lugares'] ?>">
                </div>
                <div class="grupo-input">
                  <label for="me-area">Área</label>
                  <input type="text" id="me-area" name="area" class="input-dash" maxlength="50" list="areas" value="<?= e($me['area']) ?>">
                  <datalist id="areas"><?php foreach ($areas as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?></datalist>
                </div>
                <div class="grupo-input">
                  <label for="me-status">Situação</label>
                  <select id="me-status" name="status" class="input-dash">
                    <option value="ATIVO"<?= $me['status'] === 'ATIVO' ? ' selected' : '' ?>>Em uso</option>
                    <option value="INATIVO"<?= $me['status'] === 'INATIVO' ? ' selected' : '' ?>>Desativada</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario"><?= $me['id'] ? 'Salvar mesa' : 'Cadastrar mesa' ?></button>
                <a class="btn btn-secundario" href="<?= e(url(['editar' => null, 'nova' => null])) ?>" data-fechar>Cancelar</a>
                <?php if ($me['id']): ?><a class="btn btn-perigo empurra" href="<?= e(url(['excluir' => $me['id'], 'editar' => null])) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</a><?php endif; ?>
              </div>
            </form>
          </section>
        <?php endif; ?>

        <?php if ($alvoExcluir): ?>
          <section class="confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir mesa <?= e($alvoExcluir['numero']) ?>?</h2>
            <p>A mesa sai do mapa do salão. Os pedidos antigos dela continuam no histórico.</p>
            <form method="post" action="<?= e(url()) ?>">
              <?= csrf_campo() ?>
              <input type="hidden" name="acao" value="excluir_mesa">
              <input type="hidden" name="id" value="<?= (int) $alvoExcluir['id'] ?>">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir mesa</button>
              <a class="btn btn-secundario" href="<?= e(url(['excluir' => null])) ?>" data-fechar>Cancelar</a>
            </form>
          </section>
        <?php endif; ?>

<?php
$rodapeExtra = <<<'HTML'
<script>
  // Dinheiro: mostra o troco ao digitar o valor recebido
  (() => {
    const form = document.getElementById('form-conta');
    if (!form) return;
    const recebido = form.querySelector('[name=valor_recebido]');
    const atualizar = () => { recebido.closest('.grupo-input').hidden = form.querySelector('[name=forma_pagamento]:checked')?.value !== 'DINHEIRO'; };
    form.querySelectorAll('[name=forma_pagamento]').forEach((r) => r.addEventListener('change', atualizar));
    atualizar();
  })();
</script>
HTML;
include __DIR__ . '/_partials/rodape.php';
?>
