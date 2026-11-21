<?php
declare(strict_types=1);
const COMPONENT_WIRE_GND = 'GND -> GND';
const COMPONENT_WIRE_VCC_5V = 'VCC -> 5V';
if (!function_exists('fitodomikComponents')) {
    function fitodomikComponents(): array
    {
        return [
            'usb-camera' => [
                'slug' => 'usb-camera',
                'title' => 'USB веб-камера',
                'short' => 'Камера фиксирует состояние растений и передаёт кадры на Raspberry Pi для визуального контроля и анализа.',
                'usage' => 'В ФитоДомике камера нужна для регулярных фото, наблюдения за ростом растений и подготовки кадров для OpenCV/HSV-анализа.',
                'responsibility' => 'Отвечает за визуальный слой системы: снимки, наблюдение, контроль изменений и будущие таймлапсы.',
                'connection' => 'Подключается к USB-порту Raspberry Pi 3B. Питание и передача данных идут по одному USB-кабелю.',
                'pins' => ['USB -> Raspberry Pi 3B'],
                'role' => 'Помогает владельцу видеть состояние мини-фермы удалённо и сравнивать фото с показаниями датчиков.',
                'model' => '/3d/logitech_webcam.glb',
                'seo_title' => 'USB веб-камера ФитоДомика — фото растений и 3D-модель',
                'seo_description' => 'USB веб-камера в ФитоДомике: подключение к Raspberry Pi 3B, назначение, роль в OpenCV/HSV-анализе растений и 3D-модель.',
                'keywords' => 'ФитоДомик, USB камера, веб-камера Raspberry Pi, камера для растений, OpenCV анализ растений, 3D модель камеры',
                'hotspot' => ['left' => 12.2, 'top' => 6.0, 'width' => 21.0, 'height' => 18.0],
            ],
            'raspberry-pi-3b' => [
                'slug' => 'raspberry-pi-3b',
                'title' => 'Raspberry Pi 3B',
                'short' => 'Одноплатный компьютер, который запускает desktop-приложение, камеру, локальные очереди и обмен с API.',
                'usage' => 'В ФитоДомике Raspberry Pi 3B выступает главным вычислительным узлом: принимает данные Arduino, работает с камерой и отправляет телеметрию на сервер.',
                'responsibility' => 'Отвечает за связь с интернетом, локальную обработку данных, фото, очереди, настройки и обмен с сайтом и Telegram.',
                'connection' => 'Arduino Nano и USB-камера подключаются к Raspberry Pi по USB. Интернет — через Wi-Fi или Ethernet.',
                'pins' => ['USB -> Arduino Nano', 'USB -> USB веб-камера', 'Wi-Fi/Ethernet -> API ФитоДомика'],
                'role' => 'Связывает физическое устройство, веб-кабинет и Telegram-уведомления в одну систему.',
                'model' => '/3d/raspberry_pi_3.glb',
                'seo_title' => 'Raspberry Pi 3B в ФитоДомике — управление IoT-теплицей',
                'seo_description' => 'Raspberry Pi 3B в ФитоДомике: роль в умной теплице, подключение Arduino Nano и USB-камеры, API, Telegram и 3D-модель.',
                'keywords' => 'ФитоДомик, Raspberry Pi 3B, Raspberry Pi теплица, IoT теплица, умная мини-ферма, 3D модель Raspberry Pi',
                'hotspot' => ['left' => 42.0, 'top' => 2.5, 'width' => 13.5, 'height' => 35.0],
            ],
            'arduino-nano' => [
                'slug' => 'arduino-nano',
                'title' => 'Arduino Nano',
                'short' => 'Микроконтроллер, который считывает датчики и управляет реле по командам от Raspberry Pi.',
                'usage' => 'В ФитоДомике Arduino Nano отвечает за BME280, MH-Z19B, YL-69 и релейный модуль.',
                'responsibility' => 'Собирает показания датчиков, передаёт данные по USB Serial и включает исполнительные устройства через реле.',
                'connection' => 'Связь с Raspberry Pi идёт через USB Serial. Датчики и реле подключаются к аналоговым, цифровым и I2C-пинам Arduino.',
                'pins' => ['BME280: I2C SDA/SCL', 'MH-Z19B: D2/D3', 'YL-69: A6', 'Реле: D4-D7'],
                'role' => 'Низкоуровневый контроллер мини-фермы: быстро реагирует на датчики и команды управления.',
                'model' => '/3d/arduino_nano_v3_mini_usb_download_free.glb',
                'seo_title' => 'Arduino Nano в ФитоДомике — датчики, реле и 3D-модель',
                'seo_description' => 'Arduino Nano в ФитоДомике: подключение BME280, MH-Z19B, YL-69 и реле, обмен с Raspberry Pi через USB Serial и 3D-модель.',
                'keywords' => 'ФитоДомик, Arduino Nano, Arduino теплица, реле Arduino, датчики Arduino, 3D модель Arduino',
                'hotspot' => ['left' => 43.4, 'top' => 43.5, 'width' => 11.2, 'height' => 39.0],
            ],
            'bme280' => [
                'slug' => 'bme280',
                'title' => 'BME280',
                'short' => 'Датчик температуры, влажности воздуха и атмосферного давления.',
                'usage' => 'В ФитоДомике BME280 измеряет микроклимат внутри мини-фермы и помогает принимать решения по вентиляции и освещению.',
                'responsibility' => 'Отвечает за базовые климатические показатели: температуру, влажность воздуха и давление.',
                'connection' => 'Подключается к Arduino Nano по I2C. Для большинства модулей используются линии SDA/SCL и питание 3.3V или 5V по спецификации платы.',
                'pins' => ['SDA -> A4', 'SCL -> A5', 'VCC -> 3.3V/5V по модулю', COMPONENT_WIRE_GND],
                'role' => 'Даёт системе понимание климата, чтобы владелец видел условия выращивания в веб-кабинете и Telegram.',
                'model' => '/3d/bme280_from_adafruit_cansat_kit_component.glb',
                'seo_title' => 'BME280 в ФитоДомике — датчик температуры и влажности',
                'seo_description' => 'BME280 в ФитоДомике: температура, влажность воздуха, давление, подключение по I2C к Arduino Nano и 3D-модель датчика.',
                'keywords' => 'ФитоДомик, BME280, датчик температуры и влажности, датчики для теплицы, Arduino I2C, 3D модель датчика',
                'hotspot' => ['left' => 14.0, 'top' => 35.5, 'width' => 13.5, 'height' => 26.0],
            ],
            'yl-69' => [
                'slug' => 'yl-69',
                'title' => 'YL-69 / датчик влажности почвы',
                'short' => 'Датчик помогает оценивать влажность почвы и вовремя запускать автополив.',
                'usage' => 'В ФитоДомике YL-69 используется для контроля влажности субстрата и защиты растения от пересыхания.',
                'responsibility' => 'Отвечает за почвенную влажность: передаёт аналоговое значение на Arduino Nano.',
                'connection' => 'Зонд подключается к плате датчика, аналоговый выход платы идёт на Arduino Nano.',
                'pins' => ['AO -> A6', COMPONENT_WIRE_VCC_5V, COMPONENT_WIRE_GND],
                'role' => 'Помогает автоматизировать полив и сравнивать состояние почвы с температурой и влажностью воздуха.',
                'model' => '/3d/YL-69.glb',
                'seo_title' => 'YL-69 в ФитоДомике — датчик влажности почвы',
                'seo_description' => 'YL-69 в ФитоДомике: датчик влажности почвы для автополива, подключение к Arduino Nano, аналоговый сигнал A6 и 3D-модель.',
                'keywords' => 'ФитоДомик, YL-69, датчик влажности почвы, автополив Arduino, умная теплица, 3D модель датчика',
                'hotspot' => ['left' => 10.6, 'top' => 66.0, 'width' => 17.0, 'height' => 23.0],
            ],
            'mh-z19b' => [
                'slug' => 'mh-z19b',
                'title' => 'MH-Z19B / датчик CO2',
                'short' => 'Инфракрасный датчик CO2 для контроля качества воздуха внутри мини-фермы.',
                'usage' => 'В ФитоДомике MH-Z19B измеряет концентрацию CO2 и помогает понимать, когда нужна вентиляция.',
                'responsibility' => 'Отвечает за показатель CO2, который важен для микроклимата и роста растений.',
                'connection' => 'Подключается к Arduino Nano по UART/SoftwareSerial.',
                'pins' => ['TX/RX -> D2/D3', COMPONENT_WIRE_VCC_5V, COMPONENT_WIRE_GND],
                'role' => 'Дополняет климатические данные и помогает системе оценивать воздух внутри закрытого объёма.',
                'model' => '/3d/MH-Z19B.glb',
                'seo_title' => 'MH-Z19B в ФитоДомике — датчик CO2 и 3D-модель',
                'seo_description' => 'MH-Z19B в ФитоДомике: датчик CO2 для умной теплицы, подключение к Arduino Nano по UART, роль в вентиляции и 3D-модель.',
                'keywords' => 'ФитоДомик, MH-Z19B, датчик CO2, CO2 теплица, Arduino UART, 3D модель датчика',
                'hotspot' => ['left' => 70.0, 'top' => 64.0, 'width' => 22.0, 'height' => 22.0],
            ],
            'relay-module' => [
                'slug' => 'relay-module',
                'title' => '1-канальный модуль реле',
                'short' => 'Реле включает и выключает исполнительные устройства по сигналу микроконтроллера.',
                'usage' => 'В ФитоДомике релейные модули управляют нагрузками: вентилятором, насосом, лампой и другими исполнительными устройствами.',
                'responsibility' => 'Отвечает за безопасное разделение логики Arduino и силовой части устройств.',
                'connection' => 'Сигнальный вход подключается к цифровому пину Arduino, питание берётся по требованиям конкретного модуля.',
                'pins' => ['IN/D2 -> цифровой пин Arduino', COMPONENT_WIRE_VCC_5V, COMPONENT_WIRE_GND, 'NO/COM/NC -> нагрузка'],
                'role' => 'Позволяет системе не только измерять параметры, но и физически управлять мини-фермой.',
                'model' => '/3d/relay_shield__wemos_d1_mini.glb',
                'seo_title' => 'Релейный модуль ФитоДомика — управление насосом и лампой',
                'seo_description' => '1-канальный модуль реле в ФитоДомике: управление нагрузками, подключение к Arduino Nano, роль в автополиве и освещении, 3D-модель.',
                'keywords' => 'ФитоДомик, реле Arduino, модуль реле, управление насосом Arduino, умная теплица, 3D модель реле',
                'hotspot' => ['left' => 72.5, 'top' => 16.5, 'width' => 20.0, 'height' => 24.0],
            ],
        ];
    }
}
if (!function_exists('fitodomikComponent')) {
    function fitodomikComponent(string $slug): ?array
    {
        $components = fitodomikComponents();
        return $components[$slug] ?? null;
    }
}
