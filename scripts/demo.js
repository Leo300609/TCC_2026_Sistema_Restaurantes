'use strict';
/**
 * Carrega os dados de demonstração (60 dias de vendas, pedidos em andamento,
 * mesas ocupadas, compras e despesas). O arquivo guarda as datas do dia em que
 * foi gerado; aqui todas são deslocadas para que o "agora" dos dados coincida
 * com o agora de quem instala.
 */
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');
const bd = require('../servidor/bd');

const ARQUIVO = path.join(__dirname, '..', 'database', 'postgres', 'demo', 'dados.json.gz');
// ordem que respeita as chaves estrangeiras
const ORDEM = ['estado', 'cidade', 'cliente', 'fornecedor', 'produto_categoria', 'produto', 'fornecedor_produto', 'usuarios',
  'mesa', 'ficha_tecnica', 'movimentacao_estoque', 'venda', 'venda_item', 'conta_receber', 'compra', 'compra_item',
  'conta_pagar', 'movimentacao_caixa', 'empresa', 'empresa_horario', 'configuracao'];

const naive = (s) => Date.parse(s.replace(' ', 'T') + (s.length === 10 ? 'T00:00:00Z' : 'Z').replace('TT', 'T'));
function agoraLocal() {
  const p = new Intl.DateTimeFormat('sv-SE', { timeZone: bd.FUSO, dateStyle: 'short', timeStyle: 'medium' }).format(new Date());
  return p; // "2026-10-31 23:51:20"
}
const fmtTs = (ms) => new Date(ms).toISOString().slice(0, 19).replace('T', ' ');
const fmtData = (ms) => new Date(ms).toISOString().slice(0, 10);

async function carregar() {
  const dados = JSON.parse(zlib.gunzipSync(fs.readFileSync(ARQUIVO)).toString('utf8'));
  const deslocamento = naive(agoraLocal()) - naive(dados._ref); // ms
  const dias = Math.round(deslocamento / 86400000);

  // quais colunas são data / data-hora
  const cols = await bd.consulta(`SELECT table_name t, column_name c, data_type d FROM information_schema.columns
                                   WHERE table_schema = 'public' AND data_type IN ('date', 'timestamp without time zone')`);
  const tipos = {};
  for (const r of cols.rows) (tipos[r.t] ??= {})[r.c] = r.d;

  await bd.exec(`TRUNCATE ${ORDEM.join(', ')} RESTART IDENTITY CASCADE`);
  for (const tabela of ORDEM) {
    const linhas = dados[tabela];
    if (!linhas || !linhas.length) continue;
    const t = tipos[tabela] || {};
    for (const l of linhas) {
      for (const [c, v] of Object.entries(l)) {
        if (v === null || !t[c]) continue;
        l[c] = t[c] === 'date' ? fmtData(naive(v) + dias * 86400000) : fmtTs(naive(v) + deslocamento);
      }
    }
    await bd.consulta(`INSERT INTO ${tabela} SELECT * FROM jsonb_populate_recordset(NULL::${tabela}, $1::jsonb)`, [JSON.stringify(linhas)]);
    if (linhas[0].id !== undefined) {
      await bd.exec(`SELECT setval(pg_get_serial_sequence('${tabela}', 'id'), COALESCE((SELECT MAX(id) FROM ${tabela}), 1))`);
    }
  }
  console.log(`✓ dados de demonstração carregados (datas deslocadas em ${dias} dia(s))`);
}

module.exports = { carregar };
