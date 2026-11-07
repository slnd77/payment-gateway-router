<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · Payment Gateway Router demo</title>
    <style>
        :root { --bg: #f4f5f7; --card: #fff; --text: #1f2933; --muted: #616e7c; --line: #e4e7eb; --accent: #2563eb; --ok: #15803d; --bad: #b91c1c; --warn: #b45309; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); }
        main { max-width: 760px; margin: 40px auto; padding: 0 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        p { margin: 0 0 12px; color: var(--muted); }
        .card { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 20px; margin-bottom: 16px; }
        label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
        input, select { width: 100%; padding: 9px 10px; border: 1px solid #cbd2d9; border-radius: 6px; font: inherit; }
        .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
        @media (max-width: 560px) { .grid { grid-template-columns: 1fr; } }
        button, .button { display: inline-block; margin-top: 16px; padding: 10px 18px; border: 0; border-radius: 6px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; text-decoration: none; }
        .error { color: var(--bad); font-size: 13px; margin-top: 8px; }
        code { font: 13px ui-monospace, Consolas, monospace; background: #eef0f3; padding: 1px 5px; border-radius: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        td { padding: 6px 0; border-bottom: 1px solid var(--line); vertical-align: top; }
        td:first-child { color: var(--muted); width: 40%; }
        .status { font-weight: 700; text-transform: uppercase; }
        .status-success { color: var(--ok); } .status-failed { color: var(--bad); } .status-pending { color: var(--warn); }
        ul { margin: 0; padding-left: 18px; color: var(--muted); }
    </style>
</head>
<body>
<main>
    @yield('content')
</main>
</body>
</html>
