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
     * Create the specified resource in storage.
     *
     * @param Request $request
     * @return Response
     * @throws Exception
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'api_key' => 'required|string|min:1',
            'nom' => 'required|string|min:1',
            'abreviation' => 'string|nullable',
            'mobile' => 'required|boolean',
        ]);

        $sis = new Sis();
        $sis->fill($data);
        $sis->api_key = $data['api_key'];
        $sis->save();

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
            'abreviation' => 'string|nullable',
            'mobile' => 'required|boolean',
        ]);

        $sis = Sis::find($sisId);
        $sis->fill($data);
        $sis->save();

        return response()->json(['data' => $sis]);
    }
}
