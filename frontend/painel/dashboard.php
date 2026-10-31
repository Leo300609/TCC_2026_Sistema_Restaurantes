<?php
require __DIR__ . '/_partials/auth.php';

$uid = (int) $_SESSION['usuario_id'];
$cfg = ler(fn() => chamar_um('sp_config_listar', [$uid]), ['dias_validade' => 3]);

$r = ler(fn() => chamar_um('sp_dash_resumo'), null) ?? [];
$r += ['faturamento_hoje' => 0, 'faturamento_ontem_ate_agora' => 0, 'pedidos_hoje' => 0, 'pedidos_ontem_ate_agora' => 0,
       'pedidos_ultima_hora' => 0, 'ticket_hoje' => null, 'ticket_30dias' => null, 'em_andamento' => 0, 'atrasados' => 0,
       'em_entrega' => 0, 'cancelados_hoje' => 0, 'mesas_ocupadas' => 0, 'mesas_total' => 0, 'tempo_preparo' => 20];
$etapas = ler(fn() => chamar_um('sp_dash_etapas'), null) ?? ['novos' => 0, 'preparo' => 0, 'prontos' => 0, 'entregues' => 0];
$ultimos = ler(fn() => chamar('sp_dash_ultimos', [6]));
$maisVendidos = ler(fn() => chamar('sp_rel_produtos', [date('Y-m-d', strtotime('-6 days')), date('Y-m-d'), 5]));
$equipe = ler(fn() => chamar('sp_dash_equipe_online'));
$mes = $perm['financeiro'] ? ler(fn() => chamar_um('sp_dash_mes'), null) : null;
$alertas = $perm['estoque'] ? ler(fn() => chamar('sp_dash_alertas_estoque', [(int) $cfg['dias_validade']])) : [];

// Últimos 14 dias: sete atuais para os minigráficos, sete anteriores para comparar
$porDia = [];
foreach (ler(fn() => chamar('sp_dash_semana')) as $d) {
  $porDia[$d['dia']] = $d;
}
$fat7 = $ped7 = [];
$fatSemana = $fatSemanaAnterior = 0.0;
for ($i = 13; $i >= 0; $i--) {
  $dia = date('Y-m-d', strtotime("-{$i} days"));
  $f = (float) ($porDia[$dia]['faturamento'] ?? 0);
  if ($i <= 6) {
    $fat7[] = $f;
    $ped7[] = (int) ($porDia[$dia]['pedidos'] ?? 0);
    $fatSemana += $f;
  } else {
    $fatSemanaAnterior += $f;
  }
}

// Vendas por hora: hoje x mesmo dia da semana passada
$horas = ['HOJE' => array_fill(0, 24, 0.0), 'SEMANA_PASSADA' => array_fill(0, 24, 0.0)];
foreach (ler(fn() => chamar('sp_dash_horas')) as $h) {
  $horas[$h['dia']][(int) $h['hora']] = (float) $h['total'];
}
$comVenda = array_keys(array_filter(array_map(null, $horas['HOJE'], $horas['SEMANA_PASSADA']), static fn($p) => $p[0] > 0 || $p[1] > 0));
$horaIni = min(11, $comVenda ? min($comVenda) : 11);
$horaFim = max(23, $comVenda ? max($comVenda) : 23);
$horaAgora = (int) date('G');
$grafico = ['rotulos' => [], 'hoje' => [], 'passada' => []];
for ($h = $horaIni; $h <= $horaFim; $h++) {
  $grafico['rotulos'][] = sprintf('%02dh', $h);
  $grafico['hoje'][] = $h <= $horaAgora ? round($horas['HOJE'][$h], 2) : null;
  $grafico['passada'][] = round($horas['SEMANA_PASSADA'][$h], 2);
}
$diaSemanaPassada = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][(int) date('w')];

