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

    /** @return BelongsTo<AkunDana, $this> */
    public function akunAsal(): BelongsTo
    {
        return $this->belongsTo(AkunDana::class, 'akun_asal_id');
    }

    /** @return BelongsTo<AkunDana, $this> */
    public function akunTujuan(): BelongsTo
    {
        return $this->belongsTo(AkunDana::class, 'akun_tujuan_id');
    }

    /** @return BelongsTo<PeriodeKas, $this> */
    public function periodeKas(): BelongsTo
    {
        return $this->belongsTo(PeriodeKas::class, 'periode_kas_id');
    }
}
