<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE VIEW saldo_akun AS
            SELECT
                a.id AS akun_id,
                a.nama,
                COALESCE(SUM(CASE WHEN t.akun_tujuan_id = a.id THEN t.nominal ELSE 0 END), 0)
                    - COALESCE(SUM(CASE WHEN t.akun_asal_id = a.id THEN t.nominal ELSE 0 END), 0) AS saldo_rupiah
            FROM akun_dana a
            LEFT JOIN transaksi t ON t.akun_tujuan_id = a.id OR t.akun_asal_id = a.id
            GROUP BY a.id, a.nama
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldo_akun_view');
    }
};
