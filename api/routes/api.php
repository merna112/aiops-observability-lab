<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

Route::get('/normal', function () {
    return response()->json([
        "message" => "normal response"
    ]);
});

Route::get('/slow', function (Request $request) {

    if ($request->query('hard')) {
        sleep(rand(5, 7));
    } else {
        sleep(2);
    }

    return response()->json([
        "message" => "slow response"
    ]);
});

Route::get('/error', function () {
    throw new Exception("simulated system error");
});

Route::get('/random', function () {

    if (rand(0, 1)) {
        return response()->json([
            "message" => "random success"
        ]);
    }

    throw new Exception("random failure");
});