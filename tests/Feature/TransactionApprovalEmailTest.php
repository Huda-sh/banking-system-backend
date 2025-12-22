<?php

namespace Tests\Feature;

use App\Mail\TransactionApprovedMail;
use App\Models\Account;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class TransactionApprovalEmailTest extends TestCase
{
    use RefreshDatabase;

    protected User $sender;
    protected User $receiver;
    protected Account $senderAccount;
    protected Account $receiverAccount;

    protected function setUp(): void
    {
        parent::setUp();

        // إنشاء الأدوار
        Role::create(['name' => 'Customer']);
        Role::create(['name' => 'Admin']);

        // إنشاء المستخدمين
        $this->sender = User::factory()->create([
            'email' => 'mhranabwdqt971@gmail.com',
            'first_name' => 'محمد',
            'last_name' => 'رحمن',
        ]);

        $this->receiver = User::factory()->create([
            'email' => 'receiver@example.com',
            'first_name' => 'علي',
            'last_name' => 'أحمد',
        ]);

        // ربط المستخدمين بالأدوار
        $this->sender->roles()->attach(Role::where('name', 'Customer')->first());
        $this->receiver->roles()->attach(Role::where('name', 'Customer')->first());

        // إنشاء الحسابات
        $this->senderAccount = Account::factory()->create([
            'account_number' => 'ACC-SENDER-001',
            'balance' => 10000.00,
            'currency' => 'USD',
        ]);

        $this->receiverAccount = Account::factory()->create([
            'account_number' => 'ACC-RECEIVER-001',
            'balance' => 5000.00,
            'currency' => 'USD',
        ]);

        // ربط الحسابات بالمستخدمين (عبر جدول account_users)
        DB::table('account_users')->insert([
            [
                'user_id' => $this->sender->id,
                'account_id' => $this->senderAccount->id,
                'is_owner' => 1
            ],
            [
                'user_id' => $this->receiver->id,
                'account_id' => $this->receiverAccount->id,
                'is_owner' => 1
            ],
        ]);
    }

    /** @test */
    public function it_sends_emails_on_small_transaction_approval()
    {
        Mail::fake();

        $this->actingAs($this->sender);

        $transactionData = [
            'type' => 'transfer',
            'sourceAccountId' => $this->senderAccount->id,
            'targetAccountId' => $this->receiverAccount->id,
            'amount' => 500.00, // ≤ 1000 للحصول على موافقة تلقائية
            'currency' => 'USD',
            'description' => 'اختبار إرسال الإيميل - معاملة صغيرة',
            'reference_number' => 'TXN-TEST-'.now()->format('YmdHis'),
        ];

        $response = $this->postJson('/api/transactions', $transactionData);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'message' => 'تمت الموافقة وإرسال الإيميلات بنجاح',
            ]);

        // التحقق من إرسال الإيميلات
        Mail::assertSent(TransactionApprovedMail::class, function ($mail) {
            return $mail->hasTo('mhranabwdqt971@gmail.com') &&
                str_contains($mail->subject, 'تمت الموافقة على تحويلك');
        });

        Mail::assertSent(TransactionApprovedMail::class, function ($mail) {
            return $mail->hasTo('receiver@example.com') &&
                str_contains($mail->subject, 'لقد استلمت تحويلًا');
        });

        Mail::assertSent(TransactionApprovedMail::class, 2);
    }
}
