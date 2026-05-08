# Паттерны Pest

Используй этот референс, если в проекте Pest. Pest стоит поверх PHPUnit, поэтому большая часть знаний PHPUnit переносится — но синтаксис другой, и стиль существующих тестов проекта обычно важнее.

## Скелет файла

```php
<?php

declare(strict_types=1);

use App\Services\PaymentService;

beforeEach(function () {
    $this->service = new PaymentService(/* deps */);
});

it('charges the card and returns a receipt', function () {
    $card = new Card('4111...', 12, 2030);

    $receipt = $this->service->charge($card, 100);

    expect($receipt->amount)->toBe(100);
    expect($receipt->id)->not->toBeEmpty();
});

it('throws when amount is negative', function () {
    expect(fn () => $this->service->charge($card, -100))
        ->toThrow(InvalidAmountException::class, 'must be positive');
});
```

Без класса. Без обязательного namespace (Pest подхватывает его из `phpunit.xml` или `Pest.php`). `$this` биндится к TestCase, который Pest создаёт под капотом.

## Pest.php (конфиг проекта)

Обязательно открыть один раз во время разведки. Типичное содержимое:

```php
// tests/Pest.php
uses(Tests\TestCase::class)->in('Feature');
uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');
uses()->beforeEach(fn () => Carbon::setTestNow('2025-01-01'))->in('Feature');

expect()->extend('toBeOne', fn () => $this->toBe(1));
```

Что важно:
1. Какой TestCase / трейты применяются к каким папкам — это влияет на возможности `$this` (доступ к БД, HTTP-хелперам и т.п.).
2. Кастомные expectations, объявленные тут, — используй их в тестах, если они уже есть; повторяй стиль, если пишешь новые.

## Naming

Описательные предложения в стиле, выбранном проектом:

```php
it('rejects creation when name is empty', ...);
test('rejects creation when name is empty', ...);
```

`it` мысленно подставляет "it" — читается как "it rejects creation when name is empty". `test` — для описаний, не вписывающихся в "it ..." форму ("rejects creation when name is empty" работает в обоих; "POST /api/tasks creates a task" лучше с `test`).

Один стиль на проект.

## Expectations

API expectations у Pest — fluent, цепочный:

```php
expect($user)
    ->toBeInstanceOf(User::class)
    ->name->toBe('Alice')                    // обращение к свойству
    ->isAdmin()->toBeFalse();                // вызов метода

expect($items)
    ->toHaveCount(3)
    ->each->toBeInstanceOf(Item::class);

expect($response->json())
    ->toHaveKey('data')
    ->and(...)->toBe(...);
```

Часто используемые expectations:

| Expectation | Для чего |
|---|---|
| `toBe($x)` | Строгое равенство |
| `toEqual($x)` | Нестрогое равенство |
| `toBeNull()` / `toBeTrue()` / `toBeFalse()` | Конкретные значения |
| `toBeEmpty()` / `not->toBeEmpty()` | Проверка пустоты |
| `toHaveCount(n)` | Размер countable |
| `toContain($x)` | Содержит (массив / строка) |
| `toHaveKey('id')` | В массиве есть ключ |
| `toThrow(Exception::class)` / `toThrow(Exception::class, 'msg')` | Исключение |
| `toBeInstanceOf(X::class)` | Тип |
| `toMatch('/regex/')` | Регулярка |
| `toBeGreaterThan(n)` | Числовое сравнение |
| `toBeFloat()->toEqualWithDelta($f, 0.01)` | Сравнение float |

Инверсия: `not->`. Цепочка через `and()` для отдельных значений.

## Datasets (data providers в Pest)

```php
it('rejects invalid emails', function (string $email) {
    expect($this->validator->isValid($email))->toBeFalse();
})->with([
    'missing @'      => 'plainstring',
    'missing domain' => 'user@',
    'missing user'   => '@example.com',
    'whitespace'     => 'user @example.com',
]);
```

Для общих datasets — определять в `tests/Datasets/`:

```php
// tests/Datasets/Emails.php
dataset('invalid_emails', [
    'missing @'      => 'plainstring',
    'missing domain' => 'user@',
]);
```

Затем: `->with('invalid_emails')`.

## Higher-order tests

Pest умеет сцеплять expectations прямо после `it()`:

```php
it('returns 200', fn () => $this->get('/'))
    ->assertStatus(200);
```

Выглядит элегантно; сложнее дебажить при падении. Использовать редко — нормально для односложных smoke-тестов, избегать для всего, где более одного assertion'а.

## Hooks

```php
beforeAll(fn () => /* один раз перед всеми тестами в файле */);
beforeEach(fn () => /* перед каждым тестом */);
afterEach(fn () => /* после каждого теста */);
afterAll(fn () => /* один раз после всех тестов в файле */);
```

Переменные на `$this` в `beforeEach` видны в тестах:

```php
beforeEach(fn () => $this->user = User::factory()->create());

it('reads the user', fn () => expect($this->user)->not->toBeNull());
```

## Skip / focus

```php
it('does X', fn () => ...)->skip('reason');
it('does Y', fn () => ...)->skip(PHP_VERSION < 8.2, 'requires PHP 8.2');
it('does Z', fn () => ...)->only();   // только этот в файле — временно, никогда не коммитить
```

`->only()` в коммитимом коде — самострел: молча отключает остальные тесты. CI должен падать, если найдена `->only()` (некоторые команды добавляют pre-commit grep).

## Architecture-тесты

В Pest есть встроенный слой arch-тестирования:

```php
arch('controllers do not access DB directly')
    ->expect('App\Http\Controllers')
    ->not->toUse(['DB', 'Illuminate\Support\Facades\DB']);

arch('actions are final')
    ->expect('App\Actions')
    ->toBeFinal();
```

Полезно для энфорса конвенций проекта; этот скилл вряд ли будет писать их проактивно — но если в проекте уже есть, повторяй существующий паттерн при добавлении новых правил.

## Запуск

```bash
vendor/bin/pest                                       # все
vendor/bin/pest tests/Unit/Services                  # директория
vendor/bin/pest tests/Unit/Services/PaymentServiceTest.php
vendor/bin/pest --filter "charges the card"         # по имени
vendor/bin/pest --group=slow                         # по группе
vendor/bin/pest --parallel                           # если установлен pestphp/pest-plugin-parallel
vendor/bin/pest --coverage --min=80                  # gate по покрытию
```
