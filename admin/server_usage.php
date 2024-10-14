<?php
header('Content-Type: application/json');

// Fonction pour obtenir la mémoire et la charge CPU du serveur
function getServerResourceUsage() {
    $output = [];
    // Exécuter la commande shell pour obtenir la charge CPU et mémoire totale du serveur
    exec("ps -eo %cpu,%mem --no-headers | awk '{cpu+=$1; mem+=$2} END {print cpu, mem}'", $output);

    if (!empty($output)) {
        // Récupérer la ligne de sortie et extraire les valeurs CPU et MEM
        $cpuMemoryInfo = trim($output[0]);
        
        // Extraire les valeurs numériques de la charge CPU et mémoire
        list($cpuUsage, $memoryUsage) = preg_split('/\s+/', $cpuMemoryInfo);

        // Retourner les résultats au format JSON avec des valeurs numériques brutes
        echo json_encode([
            'cpu' => floatval($cpuUsage), // Conversion en float
            'memory' => floatval($memoryUsage) // Conversion en float
        ]);
    } else {
        // Si aucune information n'est retournée
        echo json_encode([
            'cpu' => 'N/A',
            'memory' => 'N/A'
        ]);
    }
}

getServerResourceUsage();
