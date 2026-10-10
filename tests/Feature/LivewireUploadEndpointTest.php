<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;

class LivewireUploadEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_livewire_upload_endpoint_accepts_various_files()
    {
        config([
            'filesystems.disks.tmp-for-tests' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/tmp-for-tests'),
            ],
        ]);
        \Illuminate\Support\Facades\Storage::fake('tmp-for-tests');

        $domain = VirtualDomain::create(['name' => 'perusahaan.co.id', 'is_active' => true]);
        $user = VirtualUser::create([
            'domain_id' => $domain->id,
            'email' => 'admin@perusahaan.co.id',
            'password' => bcrypt('secret123'),
            'maildir_path' => 'perusahaan.co.id/admin/',
            'quota_bytes' => 1073741824,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'mailbox');
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);

        $formats = [
            'sample.pdf' => 'application/pdf',
            'archive.zip' => 'application/zip',
            'package.rar' => 'application/x-rar-compressed',
            'sheet.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'doc.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'photo.jpg' => 'image/jpeg',
            'notes.txt' => 'text/plain',
        ];

        foreach ($formats as $filename => $mime) {
            $file = UploadedFile::fake()->createWithContent($filename, 'sample binary data for attachment');

            \Livewire\Livewire::test('webmail.mail-client')
                ->set('attachments', [$file])
                ->assertHasNoErrors();
        }
    }
}