$nomesEtapa = ['NOVO' => ['novo', 'Novo'], 'PREPARO' => ['em-preparo', 'Em preparo'], 'PRONTO' => ['pronto', 'Pronto'],
               'ENTREGUE' => ['entregue', 'Entregue'], 'CANCELADO' => ['cancelado', 'Cancelado']];
$hora = (int) date('G');
$saudacao = $hora >= 5 && $hora < 12 ? 'Bom dia' : ($hora >= 12 && $hora < 18 ? 'Boa tarde' : 'Boa noite');
$primeiroNome = explode(' ', trim($_SESSION['usuario_nome'] ?? ''))[0];

$titulo = 'Visão geral';
$subtitulo = "{$saudacao}, {$primeiroNome}. Este é o movimento de hoje, " . date('d/m') . '.';
$paginaAtual = 'dashboard';
$cabecaExtra = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>';
include __DIR__ . '/_partials/topo.php';
?>

        <section class="grid-cards cinco" aria-label="Indicadores de hoje">
          <?php if ($perm['financeiro']): ?>
            <div class="card-metrica">
              <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span> Faturamento hoje</span>
              <div class="card-valor"><?= moeda($r['faturamento_hoje']) ?></div>
              <span class="card-detalhe"><?= ($d = delta_html(variacao((float) $r['faturamento_hoje'], (float) $r['faturamento_ontem_ate_agora']))) ? $d . ' <span>vs. ontem neste horário</span>' : '<span>Ontem não havia vendas até este horário</span>' ?></span>
              <?= sparkline($fat7) ?>
            </div>
          <?php endif; ?>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span> Pedidos hoje</span>
            <div class="card-valor"><?= (int) $r['pedidos_hoje'] ?></div>
            <span class="card-detalhe"><?= delta_html(variacao((float) $r['pedidos_hoje'], (float) $r['pedidos_ontem_ate_agora'])) ?> <span><?= (int) $r['pedidos_ultima_hora'] ?> na última hora</span></span>
            <?= sparkline($ped7, 'var(--azul)') ?>
          </div>
          <?php if ($perm['financeiro']): ?>
            <div class="card-metrica">
              <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span> Ticket médio</span>
              <div class="card-valor"><?= $r['ticket_hoje'] === null ? '—' : moeda($r['ticket_hoje']) ?></div>
              <span class="card-detalhe"><?= $r['ticket_hoje'] !== null && $r['ticket_30dias'] ? delta_html(variacao((float) $r['ticket_hoje'], (float) $r['ticket_30dias'])) . ' <span>vs. média de 30 dias</span>' : '<span>Pedidos entregues hoje</span>' ?></span>
            </div>
          <?php else: ?>
            <div class="card-metrica">
              <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i></span> Em entrega</span>
              <div class="card-valor"><?= (int) $r['em_entrega'] ?></div>
              <span class="card-detalhe"><span>Delivery a caminho do cliente</span></span>
            </div>
          <?php endif; ?>
          <a class="card-metrica alerta-box<?= (int) $r['atrasados'] > 0 ? ' ativo-alerta' : '' ?>" href="pedidos.php" style="text-decoration:none">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-fire-burner" aria-hidden="true"></i></span> Em andamento</span>
            <div class="card-valor"><?= (int) $r['em_andamento'] ?></div>
            <span class="card-detalhe <?= (int) $r['atrasados'] > 0 ? 'alerta' : '' ?>">
              <?= (int) $r['atrasados'] > 0 ? '<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ' . (int) $r['atrasados'] . ' acima de ' . (int) $r['tempo_preparo'] . ' min' : 'Nenhum pedido atrasado' ?>
            </span>
          </a>
          <a class="card-metrica" href="mesas.php" style="text-decoration:none">
            <span class="card-titulo"><span class="card-icone roxo"><i class="fa-solid fa-chair" aria-hidden="true"></i></span> Mesas ocupadas</span>
            <div class="card-valor"><?= (int) $r['mesas_ocupadas'] ?><small style="font-size:16px;color:var(--tinta-3)"> / <?= (int) $r['mesas_total'] ?></small></div>
            <span class="progresso azul" aria-hidden="true"><span style="--nivel: <?= (int) $r['mesas_total'] ? round($r['mesas_ocupadas'] / $r['mesas_total'] * 100) : 0 ?>%"></span></span>
            <span class="card-detalhe"><span><?= (int) $r['mesas_total'] ? round($r['mesas_ocupadas'] / $r['mesas_total'] * 100) : 0 ?>% de ocupação do salão</span></span>
          </a>
        </section>

        <div class="dashboard-secoes">
          <section class="painel-card" aria-labelledby="t-horas">
            <div class="painel-header">
              <h2 id="t-horas"><i class="fa-solid fa-chart-area" aria-hidden="true"></i> Vendas por hora</h2>
              <span class="painel-meta">Hoje x <?= $diaSemanaPassada ?> passado(a)</span>
            </div>
            <div class="chart-container alto">
              <canvas id="grafico-horas" role="img" aria-label="Faturamento por hora de hoje comparado com o mesmo dia da semana passada"></canvas>
            </div>
            <ul class="legenda">
              <li><span class="ponto" style="background:var(--brasa)" aria-hidden="true"></span> Hoje</li>
              <li><span class="ponto" style="background:var(--fio-forte)" aria-hidden="true"></span> Mesmo dia da semana passada</li>
              <li style="margin-left:auto">Semana: <strong><?= moeda($fatSemana) ?></strong> <?= delta_html(variacao($fatSemana, $fatSemanaAnterior)) ?></li>
            </ul>
          </section>

          <section class="painel-card" aria-labelledby="t-agora">
            <div class="painel-header">
              <h2 id="t-agora"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Pedidos agora</h2>
              <a href="pedidos.php" class="btn-link">Abrir quadro</a>
            </div>
            <div class="etapas-faixa">
              <a href="pedidos.php" style="--cor: var(--azul)"><strong><?= (int) $etapas['novos'] ?></strong><span>Novos</span></a>
              <a href="cozinha.php" style="--cor: var(--mostarda)"><strong><?= (int) $etapas['preparo'] ?></strong><span>Em preparo</span></a>
              <a href="pedidos.php" style="--cor: var(--brasa)"><strong><?= (int) $etapas['prontos'] ?></strong><span>Prontos</span></a>
              <a href="pedidos.php" style="--cor: var(--salvia)"><strong><?= (int) $etapas['entregues'] ?></strong><span>Entregues hoje</span></a>
            </div>
            <?php if (!$ultimos): ?>
              <p class="vazio">Nenhum pedido registrado ainda. <a href="pdv.php">Abrir o PDV</a></p>
            <?php else: ?>
              <ul class="lista-linhas">
                <?php foreach ($ultimos as $p):
                  [$classe, $rotulo] = $nomesEtapa[$p['etapa']] ?? ['', $p['etapa']];
                  $origem = $p['cliente'] ?: ($p['tipo'] === 'SALAO' ? 'Mesa ' . $p['mesa'] : ($p['tipo'] === 'DELIVERY' ? 'Delivery' : 'Balcão')); ?>
                  <li>
                    <span class="celula-dupla" style="flex:1">
                      <strong><span class="id-pedido">#<?= (int) $p['id'] ?></span> <?= e($origem) ?></strong>
                      <small><?= e($p['hora']) ?> · <?= e($p['itens'] ?: 'sem itens') ?></small>
                    </span>
                    <span class="lado">
                      <strong><?= moeda($p['total']) ?></strong><br>
                      <span class="status <?= $classe ?>"><?= $rotulo ?></span>
                    </span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        </div>

        <div class="dashboard-secoes tres">
          <section class="painel-card" aria-labelledby="t-top">
            <div class="painel-header">
              <h2 id="t-top"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i> Mais vendidos</h2>
              <span class="painel-meta">Últimos 7 dias</span>
            </div>
            <?php if (!$maisVendidos): ?>
              <p class="vazio">Sem vendas nos últimos 7 dias.</p>
            <?php else: $maiorReceita = max(array_map(static fn($x) => (float) $x['receita'], $maisVendidos)) ?: 1; ?>
              <ol class="ranking">
                <?php foreach ($maisVendidos as $n => $p): ?>
                  <li>
                    <span class="pos"><?= $n + 1 ?></span>
                    <span class="nome"><?= e($p['nome']) ?> <small class="muted"><?= (int) $p['quantidade'] ?> un.</small></span>
                    <span class="valor"><?= $perm['financeiro'] ? moeda($p['receita']) : (int) $p['quantidade'] . ' un.' ?></span>
                    <span class="progresso brasa"><span style="--nivel: <?= round((float) $p['receita'] / $maiorReceita * 100) ?>%"></span></span>
                  </li>
                <?php endforeach; ?>
              </ol>
            <?php endif; ?>
          </section>

          <?php if ($mes): $saldoMes = (float) $mes['receitas'] - (float) $mes['despesas']; ?>
            <section class="painel-card" aria-labelledby="t-mes">
              <div class="painel-header">
                <h2 id="t-mes"><i class="fa-solid fa-wallet" aria-hidden="true"></i> Caixa do mês</h2>
                <a href="fluxo_de_caixa.php" class="btn-link">Fluxo de caixa</a>
              </div>
              <div class="painel-corpo">
                <div class="card-valor" style="color: <?= $saldoMes >= 0 ? 'var(--salvia-texto)' : 'var(--brasa-texto)' ?>"><?= moeda($saldoMes) ?></div>
                <p class="painel-meta" style="margin-bottom:14px">Saldo realizado em <?= ['', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'][(int) date('n')] ?> (recebido menos pago)</p>
                <?php $base = max((float) $mes['receitas'], (float) $mes['despesas'], 1); ?>
                <div class="grupo-input" style="gap:4px;margin-bottom:10px">
                  <span class="card-detalhe" style="justify-content:space-between"><span>Receitas</span><strong style="color:var(--tinta)"><?= moeda($mes['receitas']) ?></strong></span>
                  <span class="progresso"><span style="--nivel: <?= round((float) $mes['receitas'] / $base * 100) ?>%"></span></span>
                </div>
                <div class="grupo-input" style="gap:4px">
                  <span class="card-detalhe" style="justify-content:space-between"><span>Despesas</span><strong style="color:var(--tinta)"><?= moeda($mes['despesas']) ?></strong></span>
                  <span class="progresso brasa"><span style="--nivel: <?= round((float) $mes['despesas'] / $base * 100) ?>%"></span></span>
                </div>
              </div>
              <div class="saldo-mes">
                <div><small>A receber</small><strong><?= moeda($mes['a_receber']) ?></strong></div>
                <div><small>A pagar</small><strong><?= moeda($mes['a_pagar']) ?></strong><?php if ((float) $mes['vencido'] > 0): ?><small style="color:var(--brasa-texto)"><?= moeda($mes['vencido']) ?> vencido</small><?php endif; ?></div>
              </div>
            </section>
          <?php endif; ?>

          <?php if ($perm['estoque']): ?>
            <section class="painel-card" aria-labelledby="t-alertas">
              <div class="painel-header">
                <h2 id="t-alertas"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Alertas de estoque</h2>
                <span class="painel-meta"><?= count($alertas) ?> <?= count($alertas) === 1 ? 'item' : 'itens' ?></span>
              </div>
              <div class="lista-rolavel">
                <?php if (!$alertas): ?>
                  <p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-check" aria-hidden="true"></i></span>Estoque em dia.</p>
                <?php endif; ?>
                <?php foreach ($alertas as $a):
                  $critico = in_array($a['situacao'], ['VENCIDO', 'CRITICO'], true);
                  $motivo = match ($a['situacao']) {
                    'VENCIDO' => 'Vencido em ' . data_br($a['validade']),
                    'CRITICO' => (int) $a['estoque'] . ' ' . $a['unidade'] . ' em estoque · mínimo ' . (int) $a['minimo'],
                    default => (int) $a['dias'] === 0 ? 'Vence hoje' : 'Vence em ' . (int) $a['dias'] . ' ' . ((int) $a['dias'] === 1 ? 'dia' : 'dias'),
                  }; ?>
                  <a class="alerta-vencimento<?= $critico ? ' critico' : '' ?>" href="estoque.php?mov=ENTRADA&amp;produto=<?= (int) $a['id'] ?>#movimento">
                    <span class="notif-icone"><i class="fa-solid <?= $a['situacao'] === 'CRITICO' ? 'fa-box-open' : 'fa-calendar-xmark' ?>" aria-hidden="true"></i></span>
                    <span class="celula-dupla"><strong><?= e($a['nome']) ?></strong><small><?= e($motivo) ?></small></span>
                  </a>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

          <section class="painel-card" aria-labelledby="t-equipe">
            <div class="painel-header">
              <h2 id="t-equipe"><i class="fa-solid fa-user-group" aria-hidden="true"></i> Equipe online</h2>
              <a href="empresa.php" class="btn-link">Equipe</a>
            </div>
            <?php if (!$equipe): ?>
              <p class="vazio">Ninguém mais conectado agora.</p>
            <?php else: ?>
              <ul class="lista-linhas">
                <?php foreach ($equipe as $m): ?>
                  <li>
                    <span class="avatar <?= $corAvatar($m['nome']) ?>" aria-hidden="true"><?= e($iniciais($m['nome'])) ?></span>
                    <span class="celula-dupla"><strong><?= e($m['nome']) ?></strong><small><?= e(rotulo_cargo($m['cargo'], $m['nivel'])) ?></small></span>
                    <span class="lado presenca online"><?= (int) $m['minutos'] < 1 ? 'agora' : 'há ' . (int) $m['minutos'] . ' min' ?></span>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
          </section>
        </div>

<?php
$dadosGrafico = json_encode($grafico, JSON_UNESCAPED_UNICODE);
$rodapeExtra = <<<HTML
<script>
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;
    const dados = {$dadosGrafico};
    const css = (v) => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
    const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    let grafico = null;
    function desenhar() {
      grafico?.destroy();
      Chart.defaults.font.family = css('--f-ui');
      Chart.defaults.color = css('--tinta-3');
      grafico = new Chart(document.getElementById('grafico-horas'), {
        type: 'line',
        data: {
          labels: dados.rotulos,
          datasets: [
            { label: 'Hoje', data: dados.hoje, borderColor: css('--brasa'), backgroundColor: css('--brasa-fundo'), fill: true, tension: 0.35, pointRadius: 0, pointHoverRadius: 4, borderWidth: 2.5, spanGaps: false },
            { label: 'Semana passada', data: dados.passada, borderColor: css('--fio-forte'), borderDash: [5, 4], fill: false, tension: 0.35, pointRadius: 0, pointHoverRadius: 3, borderWidth: 2 },
          ],
        },
        options: {
          responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
          plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => c.dataset.label + ': ' + moeda(c.parsed.y ?? 0) } } },
          scales: {
            x: { grid: { display: false }, border: { color: css('--fio') } },
            y: { beginAtZero: true, grid: { color: css('--fio') }, border: { display: false }, ticks: { callback: (v) => 'R$ ' + v.toLocaleString('pt-BR') } },
          },
        },
      });
    }
    desenhar();
    document.addEventListener('xtec:tema', desenhar);
  });
</script>
HTML;
include __DIR__ . '/_partials/rodape.php';
?>
