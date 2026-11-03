<?php

namespace App\Http\Controllers\Api\V1\Common;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ContactController extends Controller
{
    public function submitMessage(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'email' => 'required|email|max:150',
            'subject' => 'nullable|string|max:200',
            'message' => 'required|string|max:2000',
        ]);

        $userId = Auth::id() ?? ($request->user() ? $request->user()->id : null);

        if (Schema::hasTable('contact_messages')) {
            DB::table('contact_messages')->insert([
                'user_id' => $userId,
                'name' => $validated['name'],
                'email' => $validated['email'],
                'subject' => $validated['subject'] ?? 'General Inquiry',
                'message' => $validated['message'],
                'ip_address' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json([
            'data' => [
                'message' => 'Your message has been sent successfully. Our support team will reach out to you shortly.',
            ],
            'meta' => null,
            'errors' => null,
        ]);
    }
}

