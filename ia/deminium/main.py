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
import hashlib
import shutil
import contextlib

# Variables globales
current_player_id = None
current_game_id = None
current_invitation_id = None
invited_player_id = None
stop_ai = False
consecutive_move_errors = 0

# Configuration par défaut
pause_duration = 1  # En millisecondes
invite_automatically = '--invite' in sys.argv or os.environ.get('IA_INVITE') == '1'
grid_size = '20x20'
selected_level = os.environ.get('IA_LEVEL', 'medium')
model_name = '2o1'  # Modèle par défaut
pause_duration = int(os.environ.get('IA_PAUSE_MS', pause_duration))

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
strategy_path = os.path.join(plugins_dir, 'move_strategy.py')
with open(strategy_path, 'rb') as strategy_file:
    strategy_source = strategy_file.read()
legacy_jimbo_hash = '19920605551e41174d8f853a8e7970d4761ac8e3d48bd5a129f6a1df0ca4f88a'
if model_name == 'Jimbo' and hashlib.sha256(strategy_source).hexdigest() == legacy_jimbo_hash:
    safe_template = os.path.join(os.path.dirname(__file__), 'plugins', '.template', 'move_strategy.py')
    migrated_path = strategy_path + '.migrated'
    shutil.copyfile(safe_template, migrated_path)
    os.replace(migrated_path, strategy_path)
    with open(strategy_path, 'rb') as strategy_file:
        strategy_source = strategy_file.read()
    logging.info('Stratégie Jimbo migrée automatiquement de pickle vers JSON.')
if b'import pickle' in strategy_source or b'pickle.load' in strategy_source:
    logging.critical('Stratégie refusée : utilisation de pickle interdite.')
    print("La stratégie utilise pickle et doit être migrée vers JSON.")
    sys.exit(1)
move_strategy_module = load_module('move_strategy', strategy_path)

# Créer une instance de MoveStrategy
legacy_memory = os.path.join(plugins_dir, 'memory.pkl')
if os.path.isfile(legacy_memory):
    # L'ancien format pickle permet l'exécution de code au chargement. Il est neutralisé.
    os.remove(legacy_memory)
move_strategy = move_strategy_module.MoveStrategy()

# La mémoire est gérée par le lanceur en JSON validé. Les anciennes stratégies
# ne sont plus autorisées à sérialiser avec pickle.
memory_path = os.path.join(plugins_dir, 'memory.json')
ai_memory = {'games': 0, 'wins': 0, 'losses': 0, 'draws': 0}
if os.path.isfile(memory_path) and os.path.getsize(memory_path) <= 65536:
    try:
        with open(memory_path, 'r', encoding='utf-8') as memory_file:
            loaded_memory = json.load(memory_file)
        if isinstance(loaded_memory, dict):
            for memory_key in ai_memory:
                memory_value = loaded_memory.get(memory_key)
                if isinstance(memory_value, int) and 0 <= memory_value <= 1_000_000_000:
                    ai_memory[memory_key] = memory_value
    except (OSError, ValueError, TypeError):
        logging.warning('Mémoire IA JSON invalide; réinitialisation.')

def save_ai_memory(winner_name, username):
    ai_memory['games'] += 1
    if winner_name == username:
        ai_memory['wins'] += 1
    elif winner_name in ('Egalité', 'Égalité'):
        ai_memory['draws'] += 1
    else:
        ai_memory['losses'] += 1
    temp_path = memory_path + '.tmp'
    with open(temp_path, 'w', encoding='utf-8') as memory_file:
        json.dump(ai_memory, memory_file, ensure_ascii=False, separators=(',', ':'))
        memory_file.flush()
        os.fsync(memory_file.fileno())
    os.replace(temp_path, memory_path)

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

            save_ai_memory(data['winner_name'], username)
            
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
    global pause_duration, consecutive_move_errors

    # Utilisation de la stratégie de mouvement pour choisir le coup
    available = [
        {'x': x, 'y': y}
        for x, row in enumerate(board)
        for y, cell in enumerate(row)
        if not cell.get('revealed', False) and not cell.get('flagged', False)
    ]
    decision_started = time.monotonic()
    if selected_level == 'easy' or (selected_level == 'medium' and random.random() < 0.25):
        best_move = random.choice(available) if available else None
    else:
        try:
            best_move = await asyncio.wait_for(asyncio.to_thread(move_strategy.choose_move, board), timeout=2.0)
            consecutive_move_errors = 0
        except Exception as exc:
            consecutive_move_errors += 1
            logging.error('Décision IA impossible (%s/5): %s', consecutive_move_errors, exc)
            best_move = random.choice(available) if available else None
            if consecutive_move_errors >= 5:
                logging.critical('Cinq erreurs de décision consécutives; temporisation de sécurité.')
                await asyncio.sleep(10)
                consecutive_move_errors = 0
    logging.info('Décision IA niveau=%s durée_ms=%.2f', selected_level, (time.monotonic() - decision_started) * 1000)

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

    async def run_forever():
        global stop_ai
        shutdown_event = asyncio.Event()
        loop = asyncio.get_running_loop()

        def request_stop():
            global stop_ai
            stop_ai = True
            shutdown_event.set()

        for shutdown_signal in (signal.SIGTERM, signal.SIGINT):
            loop.add_signal_handler(shutdown_signal, request_stop)

        delay = 1
        while not stop_ai:
            connection_task = asyncio.create_task(connect_to_server(uri))
            shutdown_task = asyncio.create_task(shutdown_event.wait())
            try:
                done, _pending = await asyncio.wait(
                    (connection_task, shutdown_task),
                    return_when=asyncio.FIRST_COMPLETED,
                )
                if shutdown_task in done:
                    connection_task.cancel()
                    with contextlib.suppress(asyncio.CancelledError):
                        await connection_task
                    break
                await connection_task
                delay = 1
            except (OSError, asyncio.TimeoutError, websockets.WebSocketException) as exc:
                logging.warning('Connexion WebSocket interrompue: %s', exc)
            finally:
                shutdown_task.cancel()
                with contextlib.suppress(asyncio.CancelledError):
                    await shutdown_task
            if not stop_ai:
                try:
                    await asyncio.wait_for(shutdown_event.wait(), timeout=delay)
                except asyncio.TimeoutError:
                    pass
                delay = min(delay * 2, 30)

    asyncio.run(run_forever())
