<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Booking;
use App\Models\Profile;
use App\Models\BookingCollaborator;

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

        DB::transaction(function() use ($booking, $payload) {
            foreach ($payload['profile_ids'] as $profileId) {
                if ($profileId === (string) $booking->mua_id) continue; // skip lead

                DB::table('booking_collaborators')->updateOrInsert(
                    ['booking_id' => $booking->id, 'profile_id' => $profileId],
                    [
                        'role' => $payload['role'] ?? 'assistant',
                        'status' => 'invited',
                        'invited_at' => now(),
                        'updated_at' => now(),
                        'created_at' => now()
                    ]
                );

                // TODO: push Notification job -> CollaboratorInvited
            }

            $booking->update(['is_collaborative' => true]);
        });

        return response()->json(['message' => 'Invited'], 200);
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
