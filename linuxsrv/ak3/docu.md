# Arbeidskrav 3 Linux serverdrift -- Tjenestemiljø

## Planlagt oppsett

![](assets/arkitekturtegning.png)

Ansible host/administrasjonsserver kjører på ubuntu host dantan04-ubuntu (VMid: 802, IP addr: 172.16.45.10), og administrerer 4 servere:
- HAproxy (LB (HAProxy), Distro: Ubuntu)
- web01 (web server (NGINX + PHP), Distro: Ubuntu)
- web02 (web server (NGINX + PHP), Distro: Ubuntu)
- DB01 (DB (PostgreSQL), Distro: Ubuntu)

## Administrasionsserver

![](assets/ansiblehost.png)

Bruker fortsatt VM dantan04-ubuntu (ID: 802, ip addr: 172.16.45.10) som ansible host, samme som AK2. \
Brukernavn: dantan \
Passord: Passord123 \
IP addr: 172.16.45.10  -- Kanskje flytte bak pfsense?

Ansible root dir for dette arbeidskravet: /home/dantan/ansible-ak3

Husk å legge til litt om grunn oppsett til ansible (Hent fra da jeg satt opp i ak2)

## Web01

![](assets/web01.png)

web01 server -- ene web serverene i webserver cluster.
Brukernavn: dantan \
Passord: Passord123 \
IP addr: 172.16.45.50 -- Kanskje flytte bak pfsense?

Web server stack (installeres, konfigureres, og styres av ansible via administrasjonsserver)
- PHP
- NGINX

