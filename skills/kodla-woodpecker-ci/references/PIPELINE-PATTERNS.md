# Woodpecker CI — Pipeline Patterns

Руководство по выбору структуры pipeline в зависимости от задачи.

---

## Pattern 1: Single Docker Backend

**Когда применять:**
- Нужны только тесты, линтинг, сборка артефактов
- Нет деплоя на конкретный сервер
- Woodpecker сервер с одним docker-агентом

**Файловая структура:**
```
.woodpecker/
└── pipeline.yml    # один файл, все шаги
```

**Пример:**
```yaml
labels:
  backend: docker

when:
  - event: push
    branch: master

steps:
  - name: test
    image: php:8.3-cli
    commands:
      - vendor/bin/phpunit
```

**Шаблоны:** `php-docker.yml`, `node-docker.yml`, `go-docker.yml`

---

## Pattern 2: Multi-Backend (Build + Deploy)

**Когда применять:**
- Нужен деплой на тот же сервер где стоит Woodpecker
- Build в Docker (composer install, npm build, компиляция)
- Deploy на хосте напрямую (cp файлов, systemctl restart, etc.)

**Файловая структура:**
```
.woodpecker/
├── build.yml       # labels: backend: docker
└── deploy.yml      # labels: backend: local, depends_on: [build]
```

**Ключевые правила:**
- `build.yml` — docker backend, создаёт артефакт в `/tmp/woodpecker-build/`
- `deploy.yml` — local backend, `depends_on: [build]`, `image: bash`
- Передача артефактов через volume `/tmp/woodpecker-build`

**Шаблоны:** `php-multi-build.yml` + `php-multi-deploy.yml`

---

## Pattern 3: Local Backend Only

**Когда применять:**
- Docker недоступен или не нужен
- Все инструменты установлены на хосте
- Простые скрипты деплоя

**Файловая структура:**
```
.woodpecker/
└── pipeline.yml    # один файл, image: bash
```

**Ключевые правила:**
- `image: bash` (или sh, zsh) — НЕ docker image
- Все нужные утилиты должны быть установлены на хосте

**Шаблоны:** `generic-local.yml`

---

## Pattern 4: Multi-Workflow (CI + CD раздельно)

**Когда применять:**
- Разные триггеры для CI и CD (CI на все push, CD только на master)
- Независимые команды для разных агентов

**Файловая структура:**
```
.woodpecker/
├── ci.yml          # тесты, линтинг — на все push/PR
└── deploy.yml      # деплой — только на master, depends_on: [ci]
```

**Пример разделения триггеров:**
```yaml
# ci.yml
when:
  - event: [push, pull_request]

# deploy.yml
when:
  - event: push
    branch: master
depends_on:
  - ci
```

---

## Таблица выбора паттерна

| Нужен деплой? | Docker доступен? | Паттерн |
|---------------|-----------------|---------|
| Нет | Да | Single Docker |
| Да, на тот же сервер | Да + local агент | Multi-Backend |
| Нет | Нет | Local Only |
| Да, на тот же сервер | Нет | Local Only |

---

## Передача артефактов между workflow (Volume Pattern)

Для multi-backend передача файлов через shared volume:

```
/tmp/woodpecker-build/  ← общая директория на хосте
```

**Build step (docker):**
```yaml
volumes:
  - /tmp/woodpecker-build:/out
commands:
  - tar -czf /tmp/app.tar.gz .
  - mv /tmp/app.tar.gz /out/app.tar.gz
```

**Deploy step (local):**
```yaml
commands:
  - cp /tmp/woodpecker-build/app.tar.gz /deploy/app.tar.gz
```

**Требования:**
- Trust включён в настройках репозитория
- `WOODPECKER_BACKEND_DOCKER_VOLUMES=/tmp/woodpecker-build:/tmp/woodpecker-build` в env docker-агента
