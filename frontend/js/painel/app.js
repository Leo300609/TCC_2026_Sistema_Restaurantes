/* ==========================================================================
   XTEC · Painel — comportamento comum a todas as telas
   Tudo aqui é melhoria progressiva: os cadastros funcionam com formulários
   comuns (POST) mesmo sem JavaScript.
   ========================================================================== */
(() => {
  'use strict';

  const raiz = document.documentElement;
  const API = '../../backend/api/';
  const guardar = (chave, valor) => { try { localStorage.setItem(chave, valor); } catch (e) { /* modo privado */ } };
  const lerGuardado = (chave) => { try { return localStorage.getItem(chave); } catch (e) { return null; } };
  const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  /* ---------- Tema claro / escuro ---------- */
  const btnTema = document.getElementById('btn-tema');
  function aplicarTema(tema) {
    raiz.setAttribute('data-tema', tema);
    if (btnTema) {
      btnTema.querySelector('i').className = tema === 'escuro' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
      btnTema.setAttribute('aria-label', tema === 'escuro' ? 'Usar tema claro' : 'Usar tema escuro');
    }
    document.dispatchEvent(new CustomEvent('xtec:tema', { detail: tema }));
  }
  aplicarTema(raiz.getAttribute('data-tema') || 'claro');
  btnTema?.addEventListener('click', () => {
    const novo = raiz.getAttribute('data-tema') === 'escuro' ? 'claro' : 'escuro';
    guardar('xtec-tema', novo);
    aplicarTema(novo);
  });
  matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
    const escolhido = lerGuardado('xtec-tema');
    if (escolhido !== 'claro' && escolhido !== 'escuro') aplicarTema(e.matches ? 'escuro' : 'claro');
  });
  // usado pela tela de Configurações > Aparência
  window.xtecDefinirTema = (tema) => {
    if (tema === 'sistema') {
      try { localStorage.removeItem('xtec-tema'); } catch (e) { /* ignora */ }
      aplicarTema(matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro');
    } else {
      guardar('xtec-tema', tema);
      aplicarTema(tema);
    }
  };

  /* ---------- Menu lateral: gaveta no celular e modo recolhido ---------- */
  const btnMenu = document.getElementById('btn-menu');
  const menu = document.getElementById('menu-lateral');
  const fundoMenu = document.getElementById('menu-fundo');
  const celular = matchMedia('(max-width: 860px)');

  function abrirMenu(aberto) {
    if (!menu || !fundoMenu || !btnMenu) return;
    menu.classList.toggle('aberto', aberto);
    fundoMenu.hidden = !aberto;
    btnMenu.setAttribute('aria-expanded', String(aberto));
    btnMenu.setAttribute('aria-label', aberto ? 'Fechar menu' : 'Abrir menu');
    btnMenu.querySelector('i').className = aberto ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
    if (aberto) menu.querySelector('.nav-item')?.focus();
  }
  btnMenu?.addEventListener('click', () => abrirMenu(!menu.classList.contains('aberto')));
  fundoMenu?.addEventListener('click', () => abrirMenu(false));
  celular.addEventListener('change', (e) => { if (!e.matches) abrirMenu(false); });

  document.getElementById('btn-recolher')?.addEventListener('click', () => {
    const recolhido = raiz.classList.toggle('menu-recolhido');
    guardar('xtec-menu', recolhido ? 'recolhido' : 'aberto');
  });

  /* ---------- Menus suspensos (notificações, usuário) ---------- */
  function fecharSuspensos(exceto) {
    document.querySelectorAll('[data-suspenso]').forEach((b) => {
      const painel = document.getElementById(b.dataset.suspenso);
      if (painel && painel !== exceto) {
        painel.hidden = true;
        b.setAttribute('aria-expanded', 'false');
      }
    });
  }
  document.querySelectorAll('[data-suspenso]').forEach((botao) => {
    botao.addEventListener('click', (e) => {
      e.stopPropagation();
      const painel = document.getElementById(botao.dataset.suspenso);
      if (!painel) return;
      const abrir = painel.hidden;
      fecharSuspensos(painel);
      painel.hidden = !abrir;
      botao.setAttribute('aria-expanded', String(abrir));
      if (abrir && botao.id === 'btn-notificacoes') atualizarNotificacoes(true);
    });
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('.suspenso-painel')) fecharSuspensos(null);
  });

  /* ---------- Notificações (atualiza a cada minuto) ---------- */
  const icones = { PEDIDO: 'fa-receipt', ESTOQUE: 'fa-box-open', VALIDADE: 'fa-calendar-xmark', CONTA: 'fa-file-invoice-dollar' };
  async function atualizarNotificacoes(lista) {
    try {
      const r = await fetch(API + 'notificacoes.php', { headers: { Accept: 'application/json' } });
      if (!r.ok) return;
      const dados = await r.json();
      const botao = document.getElementById('btn-notificacoes');
      let bolinha = document.getElementById('notif-contagem');
      if (dados.total > 0) {
        if (!bolinha && botao) {
          bolinha = document.createElement('span');
          bolinha.className = 'bolinha';
          bolinha.id = 'notif-contagem';
          botao.appendChild(bolinha);
        }
        bolinha.textContent = dados.total > 9 ? '9+' : dados.total;
      } else {
        bolinha?.remove();
      }
      if (lista) {
        const ul = document.getElementById('notif-lista');
        if (!ul) return;
        ul.innerHTML = dados.itens.length
          ? dados.itens.map((n) => `<li class="notif-item ${escapar(n.nivel)}"><a href="${escapar(n.link)}">
              <span class="notif-icone"><i class="fa-solid ${icones[n.tipo] || 'fa-bell'}" aria-hidden="true"></i></span>
              <span><strong>${escapar(n.titulo)}</strong><small>${escapar(n.detalhe)}</small></span></a></li>`).join('')
          : '<li class="notif-vazio"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>Tudo em dia por aqui.</li>';
      }
    } catch (e) { /* sem rede: mantém o que já está na tela */ }
  }
  setInterval(() => { if (!document.hidden) atualizarNotificacoes(false); }, 60000);

  /* ---------- Avisos: somem sozinhos (os de sucesso) ---------- */
  function fecharAviso(aviso) {
    aviso.classList.add('saindo');
    setTimeout(() => aviso.remove(), 260);
  }
  document.querySelectorAll('.toasts .aviso').forEach((aviso) => {
    if (aviso.classList.contains('aviso-ok')) setTimeout(() => fecharAviso(aviso), 5000);
  });
  document.addEventListener('click', (e) => {
    const x = e.target.closest('.aviso-fechar');
    if (x) fecharAviso(x.closest('.aviso'));
  });
  window.xtecAviso = (mensagem, tipo = 'ok') => {
    const caixa = document.getElementById('toasts');
    if (!caixa) return;
    const div = document.createElement('div');
    div.className = `aviso aviso-${tipo}`;
    div.setAttribute('role', tipo === 'ok' ? 'status' : 'alert');
    div.innerHTML = `<i class="fa-solid ${tipo === 'ok' ? 'fa-circle-check' : 'fa-triangle-exclamation'}" aria-hidden="true"></i>
      <span>${escapar(mensagem)}</span><button type="button" class="aviso-fechar" aria-label="Fechar aviso"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>`;
    caixa.appendChild(div);
    setTimeout(() => fecharAviso(div), 5000);
  };

  /* ---------- Gavetas e confirmações: Esc / clique fora fecham ---------- */
  function sobreposicaoAberta() {
    return document.querySelector('.confirmar-card:not(.form-card .confirmar-card)') || document.querySelector('.form-card');
  }
  function fecharSobreposicao() {
    const aberta = sobreposicaoAberta();
    const link = aberta?.querySelector('[data-fechar]') || aberta?.querySelector('a.btn-secundario');
    if (link) window.location.href = link.href;
  }
  document.getElementById('veu-bloqueio')?.addEventListener('click', fecharSobreposicao);
  const aberta = sobreposicaoAberta();
  if (aberta) {
    // leva o foco para o primeiro campo da gaveta
    const campo = aberta.querySelector('input:not([type=hidden]):not([disabled]), select, textarea, button');
    setTimeout(() => campo?.focus({ preventScroll: true }), 60);
  }

  /* ---------- Paleta de comandos (Ctrl + K) ---------- */
  const paleta = document.getElementById('paleta');
  const campoPaleta = document.getElementById('paleta-campo');
  const listaPaleta = document.getElementById('paleta-lista');
  let itensPaleta = [];
  let ativo = 0;
  let buscaAtual = null;

  const paginas = [...document.querySelectorAll('[data-busca-pagina]')].map((a) => ({
    grupo: 'Páginas', titulo: a.dataset.buscaPagina, detalhe: '', icone: a.dataset.icone, link: a.getAttribute('href'),
  }));
  const atalhos = [
    { titulo: 'Nova venda no PDV', link: 'pdv.php', icone: 'fa-cash-register', pagina: 'pdv.php' },
    { titulo: 'Novo pedido', link: 'pedidos.php?novo=1#formulario', icone: 'fa-plus', pagina: 'pedidos.php' },
    { titulo: 'Novo cliente', link: 'clientes.php?novo=1', icone: 'fa-user-plus', pagina: 'clientes.php' },
    { titulo: 'Registrar compra', link: 'compras.php?novo=1', icone: 'fa-cart-plus', pagina: 'compras.php' },
    { titulo: 'Registrar entrada no estoque', link: 'estoque.php?mov=ENTRADA', icone: 'fa-download', pagina: 'estoque.php' },
    { titulo: 'Lançamento no caixa', link: 'fluxo_de_caixa.php#novo-lancamento', icone: 'fa-wallet', pagina: 'fluxo_de_caixa.php' },
  ].filter((a) => paginas.some((p) => p.link === a.pagina)).map((a) => ({ ...a, grupo: 'Ações rápidas', detalhe: '' }));

  const iconeTipo = { CLIENTE: 'fa-user', PRODUTO: 'fa-burger', PEDIDO: 'fa-receipt', FORNECEDOR: 'fa-truck-field' };
  const grupoTipo = { CLIENTE: 'Clientes', PRODUTO: 'Produtos', PEDIDO: 'Pedidos', FORNECEDOR: 'Fornecedores' };

  function desenharPaleta() {
    let grupo = '';
    listaPaleta.innerHTML = itensPaleta.length ? itensPaleta.map((it, i) => {
      const cab = it.grupo !== grupo ? `<li class="paleta-grupo" role="presentation">${escapar(it.grupo)}</li>` : '';
      grupo = it.grupo;
      return `${cab}<li class="paleta-item${i === ativo ? ' ativo' : ''}" role="option" aria-selected="${i === ativo}" data-i="${i}">
        <a href="${escapar(it.link)}"><i class="fa-solid ${escapar(it.icone)}" aria-hidden="true"></i>
        <span>${escapar(it.titulo)}${it.detalhe ? `<small>${escapar(it.detalhe)}</small>` : ''}</span></a></li>`;
    }).join('') : '<li class="notif-vazio">Nada encontrado. Tente outro termo.</li>';
    listaPaleta.querySelector('.ativo')?.scrollIntoView({ block: 'nearest' });
  }

  function filtrarLocais(termo) {
    const t = termo.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
    const casa = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().includes(t);
    return [...paginas, ...atalhos].filter((it) => !t || casa(it.titulo));
  }

  async function buscarRemoto(termo) {
    if (termo.trim().length < 2) return [];
    buscaAtual?.abort();
    buscaAtual = new AbortController();
    try {
      const r = await fetch(API + 'busca.php?q=' + encodeURIComponent(termo), { signal: buscaAtual.signal });
      if (!r.ok) return [];
      const dados = await r.json();
      return (dados.itens || []).map((it) => ({
        grupo: grupoTipo[it.tipo] || 'Resultados', titulo: it.titulo, detalhe: it.detalhe,
        icone: iconeTipo[it.tipo] || 'fa-circle', link: it.link,
      }));
    } catch (e) {
      return [];
    }
  }

  let espera = null;
  campoPaleta?.addEventListener('input', () => {
    const termo = campoPaleta.value;
    itensPaleta = filtrarLocais(termo);
    ativo = 0;
    desenharPaleta();
    clearTimeout(espera);
    espera = setTimeout(async () => {
      const remotos = await buscarRemoto(termo);
      if (campoPaleta.value !== termo) return;
      itensPaleta = [...filtrarLocais(termo), ...remotos];
      desenharPaleta();
    }, 220);
  });

  campoPaleta?.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown') { e.preventDefault(); ativo = Math.min(ativo + 1, itensPaleta.length - 1); desenharPaleta(); }
    if (e.key === 'ArrowUp') { e.preventDefault(); ativo = Math.max(ativo - 1, 0); desenharPaleta(); }
    if (e.key === 'Enter' && itensPaleta[ativo]) { e.preventDefault(); window.location.href = itensPaleta[ativo].link; }
  });
  listaPaleta?.addEventListener('mousemove', (e) => {
    const li = e.target.closest('.paleta-item');
    if (li && Number(li.dataset.i) !== ativo) { ativo = Number(li.dataset.i); desenharPaleta(); }
  });

  function abrirPaleta() {
    if (!paleta) return;
    fecharSuspensos(null);
    paleta.hidden = false;
    campoPaleta.value = '';
    itensPaleta = filtrarLocais('');
    ativo = 0;
    desenharPaleta();
    campoPaleta.focus();
  }
  function fecharPaleta() { if (paleta) paleta.hidden = true; }
  document.querySelectorAll('[data-abrir-paleta]').forEach((b) => b.addEventListener('click', abrirPaleta));
  paleta?.addEventListener('click', (e) => { if (e.target === paleta) fecharPaleta(); });

  /* ---------- Teclado ---------- */
  document.addEventListener('keydown', (e) => {
    const digitando = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName) || document.activeElement?.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      paleta?.hidden ? abrirPaleta() : fecharPaleta();
      return;
    }
    if (e.key === '/' && !digitando && paleta?.hidden) {
      e.preventDefault();
      abrirPaleta();
      return;
    }
    if (e.key === 'Escape') {
      if (paleta && !paleta.hidden) return fecharPaleta();
      if (document.querySelector('.suspenso-painel:not([hidden])')) return fecharSuspensos(null);
      if (menu?.classList.contains('aberto')) return abrirMenu(false);
      if (sobreposicaoAberta()) fecharSobreposicao();
    }
  });

  /* ---------- Máscaras de digitação ---------- */
  const mascaras = {
    telefone(v) {
      v = v.replace(/\D/g, '').slice(0, 11);
      if (v.length > 10) return v.replace(/^(\d{2})(\d{5})(\d{0,4}).*/, '($1) $2-$3');
      if (v.length > 6) return v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
      if (v.length > 2) return v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
      return v;
    },
    documento(v) {
      v = v.replace(/\D/g, '').slice(0, 14);
      if (v.length <= 11) {
        return v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
      }
      return v.replace(/^(\d{2})(\d)/, '$1.$2').replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3')
        .replace(/\.(\d{3})(\d)/, '.$1/$2').replace(/(\d{4})(\d)/, '$1-$2');
    },
    cnpj(v) { return mascaras.documento(v.replace(/\D/g, '').padEnd(12, '').slice(0, 14)); },
    numeros(v) { return v.replace(/\D/g, ''); },
  };
  document.addEventListener('input', (e) => {
    const campo = e.target.closest('[data-mascara]');
    const fn = campo && mascaras[campo.dataset.mascara];
    if (fn) campo.value = fn(campo.value);
  });
  document.querySelectorAll('[data-mascara]').forEach((c) => { const fn = mascaras[c.dataset.mascara]; if (fn && c.value) c.value = fn(c.value); });

  /* ---------- Copiar para a área de transferência ---------- */
  document.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-copiar]');
    if (!b) return;
    try {
      await navigator.clipboard.writeText(b.dataset.copiar);
      window.xtecAviso('Copiado: ' + b.dataset.copiar);
    } catch (err) {
      window.prompt('Copie o código:', b.dataset.copiar);
    }
  });

  /* ---------- Tabelas: rótulos para o modo cartão no celular ---------- */
  function rotular(tabela) {
    const titulos = [...tabela.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    tabela.querySelectorAll('tbody tr').forEach((linha) => {
      [...linha.children].forEach((celula, i) => {
        if (celula.colSpan > 1 || celula.classList.contains('acoes')) return;
        if (titulos[i] && celula.dataset.rotulo !== titulos[i]) celula.dataset.rotulo = titulos[i];
      });
    });
  }
  document.querySelectorAll('table.tabela').forEach(rotular);

  /* ---------- Atualização automática (quadro de pedidos, mesas) ---------- */
  const segundos = Number(document.body.dataset.atualizar || 0);
  if (segundos > 0) {
    setInterval(() => {
      const ocupado = document.hidden || sobreposicaoAberta() || (paleta && !paleta.hidden)
        || document.querySelector('.suspenso-painel:not([hidden])')
        || /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName);
      if (!ocupado) window.location.reload();
    }, segundos * 1000);
  }

  /* ---------- Envio único: evita clique duplo em botões de formulário ---------- */
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if (form.method.toLowerCase() !== 'post' || e.defaultPrevented) return;
    setTimeout(() => form.querySelectorAll('button[type=submit]').forEach((b) => { b.disabled = true; }), 0);
  });
})();
