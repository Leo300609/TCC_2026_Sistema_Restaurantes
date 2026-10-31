<?php
require __DIR__ . '/_partials/auth.php';
exigir_permissao($perm['cozinha']);

// ---------- ações (POST) ----------
tratar_post(function () {
    $id = entrada_int('id');
    $destino = entrada('etapa');
    if (entrada('acao') !== 'etapa' || !in_array($destino, ['NOVO', 'PREPARO', 'PRONTO'], true)) {
        throw new ErroNegocio('Ação não reconhecida.');
    }
    chamar('sp_pedido_etapa', [$id, $destino]);
    return match ($destino) {
        'PREPARO' => "Pedido #{$id} em preparo.",
        'PRONTO' => "Pedido #{$id} pronto para servir.",
        default => "Pedido #{$id} voltou para a fila.",
    };
});

// ---------- dados (GET) ----------
$uid = (int) $_SESSION['usuario_id'];
$cfg = ler(fn() => chamar_um('sp_config_listar', [$uid]), ['tempo_preparo' => 20]);
$limite = max(1, (int) $cfg['tempo_preparo']);

$fila = array_values(array_filter(ler(fn() => chamar('sp_pedido_quadro')), static fn($p) => in_array($p['etapa'], ['NOVO', 'PREPARO'], true)));
$prontos = array_values(array_filter(ler(fn() => chamar('sp_pedido_quadro')), static fn($p) => $p['etapa'] === 'PRONTO'));
$itens = [];
foreach (ler(fn() => chamar('sp_pedido_quadro_itens')) as $it) {
  $itens[(int) $it['venda_id']][] = $it;
}
$tipo = in_array(entrada('tipo'), ['SALAO', 'BALCAO', 'DELIVERY'], true) ? entrada('tipo') : '';
if ($tipo) {
  $fila = array_values(array_filter($fila, static fn($p) => $p['tipo'] === $tipo));
}
$novos = count(array_filter($fila, static fn($p) => $p['etapa'] === 'NOVO'));
$preparo = count($fila) - $novos;
$atrasados = count(array_filter($fila, static fn($p) => (int) $p['minutos'] > $limite));

// resumo do que produzir agora (soma por item)
$producao = [];
foreach ($fila as $p) {
  foreach ($itens[(int) $p['id']] ?? [] as $it) {
    $producao[$it['nome']] = ($producao[$it['nome']] ?? 0) + (int) $it['quantidade'];
  }
}
arsort($producao);

$origem = static fn(array $p) => match ($p['tipo']) {
  'SALAO' => '<i class="fa-solid fa-chair" aria-hidden="true"></i> Mesa ' . e($p['mesa']),
  'BALCAO' => '<i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão' . ($p['cliente'] ? ' · ' . e($p['cliente']) : ''),
  default => '<i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · ' . e($p['cliente'] ?: 'sem cliente'),
};

$titulo = 'Cozinha';
$subtitulo = 'Fila de preparo por ordem de chegada';
$paginaAtual = 'cozinha';
include __DIR__ . '/_partials/topo.php';
?>
<script>document.body.dataset.atualizar = '20';</script>

        <div class="cozinha-topo">
          <div class="toolbar-grupo">
            <span class="relogio" id="relogio"><?= date('H:i') ?></span>
            <div class="cozinha-legenda">
              <span class="status novo">Novos: <?= $novos ?></span>
              <span class="status em-preparo">Em preparo: <?= $preparo ?></span>
              <span class="status <?= $atrasados ? 'atrasado' : 'ok' ?>">Acima de <?= $limite ?> min: <?= $atrasados ?></span>
            </div>
          </div>
          <div class="toolbar-grupo">
            <div class="filtros" role="group" aria-label="Tipo de pedido">
              <?php foreach (['' => 'Todos', 'SALAO' => 'Salão', 'BALCAO' => 'Balcão', 'DELIVERY' => 'Delivery'] as $v => $r): ?>
                <a class="filtro<?= $tipo === $v ? ' ativo' : '' ?>" href="<?= e(url(['tipo' => $v ?: null])) ?>"><?= $r ?></a>
              <?php endforeach; ?>
            </div>
            <button type="button" class="btn btn-pequeno" id="btn-som" aria-pressed="false"><i class="fa-solid fa-volume-xmark" aria-hidden="true"></i> Som desligado</button>
          </div>
        </div>

        <?php if ($producao): ?>
          <section class="painel-card" style="margin-bottom:18px" aria-labelledby="t-producao">
            <div class="painel-header"><h2 id="t-producao"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Produzir agora</h2><span class="painel-meta">Soma dos itens na fila</span></div>
            <div class="painel-corpo item-meta">
              <?php foreach ($producao as $nome => $qtd): ?><span class="selo" style="font-size:13px;padding:4px 10px"><strong><?= $qtd ?>x</strong>&nbsp;<?= e($nome) ?></span><?php endforeach; ?>
            </div>
          </section>
        <?php endif; ?>

        <?php if (!$fila): ?>
          <section class="painel-card"><p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-mug-hot" aria-hidden="true"></i></span>Nenhum pedido na fila. Os novos pedidos aparecem aqui automaticamente.</p></section>
        <?php endif; ?>

        <div class="kds" id="kds">
          <?php foreach ($fila as $p):
            $atrasado = (int) $p['minutos'] > $limite; ?>
            <article class="comanda<?= $atrasado ? ' atrasada' : '' ?>" data-etapa="<?= e($p['etapa']) ?>" data-id="<?= (int) $p['id'] ?>" data-inicio="<?= time() - (int) $p['minutos'] * 60 ?>" data-limite="<?= $limite ?>">
              <header class="comanda-topo">
                <strong class="comanda-numero">#<?= (int) $p['id'] ?></strong>
                <span class="cronometro" aria-label="Tempo desde o pedido"><?= sprintf('%d:%02d', intdiv((int) $p['minutos'], 60), (int) $p['minutos'] % 60) ?></span>
              </header>
              <p class="comanda-origem"><?= $origem($p) ?> <span style="margin-left:auto" class="comanda-hora"><?= e($p['hora']) ?></span></p>
              <ul class="comanda-itens">
                <?php foreach ($itens[(int) $p['id']] ?? [] as $it): ?>
                  <li><span class="qtd"><?= (int) $it['quantidade'] ?>x</span> <?= e($it['nome']) ?><?= $it['observacao'] ? ' <em>' . e($it['observacao']) . '</em>' : '' ?></li>
                <?php endforeach; ?>
              </ul>
              <?php if ($p['observacao']): ?><p class="comanda-obs"><i class="fa-regular fa-note-sticky" aria-hidden="true"></i> <?= e($p['observacao']) ?></p><?php endif; ?>
              <form method="post" action="<?= e(url()) ?>" class="comanda-rodape" style="gap:8px">
                <?= csrf_campo() ?>
                <input type="hidden" name="acao" value="etapa">
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <?php if ($p['etapa'] === 'NOVO'): ?>
                  <button type="submit" name="etapa" value="PREPARO" class="btn btn-primario kds-acao"><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> Iniciar preparo</button>
                <?php else: ?>
                  <button type="submit" name="etapa" value="NOVO" class="btn btn-icone" style="min-height:44px;width:44px" title="Voltar para a fila" aria-label="Voltar o pedido <?= (int) $p['id'] ?> para a fila"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
                  <button type="submit" name="etapa" value="PRONTO" class="btn btn-sucesso kds-acao"><i class="fa-solid fa-bell-concierge" aria-hidden="true"></i> Pronto</button>
                <?php endif; ?>
              </form>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if ($prontos): ?>
          <h2 class="area-titulo" style="margin-top:26px">Prontos aguardando retirada <span class="painel-meta"><?= count($prontos) ?></span></h2>
          <div class="item-meta">
            <?php foreach ($prontos as $p): ?>
              <span class="selo" style="font-size:13px;padding:5px 10px">#<?= (int) $p['id'] ?> · <?= strip_tags($origem($p)) ?> · há <?= (int) $p['minutos_etapa'] ?> min</span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

