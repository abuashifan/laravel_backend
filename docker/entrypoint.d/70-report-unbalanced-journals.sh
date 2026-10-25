#!/bin/sh
# Laporan baca-saja jurnal posted yang tidak seimbang, tampil di log deploy
# Render (tidak ada Shell). Tidak mengubah data dan tidak boleh menggagalkan start.
echo "Memeriksa jurnal tidak seimbang..."
php /var/www/html/artisan journal:report-unbalanced --no-interaction || echo "Pemeriksaan jurnal selesai dengan error (lihat log di atas)."
exit 0
