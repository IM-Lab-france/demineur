# move_strategy.py

import numpy as np
import os
import json
import random
from collections import deque
from itertools import combinations
from copy import deepcopy

class MoveStrategy:
    def __init__(self):
        # Initialisation de l'IA
        plugin_dir = os.path.dirname(__file__)
        self.memory_path = os.path.join(plugin_dir, 'memory.json')
        self.state_size = (10, 10)  # Taille du plateau : 10x10
        self.total_mines = 15  # Nombre total de mines (à ajuster selon la difficulté)
        self.level = 'medium'
        
        # Charger la mémoire si elle existe
        self.memory = {'games': 0, 'wins': 0, 'losses': 0, 'draws': 0}
        if os.path.isfile(self.memory_path) and os.path.getsize(self.memory_path) <= 65536:
            try:
                with open(self.memory_path, 'r', encoding='utf-8') as f:
                    loaded = json.load(f)
                if isinstance(loaded, dict):
                    for key in self.memory:
                        value = loaded.get(key)
                        if isinstance(value, int) and 0 <= value <= 1_000_000_000:
                            self.memory[key] = value
            except (OSError, ValueError, TypeError):
                pass

        # Variables pour la partie en cours
        self.known_mines = set()
        self.known_safe = set()
        self.flags = set()
        self.uncovered = set()
        self.board = None

    def beginGame(self, board, mine_count=None):
        # Réinitialiser les variables pour une nouvelle partie
        self.known_mines = set()
        self.known_safe = set()
        self.flags = set()
        self.uncovered = set()
        self.board = board
        if isinstance(mine_count, int) and mine_count >= 0:
            self.total_mines = mine_count

    def choose_move(self, board):
        self.board = board
        width = len(board)
        height = len(board[0])

        # Mettre à jour les cellules découvertes et les drapeaux
        for x in range(width):
            for y in range(height):
                cell = board[x][y]
                if cell['revealed']:
                    self.uncovered.add((x, y))
                    if 'mine' in cell and cell['mine']:
                        self.known_mines.add((x, y))
                if cell.get('flagged', False):
                    self.flags.add((x, y))

        # Retirer les cellules révélées des ensembles known_safe et known_mines
        self.known_safe -= self.uncovered
        self.known_mines -= self.uncovered

        # Appliquer la logique déterministe pour trouver des coups sûrs
        progress = True
        while progress:
            progress = self.apply_deterministic_logic()

        # Signaler d'abord une mine déduite qui n'est pas encore marquée sur le serveur.
        flag_candidates = self.known_mines - self.flags - self.uncovered
        if flag_candidates:
            move = flag_candidates.pop()
            return {'x': move[0], 'y': move[1], 'action': 'flag'}

        # Si des coups sûrs sont trouvés, en choisir un
        if self.known_safe:
            move = self.known_safe.pop()
            print(f"Action sûre choisie : ({move[0]}, {move[1]})")
            # Vérifier que la cellule n'est pas révélée ou marquée
            if move not in self.uncovered and move not in self.flags:
                return {'x': move[0], 'y': move[1]}
            else:
                # Si la cellule est déjà révélée ou marquée, continuer la recherche
                return self.choose_move(board)
        else:
            # Aucune action sûre, utiliser la méthode probabiliste
            move = self.probabilistic_choice()
            flag_candidates = self.known_mines - self.flags - self.uncovered
            if flag_candidates:
                flag = flag_candidates.pop()
                return {'x': flag[0], 'y': flag[1], 'action': 'flag'}
            if move:
                print(f"Action probabiliste choisie : ({move[0]}, {move[1]})")
                # Vérifier que la cellule n'est pas révélée ou marquée
                if move not in self.uncovered and move not in self.flags:
                    return {'x': move[0], 'y': move[1]}
                else:
                    # Si la cellule est déjà révélée ou marquée, continuer la recherche
                    return self.choose_move(board)
            else:
                # En dernier recours, choisir une cellule non révélée au hasard
                possible_moves = [
                    (x, y) for x in range(width) for y in range(height)
                    if (x, y) not in self.uncovered and (x, y) not in self.flags
                ]
                if possible_moves:
                    move = random.choice(possible_moves)
                    print(f"Aucune action sûre ou probabiliste, choix aléatoire : ({move[0]}, {move[1]})")
                    return {'x': move[0], 'y': move[1]}
                else:
                    print("Aucun coup possible trouvé.")
                    return None

    def endGame(self, winner_name, username):
        self.memory['games'] += 1
        if winner_name == username:
            self.memory['wins'] += 1
        elif winner_name in ('Egalité', 'Égalité'):
            self.memory['draws'] += 1
        else:
            self.memory['losses'] += 1
        temp_path = self.memory_path + '.tmp'
        with open(temp_path, 'w', encoding='utf-8') as f:
            json.dump(self.memory, f, ensure_ascii=False, separators=(',', ':'))
            f.flush()
            os.fsync(f.fileno())
        os.replace(temp_path, self.memory_path)

    def apply_deterministic_logic(self):
        progress = False
        width = len(self.board)
        height = len(self.board[0])

        for x in range(width):
            for y in range(height):
                cell = self.board[x][y]
                if cell['revealed'] and cell['adjacentMines'] > 0:
                    # Obtenir les cellules adjacentes
                    neighbors = self.get_neighbors(x, y)
                    unrevealed = [n for n in neighbors if n not in self.uncovered and n not in self.flags]
                    flagged = [n for n in neighbors if n in self.flags]
                    if len(flagged) == cell['adjacentMines']:
                        # Les autres cellules non révélées sont sûres
                        for n in unrevealed:
                            if n not in self.known_safe and n not in self.uncovered:
                                self.known_safe.add(n)
                                progress = True
                    elif len(unrevealed) + len(flagged) == cell['adjacentMines']:
                        # Toutes les cellules non révélées sont des mines
                        for n in unrevealed:
                            if n not in self.known_mines and n not in self.uncovered:
                                self.known_mines.add(n)
                                progress = True
        return progress

    def get_neighbors(self, x, y):
        neighbors = []
        width = len(self.board)
        height = len(self.board[0])
        for dx in [-1, 0, 1]:
            for dy in [-1, 0, 1]:
                nx, ny = x + dx, y + dy
                if (dx != 0 or dy != 0) and 0 <= nx < width and 0 <= ny < height:
                    neighbors.append((nx, ny))
        return neighbors

    def probabilistic_choice(self):
        # Générer une carte de probabilités
        prob_map = self.calculate_exact_probabilities() if self.level == 'master' else self.calculate_probabilities()
        if not prob_map:
            return None
        if self.level == 'master':
            for cell, probability in prob_map.items():
                if probability >= 1.0:
                    self.known_mines.add(cell)
                elif probability <= 0.0:
                    self.known_safe.add(cell)
            prob_map = {cell: probability for cell, probability in prob_map.items() if probability < 1.0}
            if self.known_safe:
                return self.known_safe.pop()
        # Trouver les cellules avec la probabilité minimale
        min_prob = min(prob_map.values())
        min_cells = [cell for cell, prob in prob_map.items() if prob == min_prob]
        # Choisir l'une d'entre elles
        for cell in min_cells:
            if cell not in self.uncovered and cell not in self.flags:
                return cell
        return None

    def calculate_exact_probabilities(self, max_component_size=18, max_solutions=200000):
        """Résout exactement les composantes de frontière de taille raisonnable.

        Les composantes trop grandes sont volontairement ignorées : le niveau
        maître reste ainsi borné en CPU et peut utiliser le calcul simplifié en
        solution de repli.
        """
        constraints = []
        frontier = set()
        for x in range(len(self.board)):
            for y in range(len(self.board[0])):
                cell = self.board[x][y]
                if not cell['revealed'] or cell.get('adjacentMines', 0) <= 0:
                    continue
                neighbors = self.get_neighbors(x, y)
                unknown = {
                    neighbor for neighbor in neighbors
                    if neighbor not in self.uncovered and neighbor not in self.flags
                }
                required = cell['adjacentMines'] - sum(1 for neighbor in neighbors if neighbor in self.flags)
                if unknown and 0 <= required <= len(unknown):
                    constraints.append((unknown, required))
                    frontier.update(unknown)
        if not constraints:
            return self.calculate_probabilities()

        # Regrouper les variables reliées par au moins une même contrainte.
        components = []
        remaining = set(frontier)
        while remaining:
            component = {remaining.pop()}
            changed = True
            while changed:
                changed = False
                for cells, _required in constraints:
                    if component.intersection(cells) and not cells.issubset(component):
                        additions = cells - component
                        component.update(additions)
                        remaining.difference_update(additions)
                        changed = True
            components.append(component)

        probabilities = {}
        for component in components:
            if len(component) > max_component_size:
                continue
            variables = list(component)
            relevant = [(cells & component, required) for cells, required in constraints if cells & component]
            mine_counts = {cell: 0 for cell in variables}
            solution_count = 0
            for mask in range(1 << len(variables)):
                if solution_count >= max_solutions:
                    break
                mines = {variables[index] for index in range(len(variables)) if mask & (1 << index)}
                if all(len(mines & cells) == required for cells, required in relevant):
                    solution_count += 1
                    for mine in mines:
                        mine_counts[mine] += 1
            if solution_count:
                for cell, count in mine_counts.items():
                    probabilities[cell] = count / solution_count

        if not probabilities:
            return self.calculate_probabilities()
        return probabilities

    def calculate_probabilities(self):
        # Pour simplifier, limiter aux cellules en frontière
        frontier = set()
        for x in range(len(self.board)):
            for y in range(len(self.board[0])):
                if self.board[x][y]['revealed'] and self.board[x][y]['adjacentMines'] > 0:
                    neighbors = self.get_neighbors(x, y)
                    for n in neighbors:
                        if n not in self.uncovered and n not in self.flags:
                            frontier.add(n)
        if not frontier:
            return None
        # Calculer les probabilités de manière simplifiée
        remaining_mines = self.total_mines - len(self.flags)
        remaining_cells = len([
            (x, y) for x in range(len(self.board)) for y in range(len(self.board[0]))
            if (x, y) not in self.uncovered and (x, y) not in self.flags
        ])
        if remaining_cells == 0:
            return None
        default_prob = remaining_mines / remaining_cells
        prob_map = {cell: default_prob for cell in frontier}
        return prob_map
