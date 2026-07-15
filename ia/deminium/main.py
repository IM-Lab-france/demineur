# main.py

import asyncio
import websockets
import json
import time
import sys
import threading
import os
import importlib.util
import random
import logging
import signal

# Variables globales
current_player_id = None
current_game_id = None
current_invitation_id = None
invited_player_id = None
stop_ai = False

# Configuration par défaut
pause_duration = 1  # En millisecondes
invite_automatically = '--invite' in sys.argv
grid_size = '20x20'
selected_level = 'medium'
model_name = '2o1'  # Modèle par défaut

# Gestion des arguments de ligne de commande
for arg in sys.argv:
    if arg.startswith('--pause='):
        pause_duration = int(arg.split('=')[1])
    elif arg.startswith('--grid_size='):
        grid_size = arg.split('=')[1]
    elif arg.startswith('--ai_level='):
        selected_level = arg.split('=')[1]
    elif arg.startswith('--model='):
        model_name = arg.split('=')[1]  # Extraire le nom du modèle choisi

# Définir le dossier des logs hors du DocumentRoot en production.
log_root = os.environ.get('IA_LOG_ROOT')
log_dir = os.path.join(log_root, model_name) if log_root else os.path.join(os.path.dirname(__file__), 'plugins', model_name, 'logs')
if not os.path.exists(log_dir):
    os.makedirs(log_dir)

log_file = os.path.join(log_dir, f"ia_{model_name}.log")

# Configuration du logging
logging.basicConfig(
    filename=log_file,
    level=logging.DEBUG,
    format='%(asctime)s [%(levelname)s] %(message)s',
    datefmt='%Y-%m-%d %H:%M:%S'
)

# Exemple de log pour différentes étapes
logging.info('Début du script IA')
logging.info(f'Nom du modèle: {model_name}')
logging.info(f'Taille de la grille: {grid_size}')

# Chemin du répertoire des plugins
plugins_dir = os.path.join(os.path.dirname(__file__), 'plugins', model_name)

# Vérifier si le répertoire du modèle existe
if not os.path.isdir(plugins_dir):
    print(f"Le répertoire du modèle '{model_name}' n'existe pas.")
    sys.exit(1)

# Vérifier que les fichiers nécessaires sont présents
required_files = ['move_strategy.py']
for file in required_files:
    if not os.path.isfile(os.path.join(plugins_dir, file)):
        print(f"Le fichier requis '{file}' est manquant dans le répertoire '{model_name}'.")
        sys.exit(1)

