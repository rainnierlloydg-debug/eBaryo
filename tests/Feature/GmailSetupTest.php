<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class GmailSetupTest extends TestCase
{
    private string $directory;

    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = storage_path('framework/testing/mail-setup-'.bin2hex(random_bytes(5)));
        mkdir($this->directory, 0777, true);
        $this->original = "APP_NAME=KeepMe\r\n# Keep this comment\r\nMAIL_MAILER=log\r\nMAIL_USERNAME=null\r\nMAIL_PASSWORD=null\r\n";
        file_put_contents($this->directory.'/.env', $this->original);
        $this->app->useEnvironmentPath($this->directory);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/.env');
        rmdir($this->directory);
        parent::tearDown();
    }

    private function mockSmtp(bool $succeeds, ?\Throwable $error = null): void
    {
        $transport = Mockery::mock(\Symfony\Component\Mailer\Transport\Smtp\SmtpTransport::class);
        if ($succeeds) {
            $transport->shouldReceive('start')->once();
            $transport->shouldReceive('stop')->once();
        } else {
            $transport->shouldReceive('start')->once()->andThrow($error ?? new \RuntimeException('Authentication failed'));
        }
        $mailer = Mockery::mock(\Illuminate\Mail\Mailer::class);
        $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn($transport);
        Mail::shouldReceive('purge')->with('smtp')->once();
        Mail::shouldReceive('mailer')->with('smtp')->once()->andReturn($mailer);
    }

    public function test_successful_authentication_updates_windows_env_without_duplicate_keys(): void
    {
        $this->mockSmtp(true);
        $this->artisan('mail:setup-gmail')
            ->expectsQuestion('Sender Gmail address', 'sender@gmail.com')
            ->expectsQuestion('Google App Password (16 characters, hidden)', 'abcd efgh ijkl mnop')
            ->assertSuccessful();
        $contents = file_get_contents($this->directory.'/.env');
        $this->assertStringContainsString("APP_NAME=KeepMe\r\n# Keep this comment", $contents);
        $this->assertStringContainsString('MAIL_MAILER=smtp', $contents);
        $this->assertStringContainsString('MAIL_FROM_ADDRESS=sender@gmail.com', $contents);
        $this->assertStringContainsString('MAIL_PASSWORD=abcdefghijklmnop', $contents);
        $this->assertSame(1, substr_count($contents, 'MAIL_MAILER='));
        $this->assertSame(1, substr_count($contents, 'MAIL_PASSWORD='));
        $parsed = \Dotenv\Dotenv::parse($contents);
        $this->assertSame('smtp', $parsed['MAIL_MAILER']);
        $this->assertSame('abcdefghijklmnop', $parsed['MAIL_PASSWORD']);
    }

    public function test_failed_authentication_preserves_env(): void
    {
        $this->mockSmtp(false);
        $this->artisan('mail:setup-gmail')
            ->expectsQuestion('Sender Gmail address', 'sender@gmail.com')
            ->expectsQuestion('Google App Password (16 characters, hidden)', 'abcdefghijklmnop')
            ->assertFailed();
        $this->assertSame($this->original, file_get_contents($this->directory.'/.env'));
    }

    public function test_certificate_failure_has_a_specific_message_and_preserves_env(): void
    {
        $this->mockSmtp(false, new \RuntimeException('STARTTLS certificate verify failed'));
        $this->artisan('mail:setup-gmail')
            ->expectsQuestion('Sender Gmail address', 'sender@gmail.com')
            ->expectsQuestion('Google App Password (16 characters, hidden)', 'abcdefghijklmnop')
            ->expectsOutput('Gmail TLS certificate verification failed. Check the PHP CA certificate configuration. No settings were saved.')
            ->assertFailed();
        $this->assertSame($this->original, file_get_contents($this->directory.'/.env'));
    }

    public function test_custom_ca_configuration_keeps_peer_and_hostname_verification_enabled(): void
    {
        $caFile = $this->directory.'/trusted-roots.pem';
        file_put_contents($caFile, 'test certificate file; no network connection is made');
        try {
            config(['mail.smtp_ca_file' => $caFile]);
            (new \App\Providers\AppServiceProvider($this->app))->boot();
            Mail::purge('smtp');
            $stream = Mail::mailer('smtp')->getSymfonyTransport()->getStream();
            $ssl = $stream->getStreamOptions()['ssl'];
            $this->assertSame($caFile, $ssl['cafile']);
            $this->assertTrue($ssl['verify_peer']);
            $this->assertTrue($ssl['verify_peer_name']);
        } finally {
            unlink($caFile);
        }
    }
}
