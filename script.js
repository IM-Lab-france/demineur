// script.js

let socket;
let username;
let currentGameId;
let refreshInterval;
let connected = false;
let currentPlayerId;
let currentInvitationId = null;
let errorDiv = null;
let retryInterval = 5000;
let keepAliveInterval; 
let isReconnecting = false;

let isMuted = false;


// gestion des sons
const soundClick = new Audio('sounds/click.mp3');
const soundMine = new Audio('sounds/mine.mp3');
const soundFlag = new Audio('sounds/flag.mp3');
const soundWin = new Audio('sounds/win.mp3');
const soundLose = new Audio('sounds/lose.mp3');
const soundTie = new Audio('sounds/tie.mp3');
const muteButton = document.getElementById('muteButton');

const loginModal = document.getElementById('loginModal');
const registerModal = document.getElementById('registerModal');
const showRegisterModalLink = document.getElementById('showRegisterModal');
const showLoginModalLink = document.getElementById('showLoginModal');

const loginBtn = document.getElementById('loginBtn');
const registerBtn = document.getElementById('registerBtn');

const loginError = document.getElementById('loginError');
const registerError = document.getElementById('registerError');

// Fonction pour afficher le modal de connexion
function showLoginModal() {
    registerModal.classList.add('hidden');
    loginModal.classList.remove('hidden');
}

// Fonction pour afficher le modal de création de compte
function showRegisterModal() {
    loginModal.classList.add('hidden');
    registerModal.classList.remove('hidden');
}

// Afficher le modal de connexion au chargement
window.addEventListener('load', () => {
    showLoginModal();
});

// Écouteurs pour les liens
showRegisterModalLink.addEventListener('click', (e) => {
    e.preventDefault();
    showRegisterModal();
});

showLoginModalLink.addEventListener('click', (e) => {
    e.preventDefault();
    showLoginModal();
});

muteButton.addEventListener('click', () => {
    isMuted = !isMuted;
    const newIcon = isMuted ? '🔇' : '🔊';
    muteButton.textContent = newIcon;

    // Mute ou unmute tous les sons
    [soundClick, soundMine, soundFlag, soundWin, soundLose,soundTie].forEach(sound => {
        sound.muted = isMuted;
    });
});

// gestion de l'aide en jeu
// Fonction pour afficher l'overlay d'aide
function showHelpOverlay() {
    const overlay = document.getElementById('helpOverlay');
    overlay.style.display = 'flex'; // Assurez-vous que l'overlay est affiché
    // Force un reflow pour que la transition fonctionne
    overlay.offsetHeight; // Déclenche un reflow
    overlay.classList.add('show'); // Ajoute la classe pour démarrer la transition
}

// Fonction pour cacher l'overlay d'aide
function hideHelpOverlay() {
    const overlay = document.getElementById('helpOverlay');
    overlay.classList.remove('show'); // Retire la classe pour démarrer la transition de disparition
    // Une fois la transition terminée, cacher l'overlay
    overlay.addEventListener('transitionend', function handler() {
        overlay.style.display = 'none'; // Cache l'overlay après la transition
        overlay.removeEventListener('transitionend', handler); // Retire l'écouteur pour éviter les appels multiples
    });
}

// Vérifier la préférence de l'utilisateur
function checkHelpPreference() {
    const dontShowHelp = localStorage.getItem('dontShowHelpAgain');
    if (dontShowHelp !== 'true') {
        showHelpOverlay();
    }
}

// Écouteur pour le bouton 'Fermer' de l'aide
document.getElementById('closeHelpBtn').addEventListener('click', () => {
    const dontShowAgain = document.getElementById('dontShowHelpAgain').checked;
    if (dontShowAgain) {
        localStorage.setItem('dontShowHelpAgain', 'true');
    }
    hideHelpOverlay();
});

