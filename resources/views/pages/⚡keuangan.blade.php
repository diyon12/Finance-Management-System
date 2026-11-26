<?php

use App\Models\PeriodeKas;
use App\Models\Transaksi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

new class extends Component
{
    use WithPagination;

    // Excel dengan pengaturan wilayah Indonesia memakai titik koma sebagai pemisah kolom.
    private const PEMISAH_CSV = ';';

    // Pengingat "mulai periode baru" muncul saat saldo Kas periode berjalan sama atau di bawah angka ini.
    private const BATAS_PENGINGAT_KAS = 5000;

    #[Locked]
    public string $tab = 'Kas';

    // Periode Kas yang sedang dilihat. Hanya diganti lewat pilihPeriode().
    #[Locked]
    public ?int $periodeId = null;

    #[Locked]
    public bool $formPeriodeOpen = false;

    #[Locked]
    public ?int $editId = null;

    #[Locked]
    public string $jenis = 'pemasukan';

    #[Locked]
    public bool $formOpen = false;

    public string $tanggal = '';
    public string $nominal = '';
    public ?string $keterangan = null;

    public string $namaPeriode = '';
    public string $mulaiPeriode = '';

    // Filter riwayat. Nilai di luar pilihan diperlakukan sebagai "semua".
    public string $cari = '';
    public string $arus = 'semua';     // semua | kredit | debit
    public string $rentang = 'semua';  // semua | bulan | 30hari | tahun

    public function mount(): void
    {
        $this->tab = request()->query('tab') === 'operasional' ? 'Operasional' : 'Kas';
        $this->tanggal = today()->toDateString();
        $this->periodeId = $this->periodeBerjalan?->id;

        // Dari tombol Tambah/Kurangi saldo di dashboard: ?aksi=tambah atau ?aksi=kurangi.
        match (request()->query('aksi')) {
            'tambah' => $this->tambah(),
            'kurangi' => $this->tambah('pengeluaran'),
            default => null,
        };
    }

    public function updated(string $properti): void
    {
        if (in_array($properti, ['cari', 'arus', 'rentang'], true)) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function akun(): object
    {
        return DB::table('saldo_akun')->where('nama', $this->tab)->firstOrFail();
    }

    private function diKas(): bool
    {
        return $this->tab === 'Kas';
    }

    #[Computed]
    public function periodeBerjalan(): ?PeriodeKas
    {
        return PeriodeKas::query()->whereNull('tanggal_tutup')->latest('tanggal_mulai')->latest('id')->first();
    }

    // Semua periode Kas beserta saldonya (dari view saldo_kas_periode), terbaru di depan.
    #[Computed]
    public function daftarPeriode()
    {
        return DB::table('saldo_kas_periode')->orderByDesc('tanggal_mulai')->orderByDesc('periode_id')->get();
    }

    // Periode yang sedang dilihat di tab Kas. Null di tab lain.
    #[Computed]
    public function periode(): ?object
    {
        if (! $this->diKas()) {
            return null;
        }

        return $this->daftarPeriode->firstWhere('periode_id', $this->periodeId) ?? $this->daftarPeriode->first();
    }

    #[Computed]
    public function periodeTertutup(): array
    {
        return PeriodeKas::query()->whereNotNull('tanggal_tutup')->pluck('id')->all();
    }

    #[Computed]
    public function perluPeriodeBaru(): bool
    {
        $p = $this->periode;

        return $p !== null && $p->tanggal_tutup === null && (float) $p->saldo_akhir <= self::BATAS_PENGINGAT_KAS;
    }

    private function transaksiAkun(): Builder
    {
        $id = $this->akun->akun_id;

        return Transaksi::query()->where(function (Builder $query) use ($id) {
            $query->where('akun_tujuan_id', $id)->orWhere('akun_asal_id', $id);
        });
    }

    private function riwayatTersaring(): Builder
    {
        $id = $this->akun->akun_id;

        // MySQL menghitung saldo dari transaksi terlama di subkueri atas SEMUA transaksi akun.
        // Filter dan pembagian halaman baru diterapkan di luar, jadi sisa saldo tiap baris tidak berubah.
        if ($this->diKas()) {
            // Kas: hanya transaksi di periode yang dipilih, dan sisa saldo dimulai dari saldo awal periode itu.
            $semua = $this->transaksiAkun()
                ->where('periode_kas_id', $this->periode?->periode_id)
                ->select('*')
                ->selectRaw(
                    'CAST(? AS DECIMAL(42,0)) + SUM(CASE WHEN akun_tujuan_id = ? THEN nominal ELSE -nominal END)
                     OVER (ORDER BY tanggal, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS sisa_saldo',
                    [$this->periode?->saldo_awal ?? 0, $id]
                );
        } else {
            $semua = $this->transaksiAkun()
                ->select('*')
                ->selectRaw(
                    'SUM(CASE WHEN akun_tujuan_id = ? THEN nominal ELSE -nominal END)
                     OVER (ORDER BY tanggal, id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS sisa_saldo',
                    [$id]
                );
        }

        $query = Transaksi::query()->fromSub($semua, (new Transaksi)->getTable());

        $cari = mb_substr(trim($this->cari), 0, 100);
        if ($cari !== '') {
            $query->where('keterangan', 'like', '%'.addcslashes($cari, '\\%_').'%');
        }

        if ($this->arus === 'kredit') {
            $query->where('akun_tujuan_id', $id);
        } elseif ($this->arus === 'debit') {
            $query->where('akun_asal_id', $id);
        }

        [$dari, $sebelum] = match ($this->rentang) {
            'bulan' => [today()->startOfMonth(), today()->startOfMonth()->addMonth()],
            '30hari' => [today()->subDays(29), today()->addDay()],
            'tahun' => [today()->startOfYear(), today()->startOfYear()->addYear()],
            default => [null, null],
        };

        if ($dari !== null) {
            $query->where('tanggal', '>=', $dari->toDateString())
                ->where('tanggal', '<', $sebelum->toDateString());
        }

        return $query;
    }

    #[Computed]
    public function riwayat()
    {
        $query = $this->riwayatTersaring();

        // Kas dibaca seperti buku kas: dari saldo awal ke transaksi terbaru. Operasional: terbaru di atas.
        if ($this->diKas()) {
            $query->orderBy('tanggal')->orderBy('id');
        } else {
            $query->orderByDesc('tanggal')->orderByDesc('id');
        }

        return $query->paginate(10);
    }

    #[Computed]
    public function rekap(): object
    {
        $id = $this->akun->akun_id;

        return $this->riwayatTersaring()
            ->toBase()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN akun_tujuan_id = ? THEN nominal ELSE 0 END), 0) AS kredit,
                 COALESCE(SUM(CASE WHEN akun_tujuan_id = ? THEN 0 ELSE nominal END), 0) AS debit',
                [$id, $id]
            )
            ->first();
    }

    #[Computed]
    public function terakhir(): ?string
    {
        return $this->transaksiAkun()->max('tanggal');
    }

    public function resetFilter(): void
    {
        $this->reset('cari', 'arus', 'rentang');
        $this->resetPage();
    }

    public function ekspor(): StreamedResponse
    {
        $id = $this->akun->akun_id;
        $baris = $this->riwayatTersaring()->orderBy('tanggal')->orderBy('id');
        $saldoAwal = $this->diKas() ? ($this->periode?->saldo_awal ?? 0) : null;
        $namaPeriode = $this->diKas() && $this->periode ? '-'.Str::slug($this->periode->nama) : '';
        $namaFile = 'riwayat-'.strtolower($this->tab).$namaPeriode.'-'.today()->toDateString().'.csv';

        return response()->streamDownload(function () use ($baris, $id, $saldoAwal) {
            $keluaran = fopen('php://output', 'w');
            fwrite($keluaran, "\xEF\xBB\xBF"); // BOM agar Excel membaca UTF-8.
            fputcsv($keluaran, ['Tanggal', 'Keterangan', 'Kredit', 'Debit', 'Sisa saldo'], self::PEMISAH_CSV);

            if ($saldoAwal !== null) {
                fputcsv($keluaran, ['', 'Saldo awal', '', '', $saldoAwal], self::PEMISAH_CSV);
            }

            foreach ($baris->cursor() as $t) {
                $masuk = (string) $t->akun_tujuan_id === (string) $id;
                fputcsv($keluaran, [
                    $t->getRawOriginal('tanggal'),
                    self::selAman($t->keterangan),
                    $masuk ? $t->nominal : '',
                    $masuk ? '' : $t->nominal,
                    $t->sisa_saldo,
                ], self::PEMISAH_CSV);
            }

            fclose($keluaran);
        }, $namaFile, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // Cegah keterangan seperti "=..." dibaca sebagai rumus saat dibuka di Excel.
    private static function selAman(?string $teks): string
    {
        $teks ??= '';

        return preg_match('/^[=+\-@\t\r]/', $teks) ? "'".$teks : $teks;
    }

    public function tambah(string $jenis = 'pemasukan'): void
    {
        $boleh = in_array($jenis, ['pemasukan', 'pengeluaran'], true);
        abort_unless($boleh, 422, 'Jenis transaksi tidak tersedia untuk akun ini.');

        if ($this->diKas()) {
            abort_if($this->periodeBerjalan === null, 422, 'Belum ada periode Kas yang berjalan.');

            // Transaksi Kas baru selalu masuk ke periode yang sedang berjalan, jadi tampilkan periode itu.
            if ($this->periodeId !== $this->periodeBerjalan->id) {
                $this->periodeId = $this->periodeBerjalan->id;
                unset($this->periode, $this->riwayat, $this->rekap);
                $this->resetPage();
            }
        }

        $this->baru();
        $this->jenis = $jenis;
        $this->formOpen = true;
    }

    // Ganti Tambah/Kurangi di dalam form tanpa menghapus isian. Hanya untuk transaksi baru.
    public function pilihJenis(string $jenis): void
    {
        $boleh = in_array($jenis, ['pemasukan', 'pengeluaran'], true);
        abort_unless($boleh, 422, 'Jenis transaksi tidak tersedia untuk akun ini.');
        abort_unless($this->formOpen && $this->editId === null, 403);

        $this->jenis = $jenis;
    }

    public function pilihPeriode(int $id): void
    {
        abort_unless($this->diKas() && PeriodeKas::whereKey($id)->exists(), 404);

        $this->periodeId = $id;
        $this->baru();
        $this->resetPage();
        unset($this->periode, $this->riwayat, $this->rekap);
    }

    // Transaksi di periode Kas yang sudah ditutup tidak boleh diubah atau dihapus,
    // termasuk transfer Kas yang terlihat dari tab Operasional.
    private function pastikanBisaDiubah(Transaksi $t): void
    {
        $terkunci = $t->periode_kas_id !== null
            && PeriodeKas::whereKey($t->periode_kas_id)->whereNotNull('tanggal_tutup')->exists();

        abort_if($terkunci, 403, 'Periode Kas transaksi ini sudah ditutup.');
    }

    public function ubah(int $id): void
    {
        $t = $this->transaksiAkun()->findOrFail($id);
        $this->pastikanBisaDiubah($t);
        $this->editId = $t->id;
        $this->jenis = $t->jenis;
        $this->tanggal = $t->getRawOriginal('tanggal');
        $this->nominal = (string) $t->nominal;
        $this->keterangan = $t->keterangan;
        $this->resetValidation();
        $this->formOpen = true;
    }

    public function simpan(): void
    {
        if (! $this->formOpen) {
            return;
        }

        // Terima penulisan ribuan ala Indonesia, misalnya 6.000.000.
        $nominal = trim($this->nominal);
        if (preg_match('/^\d{1,3}(\.\d{3})+$/', $nominal)) {
            $nominal = str_replace('.', '', $nominal);
        }
        $this->nominal = $nominal;

        $transaksi = $this->editId === null ? null : $this->transaksiAkun()->findOrFail($this->editId);
        if ($transaksi !== null) {
            $this->pastikanBisaDiubah($transaksi);
        }

        // Periode Kas yang dipakai: milik transaksi yang diubah, atau periode berjalan untuk transaksi Kas baru.
        $periodeKas = $transaksi !== null ? $transaksi->periodeKas : ($this->diKas() ? $this->periodeBerjalan : null);
        abort_if($transaksi === null && $this->diKas() && $periodeKas === null, 422, 'Belum ada periode Kas yang berjalan.');

        $aturanTanggal = ['required', 'date_format:Y-m-d'];
        if ($periodeKas !== null) {
            $aturanTanggal[] = 'after_or_equal:'.$periodeKas->tanggal_mulai->toDateString();
        }

        $data = $this->validate([
            'tanggal' => $aturanTanggal,
            'nominal' => 'required|integer|min:1',
            'keterangan' => 'nullable|string|max:255',
        ], [
            'tanggal.required' => 'Isi tanggal transaksi.',
            'tanggal.date_format' => 'Tanggal transaksi tidak valid.',
            'tanggal.after_or_equal' => 'Tanggal tidak boleh sebelum '.($periodeKas?->nama ?? 'periode').' dimulai.',
            'nominal.required' => 'Isi nominal transaksi.',
            'nominal.integer' => 'Gunakan rupiah bulat.',
            'nominal.min' => 'Nominal minimal Rp1.',
            'keterangan.max' => 'Keterangan maksimal 255 karakter.',
        ]);

        if ($transaksi === null) {
            $id = $this->akun->akun_id;
            $masuk = $this->jenis === 'pemasukan';
            Transaksi::create([
                ...$data,
                'jenis' => $this->jenis,
                'akun_asal_id' => $masuk ? null : $id,
                'akun_tujuan_id' => $masuk ? $id : null,
                'periode_kas_id' => $periodeKas?->id,
            ]);
        } else {
            // Kedua akun pada transfer lama tetap terhubung ketika nominal atau tanggal dikoreksi.
            $transaksi->update($data);
        }

        unset($this->akun, $this->riwayat, $this->rekap, $this->terakhir, $this->daftarPeriode, $this->periode, $this->perluPeriodeBaru);
        $this->baru();
        $this->resetPage();
        session()->flash('pesan', 'Transaksi tersimpan.');
    }

    public function hapus(int $id): void
    {
        $t = $this->transaksiAkun()->findOrFail($id);
        $this->pastikanBisaDiubah($t);
        $t->delete();
        unset($this->akun, $this->riwayat, $this->rekap, $this->terakhir, $this->daftarPeriode, $this->periode, $this->perluPeriodeBaru);
        $this->baru();
        $this->resetPage();
        session()->flash('pesan', 'Transaksi dihapus.');
    }

    public function baru(): void
    {
        $this->reset('formOpen', 'editId', 'jenis', 'nominal', 'keterangan');
        $this->tanggal = today()->toDateString();
        $this->resetValidation();
    }

    public function bukaPeriodeBaru(): void
    {
        abort_unless($this->diKas() && $this->periodeBerjalan !== null, 403);

        $this->namaPeriode = 'Periode '.(PeriodeKas::count() + 1);
        $this->mulaiPeriode = today()->toDateString();
        $this->resetValidation();
        $this->formPeriodeOpen = true;
    }

    public function batalPeriodeBaru(): void
    {
        $this->reset('formPeriodeOpen', 'namaPeriode', 'mulaiPeriode');
        $this->resetValidation();
    }

    // Menutup periode berjalan (dikunci) dan membuka periode baru. Sisa saldo otomatis jadi saldo awal.
    public function mulaiPeriodeBaru(): void
    {
        if (! $this->formPeriodeOpen) {
            return;
        }

        $berjalan = $this->periodeBerjalan;
        abort_if($berjalan === null, 422, 'Belum ada periode Kas yang berjalan.');

        $this->namaPeriode = trim($this->namaPeriode);
        $data = $this->validate([
            'namaPeriode' => ['required', 'string', 'max:50', Rule::unique('periode_kas', 'nama')],
            'mulaiPeriode' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$berjalan->tanggal_mulai->toDateString()],
        ], [
            'namaPeriode.required' => 'Isi nama periode.',
            'namaPeriode.max' => 'Nama periode maksimal 50 karakter.',
            'namaPeriode.unique' => 'Nama periode sudah dipakai.',
            'mulaiPeriode.required' => 'Isi tanggal mulai.',
            'mulaiPeriode.date_format' => 'Tanggal mulai tidak valid.',
            'mulaiPeriode.after_or_equal' => 'Tanggal mulai tidak boleh sebelum '.$berjalan->nama.' dimulai.',
        ]);

        $baru = DB::transaction(function () use ($berjalan, $data) {
            // Kunci baris periode berjalan supaya tidak ditutup dua kali oleh dua pengguna sekaligus.
            $periode = PeriodeKas::whereKey($berjalan->id)->lockForUpdate()->first();
            abort_if($periode === null || $periode->tanggal_tutup !== null, 409, 'Periode ini sudah ditutup oleh pengguna lain.');

            $periode->update(['tanggal_tutup' => now()]);

            return PeriodeKas::create([
                'nama' => $data['namaPeriode'],
                'tanggal_mulai' => $data['mulaiPeriode'],
            ]);
        });

        $this->periodeId = $baru->id;
        $this->batalPeriodeBaru();
        unset(
            $this->periodeBerjalan, $this->daftarPeriode, $this->periode, $this->periodeTertutup,
            $this->perluPeriodeBaru, $this->riwayat, $this->rekap
        );
        $this->resetPage();
        session()->flash('pesan', $berjalan->nama.' ditutup. '.$baru->nama.' dimulai dengan sisa saldo sebelumnya.');
    }
};
?>

<section aria-labelledby="judul-keuangan">
    @php
        // Hasil SUM bisa melampaui presisi float. Format langsung dari angka desimal MySQL.
        $rupiah = static function ($nilai): string {
            $angka = (string) $nilai;
            $minus = str_starts_with($angka, '-');

            return ($minus ? '−' : '').'Rp'.preg_replace('/\B(?=(\d{3})+$)/', '.', ltrim($angka, '-'));
        };
        $tanggalId = static fn ($nilai) => \Illuminate\Support\Carbon::parse($nilai)->locale('id');
        $tersaring = $cari !== '' || $arus !== 'semua' || $rentang !== 'semua';
        $kas = $tab === 'Kas';
        $periodeKas = $this->periode;
        $terkunci = $kas && $periodeKas !== null && $periodeKas->tanggal_tutup !== null;
        $berjalan = $kas ? $this->periodeBerjalan : null;
        $periodePertama = $periodeKas !== null && (string) $this->daftarPeriode->last()?->periode_id === (string) $periodeKas->periode_id;
        $saldoBerjalan = $berjalan ? $this->daftarPeriode->firstWhere('periode_id', $berjalan->id)?->saldo_akhir : null;
        $gembok = '<svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>';
        $gembokBuka = '<svg class="ikon ikon-buka" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 7.46-2"/></svg>';
        $transaksiBaru = $editId === null;
        $keluar = $jenis === 'pengeluaran';
        $pilihanArus = ['semua' => 'Semua', 'kredit' => 'Kredit', 'debit' => 'Debit'];
    @endphp

    <header class="kepala">
        <div>
            <h1 id="judul-keuangan">Catatan keuangan</h1>
            <p class="redup">Kelola saldo kas dan operasional beserta riwayat transaksinya.</p>
        </div>
        <button type="button" class="tombol" wire:click="ekspor" wire:loading.attr="disabled" wire:target="ekspor">
            <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14"/></svg>
            Ekspor CSV
        </button>
    </header>

    <nav class="tab-akun" aria-label="Akun keuangan">
        @foreach (['Kas', 'Operasional'] as $nama)
            <a href="?tab={{ strtolower($nama) }}" wire:navigate
               @if ($tab === $nama) aria-current="page" @endif>{{ $nama }}</a>
        @endforeach
    </nav>

    @if ($kas && $this->daftarPeriode->isNotEmpty())
        <div class="bilah-periode">
            <div class="daftar-periode" role="group" aria-label="Periode Kas">
                <span class="redup">Periode</span>
                @foreach ($this->daftarPeriode as $p)
                    <button type="button" class="chip-periode" wire:click="pilihPeriode({{ $p->periode_id }})"
                            wire:key="periode-{{ $p->periode_id }}"
                            aria-pressed="{{ (string) $p->periode_id === (string) $periodeKas?->periode_id ? 'true' : 'false' }}">
                        @if ($p->tanggal_tutup)
                            {!! $gembok !!}
                        @else
                            {!! $gembokBuka !!}
                        @endif
                        {{ $p->nama }}
                        <span class="chip-status">· {{ $p->tanggal_tutup ? 'ditutup' : 'berjalan' }}</span>
                    </button>
                @endforeach
            </div>
            @if (! $terkunci && $berjalan)
                <button type="button" class="tombol" wire:click="bukaPeriodeBaru">
                    <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                    Mulai periode baru
                </button>
            @endif
        </div>
    @endif

    @if ($this->perluPeriodeBaru)
        <div class="pengingat" role="status">
            <p>
                <strong>Saldo Kas tinggal {{ $rupiah($periodeKas->saldo_akhir) }}.</strong>
                Kalau uangnya sudah dianggap habis, mulai periode baru. Sisanya otomatis dibawa ke periode berikutnya.
            </p>
            <button type="button" wire:click="bukaPeriodeBaru">Mulai periode baru</button>
        </div>
    @endif

    @if ($terkunci)
        <p class="info-terkunci" role="status">
            {!! $gembok !!}
            <span>
                <strong>{{ $periodeKas->nama }} sudah ditutup pada {{ $tanggalId($periodeKas->tanggal_tutup)->isoFormat('D MMM YYYY') }}.</strong>
                Transaksinya terkunci dan hanya bisa dilihat.
            </span>
        </p>
    @endif

    <div class="kartu-saldo">
        <div class="saldo">
            <span class="ikon-dompet" aria-hidden="true">
                <svg class="ikon" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h11v4"/><path d="M4 7v10a2 2 0 0 0 2 2h14V9H6a2 2 0 0 1-2-2z"/><circle class="isi" cx="16" cy="14" r="1.3"/></svg>
            </span>
            <div>
                @if ($kas && $periodeKas)
                    <h2>{{ $terkunci ? 'Saldo akhir' : 'Saldo Kas' }} · {{ $periodeKas->nama }}</h2>
                    <p class="nilai-saldo">{{ $rupiah($periodeKas->saldo_akhir) }}</p>
                    <p class="catatan rincian-periode">
                        <span>Saldo awal <strong>{{ $rupiah($periodeKas->saldo_awal) }}</strong></span>
                        <span>Masuk <strong>+{{ $rupiah($periodeKas->masuk) }}</strong></span>
                        <span>Keluar <strong>−{{ $rupiah($periodeKas->keluar) }}</strong></span>
                    </p>
                @else
                    <h2>Saldo {{ $tab }}</h2>
                    <p class="nilai-saldo">{{ $rupiah($this->akun->saldo_rupiah) }}</p>
                    @if ($this->terakhir)
                        <p class="catatan">Transaksi terakhir {{ $tanggalId($this->terakhir)->isoFormat('D MMM YYYY') }}</p>
                    @endif
                @endif
            </div>
        </div>
        @unless ($terkunci)
        <div class="aksi-saldo">
            <button type="button" class="utama" wire:click="tambah">
                <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                Tambah saldo
            </button>
            <button type="button" wire:click="tambah('pengeluaran')">
                <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>
                Kurangi saldo
            </button>
        </div>
        @endunless
    </div>

    <p role="status" class="pesan">@if (session('pesan'))<svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="m5 12 5 5L20 7"/></svg>{{ session('pesan') }}@endif</p>

    <div class="kartu-riwayat">
        <div class="alat">
            <div class="judul-riwayat">
                <h2>{{ $kas && $periodeKas ? 'Riwayat '.$periodeKas->nama : 'Riwayat transaksi' }}</h2>
                <span class="redup">{{ $this->riwayat->total() }} transaksi</span>
            </div>
            <div class="filter">
                <label class="cari">
                    <span class="sr-only">Cari keterangan transaksi</span>
                    <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                    <input type="search" wire:model.live.debounce.300ms="cari" maxlength="100"
                           placeholder="Cari keterangan transaksi…">
                </label>
                <div class="pilihan" role="group" aria-label="Jenis transaksi">
                    @foreach ($pilihanArus as $nilai => $label)
                        <button type="button" wire:click="$set('arus', '{{ $nilai }}')"
                                aria-pressed="{{ $arus === $nilai ? 'true' : 'false' }}">{{ $label }}</button>
                    @endforeach
                </div>
                <label class="waktu">
                    <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/></svg>
                    Waktu
                    <select wire:model.live="rentang">
                        <option value="semua">Semua waktu</option>
                        <option value="bulan">Bulan ini</option>
                        <option value="30hari">30 hari terakhir</option>
                        <option value="tahun">Tahun ini</option>
                    </select>
                </label>
                @if ($tersaring)
                    <button type="button" class="tautan" wire:click="resetFilter">Reset filter</button>
                @endif
            </div>
        </div>

        <div class="tabel-scroll" role="region" aria-label="Riwayat {{ $tab }}" tabindex="0">
            <table aria-label="Transaksi {{ $tab }}">
                <thead>
                    <tr>
                        <th scope="col">Tanggal</th>
                        <th scope="col">Keterangan</th>
                        <th scope="col" class="uang">Kredit</th>
                        <th scope="col" class="uang">Debit</th>
                        <th scope="col" class="uang">Sisa saldo</th>
                    </tr>
                </thead>
                <tbody>
                    @if ($kas && $periodeKas && $this->riwayat->onFirstPage())
                        <tr class="baris-awal">
                            <td class="tanggal">
                                <time datetime="{{ $periodeKas->tanggal_mulai }}">{{ $tanggalId($periodeKas->tanggal_mulai)->isoFormat('D MMM YYYY') }}</time>
                            </td>
                            <td>
                                <div class="keterangan">
                                    <span class="arah arah-awal" aria-hidden="true">
                                        <svg class="ikon" viewBox="0 0 24 24"><path d="M9 14 4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-1"/></svg>
                                    </span>
                                    <div>
                                        <strong>Saldo awal</strong>
                                        <span class="redup">{{ $periodePertama ? 'Periode pertama' : 'Sisa dari periode sebelumnya' }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="uang"><span class="redup">—</span></td>
                            <td class="uang"><span class="redup">—</span></td>
                            <td class="uang saldo-baris">{{ $rupiah($periodeKas->saldo_awal) }}</td>
                        </tr>
                    @endif
                    @forelse ($this->riwayat as $t)
                        @php
                            $masuk = (string) $t->akun_tujuan_id === (string) $this->akun->akun_id;
                            $tgl = $tanggalId($t->getRawOriginal('tanggal'));
                            $barisTerkunci = $t->periode_kas_id !== null && in_array($t->periode_kas_id, $this->periodeTertutup);
                            $label = $t->jenis === 'transfer'
                                ? ($masuk ? 'Transfer masuk' : 'Transfer keluar')
                                : ($masuk ? 'Pemasukan' : 'Pengeluaran');
                        @endphp
                        <tr wire:key="transaksi-{{ $tab }}-{{ $t->id }}">
                            <td class="tanggal">
                                <time datetime="{{ $tgl->toDateString() }}">{{ $tgl->isoFormat('D MMM YYYY') }}</time>
                                <span class="redup">{{ $tgl->isoFormat('dddd') }}</span>
                            </td>
                            <td>
                                <div class="keterangan">
                                    <span @class(['arah', 'arah-masuk' => $masuk, 'arah-keluar' => ! $masuk]) aria-hidden="true">
                                        @if ($masuk)
                                            <svg class="ikon" viewBox="0 0 24 24"><path d="M17 7 7 17M7 9v8h8"/></svg>
                                        @else
                                            <svg class="ikon" viewBox="0 0 24 24"><path d="M7 17 17 7M9 7h8v8"/></svg>
                                        @endif
                                    </span>
                                    <div>
                                        @if ($barisTerkunci)
                                            <span class="terkunci">
                                                {{ filled($t->keterangan) ? $t->keterangan : 'Tanpa keterangan' }}
                                                <span class="sr-only">(terkunci)</span>{!! $gembok !!}
                                            </span>
                                        @else
                                            <button type="button" class="ubah" wire:click="ubah({{ $t->id }})" title="Ubah transaksi">
                                                {{ filled($t->keterangan) ? $t->keterangan : 'Tanpa keterangan' }}
                                            </button>
                                        @endif
                                        <span class="redup">{{ $label }}</span>
                                    </div>
                                </div>
                            </td>
                            <td class="uang">
                                @if ($masuk)
                                    <span class="masuk">+{{ $rupiah($t->nominal) }}</span>
                                @else
                                    <span class="redup">—</span>
                                @endif
                            </td>
                            <td class="uang">
                                @if ($masuk)
                                    <span class="redup">—</span>
                                @else
                                    <span class="keluar">−{{ $rupiah($t->nominal) }}</span>
                                @endif
                            </td>
                            <td class="uang saldo-baris">{{ $rupiah($t->sisa_saldo) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="kosong" colspan="5">
                                <div class="kosong-isi">
                                    @if ($tersaring)
                                        <strong>Tidak ada transaksi yang cocok</strong>
                                        <span class="redup">Coba ubah kata kunci, jenis, atau waktu transaksi.</span>
                                        <button type="button" class="tombol" wire:click="resetFilter">Reset filter</button>
                                    @else
                                        <strong>Belum ada transaksi {{ $kas && $periodeKas ? 'di '.$periodeKas->nama : $tab }}</strong>
                                        @unless ($terkunci)
                                            <span class="redup">Gunakan Tambah saldo untuk mencatat transaksi pertama.</span>
                                        @endunless
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="kaki">
            <p class="redup">
                @if ($this->riwayat->total() > 0)
                    Menampilkan {{ $this->riwayat->firstItem() }}–{{ $this->riwayat->lastItem() }}
                    dari {{ $this->riwayat->total() }} transaksi
                @else
                    Tidak ada transaksi yang ditampilkan
                @endif
            </p>
            <p class="total">
                <span>Total kredit <strong class="masuk">{{ $rupiah($this->rekap->kredit) }}</strong></span>
                <span>Total debit <strong class="keluar">{{ $rupiah($this->rekap->debit) }}</strong></span>
                @if ($kas && $periodeKas)
                    <span>{{ $terkunci ? 'Saldo akhir' : 'Saldo saat ini' }} <strong>{{ $rupiah($periodeKas->saldo_akhir) }}</strong></span>
                @endif
            </p>
        </div>

        @if ($this->riwayat->hasPages())
            <div class="halaman">{{ $this->riwayat->links() }}</div>
        @endif
    </div>

    <dialog class="modal-transaksi" aria-labelledby="judul-transaksi" wire:ignore.self x-data
            x-effect="$wire.formOpen ? $el.showModal() : $el.close()"
            x-on:cancel.prevent="$wire.baru()">
        <div class="modal-kepala">
            <div>
                <h2 id="judul-transaksi">
                    @if (! $transaksiBaru)
                        Ubah {{ $jenis }} {{ $tab }}
                    @else
                        {{ $keluar ? 'Kurangi saldo' : 'Tambah saldo' }} {{ $tab }}
                    @endif
                </h2>
                <p class="redup">Saldo {{ $tab }} saat ini {{ $rupiah($this->akun->saldo_rupiah) }}</p>
            </div>
            <button type="button" class="tutup" wire:click="baru" aria-label="Tutup">
                <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>

        @if ($jenis === 'transfer')
            <p class="info">Perubahan transfer ini berlaku pada kedua akun.</p>
        @endif

        <form wire:submit="simpan" data-saldo="{{ $this->akun->saldo_rupiah }}"
              x-data="{
                  get angka() {
                      const n = String($wire.nominal ?? '').trim();
                      if (/^\d{1,3}(\.\d{3})+$/.test(n)) return Number(n.replaceAll('.', ''));
                      return /^\d+$/.test(n) ? Number(n) : 0;
                  },
                  get setelah() {
                      const saldo = Number(this.$root.dataset.saldo);
                      return $wire.jenis === 'pengeluaran' ? saldo - this.angka : saldo + this.angka;
                  },
                  rp(n) { return (n < 0 ? '−' : '') + 'Rp' + Math.abs(n).toLocaleString('id-ID'); }
              }">
            @if ($transaksiBaru)
                <div class="pilih-jenis" role="group" aria-label="Jenis transaksi">
                    <button type="button" class="jenis-masuk" wire:click="pilihJenis('pemasukan')"
                            aria-pressed="{{ $keluar ? 'false' : 'true' }}">
                        <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        Tambah saldo
                    </button>
                    <button type="button" class="jenis-keluar" wire:click="pilihJenis('pengeluaran')"
                            aria-pressed="{{ $keluar ? 'true' : 'false' }}">
                        <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14"/></svg>
                        Kurangi saldo
                    </button>
                </div>
            @endif

            <label class="isian">Nominal
                <span class="nominal">
                    <span aria-hidden="true">Rp</span>
                    <input type="text" inputmode="numeric" autocomplete="off" wire:model="nominal" placeholder="0" required>
                </span>
                @error('nominal') <small role="alert">{{ $message }}</small> @enderror
                @if ($transaksiBaru && $keluar)
                    <small class="peringatan" aria-live="polite" x-show="setelah < 0" x-cloak>
                        Nominal melebihi saldo {{ $tab }} saat ini.
                    </small>
                @endif
            </label>

            <label class="isian">Tanggal
                <input type="date" wire:model="tanggal" required>
                @error('tanggal') <small role="alert">{{ $message }}</small> @enderror
            </label>

            <label class="isian">Keterangan <span class="redup">(opsional)</span>
                <textarea wire:model="keterangan" rows="3" maxlength="255" placeholder="Tulis keterangan transaksi"></textarea>
                @error('keterangan') <small role="alert">{{ $message }}</small> @enderror
            </label>

            @if ($transaksiBaru)
                <dl class="pratinjau">
                    <div>
                        <dt>Saldo saat ini</dt>
                        <dd>{{ $rupiah($this->akun->saldo_rupiah) }}</dd>
                    </div>
                    <div>
                        <dt>{{ $keluar ? 'Dikurangi' : 'Ditambahkan' }}</dt>
                        <dd @class(['masuk' => ! $keluar, 'keluar' => $keluar])
                            x-text="($wire.jenis === 'pengeluaran' ? '−' : '+') + rp(angka)">{{ $keluar ? '−' : '+' }}Rp0</dd>
                    </div>
                    <div class="setelah">
                        <dt>Saldo setelah transaksi</dt>
                        <dd x-text="rp(setelah)">{{ $rupiah($this->akun->saldo_rupiah) }}</dd>
                    </div>
                </dl>
            @endif

            <div class="aksi-form">
                @if (! $transaksiBaru)
                    <button type="button" class="hapus" wire:click="hapus({{ $editId }})"
                            wire:confirm="Hapus transaksi ini? Saldo akun terkait akan dihitung ulang.">Hapus</button>
                @endif
                <button type="button" class="tombol" wire:click="baru">Batal</button>
                <button type="submit" class="tombol utama" wire:loading.attr="disabled" wire:target="simpan">
                    {{ $transaksiBaru ? 'Simpan transaksi' : 'Simpan perubahan' }}
                </button>
            </div>
        </form>
    </dialog>

    @if ($berjalan)
        <dialog class="modal-transaksi" aria-labelledby="judul-periode" wire:ignore.self x-data
                x-effect="$wire.formPeriodeOpen ? $el.showModal() : $el.close()"
                x-on:cancel.prevent="$wire.batalPeriodeBaru()">
            <div class="modal-kepala">
                <div>
                    <h2 id="judul-periode">Mulai periode baru</h2>
                    <p class="redup">{{ $berjalan->nama }} akan ditutup dan transaksinya dikunci. Sisa saldo dibawa ke periode baru sebagai saldo awal.</p>
                </div>
                <button type="button" class="tutup" wire:click="batalPeriodeBaru" aria-label="Tutup">
                    <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg>
                </button>
            </div>

            <form wire:submit="mulaiPeriodeBaru">
                <label class="isian">Nama periode baru
                    <input type="text" wire:model="namaPeriode" maxlength="50" required>
                    @error('namaPeriode') <small role="alert">{{ $message }}</small> @enderror
                </label>

                <label class="isian">Tanggal mulai
                    <input type="date" wire:model="mulaiPeriode" required>
                    @error('mulaiPeriode') <small role="alert">{{ $message }}</small> @enderror
                </label>

                <dl class="pratinjau">
                    <div>
                        <dt>{{ $berjalan->nama }}</dt>
                        <dd>Ditutup</dd>
                    </div>
                    <div class="setelah">
                        <dt>Saldo awal periode baru</dt>
                        <dd>{{ $rupiah($saldoBerjalan ?? 0) }}</dd>
                    </div>
                </dl>

                <div class="aksi-form">
                    <button type="button" class="tombol" wire:click="batalPeriodeBaru">Batal</button>
                    <button type="submit" class="tombol utama" wire:loading.attr="disabled" wire:target="mulaiPeriodeBaru">
                        Tutup dan mulai baru
                    </button>
                </div>
            </form>
        </dialog>
    @endif
</section>

<style>
    & {
        --teal: #0f766e;
        --teal-gelap: #115e59;
        --hijau-saldo: #0f4c47;
        --garis: light-dark(#e3e8e6, #2c3a37);
        --garis-kuat: light-dark(#cfd9d6, #3d4f4b);
        --arsir: light-dark(#f6f8f7, #182321);
        --redup: light-dark(#5f6e6b, #a5b4b0);
        --masuk: light-dark(#0f766e, #5eead4);
        --masuk-latar: light-dark(#e6f4f1, #123631);
        --keluar: light-dark(#b3471b, #ffb59c);
        --keluar-latar: light-dark(#fdeee7, #3d2219);
        --cincin: 0 0 0 3px color-mix(in srgb, var(--teal) 25%, transparent);
        display: flex; flex-direction: column; gap: 1.5rem;
        width: 100%; min-width: 0; font-family: var(--font-sans, system-ui), sans-serif; line-height: 1.5;
    }

    /* Dasar */
    .ikon { width: 1.125rem; height: 1.125rem; flex-shrink: 0; fill: none; stroke: currentColor;
        stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .ikon .isi { fill: currentColor; stroke: none; }
    .redup { color: var(--redup); }
    .sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); white-space: nowrap; }
    [x-cloak] { display: none !important; }
    .tombol { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 2.75rem;
        padding: 0 1rem; border: 1px solid var(--garis-kuat); border-radius: .625rem; background: Canvas; color: CanvasText;
        font: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; }
    .tombol:hover { background: var(--arsir); }
    .tombol.utama { border-color: var(--teal); background: var(--teal); color: #fff; }
    .tombol.utama:hover { background: var(--teal-gelap); }
    .tombol:disabled { opacity: .6; cursor: progress; }
    .tautan { min-height: 2.75rem; padding: 0 .5rem; border: 0; background: none; color: var(--teal);
        font: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; }

    /* Kepala dan tab */
    .kepala { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem; }
    h1 { margin: 0; font-size: clamp(1.6rem, 3vw, 2rem); line-height: 1.2; font-weight: 700; letter-spacing: -.02em; }
    .kepala p { margin: .35rem 0 0; font-size: .95rem; }
    .tab-akun { display: flex; gap: .25rem; border-bottom: 1px solid var(--garis); }
    .tab-akun a { display: inline-flex; align-items: center; min-height: 2.75rem; padding: 0 1rem; margin-bottom: -1px;
        border-bottom: 2px solid transparent; color: var(--redup); font-weight: 500; text-decoration: none; }
    .tab-akun a:hover { color: CanvasText; background: var(--arsir); }
    .tab-akun a[aria-current] { border-color: var(--teal); color: CanvasText; font-weight: 700; }

    /* Kartu saldo */
    .kartu-saldo { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 1.5rem;
        padding: 1.75rem 2rem; border-radius: 1rem; background: var(--hijau-saldo); color: #fff; }
    .saldo { display: flex; align-items: center; gap: 1.25rem; min-width: 0; }
    .ikon-dompet { display: grid; place-items: center; width: 3.5rem; height: 3.5rem; flex-shrink: 0;
        border-radius: .875rem; background: rgb(255 255 255 / 12%); }
    .ikon-dompet .ikon { width: 1.75rem; height: 1.75rem; }
    .kartu-saldo h2 { margin: 0; font-size: .9rem; font-weight: 500; color: #cfe7e2; }
    .nilai-saldo { margin: .15rem 0; font-size: clamp(1.9rem, 4vw, 2.5rem); line-height: 1.15; font-weight: 800;
        letter-spacing: -.02em; font-variant-numeric: tabular-nums; overflow-wrap: anywhere; }
    .kartu-saldo .catatan { margin: 0; font-size: .8rem; color: #a9d2cb; }
    .aksi-saldo { display: flex; flex-wrap: wrap; gap: .75rem; }
    .aksi-saldo button { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 3rem;
        padding: 0 1.25rem; border: 1px solid rgb(255 255 255 / 45%); border-radius: .75rem; background: transparent;
        color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
    .aksi-saldo button:hover { background: rgb(255 255 255 / 10%); }
    .aksi-saldo .utama { border-color: #fff; background: #fff; color: var(--hijau-saldo); font-weight: 700; }
    .aksi-saldo .utama:hover { background: #e6f4f1; }
    .kartu-saldo button:focus-visible { outline-color: #fff; }

    .pesan { display: flex; align-items: center; gap: .5rem; margin: 0; padding: .75rem 1rem; border-radius: .625rem;
        background: var(--masuk-latar); color: var(--masuk); font-weight: 600; }
    .pesan:empty { display: none; }

    /* Riwayat */
    .kartu-riwayat { border: 1px solid var(--garis); border-radius: 1rem; background: Canvas; overflow: hidden; }
    .alat { display: grid; gap: 1rem; padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--garis); }
    .judul-riwayat { display: flex; align-items: baseline; gap: .6rem; }
    .judul-riwayat h2 { margin: 0; font-size: 1.1rem; font-weight: 700; }
    .judul-riwayat span { font-size: .8rem; }
    .filter { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
    .cari, .waktu { display: flex; align-items: center; gap: .6rem; min-height: 2.75rem; padding: 0 .85rem;
        border: 1px solid var(--garis-kuat); border-radius: .625rem; background: Canvas; color: var(--redup); font-size: .9rem; }
    .cari { flex: 1 1 16rem; min-width: 0; }
    .cari:focus-within, .waktu:focus-within { border-color: var(--teal); box-shadow: var(--cincin); }
    .cari input, .waktu select { min-width: 0; border: 0; background: transparent; color: CanvasText; font: inherit; outline: none; }
    .cari input { flex: 1; align-self: stretch; }
    .waktu select { font-weight: 600; cursor: pointer; }
    .pilihan { display: flex; gap: .25rem; padding: .25rem; border-radius: .625rem; background: var(--arsir); }
    .pilihan button { min-height: 2.25rem; padding: 0 .9rem; border: 0; border-radius: .5rem; background: transparent;
        color: var(--redup); font: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; }
    .pilihan button[aria-pressed="true"] { background: Canvas; color: CanvasText; box-shadow: 0 1px 2px rgb(0 0 0 / 14%); }

    .tabel-scroll { max-width: 100%; overflow-x: auto; }
    .tabel-scroll:focus-visible { outline-offset: -2px; }
    table { width: 100%; min-width: 40rem; border-collapse: collapse; font-variant-numeric: tabular-nums; }
    th, td { padding: .9rem .75rem; text-align: left; font-size: .9rem; }
    th:first-child, td:first-child { padding-inline-start: 1.5rem; }
    th:last-child, td:last-child { padding-inline-end: 1.5rem; }
    th { background: var(--arsir); color: var(--redup); font-size: .75rem; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
    td { border-top: 1px solid var(--garis); vertical-align: middle; }
    .uang { text-align: right; white-space: nowrap; }
    .tanggal { white-space: nowrap; }
    .tanggal time { display: block; font-weight: 600; }
    .tanggal span { font-size: .8rem; }
    .keterangan { display: flex; align-items: center; gap: .75rem; }
    .keterangan > div { display: grid; min-width: 0; }
    .keterangan .redup { font-size: .8rem; }
    .arah { display: grid; place-items: center; width: 2.25rem; height: 2.25rem; flex-shrink: 0; border-radius: 50%; }
    .arah-masuk { background: var(--masuk-latar); color: var(--masuk); }
    .arah-keluar { background: var(--keluar-latar); color: var(--keluar); }
    .ubah { justify-self: start; padding: 0; border: 0; background: none; color: inherit; font: inherit; font-weight: 600;
        text-align: start; text-decoration: underline; text-decoration-color: var(--garis-kuat); text-underline-offset: .2em;
        overflow-wrap: anywhere; cursor: pointer; }
    .ubah:hover { color: var(--teal); text-decoration-color: currentColor; }
    .masuk { color: var(--masuk); font-weight: 600; }
    .keluar { color: var(--keluar); font-weight: 600; }
    .saldo-baris { font-weight: 700; }
    .kosong { padding: 3rem 1.5rem; }
    .kosong-isi { display: grid; justify-items: center; gap: .35rem; text-align: center; }
    .kosong-isi .tombol { margin-top: .5rem; }
    .kaki { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;
        padding: .9rem 1.5rem; border-top: 1px solid var(--garis); background: var(--arsir); font-size: .8rem; }
    .kaki p { margin: 0; }
    .total { display: flex; flex-wrap: wrap; gap: 1.25rem; color: var(--redup); font-variant-numeric: tabular-nums; }
    .halaman { padding: .75rem 1.5rem; border-top: 1px solid var(--garis); }

    /* Form transaksi */
    .modal-transaksi {
        inset: 0; box-sizing: border-box; width: min(30rem, calc(100% - 2rem)); max-height: calc(100dvh - 2rem);
        margin: auto; padding: 1.5rem; overflow-y: auto; border: 0; border-radius: 1rem;
        background: Canvas; color: CanvasText; box-shadow: 0 24px 64px rgb(10 32 29 / 24%);
    }
    .modal-transaksi:not([open]) { display: none; }
    .modal-transaksi::backdrop { background: rgb(10 32 29 / 50%); }
    .modal-kepala { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1.25rem; }
    .modal-kepala h2 { margin: 0; font-size: 1.25rem; line-height: 1.3; font-weight: 700; }
    .modal-kepala p { margin: .25rem 0 0; font-size: .9rem; }
    .tutup { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; flex-shrink: 0; margin: -.5rem -.6rem 0 0;
        border: 0; border-radius: .625rem; background: none; color: var(--redup); cursor: pointer; }
    .tutup:hover { background: var(--arsir); color: CanvasText; }
    .info { margin: 0 0 1rem; padding: .75rem 1rem; border-radius: .625rem; background: var(--arsir); font-size: .9rem; }
    .modal-transaksi form { display: grid; gap: 1rem; }
    .pilih-jenis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .5rem; }
    .pilih-jenis button { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; min-height: 3rem;
        border: 1.5px solid var(--garis-kuat); border-radius: .625rem; background: Canvas; color: var(--redup);
        font: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; }
    .jenis-masuk[aria-pressed="true"] { border-color: var(--masuk); background: var(--masuk-latar); color: var(--masuk); }
    .jenis-keluar[aria-pressed="true"] { border-color: var(--keluar); background: var(--keluar-latar); color: var(--keluar); }
    .isian { display: grid; gap: .35rem; font-size: .85rem; font-weight: 600; }
    .isian .redup { font-weight: 400; }
    .isian input, .isian textarea { box-sizing: border-box; width: 100%; min-height: 2.75rem; padding: .55rem .75rem;
        border: 1px solid var(--garis-kuat); border-radius: .625rem; background: Canvas; color: CanvasText;
        font: inherit; font-size: .9rem; font-weight: 400; }
    .isian textarea { resize: vertical; }
    .isian input:focus, .isian textarea:focus { outline: none; border-color: var(--teal); box-shadow: var(--cincin); }
    .nominal { display: flex; align-items: stretch; min-height: 3.5rem; overflow: hidden;
        border: 1px solid var(--garis-kuat); border-radius: .625rem; }
    .nominal:focus-within { border-color: var(--teal); box-shadow: var(--cincin); }
    .nominal > span { display: grid; place-items: center; padding: 0 .9rem; border-inline-end: 1px solid var(--garis-kuat);
        background: var(--arsir); color: var(--redup); font-size: 1rem; }
    .isian .nominal input { min-height: 0; border: 0; border-radius: 0; font-size: 1.35rem; font-weight: 700;
        font-variant-numeric: tabular-nums; }
    .isian .nominal input:focus { box-shadow: none; }
    .peringatan { color: var(--keluar); font-size: .8rem; font-weight: 500; }
    [role="alert"] { color: light-dark(#9f2020, #ffb4ab); font-size: .8rem; font-weight: 500; }
    .pratinjau { display: grid; gap: .5rem; margin: 0; padding: .9rem 1rem; border-radius: .75rem; background: var(--arsir);
        font-size: .9rem; font-variant-numeric: tabular-nums; }
    .pratinjau div { display: flex; justify-content: space-between; gap: 1rem; }
    .pratinjau dt { color: var(--redup); }
    .pratinjau dd { margin: 0; font-weight: 600; }
    .pratinjau .setelah { padding-top: .5rem; border-top: 1px dashed var(--garis-kuat); }
    .pratinjau .setelah dt { color: CanvasText; font-weight: 700; }
    .pratinjau .setelah dd { font-size: 1rem; font-weight: 800; }
    .aksi-form { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .75rem; margin-top: .25rem; }
    .hapus { margin-inline-end: auto; min-height: 2.75rem; padding: 0 .5rem; border: 0; background: none;
        color: light-dark(#9f2020, #ffb4ab); font: inherit; font-size: .9rem; font-weight: 600; cursor: pointer; }

    /* Periode Kas */
    .bilah-periode { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem; }
    .daftar-periode { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
    .daftar-periode > .redup { margin-right: .25rem; font-size: .8rem; font-weight: 600; }
    .chip-periode { display: inline-flex; align-items: center; gap: .5rem; min-height: 2.5rem; padding: 0 .9rem;
        border: 1px solid var(--garis-kuat); border-radius: 999px; background: Canvas; color: CanvasText;
        font: inherit; font-size: .875rem; font-weight: 600; cursor: pointer; }
    .chip-periode .ikon { width: .875rem; height: .875rem; }
    .chip-status { font-weight: 500; color: var(--redup); }
    .chip-periode[aria-pressed="true"] { border-color: var(--hijau-saldo); background: var(--hijau-saldo); color: #fff; }
    .chip-periode[aria-pressed="true"] .chip-status { color: #cfe7e2; }
    .chip-periode .ikon-buka { color: #0d9488; }
    .chip-periode[aria-pressed="true"] .ikon-buka { color: #5eead4; }
    .pengingat { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem;
        padding: .9rem 1.1rem; border-radius: .875rem; background: light-dark(#fef3e2, #3a2a12); color: light-dark(#7a4307, #fcd9a8); }
    .pengingat p { margin: 0; font-size: .9rem; }
    .pengingat button { min-height: 2.5rem; padding: 0 1rem; border: 0; border-radius: .625rem;
        background: light-dark(#7a4307, #fcd9a8); color: light-dark(#fff, #3a2a12); font: inherit; font-size: .875rem; font-weight: 700; cursor: pointer; }
    .info-terkunci { display: flex; align-items: center; gap: .6rem; margin: 0; padding: .9rem 1.1rem;
        border-radius: .875rem; background: var(--arsir); font-size: .9rem; }
    .rincian-periode { display: flex; flex-wrap: wrap; gap: .25rem 1rem; }
    .rincian-periode strong { color: #fff; }
    .baris-awal td { background: color-mix(in srgb, var(--masuk-latar) 60%, transparent); }
    .arah-awal { background: Canvas; color: var(--masuk); }
    .terkunci { display: inline-flex; align-items: center; gap: .4rem; font-weight: 600; overflow-wrap: anywhere; }
    .terkunci .ikon { width: .875rem; height: .875rem; color: var(--redup); }

    :is(a, button, .tabel-scroll):focus-visible { outline: 2px solid var(--teal); outline-offset: 2px; }

    @media (max-width: 40rem) {
        .kartu-saldo { padding: 1.25rem; }
        .aksi-saldo, .aksi-saldo button { flex: 1 1 auto; }
        .alat, .kaki { padding-inline: 1rem; }
        th:first-child, td:first-child { padding-inline-start: 1rem; }
        th:last-child, td:last-child { padding-inline-end: 1rem; }
    }
</style>