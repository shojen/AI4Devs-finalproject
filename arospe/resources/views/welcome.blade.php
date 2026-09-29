<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Estamos cocinando código 👨‍💻</title>

    <meta name="description" content="Página web en construcción. El desarrollador está peleándose con un bug.">

    @vite(['resources/css/welcome.css', 'resources/js/welcome.js'])
</head>

<body>

<div class="wrapper">

    <header class="header">
        <div class="brand">
            <div class="brand-icon">&lt;/&gt;</div>
            <span>{{ config('app.name', 'dev') }}</span>
        </div>

        <div class="status">
            <span class="status-dot"></span>
            En desarrollo
        </div>
    </header>


    <main class="hero">

        <!-- Texto principal -->

        <section class="content">

            <div class="eyebrow">
                🚧 HTTP 418 · Coming Soon
            </div>

            <h1>
                Estoy<br>
                <span>compilando.</span>
            </h1>

            <p class="description">
                Esta web está actualmente en construcción.
                <strong>No está rota.</strong>
                Bueno... técnicamente sí, pero es intencionado.
                Estoy escribiendo código, eliminándolo y volviéndolo a escribir
                hasta que funcione.
            </p>

            <div class="progress-header">
                <span>deploy_progress</span>
                <span>73%</span>
            </div>

            <div class="progress">
                <div class="progress-bar"></div>
            </div>

            <p class="progress-note">
                * El porcentaje puede variar dependiendo de cuántos cafés
                queden disponibles.
            </p>

        </section>


        <!-- Terminal -->

        <section class="terminal">

            <div class="terminal-header">
                <span class="circle red"></span>
                <span class="circle yellow"></span>
                <span class="circle green"></span>

                <span class="terminal-title">
                    developer@localhost: ~/website
                </span>
            </div>

            <div class="terminal-body">

                <div class="line">
                    <span class="prompt">$</span>
                    <span class="command"> php artisan serve</span>
                </div>

                <div class="line comment">
                    &nbsp;&nbsp;Starting development server...
                </div>

                <br>

                <div class="line">
                    <span class="prompt">➜</span>
                    <span class="blue"> Building something awesome</span>
                </div>

                <div class="line">
                    <span class="prompt">➜</span>
                    <span class="command"> npm run build</span>
                </div>

                <div class="line warning">
                    ⚠ Warning: developer needs more coffee.
                </div>

                <div class="line">
                    <span class="prompt">➜</span>
                    <span class="command"> npm run coffee</span>
                </div>

                <div class="line error">
                    ✖ Error: coffee machine not found.
                </div>

                <br>

                <div class="line success">
                    ✓ Attempting to solve the problem...
                </div>

                <div class="line">
                    <span class="prompt">$</span>
                    <span class="command"> git push origin production</span><span class="cursor"></span>
                </div>

            </div>
        </section>

    </main>


    <footer class="footer">

        <span>
            © {{ date('Y') }}
            {{ config('app.name', 'Tu nombre') }}
        </span>

        <span
            class="coffee"
            id="coffee"
            title="No hagas clic aquí"
        >
            ☕ Cafés consumidos: <code id="coffeeCount">42</code>
        </span>

    </footer>

</div>

</body>
</html>
