<?php

use App\Models\Transaksi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component
{
    // Akun yang dirangkum, sama dengan tab di halaman Catatan keuangan.
    private const AKUN = ['Kas', 'Operasional'];

    private const JUMLAH_BULAN = 6;

    // Samakan dengan BATAS_PENGINGAT_KAS di komponen Catatan keuangan.
    private const BATAS_PENGINGAT_KAS = 5000;

    #[Computed]
    public function akun(): Collection
    {
        return DB::table('saldo_akun')
            ->whereIn('nama', self::AKUN)
            ->get()
            ->sortBy(fn ($akun) => array_search($akun->nama, self::AKUN, true))
            ->values();
    }

    #[Computed]
    public function totalSaldo(): string
    {
        // Dijumlahkan di MySQL agar tetap angka desimal utuh, tidak lewat float.
        return (string) DB::table('saldo_akun')
            ->whereIn('nama', self::AKUN)
            ->selectRaw('COALESCE(SUM(saldo_rupiah), 0) AS total')
            ->value('total');
    }

    /**
     * Masuk dan keluar per bulan untuk semua akun di atas, dari bulan terlama.
     * Transfer antar akun tersebut tidak dihitung karena uangnya hanya berpindah tempat.
     *
     * @return array<string, array{masuk: string, keluar: string}> berkunci 'Y-m'
     */
    #[Computed]
    public function arusKas(): array
    {
        $mulai = today()->startOfMonth()->subMonths(self::JUMLAH_BULAN - 1);

        $hasil = [];
        for ($i = 0; $i < self::JUMLAH_BULAN; $i++) {
            $hasil[$mulai->copy()->addMonths($i)->format('Y-m')] = ['masuk' => '0', 'keluar' => '0'];
        }

        $ids = $this->akun->pluck('akun_id')->all();
        if ($ids === []) {
            return $hasil;
        }

        $tanda = implode(',', array_fill(0, count($ids), '?'));

        $baris = Transaksi::query()
            ->toBase()
            ->where('tanggal', '>=', $mulai->toDateString())
            ->where('tanggal', '<', today()->startOfMonth()->addMonth()->toDateString())
            ->selectRaw("DATE_FORMAT(tanggal, '%Y-%m') AS bulan")
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN akun_tujuan_id IN ($tanda)
                    AND (akun_asal_id IS NULL OR akun_asal_id NOT IN ($tanda)) THEN nominal ELSE 0 END), 0) AS masuk",
                [...$ids, ...$ids]
            )
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN akun_asal_id IN ($tanda)
                    AND (akun_tujuan_id IS NULL OR akun_tujuan_id NOT IN ($tanda)) THEN nominal ELSE 0 END), 0) AS keluar",
                [...$ids, ...$ids]
            )
            ->groupBy('bulan')
            ->get();

        foreach ($baris as $b) {
            if (isset($hasil[$b->bulan])) {
                $hasil[$b->bulan] = ['masuk' => (string) $b->masuk, 'keluar' => (string) $b->keluar];
            }
        }

        return $hasil;
    }

    /**
     * Jumlah masuk dan keluar bulan ini per akun_id.
     * Transfer ikut dihitung, sama seperti kolom Kredit/Debit di riwayat tiap akun.
     *
     * @return array{masuk: Collection, keluar: Collection}
     */
    #[Computed]
    public function bulanIniPerAkun(): array
    {
        $ids = $this->akun->pluck('akun_id')->all();

        $bulanIni = fn () => Transaksi::query()
            ->toBase()
            ->where('tanggal', '>=', today()->startOfMonth()->toDateString())
            ->where('tanggal', '<', today()->startOfMonth()->addMonth()->toDateString());

        return [
            'masuk' => $bulanIni()
                ->whereIn('akun_tujuan_id', $ids)
                ->groupBy('akun_tujuan_id')
                ->selectRaw('akun_tujuan_id AS akun_id, SUM(nominal) AS jumlah')
                ->pluck('jumlah', 'akun_id'),
            'keluar' => $bulanIni()
                ->whereIn('akun_asal_id', $ids)
                ->groupBy('akun_asal_id')
                ->selectRaw('akun_asal_id AS akun_id, SUM(nominal) AS jumlah')
                ->pluck('jumlah', 'akun_id'),
        ];
    }

    // Periode Kas yang sedang berjalan beserta saldonya (dari view saldo_kas_periode).
    #[Computed]
    public function periodeKas(): ?object
    {
        return DB::table('saldo_kas_periode')
            ->whereNull('tanggal_tutup')
            ->orderByDesc('tanggal_mulai')
            ->orderByDesc('periode_id')
            ->first();
    }

    #[Computed]
    public function perluPeriodeBaru(): bool
    {
        return $this->periodeKas !== null && (float) $this->periodeKas->saldo_akhir <= self::BATAS_PENGINGAT_KAS;
    }
};
?>

