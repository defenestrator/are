<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32x32.png') }}">
    <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16x16.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">
    <link rel="manifest" href="{{ asset('site.webmanifest') }}">
    
    <title>ARE</title>
    <style>
        body {
            background-color: #000;
            color: #ccc;
            font-family: 'Times New Roman', Times, serif;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            text-align: center;
        }
        .container {
            max-width: 800px;
            padding: 20px;
        }
        h1 {
            font-size: 3em;
            margin-bottom: 0.5em;
        }
        p {
            font-size: 1.5em;
            line-height: 1.6;
        }
        .tagline, .header, .text, .version {
            opacity: 0;
            color:#000;
        }
        .header, #fade-in-header {
            transition: all 5s ease-in;            
        }
        .text, #fade-in-text {
            transition: all 2s ease-in;
            
        }
        .version, #fade-in-version {
            transition: all 8s ease-in;
        }

    </style>
</head>
<body>
    <div class="container">
        <h1 class="header" id="fade-in-header">Welcome to ARE</h1>
        <p><span class="text" id="fade-in-text">The investment we made in ourselves,</span> <span class="version" id="fade-in-version"> ARE version {{ app()->version() }}, {{ now("America/Denver")->format('Y-m-d H:i:s') }}</span></p>
    </div>
    <script>
        document.addEventListener("DOMContentLoaded", function() {
            let sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
            sleep(3000).then(() => document.getElementById("fade-in-header").style.color = "#CCC").then(() => document.getElementById("fade-in-header").style.opacity = 1);
            sleep(5000).then(() => document.getElementById("fade-in-text").style.color = "#CCC").then(() => document.getElementById("fade-in-text").style.opacity = 1);
            sleep(8000).then(() => document.getElementById("fade-in-version").style.color = "#CCC").then(() => document.getElementById("fade-in-version").style.opacity = 1);
            // document.getElementById("fade-in-text").style.opacity = 1;
            // document.getElementById("fade-in-header").style.opacity = 1;
            // document.getElementById("fade-in-version").style.opacity = 1;
        });
    </script>
</body>
</html>