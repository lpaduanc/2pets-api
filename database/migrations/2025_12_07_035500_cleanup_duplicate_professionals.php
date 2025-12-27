<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Cleanup duplicate CNPJ entries and orphaned records
     */
    public function up(): void
    {
        // Find and fix duplicate CNPJs
        $duplicateCnpjs = DB::table('professionals')
            ->select('cnpj', DB::raw('COUNT(*) as count'))
            ->whereNotNull('cnpj')
            ->where('cnpj', '!=', '')
            ->groupBy('cnpj')
            ->having('count', '>', 1)
            ->get();
        
        foreach ($duplicateCnpjs as $duplicate) {
            // Keep the most recent record, delete others
            $records = DB::table('professionals')
                ->where('cnpj', $duplicate->cnpj)
                ->orderBy('updated_at', 'desc')
                ->get();
            
            $keepId = $records->first()->id;
            
            // Delete duplicates
            DB::table('professionals')
                ->where('cnpj', $duplicate->cnpj)
                ->where('id', '!=', $keepId)
                ->delete();
            
            Log::info("Cleaned up duplicate CNPJ: {$duplicate->cnpj}, kept ID: {$keepId}");
        }
        
        // Find and fix users with multiple professional records
        $duplicateUsers = DB::table('professionals')
            ->select('user_id', DB::raw('COUNT(*) as count'))
            ->groupBy('user_id')
            ->having('count', '>', 1)
            ->get();
        
        foreach ($duplicateUsers as $duplicate) {
            // Keep the most recent record, delete others
            $records = DB::table('professionals')
                ->where('user_id', $duplicate->user_id)
                ->orderBy('updated_at', 'desc')
                ->get();
            
            $keepId = $records->first()->id;
            
            // Delete duplicates
            DB::table('professionals')
                ->where('user_id', $duplicate->user_id)
                ->where('id', '!=', $keepId)
                ->delete();
            
            Log::info("Cleaned up duplicate user_id: {$duplicate->user_id}, kept ID: {$keepId}");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Cannot reverse cleanup
    }
};

