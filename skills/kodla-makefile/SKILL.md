---
name: kodla-makefile
description: >-
  Анализирует проект и генерирует или улучшает файл автоматизации сборки (Makefile, Taskfile.yml, Justfile, Magefile.go).
  Если build-файл уже существует — улучшает его, добавляя недостающие цели и лучшие практики.
  Используй когда пользователь говорит "создай makefile", "добавь taskfile", "сгенерируй justfile", "настрой mage", "автоматизация сборки".
argument-hint: "[makefile|taskfile|justfile|mage|castor]"
allowed-tools: Read Edit Glob Grep Write Bash(git *) AskUserQuestion Questions
disable-model-invocation: false
metadata:
  author: Kodla
  version: "1.0"
  category: build-automation
---

# Генератор автоматизации сборки

Генерирует или улучшает файл автоматизации сборки для любого проекта. Поддерживает Makefile, Taskfile.yml, Justfile и Magefile.go.

**Два режима:**
- **Generate** — Build-файл отсутствует → создать с нуля по шаблонам лучших практик
- **Enhance** — Build-файл уже есть → анализ пробелов, добавление недостающих целей, исправление anti-patterns, сохранение существующей работы

---

## Шаг 0: Загрузка контекста проекта

Прочитай описание проекта если доступно:

```
Read .kodla/DESCRIPTION.md
```

Сохрани контекст проекта (tech-стек, фреймворк, архитектура) для использования на следующих шагах. Если файл не существует — всё определим в Шаге 2.

**Прочитай `.kodla/skill-context/kodla-makefile/SKILL.md`** — ОБЯЗАТЕЛЬНО если файл существует.

Этот файл содержит проектно-специфичные правила, накопленные `/kodla-evolve` из патчей,
соглашений кодовой базы и анализа tech-стека. Правила адаптированы под конкретный проект.

**Как применять правила skill-context:**
- Рассматривай их как **переопределения уровня проекта** для общих инструкций навыка
- Когда правило skill-context конфликтует с общим правилом из этого SKILL.md,
  **правило skill-context имеет приоритет** (более специфичный контекст важнее — тот же принцип что у вложенных CLAUDE.md)
- При отсутствии конфликта применяй оба: общие правила SKILL.md + проектные из skill-context
- НЕ игнорируй правила skill-context даже если они кажутся противоречащими умолчаниям —
  они существуют потому что опыт проекта доказал недостаточность умолчания
- **КРИТИЧНО:** правила skill-context применяются ко ВСЕМ выходным данным навыка — включая
  сгенерированные build-файлы (Makefile, Taskfile, justfile, magefile). Шаблоны в навыке — **базовые структуры**.
  Если правило skill-context говорит "build-файл ДОЛЖЕН содержать цель X" или "ДОЛЖЕН следовать соглашению Y" —
  ОБЯЗАН выполнить. Генерация build-автоматизации нарушающей правила skill-context — это баг.

**Проверка:** После генерации любого выходного артефакта — сверь его со всеми правилами skill-context.
Если правило нарушено — исправь вывод до показа пользователю.

---

## Шаг 1: Обнаружение существующих build-файлов и определение режима

### 1.1 Сканирование существующих build-файлов

Прежде всего проверь есть ли в проекте уже build-автоматизация:

```
Glob: Makefile, makefile, GNUmakefile, Taskfile.yml, Taskfile.yaml, taskfile.yml, justfile, Justfile, .justfile, magefile.go, magefiles/*.go, castor.php, .castor/castor.php
```

Создай список `EXISTING_FILES` из результатов.

### 1.2 Определение режима

**Режим A — Улучшение существующего** (если `EXISTING_FILES` не пуст):

- Установи `MODE = "enhance"`
- Определи `TARGET_TOOL` автоматически из обнаруженного файла (Makefile → `makefile`, Taskfile.yml → `taskfile`, и т.д.)
- Если существует несколько build-файлов И `$ARGUMENTS` указывает на один — используй аргумент
- Если несколько файлов И нет аргумента — спроси какой улучшать:

```
AskUserQuestion: В проекте несколько build-файлов. Какой улучшить?

Варианты (динамические, на основе найденного):
1. Makefile — Улучшить существующий Makefile
2. Taskfile.yml — Улучшить существующий Taskfile
...
```

- Прочитай содержимое существующего файла — это baseline для улучшения
- Сохрани как `EXISTING_CONTENT`

