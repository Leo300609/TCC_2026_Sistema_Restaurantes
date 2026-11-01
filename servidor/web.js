'use strict';
/** Utilidades das páginas do painel: HTML seguro, formatação, datas, entrada, flash, CSRF e paginação. */

const crypto = require('crypto');
const bd = require('./bd');

// ---------- HTML ----------

class Cru {
  constructor(s) { this.s = s; }
  toString() { return this.s; }
}
/** Marca um texto como HTML já seguro (não será escapado de novo). */
const cru = (s) => new Cru(String(s ?? ''));

/** Escapa para HTML (equivale ao e() do PHP). */
function e(v) {
  if (v instanceof Cru) return v.s;
  return String(v ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[c]);
}

function renderizar(v) {
  if (v instanceof Cru) return v.s;
  if (Array.isArray(v)) return v.map(renderizar).join('');
  if (v === null || v === undefined || v === false || v === true) return '';
  return e(v);
}

/**
 * Template com escape automático: tudo que for interpolado é escapado, exceto
 * outro h`...` (ou cru()). Listas viram a junção dos itens; null/false somem.
 */
function h(strings, ...valores) {
  let out = strings[0];
  for (let i = 0; i < valores.length; i++) out += renderizar(valores[i]) + strings[i + 1];
  return new Cru(out);
}

// ---------- números e textos ----------

function numeroBr(v, casas = 0) {
  const n = Number(v ?? 0);
  const fixo = (Number.isFinite(n) ? n : 0).toFixed(casas);
  const [inteiro, dec] = fixo.split('.');
  const milhar = inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  return dec !== undefined ? `${milhar},${dec}` : milhar;
}
const moeda = (v) => 'R$ ' + numeroBr(v, 2);
const numero = numeroBr;
const soDigitos = (s) => String(s ?? '').replace(/\D+/g, '');

/** Variação percentual com sinal, ou null quando não há base de comparação. */
function variacao(atual, anterior) {
  atual = Number(atual); anterior = Number(anterior);
  return anterior > 0 ? Math.round(((atual - anterior) / anterior) * 100) : null;
}

function telefoneBr(t) {
  const d = soDigitos(t);
  if (d.length === 11) return `(${d.slice(0, 2)}) ${d.slice(2, 7)}-${d.slice(7)}`;
  if (d.length === 10) return `(${d.slice(0, 2)}) ${d.slice(2, 6)}-${d.slice(6)}`;
  return String(t ?? '');
}
function documentoBr(doc) {
  const d = soDigitos(doc);
  if (d.length === 11) return `${d.slice(0, 3)}.${d.slice(3, 6)}.${d.slice(6, 9)}-${d.slice(9)}`;
  if (d.length === 14) return `${d.slice(0, 2)}.${d.slice(2, 5)}.${d.slice(5, 8)}/${d.slice(8, 12)}-${d.slice(12)}`;
  return String(doc ?? '');
}

// ---------- datas (sempre no fuso de São Paulo, como "texto local") ----------

