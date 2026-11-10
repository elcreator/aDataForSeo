<?php

/**
 * Real-estate vocabulary, per language.
 *
 * The only place the plugin knows what a "flat" or "rent" is: the classifier
 * reads stems from here, the target builder reads slugs and titles. Stems are
 * matched against the start of a diacritics-free, lower-cased token, so
 * "pronajem" matches "pronájem", "pronájmu" needs its own stem "pronajm".
 *
 * Another vertical is another file with the same keys.
 */
return [
    'deals' => ['rent', 'sale'],
    'types' => ['flat', 'house', 'land', 'room', 'garage', 'commercial'],

    // A keyword with no deal word is attached to the deal most people mean for that type.
    'ambiguous_default' => [
        'flat' => 'rent', 'room' => 'rent', 'garage' => 'rent', 'any' => 'rent',
        'house' => 'sale', 'land' => 'sale', 'commercial' => 'rent',
    ],

    'languages' => [
        'en' => [
            // Nominal forms combined with place names into research seeds.
            'seed' => ['flat' => 'apartments', 'house' => 'houses'],
            'deal' => [
                'rent' => ['rent', 'rental', 'lease', 'to let', 'let'],
                'sale' => ['sale', 'buy', 'buying', 'purchase', 'for sale'],
            ],
            'type' => [
                'flat' => ['apartment', 'flat', 'studio', 'condo'],
                'house' => ['house', 'villa', 'home', 'cottage'],
                'land' => ['land', 'plot', 'lot'],
                'room' => ['room', 'shared'],
                'garage' => ['garage', 'parking'],
                'commercial' => ['office', 'commercial', 'retail', 'warehouse'],
            ],
            'stop' => ['in', 'for', 'the', 'a', 'an', 'of', 'to', 'near', 'at', 'on', 'with', 'and'],
            'slug' => [
                'deal' => ['rent' => 'rent', 'sale' => 'sale'],
                'type' => ['flat' => 'apartments', 'house' => 'houses', 'land' => 'land', 'room' => 'rooms', 'garage' => 'garages', 'commercial' => 'commercial', 'any' => 'property'],
                'pattern' => '{lang}/{type}-for-{deal}/{geo}',
            ],
            'words' => [
                'deal' => ['rent' => 'rent', 'sale' => 'sale'],
                'type' => ['flat' => 'apartments', 'house' => 'houses', 'land' => 'land', 'room' => 'rooms', 'garage' => 'garages', 'commercial' => 'commercial property', 'any' => 'property'],
                'title' => '{Type} for {deal} in {Geo}',
                'description' => '{Type} for {deal} in {Geo}: current offers with prices, photos and maps. Updated daily.',
            ],
        ],
        'cs' => [
            // Nominal forms combined with place names into research seeds.
            'seed' => ['flat' => 'byty', 'house' => 'domy'],
            'deal' => [
                'rent' => ['pronajem', 'pronajm', 'najem', 'podnajem', 'k pronajmu'],
                'sale' => ['prodej', 'koupe', 'koupit', 'na prodej'],
            ],
            'type' => [
                'flat' => ['byt', 'byty', 'bytu', 'garsonk', 'apartman'],
                'house' => ['dum', 'domy', 'domu', 'rodinny', 'vila', 'chalup', 'chat'],
                'land' => ['pozem', 'parcel'],
                'room' => ['pokoj', 'spolubydl'],
                'garage' => ['garaz', 'parkovaci'],
                'commercial' => ['kancelar', 'komercni', 'nebytov', 'obchodni'],
            ],
            'stop' => ['v', 've', 'na', 'do', 'k', 'pro', 'a', 'z', 'u'],
            'slug' => [
                'deal' => ['rent' => 'pronajem', 'sale' => 'prodej'],
                'type' => ['flat' => 'byty', 'house' => 'domy', 'land' => 'pozemky', 'room' => 'pokoje', 'garage' => 'garaze', 'commercial' => 'komercni', 'any' => 'nemovitosti'],
                'pattern' => '{lang}/{deal}-{type}/{geo}',
            ],
            'words' => [
                'deal' => ['rent' => 'pronájem', 'sale' => 'prodej'],
                'type' => ['flat' => 'bytů', 'house' => 'domů', 'land' => 'pozemků', 'room' => 'pokojů', 'garage' => 'garáží', 'commercial' => 'komerčních prostor', 'any' => 'nemovitostí'],
                'title' => '{Deal} {type} {Geo}',
                'description' => '{Deal} {type} {Geo}: aktuální nabídky s cenami, fotografiemi a mapou.',
            ],
        ],
        'pl' => [
            // Nominal forms combined with place names into research seeds.
            'seed' => ['flat' => 'mieszkania', 'house' => 'domy'],
            'deal' => [
                'rent' => ['wynajem', 'wynajm', 'najem', 'do wynajecia', 'wynajac'],
                'sale' => ['sprzedaz', 'kupi', 'kupno', 'na sprzedaz'],
            ],
            'type' => [
                'flat' => ['mieszkan', 'kawalerk', 'apartament'],
                'house' => ['dom', 'domy', 'domow', 'willa'],
                'land' => ['dzialk', 'grunt'],
                'room' => ['pokoj', 'pokoi'],
                'garage' => ['garaz', 'miejsce parkingowe'],
                'commercial' => ['biuro', 'biur', 'lokal', 'uzytkow'],
            ],
            'stop' => ['w', 'we', 'na', 'do', 'dla', 'i', 'z', 'od'],
            'slug' => [
                'deal' => ['rent' => 'wynajem', 'sale' => 'sprzedaz'],
                'type' => ['flat' => 'mieszkania', 'house' => 'domy', 'land' => 'dzialki', 'room' => 'pokoje', 'garage' => 'garaze', 'commercial' => 'lokale', 'any' => 'nieruchomosci'],
                'pattern' => '{lang}/{type}-{deal}/{geo}',
            ],
            'words' => [
                'deal' => ['rent' => 'na wynajem', 'sale' => 'na sprzedaż'],
                'type' => ['flat' => 'Mieszkania', 'house' => 'Domy', 'land' => 'Działki', 'room' => 'Pokoje', 'garage' => 'Garaże', 'commercial' => 'Lokale użytkowe', 'any' => 'Nieruchomości'],
                'title' => '{type} {deal} {Geo}',
                'description' => '{type} {deal} {Geo}: aktualne oferty z cenami, zdjęciami i mapą.',
            ],
        ],
        'sk' => [
            // Nominal forms combined with place names into research seeds.
            'seed' => ['flat' => 'byty', 'house' => 'domy'],
            'deal' => [
                'rent' => ['prenajom', 'prenajm', 'najom'],
                'sale' => ['predaj', 'kupa', 'kupit'],
            ],
            'type' => [
                'flat' => ['byt', 'byty', 'garsonk', 'apartman'],
                'house' => ['dom', 'domy', 'rodinny', 'vila', 'chat'],
                'land' => ['pozem', 'parcel'],
                'room' => ['izb'],
                'garage' => ['garaz'],
                'commercial' => ['kancelar', 'komercn', 'nebytov'],
            ],
            'stop' => ['v', 'vo', 'na', 'do', 'k', 'pre', 'a', 'z'],
            'slug' => [
                'deal' => ['rent' => 'prenajom', 'sale' => 'predaj'],
                'type' => ['flat' => 'byty', 'house' => 'domy', 'land' => 'pozemky', 'room' => 'izby', 'garage' => 'garaze', 'commercial' => 'komercne', 'any' => 'nehnutelnosti'],
                'pattern' => '{lang}/{deal}-{type}/{geo}',
            ],
            'words' => [
                'deal' => ['rent' => 'prenájom', 'sale' => 'predaj'],
                'type' => ['flat' => 'bytov', 'house' => 'domov', 'land' => 'pozemkov', 'room' => 'izieb', 'garage' => 'garáží', 'commercial' => 'komerčných priestorov', 'any' => 'nehnuteľností'],
                'title' => '{Deal} {type} {Geo}',
                'description' => '{Deal} {type} {Geo}: aktuálne ponuky s cenami, fotografiami a mapou.',
            ],
        ],
        'uk' => [
            // Nominal forms combined with place names into research seeds.
            'seed' => ['flat' => 'квартири', 'house' => 'будинки'],
            'deal' => [
                'rent' => ['оренд', 'зняти', 'винайм', 'здати', 'подобов'],
                'sale' => ['купити', 'купів', 'продаж', 'придбати'],
            ],
            'type' => [
                'flat' => ['квартир', 'апартамент', 'студі'],
                'house' => ['будин', 'котедж', 'вілл', 'хат'],
                'land' => ['ділянк', 'земл'],
                'room' => ['кімнат'],
                'garage' => ['гараж', 'паркомісц'],
                'commercial' => ['офіс', 'комерційн', 'приміщен'],
            ],
            'stop' => ['в', 'у', 'на', 'до', 'для', 'і', 'та', 'з'],
            'slug' => [
                'deal' => ['rent' => 'orenda', 'sale' => 'prodazh'],
                'type' => ['flat' => 'kvartyry', 'house' => 'budynky', 'land' => 'dilyanky', 'room' => 'kimnaty', 'garage' => 'garazhi', 'commercial' => 'komertsiya', 'any' => 'nerukhomist'],
                'pattern' => '{lang}/{deal}-{type}/{geo}',
            ],
            'words' => [
                'deal' => ['rent' => 'Оренда', 'sale' => 'Продаж'],
                'type' => ['flat' => 'квартир', 'house' => 'будинків', 'land' => 'ділянок', 'room' => 'кімнат', 'garage' => 'гаражів', 'commercial' => 'комерційних приміщень', 'any' => 'нерухомості'],
                'title' => '{Deal} {type} {Geo}',
                'description' => '{Deal} {type} {Geo}: актуальні пропозиції з цінами, фото та картою.',
            ],
        ],
        'ru' => [
            // Nominal forms combined with place names into research seeds.
            'seed' => ['flat' => 'квартиры', 'house' => 'дома'],
            'deal' => [
                'rent' => ['аренд', 'снять', 'сдать', 'съем', 'посуточ'],
                'sale' => ['купить', 'покупк', 'продаж', 'приобрести'],
            ],
            'type' => [
                'flat' => ['квартир', 'апартамент', 'студи'],
                'house' => ['дом', 'коттедж', 'вилл'],
                'land' => ['участ', 'земл'],
                'room' => ['комнат'],
                'garage' => ['гараж', 'парковк'],
                'commercial' => ['офис', 'коммерческ', 'помещен'],
            ],
            'stop' => ['в', 'во', 'на', 'до', 'для', 'и', 'с', 'из'],
            'slug' => [
                'deal' => ['rent' => 'arenda', 'sale' => 'prodazha'],
                'type' => ['flat' => 'kvartiry', 'house' => 'doma', 'land' => 'uchastki', 'room' => 'komnaty', 'garage' => 'garazhi', 'commercial' => 'kommercheskaya', 'any' => 'nedvizhimost'],
                'pattern' => '{lang}/{deal}-{type}/{geo}',
            ],
            'words' => [
                'deal' => ['rent' => 'Аренда', 'sale' => 'Продажа'],
                'type' => ['flat' => 'квартир', 'house' => 'домов', 'land' => 'участков', 'room' => 'комнат', 'garage' => 'гаражей', 'commercial' => 'коммерческих помещений', 'any' => 'недвижимости'],
                'title' => '{Deal} {type} {Geo}',
                'description' => '{Deal} {type} {Geo}: актуальные предложения с ценами, фото и картой.',
            ],
        ],
        'de' => [
            // Nominal forms combined with place names into research seeds.
            'seed' => ['flat' => 'wohnungen', 'house' => 'häuser'],
            'deal' => [
                'rent' => ['miete', 'mieten', 'vermiet'],
                'sale' => ['kauf', 'kaufen', 'verkauf'],
            ],
            'type' => [
                'flat' => ['wohnung', 'apartment', 'appartement'],
                'house' => ['haus', 'hauser', 'villa', 'einfamilienhaus'],
                'land' => ['grundstuck', 'bauland'],
                'room' => ['zimmer', 'wg'],
                'garage' => ['garage', 'stellplatz'],
                'commercial' => ['buro', 'gewerbe', 'laden'],
            ],
            'stop' => ['in', 'zu', 'zur', 'zum', 'der', 'die', 'das', 'und', 'fur', 'von', 'am', 'im'],
            'slug' => [
                'deal' => ['rent' => 'mieten', 'sale' => 'kaufen'],
                'type' => ['flat' => 'wohnungen', 'house' => 'haeuser', 'land' => 'grundstuecke', 'room' => 'zimmer', 'garage' => 'garagen', 'commercial' => 'gewerbe', 'any' => 'immobilien'],
                'pattern' => '{lang}/{type}-{deal}/{geo}',
            ],
            'words' => [
                'deal' => ['rent' => 'mieten', 'sale' => 'kaufen'],
                'type' => ['flat' => 'Wohnungen', 'house' => 'Häuser', 'land' => 'Grundstücke', 'room' => 'Zimmer', 'garage' => 'Garagen', 'commercial' => 'Gewerbeflächen', 'any' => 'Immobilien'],
                'title' => '{type} {deal} in {Geo}',
                'description' => '{type} {deal} in {Geo}: aktuelle Angebote mit Preisen, Fotos und Karte.',
            ],
        ],
    ],
];
