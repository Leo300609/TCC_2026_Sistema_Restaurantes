'use strict';
/**
 * Exportações do relatório gerencial: PDF (pdf-lib), Excel (ExcelJS), Word (docx) e CSV.
 * Período por ?de=AAAA-MM-DD&ate=AAAA-MM-DD; só administradores e gerentes.
 */
const bd = require('./bd');
const config = require('./config');
const sessao = require('./sessao');
const { moeda, numero, dataBr, data, hoje, somarDias, validaData, formasPagamento } = require('./web');

const N = (v) => Number(v ?? 0) || 0;
const TIPOS = { SALAO: 'Salão', BALCAO: 'Balcão', DELIVERY: 'Delivery' };
const situacaoVenda = (s) => (s === 'FINALIZADA' ? 'Entregue' : s === 'CANCELADA' ? 'Cancelado' : 'Em aberto');

const negado = (status, texto) => ({ status, tipo: 'text/plain; charset=utf-8', corpo: texto });

/** Confere a permissão, lê o período e busca os dados nas procedures. */
async function dadosRelatorio(ctx) {
  let u;
  try { u = await sessao.autenticar(ctx, { api: true }); } catch (_) { return { erro: negado(403, 'Acesso negado: somente administradores e gerentes exportam relatórios.') }; }
  if (!u.perm.relatorios) return { erro: negado(403, 'Acesso negado: somente administradores e gerentes exportam relatórios.') };

  let de = String(ctx.query.de || ''), ate = String(ctx.query.ate || '');
  if (!validaData(de) || !validaData(ate)) { de = somarDias(hoje(), -29); ate = hoje(); }
  if (de > ate) [de, ate] = [ate, de];

  try {
    const [empresa, resumo, produtos, formas, tipos, atendentes, vendas] = await Promise.all([
      bd.chamarUm('sp_empresa_obter'),
      bd.chamarUm('sp_rel_resumo', [de, ate]),
      bd.chamar('sp_rel_produtos', [de, ate, 30]),
      bd.chamar('sp_rel_formas', [de, ate]),
      bd.chamar('sp_rel_tipos', [de, ate]),
      bd.chamar('sp_rel_atendentes', [de, ate]),
      bd.chamar('sp_rel_vendas_lista', [de, ate]),
    ]);
    const r = resumo || {};
    const rel = {
      de, ate, empresa: empresa || { nome_fantasia: config.appNome }, resumo: r, produtos, formas, tipos, atendentes, vendas,
      gerado_por: String(ctx.sessao.usuario_nome || ''), gerado_em: data('d/m/Y H:i'),
    };
    rel.ticket = N(r.pedidos) ? N(r.faturamento) / N(r.pedidos) : 0;
    rel.lucro_bruto = N(r.faturamento) - N(r.cmv);
    rel.resultado = rel.lucro_bruto - N(r.despesas_operacionais);
    rel.nome_arquivo = `relatorio_xtec_${de}_a_${ate}`;
    return { rel };
  } catch (err) {
    console.error('[xtec] exportação:', err.message);
    return { erro: negado(500, 'Não foi possível gerar o relatório agora. Tente novamente em instantes.') };
  }
}

/** Linhas do quadro de indicadores, na mesma ordem em todos os formatos. */
function indicadores(rel) {
  const r = rel.resumo;
  return [
    ['Faturamento', moeda(r.faturamento)],
    ['Pedidos entregues', numero(r.pedidos)],
    ['Ticket médio', moeda(rel.ticket)],
    ['Itens vendidos', numero(r.itens)],
    ['Descontos concedidos', moeda(r.descontos)],
    ['Cancelamentos', `${numero(r.cancelados)} (${moeda(r.valor_cancelado)})`],
    ['Custo dos insumos (estimado)', moeda(r.cmv)],
    ['Lucro bruto', moeda(rel.lucro_bruto)],
    ['Despesas operacionais pagas', moeda(r.despesas_operacionais)],
    ['Resultado estimado', moeda(rel.resultado)],
  ];
}

async function registrar(ctx, rel, formato) {
  await sessao.registrarAtividade(ctx, 'relatorios', 'exportar', `Exportou relatório de ${dataBr(rel.de)} a ${dataBr(rel.ate)} (${formato})`);
}

