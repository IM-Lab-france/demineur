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

echo "Tests de sécurité administrateur réussis.\n";
