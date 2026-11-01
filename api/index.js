'use strict';
// Função única da Vercel: todas as rotas dinâmicas passam por aqui (ver vercel.json)
module.exports = (req, res) => require('../servidor/app').handler(req, res);
