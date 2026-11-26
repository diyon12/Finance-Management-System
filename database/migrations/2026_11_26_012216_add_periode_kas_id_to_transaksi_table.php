<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
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
        Schema::table('transaksi', function (Blueprint $table) {
            $kolom = $table->foreignId('periode_kas_id')->nullable()->after('akun_tujuan_id');

            // SQLite (dipakai saat testing) harus membangun ulang tabel untuk menambah foreign key,
            // dan itu gagal karena view saldo_akun memakai tabel transaksi. Di MySQL tetap pakai foreign key.
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $kolom->constrained('periode_kas')->restrictOnDelete();
            }
        });

        $kas = DB::table('akun_dana')->where('nama', 'Kas')->value('id');
        if ($kas === null) {
            return;
        }

        $transaksiKas = DB::table('transaksi')->where(function (Builder $query) use ($kas) {
            $query->where('akun_tujuan_id', $kas)->orWhere('akun_asal_id', $kas);
        });

        $periodeId = DB::table('periode_kas')->insertGetId([
            'nama' => 'Periode 1',
            'tanggal_mulai' => (clone $transaksiKas)->min('tanggal') ?? now()->toDateString(),
        ]);

        (clone $transaksiKas)->update([
            'periode_kas_id' => $periodeId,
            'updated_at' => DB::raw('updated_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaksi', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() === 'sqlite') {
                $table->dropColumn('periode_kas_id');
            } else {
                $table->dropConstrainedForeignId('periode_kas_id');
            }
        });

        DB::table('periode_kas')->delete();
    }
};
