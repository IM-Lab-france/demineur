# Multiplayer Minesweeper


pré requis : 
- apache2
- mysql
- php
- configuration 

```apache
<VirtualHost *:80>
    ServerName <domaine>
    DocumentRoot <repertoire du site>

    <Directory <repertoire du site>>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/demineur_error.log
    CustomLog ${APACHE_LOG_DIR}/demineur_access.log combined
</VirtualHost>
```

# Configuration de la base de données
Le serveur mysql doit etre actif avec un schéma et un utilisateur permettant l'accès.


# configuration du serveur
Ouvrir un navigateur et naviguer vers votre site. Le programme d'installation préparera le serveur. 
Il est important de creer un fichier .htpasswd dans le repertoire admin pour le proteger.

