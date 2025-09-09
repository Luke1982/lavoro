<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Artisan;

class SnelStartImportController extends Controller
{
    public function importCustomers()
    {
        Artisan::call('snelstart:fetch-relaties');
        return redirect()->back()->with('success', 'SnelStart klanten import gestart.');
    }

    public function importMaterials()
    {
        Artisan::call('snelstart:fetch-artikelen');
        return redirect()->back()->with('success', 'SnelStart materialen import gestart.');
    }
}
