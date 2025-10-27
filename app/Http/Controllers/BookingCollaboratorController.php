<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\Notification as AppNotification; 
use Illuminate\Support\Str;
use App\Models\Booking;
use App\Models\Profile;

class BookingCollaboratorController extends Controller
{
    public function index($bookingId)
    {
        $booking = Booking::with('collaborators')->findOrFail($bookingId);
        return response()->json($booking->collaborators);
    }

    public function invite(Request $req, $bookingId)
    {
        $payload = $req->validate([
            'profile_ids' => 'required|array|min:1',
            'profile_ids.*' => 'uuid|exists:profiles,id',
            'role' => 'nullable|in:assistant,co-mua,lead',
        ]);

        $booking = Booking::findOrFail($bookingId);

        // Authorization: hanya lead (mua_id) atau admin
        if ($req->user()->profile->id !== $booking->mua_id && ! $req->user()->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $role = $payload['role'] ?? 'assistant';
        $processedProfileIds = [];

        // 1) update/insert booking_collaborators in a transaction
        DB::transaction(function() use ($booking, $payload, $role, &$processedProfileIds) {
            foreach ($payload['profile_ids'] as $profileId) {
                // skip lead sendiri
                if ($profileId === (string) $booking->mua_id) continue;

                DB::table('booking_collaborators')->updateOrInsert(
                    ['booking_id' => $booking->id, 'profile_id' => $profileId],
                    [
                        'role' => $role,
                        'status' => 'invited',
                        'invited_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

                $processedProfileIds[] = $profileId;
            }

            // tandai booking collaborative jika belum
            if (! $booking->is_collaborative) {
                $booking->update(['is_collaborative' => true]);
            }
        });

        if (empty($processedProfileIds)) {
            return response()->json(['message' => 'No valid profiles to invite'], 200);
        }

        // 2) Buat notifikasi per user (menggunakan model App\Models\Notification)
        // Kumpulkan hasil untuk response debugging
        $createdNotifications = [];

        // Siapkan data inviter
        $inviterProfile = optional($req->user())->profile;
        $inviterName = $inviterProfile->name ?? optional($req->user())->name ?? 'MUA';
        $invoiceLabel = $booking->invoice_number ? $booking->invoice_number : ("#" . $booking->id);

        foreach ($processedProfileIds as $profileId) {
            // ambil profil dan user_id target
            $profile = Profile::find($profileId);
            if (! $profile) {
                Log::warning("Invite: Profile not found while creating notification: {$profileId}");
                continue;
            }

            // cari user id terkait — sesuaikan field jika di modelmu beda
            $targetUserId = $profile->user_id ?? optional($profile->user)->id ?? null;
            if (! $targetUserId) {
                Log::warning("Invite: Profile {$profileId} has no linked user_id; skipping notification insert.");
                continue;
            }

            // buat title & message yang bisa ditampilkan di FE
            $title = "Undangan Kolaborasi Booking";
            $message = sprintf(
                "%s mengundang Anda sebagai %s pada booking %s",
                $inviterName,
                $role,
                $invoiceLabel
            );

            try {
                $notif = AppNotification::create([
                    'user_id' => $targetUserId,
                    'title' => $title,
                    'message' => $message,
                    'type' => 'booking_invite', // custom type, bisa kamu ganti
                    'is_read' => false,
                ]);

                $createdNotifications[] = [
                    'profile_id' => $profileId,
                    'user_id' => $targetUserId,
                    'notification_id' => $notif->id ?? null,
                ];
            } catch (\Throwable $ex) {
                Log::error("Invite: failed to insert notification for profile {$profileId}: " . $ex->getMessage());
                // lanjutkan ke profile selanjutnya
            }
        }

        return response()->json([
            'message' => 'Invited',
            'invited_count' => count($processedProfileIds),
            'invited_ids' => $processedProfileIds,
            'notifications' => $createdNotifications,
        ], 200);
    }

    public function remove(Request $req, $bookingId, $profileId)
    {
        $booking = Booking::findOrFail($bookingId);

        // Authorization: only lead or admin
        if ($req->user()->profile->id !== $booking->mua_id && ! $req->user()->hasRole('admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::transaction(function() use ($booking, $profileId) {
            DB::table('booking_collaborators')
                ->where('booking_id', $booking->id)
                ->where('profile_id', $profileId)
                ->delete();

            $count = DB::table('booking_collaborators')->where('booking_id', $booking->id)->count();
            if ($count === 0) {
                $booking->update(['is_collaborative' => false]);
            }
        });

        return response()->json(['message' => 'Removed'], 200);
    }

    public function respondInvite(Request $req, $bookingId)
    {
        $req->validate(['status' => 'required|in:accepted,declined']);

        $profileId = $req->user()->profile->id;

        $updated = DB::table('booking_collaborators')
            ->where('booking_id', $bookingId)
            ->where('profile_id', $profileId)
            ->update([
                'status' => $req->status,
                'responded_at' => now(),
                'updated_at' => now()
            ]);

        if (! $updated) {
            return response()->json(['message' => 'Not found or not invited'], 404);
        }

        return response()->json(['message' => 'OK'], 200);
    }
}
