'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina } = require('../layout');
const { tratarCategorias, dadosCategorias, painelCategorias } = require('../categorias');
const {
  h, cru, ler, moeda, dataBr, entrada, entradaInt, entradaDecimal, csrfCampo, tratarPost, url, paginacao, sel,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const POR_PAGINA = 12;
const SITUACOES = {
  OK: ['ok', 'Em dia'], CRITICO: ['critico', 'Abaixo do mínimo'], VENCIDO: ['critico', 'Vencido'],
  VENCENDO: ['baixo', 'Vence em breve'], INATIVO: ['inativo', 'Inativo'],
};

module.exports = async function estoque(ctx) {
  const u = await sessao.autenticar(ctx);
  sessao.exigirPermissao(ctx, u.perm.estoque);
  const ehGerente = u.perm.financeiro;

  // ---------- ações (POST) ----------
  await tratarCategorias(ctx, 'INSUMO', ehGerente);

  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    const id = entradaInt(ctx, 'id');

    if (acao === 'salvar') {
      const validade = entrada(ctx, 'validade') || null;
      await bd.chamar('sp_produto_salvar', [
        id, 'ESTOQUE', entrada(ctx, 'nome'), entrada(ctx, 'descricao'), entradaDecimal(ctx, 'preco'), entradaInt(ctx, 'categoria_id'),
        entrada(ctx, 'status', 'ATIVO'), entrada(ctx, 'codigo_barras'), entrada(ctx, 'ncm'), entrada(ctx, 'unidade'),
        entradaInt(ctx, 'estoque_minimo'), validade, entradaInt(ctx, 'estoque'), u.id,
      ]);
      return [id ? 'Item atualizado.' : 'Item cadastrado no estoque.', url(ctx, { editar: null, novo: null })];
    }
    if (acao === 'movimentar') {
      const tipo = entrada(ctx, 'tipo') === 'SAIDA' ? 'SAIDA' : 'ENTRADA';
      const novo = await bd.chamarValor('sp_estoque_movimentar', [entradaInt(ctx, 'produto_id'), tipo, entradaInt(ctx, 'quantidade'), entrada(ctx, 'observacao'), u.id]);
      return [`${tipo === 'ENTRADA' ? 'Entrada registrada' : 'Saída registrada'}. Estoque atual: ${N(novo)}.`, url(ctx, { mov: null, produto: null })];
    }
    if (acao === 'excluir') {
      if (!ehGerente) throw new bd.ErroNegocio('Somente administradores e gerentes podem excluir itens.');
      const r = await bd.chamarValor('sp_produto_excluir', [id, 'ESTOQUE']);
      return [r === 'INATIVADO' ? 'O item tem vendas ou movimentações no histórico, então foi inativado em vez de excluído.' : 'Item excluído do estoque.', url(ctx, { excluir: null, editar: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const cfg = await ler(ctx, () => bd.chamarUm('sp_config_listar', [u.id]), { dias_validade: 3 });
  const dias = Math.min(60, Math.max(0, N(cfg.dias_validade)));

  const busca = entrada(ctx, 'q');
  const situacao = ['CRITICO', 'VENCENDO', 'OK'].includes(entrada(ctx, 'situacao')) ? entrada(ctx, 'situacao') : 'TODOS';
  const categoriaFiltro = entradaInt(ctx, 'categoria');
  let pg = Math.max(1, entradaInt(ctx, 'pagina', 1));

  const [resumo, contagem] = await Promise.all([
    ler(ctx, () => bd.chamarUm('sp_estoque_resumo', [dias]), { itens: 0, categorias: 0, abaixo_minimo: 0, vencendo: 0, valor_estoque: 0 }),
    ler(ctx, () => bd.chamarUm('sp_estoque_contagem', [busca, categoriaFiltro, dias]), { todos: 0, critico: 0, vencendo: 0, ok: 0 }),
  ]);
  const chaveSit = { TODOS: 'todos', CRITICO: 'critico', VENCENDO: 'vencendo', OK: 'ok' }[situacao];
  const totalFiltro = N(contagem[chaveSit]);
  const paginasTotal = Math.max(1, Math.ceil(totalFiltro / POR_PAGINA));
  pg = Math.min(pg, paginasTotal);

  const [itens, movimentos, categoriasTodas] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_estoque_listar', [busca, situacao, categoriaFiltro, dias, POR_PAGINA, (pg - 1) * POR_PAGINA])),
    ler(ctx, () => bd.chamar('sp_estoque_movimentos_hoje')),
    ler(ctx, () => bd.chamar('sp_categoria_listar', [''])),
  ]);
  const categoriasAtivas = categoriasTodas.filter((c) => c.status === 'ATIVO');

  // formulários
  const editarId = entradaInt(ctx, 'editar');
  const formAberto = 'novo' in ctx.query || editarId > 0;
  const item = editarId > 0 ? await ler(ctx, () => bd.chamarUm('sp_produto_obter', [editarId]), null) : null;
  const i = item || { id: 0, nome: '', descricao: '', preco: '0', categoria_id: 0, codigo_barras: '', ncm: '', unidade: 'UN', estoque_minimo: 0, estoque: 0, validade: '', status: 'ATIVO' };

  const mov = ['ENTRADA', 'SAIDA'].includes(entrada(ctx, 'mov')) ? entrada(ctx, 'mov') : '';
  const produtosMov = mov ? await ler(ctx, () => bd.chamar('sp_estoque_produtos_ativos')) : [];
  const produtoSel = entradaInt(ctx, 'produto');

  const excluirId = entradaInt(ctx, 'excluir');
  const alvoExcluir = ehGerente && excluirId > 0 ? await ler(ctx, () => bd.chamarUm('sp_produto_obter', [excluirId]), null) : null;

  const mostrarCategorias = ehGerente && ['categorias', 'cat', 'cat_novo', 'cat_excluir'].some((k) => k in ctx.query);
  const dadosCat = mostrarCategorias ? await dadosCategorias(ctx, 'INSUMO') : null;

  const filtrosSit = [['TODOS', 'Todos', contagem.todos], ['CRITICO', 'Críticos', contagem.critico], ['VENCENDO', 'Vencendo', contagem.vencendo], ['OK', 'Em dia', contagem.ok]];
  const validadeI = i.validade ? String(i.validade).slice(0, 10) : '';

  const conteudo = h`
        <section class="grid-cards" aria-label="Resumo do estoque">
          <div class="card-metrica">
            <span class="card-titulo">Itens ativos</span>
            <div class="card-valor">${N(resumo.itens)}</div>
            <span class="card-detalhe neutro">${N(resumo.categorias)} categorias</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Abaixo do mínimo</span>
            <div class="card-valor">${N(resumo.abaixo_minimo)}</div>
            <span class="card-detalhe alerta">Repor com urgência</span>
          </div>
          <div class="card-metrica alerta-box">
            <span class="card-titulo">Vencimento próximo</span>
            <div class="card-valor">${N(resumo.vencendo)}</div>
            <span class="card-detalhe alerta">Em até ${dias} dias · use primeiro</span>
          </div>
          <div class="card-metrica">
            <span class="card-titulo">Valor em estoque</span>
            <div class="card-valor">${moeda(resumo.valor_estoque)}</div>
            <span class="card-detalhe neutro">quantidade × preço unitário</span>
          </div>
        </section>

        <form class="toolbar" method="get" action="estoque">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar produto</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="${busca}" placeholder="Buscar por nome ou código de barras" autocomplete="off">
            </label>
            <div class="grupo-input seletor">
              <label class="sr-only" for="categoria">Categoria</label>
              <select id="categoria" class="input-dash" name="categoria">
                <option value="0">Todas as categorias</option>
                ${categoriasTodas.map((c) => h`<option value="${N(c.id)}"${sel(categoriaFiltro === N(c.id))}>${c.nome}</option>`)}
              </select>
            </div>
            ${situacao !== 'TODOS' ? h`<input type="hidden" name="situacao" value="${situacao}">` : ''}
            <button type="submit" class="btn btn-secundario">Filtrar</button>
            <div class="filtros" role="group" aria-label="Situação do estoque">
              ${filtrosSit.map(([valor, rotulo, qtd]) => h`<a class="filtro${situacao === valor ? ' ativo' : ''}" href="${url(ctx, { situacao: valor === 'TODOS' ? null : valor, pagina: null })}">${rotulo} <span>${N(qtd)}</span></a>`)}
            </div>
          </div>
          <div class="toolbar-grupo">
            ${ehGerente ? h`<a class="btn" href="${url(ctx, { categorias: 1 })}#categorias"><i class="fa-solid fa-tags" aria-hidden="true"></i> Categorias</a>` : ''}
            <a class="btn" href="${url(ctx, { mov: 'SAIDA', novo: null, editar: null })}#movimento"><i class="fa-solid fa-arrow-up-from-bracket" aria-hidden="true"></i> Registrar saída</a>
            <a class="btn" href="${url(ctx, { mov: 'ENTRADA', novo: null, editar: null })}#movimento"><i class="fa-solid fa-download" aria-hidden="true"></i> Registrar entrada</a>
            <a class="btn btn-primario" href="${url(ctx, { novo: 1, editar: null, mov: null })}#formulario"><i class="fa-solid fa-plus" aria-hidden="true"></i> Novo item</a>
          </div>
        </form>

        ${alvoExcluir ? h`
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir item</h2>
            <p>Excluir <strong>${alvoExcluir.nome}</strong> do estoque? Se houver vendas, compras ou movimentações, ele será apenas inativado.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="${N(alvoExcluir.id)}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="${url(ctx, { excluir: null })}">Cancelar</a>
            </form>
          </section>` : ''}

        ${dadosCat ? painelCategorias(ctx, dadosCat, 'de insumos') : ''}

        ${mov ? h`
          <section class="painel-card form-card" id="movimento" aria-labelledby="t-mov">
            <div class="painel-header"><h2 id="t-mov">${mov === 'ENTRADA' ? 'Registrar entrada' : 'Registrar saída'}</h2><a class="gaveta-fechar" href="${url(ctx, { mov: null, produto: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="movimentar">
              <input type="hidden" name="tipo" value="${mov}">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="m-produto">Produto</label>
                  <select id="m-produto" name="produto_id" class="input-dash" required>
                    <option value="">Selecione...</option>
                    ${produtosMov.map((p) => h`<option value="${N(p.id)}"${sel(produtoSel === N(p.id))}>${p.nome} (${N(p.estoque)} ${p.unidade})</option>`)}
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="m-qtd">Quantidade</label>
                  <input type="number" id="m-qtd" name="quantidade" class="input-dash" min="1" step="1" inputmode="numeric" required>
                </div>
                <div class="grupo-input">
                  <label for="m-obs">Observação (opcional)</label>
                  <input type="text" id="m-obs" name="observacao" class="input-dash" maxlength="255" placeholder="${mov === 'ENTRADA' ? 'Ex.: Compra #214' : 'Ex.: Produção do jantar'}">
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario">${mov === 'ENTRADA' ? 'Registrar entrada' : 'Registrar saída'}</button>
                <a class="btn btn-secundario" href="${url(ctx, { mov: null, produto: null })}">Cancelar</a>
              </div>
            </form>
          </section>` : ''}

        ${formAberto && (editarId === 0 || item) ? h`
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form">${i.id ? 'Editar item do estoque' : 'Novo item do estoque'}</h2><a class="gaveta-fechar" href="${url(ctx, { novo: null, editar: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="salvar">
              <input type="hidden" name="id" value="${N(i.id)}">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="e-nome">Nome</label>
                  <input type="text" id="e-nome" name="nome" class="input-dash" maxlength="200" required value="${i.nome}">
                </div>
                <div class="grupo-input">
                  <label for="e-cat">Categoria</label>
                  <select id="e-cat" name="categoria_id" class="input-dash" required>
                    ${[['CARDAPIO', 'Itens do cardápio'], ['INSUMO', 'Insumos e materiais']].map(([tipoCat, rotuloGrupo]) => {
                      const opcoes = categoriasAtivas.filter((c) => c.tipo === tipoCat);
                      return opcoes.length ? h`<optgroup label="${rotuloGrupo}">
                        ${opcoes.map((c) => h`<option value="${N(c.id)}"${sel(N(c.id) === N(i.categoria_id))}>${c.nome}</option>`)}
                      </optgroup>` : '';
                    })}
                  </select>
                </div>
                <div class="grupo-input">
                  <label for="e-un">Unidade de medida</label>
                  <input type="text" id="e-un" name="unidade" class="input-dash" maxlength="6" placeholder="UN, KG, L..." required value="${i.unidade}">
                </div>
                ${!i.id ? h`
                  <div class="grupo-input">
                    <label for="e-est">Quantidade inicial</label>
                    <input type="number" id="e-est" name="estoque" class="input-dash" min="0" step="1" value="0">
                  </div>` : ''}
                <div class="grupo-input">
                  <label for="e-min">Estoque mínimo</label>
                  <input type="number" id="e-min" name="estoque_minimo" class="input-dash" min="0" step="1" required value="${N(i.estoque_minimo)}">
                </div>
                <div class="grupo-input">
                  <label for="e-val">Validade (opcional)</label>
                  <input type="date" id="e-val" name="validade" class="input-dash" value="${validadeI}">
                </div>
                <div class="grupo-input">
                  <label for="e-preco">Preço unitário (R$)</label>
                  <input type="number" id="e-preco" name="preco" class="input-dash" min="0" step="0.01" inputmode="decimal" required value="${i.preco}">
                </div>
                <div class="grupo-input">
                  <label for="e-cod">Código de barras (opcional)</label>
                  <input type="text" id="e-cod" name="codigo_barras" class="input-dash" maxlength="14" inputmode="numeric" value="${i.codigo_barras ?? ''}">
                </div>
                <div class="grupo-input">
                  <label for="e-ncm">NCM (opcional)</label>
                  <input type="text" id="e-ncm" name="ncm" class="input-dash" maxlength="8" inputmode="numeric" value="${i.ncm ?? ''}">
                </div>
                <div class="grupo-input largo">
                  <label for="e-desc">Descrição</label>
                  <textarea id="e-desc" name="descricao" class="input-dash" maxlength="255" required>${i.descricao}</textarea>
                </div>
                <div class="grupo-input">
                  <label for="e-status">Situação</label>
                  <select id="e-status" name="status" class="input-dash">
                    <option value="ATIVO"${sel(i.status === 'ATIVO')}>Ativo</option>
                    <option value="INATIVO"${sel(i.status === 'INATIVO')}>Inativo</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario">${i.id ? 'Salvar alterações' : 'Cadastrar item'}</button>
                <a class="btn btn-secundario" href="${url(ctx, { novo: null, editar: null })}">Cancelar</a>
                ${i.id && ehGerente ? h`<a class="btn btn-perigo" href="${url(ctx, { excluir: i.id, editar: null })}"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</a>` : ''}
              </div>
            </form>
          </section>` : ''}

        <div class="estoque-grid">
          <section class="painel-card painel-tabela" aria-label="Produtos em estoque">
            ${!itens.length ? h`<p class="vazio">${busca || situacao !== 'TODOS' || categoriaFiltro ? 'Nenhum item encontrado. Tente outra busca ou outro filtro.' : 'Nenhum item no estoque. Use "Novo item" para cadastrar o primeiro.'}</p>` : h`
              <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de produtos, role para os lados se necessário">
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
                    ${itens.map((it) => {
                      const [classe, texto] = SITUACOES[it.situacao] || SITUACOES.OK;
                      const critico = ['CRITICO', 'VENCIDO'].includes(it.situacao);
                      return h`<tr>
                        <td><span class="celula-dupla"><strong>${it.nome}</strong><small>${it.categoria}</small></span></td>
                        <td>
                          <div class="nivel${critico ? ' critico' : ''}" style="--nivel: ${N(it.nivel)}%">
                            <span class="nivel-valor">${N(it.estoque)} ${it.unidade}</span>
                            <span class="nivel-barra" aria-hidden="true"><span></span></span>
                          </div>
                        </td>
                        <td class="num">${N(it.estoque_minimo)} ${it.unidade}</td>
                        <td class="data">${it.validade ? dataBr(it.validade) : 'Sem validade'}</td>
                        <td><span class="status ${classe}">${texto}</span></td>
                        <td class="acoes">
                          ${it.status === 'ATIVO' ? h`<a class="btn btn-pequeno${critico ? '' : ' btn-fantasma'}" href="${url(ctx, { mov: 'ENTRADA', produto: it.id, novo: null, editar: null })}#movimento">Repor<span class="sr-only"> ${it.nome}</span></a>` : ''}
                          <a class="btn btn-pequeno btn-fantasma" href="${url(ctx, { editar: it.id, novo: null, mov: null })}#formulario"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> ${it.nome}</span></a>
                        </td>
                      </tr>`;
                    })}
                  </tbody>
                </table>
              </div>
              ${paginacao(ctx, pg, paginasTotal, totalFiltro, POR_PAGINA)}`}
          </section>

          <section class="painel-card">
            <div class="painel-header"><h2>Movimentações de hoje</h2></div>
            <ul class="movimentos">
              ${!movimentos.length ? h`<li class="movimento"><span class="celula-dupla"><strong>Sem movimentações hoje</strong><small>Entradas e saídas aparecem aqui.</small></span></li>` : ''}
              ${movimentos.map((m) => h`<li class="movimento">
                  <span class="movimento-qtd ${m.tipo === 'ENTRADA' ? 'entrada' : 'saida'}">${m.tipo === 'ENTRADA' ? '+' : '−'}${N(m.quantidade)} ${m.unidade}</span>
                  <span class="celula-dupla"><strong>${m.produto}</strong><small>${m.usuario} · ${m.hora}${m.observacao ? ' · ' + m.observacao : ''}</small></span>
                </li>`)}
            </ul>
          </section>
        </div>`;

  return pagina(ctx, { titulo: 'Estoque', subtitulo: 'Insumos, validade e níveis mínimos', paginaAtual: 'estoque' }, conteudo);
};
