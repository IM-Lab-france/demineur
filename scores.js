let socket;

window.onload = function() {
    connectWebSocket(); // Connexion WebSocket
};

// Connexion WebSocket
function connectWebSocket() {
    socket = new WebSocket('wss://fozzy.fr:9443');

    socket.onopen = function() {
        console.log('WebSocket ouvert');
        fetchPlayerScores(); // Récupérer les scores des joueurs une fois connecté
    };

    socket.onmessage = function(event) {
        const data = JSON.parse(event.data);
        console.log('Message reçu du serveur: ' + JSON.stringify(data));

        // Traiter le message envoyé par le serveur
        switch (data.type) {
            case 'connected_players': // Le type de message envoyé par le serveur pour les scores
                refreshScores(data.players); // Rafraîchir l'affichage des scores
                break;

            case 'error':
                console.error('Erreur: ' + data.message);
                break;

            // Ajoute d'autres types de messages si nécessaire...
        }
    };

    socket.onclose = function() {
        console.log('WebSocket fermé');
    };
}

// Fonction pour récupérer les scores des joueurs
function fetchPlayerScores() {
    socket.send(JSON.stringify({ type: 'get_scores' })); // Demander les scores au serveur
}

// Fonction pour afficher les scores des joueurs
function refreshScores(players) {
    const scoresTable = document.getElementById('scoresTable');
    scoresTable.innerHTML = ''; // Vider le tableau avant de l'actualiser

    players.forEach(player => {
        const row = document.createElement('tr');
        const gamesPlayed = player.games_played || 0; // S'assurer que le nombre de parties jouées est défini
        let winPercentage = player.win_percentage || 0; // S'assurer que win_percentage est défini

        // Convertir winPercentage en float si c'est une chaîne de caractères
        if (typeof winPercentage === 'string') {
            winPercentage = parseFloat(winPercentage);
        }

        // Utiliser toFixed pour afficher deux décimales
        row.innerHTML = `
            <td>${player.username}</td>
            <td>${gamesPlayed}</td>
            <td>${player.games_won || 0}</td>
            <td><div class="progress">
                    <div class="progress-bar bg-success" role="progressbar" style="width: ${winPercentage.toFixed(2)}%;" aria-valuenow="${winPercentage.toFixed(2)}" aria-valuemin="0" aria-valuemax="100">${winPercentage.toFixed(2)}%</div>
                </div>
            </td>
        `;
        scoresTable.appendChild(row);
    });
}
