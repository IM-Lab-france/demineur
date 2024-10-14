# Demineur multijoueur

Et ben en voila un démineur multijoueur


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
Ouvrir un navigateur et navigué vers votre site. la procedure d'installation débutera