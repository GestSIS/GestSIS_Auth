#!/bin/sh
/opt/alt/php85/usr/bin/php /home/sishs/auth.gestsis.ch/artisan users:sync-sapeurs
/opt/alt/php85/usr/bin/php /home/sishs/auth.gestsis.ch/artisan users:process-deactivation
