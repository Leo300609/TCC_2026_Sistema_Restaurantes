<?php
declare(strict_types=1);

require_once __DIR__ . '/_comum.php';

/**
 * Configurações.
 *  - Conta (nome, e-mail, senha) e Notificações: cada usuário cuida das suas.
 *  - Operação (delivery, tempo de preparo, formas de pagamento): administrador ou gerente.
 */
Api::iniciar(['obter'], ['salvar']);

function lerPreferencias(): array
{
    $id = Api::usuarioId();

    return [
        'notif_pedido' => Api::configuracao('notif_pedido', '1', $id) === '1',
        'notif_minimo' => Api::configuracao('notif_minimo', '1', $id) === '1',
        'notif_validade' => Api::configuracao('notif_validade', '1', $id) === '1',
        'notif_contas' => Api::configuracao('notif_contas', '0', $id) === '1',
        'dias_validade' => (int) Api::configuracao('dias_validade', '3', $id),
    ];
}

/** Confere a senha do mesmo jeito que o login (hash ou, nas contas antigas, texto puro). */
function senhaConfere(string $informada, string $guardada): bool
{
    return password_verify($informada, $guardada) || hash_equals($guardada, $informada);
}

Api::executar(function (): void {
    switch (Api::$acao) {
        case 'obter':
            $conta = Api::linha(
                "SELECT NOME AS nome, EMAIL AS email, NIVEL AS nivel, COALESCE(CARGO, 'FUNCIONARIO') AS cargo FROM USUARIOS WHERE ID=?",
                'i',
                [Api::usuarioId()],
            ) ?? Api::falhar('Usuário não encontrado.', 404);

            Api::responder([
                'conta' => $conta,
                'notificacoes' => lerPreferencias(),
                'operacao' => Api::operacao(),
                'formas_pagamento' => Api::PAGAMENTOS,
                'pode_editar_operacao' => Api::ehGerente(),
            ]);

        case 'salvar':
            $id = Api::usuarioId();

            // ---- Conta
            $nome = Api::texto('nome', 255, true, 'nome');
            $email = Api::texto('email', 255, true, 'e-mail');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                Api::falhar('Informe um e-mail válido.');
            }
            if (Api::linha('SELECT ID FROM USUARIOS WHERE EMAIL=? AND ID<>?', 'si', [$email, $id])) {
                Api::falhar('Esse e-mail já é usado por outra conta.', 409);
            }

            $senhaNova = (string) (Api::$corpo['senha_nova'] ?? '');
            $novoHash = null;
            if ($senhaNova !== '') {
                $atual = (string) (Api::$corpo['senha_atual'] ?? '');
                $guardada = (string) Api::valor('SELECT SENHA FROM USUARIOS WHERE ID=?', 'i', [$id]);

                if ($atual === '' || !senhaConfere($atual, $guardada)) {
                    Api::falhar('A senha atual está incorreta.', 403);
                }
                if (Api::tamanho($senhaNova) < 8) {
                    Api::falhar('A nova senha precisa ter ao menos 8 caracteres.');
                }
                $novoHash = password_hash($senhaNova, PASSWORD_BCRYPT);
            }

            // ---- Notificações
            $diasValidade = Api::inteiro('dias_validade', false, 1, 60, 'dias de antecedência') ?? 3;
            $notificacoes = [
                'notif_pedido' => Api::booleano('notif_pedido'),
                'notif_minimo' => Api::booleano('notif_minimo'),
                'notif_validade' => Api::booleano('notif_validade'),
                'notif_contas' => Api::booleano('notif_contas'),
            ];

            // ---- Operação (somente gerência)
            $operacao = null;
            if (Api::ehGerente() && array_key_exists('tempo_preparo', Api::$corpo)) {
                $formas = Api::$corpo['pagamentos'] ?? null;
                if (!is_array($formas) || array_diff($formas, Api::PAGAMENTOS) !== [] || $formas === []) {
                    Api::falhar('Escolha ao menos uma forma de pagamento válida.');
                }

                $operacao = [
                    'aceita_delivery' => Api::booleano('aceita_delivery'),
                    'tempo_preparo' => Api::inteiro('tempo_preparo', true, 5, 240, 'tempo de preparo'),
                    'pagamentos' => array_values(array_unique($formas)),
                ];
            }

            Api::transacao(static function () use ($id, $nome, $email, $novoHash, $diasValidade, $notificacoes, $operacao): void {
                if ($novoHash === null) {
                    Api::comando('UPDATE USUARIOS SET NOME=?, EMAIL=? WHERE ID=?', 'ssi', [$nome, $email, $id]);
                } else {
                    Api::comando('UPDATE USUARIOS SET NOME=?, EMAIL=?, SENHA=? WHERE ID=?', 'sssi', [$nome, $email, $novoHash, $id]);
                }

                foreach ($notificacoes as $chave => $ligado) {
                    Api::salvarConfiguracao($id, $chave, $ligado ? '1' : '0');
                }
                Api::salvarConfiguracao($id, 'dias_validade', (string) $diasValidade);

                if ($operacao !== null) {
                    Api::salvarConfiguracao(0, 'aceita_delivery', $operacao['aceita_delivery'] ? '1' : '0');
                    Api::salvarConfiguracao(0, 'tempo_preparo', (string) $operacao['tempo_preparo']);
                    Api::salvarConfiguracao(0, 'pagamentos', implode(',', $operacao['pagamentos']));
                }
            });

            $_SESSION['usuario_nome'] = $nome;
            Api::responder([
                'ok' => true,
                'message' => $novoHash === null ? 'Configurações salvas.' : 'Configurações salvas e senha alterada.',
            ]);
    }
});
