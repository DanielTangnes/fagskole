# AK3 – Tjenestemiljø for Fagskolen Industrier

Sep 25, 2026 · @Daniel

## 1. Sammendrag

Fagskolen Industrier har fått et driftsmiljø med én lastbalanserer (HAProxy), to webservere (nginx + PHP-FPM) og én databaseserver (PostgreSQL 17). Hele miljøet styres fra én administrasjonsserver med Ansible. Alle servere kjører Ubuntu LTS.

Oppsettet bygger på risikoanalysen i kapittel 2. De viktigste valgene er disse:

- **To webnoder bak en lastbalanserer.** Nettsiden er oppe selv om én webserver går ned, og det er lett å legge til flere.
- **Rullerende deploy.** Ny kode rulles ut én node om gangen uten nedetid (`deploy-website.yml`).
- **Sentralisert administrasjon.** All konfigurasjon ligger i Ansible-roller (`db`, `web`, `lb`), så en server kan bygges på nytt med én kommando.
- **Sikker tilkobling.** Administrasjonsserveren kobler til med en egen `ansible`-bruker og SSH-nøkkel. Nøkkelen er låst til administrasjonsserverens IP, og brukeren har ikke passordinnlogging.
- **Databasen er sikret.** Den godtar bare applikasjonsbrukeren fra webserverne, med `scram-sha-256`.

**Status**

| Del | Status |
| --- | --- |
| Ansible-roller for DB, web og LB | Ferdig og testet i lokalt miljø |
| SSH-oppsett mot administrasjonsserver | Ferdig i lab |
| Rullerende deploy | Ferdig |
| Backup | TODO: ikke implementert (se kapittel 11 og 13) |
| Herding av operativsystemet (`common`-rollen) | TODO: rollen er tom |
| Pentest (red team) | Gjennomføres senere |
| Presentasjon | Lages senere |

Rollene er utviklet og testet i et lokalt multipass-miljø (10.188.38.0/24) med enklere SSH-regler. Målmiljøet er labben på 172.16.45.0/24. Feil og mangler er merket med `TODO:` gjennom hele rapporten og samlet i kapittel 13.

## 2. Risikoanalyse

Risikoanalysen ble gjort før oppsettet startet og er grunnlaget for arkitekturen og sikkerhetsvalgene. Analysen fant 17 risikoer. 11 av dem var høye (15–25) før tiltak. Kolonnen «Gjennomført i» viser hvor tiltaket ligger i Ansible-koden. Tiltak som ikke er gjort ennå, er merket med `TODO:`.

### 2.1 Metode

Risiko = sannsynlighet (S) × konsekvens (K), begge på en skala fra 1 til 5.

| Verdi | Sannsynlighet (S) | Konsekvens (K) |
| --- | --- | --- |
| 1 | Svært usannsynlig, sjeldnere enn hvert 5. år | Ubetydelig, ingen merkbar effekt |
| 2 | Lite sannsynlig, hvert 2.–5. år | Liten, kort treghet eller én feilet forespørsel |
| 3 | Mulig, omtrent årlig | Moderat, nedetid under 1 time |
| 4 | Sannsynlig, flere ganger i året | Alvorlig, nedetid i timer eller lekkasje av data |
| 5 | Svært sannsynlig, månedlig | Kritisk, varig datatap eller full kompromittering |

Risikonivå: **1–6 lav** (akseptabel), **8–12 middels** (bør reduseres), **15–25 høy** (må reduseres før produksjon).

### 2.2 Verdier som skal beskyttes

- **Nettsiden:** tilgjengelighet for brukerne.
- **Databasen:** meldingene i tabellen `messages` (integritet og konfidensialitet).
- **Administrasjonsserveren:** den har root-tilgang til alle andre servere gjennom Ansible.
- **Hemmeligheter:** SSH-nøkkelen `ansible_rsa`, databasepassordet og passordet til HAProxy-statistikksiden.
- **Konfigurasjonen:** Ansible-koden er «fasiten» for hvordan miljøet skal se ut.

### 2.3 Risikotabell

