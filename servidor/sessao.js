'use strict';
/**
 * Sessão segura (cookie assinado, HttpOnly/SameSite), permissões por perfil e
 * registro de atividades (auditoria).
 */
const crypto = require('crypto');
const config = require('./config');
const bd = require('./bd');
const { Redirecionar } = require('./web');

const COOKIE = 'XTECSESS';

function segredo() {
  if (process.env.SESSION_SECRET) return process.env.SESSION_SECRET;
  return crypto.createHash('sha256').update('xtec:' + (process.env.DATABASE_URL || process.env.POSTGRES_URL || 'desenvolvimento')).digest('hex');
}
const assinar = (texto) => crypto.createHmac('sha256', segredo()).update(texto).digest('base64url');

function lerCookies(req) {
  const out = {};
  for (const par of String(req.headers.cookie || '').split(';')) {
    const i = par.indexOf('=');
    if (i > 0) out[par.slice(0, i).trim()] = decodeURIComponent(par.slice(i + 1).trim());
  }
  return out;
}

/** Lê a sessão do cookie; se faltar ou for inválida, começa uma nova (com token CSRF). */
function carregar(req) {
  const bruto = lerCookies(req)[COOKIE];
  if (bruto) {
    const [corpo, assinatura] = bruto.split('.');
    if (corpo && assinatura) {
      const esperado = assinar(corpo);
      const a = Buffer.from(assinatura), b = Buffer.from(esperado);
      if (a.length === b.length && crypto.timingSafeEqual(a, b)) {
        try {
          const s = JSON.parse(Buffer.from(corpo, 'base64url').toString('utf8'));
          if (s && typeof s === 'object') return s;
        } catch (_) { /* cai na sessão nova */ }
      }
    }
  }
  return { csrf: crypto.randomBytes(32).toString('hex') };
}

/** Cabeçalho Set-Cookie com a sessão atual. */
function cookie(sessao, req) {
  const corpo = Buffer.from(JSON.stringify(sessao)).toString('base64url');
  const seguro = req.headers['x-forwarded-proto'] === 'https' || req.socket?.encrypted;
  return `${COOKIE}=${encodeURIComponent(corpo + '.' + assinar(corpo))}; Path=/; HttpOnly; SameSite=Lax${seguro ? '; Secure' : ''}`;
}
const cookieLimpo = (req) => `${COOKIE}=; Path=/; HttpOnly; SameSite=Lax; Max-Age=0${req.headers['x-forwarded-proto'] === 'https' ? '; Secure' : ''}`;

function ipCliente(req) {
  const xf = String(req.headers['x-forwarded-for'] || '').split(',')[0].trim();
  return (xf || req.socket?.remoteAddress || '').replace(/^::ffff:/, '').slice(0, 45);
}

/** Permissões de cada perfil. ADMIN vê tudo; o cargo define o restante. */
function permissoes(nivel, cargo) {
  const admin = nivel === 'ADMIN';
  const gerente = admin || cargo === 'Gerente';
  const cozinha = cargo === 'Cozinheiro';
  const entregador = cargo === 'Entregador';
  return {
    admin,
    gerente,
    financeiro: gerente,
    estoque: gerente || cozinha,
    compras: gerente,
    relatorios: gerente,
    auditoria: gerente,
    pdv: !cozinha && !entregador,
    mesas: !cozinha && !entregador,
    cozinha: !entregador,
  };
}

/** Rótulo amigável do cargo. */
function rotuloCargo(cargo, nivel = '') {
  if (cargo === 'ADMIN' || (cargo === 'FUNCIONARIO' && nivel === 'ADMIN')) return 'Administrador';
  if (cargo === 'FUNCIONARIO') return 'Funcionário';
  return cargo;
}

/** Grava uma linha na auditoria. Nunca interrompe a página: se falhar, só vai para o log. */
async function registrarAtividade(ctx, modulo, acao, descricao, usuarioId = null, usuarioNome = null) {
  try {
    await bd.chamar('sp_atividade_registrar', [
      usuarioId ?? Number(ctx.sessao.usuario_id || 0),
      usuarioNome ?? String(ctx.sessao.usuario_nome || ''),
      modulo, acao, String(descricao).slice(0, 500), ipCliente(ctx.req),
    ]);
  } catch (err) {
    console.error('[xtec] auditoria:', err.message);
  }
}

/**
 * Guarda de acesso do painel: exige login, expira a sessão por inatividade e
 * confere no banco, a cada minuto, se o usuário continua ativo. Devolve
 * { nivel, cargo, nome, perm }.
 */
async function autenticar(ctx, { api = false } = {}) {
  const s = ctx.sessao;
  const negar = (url, erro) => {
    if (api) { const e = new Error('nao_autenticado'); e.api = true; throw e; }
    throw new Redirecionar(url + (erro ? '?erro=' + erro : ''));
  };
  if (!s.usuario_id) negar(config.loginUrl);

  const agora = Math.floor(Date.now() / 1000);
  if (s.ultimo_uso && agora - s.ultimo_uso > config.sessaoMinutos * 60) {
    await registrarAtividade(ctx, 'sessao', 'expirou', 'Sessão encerrada por inatividade');
    ctx.encerrarSessao = true;
    negar(config.loginUrl, 'sessao');
  }
  s.ultimo_uso = agora;

  if (agora - (s.conferido_em || 0) >= 60) {
    try {
      const atual = await bd.chamarUm('sp_usuario_acesso', [Number(s.usuario_id)]);
      if (!atual || atual.ativo !== 'ATIVO') {
        ctx.encerrarSessao = true;
        negar(config.loginUrl, 'inativo');
      }
      s.usuario_nome = atual.nome; s.usuario_nivel = atual.nivel; s.usuario_cargo = atual.cargo;
      s.conferido_em = agora;
    } catch (err) {
      if (err instanceof Redirecionar || err.api) throw err;
      console.error('[xtec] acesso:', err.message);
    }
  }

  const cargo = s.usuario_cargo || 'FUNCIONARIO';
  const nivel = s.usuario_nivel || 'FUNCIONARIO';
  ctx.u = { nivel, cargo, nome: s.usuario_nome || 'Usuário', id: Number(s.usuario_id), perm: permissoes(nivel, cargo) };
  return ctx.u;
}

/** Bloqueia a página para quem não tem a permissão (volta para a Visão Geral com aviso). */
function exigirPermissao(ctx, pode) {
  if (!pode) {
    require('./web').flash(ctx, 'erro', 'Você não tem acesso a essa área do sistema.');
    throw new Redirecionar('dashboard');
  }
}

module.exports = { carregar, cookie, cookieLimpo, ipCliente, permissoes, rotuloCargo, registrarAtividade, autenticar, exigirPermissao };
