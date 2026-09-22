<?php

declare(strict_types=1);

return [
    // Bounded reads. Reaching either limit is reported as incomplete, never zero.
    'search_page_size' => 25000,
    'search_max_pages' => 10,
    'analytics_page_size' => 50000,
    'analytics_max_pages' => 5,
];
