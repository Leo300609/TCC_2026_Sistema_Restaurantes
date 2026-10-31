<?php
require __DIR__ . '/_partials/auth.php';
exigir_permissao($perm['relatorios']);

[$de, $ate, $periodo] = periodo_filtro('30dias');
$dias = (int) ((strtotime($ate) - strtotime($de)) / 86400) + 1;
// período anterior, de mesmo tamanho, para comparação
$deAnt = date('Y-m-d', strtotime($de . " -{$dias} days"));
$ateAnt = date('Y-m-d', strtotime($de . ' -1 day'));

$padraoResumo = ['faturamento' => 0, 'pedidos' => 0, 'cancelados' => 0, 'valor_cancelado' => 0, 'descontos' => 0, 'clientes' => 0,
                 'itens' => 0, 'cmv' => 0, 'despesas_pagas' => 0, 'despesas_operacionais' => 0, 'compras_pagas' => 0];
$r = ler(fn() => chamar_um('sp_rel_resumo', [$de, $ate]), null) ?? $padraoResumo;
$ant = ler(fn() => chamar_um('sp_rel_resumo', [$deAnt, $ateAnt]), null) ?? $padraoResumo;

$ticket = (int) $r['pedidos'] ? (float) $r['faturamento'] / (int) $r['pedidos'] : 0;
$ticketAnt = (int) $ant['pedidos'] ? (float) $ant['faturamento'] / (int) $ant['pedidos'] : 0;
$lucroBruto = (float) $r['faturamento'] - (float) $r['cmv'];
$margem = (float) $r['faturamento'] > 0 ? $lucroBruto / (float) $r['faturamento'] * 100 : 0;
$resultado = $lucroBruto - (float) $r['despesas_operacionais'];

$produtos = ler(fn() => chamar('sp_rel_produtos', [$de, $ate, 15]));
$categorias = ler(fn() => chamar('sp_rel_categorias', [$de, $ate]));
$atendentes = ler(fn() => chamar('sp_rel_atendentes', [$de, $ate]));
$clientesTop = ler(fn() => chamar('sp_rel_clientes', [$de, $ate, 8]));
$formasRaw = ler(fn() => chamar('sp_rel_formas', [$de, $ate]));
$tiposRaw = ler(fn() => chamar('sp_rel_tipos', [$de, $ate]));

// séries para os gráficos (preenchendo dias e horas sem venda)
$porDia = [];
foreach (ler(fn() => chamar('sp_rel_vendas_dia', [$de, $ate])) as $d) {
  $porDia[$d['dia']] = $d;
}
$serieDia = ['rotulos' => [], 'faturamento' => [], 'pedidos' => []];
for ($t = strtotime($de); $t <= strtotime($ate); $t += 86400) {
  $dia = date('Y-m-d', $t);
  $serieDia['rotulos'][] = $dias > 62 ? date('d/m/y', $t) : date('d/m', $t);
  $serieDia['faturamento'][] = round((float) ($porDia[$dia]['faturamento'] ?? 0), 2);
  $serieDia['pedidos'][] = (int) ($porDia[$dia]['pedidos'] ?? 0);
}
$horas = array_fill(0, 24, 0.0);
foreach (ler(fn() => chamar('sp_rel_horas', [$de, $ate])) as $h) {
  $horas[(int) $h['hora']] = (float) $h['total'];
}
$comVenda = array_keys(array_filter($horas));
$hIni = $comVenda ? min($comVenda) : 11;
$hFim = $comVenda ? max($comVenda) : 23;
$serieHora = ['rotulos' => [], 'total' => []];
for ($h = $hIni; $h <= $hFim; $h++) {
  $serieHora['rotulos'][] = sprintf('%02dh', $h);
  $serieHora['total'][] = round($horas[$h], 2);
}
$nomesDias = [1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];
$semana = array_fill(1, 7, 0.0);
foreach (ler(fn() => chamar('sp_rel_dias_semana', [$de, $ate])) as $d) {
  $semana[(int) $d['dia']] = (float) $d['total'];
}
$formas = formas_pagamento();
$serieFormas = ['rotulos' => array_map(static fn($f) => $formas[$f['forma']][0] ?? $f['forma'], $formasRaw), 'total' => array_map(static fn($f) => round((float) $f['total'], 2), $formasRaw)];
$nomesTipo = ['SALAO' => 'Salão', 'BALCAO' => 'Balcão', 'DELIVERY' => 'Delivery'];

