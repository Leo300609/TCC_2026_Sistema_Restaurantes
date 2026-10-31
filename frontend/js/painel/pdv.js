/* PDV: carrinho da comanda, totais e troco. O carrinho fica salvo na aba
   (sessionStorage) para não se perder num recarregamento acidental. */
(() => {
  'use strict';

  const form = document.getElementById('pdv');
  if (!form) return;

  const CHAVE = 'xtec-pdv-carrinho';
  const lista = document.getElementById('carrinho');
  const campos = document.getElementById('carrinho-campos');
  const busca = document.getElementById('pdv-busca');
  const desconto = document.getElementById('pdv-desconto');
  const recebido = document.getElementById('pdv-recebido');
  const btnCozinha = document.getElementById('btn-cozinha');
  const btnCobrar = document.getElementById('btn-cobrar');
  const produtos = [...document.querySelectorAll('.pdv-produto')];
  const moeda = (v) => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const escapar = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

  let carrinho = [];
  try {
    if (form.dataset.limpar === '1') sessionStorage.removeItem(CHAVE);
    carrinho = JSON.parse(sessionStorage.getItem(CHAVE) || '[]');
  } catch (e) { carrinho = []; }

  const salvar = () => { try { sessionStorage.setItem(CHAVE, JSON.stringify(carrinho)); } catch (e) { /* ignora */ } };

  function adicionar(botao) {
    const id = Number(botao.dataset.id);
    const limite = botao.dataset.limite === '' ? Infinity : Number(botao.dataset.limite);
    const item = carrinho.find((i) => i.id === id);
    if (item) {
      if (item.qtd >= limite) return window.xtecAviso?.(`Só há estoque para ${limite} de ${item.nome}.`, 'erro');
      item.qtd++;
    } else {
      carrinho.push({ id, nome: botao.dataset.nome, preco: Number(botao.dataset.preco), qtd: 1, obs: '', limite });
    }
    desenhar();
  }

  function desenhar() {
    if (!carrinho.length) {
      lista.innerHTML = '<li class="carrinho-vazio">Toque nos itens ao lado para montar a comanda.</li>';
    } else {
      lista.innerHTML = carrinho.map((i, n) => `
        <li class="carrinho-item" data-n="${n}">
          <strong>${escapar(i.nome)}</strong>
          <span class="subtotal">${moeda(i.preco * i.qtd)}</span>
          <span class="controles">
            <button type="button" data-acao="menos" aria-label="Diminuir ${escapar(i.nome)}"><i class="fa-solid ${i.qtd > 1 ? 'fa-minus' : 'fa-trash-can'}" aria-hidden="true"></i></button>
            <span aria-label="Quantidade">${i.qtd}</span>
            <button type="button" data-acao="mais" aria-label="Aumentar ${escapar(i.nome)}"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
            <small style="color:#8a7f69;margin-left:6px">${moeda(i.preco)} un.</small>
          </span>
          <span></span>
          <input class="obs" type="text" maxlength="255" placeholder="Observação (ex.: sem cebola)" value="${escapar(i.obs)}" data-acao="obs" aria-label="Observação de ${escapar(i.nome)}">
        </li>`).join('');
    }

    // campos enviados no POST (mesmo formato da tela de Pedidos)
    campos.innerHTML = carrinho.map((i) => `
      <input type="hidden" name="itens_produto[]" value="${i.id}">
      <input type="hidden" name="itens_qtd[]" value="${i.qtd}">
      <input type="hidden" name="itens_obs[]" value="${escapar(i.obs)}">`).join('');

    // contador nos cartões do catálogo
    produtos.forEach((p) => {
      const item = carrinho.find((i) => i.id === Number(p.dataset.id));
      let selo = p.querySelector('.no-carrinho');
      if (item) {
        if (!selo) { selo = document.createElement('span'); selo.className = 'no-carrinho'; p.appendChild(selo); }
        selo.textContent = item.qtd;
      } else {
        selo?.remove();
      }
    });

    atualizarTotais();
    salvar();
  }

  function atualizarTotais() {
    const subtotal = carrinho.reduce((s, i) => s + i.preco * i.qtd, 0);
    const desc = Math.min(Math.max(Number(desconto.value) || 0, 0), subtotal);
    const total = subtotal - desc;
    document.getElementById('t-subtotal').textContent = moeda(subtotal);
    document.getElementById('t-total').textContent = moeda(total);

    const forma = form.querySelector('input[name=forma_pagamento]:checked')?.value;
    const dinheiro = forma === 'DINHEIRO';
    document.getElementById('campo-recebido').hidden = !dinheiro;
    const linhaTroco = document.getElementById('linha-troco');
    const valorRecebido = Number(recebido.value) || 0;
    linhaTroco.hidden = !(dinheiro && valorRecebido > 0);
    document.getElementById('t-troco').textContent = moeda(Math.max(valorRecebido - total, 0));
    linhaTroco.style.color = valorRecebido && valorRecebido < total ? '#b0261e' : '';

    const vazio = carrinho.length === 0;
    btnCozinha.disabled = vazio;
    btnCobrar.disabled = vazio || (dinheiro && valorRecebido > 0 && valorRecebido < total);
    btnCobrar.innerHTML = `<i class="fa-solid fa-check" aria-hidden="true"></i> Cobrar ${vazio ? 'agora' : moeda(total)}`;
  }

  lista.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-acao]');
    if (!b) return;
    const item = carrinho[Number(b.closest('.carrinho-item').dataset.n)];
    if (b.dataset.acao === 'mais') {
      if (item.qtd >= item.limite) return window.xtecAviso?.(`Só há estoque para ${item.limite} de ${item.nome}.`, 'erro');
      item.qtd++;
    } else if (--item.qtd <= 0) {
      carrinho.splice(carrinho.indexOf(item), 1);
    }
    desenhar();
  });
  lista.addEventListener('input', (e) => {
    if (e.target.dataset.acao !== 'obs') return;
    carrinho[Number(e.target.closest('.carrinho-item').dataset.n)].obs = e.target.value;
    campos.querySelectorAll('input[name="itens_obs[]"]').forEach((c, n) => { c.value = carrinho[n].obs; });
    salvar();
  });

  produtos.forEach((p) => p.addEventListener('click', () => adicionar(p)));

  // filtros por categoria e busca
  let categoria = '';
  function filtrar() {
    const termo = busca.value.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    let visiveis = 0;
    produtos.forEach((p) => {
      const nome = p.dataset.nome.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
      const ok = (!categoria || p.dataset.categoria === categoria) && (!termo || nome.includes(termo) || (p.dataset.codigo && p.dataset.codigo.includes(termo)));
      p.hidden = !ok;
      if (ok) visiveis++;
    });
    document.getElementById('pdv-nada').hidden = visiveis > 0;
  }
  document.querySelectorAll('[data-categoria]').forEach((b) => b.addEventListener('click', () => {
    if (!b.matches('.filtro')) return;
    categoria = b.dataset.categoria;
    document.querySelectorAll('.filtros .filtro').forEach((f) => f.classList.toggle('ativo', f === b));
    filtrar();
  }));
  busca.addEventListener('input', filtrar);
  // Enter na busca: código de barras exato ou único resultado vai direto para a comanda
  busca.addEventListener('keydown', (e) => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const termo = busca.value.trim();
    const visiveis = produtos.filter((p) => !p.hidden && !p.disabled);
    const alvo = produtos.find((p) => p.dataset.codigo && p.dataset.codigo === termo) || (visiveis.length === 1 ? visiveis[0] : null);
    if (alvo) { adicionar(alvo); busca.value = ''; filtrar(); }
  });

  // tipo do pedido mostra/esconde mesa e cliente
  function atualizarTipo() {
    const tipo = form.querySelector('input[name=tipo_pedido]:checked')?.value || 'BALCAO';
    document.querySelectorAll('#pdv-destino [data-mostrar]').forEach((el) => {
      el.hidden = !el.dataset.mostrar.split(' ').includes(tipo);
      el.style.gridColumn = tipo === 'SALAO' || el.dataset.mostrar === 'SALAO' ? '' : 'span 2';
    });
    document.getElementById('pdv-mesa').required = tipo === 'SALAO';
    document.querySelector('#pdv-cliente option[value="0"]').textContent = tipo === 'DELIVERY' ? 'Escolha o cliente da entrega' : 'Cliente não identificado';
  }
  form.querySelectorAll('input[name=tipo_pedido]').forEach((r) => r.addEventListener('change', atualizarTipo));
  form.querySelectorAll('input[name=forma_pagamento]').forEach((r) => r.addEventListener('change', atualizarTotais));
  desconto.addEventListener('input', atualizarTotais);
  recebido.addEventListener('input', atualizarTotais);

  document.getElementById('btn-limpar').addEventListener('click', () => {
    carrinho = [];
    desconto.value = 0;
    recebido.value = '';
    desenhar();
  });

  form.addEventListener('submit', (e) => {
    if (!carrinho.length) { e.preventDefault(); return; }
  });

  // F2 foca a busca
  document.addEventListener('keydown', (e) => {
    if (e.key === 'F2') { e.preventDefault(); busca.focus(); busca.select(); }
  });

  // relógio da comanda
  const relogio = document.getElementById('pdv-relogio');
  setInterval(() => { relogio.textContent = new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }); }, 15000);

  atualizarTipo();
  desenhar();
})();
