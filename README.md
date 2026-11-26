# Finance Management System

Sistem pencatatan keuangan untuk mengelola saldo **Kas** dan **Operasional** beserta riwayat transaksinya. Dibuat dengan Laravel dan Livewire.

## Kegunaan

- **Mencatat pemasukan dan pengeluaran** untuk akun Kas dan Operasional. Saldo dihitung otomatis dari semua transaksi, jadi tidak perlu diketik manual.
- **Riwayat transaksi dengan sisa saldo** di setiap baris, lengkap dengan pencarian, filter jenis (kredit/debit) dan waktu, serta ekspor ke CSV yang bisa dibuka di Excel.
- **Kas per periode.** Satu periode Kas berjalan sampai uangnya habis atau tinggal sedikit. Saat periode baru dimulai, periode lama ditutup dan transaksinya dikunci, lalu sisa saldonya otomatis menjadi saldo awal periode berikutnya. Aplikasi memberi pengingat saat saldo Kas tinggal Rp5.000 atau kurang.
- **Dashboard** berisi total saldo, uang masuk dan keluar bulan ini, grafik arus kas 6 bulan terakhir, dan saldo per akun.


## Akun login

Akun login dapat dilihat di file `database/seeders/DatabaseSeeder.php`:



> **Penting:** akun ini hanya untuk percobaan di komputer sendiri. Segera ganti kata sandinya setelah login pertama, dan jangan memakai akun ini di server yang bisa diakses orang lain.
