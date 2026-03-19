<?php

namespace App\Services;

use Log;

class SmsService
{
    // public function send(string $phone, string $message): array
    // {
    //     $data = [
    //         "sender"     => env('INTOUCH_SENDER', '250788407941'),
    //         "recipients" => $phone,
    //         "message"    => $message,
    //         "dlrurl"     => env('INTOUCH_DLR_URL'),
    //     ];

    //     $url = env('INTOUCH_URL', "https://www.intouchsms.co.rw/api/sendsms/.json");
    //     $username = env('INTOUCH_USERNAME');
    //     $password = env('INTOUCH_PASSWORD');

    //     $payload = http_build_query($data);

    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, $url);
    //     curl_setopt($ch, CURLOPT_USERPWD, $username . ":" . $password);
    //     curl_setopt($ch, CURLOPT_POST, true);
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

    //     $result = curl_exec($ch);
    //     $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //     $err = curl_error($ch);
    //     curl_close($ch);

    //     return [
    //         'http_code' => $httpcode,
    //         'response'  => $result,
    //         'error'     => $err ?: null,
    //     ];
    // }

    public function send(string $phone, string $message): array
    {
        $url = env('INTOUCH_URL', "https://www.intouchsms.co.rw/api/sendsms/.json");

        $data = [
            "sender"     => env('INTOUCH_SENDER', 'Intouch'),
            "recipients" => $this->normalizePhoneToMsisdn($phone),
            "message"    => $message,
            // "dlrurl"  => env('INTOUCH_DLR_URL'),
        ];

        $payload  = http_build_query($data);
        $username = env('INTOUCH_USERNAME');
        $password = env('INTOUCH_PASSWORD');
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_USERPWD => $username . ":" . $password,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // helpful for debugging if needed:
            // CURLOPT_VERBOSE => true,
        ]);

        $result   = curl_exec($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        $json = null;
        if (is_string($result) && $result !== '') {
            $json = json_decode($result, true);
        }

        // Determine "ok" more reliably:
        $okHttp = ($httpcode >= 200 && $httpcode < 300);
        $okJson = is_array($json) && (
            // depending on Intouch response format, adjust these keys:
            (($json['success'] ?? null) === true) ||
            (($json['status'] ?? null) === 'success') ||
            (($json['response'] ?? null) === 'OK') ||
            (($json['error'] ?? null) === 0)
        );

        return [
            'ok'        => $okHttp && !$err && ($okJson || $json === null), // if unknown JSON, fall back to HTTP
            'http_code' => $httpcode,
            'error'     => $err ?: null,
            'response'  => $result,
            'json'      => $json,
            'to'        => $data['recipients'],
            'sender'    => $data['sender'],
        ];
    }

    /**
     * Always return Rwanda number as MSISDN: 2507XXXXXXXX
     */
    protected function normalizePhoneToMsisdn(string $phone): string
    {
        $p = preg_replace('/\D+/', '', $phone);

        // 07XXXXXXXX -> 2507XXXXXXXX
        if (preg_match('/^07[89]\d{7}$/', $p)) {
            return '250' . substr($p, 1);
        }

        // 7XXXXXXXX -> 2507XXXXXXXX
        if (preg_match('/^7[89]\d{7}$/', $p)) {
            return '250' . $p;
        }

        // already MSISDN
        if (preg_match('/^2507[89]\d{7}$/', $p)) {
            return $p;
        }

        throw new \InvalidArgumentException('Invalid Rwanda phone number.');
    }
}
