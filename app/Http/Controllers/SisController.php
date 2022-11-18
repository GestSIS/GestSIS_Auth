<?php

namespace App\Http\Controllers;

use App\Sis;
use Illuminate\Http\Request;

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

    /**
     * Update the specified resource in storage.
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function update(Request $request, $sisId)
    {
        $data = $request->validate([
            'nom' => 'required|string|min:1',
            'description' => 'string|nullable',
            'mobile' => 'required|boolean',
        ]);

        $sis = Sis::find($sisId);
        $sis->fill($data);
        $sis->save();

        return response()->json(['data' => $sis]);
    }
}
