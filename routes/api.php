<?php

use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


Route::group(['prefix' => 'v1'], function () {

    Route::post('login', 'ApiLoginController@login');
    Route::post('register', 'ApiRegisterController@register');
    Route::post('refresh-token', 'ApiRefreshTokenController@refresh');

    Route::middleware('auth:token')->get('/test', function (Request $request) {
        return "TEST";
    });

});
