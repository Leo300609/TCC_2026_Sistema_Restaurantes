'use strict';
/**
 * Chama toda função fn_ e sp_ do banco com argumentos plausíveis, dentro de uma
 * transação que é desfeita no fim. Erros de regra de negócio (RAISE EXCEPTION)
 * são esperados com argumentos de teste; qualquer outro erro é bug de SQL.
 */
const bd = require('../servidor/bd');

function argumento(nome, tipo) {
  const n = nome.replace(/^p_/, '');
  if (tipo.startsWith('date')) return /^de$|inicio|inicial/.test(n) ? "(CURRENT_DATE - 30)" : "CURRENT_DATE";
  if (tipo.startsWith('timestamp')) return 'now()';
  if (tipo.startsWith('time')) return "'18:00'";
  if (/integer|smallint|bigint|numeric|double/.test(tipo)) {
    if (/limite|quantidade|dias|janela|semanas|valor|preco|qtd|lugares|parcelas|pagina/.test(n)) return /pagina/.test(n) ? '1' : '5';
    return '1';
  }
  if (/busca|termo|texto|q$/.test(n)) return "'a'";
  if (/status|situacao/.test(n)) return "'ATIVO'";
  if (/^tipo/.test(n)) return "'ENTRADA'";
  if (/forma/.test(n)) return "'PIX'";
  if (/etapa|destino/.test(n)) return "'PREPARO'";
  if (/email/.test(n)) return "'admin@tcc.com'";
  if (/senha|hash/.test(n)) return "''";
  if (/ip/.test(n)) return "'127.0.0.1'";
  if (/chave/.test(n)) return "'tempo_preparo'";
  if (/estado/.test(n)) return "'SP'";
  return "'x'";
}

(async () => {
  const filtro = process.argv[2] ? new RegExp(process.argv[2]) : null;
  const fns = (await bd.consulta(`
    SELECT p.proname AS nome, pg_get_function_arguments(p.oid) AS args
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
    WHERE n.nspname = 'public' AND p.proname ~ '^(fn|sp)_' ORDER BY p.proname`)).rows;
  let ok = 0, negocio = 0, bugs = 0;
  await bd.exec('BEGIN');
  for (const f of fns) {
    if (filtro && !filtro.test(f.nome)) continue;
    const params = f.args ? f.args.split(/,\s*(?=\w+ )/).map((a) => a.trim().split(/\s+/)) : [];
    const lista = params.map(([nome, ...t]) => argumento(nome, t.join(' ')) + '::' + t.join(' ').replace(/ DEFAULT.*/, '')).join(', ');
    const sql = f.nome.startsWith('fn_') ? `SELECT ${f.nome}(${lista})` : `SELECT * FROM ${f.nome}(${lista})`;
    await bd.exec('SAVEPOINT s');
    try {
      await bd.exec(sql);
      ok++;
    } catch (e) {
      if (e.code === 'P0001') negocio++;
      else { bugs++; console.log(`✗ ${f.nome}(${lista})\n    ${e.message}`); }
    }
    await bd.exec('ROLLBACK TO s');
  }
  await bd.exec('ROLLBACK');
  console.log(`\n${ok} ok · ${negocio} recusadas por regra de negócio · ${bugs} com erro de SQL`);
  await bd.fechar();
})().catch((e) => { console.error(e); process.exit(1); });
