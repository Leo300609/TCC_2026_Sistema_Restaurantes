'use strict';
/**
 * Acesso ao banco. Toda regra de dados mora em funções do PostgreSQL
 * (database/postgres/*.sql). O Node só chama `SELECT * FROM sp_...($1, $2)`
 * com parâmetros e mostra o resultado.
 *
 * Em produção (Vercel) usa DATABASE_URL (Neon). Sem DATABASE_URL, no
 * computador de quem desenvolve, usa um Postgres embutido (PGlite) gravado em
 * .dados-locais/, para o sistema rodar sem instalar nada.
 */

const FUSO = 'America/Sao_Paulo';

/** Erro de regra de negócio (RAISE EXCEPTION nas funções): a mensagem pode ser exibida. */
class ErroNegocio extends Error {}

let motor = null;

async function abrir() {
  if (motor) return motor;
  const url = process.env.DATABASE_URL || process.env.POSTGRES_URL;
  if (url) {
    const { Pool } = require('pg');
    const pool = new Pool({
      connectionString: url.replace(/[?&]channel_binding=\w+/, ''),
      max: 3,
      idleTimeoutMillis: 10000,
      ssl: /localhost|127\.0\.0\.1/.test(url) ? false : { rejectUnauthorized: false },
    });
    pool.on('connect', (c) => c.query(`SET TIME ZONE '${FUSO}'`).catch(() => {}));
    pool.on('error', () => {});
    motor = {
      tipo: 'pg',
      consulta: (texto, params) => pool.query(texto, params),
      async transacao(bloco) {
        const c = await pool.connect();
        try {
          await c.query('BEGIN');
          const r = await bloco({ consulta: (t, p) => c.query(t, p) });
          await c.query('COMMIT');
          return r;
        } catch (e) {
          try { await c.query('ROLLBACK'); } catch (_) { /* ignora */ }
          throw e;
        } finally {
          c.release();
        }
      },
      exec: (texto) => pool.query(texto),
      fechar: () => pool.end(),
    };
  } else {
    const path = require('path');
    const embutido = '@electric-sql/pglite'; // nome em variável: não vai para o pacote da Vercel
    const { PGlite } = require(embutido);
    const dir = process.env.XTEC_DADOS_LOCAIS || path.join(__dirname, '..', '.dados-locais');
    const db = new PGlite(dir);
    await db.waitReady;
    await db.exec(`SET TIME ZONE '${FUSO}'`);
    motor = {
      tipo: 'pglite',
      consulta: (texto, params) => db.query(texto, params),
      transacao: (bloco) => db.transaction((tx) => bloco({ consulta: (t, p) => tx.query(t, p) })),
      exec: (texto) => db.exec(texto),
      fechar: () => db.close(),
    };
  }
  return motor;
}

function traduzirErro(e, rotina) {
  // RAISE EXCEPTION sem código próprio chega como P0001: mensagem de regra de negócio
  if (e && e.code === 'P0001') return new ErroNegocio(e.message);
  console.error('[xtec] ' + (rotina || 'consulta') + ': ' + (e && e.message));
  const erro = new Error('Não foi possível concluir a operação. Tente novamente em instantes.');
  erro.original = e;
  return erro;
}

function executor(base) {
  /** SELECT * FROM rotina(args): devolve as linhas (objetos) da procedure. */
  async function chamar(rotina, args = []) {
    if (!/^[a-z_0-9]+$/.test(rotina)) throw new Error('rotina inválida');
    const marcas = args.map((_, i) => '$' + (i + 1)).join(', ');
    const valores = args.map((v) => (typeof v === 'boolean' ? (v ? 1 : 0) : v === undefined ? null : v));
    try {
      const r = await base(`SELECT r FROM ${rotina}(${marcas}) AS r`, valores);
      return r.rows.map((x) => x.r);
    } catch (e) {
      throw traduzirErro(e, rotina);
    }
  }
  async function chamarUm(rotina, args = []) {
    return (await chamar(rotina, args))[0] ?? null;
  }
  async function chamarValor(rotina, args = []) {
    const linha = await chamarUm(rotina, args);
    if (linha === null) return null;
    // funções fn_* devolvem escalar: vem como o próprio valor
    if (typeof linha !== 'object') return linha;
    return Object.values(linha)[0] ?? null;
  }
  return { chamar, chamarUm, chamarValor };
}

/** Funções fn_* (escalares): SELECT fn_x($1). */
async function escalar(texto, params = []) {
  const m = await abrir();
  const r = await m.consulta(texto, params);
  return r.rows[0] ? Object.values(r.rows[0])[0] : null;
}

const global = executor(async (t, p) => (await abrir()).consulta(t, p));

/** Roda o bloco numa transação; qualquer exceção desfaz tudo. O bloco recebe {chamar, chamarUm, chamarValor}. */
async function transacao(bloco) {
  const m = await abrir();
  try {
    return await m.transacao((tx) => bloco(executor(tx.consulta)));
  } catch (e) {
    if (e instanceof ErroNegocio) throw e;
    if (e && e.code === 'P0001') throw new ErroNegocio(e.message);
    throw e;
  }
}

module.exports = {
  ErroNegocio,
  abrir,
  consulta: async (t, p) => (await abrir()).consulta(t, p),
  exec: async (t) => (await abrir()).exec(t),
  fechar: async () => { if (motor) { await motor.fechar(); motor = null; } },
  escalar,
  transacao,
  chamar: global.chamar,
  chamarUm: global.chamarUm,
  chamarValor: global.chamarValor,
  FUSO,
};
