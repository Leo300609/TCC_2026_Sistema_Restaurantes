/* Cardápio · itens por categoria, disponibilidade, cadastro, edição e exclusão */
(() => {
  const { api, modal, confirmar, toast, esc, brl, filtros, carregando, vazio, debounce, gerenciarCategorias } = XT;

  const estado = { q: '', categoria: 'TODOS' };
  let dados = null;

  const chips = document.getElementById('filtros');
  const lista = document.getElementById('cardapio');

  async function carregar() {
    if (!dados) carregando(lista, 'Carregando cardápio...');
    try {
      dados = await api('cardapio', 'listar', { params: { q: estado.q } });
      document.getElementById('btn-categorias').hidden = !dados.pode_editar;
      document.getElementById('btn-novo').hidden = !dados.pode_editar;
      desenhar();
    } catch (e) {
      lista.innerHTML = vazio('fa-triangle-exclamation', 'Não foi possível carregar o cardápio', e.message);
    }
  }

  function desenhar() {
    const totalItens = dados.categorias.reduce((soma, c) => soma + Number(c.itens), 0);
    const categoriasComItens = dados.categorias.filter((c) => Number(c.itens) > 0);

    if (estado.categoria !== 'TODOS' && !dados.categorias.some((c) => String(c.id) === estado.categoria)) {
      estado.categoria = 'TODOS';
    }

    filtros(
      chips,
      [{ valor: 'TODOS', rotulo: 'Todos', qtd: totalItens }, ...categoriasComItens.map((c) => ({ valor: String(c.id), rotulo: c.nome, qtd: Number(c.itens) }))],
      estado.categoria,
      (valor) => {
        estado.categoria = valor;
        desenhar();
      },
    );

    const visiveis = dados.data.filter((i) => estado.categoria === 'TODOS' || String(i.categoria_id) === estado.categoria);

    if (!visiveis.length) {
      lista.innerHTML = vazio(
        'fa-utensils',
        estado.q ? 'Nenhum item encontrado' : 'O cardápio ainda está vazio',
        estado.q ? 'Tente outro termo de busca.' : dados.pode_editar ? 'Crie uma categoria e use "Novo item" para começar.' : 'Peça a um gerente para cadastrar os itens.',
      );
      return;
    }

    const grupos = new Map();
    visiveis.forEach((item) => {
      if (!grupos.has(item.categoria_id)) grupos.set(item.categoria_id, { nome: item.categoria, itens: [] });
      grupos.get(item.categoria_id).itens.push(item);
    });

    lista.innerHTML = [...grupos.entries()]
      .map(
        ([id, grupo]) => `
        <section class="categoria" aria-labelledby="cat-${id}">
          <header class="categoria-topo">
            <h2 id="cat-${id}">${esc(grupo.nome)}</h2>
            <span class="painel-meta">${grupo.itens.length} ${grupo.itens.length === 1 ? 'item' : 'itens'}</span>
          </header>
          <ul class="itens-cardapio">
            ${grupo.itens.map(linhaItem).join('')}
          </ul>
        </section>`,
      )
      .join('');

    lista.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () => formulario(dados.data.find((i) => i.id == b.dataset.editar))));
    lista.querySelectorAll('[data-disponivel]').forEach((caixa) =>
      caixa.addEventListener('change', async () => {
        try {
          const resposta = await api('cardapio', 'disponibilidade', { dados: { id: caixa.dataset.disponivel, disponivel: caixa.checked } });
          toast(resposta.message);
          carregar();
        } catch (e) {
          caixa.checked = !caixa.checked;
          toast(e.message, 'erro');
        }
      }),
    );
  }

  function linhaItem(item) {
    const disponivel = item.status === 'ATIVO';
    const podeAlternar = dados.pode_editar || dados.pode_disponibilidade;

    return `
      <li class="item-cardapio${disponivel ? '' : ' indisponivel'}">
        <div class="item-linha">
          <h3 class="item-nome">${esc(item.nome)}</h3>
          <span class="item-pontos" aria-hidden="true"></span>
          <span class="item-preco">${brl(item.preco)}</span>
        </div>
        <p class="item-descricao">${esc(item.descricao)}</p>
        <div class="item-acoes">
          <label class="switch-rotulo">
            <span class="switch"><input type="checkbox" role="switch" data-disponivel="${item.id}"${disponivel ? ' checked' : ''}${podeAlternar ? '' : ' disabled'}><span class="switch-trilho" aria-hidden="true"></span></span>
            <span>${disponivel ? 'Disponível' : 'Indisponível'}</span>
          </label>
          ${
            dados.pode_editar
              ? `<button type="button" class="btn btn-pequeno btn-fantasma" data-editar="${item.id}"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> ${esc(item.nome)}</span></button>`
              : ''
          }
        </div>
      </li>`;
  }

  async function excluir(item) {
    const ok = await confirmar({
      titulo: 'Excluir item',
      mensagem: `Excluir "${item.nome}" do cardápio? Se já houver vendas com ele, será apenas marcado como indisponível.`,
      rotulo: 'Excluir',
      perigo: true,
    });
    if (!ok) return false;

    try {
      toast((await api('cardapio', 'excluir', { dados: { id: item.id } })).message);
      carregar();
      return true;
    } catch (e) {
      toast(e.message, 'erro');
      return false;
    }
  }

  function formulario(item) {
    const editando = Boolean(item);
    const i = item || { status: 'ATIVO' };
    const ativas = dados.categorias.filter((c) => c.status === 'ATIVO' || c.id == i.categoria_id);

    if (!ativas.length) {
      toast('Crie uma categoria antes de adicionar itens ao cardápio.', 'erro');
      gerenciarCategorias({ tipo: 'CARDAPIO', aoMudar: carregar });
      return;
    }

    modal({
      titulo: editando ? 'Editar item' : 'Novo item do cardápio',
      rotuloSalvar: editando ? 'Salvar alterações' : 'Adicionar ao cardápio',
      corpo: `
        <div class="form-grid">
          <div class="grupo-input largo">
            <label for="i-nome">Nome</label>
            <input type="text" id="i-nome" name="nome" class="input-dash" maxlength="200" required value="${esc(i.nome)}">
          </div>
          <div class="grupo-input">
            <label for="i-cat">Categoria</label>
            <select id="i-cat" name="categoria_id" class="input-dash" required>
              ${ativas.map((c) => `<option value="${c.id}"${c.id == i.categoria_id ? ' selected' : ''}>${esc(c.nome)}</option>`).join('')}
            </select>
          </div>
          <div class="grupo-input">
            <label for="i-preco">Preço (R$)</label>
            <input type="number" id="i-preco" name="preco" class="input-dash" min="0.01" step="0.01" inputmode="decimal" required value="${i.preco ?? ''}">
          </div>
          <div class="grupo-input largo">
            <label for="i-desc">Descrição</label>
            <textarea id="i-desc" name="descricao" class="input-dash" maxlength="255" required>${esc(i.descricao)}</textarea>
          </div>
          <div class="grupo-input">
            <label for="i-cod">Código de barras (opcional)</label>
            <input type="text" id="i-cod" name="codigo_barras" class="input-dash" maxlength="14" inputmode="numeric" value="${esc(i.codigo_barras)}">
          </div>
          <div class="grupo-input">
            <label for="i-status">Situação</label>
            <select id="i-status" name="status" class="input-dash">
              <option value="ATIVO"${i.status === 'ATIVO' ? ' selected' : ''}>Disponível</option>
              <option value="INATIVO"${i.status === 'INATIVO' ? ' selected' : ''}>Indisponível</option>
            </select>
          </div>
        </div>`,
      extras: editando ? [{ rotulo: 'Excluir', icone: 'fa-trash', classe: 'btn-perigo', aoClicar: async (ctx) => { if (await excluir(item)) ctx.fechar(); } }] : [],
      aoEnviar: async (campos) => {
        const resposta = await api('cardapio', editando ? 'atualizar' : 'criar', { dados: { ...campos, id: editando ? i.id : undefined, ncm: i.ncm || '' } });
        toast(resposta.message);
        carregar();
      },
    });
  }

  document.getElementById('busca').addEventListener(
    'input',
    debounce((e) => {
      estado.q = e.target.value.trim();
      carregar();
    }, 300),
  );
  document.getElementById('btn-novo').addEventListener('click', () => formulario(null));
  document.getElementById('btn-categorias').addEventListener('click', () => gerenciarCategorias({ tipo: 'CARDAPIO', aoMudar: carregar }));

  carregar();
})();
