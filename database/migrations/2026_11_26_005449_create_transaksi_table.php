<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('transaksi', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal')->index();
            $table->enum('jenis', ['pemasukan', 'pengeluaran']);
            $table->foreignId('akun_asal_id')->nullable()->constrained('akun_dana')->restrictOnDelete();
            $table->foreignId('akun_tujuan_id')->nullable()->constrained('akun_dana')->restrictOnDelete();
            $table->bigInteger('nominal');
            $table->string('keterangan')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaksi');
    }
};
