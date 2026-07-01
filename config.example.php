<?php

return [
    'broker_host' => '192.168.1.24',
    'broker_port' => 1883,
    'serial'      => 'YOUR_SERIAL_HERE',

    // Camera streams — NOT currently working for most users. Yarbo has not opened
    // local RTSP access; the official app uses cloud video. Keep this false.
    'cameras_enabled' => false,
    'camera_auto_detect' => true,
    // Use broker IP by default, or 127.0.0.1 if you SSH-tunnel camera ports locally.
    'camera_host'     => null,
    'ffmpeg_path'     => 'ffmpeg',
    'camera_fps'      => 5,

    // Default Yarbo RTSP ports: 19201=front, 19202=left, 19203=right, 19204=rear
    // Override with full rtsp URL per camera if needed:
    // 'front' => ['name' => 'Front', 'rtsp' => 'rtsp://127.0.0.1:19201/live/chn0'],
    'cameras' => [
        'front' => ['name' => 'Front', 'port' => 19201, 'state_key' => 'cam_m_state'],
        'left'  => ['name' => 'Left',  'port' => 19202, 'state_key' => 'cam_l_state'],
        'right' => ['name' => 'Right', 'port' => 19203, 'state_key' => 'cam_r_state'],
        'rear'  => ['name' => 'Rear',   'port' => 19204, 'state_key' => 'cam_b_state'],
    ],
];
