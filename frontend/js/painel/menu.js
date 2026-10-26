/* Menu lateral em telas pequenas: abre/fecha a gaveta (botão, fundo, Esc e clique em um link) */
(() => {
  const botao = document.getElementById('btn-menu');
  const menu = document.getElementById('menu-lateral');
  const fundo = document.getElementById('menu-fundo');
  if (!botao || !menu || !fundo) return;

  const celular = window.matchMedia('(max-width: 860px)');

  function definir(aberto) {
    menu.classList.toggle('aberto', aberto);
    fundo.hidden = !aberto;
    document.body.classList.toggle('menu-aberto', aberto);
    botao.setAttribute('aria-expanded', String(aberto));
    botao.setAttribute('aria-label', aberto ? 'Fechar menu' : 'Abrir menu');
    botao.querySelector('i').className = aberto ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
    if (aberto) menu.querySelector('.nav-item')?.focus();
    else if (celular.matches) botao.focus({ preventScroll: true });
  }

  botao.addEventListener('click', () => definir(!menu.classList.contains('aberto')));
  fundo.addEventListener('click', () => definir(false));
  menu.addEventListener('click', (e) => { if (e.target.closest('a') && celular.matches) definir(false); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && menu.classList.contains('aberto')) definir(false); });
  celular.addEventListener('change', (e) => { if (!e.matches) definir(false); });
})();
