<x-layouts.app>
    @auth
        {{ Auth::user()->name }}
        {{ Auth::user()->twitch_subscription }}
    @endauth

    @guest
        <a href="{{ route('login') }}">Login</a>
        <a href="{{ route('register') }}">Register</a>
    @endguest
</x-layouts.app>
