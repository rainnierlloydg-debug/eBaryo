<?php

namespace Database\Seeders;

use App\Models\Document;
use Illuminate\Database\Seeder;

class DocumentSeeder extends Seeder
{
    public function run(): void
    {
        $documents = [
            ['name' => 'Barangay Clearance', 'slug' => 'barangay-clearance', 'description' => 'Official clearance issued to a resident of the barangay.', 'purpose' => 'Employment, applications, and official transactions.', 'fee' => 100, 'requirements' => []],
            ['name' => 'Certificate of Residency', 'slug' => 'certificate-of-residency', 'description' => 'Certification confirming that the applicant resides in the barangay.', 'purpose' => 'School, employment, and personal transactions.', 'fee' => 50, 'requirements' => ['Valid ID', 'Proof of residency']],
            ['name' => 'Certificate of Indigency', 'slug' => 'certificate-of-indigency', 'description' => 'Certification of indigency for eligible residents.', 'purpose' => 'Medical, educational, and assistance applications.', 'fee' => 0, 'requirements' => ['Valid ID', 'Proof of income or indigency']],
        ];

        foreach ($documents as $document) {
            Document::updateOrCreate(['slug' => $document['slug']], $document + ['is_active' => true]);
        }
    }
}
