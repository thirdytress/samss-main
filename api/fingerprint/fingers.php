<?php
// List of supported finger names for enrollment UI and backend
header('Content-Type: application/json');
echo json_encode([
    'right_thumb' => 'Right Thumb',
    'right_index' => 'Right Index',
    'right_middle' => 'Right Middle',
    'left_thumb' => 'Left Thumb',
    'left_index' => 'Left Index',
    'left_middle' => 'Left Middle',
]);
