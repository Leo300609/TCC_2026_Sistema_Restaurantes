/* Pedidos · quadro de comandas (novo → preparo → pronto/a caminho → entregue) */
(() => {
  const { api, modal, confirmar, toast, esc, brl, numero, filtros, carregando, vazio, debounce } = XT;

  const estado = { q: '', tipo: 'TODOS' };
  let pedidos = [];
  let meta = { operacao: { tempo_preparo: 20, aceita_delivery: true, pagamentos: [] }, pode_excluir: false, pode_estornar: false };
  let opcoes = null;

  const quadro = document.getElementById('quadro');
  const chips = document.getElementById('filtros');

  const FORMAS = { PIX: 'Pix', CREDITO: 'Crédito', DEBITO: 'Débito', DINHEIRO: 'Dinheiro', BOLETO: 'Boleto' };
  const COLUNAS = [
    { etapa: 'NOVO', id: 'col-novos', titulo: 'Novos' },
    { etapa: 'PREPARO', id: 'col-preparo', titulo: 'Em preparo' },
    { etapa: 'PRONTO', id: 'col-caminho', titulo: 'Pronto / a caminho' },
    { etapa: 'ENTREGUE', id: 'col-entregues', titulo: 'Entregues' },
  ];
  const ANTERIOR = { PREPARO: 'NOVO', PRONTO: 'PREPARO' };
  const PROXIMA = { NOVO: ['PREPARO', 'Aceitar', true], PREPARO: ['PRONTO', 'Marcar pronto', false], PRONTO: ['ENTREGUE', 'Confirmar entrega', false] };

  const tempo = (min) => (min >= 60 ? `${Math.floor(min / 60)}h ${String(min % 60).padStart(2, '0')}min` : `${min} min`);

  async function carregar(silencioso = false) {
    if (!silencioso && !pedidos.length) carregando(quadro, 'Carregando pedidos...');
    try {
      const r = await api('pedidos', 'quadro');
      pedidos = r.data;
      meta = r;
      desenhar();
    } catch (e) {
      if (!silencioso) quadro.innerHTML = vazio('fa-triangle-exclamation', 'Não foi possível carregar os pedidos', e.message);
    }
  }

  function filtrados() {
    const termo = estado.q.toLowerCase();
    return pedidos.filter((p) => {
      if (estado.tipo !== 'TODOS' && p.tipo !== estado.tipo) return false;
      if (!termo) return true;
      return String(p.id).includes(termo.replace('#', '')) || (p.cliente || '').toLowerCase().includes(termo) || (p.mesa || '').toLowerCase().includes(termo);
    });
  }

  function desenhar() {
    const base = pedidos.filter((p) => {
      const termo = estado.q.toLowerCase();
      return !termo || String(p.id).includes(termo.replace('#', '')) || (p.cliente || '').toLowerCase().includes(termo) || (p.mesa || '').toLowerCase().includes(termo);
    });
    const conta = (tipo) => base.filter((p) => tipo === 'TODOS' || p.tipo === tipo).length;

    filtros(
      chips,
      [
        { valor: 'TODOS', rotulo: 'Todos', qtd: conta('TODOS') },
        { valor: 'SALAO', rotulo: 'Salão', qtd: conta('SALAO') },
        { valor: 'BALCAO', rotulo: 'Balcão', qtd: conta('BALCAO') },
        { valor: 'DELIVERY', rotulo: 'Delivery', qtd: conta('DELIVERY') },
      ],
      estado.tipo,
      (valor) => {
        estado.tipo = valor;
        desenhar();
      },
    );

    const lista = filtrados();
    quadro.innerHTML = COLUNAS.map((col) => {
      let doGrupo = lista.filter((p) => p.etapa === col.etapa);
      if (col.etapa === 'ENTREGUE') doGrupo = doGrupo.slice().reverse();
      return `
        <section class="coluna" aria-labelledby="${col.id}">
          <header class="coluna-topo">
            <h2 id="${col.id}">${col.titulo}</h2>
            <span class="contagem">${doGrupo.length}</span>
          </header>
          ${doGrupo.length ? doGrupo.map(comanda).join('') : '<p class="coluna-vazia">Nenhum pedido</p>'}
        </section>`;
    }).join('');

    quadro.querySelectorAll('[data-etapa]').forEach((b) =>
      b.addEventListener('click', () => mudarEtapa(Number(b.dataset.id), b.dataset.etapa, b)),
    );
    quadro.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () => formulario(pedidos.find((p) => p.id == b.dataset.editar))));
    quadro.querySelectorAll('[data-detalhes]').forEach((b) => b.addEventListener('click', () => detalhes(pedidos.find((p) => p.id == b.dataset.detalhes))));
  }

  function origem(p) {
    if (p.tipo === 'SALAO') return `<i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Mesa ${esc(p.mesa)}${p.cliente ? ' · ' + esc(p.cliente) : ''}`;
    if (p.tipo === 'BALCAO') return `<i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão${p.cliente ? ' · ' + esc(p.cliente) : ''}`;
    return `<i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery · ${esc(p.cliente || 'sem cliente')}`;
  }

  function comanda(p) {
    const limite = Number(meta.operacao.tempo_preparo) || 20;
    const entregue = p.etapa === 'ENTREGUE';
    const atrasado = (p.etapa === 'NOVO' || p.etapa === 'PREPARO') && p.minutos > limite;

    let relogio;
    if (entregue) relogio = `<span class="status entregue">Entregue</span>`;
    else if (p.etapa === 'PRONTO') relogio = `<span class="comanda-tempo"><i class="fa-regular fa-clock" aria-hidden="true"></i> ${p.tipo === 'DELIVERY' ? 'saiu' : 'pronto'} há ${tempo(p.minutos_etapa)}</span>`;
    else relogio = `<span class="comanda-tempo${atrasado ? ' atrasado' : ''}"><i class="fa-regular fa-clock" aria-hidden="true"></i> há ${tempo(p.minutos)}</span>`;

    const proxima = PROXIMA[p.etapa];
    const botoes = entregue
      ? `<span class="comanda-hora">${esc(p.hora_etapa)}</span><button type="button" class="btn btn-pequeno btn-fantasma" data-detalhes="${p.id}">Detalhes<span class="sr-only"> do pedido ${p.id}</span></button>`
      : `${ANTERIOR[p.etapa] ? `<button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-id="${p.id}" data-etapa="${ANTERIOR[p.etapa]}" title="Voltar etapa" aria-label="Voltar o pedido ${p.id} para a etapa anterior"><i class="fa-solid fa-rotate-left" aria-hidden="true"></i></button>` : ''}
         <button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-editar="${p.id}" title="Editar pedido" aria-label="Editar pedido ${p.id}"><i class="fa-solid fa-pen" aria-hidden="true"></i></button>
         <button type="button" class="btn btn-pequeno${proxima[2] ? ' btn-primario' : ''}" data-id="${p.id}" data-etapa="${proxima[0]}">${proxima[1]}<span class="sr-only"> pedido ${p.id}</span></button>`;

    return `
      <article class="comanda${entregue ? ' concluida' : ''}">
        <header class="comanda-topo">
          <strong class="comanda-numero">#${p.id}</strong>
          ${relogio}
        </header>
        <p class="comanda-origem">${origem(p)}</p>
        <ul class="comanda-itens">
          ${p.itens.map((i) => `<li><span class="qtd">${i.quantidade}x</span> ${esc(i.nome)}${i.observacao ? ` <em>${esc(i.observacao)}</em>` : ''}</li>`).join('')}
        </ul>
        ${p.observacao ? `<p class="comanda-obs"><i class="fa-regular fa-note-sticky" aria-hidden="true"></i> ${esc(p.observacao)}</p>` : ''}
        <footer class="comanda-rodape">
          <div class="comanda-total">
            <strong>${brl(p.total)}</strong>
            <small>${esc(FORMAS[p.forma_pagamento] || p.forma_pagamento)}</small>
          </div>
          <div class="comanda-acoes">${botoes}</div>
        </footer>
      </article>`;
  }

  async function mudarEtapa(id, etapa, botao) {
    botao.disabled = true;
    try {
      await api('pedidos', 'etapa', { dados: { id, etapa } });
      if (etapa === 'ENTREGUE') toast(`Pedido #${id} entregue e registrado no Fluxo de Caixa.`);
      await carregar(true);
    } catch (e) {
      toast(e.message, 'erro');
      botao.disabled = false;
    }
  }

  async function cancelar(p) {
    const ok = await confirmar({
      titulo: 'Cancelar pedido',
      mensagem: `Cancelar o pedido #${p.id}?${p.etapa === 'ENTREGUE' ? ' Como ele já foi entregue, o valor será estornado do Fluxo de Caixa.' : ''}`,
      rotulo: 'Cancelar pedido',
      perigo: true,
    });
    if (!ok) return false;

    try {
      toast((await api('pedidos', 'cancelar', { dados: { id: p.id } })).message);
      await carregar(true);
      return true;
    } catch (e) {
      toast(e.message, 'erro');
      return false;
    }
  }

  async function excluir(p) {
    const ok = await confirmar({ titulo: 'Excluir pedido', mensagem: `Excluir definitivamente o pedido #${p.id}? Essa ação não pode ser desfeita.`, rotulo: 'Excluir', perigo: true });
    if (!ok) return false;

    try {
      toast((await api('pedidos', 'excluir', { dados: { id: p.id } })).message);
      await carregar(true);
      return true;
    } catch (e) {
      toast(e.message, 'erro');
      return false;
    }
  }

  function detalhes(p) {
    modal({
      titulo: `Pedido #${p.id}`,
      rotuloFechar: 'Fechar',
      corpo: `
        <p class="comanda-origem">${origem(p)}</p>
        <ul class="comanda-itens">
          ${p.itens.map((i) => `<li><span class="qtd">${i.quantidade}x</span> ${esc(i.nome)} <span class="item-valor">${brl(i.quantidade * i.preco)}</span>${i.observacao ? ` <em>${esc(i.observacao)}</em>` : ''}</li>`).join('')}
        </ul>
        ${p.observacao ? `<p class="modal-texto">Observação: ${esc(p.observacao)}</p>` : ''}
        <p class="modal-texto">Atendido por ${esc(p.atendente)} · ${esc(FORMAS[p.forma_pagamento] || p.forma_pagamento)}${p.desconto > 0 ? ` · desconto de ${brl(p.desconto)}` : ''}</p>
        <p class="total-pedido"><span>Total</span><strong>${brl(p.total)}</strong></p>`,
      extras: meta.pode_estornar ? [{ rotulo: 'Cancelar pedido (estornar)', icone: 'fa-ban', classe: 'btn-perigo', aoClicar: async (ctx) => { if (await cancelar(p)) ctx.fechar(); } }] : [],
    });
  }

  // ------------------------------------------------------------------
  // Formulário de pedido (novo / editar)
  // ------------------------------------------------------------------
  async function formulario(pedido) {
    try {
      // sempre busca de novo: preços, itens disponíveis e configurações podem ter mudado
      opcoes = await api('pedidos', 'opcoes');
    } catch (e) {
      toast(e.message, 'erro');
      return;
    }

    const editando = Boolean(pedido);
    const p = pedido || { tipo: 'SALAO', itens: [], desconto: 0, forma_pagamento: opcoes.operacao.pagamentos[0] };
    const aceitaDelivery = opcoes.operacao.aceita_delivery || p.tipo === 'DELIVERY';

    const precoAtual = (id, padrao) => {
      const produto = opcoes.produtos.find((x) => x.id == id);
      return produto ? Number(produto.preco) : Number(padrao);
    };
    let itens = p.itens.map((i) => ({ produto_id: i.produto_id, nome: i.nome, quantidade: i.quantidade, observacao: i.observacao || '', preco: precoAtual(i.produto_id, i.preco) }));

    const pagamentos = [...new Set([...opcoes.operacao.pagamentos, p.forma_pagamento])];
    const categorias = [...new Set(opcoes.produtos.map((x) => x.categoria))];

    const m = modal({
      titulo: editando ? `Editar pedido #${p.id}` : 'Novo pedido',
      rotuloSalvar: editando ? 'Salvar alterações' : 'Criar pedido',
      largo: true,
      corpo: `
        <fieldset class="segmentado segmentado-3">
          <legend class="sr-only">Tipo do pedido</legend>
          <label><input type="radio" name="tipo_pedido" value="SALAO"${p.tipo === 'SALAO' ? ' checked' : ''}><span><i class="fa-solid fa-table-cells-large" aria-hidden="true"></i> Salão</span></label>
          <label><input type="radio" name="tipo_pedido" value="BALCAO"${p.tipo === 'BALCAO' ? ' checked' : ''}><span><i class="fa-solid fa-bag-shopping" aria-hidden="true"></i> Balcão</span></label>
          <label><input type="radio" name="tipo_pedido" value="DELIVERY"${p.tipo === 'DELIVERY' ? ' checked' : ''}${aceitaDelivery ? '' : ' disabled'}><span><i class="fa-solid fa-motorcycle" aria-hidden="true"></i> Delivery${aceitaDelivery ? '' : ' (pausado)'}</span></label>
        </fieldset>

        <div class="form-grid">
          <div class="grupo-input" data-campo-mesa>
            <label for="p-mesa">Mesa</label>
            <input type="text" id="p-mesa" name="mesa" class="input-dash" maxlength="20" placeholder="Ex.: 4" value="${esc(p.mesa)}">
          </div>
          <div class="grupo-input" data-campo-cliente>
            <label for="p-cliente">Cliente <span data-cliente-obrigatorio></span></label>
            <select id="p-cliente" name="cliente_id" class="input-dash">
              <option value="">Sem cliente identificado</option>
              ${opcoes.clientes.map((c) => `<option value="${c.id}"${c.id == p.cliente_id ? ' selected' : ''}>${esc(c.nome)}</option>`).join('')}
            </select>
          </div>
        </div>

        <div class="grupo-input">
          <label for="p-add">Itens do pedido</label>
          <select id="p-add" class="input-dash">
            <option value="">Adicionar item do cardápio...</option>
            ${categorias
              .map(
                (cat) =>
                  `<optgroup label="${esc(cat)}">${opcoes.produtos
                    .filter((x) => x.categoria === cat)
                    .map((x) => `<option value="${x.id}">${esc(x.nome)} — ${brl(x.preco)}</option>`)
                    .join('')}</optgroup>`,
              )
              .join('')}
          </select>
        </div>
        <div class="itens-pedido" data-itens aria-live="polite"></div>

        <div class="form-grid">
          <div class="grupo-input">
            <label for="p-forma">Forma de pagamento</label>
            <select id="p-forma" name="forma_pagamento" class="input-dash" required>
              ${pagamentos.map((f) => `<option value="${f}"${f === p.forma_pagamento ? ' selected' : ''}>${esc(FORMAS[f] || f)}</option>`).join('')}
            </select>
          </div>
          <div class="grupo-input">
            <label for="p-desc">Desconto (R$)</label>
            <input type="number" id="p-desc" name="desconto" class="input-dash" min="0" step="0.01" inputmode="decimal" value="${p.desconto || 0}">
          </div>
          <div class="grupo-input largo">
            <label for="p-obs">Observação do pedido (opcional)</label>
            <input type="text" id="p-obs" name="observacao" class="input-dash" maxlength="255" value="${esc(p.observacao)}">
          </div>
        </div>
        <p class="total-pedido"><span>Total</span><strong data-total>R$ 0,00</strong></p>`,
      extras: editando
        ? [
            { rotulo: 'Cancelar pedido', icone: 'fa-ban', classe: 'btn-fantasma', aoClicar: async (ctx) => { if (await cancelar(p)) ctx.fechar(); } },
            ...(meta.pode_excluir ? [{ rotulo: 'Excluir', icone: 'fa-trash', classe: 'btn-perigo', aoClicar: async (ctx) => { if (await excluir(p)) ctx.fechar(); } }] : []),
          ]
        : [],
      aoEnviar: async (campos) => {
        if (!itens.length) throw new Error('Adicione ao menos um item ao pedido.');
        const resposta = await api('pedidos', editando ? 'atualizar' : 'criar', {
          dados: {
            id: editando ? p.id : undefined,
            tipo_pedido: campos.tipo_pedido,
            mesa: campos.mesa || '',
            cliente_id: campos.cliente_id || '',
            forma_pagamento: campos.forma_pagamento,
            desconto: campos.desconto || 0,
            observacao: campos.observacao || '',
            itens: itens.map((i) => ({ produto_id: i.produto_id, quantidade: i.quantidade, observacao: i.observacao })),
          },
        });
        toast(resposta.message);
        await carregar(true);
      },
    });

    const area = m.el.querySelector('[data-itens]');
    const total = m.el.querySelector('[data-total]');
    const desconto = m.form.querySelector('[name=desconto]');

    const recalcular = () => {
      const bruto = itens.reduce((soma, i) => soma + i.quantidade * i.preco, 0);
      const abatimento = Math.min(Number(desconto.value) || 0, bruto);
      total.textContent = brl(bruto - abatimento);
    };

    const desenharItens = () => {
      area.innerHTML = itens.length
        ? itens
            .map(
              (i, n) => `
          <div class="item-pedido">
            <span class="item-pedido-nome">${esc(i.nome)}<small>${brl(i.preco)}</small></span>
            <input type="number" class="input-dash" min="1" max="999" step="1" value="${i.quantidade}" data-qtd="${n}" aria-label="Quantidade de ${esc(i.nome)}" required>
            <input type="text" class="input-dash" maxlength="255" placeholder="Observação (ex.: sem sal)" value="${esc(i.observacao)}" data-obs="${n}" aria-label="Observação de ${esc(i.nome)}">
            <strong class="item-pedido-sub">${brl(i.quantidade * i.preco)}</strong>
            <button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-remover="${n}" aria-label="Remover ${esc(i.nome)}"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
          </div>`,
            )
            .join('')
        : '<p class="itens-vazio">Nenhum item adicionado ainda.</p>';
      recalcular();
    };

    area.addEventListener('input', (e) => {
      if (e.target.dataset.qtd !== undefined) {
        const item = itens[Number(e.target.dataset.qtd)];
        item.quantidade = Math.max(1, Math.min(999, parseInt(e.target.value, 10) || 1));
        e.target.closest('.item-pedido').querySelector('.item-pedido-sub').textContent = brl(item.quantidade * item.preco);
        recalcular();
      } else if (e.target.dataset.obs !== undefined) {
        itens[Number(e.target.dataset.obs)].observacao = e.target.value;
      }
    });
    area.addEventListener('click', (e) => {
      const botao = e.target.closest('[data-remover]');
      if (!botao) return;
      itens.splice(Number(botao.dataset.remover), 1);
      desenharItens();
    });

    m.el.querySelector('#p-add').addEventListener('change', (e) => {
      const produto = opcoes.produtos.find((x) => x.id == e.target.value);
      e.target.value = '';
      if (!produto) return;
      const existente = itens.find((i) => i.produto_id == produto.id);
      if (existente) existente.quantidade = Math.min(999, existente.quantidade + 1);
      else itens.push({ produto_id: Number(produto.id), nome: produto.nome, quantidade: 1, observacao: '', preco: Number(produto.preco) });
      desenharItens();
    });
    desconto.addEventListener('input', recalcular);

    const ajustarTipo = () => {
      const tipo = m.form.querySelector('[name=tipo_pedido]:checked').value;
      m.el.querySelector('[data-campo-mesa]').hidden = tipo !== 'SALAO';
      m.form.querySelector('[name=mesa]').required = tipo === 'SALAO';
      m.form.querySelector('[name=cliente_id]').required = tipo === 'DELIVERY';
      m.el.querySelector('[data-cliente-obrigatorio]').textContent = tipo === 'DELIVERY' ? '(obrigatório)' : '(opcional)';
    };
    m.form.querySelectorAll('[name=tipo_pedido]').forEach((r) => r.addEventListener('change', ajustarTipo));
    ajustarTipo();
    desenharItens();
  }

  document.getElementById('busca').addEventListener(
    'input',
    debounce((e) => {
      estado.q = e.target.value.trim();
      desenhar();
    }, 200),
  );
  document.getElementById('btn-novo').addEventListener('click', () => formulario(null));

  carregar();
  setInterval(() => {
    if (!document.hidden && !document.querySelector('dialog[open]')) carregar(true);
  }, 30000);
})();
