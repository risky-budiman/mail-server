<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use App\Models\VirtualAlias;
use App\Models\MailboxEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Artisan;

class MailServerAndAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_attachment_download_and_preview_endpoints()
    {
        $domain = VirtualDomain::create(['name' => 'perusahaan.co.id', 'is_active' => true]);
        $user = VirtualUser::create([
            'domain_id' => $domain->id,
            'email' => 'admin@perusahaan.co.id',
            'password' => bcrypt('secret123'),
            'maildir_path' => 'perusahaan.co.id/admin/',
            'quota_bytes' => 1073741824,
            'is_active' => true,
        ]);

        // Simpan file lampiran dummy ke disk
        $filePath = 'attachments/' . $user->id . '/test_doc.pdf';
        Storage::disk('public')->put($filePath, '%PDF-1.4 test binary content');

        $email = MailboxEmail::create([
            'virtual_user_id' => $user->id,
            'folder' => 'inbox',
            'from_name' => 'Sender Test',
            'from_email' => 'sender@example.com',
            'to' => $user->email,
            'subject' => 'Tes Dokumen Lampiran',
            'body' => 'Silakan unduh dokumen terlampir.',
            'attachments' => [
                [
                    'name' => 'test_doc.pdf',
                    'size' => '1.2 KB',
                    'ext'  => 'pdf',
                    'path' => $filePath,
                    'url'  => Storage::url($filePath),
                    'bytes' => 30,
                ],
            ],
        ]);

        // 1. Tes preview endpoint saat login sebagai mailbox user
        $this->actingAs($user, 'mailbox');

        $previewResponse = $this->get(route('webmail.attachment.preview', [
            'email' => $email->id,
            'index' => 0,
        ]));
        $previewResponse->assertStatus(200);
        $previewResponse->assertHeader('Content-Type', 'application/pdf');

        // 2. Tes download endpoint
        $downloadResponse = $this->get(route('webmail.attachment.download', [
            'email' => $email->id,
            'index' => 0,
        ]));
        $downloadResponse->assertStatus(200);
        $downloadResponse->assertHeader('Content-Disposition');
    }

    public function test_inbound_mail_alias_routing_to_local_mailbox()
    {
        $domain = VirtualDomain::create(['name' => 'perusahaan.co.id', 'is_active' => true]);
        $user = VirtualUser::create([
            'domain_id' => $domain->id,
            'email' => 'admin@perusahaan.co.id',
            'password' => bcrypt('secret123'),
            'maildir_path' => 'perusahaan.co.id/admin/',
            'quota_bytes' => 1073741824,
            'is_active' => true,
        ]);

        // Buat alias info@perusahaan.co.id yang meneruskan ke admin@perusahaan.co.id dan external@gmail.com
        VirtualAlias::create([
            'domain_id' => $domain->id,
            'source_email' => 'info@perusahaan.co.id',
            'destination_email' => 'admin@perusahaan.co.id, external@gmail.com',
            'is_active' => true,
        ]);

        $rawMime = "From: customer@example.com\r\n" .
                   "To: info@perusahaan.co.id\r\n" .
                   "Subject: Pertanyaan Layanan Melalui Alias\r\n" .
                   "Date: " . date('r') . "\r\n" .
                   "Content-Type: text/plain; charset=UTF-8\r\n\r\n" .
                   "Halo tim perusahaan, mohon info penawaran harga.";

        // Jalankan perintah artisan inbound mail
        Artisan::call('mail:receive', ['--raw' => $rawMime]);

        // Pastikan email masuk ke mailbox admin@perusahaan.co.id
        $this->assertDatabaseHas('mailbox_emails', [
            'virtual_user_id' => $user->id,
            'folder' => 'inbox',
            'subject' => 'Pertanyaan Layanan Melalui Alias',
        ]);
    }
}
