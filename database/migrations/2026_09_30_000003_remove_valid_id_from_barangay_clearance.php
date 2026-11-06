<?php

use App\Models\Document;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $document = Document::where('slug', 'barangay-clearance')->first();
        if (! $document) {
            return;
        }

        $document->update([
            'requirements' => array_values(array_filter(
                $document->requirements ?? [],
                fn (string $requirement): bool => $requirement !== 'Valid ID',
            )),
        ]);
    }

    public function down(): void
    {
        $document = Document::where('slug', 'barangay-clearance')->first();
        if (! $document || in_array('Valid ID', $document->requirements ?? [], true)) {
            return;
        }

        $document->update([
            'requirements' => ['Valid ID', ...($document->requirements ?? [])],
        ]);
    }
};
