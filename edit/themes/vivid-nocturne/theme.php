<?php
return [
    'name' => 'Vivid Nocturne',
    'description' => 'A bold, dramatic dark theme: near-black indigo surfaces, gradient violet-to-fuchsia accents, and expressive display type.',
    'inspiration' => 'A high-contrast, modern showcase look for sites that want presence and energy.',
    'preview_blurb' => 'Glowing cards, gradient headlines in Syne, and Space Grotesk body text on a midnight backdrop.',
    // Light dashboard palette carrying the theme accent, so the editor keeps its
    // familiar look while picking up the theme's colour. Keys map to editor.css
    // custom properties (without the --wysite- prefix).
    'dashboard' => [
        'bg' => '#f4f1fb',
        'surface' => 'rgba(255, 255, 255, 0.96)',
        'ink' => '#1e1733',
        'muted' => '#6b6386',
        'line' => 'rgba(124, 58, 237, 0.14)',
        'accent' => '#7c3aed',
        'accent-2' => '#db2777',
        'shadow' => '0 16px 44px rgba(124, 58, 237, 0.14)',
        'app-bg' => 'linear-gradient(180deg, #faf8ff, #f1edfb)',
        'font-sans' => '"Space Grotesk", "Segoe UI", system-ui, sans-serif',
        'font-serif' => '"Syne", "Segoe UI", system-ui, sans-serif',
    ],
];