// Écouteur pour l'icône du point d'interrogation
document.getElementById('helpIcon').addEventListener('click', () => {
    showHelpOverlay();
});
function hideHelpIcon() {
    const helpIcon = document.getElementById('helpIcon');
    helpIcon.classList.add('hidden');
}
function showHelpIcon() {
    const helpIcon = document.getElementById('helpIcon');
    helpIcon.classList.remove('hidden');
}

// Fonction pour afficher les messages WebSocket dans la div messages
function logMessage(message) {
    const messageDiv = document.getElementById('messages');
    const messageElement = document.createElement('p');
    messageElement.textContent = message;
    messageDiv.appendChild(messageElement);
    console.log(message);
    // Faire défiler automatiquement vers le bas quand un nouveau message est ajouté
    messageDiv.scrollTop = messageDiv.scrollHeight;
}

// Connexion WebSocket
function connectWebSocket() {

    // Récupérer le nom d'hôte (domaine ou IP)
    const hostname = window.location.hostname;

    // Déterminer le protocole WebSocket et le port en fonction de l'URL
    let wsProtocol = 'ws';
    let wsPort = '8080'; // Par défaut pour les IP locales

    // Si l'hôte est un domaine (fozzy.fr par exemple), utiliser wss (WebSocket sécurisé) et le port 9443
    console.log("Tentative de connexion au server : " + hostname)
    
    if (hostname === 'fozzy.fr') {
        wsProtocol = 'wss';
        wsPort = '9443';

    }

    // Construire l'URL du WebSocket
    const wsUrl = `${wsProtocol}://${hostname}:${wsPort}`;
    console.log("URL du WebSocket construite : " + wsUrl);

    try {
        socket = new WebSocket(wsUrl);
        console.log("Objet WebSocket créé avec l'URL : " + wsUrl);
    } catch (error) {
        console.error("Erreur lors de la création du WebSocket : ", error);
        showConnectionError();
        attemptReconnect();
        return;
    }

    socket.onopen = function() {
        console.log("WebSocket connecté : " + wsUrl);
        connected = true;
        isReconnecting = false;
        hideConnectionError(); // Afficher le formulaire de connexion
        showLoginModal();

        keepAliveInterval = setInterval(function() {
            if (socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({ type: 'ping' }));
                console.log('Ping envoyé au serveur');
            }
        }, 15000);
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        logMessage('Message reçu du serveur: ' + JSON.stringify(data));

        switch (data.type) {
            case 'pong':
                console.log("Pong reçu du serveur");
                break;

            case 'login_failed':
                // Afficher le message d'erreur et réinitialiser les inputs
                loginError.textContent = 'Nom d\'utilisateur ou mot de passe incorrect.';
                document.getElementById('username').value = '';
                document.getElementById('password').value = '';
                break;

            case 'login_success':
                currentPlayerId = data.playerId; 
                // Masquer la section de connexion et afficher la liste des joueurs
                hideModal(loginModal); 
                document.getElementById('game').style.display = 'block';
                document.getElementById('navbarUserDisplay').textContent = data.username;
                document.getElementById('navbar').style.display = 'block';
                document.getElementById('welcomeMessage').style.display = 'block';
                document.getElementById('logoutLink').style.display = 'block';
                document.getElementById('availableUser').style.display = 'block';
                refreshPlayersList(data.players);
                break;

            case 'register_success':
                registerError.textContent = 'Compte créé avec succès ! Vous pouvez maintenant vous connecter.';
                // Afficher le bouton de connexion
                document.getElementById('creationOk').textContent = "Compte créé avec succès. Vous pouvez vous connecter !"
                showLoginModal();
                break;

            case 'register_failed':
                registerError.textContent = 'Erreur lors de la création du compte : ' + data.message;
                break;

            case 'connected_players':
                
                // Rafraîchir la liste des joueurs connectés
                refreshPlayersList(data.players);
                break;

            case 'game_start':

                // Stocker le game_id pour les futures actions
                document.getElementById('availableUser').style.display = 'none';
                document.getElementById('gameContainer').style.display = 'flex';
                currentGameId = data.game_id;
                displayGameBoard(data.board);

                // Afficher le joueur qui commence
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = 'C\'est à ' + data.currentPlayer + ' de commencer.'; // Afficher qui commence


                logMessage('Tour actuel: ' + data.turn);

                showHelpIcon();
                checkHelpPreference();

                break;

            case 'update_board':
                displayGameBoard(data.board);
                // Mettre à jour le nom du joueur dont c'est le tour
                currentPlayerDisplay = document.getElementById('currentTurnDisplay');
                currentPlayerDisplay.textContent = 'Tour actuel: ' + data.currentPlayer; // Affiche le joueur actuel
                break;

            case 'invite':
                document.getElementById('inviter').textContent = data.inviter;
                document.getElementById('invitation').style.display = 'block';
                currentInvitationId = data.invitationId;
                break;

            case 'invite_declined':
                logMessage('Invitation refusée par le joueur.');
                break;

            case 'game_over':

                if (data.winner.includes('Vous avez gagné')) {
                    soundWin.play();
                } else if (data.winner.includes('La partie se termine par une égalité!')) {
                    soundTie.play();
                } else {
                    soundLose.play();
                }
                // Fin de partie et affichage du gagnant
                displayGameBoard(data.board, data.losingCell);
                showWinnerModal(data.winner, data.game_id);
            
                // Révéler toutes les cellules (mines et chiffres)
                revealAllCells(data.board);
                hideHelpIcon();
                break;
                // Ajout de la gestion de la déconnexion d'un joueur
            case 'player_disconnected':
                logMessage('Votre adversaire s\'est déconnecté. La partie est annulée.');
                showWinnerModal('Votre adversaire s\'est déconnecté. La partie est annulée.', currentGameId);
                break;
            case 'logout_success':
                handleLogoutSuccess(data);
                break;

            case 'error':
            if (data.message === "Ce n'est pas votre tour de jouer.") {
                    showNotYourTurnPopup();
                }
                logMessage('Erreur: ' + data.message);
                break;

           
        }
    };

    // Gestion de la fermeture ou de l'erreur de connexion WebSocket
    socket.onerror = function() {
        logMessage('Impossible de se connecter au serveur WebSocket.');
        showConnectionError();
        attemptReconnect(); // Essayer de se reconnecter
    };

    socket.onclose = function() {
        logMessage('Connexion WebSocket fermée.');
        connected = false; // Indiquer que le client est déconnecté
        showConnectionError();
        clearInterval(keepAliveInterval);
        attemptReconnect(); // Essayer de se reconnecter
    };

    // Vérifier l'état du WebSocket périodiquement pour plus de détails
    setInterval(() => {
        console.log("État périodique du WebSocket : ", socket.readyState);
    }, 5000);
}