<?php
$rodapeExtra = <<<'HTML'
<script>
  (() => {
    // cronômetros ao vivo
    const atualizar = () => {
      const agora = Math.floor(Date.now() / 1000);
      document.querySelectorAll('#kds .comanda').forEach((c) => {
        const seg = Math.max(0, agora - Number(c.dataset.inicio));
        const min = Math.floor(seg / 60);
        c.querySelector('.cronometro').textContent = `${Math.floor(min / 60) ? Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0') : min}:${String(seg % 60).padStart(2, '0')}`;
        c.classList.toggle('atrasada', min > Number(c.dataset.limite));
      });
      document.getElementById('relogio').textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    };
    atualizar();
    setInterval(atualizar, 1000);

    // pedidos novos desde a última atualização: destaque e aviso sonoro opcional
    const CHAVE = 'xtec-cozinha-vistos';
    const ids = [...document.querySelectorAll('#kds .comanda')].map((c) => c.dataset.id);
    let vistos = null;
    try { vistos = JSON.parse(sessionStorage.getItem(CHAVE) || 'null'); } catch (e) { /* ignora */ }
    const novos = vistos ? ids.filter((id) => !vistos.includes(id)) : [];
    novos.forEach((id) => document.querySelector(`#kds .comanda[data-id="${id}"]`)?.classList.add('nova'));
    try { sessionStorage.setItem(CHAVE, JSON.stringify(ids)); } catch (e) { /* ignora */ }

    const botao = document.getElementById('btn-som');
    let som = false;
    try { som = localStorage.getItem('xtec-cozinha-som') === '1'; } catch (e) { /* ignora */ }
    const desenharBotao = () => {
      botao.setAttribute('aria-pressed', String(som));
      botao.innerHTML = som ? '<i class="fa-solid fa-volume-high" aria-hidden="true"></i> Som ligado' : '<i class="fa-solid fa-volume-xmark" aria-hidden="true"></i> Som desligado';
    };
    const tocar = () => {
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        [0, 0.18].forEach((t) => {
          const o = ctx.createOscillator(); const g = ctx.createGain();
          o.frequency.value = 880; o.type = 'sine';
          g.gain.setValueAtTime(0.0001, ctx.currentTime + t);
          g.gain.exponentialRampToValueAtTime(0.25, ctx.currentTime + t + 0.02);
          g.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + t + 0.16);
          o.connect(g).connect(ctx.destination); o.start(ctx.currentTime + t); o.stop(ctx.currentTime + t + 0.17);
        });
      } catch (e) { /* navegador sem áudio */ }
    };
    botao.addEventListener('click', () => {
      som = !som;
      try { localStorage.setItem('xtec-cozinha-som', som ? '1' : '0'); } catch (e) { /* ignora */ }
      desenharBotao();
      if (som) tocar();
    });
    desenharBotao();
    if (som && novos.length) tocar();
  })();
</script>
HTML;
include __DIR__ . '/_partials/rodape.php';
?>
