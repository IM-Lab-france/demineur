# move_strategy.py

from abc import ABC, abstractmethod
import random

class MoveStrategy(ABC):
    @abstractmethod
    def choose_move(self, board):
        pass
    
    @abstractmethod
    def beginGame(self, board):
        pass

    @abstractmethod
    def endGame(self, winner_name, ai_name):
        pass

class MemoryManager:
    def __init__(self):
        self.moves = []  # Liste pour stocker les coups joués, seulement en mémoire

    def save_move(self, x, y):
        """Ajouter un coup joué à la liste (stocké en mémoire uniquement)"""
        move = {'x': x, 'y': y}
        self.moves.append(move)
        print(f"Move saved in memory: {move}")

    def reset_memory(self):
        """Réinitialiser la mémoire des coups"""
        self.moves = []

class MoveStrategy(MoveStrategy):
    def __init__(self):
        self.memory_manager = MemoryManager()  # Initialiser le gestionnaire en mémoire uniquement
    
    def beginGame(self, board):
        """Méthode appelée au début de la partie"""
        print("Game started for probabilistic strategy.")
        self.memory_manager.reset_memory()  # Réinitialiser la mémoire au début d'une nouvelle partie

    def endGame(self, winner_name, ai_name):
        """Méthode appelée à la fin de la partie"""
        print("Game ended for probabilistic strategy.")
        
    def choose_move(self, board):
        """Choisit un coup sécurisé basé sur une approche logique déterministe"""
        width = len(board)
        height = len(board[0])
        possible_moves = []
        safe_moves = []

        # Fonction pour obtenir les voisins d'une case
        def get_neighbors(x, y):
            neighbors = []
            for dx in range(-1, 2):
                for dy in range(-1, 2):
                    if dx == 0 and dy == 0:
                        continue
                    nx, ny = x + dx, y + dy
                    if 0 <= nx < width and 0 <= ny < height:
                        neighbors.append((nx, ny))
            return neighbors

        # Vérifier la sécurité des cases en utilisant la logique déterministe
        for x in range(width):
            for y in range(height):
                cell = board[x][y]
                if cell['revealed'] and 'adjacentMines' in cell:
                    adjacent_mines = cell['adjacentMines']
                    neighbors = get_neighbors(x, y)

                    # Compter les drapeaux et les cases non révélées autour de cette case
                    flagged_neighbors = 0
                    unrevealed_neighbors = []
                    for nx, ny in neighbors:
                        neighbor_cell = board[nx][ny]
                        if neighbor_cell['flagged']:
                            flagged_neighbors += 1
                        elif not neighbor_cell['revealed']:
                            unrevealed_neighbors.append((nx, ny))

                    # Règle 1: Toutes les mines adjacentes sont marquées, révéler les autres cases
                    if flagged_neighbors == adjacent_mines and unrevealed_neighbors:
                        safe_moves.extend(unrevealed_neighbors)

                    # Règle 2: Si toutes les cases non révélées sont des mines
                    if len(unrevealed_neighbors) == adjacent_mines - flagged_neighbors:
                        for nx, ny in unrevealed_neighbors:
                            # Marquer ces cases comme des mines (les flagger)
                            board[nx][ny]['flagged'] = True
                            print(f"Marked ({nx}, {ny}) as mine")

        # Si des coups sûrs ont été trouvés, en choisir un
        if safe_moves:
            x, y = safe_moves[0]  # Choisir le premier coup sécurisé
            print(f"Safe move chosen at ({x}, {y})")
        else:
            # Si aucun coup sûr n'est trouvé, jouer aléatoirement parmi les cases non révélées
            for x in range(width):
                for y in range(height):
                    cell = board[x][y]
                    if not cell['revealed'] and not cell['flagged']:
                        possible_moves.append((x, y))

            x, y = random.choice(possible_moves)
            print(f"Random move chosen at ({x}, {y})")

        # Sauvegarder le coup joué uniquement en mémoire
        self.memory_manager.save_move(x, y)

        return {'x': x, 'y': y}