const p2 = (n) => String(n).padStart(2, '0');
/** "agora" de São Paulo como milissegundos de um relógio sem fuso (use com os métodos getUTC*). */
function agoraMs() {
  const f = new Intl.DateTimeFormat('sv-SE', { timeZone: bd.FUSO, dateStyle: 'short', timeStyle: 'medium' }).format(new Date());
  return Date.parse(f.replace(' ', 'T') + 'Z');
}
/** Converte "AAAA-MM-DD" ou "AAAA-MM-DD HH:MM:SS" (ou ISO) em milissegundos de relógio local. */
function paraMs(s) {
  if (s === null || s === undefined || s === '') return NaN;
  if (typeof s === 'number') return s;
  const t = String(s).replace(' ', 'T').replace(/\.\d+/, '').replace(/(Z|[+-]\d\d:\d\d)$/, '');
  return Date.parse(t.length === 10 ? t + 'T00:00:00Z' : t + 'Z');
}
const DIAS = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
/** Formata como o date() do PHP (d m Y y H i s j n N w G D l M F t). */
function data(formato, ms = agoraMs()) {
  const d = new Date(typeof ms === 'string' ? paraMs(ms) : ms);
  if (Number.isNaN(d.getTime())) return '';
  const Y = d.getUTCFullYear(), M = d.getUTCMonth() + 1, D = d.getUTCDate(), H = d.getUTCHours(), I = d.getUTCMinutes(), S = d.getUTCSeconds(), W = d.getUTCDay();
  const mapa = {
    d: p2(D), j: D, m: p2(M), n: M, Y, y: String(Y).slice(2), H: p2(H), G: H, i: p2(I), s: p2(S),
    N: W === 0 ? 7 : W, w: W, t: new Date(Date.UTC(Y, M, 0)).getUTCDate(),
    D: DIAS[W].slice(0, 3), l: DIAS[W], F: MESES[M - 1], M: MESES[M - 1].slice(0, 3),
  };
  return String(formato).replace(/\\?[a-zA-Z]/g, (c) => (c[0] === '\\' ? c[1] : c in mapa ? mapa[c] : c));
}
const hoje = () => data('Y-m-d');
/** Soma dias a uma data "AAAA-MM-DD" (ou ao agora) e devolve "AAAA-MM-DD". */
const somarDias = (d, n) => data('Y-m-d', paraMs(d || hoje()) + n * 86400000);
/** Soma meses a uma data, travando no último dia do mês quando preciso. */
function somarMeses(d, n) {
  const x = new Date(paraMs(d));
  const alvo = new Date(Date.UTC(x.getUTCFullYear(), x.getUTCMonth() + n, 1));
  const ultimo = new Date(Date.UTC(alvo.getUTCFullYear(), alvo.getUTCMonth() + 1, 0)).getUTCDate();
  alvo.setUTCDate(Math.min(x.getUTCDate(), ultimo));
  return alvo.toISOString().slice(0, 10);
}
const dataBr = (d) => (d ? data('d/m/Y', paraMs(d)) : '–');

// ---------- entrada (corpo do POST e query string) ----------

/** Texto de um campo (POST antes de GET), já sem espaços nas pontas. */
function entrada(ctx, campo, padrao = '') {
  const v = ctx.corpo[campo] ?? ctx.query[campo] ?? padrao;
  return typeof v === 'string' ? v.trim() : padrao;
}
function entradaInt(ctx, campo, padrao = 0) {
  const t = entrada(ctx, campo, String(padrao));
  return /^[+-]?\d+$/.test(t) ? parseInt(t, 10) : padrao;
}
/** Converte "12,50", "1.234,56" ou "12.50" em número. */
function entradaDecimal(ctx, campo) {
  let t = entrada(ctx, campo, '0');
  if (t.includes(',')) t = t.replace(/\./g, '').replace(',', '.');
  const n = Number(t);
  return t !== '' && Number.isFinite(n) ? n : 0;
}
/** Campo repetido (name="x[]"): sempre devolve uma lista de textos. */
function lista(ctx, campo) {
  const v = ctx.corpo[campo] ?? ctx.query[campo] ?? [];
  return (Array.isArray(v) ? v : [v]).map((x) => String(x));
}
const validaData = (d) => /^\d{4}-\d{2}-\d{2}$/.test(d) && !Number.isNaN(paraMs(d));

/** Período dos filtros (?periodo=hoje|7dias|30dias|mes|passado|90dias|ano|personalizado&de=&ate=) → [de, ate, chave]. */
function periodoFiltro(ctx, padrao = 'mes') {
  const chave = entrada(ctx, 'periodo', padrao);
  const de = entrada(ctx, 'de'), ate = entrada(ctx, 'ate');
  if (chave === 'personalizado' && validaData(de) && validaData(ate)) return de <= ate ? [de, ate, chave] : [ate, de, chave];
  const hj = hoje();
  const mesIni = data('Y-m-01');
  const ultimoDia = (ini) => data('Y-m-t', paraMs(ini));
  switch (chave) {
    case 'hoje': return [hj, hj, 'hoje'];
    case '7dias': return [somarDias(hj, -6), hj, '7dias'];
    case '30dias': return [somarDias(hj, -29), hj, '30dias'];
    case '90dias': return [somarDias(hj, -89), hj, '90dias'];
    case 'passado': { const ini = somarMeses(mesIni, -1); return [ini, ultimoDia(ini), 'passado']; }
    case 'ano': return [data('Y-01-01'), data('Y-12-31'), 'ano'];
    default: return [mesIni, ultimoDia(mesIni), 'mes'];
  }
}
const periodosRotulos = () => ({
  hoje: 'Hoje', '7dias': 'Últimos 7 dias', '30dias': 'Últimos 30 dias', mes: 'Este mês',
  passado: 'Mês passado', '90dias': 'Últimos 90 dias', ano: 'Este ano', personalizado: 'Personalizado',
});

