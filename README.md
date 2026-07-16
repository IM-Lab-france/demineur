# Démineur multijoueur

## Documentation d'évolution

- [Conception des parties de 2 à 4 joueurs](docs/multiplayer-2-to-4-design.md)

## Règles de score et classement

Une explosion provoque une défaite immédiate. Si toutes les cases sûres sont
révélées, chaque drapeau correct rapporte un point et chaque drapeau incorrect
retire un point. Le meilleur score gagne; des scores identiques donnent une
égalité. Le classement général utilise un Elo initial de 1200 avec un facteur
K de 32. Une partie annulée ne modifie pas l'Elo.

Application PHP/MySQL utilisant Ratchet pour les parties WebSocket, avec classement, comptes validés par e-mail, administration protégée par MFA, sauvegardes pilotées depuis l’administration et joueurs IA Python.

## Prérequis

- Apache 2.4 avec `proxy`, `proxy_http`, `proxy_wstunnel`, `headers`, `expires` et `rewrite`
- PHP 8.2 ou plus récent avec PDO MySQL, JSON, mbstring, OpenSSL et POSIX
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

Le script est préconfiguré pour le relais Brevo sur `smtp-relay.brevo.com:587`.
Il demande la clé SMTP sans l’afficher et encode automatiquement les caractères
réservés du login et de la clé. Utiliser une **clé SMTP Brevo**, jamais une clé
API ni le mot de passe du compte Brevo. Les pages publiques sont
`/verify-email.php`, `/resend-verification.php`, `/forgot-password.php` et
`/reset-password.php`. Les réponses de récupération ne révèlent jamais si une
adresse existe.

Configurer SPF, DKIM et DMARC pour le domaine d’expédition avant une ouverture
publique, puis tester le parcours avec une adresse réelle.

Les e-mails de validation possèdent un code de demande unique dans leur objet
afin d’éviter la confusion lorsque le client mail regroupe plusieurs messages.
La validation est idempotente : rouvrir un lien ayant déjà validé le compte
confirme le succès sans rejouer l’opération.

Les journaux du worker sont disponibles avec :

```bash
sudo journalctl -u minesweeper-mail.service -n 100 --no-pager
```

## Sauvegardes et restauration

Une sauvegarde SQL est créée chaque nuit par `minesweeper-backup.timer`. Le
service `minesweeper-backup-verify.timer` importe régulièrement la dernière
archive dans une base temporaire pour vérifier qu’elle est restaurable.

L’administration permet également de :

- créer immédiatement une sauvegarde ;
- afficher les archives avec leur date, taille et empreinte SHA-256 ;
- tester une archive sélectionnée sans modifier la production ;
- restaurer réellement la base depuis une archive sélectionnée.

La restauration réelle exige le mot de passe administrateur, un code TOTP et la
confirmation `RESTAURER`. Le service privilégié revérifie lui-même ces
identifiants. Il vérifie les checksums, arrête temporairement le WebSocket et les
IA, crée une sauvegarde de secours, teste l’import dans une base intermédiaire,
restaure la base puis redémarre et contrôle les services.

Les secrets actuels ne sont pas remplacés par ceux de l’archive. Les accès MFA
des administrateurs sont conservés, les comptes IA sont réalignés sur
`/var/www/secure/ia_accounts.json`, et les anciennes sessions, files e-mail et
jetons de compte restaurés sont révoqués. Les joueurs doivent donc se
reconnecter après une restauration.

La copie `secure-config.tar.gz` est destinée à la reprise après sinistre en
ligne de commande. Elle n’est jamais restaurée depuis le navigateur.

Journaux utiles :

```bash
sudo journalctl -u minesweeper-backup.service -n 100 --no-pager
sudo journalctl -u minesweeper-backup-verify.service -n 100 --no-pager
sudo journalctl -u minesweeper-backup-admin.service -n 100 --no-pager
```

La restauration de production en ligne de commande reste disponible pour une
intervention locale explicitement contrôlée :

```bash
sudo /var/www/demineur/scripts/restore-backup.sh 20260715T160727Z
```

Remplacer l’identifiant par celui d’une archive existante dans
`/var/backups/minesweeper`. Cette commande est destructive et crée elle aussi
un point de retour avant l’import.

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
