<?php

return [
    // Applies to every backdated sale, including administrators. Zero disables backdating.
    'max_backdate_days' => (int) env('POS_MAX_BACKDATE_DAYS', 30),
];
