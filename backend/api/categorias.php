<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

/**
 * Categorias de produto.
 *   tipo=CARDAPIO → categorias do Cardápio
 *   tipo=INSUMO   → categorias só de estoque (ingredientes, embalagens...)
 * Leitura: todos os logados. Escrita: administrador ou gerente.
 */
Api::iniciar(['listar'], ['criar', 'atualizar', 'excluir']);

function tipoCategoria(bool $obrigatorio = true): ?string
{
    $bruto = Api::$metodo === 'GET' ? $_GET['tipo'] ?? '' : Api::$corpo['tipo'] ?? '';

    if ($bruto === '' && !$obrigatorio) {
        return null;
    }
    if (!in_array($bruto, ['CARDAPIO', 'INSUMO'], true)) {
        Api::falhar('Tipo de categoria inválido.');
    }

    return $bruto;
}

function exigirCategoria(int $id): array
{
    return Api::linha('SELECT ID, NOME, TIPO FROM PRODUTO_CATEGORIA WHERE ID=?', 'i', [$id]) ??
        Api::falhar('Categoria não encontrada.', 404);
}

Api::executar(function (): void {
    switch (Api::$acao) {
        case 'listar':
            $tipo = tipoCategoria(false);
            $onde = $tipo === null ? '' : 'WHERE c.TIPO=?';

            $dados = Api::linhas(
                "SELECT c.ID AS id, c.NOME AS nome, c.DESCRICAO AS descricao, c.STATUS AS status, c.TIPO AS tipo,
                        (SELECT COUNT(*) FROM PRODUTO p WHERE p.CATEGORIA_ID=c.ID) AS itens
                 FROM PRODUTO_CATEGORIA c {$onde}
                 ORDER BY c.NOME",
                $tipo === null ? '' : 's',
                $tipo === null ? [] : [$tipo],
            );

            Api::responder(['data' => $dados, 'pode_editar' => Api::ehGerente()]);

        case 'criar':
        case 'atualizar':
            Api::exigirGerente();
            $id = Api::$acao === 'atualizar' ? Api::inteiro('id') : null;
            $nome = Api::texto('nome', 200, true, 'nome');
            $descricao = Api::texto('descricao', 255, false, 'descrição');
            $status = Api::opcao('status', ['ATIVO', 'INATIVO'], 'ATIVO', 'situação');

            if (Api::linha('SELECT ID FROM PRODUTO_CATEGORIA WHERE NOME=? AND ID<>?', 'si', [$nome, $id ?? 0])) {
                Api::falhar('Já existe uma categoria com esse nome.', 409);
            }

            if ($id === null) {
                Api::comando(
                    'INSERT INTO PRODUTO_CATEGORIA (NOME, DESCRICAO, STATUS, TIPO) VALUES (?, ?, ?, ?)',
                    'ssss',
                    [$nome, $descricao === '' ? $nome : $descricao, $status, tipoCategoria()],
                );
                Api::responder(['ok' => true, 'id' => Api::idInserido(), 'message' => 'Categoria criada.'], 201);
            }

            exigirCategoria($id);
            Api::comando(
                'UPDATE PRODUTO_CATEGORIA SET NOME=?, DESCRICAO=?, STATUS=? WHERE ID=?',
                'sssi',
                [$nome, $descricao === '' ? $nome : $descricao, $status, $id],
            );
            Api::responder(['ok' => true, 'message' => 'Categoria atualizada.']);

        case 'excluir':
            Api::exigirGerente();
            $id = Api::inteiro('id');
            exigirCategoria($id);

            $itens = (int) Api::valor('SELECT COUNT(*) FROM PRODUTO WHERE CATEGORIA_ID=?', 'i', [$id]);
            if ($itens > 0) {
                Api::falhar(
                    "A categoria tem {$itens} item(ns). Mova ou remova os itens antes de excluí-la, ou apenas inative a categoria.",
                    409,
                );
            }

            Api::comando('DELETE FROM PRODUTO_CATEGORIA WHERE ID=?', 'i', [$id]);
            Api::responder(['ok' => true, 'message' => 'Categoria excluída.']);
    }
});
