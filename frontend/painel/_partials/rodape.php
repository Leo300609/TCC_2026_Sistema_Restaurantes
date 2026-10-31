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

  <script src="../js/painel/app.js?v=<?= e(config('app_versao')) ?>" defer></script>
  <?= $rodapeExtra ?? '' ?>
</body>

</html>
