/* Telas de acesso: mostrar senha, mensagens e força da senha. */
(() => {
  window.xtecMensagem = (tipo, texto) => {
    const caixa = document.getElementById('mensagem');
    if (!caixa) return;
    const icone = { erro: 'fa-triangle-exclamation', aviso: 'fa-circle-exclamation', ok: 'fa-circle-check' }[tipo] || 'fa-circle-info';
    caixa.className = 'mensagem ' + tipo;
    caixa.innerHTML = `<i class="fa-solid ${icone}" aria-hidden="true"></i><span></span>`;
    caixa.querySelector('span').textContent = texto;
    caixa.hidden = false;
  };

  document.querySelectorAll('.mostrar-senha').forEach((botao) => {
    botao.addEventListener('click', () => {
      const campo = document.getElementById(botao.dataset.alvo);
      const mostrar = campo.type === 'password';
      campo.type = mostrar ? 'text' : 'password';
      botao.setAttribute('aria-label', mostrar ? 'Esconder senha' : 'Mostrar senha');
      botao.querySelector('i').className = mostrar ? 'fa-regular fa-eye-slash' : 'fa-regular fa-eye';
      campo.focus();
    });
  });

  // força da senha: comprimento, números, letras maiúsculas/minúsculas e símbolos
  const senha = document.querySelector('[data-forca]');
  if (senha) {
    const barras = [...document.querySelectorAll('.forca span')];
    const texto = document.querySelector('.forca-texto');
    const cores = ['#d7262e', '#e3a21a', '#2f6fb2', '#2e8b57'];
    const rotulos = ['Fraca', 'Razoável', 'Boa', 'Forte'];
    senha.addEventListener('input', () => {
      const v = senha.value;
      let pontos = 0;
      if (v.length >= 8) pontos++;
      if (/\d/.test(v) && /\p{L}/u.test(v)) pontos++;
      if (/[a-z]/.test(v) && /[A-Z]/.test(v)) pontos++;
      if (/[^\p{L}\d]/u.test(v) || v.length >= 12) pontos++;
      barras.forEach((b, i) => { b.style.background = v && i < pontos ? cores[Math.max(pontos - 1, 0)] : ''; });
      texto.textContent = v ? `Senha ${rotulos[Math.max(pontos - 1, 0)].toLowerCase()}` + (v.length < 8 ? ' · use ao menos 8 caracteres' : '') : 'Ao menos 8 caracteres, com letras e números.';
    });
  }
})();