// Masquer le formulaire de login si le serveur est inaccessible
function hideLoginForm() {
    document.getElementById('loginModal').classList.add('hidden');
}

// Afficher le formulaire de login une fois que le serveur est disponible
function showLoginForm() {
    document.getElementById('loginModal').classList.remove('hidden');
}


// Fonction pour essayer de se reconnecter régulièrement si déconnecté
function attemptReconnect() {
    if (!connected && !isReconnecting) { // Vérifie si déjà en cours de reconnexion
        isReconnecting = true; // Empêche les nouvelles tentatives pendant la reconnexion
        setTimeout(function() {
            logMessage('Tentative de reconnexion au serveur...');
            connectWebSocket(); // Tente une reconnexion
            isReconnecting = false; // Reset une fois terminé
        }, retryInterval);
    }
}


// Fonction pour afficher un message d'erreur et masquer le formulaire de login
function showConnectionError() {
    if (!errorDiv) {

        hideLoginForm();

        errorDiv = document.createElement('div');
        errorDiv.classList.add('connection-error', 'text-center'); // Classe CSS pour styliser le message
        errorDiv.innerHTML = `
            <h2>Oups !</h2>
            <p>On dirait que notre serveur est en pause café ☕. Pas de panique, il sera de retour très bientôt !</p>
            <p>Nous tentons de le reconnecter…</p>
        `;
        const container = document.createElement('div');
        container.id = 'errorContainer'; // ID pour faciliter la suppression
        container.classList.add('container', 'd-flex', 'justify-content-center', 'align-items-center', 'vh-100');
        container.appendChild(errorDiv);
        document.body.appendChild(container); // Afficher le message d'erreur
    }
}

