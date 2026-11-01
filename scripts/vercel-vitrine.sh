#!/usr/bin/env bash
# Monta a vitrine estática publicada no Vercel: landing page, login e cadastro.
# O painel (PHP + MySQL) não roda no Vercel; ele é instalado com o WampServer.
# Só arquivos públicos entram na pasta publicada: nenhum .php sai daqui.
set -euo pipefail

rm -rf vitrine
mkdir -p vitrine/frontend/css vitrine/frontend/js
cp -r frontend/html frontend/assets vitrine/frontend/
cp -r frontend/css/publico vitrine/frontend/css/
cp -r frontend/js/publico vitrine/frontend/js/

# avisa as páginas que estão na vitrine (formulários mostram como instalar)
echo 'window.XTEC_VITRINE = true;' > vitrine/frontend/js/publico/ambiente.js

echo "Vitrine pronta:"
find vitrine -type f | sort
