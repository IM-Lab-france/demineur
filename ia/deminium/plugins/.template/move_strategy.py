# move_strategy.py

import numpy as np
import os
import json
import random
import pickle
from collections import deque
from itertools import combinations
from copy import deepcopy

class MoveStrategy:
    def __init__(self):
        # Initialisation de l'IA
        plugin_dir = os.path.dirname(__file__)
        self.memory_path = os.path.join(plugin_dir, 'memory.pkl')
        self.state_size = (10, 10)  # Taille du plateau : 10x10
        self.total_mines = 15  # Nombre total de mines (à ajuster selon la difficulté)
        
        # Charger la mémoire si elle existe
        if os.path.isfile(self.memory_path):
            with open(self.memory_path, 'rb') as f:
                self.memory = pickle.load(f)
        else:
            self.memory = {}  # Initialiser une mémoire vide

        # Variables pour la partie en cours
        self.known_mines = set()
        self.known_safe = set()
        self.flags = set()
        self.uncovered = set()
        self.board = None

    def beginGame(self, board):
        # Réinitialiser les variables pour une nouvelle partie
        self.known_mines = set()
        self.known_safe = set()
        self.flags = set()
        self.uncovered = set()
        self.board = board

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
        # Enregistrer la mémoire pour la prochaine session
        plugin_dir = os.path.dirname(__file__)
        self.memory_path = os.path.join(plugin_dir, 'memory.pkl')
        with open(self.memory_path, 'wb') as f:
            pickle.dump(self.memory, f)

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
                                self.flags.add(n)
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
        prob_map = self.calculate_probabilities()
        if not prob_map:
            return None
        # Trouver les cellules avec la probabilité minimale
        min_prob = min(prob_map.values())
        min_cells = [cell for cell, prob in prob_map.items() if prob == min_prob]
        # Choisir l'une d'entre elles
        for cell in min_cells:
            if cell not in self.uncovered and cell not in self.flags:
                return cell
        return None

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
