# Стратегии Mocking

Когда и как мочить — здесь больше всего ошибок в LLM-сгенерированных PHP-тестах. Дефолт этого скилла: **мочить как можно меньше, предпочитать реальный код или in-memory fakes**.

## Иерархия (от дешёвого в поддержке к дорогому)

1. **Реальная реализация** — использовать настоящий класс. Бесплатно, если он чистый / дешёвый.
2. **In-memory fake** — небольшой рукописный класс, реализующий интерфейс, хранящий данные в массивах.
3. **Laravel facade fake** — `Mail::fake()`, `Bus::fake()` и т.д. (только Laravel).
4. **Stub через PHPUnit `createStub`** — фиксированные возвращаемые значения, без expectations.
5. **Mock через PHPUnit `createMock`** — возвращаемые значения + expectations на вызовы.
6. **Mockery mock** — гибкие expectations, но легко неправильно использовать.

Спускайся вниз только тогда, когда верхний вариант не подходит.

## Когда мочить

- Зависимость **медленная** (сеть, реальная БД-операция, файловый IO на больших файлах).
- Зависимость **недетерминированная** (random, time-based, third-party API).
- У зависимости есть **побочные эффекты**, которые ты не контролируешь (отправляет email, списывает с карты, дёргает внешний сервис).
- Зависимость **ещё не реализована** (есть интерфейс, нет конкретики).
- Триггер реального поведения требует громоздкого setup, который замусоривает тест.

## Когда НЕ мочить

- **Сам тестируемый класс.** Всегда реальный.
- **Value objects, DTO, enum'ы.** Всегда реальные — нет побочных эффектов, нет IO.
- **Eloquent-модели.** Используй реальную модель с `RefreshDatabase`, а не мок — поведение Eloquent — это то, с чем ты интегрируешься.
- **Внутренние коллабораторы, которые чистые.** Маппер / форматтер / валидатор без IO — реальный.
- **Фреймворк.** Не мочь `Request`, `Response`, контейнер и т.д. — используй HTTP-хелперы тестирования.

## In-memory fakes

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

В тестах:

```php
$repo = new FakeUserRepository();
$repo->save(new User(1, 'Alice'));

$service = new ProfileService($repo);
expect($service->getProfile(1)->name)->toBe('Alice');
```

Плюсы: тесты читаются как production-код. Нет шума оркестрации моков. Рефакторинги, меняющие *поведение*, ломают тесты; меняющие *реализацию* — нет.

Минусы: нужно написать fake. Окупается, если иначе пришлось бы мочить тот же интерфейс в 10+ тестах.

Клади fakes в `tests/Fakes/` — легко найти, не засоряет production namespace.

## Mockery — доминирующий выбор для PHP-моков

```php
use Mockery;

$repo = Mockery::mock(UserRepository::class);

// Стаббинг — вернуть значение, не требовать вызова
$repo->shouldReceive('findById')->andReturn(new User(1, 'Alice'));

// Mocking — требовать что вызов произошёл
$repo->shouldReceive('save')->once()->with(Mockery::on(fn ($u) => $u->id === 1));

// Разные значения по очереди
$repo->shouldReceive('findById')
    ->andReturn(null, new User(1, 'Alice'));   // первый вызов вернёт null, второй — пользователя
```

В Pest / PHPUnit Mockery авто-верифицируется в конце теста (проверяет expectations `shouldReceive`), если в базовом TestCase подмешан трейт `Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration`.

## Подводные камни Mockery

- **`andReturn` без count в `shouldReceive`** — вызов разрешён, но не обязателен. Тесты могут пройти без ни одного обращения к методу.
- **`shouldReceive` затеняет реальные методы на partial-моке** — `Mockery::mock(SomeClass::class . '[methodA]')` — partial-мок; заглушён только `methodA`, остальные реальные. Легко перепутать направление.
- **Strict vs lenient режим Mockery** — `shouldReceive('foo')` без `shouldNotReceive('bar')` тихо разрешает вызывать `bar()`. Будь явным, когда это важно.
- **Type-hint моков** — `Mockery::mock(X::class)` возвращает `MockInterface`, не `X`. В конструкторы пробрасывается нормально, но если присваиваешь `private X $x` свойству — IDE / статический анализатор ругается.

## Встроенные моки PHPUnit

Для простых случаев:

```php
$repo = $this->createMock(UserRepository::class);
$repo->method('findById')->willReturn(new User(1, 'Alice'));   // stub, без энфорса
$repo->expects(self::once())                                    // энфорсим count
    ->method('save')
    ->with(self::isInstanceOf(User::class));
```

Менее выразительно, чем Mockery (нет последовательных return'ов из коробки, нет гибких argument matcher'ов без `Callback`), но для большинства случаев хватает. Бери то, что в проекте; не вводи оба сразу.

## Spies — когда важно только то, что вызов произошёл

```php
// Mockery
$logger = Mockery::spy(Logger::class);
$service->doThing();
$logger->shouldHaveReceived('warning')->with('reason');
```

Spy — stub-by-default — не падает на неожиданные вызовы. Используй, когда хочешь утверждать на вызовы *постфактум*, а не предобъявлять expectations. Читается естественнее в тестах, проверяющих в первую очередь побочные эффекты.

## Типизированные моки на интерфейсах vs конкретных классах

- Mock **интерфейса**: чисто, без сюрпризов.
- Mock **конкретного класса**: работает, но привязывает тесты к деталям реализации. Лучше извлеки интерфейс и мочь его.
- Mock **final-класса**: не работает с PHPUnit-моками (final блокирует наследование). Mockery поддерживает через `Mockery::mock(FinalClass::class)`, но через runtime-хаки. Лучшее решение: сделать класс не-final (редко имеет смысл) или извлечь интерфейс.

## Решающее дерево

```
Нужно подменить зависимость?
│
├── Это Laravel-фасад с fake() helper'ом? → Mail::fake() / Bus::fake() / etc.
│
├── Поведение простое и используется во многих тестах? → in-memory fake
│
├── Нужно энфорсить конкретные шейпы / counts вызовов? → Mockery (или PHPUnit createMock)
│
├── Нужно только возвращаемое значение, на вызовы плевать? → createStub или Mockery shouldReceive без count
│
└── По умолчанию: не мочь — используй настоящее.
```

## Резюме: красные флаги в mocking

- Строк setup'а у моков больше, чем тела теста.
- 3+ мока в одном тесте.
- Мочат класс, в котором нет IO и нет медленных операций.
- Цепочки `shouldReceive`, понять которые невозможно без перечитывания production-кода.
- Тест проходит, когда тело метода в проде заменили на `return null`.

Если что-то из этого всплыло — отступи и подумай: feature-тест с реальной БД, реальными сервисами не будет ли короче и честнее?