**Режим B — Генерация нового** (если `EXISTING_FILES` пуст):

- Установи `MODE = "generate"`
- Разбери `$ARGUMENTS` для определения инструмента:

| Аргумент | Инструмент | Выходной файл |
|----------|------------|---------------|
| `makefile` или `make` | GNU Make | `Makefile` |
| `taskfile` или `task` | Taskfile | `Taskfile.yml` |
| `justfile` или `just` | Just | `justfile` |
| `mage` или `magefile` | Mage | `magefile.go` |
| `castor` | Castor | `castor.php` |

- Если `$ARGUMENTS` пуст или не совпадает — спроси интерактивно.

**Для PHP-проектов** (`language == "PHP"`):

```
AskUserQuestion: Какой инструмент автоматизации сборки сгенерировать?

Варианты:
1. Makefile — GNU Make (универсальный, не требует установки)
2. Taskfile.yml — Task runner (YAML, современный, кросс-платформенный)
3. justfile — Just command runner (простой, быстрый, эргономичный)
4. castor.php — Castor (нативный PHP, задачи на PHP, богатые хелперы)
```

**Для не-PHP-проектов**:

```
AskUserQuestion: Какой инструмент автоматизации сборки сгенерировать?

Варианты:
1. Makefile — GNU Make (универсальный, не требует установки)
2. Taskfile.yml — Task runner (YAML, современный, кросс-платформенный)
3. justfile — Just command runner (простой, быстрый, эргономичный)
4. magefile.go — Mage (нативный Go, типизированный, без shell-скриптов)
```

**Ограничение Castor:** если `castor` выбран или передан в аргументе, но `language != "PHP"` — объясни что Castor PHP-специфичен и требует PHP runtime, предложи Makefile как ближайшую альтернативу. Спроси через `AskUserQuestion` продолжать ли с Makefile.

Сохрани выбранный инструмент как `TARGET_TOOL`.

---

## Шаг 2: Анализ проекта

Определи профиль проекта сканированием репозитория. Используй `Glob` и `Grep`:

### 2.1 Основной язык

Проверь файлы (первое совпадение — победитель):

| Файл | Язык |
|------|------|
| `go.mod` | Go |
| `package.json` | Node.js / JavaScript / TypeScript |
| `pyproject.toml` или `setup.py` или `setup.cfg` | Python |
| `Cargo.toml` | Rust |
| `composer.json` | PHP |
| `Gemfile` | Ruby |
| `build.gradle` или `pom.xml` | Java/Kotlin |
| `*.csproj` или `*.sln` | C# / .NET |

### 2.2 Пакетный менеджер

Проверь lock-файлы:

| Файл | Пакетный менеджер |
|------|------------------|
| `bun.lockb` | bun |
| `pnpm-lock.yaml` | pnpm |
| `yarn.lock` | yarn |
| `package-lock.json` | npm |
| `poetry.lock` | poetry |
| `uv.lock` | uv |
| `Pipfile.lock` | pipenv |

### 2.3 Определение фреймворка

Для Node.js-проектов проверь зависимости `package.json`:
- `next` → Next.js
- `nuxt` → Nuxt
- `@remix-run/node` → Remix
- `express` → Express
- `fastify` → Fastify
- `hono` → Hono
- `@nestjs/core` → NestJS

Для Python-проектов проверь `pyproject.toml` или импорты:
- `fastapi` → FastAPI
- `django` → Django
- `flask` → Flask

Для PHP-проектов проверь `require` в `composer.json`:
- `laravel/framework` → Laravel
- `symfony/framework-bundle` → Symfony
- `slim/slim` → Slim
- `cakephp/cakephp` → CakePHP

Для Go-проектов проверь `go.mod`:
- `gin-gonic/gin` → Gin
- `labstack/echo` → Echo
- `gofiber/fiber` → Fiber
- `go-chi/chi` → Chi

### 2.4 Docker (глубокое сканирование)

```
Glob: Dockerfile, Dockerfile.*, docker-compose.yml, docker-compose.yaml, compose.yml, compose.yaml, .dockerignore
```

Если файлы найдены — установи `HAS_DOCKER=true` и выполни глубокий анализ:

**Прочитай Dockerfile(s)** для обнаружения:
- Многоэтапные сборки (отдельные `dev` / `prod` стадии) → `DOCKER_MULTISTAGE=true`
- Открытые порты → `DOCKER_PORTS` (напр. `3000`, `8080`)
- Базовый образ → `DOCKER_BASE` (напр. `node:20-alpine`, `golang:1.22`)
- Entrypoint/CMD → понять как запускается приложение внутри контейнера