$exportar = static fn(string $arquivo) => '../../backend/relatorios/' . $arquivo . '?' . http_build_query(['de' => $de, 'ate' => $ate]);

$titulo = 'Relatórios';
$subtitulo = 'Desempenho de ' . data_br($de) . ' a ' . data_br($ate) . " ({$dias} " . ($dias === 1 ? 'dia' : 'dias') . ')';
$paginaAtual = 'relatorios';
$cabecaExtra = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>';
include __DIR__ . '/_partials/topo.php';
?>

        <form class="relatorio-filtro" method="get" action="relatorios.php">
          <div class="grupo-input">
            <label for="periodo">Período</label>
            <select id="periodo" name="periodo" class="input-dash">
              <?php foreach (periodos_rotulos() as $v => $rot): ?>
                <option value="<?= $v ?>"<?= $periodo === $v ? ' selected' : '' ?>><?= $rot ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="grupo-input">
            <label for="de">De</label>
            <input type="date" id="de" name="de" class="input-dash" value="<?= e($de) ?>">
          </div>
          <div class="grupo-input">
            <label for="ate">Até</label>
            <input type="date" id="ate" name="ate" class="input-dash" value="<?= e($ate) ?>">
          </div>
          <button type="submit" class="btn btn-secundario">Aplicar</button>
          <div class="export-actions">
            <a class="btn btn-export pdf" href="<?= e($exportar('gerar_pdf.php')) ?>"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> PDF</a>
            <a class="btn btn-export excel" href="<?= e($exportar('gerar_excel.php')) ?>"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Excel</a>
            <a class="btn btn-export word" href="<?= e($exportar('gerar_word.php')) ?>"><i class="fa-solid fa-file-word" aria-hidden="true"></i> Word</a>
            <a class="btn btn-export" href="<?= e($exportar('gerar_csv.php')) ?>"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV</a>
          </div>
        </form>

        <section class="grid-cards cinco" aria-label="Indicadores do período">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span> Faturamento</span>
            <div class="card-valor"><?= moeda($r['faturamento']) ?></div>
            <span class="card-detalhe"><?= delta_html(variacao((float) $r['faturamento'], (float) $ant['faturamento'])) ?> vs. período anterior</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span> Pedidos entregues</span>
            <div class="card-valor"><?= numero($r['pedidos']) ?></div>
            <span class="card-detalhe"><?= delta_html(variacao((float) $r['pedidos'], (float) $ant['pedidos'])) ?> <?= numero((int) $r['pedidos'] / max($dias, 1), 1) ?> por dia</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span> Ticket médio</span>
            <div class="card-valor"><?= moeda($ticket) ?></div>
            <span class="card-detalhe"><?= delta_html(variacao($ticket, $ticketAnt)) ?> <?= numero($r['itens']) ?> itens vendidos</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone roxo"><i class="fa-solid fa-percent" aria-hidden="true"></i></span> Margem bruta</span>
            <div class="card-valor"><?= numero($margem, 1) ?>%</div>
            <span class="card-detalhe">Custo dos insumos: <?= moeda($r['cmv']) ?></span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-ban" aria-hidden="true"></i></span> Cancelamentos</span>
            <div class="card-valor"><?= numero($r['cancelados']) ?></div>
            <span class="card-detalhe <?= (int) $r['cancelados'] ? 'aviso' : '' ?>"><?= moeda($r['valor_cancelado']) ?> · <?= numero(((int) $r['pedidos'] + (int) $r['cancelados']) ? (int) $r['cancelados'] / ((int) $r['pedidos'] + (int) $r['cancelados']) * 100 : 0, 1) ?>% dos pedidos</span>
          </div>
        </section>

        <div class="dashboard-secoes">
          <section class="painel-card" aria-labelledby="t-dia">
            <div class="painel-header"><h2 id="t-dia"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Vendas por dia</h2><span class="painel-meta">Faturamento e pedidos</span></div>
            <div class="chart-container alto"><canvas id="g-dia" role="img" aria-label="Faturamento e quantidade de pedidos por dia"></canvas></div>
          </section>
          <section class="painel-card" aria-labelledby="t-dre">
            <div class="painel-header"><h2 id="t-dre"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i> Resultado do período</h2><span class="painel-meta">Estimativa</span></div>
            <ul class="dre">
              <li><span>Vendas brutas</span><span><?= moeda((float) $r['faturamento'] + (float) $r['descontos']) ?></span></li>
              <li class="menos"><span>(−) Descontos concedidos</span><span><?= moeda($r['descontos']) ?></span></li>
              <li class="subtotal"><span>Receita líquida</span><span><?= moeda($r['faturamento']) ?></span></li>
              <li class="menos"><span>(−) Custo dos insumos (ficha técnica)</span><span><?= moeda($r['cmv']) ?></span></li>
              <li class="subtotal"><span>Lucro bruto · <?= numero($margem, 1) ?>%</span><span><?= moeda($lucroBruto) ?></span></li>
              <li class="menos"><span>(−) Despesas operacionais pagas</span><span><?= moeda($r['despesas_operacionais']) ?></span></li>
              <li class="resultado" style="color: <?= $resultado >= 0 ? 'var(--salvia-texto)' : 'var(--brasa-texto)' ?>"><span>Resultado</span><span><?= moeda($resultado) ?></span></li>
            </ul>
            <p class="painel-meta" style="padding:0 20px 18px">Compras pagas no período: <?= moeda($r['compras_pagas']) ?> (já consideradas no custo dos insumos). O custo usa a ficha técnica de cada item com o último preço de compra.</p>
          </section>
        </div>

        <div class="dashboard-secoes tres">
          <section class="painel-card" aria-labelledby="t-formas">
            <div class="painel-header"><h2 id="t-formas"><i class="fa-solid fa-credit-card" aria-hidden="true"></i> Formas de pagamento</h2></div>
            <div class="chart-container baixo"><canvas id="g-formas" role="img" aria-label="Participação de cada forma de pagamento no faturamento"></canvas></div>
          </section>
          <section class="painel-card" aria-labelledby="t-horas">
            <div class="painel-header"><h2 id="t-horas"><i class="fa-regular fa-clock" aria-hidden="true"></i> Horários de pico</h2></div>
            <div class="chart-container baixo"><canvas id="g-horas" role="img" aria-label="Faturamento por hora do dia"></canvas></div>
          </section>
          <section class="painel-card" aria-labelledby="t-semana">
            <div class="painel-header"><h2 id="t-semana"><i class="fa-solid fa-calendar-week" aria-hidden="true"></i> Dias da semana</h2></div>
            <div class="chart-container baixo"><canvas id="g-semana" role="img" aria-label="Faturamento por dia da semana"></canvas></div>
          </section>
        </div>

        <div class="dashboard-secoes">
          <section class="painel-card painel-tabela" aria-labelledby="t-produtos">
            <div class="painel-header"><h2 id="t-produtos"><i class="fa-solid fa-burger" aria-hidden="true"></i> Produtos mais vendidos</h2><span class="painel-meta">Top 15 por receita</span></div>
            <?php if (!$produtos): ?>
              <p class="vazio">Sem vendas no período.</p>
            <?php else: ?>
              <div class="tabela-container">
                <table class="tabela">
                  <thead><tr><th scope="col">Produto</th><th scope="col" class="num">Qtd.</th><th scope="col" class="num">Receita</th><th scope="col" class="num">Custo</th><th scope="col" class="num">Margem</th><th scope="col" class="num">Participação</th></tr></thead>
                  <tbody>
                    <?php foreach ($produtos as $p):
                      $mg = (float) $p['receita'] > 0 ? ((float) $p['receita'] - (float) $p['custo']) / (float) $p['receita'] * 100 : 0;
                      $part = (float) $r['faturamento'] > 0 ? (float) $p['receita'] / ((float) $r['faturamento'] + (float) $r['descontos']) * 100 : 0; ?>
                      <tr>
                        <td><span class="celula-dupla"><strong><?= e($p['nome']) ?></strong><small><?= e($p['categoria']) ?></small></span></td>
                        <td class="num"><?= numero($p['quantidade']) ?></td>
                        <td class="num"><strong><?= moeda($p['receita']) ?></strong></td>
                        <td class="num"><?= (float) $p['custo'] > 0 ? moeda($p['custo']) : '<span class="muted">—</span>' ?></td>
                        <td class="num"><?= (float) $p['custo'] > 0 ? '<span class="status ' . ($mg >= 60 ? 'ok' : ($mg >= 35 ? 'baixo' : 'critico')) . '">' . numero($mg, 0) . '%</span>' : '<span class="muted">—</span>' ?></td>
                        <td class="num"><div class="nivel" style="min-width:90px;align-items:flex-end"><span class="painel-meta"><?= numero($part, 1) ?>%</span><span class="progresso brasa" style="width:100%"><span style="--nivel: <?= min(100, round($part * 2.5)) ?>%"></span></span></div></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>

          <div class="fluxo-lateral">
            <section class="painel-card" aria-labelledby="t-tipos">
              <div class="painel-header"><h2 id="t-tipos"><i class="fa-solid fa-shop" aria-hidden="true"></i> Canais de venda</h2></div>
              <?php $baseTipos = max(1, array_sum(array_map(static fn($t) => (float) $t['total'], $tiposRaw))); ?>
              <ol class="ranking">
                <?php foreach ($tiposRaw as $n => $t): ?>
                  <li>
                    <span class="pos"><i class="fa-solid <?= ['SALAO' => 'fa-chair', 'BALCAO' => 'fa-bag-shopping', 'DELIVERY' => 'fa-motorcycle'][$t['tipo']] ?? 'fa-circle' ?>" aria-hidden="true"></i></span>
                    <span class="nome"><?= e($nomesTipo[$t['tipo']] ?? $t['tipo']) ?> <small class="muted"><?= numero($t['pedidos']) ?> pedidos · ticket <?= moeda($t['ticket']) ?></small></span>
                    <span class="valor"><?= moeda($t['total']) ?></span>
                    <span class="progresso"><span style="--nivel: <?= round((float) $t['total'] / $baseTipos * 100) ?>%"></span></span>
                  </li>
                <?php endforeach; ?>
                <?php if (!$tiposRaw): ?><li class="muted">Sem vendas no período.</li><?php endif; ?>
              </ol>
            </section>
            <section class="painel-card" aria-labelledby="t-cats">
              <div class="painel-header"><h2 id="t-cats"><i class="fa-solid fa-tags" aria-hidden="true"></i> Categorias</h2></div>
              <?php $baseCat = max(1, array_sum(array_map(static fn($c) => (float) $c['receita'], $categorias))); ?>
              <ol class="ranking">
                <?php foreach ($categorias as $n => $c): ?>
                  <li>
                    <span class="pos"><?= $n + 1 ?></span>
                    <span class="nome"><?= e($c['categoria']) ?> <small class="muted"><?= numero($c['quantidade']) ?> un.</small></span>
                    <span class="valor"><?= numero((float) $c['receita'] / $baseCat * 100, 0) ?>%</span>
                    <span class="progresso azul"><span style="--nivel: <?= round((float) $c['receita'] / $baseCat * 100) ?>%"></span></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            </section>
          </div>
        </div>

        <div class="dashboard-secoes iguais">
          <section class="painel-card painel-tabela" aria-labelledby="t-equipe">
            <div class="painel-header"><h2 id="t-equipe"><i class="fa-solid fa-user-tie" aria-hidden="true"></i> Desempenho da equipe</h2></div>
            <?php if (!$atendentes): ?><p class="vazio">Sem vendas no período.</p><?php else: ?>
              <div class="tabela-container">
                <table class="tabela">
                  <thead><tr><th scope="col">Atendente</th><th scope="col" class="num">Pedidos</th><th scope="col" class="num">Ticket</th><th scope="col" class="num">Vendido</th></tr></thead>
                  <tbody>
                    <?php foreach ($atendentes as $a): ?>
                      <tr>
                        <td><div class="pessoa"><span class="avatar <?= $corAvatar($a['nome']) ?>" aria-hidden="true"><?= e($iniciais($a['nome'])) ?></span><span class="celula-dupla"><strong><?= e($a['nome']) ?></strong><small><?= e(rotulo_cargo($a['cargo'])) ?></small></span></div></td>
                        <td class="num"><?= numero($a['pedidos']) ?></td>
                        <td class="num"><?= moeda($a['ticket']) ?></td>
                        <td class="num"><strong><?= moeda($a['total']) ?></strong></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
          <section class="painel-card painel-tabela" aria-labelledby="t-clientes">
            <div class="painel-header"><h2 id="t-clientes"><i class="fa-solid fa-crown" aria-hidden="true"></i> Melhores clientes</h2><span class="painel-meta"><?= numero($r['clientes']) ?> clientes identificados</span></div>
            <?php if (!$clientesTop): ?><p class="vazio">Nenhuma venda com cliente identificado no período.</p><?php else: ?>
              <div class="tabela-container">
                <table class="tabela">
                  <thead><tr><th scope="col">Cliente</th><th scope="col" class="num">Pedidos</th><th scope="col">Última compra</th><th scope="col" class="num">Total</th></tr></thead>
                  <tbody>
                    <?php foreach ($clientesTop as $c): ?>
                      <tr>
                        <td><div class="pessoa"><span class="avatar <?= $corAvatar($c['nome']) ?>" aria-hidden="true"><?= e($iniciais($c['nome'])) ?></span><strong><?= e($c['nome']) ?></strong></div></td>
                        <td class="num"><?= numero($c['pedidos']) ?></td>
                        <td class="data"><?= e($c['ultima_compra']) ?></td>
                        <td class="num"><strong><?= moeda($c['total']) ?></strong></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </section>
        </div>

