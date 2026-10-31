<?php
require_once __DIR__ . '/config.php';

/**
 * Conexão mysqli com o banco. Os dados de acesso vêm de config.php.
 * As páginas usam bd() (backend/lib/bd.php), que reaproveita esta classe.
 */
class BancodeDados
{
    public ?mysqli $con = null;

    // Abre a conexão; em caso de erro lança exceção (quem chama decide como avisar).
    public function conecta(): void
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $this->con = new mysqli(
            (string) config('db_host'),
            (string) config('db_usuario'),
            (string) config('db_senha'),
            (string) config('db_nome'),
            (int) config('db_porta')
        );
        $this->con->set_charset('utf8mb4');
        $this->con->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        // NOW() e CURDATE() do banco no mesmo fuso do PHP
        $this->con->query("SET time_zone = '" . date('P') . "'");
    }

    public function fechar(): void
    {
        if ($this->con) {
            $this->con->close();
            $this->con = null;
        }
    }
}
