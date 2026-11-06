FROM alpine:3.22

# - php83-pdo_pgsql: PDO-driveren index.php bruker mot PostgreSQL
# - clear_env = no: php-fpm sletter ellers alle miljøvariabler, og getenv('DB_HOST') o.l. blir false
RUN apk add --no-cache nginx supervisor php83 php83-fpm php83-pdo php83-pdo_pgsql \
 && sed -i 's/^;\?clear_env = .*/clear_env = no/' /etc/php83/php-fpm.d/www.conf \
 && mkdir -p /run/nginx /var/www/html

COPY src/ /var/www/html/
COPY nginx.conf       /etc/nginx/http.d/default.conf
COPY supervisord.conf /etc/supervisord.conf

EXPOSE 80

# supervisord kjører nginx og php-fpm i forgrunnen som to prosesser i samme container
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
