# Évolution des parties de 2 à 4 joueurs

## 1. Objectif

Faire évoluer le jeu actuel, conçu autour de deux joueurs, vers des parties de
2 à 4 participants humains ou IA, sans casser les parties à deux, la reprise
après redémarrage, le score par drapeaux, le classement Elo et les spectateurs.

La capacité doit être une propriété de chaque salon. Une partie ne commence
que lorsque le propriétaire du salon la lance et qu'au moins deux participants
sont prêts.

## 2. Règles fonctionnelles proposées

### Salon

- Capacité configurable de 2 à 4 joueurs.
- Le créateur devient propriétaire du salon.
- Le propriétaire invite plusieurs joueurs ou IA.
- Chaque invité accepte ou refuse individuellement.
- Un participant peut indiquer qu'il est prêt.
- Le propriétaire peut lancer dès que tous les participants présents sont prêts
  et que leur nombre est compris entre 2 et la capacité.
- Un salon vide ou réduit à une personne est supprimé après expiration.

### Tours

- L'ordre initial correspond aux positions (`player_slot`) du salon.
- Après une révélation valide, le tour passe au prochain participant actif.
- Poser ou retirer un drapeau ne termine pas le tour, comme actuellement.
- Un participant déconnecté temporairement est ignoré après le délai de reprise.
- Si un seul participant actif reste, la partie est annulée ou gagnée par
  forfait selon une règle explicite configurable. Pour la première version,
  l'annulation est recommandée afin d'éviter les gains Elo opportunistes.

### Drapeaux et score final

- Chaque position possède une couleur stable et accessible autrement que par la
  couleur seule (numéro, motif ou initiale).
- Un joueur ne peut retirer que ses propres drapeaux.
- Un drapeau correct vaut `+1`; un drapeau incorrect vaut `-1`.
- Une explosion reste une défaite immédiate pour son auteur. Le gagnant est
  alors le meilleur score parmi les autres participants; une égalité de score
  donne plusieurs premiers ex aequo.
- Si toutes les cases sûres sont révélées, le meilleur score gagne.
- Tous les scores identiques produisent une égalité globale.

Ces règles doivent être validées avant l'implémentation, en particulier le cas
d'une explosion et celui des joueurs déconnectés.

## 3. Modèle de données cible

Le schéma actuel contient des colonnes binaires (`inviter_id`, `invitee_id`,
`player1_id`, `player2_id`). Elles ne doivent plus être la source de vérité.

### `games`

```sql
CREATE TABLE games (
  id VARCHAR(64) NOT NULL PRIMARY KEY,
  owner_user_id INT NOT NULL,
  status ENUM('lobby','active','finished','cancelled') NOT NULL,
  capacity TINYINT UNSIGNED NOT NULL,
  grid_size VARCHAR(8) NOT NULL,
  difficulty TINYINT UNSIGNED NOT NULL,
  mine_count INT UNSIGNED NOT NULL,
  current_slot TINYINT UNSIGNED DEFAULT NULL,
  moves INT UNSIGNED NOT NULL DEFAULT 0,
  state_json JSON DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at TIMESTAMP NULL,
  CONSTRAINT chk_games_capacity CHECK (capacity BETWEEN 2 AND 4),
  CONSTRAINT fk_games_owner FOREIGN KEY (owner_user_id) REFERENCES users(id)
);
```

### `game_participants`

```sql
CREATE TABLE game_participants (
  game_id VARCHAR(64) NOT NULL,
  user_id INT NOT NULL,
  player_slot TINYINT UNSIGNED NOT NULL,
  role ENUM('player','spectator') NOT NULL DEFAULT 'player',
  state ENUM('invited','joined','ready','active','disconnected','left') NOT NULL,
  final_score SMALLINT DEFAULT NULL,
  correct_flags SMALLINT UNSIGNED DEFAULT NULL,
  incorrect_flags SMALLINT UNSIGNED DEFAULT NULL,
  final_rank TINYINT UNSIGNED DEFAULT NULL,
  elo_before INT DEFAULT NULL,
  elo_change SMALLINT DEFAULT NULL,
  joined_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at TIMESTAMP NULL,
  PRIMARY KEY (game_id, user_id),
  UNIQUE KEY uq_game_slot (game_id, player_slot),
  CONSTRAINT fk_participant_game FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE,
  CONSTRAINT fk_participant_user FOREIGN KEY (user_id) REFERENCES users(id)
);
```

Les invitations peuvent rester dans `invitations`, mais elles doivent référencer
le salon par `game_id` dès leur création.

### Transition avec les tables existantes

1. Ajouter les nouvelles tables sans supprimer les anciennes.
2. Écrire les nouvelles parties dans les deux modèles pendant une version si
   une compatibilité descendante est nécessaire.
3. Migrer les parties actives seulement si leur JSON est compatible; sinon
   terminer les parties existantes avant le déploiement.
4. Faire lire le classement et l'historique depuis le nouveau modèle.
5. Supprimer `active_games.player1_id/player2_id` et les colonnes binaires de
   `game_details` dans une migration ultérieure, jamais dans la première PR.

