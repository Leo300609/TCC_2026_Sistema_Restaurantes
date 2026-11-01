'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina, corAvatar } = require('../layout');
const {
  h, cru, ler, e, moeda, numero, dataBr, data, paraMs, somarDias, entrada, periodoFiltro, periodosRotulos, variacao, deltaHtml,
  formasPagamento, iniciais, jsonSeguro, sel,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const NOMES_TIPO = { SALAO: 'Salão', BALCAO: 'Balcão', DELIVERY: 'Delivery' };
const ICONE_TIPO = { SALAO: 'fa-chair', BALCAO: 'fa-bag-shopping', DELIVERY: 'fa-motorcycle' };
const r2 = (v) => Math.round(v * 100) / 100;

module.exports = async function relatorios(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.relatorios);

  const [de, ate, periodo] = periodoFiltro(ctx, '30dias');
  const dias = Math.round((paraMs(ate) - paraMs(de)) / 86400000) + 1;
  // período anterior, de mesmo tamanho, para comparação
  const deAnt = somarDias(de, -dias);
  const ateAnt = somarDias(de, -1);

  const padraoResumo = { faturamento: 0, pedidos: 0, cancelados: 0, valor_cancelado: 0, descontos: 0, clientes: 0, itens: 0, cmv: 0, despesas_pagas: 0, despesas_operacionais: 0, compras_pagas: 0 };
  const [rBd, antBd, produtos, categorias, atendentes, clientesTop, formasRaw, tiposRaw, vendasDia, horasBd, semanaBd] = await Promise.all([
    ler(ctx, () => bd.chamarUm('sp_rel_resumo', [de, ate]), null),
    ler(ctx, () => bd.chamarUm('sp_rel_resumo', [deAnt, ateAnt]), null),
    ler(ctx, () => bd.chamar('sp_rel_produtos', [de, ate, 15])),
    ler(ctx, () => bd.chamar('sp_rel_categorias', [de, ate])),
    ler(ctx, () => bd.chamar('sp_rel_atendentes', [de, ate])),
    ler(ctx, () => bd.chamar('sp_rel_clientes', [de, ate, 8])),
    ler(ctx, () => bd.chamar('sp_rel_formas', [de, ate])),
    ler(ctx, () => bd.chamar('sp_rel_tipos', [de, ate])),
    ler(ctx, () => bd.chamar('sp_rel_vendas_dia', [de, ate])),
    ler(ctx, () => bd.chamar('sp_rel_horas', [de, ate])),
    ler(ctx, () => bd.chamar('sp_rel_dias_semana', [de, ate])),
  ]);
  const r = rBd || padraoResumo;
  const ant = antBd || padraoResumo;

  const ticket = N(r.pedidos) ? N(r.faturamento) / N(r.pedidos) : 0;
  const ticketAnt = N(ant.pedidos) ? N(ant.faturamento) / N(ant.pedidos) : 0;
  const lucroBruto = N(r.faturamento) - N(r.cmv);
  const margem = N(r.faturamento) > 0 ? (lucroBruto / N(r.faturamento)) * 100 : 0;
  const resultado = lucroBruto - N(r.despesas_operacionais);

  // séries para os gráficos (preenchendo dias e horas sem venda)
  const porDia = {};
  for (const d of vendasDia) porDia[String(d.dia).slice(0, 10)] = d;
  const serieDia = { rotulos: [], faturamento: [], pedidos: [] };
  for (let t = paraMs(de); t <= paraMs(ate); t += 86400000) {
    const dia = data('Y-m-d', t);
    serieDia.rotulos.push(dias > 62 ? data('d/m/y', t) : data('d/m', t));
    serieDia.faturamento.push(r2(N(porDia[dia]?.faturamento)));
    serieDia.pedidos.push(N(porDia[dia]?.pedidos));
  }
  const horas = new Array(24).fill(0);
  for (const x of horasBd) horas[N(x.hora)] = N(x.total);
  const comVenda = horas.map((v, i) => (v ? i : -1)).filter((i) => i >= 0);
  const hIni = comVenda.length ? Math.min(...comVenda) : 11;
  const hFim = comVenda.length ? Math.max(...comVenda) : 23;
  const serieHora = { rotulos: [], total: [] };
  for (let hh = hIni; hh <= hFim; hh++) { serieHora.rotulos.push(String(hh).padStart(2, '0') + 'h'); serieHora.total.push(r2(horas[hh])); }
  const semana = new Array(8).fill(0);
  for (const x of semanaBd) semana[N(x.dia)] = N(x.total);
  const formas = formasPagamento();
  const serieFormas = { rotulos: formasRaw.map((f) => (formas[f.forma] || [f.forma])[0]), total: formasRaw.map((f) => r2(N(f.total))) };
  const dados = {
    dia: serieDia, hora: serieHora, formas: serieFormas,
    semana: { rotulos: ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'], total: semana.slice(1).map(r2) },
  };

  const exportar = (formato) => `/relatorios/${formato}?de=${encodeURIComponent(de)}&ate=${encodeURIComponent(ate)}`;
  const baseTipos = Math.max(1, tiposRaw.reduce((s, t) => s + N(t.total), 0));
  const baseCat = Math.max(1, categorias.reduce((s, c) => s + N(c.receita), 0));
  const cancelPct = (N(r.pedidos) + N(r.cancelados)) ? (N(r.cancelados) / (N(r.pedidos) + N(r.cancelados))) * 100 : 0;

  const conteudo = h`
        <form class="relatorio-filtro" method="get" action="relatorios">
          <div class="grupo-input">
            <label for="periodo">Período</label>
            <select id="periodo" name="periodo" class="input-dash">
              ${Object.entries(periodosRotulos()).map(([v, rot]) => h`<option value="${v}"${sel(periodo === v)}>${rot}</option>`)}
            </select>
          </div>
          <div class="grupo-input">
            <label for="de">De</label>
            <input type="date" id="de" name="de" class="input-dash" value="${de}">
          </div>
          <div class="grupo-input">
            <label for="ate">Até</label>
            <input type="date" id="ate" name="ate" class="input-dash" value="${ate}">
          </div>
          <button type="submit" class="btn btn-secundario">Aplicar</button>
          <div class="export-actions">
            <a class="btn btn-export pdf" href="${exportar('pdf')}"><i class="fa-solid fa-file-pdf" aria-hidden="true"></i> PDF</a>
            <a class="btn btn-export excel" href="${exportar('excel')}"><i class="fa-solid fa-file-excel" aria-hidden="true"></i> Excel</a>
            <a class="btn btn-export word" href="${exportar('word')}"><i class="fa-solid fa-file-word" aria-hidden="true"></i> Word</a>
            <a class="btn btn-export" href="${exportar('csv')}"><i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV</a>
          </div>
        </form>

        <section class="grid-cards cinco" aria-label="Indicadores do período">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span> Faturamento</span>
            <div class="card-valor">${moeda(r.faturamento)}</div>
            <span class="card-detalhe">${deltaHtml(variacao(N(r.faturamento), N(ant.faturamento)))} vs. período anterior</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span> Pedidos entregues</span>
            <div class="card-valor">${numero(r.pedidos)}</div>
            <span class="card-detalhe">${deltaHtml(variacao(N(r.pedidos), N(ant.pedidos)))} ${numero(N(r.pedidos) / Math.max(dias, 1), 1)} por dia</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span> Ticket médio</span>
            <div class="card-valor">${moeda(ticket)}</div>
            <span class="card-detalhe">${deltaHtml(variacao(ticket, ticketAnt))} ${numero(r.itens)} itens vendidos</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone roxo"><i class="fa-solid fa-percent" aria-hidden="true"></i></span> Margem bruta</span>
            <div class="card-valor">${numero(margem, 1)}%</div>
            <span class="card-detalhe">Custo dos insumos: ${moeda(r.cmv)}</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-ban" aria-hidden="true"></i></span> Cancelamentos</span>
            <div class="card-valor">${numero(r.cancelados)}</div>
            <span class="card-detalhe ${N(r.cancelados) ? 'atencao' : ''}">${moeda(r.valor_cancelado)} · ${numero(cancelPct, 1)}% dos pedidos</span>
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
              <li><span>Vendas brutas</span><span>${moeda(N(r.faturamento) + N(r.descontos))}</span></li>
              <li class="menos"><span>(−) Descontos concedidos</span><span>${moeda(r.descontos)}</span></li>
              <li class="subtotal"><span>Receita líquida</span><span>${moeda(r.faturamento)}</span></li>
              <li class="menos"><span>(−) Custo dos insumos (ficha técnica)</span><span>${moeda(r.cmv)}</span></li>
              <li class="subtotal"><span>Lucro bruto · ${numero(margem, 1)}%</span><span>${moeda(lucroBruto)}</span></li>
              <li class="menos"><span>(−) Despesas operacionais pagas</span><span>${moeda(r.despesas_operacionais)}</span></li>
              <li class="resultado" style="color: ${resultado >= 0 ? 'var(--salvia-texto)' : 'var(--brasa-texto)'}"><span>Resultado</span><span>${moeda(resultado)}</span></li>
            </ul>
            <p class="painel-meta" style="padding:0 20px 18px">Compras pagas no período: ${moeda(r.compras_pagas)} (já consideradas no custo dos insumos). O custo usa a ficha técnica de cada item com o último preço de compra.</p>
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
            ${!produtos.length ? h`<p class="vazio">Sem vendas no período.</p>` : h`
              <div class="tabela-container">
                <table class="tabela">
                  <thead><tr><th scope="col">Produto</th><th scope="col" class="num">Qtd.</th><th scope="col" class="num">Receita</th><th scope="col" class="num">Custo</th><th scope="col" class="num">Margem</th><th scope="col" class="num">Participação</th></tr></thead>
                  <tbody>
                    ${produtos.map((p) => {
                      const mg = N(p.receita) > 0 ? ((N(p.receita) - N(p.custo)) / N(p.receita)) * 100 : 0;
                      const part = N(r.faturamento) > 0 ? (N(p.receita) / (N(r.faturamento) + N(r.descontos))) * 100 : 0;
                      const temCusto = N(p.custo) > 0;
                      return h`<tr>
                        <td><span class="celula-dupla"><strong>${p.nome}</strong><small>${p.categoria}</small></span></td>
                        <td class="num">${numero(p.quantidade)}</td>
                        <td class="num"><strong>${moeda(p.receita)}</strong></td>
                        <td class="num">${temCusto ? moeda(p.custo) : h`<span class="muted">—</span>`}</td>
                        <td class="num">${temCusto ? h`<span class="status ${mg >= 60 ? 'ok' : mg >= 35 ? 'baixo' : 'critico'}">${numero(mg, 0)}%</span>` : h`<span class="muted">—</span>`}</td>
                        <td class="num"><div class="nivel" style="min-width:90px;align-items:flex-end"><span class="painel-meta">${numero(part, 1)}%</span><span class="progresso brasa" style="width:100%"><span style="--nivel: ${Math.min(100, Math.round(part * 2.5))}%"></span></span></div></td>
                      </tr>`;
                    })}
                  </tbody>
                </table>
              </div>`}
          </section>

          <div class="fluxo-lateral">
            <section class="painel-card" aria-labelledby="t-tipos">
              <div class="painel-header"><h2 id="t-tipos"><i class="fa-solid fa-shop" aria-hidden="true"></i> Canais de venda</h2></div>
              <ol class="ranking">
                ${tiposRaw.map((t) => h`<li>
                    <span class="pos"><i class="fa-solid ${ICONE_TIPO[t.tipo] || 'fa-circle'}" aria-hidden="true"></i></span>
                    <span class="nome">${NOMES_TIPO[t.tipo] || t.tipo} <small class="muted">${numero(t.pedidos)} pedidos · ticket ${moeda(t.ticket)}</small></span>
                    <span class="valor">${moeda(t.total)}</span>
                    <span class="progresso"><span style="--nivel: ${Math.round((N(t.total) / baseTipos) * 100)}%"></span></span>
                  </li>`)}
                ${!tiposRaw.length ? h`<li class="muted">Sem vendas no período.</li>` : ''}
              </ol>
            </section>
            <section class="painel-card" aria-labelledby="t-cats">
              <div class="painel-header"><h2 id="t-cats"><i class="fa-solid fa-tags" aria-hidden="true"></i> Categorias</h2></div>
              <ol class="ranking">
                ${categorias.map((c, n) => h`<li>
                    <span class="pos">${n + 1}</span>
                    <span class="nome">${c.categoria} <small class="muted">${numero(c.quantidade)} un.</small></span>
                    <span class="valor">${numero((N(c.receita) / baseCat) * 100, 0)}%</span>
                    <span class="progresso azul"><span style="--nivel: ${Math.round((N(c.receita) / baseCat) * 100)}%"></span></span>
                  </li>`)}
              </ol>
            </section>
          </div>
        </div>

        <div class="dashboard-secoes iguais">
          <section class="painel-card painel-tabela" aria-labelledby="t-equipe">
            <div class="painel-header"><h2 id="t-equipe"><i class="fa-solid fa-user-tie" aria-hidden="true"></i> Desempenho da equipe</h2></div>
            ${!atendentes.length ? h`<p class="vazio">Sem vendas no período.</p>` : h`
              <div class="tabela-container">
                <table class="tabela">
                  <thead><tr><th scope="col">Atendente</th><th scope="col" class="num">Pedidos</th><th scope="col" class="num">Ticket</th><th scope="col" class="num">Vendido</th></tr></thead>
                  <tbody>
                    ${atendentes.map((a) => h`<tr>
                        <td><div class="pessoa"><span class="avatar ${corAvatar(a.nome)}" aria-hidden="true">${iniciais(a.nome)}</span><span class="celula-dupla"><strong>${a.nome}</strong><small>${sessao.rotuloCargo(a.cargo)}</small></span></div></td>
                        <td class="num">${numero(a.pedidos)}</td>
                        <td class="num">${moeda(a.ticket)}</td>
                        <td class="num"><strong>${moeda(a.total)}</strong></td>
                      </tr>`)}
                  </tbody>
                </table>
              </div>`}
          </section>
          <section class="painel-card painel-tabela" aria-labelledby="t-clientes">
            <div class="painel-header"><h2 id="t-clientes"><i class="fa-solid fa-crown" aria-hidden="true"></i> Melhores clientes</h2><span class="painel-meta">${numero(r.clientes)} clientes identificados</span></div>
            ${!clientesTop.length ? h`<p class="vazio">Nenhuma venda com cliente identificado no período.</p>` : h`
              <div class="tabela-container">
                <table class="tabela">
                  <thead><tr><th scope="col">Cliente</th><th scope="col" class="num">Pedidos</th><th scope="col">Última compra</th><th scope="col" class="num">Total</th></tr></thead>
                  <tbody>
                    ${clientesTop.map((c) => h`<tr>
                        <td><div class="pessoa"><span class="avatar ${corAvatar(c.nome)}" aria-hidden="true">${iniciais(c.nome)}</span><strong>${c.nome}</strong></div></td>
                        <td class="num">${numero(c.pedidos)}</td>
                        <td class="data">${c.ultima_compra}</td>
                        <td class="num"><strong>${moeda(c.total)}</strong></td>
                      </tr>`)}
                  </tbody>
                </table>
              </div>`}
          </section>
        </div>`;

  const rodapeExtra = cru(`<script>
  document.addEventListener('DOMContentLoaded', () => {
    // período personalizado: datas digitadas trocam o seletor
    const periodo = document.getElementById('periodo');
    ['de', 'ate'].forEach((id) => document.getElementById(id).addEventListener('change', () => { periodo.value = 'personalizado'; }));
    periodo.addEventListener('change', () => { if (periodo.value !== 'personalizado') periodo.form.submit(); });

    if (typeof Chart === 'undefined') return;
    const d = ${jsonSeguro(dados)};
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
</script>`);

  return pagina(ctx, {
    titulo: 'Relatórios',
    subtitulo: `Desempenho de ${dataBr(de)} a ${dataBr(ate)} (${dias} ${dias === 1 ? 'dia' : 'dias'})`,
    paginaAtual: 'relatorios',
    cabecaExtra: cru('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>'),
    rodapeExtra,
  }, conteudo);
};
