<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Sis;

class SisController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $sis = Sis::get();

        return response()->json(['data' => $sis]);
    }

}
