<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\DocumentRequest;
use App\Models\ResidentNotification;
use App\Models\User;
use App\Services\RequestWorkflow;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    $admin = User::where('role', 'admin')->first();
    if (! $admin) {
        return;
    }

    $now = now();
    $workflow = app(RequestWorkflow::class);
    DocumentRequest::where('status', 'Ready for Pickup')
        ->whereNotNull('pickup_deadline')
        ->where('pickup_deadline', '<=', $now)
        ->get()
        ->each(fn (DocumentRequest $record) => $workflow->transition($record, $admin, 'Expired'));

    DocumentRequest::where('status', 'Ready for Pickup')
        ->whereNull('pickup_reminder_at')
        ->whereBetween('pickup_deadline', [$now, $now->copy()->addDay()])
        ->get()
        ->each(function (DocumentRequest $record): void {
            ResidentNotification::create([
                'user_id' => $record->user_id,
                'title' => 'Pickup reminder',
                'message' => "Your document {$record->tracking_number} is ready for pickup. The 3-day claim period is ending soon.",
            ]);
            $record->update(['pickup_reminder_at' => now()]);
        });
})->everyMinute()->name('ebaryo-pickup-deadlines')->withoutOverlapping();
