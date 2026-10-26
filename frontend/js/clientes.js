/* Clientes · listar, buscar, cadastrar, editar, inativar e excluir */
(() => {
  const { api, modal, confirmar, toast, esc, brl, filtros, paginacao, carregando, vazio, iniciais, formatarDocumento, formatarTelefone, debounce, digitos, numero } = XT;

  const estado = { q: '', status: 'TODOS', pagina: 1 };
  let opcoes = { estados: [], cidades: [] };
  let ultima = null;

  const container = document.querySelector('.painel-tabela .tabela-container');
  const chips = document.getElementById('filtros');
  const nav = document.getElementById('paginacao');

  async function carregar() {
    if (!ultima) carregando(container, 'Carregando clientes...');
    try {
      ultima = await api('clientes', 'listar', { params: { q: estado.q, status: estado.status, pagina: estado.pagina } });
      desenhar();
    } catch (e) {
      container.innerHTML = vazio('fa-triangle-exclamation', 'Não foi possível carregar os clientes', e.message);
    }
  }

  function desenhar() {
    const r = ultima;

    filtros(
      chips,
      [
        { valor: 'TODOS', rotulo: 'Todos', qtd: r.contagem.todos },
        { valor: 'ATIVO', rotulo: 'Ativos', qtd: r.contagem.ativos },
        { valor: 'INATIVO', rotulo: 'Inativos', qtd: r.contagem.inativos },
      ],
      estado.status,
      (valor) => {
        estado.status = valor;
        estado.pagina = 1;
        carregar();
      },
    );

    if (!r.data.length) {
      container.innerHTML = vazio(
        'fa-users',
        estado.q || estado.status !== 'TODOS' ? 'Nenhum cliente encontrado' : 'Nenhum cliente cadastrado ainda',
        estado.q || estado.status !== 'TODOS' ? 'Tente outra busca ou outro filtro.' : 'Use o botão "Novo cliente" para cadastrar o primeiro.',
      );
      paginacao(nav, r, 'clientes', () => {});
      return;
    }

    container.innerHTML = `
      <table class="tabela">
        <thead>
          <tr>
            <th scope="col">Cliente</th>
            <th scope="col">Telefone</th>
            <th scope="col">Cidade</th>
            <th scope="col" class="num">Pedidos</th>
            <th scope="col" class="num">Total gasto</th>
            <th scope="col">Situação</th>
            <th scope="col"><span class="sr-only">Ações</span></th>
          </tr>
        </thead>
        <tbody>
          ${r.data
            .map((c) => {
              const inativo = c.status === 'INATIVO';
              return `
            <tr>
              <td>
                <div class="pessoa">
                  <span class="avatar" aria-hidden="true">${esc(iniciais(c.nome))}</span>
                  <span class="celula-dupla"><strong>${esc(c.nome)}</strong><small>${c.documento.length === 14 ? 'CNPJ' : 'CPF'} ${esc(formatarDocumento(c.documento))}</small></span>
                </div>
              </td>
              <td>${esc(formatarTelefone(c.telefone))}</td>
              <td>${esc(c.cidade)}, ${esc(c.estado)}</td>
              <td class="num">${numero(c.pedidos)}</td>
              <td class="num">${brl(c.total_gasto)}</td>
              <td><span class="status ${inativo ? 'inativo' : 'ativo'}">${inativo ? 'Inativo' : 'Ativo'}</span></td>
              <td class="acoes">
                <button type="button" class="btn btn-pequeno btn-fantasma" data-editar="${c.id}"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> ${esc(c.nome)}</span></button>
                <button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-status="${c.id}" title="${inativo ? 'Reativar' : 'Inativar'} cliente" aria-label="${inativo ? 'Reativar' : 'Inativar'} ${esc(c.nome)}"><i class="fa-solid ${inativo ? 'fa-rotate-left' : 'fa-user-slash'}" aria-hidden="true"></i></button>
                ${r.pode_excluir ? `<button type="button" class="btn btn-pequeno btn-fantasma btn-icone" data-excluir="${c.id}" title="Excluir cliente" aria-label="Excluir ${esc(c.nome)}"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>` : ''}
              </td>
            </tr>`;
            })
            .join('')}
        </tbody>
      </table>`;

    const achar = (id) => r.data.find((c) => c.id == id);
    container.querySelectorAll('[data-editar]').forEach((b) => b.addEventListener('click', () => formulario(achar(b.dataset.editar))));
    container.querySelectorAll('[data-status]').forEach((b) =>
      b.addEventListener('click', async () => {
        const c = achar(b.dataset.status);
        try {
          const resposta = await api('clientes', 'status', { dados: { id: c.id, status: c.status === 'ATIVO' ? 'INATIVO' : 'ATIVO' } });
          toast(resposta.message);
          carregar();
        } catch (e) {
          toast(e.message, 'erro');
        }
      }),
    );
    container.querySelectorAll('[data-excluir]').forEach((b) => b.addEventListener('click', () => excluir(achar(b.dataset.excluir))));

    paginacao(nav, r, 'clientes', (pagina) => {
      estado.pagina = pagina;
      carregar();
    });
  }

  async function excluir(cliente) {
    const ok = await confirmar({
      titulo: 'Excluir cliente',
      mensagem: `Excluir ${cliente.nome}? Se ele já tiver pedidos, será apenas inativado para preservar o histórico.`,
      rotulo: 'Excluir',
      perigo: true,
    });
    if (!ok) return false;

    try {
      toast((await api('clientes', 'excluir', { dados: { id: cliente.id } })).message);
      carregar();
      return true;
    } catch (e) {
      toast(e.message, 'erro');
      return false;
    }
  }

  function formulario(cliente) {
    const editando = Boolean(cliente);
    const c = cliente || { estado: 'SP', status: 'ATIVO' };

    const opcoesEstado = opcoes.estados
      .map((e) => `<option value="${esc(e.sigla)}"${e.sigla === c.estado ? ' selected' : ''}>${esc(e.nome)}</option>`)
      .join('');

    modal({
      titulo: editando ? 'Editar cliente' : 'Novo cliente',
      rotuloSalvar: editando ? 'Salvar alterações' : 'Cadastrar cliente',
      corpo: `
        <div class="form-grid">
          <div class="grupo-input largo">
            <label for="c-nome">Nome ou razão social</label>
            <input type="text" id="c-nome" name="nome" class="input-dash" maxlength="255" required value="${esc(c.nome)}">
          </div>
          <div class="grupo-input">
            <label for="c-doc">CPF ou CNPJ</label>
            <input type="text" id="c-doc" name="documento" class="input-dash" inputmode="numeric" data-mascara="documento" required value="${esc(c.documento ? formatarDocumento(c.documento) : '')}">
          </div>
          <div class="grupo-input">
            <label for="c-tel">Telefone</label>
            <input type="tel" id="c-tel" name="telefone" class="input-dash" data-mascara="telefone" placeholder="(11) 98765-4321" required value="${esc(c.telefone ? formatarTelefone(c.telefone) : '')}">
          </div>
          <div class="grupo-input largo">
            <label for="c-end">Endereço (rua e bairro)</label>
            <input type="text" id="c-end" name="endereco" class="input-dash" maxlength="400" required value="${esc(c.endereco)}">
          </div>
          <div class="grupo-input">
            <label for="c-num">Número</label>
            <input type="text" id="c-num" name="endereco_numero" class="input-dash" maxlength="20" required value="${esc(c.endereco_numero)}">
          </div>
          <div class="grupo-input">
            <label for="c-uf">Estado</label>
            <select id="c-uf" name="estado" class="input-dash" required>${opcoesEstado}</select>
          </div>
          <div class="grupo-input largo">
            <label for="c-cidade">Cidade</label>
            <input type="text" id="c-cidade" name="cidade" class="input-dash" maxlength="200" list="c-cidades" autocomplete="off" required value="${esc(c.cidade)}">
            <datalist id="c-cidades"></datalist>
          </div>
          <div class="grupo-input">
            <label for="c-contato">Contato (opcional)</label>
            <input type="text" id="c-contato" name="contato" class="input-dash" maxlength="255" placeholder="E-mail ou nome do responsável" value="${esc(c.contato)}">
          </div>
          <div class="grupo-input">
            <label for="c-status">Situação</label>
            <select id="c-status" name="status" class="input-dash">
              <option value="ATIVO"${c.status === 'ATIVO' ? ' selected' : ''}>Ativo</option>
              <option value="INATIVO"${c.status === 'INATIVO' ? ' selected' : ''}>Inativo</option>
            </select>
          </div>
        </div>`,
      extras:
        editando && ultima.pode_excluir
          ? [{ rotulo: 'Excluir', icone: 'fa-trash', classe: 'btn-perigo', aoClicar: async (ctx) => { if (await excluir(cliente)) ctx.fechar(); } }]
          : [],
      aoAbrir: (ctx) => {
        const uf = ctx.form.querySelector('#c-uf');
        const lista = ctx.form.querySelector('#c-cidades');
        const atualizar = () => {
          lista.innerHTML = opcoes.cidades
            .filter((x) => x.estado === uf.value)
            .map((x) => `<option value="${esc(x.nome)}">`)
            .join('');
        };
        uf.addEventListener('change', atualizar);
        atualizar();
      },
      aoEnviar: async (dados) => {
        const resposta = await api('clientes', editando ? 'atualizar' : 'criar', { dados: { ...dados, id: editando ? c.id : undefined } });
        toast(resposta.message);
        carregar();
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
  document.getElementById('btn-novo').addEventListener('click', () => formulario(null));

  (async () => {
    try {
      opcoes = await api('clientes', 'opcoes');
    } catch (e) {
      toast(e.message, 'erro');
    }
    carregar();
  })();
})();