| ID | Trussel | S | K | Risiko | Tiltak | Gjennomført i | Rest (S×K) |
| --- | --- | --- | --- | --- | --- | --- | --- |
| R1 | Webnode går ned (VM, OS, nginx eller PHP-FPM) | 4 | 4 | 16 | To webnoder bak HAProxy. Helsesjekk mot `/health`, som tester nginx og PHP-FPM. `option redispatch` sender forespørselen videre til en frisk node. | `site.yml`, `roles/lb/templates/haproxy.cfg.j2`, `roles/web/tasks/main.yml` | 4×1 = 4 |
| R2 | Lastbalanserer går ned | 3 | 5 | 15 | Konfigurasjonen valideres før den tas i bruk (`haproxy -c`). LB kan bygges på nytt med én Ansible-kjøring. TODO: to LB-er med keepalived og virtuell IP. | `roles/lb/tasks/main.yml` | 3×4 = 12 |
| R3 | Databaseserveren går ned | 3 | 5 | 15 | Idempotent `db`-rolle som bygger serveren på nytt. Skjemaet bruker `IF NOT EXISTS`. TODO: strømmereplikering til en standby-server. | `roles/db/tasks/main.yml`, `roles/db/files/schema.sql` | 3×4 = 12 |
| R4 | Datatap (sletting, korrupt disk, ransomware) | 3 | 5 | 15 | Daglig `pg_dump` styrt av Ansible, rotasjon og kopi utenfor serveren. Månedlig test av gjenoppretting. TODO: ikke implementert. | – | 3×5 = 15 |
| R5 | Feil i ny kode tar ned nettsiden | 4 | 4 | 16 | Rullerende deploy med `serial: 1`. Noden tappes (drain) før den oppdateres og røyktestes før den settes tilbake. Utrullingen stopper ved første feil (`any_errors_fatal`). Siste friske node tappes aldri. | `deploy-website.yml` | 4×1 = 4 |
| R6 | Feilkonfigurasjon ved manuelle endringer | 4 | 4 | 16 | All konfigurasjon ligger i idempotente Ansible-roller. `nginx -t` og `haproxy -c` kjøres før reload, og verifiseringssteg kjøres til slutt i hver rolle. | Alle roller | 2×3 = 6 |
| R7 | Uautorisert SSH-tilgang til serverne | 3 | 5 | 15 | Egen `ansible`-bruker med RSA 4096-nøkkel, `from="172.16.45.10"` og `exclusive`. Passordet er låst, og `host_key_checking = True`. TODO: herde `sshd_config` for alle brukere og innføre fail2ban. | Kapittel 5, `ansible.cfg` | 2×5 = 10 |
| R8 | Uautorisert tilgang til databasen | 3 | 5 | 15 | PostgreSQL lytter bare på privat IP. `pg_hba` slipper bare inn `testside_app` til `testside` fra webnodene, med `scram-sha-256`. UFW-regel per klient. TODO: UFW blir ikke aktivert. | `roles/db/tasks/main.yml`, `roles/db/defaults/main.yml` | 2×5 = 10 |
| R9 | Administrasjonsserveren blir kompromittert | 2 | 5 | 10 | Bare én kontrollnode, og nøkkelen ligger bare der. TODO: passphrase på nøkkelen, begrense `NOPASSWD: ALL` og flytte serveren bak pfSense. | Kapittel 5 | 2×5 = 10 |
| R10 | Passordlekkasje eller svake passord | 4 | 4 | 16 | Nøkkel i stedet for passord for `ansible`. `no_log` på oppgaver med hemmeligheter. TODO: ansible-vault og sterke, unike passord på `dantan`-kontoene. | `group_vars/all.yml`, `roles/db/tasks/main.yml` | 3×4 = 12 |
| R11 | Angrep mot webapplikasjonen (SQLi, XSS, filopplasting) | 4 | 4 | 16 | Koden eies av root, så PHP kan ikke skrive om den. Dotfiler (`.git`, `.env`) blokkeres, og sikkerhetsheadere er satt. `expose_php = Off` og `cgi.fix_pathinfo = 0`. DB-brukeren har bare tilgang til sin egen database. Appen bruker prepared statements og htmlspecialchars() (kapittel 7). | `roles/web/templates/*.j2`, `group_vars/web.yml` | 2×4 = 8 |
| R12 | Brute force mot innlogging (SSH, statistikkside) | 4 | 3 | 12 | Passordet til `ansible` er låst, og statistikksiden krever innlogging. TODO: fail2ban, og begrense statistikksiden (`*:8404`) til administrasjonsnettet. | `group_vars/lb.yml` | 3×3 = 9 |
| R13 | Upatchede sårbarheter | 4 | 4 | 16 | Ubuntu LTS og pakker fra offisielle repoer (PGDG for PostgreSQL). TODO: unattended-upgrades i `common` og en månedlig patcherutine (kapittel 11). | `roles/*/tasks/main.yml` | 3×4 = 12 |
| R14 | Overbelastning ved mange brukere, eller DoS | 3 | 3 | 9 | Roundrobin-balansering, `slowstart 10s` og `timeout http-request 10s`. Horisontal skalering ved å legge til webnoder (kapittel 8). | `group_vars/lb.yml` | 2×3 = 6 |
| R15 | Avlytting av trafikk i klartekst | 2 | 4 | 8 | Trafikken går over et privat nett. TODO: TLS på HAProxy og `sslmode=require` mellom web og DB. | – | 2×4 = 8 |
| R16 | Hemmeligheter havner i git-repoet | 3 | 4 | 12 | DB-påloggingen ligger ikke i app-repoet. Den leveres som miljøvariabler i PHP-FPM (fil med modus `0640`). TODO: vault i Ansible-repoet. | `roles/web/templates/php-fpm-db-env.conf.j2` | 3×3 = 9 |
| R17 | Menneskelig feil eller manglende kunnskapsoverføring | 3 | 3 | 9 | Alt er gjort i Ansible, med kommentarer i koden, denne rapporten og driftshåndboken (kapittel 10). | Hele repoet | 2×2 = 4 |

### 2.4 Risikomatrise før tiltak

| S \\ K | 1 | 2 | 3 | 4 | 5 |
| --- | --- | --- | --- | --- | --- |
| **5** |  |  |  |  |  |
| **4** |  |  | R12 | R1, R5, R6, R10, R11, R13 |  |
| **3** |  |  | R14, R17 | R16 | R2, R3, R4, R7, R8 |
| **2** |  |  |  | R15 | R9 |
| **1** |  |  |  |  |  |

**Restrisiko:** Tiltakene flytter R1, R5, R6, R14 og R17 til lav risiko. R4 (datatap) er fortsatt høy fordi backup ikke er implementert, og det er det viktigste punktet som gjenstår. R2 og R3 er middels fordi LB og DB fortsatt er enkeltpunkter for feil (single points of failure).

## 3. Arkitektur

Miljøet består av fem Ubuntu-servere på VLAN 172.16.45.0/24. Brukerne når bare lastbalansereren. Webnodene er de eneste som snakker med databasen, og all administrasjon går fra administrasjonsserveren.

&#91;embedded content: arkitektur · 4 styrte servere + administrasjonsserver\]

Den stiplede boksen er serverne Ansible styrer. De heltrukne pilene er brukertrafikk, og den stiplede pilen er SSH fra administrasjonsserveren.

| Server | Rolle | Lab-IP | Lokal test-IP | OS | Tjenester |
| --- | --- | --- | --- | --- | --- |
| dantan04-ubuntu (VM 802) | Administrasjonsserver | 172.16.45.10 | Vertsmaskin (10.188.38.1) | Ubuntu | Ansible (ansible-core 2.20) |
| lb01 | Lastbalanserer | 172.16.45.52 | 10.188.38.18 | Ubuntu 26.04 LTS | HAProxy (:80, statistikk :8404) |
| web01 | Webserver | 172.16.45.50 | 10.188.38.32 | Ubuntu 26.04 LTS | nginx, PHP-FPM |
| web02 | Webserver | 172.16.45.51 | 10.188.38.60 | Ubuntu 26.04 LTS | nginx, PHP-FPM |
| db01 | Database | 172.16.45.53 | 10.188.38.225 | Ubuntu 26.04 LTS | PostgreSQL 17 (:5432) |

**Hvorfor Ubuntu LTS:** Oppgaven krever oppdaterte systemer med langtidsstøtte. Ubuntu 26.04 LTS får sikkerhetsoppdateringer i fem år. Alle rollene sjekker at målet er Ubuntu før de gjør noe.

**Hvorfor fire servere og ikke tre:** Oppgaven ber om webserver, database og administrasjonsserver. Kravet om minimal nedetid og skalering (R1 og R14 i risikoanalysen) gjorde at vi la til en ekstra webnode og en lastbalanserer.

**Lokalt testmiljø:** Rollene er utviklet og testet i multipass-VM-er på 10.188.38.0/24. Der kobler Ansible til med enklere SSH-regler enn i labben. Den eneste forskjellen i koden er IP-adressene i `hosts` og `db_extra_clients`.

