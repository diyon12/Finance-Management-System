<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="Catat kas dan operasional dalam satu tempat.">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        <style>
            :root {
                color-scheme: light;
                --latar: #fbfaf7;
                --teks: #12302b;
                --redup: #3e5551;
                --redup-2: #4b5b58;
                --mint: #e3f3ee;
                --gelap: #0f4c47;
                --gelap-hover: #0b3a36;
            }
            *, *::before, *::after { box-sizing: border-box; }
            html { scroll-behavior: smooth; }
            body {
                margin: 0; background: var(--latar); color: var(--teks);
                font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
                line-height: 1.5; -webkit-font-smoothing: antialiased;
            }
            a { color: inherit; }
            :focus-visible { outline: 2px solid #0f766e; outline-offset: 3px; }
            .ikon { width: 1.125rem; height: 1.125rem; flex-shrink: 0; fill: none; stroke: currentColor;
                stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
            .wadah { width: 100%; max-width: 78rem; margin-inline: auto; padding-inline: 1.5rem; }

            /* Navigasi */
            .nav { min-height: 5rem; padding-inline: 2rem; display: flex; align-items: center; justify-content: space-between; gap: 1rem; }
            .merek { display: inline-flex; align-items: center; gap: .625rem; font-size: 1.0625rem; font-weight: 700; text-decoration: none; }
            .logo { display: grid; place-items: center; width: 2.25rem; height: 2.25rem; border-radius: 50%; background: #cdebe2; color: var(--gelap); }

            /* Tombol */
            .tombol { display: inline-flex; align-items: center; justify-content: center; gap: .625rem; min-height: 3.375rem;
                padding: 0 1.75rem; border-radius: 999px; font-size: 1rem; font-weight: 700; text-decoration: none;
                transition: background-color .15s; }
            .tombol-kecil { min-height: 2.75rem; padding: 0 1.375rem; font-size: .875rem; }
            .tombol-gelap { background: var(--gelap); color: #fff; }
            .tombol-gelap:hover { background: var(--gelap-hover); }
            .tombol-terang { background: #fff; color: var(--teks); font-weight: 600; }
            .tombol-terang:hover { background: #f4f7f6; }

            /* Bagian utama */
            .hero { display: flex; flex-wrap: wrap; align-items: center; gap: 3rem; padding: 4.5rem 3.5rem;
                border-radius: 2.25rem; background: var(--mint); }
            .hero-teks { flex: 1 1 25rem; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 1.5rem; }
            .lencana { display: inline-flex; align-items: center; gap: .5rem; min-height: 2rem; padding: 0 .875rem;
                border-radius: 999px; background: #fff; color: var(--gelap); font-size: .8125rem; font-weight: 600; }
            .lencana .ikon { width: .875rem; height: .875rem; }
            h1 { margin: 0; font-size: clamp(2.25rem, 5vw, 3.25rem); line-height: 1.1; font-weight: 800; letter-spacing: -.03em; }
            .sub { margin: 0; max-width: 31rem; font-size: 1.125rem; line-height: 1.6; color: var(--redup); }
            .aksi { display: flex; flex-wrap: wrap; gap: .75rem; }
            .catatan { margin: 0; font-size: .8125rem; color: var(--redup); }

            /* Contoh tampilan dashboard (ilustrasi, bukan data asli) */
            .pratinjau { flex: 1 1 28.75rem; min-width: 0; padding: 1.25rem; border-radius: 1.75rem; background: #fff;
                box-shadow: 0 24px 60px rgb(15 76 71 / 12%); display: grid; gap: .75rem; }
            .p-kepala { display: flex; justify-content: space-between; align-items: baseline; font-size: .9375rem; font-weight: 700; }
            .p-kepala span { font-size: .6875rem; font-weight: 400; color: #5f6e6b; }
            .p-ubin { display: grid; grid-template-columns: 1.6fr 1fr 1fr; gap: .625rem; }
            .ubin { padding: .875rem; border-radius: 1rem; }
            .ubin small { display: block; font-size: .625rem; font-weight: 600; color: var(--redup-2); }
            .ubin strong { display: block; font-size: .875rem; }
            .ubin-besar strong { font-size: 1.25rem; font-weight: 800; }
            .p-bawah { display: grid; grid-template-columns: 1.7fr 1fr; gap: .625rem; }
            .panel { padding: .875rem; border-radius: 1rem; background: #fafaf8; display: grid; gap: .625rem; align-content: start; }
            .panel > b { font-size: .6875rem; }
            .batang-batang { height: 7.5rem; display: flex; border-bottom: 1px solid #d9dfdc; }
            .kelompok { flex: 1; display: flex; align-items: flex-end; justify-content: center; gap: 2px; }
            .kelompok span { width: .625rem; border-radius: 5px 5px 0 0; }
            .akun { display: grid; gap: .25rem; }
            .akun small { font-size: .625rem; color: var(--redup-2); }
            .akun strong { font-size: .8125rem; }
            .trek { height: 6px; overflow: hidden; border-radius: 3px; background: #e9eeec; }
            .trek span { display: block; height: 100%; border-radius: 3px; background: #0d9488; }

            /* Fitur */
            .fitur { padding: 6rem 2rem; display: grid; justify-items: center; gap: 3rem; }
            .fitur-kepala { max-width: 40rem; display: grid; gap: .75rem; text-align: center; }
            h2 { margin: 0; font-size: clamp(1.75rem, 3.5vw, 2.375rem); line-height: 1.18; font-weight: 800; letter-spacing: -.02em; }
            .fitur-kepala p { margin: 0; color: var(--redup); }
            .kartu-kartu { width: 100%; margin: 0; padding: 0; list-style: none;
                display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: 1.25rem; }
            .kartu { padding: 1.75rem; border-radius: 1.75rem; display: grid; gap: .875rem; align-content: start; }
            .bulat { display: grid; place-items: center; width: 3.25rem; height: 3.25rem; border-radius: 50%; background: #fff; }
            .bulat .ikon { width: 1.375rem; height: 1.375rem; }
            h3 { margin: .375rem 0 0; font-size: 1.1875rem; font-weight: 700; }
            .kartu p { margin: 0; font-size: .9375rem; line-height: 1.6; color: var(--redup); }
            .mint { background: #e3f3ee; }
            .peach { background: #fdebdd; }
            .lavender { background: #eceafb; }
            .butter { background: #fbf1d3; }

            /* Ajakan dan footer */
            .ajakan { margin-bottom: 5rem; padding: 3.5rem; border-radius: 2.25rem; background: #fbf1d3;
                display: grid; justify-items: center; gap: 1.25rem; text-align: center; }
            .ajakan p { margin: 0; font-size: 1.0625rem; color: #4a4a3a; }
            .kaki { padding: 0 2rem 2.5rem; display: flex; flex-wrap: wrap; justify-content: space-between; gap: .75rem;
                font-size: .8125rem; color: var(--redup-2); }
            .kaki strong { color: var(--teks); }

            @media (max-width: 40rem) {
                .nav, .kaki { padding-inline: .5rem; }
                .hero { padding: 2.5rem 1.5rem; border-radius: 1.75rem; }
                .fitur { padding: 4rem .5rem; }
                .ajakan { padding: 2.5rem 1.5rem; }
                .p-ubin { grid-template-columns: 1fr 1fr; }
                .ubin-besar { grid-column: 1 / -1; }
                .p-bawah { grid-template-columns: 1fr; }
            }
            @media (prefers-reduced-motion: reduce) {
                html { scroll-behavior: auto; }
                .tombol { transition: none; }
            }
        </style>
    </head>
    <body>
        @php
            $namaAplikasi = config('app.name', 'Laravel');
            $sudahMasuk = auth()->check();
            $tujuan = match (true) {
                $sudahMasuk && Route::has('dashboard') => route('dashboard'),
                ! $sudahMasuk && Route::has('login') => route('login'),
                default => null,
            };
            $labelTujuan = $sudahMasuk ? 'Buka dashboard' : 'Masuk';

            $panah = '<svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

            $fitur = [
                ['mint', 'Saldo per akun', 'Kas dan Operasional dicatat terpisah. Saldo dihitung otomatis dari setiap transaksi.',
                    '<path d="M4 7a2 2 0 0 1 2-2h11v4"/><path d="M4 7v10a2 2 0 0 0 2 2h14V9H6a2 2 0 0 1-2-2z"/>'],
                ['peach', 'Riwayat yang rapi', 'Setiap transaksi menampilkan sisa saldo, jadi mudah ditelusuri kapan pun.',
                    '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>'],
                ['lavender', 'Cari dan filter', 'Temukan transaksi berdasarkan keterangan, jenis, atau periode.',
                    '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>'],
                ['butter', 'Ekspor laporan', 'Unduh riwayat sesuai filter sebagai CSV dan buka langsung di Excel.',
                    '<path d="M12 4v11m0 0-4-4m4 4 4-4M5 19h14"/>'],
            ];

            // Tinggi batang (%) untuk ilustrasi grafik: [masuk, keluar].
            $contohBatang = [[42, 31], [50, 36], [38, 41], [61, 39], [54, 44], [99, 26]];
        @endphp

        <header class="wadah nav">
            <a class="merek" href="{{ url('/') }}">
                <span class="logo" aria-hidden="true">
                    <svg class="ikon" viewBox="0 0 24 24"><path d="M4 7a2 2 0 0 1 2-2h11v4"/><path d="M4 7v10a2 2 0 0 0 2 2h14V9H6a2 2 0 0 1-2-2z"/></svg>
                </span>
                {{ $namaAplikasi }}
            </a>
            @if ($tujuan)
                <a class="tombol tombol-gelap tombol-kecil" href="{{ $tujuan }}">{{ $labelTujuan }}</a>
            @endif
        </header>

        <main class="wadah">
            <section class="hero" aria-labelledby="judul">
                <div class="hero-teks">
                    <span class="lencana">
                        <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                        Khusus pengguna terdaftar
                    </span>
                    <h1 id="judul">Catat kas dan operasional dalam satu tempat</h1>
                    <p class="sub">Saldo selalu terkini, riwayat transaksi tersusun rapi, dan laporan siap diunduh kapan saja.</p>
                    <div class="aksi">
                        @if ($tujuan)
                            <a class="tombol tombol-gelap" href="{{ $tujuan }}">{{ $labelTujuan }} {!! $panah !!}</a>
                        @endif
                        <a class="tombol tombol-terang" href="#fitur">Lihat fitur</a>
                    </div>
                    @guest
                        <p class="catatan">Belum punya akun? Hubungi admin untuk mendapatkan akses.</p>
                    @endguest
                </div>

                <div class="pratinjau" role="img" aria-label="Contoh tampilan dashboard aplikasi">
                    <div class="p-kepala">Dashboard</div>
                    <div class="p-ubin">
                        <div class="ubin ubin-besar" style="background: #f1f8f5"><small>Total saldo</small><strong>Rp18.450.000</strong></div>
                        <div class="ubin" style="background: #e8f5f0"><small>Masuk</small><strong>Rp9.900.000</strong></div>
                        <div class="ubin" style="background: #fdf0e6"><small>Keluar</small><strong>Rp2.625.000</strong></div>
                    </div>
                    <div class="p-bawah">
                        <div class="panel">
                            <b>Arus kas</b>
                            <div class="batang-batang">
                                @foreach ($contohBatang as [$masuk, $keluar])
                                    <div class="kelompok">
                                        <span style="height: {{ $masuk }}%; background: #0d9488"></span>
                                        <span style="height: {{ $keluar }}%; background: #e0763a"></span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        <div class="panel">
                            <b>Saldo per akun</b>
                            <div class="akun"><small>Kas</small><strong>Rp12.450.000</strong><span class="trek"><span style="width: 67%"></span></span></div>
                            <div class="akun"><small>Operasional</small><strong>Rp6.000.000</strong><span class="trek"><span style="width: 33%"></span></span></div>
                        </div>
                    </div>
                </div>
            </section>

            <section id="fitur" class="fitur" aria-labelledby="judul-fitur">
                <div class="fitur-kepala">
                    <h2 id="judul-fitur">Semua yang dibutuhkan untuk mencatat keuangan</h2>
                    <p>Dibuat sederhana, supaya pencatatan harian tidak memakan waktu.</p>
                </div>
                <ul class="kartu-kartu">
                    @foreach ($fitur as [$warna, $judul, $isi, $ikon])
                        <li class="kartu {{ $warna }}">
                            <span class="bulat" aria-hidden="true"><svg class="ikon" viewBox="0 0 24 24">{!! $ikon !!}</svg></span>
                            <h3>{{ $judul }}</h3>
                            <p>{{ $isi }}</p>
                        </li>
                    @endforeach
                </ul>
            </section>

            @if ($tujuan)
                <section class="ajakan" aria-labelledby="judul-ajakan">
                    <h2 id="judul-ajakan">{{ $sudahMasuk ? 'Lanjutkan pencatatan' : 'Siap mencatat hari ini?' }}</h2>
                    <p>{{ $sudahMasuk ? 'Buka dashboard untuk melihat saldo terbaru.' : 'Masuk ke akun Anda untuk melihat saldo terbaru.' }}</p>
                    <a class="tombol tombol-gelap" href="{{ $tujuan }}">{{ $labelTujuan }} {!! $panah !!}</a>
                </section>
            @endif
        </main>

        <footer class="wadah kaki">
            <strong>{{ $namaAplikasi }}</strong>
            <span>© {{ now()->year }} {{ $namaAplikasi }}</span>
        </footer>
    </body>
</html>