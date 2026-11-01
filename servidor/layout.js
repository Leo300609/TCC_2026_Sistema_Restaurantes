'use strict';
/**
 * Moldura padrão das páginas do painel: <head>, menu lateral, barra superior,
 * avisos, paleta de comandos (Ctrl + K) e scripts. Equivale ao topo/rodapé em PHP.
 */
const config = require('./config');
const bd = require('./bd');
const { h, e, cru, ler, flashLer, data, iniciais } = require('./web');
const { rotuloCargo } = require('./sessao');

const hash = (nome) => { let x = 0; for (const c of nome) x = (x * 31 + c.charCodeAt(0)) >>> 0; return x; };
const corAvatar = (nome) => 'cor-' + (hash(nome) % 5 + 1);

function menu(ctx, paginaAtual, etapas) {
  const { perm } = ctx.u;
  const emAndamento = Number(etapas.novos) + Number(etapas.preparo) + Number(etapas.prontos);
  const naCozinha = Number(etapas.novos) + Number(etapas.preparo);
  // [chave, página, ícone, rótulo, visível, contador]
  const grupos = {
    'Operação': [
      ['dashboard', 'dashboard', 'fa-chart-line', 'Visão geral', true, 0],
      ['pdv', 'pdv', 'fa-cash-register', 'PDV', perm.pdv, 0],
      ['pedidos', 'pedidos', 'fa-receipt', 'Pedidos', true, emAndamento],
      ['mesas', 'mesas', 'fa-chair', 'Mesas', perm.mesas, 0],
      ['cozinha', 'cozinha', 'fa-fire-burner', 'Cozinha', perm.cozinha, naCozinha],
    ],
    'Gestão': [
      ['cardapios', 'cardapios', 'fa-utensils', 'Cardápio', true, 0],
      ['estoque', 'estoque', 'fa-boxes-stacked', 'Estoque', perm.estoque, 0],
      ['compras', 'compras', 'fa-cart-flatbed', 'Compras', perm.compras, 0],
      ['fornecedores', 'fornecedores', 'fa-truck-field', 'Fornecedores', perm.compras, 0],
      ['clientes', 'clientes', 'fa-users', 'Clientes', true, 0],
    ],
    'Financeiro': [
      ['fluxo_de_caixa', 'fluxo_de_caixa', 'fa-wallet', 'Fluxo de caixa', perm.financeiro, 0],
      ['relatorios', 'relatorios', 'fa-chart-pie', 'Relatórios', perm.relatorios, 0],
      ['previsoes', 'previsoes', 'fa-wand-magic-sparkles', 'Previsões', perm.relatorios, 0],
    ],
    'Administração': [
      ['empresa', 'empresa', 'fa-store', 'Empresa e equipe', true, 0],
      ['atividades', 'atividades', 'fa-clock-rotate-left', 'Atividades', perm.auditoria, 0],
      ['configs', 'configs', 'fa-gear', 'Configurações', true, 0],
    ],
  };
  return Object.entries(grupos).map(([nomeGrupo, itens]) => {
    const visiveis = itens.filter((i) => i[4]);
    if (!visiveis.length) return '';
    return h`<div class="nav-grupo">
        <span class="nav-grupo-titulo">${nomeGrupo}</span>
        ${visiveis.map(([chave, arquivo, icone, rotulo, , contador]) => {
          const ativo = chave === paginaAtual;
          return h`<a href="${arquivo}" class="nav-item${ativo ? ' active' : ''}"${ativo ? cru(' aria-current="page"') : ''} title="${rotulo}" data-busca-pagina="${rotulo}" data-icone="${icone}">
            <i class="fa-solid ${icone}" aria-hidden="true"></i>
            <span>${rotulo}</span>
            ${contador > 0 ? h`<span class="nav-contador" aria-label="${contador} em andamento">${contador}</span>` : ''}
          </a>`;
        })}
      </div>`;
  });
}

const ICONE_NOTIF = { PEDIDO: 'fa-receipt', ESTOQUE: 'fa-box-open', VALIDADE: 'fa-calendar-xmark', CONTA: 'fa-file-invoice-dollar' };

/**
 * Monta a página inteira. opts: titulo, subtitulo, paginaAtual, cabecaExtra (HTML),
 * rodapeExtra (HTML), classeCorpo. `conteudo` é o HTML (h`...`) dentro de .conteudo.
 */
