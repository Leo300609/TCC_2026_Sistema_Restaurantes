'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const {
  h, cru, ler, moeda, entrada, entradaInt, entradaDecimal, csrfCampo, tratarPost, url, formasPagamento, numeroBr,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const ROTULOS = { LIVRE: ['livre', 'Livre'], OCUPADA: ['ocupada', 'Ocupada'], CONTA: ['conta', 'Aguardando conta'], INATIVA: ['inativa', 'Desativada'] };
const ETAPAS = { NOVO: ['novo', 'Novo'], PREPARO: ['em-preparo', 'Em preparo'], PRONTO: ['pronto', 'Servido'] };
const tempo = (min) => (min >= 60 ? `${Math.floor(min / 60)}h${String(min % 60).padStart(2, '0')}` : `${min} min`);

module.exports = async function mesas(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.mesas);
  const ehGerente = u.perm.gerente;

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    const numero = entrada(ctx, 'numero');
    if (acao === 'fechar_conta') {
      const forma = entrada(ctx, 'forma_pagamento');
      const recebido = forma === 'DINHEIRO' && entrada(ctx, 'valor_recebido') !== '' ? entradaDecimal(ctx, 'valor_recebido') : null;
      const r = await bd.chamarUm('sp_mesa_fechar_conta', [numero, forma, recebido]);
      const troco = N(r?.troco);
      return [`Conta da mesa ${numero} fechada: ${moeda(r.total)}${troco > 0 ? ' · troco de ' + moeda(troco) : ''}.`, url(ctx, { mesa: null })];
    }
    if (acao === 'transferir') {
      const destino = entrada(ctx, 'destino');
      await bd.chamar('sp_mesa_transferir', [numero, destino]);
      return [`Pedidos da mesa ${numero} transferidos para a mesa ${destino}.`, url(ctx, { mesa: destino })];
    }
    if (!ehGerente) throw new bd.ErroNegocio('Somente administradores e gerentes podem alterar as mesas.');
    if (acao === 'salvar_mesa') {
      const id = entradaInt(ctx, 'id');
      await bd.chamar('sp_mesa_salvar', [id, numero, entradaInt(ctx, 'lugares', 4), entrada(ctx, 'area'), entrada(ctx, 'status', 'ATIVO')]);
      return [id ? `Mesa ${numero} atualizada.` : `Mesa ${numero} cadastrada.`, url(ctx, { editar: null, nova: null })];
    }
    if (acao === 'excluir_mesa') {
      await bd.chamar('sp_mesa_excluir', [entradaInt(ctx, 'id')]);
      return ['Mesa excluída.', url(ctx, { editar: null, excluir: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const cfg = await ler(ctx, () => bd.chamarUm('sp_config_listar', [u.id]), { pagamentos: 'PIX,CREDITO,DEBITO,DINHEIRO' });
  const pagamentos = String(cfg.pagamentos || '').split(',').filter(Boolean);
  const formas = formasPagamento();

  const todas = await ler(ctx, () => bd.chamar('sp_mesa_listar'));
  const porArea = {};
  for (const m of todas) (porArea[m.area] ??= []).push(m);
  const ativas = todas.filter((m) => m.status === 'ATIVO');
  const contar = (s) => ativas.filter((m) => m.situacao === s).length;
  const livres = contar('LIVRE'), ocupadas = contar('OCUPADA'), naConta = contar('CONTA');
  const lugaresOcupados = ativas.reduce((s, m) => s + (m.situacao !== 'LIVRE' ? N(m.lugares) : 0), 0);
  const lugaresTotal = ativas.reduce((s, m) => s + N(m.lugares), 0) || 1;
  const totalAberto = ativas.reduce((s, m) => s + N(m.total), 0);
  const pctLugares = Math.round((lugaresOcupados / lugaresTotal) * 100);

  const gerenciar = ehGerente && 'gerenciar' in ctx.query;

  // comanda de uma mesa
  const numeroAberto = entrada(ctx, 'mesa');
  const mesaAberta = todas.find((m) => String(m.numero) === numeroAberto) || null;
  const [pedidosMesa, comanda] = mesaAberta ? await Promise.all([
    ler(ctx, () => bd.chamar('sp_mesa_pedidos', [numeroAberto])),
    ler(ctx, () => bd.chamar('sp_mesa_comanda', [numeroAberto])),
  ]) : [[], []];

  // cadastro de mesa (gerência)
  const editarId = entradaInt(ctx, 'editar');
  const formMesa = ehGerente && ('nova' in ctx.query || editarId > 0);
  const mesaEdit = editarId > 0 ? await ler(ctx, () => bd.chamarUm('sp_mesa_obter', [editarId]), null) : null;
  const me = mesaEdit || { id: 0, numero: '', lugares: 4, area: 'Salão', status: 'ATIVO' };
  const excluirId = entradaInt(ctx, 'excluir');
  const alvoExcluir = ehGerente && excluirId > 0 ? await ler(ctx, () => bd.chamarUm('sp_mesa_obter', [excluirId]), null) : null;
  const areas = Object.keys(porArea).length ? Object.keys(porArea) : ['Salão'];

  const totalMesa = mesaAberta ? N(mesaAberta.total) : 0;
  const livresDestino = ativas.filter((m) => m.situacao === 'LIVRE');

  const conteudo = h`
<script>document.body.dataset.atualizar = '${mesaAberta || formMesa || alvoExcluir ? '0' : '30'}';</script>

        <div class="mesas-resumo">
          <div><span class="status livre">Livres</span> <strong>${livres}</strong></div>
          <div><span class="status ocupada">Ocupadas</span> <strong>${ocupadas}</strong></div>
          <div><span class="status conta">Aguardando conta</span> <strong>${naConta}</strong></div>
          <div class="ocupacao">
            <span class="card-detalhe" style="justify-content:space-between"><span>${lugaresOcupados} de ${lugaresTotal} lugares em uso</span><strong style="font-size:13px;color:var(--tinta)">${pctLugares}%</strong></span>
            <span class="progresso azul"><span style="--nivel: ${pctLugares}%"></span></span>
          </div>
          ${u.perm.financeiro ? h`<div><span class="muted">Em aberto no salão</span> <strong>${moeda(totalAberto)}</strong></div>` : ''}
        </div>

        <div class="toolbar">
          <div class="toolbar-grupo">
            <span class="painel-meta">Toque numa mesa livre para abrir a comanda no PDV. A tela se atualiza sozinha a cada 30 segundos.</span>
          </div>
          ${ehGerente ? h`
            <div class="toolbar-grupo">
              ${gerenciar ? h`
                <a class="btn" href="${url(ctx, { gerenciar: null })}"><i class="fa-solid fa-check" aria-hidden="true"></i> Concluir edição</a>
                <a class="btn btn-primario" href="${url(ctx, { nova: 1 })}"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova mesa</a>` : h`
                <a class="btn" href="${url(ctx, { gerenciar: 1 })}"><i class="fa-solid fa-pen-ruler" aria-hidden="true"></i> Gerenciar mesas</a>`}
            </div>` : ''}
        </div>

        ${!todas.length ? h`<section class="painel-card"><p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-chair" aria-hidden="true"></i></span>Nenhuma mesa cadastrada.${ehGerente ? h` <a href="${url(ctx, { nova: 1, gerenciar: 1 })}">Cadastrar a primeira mesa</a>` : ''}</p></section>` : ''}

        ${Object.entries(porArea).map(([area, lst]) => h`
          <h2 class="area-titulo">${area} <span class="painel-meta">${lst.length} ${lst.length === 1 ? 'mesa' : 'mesas'}</span></h2>
          <div class="mesas-grid">
            ${lst.map((m) => {
              const [classe, rotulo] = ROTULOS[m.situacao] || ['', m.situacao];
              const link = gerenciar ? url(ctx, { editar: m.id })
                : m.situacao === 'LIVRE' ? 'pdv?mesa=' + encodeURIComponent(m.numero)
                : m.situacao === 'INATIVA' ? '#' : url(ctx, { mesa: m.numero });
              return h`<a class="mesa ${classe}" href="${link}" aria-label="Mesa ${m.numero}, ${rotulo}${N(m.pedidos) ? ', ' + moeda(m.total) : ''}">
                <div class="mesa-topo">
                  <span class="mesa-numero"><small>Mesa</small>${m.numero}</span>
                  <span class="mesa-lugares"><i class="fa-solid fa-user" aria-hidden="true"></i> ${N(m.lugares)}</span>
                </div>
                <span class="status ${classe}">${rotulo}</span>
                ${gerenciar ? h`<span class="mesa-info"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar mesa</span>`
                  : N(m.pedidos) > 0 ? h`<span class="mesa-info">
                    <strong>${moeda(m.total)}</strong>
                    ${N(m.pedidos)} ${N(m.pedidos) === 1 ? 'pedido' : 'pedidos'} · há ${tempo(N(m.minutos))}
                    ${N(m.na_cozinha) ? h`<br><i class="fa-solid fa-fire-burner" aria-hidden="true"></i> ${N(m.na_cozinha)} na cozinha` : ''}
                  </span>`
                  : m.situacao === 'LIVRE' ? h`<span class="mesa-info muted"><i class="fa-solid fa-plus" aria-hidden="true"></i> Abrir comanda</span>` : ''}
              </a>`;
            })}
          </div>`)}

        ${mesaAberta ? h`
          <section class="painel-card form-card" aria-labelledby="t-mesa">
            <div class="painel-header">
              <h2 id="t-mesa">Mesa ${mesaAberta.numero} <span class="status ${ROTULOS[mesaAberta.situacao][0]}">${ROTULOS[mesaAberta.situacao][1]}</span></h2>
              <a class="gaveta-fechar" href="${url(ctx, { mesa: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <div class="gaveta-corpo">
              ${!pedidosMesa.length ? h`<p class="vazio">Esta mesa não tem pedidos em aberto.</p>` : h`
                <div class="comanda">
                  <div class="comanda-topo">
                    <span class="comanda-numero">Mesa ${mesaAberta.numero}</span>
                    <span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> aberta há ${tempo(N(mesaAberta.minutos))}</span>
                  </div>
                  <ul class="comanda-itens">
                    ${comanda.map((it) => h`<li><span class="qtd">${N(it.quantidade)}x</span> ${it.nome} <span class="item-valor">${moeda(it.total)}</span></li>`)}
                  </ul>
                  <div class="comanda-rodape">
                    <div class="comanda-total"><small>Total da mesa</small><strong style="font-size:20px">${moeda(totalMesa)}</strong></div>
                    <a class="btn btn-pequeno" href="pdv?mesa=${encodeURIComponent(mesaAberta.numero)}"><i class="fa-solid fa-plus" aria-hidden="true"></i> Adicionar itens</a>
                  </div>
                </div>

                <h3 class="subtitulo-form">Pedidos da mesa</h3>
                <ul class="lista-linhas" style="margin:0 -20px">
                  ${pedidosMesa.map((pd) => {
                    const [cl, rt] = ETAPAS[pd.etapa] || ['', pd.etapa];
                    return h`<li>
                      <span class="celula-dupla" style="flex:1"><strong>#${N(pd.id)} · ${pd.hora}</strong><small>${pd.atendente}</small></span>
                      <span class="status ${cl}">${rt}</span>
                      <strong>${moeda(pd.total)}</strong>
                      <a class="btn btn-pequeno btn-fantasma btn-icone" href="pedidos?editar=${N(pd.id)}#formulario" aria-label="Editar pedido ${N(pd.id)}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                    </li>`;
                  })}
                </ul>

                <h3 class="subtitulo-form">Fechar conta</h3>
                ${N(mesaAberta.na_cozinha) > 0 ? h`<p class="painel-nota" style="margin:0 0 12px">Ainda há ${N(mesaAberta.na_cozinha)} pedido(s) na cozinha. A conta pode ser fechada quando todos estiverem servidos.</p>` : ''}
                <form method="post" action="${url(ctx)}" id="form-conta">
                  ${csrfCampo(ctx)}
                  <input type="hidden" name="acao" value="fechar_conta">
                  <input type="hidden" name="numero" value="${mesaAberta.numero}">
                  <div class="pdv-formas" role="radiogroup" aria-label="Forma de pagamento">
                    ${pagamentos.map((f, i) => h`<label><input type="radio" name="forma_pagamento" value="${f}"${i === 0 ? cru(' checked') : ''}><span><i class="${(formas[f] || [])[1] || ''}" aria-hidden="true"></i> ${(formas[f] || [f])[0]}</span></label>`)}
                  </div>
                  <div class="form-grid" style="margin-top:12px">
                    <div class="grupo-input">
                      <label for="m-recebido">Valor recebido (dinheiro)</label>
                      <div class="campo-dinheiro"><input type="number" id="m-recebido" name="valor_recebido" class="input-dash" min="0" step="0.01" placeholder="${numeroBr(totalMesa, 2).replace(/\./g, '')}"></div>
                    </div>
                    <div class="grupo-input">
                      <span class="rotulo">Dividir a conta</span>
                      <select class="input-dash" id="m-dividir" aria-label="Dividir a conta por pessoas">
                        ${Array.from({ length: Math.max(2, N(mesaAberta.lugares)) }, (_, i) => i + 1).map((n) => h`<option value="${n}">${n === 1 ? 'Sem divisão' : `${n} pessoas · ${moeda(totalMesa / n)} cada`}</option>`)}
                      </select>
                    </div>
                  </div>
                  <div class="form-acoes">
                    <button type="submit" class="btn btn-primario btn-grande"${N(mesaAberta.na_cozinha) > 0 ? cru(' disabled') : ''}><i class="fa-solid fa-check" aria-hidden="true"></i> Fechar conta de ${moeda(totalMesa)}</button>
                  </div>
                </form>

                ${livresDestino.length ? h`
                  <h3 class="subtitulo-form">Trocar de mesa</h3>
                  <form method="post" action="${url(ctx)}" class="toolbar-grupo">
                    ${csrfCampo(ctx)}
                    <input type="hidden" name="acao" value="transferir">
                    <input type="hidden" name="numero" value="${mesaAberta.numero}">
                    <label class="sr-only" for="m-destino">Mesa de destino</label>
                    <select id="m-destino" name="destino" class="input-dash" style="flex:1" required>
                      ${livresDestino.map((l) => h`<option value="${l.numero}">Mesa ${l.numero} · ${l.area} (${N(l.lugares)} lugares)</option>`)}
                    </select>
                    <button type="submit" class="btn"><i class="fa-solid fa-right-left" aria-hidden="true"></i> Transferir</button>
                  </form>` : ''}`}
            </div>
          </section>` : ''}

        ${formMesa && (editarId === 0 || mesaEdit) ? h`
          <section class="painel-card form-card estreita" aria-labelledby="t-form-mesa">
            <div class="painel-header">
              <h2 id="t-form-mesa">${me.id ? 'Editar mesa ' + me.numero : 'Nova mesa'}</h2>
              <a class="gaveta-fechar" href="${url(ctx, { editar: null, nova: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="salvar_mesa">
              <input type="hidden" name="id" value="${N(me.id)}">
              <div class="form-grid">
                <div class="grupo-input">
                  <label for="me-numero">Número ou nome</label>
                  <input type="text" id="me-numero" name="numero" class="input-dash" maxlength="20" required value="${me.numero}" placeholder="Ex.: 13">
                </div>
                <div class="grupo-input">
                  <label for="me-lugares">Lugares</label>
                  <input type="number" id="me-lugares" name="lugares" class="input-dash" min="1" max="30" required value="${N(me.lugares)}">
                </div>
                <div class="grupo-input">
                  <label for="me-area">Área</label>
                  <input type="text" id="me-area" name="area" class="input-dash" maxlength="50" list="areas" value="${me.area}">
                  <datalist id="areas">${areas.map((a) => h`<option value="${a}">`)}</datalist>
                </div>
                <div class="grupo-input">
                  <label for="me-status">Situação</label>
                  <select id="me-status" name="status" class="input-dash">
                    <option value="ATIVO"${me.status === 'ATIVO' ? cru(' selected') : ''}>Em uso</option>
                    <option value="INATIVO"${me.status === 'INATIVO' ? cru(' selected') : ''}>Desativada</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario">${me.id ? 'Salvar mesa' : 'Cadastrar mesa'}</button>
                <a class="btn btn-secundario" href="${url(ctx, { editar: null, nova: null })}" data-fechar>Cancelar</a>
                ${me.id ? h`<a class="btn btn-perigo empurra" href="${url(ctx, { excluir: me.id, editar: null })}"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</a>` : ''}
              </div>
            </form>
          </section>` : ''}

        ${alvoExcluir ? h`
          <section class="confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir mesa ${alvoExcluir.numero}?</h2>
            <p>A mesa sai do mapa do salão. Os pedidos antigos dela continuam no histórico.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="excluir_mesa">
              <input type="hidden" name="id" value="${N(alvoExcluir.id)}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir mesa</button>
              <a class="btn btn-secundario" href="${url(ctx, { excluir: null })}" data-fechar>Cancelar</a>
            </form>
          </section>` : ''}`;

  const rodapeExtra = cru(`<script>
  // Dinheiro: mostra o troco ao digitar o valor recebido
  (() => {
    const form = document.getElementById('form-conta');
    if (!form) return;
    const recebido = form.querySelector('[name=valor_recebido]');
    const atualizar = () => { recebido.closest('.grupo-input').hidden = form.querySelector('[name=forma_pagamento]:checked')?.value !== 'DINHEIRO'; };
    form.querySelectorAll('[name=forma_pagamento]').forEach((r) => r.addEventListener('change', atualizar));
    atualizar();
  })();
</script>`);

  return pagina(ctx, { titulo: 'Mesas', subtitulo: 'Mapa do salão em tempo real', paginaAtual: 'mesas', rodapeExtra }, conteudo);
};