**Прочитай docker-compose / compose файл** для обнаружения:
- Имена сервисов → `DOCKER_SERVICES` (напр. `app`, `db`, `redis`, `worker`)
- Монтирование томов → понять dev vs prod настройку
- Профили (если есть) → `dev`, `production`, `test`
- Зависимые сервисы (postgres, redis, rabbitmq, и т.д.) → `DOCKER_DEPS`

Сохрани как `DOCKER_PROFILE`:
- `has_compose`: boolean
- `has_multistage`: boolean
- `services`: список имён сервисов
- `deps`: список инфраструктурных сервисов (db, cache, queue)
- `ports`: открытые порты
- `has_dev_stage`: boolean (Dockerfile имеет стадию `dev` или `development`)

### 2.5 CI/CD

```
Glob: .github/workflows/*.yml, .gitlab-ci.yml, .circleci/config.yml, Jenkinsfile, .travis.yml
```

Определи какая CI-система используется.

### 2.6 База данных и миграции

Ищи инструменты миграций:

```
Grep: prisma|drizzle|knex|typeorm|sequelize|alembic|django.*migrate|goose|migrate|atlas|sqlx
```

Проверь:
- `prisma/schema.prisma` → Prisma
- `drizzle.config.ts` → Drizzle
- `alembic/` директория → Alembic
- `migrations/` директория → Общие миграции

### 2.7 Тестовый фреймворк

| Язык | Что искать |
|------|------------|
| Node.js | `jest`, `vitest`, `mocha`, `ava` в package.json |
| Python | `pytest` в pyproject.toml/requirements, импорты `unittest` |
| Go | Встроенное тестирование; проверь `testify` в go.mod |
| Rust | Встроенное; проверь директорию интеграционных тестов `tests/` |

### 2.8 Линтеры и форматтеры

```
Glob: .eslintrc*, eslint.config.*, .prettierrc*, biome.json, .golangci.yml, .golangci.yaml
Grep в pyproject.toml: ruff|black|flake8|pylint|isort
```

### 2.9 Определение монорепозитория

```
Glob: turbo.json, nx.json, lerna.json, pnpm-workspace.yaml
```

### Итог

Собери объект `PROJECT_PROFILE`:
- `language`: основной язык
- `package_manager`: обнаруженный PM
- `framework`: обнаруженный фреймворк (если есть)
- `has_docker`: boolean
- `docker_profile`: объект `DOCKER_PROFILE` (если `has_docker`)
- `ci_system`: обнаруженная CI (если есть)
- `has_migrations`: boolean + название инструмента
- `test_framework`: обнаруженный test runner
- `linters`: список обнаруженных линтеров
- `is_monorepo`: boolean
- `has_dev_server`: boolean (фреймворк с dev-сервером)

---

## Шаг 3: Чтение лучших практик

Прочитай справочник лучших практик для выбранного инструмента:

```
Read references/BEST-PRACTICES.md
```

Сосредоточься на разделе соответствующем `TARGET_TOOL`:
- Makefile → Раздел 1
- Taskfile → Раздел 2
- Justfile → Раздел 3
- Magefile → Раздел 4

Также прочитай раздел "Cross-Cutting Concerns" для стандартных целей.

---

## Шаг 4: Выбор и чтение шаблона

Выбери ближайший подходящий шаблон на основе `language` + `TARGET_TOOL`:

| Инструмент | Go | Node.js | Python | PHP | Другие |
|------------|----|---------|--------|-----|--------|
| Makefile | `makefile-go.mk` | `makefile-node.mk` | `makefile-python.mk` | `makefile-php.mk` | Используй ближайший |
| Taskfile | `taskfile-go.yml` | `taskfile-node.yml` | `taskfile-python.yml` | `taskfile-php.yml` | Используй ближайший |
| Justfile | `justfile-go` | `justfile-node` | `justfile-python` | `justfile-php` | Используй ближайший |
| Magefile | `magefile-basic.go` | `magefile-full.go` | `magefile-full.go` | N/A (используй Makefile) | `magefile-basic.go` |
| Castor | N/A | N/A | N/A | `castor-php.php` | N/A (только PHP) |

