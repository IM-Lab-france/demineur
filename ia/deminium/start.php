<?php
require_once __DIR__ . '/../../admin/bootstrap.php';
require_admin();
require_post();
require_csrf();
// start.php

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $iaName = validated_ia_name();
    $invite = isset($_POST['invite']) && $_POST['invite'] == 1 ? true : false;
    $level = $_POST['level'] ?? 'medium';
    if (!in_array($level, ['easy', 'medium', 'hard'], true)) $level = 'medium';
    $pause = filter_var($_POST['pause'] ?? 700, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 100, 'max_range' => 10000, 'default' => 700],
    ]);
    $iaPath = validated_ia_path($iaName);
    $envPath = $iaPath . '/env';
    $secureLogRoot = '/var/log/minesweeper/ai';
    $useSecureLogs = is_dir($secureLogRoot) && is_writable($secureLogRoot);
    $logDir = $useSecureLogs ? $secureLogRoot . '/' . $iaName : $iaPath . '/logs';

    // Vérifier si l'IA existe et si l'environnement est initialisé
    if (!is_dir($iaPath) || !is_dir($envPath)) {
        echo json_encode(['success' => false, 'message' => 'IA non initialisée.']);
        exit;
    }

    $logFile = $logDir . '/run.log';
    $pidFile = $iaPath . '/pid';
    $accountsFile = ia_accounts_file();

    if (!is_file($accountsFile) || !is_readable($accountsFile)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Le fichier sécurisé des comptes IA est absent ou illisible. Relancez le script d’installation du service.'
        ]);
        exit;
    }

    // Chaque IA est isolée dans une unité systemd dédiée lorsque le modèle est installé.
    $mainScript = realpath(__DIR__ . '/main.py');
    if (!is_dir($logDir) && !mkdir($logDir, 0750, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Répertoire sécurisé des journaux IA indisponible.']);
        exit;
    }
    $systemdUnit = 'minesweeper-ai@' . $iaName . '.service';
    $systemdTemplate = '/etc/systemd/system/minesweeper-ai@.service';
    if (is_file($systemdTemplate)) {
        $instanceEnv = (getenv('APP_CONFIG_DIR') ?: '/var/www/secure') . '/ai-' . $iaName . '.env';
        file_put_contents($instanceEnv, "IA_INVITE=" . ($invite ? '1' : '0') . "\nIA_LEVEL={$level}\nIA_PAUSE_MS={$pause}\n", LOCK_EX);
        @chmod($instanceEnv, 0640);
        exec('/usr/bin/sudo -n /usr/bin/systemctl start ' . escapeshellarg($systemdUnit) . ' 2>&1', $output, $code);
        usleep(500000);
        exec('/usr/bin/systemctl is-active ' . escapeshellarg($systemdUnit) . ' 2>/dev/null', $state, $stateCode);
        if ($code === 0 && $stateCode === 0 && trim(implode('', $state)) === 'active') {
            echo json_encode(['success' => true, 'message' => 'IA démarrée dans son service isolé.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Le service isolé de l’IA n’a pas démarré.', 'log' => implode("\n", $output)]);
        }
        exit;
    }
    $command = ($useSecureLogs ? 'IA_LOG_ROOT=' . escapeshellarg($secureLogRoot) . ' ' : '')
        . escapeshellarg($envPath . '/bin/python') . ' ' . escapeshellarg($mainScript)
        . ' --model=' . escapeshellarg($iaName)
        . ' --ai_level=' . escapeshellarg($level)
        . ' --pause=' . escapeshellarg((string) $pause);
    
    // Ajouter l'option --invite si le mode invite est activé
    if ($invite) {
        $command .= ' --invite';
    }

    // Rediriger la sortie vers le fichier log et exécuter en arrière-plan
    $command .= ' >> ' . escapeshellarg($logFile) . ' 2>&1 & echo $!';

    // Exécuter la commande et récupérer le PID
    $pid = shell_exec($command);
    if ($pid) {
        $pid = trim($pid);
        file_put_contents($pidFile, $pid, LOCK_EX);
        usleep(500000);
        $running = ctype_digit($pid) && posix_kill((int) $pid, 0);
        if ($running) {
            echo json_encode(['success' => true, 'message' => 'IA démarrée avec succès.']);
        } else {
            @unlink($pidFile);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'L’IA s’est arrêtée pendant son démarrage. Consultez son journal d’exécution.']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Échec du démarrage de l\'IA.']);
    }
}
?>
