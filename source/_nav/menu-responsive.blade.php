<nav id="js-nav-menu" class="nav-menu hidden lg:hidden">
    <ul class="my-0">
        <li class="pl-4">
            <a
                title="{{ $page->siteName }} Blog"
                href="/blog"
                class="nav-menu__item hover:text-teal-500 {{ $page->isActive('/blog') ? 'active text-teal' : '' }}"
            >Blog</a>
        </li>
        <li class="pl-4">
            <a
                title="{{ $page->siteName }} About"
                href="/about"
                class="nav-menu__item hover:text-teal-500 {{ $page->isActive('/about') ? 'active text-teal' : '' }}"
            >About</a>
        </li>
        <li class="pl-4">
            <a
                title="{{ $page->siteName }} Contact"
                href="/contact"
                class="nav-menu__item hover:text-teal-500 {{ $page->isActive('/contact') ? 'active text-teal' : '' }}"
            >Contact</a>
        </li>
    </ul>
</nav>
