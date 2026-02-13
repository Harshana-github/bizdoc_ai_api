<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SystemSetting;
use Illuminate\Http\Request;

class SystemSettingController extends Controller
{
    public function index()
    {
        $settings = SystemSetting::first();

        if (!$settings) {
            $settings = SystemSetting::create([
                'company_name' => 'My Company'
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $settings
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'company_name' => 'required|string|max:255'
        ]);

        $settings = SystemSetting::first();

        if (!$settings) {
            $settings = SystemSetting::create([
                'company_name' => $request->company_name
            ]);
        } else {
            $settings->update([
                'company_name' => $request->company_name
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Company name updated successfully',
            'data' => $settings
        ]);
    }
}
