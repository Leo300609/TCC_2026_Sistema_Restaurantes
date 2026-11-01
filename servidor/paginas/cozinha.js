'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const { h, cru, ler, entrada, entradaInt, csrfCampo, tratarPost, url, data } = require('../web');

const N = (v) => Number(v ?? 0) || 0;

const origem = (p) => {
  if (p.tipo === 'SALAO') return h`<i class="fa-solid fa-chair" aria-hidden="true"></i> Mesa ${p.mesa}`;
  if (p.tipo === 'BALCAO') return h`<i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão${p.cliente ? h` · ${p.cliente}` : ''}`;
  return h`<i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · ${p.cliente || 'sem cliente'}`;
};
const origemTexto = (p) => (p.tipo === 'SALAO' ? 'Mesa ' + p.mesa : p.tipo === 'BALCAO' ? 'Balcão' + (p.cliente ? ' · ' + p.cliente : '') : 'Delivery · ' + (p.cliente || 'sem cliente'));

module.exports = async function cozinha(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.cozinha);

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const id = entradaInt(ctx, 'id');
    const destino = entrada(ctx, 'etapa');
    if (entrada(ctx, 'acao') !== 'etapa' || !['NOVO', 'PREPARO', 'PRONTO'].includes(destino)) throw new bd.ErroNegocio('Ação não reconhecida.');
    await bd.chamar('sp_pedido_etapa', [id, destino]);
    if (destino === 'PREPARO') return `Pedido #${id} em preparo.`;
    if (destino === 'PRONTO') return `Pedido #${id} pronto para servir.`;
    return `Pedido #${id} voltou para a fila.`;
  });

  // ---------- dados (GET) ----------
  const cfg = await ler(ctx, () => bd.chamarUm('sp_config_listar', [u.id]), { tempo_preparo: 20 });
  const limite = Math.max(1, N(cfg.tempo_preparo));

  const [quadro, itensQuadro] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_pedido_quadro')),
    ler(ctx, () => bd.chamar('sp_pedido_quadro_itens')),
  ]);
  let fila = quadro.filter((p) => ['NOVO', 'PREPARO'].includes(p.etapa));
  const prontos = quadro.filter((p) => p.etapa === 'PRONTO');
  const itens = {};
  for (const it of itensQuadro) (itens[N(it.venda_id)] ??= []).push(it);
  const tipo = ['SALAO', 'BALCAO', 'DELIVERY'].includes(entrada(ctx, 'tipo')) ? entrada(ctx, 'tipo') : '';
  if (tipo) fila = fila.filter((p) => p.tipo === tipo);
  const novos = fila.filter((p) => p.etapa === 'NOVO').length;
  const preparo = fila.length - novos;
  const atrasados = fila.filter((p) => N(p.minutos) > limite).length;

  // resumo do que produzir agora (soma por item)
  const soma = {};
  for (const p of fila) for (const it of itens[N(p.id)] || []) soma[it.nome] = (soma[it.nome] || 0) + N(it.quantidade);
  const producao = Object.entries(soma).sort((a, b) => b[1] - a[1]);
  const agora = Math.floor(Date.now() / 1000);

  const conteudo = h`
<script>document.body.dataset.atualizar = '20';</script>

        <div class="cozinha-topo">
          <div class="toolbar-grupo">
            <span class="relogio" id="relogio">${data('H:i')}</span>
            <div class="cozinha-legenda">
              <span class="status novo">Novos: ${novos}</span>
              <span class="status em-preparo">Em preparo: ${preparo}</span>
              <span class="status ${atrasados ? 'atrasado' : 'ok'}">Acima de ${limite} min: ${atrasados}</span>
            </div>
          </div>
          <div class="toolbar-grupo">
            <div class="filtros" role="group" aria-label="Tipo de pedido">
              ${[['', 'Todos'], ['SALAO', 'Salão'], ['BALCAO', 'Balcão'], ['DELIVERY', 'Delivery']].map(([v, r]) => h`<a class="filtro${tipo === v ? ' ativo' : ''}" href="${url(ctx, { tipo: v || null })}">${r}</a>`)}
            </div>
            <button type="button" class="btn btn-pequeno" id="btn-som" aria-pressed="false"><i class="fa-solid fa-volume-xmark" aria-hidden="true"></i> Som desligado</button>
          </div>
        </div>

        ${producao.length ? h`
          <section class="painel-card" style="margin-bottom:18px" aria-labelledby="t-producao">
            <div class="painel-header"><h2 id="t-producao"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Produzir agora</h2><span class="painel-meta">Soma dos itens na fila</span></div>
            <div class="painel-corpo item-meta">
              ${producao.map(([nome, qtd]) => h`<span class="selo" style="font-size:13px;padding:4px 10px"><strong>${qtd}x</strong>&nbsp;${nome}</span>`)}
            </div>
          </section>` : ''}

        ${!fila.length ? h`<section class="painel-card"><p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-mug-hot" aria-hidden="true"></i></span>Nenhum pedido na fila. Os novos pedidos aparecem aqui automaticamente.</p></section>` : ''}

        <div class="kds" id="kds">
          ${fila.map((p) => {
            const atrasado = N(p.minutos) > limite;
            const min = N(p.minutos);
            return h`
            <article class="comanda${atrasado ? ' atrasada' : ''}" data-etapa="${p.etapa}" data-id="${N(p.id)}" data-inicio="${agora - min * 60}" data-limite="${limite}">
              <header class="comanda-topo">
                <strong class="comanda-numero">#${N(p.id)}</strong>
                <span class="cronometro" aria-label="Tempo desde o pedido">${Math.floor(min / 60)}:${String(min % 60).padStart(2, '0')}</span>
              </header>
              <p class="comanda-origem">${origem(p)} <span style="margin-left:auto" class="comanda-hora">${p.hora}</span></p>
              <ul class="comanda-itens">
                ${(itens[N(p.id)] || []).map((it) => h`<li><span class="qtd">${N(it.quantidade)}x</span> ${it.nome}${it.observacao ? h` <em>${it.observacao}</em>` : ''}</li>`)}
              </ul>
              ${p.observacao ? h`<p class="comanda-obs"><i class="fa-regular fa-note-sticky" aria-hidden="true"></i> ${p.observacao}</p>` : ''}
              <form method="post" action="${url(ctx)}" class="comanda-rodape" style="gap:8px">
                ${csrfCampo(ctx)}
                <input type="hidden" name="acao" value="etapa">
                <input type="hidden" name="id" value="${N(p.id)}">
                ${p.etapa === 'NOVO' ? h`
                  <button type="submit" name="etapa" value="PREPARO" class="btn btn-primario kds-acao"><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> Iniciar preparo</button>` : h`
                  <button type="submit" name="etapa" value="NOVO" class="btn btn-icone" style="min-height:44px;width:44px" title="Voltar para a fila" aria-label="Voltar o pedido ${N(p.id)} para a fila"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>
                  <button type="submit" name="etapa" value="PRONTO" class="btn btn-sucesso kds-acao"><i class="fa-solid fa-bell-concierge" aria-hidden="true"></i> Pronto</button>`}
              </form>
            </article>`;
          })}
        </div>

        ${prontos.length ? h`
          <h2 class="area-titulo" style="margin-top:26px">Prontos aguardando retirada <span class="painel-meta">${prontos.length}</span></h2>
          <div class="item-meta">
            ${prontos.map((p) => h`<span class="selo" style="font-size:13px;padding:5px 10px">#${N(p.id)} · ${origemTexto(p)} · há ${N(p.minutos_etapa)} min</span>`)}
          </div>` : ''}`;

  const rodapeExtra = cru(`<script>
  (() => {
    // cronômetros ao vivo
    const atualizar = () => {
      const agora = Math.floor(Date.now() / 1000);
      document.querySelectorAll('#kds .comanda').forEach((c) => {
        const seg = Math.max(0, agora - Number(c.dataset.inicio));
        const min = Math.floor(seg / 60);
        c.querySelector('.cronometro').textContent = \`\${Math.floor(min / 60) ? Math.floor(min / 60) + ':' + String(min % 60).padStart(2, '0') : min}:\${String(seg % 60).padStart(2, '0')}\`;
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
    novos.forEach((id) => document.querySelector(\`#kds .comanda[data-id="\${id}"]\`)?.classList.add('nova'));
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
</script>`);

  return pagina(ctx, { titulo: 'Cozinha', subtitulo: 'Fila de preparo por ordem de chegada', paginaAtual: 'cozinha', rodapeExtra }, conteudo);
};
