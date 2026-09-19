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


## Web02

![](assets/web02.png)
(Klonet web01 etter ubuntuinstallasjon, så hostname står som web01 i bildet over. Men er nå endret til web02)
web02 server -- ene web serverene i webserver cluster.
Brukernavn: dantan \
Passord: Passord123 \
IP addr: 172.16.45.51 -- Kanskje flytte bak pfsense?

Web server stack (installeres, konfigureres, og styres av ansible via administrasjonsserver)
- PHP
- NGINX

## Sette opp dedikert ansible bruker på target servere (gjentas på alle servere, unntatt administrasjonsserver)

TODO: Sette opp ansible bruker på alle ansible klientene:
- [ ] web01
- [X] web02
- [ ] db01
- [ ] lb01

### Opprette ansible bruker

![](assets/adduseransible.png)

Brukernavn: ansible
Passord: Passord123

### Gi sudo tilgang uten passord 
- Kjører sudo visudo
- Legger til ```ansible ALL=(ALL) NOPASSWD: ALL``` i text filen som kommer opp

Tester at dette virker ved å bytte til ansible brukeren, og kjøre ```sudo whoami``` 
![](assets/nopasswdsudotest.png)
Får kjørt sudo kommandoer uten passord. Alt ok!

### Passwordless login

Setter opp passwordless login, med RSA key, for å forhindre uautorisert innlogging ved passordlekkasje.

TODO: Når ansible bruker er satt opp med nopasswd sudo på alle servere. Aktiver RSA key innlogging med følgende kommandoer:
- ```# 1. Generate the key on the admin server (if you haven't already)
    ssh-keygen -t rsa -b 4096 -C "admin-server"

    # 2. Push the key to all 4 servers in one go
    for server in server1_ip server2_ip server3_ip server4_ip; do
        ssh-copy-id user@$server
    done
    ```
TODO: Deaktiver passord autentisering med følgende kommandoer (Kanskje ikke gjøre?:
- ```for server in server1_ip server2_ip server3_ip server4_ip; do
        echo "Configuring $server..."
        ssh user@$server "sudo sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config && sudo systemctl restart sshd"
    done ```
