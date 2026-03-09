<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
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

Route::get('/db', function (Request $request) {

    if ($request->query('fail')) {
        DB::select("SELECT * FROM non_existing_table");
    }

    $data = DB::select("SELECT 1 as test");

    return response()->json([
        "status" => "db success",
        "data" => $data
    ]);
});

Route::post('/validate', function (Request $request) {

    $validated = $request->validate([
        "email" => "required|email",
        "age" => "required|integer|between:18,60"
    ]);

    return response()->json([
        "message" => "validation success",
        "data" => $validated
    ]);
});


Route::get('/metrics', function () {

    $metrics = "# HELP http_requests_total Total requests\n";
    $metrics .= "# TYPE http_requests_total counter\n";

    return response($metrics, 200)
        ->header('Content-Type', 'text/plain');
});