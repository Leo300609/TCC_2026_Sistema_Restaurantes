/* Tabelas responsivas: copia o título de cada coluna para o atributo data-rotulo das células,
   para o CSS mostrar "Rótulo: valor" quando a tabela vira lista de cartões no celular. */
(() => {
  function rotular(tabela) {
    const titulos = [...tabela.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    tabela.querySelectorAll('tbody tr').forEach((linha) => {
      [...linha.children].forEach((celula, i) => {
        if (celula.colSpan > 1 || celula.classList.contains('acoes')) return;
        if (titulos[i] && celula.dataset.rotulo !== titulos[i]) celula.dataset.rotulo = titulos[i];
      });
    });
  }

  const atualizar = () => document.querySelectorAll('table.tabela, table.tabela-pedidos').forEach(rotular);

  let agendado = false;
  new MutationObserver(() => {
    if (agendado) return;
    agendado = true;
    requestAnimationFrame(() => { agendado = false; atualizar(); });
  }).observe(document.body, { childList: true, subtree: true });

  atualizar();
})();