// ---------- componentes ----------

/** Mini gráfico de linha em SVG (sem JavaScript) para os cartões de indicadores. */
function sparkline(valores, cor = 'var(--brasa)') {
  valores = valores.map(Number);
  const n = valores.length;
  if (n < 2) return cru('');
  const max = Math.max(...valores) || 1;
  const min = Math.min(...valores);
  const faixa = max - min || 1;
  const r2 = (x) => Math.round(x * 100) / 100;
  const linha = valores.map((v, i) => `${r2((i / (n - 1)) * 100)},${r2(28 - ((v - min) / faixa) * 24)}`).join(' ');
  return cru(`<svg class="sparkline" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true">`
    + `<polygon points="0,30 ${linha} 100,30" fill="${cor}" opacity="0.12"/>`
    + `<polyline pathLength="1" points="${linha}" fill="none" stroke="${cor}" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"/>`
    + `</svg>`);
}
/** Etiqueta "+12%" / "-5%" para comparar com o período anterior. */
function deltaHtml(pct) {
  if (pct === null || pct === undefined) return cru('');
  const sobe = pct >= 0;
  return h`<span class="delta ${sobe ? 'sobe' : 'desce'}"><i class="fa-solid fa-arrow-${sobe ? 'up' : 'down'}" aria-hidden="true"></i>${Math.abs(pct)}%</span>`;
}
const formasPagamento = () => ({
  PIX: ['Pix', 'fa-brands fa-pix'],
  CREDITO: ['Crédito', 'fa-regular fa-credit-card'],
  DEBITO: ['Débito', 'fa-solid fa-credit-card'],
  DINHEIRO: ['Dinheiro', 'fa-solid fa-money-bill-wave'],
  BOLETO: ['Boleto', 'fa-solid fa-barcode'],
});
const iniciais = (nome) => {
  const partes = String(nome || '').trim().split(/\s+/).filter(Boolean);
  const p = (partes[0] || '?').slice(0, 1) + (partes.length > 1 ? partes[partes.length - 1].slice(0, 1) : '');
  return p.toUpperCase();
};

// ---------- sessão: flash e CSRF ----------

