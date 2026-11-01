'use strict';
/**
 * Servidor local: node servidor.js  →  http://localhost:3000
 * Serve os arquivos estáticos de frontend/ e as rotas dinâmicas de servidor/app.js.
 * Sem DATABASE_URL usa o Postgres embutido (.dados-locais/); instale-o antes com
 *   npm run banco:instalar && npm run banco:demo
 */
const http = require('http');
const fs = require('fs');
const path = require('path');
const { handler } = require('./servidor/app');

const PORTA = Number(process.env.PORT || 3000);
const TIPOS = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp', '.ico': 'image/x-icon',
  '.woff2': 'font/woff2', '.json': 'application/json',
};

http.createServer((req, res) => {
  const { pathname } = new URL(req.url, 'http://localhost');
  if (pathname === '/') { res.statusCode = 302; res.setHeader('Location', '/frontend/html/index.html'); res.end(); return; }
  const rel = pathname;
  if (rel.startsWith('/frontend/')) {
    const arquivo = path.join(__dirname, 'public', path.normalize(rel));
    if (arquivo.startsWith(path.join(__dirname, 'public', 'frontend')) && fs.existsSync(arquivo) && fs.statSync(arquivo).isFile()) {
      res.setHeader('Content-Type', TIPOS[path.extname(arquivo)] || 'application/octet-stream');
      fs.createReadStream(arquivo).pipe(res);
      return;
    }
  }
  handler(req, res);
}).listen(PORTA, () => console.log(`XTEC em http://localhost:${PORTA}`));
