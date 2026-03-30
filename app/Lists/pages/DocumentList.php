<?php

namespace App\Lists\pages;

class DocumentList
{
    public static function articleList(): array
    {
        return [
            [
                'title' => 'Правовая информация и ценовая политика',
                'description' => "
                <a href='/storage/documents/Политика_конфиденциальности.txt' class='underline' download>
                  Политика конфиденциальности
                </a> <br />
                <a href='/storage/documents/Пользовательское_соглашение.txt' class='underline' download>
                  Пользовательское соглашение
                </a> <br />
                <a href='/storage/documents/Согласие_на_обработку_персональных_данных.txt' class='underline' download>
                  Согласие на обработку персональных данных
                </a> <br />
                <a href='/storage/documents/Ценовая__политика.docx' class='underline' download>
                  Ценовая политика
                </a>
            ",
            ],
        ];
    }
}