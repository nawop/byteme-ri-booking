<?php
return [
  // WhatsApp via CallMeBot (one recipient – your RI WhatsApp group admin phone)
  // https://api.callmebot.com/whatsapp.php?phone=...&text=...&apikey=...
  'callmebot' => [
    'phone' => 'YOUR_PHONE_INTL_FORMAT',  // e.g. '3526XXXXXXX'
    'apikey' => 'YOUR_CALLMEBOT_APIKEY'
  ],

  // Admin approval actions require this bearer token in the request header:
  // Authorization: Bearer YOUR_RANDOM_SECRET
  'admin_secret' => 'admin_secret',

  // School year/trimester logic (adjust if needed)
  'trimesters' => [
    // T1: Sep–Dec, T2: Jan–Mar, T3: Apr–Jul (Lux calendar-ish; tweak if needed)
    'T1' => [ 'start' => [9,15],  'end' => [12,19] ],
    'T2' => [ 'start' => [1,5],  'end' => [3,27]  ],
    'T3' => [ 'start' => [4,13],  'end' => [7,15]  ]
  ],
];

