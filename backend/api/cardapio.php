<?php
declare(strict_types=1);

require_once __DIR__ . '/_produto.php';

/**
 * Cardápio = produtos cuja categoria é do tipo CARDAPIO.
 * Ver: qualquer usuário logado. Criar/editar/excluir: administrador ou gerente.
 * Marcar disponível/indisponível: gerente ou quem acessa o estoque (cozinha).
 */
Api::iniciar(['listar'], ['criar', 'atualizar', 'disponibilidade', 'excluir']);

function exigirItemDoCardapio(int $id): array
{
    $item = Api::linha(
        "SELECT p.ID, p.NOME FROM PRODUTO p JOIN PRODUTO_CATEGORIA c ON c.ID=p.CATEGORIA_ID WHERE p.ID=? AND c.TIPO='CARDAPIO'",
        'i',
        [$id],
    );

    return $item ?? Api::falhar('Item do cardápio não encontrado.', 404);
}

Api::executar(function (): void {
    switch (Api::$acao) {
        case 'listar':
            $busca = Api::parametro('q');
            $onde = "WHERE c.TIPO='CARDAPIO'";
            $tipos = '';
            $valores = [];

            if ($busca !== '') {
                $onde .= ' AND (p.NOME LIKE ? OR p.DESCRICAO LIKE ?)';
                $tipos = 'ss';
                $valores = [Api::like($busca), Api::like($busca)];
            }

            $itens = Api::linhas(
                "SELECT p.ID AS id, p.NOME AS nome, p.DESCRICAO AS descricao, p.PRECO_UNITARIO AS preco,
                        p.STATUS AS status, p.CATEGORIA_ID AS categoria_id, c.NOME AS categoria,
                        p.CODIGO_BARRAS AS codigo_barras, p.NCM AS ncm
                 FROM PRODUTO p
                 JOIN PRODUTO_CATEGORIA c ON c.ID=p.CATEGORIA_ID
                 {$onde}
                 ORDER BY c.NOME, p.NOME",
                $tipos,
                $valores,
            );

            $categorias = Api::linhas(
                "SELECT c.ID AS id, c.NOME AS nome, c.STATUS AS status,
                        (SELECT COUNT(*) FROM PRODUTO p WHERE p.CATEGORIA_ID=c.ID) AS itens
                 FROM PRODUTO_CATEGORIA c
                 WHERE c.TIPO='CARDAPIO'
                 ORDER BY c.NOME",
            );

            Api::responder([
                'data' => $itens,
                'categorias' => $categorias,
                'pode_editar' => Api::ehGerente(),
                'pode_disponibilidade' => Api::podeEstoque(),
            ]);

        case 'criar':
            Api::exigirGerente();
            $id = Produto::inserir(Produto::lerCampos(true, null));
            Api::responder(['ok' => true, 'id' => $id, 'message' => 'Item adicionado ao cardápio.'], 201);

        case 'atualizar':
            Api::exigirGerente();
            $id = Api::inteiro('id');
            exigirItemDoCardapio($id);
            Produto::atualizar($id, Produto::lerCampos(true, $id));
            Api::responder(['ok' => true, 'message' => 'Item atualizado.']);

        case 'disponibilidade':
            if (!Api::ehGerente() && !Api::podeEstoque()) {
                Api::falhar('Você não pode alterar a disponibilidade dos itens.', 403);
            }

            $id = Api::inteiro('id');
            exigirItemDoCardapio($id);
            $disponivel = Api::booleano('disponivel');
            Api::comando('UPDATE PRODUTO SET STATUS=? WHERE ID=?', 'si', [$disponivel ? 'ATIVO' : 'INATIVO', $id]);
            Api::responder([
                'ok' => true,
                'message' => $disponivel ? 'Item disponível no cardápio.' : 'Item marcado como indisponível.',
            ]);

        case 'excluir':
            Api::exigirGerente();
            $id = Api::inteiro('id');
            exigirItemDoCardapio($id);

            if (Produto::excluirOuInativar($id)) {
                Api::responder([
                    'ok' => true,
                    'inativado' => true,
                    'message' => 'O item tem vendas ou movimentações no histórico, então foi marcado como indisponível em vez de excluído.',
                ]);
            }

            Api::responder(['ok' => true, 'message' => 'Item excluído do cardápio.']);
    }
});
