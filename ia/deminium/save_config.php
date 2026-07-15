<?php
declare(strict_types=1);
require_once __DIR__ . '/../../admin/bootstrap.php';
require_once __DIR__ . '/ai_config.php';
require_admin();
require_post();
require_csrf();
header('Content-Type: application/json; charset=utf-8');

try {
    $iaName = validated_ia_name();
    validated_ia_path($iaName);
    $config = write_ai_config($iaName, $_POST);
    $unit = 'minesweeper-ai@' . $iaName . '.service';
    exec('/usr/bin/systemctl is-active --quiet ' . escapeshellarg($unit), $output, $code);
    echo json_encode([
        'success' => true,
        'message' => $code === 0
            ? 'Configuration enregistrée. Redémarrez l’IA pour l’appliquer.'
            : 'Configuration enregistrée.',
        'config' => $config,
        'requiresRestart' => $code === 0,
    ]);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $error->getMessage()]);
}
