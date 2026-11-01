'use strict';
/** APIs internas do painel (JSON): notificações e busca global (Ctrl + K). */
const bd = require('./bd');
const sessao = require('./sessao');

async function notificacoes(ctx) {
  const u = await sessao.autenticar(ctx, { api: true });
  try {
    const itens = await bd.chamar('sp_notificacoes', [u.id, u.perm.estoque ? 1 : 0, u.perm.financeiro ? 1 : 0]);
    return {
      json: {
        total: itens.length,
        itens: itens.slice(0, 25).map((n) => ({ tipo: n.tipo, nivel: n.nivel, titulo: n.titulo, detalhe: n.detalhe, link: n.link })),
      },
    };
  } catch (err) {
    console.error('[xtec] notificacoes:', err.message);
    return { status: 500, json: { erro: 'Não foi possível carregar as notificações.', total: 0, itens: [] } };
  }
}

async function busca(ctx) {
  const u = await sessao.autenticar(ctx, { api: true });
  const termo = String(ctx.query.q || '').trim().slice(0, 100);
  if ([...termo].length < 2) return { json: { itens: [] } };
  try {
    const itens = (await bd.chamar('sp_busca_global', [termo, u.perm.financeiro ? 1 : 0])).map((r) => {
      let link;
      if (r.tipo === 'CLIENTE') link = 'clientes?editar=' + Number(r.id);
      else if (r.tipo === 'PEDIDO') link = ['NOVO', 'PREPARO', 'PRONTO'].includes(r.extra) ? `pedidos?editar=${Number(r.id)}#formulario` : 'pedidos?detalhes=' + Number(r.id);
      else if (r.tipo === 'FORNECEDOR') link = 'fornecedores?editar=' + Number(r.id);
      else if (r.extra === 'INSUMO') link = u.perm.estoque ? 'estoque?editar=' + Number(r.id) : 'estoque';
      else link = 'cardapios?q=' + encodeURIComponent(r.titulo);
      return { tipo: r.tipo, titulo: r.titulo, detalhe: r.detalhe, link };
    });
    return { json: { itens } };
  } catch (err) {
    console.error('[xtec] busca:', err.message);
    return { status: 500, json: { erro: 'Não foi possível buscar agora.', itens: [] } };
  }
}

module.exports = { notificacoes, busca };
