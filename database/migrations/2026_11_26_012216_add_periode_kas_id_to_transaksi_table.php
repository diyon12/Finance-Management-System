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
        Schema::table('transaksi', function (Blueprint $table) {
            $table->foreignId('periode_kas_id')->nullable()->after('akun_tujuan_id')->constrained('periode_kas')->restrictOnDelete();
        });

        $kas = DB::table('akun_dana')->where('nama', 'Kas')->value('id');
        if($kas === null){
            return;
        }

        $transaksiKas = DB::table('transaksi')->where(function ($query) use ($kas) {
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
            $table->dropConstrainedForeignId('periode_kas_id');
        });

        DB::table('periode_kas')->delete();
    }
};
