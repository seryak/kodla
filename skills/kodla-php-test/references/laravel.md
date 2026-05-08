# Laravel Testing

Паттерны, специфичные для Laravel-проектов — применяются поверх PHPUnit или Pest. Синтаксис — в `phpunit.md` / `pest.md`, этот файл — про Laravel-фичи.

## Базовый TestCase и трейты

```php
namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;
}
```

Самые употребляемые трейты для подмешивания (per-test или глобально через `Pest.php` / базовый `TestCase`):

- `RefreshDatabase` — оборачивает каждый тест в транзакцию, откатывает в конце. Дефолтный выбор для feature-тестов.
- `DatabaseTransactions` — то же по сути, мелкие отличия для параллельного запуска.
- `WithFaker` — даёт `$this->faker`.
- `LazilyRefreshDatabase` — как RefreshDatabase, но инициализируется только при первом обращении к БД (быстрее для тестов, не трогающих БД).

Правило: если test class трогает БД — нужна очистка БД. Один трейт на проект — и придерживаться.

## Фабрики

Самый дешёвый способ получить доменные объекты:

```php
$user = User::factory()->create();                         // сохранён
$user = User::factory()->make();                           // не сохранён (только в памяти)
$users = User::factory()->count(5)->create();
$admin = User::factory()->admin()->create();               // factory state
$user = User::factory()->for($team)->create();             // belongsTo
$team = Team::factory()->has(User::factory()->count(3))->create();  // hasMany
```

В тестах предпочитай фабрики ручным `User::create([...])`. Они обрабатывают дефолты, переживают рефакторинги и документируют что тест осмысленно меняет (явные overrides) vs что ему безразлично (дефолты).

Для одноразовых форм, когда factory state — оверкилл, используй прямые overrides:

```php
User::factory()->create(['email' => 'specific@example.com']);
```

## Fakes — суперсила Laravel-тестирования

Fakes подменяют фасады in-memory реализациями, записывающими вызовы, — чище, чем mocking, с явными assertion'ами:

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

Предпочитай fakes Mockery для фасадов. Читается лучше, меньше false-позитивов, проще рассуждать.

## HTTP-тестирование

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

Отключение CSRF / rate-limiting в feature-тестах:

```php
// В TestCase::setUp
$this->withoutMiddleware([VerifyCsrfToken::class, ThrottleRequests::class]);
```

## Database-assertion'ы

```php
$this->assertDatabaseHas('tasks', ['name' => 'Buy milk', 'priority' => 'medium']);
$this->assertDatabaseMissing('tasks', ['name' => 'deleted']);
$this->assertDatabaseCount('tasks', 3);
$this->assertModelExists($task);
$this->assertModelMissing($task);
$this->assertSoftDeleted($task);
```

Предпочитай model-assertion'ы, когда модель уже в руках — они конкретнее.

## Time travel

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

## Eloquent-нюансы в тестах

- **`refresh()` после записи** — если тест меняет модель через сырой запрос / прямое обращение к БД, вызови `$model->refresh()` или возьми свежую: `User::find($id)`.
- **Eager vs lazy loading** — `$user->posts->count()` в тесте утверждает, что *было загружено*; если нужны все posts — `$user->posts()->count()` (запрос) или `$user->loadCount('posts')`.
- **Детект N+1** — `Model::preventLazyLoading()` (в `AppServiceProvider::boot` для не-prod) делает lazy-loads бросающими исключение, что проявляет N+1 во время тестов.

## Очереди / Jobs

Для тестирования *постановки в очередь* (job был отправлен):

```php
Queue::fake();
ProcessOrder::dispatch($order);
Queue::assertPushed(ProcessOrder::class, fn ($j) => $j->order->is($order));
```

Для тестирования *обработки* (job реально делает что нужно): инстанцируй и вызывай `handle()` напрямую с моками/fakes для зависимостей:

```php
$job = new ProcessOrder($order);
$job->handle($mockedDependency);
expect($order->fresh()->status)->toBe('processed');
```

Не пытайся тестировать оба аспекта одновременно.

## Когда писать Unit vs Feature

Laravel-кодовые базы часто содержат гораздо больше "юнит"-тестов с замоканным всем, чем им положено — и feature-тест был бы и короче, и честнее. Эвристика:

| Тестируемый код | Тип теста |
|---|---|
| Чистый value object / DTO / enum-логика | Unit |
| Доменный сервис без побочных эффектов | Unit (реальные deps, если дёшево) |
| Сервис, пишущий в БД | Feature (с `RefreshDatabase`) |
| Controller-action | Feature (HTTP + БД) |
| Eloquent scope / связь | Feature (БД) |
| Job, вызывающий внешний API | Unit с `Http::fake()` для http-вызовов, реальный handle() |
| Job, диспатчащий другой job | Feature с `Bus::fake()` |
| Шаблон уведомления / тело письма | Unit (инстанцировать, отрендерить, проверить контент) |
| Form request validation | Feature (POST и проверить ошибки валидации) |

Когда сомневаешься — feature-тест. Быстрее писать, реалистичнее, ловит интеграционные баги.
