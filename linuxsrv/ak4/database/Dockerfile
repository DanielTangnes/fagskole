FROM debian:13-slim

ENV PGDATA=/var/lib/postgresql/data

RUN mkdir -p /etc/postgresql-common/createcluster.d \
 && echo "create_main_cluster = false" > /etc/postgresql-common/createcluster.d/no-main-cluster.conf \
 && apt-get update \
 && apt-get install -y --no-install-recommends postgresql \
 && rm -rf /var/lib/apt/lists/* \
 && ln -s /usr/lib/postgresql/*/bin/* /usr/local/bin/ \
 && mkdir -p "$PGDATA" /var/run/postgresql \
 && chown -R postgres:postgres /var/lib/postgresql /var/run/postgresql \
 && chmod 700 "$PGDATA"

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
COPY init/ /docker-init/

# Fjern eventuelle Windows-linjeskift (CRLF) og gjør scriptet kjørbart
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh \
 && chmod 755 /usr/local/bin/entrypoint.sh

USER postgres

EXPOSE 5432
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
