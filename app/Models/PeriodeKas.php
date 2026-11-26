<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PeriodeKas extends Model
{
    protected $table = 'periode_kas';

    protected $fillable = [
        'nama',
        'tanggal_mulai',
        'tanggal_tutup',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_mulai' => 'date',
            'tanggal_tutup' => 'datetime',
        ];
    }

    /** @return HasMany<Transaksi, $this> */
    public function transaksi(): HasMany
    {
        return $this->hasMany(Transaksi::class);
    }

    public function sudahDitutup(): bool
    {
        return $this->tanggal_tutup !== null;
    }
}
