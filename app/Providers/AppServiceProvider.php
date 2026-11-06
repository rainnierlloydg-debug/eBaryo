<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $caFile = config('mail.smtp_ca_file');
        if (! $caFile || ! is_file($caFile)) {
            return;
        }

        Mail::extend('smtp', function (array $config) use ($caFile) {
            $transport = (new EsmtpTransportFactory)->create(new Dsn(
                $config['scheme'] ?? (($config['port'] ?? 587) == 465 ? 'smtps' : 'smtp'),
                $config['host'],
                $config['username'] ?? null,
                $config['password'] ?? null,
                $config['port'] ?? null,
                $config,
            ));
            $stream = $transport->getStream();
            $options = $stream->getStreamOptions();
            $options['ssl']['cafile'] = $caFile;
            $options['ssl']['verify_peer'] = true;
            $options['ssl']['verify_peer_name'] = true;
            $stream->setStreamOptions($options);
            if (isset($config['timeout'])) {
                $stream->setTimeout($config['timeout']);
            }

            return $transport;
        });
    }
}
