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
        // DROP lalu CREATE (bukan CREATE OR REPLACE) supaya juga jalan di SQLite yang dipakai saat testing.
        DB::statement('DROP VIEW IF EXISTS saldo_kas_periode');
        DB::statement(<<<'SQL'
            CREATE VIEW saldo_kas_periode AS
            SELECT
                r.periode_id,
                r.nama,
                r.tanggal_mulai,
                r.tanggal_tutup,
                SUM(r.masuk - r.keluar) OVER urutan - (r.masuk - r.keluar) AS saldo_awal,
                r.masuk,
                r.keluar,
                SUM(r.masuk - r.keluar) OVER urutan AS saldo_akhir
            FROM (
                SELECT
                    p.id AS periode_id,
                    p.nama,
                    p.tanggal_mulai,
                    p.tanggal_tutup,
                    COALESCE(SUM(CASE WHEN t.akun_tujuan_id = kas.id THEN t.nominal ELSE 0 END), 0) AS masuk,
                    COALESCE(SUM(CASE WHEN t.akun_asal_id = kas.id THEN t.nominal ELSE 0 END), 0) AS keluar
                FROM periode_kas p
                JOIN akun_dana kas ON kas.nama = 'Kas'
                LEFT JOIN transaksi t ON t.periode_kas_id = p.id
                GROUP BY p.id, p.nama, p.tanggal_mulai, p.tanggal_tutup
            ) r
            WINDOW urutan AS (ORDER BY r.tanggal_mulai, r.periode_id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW)
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('saldo_kas_periode_view');
    }
};
