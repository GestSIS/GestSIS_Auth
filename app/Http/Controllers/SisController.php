<?php

namespace App\Http\Controllers;

use App\Models\Sis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SisController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        $sis = Sis::get();

        return response()->json(['data' => $sis]);
    }

    /**
     * Create the specified resource in storage.
     */
    public function store(Request $request): JsonResponse
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
     */
    public function update(Request $request, int $sisId): JsonResponse
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
