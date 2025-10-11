<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\{
    BookingController,
    OfferingController,
    PortfolioController,
    NotificationController,
    MuaController
};

/*
|--------------------------------------------------------------------------
| Public (no auth)
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:30,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:20,1');
    Route::post('/register-mua', [AuthController::class, 'registerMua'])->middleware('throttle:20,1');
});

/*
|--------------------------------------------------------------------------
| Protected (Sanctum)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // --- Auth / Profile ---
    Route::prefix('auth')->group(function () {
        Route::get('/me',                  [AuthController::class, 'me']);
        Route::post('/logout',             [AuthController::class, 'logout']); // ?all=true untuk revoke semua token

        // Update profile:
        // - PATCH JSON langsung
        // - POST multipart (FormData) + _method=PATCH untuk upload foto
        // Route::patch('/profile',           [AuthController::class, 'updateProfile']);
        Route::post('/profile',            [AuthController::class, 'updateProfile']); // untuk multipart + _method=PATCH

        // Toggle online/offline
        Route::patch('/profile/online',    [AuthController::class, 'toggleOnline']);

        // Ganti password (JANGAN pakai /auth/profile agar tidak bentrok)
        // body: { current_password, new_password, new_password_confirmation }
        Route::post('/password',           [AuthController::class, 'changeProfile']);
        // kalau mau pakai PATCH juga boleh:
        // Route::patch('/password',       [AuthController::class, 'changeProfile']);
    });
 // --- MUA ---
    Route::get('/mua/{muaId}', [MuaController::class, 'getMuaProfile']);
    Route::get(   '/mua-location',                [MuaController::class, 'getMuaLocation']);
  


    // --- Bookings ---
    Route::get('bookings', [BookingController::class, 'index']);
    Route::get('users/{user}/bookings', [BookingController::class, 'getUserBookings']);
    Route::post('bookings', [BookingController::class, 'store']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);
    Route::put('bookings/{booking}', [BookingController::class, 'update']);
    
    Route::post('bookings/{booking}/in-progress', [BookingController::class, 'markInProgress']);
    Route::post('bookings/{booking}/complete',    [BookingController::class, 'markComplete']);
    
    // --- Offerings ---
    // LISTING & DETAIL
    Route::get( '/offerings',                 [OfferingController::class, 'index']);     // filter & paginate
    Route::get( '/offerings/mine',            [OfferingController::class, 'mine']);      // offering milik user login (MUA)
    Route::get( '/offerings/{offering}',      [OfferingController::class, 'show']);

    // CREATE / UPDATE / DELETE
    Route::post(   '/offerings',              [OfferingController::class, 'store']);
    Route::patch( '/offerings/{offering}', [OfferingController::class, 'update']);
    Route::delete( '/offerings/{offering}',   [OfferingController::class, 'destroy']);
    Route::delete('/offerings/{offering}/pictures', [OfferingController::class, 'deletePictures']);

    // MEDIA & ADD-ONS
    Route::patch( '/offerings/{offering}/pictures', [OfferingController::class, 'pictures']); // mode=add|remove|replace, pictures:[]
    Route::patch( '/offerings/{offering}/addons',   [OfferingController::class, 'addons']);   // mode=add|remove|replace, add_ons:[]

    // BULK
    Route::post( '/offerings/bulk',           [OfferingController::class, 'bulkStore']);


    // --- Portfolios ---
    // LISTING & DETAIL
    Route::get( '/portfolios',                 [PortfolioController::class, 'index']);   // filter & paginate
    Route::get( '/portfolios/mine',            [PortfolioController::class, 'mine']);    // milik user login (MUA)
    Route::get( '/portfolios/{portfolio}',     [PortfolioController::class, 'show']);

    // CREATE / UPDATE / DELETE
    Route::post(   '/portfolios',              [PortfolioController::class, 'store']);
    Route::match(['put','patch'], '/portfolios/{portfolio}', [PortfolioController::class, 'update']);
    Route::delete( '/portfolios/{portfolio}',  [PortfolioController::class, 'destroy']);

    // MEDIA (foto)
    Route::patch( '/portfolios/{portfolio}/pictures', [PortfolioController::class, 'pictures']); // mode=add|remove|replace, photos:[]

    // BULK
    Route::post( '/portfolios/bulk',           [PortfolioController::class, 'bulkStore']);



    // --- Notifications ---
    // LIST & DETAIL
    Route::get(   '/notifications',             [NotificationController::class, 'index']);
    Route::get(   '/notifications/{notification}', [NotificationController::class, 'show']);

    // COUNTER
    Route::get(   '/notifications/unread-count',[NotificationController::class, 'unreadCount']);

    // CREATE (single/bulk)
    Route::post(  '/notifications',             [NotificationController::class, 'store']);

    // READ FLAGS
    Route::patch( '/notifications/{id}/read',   [NotificationController::class, 'markRead']);
    Route::patch( '/notifications/read-all',    [NotificationController::class, 'markAllRead']);

    // DELETE
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::delete('/notifications',             [NotificationController::class, 'destroyAll']); // mass delete (default: only read)

   

});

/*
|--------------------------------------------------------------------------
| Optional: JSON fallback 404
|--------------------------------------------------------------------------
*/
Route::fallback(function () {
    return response()->json(['error' => 'Not Found'], 404);
});
