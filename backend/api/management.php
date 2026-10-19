<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function utf8Length(string $value): int
{
    $count = preg_match_all('/./us', $value, $matches);
    return $count === false ? strlen($value) : $count;
}
function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}
if (!isset($_SESSION['usuario_id'])) {
    reply(['error' => 'Sessão expirada. Entre novamente.'], 401);
}
$body = $_POST;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jsonBody = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonBody)) {
        $body = $jsonBody;
    }
}
$action = $_GET['action'] ?? ($body['action'] ?? '');
if (!is_string($action)) {
    $action = '';
}
$isAdmin = ($_SESSION['usuario_nivel'] ?? '') === 'ADMIN';
$userRole = $_SESSION['usuario_cargo'] ?? '';
$financeActions = [
    'cashflow',
    'pay_account',
    'create_manual_flow',
    'update_manual_flow',
];
$stockActions = ['inventory', 'stock_adjust'];
if (
    in_array($action, $financeActions, true) &&
    !$isAdmin &&
    $userRole !== 'Gerente'
) {
    reply(['error' => 'Acesso restrito ao financeiro.'], 403);
}
if (
    in_array($action, $stockActions, true) &&
    !$isAdmin &&
    !in_array($userRole, ['Gerente', 'Cozinheiro'], true)
) {
    reply(['error' => 'Acesso restrito ao estoque.'], 403);
}
if (
    in_array(
        $action,
        ['company', 'save_company', 'settings', 'save_settings'],
        true,
    ) &&
    !$isAdmin
) {
    reply(['error' => 'Somente administrador pode acessar esta área.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$csrf || !hash_equals($_SESSION['csrf_token'] ?? '', $csrf)) {
        reply(
            [
                'error' =>
                    'Token de segurança inválido. Atualize a página e tente novamente.',
            ],
            403,
        );
    }
}
require_once __DIR__ . '/../conexao.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new BancodeDados();
try {
    $db->conecta();
    $con = $db->con;
    mysqli_set_charset($con, 'utf8mb4');
    $id = static function (string $key) use ($body): int {
        $n = filter_var($body[$key] ?? null, FILTER_VALIDATE_INT);
        if (!$n || $n < 1) {
            reply(['error' => 'Identificador inválido.'], 422);
        }
        return $n;
    };
    $text = static function (string $key, int $max, bool $required = true) use (
        $body,
    ): string {
        $v = trim((string) ($body[$key] ?? ''));
        if ($required && $v === '') {
            reply(['error' => "Preencha o campo {$key}."], 422);
        }
        if (utf8Length($v) > $max) {
            reply(['error' => "O campo {$key} excede {$max} caracteres."], 422);
        }
        return $v;
    };
    $stmt = static function (
        string $sql,
        string $types = '',
        array $values = [],
    ) use ($con): mysqli_stmt {
        $s = mysqli_prepare($con, $sql);
        if ($types !== '') {
            $refs = [];
            foreach ($values as $k => &$value) {
                $refs[$k] = &$value;
            }
            unset($value);
            mysqli_stmt_bind_param($s, $types, ...$refs);
        }
        mysqli_stmt_execute($s);
        return $s;
    };
    $rows = static function (string $sql): array {
        global $con;
        $r = mysqli_query($con, $sql);
        return mysqli_fetch_all($r, MYSQLI_ASSOC);
    };
    $one = static function (string $sql): ?array {
        global $con;
        $r = mysqli_query($con, $sql);
        $v = mysqli_fetch_assoc($r);
        return $v ?: null;
    };

    switch ($action) {
        case 'cities':
            reply([
                'data' => $rows(
                    "SELECT c.ID id, CONCAT(c.NOME, ' / ', e.SIGLA) label FROM CIDADE c JOIN ESTADO e ON e.ID=c.ESTADO_ID WHERE c.STATUS='ATIVO' ORDER BY c.NOME",
                ),
            ]);
        case 'clients':
            reply([
                'data' => $rows(
                    "SELECT cl.ID id, cl.NOME nome, cl.DOCUMENTO documento, cl.TELEFONE telefone, cl.CONTATO contato, cl.ENDERECO endereco, cl.ENDERECO_NUMERO numero, cl.CIDADE_ID cidade_id, CONCAT(ci.NOME, ' / ', es.SIGLA) cidade, cl.STATUS status FROM CLIENTE cl JOIN CIDADE ci ON ci.ID=cl.CIDADE_ID JOIN ESTADO es ON es.ID=ci.ESTADO_ID ORDER BY cl.NOME",
                ),
            ]);
        case 'save_client':
            $clientId = (int) ($body['id'] ?? 0);
            $name = $text('nome', 255);
            $doc = $text('documento', 18);
            $address = $text('endereco', 400);
            $number = $text('numero', 20);
            $phone = $text('telefone', 15);
            $contact = $text('contato', 255, false);
            $city = (int) ($body['cidade_id'] ?? 0);
            if ($city < 1) {
                reply(['error' => 'Selecione uma cidade.'], 422);
            }
            if ($clientId) {
                $stmt(
                    'UPDATE CLIENTE SET NOME=?, DOCUMENTO=?, ENDERECO=?, ENDERECO_NUMERO=?, TELEFONE=?, CONTATO=?, CIDADE_ID=? WHERE ID=?',
                    'ssssssii',
                    [
                        $name,
                        $doc,
                        $address,
                        $number,
                        $phone,
                        $contact,
                        $city,
                        $clientId,
                    ],
                );
            } else {
                $stmt(
                    'INSERT INTO CLIENTE (NOME,DOCUMENTO,ENDERECO,ENDERECO_NUMERO,TELEFONE,CONTATO,CIDADE_ID) VALUES (?,?,?,?,?,?,?)',
                    'ssssssi',
                    [$name, $doc, $address, $number, $phone, $contact, $city],
                );
            }
            reply([
                'ok' => true,
                'message' => $clientId
                    ? 'Cliente atualizado.'
                    : 'Cliente cadastrado.',
            ]);
        case 'deactivate_client':
            $clientId = $id('id');
            $stmt("UPDATE CLIENTE SET STATUS='INATIVO' WHERE ID=?", 'i', [
                $clientId,
            ]);
            reply([
                'ok' => true,
                'message' =>
                    'Cliente inativado. O histórico de vendas foi preservado.',
            ]);
        case 'categories':
            reply([
                'data' => $rows(
                    "SELECT ID id,NOME nome,DESCRICAO descricao,STATUS status FROM PRODUTO_CATEGORIA WHERE STATUS='ATIVO' ORDER BY NOME",
                ),
            ]);
        case 'save_category':
            $categoryId = (int) ($body['id'] ?? 0);
            $name = $text('nome', 200);
            $description = $text('descricao', 255, false);
            if ($categoryId) {
                $stmt(
                    'UPDATE PRODUTO_CATEGORIA SET NOME=?,DESCRICAO=? WHERE ID=?',
                    'ssi',
                    [$name, $description, $categoryId],
                );
            } else {
                $stmt(
                    'INSERT INTO PRODUTO_CATEGORIA (NOME,DESCRICAO) VALUES (?,?)',
                    'ss',
                    [$name, $description],
                );
            }
            reply([
                'ok' => true,
                'message' => $categoryId
                    ? 'Categoria atualizada.'
                    : 'Categoria cadastrada.',
            ]);
        case 'deactivate_category':
            $categoryId = $id('id');
            $stmt(
                "UPDATE PRODUTO_CATEGORIA SET STATUS='INATIVO' WHERE ID=?",
                'i',
                [$categoryId],
            );
            reply([
                'ok' => true,
                'message' =>
                    'Categoria inativada. Produtos existentes mantêm seu histórico.',
            ]);
        case 'products':
            reply([
                'data' => $rows(
                    'SELECT p.ID id,p.NOME nome,p.DESCRICAO descricao,p.PRECO_UNITARIO preco,p.ESTOQUE estoque,p.ESTOQUE_MINIMO estoque_minimo,p.DATA_VALIDADE validade,p.CODIGO_BARRAS codigo_barras,p.NCM ncm,p.UNIDADE_MEDIDA unidade,p.CATEGORIA_ID categoria_id,c.NOME categoria,p.STATUS status FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID=p.CATEGORIA_ID ORDER BY p.NOME',
                ),
            ]);
        case 'save_product':
            $productId = (int) ($body['id'] ?? 0);
            $name = $text('nome', 200);
            $desc = $text('descricao', 255, false);
            $price = filter_var($body['preco'] ?? null, FILTER_VALIDATE_FLOAT);
            $min = filter_var(
                $body['estoque_minimo'] ?? 0,
                FILTER_VALIDATE_INT,
            );
            $category = (int) ($body['categoria_id'] ?? 0);
            $barcode = trim((string) ($body['codigo_barras'] ?? '')) ?: null;
            $ncm = trim((string) ($body['ncm'] ?? '')) ?: null;
            $unit = $text('unidade', 6);
            $validity = trim((string) ($body['validade'] ?? '')) ?: null;
            if (
                $price === false ||
                $price < 0 ||
                $min === false ||
                $min < 0 ||
                $category < 1
            ) {
                reply(
                    [
                        'error' =>
                            'Preço, estoque mínimo ou categoria inválidos.',
                    ],
                    422,
                );
            }
            $catCheck = $stmt(
                "SELECT ID FROM PRODUTO_CATEGORIA WHERE ID=? AND STATUS='ATIVO'",
                'i',
                [$category],
            );
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($catCheck))) {
                reply(['error' => 'Selecione uma categoria ativa.'], 422);
            }
            if ($productId) {
                $stmt(
                    'UPDATE PRODUTO SET NOME=?,DESCRICAO=?,PRECO_UNITARIO=?,ESTOQUE_MINIMO=?,DATA_VALIDADE=?,CODIGO_BARRAS=?,NCM=?,UNIDADE_MEDIDA=?,CATEGORIA_ID=? WHERE ID=?',
                    'ssdissssii',
                    [
                        $name,
                        $desc,
                        $price,
                        $min,
                        $validity,
                        $barcode,
                        $ncm,
                        $unit,
                        $category,
                        $productId,
                    ],
                );
            } else {
                $stmt(
                    'INSERT INTO PRODUTO (NOME,DESCRICAO,PRECO_UNITARIO,ESTOQUE_MINIMO,DATA_VALIDADE,CODIGO_BARRAS,NCM,UNIDADE_MEDIDA,CATEGORIA_ID) VALUES (?,?,?,?,?,?,?,?,?)',
                    'ssdisssssi',
                    [
                        $name,
                        $desc,
                        $price,
                        $min,
                        $validity,
                        $barcode,
                        $ncm,
                        $unit,
                        $category,
                    ],
                );
            }
            reply([
                'ok' => true,
                'message' => $productId
                    ? 'Produto atualizado.'
                    : 'Produto cadastrado.',
            ]);
        case 'deactivate_product':
            $productId = $id('id');
            $stmt("UPDATE PRODUTO SET STATUS='INATIVO' WHERE ID=?", 'i', [
                $productId,
            ]);
            reply([
                'ok' => true,
                'message' =>
                    'Produto inativado. Vendas e movimentações históricas foram preservadas.',
            ]);
        case 'inventory':
            $items = $rows(
                'SELECT p.ID id,p.NOME nome,p.ESTOQUE estoque,p.ESTOQUE_MINIMO minimo,p.UNIDADE_MEDIDA unidade,p.DATA_VALIDADE validade,p.STATUS status,c.NOME categoria FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID=p.CATEGORIA_ID ORDER BY p.NOME',
            );
            $history = $rows(
                'SELECT m.ID id,p.NOME produto,m.TIPO tipo,m.QUANTIDADE quantidade,m.OBSERVACAO observacao,m.DATA_MOVIMENTACAO data_mov,u.NOME usuario FROM MOVIMENTACAO_ESTOQUE m JOIN PRODUTO p ON p.ID=m.PRODUTO_ID JOIN USUARIOS u ON u.ID=m.USUARIO_ID ORDER BY m.DATA_MOVIMENTACAO DESC,m.ID DESC LIMIT 100',
            );
            reply(['data' => $items, 'history' => $history]);
        case 'stock_adjust':
            $productId = $id('produto_id');
            $qty = filter_var($body['quantidade'] ?? null, FILTER_VALIDATE_INT);
            $type = $body['tipo'] ?? '';
            if (!in_array($type, ['ENTRADA', 'SAIDA'], true)) {
                reply(['error' => 'Tipo de movimentação inválido.'], 422);
            }
            $note = $text('observacao', 255, false);
            if (!$qty || $qty < 1) {
                reply(
                    ['error' => 'A quantidade deve ser maior que zero.'],
                    422,
                );
            }
            mysqli_begin_transaction($con);
            try {
                $s = $stmt(
                    'SELECT ESTOQUE,STATUS FROM PRODUTO WHERE ID=? FOR UPDATE',
                    'i',
                    [$productId],
                );
                $prod = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
                if (!$prod) {
                    throw new RuntimeException('Produto não encontrado.');
                }
                if ($prod['STATUS'] !== 'ATIVO') {
                    throw new RuntimeException(
                        'Produto inativo não pode receber movimentações.',
                    );
                }
                $new =
                    (int) $prod['ESTOQUE'] +
                    ($type === 'ENTRADA' ? $qty : -$qty);
                if ($new < 0) {
                    throw new RuntimeException(
                        'A saída excede o estoque disponível.',
                    );
                }
                $stmt('UPDATE PRODUTO SET ESTOQUE=? WHERE ID=?', 'ii', [
                    $new,
                    $productId,
                ]);
                $userId = (int) $_SESSION['usuario_id'];
                $stmt(
                    'INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID,USUARIO_ID,TIPO,QUANTIDADE,OBSERVACAO) VALUES (?,?,?,?,?)',
                    'iisis',
                    [$productId, $userId, $type, $qty, $note],
                );
                mysqli_commit($con);
            } catch (Throwable $e) {
                mysqli_rollback($con);
                throw $e;
            }
            reply([
                'ok' => true,
                'message' => 'Estoque atualizado e movimentação registrada.',
            ]);
        case 'orders':
            reply([
                'data' => $rows(
                    "SELECT v.ID id,v.TOTAL total,v.VALOR valor,v.DESCONTO desconto,v.DATA_CADASTRO data,v.FORMA_PAGAMENTO pagamento,v.STATUS status,cl.NOME cliente,u.NOME funcionario, (SELECT GROUP_CONCAT(CONCAT(vi.QUANTIDADE,'× ',p.NOME) ORDER BY p.NOME SEPARATOR ', ') FROM VENDA_ITEM vi JOIN PRODUTO p ON p.ID=vi.PRODUTO_ID WHERE vi.VENDA_ID=v.ID) itens FROM VENDA v JOIN CLIENTE cl ON cl.ID=v.CLIENTE_ID JOIN USUARIOS u ON u.ID=v.FUNCIONARIO_ID ORDER BY v.DATA_CADASTRO DESC,v.ID DESC LIMIT 250",
                ),
            ]);
        case 'order_options':
            $pay = $one(
                "SELECT VALOR FROM CONFIGURACAO_SISTEMA WHERE CHAVE='formas_pagamento'",
            );
            $methods = $pay
                ? array_values(
                    array_filter(explode(',', (string) $pay['VALOR'])),
                )
                : ['DINHEIRO', 'PIX', 'CREDITO', 'DEBITO', 'BOLETO'];
            reply([
                'clients' => $rows(
                    "SELECT ID id,NOME nome FROM CLIENTE WHERE STATUS='ATIVO' ORDER BY NOME",
                ),
                'products' => $rows(
                    "SELECT ID id,NOME nome,PRECO_UNITARIO preco,ESTOQUE estoque FROM PRODUTO WHERE STATUS='ATIVO' ORDER BY NOME",
                ),
                'payment_methods' => $methods,
            ]);
        case 'create_order':
            $clientId = $id('cliente_id');
            $activeClient = $stmt(
                "SELECT ID FROM CLIENTE WHERE ID=? AND STATUS='ATIVO'",
                'i',
                [$clientId],
            );
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($activeClient))) {
                reply(['error' => 'Selecione um cliente ativo.'], 422);
            }
            $method = $body['forma_pagamento'] ?? '';
            $pay = $one(
                "SELECT VALOR FROM CONFIGURACAO_SISTEMA WHERE CHAVE='formas_pagamento'",
            );
            $allowedMethods = $pay
                ? explode(',', (string) $pay['VALOR'])
                : ['DINHEIRO', 'PIX', 'CREDITO', 'DEBITO', 'BOLETO'];
            if (!in_array($method, $allowedMethods, true)) {
                reply(
                    [
                        'error' =>
                            'Forma de pagamento não está habilitada nas configurações.',
                    ],
                    422,
                );
            }
            $items = $body['itens'] ?? [];
            if (!is_array($items) || !count($items)) {
                reply(['error' => 'Adicione ao menos um item.'], 422);
            }
            mysqli_begin_transaction($con);
            try {
                $subtotal = 0.0;
                $lineMap = [];
                foreach ($items as $line) {
                    $pid = (int) ($line['produto_id'] ?? 0);
                    $q = (int) ($line['quantidade'] ?? 0);
                    if ($pid < 1 || $q < 1 || $q > 999) {
                        throw new RuntimeException(
                            'Itens ou quantidades inválidos.',
                        );
                    }
                    $s = $stmt(
                        'SELECT NOME,PRECO_UNITARIO FROM PRODUTO WHERE ID=? AND STATUS=\'ATIVO\'',
                        'i',
                        [$pid],
                    );
                    $p = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
                    if (!$p) {
                        throw new RuntimeException('Produto indisponível.');
                    }
                    $lineTotal = (float) $p['PRECO_UNITARIO'] * $q;
                    $subtotal += $lineTotal;
                    if (isset($lineMap[$pid])) {
                        $lineMap[$pid][1] += $q;
                        $lineMap[$pid][3] += $lineTotal;
                    } else {
                        $lineMap[$pid] = [
                            $pid,
                            $q,
                            (float) $p['PRECO_UNITARIO'],
                            $lineTotal,
                        ];
                    }
                }
                $lines = array_values($lineMap);
                $userId = (int) $_SESSION['usuario_id'];
                $stmt(
                    "INSERT INTO VENDA (VALOR,DESCONTO,TOTAL,CLIENTE_ID,FUNCIONARIO_ID,FORMA_PAGAMENTO,STATUS) VALUES (?,0,?,?,?,?,'EM_ABERTO')",
                    'ddiis',
                    [$subtotal, $subtotal, $clientId, $userId, $method],
                );
                $saleId = mysqli_insert_id($con);
                foreach ($lines as [$pid, $q, $price, $lineTotal]) {
                    $stmt(
                        "INSERT INTO VENDA_ITEM (VENDA_ID,PRODUTO_ID,QUANTIDADE,PRECO_UNITARIO,VALOR,DESCONTO,TOTAL,STATUS) VALUES (?,?,?,?,?,0,?,'EM_ABERTO')",
                        'iiiddd',
                        [$saleId, $pid, $q, $price, $lineTotal, $lineTotal],
                    );
                }
                mysqli_commit($con);
            } catch (Throwable $e) {
                mysqli_rollback($con);
                throw $e;
            }
            reply([
                'ok' => true,
                'message' => 'Pedido criado como em aberto.',
                'id' => $saleId,
            ]);
        case 'order_status':
            $saleId = $id('id');
            $next = $body['status'] ?? '';
            if (
                !in_array($next, ['EM_ABERTO', 'FINALIZADA', 'CANCELADA'], true)
            ) {
                reply(['error' => 'Status inválido.'], 422);
            }
            mysqli_begin_transaction($con);
            try {
                $s = $stmt(
                    'SELECT STATUS FROM VENDA WHERE ID=? FOR UPDATE',
                    'i',
                    [$saleId],
                );
                $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
                if (!$sale) {
                    throw new RuntimeException('Pedido não encontrado.');
                }
                $prev = $sale['STATUS'];
                if ($prev === $next) {
                    throw new RuntimeException(
                        'O pedido já está nesse status.',
                    );
                }
                if ($prev === 'CANCELADA') {
                    throw new RuntimeException(
                        'Pedido cancelado não pode ser reaberto.',
                    );
                }
                if ($prev === 'FINALIZADA' && $next !== 'CANCELADA') {
                    throw new RuntimeException(
                        'Pedido finalizado só pode ser cancelado.',
                    );
                }
                $ls = $stmt(
                    'SELECT PRODUTO_ID,QUANTIDADE FROM VENDA_ITEM WHERE VENDA_ID=? FOR UPDATE',
                    'i',
                    [$saleId],
                );
                $lines = mysqli_fetch_all(
                    mysqli_stmt_get_result($ls),
                    MYSQLI_ASSOC,
                );
                if ($next === 'FINALIZADA' && $prev === 'EM_ABERTO') {
                    foreach ($lines as $line) {
                        $ps = $stmt(
                            'SELECT ESTOQUE,NOME FROM PRODUTO WHERE ID=? FOR UPDATE',
                            'i',
                            [(int) $line['PRODUTO_ID']],
                        );
                        $prod = mysqli_fetch_assoc(mysqli_stmt_get_result($ps));
                        if (
                            (int) $prod['ESTOQUE'] < (int) $line['QUANTIDADE']
                        ) {
                            throw new RuntimeException(
                                'Estoque insuficiente para ' .
                                    $prod['NOME'] .
                                    '.',
                            );
                        }
                        $new =
                            (int) $prod['ESTOQUE'] - (int) $line['QUANTIDADE'];
                        $stmt('UPDATE PRODUTO SET ESTOQUE=? WHERE ID=?', 'ii', [
                            $new,
                            (int) $line['PRODUTO_ID'],
                        ]);
                        $uid = (int) $_SESSION['usuario_id'];
                        $qty = (int) $line['QUANTIDADE'];
                        $note = 'Baixa automática do pedido #' . $saleId;
                        $stmt(
                            "INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID,USUARIO_ID,TIPO,QUANTIDADE,OBSERVACAO) VALUES (?,?,'SAIDA',?,?)",
                            'iiis',
                            [(int) $line['PRODUTO_ID'], $uid, $qty, $note],
                        );
                    }
                } elseif ($prev === 'FINALIZADA' && $next === 'CANCELADA') {
                    foreach ($lines as $line) {
                        $stmt(
                            'UPDATE PRODUTO SET ESTOQUE=ESTOQUE+? WHERE ID=?',
                            'ii',
                            [
                                (int) $line['QUANTIDADE'],
                                (int) $line['PRODUTO_ID'],
                            ],
                        );
                        $uid = (int) $_SESSION['usuario_id'];
                        $qty = (int) $line['QUANTIDADE'];
                        $note = 'Estorno de cancelamento do pedido #' . $saleId;
                        $stmt(
                            "INSERT INTO MOVIMENTACAO_ESTOQUE (PRODUTO_ID,USUARIO_ID,TIPO,QUANTIDADE,OBSERVACAO) VALUES (?,?,'ENTRADA',?,?)",
                            'iiis',
                            [(int) $line['PRODUTO_ID'], $uid, $qty, $note],
                        );
                    }
                }
                $stmt('UPDATE VENDA SET STATUS=? WHERE ID=?', 'si', [
                    $next,
                    $saleId,
                ]);
                $stmt('UPDATE VENDA_ITEM SET STATUS=? WHERE VENDA_ID=?', 'si', [
                    $next,
                    $saleId,
                ]);
                if ($next === 'FINALIZADA') {
                    $today = date('Y-m-d');
                    $s = $stmt(
                        'SELECT ID FROM CONTA_RECEBER WHERE VENDA_ID=? LIMIT 1',
                        'i',
                        [$saleId],
                    );
                    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
                        $paidStatus =
                            ($body['forma_pagamento'] ?? '') === 'BOLETO'
                                ? 'PENDENTE'
                                : 'PAGO';
                        $s = $stmt(
                            'SELECT FORMA_PAGAMENTO FROM VENDA WHERE ID=?',
                            'i',
                            [$saleId],
                        );
                        $pm = mysqli_fetch_assoc(mysqli_stmt_get_result($s))[
                            'FORMA_PAGAMENTO'
                        ];
                        $paidStatus = $pm === 'BOLETO' ? 'PENDENTE' : 'PAGO';
                        $paymentDate = $paidStatus === 'PAGO' ? $today : null;
                        $stmt(
                            'INSERT INTO CONTA_RECEBER (VENDA_ID,DATA_VENCIMENTO,DATA_PAGAMENTO,VALOR,TOTAL,STATUS) SELECT ID,?, ?, TOTAL,TOTAL,? FROM VENDA WHERE ID=?',
                            'sssi',
                            [$today, $paymentDate, $paidStatus, $saleId],
                        );
                    }
                }
                if ($next === 'CANCELADA') {
                    $stmt(
                        "UPDATE CONTA_RECEBER SET STATUS='CANCELADO',DATA_PAGAMENTO=NULL WHERE VENDA_ID=?",
                        'i',
                        [$saleId],
                    );
                }
                mysqli_commit($con);
            } catch (Throwable $e) {
                mysqli_rollback($con);
                throw $e;
            }
            reply(['ok' => true, 'message' => 'Status do pedido atualizado.']);
        case 'cashflow':
            $in = $rows(
                "SELECT 'ENTRADA' tipo, 'VENDA' origem, cr.ID id, v.ID referencia, cr.DATA_VENCIMENTO vencimento, cr.DATA_PAGAMENTO pagamento, cr.TOTAL total, cr.STATUS status, cl.NOME contraparte FROM CONTA_RECEBER cr JOIN VENDA v ON v.ID=cr.VENDA_ID JOIN CLIENTE cl ON cl.ID=v.CLIENTE_ID ORDER BY cr.DATA_VENCIMENTO DESC,cr.ID DESC LIMIT 500",
            );
            $out = $rows(
                "SELECT 'SAIDA' tipo, 'COMPRA' origem, cp.ID id, c.ID referencia, cp.DATA_VENCIMENTO vencimento, cp.DATA_PAGAMENTO pagamento, cp.TOTAL total, cp.STATUS status, f.NOME contraparte FROM CONTA_PAGAR cp JOIN COMPRA c ON c.ID=cp.COMPRA_ID JOIN FORNECEDOR f ON f.ID=c.FORNECEDOR_ID ORDER BY cp.DATA_VENCIMENTO DESC,cp.ID DESC LIMIT 500",
            );
            $manual = $rows(
                "SELECT TIPO tipo,'MANUAL' origem,ID id,ID referencia,DATA_MOVIMENTACAO vencimento,DATA_PAGAMENTO pagamento,VALOR total,STATUS status,DESCRICAO contraparte,CATEGORIA categoria FROM MOVIMENTACAO_CAIXA ORDER BY DATA_MOVIMENTACAO DESC,ID DESC LIMIT 500",
            );
            reply(['data' => array_merge($in, $out, $manual)]);
        case 'pay_account':
            $accountId = $id('id');
            $type = $body['tipo'] ?? '';
            $payStatus = $body['status'] ?? '';
            if (
                !in_array($type, ['ENTRADA', 'SAIDA'], true) ||
                !in_array($payStatus, ['PAGO', 'PENDENTE', 'CANCELADO'], true)
            ) {
                reply(['error' => 'Status ou tipo inválido.'], 422);
            }
            $origin = $body['origem'] ?? '';
            if ($origin === 'MANUAL') {
                $stmt(
                    "UPDATE MOVIMENTACAO_CAIXA SET STATUS=?,DATA_PAGAMENTO=IF(?='PAGO',CURDATE(),NULL) WHERE ID=?",
                    'ssi',
                    [$payStatus, $payStatus, $accountId],
                );
            } else {
                if ($origin !== ($type === 'ENTRADA' ? 'VENDA' : 'COMPRA')) {
                    reply(['error' => 'Origem do lançamento inválida.'], 422);
                }
                $table = $type === 'ENTRADA' ? 'CONTA_RECEBER' : 'CONTA_PAGAR';
                $stmt(
                    "UPDATE {$table} SET STATUS=?, DATA_PAGAMENTO=IF(?='PAGO',CURDATE(),NULL) WHERE ID=?",
                    'ssi',
                    [$payStatus, $payStatus, $accountId],
                );
            }
            reply(['ok' => true, 'message' => 'Lançamento atualizado.']);
        case 'create_manual_flow':
            $type = $body['tipo'] ?? '';
            $description = $text('descricao', 255);
            $category = $text('categoria', 100);
            $value = filter_var($body['valor'] ?? null, FILTER_VALIDATE_FLOAT);
            $date = $text('data_movimentacao', 10);
            $status = $body['status'] ?? 'PENDENTE';
            if (
                !in_array($type, ['ENTRADA', 'SAIDA'], true) ||
                !in_array($status, ['PENDENTE', 'PAGO'], true) ||
                $value === false ||
                $value <= 0 ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ) {
                reply(
                    [
                        'error' =>
                            'Preencha os dados do lançamento corretamente.',
                    ],
                    422,
                );
            }
            $userId = (int) $_SESSION['usuario_id'];
            $paymentDate = $status === 'PAGO' ? $date : null;
            $stmt(
                'INSERT INTO MOVIMENTACAO_CAIXA (TIPO,DESCRICAO,CATEGORIA,VALOR,DATA_MOVIMENTACAO,DATA_PAGAMENTO,STATUS,USUARIO_ID) VALUES (?,?,?,?,?,?,?,?)',
                'sssdsssi',
                [
                    $type,
                    $description,
                    $category,
                    $value,
                    $date,
                    $paymentDate,
                    $status,
                    $userId,
                ],
            );
            reply(['ok' => true, 'message' => 'Lançamento registrado.']);
        case 'update_manual_flow':
            $flowId = $id('id');
            $exists = $stmt(
                "SELECT ID FROM MOVIMENTACAO_CAIXA WHERE ID=? AND STATUS<>'CANCELADO'",
                'i',
                [$flowId],
            );
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($exists))) {
                reply(
                    [
                        'error' =>
                            'Lançamento cancelado ou inexistente não pode ser editado.',
                    ],
                    404,
                );
            }
            $type = $body['tipo'] ?? '';
            if (!in_array($type, ['ENTRADA', 'SAIDA'], true)) {
                reply(['error' => 'Tipo de lançamento inválido.'], 422);
            }
            $description = $text('descricao', 255);
            $category = $text('categoria', 100);
            $value = filter_var($body['valor'] ?? null, FILTER_VALIDATE_FLOAT);
            $date = $text('data_movimentacao', 10);
            $status = $body['status'] ?? 'PENDENTE';
            if (
                $value === false ||
                $value <= 0 ||
                !in_array($status, ['PENDENTE', 'PAGO'], true) ||
                !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ) {
                reply(
                    [
                        'error' =>
                            'Preencha os dados do lançamento corretamente.',
                    ],
                    422,
                );
            }
            $paymentDate = $status === 'PAGO' ? $date : null;
            $stmt(
                'UPDATE MOVIMENTACAO_CAIXA SET TIPO=?,DESCRICAO=?,CATEGORIA=?,VALOR=?,DATA_MOVIMENTACAO=?,STATUS=?,DATA_PAGAMENTO=? WHERE ID=?',
                'sssdsssi',
                [
                    $type,
                    $description,
                    $category,
                    $value,
                    $date,
                    $status,
                    $paymentDate,
                    $flowId,
                ],
            );
            reply(['ok' => true, 'message' => 'Lançamento atualizado.']);
        case 'company':
            reply([
                'data' => $one(
                    'SELECT ID id,NOME nome,DOCUMENTO documento,TELEFONE telefone,EMAIL email,ENDERECO endereco,ENDERECO_NUMERO numero,CIDADE_ID cidade_id FROM EMPRESA WHERE ID=1',
                ),
            ]);
        case 'save_company':
            $name = $text('nome', 255);
            $doc = $text('documento', 18, false);
            $phone = $text('telefone', 15, false);
            $email = $text('email', 255, false);
            $addr = $text('endereco', 400, false);
            $num = $text('numero', 20, false);
            $city = (int) ($body['cidade_id'] ?? 0);
            if ($city < 1) {
                reply(['error' => 'Selecione a cidade.'], 422);
            }
            $stmt(
                'INSERT INTO EMPRESA (ID,NOME,DOCUMENTO,TELEFONE,EMAIL,ENDERECO,ENDERECO_NUMERO,CIDADE_ID) VALUES (1,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE NOME=VALUES(NOME),DOCUMENTO=VALUES(DOCUMENTO),TELEFONE=VALUES(TELEFONE),EMAIL=VALUES(EMAIL),ENDERECO=VALUES(ENDERECO),ENDERECO_NUMERO=VALUES(ENDERECO_NUMERO),CIDADE_ID=VALUES(CIDADE_ID)',
                'ssssssi',
                [$name, $doc, $phone, $email, $addr, $num, $city],
            );
            reply(['ok' => true, 'message' => 'Dados da empresa salvos.']);
        case 'settings':
            reply([
                'data' => $rows(
                    'SELECT CHAVE chave,VALOR valor,TIPO tipo FROM CONFIGURACAO_SISTEMA ORDER BY CHAVE',
                ),
            ]);
        case 'save_settings':
            $values = $body['values'] ?? [];
            if (!is_array($values) || count($values) > 40) {
                reply(['error' => 'Configurações inválidas.'], 422);
            }
            $allowed = ['alerta_estoque', 'formas_pagamento'];
            foreach ($values as $key => $value) {
                if (!in_array($key, $allowed, true)) {
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $val = (string) $value;
                if (
                    $key === 'alerta_estoque' &&
                    !in_array($val, ['0', '1'], true)
                ) {
                    reply(['error' => 'Valor inválido para alertas.'], 422);
                }
                if ($key === 'formas_pagamento') {
                    $allowedPayments = [
                        'DINHEIRO',
                        'PIX',
                        'CREDITO',
                        'DEBITO',
                        'BOLETO',
                    ];
                    $chosen = array_values(
                        array_intersect($allowedPayments, explode(',', $val)),
                    );
                    if (!$chosen) {
                        reply(
                            [
                                'error' =>
                                    'Selecione ao menos uma forma de pagamento.',
                            ],
                            422,
                        );
                    }
                    $val = implode(',', $chosen);
                }
                if (utf8Length($val) > 255) {
                    reply(
                        ['error' => 'Valor de configuração muito longo.'],
                        422,
                    );
                }
                $s = $stmt(
                    'INSERT INTO CONFIGURACAO_SISTEMA (CHAVE,VALOR,TIPO) VALUES (?,?,?) ON DUPLICATE KEY UPDATE VALOR=VALUES(VALOR),TIPO=VALUES(TIPO)',
                    'sss',
                    [$key, $val, 'string'],
                );
            }
            reply(['ok' => true, 'message' => 'Configurações salvas.']);
        default:
            reply(['error' => 'Ação não reconhecida.'], 404);
    }
} catch (mysqli_sql_exception $e) {
    if (isset($con) && $con) {
        mysqli_close($con);
    }
    $msg =
        $e->getCode() === 1062
            ? 'Já existe um registro com esse documento, nome ou código.'
            : ($e->getCode() === 1451
                ? 'Este registro está relacionado a um histórico e não pode ser removido.'
                : 'Falha ao acessar o banco de dados. Verifique a conexão e se a migração foi aplicada.');
    reply(['error' => $msg], 400);
} catch (Throwable $e) {
    if (isset($con) && $con) {
        mysqli_close($con);
    }
    reply(['error' => $e->getMessage() ?: 'Ocorreu um erro inesperado.'], 400);
}
