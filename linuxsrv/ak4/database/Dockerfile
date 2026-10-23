FROM ubuntu:latest

ENV DEBIAN_FRONTEND=noninteractive

# Install Nginx
RUN apt-get update && \
    apt-get install -y mariadb-server && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/*

EXPOSE 3306

CMD ["mariadb", "-g", "daemon off;"]
