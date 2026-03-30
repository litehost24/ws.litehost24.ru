<?php

namespace App\Lists;

class MainMenuList
{
    /**
     * Главное меню.
     * @var array|string[] name => url
     */
    public static array $unauthMenu = [
        'Главная' => 'home',
        'О компании' => 'about-company',
        'Контакты' => 'contacts',
        'Правовая информация' => 'documents',
    ];
}
