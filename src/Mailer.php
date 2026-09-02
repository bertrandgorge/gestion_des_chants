<?php

declare(strict_types=1);

namespace App;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

/**
 * Envoi d'emails transactionnels via SMTP (config.php).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $c = config('smtp');

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $c['host'];
            $mail->Port = (int) $c['port'];
            $mail->CharSet = 'UTF-8';

            if (!empty($c['user'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $c['user'];
                $mail->Password = $c['pass'];
            } else {
                $mail->SMTPAuth = false;
            }

            if ($c['secure'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($c['secure'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom($c['from'], $c['from_name'] ?? 'Feuilles de messe');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)));

            $mail->send();

            return true;
        } catch (\Throwable $e) {
            error_log('Mailer: ' . $e->getMessage());

            return false;
        }
    }

    public static function invitation(string $to, string $paroisse, string $lien): bool
    {
        $body = view('emails/invitation', ['paroisse' => $paroisse, 'lien' => $lien]);

        return self::send($to, "Invitation — feuilles de messe ({$paroisse})", $body);
    }

    public static function reset(string $to, string $lien): bool
    {
        $body = view('emails/reset', ['lien' => $lien]);

        return self::send($to, 'Réinitialisation de votre mot de passe', $body);
    }
}
