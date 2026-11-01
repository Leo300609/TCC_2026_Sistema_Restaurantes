'use strict';
/**
 * Categorias de produto (CARDAPIO ou INSUMO), usadas nas telas de Cardápio e Estoque.
 * Regras no banco: sp_categoria_listar / _obter / _salvar / _excluir.
 */
const bd = require('./bd');
const { h, ler, entrada, entradaInt, csrfCampo, tratarPost, url } = require('./web');

const N = (v) => Number(v ?? 0) || 0;

/** Trata o POST das categorias (acao = cat_salvar | cat_excluir). Só gerência. */
async function tratarCategorias(ctx, tipo, gerente) {
  if (!['cat_salvar', 'cat_excluir'].includes(entrada(ctx, 'acao'))) return;
  await tratarPost(ctx, async () => {
    if (!gerente) throw new bd.ErroNegocio('Somente administradores e gerentes podem gerenciar categorias.');
    const id = entradaInt(ctx, 'id');
    const volta = url(ctx, { cat: null, cat_novo: null, cat_excluir: null });
    if (entrada(ctx, 'acao') === 'cat_salvar') {
      await bd.chamar('sp_categoria_salvar', [id, entrada(ctx, 'nome'), entrada(ctx, 'descricao'), entrada(ctx, 'status', 'ATIVO'), tipo]);
      return [id ? 'Categoria atualizada.' : 'Categoria criada.', volta];
    }
    await bd.chamar('sp_categoria_excluir', [id]);
    return ['Categoria excluída.', volta];
  }, url(ctx));
}

/** Dados para a tela: lista, categoria em edição e categoria a excluir. */
async function dadosCategorias(ctx, tipo) {
  const lista = await ler(ctx, () => bd.chamar('sp_categoria_listar', [tipo]));
  const editId = entradaInt(ctx, 'cat');
  const excId = entradaInt(ctx, 'cat_excluir');
  const achar = (id) => lista.find((c) => N(c.id) === id) || null;
  return { lista, editar: editId ? achar(editId) : null, novo: 'cat_novo' in ctx.query, excluir: excId ? achar(excId) : null };
}

/** Painel de categorias (gerência). */
function painelCategorias(ctx, dadosCat, rotuloCat) {
  const ed = dadosCat.editar;
  const abrirForm = dadosCat.novo || ed;
  const c = ed || { id: 0, nome: '', descricao: '', status: 'ATIVO' };
  const x = dadosCat.excluir;
  const sel = (cond) => (cond ? h`${' '}selected` : '');
  return h`
<section class="painel-card form-card" id="categorias" aria-labelledby="t-cats">
  <div class="painel-header">
    <h2 id="t-cats">Categorias ${rotuloCat}</h2>
    <span class="toolbar-grupo" style="width:auto">
      <a class="btn btn-pequeno" href="${url(ctx, { cat_novo: 1, cat: null, cat_excluir: null })}#categorias"><i class="fa-solid fa-plus" aria-hidden="true"></i> Nova categoria</a>
      <a class="gaveta-fechar" href="${url(ctx, { categorias: null, cat: null, cat_novo: null, cat_excluir: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a>
    </span>
  </div>
  <div class="gaveta-corpo">

  ${x ? h`
    <div class="confirmar-card">
      <p>Excluir a categoria <strong>${x.nome}</strong>? Só é possível se ela não tiver itens.</p>
      <form method="post" action="${url(ctx)}">
        ${csrfCampo(ctx)}
        <input type="hidden" name="acao" value="cat_excluir">
        <input type="hidden" name="id" value="${N(x.id)}">
        <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
        <a class="btn btn-secundario" href="${url(ctx, { cat_excluir: null })}#categorias">Cancelar</a>
      </form>
    </div>` : ''}

  ${abrirForm ? h`
    <form method="post" action="${url(ctx)}">
      ${csrfCampo(ctx)}
      <input type="hidden" name="acao" value="cat_salvar">
      <input type="hidden" name="id" value="${N(c.id)}">
      <div class="form-grid">
        <div class="grupo-input">
          <label for="cat-nome">Nome</label>
          <input type="text" id="cat-nome" name="nome" class="input-dash" maxlength="200" required value="${c.nome}">
        </div>
        <div class="grupo-input">
          <label for="cat-status">Situação</label>
          <select id="cat-status" name="status" class="input-dash">
            <option value="ATIVO"${sel(c.status === 'ATIVO')}>Ativa</option>
            <option value="INATIVO"${sel(c.status === 'INATIVO')}>Inativa</option>
          </select>
        </div>
        <div class="grupo-input largo">
          <label for="cat-desc">Descrição (opcional)</label>
          <input type="text" id="cat-desc" name="descricao" class="input-dash" maxlength="255" value="${c.descricao}">
        </div>
      </div>
      <div class="form-acoes">
        <button type="submit" class="btn btn-primario">${c.id ? 'Salvar categoria' : 'Criar categoria'}</button>
        <a class="btn btn-secundario" href="${url(ctx, { cat: null, cat_novo: null })}">Cancelar</a>
      </div>
    </form>` : ''}

  ${!dadosCat.lista.length ? h`<p class="vazio">Nenhuma categoria cadastrada.</p>` : h`
    <div class="tabela-container">
      <table class="tabela">
        <thead><tr><th scope="col">Categoria</th><th scope="col" class="num">Itens</th><th scope="col">Situação</th><th scope="col"><span class="sr-only">Ações</span></th></tr></thead>
        <tbody>
          ${dadosCat.lista.map((cat) => h`
            <tr>
              <td><strong>${cat.nome}</strong></td>
              <td class="num">${N(cat.itens)}</td>
              <td><span class="status ${cat.status === 'ATIVO' ? 'ativo' : 'inativo'}">${cat.status === 'ATIVO' ? 'Ativa' : 'Inativa'}</span></td>
              <td class="acoes">
                <a class="btn btn-pequeno btn-fantasma" href="${url(ctx, { cat: cat.id, cat_novo: null, cat_excluir: null })}#categorias"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> ${cat.nome}</span></a>
                <a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { cat_excluir: cat.id, cat: null, cat_novo: null })}#categorias" aria-label="Excluir ${cat.nome}"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>
              </td>
            </tr>`)}
        </tbody>
      </table>
    </div>`}
  </div>
</section>`;
}

module.exports = { tratarCategorias, dadosCategorias, painelCategorias };