<?php
$dados = json_encode([
  'dia' => $serieDia,
  'hora' => $serieHora,
  'formas' => $serieFormas,
  'semana' => ['rotulos' => array_values($nomesDias), 'total' => array_values(array_map(static fn($v) => round($v, 2), $semana))],
], JSON_UNESCAPED_UNICODE);
$rodapeExtra = <<<HTML
<script>
  document.addEventListener('DOMContentLoaded', () => {
    // período personalizado: datas digitadas trocam o seletor
    const periodo = document.getElementById('periodo');
    ['de', 'ate'].forEach((id) => document.getElementById(id).addEventListener('change', () => { periodo.value = 'personalizado'; }));
    periodo.addEventListener('change', () => { if (periodo.value !== 'personalizado') periodo.form.submit(); });

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
      const eixoX = { grid: { display: false }, border: { color: css('--fio') } };
      const eixoMoeda = { beginAtZero: true, grid: grade, border: { display: false }, ticks: { callback: curto } };
      const dica = { callbacks: { label: (c) => c.dataset.label + ': ' + (c.dataset.yAxisID === 'p' ? c.parsed.y : moeda(c.parsed.y ?? c.parsed)) } };
      graficos = [
        new Chart(document.getElementById('g-dia'), {
          data: { labels: d.dia.rotulos, datasets: [
            { type: 'bar', label: 'Faturamento', data: d.dia.faturamento, backgroundColor: css('--brasa'), borderRadius: 4, maxBarThickness: 26, yAxisID: 'y' },
            { type: 'line', label: 'Pedidos', data: d.dia.pedidos, borderColor: css('--azul'), backgroundColor: css('--azul'), tension: 0.35, pointRadius: 0, borderWidth: 2, yAxisID: 'p' },
          ] },
          options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } }, tooltip: dica },
            scales: { x: { ...eixoX, ticks: { maxRotation: 0, autoSkipPadding: 12 } }, y: eixoMoeda, p: { position: 'right', beginAtZero: true, grid: { display: false }, border: { display: false } } } },
        }),
        new Chart(document.getElementById('g-formas'), {
          type: 'doughnut',
          data: { labels: d.formas.rotulos, datasets: [{ data: d.formas.total, borderWidth: 2, borderColor: css('--chapa'),
            backgroundColor: [css('--brasa'), css('--azul'), css('--salvia'), css('--mostarda'), css('--roxo')] }] },
          options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
            plugins: { legend: { position: 'right', labels: { boxWidth: 10, boxHeight: 10 } }, tooltip: { callbacks: { label: (c) => c.label + ': ' + moeda(c.parsed) } } } },
        }),
        new Chart(document.getElementById('g-horas'), {
          type: 'bar',
          data: { labels: d.hora.rotulos, datasets: [{ label: 'Faturamento', data: d.hora.total, backgroundColor: css('--mostarda'), borderRadius: 4 }] },
          options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: dica }, scales: { x: eixoX, y: eixoMoeda } },
        }),
        new Chart(document.getElementById('g-semana'), {
          type: 'bar',
          data: { labels: d.semana.rotulos, datasets: [{ label: 'Faturamento', data: d.semana.total, backgroundColor: css('--azul'), borderRadius: 4 }] },
          options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: dica }, scales: { x: eixoX, y: eixoMoeda } },
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