function flash(ctx, tipo, mensagem) {
  ctx.sessao.flash = { tipo, mensagem };
}
function flashLer(ctx) {
  const f = ctx.sessao.flash || null;
  delete ctx.sessao.flash;
  return f;
}
const csrfCampo = (ctx) => cru(`<input type="hidden" name="csrf_token" value="${e(ctx.sessao.csrf)}">`);
function csrfOk(ctx) {
  const t = ctx.corpo.csrf_token;
  if (typeof t !== 'string' || !ctx.sessao.csrf) return false;
  const a = Buffer.from(t), b = Buffer.from(ctx.sessao.csrf);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

/** Lançada para responder com um redirecionamento (Padrão POST → redirecionar → GET). */
class Redirecionar extends Error {
  constructor(url, status = 302) { super('redirecionar'); this.url = url; this.status = status; }
}
const redirecionar = (url) => { throw new Redirecionar(url); };

/** URL da própria página com parâmetros (null remove o parâmetro). */
function url(ctx, params = {}) {
  const q = new URLSearchParams();
  const todos = { ...ctx.query, ...params };
  for (const [k, v] of Object.entries(todos)) {
    if (v === null || v === undefined || v === '') continue;
    if (Array.isArray(v)) v.forEach((x) => q.append(k + '[]', x)); else q.append(k, String(v));
  }
  const base = ctx.caminho.split('/').filter(Boolean).pop() || 'dashboard';
  const s = q.toString();
  return base + (s ? '?' + s : '');
}

/**
 * Trata o POST de uma página: valida CSRF, roda o bloco, grava o flash e redireciona.
 * O bloco devolve a mensagem de sucesso (ou [mensagem, url]) e pode lançar ErroNegocio.
 * Em GET não faz nada.
 */
async function tratarPost(ctx, bloco, voltar = null) {
  if (ctx.metodo !== 'POST') return;
  let destino = voltar ?? url(ctx, { editar: null, novo: null, excluir: null, mov: null });
  if (!csrfOk(ctx)) {
    flash(ctx, 'erro', 'Sessão expirada. Tente novamente.');
    redirecionar(destino);
  }
  try {
    const r = await bloco();
    const mensagem = Array.isArray(r) ? String(r[0]) : typeof r === 'string' ? r : '';
    if (Array.isArray(r)) { flash(ctx, 'ok', r[0]); destino = r[1] ?? destino; }
    else if (mensagem) flash(ctx, 'ok', mensagem);
    // auditoria: toda ação concluída fica registrada com a mensagem mostrada ao usuário
    if (mensagem) await require('./sessao').registrarAtividade(ctx, ctx.caminho.split('/').filter(Boolean).pop() || 'painel', entrada(ctx, 'acao'), mensagem);
  } catch (err) {
    if (err instanceof Redirecionar) throw err;
    if (err instanceof bd.ErroNegocio) { flash(ctx, 'erro', err.message); destino = voltar ?? url(ctx); }
    else { console.error('[xtec]', err); flash(ctx, 'erro', 'Não foi possível concluir a operação. Tente novamente em instantes.'); destino = voltar ?? url(ctx); }
  }
  redirecionar(destino);
}

/** Roda uma leitura; se o banco falhar, mostra o aviso e devolve o padrão. */
async function ler(ctx, bloco, padrao = []) {
  try {
    return await bloco();
  } catch (err) {
    console.error('[xtec]', err && err.message);
    ctx.sessao.flash_leitura = 'Não foi possível ler os dados agora. Tente atualizar a página em instantes.';
    return padrao;
  }
}

// ---------- paginação ----------

function paginas(atual, total) {
  const set = new Set([1, atual - 1, atual, atual + 1, total].filter((n) => n >= 1 && n <= total));
  return [...set].sort((a, b) => a - b);
}
function paginacao(ctx, atual, total, quantidade, porPagina, param = 'pagina') {
  if (total <= 1) return cru('');
  const de = (atual - 1) * porPagina + 1;
  const ate = Math.min(quantidade, atual * porPagina);
  let anterior = 0;
  const numeros = paginas(atual, total).map((n) => {
    const reticencias = anterior && n - anterior > 1 ? cru('<span class="paginacao-reticencias" aria-hidden="true">…</span>') : '';
    anterior = n;
    return h`${reticencias}<a class="btn btn-secundario${n === atual ? ' ativo' : ''}" href="${url(ctx, { [param]: n })}"${n === atual ? cru(' aria-current="page"') : ''}>${n}</a>`;
  });
  return h`<nav class="paginacao" aria-label="Paginação"><span class="paginacao-info">${de}–${ate} de ${quantidade}</span><div class="paginacao-botoes">${atual > 1 ? h`<a class="btn btn-secundario" href="${url(ctx, { [param]: atual - 1 })}">Anterior</a>` : ''}${numeros}${atual < total ? h`<a class="btn btn-secundario" href="${url(ctx, { [param]: atual + 1 })}">Próxima</a>` : ''}</div></nav>`;
}

/** JSON seguro para dentro de <script> (não deixa fechar a tag). */
const jsonSeguro = (o) => JSON.stringify(o).replace(/</g, String.fromCharCode(92) + 'u003c');

/** Atributos booleanos prontos: ${sel(cond)} ${chk(cond)} ${dis(cond)}. */
const sel = (c) => (c ? new Cru(" selected") : "");
const chk = (c) => (c ? new Cru(" checked") : "");
const dis = (c) => (c ? new Cru(" disabled") : "");

/** Valor de um campo do jsonb que o banco devolve como número, sempre numérico. */
const num = (v) => Number(v ?? 0) || 0;

module.exports = {
  Cru, cru, e, h, moeda, numero, numeroBr, soDigitos, variacao, telefoneBr, documentoBr,
  agoraMs, paraMs, data, hoje, somarDias, somarMeses, dataBr, DIAS, MESES,
  entrada, entradaInt, entradaDecimal, lista, validaData, periodoFiltro, periodosRotulos,
  sparkline, deltaHtml, formasPagamento, iniciais,
  flash, flashLer, csrfCampo, csrfOk, Redirecionar, redirecionar, url, tratarPost, ler,
  paginas, paginacao, num, jsonSeguro, sel, chk, dis,
};
