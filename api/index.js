'use strict';
// Função única da Vercel: todas as rotas dinâmicas passam por aqui (ver vercel.json).
// Os require abaixo existem só para a Vercel incluir as dependências no pacote,
// já que as telas são carregadas dinamicamente pelo servidor/app.js.
if (process.env.XTEC_NUNCA === '1') {
  require('pg'); require('bcryptjs'); require('exceljs'); require('docx'); require('pdf-lib');
}
module.exports = (req, res) => require('../servidor/app').handler(req, res);
