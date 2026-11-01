'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const { corAvatar } = require('../layout');
const {
  h, ler, moeda, entrada, entradaInt, csrfCampo, tratarPost, url, paginacao, documentoBr, telefoneBr, sel,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const POR_PAGINA = 10;

module.exports = async function fornecedores(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.compras);

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    const id = entradaInt(ctx, 'id');
    if (acao === 'salvar') {
      await bd.chamar('sp_fornecedor_salvar', [
        id, entrada(ctx, 'nome'), entrada(ctx, 'documento'), entrada(ctx, 'endereco'), entrada(ctx, 'endereco_numero'), entrada(ctx, 'telefone'),
        entrada(ctx, 'contato'), entrada(ctx, 'email'), entrada(ctx, 'status', 'ATIVO'), entrada(ctx, 'estado'), entrada(ctx, 'cidade'),
      ]);
      return [id ? 'Fornecedor atualizado.' : 'Fornecedor cadastrado.', url(ctx, { editar: null, novo: null })];
    }
    if (acao === 'status') {
      const novo = entrada(ctx, 'status') === 'INATIVO' ? 'INATIVO' : 'ATIVO';
      await bd.chamar('sp_fornecedor_status', [id, novo]);
      return novo === 'ATIVO' ? 'Fornecedor reativado.' : 'Fornecedor inativado.';
    }
    if (acao === 'excluir') {
      const r = await bd.chamarValor('sp_fornecedor_excluir', [id]);
      return [r === 'INATIVADO' ? 'O fornecedor tem compras no histórico, então foi apenas inativado.' : 'Fornecedor excluído.', url(ctx, { excluir: null, editar: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const busca = entrada(ctx, 'q');
  const status = ['ATIVO', 'INATIVO'].includes(entrada(ctx, 'status')) ? entrada(ctx, 'status') : '';
  let pg = Math.max(1, entradaInt(ctx, 'pagina', 1));

  const contagem = await ler(ctx, () => bd.chamarUm('sp_fornecedor_contagem', [busca]), { todos: 0, ativos: 0, inativos: 0 });
  const totalFiltro = N(status === 'ATIVO' ? contagem.ativos : status === 'INATIVO' ? contagem.inativos : contagem.todos);
  const paginasTotal = Math.max(1, Math.ceil(totalFiltro / POR_PAGINA));
  pg = Math.min(pg, paginasTotal);

  const [lst, estados] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_fornecedor_listar', [busca, status, POR_PAGINA, (pg - 1) * POR_PAGINA])),
    ler(ctx, () => bd.chamar('sp_estado_listar')),
  ]);

  const editar = entradaInt(ctx, 'editar');
  const formAberto = 'novo' in ctx.query || editar > 0;
  const fornecedor = editar > 0 ? await ler(ctx, () => bd.chamarUm('sp_fornecedor_obter', [editar]), null) : null;
  const f = fornecedor || { id: 0, nome: '', documento: '', telefone: '', endereco: '', endereco_numero: '', estado: 'SP', cidade: '', contato: '', email: '', status: 'ATIVO' };
  const produtosFornecedor = fornecedor ? await ler(ctx, () => bd.chamar('sp_fornecedor_produtos', [editar])) : [];

  const excluirId = entradaInt(ctx, 'excluir');
  const alvoExcluir = excluirId > 0 ? await ler(ctx, () => bd.chamarUm('sp_fornecedor_obter', [excluirId]), null) : null;

  const conteudo = h`
        <form class="toolbar" method="get" action="fornecedores">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar fornecedor</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="${busca}" placeholder="Buscar por nome, contato, CNPJ ou telefone" autocomplete="off">
            </label>
            ${status ? h`<input type="hidden" name="status" value="${status}">` : ''}
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Situação">
              ${[['', 'Todos', contagem.todos], ['ATIVO', 'Ativos', contagem.ativos], ['INATIVO', 'Inativos', contagem.inativos]].map(([v, r, q]) => h`<a class="filtro${status === v ? ' ativo' : ''}" href="${url(ctx, { status: v || null, pagina: null })}">${r} <span>${N(q)}</span></a>`)}
            </div>
          </div>
          <a class="btn btn-primario" href="${url(ctx, { novo: 1, editar: null })}"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo fornecedor</a>
        </form>

        <section class="painel-card painel-tabela" aria-label="Lista de fornecedores">
          ${!lst.length ? h`<p class="vazio"><span class="vazio-icone"><i class="fa-solid fa-truck-field" aria-hidden="true"></i></span>${busca ? 'Nenhum fornecedor encontrado.' : 'Nenhum fornecedor cadastrado. Use "Novo fornecedor" para começar.'}</p>` : h`
            <div class="tabela-container">
              <table class="tabela">
                <thead>
                  <tr>
                    <th scope="col">Fornecedor</th>
                    <th scope="col">Contato</th>
                    <th scope="col">Cidade</th>
                    <th scope="col" class="num">Produtos</th>
                    <th scope="col" class="num">Compras</th>
                    <th scope="col" class="num">Total comprado</th>
                    <th scope="col">Situação</th>
                    <th scope="col"><span class="sr-only">Ações</span></th>
                  </tr>
                </thead>
                <tbody>
                  ${lst.map((fo) => {
                    const inativo = fo.status === 'INATIVO';
                    return h`<tr class="${inativo ? 'linha-apagada' : ''}">
                      <td>
                        <div class="pessoa">
                          <span class="avatar ${corAvatar(fo.nome)}" aria-hidden="true"><i class="fa-solid fa-truck" style="font-size:12px"></i></span>
                          <span class="celula-dupla"><strong>${fo.nome}</strong><small>${String(fo.documento).length === 14 ? 'CNPJ' : 'CPF'} ${documentoBr(fo.documento)}</small></span>
                        </div>
                      </td>
                      <td><span class="celula-dupla"><strong>${telefoneBr(fo.telefone)}</strong><small>${fo.contato || fo.email || '—'}</small></span></td>
                      <td>${fo.cidade}, ${fo.estado}</td>
                      <td class="num">${N(fo.produtos)}</td>
                      <td class="num"><span class="celula-dupla"><strong>${N(fo.compras)}</strong><small>${fo.ultima_compra ? 'última ' + fo.ultima_compra : 'nenhuma'}</small></span></td>
                      <td class="num"><strong>${moeda(fo.total_comprado)}</strong></td>
                      <td><span class="status ${inativo ? 'inativo' : 'ativo'}">${inativo ? 'Inativo' : 'Ativo'}</span></td>
                      <td class="acoes">
                        ${!inativo ? h`<a class="btn btn-pequeno" href="compras?novo=1&amp;fornecedor=${N(fo.id)}"><i class="fa-solid fa-cart-plus" aria-hidden="true"></i> Comprar</a>` : ''}
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { editar: fo.id, novo: null })}" aria-label="Editar ${fo.nome}"><i class="fa-solid fa-pen" aria-hidden="true"></i></a>
                        <form class="em-linha" method="post" action="${url(ctx)}">
                          ${csrfCampo(ctx)}
                          <input type="hidden" name="acao" value="status">
                          <input type="hidden" name="id" value="${N(fo.id)}">
                          <input type="hidden" name="status" value="${inativo ? 'ATIVO' : 'INATIVO'}">
                          <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="${inativo ? 'Reativar' : 'Inativar'}" aria-label="${inativo ? 'Reativar' : 'Inativar'} ${fo.nome}"><i class="fa-solid ${inativo ? 'fa-rotate-left' : 'fa-ban'}" aria-hidden="true"></i></button>
                        </form>
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { excluir: fo.id })}" title="Excluir" aria-label="Excluir ${fo.nome}"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>
                      </td>
                    </tr>`;
                  })}
                </tbody>
              </table>
            </div>
            ${paginacao(ctx, pg, paginasTotal, totalFiltro, POR_PAGINA)}`}
        </section>

        ${alvoExcluir ? h`
          <section class="confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir fornecedor?</h2>
            <p>Excluir <strong>${alvoExcluir.nome}</strong>? Se houver compras ou produtos vinculados, ele será apenas inativado para preservar o histórico.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="${N(alvoExcluir.id)}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="${url(ctx, { excluir: null })}" data-fechar>Cancelar</a>
            </form>
          </section>` : ''}

        ${formAberto && (editar === 0 || fornecedor) ? h`
          <section class="painel-card form-card" aria-labelledby="t-form">
            <div class="painel-header">
              <h2 id="t-form">${f.id ? 'Editar fornecedor' : 'Novo fornecedor'}</h2>
              <a class="gaveta-fechar" href="${url(ctx, { novo: null, editar: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
            </div>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="salvar">
              <input type="hidden" name="id" value="${N(f.id)}">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="f-nome">Razão social ou nome</label>
                  <input type="text" id="f-nome" name="nome" class="input-dash" maxlength="255" required value="${f.nome}">
                </div>
                <div class="grupo-input">
                  <label for="f-doc">CNPJ ou CPF</label>
                  <input type="text" id="f-doc" name="documento" class="input-dash" inputmode="numeric" maxlength="18" required data-mascara="documento" value="${documentoBr(f.documento)}">
                </div>
                <div class="grupo-input">
                  <label for="f-tel">Telefone</label>
                  <input type="tel" id="f-tel" name="telefone" class="input-dash" maxlength="16" required data-mascara="telefone" placeholder="(11) 3333-4444" value="${telefoneBr(f.telefone)}">
                </div>
                <div class="grupo-input">
                  <label for="f-contato">Pessoa de contato (opcional)</label>
                  <input type="text" id="f-contato" name="contato" class="input-dash" maxlength="255" value="${f.contato ?? ''}">
                </div>
                <div class="grupo-input">
                  <label for="f-email">E-mail (opcional)</label>
                  <input type="email" id="f-email" name="email" class="input-dash" maxlength="255" value="${f.email ?? ''}">
                </div>
                <div class="grupo-input largo">
                  <label for="f-end">Endereço (rua e bairro)</label>
                  <input type="text" id="f-end" name="endereco" class="input-dash" maxlength="255" required value="${f.endereco}">
                </div>
                <div class="grupo-input">
                  <label for="f-num">Número</label>
                  <input type="text" id="f-num" name="endereco_numero" class="input-dash" maxlength="20" required value="${f.endereco_numero}">
                </div>
                <div class="grupo-input">
                  <label for="f-uf">Estado</label>
                  <select id="f-uf" name="estado" class="input-dash" required>
                    ${estados.map((uf) => h`<option value="${uf.sigla}"${sel(uf.sigla === f.estado)}>${uf.nome}</option>`)}
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="f-cidade">Cidade</label>
                  <input type="text" id="f-cidade" name="cidade" class="input-dash" maxlength="200" required value="${f.cidade}">
                </div>
                <div class="grupo-input">
                  <label for="f-status">Situação</label>
                  <select id="f-status" name="status" class="input-dash">
                    <option value="ATIVO"${sel(f.status === 'ATIVO')}>Ativo</option>
                    <option value="INATIVO"${sel(f.status === 'INATIVO')}>Inativo</option>
                  </select>
                </div>
              </div>

              ${produtosFornecedor.length ? h`
                <h3 class="subtitulo-form">Produtos e último preço pago</h3>
                <table class="tabela" style="font-size:13px">
                  <thead><tr><th scope="col">Produto</th><th scope="col" class="num">Preço</th></tr></thead>
                  <tbody>
                    ${produtosFornecedor.map((p) => h`<tr><td>${p.nome} <small class="muted">(${p.unidade})</small></td><td class="num">${moeda(p.preco)}</td></tr>`)}
                  </tbody>
                </table>` : ''}

              <div class="form-acoes">
                <button type="submit" class="btn btn-primario">${f.id ? 'Salvar alterações' : 'Cadastrar fornecedor'}</button>
                <a class="btn btn-secundario" href="${url(ctx, { novo: null, editar: null })}" data-fechar>Cancelar</a>
              </div>
            </form>
          </section>` : ''}`;

  return pagina(ctx, { titulo: 'Fornecedores', subtitulo: 'Quem abastece a cozinha e quanto você já comprou de cada um', paginaAtual: 'fornecedores' }, conteudo);
};
