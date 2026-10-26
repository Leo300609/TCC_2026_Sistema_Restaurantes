<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

Api::iniciar(['listar', 'opcoes'], ['criar', 'atualizar', 'status', 'excluir']);

/** Encontra (ou cadastra) a cidade a partir da sigla do estado e do nome. */
function garantirCidade(string $uf, string $nome): int
{
    $estado = Api::linha('SELECT ID FROM ESTADO WHERE SIGLA=?', 's', [$uf]);
    if ($estado === null) {
        Api::falhar('Estado inválido.');
    }

    $cidade = Api::linha('SELECT ID FROM CIDADE WHERE NOME=? AND ESTADO_ID=?', 'si', [$nome, $estado['ID']]);
    if ($cidade !== null) {
        return (int) $cidade['ID'];
    }

    Api::comando('INSERT INTO CIDADE (NOME, ESTADO_ID) VALUES (?, ?)', 'si', [$nome, $estado['ID']]);
    return Api::idInserido();
}

/** @return array<string, mixed> */
function lerCliente(?int $idAtual): array
{
    $documento = Api::digitos(Api::texto('documento', 18, true, 'CPF/CNPJ'));
    if (!in_array(strlen($documento), [11, 14], true)) {
        Api::falhar('Informe um CPF (11 números) ou CNPJ (14 números) válido.');
    }

    $telefone = Api::digitos(Api::texto('telefone', 20, true, 'telefone'));
    if (!in_array(strlen($telefone), [10, 11], true)) {
        Api::falhar('Informe o telefone com DDD (10 ou 11 números).');
    }

    $uf = strtoupper(Api::texto('estado', 2, true, 'estado'));
    $cidade = Api::texto('cidade', 200, true, 'cidade');

    if (Api::linha('SELECT ID FROM CLIENTE WHERE DOCUMENTO=? AND ID<>?', 'si', [$documento, $idAtual ?? 0])) {
        Api::falhar('Já existe um cliente com esse CPF/CNPJ.', 409);
    }

    return [
        'nome' => Api::texto('nome', 255, true, 'nome'),
        'documento' => $documento,
        'endereco' => Api::texto('endereco', 400, true, 'endereço'),
        'numero' => Api::texto('endereco_numero', 20, true, 'número'),
        'telefone' => $telefone,
        'contato' => Api::texto('contato', 255, false, 'contato'),
        'status' => Api::opcao('status', ['ATIVO', 'INATIVO'], 'ATIVO', 'situação'),
        'cidade_id' => garantirCidade($uf, $cidade),
    ];
}

function exigirCliente(int $id): array
{
    return Api::linha('SELECT ID, NOME FROM CLIENTE WHERE ID=?', 'i', [$id]) ??
        Api::falhar('Cliente não encontrado.', 404);
}

