# Паттерны PHPUnit

Используй этот референс, если в проекте PHPUnit (без Pest). Совмести с версией PHPUnit, которой пользуется проект — атрибуты (10+) vs аннотации (≤9) — частая причина рассинхрона.

## Скелет файла / класса

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\PaymentService;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;

final class PaymentServiceTest extends TestCase
{
    private PaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaymentService(/* deps */);
    }

    #[Test]
    public function it_charges_the_card_and_returns_a_receipt(): void
    {
        // Arrange
        $card = new Card('4111...', 12, 2030);

        // Act
        $receipt = $this->service->charge($card, 100);

        // Assert
        self::assertSame(100, $receipt->amount);
        self::assertNotEmpty($receipt->id);
    }
}
```

Заметки:
- `declare(strict_types=1);` — только если используется в проекте; смотри существующие тесты.
- `final class` — тоже проектная конвенция; многие кодовые базы предпочитают, но не все.
- Атрибут `#[Test]` (PHPUnit 10+) заменяет аннотацию `/** @test */`. Смешивать оба в одном файле — запах.
- `self::assert*` vs `$this->assert*` — оба работают; выбирай тот, что в проекте.

## Naming

Две распространённые конвенции — повторяй проектную:

- `test_charges_the_card(): void` — snake_case с префиксом `test_`
- `it_charges_the_card(): void` с атрибутом `#[Test]` — Pest-подобный стиль

Оба дают читаемые failure messages. Избегай `testCharge1`, `testCharge2` и подобного.

## Data providers

Для параметризованных кейсов. Атрибут — на PHPUnit 10+, аннотация — на старых.

```php
#[Test]
#[DataProvider('invalidEmailProvider')]
public function it_rejects_invalid_emails(string $email): void
{
    self::assertFalse($this->validator->isValid($email));
}

public static function invalidEmailProvider(): array
{
    return [
        'missing @'        => ['plainstring'],
        'missing domain'   => ['user@'],
        'missing user'     => ['@example.com'],
        'whitespace'       => ['user @example.com'],
    ];
}
```

Главное преимущество: каждая строка получает свою строку результата в CI с ключом массива в качестве лейбла — *гораздо* лучше, чем цикл внутри одного теста.

## Шпаргалка по assertion'ам

| Что проверить | Как |
|---|---|
| Точное равенство (с типом) | `assertSame($expected, $actual)` |
| Нестрогое равенство | `assertEquals(...)` — предпочитать Same, где возможно |
| Та же инстанция объекта | `assertSame($obj1, $obj2)` |
| Объекты равны по значению | `assertEquals(...)` (через `==`, проходит по свойствам) |
| Массив содержит | `assertContains($needle, $haystack)` |
| Массив имеет ключ | `assertArrayHasKey('id', $row)` |
| Брошено исключение | `$this->expectException(Foo::class)` *перед* act-шагом |
| Конкретное сообщение исключения | `$this->expectExceptionMessage('substring')` |
| Конкретный count | `assertCount(3, $items)` (лучше, чем `assertSame(3, count(...))`) |
| Числовой диапазон | `assertGreaterThan(0, $x)` и т.д. — не оборачивай в кастомный assertion-код |
| Сравнение float | `assertEqualsWithDelta($expected, $actual, 0.001)` |

## Тестирование исключений

```php
#[Test]
public function it_throws_when_amount_is_negative(): void
{
    $this->expectException(InvalidAmountException::class);
    $this->expectExceptionMessage('must be positive');

    $this->service->charge($card, -100);  // Act ПОСЛЕ expects
}
```

Порядок важен: `expectException` вызывается **до** act-шага. Если поставить после — тест проходит независимо от поведения.

Для тестов, где нужно одновременно утверждать *и* брошенное исключение, *и* состояние после (например, "транзакция откатилась"), — используй try/catch:

```php
try {
    $this->service->charge($card, -100);
    self::fail('Expected InvalidAmountException');
} catch (InvalidAmountException) {
    // ожидаемо
}

self::assertSame(0, Transaction::count());
```

## Моки PHPUnit

Встроенные моки для простых случаев — Mockery не нужен:

```php
$repo = $this->createMock(UserRepository::class);
$repo->expects(self::once())
    ->method('findById')
    ->with(42)
    ->willReturn(new User(42, 'Alice'));

$service = new ProfileService($repo);
$profile = $service->getProfile(42);

self::assertSame('Alice', $profile->name);
```

Для гибких expectations или partial-моков — используй Mockery (см. `mocking.md`).

## Таксономия test doubles

- `createMock(X::class)` — полный мок; все методы по умолчанию возвращают `null`, если не застаблены.
- `createStub(X::class)` — stub; то же что мок, но не верифицирует expectations.
- `createPartialMock(X::class, ['methodA'])` — конкретный класс с заглушёнными перечисленными методами.
- `getMockBuilder(X::class)->disableOriginalConstructor()->getMock()` — когда конструктор требует аргументы, которые не хочется предоставлять.

Избегай `getMockBuilder`, если не нужны его специфичные опции — `createMock` это современный путь.

## Базовые опции phpunit.xml

Если нужно понять что сконфигурено:

```xml
<phpunit colors="true" bootstrap="vendor/autoload.php">
  <testsuites>
    <testsuite name="Unit">
      <directory>tests/Unit</directory>
    </testsuite>
    <testsuite name="Feature">
      <directory>tests/Feature</directory>
    </testsuite>
  </testsuites>
  <source>
    <include>
      <directory>app</directory>
    </include>
  </source>
</phpunit>
```

Запустить один suite: `vendor/bin/phpunit --testsuite=Unit`.
Запустить один файл: `vendor/bin/phpunit tests/Unit/PaymentServiceTest.php`.
Запустить один метод: `vendor/bin/phpunit --filter=it_charges_the_card`.
