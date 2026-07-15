<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }} API</title>
        <style>
            :root {
                color-scheme: dark;
                --bg: #0a0a0a;
                --panel: #141414;
                --border: #262626;
                --text: #ededec;
                --muted: #8a8a86;
                --accent: #22c55e;
            }
            * { box-sizing: border-box; }
            html, body {
                height: 100%;
                margin: 0;
            }
            body {
                background: radial-gradient(circle at 50% 0%, #161616 0%, var(--bg) 60%);
                color: var(--text);
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .card {
                width: 100%;
                max-width: 28rem;
                margin: 1.5rem;
                background: var(--panel);
                border: 1px solid var(--border);
                border-radius: 0.75rem;
                padding: 2rem;
                text-align: center;
                box-shadow: 0 20px 40px -20px rgba(0, 0, 0, 0.6);
            }
            .status {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                font-size: 0.75rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--accent);
                margin-bottom: 1.5rem;
            }
            .dot {
                width: 0.5rem;
                height: 0.5rem;
                border-radius: 999px;
                background: var(--accent);
                box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.15);
                animation: pulse 2s ease-in-out infinite;
            }
            @keyframes pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.4; }
            }
            h1 {
                font-size: 1.25rem;
                font-weight: 600;
                margin: 0 0 2rem;
                color: var(--text);
            }
            .uptime-label {
                font-size: 0.7rem;
                text-transform: uppercase;
                letter-spacing: 0.08em;
                color: var(--muted);
                margin-bottom: 0.5rem;
            }
            .uptime-value {
                font-size: 2.25rem;
                font-weight: 600;
                font-variant-numeric: tabular-nums;
                letter-spacing: 0.02em;
            }
            .footer {
                margin-top: 2rem;
                padding-top: 1.5rem;
                border-top: 1px solid var(--border);
                font-size: 0.75rem;
                color: var(--muted);
                display: flex;
                justify-content: space-between;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="status"><span class="dot"></span> Operational</div>
            <h1>{{ config('app.name') }} API</h1>
            <div class="uptime-label">Server Uptime</div>
            <div class="uptime-value" id="uptime" data-booted-at="{{ $bootedAtIso }}">{{ $uptime }}</div>
            <div class="footer">
                <span>PHP {{ PHP_VERSION }}</span>
                <span> {{ app()->version() }}</span>
            </div>
        </div>

        <script>
            const el = document.getElementById('uptime');
            const bootedAt = new Date(el.dataset.bootedAt).getTime();

            function pad(n) { return String(n).padStart(2, '0'); }

            function render() {
                let seconds = Math.max(0, Math.floor((Date.now() - bootedAt) / 1000));
                const days = Math.floor(seconds / 86400); seconds -= days * 86400;
                const hours = Math.floor(seconds / 3600); seconds -= hours * 3600;
                const minutes = Math.floor(seconds / 60); seconds -= minutes * 60;

                el.textContent = days > 0
                    ? `${days}d ${pad(hours)}:${pad(minutes)}:${pad(seconds)}`
                    : `${pad(hours)}:${pad(minutes)}:${pad(seconds)}`;
            }

            render();
            setInterval(render, 1000);
        </script>
    </body>
</html>
