<?php
// Standalone page — no layout needed
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>404 — GI Stats</title>
<style>body{background:#1a1a1a;color:rgba(255,255,255,0.87);font-family:Inter,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;}</style>
</head>
<body>
<div style="text-align:center;">
    <h1 style="font-size:48px;margin:0 0 8px;">404</h1>
    <p style="color:rgba(255,255,255,0.55);">Page not found</p>
    <a href="<?= htmlspecialchars($config['base_url']) ?>/" style="color:#00754A;">← Home</a>
</div>
</body>
</html>
