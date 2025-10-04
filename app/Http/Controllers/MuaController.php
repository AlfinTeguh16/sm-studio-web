<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\Http\Controllers\Controller;     
use App\Models\Profile;

class MuaController extends Controller
{
    public function getMuaLocation (Request $req)
    {
        $location = Profile::select('id', 'name', 'location_lat', 'location_lng', 'address', 'photo_url')
            ->whereNotNull('location_lat')
            ->whereNotNull('location_lng')
            ->where('is_online', true)
            ->get();

        return response()->json(['message' => 'success', 'data' => $location], 200);
    }

    public function getMuaProfile(Request $req, $muaId)
    {
        $profile = Profile::find($muaId);
        if (!$profile) {
            return response()->json(['message' => 'MUA not found'], 404);
        }
        return response()->json(['message' => 'success', 'data' => $profile], 200);
    }
}