<section aria-labelledby="judul-dashboard">
    @php
        // Hasil SUM bisa melampaui presisi float. Format langsung dari angka desimal MySQL.
        $rupiah = static function ($nilai): string {
            $angka = (string) $nilai;
            $minus = str_starts_with($angka, '-');

            return ($minus ? '−' : '').'Rp'.preg_replace('/\B(?=(\d{3})+$)/', '.', ltrim($angka, '-'));
        };

        // Label sumbu grafik, misalnya "7,5 jt".
        $singkat = static function (float $n): string {
            foreach ([[1e12, 'T'], [1e9, 'M'], [1e6, 'jt'], [1e3, 'rb']] as [$batas, $satuan]) {
                if (abs($n) >= $batas) {
                    return rtrim(rtrim(number_format($n / $batas, 1, ',', '.'), '0'), ',').' '.$satuan;
                }
            }

            return number_format($n, 0, ',', '.');
        };

        // Sesuaikan dengan alamat halaman Catatan keuangan.
        $urlKeuangan = url('/keuangan');
        $tautan = static fn (string $akun, ?string $aksi = null): string =>
            $urlKeuangan.'?'.http_build_query(array_filter(['tab' => strtolower($akun), 'aksi' => $aksi]));

        $arus = $this->arusKas;
        $bulanIni = $arus[array_key_last($arus)];

        // Batas atas sumbu dibulatkan supaya tiap garis bantu bernilai rapi (1, 2, 2,5 atau 5 × 10ⁿ).
        $maks = max(array_map(fn ($b) => max((float) $b['masuk'], (float) $b['keluar']), $arus));
        $langkah = 250000;
        if ($maks > 0) {
            $kasar = $maks / 4;
            $pangkat = 10 ** floor(log10($kasar));
            $langkah = collect([1, 2, 2.5, 5, 10])->map(fn ($k) => $k * $pangkat)->first(fn ($v) => $v >= $kasar);
        }
        $atas = $langkah * 4;

        $grafik = [];
        foreach ($arus as $kunci => $b) {
            $bulan = \Illuminate\Support\Carbon::createFromFormat('!Y-m', $kunci)->locale('id');
            $grafik[] = [
                'pendek' => $bulan->isoFormat('MMM'),
                'panjang' => $bulan->isoFormat('MMMM YYYY'),
                'masuk' => $rupiah($b['masuk']),
                'keluar' => $rupiah($b['keluar']),
                'tinggiMasuk' => round((float) $b['masuk'] / $atas * 100, 2),
                'tinggiKeluar' => round((float) $b['keluar'] / $atas * 100, 2),
            ];
        }
        $terakhir = count($grafik) - 1;

        $total = (float) $this->totalSaldo;
        $perAkun = $this->bulanIniPerAkun;
        $periodeKas = $this->periodeKas;
    @endphp

    <header class="kepala">
        <div>
            <h1 id="judul-dashboard">Dashboard</h1>
            <p class="redup">{{ today()->locale('id')->isoFormat('MMMM YYYY') }}</p>
        </div>
    </header>

    @if ($this->perluPeriodeBaru)
        <div class="pengingat" role="status">
            <p>
                <strong>Saldo Kas tinggal {{ $rupiah($periodeKas->saldo_akhir) }} di {{ $periodeKas->nama }}.</strong>
                Kalau uangnya sudah dianggap habis, mulai periode baru supaya sisanya dibawa ke periode berikutnya.
            </p>
            <a href="{{ $tautan('Kas', 'periode-baru') }}" wire:navigate>Mulai periode baru</a>
        </div>
    @endif

    <div class="ringkasan">
        <div class="kartu total">
            <span class="label">Total saldo</span>
            <span class="angka-besar">{{ $rupiah($this->totalSaldo) }}</span>
        </div>
        <div class="kartu">
            <span class="label">
                <svg class="ikon teks-masuk" viewBox="0 0 24 24" aria-hidden="true"><path d="M17 7 7 17M7 9v8h8"/></svg>
                Masuk bulan ini
            </span>
            <span class="angka">{{ $rupiah($bulanIni['masuk']) }}</span>
        </div>
        <div class="kartu">
            <span class="label">
                <svg class="ikon teks-keluar" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg>
                Keluar bulan ini
            </span>
            <span class="angka">{{ $rupiah($bulanIni['keluar']) }}</span>
        </div>
    </div>

    <div class="bawah">
        <section class="kartu arus" aria-labelledby="judul-arus"
                 x-data="{ aktif: {{ $terakhir }}, data: @js(array_map(fn ($g) => [$g['panjang'], $g['masuk'], $g['keluar']], $grafik)) }">
            <div class="kepala-kartu">
                <h2 id="judul-arus">Arus kas</h2>
                <span class="redup">{{ count($grafik) }} bulan terakhir · semua akun</span>
            </div>

            <p class="baca" aria-hidden="true">
                <strong x-text="data[aktif][0]">{{ $grafik[$terakhir]['panjang'] }}</strong>
                <span><i class="tanda tanda-masuk"></i>Masuk <strong x-text="data[aktif][1]">{{ $grafik[$terakhir]['masuk'] }}</strong></span>
                <span><i class="tanda tanda-keluar"></i>Keluar <strong x-text="data[aktif][2]">{{ $grafik[$terakhir]['keluar'] }}</strong></span>
            </p>

            <div class="grafik" aria-hidden="true">
                <div class="sumbu">
                    @foreach ([1, .75, .5, .25, 0] as $bagian)
                        <span>{{ $singkat($atas * $bagian) }}</span>
                    @endforeach
                </div>
                <div class="plot" x-on:mouseleave="aktif = {{ $terakhir }}">
                    <div class="garis-bantu"><span></span><span></span><span></span><span></span><span></span></div>
                    <div class="batang-batang">
                        @foreach ($grafik as $i => $g)
                            <div @class(['kelompok', 'aktif' => $loop->last])
                                 x-on:mouseenter="aktif = {{ $i }}" x-bind:class="{ aktif: aktif === {{ $i }} }">
                                <span class="batang batang-masuk" style="height: {{ $g['tinggiMasuk'] }}%"></span>
                                <span class="batang batang-keluar" style="height: {{ $g['tinggiKeluar'] }}%"></span>
                            </div>
                        @endforeach
                    </div>
                    @if ($maks <= 0)
                        <p class="kosong">Belum ada transaksi dalam {{ count($grafik) }} bulan terakhir.</p>
                    @endif
                </div>
                <div class="label-bulan">
                    @foreach ($grafik as $i => $g)
                        <span @class(['aktif' => $loop->last]) x-bind:class="{ aktif: aktif === {{ $i }} }">{{ $g['pendek'] }}</span>
                    @endforeach
                </div>
            </div>

            <table class="sr-only">
                <caption>Arus kas {{ count($grafik) }} bulan terakhir</caption>
                <thead>
                    <tr><th scope="col">Bulan</th><th scope="col">Masuk</th><th scope="col">Keluar</th></tr>
                </thead>
                <tbody>
                    @foreach ($grafik as $g)
                        <tr><th scope="row">{{ $g['panjang'] }}</th><td>{{ $g['masuk'] }}</td><td>{{ $g['keluar'] }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </section>

        <section class="kartu daftar-akun" aria-labelledby="judul-akun">
            <h2 id="judul-akun">Saldo per akun</h2>
            @forelse ($this->akun as $a)
                @php
                    $saldo = (float) $a->saldo_rupiah;
                    $persen = $total > 0 && $saldo > 0 ? min(100, $saldo / $total * 100) : 0;
                    // Kas dihitung per periode; akun lain per bulan.
                    $perPeriode = $a->nama === 'Kas' && $periodeKas !== null;
                @endphp
                <a class="akun" href="{{ $tautan($a->nama) }}" wire:navigate>
                    <span class="akun-kepala">
                        <span class="nama">
                            {{ $a->nama }}
                            @if ($perPeriode)
                                <span class="label-periode">{{ $periodeKas->nama }}</span>
                            @endif
                        </span>
                        @if ($total > 0)
                            <span class="redup">{{ round($persen) }}%</span>
                        @endif
                    </span>
                    <span class="akun-saldo">{{ $rupiah($a->saldo_rupiah) }}</span>
                    <span class="trek" aria-hidden="true"><span style="width: {{ round($persen, 1) }}%"></span></span>
                    @if ($perPeriode)
                        <span class="akun-bulan redup">
                            Periode ini masuk <span class="teks-masuk">{{ $rupiah($periodeKas->masuk) }}</span>
                            · keluar <span class="teks-keluar">{{ $rupiah($periodeKas->keluar) }}</span>
                        </span>
                        <span class="akun-bulan redup">
                            Saldo awal {{ $rupiah($periodeKas->saldo_awal) }}
                            · sejak {{ \Illuminate\Support\Carbon::parse($periodeKas->tanggal_mulai)->locale('id')->isoFormat('D MMM YYYY') }}
                        </span>
                    @else
                        <span class="akun-bulan redup">
                            Bulan ini masuk <span class="teks-masuk">{{ $rupiah($perAkun['masuk']->get($a->akun_id, 0)) }}</span>
                            · keluar <span class="teks-keluar">{{ $rupiah($perAkun['keluar']->get($a->akun_id, 0)) }}</span>
                        </span>
                    @endif
                </a>
                @unless ($loop->last)
                    <hr>
                @endunless
            @empty
                <p class="redup">Belum ada akun Kas atau Operasional.</p>
            @endforelse
        </section>
    </div>
</section>

<style>
    & {
        --teal: #0f766e;
        --teal-gelap: #115e59;
        --teks: #10201e;
        --redup: #5f6e6b;
        --garis: #e3e8e6;
        --garis-tipis: #eef2f1;
        --garis-kuat: #d5dedb;
        --arsir: #f1f6f5;
        --masuk: #0f766e;
        --keluar: #b3471b;
        --batang-masuk: #0d9488;
        --batang-keluar: #e0763a;
        color-scheme: light;
        display: flex; flex-direction: column; gap: 1.5rem;
        width: 100%; min-width: 0; color: var(--teks);
        font-family: var(--font-sans, system-ui), sans-serif; line-height: 1.5;
    }

    /* Dasar */
    .ikon { width: 1rem; height: 1rem; flex-shrink: 0; fill: none; stroke: currentColor;
        stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .redup { color: var(--redup); }
    .teks-masuk { color: var(--masuk); font-weight: 600; }
    .teks-keluar { color: var(--keluar); font-weight: 600; }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    [x-cloak] { display: none !important; }
    :is(a, button):focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }

    /* Kepala */
    .kepala { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1rem; }
    h1 { margin: 0; font-size: clamp(1.6rem, 3vw, 1.75rem); line-height: 1.2; font-weight: 700; letter-spacing: -.02em; }
    .kepala p { margin: .25rem 0 0; font-size: .9rem; }
    .aksi { display: flex; flex-wrap: wrap; gap: .625rem; }
    .menu { position: relative; }
    .tombol { display: inline-flex; align-items: center; gap: .5rem; min-height: 2.75rem; padding: 0 1.1rem;
        border: 1px solid var(--garis-kuat); border-radius: .625rem; background: #fff; color: var(--teks);
        font: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; }
    .tombol:hover { background: var(--arsir); }
    .tombol.utama { border-color: var(--teal); background: var(--teal); color: #fff; font-weight: 700; }
    .tombol.utama:hover { background: var(--teal-gelap); }
    .menu-isi { position: absolute; right: 0; top: calc(100% + .375rem); z-index: 20; display: grid; min-width: 12rem;
        padding: .375rem; border: 1px solid var(--garis); border-radius: .75rem; background: #fff;
        box-shadow: 0 12px 32px rgb(16 32 30 / 12%); }
    .menu-isi p { margin: 0; padding: .35rem .65rem .25rem; font-size: .75rem; font-weight: 600; color: var(--redup); }
    .menu-isi a { display: flex; align-items: center; min-height: 2.5rem; padding: 0 .65rem; border-radius: .5rem;
        color: var(--teks); font-size: .9rem; font-weight: 600; text-decoration: none; }
    .menu-isi a:hover { background: var(--arsir); }

    /* Kartu */
    .kartu { min-width: 0; padding: 1.5rem; border: 1px solid var(--garis); border-radius: 1rem; background: #fff;
        box-shadow: 0 1px 2px rgb(16 32 30 / 4%); }
    .ringkasan { display: flex; flex-wrap: wrap; gap: 1rem; }
    .ringkasan .kartu { flex: 1 1 12.5rem; display: grid; gap: .35rem; align-content: start; }
    .ringkasan .total { flex: 2 1 20rem; }
    .label { display: flex; align-items: center; gap: .4rem; font-size: .8rem; font-weight: 600; color: var(--redup); }
    .angka { font-size: 1.5rem; font-weight: 700; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .angka-besar { font-size: clamp(2rem, 4vw, 2.5rem); line-height: 1.1; font-weight: 800; letter-spacing: -.02em;
        font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .bawah { display: flex; flex-wrap: wrap; gap: 1rem; align-items: stretch; }
    h2 { margin: 0; font-size: .95rem; font-weight: 700; }

    /* Grafik arus kas */
    .arus { flex: 2 1 30rem; display: grid; gap: 1rem; align-content: start; }
    .kepala-kartu { display: flex; flex-wrap: wrap; align-items: baseline; justify-content: space-between; gap: .5rem; }
    .kepala-kartu span { font-size: .8rem; }
    .baca { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem 1.1rem; margin: 0; font-size: .8rem;
        color: #4b5b58; font-variant-numeric: tabular-nums; }
    .baca strong { color: var(--teks); }
    .tanda { display: inline-block; width: .625rem; height: .625rem; margin-right: .35rem; border-radius: 2px; vertical-align: -.05em; }
    .tanda-masuk, .batang-masuk { background: var(--batang-masuk); }
    .tanda-keluar, .batang-keluar { background: var(--batang-keluar); }
    .grafik { display: grid; grid-template-columns: 2.5rem minmax(0, 1fr); column-gap: .625rem; row-gap: .5rem; }
    .sumbu { height: calc(12.5rem + 14px); margin: -7px 0; display: flex; flex-direction: column; justify-content: space-between;
        text-align: right; font-size: .7rem; line-height: 14px; color: var(--redup); }
    .plot { position: relative; height: 12.5rem; }
    .garis-bantu { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: space-between; }
    .garis-bantu span { border-top: 1px solid var(--garis-tipis); }
    .garis-bantu span:last-child { border-color: #c9d3d0; }
    .batang-batang { position: absolute; inset: 0; display: flex; }
    .kelompok { flex: 1; display: flex; align-items: flex-end; justify-content: center; gap: 2px; border-radius: .5rem .5rem 0 0; }
    .kelompok.aktif { background: var(--arsir); }
    .batang { width: min(1.125rem, 30%); border-radius: 4px 4px 0 0; }
    .label-bulan { grid-column: 2; display: flex; }
    .label-bulan span { flex: 1; text-align: center; font-size: .75rem; color: var(--redup); }
    .label-bulan span.aktif { color: var(--teks); font-weight: 700; }
    .kosong { position: absolute; inset: 0; display: grid; place-items: center; margin: 0; font-size: .85rem; color: var(--redup); }

    /* Saldo per akun */
    .daftar-akun { flex: 1 1 17.5rem; display: grid; gap: 1.25rem; align-content: start; }
    .akun { display: grid; gap: .5rem; margin: -.75rem; padding: .75rem; border-radius: .75rem; color: inherit; text-decoration: none; }
    .akun:hover { background: var(--arsir); }
    .akun-kepala { display: flex; justify-content: space-between; gap: .5rem; font-size: .9rem; }
    .akun-kepala .nama { font-weight: 600; }
    .akun-saldo { font-size: 1.375rem; font-weight: 700; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .trek { height: .5rem; overflow: hidden; border-radius: 4px; background: var(--garis-tipis); }
    .trek span { display: block; height: 100%; border-radius: 4px; background: var(--batang-masuk); }
    .akun-bulan { font-size: .75rem; font-variant-numeric: tabular-nums; }
    .daftar-akun hr { width: 100%; margin: 0; border: 0; border-top: 1px solid var(--garis-tipis); }
    .label-periode { display: inline-flex; align-items: center; margin-left: .4rem; padding: .1rem .5rem; border-radius: 999px;
        background: #e6f4f1; color: var(--teal); font-size: .7rem; font-weight: 600; vertical-align: .1em; }

    /* Pengingat periode Kas */
    .pengingat { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;
        padding: .9rem 1.1rem; border-radius: .875rem; background: #fef3e2; color: #7a4307; }
    .pengingat p { margin: 0; font-size: .9rem; }
    .pengingat a { display: inline-flex; align-items: center; min-height: 2.5rem; padding: 0 1rem; border-radius: .625rem;
        background: #7a4307; color: #fff; font-size: .875rem; font-weight: 700; text-decoration: none; }

    @media (max-width: 40rem) {
        .kartu { padding: 1.25rem; }
        .aksi, .menu, .tombol { flex: 1 1 auto; }
        .tombol { justify-content: center; width: 100%; }
    }
</style>