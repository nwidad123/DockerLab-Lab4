<?php

// Include the PHPMailer library files
require __DIR__ . '/PHPMailer/Exception.php';
require __DIR__ . '/PHPMailer/PHPMailer.php';
require __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendSystemMail($toEmail, $toName, $subject, $htmlBody) {

    // Read SMTP settings from the container environment
    $smtpHost = getenv('SMTP_HOST') ?: 'sandbox.smtp.mailtrap.io';
    $smtpPort = getenv('SMTP_PORT') ?: 2525;
    $smtpUser = getenv('SMTP_USER') ?: '';
    $smtpPass = getenv('SMTP_PASSWORD') ?: '';

    $mail = new PHPMailer(true);

    try {
        // Configure the SMTP server
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->Port = $smtpPort;

        // Configure the sender and recipient
        $mail->setFrom(
            'noreply@dockerlab.local',
            'Docker Lab Infrastructure'
        );

        $mail->addAddress($toEmail, $toName);

        // Configure the email content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        // Send the message
        $mail->send();

        return true;

    } catch (Exception $e) {

        // Record errors if sending fails
        error_log("PHPMailer Error: {$mail->ErrorInfo}");

        return false;
    }
}
?>