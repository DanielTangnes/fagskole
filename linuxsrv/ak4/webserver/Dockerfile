# syntax=docker/dockerfile:1
ARG REPO_URL=https://github.com/DanielTangnes/23ITDNETT-AK3-LINUX-WEBSITE
ARG REPO_REF=main

# --- stage 1: fetch source (git never ships in the final image) ---
FROM alpine/git:latest AS source
ARG REPO_URL
ARG REPO_REF
WORKDIR /src
RUN git clone --depth 1 --branch "${REPO_REF}" "${REPO_URL}" app \
 && rm -rf app/.git

# --- stage 2: runtime ---
FROM php:8.3-fpm-alpine
RUN apk add --no-cache nginx supervisor \
 && mkdir -p /run/nginx

COPY --from=source --chown=www-data:www-data /src/app /var/www/html
COPY nginx.conf        /etc/nginx/http.d/default.conf
COPY supervisord.conf  /etc/supervisord.conf

EXPOSE 80
CMD ["supervisord", "-c", "/etc/supervisord.conf"]
