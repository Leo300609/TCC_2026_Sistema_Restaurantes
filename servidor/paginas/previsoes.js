'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const prev = require('../previsao');
const {
  h, cru, ler, moeda, numero, dataBr, data, hoje, paraMs, somarDias, entradaInt, url, variacao, deltaHtml, sparkline, jsonSeguro,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const NOMES_DIA = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
const CURTO_DIA = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
const ROTULO_CLASSE = {
  ALTA: ['ok', 'Em alta', 'fa-arrow-trend-up'], EMERGENTE: ['novo', 'Entrando em tendência', 'fa-seedling'],
  QUEDA: ['critico', 'Perdendo força', 'fa-arrow-trend-down'], ESTAVEL: ['inativo', 'Estável', 'fa-minus'],
};
const r2 = (v) => Math.round(v * 100) / 100;
const soma = (v) => v.reduce((s, x) => s + x, 0);

module.exports = async function previsoes(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.relatorios);

  const janela = [7, 14, 28].includes(entradaInt(ctx, 'janela', 14)) ? entradaInt(ctx, 'janela', 14) : 14;

  const [linhasProd, seriesProd, fatBd, insumosBd, calorBd] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_prev_produtos', [janela])),
    ler(ctx, () => bd.chamar('sp_prev_serie_produtos', [janela * 2])),
    ler(ctx, () => bd.chamar('sp_prev_faturamento', [56])),
    ler(ctx, () => bd.chamar('sp_prev_insumos', [28])),
    ler(ctx, () => bd.chamar('sp_prev_mapa_calor', [8])),
  ]);

  // ---------- produtos: tendência e previsão ----------
  const produtos = prev.analisarTendencias(linhasProd, janela);
  const porProduto = {};
  for (const s of seriesProd) (porProduto[N(s.id)] ??= {})[String(s.dia).slice(0, 10)] = N(s.quantidade);
  for (const p of produtos) p.serie = prev.serieCompleta(porProduto[N(p.id)] || {}, janela * 2);

  const grupo = (classe) => produtos.filter((p) => p.classe === classe);
  const emAlta = grupo('ALTA').sort((a, b) => b.variacao - a.variacao);
  const emergentes = grupo('EMERGENTE').sort((a, b) => N(b.atual) - N(a.atual));
  const emQueda = grupo('QUEDA').sort((a, b) => a.variacao - b.variacao);
  const destaque = emergentes[0] || emAlta[0] || null;

  // ---------- faturamento: histórico de 56 dias e próximos 7 ----------
  const historico = {}, historicoPedidos = {};
  for (const d of fatBd) {
    const dia = String(d.dia).slice(0, 10);
    historico[dia] = N(d.faturamento);
    historicoPedidos[dia] = N(d.pedidos);
  }
  const prevFat = prev.preverDiario(historico);
  const prevPed = prev.preverDiario(historicoPedidos);
  const fatPrevisto = soma(prevFat.dias.map((d) => d.previsto));
  const fatUltimos7 = soma(prev.serieCompleta(historico, 7));
  const pedPrevisto = soma(prevPed.dias.map((d) => d.previsto));
  const maiorDia = prevFat.dias.reduce((c, d) => (c === null || d.previsto > c.previsto ? d : c), null);

  // ---------- reposição ----------
  const reposicao = prev.preverReposicao(insumosBd, prevFat.fator);
  const emRisco = reposicao.filter((r) => r.dias_restantes <= 7);
  const custoReposicao = soma(reposicao.filter((r) => r.dias_restantes <= 14).map((r) => r.custo_compra));

  // ---------- mapa de calor (média de pedidos por dia da semana e hora) ----------
  const calor = {};
  const horasComVenda = [];
  for (const c of calorBd) { (calor[N(c.dia)] ??= {})[N(c.hora)] = N(c.media); horasComVenda.push(N(c.hora)); }
  const hIni = horasComVenda.length ? Math.min(...horasComVenda) : 11;
  const hFim = horasComVenda.length ? Math.max(...horasComVenda) : 23;
  let maxCalor = 0;
  for (const hs of Object.values(calor)) maxCalor = Math.max(maxCalor, ...Object.values(hs));
  const diasCalor = [[3, 'Ter'], [4, 'Qua'], [5, 'Qui'], [6, 'Sex'], [7, 'Sáb'], [1, 'Dom'], [2, 'Seg']];
  const picos = [];
  for (const [d, hs] of Object.entries(calor)) for (const [hh, v] of Object.entries(hs)) picos.push([Number(d), Number(hh), v]);
  picos.sort((a, b) => b[2] - a[2]);

  // ---------- dados dos gráficos ----------
  const serieFat = prev.serieCompleta(historico, 28);
  const crescimento2s = variacao(soma(serieFat.slice(14)), soma(serieFat.slice(0, 14)));
  const grafico = { rotulos: [], real: [], previsto: [], minimo: [], maximo: [] };
  const rotuloDia = (ms) => `${CURTO_DIA[Number(data('w', ms))]} ${data('d/m', ms)}`;
  serieFat.forEach((v, i) => {
    grafico.rotulos.push(rotuloDia(paraMs(somarDias(hoje(), -(28 - i)))));
    grafico.real.push(r2(v));
    grafico.previsto.push(i === 27 ? r2(v) : null);   // liga a linha prevista ao último dia real
    grafico.minimo.push(null);
    grafico.maximo.push(null);
  });
  for (const d of prevFat.dias) {
    grafico.rotulos.push(rotuloDia(paraMs(d.data)));
    grafico.real.push(null);
    grafico.previsto.push(r2(d.previsto));
    grafico.minimo.push(r2(d.minimo));
    grafico.maximo.push(r2(d.maximo));
  }
  const topComparativo = produtos.slice(0, 8);
  const comparativo = {
    rotulos: topComparativo.map((p) => p.nome),
    anterior: topComparativo.map((p) => Math.trunc(N(p.anterior))),
    atual: topComparativo.map((p) => Math.trunc(N(p.atual))),
    previsto: topComparativo.map((p) => Math.round((p.previsto7 * janela) / 7)),
  };

  const cartaoTendencia = (p) => {
    const [classe, , icone] = ROTULO_CLASSE[p.classe];
    const cor = p.classe === 'QUEDA' ? 'var(--brasa)' : p.classe === 'EMERGENTE' ? 'var(--azul)' : 'var(--salvia)';
    const sinal = p.variacao >= 0 ? '+' : '−';
    return h`<li class="tendencia-item">
      <div class="tendencia-topo"><span class="celula-dupla"><strong>${p.nome}</strong><small>${p.categoria}</small></span>
      <span class="status ${classe}"><i class="fa-solid ${icone}" aria-hidden="true"></i> ${sinal}${numero(Math.abs(p.variacao), 0)}%</span></div>
      ${sparkline(p.serie, cor)}
      <div class="tendencia-numeros"><span><small>Últimos ${janela} dias</small><strong>${Math.trunc(N(p.atual))} un.</strong></span>
      <span><small>Antes</small><strong>${Math.trunc(N(p.anterior))} un.</strong></span>
      <span><small>Próximos 7 dias</small><strong>≈ ${Math.round(p.previsto7)} un.</strong></span></div>
    </li>`;
  };

  const maxPrev = Math.max(1, ...prevFat.dias.map((d) => d.maximo));
  const picosTexto = picos.slice(0, 3);

  const conteudo = h`
        <form class="toolbar" method="get" action="previsoes">
          <div class="toolbar-grupo">
            <div class="filtros" role="group" aria-label="Janela de comparação">
              ${[[7, '7 dias'], [14, '14 dias'], [28, '28 dias']].map(([v, r]) => h`<a class="filtro${janela === v ? ' ativo' : ''}" href="${url(ctx, { janela: v === 14 ? null : v })}">Comparar ${r}</a>`)}
            </div>
          </div>
          <span class="painel-meta"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Baseado nas vendas até ontem. Hoje ainda está em andamento.</span>
        </form>

        <section class="grid-cards" aria-label="Resumo das previsões">
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span> Faturamento previsto</span>
            <div class="card-valor">${moeda(fatPrevisto)}</div>
            <span class="card-detalhe">${deltaHtml(variacao(fatPrevisto, fatUltimos7))} próximos 7 dias vs. últimos 7</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span> Pedidos previstos</span>
            <div class="card-valor">≈ ${numero(pedPrevisto)}</div>
            <span class="card-detalhe">${maiorDia ? 'Dia mais forte: ' + NOMES_DIA[Number(data('w', paraMs(maiorDia.data)))] + ' ' + data('d/m', paraMs(maiorDia.data)) : 'Sem histórico suficiente'}</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true"></i></span> Produto em destaque</span>
            <div class="card-valor" style="font-size:20px;white-space:normal">${destaque ? destaque.nome : 'Nenhum'}</div>
            <span class="card-detalhe ${destaque ? 'positivo' : ''}">${destaque ? '+' + numero(destaque.variacao, 0) + '% em ' + janela + ' dias · ' + ROTULO_CLASSE[destaque.classe][1] : 'Vendas estáveis no período'}</span>
          </div>
          <a class="card-metrica alerta-box${emRisco.length ? ' ativo-alerta' : ''}" href="#reposicao" style="text-decoration:none">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-box-open" aria-hidden="true"></i></span> Acabam em até 7 dias</span>
            <div class="card-valor">${emRisco.length}</div>
            <span class="card-detalhe ${emRisco.length ? 'alerta' : ''}">${emRisco.length ? 'Compra sugerida: ' + moeda(custoReposicao) : 'Estoque cobre a próxima semana'}</span>
          </a>
        </section>

        <div class="dashboard-secoes">
          <section class="painel-card" aria-labelledby="t-fat">
            <div class="painel-header">
              <h2 id="t-fat"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Faturamento: realizado e previsto</h2>
              <span class="painel-meta">Últimas 2 semanas vs. 2 anteriores: ${crescimento2s === null ? '—' : (crescimento2s >= 0 ? '+' : '−') + Math.abs(crescimento2s) + '%'}</span>
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
            <ul class="lista-linhas previsao-dias">
              ${prevFat.dias.map((d, i) => {
                const t = paraMs(d.data);
                return h`<li>
                  <span class="celula-dupla" style="min-width:92px"><strong>${i === 0 ? 'Hoje' : NOMES_DIA[Number(data('w', t))].replace(/^./, (c) => c.toUpperCase())}</strong><small>${data('d/m', t)} · ≈ ${numero(prevPed.dias[i].previsto)} pedidos</small></span>
                  <span class="faixa-previsao" aria-hidden="true">
                    <span class="faixa-intervalo" style="left: ${Math.round((d.minimo / maxPrev) * 100)}%; width: ${Math.max(1, Math.round(((d.maximo - d.minimo) / maxPrev) * 100))}%"></span>
                    <span class="faixa-ponto" style="left: ${Math.round((d.previsto / maxPrev) * 100)}%"></span>
                  </span>
                  <span class="lado"><strong>${d.previsto > 0 ? moeda(d.previsto) : 'Fechado'}</strong>${d.previsto > 0 ? h`<br><small class="muted">${moeda(d.minimo)} a ${moeda(d.maximo)}</small>` : ''}</span>
                </li>`;
              })}
            </ul>
          </section>
        </div>

        <div class="dashboard-secoes tres tendencias">
          <section class="painel-card" aria-labelledby="t-alta">
            <div class="painel-header"><h2 id="t-alta"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true" style="color:var(--salvia)"></i> Em alta</h2><span class="painel-meta">${emAlta.length}</span></div>
            ${!emAlta.length ? h`<p class="vazio">Nenhum item cresceu mais de 20% no período.</p>` : h`<ul class="tendencias-lista">${emAlta.slice(0, 4).map(cartaoTendencia)}</ul>`}
          </section>
          <section class="painel-card" aria-labelledby="t-emergente">
            <div class="painel-header"><h2 id="t-emergente"><i class="fa-solid fa-seedling" aria-hidden="true" style="color:var(--azul)"></i> Entrando em tendência</h2><span class="painel-meta">${emergentes.length}</span></div>
            ${!emergentes.length ? h`<p class="vazio">Nenhum item saiu de vendas baixas para um crescimento forte.</p>` : h`
              <ul class="tendencias-lista">${emergentes.slice(0, 4).map(cartaoTendencia)}</ul>
              <p class="painel-nota" style="margin-top:4px">Vale destacar no cardápio e garantir os insumos desses itens.</p>`}
          </section>
          <section class="painel-card" aria-labelledby="t-queda">
            <div class="painel-header"><h2 id="t-queda"><i class="fa-solid fa-arrow-trend-down" aria-hidden="true" style="color:var(--brasa)"></i> Perdendo força</h2><span class="painel-meta">${emQueda.length}</span></div>
            ${!emQueda.length ? h`<p class="vazio">Nenhum item caiu mais de 20% no período.</p>` : h`
              <ul class="tendencias-lista">${emQueda.slice(0, 4).map(cartaoTendencia)}</ul>
              <p class="painel-nota" style="margin-top:4px">Considere uma promoção ou revisar o preço e a apresentação.</p>`}
          </section>
        </div>

        <div class="dashboard-secoes">
          <section class="painel-card" aria-labelledby="t-comp">
            <div class="painel-header"><h2 id="t-comp"><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Mais vendidos: antes, agora e previsão</h2><span class="painel-meta">Unidades em ${janela} dias</span></div>
            <div class="chart-container alto"><canvas id="g-comparativo" role="img" aria-label="Unidades vendidas por produto no período anterior, no atual e a previsão do próximo"></canvas></div>
          </section>

          <section class="painel-card" aria-labelledby="t-calor">
            <div class="painel-header"><h2 id="t-calor"><i class="fa-solid fa-fire" aria-hidden="true"></i> Quando o salão enche</h2><span class="painel-meta">Média de pedidos · 8 semanas</span></div>
            <div class="painel-corpo">
              <div class="mapa-calor" style="--colunas: ${hFim - hIni + 1}" role="img" aria-label="Mapa de calor de pedidos por dia da semana e hora">
                <span></span>
                ${Array.from({ length: hFim - hIni + 1 }, (_, i) => h`<span class="mc-hora">${hIni + i}h</span>`)}
                ${diasCalor.map(([d, rot]) => h`
                  <span class="mc-dia">${rot}</span>
                  ${Array.from({ length: hFim - hIni + 1 }, (_, i) => {
                    const hh = hIni + i;
                    const v = calor[d]?.[hh] ?? 0;
                    return h`<span class="mc-celula" style="--i: ${maxCalor > 0 ? r2(v / maxCalor) : 0}" title="${rot} ${hh}h: ${numero(v, 1)} pedidos em média"></span>`;
                  })}`)}
              </div>
              ${picos.length ? h`
                <p class="painel-meta" style="margin-top:14px">Picos previstos: ${picosTexto.map(([d, hh, v], n) => h`<strong>${NOMES_DIA[d - 1]} às ${hh}h</strong> (≈ ${numero(v, 1)} pedidos)${n < 2 ? ', ' : '.'}`)} Reforce a equipe nesses horários.</p>` : ''}
            </div>
          </section>
        </div>

        <section class="painel-card painel-tabela" id="reposicao" aria-labelledby="t-repo">
          <div class="painel-header">
            <h2 id="t-repo"><i class="fa-solid fa-cart-flatbed" aria-hidden="true"></i> Reposição prevista</h2>
            <span class="toolbar-grupo" style="width:auto"><span class="painel-meta">Consumo pela ficha técnica · cobertura de 14 dias</span>${u.perm.compras ? h`<a class="btn btn-pequeno btn-primario" href="compras?novo=1"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Registrar compra</a>` : ''}</span>
          </div>
          ${!reposicao.length ? h`<p class="vazio">Sem consumo registrado para projetar. Cadastre a ficha técnica dos itens do cardápio.</p>` : h`
            <div class="tabela-container">
              <table class="tabela">
                <thead><tr><th scope="col">Item</th><th scope="col" class="num">Em estoque</th><th scope="col" class="num">Consumo/dia</th><th scope="col">Duração prevista</th><th scope="col">Acaba em</th><th scope="col" class="num">Comprar</th><th scope="col" class="num">Custo</th></tr></thead>
                <tbody>
                  ${reposicao.slice(0, 15).map((r) => {
                    const dr = r.dias_restantes;
                    const nivelCor = dr <= 3 ? 'brasa' : dr <= 7 ? 'mostarda' : '';
                    return h`<tr>
                      <td><span class="celula-dupla"><strong>${r.nome}</strong><small>${r.categoria}</small></span></td>
                      <td class="num">${numero(r.estoque)} ${r.unidade}</td>
                      <td class="num">${numero(r.consumo_previsto, 1)}</td>
                      <td><div class="nivel" style="min-width:140px"><span class="painel-meta">${dr >= 60 ? 'mais de 60 dias' : numero(dr, dr < 10 ? 1 : 0) + ' dias'}</span><span class="progresso ${nivelCor}"><span style="--nivel: ${Math.min(100, Math.round((dr / 30) * 100))}%"></span></span></div></td>
                      <td class="data">${dr >= 60 ? '—' : dataBr(r.acaba_em)}</td>
                      <td class="num">${r.comprar > 0 ? h`<strong>${numero(r.comprar)} ${r.unidade}</strong>` : h`<span class="muted">—</span>`}</td>
                      <td class="num">${r.comprar > 0 ? moeda(r.custo_compra) : h`<span class="muted">—</span>`}</td>
                    </tr>`;
                  })}
                </tbody>
              </table>
            </div>`}
        </section>

        <details class="painel-card metodo" style="margin-top:14px">
          <summary class="painel-header"><h2><i class="fa-solid fa-flask" aria-hidden="true"></i> Como as previsões são calculadas</h2><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></summary>
          <div class="painel-corpo metodo-texto">
            <p><strong>Tendência dos produtos.</strong> Comparamos as vendas dos últimos ${janela} dias com os ${janela} dias anteriores e ajustamos uma regressão linear sobre as vendas diárias (y = a + b·x). Um item está <em>em alta</em> quando cresce 20% ou mais com inclinação positiva, <em>perdendo força</em> quando cai 20% ou mais, e <em>entrando em tendência</em> quando sai de vendas baixas e ao menos dobra.</p>
            <p><strong>Unidades dos próximos 7 dias.</strong> Média entre a reta da regressão projetada e a média diária recente, para não exagerar quando a reta é muito inclinada.</p>
            <p><strong>Faturamento por dia.</strong> Média ponderada do mesmo dia da semana nas últimas 4 semanas (pesos 4, 3, 2 e 1), multiplicada por um fator de tendência amortecido entre −15% e +20%. A faixa provável usa ±1,28 desvio-padrão, cerca de 80% de confiança.</p>
            <p><strong>Reposição.</strong> O consumo diário de cada insumo vem da ficha técnica dos itens vendidos nos últimos 28 dias, ajustado pela tendência de vendas. A compra sugerida cobre 14 dias acima do estoque mínimo.</p>
          </div>
        </details>`;

  const rodapeExtra = cru(`<script>
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;
    const d = ${jsonSeguro({ previsao: grafico, comparativo })};
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
</script>`);

  return pagina(ctx, {
    titulo: 'Previsões',
    subtitulo: 'Tendências do cardápio, faturamento esperado e reposição do estoque',
    paginaAtual: 'previsoes',
    cabecaExtra: cru('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>'),
    rodapeExtra,
  }, conteudo);
};
