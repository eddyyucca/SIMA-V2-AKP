<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    // Simpan/perbarui subscription push notification browser milik user yang sedang login
    public function store(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
            'keys.p256dh' => 'nullable|string',
            'keys.auth' => 'nullable|string',
            'contentEncoding' => 'nullable|string',
        ]);

        $request->user()->updatePushSubscription(
            $validated['endpoint'],
            $validated['keys']['p256dh'] ?? null,
            $validated['keys']['auth'] ?? null,
            $validated['contentEncoding'] ?? null
        );

        return response()->json(['success' => true]);
    }

    // Hapus subscription (dipanggil saat user mematikan izin notifikasi)
    public function destroy(Request $request)
    {
        $validated = $request->validate([
            'endpoint' => 'required|string',
        ]);

        $request->user()->deletePushSubscription($validated['endpoint']);

        return response()->json(['success' => true]);
    }
}