TODO: Serverne ligger i dag direkte på VLAN-et. Bør administrasjonsserveren og databasen flyttes bak pfSense, slik at bare LB01 er nåbar fra brukernettet?

TODO: Oppdater `hosts` med lab-IP-ene før første kjøring i labben.

## 4. Administrasjonsserver og Ansible-grunnoppsett

All drift skjer fra administrasjonsserveren dantan04-ubuntu (VM 802, 172.16.45.10). Det er samme VM som i AK2. Ansible-prosjektet ligger i `/home/dantan/ansible-ak3`, og ingen konfigurasjon gjøres for hånd på de styrte serverne.

&#91;Bilde: assets/ansiblehost.png\]

TODO: Legg til grunnoppsettet av Ansible fra AK2 (installasjon av ansible-core og oppsett av brukeren `dantan`).

### 4.1 ansible.cfg

```ini
[defaults]
inventory = hosts
roles_path = roles
remote_user = ansible
interpreter_python = auto_silent
host_key_checking = True
```

- `remote_user = ansible`: Ansible logger alltid inn som den dedikerte `ansible`-brukeren, aldri som en personlig konto (kapittel 5).
- `host_key_checking = True`: Ansible nekter å koble til hvis vertsnøkkelen til en server endrer seg. Det beskytter mot man-in-the-middle.

### 4.2 Inventory (`hosts`)

Serverne er delt inn i grupper etter rolle. Gruppene bestemmer både hvilken rolle som kjøres og hvem som får tilgang til hva. For eksempel bygger HAProxy-konfigurasjonen og `pg_hba.conf` seg selv ut fra gruppen `[web]`.

```ini
[web]
web01 ansible_host=10.188.38.32
web02 ansible_host=10.188.38.60

[db]
db01 ansible_host=10.188.38.225

[lb]
lb01 ansible_host=10.188.38.18
```

### 4.3 Collections

`requirements.yml` henter `community.general` (UFW og HAProxy-modulene) og `community.postgresql` (database, brukere og `pg_hba`). De installeres med `ansible-galaxy collection install -r requirements.yml`.

### 4.4 Roller og variabler

| Rolle | Kjøres på | Ansvar |
| --- | --- | --- |
| `db` | `[db]` | PostgreSQL 17, nettverkstilgang, applikasjonsbruker, database og skjema |
| `web` | `[web]` | nginx, PHP-FPM, helsesjekk, virtuell vert, første utsjekk av koden |
| `lb` | `[lb]` | HAProxy, helsesjekker og statistikkside |
| `common` | – | TODO: tom. Tenkt for felles herding av alle servere. |

Variablene ligger i `group_vars/`:

- `all.yml`: felles verdier, som backend-navnet i HAProxy og databasenavn, -bruker og -passord. DB-rollen og web-rollen leser samme variabler, så de kan aldri bli uenige.
- `web.yml`, `db.yml` og `lb.yml`: innstillinger for hver tjeneste.

### 4.5 site.yml – rekkefølgen betyr noe

`site.yml` bygger hele miljøet i tre plays: **db → web → lb**.

1. **Databasen først:** Web-rollen avslutter med å sjekke at den når DB01 på port 5432.
2. **Webnodene:** De må svare på `/health` før HAProxy slås på. En node uten helsesjekk blir ellers markert som nede.
3. **Lastbalansereren til slutt:** Rollen venter til minst én webnode er oppe, og skriver ut status for alle backend-serverne.

Alle plays kjører med `become: true`, altså som root via sudo på `ansible`-brukeren.

## 5. Sikker tilkobling fra administrasjonsserveren (SSH)

Administrasjonsserveren kobler til de fire serverne med SSH som brukeren `ansible`. Brukeren kan bare logge inn med én bestemt privatnøkkel, og bare fra 172.16.45.10. Et lekket passord gir derfor ikke tilgang til `ansible`-kontoen. En stjålet nøkkel virker heller ikke fra en annen maskin. Dette håndterer R7 og R10 i risikoanalysen.

### 5.1 Egen ansible-bruker på hver server

Brukeren ble opprettet på web01, web02, lb01 og db01. Ansible bruker den i stedet for de personlige kontoene. Da er det lett å skille automatiserte endringer fra manuelle i loggene, og tilgangen kan trekkes tilbake uten å røre administratorkontoene.

&#91;Bilde: assets/adduseransible.png\]

Ansible må kunne bli root uten å skrive passord. Derfor ble denne linjen lagt inn med `sudo visudo`:

```
ansible ALL=(ALL) NOPASSWD: ALL
```

Det ble testet ved å bytte til `ansible` og kjøre `sudo whoami`, som svarte `root` uten å spørre om passord.

&#91;Bilde: assets/nopasswdsudotest.png\]

TODO: Legg regelen i en egen fil, `/etc/sudoers.d/ansible`, i stedet for i `/etc/sudoers`. Da er den lettere å revidere og fjerne.

TODO: `NOPASSWD: ALL` betyr at den som har nøkkelen, har full root. Det er vanlig for Ansible, men det gjør administrasjonsserveren til det viktigste målet (R9).

### 5.2 Nøkkelpar og utrulling

Nøkkelparet ble laget på administrasjonsserveren:

```bash
ssh-keygen -t rsa -b 4096 -f ~/.ssh/ansible_rsa -C "ansible@mgmt"
```

&#91;Bilde: assets/sshkeygen2.png\]

Den offentlige nøkkelen ble kopiert til alle fire serverne i én løkke:

```bash
for server in web01 web02 lb01 db01; do
    ssh-copy-id -i ~/.ssh/ansible_rsa.pub ansible@$server
done
```

TODO: Sett `private_key_file = ~/.ssh/ansible_rsa` i `ansible.cfg`, eller `IdentityFile` i `~/.ssh/config`. Da blir det dokumentert hvilken nøkkel Ansible bruker.

TODO: Vurder passphrase på `ansible_rsa` med ssh-agent, og Ed25519 i stedet for RSA ved neste nøkkelrotasjon.

### 5.3 Låse nøkkelen til administrasjonsserveren og slå av passord

Etter første utrulling tar Ansible over styringen av nøkkelen. Playbooken under gjør tre ting:

1. `from="172.16.45.10"`: nøkkelen godtas bare fra administrasjonsserveren.
2. `exclusive: true`: alle andre nøkler i `authorized_keys` til `ansible` fjernes.
3. `password_lock: true`: passordet til `ansible` låses, så bare nøkkelinnlogging virker.

