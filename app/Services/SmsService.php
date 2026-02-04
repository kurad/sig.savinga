<?php

namespace App\Services;

class SmsService
{
    public function send(string $phone, string $message): array
    {
        $data = [
            "sender"     => env('INTOUCH_SENDER', '0788407941'),
            "recipients" => $phone,
            "message"    => $message,
            "dlrurl"     => env('INTOUCH_DLR_URL'),
        ];

        $url = env('INTOUCH_URL', "https://www.intouchsms.co.rw/api/sendsms/.json");
        $username = env('INTOUCH_USERNAME');
        $password = env('INTOUCH_PASSWORD');

        $payload = http_build_query($data);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return [
            'http_code' => $httpcode,
            'response'  => $result,
            'error'     => $err ?: null,
        ];
    }
}
