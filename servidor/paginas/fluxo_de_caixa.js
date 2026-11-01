'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const {
  h, ler, moeda, data, hoje, somarDias, somarMeses, paraMs, entrada, entradaInt, entradaDecimal, csrfCampo, tratarPost,
  url, paginacao, sel, chk, Cru,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const POR_PAGINA = 10;
const SITUACOES = {
  PENDENTE: ['pendente', 'Pendente'], PAGO: ['pago', 'Pago'], ATRASADO: ['atrasado', 'Atrasado'],
  CANCELADO: ['cancelado', 'Cancelado'], AGENDADO: ['agendado', 'Agendado'], NEGOCIANDO: ['pendente', 'Negociando'],
};
const ORIGENS = { MANUAL: 'Manual', VENDA: 'Venda', COMPRA: 'Compra' };

module.exports = async function fluxo(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.financeiro);

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    const id = entradaInt(ctx, 'id');
    if (acao === 'salvar') {
      await bd.chamar('sp_fluxo_salvar', [
        id, entrada(ctx, 'tipo'), entrada(ctx, 'descricao'), entrada(ctx, 'categoria'), entradaDecimal(ctx, 'valor'),
        entrada(ctx, 'data_movimentacao') || null, entrada(ctx, 'status', 'PENDENTE'), u.id,
      ]);
      return [id ? 'Lançamento atualizado.' : 'Lançamento registrado.', url(ctx, { editar: null, cancelar: null, excluir: null })];
    }
    if (acao === 'status') {
      const novo = entrada(ctx, 'status');
      await bd.chamar('sp_fluxo_status', [id, entrada(ctx, 'tipo'), entrada(ctx, 'origem'), novo]);
      return [novo === 'PAGO' ? 'Lançamento marcado como pago.' : novo === 'CANCELADO' ? 'Lançamento cancelado.' : 'Pagamento desfeito.',
        url(ctx, { cancelar: null, cancelar_tipo: null, cancelar_origem: null })];
    }
    if (acao === 'excluir') {
      await bd.chamar('sp_fluxo_excluir', [id]);
      return ['Lançamento excluído.', url(ctx, { excluir: null, editar: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const periodo = ['mes', 'passado', '30dias', 'ano'].includes(entrada(ctx, 'periodo')) ? entrada(ctx, 'periodo') : 'mes';
  const mesIni = data('Y-m-01');
  const fimDoMes = (ini) => data('Y-m-t', paraMs(ini));
  let de, ate;
  if (periodo === 'passado') { de = somarMeses(mesIni, -1); ate = fimDoMes(de); }
  else if (periodo === '30dias') { de = somarDias(hoje(), -30); ate = hoje(); }
  else if (periodo === 'ano') { de = data('Y-01-01'); ate = data('Y-12-31'); }
  else { de = mesIni; ate = fimDoMes(mesIni); }
  const tipo = ['ENTRADA', 'SAIDA'].includes(entrada(ctx, 'tipo')) ? entrada(ctx, 'tipo') : 'TODOS';
  const situacao = ['PENDENTE', 'PAGO', 'ATRASADO', 'CANCELADO'].includes(entrada(ctx, 'situacao')) ? entrada(ctx, 'situacao') : 'TODAS';
  let pg = Math.max(1, entradaInt(ctx, 'pagina', 1));

  const [resumo, contagem] = await Promise.all([
    ler(ctx, () => bd.chamarUm('sp_fluxo_resumo', [de, ate]), { entradas_pagas: 0, saidas_pagas: 0, a_receber: 0, a_pagar: 0, atrasado_valor: 0, atrasado_qtd: 0 }),
    ler(ctx, () => bd.chamarUm('sp_fluxo_contagem', [de, ate, situacao]), { todos: 0, entradas: 0, saidas: 0 }),
  ]);
  const totalFiltro = N(tipo === 'ENTRADA' ? contagem.entradas : tipo === 'SAIDA' ? contagem.saidas : contagem.todos);
  const paginasTotal = Math.max(1, Math.ceil(totalFiltro / POR_PAGINA));
  pg = Math.min(pg, paginasTotal);
  const [lancamentos, semanasBd] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_fluxo_listar', [de, ate, tipo, situacao, POR_PAGINA, (pg - 1) * POR_PAGINA])),
    ler(ctx, () => bd.chamar('sp_fluxo_semanas')),
  ]);

  const semanas = semanasBd.slice().sort((a, b) => N(b.semana) - N(a.semana));
  let maior = 1;
  for (const s of semanas) maior = Math.max(maior, N(s.entrada), N(s.saida));
  const saldo = N(resumo.entradas_pagas) - N(resumo.saidas_pagas);

  const editarId = entradaInt(ctx, 'editar');
  const edit = editarId > 0 ? await ler(ctx, () => bd.chamarUm('sp_fluxo_obter', [editarId]), null) : null;
  const f = edit || { id: 0, tipo: 'ENTRADA', descricao: '', categoria: '', valor: '', data_movimentacao: hoje(), status: 'PENDENTE' };
  const dataF = String(f.data_movimentacao).slice(0, 10);

  const cancelarId = entradaInt(ctx, 'cancelar');
  const excluirId = entradaInt(ctx, 'excluir');
  const anoAtual = data('Y');

  const descSemanas = semanas.map((s) => `Semana de ${s.rotulo}: entradas ${moeda(s.entrada)}, saídas ${moeda(s.saida)}. `).join('');

  const conteudo = h`
        <section class="grid-cards" aria-label="Resumo do período">
          <div class="card-metrica">
            <span class="card-titulo">Saldo do período</span>
            <div class="card-valor">${moeda(saldo)}</div>
            <span class="card-detalhe ${saldo >= 0 ? 'positivo' : 'alerta'}">${saldo >= 0 ? 'Entradas pagas menos saídas pagas' : 'Saídas acima das entradas'}</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Entradas pagas</span>
            <div class="card-valor">${moeda(resumo.entradas_pagas)}</div>
            <span class="card-detalhe neutro">${moeda(resumo.a_receber)} a receber</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Saídas pagas</span>
            <div class="card-valor">${moeda(resumo.saidas_pagas)}</div>
            <span class="card-detalhe neutro">${moeda(resumo.a_pagar)} a pagar</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Em atraso</span>
            <div class="card-valor">${moeda(resumo.atrasado_valor)}</div>
            <span class="card-detalhe alerta">${N(resumo.atrasado_qtd)} ${N(resumo.atrasado_qtd) === 1 ? 'lançamento' : 'lançamentos'}</span>
          </div>
        </section>

        <form class="toolbar" method="get" action="fluxo_de_caixa">
          <div class="toolbar-grupo">
            <div class="grupo-input seletor">
              <label class="sr-only" for="periodo">Período</label>
              <select id="periodo" class="input-dash" name="periodo">
                ${Object.entries({ mes: 'Este mês', passado: 'Mês passado', '30dias': 'Últimos 30 dias', ano: 'Este ano' }).map(([v, r]) => h`<option value="${v}"${sel(periodo === v)}>${r}</option>`)}
              </select>
            </div>
            <div class="grupo-input seletor">
              <label class="sr-only" for="situacao">Situação</label>
              <select id="situacao" class="input-dash" name="situacao">
                ${Object.entries({ TODAS: 'Todas as situações', PENDENTE: 'Pendente', PAGO: 'Pago', ATRASADO: 'Atrasado', CANCELADO: 'Cancelado' }).map(([v, r]) => h`<option value="${v}"${sel(situacao === v)}>${r}</option>`)}
              </select>
            </div>
            ${tipo !== 'TODOS' ? h`<input type="hidden" name="tipo" value="${tipo}">` : ''}
            <button type="submit" class="btn btn-secundario">Filtrar</button>
          </div>
          <div class="toolbar-grupo">
            <div class="filtros" role="group" aria-label="Tipo de lançamento">
              ${[['TODOS', 'Todos', contagem.todos], ['ENTRADA', 'Entradas', contagem.entradas], ['SAIDA', 'Saídas', contagem.saidas]].map(([v, r, q]) => h`<a class="filtro${tipo === v ? ' ativo' : ''}" href="${url(ctx, { tipo: v === 'TODOS' ? null : v, pagina: null })}">${r} <span>${N(q)}</span></a>`)}
            </div>
          </div>
        </form>

        ${cancelarId ? h`
          <section class="painel-card confirmar-card" aria-labelledby="t-cancelar">
            <h2 id="t-cancelar">Cancelar lançamento</h2>
            <p>Cancelar este lançamento? Um lançamento cancelado não pode ser reaberto.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="status">
              <input type="hidden" name="status" value="CANCELADO">
              <input type="hidden" name="id" value="${cancelarId}">
              <input type="hidden" name="tipo" value="${entrada(ctx, 'cancelar_tipo')}">
              <input type="hidden" name="origem" value="${entrada(ctx, 'cancelar_origem')}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-ban" aria-hidden="true"></i> Cancelar lançamento</button>
              <a class="btn btn-secundario" href="${url(ctx, { cancelar: null, cancelar_tipo: null, cancelar_origem: null })}">Voltar</a>
            </form>
          </section>` : ''}

        ${excluirId ? h`
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir lançamento</h2>
            <p>Excluir definitivamente este lançamento manual? Essa ação não pode ser desfeita.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="${excluirId}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="${url(ctx, { excluir: null })}">Voltar</a>
            </form>
          </section>` : ''}

        <div class="fluxo-grid">

          <section class="painel-card painel-tabela" aria-label="Lançamentos">
            ${!lancamentos.length ? h`<p class="vazio">Nenhum lançamento neste filtro. Registre um lançamento manual ou mude o período.</p>` : h`
              <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de lançamentos, role para os lados se necessário">
                <table class="tabela">
                  <thead>
                    <tr>
                      <th scope="col">Vencimento</th>
                      <th scope="col">Lançamento</th>
                      <th scope="col" class="num">Valor</th>
                      <th scope="col">Situação</th>
                      <th scope="col"><span class="sr-only">Ações</span></th>
                    </tr>
                  </thead>
                  <tbody>
                    ${lancamentos.map((l) => {
                      const [classe, rotulo] = SITUACOES[l.situacao] || ['pendente', l.situacao];
                      const manual = l.origem === 'MANUAL';
                      const cancelado = l.status === 'CANCELADO';
                      const detalhe = manual ? l.categoria : l.origem === 'VENDA' ? 'Pedido #' + l.referencia : 'Compra #' + l.referencia;
                      const venc = paraMs(l.vencimento);
                      const dataTxt = data('Y', venc) === anoAtual ? data('d/m', venc) : data('d/m/y', venc);
                      return h`<tr>
                        <td class="data">${dataTxt}</td>
                        <td><span class="celula-dupla"><strong>${l.contraparte}</strong><small><span class="origem">${ORIGENS[l.origem] || l.origem}</span> ${detalhe}</small></span></td>
                        <td class="num ${l.tipo === 'ENTRADA' ? 'entrada' : 'saida'}">${l.tipo === 'ENTRADA' ? '+' : '−'} ${moeda(l.total)}</td>
                        <td><span class="status ${classe}">${rotulo}</span></td>
                        <td class="acoes">
                          ${manual && !cancelado ? h`<a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { editar: l.id })}#novo-lancamento" aria-label="Editar ${l.contraparte}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>` : ''}
                          ${!cancelado ? h`
                            <form class="em-linha" method="post" action="${url(ctx)}">
                              ${csrfCampo(ctx)}
                              <input type="hidden" name="acao" value="status">
                              <input type="hidden" name="id" value="${N(l.id)}">
                              <input type="hidden" name="tipo" value="${l.tipo}">
                              <input type="hidden" name="origem" value="${l.origem}">
                              ${l.status === 'PAGO' ? h`
                                <input type="hidden" name="status" value="PENDENTE">
                                <button type="submit" class="btn btn-pequeno btn-fantasma">Desfazer<span class="sr-only"> pagamento: ${l.contraparte}</span></button>` : h`
                                <input type="hidden" name="status" value="PAGO">
                                <button type="submit" class="btn btn-pequeno">Marcar como pago<span class="sr-only">: ${l.contraparte}</span></button>`}
                            </form>
                            <a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { cancelar: l.id, cancelar_tipo: l.tipo, cancelar_origem: l.origem, excluir: null })}" title="Cancelar lançamento" aria-label="Cancelar ${l.contraparte}"><i class="fa-solid fa-ban" aria-hidden="true"></i></a>` : ''}
                          ${manual ? h`<a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { excluir: l.id, cancelar: null })}" title="Excluir lançamento" aria-label="Excluir ${l.contraparte}"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>` : ''}
                        </td>
                      </tr>`;
                    })}
                  </tbody>
                </table>
              </div>
              ${paginacao(ctx, pg, paginasTotal, totalFiltro, POR_PAGINA)}`}
          </section>

          <div class="fluxo-lateral">

            <section class="painel-card" id="novo-lancamento" aria-labelledby="titulo-novo-lancamento">
              <div class="painel-header">
                <h2 id="titulo-novo-lancamento">${f.id ? 'Editar lançamento' : 'Novo lançamento'}</h2>
              </div>
              <form class="form-lancamento" method="post" action="${url(ctx)}">
                ${csrfCampo(ctx)}
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="${N(f.id)}">
                <fieldset class="segmentado">
                  <legend class="sr-only">Tipo</legend>
                  <label>
                    <input type="radio" name="tipo" value="ENTRADA"${chk(f.tipo === 'ENTRADA')}>
                    <span><i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Entrada</span>
                  </label>
                  <label>
                    <input type="radio" name="tipo" value="SAIDA"${chk(f.tipo === 'SAIDA')}>
                    <span><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Saída</span>
                  </label>
                </fieldset>

                <div class="grupo-input">
                  <label for="descricao">Descrição</label>
                  <input type="text" id="descricao" name="descricao" class="input-dash" maxlength="255" placeholder="Ex.: Conta de energia" required value="${f.descricao}">
                </div>

                <div class="grupo-input">
                  <label for="categoria">Categoria</label>
                  <input type="text" id="categoria" name="categoria" class="input-dash" maxlength="100" list="categorias-caixa" placeholder="Ex.: Despesas fixas" required value="${f.categoria}">
                  <datalist id="categorias-caixa">
                    <option value="Despesas fixas">
                    <option value="Receitas extras">
                    <option value="Impostos">
                    <option value="Manutenção">
                  </datalist>
                </div>

                <div class="linha-dupla">
                  <div class="grupo-input">
                    <label for="valor">Valor (R$)</label>
                    <input type="number" id="valor" name="valor" class="input-dash" min="0.01" step="0.01" inputmode="decimal" placeholder="0,00" required value="${f.valor}">
                  </div>
                  <div class="grupo-input">
                    <label for="data_movimentacao">Data</label>
                    <input type="date" id="data_movimentacao" name="data_movimentacao" class="input-dash" required value="${dataF}">
                  </div>
                </div>

                <div class="grupo-input">
                  <label for="status">Situação</label>
                  <select id="status" name="status" class="input-dash">
                    <option value="PENDENTE"${sel(f.status === 'PENDENTE')}>Pendente</option>
                    <option value="PAGO"${sel(f.status === 'PAGO')}>Pago</option>
                  </select>
                </div>

                <button type="submit" class="btn btn-primario"><i class="fa-solid ${f.id ? 'fa-floppy-disk' : 'fa-plus'}" aria-hidden="true"></i> ${f.id ? 'Salvar alterações' : 'Registrar lançamento'}</button>
                ${f.id ? h`<a class="btn btn-secundario" href="${url(ctx, { editar: null })}">Cancelar edição</a>` : ''}
              </form>
            </section>

            <section class="painel-card" aria-labelledby="titulo-semanas">
              <div class="painel-header">
                <h2 id="titulo-semanas">Entradas e saídas</h2>
                <span class="painel-meta">Últimas 4 semanas</span>
              </div>
              <div class="barras" role="img" aria-label="Entradas e saídas das últimas quatro semanas. ${descSemanas}">
                ${semanas.map((s) => h`<div class="barras-semana">
                    <div class="barras-par">
                      <span class="barra entrada" style="--h: ${Math.round((N(s.entrada) / maior) * 100)}%" title="Entradas ${moeda(s.entrada)}"></span>
                      <span class="barra saida" style="--h: ${Math.round((N(s.saida) / maior) * 100)}%" title="Saídas ${moeda(s.saida)}"></span>
                    </div>
                    <span class="barras-rotulo">${s.rotulo}</span>
                  </div>`)}
              </div>
              <ul class="legenda">
                <li><span class="ponto entrada" aria-hidden="true"></span> Entradas</li>
                <li><span class="ponto saida" aria-hidden="true"></span> Saídas</li>
              </ul>
            </section>

          </div>
        </div>`;

  return pagina(ctx, { titulo: 'Fluxo de Caixa', subtitulo: 'Entradas, saídas e contas em aberto', paginaAtual: 'fluxo_de_caixa' }, conteudo);
};
