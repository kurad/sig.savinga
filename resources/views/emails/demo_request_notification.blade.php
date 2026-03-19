<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New Demo Request</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f7f7f7; padding: 20px;">
    <div style="max-width: 700px; margin: auto; background: #ffffff; border-radius: 10px; padding: 30px; border: 1px solid #e5e7eb;">
        <h2 style="margin-top: 0; color: #111827;">New Demo Request Submitted</h2>
        <p style="color: #4b5563;">
            A new demo request has been submitted through the landing page.
        </p>

        <table width="100%" cellpadding="8" cellspacing="0" style="border-collapse: collapse; margin-top: 20px;">
            <tr>
                <td style="font-weight: bold; width: 180px; border-bottom: 1px solid #e5e7eb;">Full Name</td>
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
    </div>
</body>
</html>