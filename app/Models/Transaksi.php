<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaksi extends Model
{
    protected $table = 'transaksi';

    protected $fillable = [
        'tanggal',
        'jenis',
        'akun_asal_id',
        'akun_tujuan_id',
        'periode_kas_id',
        'nominal',
        'keterangan',
    ];

    public function akunAsal(): BelongsTo
    {
        return $this->belongsTo(AkunDana::class, 'akun_asal_id');
    }

    public function akunTujuan(): BelongsTo
    {
        return $this->belongsTo(AkunDana::class, 'akun_tujuan_id');
    }

    public function periodeKas(): BelongsTo
    {
        return $this->belongsTo(PeriodeKas::class, 'periode_kas_id');
    }
}
