<?php
declare(strict_types=1);

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

/** Coeficientes da regressão linear a partir das somas: devolve [a, b]. */
function regressao_linear(float $n, float $sx, float $sy, float $sxy, float $sxx): array
{
    $den = $n * $sxx - $sx * $sx;
    if ($n < 2 || abs($den) < 1e-9) {
        return [$n > 0 ? $sy / $n : 0.0, 0.0];
    }
    $b = ($n * $sxy - $sx * $sy) / $den;
    $a = ($sy - $b * $sx) / $n;
    return [$a, $b];
}

/**
 * Classifica cada item do cardápio e projeta as vendas dos próximos 7 dias.
 * @param list<array<string, mixed>> $linhas resultado de sp_prev_produtos
 * @return list<array<string, mixed>>
 */
function analisar_tendencias(array $linhas, int $janela): array
{
    $saida = [];
    foreach ($linhas as $l) {
        $atual = (float) $l['atual'];
        $anterior = (float) $l['anterior'];
        [$a, $b] = regressao_linear((float) $l['n'], (float) $l['sx'], (float) $l['sy'], (float) $l['sxy'], (float) $l['sxx']);

        // reta projetada para os próximos 7 dias (x = 2·janela ... 2·janela + 6)
        $reta = 0.0;
        for ($k = 0; $k < 7; $k++) {
            $reta += max(0.0, $a + $b * ($janela * 2 + $k));
        }
        $media7 = $atual / max($janela, 1) * 7;
        $previsto = max(0.0, 0.5 * $reta + 0.5 * $media7);

        $variacao = $anterior > 0 ? ($atual - $anterior) / $anterior * 100 : ($atual > 0 ? 100.0 : 0.0);
        $mediaDia = (float) $l['sy'] / max((float) $l['n'], 1);
        // inclinação relativa: quanto a venda diária muda por semana, em % da média
        $ritmo = $mediaDia > 0 ? $b * 7 / $mediaDia * 100 : 0.0;

        $classe = 'ESTAVEL';
        if ($anterior < 15 && $atual >= max(10, $anterior * 2)) {
            $classe = 'EMERGENTE';
        } elseif ($variacao >= 20 && $b > 0 && $atual >= 10) {
            $classe = 'ALTA';
        } elseif ($variacao <= -20 && $b < 0) {
            $classe = 'QUEDA';
        }

        $saida[] = $l + [
            'variacao' => $variacao,
            'inclinacao' => $b,
            'ritmo' => $ritmo,
            'previsto7' => $previsto,
            'receita_prevista7' => $previsto * (float) $l['preco'],
            'classe' => $classe,
        ];
    }
    return $saida;
}

/**
 * Série diária completa (dias sem venda viram 0), do mais antigo para ontem.
 * @param array<string, float> $porDia [AAAA-MM-DD => valor]
 * @return list<float>
 */
function serie_completa(array $porDia, int $dias): array
{
    $serie = [];
    for ($i = $dias; $i >= 1; $i--) {
        $serie[] = (float) ($porDia[date('Y-m-d', strtotime("-{$i} days"))] ?? 0);
    }
    return $serie;
}

/**
 * Previsão diária para os próximos dias a partir do histórico diário.
 * @param array<string, float> $historico [AAAA-MM-DD => valor] (dias sem venda podem faltar)
 * @return array{dias: list<array{data: string, previsto: float, minimo: float, maximo: float}>, fator: float}
 */
function prever_diario(array $historico, int $horizonte = 7): array
{
    $serie = serie_completa($historico, 28);
    $recentes = array_sum(array_slice($serie, 14));
    $anteriores = array_sum(array_slice($serie, 0, 14));
    // fator de tendência amortecido (raiz) e limitado para não extrapolar demais
    $fator = $anteriores > 0 ? sqrt($recentes / $anteriores) : 1.0;
    $fator = min(1.2, max(0.85, $fator));

    $dias = [];
    for ($k = 0; $k < $horizonte; $k++) {
        $alvo = strtotime("+{$k} days");
        $mesmos = [];
        for ($s = 1; $s <= 4; $s++) {
            $mesmos[] = (float) ($historico[date('Y-m-d', strtotime("-" . (7 * $s) . ' days', $alvo))] ?? 0);
        }
        $pesos = [4, 3, 2, 1];
        $base = 0.0;
        foreach ($mesmos as $i => $v) {
            $base += $v * $pesos[$i];
        }
        $base /= array_sum($pesos);
        $media = array_sum($mesmos) / 4;
        $desvio = sqrt(array_sum(array_map(static fn($v) => ($v - $media) ** 2, $mesmos)) / 3);
        $previsto = $base * $fator;
        $dias[] = [
            'data' => date('Y-m-d', $alvo),
            'previsto' => $previsto,
            'minimo' => max(0.0, $previsto - 1.28 * $desvio),
            'maximo' => $previsto + 1.28 * $desvio,
        ];
    }
    return ['dias' => $dias, 'fator' => $fator];
}

/**
 * Dias até acabar e compra sugerida para cada insumo.
 * @param list<array<string, mixed>> $linhas resultado de sp_prev_insumos
 * @return list<array<string, mixed>>
 */
function prever_reposicao(array $linhas, float $fatorTendencia, int $cobertura = 14): array
{
    $saida = [];
    foreach ($linhas as $l) {
        $consumo = (float) $l['consumo_dia'] * $fatorTendencia;
        if ($consumo <= 0) {
            continue;
        }
        $estoque = (float) $l['estoque'];
        $diasRestantes = $estoque / $consumo;
        $comprar = (int) max(0, ceil($consumo * $cobertura + (float) $l['minimo'] - $estoque));
        $saida[] = $l + [
            'consumo_previsto' => $consumo,
            'dias_restantes' => $diasRestantes,
            'acaba_em' => date('Y-m-d', (int) strtotime('+' . (int) floor($diasRestantes) . ' days')),
            'comprar' => $comprar,
            'custo_compra' => $comprar * (float) $l['custo'],
        ];
    }
    usort($saida, static fn($x, $y) => $x['dias_restantes'] <=> $y['dias_restantes']);
    return $saida;
}
