<?php

return [

  /*
    |--------------------------------------------------------------------------
    | API Url
    |--------------------------------------------------------------------------
    |
    | This value is the url of the API, it will be used to verify if a given email might receive some rights
    |
    */
  'api_url' => env('APP_GESTSIS_API_URL', ''),

  /*
    |--------------------------------------------------------------------------
    | APP Url
    |--------------------------------------------------------------------------
    |
    | This value is the url of the APP, it will be used to generate the correct validation link URL
    |
    */
  'app_url' => env('APP_GESTSIS_APP_URL', ''),

];
