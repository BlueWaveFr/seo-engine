<?php

namespace SeoExpert\Engine\Service;

use SeoExpert\Engine\Entity\User;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Twig\Environment;

class MailerService
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly string $fromEmail = 'noreply@optimize360.fr',
        private readonly string $fromName = 'Optimize360'
    ) {}

    public function sendWelcomeEmail(User $user, string $plainPassword): void
    {
        $html = $this->twig->render('emails/welcome.html.twig', [
            'user' => $user,
            'password' => $plainPassword,
            'loginUrl' => $_ENV['APP_URL'] ?? 'http://localhost:3000/login',
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromEmail))
            ->to($user->getEmail())
            ->subject('Bienvenue sur WaveRank - Vos identifiants de connexion')
            ->html($html);

        $this->mailer->send($email);
    }

    public function sendPasswordResetEmail(User $user, string $resetToken): void
    {
        $html = $this->twig->render('emails/password_reset.html.twig', [
            'user' => $user,
            'resetUrl' => ($_ENV['APP_URL'] ?? 'https://app.waverank.io') . '/reset-password?token=' . $resetToken,
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromEmail))
            ->to($user->getEmail())
            ->subject('Réinitialisation de votre mot de passe WaveRank')
            ->html($html);

        $this->mailer->send($email);
    }

    public function sendTrialEndingEmail(User $user, int $daysLeft): void
    {
        $html = $this->twig->render('emails/trial_ending.html.twig', [
            'user' => $user,
            'daysLeft' => $daysLeft,
            'upgradeUrl' => ($_ENV['APP_URL'] ?? 'https://app.waverank.io') . '/settings?tab=billing',
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromEmail))
            ->to($user->getEmail())
            ->subject(sprintf('Votre essai gratuit WaveRank se termine dans %d jours', $daysLeft))
            ->html($html);

        $this->mailer->send($email);
    }

    public function sendTrialExpiredEmail(User $user): void
    {
        $html = $this->twig->render('emails/trial_expired.html.twig', [
            'user' => $user,
            'upgradeUrl' => ($_ENV['APP_URL'] ?? 'https://app.waverank.io') . '/settings?tab=billing',
        ]);

        $email = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromEmail))
            ->to($user->getEmail())
            ->subject('Votre essai gratuit WaveRank est terminé')
            ->html($html);

        $this->mailer->send($email);
    }

    /**
     * Send contact form email to support
     */
    public function sendContactToSupport(string $name, string $email, string $subject, string $message): void
    {
        $html = $this->twig->render('emails/contact_support.html.twig', [
            'name' => $name,
            'email' => $email,
            'subject' => $subject,
            'message' => $message,
        ]);

        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromEmail))
            ->replyTo($email)
            ->to('stephane.geraut@optimize360.fr')
            ->subject(sprintf('[Contact] %s', $subject))
            ->html($html);

        $this->mailer->send($emailMessage);
    }

    /**
     * Send confirmation email to customer after contact form submission
     */
    public function sendContactConfirmation(string $name, string $email, string $subject, string $message): void
    {
        $html = $this->twig->render('emails/contact_confirmation.html.twig', [
            'name' => $name,
            'subject' => $subject,
            'message' => $message,
        ]);

        $emailMessage = (new Email())
            ->from(sprintf('%s <%s>', $this->fromName, $this->fromEmail))
            ->to($email)
            ->subject('Nous avons bien reçu votre message - WaveRank')
            ->html($html);

        $this->mailer->send($emailMessage);
    }
}
