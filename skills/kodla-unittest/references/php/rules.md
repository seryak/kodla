# PHP — best practices для unit-тестов

Референс для написания тестов в PHP-проектах: PHPUnit, Pest, Laravel-специфика, mocking, антипаттерны. Стиль существующих тестов проекта всегда важнее того, что написано здесь — используй этот файл как базу паттернов, а не как жёсткий шаблон.

## Оглавление

1. [Определение фреймворка](#определение-фреймворка)
2. [PHPUnit — скелет и паттерны](#phpunit--скелет-и-паттерны)
3. [Pest — скелет и паттерны](#pest--скелет-и-паттерны)
4. [Naming](#naming)
5. [Шпаргалка по assertion'ам](#шпаргалка-по-assertionам)
6. [Тестирование исключений](#тестирование-исключений)
7. [Data providers / Datasets](#data-providers--datasets)
8. [Mocking — стратегии](#mocking--стратегии)
9. [Laravel-специфика](#laravel-специфика)
10. [Антипаттерны](#антипаттерны)
11. [Запуск тестов](#запуск-тестов)

---

## Определение фреймворка

Проверь `composer.json`:
- `pestphp/pest` в require-dev → **Pest**
- `phpunit/phpunit` в require-dev (без Pest) → **PHPUnit**
- `laravel/framework` в require → Laravel-стек, читай также раздел [Laravel-специфика](#laravel-специфика)
- `symfony/framework-bundle` → Symfony, используй KernelTestCase/WebTestCase поверх PHPUnit-паттернов ниже

Прочитай `phpunit.xml`/`phpunit.xml.dist` (обе платформы) и `tests/Pest.php` (только Pest) — конфигурация testsuites, базовый TestCase, глобальные трейты.

Совмести с версией PHPUnit, которой пользуется проект — атрибуты (10+) vs аннотации (≤9) частая причина рассинхрона.

## PHPUnit — скелет и паттерны

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

### Таксономия test doubles (PHPUnit)

- `createMock(X::class)` — полный мок; все методы по умолчанию возвращают `null`, если не застаблены.
- `createStub(X::class)` — stub; то же что мок, но не верифицирует expectations.
- `createPartialMock(X::class, ['methodA'])` — конкретный класс с заглушёнными перечисленными методами.
- `getMockBuilder(X::class)->disableOriginalConstructor()->getMock()` — когда конструктор требует аргументы, которые не хочется предоставлять.

Избегай `getMockBuilder`, если не нужны его специфичные опции — `createMock` это современный путь.

### Базовые опции phpunit.xml

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

## Pest — скелет и паттерны

Pest стоит поверх PHPUnit, поэтому паттерны PHPUnit выше переносятся — но синтаксис другой.

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

### Pest.php (конфиг проекта)

Обязательно открыть один раз во время разведки. Типичное содержимое:

```php
// tests/Pest.php
uses(Tests\TestCase::class)->in('Feature');
uses(Tests\TestCase::class, RefreshDatabase::class)->in('Feature');
uses()->beforeEach(fn () => Carbon::setTestNow('2025-01-01'))->in('Feature');

expect()->extend('toBeOne', fn () => $this->toBe(1));
```

Что важно:
1. Какой TestCase / трейты применяются к каким папкам — влияет на возможности `$this` (доступ к БД, HTTP-хелперам и т.п.).
2. Кастомные expectations, объявленные тут, — используй, если уже есть; повторяй стиль, если пишешь новые.

### Expectations API (Pest)

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

Инверсия: `not->`. Цепочка через `and()` для отдельных значений.

### Higher-order tests

```php
it('returns 200', fn () => $this->get('/'))
    ->assertStatus(200);
```

Выглядит элегантно; сложнее дебажить при падении. Использовать редко — нормально для односложных smoke-тестов, избегать для всего, где более одного assertion'а.

### Hooks

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

### Skip / focus

```php
it('does X', fn () => ...)->skip('reason');
it('does Y', fn () => ...)->skip(PHP_VERSION < 8.2, 'requires PHP 8.2');
it('does Z', fn () => ...)->only();   // только этот в файле — временно, никогда не коммитить
```

`->only()` в коммитимом коде — самострел: молча отключает остальные тесты. CI должен падать, если найдена `->only()`.

### Architecture-тесты

```php
arch('controllers do not access DB directly')
    ->expect('App\Http\Controllers')
    ->not->toUse(['DB', 'Illuminate\Support\Facades\DB']);

arch('actions are final')
    ->expect('App\Actions')
    ->toBeFinal();
```

Полезно для энфорса конвенций проекта; писать проактивно не нужно — но если в проекте уже есть, повторяй существующий паттерн при добавлении новых правил.

## Naming

Две распространённые конвенции — повторяй проектную:

- `test_charges_the_card(): void` — snake_case с префиксом `test_` (PHPUnit)
- `it_charges_the_card(): void` с атрибутом `#[Test]` (PHPUnit) — Pest-подобный стиль
- `it('charges the card', ...)` / `test('charges the card', ...)` (Pest) — описательные предложения

`it` мысленно подставляет "it" — читается как "it rejects creation when name is empty". `test` — для описаний, не вписывающихся в "it ..." форму.

Один стиль на проект. Избегай `testCharge1`, `testCharge2` и подобного generic-именования.

## Шпаргалка по assertion'ам

| Что проверить | PHPUnit | Pest |
|---|---|---|
| Точное равенство (с типом) | `assertSame($expected, $actual)` | `expect($actual)->toBe($expected)` |
| Нестрогое равенство | `assertEquals(...)` | `toEqual(...)` |
| Та же инстанция объекта | `assertSame($obj1, $obj2)` | `toBe($obj1)` на `$obj2` |
| Массив содержит | `assertContains($needle, $haystack)` | `toContain($needle)` |
| Массив имеет ключ | `assertArrayHasKey('id', $row)` | `toHaveKey('id')` |
| Размер countable | `assertCount(3, $items)` | `toHaveCount(3)` |
| Пусто / не пусто | `assertEmpty(...)` | `toBeEmpty()` / `not->toBeEmpty()` |
| null / true / false | `assertNull`/`assertTrue`/`assertFalse` | `toBeNull()`/`toBeTrue()`/`toBeFalse()` |
| Числовой диапазон | `assertGreaterThan(0, $x)` | `toBeGreaterThan(0)` |
| Сравнение float | `assertEqualsWithDelta($e, $a, 0.001)` | `toEqualWithDelta($f, 0.01)` |
| Тип | `assertInstanceOf(X::class, $x)` | `toBeInstanceOf(X::class)` |
| Регулярка | `assertMatchesRegularExpression(...)` | `toMatch('/regex/')` |

Предпочитай `assertSame`/`toBe` (строгое равенство), где возможно — точнее ловит расхождение типов.

## Тестирование исключений

PHPUnit:

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

Для тестов, где нужно одновременно утверждать *и* брошенное исключение, *и* состояние после (например, "транзакция откатилась"):

```php
try {
    $this->service->charge($card, -100);
    self::fail('Expected InvalidAmountException');
} catch (InvalidAmountException) {
    // ожидаемо
}

self::assertSame(0, Transaction::count());
```

Pest:

```php
it('throws when amount is negative', function () {
    expect(fn () => $this->service->charge($card, -100))
        ->toThrow(InvalidAmountException::class, 'must be positive');
});
```

## Data providers / Datasets

PHPUnit — атрибут на 10+, аннотация на старых версиях:

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

Pest:

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

Для общих datasets в Pest — определять в `tests/Datasets/`:

```php
// tests/Datasets/Emails.php
dataset('invalid_emails', [
    'missing @'      => 'plainstring',
    'missing domain' => 'user@',
]);
```

Затем: `->with('invalid_emails')`.

Главное преимущество параметризации: каждая строка получает свою строку результата в CI с ключом массива в качестве лейбла — гораздо лучше, чем цикл внутри одного теста.

## Mocking — стратегии

Дефолт этого навыка: **мочить как можно меньше, предпочитать реальный код или in-memory fakes**.

### Иерархия (от дешёвого в поддержке к дорогому)

1. **Реальная реализация** — использовать настоящий класс. Бесплатно, если он чистый/дешёвый.
2. **In-memory fake** — небольшой рукописный класс, реализующий интерфейс, хранящий данные в массивах.
3. **Laravel facade fake** — `Mail::fake()`, `Bus::fake()` и т.д. (только Laravel, см. ниже).
4. **Stub через PHPUnit `createStub`** — фиксированные возвращаемые значения, без expectations.
5. **Mock через PHPUnit `createMock`** — возвращаемые значения + expectations на вызовы.
6. **Mockery mock** — гибкие expectations, но легко неправильно использовать.

Спускайся вниз только тогда, когда верхний вариант не подходит.

### Когда мочить

- Зависимость **медленная** (сеть, реальная БД-операция, файловый IO на больших файлах).
- Зависимость **недетерминированная** (random, time-based, third-party API).
- У зависимости есть **побочные эффекты**, которые ты не контролируешь (отправляет email, списывает с карты, дёргает внешний сервис).
- Зависимость **ещё не реализована** (есть интерфейс, нет конкретики).
- Триггер реального поведения требует громоздкого setup, который замусоривает тест.

### Когда НЕ мочить

- **Сам тестируемый класс.** Всегда реальный.
- **Value objects, DTO, enum'ы.** Всегда реальные — нет побочных эффектов, нет IO.
- **Eloquent-модели.** Используй реальную модель с `RefreshDatabase`, а не мок.
- **Внутренние коллабораторы, которые чистые.** Маппер/форматтер/валидатор без IO — реальный.
- **Фреймворк.** Не мочь `Request`, `Response`, контейнер и т.д. — используй HTTP-хелперы тестирования.

### In-memory fakes

Недооценённый средний путь. Для интерфейса `UserRepository`:

```php
final class FakeUserRepository implements UserRepository
{
    /** @var array<int, User> */
    private array $users = [];

    public function findById(int $id): ?User
    {
        return $this->users[$id] ?? null;
    }

    public function save(User $user): void
    {
        $this->users[$user->id] = $user;
    }
}
```

```php
$repo = new FakeUserRepository();
$repo->save(new User(1, 'Alice'));

$service = new ProfileService($repo);
expect($service->getProfile(1)->name)->toBe('Alice');
```

Плюсы: тесты читаются как production-код, нет шума оркестрации моков, рефакторинги реализации их не ломают. Минусы: нужно написать fake — окупается, если иначе пришлось бы мочить тот же интерфейс в 10+ тестах.

Клади fakes в `tests/Fakes/` — легко найти, не засоряет production namespace.

### Mockery

```php
use Mockery;

$repo = Mockery::mock(UserRepository::class);

// Стаббинг — вернуть значение, не требовать вызова
$repo->shouldReceive('findById')->andReturn(new User(1, 'Alice'));

// Mocking — требовать что вызов произошёл
$repo->shouldReceive('save')->once()->with(Mockery::on(fn ($u) => $u->id === 1));

// Разные значения по очереди
$repo->shouldReceive('findById')
    ->andReturn(null, new User(1, 'Alice'));   // первый вызов null, второй — пользователя
```

В Pest/PHPUnit Mockery авто-верифицируется в конце теста, если в базовом TestCase подмешан трейт `Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration`.

Подводные камни:
- **`andReturn` без count в `shouldReceive`** — вызов разрешён, но не обязателен. Тесты могут пройти без ни одного обращения к методу.
- **`shouldReceive` затеняет реальные методы на partial-моке** — `Mockery::mock(SomeClass::class . '[methodA]')` заглушает только `methodA`, остальные реальные.
- **Strict vs lenient режим** — `shouldReceive('foo')` без `shouldNotReceive('bar')` тихо разрешает вызывать `bar()`.
- **Type-hint моков** — `Mockery::mock(X::class)` возвращает `MockInterface`, не `X`; статический анализатор может ругаться при присваивании в типизированное свойство.

### Встроенные моки PHPUnit

```php
$repo = $this->createMock(UserRepository::class);
$repo->method('findById')->willReturn(new User(1, 'Alice'));   // stub, без энфорса
$repo->expects(self::once())                                    // энфорсим count
    ->method('save')
    ->with(self::isInstanceOf(User::class));
```

Менее выразительно, чем Mockery, но для большинства случаев хватает. Бери то, что уже в проекте — не вводи оба сразу.

### Spies

```php
$logger = Mockery::spy(Logger::class);
$service->doThing();
$logger->shouldHaveReceived('warning')->with('reason');
```

Stub-by-default — не падает на неожиданные вызовы. Используй, когда нужно утверждать на вызовы постфактум.

### Mock интерфейса vs конкретного класса vs final-класса

- Mock **интерфейса** — чисто, без сюрпризов.
- Mock **конкретного класса** — работает, но привязывает тесты к деталям реализации; лучше извлечь интерфейс.
- Mock **final-класса** — не работает с PHPUnit-моками (final блокирует наследование). Mockery поддерживает через runtime-хаки. Лучшее решение — извлечь интерфейс.

### Решающее дерево

```
Нужно подменить зависимость?
│
├── Это Laravel-фасад с fake() helper'ом? → Mail::fake() / Bus::fake() / etc.
│
├── Поведение простое и используется во многих тестах? → in-memory fake
│
├── Нужно энфорсить конкретные шейпы/counts вызовов? → Mockery (или PHPUnit createMock)
│
├── Нужно только возвращаемое значение, на вызовы плевать? → createStub или Mockery shouldReceive без count
│
└── По умолчанию: не мочь — используй настоящее.
```

### Красные флаги в mocking

- Строк setup'а у моков больше, чем тела теста.
- 3+ мока в одном тесте.
- Мочат класс, в котором нет IO и нет медленных операций.
- Цепочки `shouldReceive`, понять которые невозможно без перечитывания production-кода.
- Тест проходит, когда тело метода в проде заменили на `return null`.

Если что-то из этого всплыло — отступи и подумай: feature-тест с реальной БД, реальными сервисами не будет ли короче и честнее?

## Laravel-специфика

Применяется поверх PHPUnit или Pest — синтаксис в разделах выше, здесь — Laravel-фичи.

### Базовый TestCase и трейты

```php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
```

Самые употребляемые трейты (per-test или глобально через `Pest.php`/базовый `TestCase`):

- `RefreshDatabase` — оборачивает каждый тест в транзакцию, откатывает в конце. Дефолтный выбор для feature-тестов.
- `DatabaseTransactions` — то же по сути, мелкие отличия для параллельного запуска.
- `WithFaker` — даёт `$this->faker`.
- `LazilyRefreshDatabase` — как RefreshDatabase, но инициализируется только при первом обращении к БД.

Правило: если test class трогает БД — нужна очистка БД. Один трейт на проект — придерживаться его.

### Фабрики

```php
$user = User::factory()->create();                         // сохранён
$user = User::factory()->make();                           // не сохранён (только в памяти)
$users = User::factory()->count(5)->create();
$admin = User::factory()->admin()->create();               // factory state
$user = User::factory()->for($team)->create();             // belongsTo
$team = Team::factory()->has(User::factory()->count(3))->create();  // hasMany
```

В тестах предпочитай фабрики ручному `User::create([...])`. Для одноразовых форм используй прямые overrides:

```php
User::factory()->create(['email' => 'specific@example.com']);
```

### Fakes — суперсила Laravel-тестирования

```php
Mail::fake();
Bus::fake();
Queue::fake();
Event::fake();
Notification::fake();
Storage::fake('public');
Http::fake([
    'api.example.com/*' => Http::response(['status' => 'ok'], 200),
]);
```

Затем assertion'ы:

```php
Mail::assertSent(WelcomeMail::class);
Mail::assertSent(WelcomeMail::class, fn ($mail) => $mail->hasTo($user->email));
Mail::assertNothingSent();

Bus::assertDispatched(ProcessOrderJob::class);
Queue::assertPushed(SendInvoice::class, 1);

Event::assertDispatched(OrderPlaced::class);
Notification::assertSentTo($user, OrderShipped::class);

Storage::disk('public')->assertExists('uploads/file.png');
Http::assertSent(fn ($req) => $req->url() === 'https://api.example.com/charge');
```

Предпочитай fakes Mockery для фасадов — читается лучше, меньше false-позитивов.

### HTTP-тестирование

```php
$response = $this->postJson('/api/tasks', ['name' => 'Buy milk']);

$response->assertStatus(201);
$response->assertJson(['name' => 'Buy milk']);                        // частичное совпадение
$response->assertJsonPath('data.priority', 'medium');                 // dot-path
$response->assertJsonStructure(['id', 'name', 'created_at']);
$response->assertJsonValidationErrors(['name']);
$response->assertHeader('X-Total-Count', '1');
$response->assertSee('Welcome');                                       // для HTML
```

Аутентифицированные запросы:

```php
$this->actingAs($user)->getJson('/api/me')->assertOk();
$this->actingAs($user, 'api')->getJson('/api/me')->assertOk();        // конкретный guard
```

### Database-assertion'ы

```php
$this->assertDatabaseHas('tasks', ['name' => 'Buy milk', 'priority' => 'medium']);
$this->assertDatabaseMissing('tasks', ['name' => 'deleted']);
$this->assertDatabaseCount('tasks', 3);
$this->assertModelExists($task);
$this->assertModelMissing($task);
$this->assertSoftDeleted($task);
```

Предпочитай model-assertion'ы, когда модель уже в руках — они конкретнее.

### Time travel

```php
// Carbon
Carbon::setTestNow('2025-01-01 12:00:00');
Carbon::setTestNow();   // сброс

// Test helpers
$this->travelTo('2025-01-01 12:00:00');
$this->travel(1)->days();
$this->travelBack();

// Pest
beforeEach(fn () => $this->travelTo('2025-01-01'));
```

Всегда замораживай время, если поведение зависит от `now()`, `today()`, расчёта возраста, окон расписаний и т.д.

### Eloquent-нюансы

- **`refresh()` после записи** — если тест меняет модель через сырой запрос/прямое обращение к БД, вызови `$model->refresh()` или возьми свежую: `User::find($id)`.
- **Eager vs lazy loading** — `$user->posts->count()` утверждает, что *было загружено*; если нужны все posts — `$user->posts()->count()` (запрос) или `$user->loadCount('posts')`.
- **Детект N+1** — `Model::preventLazyLoading()` (в `AppServiceProvider::boot` для не-prod) делает lazy-loads бросающими исключение.

### Очереди/Jobs

Тестирование *постановки в очередь*:

```php
Queue::fake();
ProcessOrder::dispatch($order);
Queue::assertPushed(ProcessOrder::class, fn ($j) => $j->order->is($order));
```

Тестирование *обработки* (job реально делает что нужно) — инстанцируй и вызывай `handle()` напрямую с моками/fakes для зависимостей:

```php
$job = new ProcessOrder($order);
$job->handle($mockedDependency);
expect($order->fresh()->status)->toBe('processed');
```

Не пытайся тестировать оба аспекта одновременно.

### Когда писать Unit vs Feature

| Тестируемый код | Тип теста |
|---|---|
| Чистый value object / DTO / enum-логика | Unit |
| Доменный сервис без побочных эффектов | Unit (реальные deps, если дёшево) |
| Сервис, пишущий в БД | Feature (с `RefreshDatabase`) |
| Controller-action | Feature (HTTP + БД) |
| Eloquent scope / связь | Feature (БД) |
| Job, вызывающий внешний API | Unit с `Http::fake()` для http-вызовов, реальный handle() |
| Job, диспатчащий другой job | Feature с `Bus::fake()` |
| Шаблон уведомления/тело письма | Unit (инстанцировать, отрендерить, проверить контент) |
| Form request validation | Feature (POST и проверить ошибки валидации) |

Когда сомневаешься — feature-тест. Быстрее писать, реалистичнее, ловит интеграционные баги.

## Антипаттерны

Каталог наиболее частых ошибок в LLM-сгенерированных PHP-тестах. Прочитай перед написанием первого теста в новом для тебя проекте; перечитывай при ревью собственного вывода.

### 1. Mocking того, что тестируется

```php
// Плохо
$service = $this->createMock(PaymentService::class);
$service->method('charge')->willReturn(true);
$this->assertTrue($service->charge(100));   // тестирует мок, а не код
```

Если метод тестируется — он должен быть **реальной** реализацией. Мочить только его зависимости.

### 2. Тавтологические assertion'ы

```php
// Плохо — ничего не утверждает о поведении
$this->assertTrue(true);
$this->assertNotNull($result);              // когда null был невозможен в принципе
expect($user)->toBeInstanceOf(User::class); // когда конструктор и так возвращает User по type-hint

// Хорошо — утверждает поведение
expect($user->isAdmin())->toBeFalse();
expect($result->total)->toBe(150);
```

Мысленная проверка: убери одну значимую строку из проды — поймает ли тест?

### 3. Coverage-чейзинг

Написание теста, который только касается строки ради повышения покрытия. Запах: `expect(true)->toBeTrue()` в конце теста, который прогнал сложный метод без проверки результата. Строка, покрытая тестом, который не проверяет её эффект, не покрыта на практике.

### 4. Over-mocking

```php
// Плохо — мочит каждую зависимость, тестирует оркестрацию моков
$repo = $this->createMock(UserRepo::class);
$mailer = $this->createMock(Mailer::class);
$logger = $this->createMock(Logger::class);
$cache = $this->createMock(Cache::class);
// ... 30 строк shouldReceive() ...
```

Когда моков больше, чем реальных объектов 4:1, тест тестирует не систему, а оркестрацию моков. Варианты: реальные реализации где дёшево, подними unit в feature/integration, зарефактори прод-код (слишком много зависимостей).

### 5. Скрытое состояние БД / общее состояние

```php
// Плохо — "проходит" из-за остаточных данных от другого теста
public function test_user_exists(): void {
    expect(User::count())->toBeGreaterThan(0);
}
```

В Laravel: `RefreshDatabase`/`DatabaseTransactions`. В vanilla: чисти/сей в `setUp`/`beforeEach`. Тесты должны проходить в любом порядке, в изоляции.

### 6. Зависимость от времени и флакающее поведение

```php
// Плохо — упадёт в полночь
expect(Carbon::now()->format('Y-m-d'))->toBe(date('Y-m-d'));
```

Laravel: `Carbon::setTestNow(...)` или `$this->travelTo(...)`. Vanilla: внедри интерфейс `Clock` через DI.

### 7. Зависимые от порядка тесты

Первый признак: тест проходит в одиночку, но падает в составе suite (или наоборот). Каждый тест должен быть независимым — либо создаёт что ему нужно сам, либо `beforeEach` это делает.

### 8. Assertion'ы на внутренности

```php
// Плохо — связан с реализацией
expect($service->buildQuery())->toContain('WHERE status = "active"');

// Хорошо — утверждает наблюдаемый результат
expect($service->getActiveUsers())->toHaveCount(3);
```

Тестируй то, что видят вызывающие (возвращаемые значения, состояние БД, отправленные события, HTTP-ответы). Не тестируй приватные методы и промежуточные внутренности.

### 9. Раздутый setUp/beforeEach

Если `beforeEach` на 50 строк — класс теста, скорее всего, нужно разбивать. Допустимо выносить маленький общий setup; недопустимо прятать туда основную часть Arrange.

### 10. Snapshot-тесты не к месту

Полезны как regression-detection на стабильных структурах (рендер писем, генерация конфигов, фиксированные отчёты). Кошмар по поддержке для активно эволюционирующих структур (feature-ответы, форма которых легитимно меняется).

### 11. Generic-имена тестов

```php
// Плохо
public function test_creation(): void
it('works correctly', ...)

// Хорошо
public function test_creates_task_with_default_medium_priority(): void
it('rejects creation when name is empty', ...)
```

Failure messages в CI — единственный контекст, который будет у будущего "я".

### 12. Тестирование фреймворка

```php
// Плохо — тестирует, что Laravel работает, а не твой код
$user = User::create(['name' => 'X']);
expect(User::find($user->id))->not->toBeNull();
```

Тестируй свою доменную логику поверх фреймворка, не сам фреймворк.

### 13. Faker/seeding без детерминизма

```php
// Плохо — флакает, когда фейковый email случайно совпал с существующим
$user = User::factory()->create();
expect(User::where('email', $user->email)->count())->toBe(1);
```

Посей Faker или утверждай на связях/ID, которые ты контролируешь.

### 14. Скрытые assertion'ы в моках

```php
// Плохо — assertion спрятан внутри Mockery, легко пропустить при ревью
$mailer->shouldReceive('send')->once()->with(Mockery::on(fn($m) => $m->subject === 'Welcome'));
$service->register($user);
// в теле теста явного assertion нет
```

Предпочитай `Mail::fake()`/`Event::fake()`/in-memory fakes с явными assertion'ами в конце теста.

### 15. Пропуск unhappy path

Класс с пятью методами и только happy-path тестами покрыт на 40%, что бы ни говорил coverage tool. Каждый `if`/`match`/`throw` в проде — ветка, нуждающаяся в тесте (или явном обоснованном решении не тестировать её).

## Запуск тестов

```bash
# Pest
vendor/bin/pest                                       # все
vendor/bin/pest tests/Unit/Services                  # директория
vendor/bin/pest tests/Unit/Services/PaymentServiceTest.php
vendor/bin/pest --filter "charges the card"         # по имени
vendor/bin/pest --group=slow                         # по группе
vendor/bin/pest --parallel                           # если установлен pestphp/pest-plugin-parallel
vendor/bin/pest --coverage --min=80                  # gate по покрытию

# PHPUnit
vendor/bin/phpunit                                    # все
vendor/bin/phpunit --testsuite=Unit                   # один suite
vendor/bin/phpunit tests/Unit/PaymentServiceTest.php  # один файл
vendor/bin/phpunit --filter=it_charges_the_card       # один метод
```
