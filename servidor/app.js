'use strict';
/**
 * Entrada de todas as requisições dinâmicas (Vercel e servidor local):
 *   /painel/<tela>   telas do painel (HTML gerado no servidor)
 *   /auth/...        login, logout e cadastro com convite
 *   /api/...         JSON do painel (notificações e busca global)
 *   /relatorios/...  exportações (PDF, Excel, Word e CSV)
 */
const sessao = require('./sessao');
const { Redirecionar, Cru } = require('./web');
const config = require('./config');

const PAGINAS = new Set(['dashboard', 'pdv', 'pedidos', 'mesas', 'cozinha', 'cardapios', 'estoque', 'compras', 'fornecedores',
  'clientes', 'fluxo_de_caixa', 'relatorios', 'previsoes', 'empresa', 'atividades', 'configs', 'comprovante']);

/** Converte "a[]=1&a[]=2&b=3" em { a: ['1','2'], b: '3' } (nomes com [] viram listas). */
function parseParametros(origem) {
  const out = {};
  const pares = origem instanceof URLSearchParams
    ? [...origem.entries()]
    : Object.entries(origem || {}).flatMap(([k, v]) => (Array.isArray(v) ? v.map((x) => [k, x]) : [[k, v]]));
  for (const [k, v] of pares) {
    if (k.endsWith('[]')) { const n = k.slice(0, -2); (out[n] ??= []).push(String(v)); }
    else out[k] = String(v);
  }
  return out;
}

async function lerCorpo(req) {
  if (req.method === 'GET' || req.method === 'HEAD') return {};
  const tipo = String(req.headers['content-type'] || '');
  if (req.body !== undefined && req.body !== null) {
    if (typeof req.body === 'object' && !Buffer.isBuffer(req.body)) return parseParametros(req.body);
    const t = Buffer.isBuffer(req.body) ? req.body.toString('utf8') : String(req.body);
    return tipo.includes('json') ? parseParametros(JSON.parse(t || '{}')) : parseParametros(new URLSearchParams(t));
  }
  const partes = [];
  let total = 0;
  for await (const p of req) {
    total += p.length;
    if (total > 1_000_000) throw new Error('corpo grande demais');
    partes.push(p);
  }
  const t = Buffer.concat(partes).toString('utf8');
  return tipo.includes('json') ? parseParametros(JSON.parse(t || '{}')) : parseParametros(new URLSearchParams(t));
}

function resolver(caminho) {
  const p = caminho.replace(/\/+$/, '') || '/';
  let m;
  if ((m = p.match(/^\/painel\/([a-z_]+)$/)) && PAGINAS.has(m[1])) return { tipo: 'pagina', modulo: './paginas/' + m[1] };
  if (p === '/painel') return { tipo: 'redirecionar', para: '/painel/dashboard' };
  if ((m = p.match(/^\/auth\/(login|logout|cadastro)$/))) return { tipo: 'rota', modulo: './auth', acao: m[1] };
  if ((m = p.match(/^\/api\/(notificacoes|busca)$/))) return { tipo: 'rota', modulo: './api', acao: m[1] };
  if ((m = p.match(/^\/relatorios\/(pdf|excel|word|csv)$/))) return { tipo: 'rota', modulo: './relatorios', acao: m[1] };
  return null;
}

function enviar(ctx, status, tipo, corpo, extras = {}) {
  const { res, req } = ctx;
  res.statusCode = status;
  res.setHeader('Content-Type', tipo);
  res.setHeader('Cache-Control', 'no-store');
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  res.setHeader('Set-Cookie', ctx.encerrarSessao ? sessao.cookieLimpo(req) : sessao.cookie(ctx.sessao, req));
  for (const [k, v] of Object.entries(extras)) res.setHeader(k, v);
  res.end(corpo);
}

const PAGINA_ERRO = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Erro</title><body style="font-family:system-ui,sans-serif;padding:3rem"><h1>Algo deu errado</h1><p>Não foi possível abrir esta tela agora. <a href="javascript:history.back()">Voltar</a></p>';
const PAGINA_404 = '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Não encontrado</title><body style="font-family:system-ui,sans-serif;padding:3rem"><h1>Página não encontrada</h1><p><a href="/">Voltar ao início</a></p>';

async function handler(req, res) {
  const u = new URL(req.url, 'http://localhost');
  const ctx = {
    req, res,
    metodo: req.method,
    caminho: u.pathname,
    query: parseParametros(u.searchParams),
    corpo: {},
    sessao: sessao.carregar(req),
    u: null,
    config,
  };
  const alvo = resolver(ctx.caminho);
  if (!alvo) { enviar(ctx, 404, 'text/html; charset=utf-8', PAGINA_404); return; }
  try {
    if (alvo.tipo === 'redirecionar') throw new Redirecionar(alvo.para);
    ctx.corpo = await lerCorpo(req);
    const modulo = require(alvo.modulo);
    const r = alvo.tipo === 'pagina' ? await modulo(ctx) : await modulo[alvo.acao](ctx);
    if (r instanceof Cru) enviar(ctx, 200, 'text/html; charset=utf-8', r.s);
    else if (r && r.json !== undefined) enviar(ctx, r.status || 200, 'application/json; charset=utf-8', JSON.stringify(r.json));
    else if (r && r.corpo !== undefined) enviar(ctx, r.status || 200, r.tipo, r.corpo, r.cabecalhos || {});
    else enviar(ctx, 204, 'text/plain', '');
  } catch (err) {
    if (err instanceof Redirecionar) {
      res.setHeader('Location', err.url);
      enviar(ctx, err.status || 302, 'text/plain; charset=utf-8', '');
    } else if (err && err.api) {
      enviar(ctx, 401, 'application/json; charset=utf-8', JSON.stringify({ erro: 'Sessão expirada. Entre novamente.' }));
    } else {
      console.error('[xtec]', err);
      enviar(ctx, 500, 'text/html; charset=utf-8', PAGINA_ERRO);
    }
  }
}

module.exports = { handler, parseParametros };
