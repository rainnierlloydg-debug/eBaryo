<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;

class CheckSmtp extends Command
{
    protected $signature = 'mail:check';

    protected $description = 'Check SMTP connection and authentication without sending an email';

    public function handle(): int
    {
        $name = config('mail.default');
        if (config("mail.mailers.$name.transport") !== 'smtp') {
            $this->error('SMTP is not enabled. Set MAIL_MAILER=smtp and configure your sender credentials in .env, then run php artisan config:clear.');

            return self::FAILURE;
        }

        try {
            $transport = Mail::mailer($name)->getSymfonyTransport();
            if (! $transport instanceof SmtpTransport) {
                $this->error('The configured mail URL does not use SMTP. Check MAIL_URL.');

                return self::FAILURE;
            }
            $transport->start();
            $transport->stop();
        } catch (\Throwable $exception) {
            report($exception);
            $this->error('SMTP connection or authentication failed. Check the host, port, TLS settings, username and password. Details are in the application log.');

            return self::FAILURE;
        }

        $this->info('SMTP connection and authentication succeeded. No email was sent. Inbox delivery still needs to be tested using Forgot password.');

        return self::SUCCESS;
    }
}
