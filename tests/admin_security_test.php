<?php
declare(strict_types=1);

$_SERVER['HTTPS'] = 'on';
require __DIR__ . '/../admin/bootstrap.php';

function assert_admin_security(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

// RFC 6238 : clé ASCII "12345678901234567890" encodée en Base32.
assert_admin_security(verify_totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 59), 'Le code TOTP RFC doit être accepté.');
assert_admin_security(!verify_totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287083', 59), 'Un code TOTP incorrect doit être refusé.');
assert_admin_security(!verify_totp('INVALID!', '000000', 59), 'Un secret invalide doit être refusé.');
$generatedSecret = generate_totp_secret();
assert_admin_security((bool) preg_match('/^[A-Z2-7]{32}$/', $generatedSecret), 'Le secret TOTP généré doit être un Base32 de 160 bits.');
$provisioningUri = totp_provisioning_uri('Admin Test', $generatedSecret);
assert_admin_security(str_starts_with($provisioningUri, 'otpauth://totp/'), 'Une URI Authenticator doit être produite.');
assert_admin_security(str_contains($provisioningUri, 'secret=' . $generatedSecret), 'L’URI doit contenir le secret généré.');
assert_admin_security(str_contains($provisioningUri, 'digits=6&period=30'), 'L’URI doit annoncer les paramètres TOTP utilisés.');
$qrResult = (new Endroid\QrCode\Builder\Builder(
    writer: new Endroid\QrCode\Writer\PngWriter(),
    data: $provisioningUri,
    size: 280,
    margin: 12,
))->build();
assert_admin_security(str_starts_with($qrResult->getDataUri(), 'data:image/png;base64,'), 'Le QR Code doit être généré localement en PNG.');

echo "Tests de sécurité administrateur réussis.\n";
