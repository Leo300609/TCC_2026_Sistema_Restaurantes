'use strict';
/**
 * Instala o banco: node scripts/banco.js instalar [--demo]
 *   instalar  recria o esquema (apaga tudo!) e cria tabelas, views e funções
 *   demo      carrega os dados de demonstração (vendas, pedidos, compras...)
 * Usa DATABASE_URL (Neon) ou, sem ela, o Postgres embutido em .dados-locais/.
 */
const fs = require('fs');
const path = require('path');
const bd = require('../servidor/bd');

const PASTA = path.join(__dirname, '..', 'database', 'postgres');

async function aplicar(arquivo) {
  const texto = fs.readFileSync(path.join(PASTA, arquivo), 'utf8');
  // cada rotina é aplicada sozinha para apontar qual falhou
  const partes = texto.split(/\n(?=(?:--[^\n]*\n)*DROP FUNCTION IF EXISTS )/);
  let erros = 0;
  for (const parte of partes) {
    if (!parte.trim()) continue;
    try {
      await bd.exec(parte);
    } catch (e) {
      erros++;
      const nome = (parte.match(/CREATE OR REPLACE FUNCTION (\w+)/) || [])[1] || parte.slice(0, 60).replace(/\s+/g, ' ');
      console.error(`  ✗ ${arquivo} · ${nome}: ${e.message}`);
    }
  }
  console.log(`${erros ? '!' : '✓'} ${arquivo}${erros ? ` (${erros} erro(s))` : ''}`);
  return erros;
}

async function instalar() {
  const m = await bd.abrir();
  await bd.exec('DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public;');
  await bd.exec(`DO $$ BEGIN EXECUTE format('ALTER DATABASE %I SET timezone TO %L', current_database(), '${bd.FUSO}'); EXCEPTION WHEN OTHERS THEN NULL; END $$;`);
  let erros = 0;
  for (const f of fs.readdirSync(PASTA).filter((n) => /^\d\d_.*\.sql$/.test(n) && !/demo/.test(n)).sort()) {
    erros += await aplicar(f);
  }
  console.log(`Banco (${m.tipo}) instalado${erros ? ' com ' + erros + ' erro(s)' : ''}.`);
  return erros;
}

async function main() {
  const cmd = process.argv[2];
  if (cmd === 'instalar') await instalar();
  else if (cmd === 'demo') await require('./demo').carregar();
  else console.log('uso: node scripts/banco.js instalar | demo');
  await bd.fechar();
}
if (require.main === module) main().catch((e) => { console.error(e); process.exit(1); });
module.exports = { instalar, aplicar };
