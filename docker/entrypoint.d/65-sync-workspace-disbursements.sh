#!/bin/sh
# Retry pending drafts after central and tenant migrations.
php /var/www/html/artisan workspace:sync-disbursements --no-interaction || echo "Sinkronisasi draf Workspace tertunda."
exit 0