Для Magefile: используй `magefile-full.go` если `HAS_DOCKER` или `has_migrations` истинно, иначе `magefile-basic.go`.

Для PHP + Magefile: Mage специфичен для Go и не применим к PHP-проектам. Если пользователь явно запросил `mage` для PHP, объясни это и предложи Makefile как ближайшую альтернативу (универсальный, не требует установки). Спроси через `AskUserQuestion` продолжать ли с Makefile.

Для Castor + не-PHP: Castor специфичен для PHP и требует PHP runtime. Объясни это и предложи Makefile. Спроси через `AskUserQuestion`.

Прочитай выбранный шаблон:

```
Read templates/<выбранный-шаблон>
```

---

## Шаг 5: Генерация или улучшение файла

### Режим B — Генерация нового файла

Используя `PROJECT_PROFILE`, лучшие практики и шаблон как справочник — сгенерируй кастомизированный build-файл с нуля.

#### Правила генерации

1. **Начни с обязательного preamble инструмента** (из лучших практик)
2. **Включи все стандартные цели**: help/default, build, test, lint, clean, dev, fmt
3. **Добавь условные цели** на основе профиля проекта:
   - Docker-цели → только если `has_docker`
   - Database-цели → только если `has_migrations` (используй правильный инструмент миграций)
   - Deploy-цели → только если обнаружен CI/CD
   - Generate-цель → только если обнаружена кодогенерация
   - Typecheck-цель → только если обнаружен TypeScript или mypy
4. **Используй правильные команды пакетного менеджера** (без хардкода npm/pip/go)
5. **Включи агрегирующую CI-цель** запускающую lint + test + build
6. **Следуй структуре шаблона** для организации и группировки
7. **Адаптируй имена переменных** под реальный проект (имя модуля, бинарника, исходные директории)
8. **Включи определение version/commit/build-time** через git
9. **Docker-цели** — если `has_docker`, сгенерируй отдельную Docker-секцию (см. ниже)

#### Генерация Docker-целей

Когда `has_docker` истинно, сгенерируй **два уровня** команд:

**Уровень 1 — Жизненный цикл контейнера** (всегда при наличии Docker):

| Цель | Назначение |
|------|------------|
| `docker-build` или `docker:build` | Собрать Docker-образ |
| `docker-run` или `docker:run` | Запустить контейнер |
| `docker-stop` или `docker:stop` | Остановить запущенные контейнеры |
| `docker-logs` или `docker:logs` | Просмотр логов контейнера |
| `docker-push` или `docker:push` | Отправить образ в реестр |
| `docker-clean` или `docker:clean` | Удалить образы и остановленные контейнеры |

**Уровень 2 — Разделение Dev и Production** (при наличии compose или multistage):

```
##@ Docker — Development
docker-dev:          ## Запустить все сервисы в dev-режиме (hot reload, смонтированные тома)
docker-dev-build:    ## Пересобрать dev-контейнеры
docker-dev-down:     ## Остановить dev-среду и удалить тома

##@ Docker — Production
docker-prod-build:   ## Собрать production-образ (оптимизированный, multi-stage)
docker-prod-run:     ## Запустить production-контейнер локально для тестирования
docker-prod-push:    ## Отправить production-образ в реестр
```

**Логика генерации:**

- Если `has_compose` → использовать команды `docker compose` (не `docker-compose`)
- Если у compose есть профили → использовать `--profile dev` / `--profile production`
- Если `has_multistage` → использовать `--target dev` для dev-сборок, без target (или `--target production`) для prod
- Если в `docker_profile.deps` есть (db, redis, и т.д.) → добавить цели `infra-up` / `infra-down` для запуска/остановки только инфраструктурных сервисов без приложения
- Если обнаружен compose → `docker-dev` должен запускать `docker compose up` с правильным профилем/сервисами
- Если нет compose но есть Dockerfile → `docker-dev` должен запускать `docker build --target dev` + `docker run` с монтированием томов

**Уровень 3 — Команды внутри контейнера** (зеркало хостовых команд через контейнер):

При Docker-ориентированном проекте также генерируй container-exec варианты:

```
# Запуск тестов внутри контейнера
docker-test:         ## Запустить тесты внутри Docker-контейнера
  docker compose exec app [команда тестов]

# Запуск линтера внутри контейнера
docker-lint:         ## Запустить линтер внутри Docker-контейнера
  docker compose exec app [команда линтера]

# Открыть shell в контейнере
docker-shell:        ## Открыть shell внутри запущенного контейнера
  docker compose exec app sh
```

