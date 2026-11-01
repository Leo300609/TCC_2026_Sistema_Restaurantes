<?php
require __DIR__ . '/_partials/auth.php';
require_once __DIR__ . '/../../backend/lib/previsao.php';
exigir_permissao($perm['relatorios']);

$janela = in_array(entrada_int('janela', 14), [7, 14, 28], true) ? entrada_int('janela', 14) : 14;

// ---------- produtos: tendência e previsão ----------
$produtos = analisar_tendencias(ler(fn() => chamar('sp_prev_produtos', [$janela])), $janela);
$porProduto = [];
foreach (ler(fn() => chamar('sp_prev_serie_produtos', [$janela * 2])) as $s) {
  $porProduto[(int) $s['id']][$s['dia']] = (float) $s['quantidade'];
}
foreach ($produtos as &$p) {
  $p['serie'] = serie_completa($porProduto[(int) $p['id']] ?? [], $janela * 2);
}
unset($p);

$grupo = static fn(string $classe) => array_values(array_filter($produtos, static fn($p) => $p['classe'] === $classe));
$emAlta = $grupo('ALTA');
usort($emAlta, static fn($a, $b) => $b['variacao'] <=> $a['variacao']);
$emergentes = $grupo('EMERGENTE');
usort($emergentes, static fn($a, $b) => $b['atual'] <=> $a['atual']);
$emQueda = $grupo('QUEDA');
usort($emQueda, static fn($a, $b) => $a['variacao'] <=> $b['variacao']);
$destaque = $emergentes[0] ?? $emAlta[0] ?? null;

// ---------- faturamento: histórico de 56 dias e próximos 7 ----------
$historico = $historicoPedidos = [];
foreach (ler(fn() => chamar('sp_prev_faturamento', [56])) as $d) {
  $historico[$d['dia']] = (float) $d['faturamento'];
  $historicoPedidos[$d['dia']] = (float) $d['pedidos'];
}
$prevFat = prever_diario($historico);
$prevPed = prever_diario($historicoPedidos);
$fatPrevisto = array_sum(array_column($prevFat['dias'], 'previsto'));
$fatUltimos7 = array_sum(array_slice(serie_completa($historico, 7), 0));
$pedPrevisto = array_sum(array_column($prevPed['dias'], 'previsto'));
$pedUltimos7 = array_sum(serie_completa($historicoPedidos, 7));
$maiorDia = $prevFat['dias'] ? array_reduce($prevFat['dias'], static fn($c, $d) => $c === null || $d['previsto'] > $c['previsto'] ? $d : $c) : null;

// ---------- reposição ----------
$reposicao = prever_reposicao(ler(fn() => chamar('sp_prev_insumos', [28])), $prevFat['fator']);
$emRisco = array_values(array_filter($reposicao, static fn($r) => $r['dias_restantes'] <= 7));
$custoReposicao = array_sum(array_column(array_filter($reposicao, static fn($r) => $r['dias_restantes'] <= 14), 'custo_compra'));

