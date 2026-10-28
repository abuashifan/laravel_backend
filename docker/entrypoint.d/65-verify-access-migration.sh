#!/bin/sh
# Diagnostic only: a mismatch is logged without preventing the app from starting.
echo "Memeriksa migrasi hak akses..."
php /var/www/html/artisan access:verify-migration --no-interaction || echo "Pemeriksaan hak akses menemukan perbedaan (lihat log di atas)."
exit 0
