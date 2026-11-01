'use strict';
const bd = require('../bd');
const sessao = require('../sessao');
const { pagina, corAvatar } = require('../layout');
const {
  h, ler, moeda, entrada, entradaInt, csrfCampo, tratarPost, url, paginacao, documentoBr, telefoneBr, iniciais, sel,
} = require('../web');

const N = (v) => Number(v ?? 0) || 0;
const POR_PAGINA = 10;

module.exports = async function clientes(ctx) {
  const u = await sessao.autenticar(ctx);
  const verFinanceiro = u.perm.financeiro;

  // ---------- ações (POST) ----------
  await tratarPost(ctx, async () => {
    const acao = entrada(ctx, 'acao');
    const id = entradaInt(ctx, 'id');
    if (acao === 'salvar') {
      await bd.chamar('sp_cliente_salvar', [
        id, entrada(ctx, 'nome'), entrada(ctx, 'documento'), entrada(ctx, 'endereco'), entrada(ctx, 'endereco_numero'),
        entrada(ctx, 'telefone'), entrada(ctx, 'contato'), entrada(ctx, 'status', 'ATIVO'), entrada(ctx, 'estado'), entrada(ctx, 'cidade'),
      ]);
      return id ? 'Cliente atualizado.' : 'Cliente cadastrado.';
    }
    if (acao === 'status') {
      const novo = entrada(ctx, 'status') === 'INATIVO' ? 'INATIVO' : 'ATIVO';
      await bd.chamar('sp_cliente_status', [id, novo]);
      return novo === 'ATIVO' ? 'Cliente reativado.' : 'Cliente inativado.';
    }
    if (acao === 'excluir') {
      if (!verFinanceiro) throw new bd.ErroNegocio('Somente administradores e gerentes podem excluir clientes.');
      const r = await bd.chamarValor('sp_cliente_excluir', [id]);
      return [r === 'INATIVADO' ? 'O cliente tem pedidos, então foi apenas inativado para preservar o histórico.' : 'Cliente excluído.', url(ctx, { excluir: null, editar: null })];
    }
    throw new bd.ErroNegocio('Ação não reconhecida.');
  });

  // ---------- dados (GET) ----------
  const busca = entrada(ctx, 'q');
  const status = ['ATIVO', 'INATIVO'].includes(entrada(ctx, 'status')) ? entrada(ctx, 'status') : '';
  let pg = Math.max(1, entradaInt(ctx, 'pagina', 1));

  const contagem = await ler(ctx, () => bd.chamarUm('sp_cliente_contagem', [busca]), { todos: 0, ativos: 0, inativos: 0 });
  const totalFiltro = N(status === 'ATIVO' ? contagem.ativos : status === 'INATIVO' ? contagem.inativos : contagem.todos);
  const paginasTotal = Math.max(1, Math.ceil(totalFiltro / POR_PAGINA));
  pg = Math.min(pg, paginasTotal);

  const [lst, estados] = await Promise.all([
    ler(ctx, () => bd.chamar('sp_cliente_listar', [busca, status, POR_PAGINA, (pg - 1) * POR_PAGINA])),
    ler(ctx, () => bd.chamar('sp_estado_listar')),
  ]);

  const editar = entradaInt(ctx, 'editar');
  const formAberto = 'novo' in ctx.query || editar > 0;
  const cliente = editar > 0 ? await ler(ctx, () => bd.chamarUm('sp_cliente_obter', [editar]), null) : null;
  const c = cliente || { id: 0, nome: '', documento: '', telefone: '', endereco: '', endereco_numero: '', estado: 'SP', cidade: '', contato: '', status: 'ATIVO' };

  const excluirId = entradaInt(ctx, 'excluir');
  const alvoExcluir = excluirId > 0 ? await ler(ctx, () => bd.chamarUm('sp_cliente_obter', [excluirId]), null) : null;

  const filtros = [['', 'Todos', contagem.todos], ['ATIVO', 'Ativos', contagem.ativos], ['INATIVO', 'Inativos', contagem.inativos]];

  const conteudo = h`
        <form class="toolbar" method="get" action="clientes">
          <div class="toolbar-grupo">
            <label class="busca">
              <span class="sr-only">Buscar cliente</span>
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input type="search" name="q" class="input-dash" value="${busca}" placeholder="Buscar por nome, CPF/CNPJ ou telefone" autocomplete="off">
            </label>
            ${status ? h`<input type="hidden" name="status" value="${status}">` : ''}
            <button type="submit" class="btn btn-secundario">Buscar</button>
            <div class="filtros" role="group" aria-label="Situação do cliente">
              ${filtros.map(([valor, rotulo, qtd]) => h`<a class="filtro${status === valor ? ' ativo' : ''}" href="${url(ctx, { status: valor || null, pagina: null, editar: null, novo: null, excluir: null })}">${rotulo} <span>${N(qtd)}</span></a>`)}
            </div>
          </div>
          <a class="btn btn-primario" href="${url(ctx, { novo: 1, editar: null, excluir: null })}#formulario"><i class="fa-solid fa-user-plus" aria-hidden="true"></i> Novo cliente</a>
        </form>

        ${alvoExcluir ? h`
          <section class="painel-card confirmar-card" aria-labelledby="t-excluir">
            <h2 id="t-excluir">Excluir cliente</h2>
            <p>Excluir <strong>${alvoExcluir.nome}</strong>? Se ele já tiver pedidos, será apenas inativado para preservar o histórico.</p>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="excluir">
              <input type="hidden" name="id" value="${N(alvoExcluir.id)}">
              <button type="submit" class="btn btn-perigo"><i class="fa-solid fa-trash" aria-hidden="true"></i> Excluir</button>
              <a class="btn btn-secundario" href="${url(ctx, { excluir: null })}" data-fechar>Cancelar</a>
            </form>
          </section>` : ''}

        ${formAberto && (editar === 0 || cliente) ? h`
          <section class="painel-card form-card" id="formulario" aria-labelledby="t-form">
            <div class="painel-header"><h2 id="t-form">${c.id ? 'Editar cliente' : 'Novo cliente'}</h2><a class="gaveta-fechar" href="${url(ctx, { novo: null, editar: null })}" data-fechar aria-label="Fechar"><i class="fa-solid fa-xmark" aria-hidden="true"></i></a></div>
            <form method="post" action="${url(ctx)}">
              ${csrfCampo(ctx)}
              <input type="hidden" name="acao" value="salvar">
              <input type="hidden" name="id" value="${N(c.id)}">
              <div class="form-grid">
                <div class="grupo-input largo">
                  <label for="c-nome">Nome ou razão social</label>
                  <input type="text" id="c-nome" name="nome" class="input-dash" maxlength="255" required value="${c.nome}">
                </div>
                <div class="grupo-input">
                  <label for="c-doc">CPF ou CNPJ</label>
                  <input type="text" id="c-doc" name="documento" class="input-dash" inputmode="numeric" maxlength="18" required data-mascara="documento" value="${documentoBr(c.documento)}">
                </div>
                <div class="grupo-input">
                  <label for="c-tel">Telefone</label>
                  <input type="tel" id="c-tel" name="telefone" class="input-dash" maxlength="16" placeholder="(11) 98765-4321" required data-mascara="telefone" value="${telefoneBr(c.telefone)}">
                </div>
                <div class="grupo-input largo">
                  <label for="c-end">Endereço (rua e bairro)</label>
                  <input type="text" id="c-end" name="endereco" class="input-dash" maxlength="400" required value="${c.endereco}">
                </div>
                <div class="grupo-input">
                  <label for="c-num">Número</label>
                  <input type="text" id="c-num" name="endereco_numero" class="input-dash" maxlength="20" required value="${c.endereco_numero}">
                </div>
                <div class="grupo-input">
                  <label for="c-uf">Estado</label>
                  <select id="c-uf" name="estado" class="input-dash" required>
                    ${estados.map((uf) => h`<option value="${uf.sigla}"${sel(uf.sigla === c.estado)}>${uf.nome}</option>`)}
                  </select>
                </div>
                <div class="grupo-input largo">
                  <label for="c-cidade">Cidade</label>
                  <input type="text" id="c-cidade" name="cidade" class="input-dash" maxlength="200" required value="${c.cidade}">
                </div>
                <div class="grupo-input">
                  <label for="c-contato">Contato (opcional)</label>
                  <input type="text" id="c-contato" name="contato" class="input-dash" maxlength="255" placeholder="E-mail ou nome do responsável" value="${c.contato ?? ''}">
                </div>
                <div class="grupo-input">
                  <label for="c-status">Situação</label>
                  <select id="c-status" name="status" class="input-dash">
                    <option value="ATIVO"${sel(c.status === 'ATIVO')}>Ativo</option>
                    <option value="INATIVO"${sel(c.status === 'INATIVO')}>Inativo</option>
                  </select>
                </div>
              </div>
              <div class="form-acoes">
                <button type="submit" class="btn btn-primario">${c.id ? 'Salvar alterações' : 'Cadastrar cliente'}</button>
                <a class="btn btn-secundario" href="${url(ctx, { novo: null, editar: null })}" data-fechar>Cancelar</a>
              </div>
            </form>
          </section>` : ''}

        <section class="painel-card painel-tabela" aria-label="Lista de clientes">
          ${!lst.length ? h`<p class="vazio">Nenhum cliente encontrado.</p>` : h`
            <div class="tabela-container" tabindex="0" role="region" aria-label="Tabela de clientes, role para os lados se necessário">
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
                  ${lst.map((cl) => {
                    const inativo = cl.status === 'INATIVO';
                    return h`<tr>
                      <td>
                        <div class="pessoa">
                          <span class="avatar ${corAvatar(cl.nome)}" aria-hidden="true">${iniciais(cl.nome)}</span>
                          <span class="celula-dupla"><strong>${cl.nome}</strong><small>${String(cl.documento).length === 14 ? 'CNPJ' : 'CPF'} ${documentoBr(cl.documento)}</small></span>
                        </div>
                      </td>
                      <td style="white-space:nowrap">${telefoneBr(cl.telefone)}</td>
                      <td>${cl.cidade}, ${cl.estado}</td>
                      <td class="num">${N(cl.pedidos)}</td>
                      <td class="num">${moeda(cl.total_gasto)}</td>
                      <td><span class="status ${inativo ? 'inativo' : 'ativo'}">${inativo ? 'Inativo' : 'Ativo'}</span></td>
                      <td class="acoes">
                        <a class="btn btn-pequeno btn-fantasma" href="${url(ctx, { editar: cl.id, novo: null, excluir: null })}#formulario"><i class="fa-solid fa-pen" aria-hidden="true"></i> Editar<span class="sr-only"> ${cl.nome}</span></a>
                        <form class="em-linha" method="post" action="${url(ctx)}">
                          ${csrfCampo(ctx)}
                          <input type="hidden" name="acao" value="status">
                          <input type="hidden" name="id" value="${N(cl.id)}">
                          <input type="hidden" name="status" value="${inativo ? 'ATIVO' : 'INATIVO'}">
                          <button type="submit" class="btn btn-pequeno btn-fantasma btn-icone" title="${inativo ? 'Reativar' : 'Inativar'} cliente" aria-label="${inativo ? 'Reativar' : 'Inativar'} ${cl.nome}"><i class="fa-solid ${inativo ? 'fa-rotate-left' : 'fa-user-slash'}" aria-hidden="true"></i></button>
                        </form>
                        ${verFinanceiro ? h`<a class="btn btn-pequeno btn-fantasma btn-icone" href="${url(ctx, { excluir: cl.id, editar: null, novo: null })}" title="Excluir cliente" aria-label="Excluir ${cl.nome}"><i class="fa-solid fa-trash" aria-hidden="true"></i></a>` : ''}
                      </td>
                    </tr>`;
                  })}
                </tbody>
              </table>
            </div>
            ${paginacao(ctx, pg, paginasTotal, totalFiltro, POR_PAGINA)}`}
        </section>`;

  return pagina(ctx, { titulo: 'Clientes', subtitulo: 'Quem compra com você e quanto já consumiu', paginaAtual: 'clientes' }, conteudo);
};