Генерируй `docker-*` exec варианты только если проект явно Docker-ориентированный (compose монтирует исходный код как тома, или нет локальной настройки language runtime).

#### Кастомизация из профиля проекта

- **Имя бинарника**: Используй реальное имя проекта из `go.mod`, `package.json` или имени директории
- **Директория исходников**: Используй реальную src-директорию (напр. `src/`, `app/`, `cmd/`)
- **Команда dev-сервера**: Соответствует dev-серверу фреймворка (напр. `next dev`, `uvicorn --reload`, `air`)
- **Команда тестов**: Соответствует обнаруженному test runner
- **Команда линтера**: Соответствует обнаруженным линтерам
- **Команды миграций**: Точно соответствует обнаруженному инструменту миграций
- **Номера портов**: Используй умолчания фреймворка (3000 для Node, 8000 для Python, 8080 для Go)

#### Генерация Castor-файла (castor.php)

Когда `TARGET_TOOL = "castor"`:

1. **Выходной файл:** `castor.php` в корне проекта
2. **Все задачи** объявляются через атрибут `#[Castor\Attribute\AsTask(description: '...')]` — `description` обязателен
3. **Группировка** через PHP-пространства имён (`namespace dev;`, `namespace test;`, и т.д.)
4. **Используй `run()`** для shell-команд, `capture()` для захвата вывода, `io()` для вывода пользователю
5. **Предпочитай массивный формат** `run(['php', 'artisan', $arg])` вместо строки — безопаснее
6. **Условная генерация задач** аналогична другим инструментам:
   - Laravel → задачи для `artisan`: `dev:serve`, `db:migrate`, `db:seed`, `db:fresh`, `cache-clear`, `optimize`
   - Symfony → задачи для `bin/console`: `dev:serve`, `db:migrate`, `cache-clear`
   - PHPUnit → задачи `test:run-tests`, `test:coverage`, `test:filter`
   - Pest → `./vendor/bin/pest` вместо `./vendor/bin/phpunit`
   - PHP-CS-Fixer → `quality:lint`, `quality:fmt`
   - Pint (Laravel) → `./vendor/bin/pint`
   - PHPStan → `quality:phpstan`
   - Docker → `docker:build`, `docker:up`, `docker:down`, `docker:logs` через `run()`
7. **Добавляй вспомогательные функции** вне namespace для общего кода (`php_bin()`, `composer_bin()`, `project_version()`)
8. **Опасные операции** (migrate:fresh, clean) — добавляй `io()->warning()` + `sleep(3)` перед выполнением
9. **Установочная подсказка** в итоговом отчёте (Шаг 6): покажи команды установки Castor глобально и через Composer

### Режим A — Улучшение существующего файла

Когда `MODE = "enhance"` — НЕ заменяй файл целиком. Вместо этого анализируй и улучшай точечно.

#### 5A.1 Анализ существующего файла

Сравни `EXISTING_CONTENT` с `PROJECT_PROFILE` и лучшими практиками. Составь gap-анализ:

**Отсутствующий preamble/config** — проверь есть ли рекомендуемый preamble:
- Makefile: `SHELL := bash`, `.ONESHELL`, `.SHELLFLAGS`, `.DELETE_ON_ERROR`, `MAKEFLAGS`
- Taskfile: `version: '3'`, `output:`, `dotenv:`
- Justfile: `set shell`, `set dotenv-load`, `set export`
- Magefile: `//go:build mage`, правильные импорты

**Отсутствующие стандартные цели** — проверь какие из них отсутствуют:
- `help` / `default` (самодокументирующийся)
- `build`, `test`, `lint`, `clean`, `dev`, `fmt`
- `ci` (агрегирующая цель)

**Отсутствующие проектно-специфичные цели** — на основе `PROJECT_PROFILE`:
- Docker-цели (если `has_docker` но нет docker-целей в файле)
- Database/migration-цели (если `has_migrations` но нет db-целей)
- Typecheck-цель (если обнаружен TypeScript/mypy но нет typecheck)
- Generate-цель (если обнаружены инструменты кодогенерации)
- Coverage-цель (если есть test-цель но нет coverage-варианта)