Chaque migration doit être idempotente et testée sur une copie de production.

## 4. Modèle en mémoire du serveur

Remplacer les accès supposant deux joueurs par une structure explicite :

```php
$games[$gameId] = [
    'ownerUserId' => 42,
    'capacity' => 4,
    'participants' => [
        ['userId' => 42, 'resourceId' => 101, 'slot' => 1, 'state' => 'active'],
        ['userId' => 51, 'resourceId' => 115, 'slot' => 2, 'state' => 'active'],
    ],
    'currentSlot' => 1,
    'board' => [],
    'mineCount' => 60,
    'moves' => 0,
    'spectators' => [],
];
```

Créer des fonctions centrales au lieu de manipuler directement le tableau :

- `getActiveParticipants($gameId)`;
- `findParticipantByResourceId($gameId, $resourceId)`;
- `findParticipantByUserId($gameId, $userId)`;
- `getNextActiveSlot($gameId, $currentSlot)`;
- `replaceParticipantConnection($gameId, $userId, $resourceId)`;
- `removeParticipant($gameId, $userId, $reason)`;
- `calculateParticipantScores($gameId)`.

Cela évite de répandre des conditions spécifiques à 2, 3 ou 4 joueurs.

## 5. Protocole WebSocket

### Nouveaux messages client vers serveur

```json
{ "type": "create_lobby", "capacity": 4, "gridSize": "20x20", "difficulty": 15 }
{ "type": "invite_to_lobby", "gameId": "...", "userId": 51 }
{ "type": "join_lobby", "gameId": "...", "invitationId": "..." }
{ "type": "leave_lobby", "gameId": "..." }
{ "type": "set_ready", "gameId": "...", "ready": true }
{ "type": "start_lobby", "gameId": "..." }
```

### Nouveaux messages serveur vers clients

```json
{
  "type": "lobby_state",
  "gameId": "...",
  "capacity": 4,
  "ownerUserId": 42,
  "participants": [
    { "userId": 42, "username": "Alice", "slot": 1, "ready": true, "isAi": false }
  ]
}
```

`game_start`, `game_resumed`, `update_board` et `game_over` doivent tous inclure
`participants`, `currentSlot` et `currentPlayer`. `flaggedBy` reste un numéro de
position. Le client ne doit jamais déduire une identité uniquement d'une couleur.

Tous les messages doivent valider : type, authentification, appartenance au
salon, rôle, capacité, état courant et autorisation du propriétaire.

## 6. Rotation des tours

Algorithme recommandé :

1. Trier les participants actifs par `player_slot`.
2. Rechercher la position strictement supérieure à la position courante.
3. Revenir à la première position si nécessaire.
4. Ignorer les états `left` et, après expiration du délai, `disconnected`.
5. Si moins de deux participants actifs restent, appliquer la règle d'annulation.

Ajouter des tests avec les ensembles `[1,2]`, `[1,2,3]`, `[1,3,4]`, le retour
de `4` vers `1`, une déconnexion et une reconnexion.

## 7. Elo multijoueur

Conserver le système Elo à somme quasi nulle en décomposant le classement final
en confrontations par paires.

Pour chaque paire A/B :

- A devant B : résultat A = `1`, B = `0`;
- même rang : `0.5` chacun;
- A derrière B : A = `0`, B = `1`.

Calculer la variation Elo de chaque paire avec la formule actuelle, additionner
les variations d'un joueur puis diviser par `nombre_de_participants - 1`.
Arrondir une seule fois à la fin. Corriger éventuellement un écart d'arrondi sur
le dernier participant afin que la somme des variations reste exactement nulle.

```text
expected(A,B) = 1 / (1 + 10 ^ ((ratingB - ratingA) / 400))
delta(A,B) = K × (result(A,B) - expected(A,B))
```

Utiliser `K=32` comme aujourd'hui. Une partie annulée ne modifie aucun Elo.
Tous les utilisateurs, humains et IA, suivent la même formule.

Le classement final doit être déterminé avant le calcul Elo : score de drapeaux,
puis rang partagé en cas d'égalité. L'explosion place son auteur derrière tous
les participants encore actifs, quelle que soit sa quantité de drapeaux.

## 8. Interface utilisateur

### Salon

- Afficher les positions occupées et libres.
- Montrer propriétaire, état prêt, humain/IA et état de connexion.
- Désactiver « Lancer » si les conditions ne sont pas réunies.
- Permettre au propriétaire d'annuler une invitation.
- Rendre les changements accessibles avec `aria-live`.

### Plateau

- Générer les compteurs depuis `participants`, sans identifiants HTML fixes
  `flagCounterPlayer1` et `flagCounterPlayer2`.
- Utiliser quatre couleurs contrastées plus un motif/numéro : rouge, bleu, vert,
  violet, par exemple.
- Adapter `renderPlayerFlag()` à une position de 1 à 4.
- Afficher l'ordre des tours et mettre en évidence le joueur courant.
- Conserver les animations d'action en attente existantes.

### Fin de partie

- Tableau par participant : rang, drapeaux corrects, erreurs, score net, Elo
  avant, variation et Elo après.
