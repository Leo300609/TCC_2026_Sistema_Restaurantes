'use strict';
// Rota /api/busca: mesma função única das demais rotas dinâmicas
module.exports = (req, res) => require('../servidor/app').handler(req, res);
