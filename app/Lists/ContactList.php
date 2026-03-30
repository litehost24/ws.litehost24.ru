<?php

namespace App\Lists;

class ContactList
{
    public static array $info = [
        'phone' => '+7 (918) 474-33-83',
        'phone_href' => 'tel:+79184743383',
        'telegram' => '@nitromane',
        'telegram_href' => 'https://t.me/nitromane',
        'whatsapp_href' => 'https://wa.me/79184743383',
        'email' => '4743383@gmail.com',
        'email_href' => 'mailto:4743383@gmail.com',
        'inn' => '234202133527',
    ];

    public static function articleList(): array
    {
        $phone = self::$info['phone'];
        $telegram = self::$info['telegram'];
        $email = self::$info['email'];
        $inn = self::$info['inn'];

        return [
            [
                'title' => 'Контакты',
                'description' => "
                Профессиональная разработка сайтов под ключ, а так же продвижение, 
                модернизация, доработка, поддержка. <br /> <br />
                ФИО: Сусып Ирина Васильевна <br />
                ИНН: $inn <br />
                Телефон: $phone <br />
                Telegram: $telegram <br />
                WhatsApp: $phone <br />
                E-mail: $email
            ",
            ],
        ];
    }
}
