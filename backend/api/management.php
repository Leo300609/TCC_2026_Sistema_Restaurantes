<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

function utf8Length(string $value): int
{
    $length = preg_match_all('/./us', $value, $matches);
    return $length === false ? strlen($value) : $length;
}

if (!isset($_SESSION['usuario_id'])) {
    reply(['error' => 'Sessão expirada. Entre novamente.'], 401);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = $_POST;

if ($method === 'POST') {
    $jsonBody = json_decode(file_get_contents('php://input'), true);
    if (is_array($jsonBody)) {
        $body = $jsonBody;
    }
}

$action = $method === 'GET' ? $_GET['action'] ?? '' : $body['action'] ?? '';
$actions = [
    'cashflow',
    'pay_account',
    'create_manual_flow',
    'update_manual_flow',
    'delete_manual_flow',
];

if (!is_string($action) || !in_array($action, $actions, true)) {
    reply(['error' => 'Ação não reconhecida.'], 404);
}

if (
    ($action === 'cashflow' && $method !== 'GET') ||
    ($action !== 'cashflow' && $method !== 'POST')
) {
    reply(['error' => 'Método não permitido para esta ação.'], 405);
}

$isAdmin = ($_SESSION['usuario_nivel'] ?? '') === 'ADMIN';
$userRole = $_SESSION['usuario_cargo'] ?? '';

if (!$isAdmin && $userRole !== 'Gerente') {
    reply(['error' => 'Acesso restrito ao financeiro.'], 403);
}

if ($method === 'POST') {
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';

    if (
        $csrf === '' ||
        $sessionToken === '' ||
        !hash_equals($sessionToken, $csrf)
    ) {
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
        $value = filter_var($body[$key] ?? null, FILTER_VALIDATE_INT);

        if ($value === false || $value < 1) {
            reply(['error' => 'Identificador inválido.'], 422);
        }

        return $value;
    };

    $text = static function (string $key, int $max) use ($body): string {
        $value = $body[$key] ?? '';

        if (!is_scalar($value)) {
            reply(['error' => "Preencha o campo {$key} corretamente."], 422);
        }

        $value = trim((string) $value);

        if ($value === '') {
            reply(['error' => "Preencha o campo {$key}."], 422);
        }

        if (utf8Length($value) > $max) {
            reply(['error' => "O campo {$key} excede {$max} caracteres."], 422);
        }

        return $value;
    };

    $validDate = static function (string $date): bool {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts)) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    };

    $stmt = static function (
        string $sql,
        string $types = '',
        array $values = [],
    ) use ($con): mysqli_stmt {
        $statement = mysqli_prepare($con, $sql);

        if ($types !== '') {
            $references = [];
            foreach ($values as $index => &$value) {
                $references[$index] = &$value;
            }
            unset($value);
            mysqli_stmt_bind_param($statement, $types, ...$references);
        }

        mysqli_stmt_execute($statement);
        return $statement;
    };

    $rows = static function (string $sql) use ($con): array {
        $result = mysqli_query($con, $sql);
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    };

    switch ($action) {
        case 'cashflow':
            $incoming = $rows(
                "SELECT 'ENTRADA' tipo, 'VENDA' origem, cr.ID id, v.ID referencia, cr.DATA_VENCIMENTO vencimento, cr.DATA_PAGAMENTO pagamento, cr.TOTAL total, cr.STATUS status, COALESCE(cl.NOME, 'Consumidor final') contraparte FROM CONTA_RECEBER cr JOIN VENDA v ON v.ID=cr.VENDA_ID LEFT JOIN CLIENTE cl ON cl.ID=v.CLIENTE_ID ORDER BY cr.DATA_VENCIMENTO DESC, cr.ID DESC LIMIT 500",
            );
            $outgoing = $rows(
                "SELECT 'SAIDA' tipo, 'COMPRA' origem, cp.ID id, c.ID referencia, cp.DATA_VENCIMENTO vencimento, cp.DATA_PAGAMENTO pagamento, cp.TOTAL total, cp.STATUS status, f.NOME contraparte FROM CONTA_PAGAR cp JOIN COMPRA c ON c.ID=cp.COMPRA_ID JOIN FORNECEDOR f ON f.ID=c.FORNECEDOR_ID ORDER BY cp.DATA_VENCIMENTO DESC, cp.ID DESC LIMIT 500",
            );
            $manual = $rows(
                "SELECT TIPO tipo, 'MANUAL' origem, ID id, ID referencia, DATA_MOVIMENTACAO vencimento, DATA_PAGAMENTO pagamento, VALOR total, STATUS status, DESCRICAO contraparte, CATEGORIA categoria FROM MOVIMENTACAO_CAIXA ORDER BY DATA_MOVIMENTACAO DESC, ID DESC LIMIT 500",
            );
            $data = array_merge($incoming, $outgoing, $manual);
            usort($data, static function (array $left, array $right): int {
                $dateOrder = strcmp(
                    (string) $right['vencimento'],
                    (string) $left['vencimento'],
                );
                return $dateOrder !== 0
                    ? $dateOrder
                    : (int) $right['id'] <=> (int) $left['id'];
            });

            reply(['data' => $data]);

        case 'pay_account':
            $accountId = $id('id');
            $type = $body['tipo'] ?? '';
            $status = $body['status'] ?? '';
            $origin = $body['origem'] ?? '';

            if (
                !in_array($type, ['ENTRADA', 'SAIDA'], true) ||
                !in_array($status, ['PAGO', 'PENDENTE', 'CANCELADO'], true)
            ) {
                reply(['error' => 'Status ou tipo inválido.'], 422);
            }

            if ($origin === 'MANUAL') {
                $check = $stmt(
                    'SELECT TIPO, STATUS FROM MOVIMENTACAO_CAIXA WHERE ID=?',
                    'i',
                    [$accountId],
                );
                $current = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

                if (!$current) {
                    reply(['error' => 'Lançamento não encontrado.'], 404);
                }
                if ($current['TIPO'] !== $type) {
                    reply(
                        ['error' => 'Tipo do lançamento não corresponde.'],
                        422,
                    );
                }
                if (
                    $current['STATUS'] === 'CANCELADO' &&
                    $status !== 'CANCELADO'
                ) {
                    reply(
                        [
                            'error' =>
                                'Um lançamento cancelado não pode ser reaberto.',
                        ],
                        409,
                    );
                }

                $stmt(
                    "UPDATE MOVIMENTACAO_CAIXA SET STATUS=?, DATA_PAGAMENTO=IF(?='PAGO', CURDATE(), NULL) WHERE ID=?",
                    'ssi',
                    [$status, $status, $accountId],
                );
            } else {
                $expectedOrigin = $type === 'ENTRADA' ? 'VENDA' : 'COMPRA';

                if ($origin !== $expectedOrigin) {
                    reply(['error' => 'Origem do lançamento inválida.'], 422);
                }

                $table = $type === 'ENTRADA' ? 'CONTA_RECEBER' : 'CONTA_PAGAR';
                $check = $stmt("SELECT STATUS FROM {$table} WHERE ID=?", 'i', [
                    $accountId,
                ]);
                $current = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

                if (!$current) {
                    reply(['error' => 'Lançamento não encontrado.'], 404);
                }
                if (
                    $current['STATUS'] === 'CANCELADO' &&
                    $status !== 'CANCELADO'
                ) {
                    reply(
                        [
                            'error' =>
                                'Um lançamento cancelado não pode ser reaberto.',
                        ],
                        409,
                    );
                }

                $stmt(
                    "UPDATE {$table} SET STATUS=?, DATA_PAGAMENTO=IF(?='PAGO', CURDATE(), NULL) WHERE ID=?",
                    'ssi',
                    [$status, $status, $accountId],
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
                !$validDate($date)
            ) {
                reply(
                    [
                        'error' =>
                            'Preencha os dados do lançamento corretamente.',
                    ],
                    422,
                );
            }

            $userId = filter_var($_SESSION['usuario_id'], FILTER_VALIDATE_INT);
            if ($userId === false || $userId < 1) {
                reply(['error' => 'Usuário da sessão inválido.'], 401);
            }

            $paymentDate = $status === 'PAGO' ? $date : null;
            $stmt(
                'INSERT INTO MOVIMENTACAO_CAIXA (TIPO, DESCRICAO, CATEGORIA, VALOR, DATA_MOVIMENTACAO, DATA_PAGAMENTO, STATUS, USUARIO_ID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
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
                !$validDate($date)
            ) {
                reply(
                    [
                        'error' =>
                            'Preencha os dados do lançamento corretamente.',
                    ],
                    422,
                );
            }

            $check = $stmt(
                "SELECT ID FROM MOVIMENTACAO_CAIXA WHERE ID=? AND STATUS<>'CANCELADO'",
                'i',
                [$flowId],
            );
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
                reply(
                    [
                        'error' =>
                            'Lançamento cancelado ou inexistente não pode ser editado.',
                    ],
                    404,
                );
            }

            $paymentDate = $status === 'PAGO' ? $date : null;
            $stmt(
                'UPDATE MOVIMENTACAO_CAIXA SET TIPO=?, DESCRICAO=?, CATEGORIA=?, VALOR=?, DATA_MOVIMENTACAO=?, STATUS=?, DATA_PAGAMENTO=? WHERE ID=?',
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

        case 'delete_manual_flow':
            $flowId = $id('id');
            $check = $stmt(
                'SELECT ID FROM MOVIMENTACAO_CAIXA WHERE ID=?',
                'i',
                [$flowId],
            );
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($check))) {
                reply(['error' => 'Lançamento não encontrado.'], 404);
            }

            $stmt('DELETE FROM MOVIMENTACAO_CAIXA WHERE ID=?', 'i', [$flowId]);

            reply(['ok' => true, 'message' => 'Lançamento excluído.']);
    }
} catch (Throwable $error) {
    error_log('Fluxo de Caixa API: ' . $error->getMessage());
    reply(
        ['error' => 'Não foi possível processar a solicitação financeira.'],
        500,
    );
}
