<?php

namespace App\Services;

class DomainBaseResolver
{
    /**
     * Best-effort base domain resolver. Prefer PSL-based resolver for accuracy.
     */
    public function resolve(string $domain): ?string
    {
        $domain = trim($domain, '.');
        if ($domain === '') {
            return null;
        }

        $labels = array_values(array_filter(explode('.', $domain), 'strlen'));
        $count = count($labels);
        if ($count <= 2) {
            return $domain;
        }

        $suffix = $labels[$count - 2] . '.' . $labels[$count - 1];
        if ($this->isKnownTwoLevelSuffix($suffix) && $count >= 3) {
            return $labels[$count - 3] . '.' . $suffix;
        }

        return $suffix;
    }

    private function isKnownTwoLevelSuffix(string $suffix): bool
    {
        static $known = [
            'co.uk',
            'org.uk',
            'gov.uk',
            'ac.uk',
            'co.jp',
            'ne.jp',
            'or.jp',
            'com.au',
            'net.au',
            'org.au',
            'edu.au',
            'com.br',
            'com.ru',
            'net.ru',
            'org.ru',
            'gov.ru',
            'edu.ru',
            'com.ua',
            'com.tr',
            'com.cn',
            'com.tw',
            'com.hk',
            'co.kr',
            'com.sg',
            'com.my',
            'com.ph',
            'com.sa',
            'com.eg',
            'com.mx',
            'com.ar',
            'com.pl',
            'com.es',
            'com.it',
            'com.de',
            'com.fr',
            'com.nl',
            'com.be',
            'com.ch',
            'com.se',
            'com.no',
            'com.fi',
            'com.cz',
            'com.sk',
            'com.ro',
            'com.hu',
            'com.bg',
            'com.lt',
            'com.lv',
            'com.ee',
            'com.vn',
            'com.th',
            'com.pk',
            'com.ng',
            'co.za',
        ];

        return in_array($suffix, $known, true);
    }
}
