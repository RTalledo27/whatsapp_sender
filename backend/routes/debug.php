<?php

use Illuminate\Support\Facades\Route;

Route::get('/debug/latest-campaign', function() {
    $campaign = \App\Models\Campaign::latest()->first();
    
    if (!$campaign) {
        return response()->json(['error' => 'No campaigns found']);
    }
    
    return response()->json([
        'campaign' => $campaign->toArray(),
        'template_name_type' => gettype($campaign->template_name),
        'template_name_value' => $campaign->template_name,
        'template_name_empty' => empty($campaign->template_name),
        'template_parameters' => $campaign->template_parameters,
        'messages_count' => $campaign->messages()->count(),
    ]);
});
