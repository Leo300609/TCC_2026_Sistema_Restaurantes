'use strict';
/** Configuração central do sistema (valores fixos; segredos vêm de variáveis de ambiente). */
module.exports = {
  appNome: 'XTEC',
  appVersao: require('../package.json').version,
  sessaoMinutos: 120,   // sessão expira após esse tempo sem uso
  loginMaxFalhas: 5,    // tentativas erradas antes do bloqueio (15 minutos)
  loginUrl: '/frontend/html/login.html',
};
