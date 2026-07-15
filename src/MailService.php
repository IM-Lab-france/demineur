<?php
declare(strict_types=1);

use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

final class MailService {
    private Mailer $mailer;
    private string $fromAddress;
    private string $fromName;
    private string $publicUrl;

    public function __construct() {
        $dsn = trim((string) ($_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: ''));
        $this->fromAddress = trim((string) ($_ENV['MAIL_FROM_ADDRESS'] ?? getenv('MAIL_FROM_ADDRESS') ?: ''));
        $this->fromName = trim((string) ($_ENV['MAIL_FROM_NAME'] ?? getenv('MAIL_FROM_NAME') ?: 'Démineur'));
        $this->publicUrl = rtrim((string) ($_ENV['APP_PUBLIC_URL'] ?? getenv('APP_PUBLIC_URL') ?: ''), '/');
        if ($dsn === '' || !filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL) || !filter_var($this->publicUrl, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Configuration SMTP incomplète.');
        }
        $this->mailer = new Mailer(Transport::fromDsn($dsn));
    }

    public function sendVerification(string $recipient, string $username, string $token): void {
        $url = $this->publicUrl . '/verify-email.php?token=' . rawurlencode($token);
        $requestCode = strtoupper(substr(hash('sha256', $token), 0, 8));
        $this->send(
            $recipient,
            'Validation Démineur — demande ' . $requestCode,
            "Bonjour {$username},\n\nCode de cette demande : {$requestCode}\n\nValidez votre compte en ouvrant ce lien, valable 24 heures :\n{$url}\n\nUtilisez uniquement le message le plus récent. Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.",
            '<p>Bonjour ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p><p><strong>Code de cette demande : ' . $requestCode . '</strong></p><p>Validez votre compte en cliquant sur ce lien, valable 24 heures :</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Valider mon adresse e-mail</a></p><p>Utilisez uniquement le message le plus récent. Si vous n’êtes pas à l’origine de cette demande, ignorez cet e-mail.</p>'
        );
    }

    public function sendPasswordReset(string $recipient, string $username, string $token): void {
        $url = $this->publicUrl . '/reset-password.php?token=' . rawurlencode($token);
        $this->send(
            $recipient,
            'Réinitialisation de votre mot de passe Démineur',
            "Bonjour {$username},\n\nChoisissez un nouveau mot de passe avec ce lien, valable 30 minutes :\n{$url}\n\nSi vous n’avez rien demandé, ignorez cet e-mail.",
            '<p>Bonjour ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p><p>Choisissez un nouveau mot de passe avec ce lien, valable 30 minutes :</p><p><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">Réinitialiser mon mot de passe</a></p><p>Si vous n’avez rien demandé, ignorez cet e-mail.</p>'
        );
    }

    public function sendPasswordChanged(string $recipient, string $username): void {
        $this->send(
            $recipient,
            'Votre mot de passe Démineur a été modifié',
            "Bonjour {$username},\n\nVotre mot de passe vient d’être modifié. Si vous n’êtes pas à l’origine de cette action, contactez immédiatement l’administrateur du site.",
            '<p>Bonjour ' . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . ',</p><p>Votre mot de passe vient d’être modifié.</p><p>Si vous n’êtes pas à l’origine de cette action, contactez immédiatement l’administrateur du site.</p>'
        );
    }

    private function send(string $recipient, string $subject, string $text, string $html): void {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Destinataire invalide.');
        $message = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromAddress))
            ->to($recipient)
            ->subject($subject)
            ->text($text)
            ->html($html);
        $this->mailer->send($message);
    }
}