**Проблемы качества** — проверь anti-patterns из лучших практик:
- Цели без описаний/документации
- Отсутствующие `.PHONY` объявления (Makefile)
- Хардкоженные пути инструментов которые должны быть переменными
- Отсутствующее определение version/commit
- Нет самодокументирующейся help-цели

#### 5A.2 Планирование изменений

Составь список конкретных изменений:

```
CHANGES = [
  { type: "add_preamble", detail: "Добавить .SHELLFLAGS и .DELETE_ON_ERROR" },
  { type: "add_target", name: "docker-build", detail: "Обнаружен Dockerfile но нет docker-цели" },
  { type: "add_target", name: "help", detail: "Нет самодокументирующейся help-цели" },
  { type: "fix_quality", detail: "Добавить ## комментарии к 3 целям без описаний" },
  { type: "add_variable", detail: "Добавить определение VERSION/COMMIT через git" },
  ...
]
```

#### 5A.3 Применение изменений

- **Сохрани существующую структуру** — оставь порядок, именование и стиль пользователя
- **Сохрани существующие цели точно** — НЕ модифицируй работающие цели без явного бага или недостающего описания
- **Добавляй новые цели в подходящую секцию** — следуй существующему паттерну группировки (если файл использует `##@` секции — добавляй в соответствующую; если нет — добавляй логично)
- **Добавляй недостающие строки preamble** вверху, перед существующим контентом
- **Добавляй недостающие переменные** рядом с существующими объявлениями
- Используй шаблон как справочник синтаксиса новых целей, но адаптируй под стиль уже присутствующий в файле

### Проверки качества (оба режима)

Перед записью файла проверь:
- [ ] Все цели имеют описания/документацию (## комментарии, desc:, [doc()], doc-комментарии)
- [ ] Нет хардкоженных путей которые должны быть переменными
- [ ] Определение пакетного менеджера корректно
- [ ] Включена самодокументирующаяся help-цель
- [ ] `.PHONY` объявления для всех не-файловых целей (только Makefile)
- [ ] Опасные операции имеют подтверждения (Justfile) или предупреждения

---

## Шаг 6: Запись файла и отчёт

### 6.1 Запись файла

**Режим B (Генерация нового):**

Запиши сгенерированный контент через инструмент `Write`:

| Инструмент | Путь вывода |
|------------|-------------|
| Makefile | `Makefile` |
| Taskfile | `Taskfile.yml` |
| Justfile | `justfile` |
| Magefile | `magefile.go` |
| Castor | `castor.php` |

**Режим A (Улучшение существующего):**

Запиши улучшенный контент по тому же пути где был найден существующий файл (сохраняя исходное имя файла и расположение). Файл обновляется на месте — не нужно спрашивать о перезаписи поскольку мы улучшаем, а не заменяем.

### 6.2 Отображение итогов

Отображай итоги в формате из `references/SUMMARY-FORMAT.md`. Показывает таблицу целей, использованный профиль проекта и команду быстрого старта для Режима B (генерация), или что изменилось + новые/существующие цели для Режима A (улучшение). Включай подсказки по установке если инструмент требует настройки.

**Для Castor** — обязательно добавляй в итоговый отчёт блок установки:

```
## Установка Castor

Глобально (рекомендуется):
  curl "https://github.com/jolicode/castor/releases/latest/download/castor.linux-amd64.phar" \
    -Lo $HOME/.local/bin/castor && chmod u+x $HOME/.local/bin/castor

Или через Composer (per-project):
  composer require castor/castor --dev

Запуск задач:
  castor          — список всех задач
  castor dev:serve — запустить конкретную задачу
```

---

## Шаг 7: Интеграция в документацию проекта

После записи build-файла интегрируй быстрые команды в документацию проекта.
Детальные процедуры интеграции (README, AGENTS.md, существующий markdown) → читай `references/DOC-INTEGRATION.md`

Кратко: сканируй существующие секции команд, обновляй или добавляй быструю справку, предлагай создание AGENTS.md если отсутствует.

## Владение артефактами и политика конфигурации

- Основное владение: сгенерированные или улучшенные файлы автоматизации сборки (`Makefile`, `Taskfile.yml`, `justfile`, `magefile.go`).
- Разрешённые сопутствующие обновления: фрагменты быстрых команд в существующей документации или `AGENTS.md` когда напрямую связаны с созданным build workflow.
- Политика конфигурации: не зависит от конфигурации по дизайну. Навык использует обнаружение репозитория и фиксированные файлы контекста kodla вместо `config.yaml`.