- Croix sur chaque mauvais drapeau, avec sa couleur de propriétaire.
- Prendre en charge plusieurs gagnants ex aequo.

## 9. IA

Le processus IA doit recevoir la liste complète des participants, mais sa
stratégie de plateau peut rester identique dans une première version.

Modifications nécessaires :

- ne plus supposer un adversaire unique;
- suivre `currentSlot` et sa propre position;
- accepter et rejoindre un salon multijoueur;
- gérer un délai plus long entre ses tours;
- reprendre sa position après reconnexion;
- afficher les scores de tous les participants à la fin;
- empêcher plusieurs IA de cibler continuellement le même salon complet.

Tester au minimum : humain + 2 IA, 4 IA, déconnexion d'une IA et reprise.

## 10. Persistance et reconnexion

`state_json` doit contenir uniquement les données de jeu sérialisables, avec un
numéro de version :

```json
{
  "version": 2,
  "board": [],
  "participants": [{ "userId": 42, "slot": 1, "state": "active" }],
  "currentSlot": 1,
  "moves": 12,
  "mineCount": 60
}
```

Les `resourceId` Ratchet sont éphémères et ne doivent jamais être persistés.
Au chargement, reconstruire les connexions depuis les sessions authentifiées.
Une reconnexion remplace la connexion du participant identifié par `user_id`,
pas par sa position dans un tableau à deux éléments.

## 11. Tests attendus

### Unitaires PHP

- validation d'une capacité de 2 à 4;
- ajout, refus et suppression d'un participant;
- impossibilité d'occuper deux fois une position;
- rotation des tours de 2 à 4 joueurs;
- propriété des drapeaux de 1 à 4;
- score, rangs simples et ex aequo;
- explosion avec trois survivants;
- Elo multijoueur et somme des variations égale à zéro;
- sérialisation et restauration version 2;
- reconnexion par `user_id`.

### Intégration WebSocket

- création d'un salon, trois acceptations, états prêts, démarrage;
- diffusion identique à tous les joueurs et spectateurs;
- refus du cinquième participant;
- actions hors tour;
- départ et reconnexion;
- fin de partie et persistance des quatre résultats.

### E2E navigateur

- salon utilisable au clavier et sur mobile;
- quatre compteurs sans débordement horizontal;
- couleurs complétées par des libellés accessibles;
- animations d'attente et erreurs serveur;
- tableau final et classement Elo.

## 12. Découpage recommandé des pull requests

1. **Schéma et abstractions** : nouvelles tables, dépôts PHP et fonctions de
   participants, sans modifier l'interface publique.
2. **Salons WebSocket** : création, invitation multiple, prêt et démarrage.
3. **Moteur multijoueur** : rotation, actions, persistance et reconnexion.
4. **Interface 2 à 4 joueurs** : salon, compteurs, couleurs et résultat final.
5. **Score et Elo multijoueur** : rangs, historique et classement.
6. **IA et spectateurs** : compatibilité complète et tests dédiés.
7. **Nettoyage** : suppression des chemins binaires historiques après une
   période de production stable et une sauvegarde vérifiée.

Chaque PR doit rester déployable et conserver les parties à deux.

## 13. Déploiement

1. Sauvegarder la base et vérifier la restauration.
2. Appliquer les migrations additives.
3. Déployer le serveur compatible avec les deux schémas.
4. Activer les salons multijoueurs derrière une variable
   `MULTIPLAYER_MAX_PLAYERS=2` par défaut.
5. Tester en production avec `3`, puis `4`, sur des comptes internes.
6. Surveiller erreurs WebSocket, temps SQL, parties bloquées et somme Elo.
7. Passer progressivement la capacité publique à 4.
8. Ne supprimer l'ancien schéma qu'après plusieurs sauvegardes validées.

## 14. Retour arrière

- Remettre `MULTIPLAYER_MAX_PLAYERS=2` bloque immédiatement les nouveaux salons
  multijoueurs sans détruire leurs données.
- Conserver les nouvelles tables lors du rollback applicatif.
- Ne jamais restaurer une sauvegarde complète uniquement pour annuler le code.
- Prévoir une commande d'administration qui annule proprement les salons de plus
  de deux joueurs avant de redéployer une version ancienne.
- Vérifier que les variations Elo d'une partie ne peuvent être appliquées qu'une
  fois grâce à l'unicité de `game_id` et à une transaction.

## 15. Critères d'acceptation globaux

- Les parties à deux existantes fonctionnent sans changement visible.
- Un salon accepte réellement 2, 3 ou 4 participants, jamais davantage.
- Chaque action est validée contre le participant et le tour courant.
- Les drapeaux, scores, rangs et Elo sont attribués au bon utilisateur.
- Une reconnexion et un redémarrage serveur conservent positions et tour.
- Les variations Elo d'une partie totalisent zéro.
- Une partie annulée ne modifie ni statistiques ni Elo.
- L'interface reste utilisable au clavier, sur mobile et sans perception des
  couleurs.
- Les migrations sont idempotentes et le rollback est documenté.
