<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use App\Models\MailboxFolder;
use App\Models\MailboxEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class MailboxMigrationToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_tool_component_renders_and_has_all_folder_options()
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

        Livewire::test('admin.⚡migration-tool')
            ->assertSet('sync_inbox', true)
            ->assertSet('sync_sent', true)
            ->assertSet('sync_custom', true)
            ->assertSet('sync_limit', 250)
            ->assertSet('target_folder', 'auto')
            ->assertSee('Terkirim (Sent)')
            ->assertSee('Folder Lain / Kustom');
    }

    public function test_file_import_auto_detects_sent_and_inbox()
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

        $component = Livewire::test('admin.⚡migration-tool');
        $instance = $component->instance();

        // 1. Uji email masuk (Inbox)
        $rawInbox = "From: Client <client@external.com>\r\nTo: admin@perusahaan.co.id\r\nSubject: Halo Admin\r\nDate: 10 Oct 2026 10:00:00 +0700\r\n\r\nIsi email masuk";
        $parsedInbox = (new \ReflectionClass($instance))->getMethod('parseEmlData')->invoke($instance, $rawInbox, $user, 'auto');
        $this->assertEquals('inbox', $parsedInbox['folder']);
        $this->assertEquals('Halo Admin', $parsedInbox['subject']);
        $this->assertEquals('client@external.com', $parsedInbox['from_email']);

        // 2. Uji email keluar (Sent)
        $rawSent = "From: admin@perusahaan.co.id\r\nTo: Client <client@external.com>\r\nSubject: Balasan Admin\r\nDate: 10 Oct 2026 11:00:00 +0700\r\n\r\nIsi email balasan keluar";
        $parsedSent = (new \ReflectionClass($instance))->getMethod('parseEmlData')->invoke($instance, $rawSent, $user, 'auto');
        $this->assertEquals('sent', $parsedSent['folder']);
        $this->assertEquals('Balasan Admin', $parsedSent['subject']);
    }

    public function test_folder_determination_identifies_sent_and_custom_folders()
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

        $component = Livewire::test('admin.⚡migration-tool');
        $instance = $component->instance();
        $method = (new \ReflectionClass($instance))->getMethod('determineTargetFolder');

        // Test Sent folder naming variants
        $folderSent1 = (object) ['name' => 'Sent', 'path' => 'INBOX.Sent', 'full_name' => 'INBOX.Sent'];
        $resSent1 = $method->invoke($instance, $folderSent1);
        $this->assertEquals('sent', $resSent1['db_folder']);

        $folderSent2 = (object) ['name' => 'Sent Items', 'path' => 'Sent Items', 'full_name' => 'Sent Items'];
        $resSent2 = $method->invoke($instance, $folderSent2);
        $this->assertEquals('sent', $resSent2['db_folder']);

        $folderSent3 = (object) ['name' => 'Pesan Terkirim', 'path' => 'INBOX.Pesan Terkirim', 'full_name' => 'INBOX.Pesan Terkirim'];
        $resSent3 = $method->invoke($instance, $folderSent3);
        $this->assertEquals('sent', $resSent3['db_folder']);

        // Test Custom folder
        $folderCustom = (object) ['name' => 'Klien VIP', 'path' => 'INBOX.Klien VIP', 'full_name' => 'INBOX.Klien VIP'];
        $resCustom = $method->invoke($instance, $folderCustom);
        $this->assertEquals('custom', $resCustom['type']);
        $this->assertEquals('Klien VIP', $resCustom['db_folder']);
        $this->assertEquals('sync_custom', $resCustom['sync_prop']);
    }
}
