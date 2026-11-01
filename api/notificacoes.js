'use strict';
// Rota /api/notificacoes: mesma função única das demais rotas dinâmicas
module.exports = (req, res) => require('../servidor/app').handler(req, res);
