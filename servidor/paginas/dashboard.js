'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina, corAvatar } = require('../layout');
const { h, cru, e, ler, moeda, variacao, deltaHtml, sparkline, dataBr, data, somarDias, hoje, jsonSeguro, iniciais, MESES } = require('../web');

const NOMES_ETAPA = {
  NOVO: ['novo', 'Novo'], PREPARO: ['em-preparo', 'Em preparo'], PRONTO: ['pronto', 'Pronto'],
  ENTREGUE: ['entregue', 'Entregue'], CANCELADO: ['cancelado', 'Cancelado'],
};
const N = (v) => Number(v ?? 0) || 0;

module.exports = async function dashboard(ctx) {
  const u = await sessao.autenticar(ctx);
  const { perm } = u;

  const cfg = await ler(ctx, () => bd.chamarUm('sp_config_listar', [u.id]), { dias_validade: 3 });
  const [rBruto, etapasBruto, ultimos, maisVendidos, equipe, mes, alertas, semana, horasBd] = await Promise.all([
    ler(ctx, () => bd.chamarUm('sp_dash_resumo'), null),
    ler(ctx, () => bd.chamarUm('sp_dash_etapas'), null),
    ler(ctx, () => bd.chamar('sp_dash_ultimos', [6])),
    ler(ctx, () => bd.chamar('sp_rel_produtos', [somarDias(hoje(), -6), hoje(), 5])),
    ler(ctx, () => bd.chamar('sp_dash_equipe_online')),
    perm.financeiro ? ler(ctx, () => bd.chamarUm('sp_dash_mes'), null) : null,
    perm.estoque ? ler(ctx, () => bd.chamar('sp_dash_alertas_estoque', [N(cfg.dias_validade) || 3])) : [],
    ler(ctx, () => bd.chamar('sp_dash_semana')),
    ler(ctx, () => bd.chamar('sp_dash_horas')),
  ]);
  const r = {
    faturamento_hoje: 0, faturamento_ontem_ate_agora: 0, pedidos_hoje: 0, pedidos_ontem_ate_agora: 0,
    pedidos_ultima_hora: 0, ticket_hoje: null, ticket_30dias: null, em_andamento: 0, atrasados: 0,
    em_entrega: 0, cancelados_hoje: 0, mesas_ocupadas: 0, mesas_total: 0, tempo_preparo: 20, ...(rBruto || {}),
  };
  const etapas = { novos: 0, preparo: 0, prontos: 0, entregues: 0, ...(etapasBruto || {}) };

  // Últimos 14 dias: sete atuais para os minigráficos, sete anteriores para comparar
  const porDia = {};
  for (const d of semana) porDia[String(d.dia).slice(0, 10)] = d;
  const fat7 = [], ped7 = [];
  let fatSemana = 0, fatSemanaAnterior = 0;
  for (let i = 13; i >= 0; i--) {
    const dia = somarDias(hoje(), -i);
    const f = N(porDia[dia]?.faturamento);
    if (i <= 6) { fat7.push(f); ped7.push(N(porDia[dia]?.pedidos)); fatSemana += f; } else fatSemanaAnterior += f;
  }

  // Vendas por hora: hoje x mesmo dia da semana passada
  const horas = { HOJE: new Array(24).fill(0), SEMANA_PASSADA: new Array(24).fill(0) };
  for (const x of horasBd) horas[x.dia][N(x.hora)] = N(x.total);
  const comVenda = horas.HOJE.map((v, i) => (v > 0 || horas.SEMANA_PASSADA[i] > 0 ? i : -1)).filter((i) => i >= 0);
  const horaIni = Math.min(11, comVenda.length ? Math.min(...comVenda) : 11);
  const horaFim = Math.max(23, comVenda.length ? Math.max(...comVenda) : 23);
  const horaAgora = Number(data('G'));
  const grafico = { rotulos: [], hoje: [], passada: [] };
  const r2 = (v) => Math.round(v * 100) / 100;
  for (let hh = horaIni; hh <= horaFim; hh++) {
    grafico.rotulos.push(String(hh).padStart(2, '0') + 'h');
    grafico.hoje.push(hh <= horaAgora ? r2(horas.HOJE[hh]) : null);
    grafico.passada.push(r2(horas.SEMANA_PASSADA[hh]));
  }
  const diaSemanaPassada = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'][Number(data('w'))];

  const hora = Number(data('G'));
  const saudacao = hora >= 5 && hora < 12 ? 'Bom dia' : hora >= 12 && hora < 18 ? 'Boa tarde' : 'Boa noite';
  const primeiroNome = String(u.nome).trim().split(' ')[0];
  const ocupacao = N(r.mesas_total) ? Math.round((N(r.mesas_ocupadas) / N(r.mesas_total)) * 100) : 0;

  const dTicket = r.ticket_hoje !== null && N(r.ticket_30dias) ? deltaHtml(variacao(N(r.ticket_hoje), N(r.ticket_30dias))) : null;
  const dFat = deltaHtml(variacao(N(r.faturamento_hoje), N(r.faturamento_ontem_ate_agora)));
  const saldoMes = mes ? N(mes.receitas) - N(mes.despesas) : 0;
  const baseMes = mes ? Math.max(N(mes.receitas), N(mes.despesas), 1) : 1;
  const maiorReceita = maisVendidos.length ? Math.max(...maisVendidos.map((x) => N(x.receita))) || 1 : 1;

  const conteudo = h`
        <section class="grid-cards cinco" aria-label="Indicadores de hoje">
          ${perm.financeiro ? h`
            <div class="card-metrica">
              <span class="card-titulo"><span class="card-icone brasa"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span> Faturamento hoje</span>
              <div class="card-valor">${moeda(r.faturamento_hoje)}</div>
              <span class="card-detalhe">${dFat.s ? h`${dFat} <span>vs. ontem neste horário</span>` : h`<span>Ontem não havia vendas até este horário</span>`}</span>
              ${sparkline(fat7)}
            </div>` : ''}
          <div class="card-metrica">
            <span class="card-titulo"><span class="card-icone azul"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span> Pedidos hoje</span>
            <div class="card-valor">${N(r.pedidos_hoje)}</div>
            <span class="card-detalhe">${deltaHtml(variacao(N(r.pedidos_hoje), N(r.pedidos_ontem_ate_agora)))} <span>${N(r.pedidos_ultima_hora)} na última hora</span></span>
            ${sparkline(ped7, 'var(--azul)')}
          </div>
          ${perm.financeiro ? h`
            <div class="card-metrica">
              <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i></span> Ticket médio</span>
              <div class="card-valor">${r.ticket_hoje === null ? '—' : moeda(r.ticket_hoje)}</div>
              <span class="card-detalhe">${dTicket ? h`${dTicket} <span>vs. média de 30 dias</span>` : h`<span>Pedidos entregues hoje</span>`}</span>
            </div>` : h`
            <div class="card-metrica">
              <span class="card-titulo"><span class="card-icone salvia"><i class="fa-solid fa-motorcycle" aria-hidden="true"></i></span> Em entrega</span>
              <div class="card-valor">${N(r.em_entrega)}</div>
              <span class="card-detalhe"><span>Delivery a caminho do cliente</span></span>
            </div>`}
          <a class="card-metrica alerta-box${N(r.atrasados) > 0 ? ' ativo-alerta' : ''}" href="pedidos" style="text-decoration:none">
            <span class="card-titulo"><span class="card-icone mostarda"><i class="fa-solid fa-fire-burner" aria-hidden="true"></i></span> Em andamento</span>
            <div class="card-valor">${N(r.em_andamento)}</div>
            <span class="card-detalhe ${N(r.atrasados) > 0 ? 'alerta' : ''}">
              ${N(r.atrasados) > 0 ? h`<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> ${N(r.atrasados)} acima de ${N(r.tempo_preparo)} min` : 'Nenhum pedido atrasado'}
            </span>
          </a>
          <a class="card-metrica" href="mesas" style="text-decoration:none">
            <span class="card-titulo"><span class="card-icone roxo"><i class="fa-solid fa-chair" aria-hidden="true"></i></span> Mesas ocupadas</span>
            <div class="card-valor">${N(r.mesas_ocupadas)}<small style="font-size:16px;color:var(--tinta-3)"> / ${N(r.mesas_total)}</small></div>
            <span class="progresso azul" aria-hidden="true"><span style="--nivel: ${ocupacao}%"></span></span>
            <span class="card-detalhe"><span>${ocupacao}% de ocupação do salão</span></span>
          </a>
        </section>

        <div class="dashboard-secoes">
          <section class="painel-card" aria-labelledby="t-horas">
            <div class="painel-header">
              <h2 id="t-horas"><i class="fa-solid fa-chart-area" aria-hidden="true"></i> Vendas por hora</h2>
              <span class="painel-meta">Hoje x ${diaSemanaPassada} passado(a)</span>
            </div>
            <div class="chart-container alto">
              <canvas id="grafico-horas" role="img" aria-label="Faturamento por hora de hoje comparado com o mesmo dia da semana passada"></canvas>
            </div>
            <ul class="legenda">
              <li><span class="ponto" style="background:var(--brasa)" aria-hidden="true"></span> Hoje</li>
              <li><span class="ponto" style="background:var(--fio-forte)" aria-hidden="true"></span> Mesmo dia da semana passada</li>
              <li style="margin-left:auto">Semana: <strong>${moeda(fatSemana)}</strong> ${deltaHtml(variacao(fatSemana, fatSemanaAnterior))}</li>
            </ul>
          </section>

          <section class="painel-card" aria-labelledby="t-agora">
            <div class="painel-header">
              <h2 id="t-agora"><i class="fa-solid fa-bolt" aria-hidden="true"></i> Pedidos agora</h2>
              <a href="pedidos" class="btn-link">Abrir quadro</a>
            </div>
            <div class="etapas-faixa">
              <a href="pedidos" style="--cor: var(--azul)"><strong>${N(etapas.novos)}</strong><span>Novos</span></a>
              <a href="cozinha" style="--cor: var(--mostarda)"><strong>${N(etapas.preparo)}</strong><span>Em preparo</span></a>
              <a href="pedidos" style="--cor: var(--brasa)"><strong>${N(etapas.prontos)}</strong><span>Prontos</span></a>
              <a href="pedidos" style="--cor: var(--salvia)"><strong>${N(etapas.entregues)}</strong><span>Entregues hoje</span></a>
            </div>
            ${!ultimos.length ? h`<p class="vazio">Nenhum pedido registrado ainda. <a href="pdv">Abrir o PDV</a></p>` : h`
              <ul class="lista-linhas">
                ${ultimos.map((p) => {
                  const [classe, rotulo] = NOMES_ETAPA[p.etapa] || ['', p.etapa];
                  const origem = p.cliente || (p.tipo === 'SALAO' ? 'Mesa ' + p.mesa : p.tipo === 'DELIVERY' ? 'Delivery' : 'Balcão');
                  return h`<li>
                    <span class="celula-dupla" style="flex:1">
                      <strong><span class="id-pedido">#${N(p.id)}</span> ${origem}</strong>
                      <small>${p.hora} · ${p.itens || 'sem itens'}</small>
                    </span>
                    <span class="lado">
                      <strong>${moeda(p.total)}</strong><br>
                      <span class="status ${classe}">${rotulo}</span>
                    </span>
                  </li>`;
                })}
              </ul>`}
          </section>
        </div>

        <div class="dashboard-secoes tres">
          <section class="painel-card" aria-labelledby="t-top">
            <div class="painel-header">
              <h2 id="t-top"><i class="fa-solid fa-ranking-star" aria-hidden="true"></i> Mais vendidos</h2>
              <span class="painel-meta">Últimos 7 dias</span>
            </div>
            ${!maisVendidos.length ? h`<p class="vazio">Sem vendas nos últimos 7 dias.</p>` : h`
              <ol class="ranking">
                ${maisVendidos.map((p, n) => h`<li>
                    <span class="pos">${n + 1}</span>
                    <span class="nome">${p.nome} <small class="muted">${N(p.quantidade)} un.</small></span>
                    <span class="valor">${perm.financeiro ? moeda(p.receita) : N(p.quantidade) + ' un.'}</span>
                    <span class="progresso brasa"><span style="--nivel: ${Math.round((N(p.receita) / maiorReceita) * 100)}%"></span></span>
                  </li>`)}
              </ol>`}
          </section>

          ${mes ? h`
            <section class="painel-card" aria-labelledby="t-mes">
              <div class="painel-header">
                <h2 id="t-mes"><i class="fa-solid fa-wallet" aria-hidden="true"></i> Caixa do mês</h2>
                <a href="fluxo_de_caixa" class="btn-link">Fluxo de caixa</a>
              </div>
              <div class="painel-corpo">
                <div class="card-valor" style="color: ${saldoMes >= 0 ? 'var(--salvia-texto)' : 'var(--brasa-texto)'}">${moeda(saldoMes)}</div>
                <p class="painel-meta" style="margin-bottom:14px">Saldo realizado em ${MESES[Number(data('n')) - 1]} (recebido menos pago)</p>
                <div class="grupo-input" style="gap:4px;margin-bottom:10px">
                  <span class="card-detalhe" style="justify-content:space-between"><span>Receitas</span><strong style="color:var(--tinta)">${moeda(mes.receitas)}</strong></span>
                  <span class="progresso"><span style="--nivel: ${Math.round((N(mes.receitas) / baseMes) * 100)}%"></span></span>
                </div>
                <div class="grupo-input" style="gap:4px">
                  <span class="card-detalhe" style="justify-content:space-between"><span>Despesas</span><strong style="color:var(--tinta)">${moeda(mes.despesas)}</strong></span>
                  <span class="progresso brasa"><span style="--nivel: ${Math.round((N(mes.despesas) / baseMes) * 100)}%"></span></span>
                </div>
              </div>
              <div class="saldo-mes">
                <div><small>A receber</small><strong>${moeda(mes.a_receber)}</strong></div>
                <div><small>A pagar</small><strong>${moeda(mes.a_pagar)}</strong>${N(mes.vencido) > 0 ? h`<small style="color:var(--brasa-texto)">${moeda(mes.vencido)} vencido</small>` : ''}</div>
              </div>
            </section>` : ''}

          ${perm.estoque ? h`
            <section class="painel-card" aria-labelledby="t-alertas">
              <div class="painel-header">
                <h2 id="t-alertas"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Alertas de estoque</h2>
                <span class="painel-meta">${alertas.length} ${alertas.length === 1 ? 'item' : 'itens'}</span>
              </div>
              <div class="lista-rolavel">
                ${!alertas.length ? h`<p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-check" aria-hidden="true"></i></span>Estoque em dia.</p>` : ''}
                ${alertas.map((a) => {
                  const critico = ['VENCIDO', 'CRITICO'].includes(a.situacao);
                  let motivo;
                  if (a.situacao === 'VENCIDO') motivo = 'Vencido em ' + dataBr(a.validade);
                  else if (a.situacao === 'CRITICO') motivo = `${N(a.estoque)} ${a.unidade} em estoque · mínimo ${N(a.minimo)}`;
                  else motivo = N(a.dias) === 0 ? 'Vence hoje' : `Vence em ${N(a.dias)} ${N(a.dias) === 1 ? 'dia' : 'dias'}`;
                  return h`<a class="alerta-vencimento${critico ? ' critico' : ''}" href="estoque?mov=ENTRADA&amp;produto=${N(a.id)}#movimento">
                    <span class="notif-icone"><i class="fa-solid ${a.situacao === 'CRITICO' ? 'fa-box-open' : 'fa-calendar-xmark'}" aria-hidden="true"></i></span>
                    <span class="celula-dupla"><strong>${a.nome}</strong><small>${motivo}</small></span>
                  </a>`;
                })}
              </div>
            </section>` : ''}

          <section class="painel-card" aria-labelledby="t-equipe">
            <div class="painel-header">
              <h2 id="t-equipe"><i class="fa-solid fa-user-group" aria-hidden="true"></i> Equipe online</h2>
              <a href="empresa" class="btn-link">Equipe</a>
            </div>
            ${!equipe.length ? h`<p class="vazio">Ninguém mais conectado agora.</p>` : h`
              <ul class="lista-linhas">
                ${equipe.map((m) => h`<li>
                    <span class="avatar ${corAvatar(m.nome)}" aria-hidden="true">${iniciais(m.nome)}</span>
                    <span class="celula-dupla"><strong>${m.nome}</strong><small>${sessao.rotuloCargo(m.cargo, m.nivel)}</small></span>
                    <span class="lado presenca online">${N(m.minutos) < 1 ? 'agora' : 'há ' + N(m.minutos) + ' min'}</span>
                  </li>`)}
              </ul>`}
          </section>
        </div>`;

  const rodapeExtra = cru(`<script>
  document.addEventListener('DOMContentLoaded', () => {
    if (typeof Chart === 'undefined') return;
    const dados = ${jsonSeguro(grafico)};
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
</script>`);

  return pagina(ctx, {
    titulo: 'Visão geral',
    subtitulo: `${saudacao}, ${primeiroNome}. Este é o movimento de hoje, ${data('d/m')}.`,
    paginaAtual: 'dashboard',
    cabecaExtra: cru('<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" defer></script>'),
    rodapeExtra,
  }, conteudo);
};