// ---------- mapa de calor (média de pedidos por dia da semana e hora) ----------
$calor = [];
$horasComVenda = [];
foreach (ler(fn() => chamar('sp_prev_mapa_calor', [8])) as $c) {
  $calor[(int) $c['dia']][(int) $c['hora']] = (float) $c['media'];
  $horasComVenda[] = (int) $c['hora'];
}
$hIni = $horasComVenda ? min($horasComVenda) : 11;
$hFim = $horasComVenda ? max($horasComVenda) : 23;
$maxCalor = 0.0;
foreach ($calor as $hs) {
  $maxCalor = max($maxCalor, ...array_values($hs));
}
$diasCalor = [3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb', 1 => 'Dom', 2 => 'Seg'];
$picos = [];
foreach ($calor as $d => $hs) {
  foreach ($hs as $h => $v) {
    $picos[] = [$d, $h, $v];
  }
}
usort($picos, static fn($a, $b) => $b[2] <=> $a[2]);

$nomesDia = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
$curtoDia = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

// ---------- dados dos gráficos ----------
$serieFat = serie_completa($historico, 28);
$crescimento2s = variacao(array_sum(array_slice($serieFat, 14)), array_sum(array_slice($serieFat, 0, 14)));
$grafico = ['rotulos' => [], 'real' => [], 'previsto' => [], 'minimo' => [], 'maximo' => []];
foreach ($serieFat as $i => $v) {
  $t = strtotime('-' . (28 - $i) . ' days');
  $grafico['rotulos'][] = $curtoDia[(int) date('w', $t)] . ' ' . date('d/m', $t);
  $grafico['real'][] = round($v, 2);
  $grafico['previsto'][] = $i === 27 ? round($v, 2) : null;   // liga a linha prevista ao último dia real
  $grafico['minimo'][] = null;
  $grafico['maximo'][] = null;
}
foreach ($prevFat['dias'] as $d) {
  $t = strtotime($d['data']);
  $grafico['rotulos'][] = $curtoDia[(int) date('w', $t)] . ' ' . date('d/m', $t);
  $grafico['real'][] = null;
  $grafico['previsto'][] = round($d['previsto'], 2);
  $grafico['minimo'][] = round($d['minimo'], 2);
  $grafico['maximo'][] = round($d['maximo'], 2);
}
$topComparativo = array_slice($produtos, 0, 8);
$comparativo = [
  'rotulos' => array_map(static fn($p) => $p['nome'], $topComparativo),
  'anterior' => array_map(static fn($p) => (int) $p['anterior'], $topComparativo),
  'atual' => array_map(static fn($p) => (int) $p['atual'], $topComparativo),
  'previsto' => array_map(static fn($p) => round($p['previsto7'] * $janela / 7), $topComparativo),
];

$rotuloClasse = ['ALTA' => ['ok', 'Em alta', 'fa-arrow-trend-up'], 'EMERGENTE' => ['novo', 'Entrando em tendência', 'fa-seedling'],
                 'QUEDA' => ['critico', 'Perdendo força', 'fa-arrow-trend-down'], 'ESTAVEL' => ['inativo', 'Estável', 'fa-minus']];

$titulo = 'Previsões';
$subtitulo = 'Tendências do cardápio, faturamento esperado e reposição do estoque';
$paginaAtual = 'previsoes';
$cabecaExtra = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>';
include __DIR__ . '/_partials/topo.php';

/** Cartão de tendência de um produto. */
$cartaoTendencia = static function (array $p) use ($rotuloClasse, $janela): string {
  [$classe, $rotulo, $icone] = $rotuloClasse[$p['classe']];
  $cor = $p['classe'] === 'QUEDA' ? 'var(--brasa)' : ($p['classe'] === 'EMERGENTE' ? 'var(--azul)' : 'var(--salvia)');
  $sinal = $p['variacao'] >= 0 ? '+' : '−';
  return '<li class="tendencia-item">'
    . '<div class="tendencia-topo"><span class="celula-dupla"><strong>' . e($p['nome']) . '</strong><small>' . e($p['categoria']) . '</small></span>'
    . '<span class="status ' . $classe . '"><i class="fa-solid ' . $icone . '" aria-hidden="true"></i> ' . $sinal . numero(abs($p['variacao']), 0) . '%</span></div>'
    . sparkline($p['serie'], $cor)
    . '<div class="tendencia-numeros"><span><small>Últimos ' . $janela . ' dias</small><strong>' . (int) $p['atual'] . ' un.</strong></span>'
    . '<span><small>Antes</small><strong>' . (int) $p['anterior'] . ' un.</strong></span>'
    . '<span><small>Próximos 7 dias</small><strong>≈ ' . (int) round($p['previsto7']) . ' un.</strong></span></div>'
    . '</li>';
};
?>

        <form class="toolbar" method="get" action="previsoes.php">
          <div class="toolbar-grupo">
            <div class="filtros" role="group" aria-label="Janela de comparação">
              <?php foreach ([7 => '7 dias', 14 => '14 dias', 28 => '28 dias'] as $v => $r): ?>
                <a class="filtro<?= $janela === $v ? ' ativo' : '' ?>" href="<?= e(url(['janela' => $v === 14 ? null : $v])) ?>">Comparar <?= $r ?></a>
              <?php endforeach; ?>
            </div>
          </div>
          <span class="painel-meta"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Baseado nas vendas até ontem. Hoje ainda está em andamento.</span>
        </form>

        <section class="grid-cards" aria-label="Resumo das previsões">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span> Faturamento previsto</span>
            <div class="card-valor"><?= moeda($fatPrevisto) ?></div>
            <span class="card-detalhe"><?= delta_html(variacao($fatPrevisto, $fatUltimos7)) ?> próximos 7 dias vs. últimos 7</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span> Pedidos previstos</span>
            <div class="card-valor">≈ <?= numero($pedPrevisto) ?></div>
            <span class="card-detalhe"><?= $maiorDia ? 'Dia mais forte: ' . $nomesDia[(int) date('w', strtotime($maiorDia['data']))] . ' ' . date('d/m', strtotime($maiorDia['data'])) : 'Sem histórico suficiente' ?></span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i></span> Produto em destaque</span>
            <div class="card-valor" style="font-size:20px;white-space:normal"><?= e($destaque['nome'] ?? 'Nenhum') ?></div>
            <span class="card-detalhe <?= $destaque ? 'positivo' : '' ?>"><?= $destaque ? '+' . numero($destaque['variacao'], 0) . '% em ' . $janela . ' dias · ' . e($rotuloClasse[$destaque['classe']][1]) : 'Vendas estáveis no período' ?></span>
          </div>
          <a class="card-metrica alerta-box<?= $emRisco ? ' ativo-alerta' : '' ?>" href="#reposicao" style="text-decoration:none">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-box-open" aria-hidden="true"></i></span> Acabam em até 7 dias</span>
            <div class="card-valor"><?= count($emRisco) ?></div>
            <span class="card-detalhe <?= $emRisco ? 'alerta' : '' ?>"><?= $emRisco ? 'Compra sugerida: ' . moeda($custoReposicao) : 'Estoque cobre a próxima semana' ?></span>
          </a>
        </section>

        <div class="dashboard-secoes">
          <section class="painel-card" aria-labelledby="t-fat">
            <div class="painel-header">
              <h2 id="t-fat"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Faturamento: realizado e previsto</h2>
              <span class="painel-meta">Últimas 2 semanas vs. 2 anteriores: <?= $crescimento2s === null ? '—' : ($crescimento2s >= 0 ? '+' : '−') . abs($crescimento2s) . '%' ?></span>
            </div>
            <div class="chart-container alto"><canvas id="g-previsao" role="img" aria-label="Faturamento diário das últimas 4 semanas e previsão dos próximos 7 dias com faixa de confiança"></canvas></div>
            <ul class="legenda">
              <li><span class="ponto" style="background:var(--brasa)" aria-hidden="true"></span> Realizado</li>
              <li><span class="ponto" style="background:var(--azul)" aria-hidden="true"></span> Previsto</li>
              <li><span class="ponto" style="background:var(--azul-fundo);box-shadow:inset 0 0 0 1px var(--azul)" aria-hidden="true"></span> Faixa provável (80%)</li>
            </ul>
          </section>

          <section class="painel-card" aria-labelledby="t-semana">
            <div class="painel-header"><h2 id="t-semana"><i class="fa-solid fa-calendar-days" aria-hidden="true"></i> Próximos 7 dias</h2></div>
            <?php $maxPrev = max(1, ...array_column($prevFat['dias'], 'maximo')); ?>
            <ul class="lista-linhas previsao-dias">
              <?php foreach ($prevFat['dias'] as $i => $d): $t = strtotime($d['data']); ?>
                <li>
                  <span class="celula-dupla" style="min-width:92px"><strong><?= $i === 0 ? 'Hoje' : ucfirst($nomesDia[(int) date('w', $t)]) ?></strong><small><?= date('d/m', $t) ?> · ≈ <?= numero($prevPed['dias'][$i]['previsto']) ?> pedidos</small></span>
                  <span class="faixa-previsao" aria-hidden="true">
                    <span class="faixa-intervalo" style="left: <?= round($d['minimo'] / $maxPrev * 100) ?>%; width: <?= max(1, round(($d['maximo'] - $d['minimo']) / $maxPrev * 100)) ?>%"></span>
                    <span class="faixa-ponto" style="left: <?= round($d['previsto'] / $maxPrev * 100) ?>%"></span>
                  </span>
                  <span class="lado"><strong><?= $d['previsto'] > 0 ? moeda($d['previsto']) : 'Fechado' ?></strong><?php if ($d['previsto'] > 0): ?><br><small class="muted"><?= moeda($d['minimo']) ?> a <?= moeda($d['maximo']) ?></small><?php endif; ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          </section>
        </div>

        <div class="dashboard-secoes tres tendencias">
          <section class="painel-card" aria-labelledby="t-alta">
            <div class="painel-header"><h2 id="t-alta"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true" style="color:var(--salvia)"></i> Em alta</h2><span class="painel-meta"><?= count($emAlta) ?></span></div>
            <?php if (!$emAlta): ?><p class="vazio">Nenhum item cresceu mais de 20% no período.</p><?php else: ?>
              <ul class="tendencias-lista"><?php foreach (array_slice($emAlta, 0, 4) as $p) { echo $cartaoTendencia($p); } ?></ul>
            <?php endif; ?>
          </section>
          <section class="painel-card" aria-labelledby="t-emergente">
            <div class="painel-header"><h2 id="t-emergente"><i class="fa-solid fa-seedling" aria-hidden="true" style="color:var(--azul)"></i> Entrando em tendência</h2><span class="painel-meta"><?= count($emergentes) ?></span></div>
            <?php if (!$emergentes): ?><p class="vazio">Nenhum item saiu de vendas baixas para um crescimento forte.</p><?php else: ?>
              <ul class="tendencias-lista"><?php foreach (array_slice($emergentes, 0, 4) as $p) { echo $cartaoTendencia($p); } ?></ul>
              <p class="painel-nota" style="margin-top:4px">Vale destacar no cardápio e garantir os insumos desses itens.</p>
            <?php endif; ?>
          </section>
          <section class="painel-card" aria-labelledby="t-queda">
            <div class="painel-header"><h2 id="t-queda"><i class="fa-solid fa-arrow-trend-down" aria-hidden="true" style="color:var(--brasa)"></i> Perdendo força</h2><span class="painel-meta"><?= count($emQueda) ?></span></div>
            <?php if (!$emQueda): ?><p class="vazio">Nenhum item caiu mais de 20% no período.</p><?php else: ?>
              <ul class="tendencias-lista"><?php foreach (array_slice($emQueda, 0, 4) as $p) { echo $cartaoTendencia($p); } ?></ul>
              <p class="painel-nota" style="margin-top:4px">Considere uma promoção ou revisar o preço e a apresentação.</p>
            <?php endif; ?>
          </section>
        </div>

        <div class="dashboard-secoes">
          <section class="painel-card" aria-labelledby="t-comp">
            <div class="painel-header"><h2 id="t-comp"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Mais vendidos: antes, agora e previsão</h2><span class="painel-meta">Unidades em <?= $janela ?> dias</span></div>
            <div class="chart-container alto"><canvas id="g-comparativo" role="img" aria-label="Unidades vendidas por produto no período anterior, no atual e a previsão do próximo"></canvas></div>
          </section>

          <section class="painel-card" aria-labelledby="t-calor">
            <div class="painel-header"><h2 id="t-calor"><i class="fa-solid fa-fire" aria-hidden="true"></i> Quando o salão enche</h2><span class="painel-meta">Média de pedidos · 8 semanas</span></div>
            <div class="painel-corpo">
              <div class="mapa-calor" style="--colunas: <?= $hFim - $hIni + 1 ?>" role="img" aria-label="Mapa de calor de pedidos por dia da semana e hora">
                <span></span>
                <?php for ($h = $hIni; $h <= $hFim; $h++): ?><span class="mc-hora"><?= $h ?>h</span><?php endfor; ?>
                <?php foreach ($diasCalor as $d => $rot): ?>
                  <span class="mc-dia"><?= $rot ?></span>
                  <?php for ($h = $hIni; $h <= $hFim; $h++): $v = $calor[$d][$h] ?? 0; ?>
                    <span class="mc-celula" style="--i: <?= $maxCalor > 0 ? round($v / $maxCalor, 2) : 0 ?>" title="<?= $rot ?> <?= $h ?>h: <?= numero($v, 1) ?> pedidos em média"></span>
                  <?php endfor; ?>
                <?php endforeach; ?>
              </div>
              <?php if ($picos): ?>
                <p class="painel-meta" style="margin-top:14px">Picos previstos: <?php foreach (array_slice($picos, 0, 3) as $n => [$d, $h, $v]): ?><strong><?= $nomesDia[$d - 1] ?> às <?= $h ?>h</strong> (≈ <?= numero($v, 1) ?> pedidos)<?= $n < 2 ? ', ' : '.' ?><?php endforeach; ?> Reforce a equipe nesses horários.</p>
              <?php endif; ?>
            </div>
          </section>
        </div>

        <section class="painel-card painel-tabela" id="reposicao" aria-labelledby="t-repo">
          <div class="painel-header">
            <h2 id="t-repo"><i class="fa-solid fa-cart-flatbed" aria-hidden="true"></i> Reposição prevista</h2>
            <span class="toolbar-grupo" style="width:auto"><span class="painel-meta">Consumo pela ficha técnica · cobertura de 14 dias</span><?php if ($perm['compras']): ?><a class="btn btn-pequeno btn-primario" href="compras.php?novo=1"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Registrar compra</a><?php endif; ?></span>
          </div>
          <?php if (!$reposicao): ?>
            <p class="vazio">Sem consumo registrado para projetar. Cadastre a ficha técnica dos itens do cardápio.</p>
          <?php else: ?>
            <div class="tabela-container">
              <table class="tabela">
                <thead><tr><th scope="col">Item</th><th scope="col" class="num">Em estoque</th><th scope="col" class="num">Consumo/dia</th><th scope="col">Duração prevista</th><th scope="col">Acaba em</th><th scope="col" class="num">Comprar</th><th scope="col" class="num">Custo</th></tr></thead>
                <tbody>
                  <?php foreach (array_slice($reposicao, 0, 15) as $r):
                    $dr = $r['dias_restantes'];
                    $nivelCor = $dr <= 3 ? 'brasa' : ($dr <= 7 ? 'mostarda' : ''); ?>
                    <tr>
                      <td><span class="celula-dupla"><strong><?= e($r['nome']) ?></strong><small><?= e($r['categoria']) ?></small></span></td>
                      <td class="num"><?= numero($r['estoque']) ?> <?= e($r['unidade']) ?></td>
                      <td class="num"><?= numero($r['consumo_previsto'], 1) ?></td>
                      <td><div class="nivel" style="min-width:140px"><span class="painel-meta"><?= $dr >= 60 ? 'mais de 60 dias' : numero($dr, $dr < 10 ? 1 : 0) . ' dias' ?></span><span class="progresso <?= $nivelCor ?>"><span style="--nivel: <?= min(100, round($dr / 30 * 100)) ?>%"></span></span></div></td>
                      <td class="data"><?= $dr >= 60 ? '—' : data_br($r['acaba_em']) ?></td>
                      <td class="num"><?= $r['comprar'] > 0 ? '<strong>' . numero($r['comprar']) . ' ' . e($r['unidade']) . '</strong>' : '<span class="muted">—</span>' ?></td>
                      <td class="num"><?= $r['comprar'] > 0 ? moeda($r['custo_compra']) : '<span class="muted">—</span>' ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </section>

        <details class="painel-card metodo" style="margin-top:14px">
          <summary class="painel-header"><h2><i class="fa-solid fa-flask" aria-hidden="true"></i> Como as previsões são calculadas</h2><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
          <div class="painel-corpo metodo-texto">
            <p><strong>Tendência dos produtos.</strong> Comparamos as vendas dos últimos <?= $janela ?> dias com os <?= $janela ?> dias anteriores e ajustamos uma regressão linear sobre as vendas diárias (y = a + b·x). Um item está <em>em alta</em> quando cresce 20% ou mais com inclinação positiva, <em>perdendo força</em> quando cai 20% ou mais, e <em>entrando em tendência</em> quando sai de vendas baixas e ao menos dobra.</p>
            <p><strong>Unidades dos próximos 7 dias.</strong> Média entre a reta da regressão projetada e a média diária recente, para não exagerar quando a reta é muito inclinada.</p>
            <p><strong>Faturamento por dia.</strong> Média ponderada do mesmo dia da semana nas últimas 4 semanas (pesos 4, 3, 2 e 1), multiplicada por um fator de tendência amortecido entre −15% e +20%. A faixa provável usa ±1,28 desvio-padrão, cerca de 80% de confiança.</p>
            <p><strong>Reposição.</strong> O consumo diário de cada insumo vem da ficha técnica dos itens vendidos nos últimos 28 dias, ajustado pela tendência de vendas. A compra sugerida cobre 14 dias acima do estoque mínimo.</p>
          </div>
        </details>

<?php
$dados = json_encode(['previsao' => $grafico, 'comparativo' => $comparativo], JSON_UNESCAPED_UNICODE);
$rodapeExtra = <<<HTML
<script>
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;
    const d = {$dados};
    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const moeda = (v) => (v ?? 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const curto = (v) => 'R$ ' + (v >= 1000 ? (v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 }) + ' mil' : v.toLocaleString('pt-BR'));
    let graficos = [];
    function desenhar() {
      graficos.forEach((g) => g.destroy());
      Chart.defaults.font.family = css('--f-ui');
      Chart.defaults.color = css('--tinta-3');
      const grade = { color: css('--fio') };
      graficos = [
        new Chart(document.getElementById('g-previsao'), {
          type: 'line',
          data: { labels: d.previsao.rotulos, datasets: [
            { label: 'Máximo provável', data: d.previsao.maximo, borderWidth: 0, pointRadius: 0, fill: '+1', backgroundColor: css('--azul-fundo'), cubicInterpolationMode: 'monotone' },
            { label: 'Mínimo provável', data: d.previsao.minimo, borderWidth: 0, pointRadius: 0, fill: false, cubicInterpolationMode: 'monotone' },
            { label: 'Realizado', data: d.previsao.real, borderColor: css('--brasa'), backgroundColor: css('--brasa'), cubicInterpolationMode: 'monotone', pointRadius: 0, pointHoverRadius: 4, borderWidth: 2.4 },
            { label: 'Previsto', data: d.previsao.previsto, borderColor: css('--azul'), backgroundColor: css('--azul'), borderDash: [6, 5], cubicInterpolationMode: 'monotone', pointRadius: 3, pointHoverRadius: 5, borderWidth: 2.4 },
          ] },
          options: {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            animation: { duration: 1100, easing: 'easeOutQuart' },
            plugins: { legend: { display: false }, tooltip: { filter: (i) => i.parsed.y !== null, callbacks: { label: (c) => c.dataset.label + ': ' + moeda(c.parsed.y) } } },
            scales: {
              x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 14 } },
              y: { beginAtZero: true, grid: grade, border: { display: false }, ticks: { callback: curto } },
            },
          },
        }),
        new Chart(document.getElementById('g-comparativo'), {
          type: 'bar',
          data: { labels: d.comparativo.rotulos, datasets: [
            { label: 'Período anterior', data: d.comparativo.anterior, backgroundColor: css('--fio-forte'), borderRadius: 4 },
            { label: 'Período atual', data: d.comparativo.atual, backgroundColor: css('--brasa'), borderRadius: 4 },
            { label: 'Previsão do próximo', data: d.comparativo.previsto, backgroundColor: css('--azul-fundo'), borderColor: css('--azul'), borderWidth: 1.5, borderDash: [4, 3], borderRadius: 4 },
          ] },
          options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            animation: { duration: 1000, easing: 'easeOutQuart' },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } } },
            scales: { x: { beginAtZero: true, grid: grade, border: { display: false } }, y: { grid: { display: false } } },
          },
        }),
      ];
    }
    desenhar();
    document.addEventListener('xtec:tema', desenhar);
  });
</script>
HTML;
include __DIR__ . '/_partials/rodape.php';
?>