# Charger dynamiquement les modules de stratégie de mouvement
def load_module(module_name, module_path):
    spec = importlib.util.spec_from_file_location(module_name, module_path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

# Charger les classes à partir des fichiers
move_strategy_module = load_module('move_strategy', os.path.join(plugins_dir, 'move_strategy.py'))

# Créer une instance de MoveStrategy
legacy_memory = os.path.join(plugins_dir, 'memory.pkl')
if os.path.isfile(legacy_memory):
    # L'ancien format pickle permet l'exécution de code au chargement. Il est neutralisé.
    os.remove(legacy_memory)
move_strategy = move_strategy_module.MoveStrategy()

# Chargement des comptes IA
accounts_file = os.environ.get('IA_ACCOUNTS_FILE', '/var/www/secure/ia_accounts.json')
with open(accounts_file, 'r', encoding='utf-8') as f:
    ia_accounts = json.load(f)

# Trouver le compte IA correspondant au modèle
def get_account_for_model(model_name, ia_accounts):
    for account in ia_accounts:
        if account.get('model_name') == model_name:
            return account
    return None

# Récupérer le compte IA pour le modèle spécifié
ai_account = get_account_for_model(model_name, ia_accounts)
if not ai_account:
    print(f"Aucun compte IA trouvé pour le modèle '{model_name}'. Veuillez vérifier 'ia_accounts.json'.")
    sys.exit(1)

async def connect_to_server(uri):
    origin = os.environ.get('WS_ORIGIN', 'http://localhost')
    async with websockets.connect(uri, origin=origin, max_size=65536, open_timeout=10) as websocket:
        await attempt_login(websocket)

async def attempt_login(websocket):
    global current_player_id

    username = ai_account['username']
    password = ai_account['password']

    print(f"Tentative de connexion avec {username}...")

    login_message = {
        'type': 'login',
        'username': username,
        'password': password
    }
    await websocket.send(json.dumps(login_message))

    message = await websocket.recv()
    data = json.loads(message)

    if data['type'] == 'login_success':
        print(f"Connecté avec succès en tant que {username}")
        current_player_id = data['playerId']
        await handle_server_messages(websocket, username)
    elif data['type'] == 'login_failed':
        print(f"Échec de connexion pour {username}.")
        sys.exit(1)

async def handle_server_messages(websocket, username):
    global current_game_id, current_invitation_id, invited_player_id

    async for message in websocket:
        data = json.loads(message)

        if stop_ai:
            print("Arrêt de l'IA...")
            return

        if data['type'] == 'login_success':
            print(f"Connecté en tant que {data['username']}")
            current_player_id = data['playerId']

            if invite_automatically:
                await search_and_invite_player(websocket, data['players'], username)

        elif data['type'] == 'invite':
            print(f"Invitation reçue de : {data['inviter']}")
            current_invitation_id = data['invitationId']
            await accept_invite(websocket)

        elif data['type'] == 'game_start':
            print("Partie commencée !")
            try:
                move_strategy.beginGame(data['board'], data.get('mineCount'))
            except TypeError:
                move_strategy.beginGame(data['board'])
            current_game_id = data['game_id']
            display_board(data['board'])

            if data['currentPlayer'] == username:
                print("C'est mon tour !")
                await make_move(websocket, data['board'])
            else:
                print("En attente de mon tour...")

        elif data['type'] == 'update_board':
            display_board(data['board'])
            if data['currentPlayer'] == username:
                print("C'est mon tour !")
                await make_move(websocket, data['board'])
            else:
                print("En attente de mon tour...")

        elif data['type'] == 'game_over':
            current_game_id = None
            print(f"Partie terminée. Vainqueur : {data['winner_name']}")
            display_board(data['board'])

            # Appeler endGame lorsque la partie est terminée
            move_strategy.endGame(data['winner_name'], username)
            
            # Réinviter de nouveau l'adversaire
            if invite_automatically and invited_player_id is not None:
                await invite_player(websocket, invited_player_id)

        elif data['type'] == 'connected_players':
            if invite_automatically:
                await search_and_invite_player(websocket, data['players'], username)

        elif data['type'] == 'error':
            print(f"Erreur : {data['message']}")

async def accept_invite(websocket):
    accept_message = {
        'type': 'accept_invite',
        'invitationId': current_invitation_id
    }
    await websocket.send(json.dumps(accept_message))

async def search_and_invite_player(websocket, players, username):
    global invited_player_id
    for player in players:
        if player['username'].startswith('ia_') and player['username'] != username:
            print(f"Invitation du joueur : {player['username']}")
            await invite_player(websocket, player['id'])
            invited_player_id = player['id']
            return
    print("Aucun joueur 'ia_' approprié trouvé pour l'invitation.")

async def invite_player(websocket, player_id):
    if player_id is None:
        print("Invitation ignorée : aucun adversaire disponible.")
        return
    invite_message = {
        'type': 'invite',
        'invitee': player_id,
        'gridSize': grid_size,
        'difficulty': 15
    }
    await websocket.send(json.dumps(invite_message))

async def make_move(websocket, board):
    global pause_duration

    # Utilisation de la stratégie de mouvement pour choisir le coup
    available = [
        {'x': x, 'y': y}
        for x, row in enumerate(board)
        for y, cell in enumerate(row)
        if not cell.get('revealed', False) and not cell.get('flagged', False)
    ]
    if selected_level == 'easy':
        best_move = random.choice(available) if available else None
    else:
        best_move = move_strategy.choose_move(board)

    if best_move:
        x, y = best_move['x'], best_move['y']
        cell = board[x][y]
        if cell['revealed']:
            print(f"Erreur : la cellule ({x}, {y}) est déjà révélée !")
            return
        print(f"Coup choisi en ({x}, {y})")
    else:
        print("Aucun coup possible trouvé.")
        return

    # Pause avant d'envoyer le coup
    await asyncio.sleep(pause_duration / 1000)

    move_message = {
        'type': 'reveal_cell',
        'game_id': current_game_id,
        'x': int(x),  # Conversion en int natif
        'y': int(y)   # Conversion en int natif
    }
    await websocket.send(json.dumps(move_message))

def display_board(board):
    width = len(board)
    height = len(board[0])

    # Calcul de l'espacement en fonction de la largeur maximale des numéros de colonne
    col_width = len(str(height - 1)) + 2  # Ajouter 2 pour inclure un espace de chaque côté

    # Afficher la ligne de numéros de colonnes
    print(" " + " " * (col_width - 1), end="")  # Espacement pour aligner avec les numéros de lignes
    for col_num in range(height):
        print(f"{col_num:>{col_width}}", end="")
    print()

    # Afficher la ligne supérieure de bordure
    print(" " * (col_width) + "+" + "-" * (col_width ) * height + "+")

    # Afficher les cellules avec numéros de ligne et bordures
    for x in range(width):
        # Numéro de la ligne sur 2 caractères pour l'alignement
        print(f"{x:>{col_width - 1}} |", end=" ")

        for y in range(height):
            cell = board[x][y]
            if cell['revealed']:
                if 'mine' in cell and cell['mine']:
                    print(" 💣 ", end="")  # Mine découverte
                elif cell.get('adjacentMines') is not None and cell['adjacentMines'] > 0:
                    print(f" {cell['adjacentMines']} ", end=" ")  # Nombre de mines adjacentes
                else:
                    print("   ", end=" ")  # Case révélée sans mines adjacentes
            elif cell.get('flagged', False):
                print(" 🚩 ", end="")  # Drapeau pour marquer une mine potentielle
            else:
                print(" . ", end=" ")  # Case non révélée

        # Bordure droite
        print("|")

    # Afficher la ligne inférieure de bordure
    print(" " * (col_width) + "+" + "-" * (col_width) * height + "+")


if __name__ == "__main__":
    uri = os.environ.get('MINESWEEPER_WS_URL', 'ws://127.0.0.1:8080')

    def request_stop(*_args):
        global stop_ai
        stop_ai = True

    signal.signal(signal.SIGTERM, request_stop)
    signal.signal(signal.SIGINT, request_stop)

    async def run_forever():
        delay = 1
        while not stop_ai:
            try:
                await connect_to_server(uri)
                delay = 1
            except (OSError, asyncio.TimeoutError, websockets.WebSocketException) as exc:
                logging.warning('Connexion WebSocket interrompue: %s', exc)
            if not stop_ai:
                await asyncio.sleep(delay)
                delay = min(delay * 2, 30)

    asyncio.run(run_forever())
