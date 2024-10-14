<?php
// admin_interface.php
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Interface d'Administration - Serveur Démineur</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- jQuery (pour AJAX) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        body {
            padding-top: 50px;
        }
        .status {
            font-size: 1.2rem;
            margin-bottom: 20px;
        }
        .message {
            margin-top: 20px;
        }
    </style>
</head>
<body>
<!-- Menu Burger -->
<nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="#">Serveur Démineur</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="/admin">Admin</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="/ia/deminium">Démineur IA</a>
                </li>
            </ul>
        </div>
    </div>
</nav>
<div class="container">
    <h1 class="text-center">Interface d'Administration du Serveur Démineur</h1>
    
    <div class="status text-center">
        <p><strong>Statut du Serveur :</strong> <span id="server-status">Vérification...</span></p>
        <p><strong>Joueurs Connectés :</strong> <span id="connected-players">0</span></p>
        <p><strong>Usage CPU :</strong> <span id="cpu-usage">Calcul...</span>%</p>
        <p><strong>Usage Mémoire :</strong> <span id="memory-usage">Calcul...</span>%</p>
    </div>
    
    <div class="d-flex justify-content-center gap-3">
        <button id="start-button" class="btn btn-success">Démarrer le Serveur</button>
        <button id="stop-button" class="btn btn-danger">Arrêter le Serveur</button>
    </div>
    
    <div class="message text-center mt-4">
        <div id="alert-container"></div>
    </div>
</div>

<script>
$(document).ready(function(){


    // Gestion du menu
    document.addEventListener("DOMContentLoaded", function () {
        const navbarToggler = document.querySelector(".navbar-toggler");
        const navbarCollapse = document.querySelector(".navbar-collapse");
        const navLinks = document.querySelectorAll(".nav-link");

        // Fonction pour fermer le menu après un clic sur un lien (sur mobile)
        function closeMenu() {
            if (window.innerWidth < 992 && navbarCollapse.classList.contains("show")) {
                navbarToggler.click();
            }
        }

        // Ajouter l'écouteur d'événement sur chaque lien du menu
        navLinks.forEach(link => {
            link.addEventListener("click", closeMenu);
        });

        // Gérer la fermeture du menu burger lorsqu'on clique en dehors
        document.addEventListener("click", function (event) {
            const isClickInsideMenu = navbarCollapse.contains(event.target) || navbarToggler.contains(event.target);
            if (!isClickInsideMenu && navbarCollapse.classList.contains("show")) {
                navbarToggler.click();
            }
        });
    });




    // Fonction pour afficher les messages
    function showMessage(type, message) {
        const alertHtml = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fermer"></button>
            </div>
        `;
        $('#alert-container').html(alertHtml);
    }

    // Fonction pour vérifier le statut du serveur
    function checkServerStatus() {
        $.ajax({
            url: '/admin/server_status.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                const serverIsOnline = response.server === 'online';
                
                // Mettre à jour le statut du serveur
                $('#server-status').text(serverIsOnline ? 'En ligne' : 'Hors ligne');
                $('#server-status').removeClass('text-success text-danger').addClass(serverIsOnline ? 'text-success' : 'text-danger');
                $('#connected-players').text(response.connectedPlayers);

                // Activer/désactiver les boutons en fonction de l'état du serveur
                $('#start-button').prop('disabled', serverIsOnline);
                $('#stop-button').prop('disabled', !serverIsOnline);
            },
            error: function() {
                $('#server-status').text('Erreur de connexion');
                $('#server-status').removeClass('text-success text-danger').addClass('text-warning');
                $('#connected-players').text('N/A');
                
                // Désactiver les deux boutons en cas d'erreur de connexion
                $('#start-button').prop('disabled', true);
                $('#stop-button').prop('disabled', true);
            }
        });
    }

    // Fonction pour vérifier l'utilisation des ressources du serveur
    function checkServerUsage() {
        $.ajax({
            url: '/admin/server_usage.php',
            method: 'GET',
            dataType: 'json',
            success: function(response) {
                $('#cpu-usage').text(response.cpu !== 'N/A' ? response.cpu.toFixed(1) : 'N/A');
                $('#memory-usage').text(response.memory !== 'N/A' ? response.memory.toFixed(1) : 'N/A');
            },
            error: function() {
                $('#cpu-usage').text('Erreur');
                $('#memory-usage').text('Erreur');
            }
        });
    }

    // Initialiser le statut et l'usage des ressources
    checkServerStatus();
    checkServerUsage();

    // Vérifier le statut toutes les 2 secondes
    setInterval(checkServerStatus, 2000);

    // Vérifier l'usage des ressources toutes les 5 secondes
    setInterval(checkServerUsage, 5000);

    // Gérer le clic sur le bouton Démarrer
    $('#start-button').click(function(){
        $.ajax({
            url: '/admin/start_server.php',
            method: 'POST',
            dataType: 'json',
            beforeSend: function() {
                showMessage('info', 'Démarrage du serveur...');
            },
            success: function(response) {
                if(response.status === 'success') {
                    showMessage('success', response.message);
                    
                    // Lancer monitor_server.php via une requête AJAX à un script dédié
                    $.ajax({
                        url: '/admin/monitor_server.php',
                        method: 'POST',
                        dataType: 'json',
                        success: function(monitorResponse) {
                            if(monitorResponse.status === 'success') {
                                showMessage('success', 'Monitor démarré avec succès.');
                            } else {
                                showMessage('warning', 'Le monitor n\'a pas pu être démarré.');
                            }
                        },
                        error: function() {
                            showMessage('danger', 'Erreur lors du démarrage du monitor.');
                        }
                    });
                } else {
                    showMessage('danger', response.message);
                }
            },
            error: function() {
                showMessage('danger', 'Erreur lors de la tentative de démarrage du serveur.');
            }
        });
    });

    // Gérer le clic sur le bouton Arrêter
    $('#stop-button').click(function(){
        $.ajax({
            url: '/admin/stop_server.php',
            method: 'POST',
            dataType: 'json',
            beforeSend: function() {
                showMessage('info', 'Arrêt du serveur...');
            },
            success: function(response) {
                if(response.status === 'success') {
                    showMessage('success', response.message);
                } else if(response.status === 'partial_success') {
                    showMessage('warning', response.message);
                } else {
                    showMessage('danger', response.message);
                }
            },
            error: function() {
                showMessage('danger', 'Erreur lors de la tentative d\'arrêt du serveur.');
            }
        });
    });
});
</script>
</body>
</html>
