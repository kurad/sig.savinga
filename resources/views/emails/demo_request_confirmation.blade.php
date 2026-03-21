<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Demo Request Received</title>
</head>
<body style="font-family: Arial, sans-serif; background: #f6f8fb; padding: 24px;">
    <div style="max-width: 650px; margin: auto; background: #ffffff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden;">

        <!-- Header -->
        <div style="background: #198754; color: #ffffff; padding: 20px;">
            <h2 style="margin: 0;">Demo Request Received</h2>
        </div>

        <!-- Body -->
        <div style="padding: 24px;">
            <p>Dear <strong>{{ $demoRequest->name }}</strong>,</p>

            <p>
                Thank you for your interest in our <strong>Savinga</strong> system.
                We have successfully received your demo request.
            </p>

            <p>
                Our team will review your request and contact you shortly to schedule
                a personalized demonstration.
            </p>

            <h4 style="margin-top: 20px;">What happens next?</h4>

            <ul style="padding-left: 18px; color: #374151;">
                <li>We review your organization’s needs</li>
                <li>We schedule a convenient demo session</li>
                <li>We walk you through the platform features</li>
            </ul>

            <p style="margin-top: 20px;">
                If you have any urgent questions, feel free to reply to this email.
            </p>

            <p style="margin-top: 24px;">
                Best regards,<br>
                <strong>Savinga Team</strong>
            </p>
        </div>

        <!-- Footer -->
        <div style="background: #f9fafb; padding: 16px; text-align: center; font-size: 13px; color: #6b7280;">
            © {{ date('Y') }} Savinga. All rights reserved.
        </div>

    </div>
</body>
</html>