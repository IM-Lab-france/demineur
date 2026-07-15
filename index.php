<?php
// Vérifiez si le répertoire 'vendor' existe
if (!is_dir(__DIR__ . '/vendor')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Application non installée. Exécutez la procédure CLI décrite dans README.md.';
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="ws-url" content="<?= htmlspecialchars(getenv('WS_PUBLIC_URL') ?: '/ws', ENT_QUOTES, 'UTF-8') ?>">
    <title>Démineur Multijoueur</title>
    <link href="/assets/vendor/bootstrap/4.5.2/bootstrap.min.css" rel="stylesheet"> <!-- Bootstrap -->
    <link rel="preload" href="img/background2.webp" as="image" type="image/webp" fetchpriority="high">
    <link rel="stylesheet" href="styles.css?v=<?= (int) filemtime(__DIR__ . '/styles.css') ?>"> <!-- Lien vers le fichier CSS -->
    <!-- Ajout du favicon -->
    <link rel="icon" type="image/png" sizes="64x64" href="favicon-64.png">
</head>
<body class="bg-light"> <!-- Change background to a light color -->

    <!-- Inclusion du menu commun -->
    <nav id="navbar" class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
        <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ml-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/">Jouer</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="scores.html">Scores</a>
                </li>
                <li>
                    <button id="muteButton" class="btn btn-light" aria-label="Couper ou rétablir le son">🔊</button>
                </li>
                <li class="nav-item">
                    <button id="logoutLink" class="btn btn-link nav-link text-danger" type="button">Déconnexion</button>
                </li>
            </ul>
        </div>
        <span id="welcomeMessage" class="navbar-text ml-auto pr-3">Bienvenue, <span id="navbarUserDisplay"></span></span>
    </nav>

    <!-- Aide Overlay -->
    <div id="connectionStatus" class="connection-status" role="status" aria-live="polite">Connexion…</div>
    <div id="helpOverlay" class="help-overlay" role="dialog" aria-modal="true" aria-labelledby="helpTitle">
        <div class="help-content">
            <h2 id="helpTitle">❓ Besoin d'aide ?</h2>
            <p>Bienvenue dans le démineur multijoueur ! Voici comment jouer, et n'oubliez pas : on vous observe...</p>
            <ul>
                <li>
                    🖱️ <strong>Cliquez gauche</strong> sur une case pour la révéler. Révélez-les toutes, si vous osez.
                </li>
                <li>
                    🚩 <strong>Cliquez droit</strong> pour placer un drapeau sur une case suspecte. Mais attention, les erreurs coûtent cher...
                </li>
                <li>
                    💣 Évitez de cliquer sur les mines, sauf si vous voulez voir ce qui se passe...
                </li>
                <li>
                    🤝 Si toutes les cases sûres sont révélées sans explosion, la partie se termine par une égalité.
                </li>
                <li>
                    🔄 Le jeu se joue à tour de rôle avec votre adversaire. Ne le faites pas attendre trop longtemps, il pourrait s'impatienter.
                </li>
                <li>
                    🔊 Le son vous fait peur ? Vous pouvez toujours le couper en cliquant sur l'icône 🔇, mais où est le fun sans quelques frissons ?
                </li>
            </ul>
            <label>
                <input type="checkbox" id="dontShowHelpAgain">
                Ne plus me montrer l'aide
            </label>
            <button id="closeHelpBtn" class="btn btn-primary">Fermer</button>
        </div>
    </div>
    <!-- Modal de Connexion -->
    <div id="loginModal" class="modal hidden">
        <div class="modal-content">
            <img src="img/demineur.png" alt="Logo du Démineur">
            <p>Connectez-vous pour rejoindre l'aventure.</p>
            <div class="form-group">
                <input type="text" id="loginUsername" class="form-control" placeholder="Nom d'utilisateur">
            </div>
            <div class="form-group">
                <input type="password" id="loginPassword" class="form-control" placeholder="Mot de passe">
            </div>
            <button id="loginBtn" class="btn btn-primary">Connexion</button>
            <p>Pas encore de compte ? <a href="#" id="showRegisterModal">Créez-en un ici !</a></p>
            <p id="loginError" class="text-danger"></p>
            <p id="creationOk" class="text-success"></p>
        </div>
    </div>
    <!-- Modal de Création de Compte -->
    <div id="registerModal" class="modal hidden">
        <div class="modal-content">
            <h2>Rejoignez-nous !</h2>
            <p>Créez un compte pour commencer à jouer.</p>
            <div class="form-group">
                <input type="text" id="registerUsername" class="form-control" placeholder="Choisissez un nom d'utilisateur">
            </div>
            <div class="form-group">
                <input type="password" id="registerPassword" class="form-control" placeholder="Choisissez un mot de passe">
            </div>
            <button id="registerBtn" class="btn btn-secondary">Créer mon compte</button>
            <p>Déjà un compte ? <a href="#" id="showLoginModal">Connectez-vous ici !</a></p>
            <p id="registerError" class="text-danger"></p>
        </div>
</div>
    <!-- Zone de jeu -->
    <div id="game" class="container text-center mt-5">
                
        <!-- Liste des joueurs connectés -->
        <div id="availableUser" class="my-3">
            <h3>Joueurs disponibles</h3>
            <ul id="players" class="list-group"></ul>
        </div>

        <!-- Invitation -->
        <div id="invitation" class="alert alert-info ">
            <p>Invitation reçue de <span id="inviter"></span></p>
            <button id="acceptInviteBtn" class="btn btn-success">Accepter</button>
            <button id="declineInviteBtn" class="btn btn-danger">Refuser</button>
        </div>

        <!-- Plateau de jeu -->
        <div id="gameContainer">
            <div id="gameStatus" aria-live="polite">
                <div id="currentTurnDisplay"></div>
                <div id="gameCounters"><span id="mineCounter">💣 0</span><span id="flagCounter">🚩 0</span></div>
            </div>
            <div id="plateau">
                <div id="gameBoard"></div> <!-- Plateau de jeu -->
            </div>
        </div>
    </div>

    <!-- Modal for winner -->
    <div id="winnerModal">
        <div id="winnerModalContent">
            <h2 id="winnerMessage"></h2>
            <button id="closeModalBtn" class="btn btn-primary">Fermer</button>
        </div>
    </div>

    <div id="notYourTurnPopup" class="popup">Ce n'est pas à vous de jouer</div>

    <!-- Div pour afficher les messages WebSocket -->
    <div id="messages" class="container-fluid"></div>

    <!-- Popin pour choisir la taille de la grille et la difficulté -->
    <div id="inviteSettingsModal" class="modal hidden">
        <div class="modal-content invite-modal">
            <span class="close" id="closeInviteSettings">&times;</span>
            <h3>Choisir les paramètres de la partie</h3>
            <form id="inviteSettingsForm">
                <div class="form-group">
                    <label for="gridSize">Taille de la grille :</label>
                    <select id="gridSize" class="form-control">
                        <option value="10x10">10x10</option>
                        <option value="20x20">20x20</option>
                        <option value="30x30">30x30</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="difficulty">Difficulté :</label>
                    <select id="difficulty" class="form-control">
                        <option value="10">Facile</option>
                        <option value="15">Moyen</option>
                        <option value="22">Difficile</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary">Envoyer l'invitation</button>
            </form>
        </div>
    </div>

    

    <!-- Icône du Point d'Interrogation -->
    <div id="helpIcon" class="help-icon hidden" role="button" aria-label="Afficher l'aide" tabindex="0">❓</div>

    <!-- jQuery, Popper.js, and Bootstrap JS -->
    <script defer src="/assets/vendor/jquery/jquery-3.5.1.slim.min.js"></script>
    <script defer src="/assets/vendor/bootstrap/4.5.2/bootstrap.bundle.min.js"></script>
    <script defer src="script.js?v=<?= (int) filemtime(__DIR__ . '/script.js') ?>"></script> <!-- Lien vers le fichier JavaScript -->
</body>
</html>