// Fonction pour masquer le message d'erreur de connexion
function hideConnectionError() {
    if (errorDiv) {
        const container = document.getElementById('errorContainer');
        if (container) {
            container.remove(); // Supprimer le conteneur
        }
        errorDiv = null; // Réinitialiser la référence de l'erreur
    }
    showLoginForm();
}



function revealAllCells(board) {
    board.forEach((row, x) => {
        row.forEach((cell, y) => {
            cell.revealed = true; // Marquer chaque cellule comme révélée
        });
    });

    // Après avoir marqué toutes les cellules comme révélées, on réaffiche le plateau
    displayGameBoard(board);
}

// Rafraîchir la liste des joueurs connectés
function refreshPlayersList(players) {
    const playersList = document.getElementById('players');
    playersList.innerHTML = '';
    const filteredPlayers = players.filter(player => player.id !== currentPlayerId);
    
    if (filteredPlayers.length === 0) {
        // Si aucun autre joueur en ligne, afficher le message
        const li = document.createElement('li');
        li.textContent = 'Aucun joueur en ligne';
        playersList.appendChild(li);
    } else {
        filteredPlayers.forEach(player => {
            const li = document.createElement('li');
            li.textContent = player.username;
            li.dataset.playerId = player.id;
            li.addEventListener('click', () => invitePlayer(player.id));
            playersList.appendChild(li);
        });
    }
} 

// Gestion des invitations
function invitePlayer(playerId) {
    // Afficher la popin
    const inviteModal = document.getElementById('inviteSettingsModal');
    inviteModal.style.display = 'block';

    // Gestion de la fermeture de la popin
    const closeBtn = document.getElementById('closeInviteSettings');
    closeBtn.onclick = function() {
        inviteModal.style.display = 'none';
    };

    // Gestion de l'envoi de l'invitation après sélection de la grille et difficulté
    const inviteForm = document.getElementById('inviteSettingsForm');
    inviteForm.onsubmit = function(e) {
        e.preventDefault(); // Empêche le rechargement de la page

        // Récupérer les choix de l'utilisateur
        const gridSize = document.getElementById('gridSize').value;
        const difficulty = document.getElementById('difficulty').value;

        // Envoyer l'invitation avec les paramètres choisis
        socket.send(JSON.stringify({
            type: 'invite',
            invitee: playerId,
            gridSize: gridSize,
            difficulty: difficulty
        }));

        // Masquer la popin après l'envoi
        inviteModal.style.display = 'none';

        console.log('Invitation envoyée à ' + playerId + ' avec une grille de ' + gridSize + ' et une difficulté de ' + difficulty + '%');
    };
}

function acceptInvite() {
    const inviter = document.getElementById('inviter').textContent;

    // Envoyer la réponse d'acceptation avec l'invitationId
    socket.send(JSON.stringify({
        type: 'accept_invite',
        inviter: inviter,
        invitationId: currentInvitationId // Utiliser la variable stockée
    }));

    // Masquer la popin d'invitation
    document.getElementById('invitation').style.display = 'none';
}

function declineInvite() {
    const inviter = document.getElementById('inviter').textContent;

    // Envoyer la réponse de refus avec l'invitationId
    socket.send(JSON.stringify({
        type: 'decline_invite',
        inviter: inviter,
        invitationId: currentInvitationId // Utiliser la variable stockée
    }));

    // Masquer la popin d'invitation
    document.getElementById('invitation').style.display = 'none';
}

