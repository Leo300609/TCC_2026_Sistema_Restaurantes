'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const { tratarCategorias, dadosCategorias, painelCategorias } = require('../categorias');
const {
  h, cru, ler, moeda, numero, entrada, entradaInt, entradaDecimal, lista, csrfCampo, tratarPost, url, sel, chk,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;

module.exports = async function cardapios(ctx) {
  const u = await sessao.autenticar(ctx);
  const ehGerente = u.perm.financeiro;          // administrador ou gerente
  const podeDisponibilidade = u.perm.estoque;   // gerente ou cozinha

  // ---------- ações (POST) ----------
  await tratarCategorias(ctx, 'CARDAPIO', ehGerente);

  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    const id = entradaInt(ctx, 'id');

    if (acao === 'salvar') {
      if (!ehGerente) throw new bd.ErroNegocio('Somente administradores e gerentes podem editar o cardápio.');
      // ficha técnica: linhas com insumo escolhido (as em branco são ignoradas)
      const qtds = lista(ctx, 'ficha_qtd');
      const ficha = [];
      lista(ctx, 'ficha_insumo').forEach((insumo, n) => {
        if ((parseInt(insumo, 10) || 0) > 0) ficha.push([parseInt(insumo, 10), parseInt(qtds[n], 10) || 1]);
      });
      const novoId = Number(await bd.chamarValor('sp_produto_salvar', [
        id, 'CARDAPIO', entrada(ctx, 'nome'), entrada(ctx, 'descricao'), entradaDecimal(ctx, 'preco'), entradaInt(ctx, 'categoria_id'),
        entrada(ctx, 'status', 'ATIVO'), entrada(ctx, 'codigo_barras'), '', 'UN', 0, null, 0, u.id,
      ]));
      await bd.transacao(async (tx) => {
        await tx.chamar('sp_produto_controle_estoque', [novoId, 'controla_estoque' in ctx.corpo && !ficha.length ? 1 : 0]);
        await tx.chamar('sp_ficha_limpar', [novoId]);
        for (const [insumo, qtd] of ficha) await tx.chamar('sp_ficha_adicionar', [novoId, insumo, qtd]);
      });
      return [id ? 'Item atualizado.' : 'Item adicionado ao cardápio.', url(ctx, { editar: null, novo: null })];
    }
    if (acao === 'disponibilidade') {
      if (!ehGerente && !podeDisponibilidade) throw new bd.ErroNegocio('Você não pode alterar a disponibilidade dos itens.');
      const disp = entrada(ctx, 'disponivel') === '1' ? 1 : 0;
      await bd.chamar('sp_cardapio_disponibilidade', [id, disp]);
      return disp ? 'Item disponível no cardápio.' : 'Item marcado como indisponível.';
    }
    if (acao === 'excluir') {
      if (!ehGerente) throw new bd.ErroNegocio('Somente administradores e gerentes podem excluir itens.');
      const r = await bd.chamarValor('sp_produto_excluir', [id, 'CARDAPIO']);
      return [r === 'INATIVADO' ? 'O item tem vendas ou movimentações no histórico, então foi marcado como indisponível em vez de excluído.' : 'Item excluído do cardápio.', url(ctx, { excluir: null, editar: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const busca = entrada(ctx, 'q');
  const categoriaFiltro = entradaInt(ctx, 'categoria');
  const [itens, categorias] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_cardapio_listar', [busca])),
    ler(ctx, () => bd.chamar('sp_categoria_listar', ['CARDAPIO'])),
  ]);

  const total = itens.length;
  const visiveis = categoriaFiltro ? itens.filter((i) => N(i.categoria_id) === categoriaFiltro) : itens;
  const grupos = {};
  for (const i of visiveis) (grupos[i.categoria] ??= []).push(i);
  const porCategoria = {};
  for (const i of itens) porCategoria[N(i.categoria_id)] = (porCategoria[N(i.categoria_id)] || 0) + 1;

  const editarId = entradaInt(ctx, 'editar');
  const formAberto = ehGerente && ('novo' in ctx.query || editarId > 0);
  const item = editarId > 0 ? await ler(ctx, () => bd.chamarUm('sp_produto_obter', [editarId]), null) : null;
  const i = item || { id: 0, nome: '', descricao: '', preco: '', categoria_id: 0, codigo_barras: '', status: 'ATIVO', controla_estoque: 0 };
  const insumos = formAberto ? await ler(ctx, () => bd.chamar('sp_ficha_insumos')) : [];
  const fichaAtual = formAberto && item ? await ler(ctx, () => bd.chamar('sp_ficha_listar', [editarId])) : [];
  const custoFicha = fichaAtual.reduce((s, f) => s + N(f.custo), 0);
  const categoriasAtivas = categorias.filter((c) => c.status === 'ATIVO' || N(c.id) === N(i.categoria_id));

  const excluirId = entradaInt(ctx, 'excluir');
  const alvoExcluir = ehGerente && excluirId > 0 ? await ler(ctx, () => bd.chamarUm('sp_produto_obter', [excluirId]), null) : null;

  const mostrarCategorias = ehGerente && ['categorias', 'cat', 'cat_novo', 'cat_excluir'].some((k) => k in ctx.query);
  const dadosCat = mostrarCategorias ? await dadosCategorias(ctx, 'CARDAPIO') : null;

  const opcoesInsumo = (atual) => {
    let grupo = null;
    const saida = [h`<option value="0" data-custo="0">— Escolha um insumo —</option>`];
    for (const ins of insumos) {
      if (ins.categoria !== grupo) {
        if (grupo !== null) saida.push(cru('</optgroup>'));
        saida.push(h`<optgroup label="${ins.categoria}">`);
        grupo = ins.categoria;
      }
      saida.push(h`<option value="${N(ins.id)}" data-custo="${ins.preco}"${sel(atual && N(atual.insumo_id) === N(ins.id))}>${ins.nome} (${ins.unidade} · ${moeda(ins.preco)})</option>`);
    }
    if (grupo !== null) saida.push(cru('</optgroup>'));
    return saida;
  };
  const linhasFicha = Math.max(4, fichaAtual.length + 2);

  const vazio = busca ? 'Nenhum item encontrado. Tente outro termo de busca.'
    : ehGerente ? 'O cardápio ainda está vazio. Crie uma categoria e use "Novo item" para começar.' : 'O cardápio ainda está vazio. Peça a um gerente para cadastrar os itens.';

  const conteudo = h`
        <form class="toolbar" method="get" action="cardapios">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar item do cardápio</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="${busca}" placeholder="Buscar item do cardápio" autocomplete="off">
            </label>
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Categoria">
              <a class="filtro${!categoriaFiltro ? ' ativo' : ''}" href="${url(ctx, { categoria: null })}">Todos <span>${total}</span></a>
              ${categorias.filter((c) => porCategoria[N(c.id)]).map((c) => h`<a class="filtro${categoriaFiltro === N(c.id) ? ' ativo' : ''}" href="${url(ctx, { categoria: c.id })}">${c.nome} <span>${porCategoria[N(c.id)]}</span></a>`)}
            </div>
          </div>
          ${ehGerente ? h`
            <div class="toolbar-grupo">
              <a class="btn" href="${url(ctx, { categorias: 1 })}#categorias"><i class="fa-solid fa-tags" aria-hidden="true"></i> Categorias</a>
              <a class="btn btn-primario" href="${url(ctx, { novo: 1, editar: null, excluir: null })}#formulario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo item</a>
            </div>` : ''}
        </form>

        ${alvoExcluir ? h`
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir item</h2>
            <p>Excluir <strong>${alvoExcluir.nome}</strong> do cardápio? Se já houver vendas com ele, será apenas marcado como indisponível.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="${N(alvoExcluir.id)}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="${url(ctx, { excluir: null })}">Cancelar</a>
            </form>
          </section>` : ''}

        ${dadosCat ? painelCategorias(ctx, dadosCat, 'do cardápio') : ''}

        ${formAberto && (editarId === 0 || item) ? h`
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form">${i.id ? 'Editar item' : 'Novo item do cardápio'}</h2><a class="gaveta-fechar" href="${url(ctx, { novo: null, editar: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            ${!categoriasAtivas.length ? h`
              <p class="vazio">Crie uma categoria antes de adicionar itens ao cardápio. <a href="${url(ctx, { categorias: 1, cat_novo: 1, novo: null })}#categorias">Criar categoria</a></p>` : h`
              <form method="post" action="${url(ctx)}">
                ${csrfCampo(ctx)}
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="${N(i.id)}">
                <div class="form-grid">
                  <div class="grupo-input largo">
                    <label for="i-nome">Nome</label>
                    <input type="text" id="i-nome" name="nome" class="input-dash" maxlength="200" required value="${i.nome}">
                  </div>
                  <div class="grupo-input">
                    <label for="i-cat">Categoria</label>
                    <select id="i-cat" name="categoria_id" class="input-dash" required>
                      ${categoriasAtivas.map((c) => h`<option value="${N(c.id)}"${sel(N(c.id) === N(i.categoria_id))}>${c.nome}</option>`)}
                    </select>
                  </div>
                  <div class="grupo-input">
                    <label for="i-preco">Preço (R$)</label>
                    <input type="number" id="i-preco" name="preco" class="input-dash" min="0.01" step="0.01" inputmode="decimal" required value="${i.preco}">
                  </div>
                  <div class="grupo-input largo">
                    <label for="i-desc">Descrição</label>
                    <textarea id="i-desc" name="descricao" class="input-dash" maxlength="255" required>${i.descricao}</textarea>
                  </div>
                  <div class="grupo-input">
                    <label for="i-cod">Código de barras (opcional)</label>
                    <input type="text" id="i-cod" name="codigo_barras" class="input-dash" maxlength="14" inputmode="numeric" value="${i.codigo_barras ?? ''}">
                  </div>
                  <div class="grupo-input">
                    <label for="i-status">Situação</label>
                    <select id="i-status" name="status" class="input-dash">
                      <option value="ATIVO"${sel(i.status === 'ATIVO')}>Disponível</option>
                      <option value="INATIVO"${sel(i.status === 'INATIVO')}>Indisponível</option>
                    </select>
                  </div>
                </div>

                <h3 class="subtitulo-form">Ficha técnica <span class="painel-meta">Insumos usados em uma unidade</span></h3>
                <p class="painel-meta" style="margin:-4px 0 10px">Quando o pedido é entregue, o estoque desses insumos recebe baixa automática. Deixe em branco para itens sem receita.</p>
                <div class="itens-pedido" id="ficha">
                  ${Array.from({ length: linhasFicha }, (_, n) => {
                    const atual = fichaAtual[n] || null;
                    return h`<div class="item-pedido ficha">
                      <select name="ficha_insumo[]" class="input-dash" aria-label="Insumo ${n + 1}">
                        ${opcoesInsumo(atual)}
                      </select>
                      <input type="number" name="ficha_qtd[]" class="input-dash" min="1" max="9999" step="1" value="${atual ? N(atual.quantidade) : 1}" aria-label="Quantidade do insumo ${n + 1}">
                    </div>`;
                  })}
                </div>
                <div class="resumo-compra" aria-live="polite">
                  <div><span>Custo estimado por unidade</span><strong id="ficha-custo">${moeda(custoFicha)}</strong></div>
                  <div><span>Margem sobre o preço</span><strong id="ficha-margem">—</strong></div>
                </div>
                <label class="switch-rotulo" style="margin-top:14px">
                  <span class="switch"><input type="checkbox" name="controla_estoque" value="1"${chk(N(i.controla_estoque) === 1)}><span class="switch-trilho" aria-hidden="true"></span></span>
                  <span class="config-texto"><strong>Item de revenda</strong><small>Sem ficha técnica: cada venda desconta o estoque do próprio item (ex.: refrigerante em lata).</small></span>
                </label>

                <div class="form-acoes">
                  <button type="submit" class="btn btn-primario">${i.id ? 'Salvar alterações' : 'Adicionar ao cardápio'}</button>
                  <a class="btn btn-secundario" href="${url(ctx, { novo: null, editar: null })}" data-fechar>Cancelar</a>
                </div>
              </form>`}
          </section>` : ''}

        <div class="painel-card cardapio">
          ${!Object.keys(grupos).length ? h`<p class="vazio">${vazio}</p>` : ''}
          ${Object.entries(grupos).map(([nomeCategoria, lst], idx) => h`
            <section class="categoria" aria-labelledby="cat-${idx + 1}">
              <header class="categoria-topo">
                <h2 id="cat-${idx + 1}">${nomeCategoria}</h2>
                <span class="painel-meta">${lst.length} ${lst.length === 1 ? 'item' : 'itens'}</span>
              </header>
              <ul class="itens-cardapio">
                ${lst.map((it) => {
                  const disp = it.status === 'ATIVO';
                  const custo = it.custo !== null && it.custo !== undefined ? N(it.custo) : null;
                  const margemItem = custo !== null && N(it.preco) > 0 ? ((N(it.preco) - custo) / N(it.preco)) * 100 : null;
                  const prod = it.produziveis;
                  const temProd = prod !== null && prod !== undefined;
                  return h`
                  <li class="item-cardapio${disp ? '' : ' indisponivel'}">
                    <div class="item-linha">
                      <h3 class="item-nome">${it.nome}</h3>
                      <span class="item-pontos" aria-hidden="true"></span>
                      <span class="item-preco">${moeda(it.preco)}</span>
                    </div>
                    <p class="item-descricao">${it.descricao}</p>
                    <div class="item-meta">
                      ${N(it.insumos) > 0 ? h`<span class="selo" title="Ficha técnica"><i class="fa-solid fa-list-ul" aria-hidden="true"></i> ${N(it.insumos)} insumos</span>` : ''}
                      ${N(it.controla_estoque) === 1 && !N(it.insumos) ? h`<span class="selo"><i class="fa-solid fa-box" aria-hidden="true"></i> Revenda</span>` : ''}
                      ${ehGerente && margemItem !== null ? h`<span class="selo" title="Custo ${moeda(custo)}">Margem ${numero(margemItem, 0)}%</span>` : ''}
                      ${temProd ? h`<span class="status ${N(prod) <= 0 ? 'critico' : N(prod) <= 10 ? 'baixo' : 'ok'}">${N(prod) <= 0 ? 'Sem estoque' : 'Rende ' + N(prod)}</span>` : ''}
                    </div>
                    <div class="item-acoes">
                      <span class="status ${disp ? 'ativo' : 'inativo'}">${disp ? 'Disponível' : 'Indisponível'}</span>
                      ${ehGerente || podeDisponibilidade ? h`
                        <form class="em-linha" method="post" action="${url(ctx)}">
                          ${csrfCampo(ctx)}
                          <input type="hidden" name="acao" value="disponibilidade">
                          <input type="hidden" name="id" value="${N(it.id)}">
                          <input type="hidden" name="disponivel" value="${disp ? '0' : '1'}">
                          <button type="submit" class="btn btn-pequeno btn-fantasma">${disp ? 'Marcar indisponível' : 'Marcar disponível'}<span class="sr-only"> ${it.nome}</span></button>
                        </form>` : ''}
                      ${ehGerente ? h`
                        <a class="btn btn-pequeno btn-fantasma" href="${url(ctx, { editar: it.id, novo: null, excluir: null })}#formulario"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> ${it.nome}</span></a>
                        <a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { excluir: it.id, editar: null, novo: null })}" aria-label="Excluir ${it.nome}"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>` : ''}
                    </div>
                  </li>`;
                })}
              </ul>
            </section>`)}
        </div>`;

  const rodapeExtra = cru(`<script>
  // custo e margem da ficha técnica enquanto o gerente edita
  (() => {
    const ficha = document.getElementById('ficha');
    if (!ficha) return;
    const preco = document.getElementById('i-preco');
    const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    function calcular() {
      let custo = 0;
      ficha.querySelectorAll('.item-pedido').forEach((l) => {
        const op = l.querySelector('select').selectedOptions[0];
        custo += (Number(op?.dataset.custo) || 0) * (Number(l.querySelector('input').value) || 0);
      });
      const p = Number(preco.value) || 0;
      document.getElementById('ficha-custo').textContent = moeda(custo);
      document.getElementById('ficha-margem').textContent = p && custo ? Math.round((p - custo) / p * 100) + '%' : '—';
    }
    ficha.addEventListener('input', calcular);
    ficha.addEventListener('change', calcular);
    preco.addEventListener('input', calcular);
    calcular();
  })();
</script>`);

  return pagina(ctx, { titulo: 'Cardápio', subtitulo: 'Pratos, bebidas e preços disponíveis para venda', paginaAtual: 'cardapios', rodapeExtra }, conteudo);
};
