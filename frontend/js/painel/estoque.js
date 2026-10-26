/* Estoque · itens, entradas/saídas, validade e níveis mínimos */
(() => {
  const { api, modal, confirmar, toast, esc, brl, numero, dataBr, filtros, paginacao, carregando, vazio, debounce, gerenciarCategorias } = XT;

  const estado = { q: '', situacao: 'TODOS', categoria: '', pagina: 1 };
  let dados = null;
  let opcoes = { produtos: [], categorias: [] };

  const container = document.querySelector('.painel-tabela .tabela-container');
  const chips = document.getElementById('filtros');
  const nav = document.getElementById('paginacao');
  const seletorCategoria = document.getElementById('categoria');

  const SITUACOES = {
    INATIVO: { classe: 'inativo', texto: () => 'Inativo' },
    VENCIDO: { classe: 'critico', texto: () => 'Vencido' },
    CRITICO: { classe: 'critico', texto: () => 'Estoque baixo' },
    VENCENDO: { classe: 'baixo', texto: (i) => (Number(i.dias_para_vencer) <= 0 ? 'Vence hoje' : `Vence em ${i.dias_para_vencer} ${Number(i.dias_para_vencer) === 1 ? 'dia' : 'dias'}`) },
    OK: { classe: 'ok', texto: () => 'Em dia' },
  };

  async function carregar() {
    if (!dados) carregando(container, 'Carregando estoque...');
    try {
      dados = await api('estoque', 'listar', { params: { q: estado.q, situacao: estado.situacao, categoria_id: estado.categoria, pagina: estado.pagina } });
      desenhar();
    } catch (e) {
      container.innerHTML = vazio('fa-triangle-exclamation', 'Não foi possível carregar o estoque', e.message);
    }
  }

  async function carregarOpcoes() {
    opcoes = await api('estoque', 'opcoes');
    seletorCategoria.innerHTML =
      '<option value="">Todas as categorias</option>' + opcoes.categorias.map((c) => `<option value="${c.id}"${String(c.id) === estado.categoria ? ' selected' : ''}>${esc(c.nome)}</option>`).join('');
  }

  function desenhar() {
    const r = dados;
    const resumo = r.resumo;

    document.getElementById('res-itens').textContent = numero(resumo.itens);
    document.getElementById('res-categorias').textContent = `em ${numero(resumo.categorias)} ${resumo.categorias === 1 ? 'categoria' : 'categorias'}`;
    document.getElementById('res-minimo').textContent = numero(resumo.abaixo_minimo);
    document.getElementById('res-vencendo').textContent = numero(resumo.vencendo);
    document.getElementById('res-vencendo-rotulo').textContent = `Vencem em ${r.dias_validade} ${r.dias_validade === 1 ? 'dia' : 'dias'}`;
    document.getElementById('res-valor').textContent = brl(resumo.valor_estoque);

    filtros(
      chips,
      [
        { valor: 'TODOS', rotulo: 'Todos', qtd: r.contagem.todos },
        { valor: 'CRITICO', rotulo: 'Críticos', qtd: r.contagem.critico },
        { valor: 'VENCENDO', rotulo: 'Vencendo', qtd: r.contagem.vencendo },
        { valor: 'OK', rotulo: 'Em dia', qtd: r.contagem.ok },
      ],
      estado.situacao,
      (valor) => {
        estado.situacao = valor;
        estado.pagina = 1;
        carregar();
      },
    );

    desenharMovimentos(r.movimentos);

    if (!r.data.length) {
      container.innerHTML = vazio(
        'fa-boxes-stacked',
        estado.q || estado.situacao !== 'TODOS' || estado.categoria ? 'Nenhum item encontrado' : 'Nenhum item no estoque',
        estado.q || estado.situacao !== 'TODOS' || estado.categoria ? 'Tente outra busca ou outro filtro.' : 'Use "Novo item" para cadastrar o primeiro.',
      );
      paginacao(nav, r, 'itens', () => {});
      return;
    }

    container.innerHTML = `
      <table class="tabela">
        <thead>
          <tr>
            <th scope="col">Produto</th>
            <th scope="col">Em estoque</th>
            <th scope="col" class="num">Mínimo</th>
            <th scope="col">Validade</th>
            <th scope="col">Situação</th>
            <th scope="col"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>
          ${r.data
            .map((i) => {
              const s = SITUACOES[i.situacao] || SITUACOES.OK;
              const critico = i.situacao === 'CRITICO' || i.situacao === 'VENCIDO';
              return `
            <tr>
              <td><span class="celula-dupla"><strong>${esc(i.nome)}</strong><small>${esc(i.categoria)}</small></span></td>
              <td>
                <div class="nivel${critico ? ' critico' : ''}" style="--nivel: ${Number(i.nivel)}%">
                  <span class="nivel-valor">${numero(i.estoque)} ${esc(i.unidade)}</span>
                  <span class="nivel-barra" aria-hidden="true"><span></span></span>
                </div>
              </td>
              <td class="num">${numero(i.estoque_minimo)} ${esc(i.unidade)}</td>
              <td class="data">${i.validade ? dataBr(i.validade) : 'Sem validade'}</td>
              <td><span class="status ${s.classe}">${esc(s.texto(i))}</span></td>
              <td class="acoes">
                ${i.status === 'ATIVO' ? `<button type="button" class="btn btn-pequeno${critico ? '' : ' btn-fantasma'}" data-repor="${i.id}">Repor<span class="sr-only"> ${esc(i.nome)}</span></button>` : ''}
                <button type="button" class="btn btn-pequeno btn-fantasma" data-editar="${i.id}"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> ${esc(i.nome)}</span></button>
              </td>
            </tr>`;
            })
            .join('')}
        </tbody>
      </table>`;

    container.querySelectorAll('[data-repor]').forEach((b) => b.addEventListener('click', () => movimento('ENTRADA', Number(b.dataset.repor))));
    container.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () => formulario(r.data.find((i) => i.id == b.dataset.editar))));

    paginacao(nav, r, 'itens', (pagina) => {
      estado.pagina = pagina;
      carregar();
    });
  }

  function desenharMovimentos(lista) {
    const ul = document.getElementById('movimentos');
    if (!lista.length) {
      ul.innerHTML = `<li class="movimento"><span class="celula-dupla"><strong>Sem movimentações hoje</strong><small>Entradas e saídas aparecem aqui.</small></span></li>`;
      return;
    }

    ul.innerHTML = lista
      .map(
        (m) => `
      <li class="movimento">
        <span class="movimento-qtd ${m.tipo === 'ENTRADA' ? 'entrada' : 'saida'}">${m.tipo === 'ENTRADA' ? '+' : '−'}${numero(m.quantidade)} ${esc(m.unidade)}</span>
        <span class="celula-dupla"><strong>${esc(m.produto)}</strong><small>${esc(m.usuario)} · ${esc(m.hora)}${m.observacao ? ' · ' + esc(m.observacao) : ''}</small></span>
      </li>`,
      )
      .join('');
  }

  function movimento(tipo, produtoId) {
    const entrada = tipo === 'ENTRADA';
    modal({
      titulo: entrada ? 'Registrar entrada' : 'Registrar saída',
      rotuloSalvar: entrada ? 'Registrar entrada' : 'Registrar saída',
      corpo: `
        <div class="form-grid">
          <div class="grupo-input largo">
            <label for="m-produto">Produto</label>
            <select id="m-produto" name="produto_id" class="input-dash" required>
              <option value="">Selecione...</option>
              ${opcoes.produtos.map((p) => `<option value="${p.id}"${p.id == produtoId ? ' selected' : ''}>${esc(p.nome)} (${numero(p.estoque)} ${esc(p.unidade)})</option>`).join('')}
            </select>
          </div>
          <div class="grupo-input">
            <label for="m-qtd">Quantidade</label>
            <input type="number" id="m-qtd" name="quantidade" class="input-dash" min="1" step="1" inputmode="numeric" required>
          </div>
          <div class="grupo-input">
            <label for="m-obs">Observação (opcional)</label>
            <input type="text" id="m-obs" name="observacao" class="input-dash" maxlength="255" placeholder="${entrada ? 'Ex.: Compra #214' : 'Ex.: Produção do jantar'}">
          </div>
        </div>`,
      aoEnviar: async (campos) => {
        const resposta = await api('estoque', 'movimentar', { dados: { ...campos, tipo } });
        toast(resposta.message);
        await Promise.all([carregar(), carregarOpcoes()]);
      },
    });
  }

  async function excluir(item) {
    const ok = await confirmar({
      titulo: 'Excluir item',
      mensagem: `Excluir "${item.nome}" do estoque? Se houver vendas, compras ou movimentações, ele será apenas inativado.`,
      rotulo: 'Excluir',
      perigo: true,
    });
    if (!ok) return false;

    try {
      toast((await api('estoque', 'excluir', { dados: { id: item.id } })).message);
      await Promise.all([carregar(), carregarOpcoes()]);
      return true;
    } catch (e) {
      toast(e.message, 'erro');
      return false;
    }
  }

  function formulario(item) {
    const editando = Boolean(item);
    const i = item || { status: 'ATIVO', unidade: 'UN', estoque_minimo: 0, estoque: 0, preco: 0 };

    const grupos = [
      ['CARDAPIO', 'Itens do cardápio'],
      ['INSUMO', 'Insumos e materiais'],
    ]
      .map(([tipo, rotulo]) => {
        const opcoesTipo = opcoes.categorias.filter((c) => c.tipo === tipo);
        return opcoesTipo.length ? `<optgroup label="${rotulo}">${opcoesTipo.map((c) => `<option value="${c.id}"${c.id == i.categoria_id ? ' selected' : ''}>${esc(c.nome)}</option>`).join('')}</optgroup>` : '';
      })
      .join('');

    modal({
      titulo: editando ? 'Editar item do estoque' : 'Novo item do estoque',
      rotuloSalvar: editando ? 'Salvar alterações' : 'Cadastrar item',
      largo: true,
      corpo: `
        <div class="form-grid">
          <div class="grupo-input largo">
            <label for="e-nome">Nome</label>
            <input type="text" id="e-nome" name="nome" class="input-dash" maxlength="200" required value="${esc(i.nome)}">
          </div>
          <div class="grupo-input">
            <label for="e-cat">Categoria</label>
            <select id="e-cat" name="categoria_id" class="input-dash" required>${grupos}</select>
          </div>
          <div class="grupo-input">
            <label for="e-un">Unidade de medida</label>
            <input type="text" id="e-un" name="unidade" class="input-dash" maxlength="6" placeholder="UN, KG, L..." required value="${esc(i.unidade)}">
          </div>
          ${
            editando
              ? `<div class="grupo-input"><label>Em estoque agora</label><input type="text" class="input-dash" value="${numero(i.estoque)} ${esc(i.unidade)}" disabled><small>Para alterar, registre uma entrada ou saída.</small></div>`
              : `<div class="grupo-input"><label for="e-est">Quantidade inicial</label><input type="number" id="e-est" name="estoque" class="input-dash" min="0" step="1" value="0"></div>`
          }
          <div class="grupo-input">
            <label for="e-min">Estoque mínimo</label>
            <input type="number" id="e-min" name="estoque_minimo" class="input-dash" min="0" step="1" required value="${i.estoque_minimo}">
          </div>
          <div class="grupo-input">
            <label for="e-val">Validade (opcional)</label>
            <input type="date" id="e-val" name="validade" class="input-dash" value="${esc(i.validade)}">
          </div>
          <div class="grupo-input">
            <label for="e-preco">Preço unitário (R$)</label>
            <input type="number" id="e-preco" name="preco" class="input-dash" min="0" step="0.01" inputmode="decimal" required value="${i.preco}">
          </div>
          <div class="grupo-input">
            <label for="e-cod">Código de barras (opcional)</label>
            <input type="text" id="e-cod" name="codigo_barras" class="input-dash" maxlength="14" inputmode="numeric" value="${esc(i.codigo_barras)}">
          </div>
          <div class="grupo-input">
            <label for="e-ncm">NCM (opcional)</label>
            <input type="text" id="e-ncm" name="ncm" class="input-dash" maxlength="8" inputmode="numeric" value="${esc(i.ncm)}">
          </div>
          <div class="grupo-input largo">
            <label for="e-desc">Descrição</label>
            <textarea id="e-desc" name="descricao" class="input-dash" maxlength="255" required>${esc(i.descricao)}</textarea>
          </div>
          <div class="grupo-input">
            <label for="e-status">Situação</label>
            <select id="e-status" name="status" class="input-dash">
              <option value="ATIVO"${i.status === 'ATIVO' ? ' selected' : ''}>Ativo</option>
              <option value="INATIVO"${i.status === 'INATIVO' ? ' selected' : ''}>Inativo</option>
            </select>
          </div>
        </div>`,
      extras: editando && dados.pode_excluir ? [{ rotulo: 'Excluir', icone: 'fa-trash', classe: 'btn-perigo', aoClicar: async (ctx) => { if (await excluir(item)) ctx.fechar(); } }] : [],
      aoEnviar: async (campos) => {
        const resposta = await api('estoque', editando ? 'atualizar' : 'criar', { dados: { ...campos, id: editando ? i.id : undefined } });
        toast(resposta.message);
        await Promise.all([carregar(), carregarOpcoes()]);
      },
    });
  }

  document.getElementById('busca').addEventListener(
    'input',
    debounce((e) => {
      estado.q = e.target.value.trim();
      estado.pagina = 1;
      carregar();
    }, 300),
  );
  seletorCategoria.addEventListener('change', () => {
    estado.categoria = seletorCategoria.value;
    estado.pagina = 1;
    carregar();
  });
  document.getElementById('btn-entrada').addEventListener('click', () => movimento('ENTRADA'));
  document.getElementById('btn-saida').addEventListener('click', () => movimento('SAIDA'));
  document.getElementById('btn-novo').addEventListener('click', () => formulario(null));
  document.getElementById('btn-categorias').addEventListener('click', () =>
    gerenciarCategorias({ tipo: 'INSUMO', aoMudar: () => Promise.all([carregar(), carregarOpcoes()]) }),
  );

  (async () => {
    try {
      await carregarOpcoes();
    } catch (e) {
      toast(e.message, 'erro');
    }
    carregar();
  })();
})();
