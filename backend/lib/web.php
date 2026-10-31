<?php
declare(strict_types=1);

/** Utilidades das páginas do painel: escape, formatação, flash, CSRF e paginação. */

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function moeda(mixed $v): string
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}

function data_br(?string $d): string
{
    return $d ? date('d/m/Y', strtotime($d)) : '–';
}

function so_digitos(string $s): string
{
    return preg_replace('/\D+/', '', $s) ?? '';
}

function numero(mixed $v, int $casas = 0): string
{
    return number_format((float) $v, $casas, ',', '.');
}

/** Variação percentual com sinal ("+12%"), ou null quando não há base de comparação. */
function variacao(float $atual, float $anterior): ?int
{
    return $anterior > 0 ? (int) round(($atual - $anterior) / $anterior * 100) : null;
}

/** (11) 98765-4321 / (11) 3333-4444 */
function telefone_br(?string $t): string
{
    $d = so_digitos((string) $t);
    return match (strlen($d)) {
        11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
        10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
        default => (string) $t,
    };
}

/** 123.456.789-01 / 12.345.678/0001-90 */
function documento_br(?string $doc): string
{
    $d = so_digitos((string) $doc);
    return match (strlen($d)) {
        11 => sprintf('%s.%s.%s-%s', substr($d, 0, 3), substr($d, 3, 3), substr($d, 6, 3), substr($d, 9)),
        14 => sprintf('%s.%s.%s/%s-%s', substr($d, 0, 2), substr($d, 2, 3), substr($d, 5, 3), substr($d, 8, 4), substr($d, 12)),
        default => (string) $doc,
    };
}

/**
 * Período escolhido nos filtros (?periodo=hoje|7dias|30dias|mes|passado|ano|personalizado&de=&ate=).
 * @return array{0: string, 1: string, 2: string} [de, ate, chave]
 */
function periodo_filtro(string $padrao = 'mes'): array
{
    $chave = entrada('periodo', $padrao);
    $valida = static fn(string $d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

    if ($chave === 'personalizado' && $valida(entrada('de')) && $valida(entrada('ate'))) {
        $de = entrada('de');
        $ate = entrada('ate');
        return $de <= $ate ? [$de, $ate, $chave] : [$ate, $de, $chave];
    }

    return match ($chave) {
        'hoje' => [date('Y-m-d'), date('Y-m-d'), 'hoje'],
        '7dias' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d'), '7dias'],
        '30dias' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d'), '30dias'],
        'passado' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month')), 'passado'],
        'ano' => [date('Y-01-01'), date('Y-12-31'), 'ano'],
        '90dias' => [date('Y-m-d', strtotime('-89 days')), date('Y-m-d'), '90dias'],
        default => [date('Y-m-01'), date('Y-m-t'), 'mes'],
    };
}

/** Rótulos dos períodos para os seletores. */
function periodos_rotulos(): array
{
    return ['hoje' => 'Hoje', '7dias' => 'Últimos 7 dias', '30dias' => 'Últimos 30 dias', 'mes' => 'Este mês',
            'passado' => 'Mês passado', '90dias' => 'Últimos 90 dias', 'ano' => 'Este ano', 'personalizado' => 'Personalizado'];
}

/** Resposta JSON para as APIs internas. */
function json_saida(array $dados, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** Nome e ícone de cada forma de pagamento. */
function formas_pagamento(): array
{
    return [
        'PIX' => ['Pix', 'fa-brands fa-pix'],
        'CREDITO' => ['Crédito', 'fa-regular fa-credit-card'],
        'DEBITO' => ['Débito', 'fa-solid fa-credit-card'],
        'DINHEIRO' => ['Dinheiro', 'fa-solid fa-money-bill-wave'],
        'BOLETO' => ['Boleto', 'fa-solid fa-barcode'],
    ];
}

// ---------- entrada ----------

function entrada(string $campo, string $padrao = ''): string
{
    $v = $_POST[$campo] ?? $_GET[$campo] ?? $padrao;
    return is_string($v) ? trim($v) : $padrao;
}

function entrada_int(string $campo, int $padrao = 0): int
{
    return (int) filter_var(entrada($campo, (string) $padrao), FILTER_VALIDATE_INT, ['options' => ['default' => $padrao]]);
}

/** Converte "12,50", "1.234,56" ou "12.50" em float. */
function entrada_decimal(string $campo): float
{
    $t = entrada($campo, '0');
    if (str_contains($t, ',')) {
        $t = str_replace(',', '.', str_replace('.', '', $t));
    }
    return is_numeric($t) ? (float) $t : 0.0;
}

// ---------- flash / CSRF ----------

function flash(string $tipo, string $mensagem): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function flash_ler(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_campo(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e($_SESSION['csrf_token'] ?? '') . '">';
}

function csrf_ok(): bool
{
    $t = $_POST['csrf_token'] ?? '';
    return is_string($t) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $t);
}

function redirecionar(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/** URL da própria página com parâmetros (null remove o parâmetro). */
function url(array $params = []): string
{
    $base = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php');
    $q = array_filter(array_merge($_GET, $params), static fn($v) => $v !== null && $v !== '');
    return $base . ($q ? '?' . http_build_query($q) : '');
}

/**
 * Trata o POST de uma página: valida CSRF, roda o bloco, grava o flash e redireciona (PRG).
 * O bloco devolve a mensagem de sucesso (ou ['mensagem', 'url']) e pode lançar ErroNegocio.
 */
function tratar_post(callable $bloco, ?string $voltar = null): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $destino = $voltar ?? url(['editar' => null, 'novo' => null, 'excluir' => null, 'mov' => null]);

    if (!csrf_ok()) {
        flash('erro', 'Sessão expirada. Tente novamente.');
        redirecionar($destino);
    }

    try {
        $r = $bloco();
        $mensagem = is_array($r) ? (string) $r[0] : (is_string($r) ? $r : '');
        if (is_array($r)) {
            flash('ok', $r[0]);
            $destino = $r[1] ?? $destino;
        } elseif (is_string($r) && $r !== '') {
            flash('ok', $r);
        }
        // auditoria: toda ação concluída fica registrada com a mensagem mostrada ao usuário
        if ($mensagem !== '' && function_exists('registrar_atividade')) {
            registrar_atividade(basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'painel'), '.php'), entrada('acao'), $mensagem);
        }
    } catch (ErroNegocio $e) {
        flash('erro', $e->getMessage());
        $destino = $voltar ?? url();
    } catch (Throwable $e) {
        error_log('[tcc] ' . $e->getMessage());
        flash('erro', 'Não foi possível concluir a operação. Confira se o banco foi instalado (instalar.php).');
        $destino = $voltar ?? url();
    }
    redirecionar($destino);
}

