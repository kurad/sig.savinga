<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New Demo Request</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f6f8fb; padding: 24px;">
    <div style="max-width: 700px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
        <div style="background: #198754; color: #ffffff; padding: 20px 24px;">
            <h2 style="margin: 0;">New Demo Request</h2>
            <p style="margin: 8px 0 0 0;">A new person has requested a demo from your landing page.</p>
        </div>

        <div style="padding: 24px;">
            <table width="100%" cellpadding="10" cellspacing="0" style="border-collapse: collapse;">
                <tr>
                    <td style="width: 180px; font-weight: bold; border-bottom: 1px solid #e5e7eb;">Full Name</td>
                    <td style="border-bottom: 1px solid #e5e7eb;">{{ $demoRequest->name }}</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; border-bottom: 1px solid #e5e7eb;">Organization</td>
                    <td style="border-bottom: 1px solid #e5e7eb;">{{ $demoRequest->organization }}</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; border-bottom: 1px solid #e5e7eb;">Email</td>
                    <td style="border-bottom: 1px solid #e5e7eb;">{{ $demoRequest->email }}</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; border-bottom: 1px solid #e5e7eb;">Phone</td>
                    <td style="border-bottom: 1px solid #e5e7eb;">{{ $demoRequest->phone }}</td>
                </tr>
                <tr>
                    <td style="font-weight: bold; vertical-align: top;">Message</td>
                    <td>{{ $demoRequest->message ?: 'No message provided.' }}</td>
                </tr>
            </table>

            <div style="margin-top: 24px; color: #6b7280; font-size: 14px;">
                Submitted on: {{ $demoRequest->created_at->format('d M Y H:i') }}
            </div>
        </div>
    </div>
</body>
</html>