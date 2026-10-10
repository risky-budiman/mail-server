<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\VirtualDomain;
use App\Models\VirtualUser;
use App\Models\MailboxEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

class WebmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_webmail_client_renders_successfully()
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

        MailboxEmail::create([
            'virtual_user_id' => $user->id,
            'folder' => 'inbox',
            'from_name' => 'Sender Test',
            'from_email' => 'sender@example.com',
            'to' => $user->email,
            'subject' => 'Tes Email',
            'body' => 'Isi email tes.',
        ]);

        $this->actingAs($user, 'mailbox');

        $response = $this->get('/webmail');
        $response->assertStatus(200);

        Livewire::test('webmail.mail-client')
            ->assertStatus(200);
    }

    public function test_webmail_client_handles_missing_contacts_table_without_500()
    {
        $domain = VirtualDomain::create(['name' => 'perusahaan2.co.id', 'is_active' => true]);
        $user = VirtualUser::create([
            'domain_id' => $domain->id,
            'email' => 'staff@perusahaan2.co.id',
            'password' => bcrypt('secret123'),
            'maildir_path' => 'perusahaan2.co.id/staff/',
            'quota_bytes' => 1073741824,
            'is_active' => true,
        ]);

        $this->actingAs($user, 'mailbox');

        \Illuminate\Support\Facades\Schema::dropIfExists('mailbox_contacts');

        $response = $this->get('/webmail');
        $response->assertStatus(200);
    }

    public function test_webmail_client_handles_null_created_at_without_error()
    {
        $domain = VirtualDomain::create(['name' => 'perusahaan3.co.id', 'is_active' => true]);
        $user = VirtualUser::create([
            'domain_id' => $domain->id,
            'email' => 'finance@perusahaan3.co.id',
            'password' => bcrypt('secret123'),
            'maildir_path' => 'perusahaan3.co.id/finance/',
            'quota_bytes' => 1073741824,
            'is_active' => true,
        ]);

        $email = new MailboxEmail();
        $email->timestamps = false;
        $email->virtual_user_id = $user->id;
        $email->folder = 'inbox';
        $email->from_name = 'Bank';
        $email->from_email = 'bank@example.com';
        $email->to = $user->email;
        $email->subject = 'Laporan';
        $email->body = 'Laporan bulanan';
        $email->date_human = null;
        $email->created_at = null;
        $email->updated_at = null;
        $email->save();

        $this->actingAs($user, 'mailbox');

        $response = $this->get('/webmail');
        $response->assertStatus(200);
    }
}