async function pagina(ctx, opts, conteudo) {
  const { perm } = ctx.u;
  const s = ctx.sessao;
  const avisoLeitura = s.flash_leitura || null;
  delete s.flash_leitura;

  const [emp, horarios, etapasMenu, notificacoes] = await Promise.all([
    ler(ctx, () => bd.chamarUm('sp_empresa_obter'), null),
    ler(ctx, () => bd.chamar('sp_empresa_horarios'), []),
    ler(ctx, () => bd.chamarUm('sp_dash_etapas'), null),
    ler(ctx, () => bd.chamar('sp_notificacoes', [ctx.u.id, perm.estoque ? 1 : 0, perm.financeiro ? 1 : 0]), []),
  ]);
  const nomeLoja = (emp && emp.nome_fantasia) || 'XTEC';
  const hhmm = data('H:i');
  const diaIso = Number(data('N'));
  let lojaAberta = false;
  for (const x of horarios) {
    if (Number(x.dia) === diaIso && Number(x.aberto) === 1) lojaAberta = hhmm >= String(x.abre).slice(0, 5) && hhmm <= String(x.fecha).slice(0, 5);
  }
  const etapas = etapasMenu || { novos: 0, preparo: 0, prontos: 0 };
  const qtdNotif = notificacoes.length;
  const flash = flashLer(ctx);
  const nome = ctx.u.nome;
  const v = config.appVersao;

  return h`<!DOCTYPE html>
<html lang="pt-BR">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="${s.csrf || ''}">
  <meta name="theme-color" content="#d7262e">
  <title>${opts.titulo} · ${config.appNome}</title>
  <script>
    /* tema e menu recolhido antes de pintar a página (evita piscar) */
    (function () {
      var d = document.documentElement, t = null, m = null;
      try { t = localStorage.getItem('xtec-tema'); m = localStorage.getItem('xtec-menu'); } catch (e) {}
      if (t !== 'claro' && t !== 'escuro') t = matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
      d.setAttribute('data-tema', t);
      if (m === 'recolhido') d.classList.add('menu-recolhido');
      try {
        if (sessionStorage.getItem('xtec-recarga') === location.pathname) d.classList.add('sem-entrada');
        sessionStorage.removeItem('xtec-recarga');
      } catch (e) {}
    })();
  </script>
  <link rel="icon" type="image/svg+xml" href="/frontend/assets/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <link rel="stylesheet" href="/frontend/css/painel/app.css?v=${v}">
  ${opts.cabecaExtra || ''}
</head>

<body class="${opts.classeCorpo || ''}" data-pagina="${opts.paginaAtual}">
  <a class="skip-link" href="#conteudo">Ir para o conteúdo</a>

  <div class="dashboard-layout">

    <header class="topo-movel">
      <button type="button" class="btn-menu" id="btn-menu" aria-controls="menu-lateral" aria-expanded="false" aria-label="Abrir menu">
        <i class="fa-solid fa-bars" aria-hidden="true"></i>
      </button>
      <span class="topo-movel-marca">
        <span class="marca-icone" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
        <strong class="nome-empresa">${config.appNome}</strong>
      </span>
    </header>
    <div class="menu-fundo" id="menu-fundo" hidden></div>

    <aside class="sidebar" id="menu-lateral" aria-label="Menu principal">
      <a class="sidebar-header" href="dashboard" title="${nomeLoja}">
        <span class="marca-icone" aria-hidden="true"><i class="fa-solid fa-utensils"></i></span>
        <span class="marca-texto">
          <span class="nome-empresa">${config.appNome}</span>
          <span class="marca-sub">${nomeLoja}</span>
        </span>
      </a>

      <nav class="sidebar-nav" aria-label="Navegação principal">
        ${menu(ctx, opts.paginaAtual, etapas)}
      </nav>

      <div class="sidebar-footer">
        <button type="button" class="btn-recolher" id="btn-recolher" aria-label="Recolher menu">
          <i class="fa-solid fa-angles-left" aria-hidden="true"></i>
          <span>Recolher menu</span>
        </button>
      </div>
    </aside>

    <main class="main-content" id="conteudo" tabindex="-1">
      <div class="conteudo">

        <header class="topbar">
          <div class="boas-vindas">
            <h1>${opts.titulo}</h1>
            <p>${opts.subtitulo}</p>
          </div>
          <div class="usuario-perfil">
            <span class="status-loja ${lojaAberta ? 'online' : 'fechada'}" title="Segundo o horário cadastrado em Empresa">
              <i class="fa-solid fa-circle" aria-hidden="true"></i> ${lojaAberta ? 'Loja aberta' : 'Loja fechada'}
            </span>

            <button type="button" class="topo-busca" data-abrir-paleta aria-label="Buscar no sistema (Ctrl + K)">
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <span>Buscar…</span>
              <kbd>Ctrl K</kbd>
            </button>

            <div class="suspenso">
              <button type="button" class="topo-icone" id="btn-notificacoes" data-suspenso="painel-notificacoes" aria-expanded="false" aria-controls="painel-notificacoes" aria-label="Notificações${qtdNotif ? ': ' + qtdNotif : ''}">
                <i class="fa-regular fa-bell" aria-hidden="true"></i>
                ${qtdNotif ? h`<span class="bolinha" id="notif-contagem">${qtdNotif > 9 ? '9+' : qtdNotif}</span>` : ''}
              </button>
              <div class="suspenso-painel largo" id="painel-notificacoes" hidden>
                <div class="notif-topo">
                  <h2>Notificações</h2>
                  <a class="btn-link" href="configs#notificacoes">Preferências</a>
                </div>
                <ul class="notif-lista" id="notif-lista">
                  ${!qtdNotif ? h`<li class="notif-vazio"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Tudo em dia por aqui.</li>` : ''}
                  ${notificacoes.slice(0, 25).map((n) => h`<li class="notif-item ${n.nivel}">
                      <a href="${n.link}">
                        <span class="notif-icone"><i class="fa-solid ${ICONE_NOTIF[n.tipo] || 'fa-bell'}" aria-hidden="true"></i></span>
                        <span><strong>${n.titulo}</strong><small>${n.detalhe}</small></span>
                      </a>
                    </li>`)}
                </ul>
              </div>
            </div>

            <button type="button" class="topo-icone" id="btn-tema" aria-label="Alternar tema claro e escuro">
              <i class="fa-solid fa-moon" aria-hidden="true"></i>
            </button>

            <div class="suspenso">
              <button type="button" class="usuario-botao" data-suspenso="menu-usuario" aria-expanded="false" aria-controls="menu-usuario">
                <span class="avatar ${corAvatar(nome)}" aria-hidden="true">${iniciais(nome)}</span>
                <strong>${nome}</strong>
                <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
              </button>
              <div class="suspenso-painel" id="menu-usuario" hidden>
                <div class="menu-cabeca">
                  <strong>${nome}</strong>
                  <small>${rotuloCargo(ctx.u.cargo, ctx.u.nivel)}</small>
                </div>
                <a class="menu-opcao" href="configs#conta"><i class="fa-regular fa-user" aria-hidden="true"></i> Minha conta</a>
                <a class="menu-opcao" href="configs#aparencia"><i class="fa-solid fa-palette" aria-hidden="true"></i> Aparência</a>
                <button type="button" class="menu-opcao" data-abrir-paleta><i class="fa-regular fa-keyboard" aria-hidden="true"></i> Atalhos e busca</button>
                <a class="menu-opcao perigo" href="/auth/logout"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Sair</a>
              </div>
            </div>
          </div>
        </header>

        <div class="toasts" id="toasts" aria-live="polite">
          ${flash ? h`<div class="aviso aviso-${flash.tipo === 'ok' ? 'ok' : 'erro'}" role="${flash.tipo === 'ok' ? 'status' : 'alert'}">
              <i class="fa-solid ${flash.tipo === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation'}" aria-hidden="true"></i>
              <span>${flash.mensagem}</span>
              <button type="button" class="aviso-fechar" aria-label="Fechar aviso"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>` : ''}
        </div>
        ${avisoLeitura ? h`<div class="aviso aviso-erro" role="alert"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span>${avisoLeitura}</span></div>` : ''}

        ${conteudo}

      </div>
    </main>
  </div>

  <!-- bloqueia cliques na página enquanto uma gaveta ou confirmação está aberta -->
  <div class="veu-bloqueio" id="veu-bloqueio" aria-hidden="true"></div>

  <!-- Paleta de comandos (Ctrl + K) -->
  <div class="paleta" id="paleta" role="dialog" aria-modal="true" aria-label="Buscar no sistema" hidden>
    <div class="paleta-caixa">
      <label class="paleta-campo">
        <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
        <span class="sr-only">Buscar páginas, clientes, produtos ou pedidos</span>
        <input type="search" id="paleta-campo" placeholder="Buscar páginas, clientes, produtos ou nº do pedido" autocomplete="off" role="combobox" aria-controls="paleta-lista" aria-expanded="true">
        <kbd>Esc</kbd>
      </label>
      <ul class="paleta-lista" id="paleta-lista" role="listbox"></ul>
      <div class="paleta-rodape">
        <span><kbd>↑</kbd> <kbd>↓</kbd> navegar</span>
        <span><kbd>Enter</kbd> abrir</span>
        <span><kbd>Ctrl</kbd> <kbd>K</kbd> abrir a busca</span>
      </div>
    </div>
  </div>

  <script src="/frontend/js/painel/app.js?v=${v}" defer></script>
  ${opts.rodapeExtra || ''}
</body>

</html>`;
}

module.exports = { pagina, corAvatar };
