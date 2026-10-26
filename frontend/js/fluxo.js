/* Fluxo de Caixa · entradas (vendas), saídas (compras) e lançamentos manuais */
(() => {
  const { api, modal, confirmar, toast, esc, brl, hojeIso, filtros, paginacao, carregando, vazio } = XT;

  const POR_PAGINA = 10;
  const estado = { periodo: 'mes', tipo: 'TODOS', situacao: 'TODAS', pagina: 1 };
  let lancamentos = [];

  const container = document.querySelector('.painel-tabela .tabela-container');
  const chips = document.getElementById('filtros');
  const nav = document.getElementById('paginacao');
  const formNovo = document.getElementById('form-lancamento');

  const chamar = (acao, dados) => api('management', acao, dados === undefined ? { chave: 'action' } : { dados, chave: 'action' });

  const somarDias = (iso, dias) => {
    const d = new Date(`${iso}T12:00:00`);
    d.setDate(d.getDate() + dias);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  /** Situação "efetiva": pendente com vencimento passado vira atrasado. */
  function situacaoDe(l) {
    if (l.status === 'PENDENTE' && l.vencimento < hojeIso()) return 'ATRASADO';
    return l.status;
  }

  const SITUACOES = {
    PENDENTE: ['pendente', 'Pendente'],
    PAGO: ['pago', 'Pago'],
    ATRASADO: ['atrasado', 'Atrasado'],
    CANCELADO: ['cancelado', 'Cancelado'],
    AGENDADO: ['agendado', 'Agendado'],
    NEGOCIANDO: ['pendente', 'Negociando'],
  };

  const ORIGENS = { MANUAL: 'Manual', VENDA: 'Venda', COMPRA: 'Compra' };

  function intervalo() {
    const hoje = hojeIso();
    const [ano, mes] = hoje.split('-').map(Number);
    const pad = (n) => String(n).padStart(2, '0');

    switch (estado.periodo) {
      case 'mes':
        return [`${ano}-${pad(mes)}-01`, `${ano}-${pad(mes)}-31`];
      case 'passado': {
        const anterior = mes === 1 ? [ano - 1, 12] : [ano, mes - 1];
        return [`${anterior[0]}-${pad(anterior[1])}-01`, `${anterior[0]}-${pad(anterior[1])}-31`];
      }
      case '30dias':
        return [somarDias(hoje, -30), hoje];
      default:
        return [`${ano}-01-01`, `${ano}-12-31`];
    }
  }

  async function carregar() {
    if (!lancamentos.length) carregando(container, 'Carregando lançamentos...');
    try {
      lancamentos = (await chamar('cashflow')).data.map((l) => ({ ...l, total: Number(l.total) }));
      desenhar();
    } catch (e) {
      container.innerHTML = vazio('fa-triangle-exclamation', 'Não foi possível carregar o fluxo de caixa', e.message);
    }
  }

  function desenhar() {
    const [de, ate] = intervalo();
    const doPeriodo = lancamentos.filter((l) => l.vencimento >= de && l.vencimento <= ate);

    desenharResumo(doPeriodo);
    desenharSemanas();

    const base = doPeriodo.filter((l) => estado.situacao === 'TODAS' || situacaoDe(l) === estado.situacao);
    const entradas = base.filter((l) => l.tipo === 'ENTRADA').length;
    const saidas = base.filter((l) => l.tipo === 'SAIDA').length;

    filtros(
      chips,
      [
        { valor: 'TODOS', rotulo: 'Todos', qtd: base.length },
        { valor: 'ENTRADA', rotulo: 'Entradas', qtd: entradas },
        { valor: 'SAIDA', rotulo: 'Saídas', qtd: saidas },
      ],
      estado.tipo,
      (valor) => {
        estado.tipo = valor;
        estado.pagina = 1;
        desenhar();
      },
    );

    const lista = base.filter((l) => estado.tipo === 'TODOS' || l.tipo === estado.tipo);
    const paginas = Math.max(1, Math.ceil(lista.length / POR_PAGINA));
    estado.pagina = Math.min(estado.pagina, paginas);
    const pagina = lista.slice((estado.pagina - 1) * POR_PAGINA, estado.pagina * POR_PAGINA);

    if (!lista.length) {
      container.innerHTML = vazio('fa-wallet', 'Nenhum lançamento neste filtro', 'Registre um lançamento manual ou mude o período.');
      paginacao(nav, { pagina: 1, paginas: 1, total: 0, por_pagina: POR_PAGINA }, 'lançamentos', () => {});
      return;
    }

    const anoAtual = hojeIso().slice(0, 4);
    container.innerHTML = `
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
          ${pagina
            .map((l, n) => {
              const efetiva = situacaoDe(l);
              const [classe, rotulo] = SITUACOES[efetiva] || ['pendente', efetiva];
              const manual = l.origem === 'MANUAL';
              const cancelado = l.status === 'CANCELADO';
              const detalhe = manual ? l.categoria : l.origem === 'VENDA' ? `Pedido #${l.referencia}` : `Compra #${l.referencia}`;
              const data = `${l.vencimento.slice(8, 10)}/${l.vencimento.slice(5, 7)}${l.vencimento.slice(0, 4) === anoAtual ? '' : '/' + l.vencimento.slice(2, 4)}`;

              return `
            <tr>
              <td class="data">${data}</td>
              <td><span class="celula-dupla"><strong>${esc(l.contraparte)}</strong><small><span class="origem">${ORIGENS[l.origem]}</span> ${esc(detalhe)}</small></span></td>
              <td class="num ${l.tipo === 'ENTRADA' ? 'entrada' : 'saida'}">${l.tipo === 'ENTRADA' ? '+' : '−'} ${brl(l.total)}</td>
              <td><span class="status ${classe}">${esc(rotulo)}</span></td>
              <td class="acoes">
                ${manual && !cancelado ? `<button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-editar="${n}" aria-label="Editar ${esc(l.contraparte)}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>` : ''}
                ${
                  cancelado
                    ? ''
                    : l.status === 'PAGO'
                      ? `<button type="button" class="btn btn-pequeno btn-fantasma" data-status="${n}" data-novo="PENDENTE">Desfazer<span class="sr-only"> pagamento: ${esc(l.contraparte)}</span></button>`
                      : `<button type="button" class="btn btn-pequeno" data-status="${n}" data-novo="PAGO">Marcar como pago<span class="sr-only">: ${esc(l.contraparte)}</span></button>`
                }
                ${cancelado ? '' : `<button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-cancelar="${n}" title="Cancelar lançamento" aria-label="Cancelar ${esc(l.contraparte)}"><i class="fa-solid fa-ban" aria-hidden="true"></i></button>`}
                ${manual ? `<button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-excluir="${n}" title="Excluir lançamento" aria-label="Excluir ${esc(l.contraparte)}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>` : ''}
              </td>
            </tr>`;
            })
            .join('')}
        </tbody>
      </table>`;

    container.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () => editar(pagina[Number(b.dataset.editar)])));
    container.querySelectorAll('[data-status]').forEach((b) => b.addEventListener('click', () => mudarStatus(pagina[Number(b.dataset.status)], b.dataset.novo)));
    container.querySelectorAll('[data-cancelar]').forEach((b) => b.addEventListener('click', () => cancelar(pagina[Number(b.dataset.cancelar)])));
    container.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', () => excluir(pagina[Number(b.dataset.excluir)])));

    paginacao(nav, { pagina: estado.pagina, paginas, total: lista.length, por_pagina: POR_PAGINA }, 'lançamentos', (p) => {
      estado.pagina = p;
      desenhar();
    });
  }

  function desenharResumo(lista) {
    const soma = (filtro) => lista.filter(filtro).reduce((total, l) => total + l.total, 0);
    const entradasPagas = soma((l) => l.tipo === 'ENTRADA' && l.status === 'PAGO');
    const saidasPagas = soma((l) => l.tipo === 'SAIDA' && l.status === 'PAGO');
    const aReceber = soma((l) => l.tipo === 'ENTRADA' && ['PENDENTE', 'ATRASADO', 'NEGOCIANDO', 'AGENDADO'].includes(l.status));
    const aPagar = soma((l) => l.tipo === 'SAIDA' && ['PENDENTE', 'ATRASADO', 'NEGOCIANDO', 'AGENDADO'].includes(l.status));
    const atrasados = lista.filter((l) => situacaoDe(l) === 'ATRASADO');
    const saldo = entradasPagas - saidasPagas;

    document.getElementById('res-saldo').textContent = brl(saldo);
    document.getElementById('res-saldo-detalhe').textContent = saldo >= 0 ? 'Entradas pagas menos saídas pagas' : 'Saídas acima das entradas';
    document.getElementById('res-saldo-detalhe').className = `card-detalhe ${saldo >= 0 ? 'positivo' : 'alerta'}`;
    document.getElementById('res-entradas').textContent = brl(entradasPagas);
    document.getElementById('res-entradas-detalhe').textContent = `${brl(aReceber)} a receber`;
    document.getElementById('res-saidas').textContent = brl(saidasPagas);
    document.getElementById('res-saidas-detalhe').textContent = `${brl(aPagar)} a pagar`;
    document.getElementById('res-atraso').textContent = brl(atrasados.reduce((total, l) => total + l.total, 0));
    document.getElementById('res-atraso-detalhe').textContent = `${atrasados.length} ${atrasados.length === 1 ? 'lançamento' : 'lançamentos'}`;
  }

  /** Barras das últimas 4 semanas (7 dias cada), por data de vencimento. */
  function desenharSemanas() {
    const hoje = hojeIso();
    const semanas = [3, 2, 1, 0].map((n) => {
      const fim = somarDias(hoje, -7 * n);
      const inicio = somarDias(fim, -6);
      const doIntervalo = lancamentos.filter((l) => l.status !== 'CANCELADO' && l.vencimento >= inicio && l.vencimento <= fim);
      return {
        rotulo: `${inicio.slice(8, 10)}/${inicio.slice(5, 7)}`,
        entrada: doIntervalo.filter((l) => l.tipo === 'ENTRADA').reduce((t, l) => t + l.total, 0),
        saida: doIntervalo.filter((l) => l.tipo === 'SAIDA').reduce((t, l) => t + l.total, 0),
      };
    });

    const maior = Math.max(1, ...semanas.flatMap((s) => [s.entrada, s.saida]));
    const barras = document.getElementById('barras');
    barras.setAttribute(
      'aria-label',
      'Entradas e saídas das últimas quatro semanas. ' + semanas.map((s) => `Semana de ${s.rotulo}: entradas ${brl(s.entrada)}, saídas ${brl(s.saida)}.`).join(' '),
    );
    barras.innerHTML = semanas
      .map(
        (s) => `
      <div class="barras-semana">
        <div class="barras-par">
          <span class="barra entrada" style="--h: ${Math.round((s.entrada / maior) * 100)}%" title="Entradas ${brl(s.entrada)}"></span>
          <span class="barra saida" style="--h: ${Math.round((s.saida / maior) * 100)}%" title="Saídas ${brl(s.saida)}"></span>
        </div>
        <span class="barras-rotulo">${s.rotulo}</span>
      </div>`,
      )
      .join('');
  }

  async function mudarStatus(l, novo) {
    try {
      toast((await chamar('pay_account', { id: l.id, tipo: l.tipo, origem: l.origem, status: novo })).message);
      carregar();
    } catch (e) {
      toast(e.message, 'erro');
    }
  }

  async function cancelar(l) {
    const ok = await confirmar({ titulo: 'Cancelar lançamento', mensagem: `Cancelar "${l.contraparte}"? Um lançamento cancelado não pode ser reaberto.`, rotulo: 'Cancelar lançamento', perigo: true });
    if (ok) mudarStatus(l, 'CANCELADO');
  }

  async function excluir(l) {
    const ok = await confirmar({ titulo: 'Excluir lançamento', mensagem: `Excluir definitivamente "${l.contraparte}"?`, rotulo: 'Excluir', perigo: true });
    if (!ok) return false;

    try {
      toast((await chamar('delete_manual_flow', { id: l.id })).message);
      carregar();
      return true;
    } catch (e) {
      toast(e.message, 'erro');
      return false;
    }
  }

  function editar(l) {
    modal({
      titulo: 'Editar lançamento',
      rotuloSalvar: 'Salvar alterações',
      corpo: `
        <fieldset class="segmentado">
          <legend class="sr-only">Tipo</legend>
          <label><input type="radio" name="tipo" value="ENTRADA"${l.tipo === 'ENTRADA' ? ' checked' : ''}><span><i class="fa-solid fa-arrow-down" aria-hidden="true"></i> Entrada</span></label>
          <label><input type="radio" name="tipo" value="SAIDA"${l.tipo === 'SAIDA' ? ' checked' : ''}><span><i class="fa-solid fa-arrow-up" aria-hidden="true"></i> Saída</span></label>
        </fieldset>
        <div class="form-grid">
          <div class="grupo-input largo">
            <label for="l-desc">Descrição</label>
            <input type="text" id="l-desc" name="descricao" class="input-dash" maxlength="255" required value="${esc(l.contraparte)}">
          </div>
          <div class="grupo-input">
            <label for="l-cat">Categoria</label>
            <input type="text" id="l-cat" name="categoria" class="input-dash" maxlength="100" required value="${esc(l.categoria)}">
          </div>
          <div class="grupo-input">
            <label for="l-valor">Valor (R$)</label>
            <input type="number" id="l-valor" name="valor" class="input-dash" min="0.01" step="0.01" inputmode="decimal" required value="${l.total}">
          </div>
          <div class="grupo-input">
            <label for="l-data">Data</label>
            <input type="date" id="l-data" name="data_movimentacao" class="input-dash" required value="${esc(l.vencimento)}">
          </div>
          <div class="grupo-input">
            <label for="l-status">Situação</label>
            <select id="l-status" name="status" class="input-dash">
              <option value="PENDENTE"${l.status === 'PENDENTE' ? ' selected' : ''}>Pendente</option>
              <option value="PAGO"${l.status === 'PAGO' ? ' selected' : ''}>Pago</option>
            </select>
          </div>
        </div>`,
      extras: [{ rotulo: 'Excluir', icone: 'fa-trash', classe: 'btn-perigo', aoClicar: async (ctx) => { if (await excluir(l)) ctx.fechar(); } }],
      aoEnviar: async (campos) => {
        toast((await chamar('update_manual_flow', { ...campos, id: l.id })).message);
        carregar();
      },
    });
  }

  formNovo.addEventListener('submit', async (evento) => {
    evento.preventDefault();
    if (!formNovo.checkValidity()) return formNovo.reportValidity();

    const botao = formNovo.querySelector('[type=submit]');
    botao.disabled = true;
    try {
      const carga = Object.fromEntries(new FormData(formNovo).entries());
      toast((await chamar('create_manual_flow', carga)).message);
      formNovo.reset();
      formNovo.elements.data_movimentacao.value = hojeIso();
      carregar();
    } catch (e) {
      toast(e.message, 'erro');
    } finally {
      botao.disabled = false;
    }
  });

  document.getElementById('periodo').addEventListener('change', (e) => {
    estado.periodo = e.target.value;
    estado.pagina = 1;
    desenhar();
  });
  document.getElementById('situacao').addEventListener('change', (e) => {
    estado.situacao = e.target.value;
    estado.pagina = 1;
    desenhar();
  });

  formNovo.elements.data_movimentacao.value = hojeIso();
  carregar();
})();