// ---------------------------------------------------------------- CSV
async function csv(ctx) {
  const { rel, erro } = await dadosRelatorio(ctx);
  if (erro) return erro;
  const formas = formasPagamento();
  const decimal = (v) => N(v).toFixed(2).replace('.', ',');
  const campo = (v) => {
    const t = String(v ?? '');
    return /[";\n\r]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t;
  };
  const linhas = [['Pedido', 'Data e hora', 'Canal', 'Mesa', 'Cliente', 'Atendente', 'Pagamento', 'Itens', 'Valor', 'Desconto', 'Total', 'Situação']];
  for (const v of rel.vendas) {
    linhas.push([v.id, v.data_hora, TIPOS[v.tipo] || v.tipo, v.mesa, v.cliente, v.atendente, (formas[v.forma] || [v.forma])[0], v.itens,
      decimal(v.valor), decimal(v.desconto), decimal(v.total), situacaoVenda(v.status)]);
  }
  await registrar(ctx, rel, 'csv');
  return {
    tipo: 'text/csv; charset=utf-8',
    corpo: '﻿' + linhas.map((l) => l.map(campo).join(';')).join('\r\n') + '\r\n',
    cabecalhos: { 'Content-Disposition': `attachment; filename="vendas_${rel.de}_a_${rel.ate}.csv"` },
  };
}

// ---------------------------------------------------------------- Excel
async function excel(ctx) {
  const { rel, erro } = await dadosRelatorio(ctx);
  if (erro) return erro;
  const ExcelJS = require('exceljs');
  const formas = formasPagamento();
  const FORMATO_MOEDA = '"R$" #,##0.00';
  const cabecalho = (linha) => {
    linha.font = { bold: true, color: { argb: 'FFFFFFFF' } };
    linha.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1C1F24' } };
  };

  const wb = new ExcelJS.Workbook();
  wb.creator = 'Sistema XTEC';
  wb.title = 'Relatório gerencial';

  // Aba 1: resumo
  const resumo = wb.addWorksheet('Resumo');
  resumo.getCell('A1').value = `${rel.empresa.nome_fantasia || 'XTEC'} · Relatório gerencial`;
  resumo.getCell('A1').font = { bold: true, size: 15, color: { argb: 'FFB81F26' } };
  resumo.getCell('A2').value = `Período: ${dataBr(rel.de)} a ${dataBr(rel.ate)} · gerado em ${rel.gerado_em} por ${rel.gerado_por}`;
  cabecalho(resumo.getRow(4)); resumo.getRow(4).values = ['Indicador', 'Valor']; cabecalho(resumo.getRow(4));
  let linha = 4;
  for (const [rotulo, valor] of indicadores(rel)) { linha++; resumo.getRow(linha).values = [rotulo, valor]; }
  linha += 2;
  resumo.getRow(linha).values = ['Forma de pagamento', 'Pedidos', 'Total']; cabecalho(resumo.getRow(linha));
  for (const f of rel.formas) { linha++; resumo.getRow(linha).values = [(formas[f.forma] || [f.forma])[0], N(f.pedidos), N(f.total)]; resumo.getCell(`C${linha}`).numFmt = FORMATO_MOEDA; }
  linha += 2;
  resumo.getRow(linha).values = ['Canal de venda', 'Pedidos', 'Total']; cabecalho(resumo.getRow(linha));
  for (const t of rel.tipos) { linha++; resumo.getRow(linha).values = [TIPOS[t.tipo] || t.tipo, N(t.pedidos), N(t.total)]; resumo.getCell(`C${linha}`).numFmt = FORMATO_MOEDA; }
  resumo.columns = [{ width: 38 }, { width: 22 }, { width: 18 }];

  // Aba 2: todas as vendas do período
  const vendas = wb.addWorksheet('Vendas', { views: [{ state: 'frozen', ySplit: 1 }] });
  vendas.columns = [
    { header: 'Pedido', width: 9 }, { header: 'Data e hora', width: 18 }, { header: 'Canal', width: 11 }, { header: 'Mesa', width: 7 },
    { header: 'Cliente', width: 24 }, { header: 'Atendente', width: 22 }, { header: 'Pagamento', width: 12 }, { header: 'Itens', width: 48 },
    { header: 'Valor', width: 13 }, { header: 'Desconto', width: 13 }, { header: 'Total', width: 13 }, { header: 'Situação', width: 12 },
  ];
  cabecalho(vendas.getRow(1));
  for (const v of rel.vendas) {
    vendas.addRow([N(v.id), v.data_hora, TIPOS[v.tipo] || v.tipo, String(v.mesa ?? ''), v.cliente, v.atendente, (formas[v.forma] || [v.forma])[0],
      String(v.itens ?? ''), N(v.valor), N(v.desconto), N(v.total), situacaoVenda(v.status)]);
  }
  for (const col of ['I', 'J', 'K']) vendas.getColumn(col).numFmt = FORMATO_MOEDA;
  vendas.getRow(1).getCell('I').numFmt = 'General'; vendas.getRow(1).getCell('J').numFmt = 'General'; vendas.getRow(1).getCell('K').numFmt = 'General';
  if (rel.vendas.length) vendas.autoFilter = { from: 'A1', to: `L${rel.vendas.length + 1}` };

  // Aba 3: produtos
  const produtos = wb.addWorksheet('Produtos');
  produtos.columns = [
    { header: 'Produto', width: 30 }, { header: 'Categoria', width: 16 }, { header: 'Quantidade', width: 12 },
    { header: 'Receita', width: 14 }, { header: 'Custo estimado', width: 16 }, { header: 'Margem', width: 10 },
  ];
  cabecalho(produtos.getRow(1));
  for (const p of rel.produtos) {
    const receita = N(p.receita), custo = N(p.custo);
    produtos.addRow([p.nome, p.categoria, N(p.quantidade), receita, custo, receita > 0 && custo > 0 ? (receita - custo) / receita : null]);
  }
  produtos.getColumn('D').numFmt = FORMATO_MOEDA; produtos.getColumn('E').numFmt = FORMATO_MOEDA; produtos.getColumn('F').numFmt = '0.0%';
  for (const c of ['D', 'E', 'F']) produtos.getRow(1).getCell(c).numFmt = 'General';

  await registrar(ctx, rel, 'excel');
  return {
    tipo: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    corpo: Buffer.from(await wb.xlsx.writeBuffer()),
    cabecalhos: { 'Content-Disposition': `attachment; filename="${rel.nome_arquivo}.xlsx"` },
  };
}

// ---------------------------------------------------------------- Word
async function word(ctx) {
  const { rel, erro } = await dadosRelatorio(ctx);
  if (erro) return erro;
  const D = require('docx');
  const formas = formasPagamento();
  const BORDA = { style: D.BorderStyle.SINGLE, size: 4, color: 'C9CEC6' };
  const bordas = { top: BORDA, bottom: BORDA, left: BORDA, right: BORDA };

  const celula = (texto, largura, { cab = false, dir = false } = {}) => new D.TableCell({
    width: { size: largura, type: D.WidthType.DXA },
    borders: bordas,
    shading: cab ? { type: D.ShadingType.CLEAR, color: 'auto', fill: '1C1F24' } : undefined,
    margins: { top: 50, bottom: 50, left: 90, right: 90 },
    children: [new D.Paragraph({
      alignment: dir ? D.AlignmentType.RIGHT : D.AlignmentType.LEFT,
      children: [new D.TextRun({ text: String(texto ?? ''), bold: cab, color: cab ? 'FFFFFF' : undefined })],
    })],
  });
  const tabela = (titulo, colunas, linhas, larguras, numericas = []) => [
    new D.Paragraph({ heading: D.HeadingLevel.HEADING_2, spacing: { before: 240, after: 80 }, children: [new D.TextRun({ text: titulo, bold: true, color: '1C1F24', size: 26 })] }),
    new D.Table({
      width: { size: larguras.reduce((a, b) => a + b, 0), type: D.WidthType.DXA },
      columnWidths: larguras,
      rows: [
        new D.TableRow({ tableHeader: true, children: colunas.map((c, i) => celula(c, larguras[i], { cab: true, dir: numericas.includes(i) })) }),
        ...(linhas.length ? linhas : [['Sem dados no período.', ...new Array(colunas.length - 1).fill('')]]).map((l) => new D.TableRow({
          children: l.map((v, i) => celula(v, larguras[i], { dir: numericas.includes(i) })),
        })),
      ],
    }),
  ];

  const filhos = [
    new D.Paragraph({ heading: D.HeadingLevel.HEADING_1, children: [new D.TextRun({ text: `${rel.empresa.nome_fantasia || 'XTEC'} · Relatório gerencial`, bold: true, color: 'B81F26', size: 36 })] }),
    new D.Paragraph({ children: [new D.TextRun({ text: `Período: ${dataBr(rel.de)} a ${dataBr(rel.ate)}`, color: '6B7380' })] }),
    ...tabela('Indicadores do período', ['Indicador', 'Valor'], indicadores(rel), [6000, 3600], [1]),
    ...tabela('Produtos mais vendidos', ['Produto', 'Categoria', 'Qtd.', 'Receita'],
      rel.produtos.slice(0, 15).map((p) => [p.nome, p.categoria, numero(p.quantidade), moeda(p.receita)]), [3800, 2400, 1200, 2200], [2, 3]),
    ...tabela('Formas de pagamento', ['Forma', 'Pedidos', 'Total'],
      rel.formas.map((f) => [(formas[f.forma] || [f.forma])[0], numero(f.pedidos), moeda(f.total)]), [4600, 2200, 2800], [1, 2]),
    ...tabela('Canais de venda', ['Canal', 'Pedidos', 'Total'],
      rel.tipos.map((t) => [TIPOS[t.tipo] || t.tipo, numero(t.pedidos), moeda(t.total)]), [4600, 2200, 2800], [1, 2]),
    ...tabela('Desempenho da equipe', ['Atendente', 'Pedidos', 'Ticket médio', 'Total'],
      rel.atendentes.map((a) => [a.nome, numero(a.pedidos), moeda(a.ticket), moeda(a.total)]), [3800, 1600, 2000, 2200], [1, 2, 3]),
  ];

  const doc = new D.Document({
    creator: 'Sistema XTEC',
    title: 'Relatório gerencial',
    styles: { default: { document: { run: { font: 'Calibri', size: 21 } } } },
    sections: [{
      properties: { page: { margin: { top: 900, bottom: 900, left: 1000, right: 1000 } } },
      footers: {
        default: new D.Footer({
          children: [new D.Paragraph({ alignment: D.AlignmentType.CENTER, children: [new D.TextRun({ text: `Gerado em ${rel.gerado_em} por ${rel.gerado_por} · Sistema XTEC`, size: 16, color: '6B7380' })] })],
        }),
      },
      children: filhos,
    }],
  });
  await registrar(ctx, rel, 'word');
  return {
    tipo: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    corpo: await D.Packer.toBuffer(doc),
    cabecalhos: { 'Content-Disposition': `attachment; filename="${rel.nome_arquivo}.docx"` },
  };
}

// ---------------------------------------------------------------- PDF
async function pdf(ctx) {
  const { rel, erro } = await dadosRelatorio(ctx);
  if (erro) return erro;
  const { PDFDocument, StandardFonts, rgb } = require('pdf-lib');
  const formas = formasPagamento();

  const doc = await PDFDocument.create();
  doc.setTitle('Relatório gerencial');
  doc.setCreator('Sistema XTEC');
  const fonte = await doc.embedFont(StandardFonts.Helvetica);
  const negrito = await doc.embedFont(StandardFonts.HelveticaBold);
  const L = 595, A = 842, M = 34;
  const VERMELHO = rgb(0.84, 0.15, 0.18), ESCURO = rgb(0.11, 0.12, 0.14), CINZA = rgb(0.42, 0.45, 0.5), LINHA = rgb(0.88, 0.89, 0.87), BRANCO = rgb(1, 1, 1);
  // a fonte padrão do PDF só tem o alfabeto latino: troca o que não existe nela
  const seguro = (t) => String(t ?? '').replace(/[−–—]/g, '-').replace(/[“”]/g, '"').replace(/[‘’]/g, "'").replace(/[^\x20-\x7E -ÿ]/g, '?');

  let pagina = doc.addPage([L, A]);
  let y = A - M;
  const novaPagina = () => { pagina = doc.addPage([L, A]); y = A - M; };
  const garante = (altura) => { if (y - altura < M + 20) novaPagina(); };
  const corta = (texto, f, tam, largura) => {
    let t = seguro(texto);
    if (f.widthOfTextAtSize(t, tam) <= largura) return t;
    while (t.length > 1 && f.widthOfTextAtSize(t + '...', tam) > largura) t = t.slice(0, -1);
    return t + '...';
  };

  // cabeçalho
  pagina.drawText(corta(`${rel.empresa.nome_fantasia || config.appNome} · Relatório gerencial`, negrito, 19, L - 2 * M), { x: M, y: y - 18, size: 19, font: negrito, color: ESCURO });
  pagina.drawText(seguro(`Período: ${dataBr(rel.de)} a ${dataBr(rel.ate)}`), { x: M, y: y - 34, size: 10.5, font: fonte, color: CINZA });
  pagina.drawRectangle({ x: M, y: y - 44, width: L - 2 * M, height: 2.5, color: VERMELHO });
  y -= 62;

  const titulo = (t) => { garante(48); pagina.drawText(seguro(t), { x: M, y: y - 12, size: 13, font: negrito, color: rgb(0.72, 0.12, 0.15) }); y -= 24; };
  /** Tabela: colunas [{ rotulo, largura, dir }], linhas de textos. */
  const tabela = (colunas, linhas) => {
    const total = colunas.reduce((s, c) => s + c.largura, 0);
    const desenhaCab = () => {
      garante(40);
      pagina.drawRectangle({ x: M, y: y - 17, width: total, height: 18, color: ESCURO });
      let x = M;
      for (const c of colunas) {
        const t = seguro(c.rotulo);
        pagina.drawText(t, { x: c.dir ? x + c.largura - 6 - negrito.widthOfTextAtSize(t, 9) : x + 6, y: y - 12, size: 9, font: negrito, color: BRANCO });
        x += c.largura;
      }
      y -= 18;
    };
    desenhaCab();
    const dados = linhas.length ? linhas : [['Sem dados no período.', ...new Array(colunas.length - 1).fill('')]];
    for (const l of dados) {
      if (y - 18 < M + 24) { novaPagina(); desenhaCab(); }
      let x = M;
      l.forEach((v, i) => {
        const c = colunas[i];
        const t = corta(v, fonte, 9.5, c.largura - 12);
        pagina.drawText(t, { x: c.dir ? x + c.largura - 6 - fonte.widthOfTextAtSize(t, 9.5) : x + 6, y: y - 12, size: 9.5, font: fonte, color: ESCURO });
        x += c.largura;
      });
      pagina.drawLine({ start: { x: M, y: y - 17 }, end: { x: M + total, y: y - 17 }, thickness: 0.6, color: LINHA });
      y -= 18;
    }
    y -= 6;
  };
  const W = L - 2 * M;

  titulo('Indicadores do período');
  tabela([{ rotulo: 'Indicador', largura: W * 0.62 }, { rotulo: 'Valor', largura: W * 0.38, dir: true }], indicadores(rel));
  titulo('Produtos mais vendidos');
  tabela([{ rotulo: '#', largura: 28 }, { rotulo: 'Produto', largura: W - 28 - 70 - 100 }, { rotulo: 'Qtd.', largura: 70, dir: true }, { rotulo: 'Receita', largura: 100, dir: true }],
    rel.produtos.slice(0, 15).map((p, n) => [String(n + 1), `${p.nome} (${p.categoria})`, numero(p.quantidade), moeda(p.receita)]));
  titulo('Formas de pagamento');
  tabela([{ rotulo: 'Forma', largura: W * 0.5 }, { rotulo: 'Pedidos', largura: W * 0.2, dir: true }, { rotulo: 'Total', largura: W * 0.3, dir: true }],
    rel.formas.map((f) => [(formas[f.forma] || [f.forma])[0], numero(f.pedidos), moeda(f.total)]));
  titulo('Canais de venda');
  tabela([{ rotulo: 'Canal', largura: W * 0.5 }, { rotulo: 'Pedidos', largura: W * 0.2, dir: true }, { rotulo: 'Total', largura: W * 0.3, dir: true }],
    rel.tipos.map((t) => [TIPOS[t.tipo] || t.tipo, numero(t.pedidos), moeda(t.total)]));
  titulo('Desempenho da equipe');
  tabela([{ rotulo: 'Atendente', largura: W * 0.4 }, { rotulo: 'Pedidos', largura: W * 0.15, dir: true }, { rotulo: 'Ticket médio', largura: W * 0.2, dir: true }, { rotulo: 'Total', largura: W * 0.25, dir: true }],
    rel.atendentes.map((a) => [a.nome, numero(a.pedidos), moeda(a.ticket), moeda(a.total)]));

  // rodapé em todas as páginas
  const paginas = doc.getPages();
  paginas.forEach((p, i) => {
    const t = seguro(`Gerado em ${rel.gerado_em} por ${rel.gerado_por} · Sistema XTEC de gestão para restaurantes · página ${i + 1} de ${paginas.length}`);
    p.drawText(t, { x: (L - fonte.widthOfTextAtSize(t, 8)) / 2, y: 20, size: 8, font: fonte, color: CINZA });
  });

  await registrar(ctx, rel, 'pdf');
  return {
    tipo: 'application/pdf',
    corpo: Buffer.from(await doc.save()),
    cabecalhos: { 'Content-Disposition': `attachment; filename="${rel.nome_arquivo}.pdf"` },
  };
}

module.exports = { csv, excel, word, pdf };