// Afficher le plateau de jeu
function displayGameBoard(board, losingCell = null) {
    const gameBoardDiv = document.getElementById('gameBoard');
    gameBoardDiv.innerHTML = ''; // Réinitialiser le plateau de jeu

    const table = document.createElement('table');
    board.forEach((row, x) => {
        const tr = document.createElement('tr');
        row.forEach((cell, y) => {
            const td = document.createElement('td');
            td.classList.add('cell');
            td.dataset.x = x;
            td.dataset.y = y;

            const cellInner = document.createElement('div');
            cellInner.classList.add('cell-inner');

            const cellFront = document.createElement('div');
            cellFront.classList.add('cell-front'); // Côté non révélé (mer)

            const cellBack = document.createElement('div');
            cellBack.classList.add('cell-back'); // Côté révélé (fond marin)

            // Si la cellule est révélée
            if (cell.revealed) {
                td.classList.add('revealed');
                if (cell.mine) {
                    soundMine.play();
                    cellBack.textContent = '💣'; // Afficher la mine

                    // Vérifier si c'est la mine qui a provoqué la fin de la partie
                    if (losingCell && x == losingCell.x && y == losingCell.y) {
                        cellBack.classList.add('mine-triggered');
                    }
                } else if (cell.adjacentMines > 0) {
                    cellBack.textContent = cell.adjacentMines; // Afficher le nombre de mines adjacentes
                    // Ajouter une classe pour la couleur du nombre de mines (et le cercle)
                    cellBack.classList.add(`mine-number-${cell.adjacentMines}`);
                }
            } else {
                // Si la cellule est marquée par un drapeau
                if (cell.flagged) {
                    td.classList.add('cell-flagged'); // Ajouter la classe pour les drapeaux
                    cellFront.textContent = '🚩'; // Afficher le drapeau
                }
            }

            cellInner.appendChild(cellFront);
            cellInner.appendChild(cellBack);
            td.appendChild(cellInner);

            // Gestion des clics (révélation des cases)
            td.addEventListener('click', () => revealCell(x, y));
            // Clic droit pour placer ou retirer un drapeau
            td.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                placeFlag(x, y);
            });

            tr.appendChild(td);
        });
        table.appendChild(tr);
    });
    gameBoardDiv.appendChild(table);
}


async function handleRegister() {
    const username = document.getElementById('registerUsername').value;
    const password = document.getElementById('registerPassword').value;
    await sendRegister(username, password);
    console.log('Tentative de création de compte pour ' + username);
}

// Ajouter l'écouteur pour le bouton de création de compte
registerBtn.addEventListener('click', handleRegister);

// Fonction pour vider le plateau de jeu
function clearGameBoard() {
    const gameBoardDiv = document.getElementById('gameBoard');
    gameBoardDiv.innerHTML = '';  // Supprimer tout le contenu du plateau de jeu
    logMessage('Le plateau de jeu a été vidé.');
}

function showNotYourTurnPopup() {
    const popup = document.getElementById('notYourTurnPopup');
    
    // Ajouter la classe 'show' pour afficher la popin
    popup.classList.add('show');
    
    // Retirer la classe 'show' après 2 secondes pour la faire disparaître
    setTimeout(() => {
        popup.classList.remove('show');
    }, 2000); // Affiche la popin pendant 2 secondes
}

function handleLogoutSuccess(data) {
    if (data.username === username) {
        // Le joueur qui a initié la déconnexion doit être redirigé vers l'écran de connexion
        document.getElementById('game').style.display = 'none';
        document.getElementById('navbar').style.display = 'none';
        showLoginModal();
        logMessage('Vous avez été déconnecté.');
    } else {
        // Un autre joueur a été déconnecté, mettre à jour la liste des utilisateurs
        updatePlayerList(data.players);
        logMessage(data.username + ' a été déconnecté.');
    }
}

// Gestion des cellules
function revealCell(x, y) {
    if (currentGameId) {  // Vérifiez que le game_id est bien défini
        socket.send(JSON.stringify({
            type: 'reveal_cell',
            game_id: currentGameId,  // Utilisez le game_id stocké
            x: x,
            y: y
        }));
        soundClick.play();
        logMessage('Cellule révélée: (' + x + ', ' + y + ')');
    } else {
        console.error('game_id manquant lors de la révélation de la cellule');
    }
}

