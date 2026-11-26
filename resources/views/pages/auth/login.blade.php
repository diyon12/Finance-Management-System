@php
    // Fortify mengembalikan galat login sebagai galat kolom email (pesan auth.failed).
    $gagalMasuk = $errors->first('email') === __('auth.failed');
    $galatEmail = $gagalMasuk ? null : $errors->first('email');
    $galatSandi = $errors->first('password');
    $beranda = Route::has('home') ? route('home') : url('/');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Masuk - {{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])

        <style>
            body.masuk {
                margin: 0; min-height: 100vh; background: #fbfaf7; color: #12302b; color-scheme: light;
                font-family: "Instrument Sans", ui-sans-serif, system-ui, sans-serif; line-height: 1.5;
                -webkit-font-smoothing: antialiased;
            }
            .masuk *, .masuk *::before, .masuk *::after { box-sizing: border-box; }
            .masuk :focus-visible { outline: 2px solid #0f766e; outline-offset: 2px; }
            .masuk .ikon { width: 1.25rem; height: 1.25rem; flex-shrink: 0; fill: none; stroke: currentColor;
                stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
            .masuk .ikon[hidden] { display: none; }

            .masuk-halaman { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1.5rem; }
            .masuk-kolom { width: 100%; max-width: 26.25rem; display: flex; flex-direction: column; align-items: center; gap: 1.5rem; }
            .masuk-merek { display: inline-flex; align-items: center; gap: .625rem; color: #12302b; font-size: 1.0625rem;
                font-weight: 700; text-decoration: none; }
            .masuk-logo { display: grid; place-items: center; width: 2.5rem; height: 2.5rem; border-radius: 50%;
                background: #cdebe2; color: #0f4c47; }

            .masuk-kartu { width: 100%; padding: 2.5rem 2.25rem; border-radius: 1.75rem; background: #fff;
                box-shadow: 0 1px 2px rgb(15 76 71 / 4%), 0 20px 48px rgb(15 76 71 / 8%);
                display: flex; flex-direction: column; gap: 1.5rem; }
            .masuk-kartu h1 { margin: 0; font-size: 1.625rem; line-height: 1.25; font-weight: 800; letter-spacing: -.02em; }
            .masuk-pesan { display: flex; align-items: flex-start; gap: .625rem; padding: .75rem .875rem;
                border-radius: .75rem; font-size: .875rem; font-weight: 500; }
            .masuk-pesan .ikon { width: 1.125rem; height: 1.125rem; margin-top: 1px; }
            .masuk-pesan-galat { background: #fdeee7; color: #8a3412; }
            .masuk-pesan-info { background: #e3f3ee; color: #0f4c47; }

            .masuk-form { display: flex; flex-direction: column; gap: 1.125rem; }
            .masuk-isian { display: flex; flex-direction: column; gap: .5rem; }
            .masuk-isian label { font-size: .875rem; font-weight: 600; }
            .masuk-isian-atas { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; }
            .masuk-tautan { color: #0f4c47; font-size: .8125rem; font-weight: 600; text-decoration: none; }
            .masuk-tautan:hover { color: #0b3a36; text-decoration: underline; }
            .masuk-input { width: 100%; height: 3rem; padding: 0 .875rem; border: 1px solid #d5dedb; border-radius: .75rem;
                background: #fff; color: #12302b; font: inherit; font-size: .9375rem; }
            .masuk-input::placeholder { color: #7d8b88; }
            .masuk-input:focus { outline: none; border-color: #0f766e; box-shadow: 0 0 0 3px #cdebe2; }
            .masuk-input[aria-invalid="true"] { border-color: #b3471b; }
            .masuk-sandi { position: relative; }
            .masuk-sandi .masuk-input { padding-right: 3.25rem; }
            .masuk-mata { position: absolute; top: 2px; right: 2px; display: grid; place-items: center; width: 2.75rem; height: 2.75rem;
                border: 0; border-radius: .625rem; background: transparent; color: #4b5b58; cursor: pointer; }
            .masuk-mata:hover { background: #f1f6f5; }
            .masuk-galat { margin: 0; font-size: .8125rem; color: #b3471b; }
            .masuk-ingat { display: flex; align-items: center; gap: .625rem; min-height: 1.5rem; font-size: .875rem;
                color: #3e5551; cursor: pointer; }
            .masuk-ingat input { width: 1.125rem; height: 1.125rem; margin: 0; accent-color: #0f4c47; cursor: pointer; }
            .masuk-tombol { height: 3.25rem; border: 0; border-radius: 999px; background: #0f4c47; color: #fff;
                font: inherit; font-size: 1rem; font-weight: 700; cursor: pointer; transition: background-color .15s; }
            .masuk-tombol:hover { background: #0b3a36; }
            .masuk-catatan { margin: 0; font-size: .8125rem; color: #4b5b58; text-align: center; }

            @media (max-width: 30rem) {
                .masuk-kartu { padding: 2rem 1.5rem; border-radius: 1.5rem; }
            }
        </style>
    </head>
    <body class="masuk">
        <div class="masuk-halaman">
            <div class="masuk-kolom">
                <main class="masuk-kartu">
                    <div>
                        <h1 style="text-align: center;">Finance Manage</h1>
                        <p class="text-center pt-2">Gunakan email dan kata sandi yang diberikan admin.</p>
                    </div>

                    @if (session('status'))
                        <div class="masuk-pesan masuk-pesan-info" role="status">{{ session('status') }}</div>
                    @endif

                    @if ($gagalMasuk)
                        <div class="masuk-pesan masuk-pesan-galat" role="alert">
                            <svg class="ikon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5"/></svg>
                            Email atau kata sandi salah. Periksa lagi lalu coba masuk kembali.
                        </div>
                    @endif

                    <x-passkey-verify />

                    <form class="masuk-form" method="POST" action="{{ route('login.store') }}">
                        @csrf

                        <div class="masuk-isian">
                            <label for="email">Email</label>
                            <input class="masuk-input" id="email" name="email" type="email" value="{{ old('email') }}"
                                   required autofocus autocomplete="email" placeholder="name@mail"
                                   @if ($gagalMasuk || $galatEmail) aria-invalid="true" @endif
                                   @if ($galatEmail) aria-describedby="email-galat" @endif>
                            @if ($galatEmail)
                                <p class="masuk-galat" id="email-galat">{{ $galatEmail }}</p>
                            @endif
                        </div>

                        <div class="masuk-isian">
                            <div class="masuk-isian-atas">
                                <label for="password">Kata sandi</label>
                                @if (Route::has('password.request'))
                                    <a class="masuk-tautan" href="{{ route('password.request') }}">Lupa kata sandi?</a>
                                @endif
                            </div>
                            <div class="masuk-sandi">
                                <input class="masuk-input" id="password" name="password" type="password"
                                       required autocomplete="current-password" placeholder="your password"
                                       @if ($gagalMasuk || $galatSandi) aria-invalid="true" @endif
                                       @if ($galatSandi) aria-describedby="password-galat" @endif>
                                <button class="masuk-mata" type="button" data-sandi-toggle aria-controls="password"
                                        aria-pressed="false" aria-label="Tampilkan kata sandi">
                                    <svg class="ikon ikon-buka" viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                    <svg class="ikon ikon-tutup" viewBox="0 0 24 24" aria-hidden="true" hidden><path d="M3 3l18 18"/><path d="M10.6 5.1A10.9 10.9 0 0 1 12 5c6.5 0 10 7 10 7a17.6 17.6 0 0 1-3.2 4.2M6.6 6.6C3.9 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>
                                </button>
                            </div>
                            @if ($galatSandi)
                                <p class="masuk-galat" id="password-galat">{{ $galatSandi }}</p>
                            @endif
                        </div>

                        <label class="masuk-ingat">
                            <input type="checkbox" name="remember" @checked(old('remember'))>
                            Ingat saya di perangkat ini
                        </label>

                        <button class="masuk-tombol" type="submit" data-test="login-button">Masuk</button>
                    </form>
                </main>

                <p class="masuk-catatan">Belum punya akun? Hubungi admin untuk mendapatkan akses.</p>
            </div>
        </div>

        <script>
            document.querySelectorAll('[data-sandi-toggle]').forEach((tombol) => {
                const input = document.getElementById(tombol.getAttribute('aria-controls'));
                tombol.addEventListener('click', () => {
                    const tampil = input.type === 'password';
                    input.type = tampil ? 'text' : 'password';
                    tombol.setAttribute('aria-pressed', String(tampil));
                    tombol.setAttribute('aria-label', tampil ? 'Sembunyikan kata sandi' : 'Tampilkan kata sandi');
                    tombol.querySelector('.ikon-buka').hidden = tampil;
                    tombol.querySelector('.ikon-tutup').hidden = ! tampil;
                });
            });
        </script>

        @fluxScripts
    </body>
</html>