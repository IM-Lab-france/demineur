<?php
// check_ia_name.php

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['exists' => false, 'message' => 'Méthode non autorisée.']);
    exit;
}

$iaName = $_POST['iaName'] ?? '';

if (empty($iaName)) {
    echo json_encode(['exists' => false, 'message' => 'Le nom de l\'IA est requis.']);
    exit;
}

// Validation du nom de l'IA
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $iaName)) {
    echo json_encode(['exists' => false, 'message' => 'Nom d\'IA invalide.']);
    exit;
}

$accountsFile = '/var/www/html/ia/deminium/ia_accounts.json';

// Vérifier si le fichier existe
if (!file_exists($accountsFile)) {
    // Le fichier n'existe pas encore, donc le nom n'existe pas
    echo json_encode(['exists' => false]);
    exit;
}

// Lire le fichier JSON
$accountsData = json_decode(file_get_contents($accountsFile), true);

if ($accountsData === null) {
    echo json_encode(['exists' => false, 'message' => 'Erreur lors de la lecture du fichier des comptes.']);
    exit;
}

// Vérifier si le nom d'utilisateur existe déjà
$username = 'ia_' . $iaName;

foreach ($accountsData as $account) {
    if ($account['username'] === $username) {
        echo json_encode(['exists' => true]);
        exit;
    }
}

echo json_encode(['exists' => false]);
