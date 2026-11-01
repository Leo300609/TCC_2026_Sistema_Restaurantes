/* ==========================================================================
   XTEC · Landing page — animações e interações
   Tudo é melhoria progressiva: sem JavaScript a página continua legível.
   Respeita "reduzir movimento" do sistema operacional.
   ========================================================================== */
(() => {
  'use strict';

  const calmo = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const topo = document.getElementById('topo');
  const progresso = document.getElementById('progresso-pagina');
  const menu = document.getElementById('menu');
  const menuBotao = document.getElementById('menu-botao');
  const caminho = document.getElementById('caminho');
  const passos = caminho ? [...caminho.querySelectorAll('.passo')] : [];

  /* ---------- Relógio da comanda do herói ---------- */
  document.querySelectorAll('[data-relogio]').forEach((el) => {
    el.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
  });

  /* ---------- Rolagem: topo, barra de progresso e caminho do pedido ---------- */
  let agendado = false;
  function aoRolar() {
    agendado = false;
    const y = window.scrollY;
    const total = document.documentElement.scrollHeight - window.innerHeight;
    topo.classList.toggle('rolou', y > 12);
    progresso.style.setProperty('--p', total > 0 ? (y / total).toFixed(4) : 0);

    if (caminho) {
      const r = caminho.getBoundingClientRect();
      const vh = window.innerHeight;
      const avanco = Math.min(1, Math.max(0, (vh * 0.7 - r.top) / (r.height * 0.85)));
      caminho.style.setProperty('--avanco', calmo ? 1 : avanco.toFixed(3));
      passos.forEach((p, i) => p.classList.toggle('aceso', calmo || avanco >= i / Math.max(passos.length - 1, 1) - 0.02));
    }
  }
  window.addEventListener('scroll', () => {
    if (!agendado) { agendado = true; requestAnimationFrame(aoRolar); }
  }, { passive: true });
  window.addEventListener('resize', aoRolar);
  aoRolar();

  /* ---------- Menu no celular ---------- */
  function abrirMenu(aberto) {
    menu.classList.toggle('aberto', aberto);
    menuBotao.setAttribute('aria-expanded', String(aberto));
    menuBotao.setAttribute('aria-label', aberto ? 'Fechar menu' : 'Abrir menu');
  }
  menuBotao?.addEventListener('click', () => abrirMenu(!menu.classList.contains('aberto')));
  menu?.addEventListener('click', (e) => { if (e.target.closest('a')) abrirMenu(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menu.classList.contains('aberto')) { abrirMenu(false); menuBotao.focus(); } });
  document.addEventListener('click', (e) => {
    if (menu.classList.contains('aberto') && !e.target.closest('#menu, #menu-botao')) abrirMenu(false);
  });

  /* ---------- Seção atual destacada no menu ---------- */
  const linksMenu = [...menu.querySelectorAll('a[href^="#"]')];
  const secoes = linksMenu.map((a) => document.querySelector(a.getAttribute('href'))).filter(Boolean);
  if ('IntersectionObserver' in window) {
    const obsMenu = new IntersectionObserver((entradas) => {
      entradas.forEach((en) => {
        if (!en.isIntersecting) return;
        linksMenu.forEach((a) => a.classList.toggle('atual', a.getAttribute('href') === '#' + en.target.id));
      });
    }, { rootMargin: '-45% 0px -50% 0px' });
    secoes.forEach((s) => obsMenu.observe(s));
  }

  /* ---------- Contadores ---------- */
  function contar(el, atraso = 0) {
    if (el.dataset.contado) return;
    el.dataset.contado = '1';
    const alvo = Number(el.dataset.contar);
    const casas = Number(el.dataset.casas || 0);
    const formatar = (v) => v.toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas });
    if (calmo) { el.textContent = formatar(alvo); return; }
    el.textContent = formatar(0); // o HTML traz o valor final para quem está sem JavaScript
    const duracao = 1400;
    setTimeout(() => {
      const inicio = performance.now();
      const passo = (agora) => {
        const t = Math.min(1, (agora - inicio) / duracao);
        const suave = 1 - Math.pow(1 - t, 3);
        el.textContent = formatar(alvo * suave);
        if (t < 1) requestAnimationFrame(passo);
      };
      requestAnimationFrame(passo);
    }, atraso);
  }
  function recontar(raiz) {
    raiz.querySelectorAll('[data-contar]').forEach((el) => { delete el.dataset.contado; contar(el); });
  }

  // KPI flutuante do herói entra junto com a animação da comanda
  document.querySelectorAll('.f-kpi [data-contar]').forEach((el) => contar(el, calmo ? 0 : 4300));

  /* ---------- Revelar ao rolar (com escalonamento em grades) ---------- */
  document.querySelectorAll('.caminho, .bento, .numeros-grade').forEach((grade) => {
    [...grade.children].forEach((filho, i) => filho.style.setProperty('--atraso', `${(i % 6) * 0.08}s`));
  });

  const revelar = [...document.querySelectorAll('.revelar')];
  if (!('IntersectionObserver' in window) || calmo) {
    revelar.forEach((el) => el.classList.add('visivel'));
    document.querySelectorAll('[data-contar]').forEach((el) => contar(el));
  } else {
    const obs = new IntersectionObserver((entradas) => {
      entradas.forEach((en) => {
        if (!en.isIntersecting) return;
        en.target.classList.add('visivel');
        en.target.querySelectorAll('.j-tela.ativa [data-contar], .numero [data-contar]').forEach((el) => contar(el));
        if (en.target.matches('.numero')) en.target.querySelectorAll('[data-contar]').forEach((el) => contar(el));
        obs.unobserve(en.target);
      });
    }, { threshold: 0.18, rootMargin: '0px 0px -8% 0px' });
    revelar.forEach((el) => obs.observe(el));
  }

  /* ---------- Prévia do sistema: abas que trocam sozinhas ---------- */
  const previa = document.getElementById('previa');
  if (previa) {
    const abas = [...previa.querySelectorAll('[role="tab"]')];
    const telas = abas.map((a) => document.getElementById(a.getAttribute('aria-controls')));
    const barra = document.getElementById('previa-tempo');
    let atual = 0;
    let timer = null;
    let visivel = false;
    let pausado = false;
    let escolhida = false; // depois que a pessoa escolhe uma aba, o rodízio automático para

    function mostrar(i, foco = false) {
      atual = (i + abas.length) % abas.length;
      abas.forEach((a, n) => {
        const ativa = n === atual;
        a.classList.toggle('ativa', ativa);
        a.setAttribute('aria-selected', String(ativa));
        a.tabIndex = ativa ? 0 : -1;
        telas[n].hidden = !ativa;
        telas[n].classList.toggle('ativa', ativa);
      });
      if (foco) abas[atual].focus();
      reanimar(telas[atual]);
      reiniciarTimer();
    }

    // reinicia as animações internas (gráfico, barras, comandas, mesas e números) da tela
    function reanimar(tela) {
      tela.querySelectorAll('.g-linha, .j-ranking b, .j-ticket, .j-mesas .m').forEach((el) => {
        el.style.animation = 'none';
        void el.offsetWidth;
        el.style.animation = '';
      });
      recontar(tela);
    }

    function reiniciarTimer() {
      clearTimeout(timer);
      barra.classList.remove('correndo');
      void barra.offsetWidth;
      if (calmo || !visivel || pausado || escolhida) return;
      barra.classList.add('correndo');
      timer = setTimeout(() => mostrar(atual + 1), 6000);
    }

    abas.forEach((a, n) => {
      a.addEventListener('click', () => { escolhida = true; mostrar(n); });
      a.addEventListener('keydown', (e) => {
        if (e.key === 'ArrowRight') { e.preventDefault(); escolhida = true; mostrar(atual + 1, true); }
        if (e.key === 'ArrowLeft') { e.preventDefault(); escolhida = true; mostrar(atual - 1, true); }
      });
    });
    previa.addEventListener('mouseenter', () => { pausado = true; reiniciarTimer(); });
    previa.addEventListener('mouseleave', () => { pausado = false; reiniciarTimer(); });
    previa.addEventListener('focusin', () => { pausado = true; reiniciarTimer(); });
    previa.addEventListener('focusout', () => { pausado = false; reiniciarTimer(); });

    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([en]) => {
        const antes = visivel;
        visivel = en.isIntersecting;
        if (visivel && !antes) reanimar(telas[atual]);
        reiniciarTimer();
      }, { threshold: 0.35 }).observe(previa);
    }
  }

  /* ---------- Registro de atividades "ao vivo" ---------- */
  const lista = document.getElementById('auditoria-lista');
  if (lista && !calmo) {
    const novos = [
      ['JP', 'Venda #1044 concluída no PDV'],
      ['AS', 'Pedido #1043 em preparo'],
      ['MO', 'Pedido #1045 criado para a mesa 4'],
      ['CM', 'Entrada registrada: Pão de Hambúrguer'],
      ['AS', 'Pedido #1043 pronto para servir'],
      ['JP', 'Conta da mesa 7 fechada · R$ 127,00'],
      ['CM', 'Relatório do mês exportado em PDF'],
      ['MO', 'Venda #1046 concluída no PDV'],
    ];
    let n = 0;
    let visivel = false;
    if ('IntersectionObserver' in window) {
      new IntersectionObserver(([en]) => { visivel = en.isIntersecting; }, { threshold: 0.3 }).observe(lista);
    }
    setInterval(() => {
      if (!visivel || document.hidden) return;
      const [iniciais, texto] = novos[n++ % novos.length];
      const li = document.createElement('li');
      li.className = 'nova';
      const agora = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
      li.innerHTML = '<time></time><b></b><span></span>';
      li.querySelector('time').textContent = agora;
      li.querySelector('b').textContent = iniciais;
      li.querySelector('span').textContent = texto;
      lista.prepend(li);
      while (lista.children.length > 7) lista.lastElementChild.remove();
    }, 3200);
  }
})();