```yaml
---
- name: Authorizedkey
  hosts: all
  become: true
  tasks:
  - name: Authorize ansible key from management server
    ansible.posix.authorized_key:
      user: ansible
      key: "{{ lookup('file', '~/.ssh/ansible_rsa.pub') }}"
      key_options: 'from="172.16.45.10"'  # IP-adressen til administrasjonsserveren
      exclusive: true

  - name: Disable password login on ansible account by locking the password
    ansible.builtin.user:
      user: ansible
      state: present
      password_lock: true
```

&#91;Bilde: assets/authorizedkeyrun.png\]

Resultatet ble verifisert ved å logge inn på web01 med `ansible_rsa` og sjekke at `~/.ssh/authorized_keys` nå starter med `from="172.16.45.10"`.

&#91;Bilde: assets/authorizedkeytest.png\]

TODO: Playbooken bruker `ansible.posix`, men den collectionen står ikke i `requirements.yml`. Playbooken ligger heller ikke i repoet med rollene.

### 5.4 Hvorfor ett nøkkelpar for alle fire serverne

Fire nøkkelpar ville gitt mer arbeid, men ikke bedre sikkerhet. Alle fire privatnøklene ville ligget i samme mappe på administrasjonsserveren og vært lesbare for samme konto. Den som kan lese én, kan lese alle. Sikkerheten ligger derfor i å beskytte administrasjonsserveren og i `from=`-begrensningen.

### 5.5 Hva som gjenstår for SSH

- TODO: `sshd_config` er ikke herdet. `PasswordAuthentication` er fortsatt på for de personlige kontoene (`dantan`), og de har svake passord. `PermitRootLogin no`, `PasswordAuthentication no` og `AllowUsers` bør settes av `common`-rollen.
- TODO: Ingen fail2ban eller rate-begrensning på port 22.
- TODO: UFW bør bare tillate port 22 fra 172.16.45.10 (se kapittel 13).

## 6. Tjenestene

Hver tjeneste har sin egen Ansible-rolle. Rollene er idempotente, så de kan kjøres om igjen når som helst uten å ødelegge noe. Hver rolle slutter med egne verifiseringssteg, så en kjøring som går grønt betyr at tjenesten faktisk virker.

### 6.1 Database – `roles/db` (DB01)

&#91;Bilde: assets/db01.png\]

| Steg | Hva og hvorfor |
| --- | --- |
| Installasjon | PostgreSQL 17 fra det offisielle PGDG-repoet, låst til versjon med `postgres_version: 17`. `python3-psycopg2` trengs for Ansible-modulene, og `acl` for `become_user: postgres`. |
| Cluster | `pg_createcluster` lager `main`-clusteret hvis det mangler. Tjenesten `postgresql@17-main` startes og settes til å starte ved oppstart. |
| Nettverk | `listen_addresses = 'localhost,<privat IP>'`. Databasen lytter ikke på andre grensesnitt. |
| Tilgang (`pg_hba.conf`) | En linje per webnode: `host testside testside_app <IP>/32 scram-sha-256`. Bare applikasjonsbrukeren får logge inn, bare til sin egen database og bare fra webserverne. Listen bygges automatisk fra gruppen `[web]`. |
| Brannmur | UFW tillater 5432/tcp bare fra de samme adressene. TODO: UFW blir ikke slått på (kapittel 13). |
| Bruker og database | `testside_app` blir opprettet (passordet vises ikke i loggen, `no_log`) og eier databasen `testside`. Den får dermed lage tabeller uten ekstra GRANT, men er ikke superbruker. |
| Skjema | `schema.sql` kopieres og kjøres som applikasjonsbrukeren. Den bruker bare `CREATE TABLE IF NOT EXISTS`, så den er trygg å kjøre hver gang. |
| Verifisering | `wait_for` sjekker at 5432 svarer på privat IP, og `postgresql_ping` sjekker at `testside_app` kan logge inn. |

Handlere: En endring i `listen_addresses` gir restart, fordi PostgreSQL krever det. En endring i `pg_hba` gir bare reload. `flush_handlers` kjører før brukeren opprettes, slik at nettverksoppsettet er aktivt først.

TODO: `db_extra_clients: 10.188.38.1/32` gir vertsmaskinen i det lokale miljøet tilgang for pgAdmin. Den må fjernes eller byttes ut i labben.

### 6.2 Webservere – `roles/web` (web01, web02)

&#91;Bilde: assets/web01.png\] \[Bilde: assets/web02.png\]

| Steg | Hva og hvorfor |
| --- | --- |
| Pakker | nginx og PHP-FPM fra Ubuntus egne repoer, med utvidelser (bl.a. `pgsql`, `mbstring`, `intl`). PHP-versjonen finnes automatisk (`detect-php-version.yml`), så rollen virker på tvers av Ubuntu-versjoner. |
| PHP-innstillinger | `99-app.ini`: `expose_php = Off`, `cgi.fix_pathinfo = 0`, minnegrense, opplastingsgrense og opcache. `opcache.validate_timestamps = 0` gir ytelse, og ny kode lastes ved reload av PHP-FPM. |
| DB-tilkobling | `zz-db-env.conf` (modus `0640`) gir PHP-FPM variablene `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER` og `DB_PASS`. Appen leser dem med `getenv()`, så passordet ligger aldri i app-repoet. |
| Helsesjekk | `/health` kjører et lite PHP-skript i `/var/www/_health`, utenfor repoet. Et svar betyr at både nginx og PHP-FPM virker, uansett hvilken kode som er rullet ut. Helsesjekken spør med vilje ikke databasen. Ellers ville et DB-avbrudd tatt ned begge webnodene samtidig. |
| Virtuell vert i nginx | Standardsiden fjernes. Sikkerhetsheadere: `X-Content-Type-Options`, `X-Frame-Options` og `Referrer-Policy`. Dotfiler som `.git` og `.env` blokkeres. |
| Koden | Web-roten opprettes. På en ny node sjekkes koden ut fra git. Filene eies av `root:www-data`, så en kompromittert PHP-prosess kan ikke skrive om applikasjonen. Eksisterende noder oppdateres bare av `deploy-website.yml`. |
| Brannmur | UFW-profilen «Nginx Full» (80 og 443). |
| Verifisering | `nginx -t`, `/health` svarer 200, og DB01:5432 kan nås. Til slutt settes noden tilbake i drift på alle lastbalanserere. |

TODO: Kommentaren i `roles/web/handlers/main.yml` sier at nginx ikke ser ut til å bli reloadet etter kjøring. Dette må undersøkes.

### 6.3 Lastbalanserer – `roles/lb` (LB01)

&#91;Bilde: assets/lb01.png\]