Api::executar(function (): void {
    switch (Api::$acao) {
        case 'listar':
            $busca = Api::parametro('q');
            $situacao = Api::parametro('status', 'TODOS');
            [$pagina, $porPagina, $deslocamento] = Api::paginacao(12);

            $filtros = [];
            $tipos = '';
            $valores = [];

            if ($busca !== '') {
                $numeros = Api::digitos($busca);
                $partes = ['c.NOME LIKE ?'];
                $tipos .= 's';
                $valores[] = Api::like($busca);

                if ($numeros !== '') {
                    $partes[] = 'c.DOCUMENTO LIKE ?';
                    $partes[] = 'c.TELEFONE LIKE ?';
                    $tipos .= 'ss';
                    $valores[] = Api::like($numeros);
                    $valores[] = Api::like($numeros);
                }

                $filtros[] = '(' . implode(' OR ', $partes) . ')';
            }

            $contagem = Api::linha(
                "SELECT COUNT(*) AS todos,
                        COALESCE(SUM(c.STATUS='ATIVO'), 0) AS ativos,
                        COALESCE(SUM(c.STATUS='INATIVO'), 0) AS inativos
                 FROM CLIENTE c" . ($filtros ? ' WHERE ' . implode(' AND ', $filtros) : ''),
                $tipos,
                $valores,
            );

            if (in_array($situacao, ['ATIVO', 'INATIVO'], true)) {
                $filtros[] = 'c.STATUS=?';
                $tipos .= 's';
                $valores[] = $situacao;
            }

            $onde = $filtros ? ' WHERE ' . implode(' AND ', $filtros) : '';
            $total = (int) Api::valor("SELECT COUNT(*) FROM CLIENTE c{$onde}", $tipos, $valores);

            $dados = Api::linhas(
                "SELECT c.ID AS id, c.NOME AS nome, c.DOCUMENTO AS documento, c.ENDERECO AS endereco,
                        c.ENDERECO_NUMERO AS endereco_numero, c.TELEFONE AS telefone, c.CONTATO AS contato,
                        c.STATUS AS status, ci.NOME AS cidade, es.SIGLA AS estado,
                        (SELECT COUNT(*) FROM VENDA v WHERE v.CLIENTE_ID=c.ID AND v.STATUS<>'CANCELADA') AS pedidos,
                        (SELECT COALESCE(SUM(v.TOTAL), 0) FROM VENDA v WHERE v.CLIENTE_ID=c.ID AND v.STATUS='FINALIZADA') AS total_gasto
                 FROM CLIENTE c
                 JOIN CIDADE ci ON ci.ID=c.CIDADE_ID
                 JOIN ESTADO es ON es.ID=ci.ESTADO_ID
                 {$onde}
                 ORDER BY c.NOME
                 LIMIT ? OFFSET ?",
                $tipos . 'ii',
                array_merge($valores, [$porPagina, $deslocamento]),
            );

            Api::responder([
                'data' => $dados,
                'total' => $total,
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'paginas' => max(1, (int) ceil($total / $porPagina)),
                'contagem' => [
                    'todos' => (int) $contagem['todos'],
                    'ativos' => (int) $contagem['ativos'],
                    'inativos' => (int) $contagem['inativos'],
                ],
                'pode_excluir' => Api::ehGerente(),
            ]);

        case 'opcoes':
            Api::responder([
                'estados' => Api::linhas("SELECT SIGLA AS sigla, NOME AS nome FROM ESTADO WHERE STATUS='ATIVO' ORDER BY NOME"),
                'cidades' => Api::linhas(
                    "SELECT ci.NOME AS nome, es.SIGLA AS estado FROM CIDADE ci JOIN ESTADO es ON es.ID=ci.ESTADO_ID WHERE ci.STATUS='ATIVO' ORDER BY ci.NOME",
                ),
            ]);

        case 'criar':
            $c = lerCliente(null);
            Api::comando(
                'INSERT INTO CLIENTE (NOME, DOCUMENTO, ENDERECO, ENDERECO_NUMERO, TELEFONE, CONTATO, STATUS, CIDADE_ID) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                'sssssssi',
                [$c['nome'], $c['documento'], $c['endereco'], $c['numero'], $c['telefone'], $c['contato'] ?: null, $c['status'], $c['cidade_id']],
            );
            Api::responder(['ok' => true, 'id' => Api::idInserido(), 'message' => 'Cliente cadastrado.'], 201);

        case 'atualizar':
            $id = Api::inteiro('id');
            exigirCliente($id);
            $c = lerCliente($id);
            Api::comando(
                'UPDATE CLIENTE SET NOME=?, DOCUMENTO=?, ENDERECO=?, ENDERECO_NUMERO=?, TELEFONE=?, CONTATO=?, STATUS=?, CIDADE_ID=? WHERE ID=?',
                'sssssssii',
                [$c['nome'], $c['documento'], $c['endereco'], $c['numero'], $c['telefone'], $c['contato'] ?: null, $c['status'], $c['cidade_id'], $id],
            );
            Api::responder(['ok' => true, 'message' => 'Cliente atualizado.']);

        case 'status':
            $id = Api::inteiro('id');
            exigirCliente($id);
            $status = Api::opcao('status', ['ATIVO', 'INATIVO'], null, 'situação');
            Api::comando('UPDATE CLIENTE SET STATUS=? WHERE ID=?', 'si', [$status, $id]);
            Api::responder([
                'ok' => true,
                'message' => $status === 'ATIVO' ? 'Cliente reativado.' : 'Cliente inativado.',
            ]);

        case 'excluir':
            Api::exigirGerente();
            $id = Api::inteiro('id');
            exigirCliente($id);

            if ((int) Api::valor('SELECT COUNT(*) FROM VENDA WHERE CLIENTE_ID=?', 'i', [$id]) > 0) {
                Api::comando("UPDATE CLIENTE SET STATUS='INATIVO' WHERE ID=?", 'i', [$id]);
                Api::responder([
                    'ok' => true,
                    'inativado' => true,
                    'message' => 'O cliente tem pedidos no histórico, então foi inativado em vez de excluído.',
                ]);
            }

            Api::comando('DELETE FROM CLIENTE WHERE ID=?', 'i', [$id]);
            Api::responder(['ok' => true, 'message' => 'Cliente excluído.']);
    }
});
