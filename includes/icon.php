<?php
// Simple icon helper for inline SVGs and consistent wrappers
function sams_icon(string $name, string $wrapperClass = 'stat-card__icon', array $attrs = []): string {
    $attrStr = '';
    foreach ($attrs as $k => $v) {
        $attrStr .= ' ' . htmlspecialchars($k, ENT_QUOTES, 'UTF-8') . '="' . htmlspecialchars($v, ENT_QUOTES, 'UTF-8') . '"';
    }

    $svg = '';
    switch ($name) {
        case 'circle':
            $svg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/></svg>';
            break;
        case 'star':
            $svg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path d="M12 2l2.9 6.3L21 9.1l-5 4.3L17 21l-5-3-5 3 1-7.6-5-4.3 6.1-.8L12 2z"/></svg>';
            break;
        case 'building':
            $svg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="1.6"/><path d="M7 7h2v2H7zM11 7h2v2h-2zM15 7h2v2h-2zM7 11h2v2H7zM11 11h2v2h-2zM15 11h2v2h-2z" fill="currentColor"/></svg>';
            break;
        case 'clock':
            $svg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="1.6"/><path d="M12 7v6l4 2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            break;
        case 'download':
            $svg = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M12 3v10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M7 10l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 21h14" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>';
            break;
        case 'eye':
            $svg = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12z" stroke="currentColor" stroke-width="1.6" fill="none"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.6" fill="none"/></svg>';
            break;
        case 'toggle-on':
            $svg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="7" width="20" height="10" rx="5" fill="#10B981"/><circle cx="17" cy="12" r="3" fill="#fff"/></svg>';
            break;
        case 'toggle-off':
            $svg = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="7" width="20" height="10" rx="5" fill="#9CA3AF"/><circle cx="7" cy="12" r="3" fill="#fff"/></svg>';
            break;
        default:
            $svg = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="4" y="4" width="16" height="16" rx="2" stroke="currentColor" stroke-width="1.6"/></svg>';
    }

    // If wrapperClass is empty, return only the SVG markup (useful for inline button icons)
    if ($wrapperClass === '') {
        return $svg;
    }

    return sprintf('<div class="%s" aria-hidden="true"%s>%s</div>', htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8'), $attrStr, $svg);
}
