<?php
return [
  // trimester windows (unchanged; adjust if needed)
  'trimesters' => [
    'T1' => ['start'=>[9,1],  'end'=>[12,20]],
    'T2' => ['start'=>[1,7],  'end'=>[3,31]],
    'T3' => ['start'=>[4,15], 'end'=>[7,15]],
  ],

  // Secrets from env
  'admin_secret' => getenv('ADMIN_SECRET') ?: 'changeme',

  // Optional WhatsApp notifications via CallMeBot
  'callmebot' => [
    'phone'  => getenv('CALLMEBOT_PHONE') ?: '',
    'apikey' => getenv('CALLMEBOT_KEY')   ?: '',
  ],

  // DB path (Render injects /data/db.sqlite via env)
  'db_path' => getenv('DB_PATH') ?: __DIR__ . '/../db.sqlite',
];
