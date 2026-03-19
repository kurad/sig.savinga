<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDemoRequestRequest;
use App\Mail\DemoRequestNotification;
use App\Models\DemoRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class DemoRequestController extends Controller
{
    public function store(StoreDemoRequestRequest  $request)
    {
        
        $demoRequest = DemoRequest::create($request->validated());

        try {
            Mail::to(config('mail.demo_receiver_address'))->send(
                new DemoRequestNotification($demoRequest)
            );
        } catch (\Throwable $e) {
            // Keep request saved even if email sending fails
            report($e);
        }

        return response()->json([
            'message' => 'Your demo request has been submitted successfully.'
        ], 201);
    }
}
