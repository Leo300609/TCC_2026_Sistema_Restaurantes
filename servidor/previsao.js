'use strict';
/**
 * Previsões de vendas e de estoque.
 *
 * Métodos (escolhidos por serem explicáveis e funcionarem com poucas semanas de dados):
 *
 * 1. Tendência por produto: compara as duas metades da janela (ex.: últimos 14 dias
 *    x 14 dias anteriores) e ajusta uma regressão linear simples sobre as vendas
 *    diárias (y = a + b·x). A inclinação b diz se o item está subindo ou caindo.
 *
 * 2. Previsão de unidades (7 dias): média entre a reta da regressão projetada e a
 *    média diária recente. Misturar as duas evita exageros quando a reta é íngreme.
 *
 * 3. Previsão de faturamento por dia: sazonalidade semanal (média ponderada do mesmo
 *    dia da semana nas últimas 4 semanas, com mais peso para as recentes) multiplicada
 *    por um fator de tendência amortecido. A faixa mostrada é ±1,28 desvio-padrão do
 *    mesmo dia da semana (cerca de 80% de confiança).
 *
 * 4. Reposição: consumo diário dos insumos pela ficha técnica, ajustado pela
 *    tendência geral de vendas, dá os dias até acabar e a quantidade a comprar para
 *    cobrir duas semanas acima do estoque mínimo.
 */
const { hoje, somarDias } = require('./web');

const N = (v) => Number(v ?? 0) || 0;

/** Coeficientes da regressão linear a partir das somas: devolve [a, b]. */
function regressaoLinear(n, sx, sy, sxy, sxx) {
  const den = n * sxx - sx * sx;
  if (n < 2 || Math.abs(den) < 1e-9) return [n > 0 ? sy / n : 0, 0];
  const b = (n * sxy - sx * sy) / den;
  const a = (sy - b * sx) / n;
  return [a, b];
}

/** Classifica cada item do cardápio e projeta as vendas dos próximos 7 dias. */
function analisarTendencias(linhas, janela) {
  return linhas.map((l) => {
    const atual = N(l.atual), anterior = N(l.anterior);
    const [a, b] = regressaoLinear(N(l.n), N(l.sx), N(l.sy), N(l.sxy), N(l.sxx));

    // reta projetada para os próximos 7 dias (x = 2·janela ... 2·janela + 6)
    let reta = 0;
    for (let k = 0; k < 7; k++) reta += Math.max(0, a + b * (janela * 2 + k));
    const media7 = (atual / Math.max(janela, 1)) * 7;
    const previsto = Math.max(0, 0.5 * reta + 0.5 * media7);

    const variacao = anterior > 0 ? ((atual - anterior) / anterior) * 100 : atual > 0 ? 100 : 0;
    const mediaDia = N(l.sy) / Math.max(N(l.n), 1);
    // inclinação relativa: quanto a venda diária muda por semana, em % da média
    const ritmo = mediaDia > 0 ? ((b * 7) / mediaDia) * 100 : 0;

    let classe = 'ESTAVEL';
    if (anterior < 15 && atual >= Math.max(10, anterior * 2)) classe = 'EMERGENTE';
    else if (variacao >= 20 && b > 0 && atual >= 10) classe = 'ALTA';
    else if (variacao <= -20 && b < 0) classe = 'QUEDA';

    return { ...l, variacao, inclinacao: b, ritmo, previsto7: previsto, receita_prevista7: previsto * N(l.preco), classe };
  });
}

/** Série diária completa (dias sem venda viram 0), do mais antigo para ontem. */
function serieCompleta(porDia, dias) {
  const serie = [];
  for (let i = dias; i >= 1; i--) serie.push(N(porDia[somarDias(hoje(), -i)]));
  return serie;
}

const soma = (v) => v.reduce((s, x) => s + x, 0);

/** Previsão diária para os próximos dias a partir do histórico diário { 'AAAA-MM-DD': valor }. */
function preverDiario(historico, horizonte = 7) {
  const serie = serieCompleta(historico, 28);
  const recentes = soma(serie.slice(14));
  const anteriores = soma(serie.slice(0, 14));
  // fator de tendência amortecido (raiz) e limitado para não extrapolar demais
  let fator = anteriores > 0 ? Math.sqrt(recentes / anteriores) : 1;
  fator = Math.min(1.2, Math.max(0.85, fator));

  const dias = [];
  for (let k = 0; k < horizonte; k++) {
    const alvo = somarDias(hoje(), k);
    const mesmos = [];
    for (let s = 1; s <= 4; s++) mesmos.push(N(historico[somarDias(alvo, -7 * s)]));
    const pesos = [4, 3, 2, 1];
    const base = mesmos.reduce((acc, v, i) => acc + v * pesos[i], 0) / soma(pesos);
    const media = soma(mesmos) / 4;
    const desvio = Math.sqrt(soma(mesmos.map((v) => (v - media) ** 2)) / 3);
    const previsto = base * fator;
    dias.push({ data: alvo, previsto, minimo: Math.max(0, previsto - 1.28 * desvio), maximo: previsto + 1.28 * desvio });
  }
  return { dias, fator };
}

/** Dias até acabar e compra sugerida para cada insumo. */
function preverReposicao(linhas, fatorTendencia, cobertura = 14) {
  const saida = [];
  for (const l of linhas) {
    const consumo = N(l.consumo_dia) * fatorTendencia;
    if (consumo <= 0) continue;
    const estoque = N(l.estoque);
    const diasRestantes = estoque / consumo;
    const comprar = Math.trunc(Math.max(0, Math.ceil(consumo * cobertura + N(l.minimo) - estoque)));
    saida.push({
      ...l,
      consumo_previsto: consumo,
      dias_restantes: diasRestantes,
      acaba_em: somarDias(hoje(), Math.floor(diasRestantes)),
      comprar,
      custo_compra: comprar * N(l.custo),
    });
  }
  return saida.sort((x, y) => x.dias_restantes - y.dias_restantes);
}

module.exports = { regressaoLinear, analisarTendencias, serieCompleta, preverDiario, preverReposicao };
