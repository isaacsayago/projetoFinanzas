<?php session_start(); ?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finanzas — Login</title>
    <link rel="icon" href="favicon.png" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --ink: #e2e8f0;
            --paper: #06080f;
            --card: #0d1117;
            --accent: #00d4ff;
            --accent-hover: #00b8e6;
            --danger: #ff2d55;
            --muted: #64748b;
            --border: rgba(255,255,255,0.08);
        }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--paper);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(0,212,255,0.07) 0%, transparent 60%),
                radial-gradient(ellipse 60% 50% at 80% 90%, rgba(255,45,85,0.05) 0%, transparent 60%);
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.02) 1px, transparent 1px);
            background-size: 60px 60px;
            pointer-events: none;
        }

        .login-wrapper {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            padding: 20px;
            animation: fadeUp 0.5s ease both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(24px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        .brand {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand-icon {
            width: 56px;
            height: 56px;
            background: rgba(0,212,255,0.12);
            border: 1px solid rgba(0,212,255,0.25);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 12px;
            box-shadow: 0 0 30px rgba(0,212,255,0.2);
        }

        .brand-icon svg { width: 28px; height: 28px; color: var(--accent); }

        .brand h1 {
            font-family: 'Space Mono', monospace;
            font-size: 22px;
            font-weight: 700;
            color: var(--ink);
            letter-spacing: -0.5px;
        }

        .brand p {
            font-size: 13px;
            color: var(--muted);
            margin-top: 4px;
        }

        .card {
            background: var(--card);
            border-radius: 20px;
            padding: 32px;
            box-shadow: 0 24px 64px rgba(0,0,0,0.3), 0 0 40px rgba(0,212,255,0.04);
            border: 1px solid rgba(0,212,255,0.1);
        }

        .card h2 {
            font-size: 17px;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: 24px;
        }

        .alert-danger {
            background: rgba(255,45,85,0.1);
            border: 1px solid rgba(255,45,85,0.25);
            color: var(--danger);
            padding: 10px 14px;
            border-radius: 10px;
            font-size: 13px;
            margin-bottom: 20px;
        }

        .field {
            margin-bottom: 16px;
        }

        .field label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
        }

        .input-wrap {
            position: relative;
        }

        .input-wrap svg {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 17px;
            height: 17px;
            color: var(--muted);
            pointer-events: none;
        }

        .input-wrap input {
            width: 100%;
            padding: 11px 14px 11px 38px;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-family: 'Sora', sans-serif;
            font-size: 14px;
            color: var(--ink);
            background: rgba(22,27,38,0.8);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .input-wrap input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(0,212,255,0.1), 0 0 20px rgba(0,212,255,0.08);
        }

        .remember {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
        }

        .remember input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: var(--accent);
            cursor: pointer;
        }

        .remember label {
            font-size: 13px;
            color: var(--muted);
            cursor: pointer;
        }

        .btn-login {
            width: 100%;
            padding: 13px;
            background: var(--accent);
            color: #0a0e1a;
            border: none;
            border-radius: 10px;
            font-family: 'Sora', sans-serif;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            letter-spacing: 0.02em;
        }

        .btn-login:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
            box-shadow: 0 4px 20px rgba(0,212,255,0.35);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>
    <div class="login-wrapper">
        <div class="brand">
            <div class="brand-icon">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h1>Finanzas</h1>
            <p>Controle financeiro pessoal</p>
        </div>

        <div class="card">
            <h2>Entrar na sua conta</h2>

            <?php if(isset($_SESSION['erro'])): ?>
                <div class="alert-danger">
                    <?php echo $_SESSION['erro']; unset($_SESSION['erro']); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="processa_login.php">
                <div class="field">
                    <label>Email</label>
                    <div class="input-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                        </svg>
                        <input type="email" name="email" placeholder="seu@email.com" required autofocus>
                    </div>
                </div>

                <div class="field">
                    <label>Senha</label>
                    <div class="input-wrap">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                        </svg>
                        <input type="password" name="senha" placeholder="••••••••" required>
                    </div>
                </div>

                <div class="remember">
                    <input type="checkbox" id="remember" name="remember">
                    <label for="remember">Lembrar-me</label>
                </div>

                <button type="submit" class="btn-login">Entrar</button>
            </form>
        </div>

        <p class="footer-text">Finanzas &copy; <?= date('Y') ?> — Controle seus gastos com clareza</p>
    </div>
</body>
</html>
