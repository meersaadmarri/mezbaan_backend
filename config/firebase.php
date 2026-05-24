<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging — multi-app / multi-project
    |--------------------------------------------------------------------------
    |
    | Consumer app (mezban)      → mezbaan-f0641
    | Business app (mezban_business) → mezbaan-5ca22
    |
    | Download a service-account JSON for EACH project from Firebase Console
    | → Project settings → Service accounts → Generate new private key
    |
    | Place files at:
    |   storage/app/firebase/mezbaan-f0641.json
    |   storage/app/firebase/mezbaan-5ca22.json
    |
    */
    'projects' => [
        'mezbaan-f0641' => [
            'credentials' => env(
                'FIREBASE_CREDENTIALS_F0641',
                storage_path('app/firebase/mezbaan-f0641.json')
            ),
            'apps' => ['consumer'],
        ],
        'mezbaan-5ca22' => [
            'credentials' => env(
                'FIREBASE_CREDENTIALS_5CA22',
                storage_path('app/firebase/mezbaan-5ca22.json')
            ),
            'apps' => ['business'],
        ],
    ],

    /** Default project when legacy single-file env is used */
    'project_id' => env('FIREBASE_PROJECT_ID'),

    'credentials_path' => env(
        'FIREBASE_CREDENTIALS',
        storage_path('app/firebase/service-account.json')
    ),

];