| Innstilling | Verdi | Hvorfor |
| --- | --- | --- |
| Balansering | `roundrobin` | Lik fordeling mellom like noder |
| Helsesjekk | `GET /health` hvert 2. sekund, forventer 200 | Oppdager en død node |
| `fall` / `rise` | 3 / 2 | Noden er nede etter cirka 6 sekunder og tilbake etter cirka 4 |
| `slowstart` | 10s | En node som kommer tilbake, får trafikk gradvis mens opcache varmes opp |
| `option redispatch` + `retries 3` | – | En forespørsel mot en node som nettopp døde, sendes til en annen |
| `timeout server` | 70s | Lengre enn `max_execution_time` i PHP (60s), så trege forespørsler ikke kuttes |
| `server-state-file` | `/var/lib/haproxy/server-state` | En node som er tappet, forblir tappet etter en reload av HAProxy |
| Statistikkside | `*:8404`, basic auth | Viser nodestatus under en deploy |

Konfigurasjonen valideres med `haproxy -c` før den skrives. Backend-listen bygges fra gruppen `[web]`. Reload-handleren lagrer først serverstatusen via admin-socketen `/run/haproxy/admin.sock`. Til slutt venter rollen til minst én webnode er oppe, og rapporterer status for hver node.

TODO: Statistikksiden er åpen for hele nettet og sender passordet over ren HTTP. Den bør begrenses til administrasjonsserveren.

TODO: Kommentarene i `roles/lb/tasks/main.yml` viser fortsatt til den gamle strukturen (`playbooks/lb/haproxy-setup.yml`).

## 7. Applikasjonen

