# Démineur multijoueur

Application PHP/MySQL utilisant Ratchet pour les parties WebSocket, avec classement, administration protégée et joueurs IA Python.

## Prérequis

- Apache 2.4 avec `mod_headers`
- PHP 8.2 ou plus récent avec PDO MySQL
- MySQL 8
- Composer
- Python 3.11 ou plus récent pour les IA
- systemd pour le service WebSocket de production

## Configuration

Copier `.env.example` vers un fichier `.env` placé hors du `DocumentRoot`, par exemple `/var/www/secure/.env`, puis définir `APP_CONFIG_DIR=/var/www/secure` dans l’environnement du service.

Le serveur WebSocket écoute par défaut uniquement sur `127.0.0.1:8080`. En production, l’exposer à travers un reverse proxy HTTPS et définir :

```dotenv
WS_PUBLIC_URL=wss://example.com/ws
WS_ALLOWED_ORIGINS=example.com
```

## Installation

L’installateur web est désactivé par défaut. Installer les dépendances et importer le schéma depuis la ligne de commande :

```bash
composer install --no-dev --classmap-authoritative
mysql -u root -p minesweeper < install/install.sql
```

Pour une base existante, examiner puis appliquer `install/migrations/20260714_hardening.sql` après sauvegarde.

Créer le premier utilisateur administrateur avec un mot de passe bcrypt, puis accéder à `/admin/login.php`. Toutes les opérations administratives nécessitent une session administrateur et un jeton CSRF.

## Service WebSocket

Adapter puis installer `deploy/systemd/minesweeper-websocket.service` :

```bash
sudo /var/www/demineur/scripts/install-websocket-service.sh
```

Le compte du serveur web doit recevoir une autorisation systemd strictement limitée si les boutons démarrer/arrêter sont conservés.

Les événements du backend sont écrits dans `/var/log/minesweeper/backend-AAAA-MM-JJ.log`
et dans le journal systemd. Pour diagnostiquer un démarrage ou suivre les actions du jeu :

```bash
journalctl -u minesweeper-websocket.service -n 200 --no-pager
journalctl -u minesweeper-websocket.service -f
tail -f /var/log/minesweeper/backend-$(date +%F).log
```

Les mots de passe ne sont jamais ajoutés aux journaux. Chaque demande de démarrage faite
depuis l'administration reçoit une référence consultable dans `/var/log/minesweeper/monitor-AAAA-MM-JJ.log`.

## Vérifications

```bash
php -l server.php
php tests/server_unit.php
composer audit
cd admin && npm audit
```

Ne jamais publier `.env`, `ia_accounts.json`, les journaux, PID, mémoires IA ou sauvegardes de base de données.

## Installation CLI

L’installation web est désactivée. Sur une nouvelle machine :

```bash
sudo php /var/www/demineur/scripts/install-cli.php \
  --db-host=127.0.0.1 --db-name=demineur \
  --db-user=UTILISATEUR --admin-user=ADMIN
sudo /var/www/demineur/scripts/install-websocket-service.sh
```

Les mots de passe sont demandés sans écho dans le terminal. Ils ne doivent pas être placés dans la ligne de commande.

## Finalisation d’une mise à niveau

```bash
sudo /var/www/demineur/scripts/finalize-upgrade.sh
```

Cette commande applique les migrations, synchronise la configuration Apache/systemd, migre Jimbo vers JSON, redémarre les services et vérifie une sauvegarde puis sa restauration.

Chaque administrateur peut activer son propre TOTP depuis `/admin/security.php`.
Le QR Code est généré localement et un premier code à six chiffres doit être
validé avant l’activation. Dix codes de récupération à usage unique sont alors
affichés une seule fois. Le secret TOTP est chiffré en base avec `APP_TOTP_KEY`.

Les échecs de connexion administrateur sont comptabilisés en base par compte
et par couple adresse/compte. Cinq échecs dans une fenêtre de quinze minutes
bloquent les nouvelles tentatives pendant quinze minutes, même si les cookies
du navigateur sont supprimés.

## Validation des comptes par e-mail

Les nouveaux joueurs doivent valider leur adresse avant la première connexion.
Les liens de validation expirent après 24 heures. Les liens de réinitialisation
du mot de passe expirent après 30 minutes et révoquent les sessions persistantes.
Les jetons sont aléatoires, à usage unique et uniquement stockés sous forme de
hash SHA-256.

Configurer le serveur SMTP avec :

```bash
sudo /var/www/demineur/scripts/configure-mail.sh
```

Le mot de passe présent dans le DSN SMTP doit être encodé pour une URL
(`@` devient `%40`, par exemple). Les pages publiques sont
`/verify-email.php`, `/resend-verification.php`, `/forgot-password.php` et
`/reset-password.php`. Les réponses de récupération ne révèlent jamais si une
adresse existe.

Configurer SPF, DKIM et DMARC pour le domaine d’expédition avant une ouverture
publique, puis tester le parcours avec une adresse réelle.

## Supervision et copie hors serveur

`minesweeper-health.timer` contrôle chaque heure le backend, les timers et l’âge
des dernières sauvegardes/restaurations. Le résultat apparaît dans
l’administration et les anomalies sont envoyées au journal système sous le tag
`minesweeper-health`.

Pour activer une copie chiffrée hors serveur, installez `age`, montez une
destination distante puis ajoutez `OFFSITE_BACKUP_DIR` et
`BACKUP_AGE_RECIPIENT` dans les fichiers sécurisés. La clé privée `age` doit être
conservée sur une autre machine et un test de restauration hors site doit être
effectué après configuration.
