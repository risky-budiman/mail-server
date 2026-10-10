<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use App\Models\MailboxEmail;
use App\Models\MailboxContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class MailboxContactTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_communication_creates_and_updates_contact()
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

        // 1. Catat kontak pertama kali
        $contact = MailboxContact::recordCommunication($user->id, 'Budi Santoso <budi@partner.com>', 'Budi Santoso');
        $this->assertNotNull($contact);
        $this->assertEquals('budi@partner.com', $contact->email);
        $this->assertEquals('Budi Santoso', $contact->name);
        $this->assertEquals(1, $contact->communication_count);

        // 2. Catat komunikasi berikutnya (increment count)
        $contact2 = MailboxContact::recordCommunication($user->id, 'budi@partner.com');
        $this->assertEquals($contact->id, $contact2->id);
        $this->assertEquals(2, $contact2->fresh()->communication_count);
    }

    public function test_sync_contacts_from_history()
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

        // Buat riwayat email masuk dan keluar
        MailboxEmail::create([
            'virtual_user_id' => $user->id,
            'folder' => 'inbox',
            'from_name' => 'Klien Utama',
            'from_email' => 'klien@client.com',
            'to' => $user->email,
            'subject' => 'Penawaran Kerjasama',
            'body' => 'Halo, ini penawaran kami.',
        ]);

        MailboxEmail::create([
            'virtual_user_id' => $user->id,
            'folder' => 'sent',
            'from_name' => 'Admin',
            'from_email' => $user->email,
            'to' => 'vendor@supplier.com',
            'subject' => 'Purchase Order',
            'body' => 'Berikut pesanan barang.',
        ]);

        $this->actingAs($user, 'mailbox');

        Livewire::test('webmail.⚡mail-client')
            ->call('syncContactsFromHistory')
            ->call('selectFolder', 'contacts')
            ->assertSee('klien@client.com')
            ->assertSee('vendor@supplier.com');

        $this->assertDatabaseHas('mailbox_contacts', [
            'virtual_user_id' => $user->id,
            'email' => 'klien@client.com',
        ]);

        $this->assertDatabaseHas('mailbox_contacts', [
            'virtual_user_id' => $user->id,
            'email' => 'vendor@supplier.com',
        ]);
    }

    public function test_create_and_delete_contact_manually()
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

        $this->actingAs($user, 'mailbox');

        Livewire::test('webmail.⚡mail-client')
            ->call('openCreateContactModal')
            ->set('contactName', 'Dewi Lestari')
            ->set('contactEmail', 'dewi@novel.id')
            ->set('contactPhone', '081122334455')
            ->set('contactCompany', 'Pustaka Utama')
            ->call('saveContact')
            ->assertHasNoErrors();

        $contact = MailboxContact::where('email', 'dewi@novel.id')->first();
        $this->assertNotNull($contact);
        $this->assertEquals('Dewi Lestari', $contact->name);

        // Hapus kontak
        Livewire::test('webmail.⚡mail-client')
            ->call('deleteContact', $contact->id);

        $this->assertDatabaseMissing('mailbox_contacts', [
            'id' => $contact->id,
        ]);
    }
}
