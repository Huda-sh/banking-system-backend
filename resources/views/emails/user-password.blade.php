<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Account Credentials</title>
</head>

<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #f4f4f4; padding: 20px; border-radius: 5px;">
        <h2 style="color: #333; margin-top: 0;">Welcome to Banking System</h2>

        <p>Hello {{ $user->first_name }} {{ $user->last_name }},</p>

        <p>Your account has been created successfully. Below are your login credentials:</p>

        <div style="background-color: #fff; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #007bff;">
            <p style="margin: 5px 0;"><strong>Email:</strong> {{ $user->email }}</p>
            <p style="margin: 5px 0;"><strong>Password:</strong> <code style="background-color: #f4f4f4; padding: 2px 6px; border-radius: 3px; font-size: 14px;">{{ $password }}</code></p>
        </div>

        <p>If you have any questions or need assistance, please contact our support team.</p>

        <p style="margin-top: 30px; color: #666; font-size: 12px;">
            Best regards,<br>
            Banking System Team
        </p>
    </div>
</body>

</html>