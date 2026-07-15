@php
    /**
     * Cache-bust CRM UI assets by filemtime so partner machines cannot serve stale JS/CSS
     * after a git pull without relying on manual hard-refresh forever.
     */
    if (! function_exists('crm_ui_asset')) {
        function crm_ui_asset(string $path): string
        {
            $relative = ltrim($path, '/');
            $full = public_path($relative);
            $version = is_file($full) ? (string) filemtime($full) : '1';

            return asset($relative).'?v='.$version;
        }
    }
@endphp
