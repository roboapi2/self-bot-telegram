#!/usr/bin/env sh
set -eu
umask 077
case "${1:-web}" in
    namespace) exec sleep infinity ;;
    init) mkdir -p /opt/app/.panel/sessions /opt/app/.panel/throttle; php /opt/app/scripts/migrate-storage.php; exec php /opt/app/console.php doctor ;;
    supervisor) php /opt/app/scripts/migrate-storage.php; php /opt/app/console.php doctor; exec php /opt/app/supervisor.php ;;
    web) exec php-fpm -F ;;
    nginx) exec nginx -g 'daemon off;' ;;
    *) exec "$@" ;;
esac
