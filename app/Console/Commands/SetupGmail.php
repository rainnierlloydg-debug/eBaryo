<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SetupGmail extends Command
{
    protected $signature = 'mail:setup-gmail';

    protected $description = 'Configure Gmail SMTP locally after checking the sender credentials';

    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run php artisan mail:setup-gmail in an interactive terminal.');

            return self::FAILURE;
        }

        $this->info('Use a sender Gmail account with 2-Step Verification enabled.');
        $this->line('Create an App Password: https://myaccount.google.com/apppasswords');
        $email = strtolower(trim((string) $this->ask('Sender Gmail address')));
        if (! preg_match('/^[a-z0-9._%+-]+@gmail\.com$/D', $email)) {
            $this->error('Enter a valid personal Gmail sender address.');

            return self::FAILURE;
        }
        $password = str_replace(' ', '', (string) $this->secret('Google App Password (16 characters, hidden)'));
        if (! preg_match('/^[a-zA-Z0-9]{16}$/D', $password)) {
            $this->error('Enter the 16-character Google App Password, not your regular Gmail password.');

            return self::FAILURE;
        }

        $path = $this->laravel->environmentFilePath();
        if (! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
            $this->error('The local .env file must exist and be writable.');

            return self::FAILURE;
        }

        $original = file_get_contents($path);
        config(['mail.mailers.smtp' => array_merge(config('mail.mailers.smtp'), [
            'transport' => 'smtp', 'scheme' => 'smtp', 'url' => null,
            'host' => 'smtp.gmail.com', 'port' => 587, 'username' => $email,
            'password' => $password, 'timeout' => 15,
        ])]);
        Mail::purge('smtp');
        $this->line('Checking Gmail connection and authentication...');
        try {
            $transport = Mail::mailer('smtp')->getSymfonyTransport();
            $transport->start();
            $transport->stop();
        } catch (\Throwable $exception) {
            // SMTP exceptions may contain account data; keep them out of console and logs.
            $detail = strtolower($exception->getMessage());
            if (str_contains($detail, 'certificate') || str_contains($detail, 'starttls')) {
                $this->error('Gmail TLS certificate verification failed. Check the PHP CA certificate configuration. No settings were saved.');
            } elseif (in_array((int) $exception->getCode(), [534, 535], true) || str_contains($detail, 'authenticat')) {
                $this->error('Gmail rejected the login. Use a new Google App Password created under the same sender Gmail account. No settings were saved.');
            } else {
                $this->error('Gmail connection failed. Check internet access and SMTP port 587. No settings were saved.');
            }

            return self::FAILURE;
        }

        $settings = [
            'MAIL_MAILER' => 'smtp', 'MAIL_URL' => 'null', 'MAIL_SCHEME' => 'smtp',
            'MAIL_HOST' => 'smtp.gmail.com', 'MAIL_PORT' => '587',
            'MAIL_USERNAME' => $email, 'MAIL_PASSWORD' => $password,
            'MAIL_FROM_ADDRESS' => $email, 'MAIL_FROM_NAME' => 'eBaryo',
        ];
        $updated = $original;
        foreach ($settings as $key => $value) {
            $line = $key.'='.$value;
            $pattern = '/^'.preg_quote($key, '/').'=[^\r\n]*/m';
            if (preg_match($pattern, $updated)) {
                $updated = preg_replace_callback($pattern, fn () => $line, $updated);
            } else {
                $updated = rtrim($updated).PHP_EOL.$line.PHP_EOL;
            }
        }
        if (file_get_contents($path) !== $original) {
            $this->error('The .env file changed during setup. Run setup again. No settings were saved.');

            return self::FAILURE;
        }
        if (file_put_contents($path, $updated, LOCK_EX) === false) {
            $this->error('Could not save .env. Check file permissions.');

            return self::FAILURE;
        }
        $this->call('config:clear');
        $this->info('Gmail SMTP connected and saved. Request a confirmation link on Forgot password to test inbox delivery.');
        $this->line('No test email was sent. Restart any long-running application server to load the new settings.');

        return self::SUCCESS;
    }
}