/** Roda uma leitura; se o banco falhar, mostra o aviso e devolve o padrão. */
function ler(callable $bloco, mixed $padrao = []): mixed
{
    try {
        return $bloco();
    } catch (Throwable $e) {
        error_log('[tcc] ' . $e->getMessage());
        static $avisou = false;
        if (!$avisou) {
            $avisou = true;
            $_SESSION['flash_leitura'] = 'Não foi possível ler os dados. Confira se o MySQL está ligado e se o banco foi instalado (instalar.php).';
        }
        return $padrao;
    }
}

/** Números das páginas a exibir (1, …, 4 5 6, …, N). */
function paginas(int $atual, int $total): array
{
    $p = array_unique(array_filter([1, $atual - 1, $atual, $atual + 1, $total], static fn($n) => $n >= 1 && $n <= $total));
    sort($p);
    return $p;
}

function paginacao(int $atual, int $total, int $quantidade, int $porPagina, string $param = 'pagina'): string
{
    if ($total <= 1) {
        return '';
    }
    $de = ($atual - 1) * $porPagina + 1;
    $ate = min($quantidade, $atual * $porPagina);
    $h = '<nav class="paginacao" aria-label="Paginação"><span class="paginacao-info">' . $de . '–' . $ate . ' de ' . $quantidade . '</span><div class="paginacao-botoes">';
    $h .= $atual > 1 ? '<a class="btn btn-secundario" href="' . e(url([$param => $atual - 1])) . '">Anterior</a>' : '';
    $anterior = 0;
    foreach (paginas($atual, $total) as $n) {
        if ($anterior && $n - $anterior > 1) {
            $h .= '<span class="paginacao-reticencias" aria-hidden="true">…</span>';
        }
        $h .= '<a class="btn btn-secundario' . ($n === $atual ? ' ativo' : '') . '" href="' . e(url([$param => $n])) . '"' . ($n === $atual ? ' aria-current="page"' : '') . '>' . $n . '</a>';
        $anterior = $n;
    }
    $h .= $atual < $total ? '<a class="btn btn-secundario" href="' . e(url([$param => $atual + 1])) . '">Próxima</a>' : '';
    return $h . '</div></nav>';
}
