# Inventaire des textes affichés

Les catalogues `locales/fr.json` et `locales/en.json` constituent la liste canonique
des phrases traduisibles. Les clés sont regroupées par écran ou domaine :

- `app.*`, `nav.*`, `language.*` : identité, navigation et choix de langue ;
- `common.*`, `connection.*` : actions communes et connexion ;
- `auth.*` : connexion et création de compte ;
- `help.*` : aide au jeu ;
- `lobby.*`, `invite.*` : accueil, joueurs et invitations ;
- `game.*` : tour, grille, compteurs et résultats ;
- `social.*` : amis, demandes, blocages et notifications ;
- `chat.*` : conversations et messages ;
- `dialog.*` : confirmations et saisies ;
- `scores.*` : classement ;
- `error.*`, `version.*` : erreurs publiques et version.

L'inventaire initial a relevé 526 occurrences françaises potentielles dans les
pages publiques, JavaScript, WebSocket et services PHP. Ce total inclut des logs,
commentaires et messages d'exploitation qui ne doivent pas être traduits. Les
catalogues contiennent uniquement les textes destinés à l'utilisateur.

Pour rechercher les textes qui resteraient codés en dur :

```bash
rg -n "[À-ÿ]|Bienvenue|Connexion|Partie|Joueur|Score|Erreur" \
  index.php script.js chat-ui.js scores.html scores.js server.php src
```

`tests/i18n_test.php` contrôle que les catalogues ont exactement les mêmes clés
et que toutes les clés `data-i18n*` utilisées par les pages existent.
