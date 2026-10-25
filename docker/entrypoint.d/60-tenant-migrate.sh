#!/bin/sh
# Jalankan migrasi semua database tenant setiap container start, setelah migrasi
# central (50-laravel-automations). Paket gratis Render tidak punya Shell, jadi
# `php artisan tenant:migrate --all` tidak bisa dijalankan manual. Gagal di satu
# tenant tidak boleh menghentikan container.
echo "Menjalankan migrasi tenant..."
php /var/www/html/artisan tenant:migrate --all --no-interaction || echo "Migrasi tenant selesai dengan error (lihat log di atas)."
exit 0