Applikasjonen er en enkel meldingsside i PHP. Brukeren skriver navn og melding, og meldingen lagres i PostgreSQL. Siden viser de 20 nyeste meldingene. Koden ligger i [23ITDNETT-AK3-LINUX-WEBSITE](https://github.com/DanielTangnes/23ITDNETT-AK3-LINUX-WEBSITE) (`index.php` og `schema.sql`), og Ansible henter den med `git`.

**Datamodell:** Tabellen `messages` har kolonnene `id` (SERIAL), `author`, `body` og `created_at` (TIMESTAMPTZ). Samme `schema.sql` ligger i `roles/db/files/`, og DB-rollen kjører den.

**Hvordan appen får databasen:** `index.php` bygger PDO-tilkoblingen fra `getenv('DB_HOST')`, `DB_PORT`, `DB_NAME`, `DB_USER` og `DB_PASS`. Verdiene settes av web-rollen i PHP-FPM-poolen og kommer fra `group_vars/all.yml`. Repoet inneholder derfor ingen adresser eller passord. Samme kode kan kjøres i test og produksjon.

**Sikkerhet i koden:**

- `INSERT` bruker `prepare()` med navngitte parametere, så skjemafeltene kan ikke brukes til SQL-injeksjon.
- All utdata går gjennom `htmlspecialchars()`, noe som hindrer lagret XSS.
- Feilmeldinger fra databasen logges med `error_log()`. Brukeren ser bare «Could not connect to the database».
- Etter innsending blir brukeren sendt videre (Post/Redirect/Get), så en oppdatering av siden sender ikke skjemaet på nytt.

TODO: Skjemaet har ikke CSRF-token, lengdebegrensning eller rate-begrensning. Hvem som helst kan fylle databasen med meldinger (R14). Dette bør testes i pentesten.

TODO: `index.php` ligger i roten av repoet, så `README.md` og `schema.sql` kan hentes over HTTP. Flytt koden til en `public/`-mappe og sett `app_public_dir: public`.

## 8. Minimal nedetid og skalering

Nettsiden tåler at én webnode går ned, og ny kode kan rulles ut uten nedetid. LB01 og DB01 er fortsatt enkeltpunkter for feil (single points of failure). Uten backup kan heller ikke databasen gjenopprettes etter datatap.

### 8.1 Hva kan forårsake nedetid?

| Årsak | Eksempel | Risiko-ID |
| --- | --- | --- |
| Maskinvare- eller VM-feil | Proxmox-verten eller disken feiler, eller VM-en henger | R1, R2, R3 |
| Tjenestekrasj | PHP-FPM eller nginx stopper, eller PostgreSQL går tom for diskplass | R1, R3 |
| Dårlig deploy | Ny kode har en fatal feil | R5 |
| Feilkonfigurasjon | Syntaksfeil i `haproxy.cfg` eller nginx, eller feil i `pg_hba` | R6 |
| Oppdateringer og omstart | Kjerneoppdatering krever omstart | R13 |
| Angrep og overbelastning | DoS, eller en kompromittert server som må tas ned | R11, R14 |
| Datatap | Tabellen slettes eller disken blir korrupt | R4 |

### 8.2 Hva er gjort for å unngå nedetid?

- **Redundante webnoder:** Med to webnoder bak HAProxy går trafikken automatisk til den andre noden hvis én feiler. Det skjer etter cirka 6 sekunder (3 feilede sjekker med 2 sekunders mellomrom), og `redispatch` sender forespørsler som er i gang videre.
- **Validering før reload:** `haproxy -c` og `nginx -t` kjøres før en ny konfigurasjon tas i bruk, så en skrivefeil stopper Ansible og ikke tjenesten.
- **Reload i stedet for restart:** nginx, HAProxy og `pg_hba` lastes inn på nytt uten å kutte tilkoblinger. PostgreSQL restartes bare hvis `listen_addresses` endres.
- **Helsesjekken er uavhengig av databasen:** Et DB-avbrudd tar ikke begge webnodene ut av HAProxy samtidig.
- **Rullerende deploy:** se 8.4.

### 8.3 Hvordan minimere nedetid når den skjer?

Alt er kode, så en ødelagt server erstattes i stedet for å repareres:

1. Lag en ny Ubuntu 26.04 LTS-VM med samme hostname og IP (eller oppdater `hosts`).
2. Legg til `ansible`-brukeren og nøkkelen (kapittel 5).
3. Kjør `ansible-playbook site.yml --limit <server>`. For en webnode kjøres i tillegg `--limit <server>,lb` slik at HAProxy tar den inn igjen.

En webnode gjenoppbygges på minutter mens den andre noden betjener brukerne. For DB01 gjenoppretter `site.yml` tjenesten og skjemaet, men ikke dataene.

TODO: Uten backup (R4) går alle meldingene tapt hvis DB01 må bygges på nytt. Backup og en testet gjenopprettingsprosedyre er det viktigste som gjenstår.

TODO: Sett opp overvåking og varsling, for eksempel Uptime Kuma eller Prometheus med alarm når en backend går ned. I dag oppdages en feil bare på HAProxy-statistikksiden.

### 8.4 Rullerende deploy uten nedetid (`deploy-website.yml`)

Ny kode rulles ut én webnode om gangen (`serial: 1`). Noder som allerede har riktig commit, hoppes over uten at HAProxy røres. For hver node:

1. **Er det trygt?** Ansible leser `show stat` fra HAProxy og stopper hvis ingen annen node er oppe (kan overstyres med `-e allow_downtime=true`).
2. **Tapp noden:** HAProxy slutter å sende nye forespørsler til noden og venter til pågående forespørsler er ferdige.
3. **Oppdater og test:** `git` sjekker ut ny versjon, PHP-FPM lastes inn på nytt, og noden røyktestes direkte på `/health` og `/`.
4. **Sett noden tilbake:** Ansible venter til HAProxys egen helsesjekk melder at noden er oppe, før den går videre til neste node.

Feiler et steg, stopper hele utrullingen (`any_errors_fatal`). Den feilede noden blir stående tappet, og resten av nodene fortsetter på forrige versjon. Brukerne merker ingenting.

TODO: `deploy_serial` i `group_vars/web.yml` brukes ikke, fordi playbooken har `serial: 1` hardkodet. Headeren i playbooken viser også til en gammel sti (`playbooks/web/deploy-website.yml`).

### 8.5 Skalering

**Horisontalt (flere webnoder)** er det miljøet er laget for. Slik legges web03 til:

1. Lag VM-en og legg til `ansible`-brukeren (kapittel 5).
2. Legg til `web03 ansible_host=<IP>` under `[web]` i `hosts`.
3. Kjør `ansible-playbook site.yml`.

Ingen annen konfigurasjon må endres. HAProxy-malen legger til en `server`-linje for hver vert i `[web]`, og DB-rollen legger til en `pg_hba`-linje og en UFW-regel for den nye IP-en. Webnodene har ingen lokal tilstand, siden alle data ligger i databasen, så de kan legges til og fjernes fritt.

**Vertikalt** kan DB01 og LB01 få mer CPU og minne i Proxmox. Det er den enkleste veien for databasen.

TODO: Neste trinn ved kraftig vekst:

- to lastbalanserere med keepalived og en delt virtuell IP (fjerner R2)
- PostgreSQL-strømmereplikering med en standby-server og eventuelt lesereplikaer (reduserer R3)
- en tilkoblingspool (PgBouncer) mellom web og DB når antall webnoder øker

## 9. Sikkerhetstiltak per lag

Sikkerheten bygger på minst mulig tilgang: hver komponent når bare det den trenger. Tabellen viser hva som er gjort i hvert lag, og hva som gjenstår.

| Lag | Gjennomført | Gjenstår (TODO) |
| --- | --- | --- |
| Nettverk | PostgreSQL lytter bare på privat IP. UFW-regler gir bare webnodene tilgang til 5432, og bare 80 og 8404 er åpnet på LB01. | UFW slås aldri på og har ingen standardregel om å avvise alt. SSH bør bare tillates fra 172.16.45.10. Flytte servere bak pfSense. |
| Tilgang til serverne | Egen `ansible`-bruker med RSA 4096-nøkkel, `from="172.16.45.10"`, `exclusive` og låst passord. `host_key_checking = True`. | Herde `sshd_config` (ingen passord eller root-innlogging, `AllowUsers`). Fail2ban. Sterke passord for `dantan`. Begrense `NOPASSWD: ALL`. |
| Operativsystem | Ubuntu 26.04 LTS. Rollene sjekker distribusjonen før de kjører. | unattended-upgrades, auditd og NTP i `common`-rollen. |
| Webserver | Standardsiden er fjernet og dotfiler blokkert. Sikkerhetsheadere, `expose_php = Off`, `cgi.fix_pathinfo = 0` og `HTTP_PROXY` tømmes (httpoxy). Koden eies av root. | HTTPS med sertifikat på HAProxy. Content-Security-Policy. Flytte koden til `public/`. |
| Applikasjon | Prepared statements, `htmlspecialchars()` og generiske feilmeldinger. | CSRF-token, lengdegrense og rate-begrensning på skjemaet. |
| Database | `pg_hba` med bare én bruker, én database og webnodenes IP-er, `scram-sha-256`. Applikasjonsbrukeren er ikke superbruker. | `sslmode=require` mellom web og DB. Fjerne `db_extra_clients` i labben. |
| Lastbalanserer | Konfigurasjonen valideres. Admin-socketen har modus `660`. Statistikksiden krever passord. | Begrense statistikksiden til administrasjonsnettet, eller legge den bak TLS. |
| Hemmeligheter | DB-påloggingen ligger ikke i app-repoet og gis til PHP-FPM i en fil med modus `0640`. `no_log` på sensitive oppgaver. | Flytte `db_password` og `haproxy_stats_password` til ansible-vault. Begge står i klartekst i `group_vars` og er like svake som kontopassordene. |
| Sporbarhet | nginx har egne tilgangs- og feillogger per side. HAProxy logger til syslog. Ansible-endringer kan skilles fra manuelle fordi de gjøres av en egen bruker. | Samle logger sentralt. auditd for sudo-bruk. |

## 10. Driftshåndbok for nye administratorer

Alt gjøres fra administrasjonsserveren i `/home/dantan/ansible-ak3`. Logg aldri inn på en styrt server for å endre konfigurasjon. Endre heller koden og kjør Ansible, ellers blir endringen overskrevet ved neste kjøring.

### 10.1 Vanlige kommandoer

| Oppgave | Kommando |
| --- | --- |
| Installere collections (én gang) | `ansible-galaxy collection install -r requirements.yml` |
| Sjekke at alle servere svarer | `ansible all -m ping` |
| Bygge eller rette hele miljøet | `ansible-playbook site.yml` |
| Se hva som ville endret seg | `ansible-playbook site.yml --check --diff` |
| Kjøre mot én server | `ansible-playbook site.yml --limit web02` |
| Rulle ut ny kode fra `main` | `ansible-playbook deploy-website.yml` |
| Rulle ut en bestemt versjon | `ansible-playbook deploy-website.yml -e git_branch=v1.4.2` |
| Tvinge ny utrulling på samme commit | `ansible-playbook deploy-website.yml -e force_deploy=true` |
| Tillate nedetid (bare én frisk node) | `ansible-playbook deploy-website.yml -e allow_downtime=true` |
| Se status i HAProxy | `http://172.16.45.52:8404/`, eller på LB01: `echo "show stat" \| sudo socat stdio /run/haproxy/admin.sock` |
| Oppdatere pakker på alle | `ansible all -b -m apt -a "upgrade=dist update_cache=true"` – én webnode om gangen med `--forks 1` |

### 10.2 Prosedyrer

**Endre konfigurasjon**

1. Endre variabelen i `group_vars/` eller malen i `roles/<rolle>/templates/`.
2. Kjør `ansible-playbook site.yml --check --diff --limit <gruppe>` og les gjennom endringene.
3. Kjør uten `--check`. Rollens verifiseringssteg må bli grønne.

**Legge til en webnode:** se 8.5.

**En webnode er nede**

1. Sjekk statistikksiden eller `show stat`. HAProxy har allerede tatt noden ut av rotasjon.
2. Prøv først `ansible-playbook site.yml --limit <node>`. Det starter tjenester og retter konfigurasjon.
3. Er VM-en ødelagt, bygg den på nytt (8.3). Siden er oppe på den andre noden i mellomtiden.

**En deploy stoppet midt i**

Den feilede noden er tappet i HAProxy. De andre kjører forrige versjon. Rett feilen i koden, og kjør `deploy-website.yml` på nytt. Vil du rulle tilbake, kjør med `-e git_branch=<forrige tag>`. `site.yml` setter også noden tilbake i rotasjon.

**DB01 er nede**

Hele nettsiden viser «Could not connect to the database». Webnodene er fortsatt oppe i HAProxy, siden `/health` ikke sjekker databasen.

1. Sjekk `systemctl status postgresql@17-main` og diskplass (`df -h`).
2. Kjør `ansible-playbook site.yml --limit db`.
3. TODO: Gjenopprett fra backup. Prosedyren finnes ikke ennå (kapittel 11).

**Bytte databasepassord**

Endre `db_password` i `group_vars/all.yml` (TODO: vault), og kjør `ansible-playbook site.yml --limit db,web`. DB-rollen setter nytt passord, og web-rollen skriver det til PHP-FPM og restarter tjenesten.

**Rotere SSH-nøkkelen**

Lag et nytt nøkkelpar, og kjør `authorized_key`-playbooken (5.3) med den nye offentlige nøkkelen. `exclusive: true` fjerner den gamle.

## 11. Oppgavene til en systemadministrator

En systemadministrator sørger for at tjenestene er tilgjengelige, sikre og oppdaterte, og at dataene kan gjenopprettes. Oppgavene under er fordelt på hvor ofte de gjøres. De fleste gjøres med Ansible fra administrasjonsserveren.

| Frekvens | Oppgave | Hvordan | Status |
| --- | --- | --- | --- |
| Én gang | Risikoanalyse og arkitektur | Kapittel 2 og 3 | Gjort |
| Én gang | Installere Ubuntu LTS, lage `ansible`-bruker, rulle ut og låse nøkkel | Kapittel 5 | Gjort |
| Én gang | Bygge miljøet | `ansible-playbook site.yml` | Gjort |
| Én gang | Herde operativsystemet (sshd, UFW på, unattended-upgrades, fail2ban) | `common`-rollen | TODO |
| Én gang | Flytte hemmeligheter til ansible-vault | `ansible-vault encrypt_string` | TODO |
| Én gang | Sette opp backup og overvåking | Se 11.1 | TODO |
| Daglig | Sjekke at alle backend-noder er oppe | HAProxy-statistikksiden | Manuelt |
| Daglig | Ta backup av databasen | `pg_dump` via cron styrt av Ansible | TODO |
| Daglig | Se etter feil i loggene | `/var/log/nginx/testside.local.error.log`, `journalctl -u haproxy`, PostgreSQL-loggen | Manuelt |
| Ukentlig | Installere sikkerhetsoppdateringer, én webnode om gangen | `ansible all -b -m apt -a "upgrade=dist"` | Manuelt |
| Ukentlig | Sjekke diskplass (spesielt DB01) | `ansible all -a "df -h"` | Manuelt |
| Ukentlig | Lese auth-logger for mislykkede innlogginger | `journalctl -u ssh` | Manuelt |
| Ukentlig | Kjøre `site.yml --check --diff` for å finne manuelle endringer | Ansible | Manuelt |
| Månedlig | Teste gjenoppretting av backup på en test-VM | `pg_restore` til en ny database | TODO |
| Månedlig | Revidere tilganger: `authorized_keys`, sudoers og brukere | `ansible all -b -a "cat /home/ansible/.ssh/authorized_keys"` | Manuelt |
| Månedlig | Omstart etter kjerneoppdatering, én node om gangen | Tapp noden (som i deploy-flyten), start den på nytt, sett den tilbake | Manuelt |
| Månedlig | Sikkerhetsskanning (Lynis, nmap) | Se kapittel 12 | Senere |
| Årlig | Rotere SSH-nøkkel og tjenestepassord | 10.2 | Manuelt |
| Årlig | Gjennomgå risikoanalysen på nytt | Kapittel 2 | Manuelt |
| Hvert 2. år | Planlegge oppgradering til neste Ubuntu LTS | Test rollene på ny LTS først | – |

### 11.1 Backupplan

TODO: Backup er ikke implementert. Dette er planen som håndterer R4.

- **Hva:** databasen `testside` med `pg_dump -Fc`. Web- og LB-nodene trenger ikke backup, fordi de kan bygges på nytt fra git og Ansible.
- **Når:** daglig kl. 02:00 med cron-jobb som Ansible installerer (`ansible.builtin.cron`).
- **Oppbevaring:** 7 daglige, 4 ukentlige og 3 månedlige kopier.
- **Hvor:** lokalt på DB01 og kopiert til administrasjonsserveren eller en egen lagringsserver. En backup som bare ligger på samme disk, beskytter ikke mot diskfeil eller ransomware.
- **Test:** månedlig `pg_restore` til en tom database, med kontroll av antall rader i `messages`.
- **Mål:** maks 24 timers datatap (RPO) og maks 1 time for å gjenopprette (RTO).

## 12. Red team / pentest

TODO: Pentesten gjennomføres senere, bare innenfor 172.16.45.0/24. Planen er å teste minst ett verktøy per angrepsflate. Hvert funn kobles til en risiko i kapittel 2.

| Angrepsflate | Kandidater | Hva vi vil sjekke |
| --- | --- | --- |
| Nettverk og porter | nmap | At bare 22, 80 og 8404 er åpne på LB01, og at 5432 bare svarer mot webnodene (R8) |
| Hostsystemet | Lynis, ssh-audit | Herding, SSH-konfigurasjon og passordinnlogging (R7, R13) |
| Webapplikasjon og database | nikto, sqlmap, gobuster, psql | SQL-injeksjon i skjemaet, tilgang til `README.md` og `schema.sql`, og `pg_hba` (R11, R8) |
| Administrasjonsserveren | ansible-lint, ssh-keyscan | Klartekstpassord i `group_vars` (R10, R16) |
| Brukere | hydra | Brute force mot `dantan` over SSH og mot statistikksiden (R12) |

## 13. Kjente mangler (samlet TODO-liste)

Listen samler alle `TODO:`-punktene i rapporten, sortert etter hvor viktige de er. De tre første bør løses før miljøet tas i bruk.

| # | Mangel | Hvor | Risiko |
| --- | --- | --- | --- |
| 1 | Backup av databasen er ikke implementert | – | R4 |
| 2 | UFW slås aldri på: ingen `state: enabled`, ingen standardregel om å avvise alt, og SSH er ikke tillatt fra 172.16.45.10 før UFW aktiveres. Alle UFW-reglene i rollene har derfor trolig ingen effekt. | `roles/*/tasks/main.yml` | R8, R7 |
| 3 | Passord i klartekst (`db_password`, `haproxy_stats_password`) | `group_vars/all.yml`, `group_vars/lb.yml` | R10, R16 |
| 4 | `common`-rollen er tom: ingen herding av sshd, unattended-upgrades, fail2ban, auditd eller NTP | `roles/common/` | R7, R12, R13 |
| 5 | Svake og delte passord på `dantan`-kontoene. Passordinnlogging er fortsatt på. | Alle servere | R10 |
| 6 | Statistikksiden (`*:8404`) er åpen for hele nettet og bruker basic auth over HTTP | `group_vars/lb.yml` | R12 |
| 7 | Ingen TLS, verken mot brukerne eller mellom web og DB | `haproxy.cfg.j2`, `php-fpm-db-env.conf.j2` | R15 |
| 8 | LB01 og DB01 er enkeltpunkter for feil | Arkitektur | R2, R3 |
| 9 | Ingen overvåking, varsling eller sentral logging | – | R1–R3 |
| 10 | `db_extra_clients: 10.188.38.1/32` fra det lokale miljøet | `group_vars/db.yml` | R8 |
| 11 | `hosts` har IP-ene fra det lokale miljøet, ikke fra labben | `hosts` | – |
| 12 | Skjemaet mangler CSRF-token, lengdegrense og rate-begrensning | App-repoet | R11, R14 |
| 13 | `README.md` og `schema.sql` kan hentes over HTTP | App-repoet, `app_public_dir` | R11 |
| 14 | `NOPASSWD: ALL` for `ansible`. Regelen ligger i `/etc/sudoers` i stedet for `sudoers.d` | Kapittel 5 | R9 |
| 15 | Privatnøkkelen har ingen passphrase. Ansible-konfigurasjonen oppgir ikke hvilken nøkkel som brukes. | `ansible.cfg` | R9 |
| 16 | `authorized_key`-playbooken ligger ikke i repoet, og `ansible.posix` mangler i `requirements.yml` | `requirements.yml` | R17 |
| 17 | En kommentar sier at nginx ikke ser ut til å bli reloadet etter kjøring | `roles/web/handlers/main.yml` | R6 |
| 18 | `deploy_serial` er definert, men brukes ikke (deploy har `serial: 1` hardkodet) | `group_vars/web.yml` | – |
| 19 | Kommentarer viser til den gamle `playbooks/`-strukturen | `roles/lb/tasks/main.yml`, `roles/db/tasks/main.yml`, `roles/web/tasks/main.yml`, `deploy-website.yml` | R17 |
| 20 | `meta/main.yml` og `README.md` i rollene er fortsatt Galaxy-maler | `roles/*/meta/main.yml` | R17 |
| 21 | Grunnoppsettet av Ansible fra AK2 mangler i rapporten | Kapittel 4 | – |
| 22 | Vurdere å flytte servere bak pfSense | Arkitektur | R9 |

## Vedlegg A – Filstruktur

```
ansible-ak3/
├── ansible.cfg            # inventory, remote_user=ansible, host_key_checking
├── hosts                  # [web] [db] [lb]
├── requirements.yml       # community.general, community.postgresql
├── site.yml               # bygger alt: db -> web -> lb
├── deploy-website.yml     # rullerende deploy, én webnode om gangen
├── group_vars/
│   ├── all.yml            # HAProxy-backend, DB-navn/bruker/passord
│   ├── db.yml             # PostgreSQL-versjon, listen_addresses, ekstra klienter
│   ├── lb.yml             # helsesjekk, timeouts, statistikkside
│   └── web.yml            # side, git-repo, PHP-innstillinger
└── roles/
    ├── common/            # TODO: tom
    ├── db/                # tasks, handlers, defaults, files/schema.sql
    ├── lb/                # tasks, handlers, templates/haproxy.cfg.j2
    └── web/               # tasks (main, install-code, detect-php-version),
                           # handlers, templates (nginx, php, db-env)
```

## Vedlegg B – Viktige variabler

| Variabel | Verdi | Fil | Brukes til |
| --- | --- | --- | --- |
| `db_name` / `db_user` | `testside` / `testside_app` | `group_vars/all.yml` | Database og applikasjonsbruker |
| `db_port` | `5432` | `group_vars/all.yml` | PostgreSQL-port |
| `db_host` | IP-en til første vert i `[db]` | `group_vars/all.yml` | Hvor PHP kobler til |
| `postgres_version` | `17` | `group_vars/db.yml` | Pakke og konfigurasjonssti |
| `db_extra_clients` | `10.188.38.1/32` | `group_vars/db.yml` | Ekstra klienter i `pg_hba` og UFW |
| `site_name` / `server_name` | `testside.local` | `group_vars/web.yml` | nginx-vert og loggnavn |
| `git_repo` / `git_branch` | App-repoet / `main` | `group_vars/web.yml` | Hva som rulles ut |
| `php_max_execution_time` | `60` | `group_vars/web.yml` | Må være lavere enn `haproxy_timeout_server` |
| `haproxy_backend` | `web_backend` | `group_vars/all.yml` | Backend-navn, delt av LB-rollen og deploy |
| `haproxy_check_inter` / `fall` / `rise` | `2s` / `3` / `2` | `group_vars/lb.yml` | Helsesjekk |
| `haproxy_slowstart` | `10s` | `group_vars/lb.yml` | Gradvis oppstart av en node |
| `haproxy_timeout_server` | `70s` | `group_vars/lb.yml` | Tidsgrense mot backend |
| `haproxy_stats_bind` | `*:8404` | `group_vars/lb.yml` | Statistikkside |
| `manage_firewall` | `true` | `group_vars/*.yml` | Slår UFW-reglene av og på |