function placeFlag(x, y) {
    if (currentGameId) {  // Vérifiez que le game_id est bien défini
        socket.send(JSON.stringify({
            type: 'place_flag',
            game_id: currentGameId,  // Utilisez le game_id stocké
            x: x,
            y: y
        }));
        soundFlag.play();
        logMessage('Drapeau posé: (' + x + ', ' + y + ')');
    } else {
        console.error('game_id manquant lors du placement du drapeau');
    }
}

// Afficher le modal du gagnant
function showWinnerModal(winnerMessage, gameId) {
    currentGameId = gameId;
    const modal = document.getElementById('winnerModal');
    const message = document.getElementById('winnerMessage');
    message.textContent = winnerMessage;
    modal.style.display = 'flex';
    
}

async function sendLogin(username, password) {
    socket.send(JSON.stringify({
        type: 'login',
        username: username,
        password: password
    }));
}

async function sendRegister(username, password) {
    socket.send(JSON.stringify({
        type: 'register',
        username: username,
        password: password // Envoi du mot de passe crypté
    }));
}

// Fermer la modale du gagnant
document.getElementById('closeModalBtn').addEventListener('click', () => {
    document.getElementById('winnerModal').style.display = 'none';
    document.getElementById('availableUser').style.display = 'block';
    document.getElementById('gameContainer').style.display = 'none';
    clearGameBoard();
    socket.send(JSON.stringify({
        type: 'refresh_players',
        game_id: currentGameId
    }));
});

loginBtn.addEventListener('click', async () => {
    const username = document.getElementById('loginUsername').value;
    const password = document.getElementById('loginPassword').value;
    await sendLogin(username, password); // Fonction existante pour envoyer les infos de login
    console.log('Tentative de connexion pour ' + username);
});

// Gestion de la création de compte
registerBtn.addEventListener('click', async () => {
    const username = document.getElementById('registerUsername').value;
    const password = document.getElementById('registerPassword').value;
    await sendRegister(username, password); // Fonction existante pour envoyer les infos de création de compte
    console.log('Tentative de création de compte pour ' + username);
});

// Fonction pour cacher le modal avec animation de rebond
function hideModal(modal) {
    modal.classList.add('bounceOut'); // Ajouter la classe pour l'animation
    modal.addEventListener('animationend', () => {
        modal.classList.add('hidden'); // Cacher le modal après l'animation
        modal.classList.remove('bounceOut'); // Réinitialiser la classe
    }, { once: true });
}

document.getElementById('logoutLink').addEventListener('click', () => {
    clearGameBoard(); 
    socket.send(JSON.stringify({ type: 'logout' }));
    document.getElementById('welcomeMessage').style.display = 'none';
    document.getElementById('logoutLink').style.display = 'none';
    
    document.getElementById('game').style.display = 'none';
    document.getElementById('navbar').style.display = 'none';
    logMessage('Déconnexion de ' + username);
    connected = false;
});

document.getElementById('acceptInviteBtn').addEventListener('click', acceptInvite);
document.getElementById('declineInviteBtn').addEventListener('click', declineInvite);



// Sélectionner les éléments du menu burger et des liens
const navbarToggler = document.querySelector('#navbar');
const navbarCollapse = document.querySelector('.navbar-collapse');
const navLinks = document.querySelectorAll('.nav-link');

// Fonction pour masquer le menu burger
function hideMenu() {
    if (navbarCollapse.classList.contains('show')) {
        navbarToggler.click();  // Simule un clic sur le bouton du burger pour fermer le menu
    }
}

// Cacher le menu burger lorsque l'on perd le focus
navbarToggler.addEventListener('blur', hideMenu);


// Démarrer la connexion WebSocket lors du chargement de la page
window.onload = function() {
    connectWebSocket();
};
