{{-- resources/views/emails/transaction-approved.blade.php --}}
    <!DOCTYPE html>
<html dir="rtl">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #28a745; color: white; padding: 15px; text-align: center; border-radius: 5px 5px 0 0; }
        .content { background-color: #f9f9f9; padding: 25px; border-radius: 0 0 5px 5px; }
        .details { background-color: white; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .details p { margin: 8px 0; }
        .footer { text-align: center; margin-top: 20px; color: #777; font-size: 0.9em; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        @if($role === 'sender')
            <h1>✅ تمت الموافقة على تحويلك</h1>
        @else
            <h1>✅ لقد استلمت تحويلًا</h1>
        @endif
    </div>
    <div class="content">
        <p>عزيزي/عزيزتي <strong>{{ $user->first_name }} {{ $user->last_name }}</strong>,</p>

        <div class="details">
            <p><strong>تفاصيل المعاملة:</strong></p>
            <p>المبلغ: <strong>{{ $amountFormatted }}</strong></p>
            <p>الوصف: {{ $transaction->description ?? 'بدون وصف' }}</p>
            <p>تاريخ الموافقة: {{ $approvalDate }}</p>
            <p>الحالة: <span style="color: green; font-weight: bold;">✅ معتمدة</span></p>

            @if($role === 'sender')
                <p>حسابك: {{ $transaction->sourceAccount->account_number }}</p>
                <p>الحساب المستقبل: {{ $transaction->targetAccount->account_number }}</p>
            @else
                <p>حسابك: {{ $transaction->targetAccount->account_number }}</p>
                <p>المرسل: {{ $transaction->initiator->first_name ?? 'غير معروف' }} {{ $transaction->initiator->last_name ?? '' }}</p>
            @endif
        </div>

        <p>نشكرك على ثقتك بنا</p>
    </div>
    <div class="footer">
        <p>© {{ date('Y') }} نظام المعاملات المالية</p>
    </div>
</div>
</body>
</html>
